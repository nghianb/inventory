<?php

namespace App\Inventory\Intake;

use App\Inventory\Access\MissingRole;
use App\Inventory\Access\Role;
use App\Inventory\Access\RoleGate;
use App\Inventory\Catalog\StockForm;
use App\Inventory\Encryption\ContentCrypto;
use App\Inventory\Encryption\KeyFingerprintMismatch;
use App\Inventory\Encryption\KeyFingerprints;
use App\Inventory\Encryption\KeyRotation;
use App\Inventory\Reveal\RevealActor;
use App\Inventory\Reveal\RevealContext;
use App\Inventory\Reveal\RevealLog;
use App\Inventory\Stock\MaskedContent;
use App\Inventory\Stock\SlotStatus;
use App\Inventory\Stock\StockLedger;
use App\Inventory\Stock\StockTransition;
use App\Inventory\Stock\StockUnitStatus;
use App\Models\Batch;
use App\Models\BatchLine;
use App\Models\Product;
use App\Models\SupplierClaim;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Throwable;

/**
 * Nhập hàng hai pha. Nhân viên gửi Lô nhập (nội dung dán hoặc file gốc mã hoá ngay, lưu ổ
 * local) → job phân loại từng dòng để xem trước → nhân viên xác nhận thì chỉ phần nhập được
 * vào kho. Lúc ghi thật phân loại lại dưới khoá hàng Sản phẩm và chèn với ON CONFLICT, mỗi Lô
 * nhập một transaction, nên cấu hình Sản phẩm đổi hay Lô nhập khác vừa ghi cùng mã cũng không
 * làm hỏng kho. Nội dung tạm của bản kiểm tra bị xoá khi bỏ hoặc quá hạn xác nhận (24 giờ).
 */
class BatchIntake
{
    private const SAMPLE_SIZE = 5;

    private const INSERT_CHUNK = 1_000;

    private const SLOT_INSERT_CHUNK = 5_000;

    public function __construct(
        private RoleGate $roles,
        private KeyFingerprints $fingerprints,
        private ContentCrypto $crypto,
        private SourceReader $reader,
        private LineClassifier $classifier,
        private PendingContentStore $pending,
        private StockLedger $ledger,
        private RevealLog $revealLog,
    ) {}

    /**
     * Tạo Lô nhập ở trạng thái Đang kiểm tra và đưa job pha 1 vào queue.
     *
     * @throws MissingRole
     * @throws KeyFingerprintMismatch
     * @throws InvalidBatch
     */
    public function submit(User $actor, BatchDraft $draft): Batch
    {
        $this->roles->authorize($actor, Role::NhapKho);
        self::validateDraft($draft);
        $this->fingerprints->verify();
        self::ensureDedupeKeysSettled();

        foreach ($draft->lines as $line) {
            $this->ensureReadable($line);
        }

        $stored = [];

        try {
            $batch = DB::transaction(function () use ($actor, $draft, &$stored): Batch {
                $batch = new Batch;
                $batch->forceFill([
                    'supplier_id' => $draft->supplier->getKey(),
                    'received_on' => $draft->receivedOn->toDateString(),
                    'document_number' => self::blankToNull($draft->documentNumber),
                    'note' => self::blankToNull($draft->note),
                    'invoice_total' => $draft->invoiceTotal,
                    'supplements_batch_id' => $draft->supplements?->getKey(),
                    'supplier_claim_id' => $draft->supplierClaim?->getKey(),
                    'status' => BatchStatus::Validating,
                    'created_by' => $actor->getKey(),
                ])->save();

                foreach ($draft->lines as $draftLine) {
                    $line = new BatchLine;
                    $line->forceFill([
                        'batch_id' => $batch->id,
                        'product_id' => $draftLine->product->getKey(),
                        'unit_cost' => $draftLine->unitCost,
                        'separator' => $draftLine->separator,
                        'source' => $draftLine->source,
                        'file_name' => $draftLine->fileName,
                        'slots' => $draftLine->product->form() === StockForm::Account ? $draftLine->slots : null,
                        'expires_on' => $draftLine->expiry?->date?->toDateString(),
                        'expires_after_days' => $draftLine->expiry?->days,
                    ])->save();

                    $this->pending->put($line, $draftLine->content);
                    $stored[] = $line;
                }

                return $batch;
            });
        } catch (Throwable $exception) {
            foreach ($stored as $line) {
                $this->pending->forget($line);
            }

            throw $exception;
        }

        // Người gọi có thể đang trong transaction (trang Filament): job chỉ chạy khi Lô nhập đã commit.
        ValidateBatch::dispatch($batch)->afterCommit();

        return $batch->refresh();
    }

    /**
     * Sửa phần chứng từ và Giá trị áp cho Đơn vị hàng của một Lô nhập còn Chờ xác nhận, rồi đưa
     * lại vào queue kiểm tra. Nội dung (danh sách Đơn vị hàng) không sửa: nó là thứ tốn công nhập
     * nhất và Khoá chống trùng tính theo nó, nên giữ nguyên mới là điểm chính. Hạn 24 giờ của bản
     * kiểm tra vẫn tính từ lúc tạo Lô nhập: sửa không gia hạn thời gian nội dung tạm nằm trên ổ.
     *
     * @throws MissingRole
     * @throws KeyFingerprintMismatch
     * @throws InvalidBatch
     */
    public function revise(User $actor, Batch $batch, BatchRevision $revision): Batch
    {
        $this->roles->authorize($actor, Role::NhapKho);
        $this->fingerprints->verify();
        self::ensureDedupeKeysSettled();

        $revised = DB::transaction(function () use ($batch, $revision): Batch {
            $current = Batch::query()->lockForUpdate()->findOrFail($batch->getKey());
            self::ensureValidated($current, 'sửa');

            $lines = $current->lines()->with(['product.productType'])->get()->keyBy('id');
            self::validateRevision($lines, $revision);

            $current->forceFill([
                'received_on' => $revision->receivedOn->toDateString(),
                'document_number' => self::blankToNull($revision->documentNumber),
                'note' => self::blankToNull($revision->note),
                'status' => BatchStatus::Validating,
                'validation_error' => null,
            ])->save();

            foreach ($revision->lines as $lineRevision) {
                $line = $lines[$lineRevision->line->getKey()];

                $line->forceFill([
                    'unit_cost' => $lineRevision->unitCost,
                    'slots' => $line->product->form() === StockForm::Account ? $lineRevision->slots : null,
                    'expires_on' => $lineRevision->expiry?->date?->toDateString(),
                    'expires_after_days' => $lineRevision->expiry?->days,
                ])->save();
            }

            return $current;
        });

        // Như lúc gửi lần đầu: job chỉ chạy khi lần sửa đã commit.
        ValidateBatch::dispatch($revised)->afterCommit();

        return $revised->refresh();
    }

    /**
     * Pha 1, chạy trong job: phân loại từng dòng theo cấu hình hiện tại của Sản phẩm.
     */
    public function validate(Batch $batch): void
    {
        $this->fingerprints->verify();
        // Phân loại bằng khoá HMAC mới trong khi kho còn hash cũ sẽ báo "không trùng" cho mã đã có
        // trong kho: thà để Lô nhập hỏng kiểm tra kèm lý do rõ ràng.
        self::ensureDedupeKeysSettled();

        DB::transaction(function () use ($batch): void {
            $current = Batch::query()->lockForUpdate()->findOrFail($batch->getKey());

            if ($current->status !== BatchStatus::Validating) {
                return;
            }

            try {
                $this->recordPreview($current);
            } catch (InvalidBatch $exception) {
                $current->forceFill(['status' => BatchStatus::ValidationFailed, 'validation_error' => $exception->getMessage()])->save();

                return;
            }

            $current->forceFill(['status' => BatchStatus::Validated])->save();
        });
    }

    /**
     * Job pha 1 thất bại. Lý do chỉ nêu lỗi khoá (không có giá trị khoá), không nêu nội dung dòng.
     */
    public function markValidationFailed(Batch $batch, ?Throwable $exception): void
    {
        Batch::query()
            ->whereKey($batch->getKey())
            ->where('status', BatchStatus::Validating)
            ->update([
                'status' => BatchStatus::ValidationFailed,
                'validation_error' => $exception instanceof KeyFingerprintMismatch || $exception instanceof InvalidBatch
                    ? $exception->getMessage()
                    : 'Lỗi hệ thống khi kiểm tra; hãy tạo lại Lô nhập.',
            ]);
    }

    /**
     * @throws MissingRole
     */
    public function preview(User $actor, Batch $batch): BatchPreview
    {
        $this->roles->authorize($actor, Role::NhapKho);

        $batch = Batch::query()->with('lines.product')->findOrFail($batch->getKey());

        return new BatchPreview(
            status: $batch->status,
            validationError: $batch->validation_error,
            lines: $batch->lines->map(fn (BatchLine $line): BatchLinePreview => new BatchLinePreview(
                productName: $line->product->name,
                source: $line->source,
                fileName: $line->file_name,
                validCount: $line->valid_count,
                renewalCount: $line->renewal_count,
                invalidCount: $line->invalid_count,
                fileDuplicateCount: $line->file_duplicate_count,
                stockDuplicateCount: $line->stock_duplicate_count,
                rejected: array_map(
                    fn (array $row): RejectedLine => new RejectedLine($row['line'], LineClass::from($row['class']), $row['reason']),
                    $line->preview['rejected'] ?? [],
                ),
                sample: $line->preview['sample'] ?? [],
                ignoredColumns: $line->preview['ignored_columns'] ?? [],
                totalCost: $line->total_cost,
                reversedCount: $line->reversed_count,
                slots: $line->preview['slots'] ?? [],
                expiresOn: $line->preview['expires_on'] ?? [],
            ))->values()->all(),
            invoiceTotal: $batch->invoice_total,
            supplementsBatchId: $batch->supplements_batch_id,
        );
    }

    /**
     * Pha 2: ghi phần nhập được vào kho, mỗi Đơn vị hàng đủ số Slot Còn hàng, rồi đóng Lô nhập.
     * Dòng trùng trong kho chỉ được bỏ qua khi nhân viên tick xác nhận riêng, kể cả dòng mới
     * thành trùng lúc ghi (Lô nhập khác vừa nhập cùng mã): khi đó không ghi gì, kết quả xem
     * trước được cập nhật để nhân viên xem lại và tick.
     *
     * @throws MissingRole
     * @throws KeyFingerprintMismatch
     * @throws InvalidBatch
     */
    public function confirm(User $actor, Batch $batch, bool $skipStockDuplicates = false): Batch
    {
        $this->roles->authorize($actor, Role::NhapKho);
        $this->fingerprints->verify();
        self::ensureDedupeKeysSettled();

        try {
            return $this->write($actor, $batch, $skipStockDuplicates);
        } catch (StockDuplicatesAtWrite) {
            $current = Batch::query()->findOrFail($batch->getKey());
            $this->recordPreview($current);

            throw self::stockDuplicatesNeedAcknowledgement((int) $current->lines()->sum('stock_duplicate_count'));
        } catch (Throwable $exception) {
            // Dòng bị bỏ ghi ra disk trong transaction: xác nhận thất bại thì không để lại file.
            foreach (BatchLine::query()->where('batch_id', $batch->getKey())->get() as $line) {
                $this->pending->forgetRejected($line);
            }

            throw $exception;
        }
    }

    /**
     * @throws InvalidBatch
     * @throws StockDuplicatesAtWrite
     */
    private function write(User $actor, Batch $batch, bool $skipStockDuplicates): Batch
    {
        // Deadlock giữa hai Lô nhập khác Sản phẩm chèn cùng mã theo thứ tự khác nhau: chạy lại.
        return DB::transaction(function () use ($actor, $batch, $skipStockDuplicates): Batch {
            $current = Batch::query()->lockForUpdate()->findOrFail($batch->getKey());
            self::ensureValidated($current, 'xác nhận');

            // Hàng thay thế: khoá Khiếu nại để hai Lô nhập xác nhận cùng lúc không vượt số được thay.
            $claim = $current->supplier_claim_id === null ? null : SupplierClaim::query()->lockForUpdate()->findOrFail($current->supplier_claim_id);

            $stockDuplicates = (int) $current->lines->sum('stock_duplicate_count');

            if ($stockDuplicates > 0 && ! $skipStockDuplicates) {
                throw self::stockDuplicatesNeedAcknowledgement($stockDuplicates);
            }

            // Cùng khoá hàng ProductCatalog và ProductTypeCatalog dùng khi sửa: không nhập theo
            // khai báo đang bị đổi.
            // Khoá theo thứ tự id để hai Lô nhập chung Sản phẩm không deadlock.
            $products = Product::query()
                ->whereIn('id', $current->lines->pluck('product_id'))
                ->orderBy('id')
                ->lockForUpdate()
                ->with(['contentFields', 'productType'])
                ->get()
                ->keyBy('id')
                ->all();

            $results = $this->classifyBatch($current, $current->lines, $products);

            foreach ($current->lines as $line) {
                $product = $products[$line->product_id];
                [$classified, $ignoredColumns] = $results[$line->id];

                self::recordClassification($line, $product, $this->store($actor, $current, $line, $product, $classified), $ignoredColumns);

                if ($line->valid_count + $line->renewal_count > 0 && ! $product->hasStock()) {
                    $product->forceFill(['stocked_at' => now()])->save();
                }
            }

            // Đếm sau khi ghi: dòng thành trùng lúc ghi không tính. Vượt thì cả transaction bị huỷ.
            if ($claim !== null && $claim->replacementGoodsImported() > $claim->replacementGoodsAllowance()) {
                throw new InvalidBatch(sprintf(
                    'Khiếu nại #%d chỉ có %d Đơn vị hàng được Hàng thay thế; Lô nhập này làm số hàng thay thế đã nhập thành %d.',
                    $claim->id,
                    $claim->replacementGoodsAllowance(),
                    $claim->replacementGoodsImported(),
                ));
            }

            if (! $skipStockDuplicates && $current->lines->sum('stock_duplicate_count') > 0) {
                throw new StockDuplicatesAtWrite;
            }

            $current->forceFill([
                'status' => BatchStatus::Confirmed,
                'confirmed_by' => $actor->getKey(),
                'confirmed_at' => now(),
            ])->save();

            // Màn kết quả ngay sau xác nhận vẫn tải được dòng bị bỏ khi nội dung tạm đã xoá.
            foreach ($current->lines as $line) {
                if (self::hasRejected($line)) {
                    $this->pending->putRejected($line, $this->rejectedCsv($line));
                }
            }

            DB::afterCommit(fn () => $this->forgetPending($current));

            return $current;
        }, attempts: 3);
    }

    /**
     * Bỏ bản kiểm tra chưa xác nhận: nội dung tạm bị xoá, Lô nhập giữ lại với trạng thái Đã bỏ.
     *
     * @throws MissingRole
     * @throws InvalidBatch
     */
    public function discard(User $actor, Batch $batch): Batch
    {
        $this->roles->authorize($actor, Role::NhapKho);

        return DB::transaction(function () use ($batch): Batch {
            $current = Batch::query()->lockForUpdate()->findOrFail($batch->getKey());

            if ($current->status === BatchStatus::Confirmed) {
                throw new InvalidBatch('Lô nhập đã xác nhận, không bỏ được.');
            }

            if (in_array($current->status, BatchStatus::pending(), true)) {
                $current->forceFill(['status' => BatchStatus::Discarded])->save();
            }

            DB::afterCommit(fn () => $this->forgetPending($current));

            return $current;
        });
    }

    /**
     * Chạy định kỳ: Lô nhập chưa xác nhận quá hạn xác nhận chuyển Quá hạn xác nhận; nội dung tạm
     * và file upload tạm cũ bị xoá.
     *
     * @return int số Lô nhập vừa hết hạn
     */
    public function purgeExpired(): int
    {
        $expired = Batch::query()
            ->whereIn('status', BatchStatus::pending())
            ->where('created_at', '<', self::staleCutoff())
            ->update(['status' => BatchStatus::Expired]);

        $pendingLineIds = BatchLine::query()
            ->whereHas('batch', fn ($query) => $query->whereIn('status', BatchStatus::pending()))
            ->pluck('id')
            ->all();

        $this->pending->purgeExcept($pendingLineIds, CarbonImmutable::now()->subHour());
        $this->pending->purgeRejectedBefore(CarbonImmutable::now()->subMinutes(self::rejectedDownloadMinutes()));
        self::purgeUploadsWrittenBefore(self::staleCutoff());

        return $expired;
    }

    /**
     * Các Dòng nhập có dòng bị bỏ mà nhân viên tải được lúc này; rỗng khi không phải người tạo,
     * thiếu Vai trò hoặc đã qua màn xem trước và thời hạn ngay sau xác nhận.
     *
     * @return Collection<int, BatchLine>
     */
    public function downloadableRejectedLines(User $actor, Batch $batch): Collection
    {
        if (! $this->roles->allows($actor, Role::NhapKho)
            || (int) $batch->created_by !== (int) $actor->getKey()
            || ! self::isRejectedDownloadOpen($batch)) {
            return new Collection;
        }

        return $batch->lines->filter(self::hasRejected(...))->values();
    }

    private static function hasRejected(BatchLine $line): bool
    {
        return ($line->preview['rejected'] ?? []) !== [];
    }

    /**
     * CSV các dòng bị bỏ của một Dòng nhập, nguyên văn, để gửi lại Nhà cung cấp. Chỉ người tạo
     * Lô nhập tải được, ở màn xem trước hoặc ngay sau khi xác nhận; mỗi lần tải ghi Nhật ký
     * xem mã với ngữ cảnh Lô nhập trong cùng transaction, trước khi trả nội dung.
     *
     * @throws MissingRole
     * @throws InvalidBatch
     */
    public function rejectedLines(User $actor, BatchLine $line): RejectedLinesExport
    {
        $this->roles->authorize($actor, Role::NhapKho);

        return DB::transaction(function () use ($actor, $line): RejectedLinesExport {
            $line = BatchLine::query()->with(['batch', 'product'])->findOrFail($line->getKey());
            $batch = $line->batch;

            if ((int) $batch->created_by !== (int) $actor->getKey()) {
                throw new InvalidBatch('Chỉ người tạo Lô nhập tải được dòng bị bỏ.');
            }

            if (! self::isRejectedDownloadOpen($batch)) {
                throw self::rejectedDownloadClosed();
            }

            if (! self::hasRejected($line)) {
                throw new InvalidBatch('Dòng nhập không có dòng bị bỏ.');
            }

            $this->revealLog->record(
                RevealActor::staff($actor),
                RevealContext::batch($batch),
                "Tải dòng bị bỏ của Dòng nhập #{$line->id} \"{$line->product->name}\"",
            );

            $csv = $batch->status === BatchStatus::Validated
                ? $this->rejectedCsv($line)
                : ($this->pending->getRejected($line) ?? throw self::rejectedDownloadClosed());

            return new RejectedLinesExport(
                sprintf('lo-nhap-%d-%s-dong-bi-bo.csv', $batch->id, preg_replace('/[^A-Za-z0-9_-]+/', '-', $line->product->code)),
                $csv,
            );
        });
    }

    /**
     * CSV (UTF-8 có BOM để Excel đọc đúng) các dòng bị bỏ theo kết quả kiểm tra đã ghi: số
     * dòng, loại, lý do, rồi nguyên văn dòng dán hoặc các ô gốc dưới dòng tiêu đề gốc của file.
     *
     * @throws InvalidBatch nội dung tạm đã bị xoá
     */
    private function rejectedCsv(BatchLine $line): string
    {
        $rejected = $line->preview['rejected'] ?? [];
        [$header, $rows] = $this->reader->rawRows($this->pending->get($line), $line->source, array_column($rejected, 'line'));

        $handle = fopen('php://temp', 'r+');
        assert($handle !== false);

        try {
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['Dòng', 'Loại', 'Lý do', ...array_map(self::utf8(...), $header ?? ['Nội dung'])], ',', '"', '');

            foreach ($rejected as $row) {
                fputcsv($handle, [
                    (string) $row['line'],
                    LineClass::from($row['class'])->label(),
                    $row['reason'],
                    ...array_map(self::utf8(...), $rows[$row['line']] ?? []),
                ], ',', '"', '');
            }

            rewind($handle);

            return (string) stream_get_contents($handle);
        } finally {
            fclose($handle);
        }
    }

    /**
     * Dòng lỗi vì không phải UTF-8 vẫn được trả lại, thay byte hỏng để file CSV hợp lệ.
     */
    private static function utf8(string $value): string
    {
        return mb_scrub($value, 'UTF-8');
    }

    /**
     * Lô nhập đang ở màn xem trước, hoặc vừa xác nhận trong thời hạn tải dòng bị bỏ.
     */
    private static function isRejectedDownloadOpen(Batch $batch): bool
    {
        return match ($batch->status) {
            BatchStatus::Validated => $batch->created_at !== null && $batch->created_at->gte(self::staleCutoff()),
            BatchStatus::Confirmed => $batch->confirmed_at !== null
                && $batch->confirmed_at->gte(CarbonImmutable::now()->subMinutes(self::rejectedDownloadMinutes())),
            default => false,
        };
    }

    private static function rejectedDownloadClosed(): InvalidBatch
    {
        return new InvalidBatch(sprintf(
            'Dòng bị bỏ chỉ tải được ở màn xem trước hoặc ngay sau khi xác nhận (trong %d phút).',
            self::rejectedDownloadMinutes(),
        ));
    }

    private static function rejectedDownloadMinutes(): int
    {
        return (int) config('inventory.intake.rejected_download_minutes');
    }

    /**
     * Phân loại lại mọi Dòng nhập theo kho hiện tại và ghi kết quả xem trước; không ghi kho.
     *
     * @throws InvalidBatch nội dung tạm đã bị xoá hoặc không đọc được
     */
    private function recordPreview(Batch $batch): void
    {
        $lines = $batch->lines()->with(['product.contentFields', 'product.productType'])->get();
        $results = $this->classifyBatch($batch, $lines, $lines->mapWithKeys(fn (BatchLine $line): array => [$line->product_id => $line->product])->all());

        foreach ($lines as $line) {
            self::recordClassification($line, $line->product, ...$results[$line->id]);
        }
    }

    /**
     * Nhập hàng tạm dừng trong lúc xoay khoá HMAC: kho không bao giờ giữ song song hai hash Khoá
     * chống trùng, nên ghi thêm hàng lúc này là để lọt dòng trùng.
     *
     * @throws InvalidBatch
     */
    private static function ensureDedupeKeysSettled(): void
    {
        if (KeyRotation::hasStaleDedupeHashes()) {
            throw new InvalidBatch('Đang xoay khoá mã hoá HMAC nên nhập hàng tạm dừng; chạy xong lệnh xoay khoá rồi thử lại.');
        }
    }

    private static function stockDuplicatesNeedAcknowledgement(int $count): InvalidBatch
    {
        return new InvalidBatch(sprintf(
            'Có %s dòng trùng trong kho; hãy tick xác nhận bỏ qua các dòng này rồi xác nhận lại.',
            self::formatCount($count),
        ));
    }

    /**
     * @throws InvalidBatch
     */
    private function ensureReadable(BatchLineDraft $line): void
    {
        $name = $line->product->name;

        try {
            $rows = count($this->reader->read($line->product->loadMissing(['contentFields', 'productType']), $line->content, $line->source, $line->separator)->rows);
        } catch (InvalidBatch $exception) {
            throw new InvalidBatch("Dòng nhập \"{$name}\": {$exception->getMessage()}");
        }

        if ($rows === 0) {
            throw new InvalidBatch("Dòng nhập \"{$name}\" chưa có nội dung.");
        }

        if ($rows > self::maxLines()) {
            throw new InvalidBatch(sprintf(
                'Dòng nhập "%s" có %s dòng, vượt giới hạn %s dòng mỗi file hoặc danh sách dán.',
                $name,
                self::formatCount($rows),
                self::formatCount(self::maxLines()),
            ));
        }
    }

    /**
     * Phân loại mọi Dòng nhập. Dòng trùng Khoá chống trùng với một dòng đứng trước ở Dòng nhập
     * khác (cùng loại hàng) xếp vào trùng trong file.
     *
     * @param  Collection<int, BatchLine>  $lines
     * @param  array<int, Product>  $products  theo id
     * @return array<int, array{list<ClassifiedLine>, list<string>}> theo id Dòng nhập: dòng đã phân loại, cột bị bỏ qua
     *
     * @throws InvalidBatch nội dung tạm đã bị xoá hoặc không đọc được
     */
    private function classifyBatch(Batch $batch, Collection $lines, array $products): array
    {
        $seen = [];
        $results = [];

        foreach ($lines as $line) {
            $product = $products[$line->product_id];
            $source = $this->reader->read($product, $this->pending->get($line), $line->source, $line->separator);

            $classified = array_map(function (ClassifiedLine $row) use (&$seen, $product, $batch): ClassifiedLine {
                if (! $row->isImportable()) {
                    return $row;
                }

                $key = $product->form()->value.':'.$row->dedupeHash;

                if (isset($seen[$key])) {
                    return $row->asFileDuplicate(sprintf('Trùng Khoá chống trùng với dòng %d của Dòng nhập "%s".', ...$seen[$key]));
                }

                $seen[$key] = [$row->lineNumber, $product->name];

                // Hàng thay thế từ Khiếu nại nhà cung cấp: Giá vốn 0, bỏ qua cột gia_von của file.
                return $batch->supplier_claim_id === null ? $row : $row->withoutCost();
            }, $this->classifier->classify($product, $source, self::defaults($batch, $line, $product)));

            $results[$line->id] = [$classified, $source->ignoredColumns];
        }

        return $results;
    }

    /**
     * Chèn các dòng nhập được. Tài khoản nhập lại nhả khoá của Đơn vị hàng cũ trước (chỉ khi cái
     * cũ vẫn Huỷ hàng hoặc quá Hạn sử dụng). Dòng bị Lô nhập khác chiếm mã trước (ON CONFLICT)
     * chuyển thành trùng trong kho.
     *
     * @param  list<ClassifiedLine>  $classified
     * @return list<ClassifiedLine>
     */
    private function store(User $actor, Batch $batch, BatchLine $line, Product $product, array $classified): array
    {
        $sensitive = $product->contentFields->where('sensitive', true)->pluck('key')->flip()->all();
        $importable = array_values(array_filter($classified, fn (ClassifiedLine $row): bool => $row->isImportable()));
        $dedupeHmacVersion = $this->crypto->hmacKeyVersion();
        $inserted = [];

        foreach (array_chunk($importable, self::INSERT_CHUNK) as $chunk) {
            $now = now();
            $released = self::releaseRenewedKeys($chunk);
            $rows = [];

            foreach ($chunk as $row) {
                if ($row->renewsStockUnitId === null || isset($released[$row->renewsStockUnitId])) {
                    $rows[(string) $row->dedupeHash] = $row;
                }
            }

            if ($rows === []) {
                continue;
            }

            $units = DB::table('stock_units')->insertOrIgnoreReturning(array_map(function (ClassifiedLine $row) use ($line, $product, $sensitive, $dedupeHmacVersion, $now): array {
                $secret = array_intersect_key($row->values, $sensitive);
                $plain = array_diff_key($row->values, $sensitive);
                $encrypted = $secret === [] ? null : $this->crypto->encrypt((string) json_encode($secret));

                return [
                    'batch_line_id' => $line->id,
                    'product_id' => $product->id,
                    'kind' => $product->form()->value,
                    'status' => StockUnitStatus::Active->value,
                    'unit_cost' => $row->unitCost,
                    'slot_count' => $row->slots,
                    'expires_on' => $row->expiresOn,
                    'renews_stock_unit_id' => $row->renewsStockUnitId,
                    'holds_dedupe_key' => true,
                    'dedupe_hash' => $row->dedupeHash,
                    'dedupe_hmac_version' => $dedupeHmacVersion,
                    'content' => $plain === [] ? null : json_encode($plain),
                    'secret_ciphertext' => $encrypted?->ciphertext,
                    'secret_key_version' => $encrypted?->keyVersion,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }, array_values($rows)), ['id', 'dedupe_hash']);

            $slotRows = [];
            $transitions = [];

            foreach ($units as $unit) {
                $row = $rows[$unit->dedupe_hash];
                $inserted[$unit->dedupe_hash] = true;
                $transitions[] = StockTransition::unitCreated((int) $unit->id);

                foreach (self::splitCost($row->unitCost, $row->slots) as $cost) {
                    $slotRows[] = [
                        'stock_unit_id' => $unit->id,
                        'status' => SlotStatus::InStock->value,
                        'cost' => $cost,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            foreach (array_chunk($slotRows, self::SLOT_INSERT_CHUNK) as $slotChunk) {
                foreach (DB::table('slots')->insertOrIgnoreReturning($slotChunk, ['id', 'stock_unit_id']) as $slot) {
                    $transitions[] = StockTransition::slotCreated((int) $slot->stock_unit_id, (int) $slot->id);
                }
            }

            $this->ledger->append($actor, $transitions, "Nhập hàng theo Lô nhập #{$batch->id}");
        }

        return array_map(
            fn (ClassifiedLine $row): ClassifiedLine => $row->isImportable() && ! isset($inserted[$row->dedupeHash])
                ? $row->asStockDuplicate()
                : $row,
            $classified,
        );
    }

    /**
     * Nhả Khoá chống trùng của các Tài khoản cũ được nhập lại. Điều kiện kiểm tra lại dưới khoá
     * hàng, nên hai Lô nhập cùng nhập lại một Tài khoản thì chỉ một bên được.
     *
     * @param  list<ClassifiedLine>  $rows
     * @return array<int, true> id Đơn vị hàng cũ đã nhả khoá
     */
    private static function releaseRenewedKeys(array $rows): array
    {
        $ids = array_values(array_filter(array_map(fn (ClassifiedLine $row): ?int => $row->renewsStockUnitId, $rows)));

        if ($ids === []) {
            return [];
        }

        $released = DB::select(
            sprintf(
                'UPDATE stock_units SET holds_dedupe_key = false, updated_at = ? WHERE id IN (%s) AND kind = ? AND holds_dedupe_key AND (status = ? OR expires_on < ?) RETURNING id',
                implode(', ', array_fill(0, count($ids), '?')),
            ),
            [now(), ...$ids, StockForm::Account->value, StockUnitStatus::Voided->value, CarbonImmutable::today()->toDateString()],
        );

        return array_fill_keys(array_map(fn (object $row): int => (int) $row->id, $released), true);
    }

    /**
     * Giá vốn Đơn vị hàng chia đều cho số slot; phần dư dồn vào slot đầu để tổng khớp.
     *
     * @return list<int>
     */
    private static function splitCost(int $cost, int $slots): array
    {
        $share = intdiv($cost, $slots);
        $costs = array_fill(0, $slots, $share);
        $costs[0] += $cost - $share * $slots;

        return $costs;
    }

    /**
     * @param  list<ClassifiedLine>  $classified
     * @param  list<string>  $ignoredColumns
     */
    private static function recordClassification(BatchLine $line, Product $product, array $classified, array $ignoredColumns): void
    {
        $count = fn (LineClass $class): int => count(array_filter($classified, fn (ClassifiedLine $row): bool => $row->class === $class));
        $importable = array_values(array_filter($classified, fn (ClassifiedLine $row): bool => $row->isImportable()));
        $rejected = array_values(array_filter($classified, fn (ClassifiedLine $row): bool => ! $row->isImportable()));

        // Giá trị áp cho Đơn vị hàng đã chốt: cột file ghi đè từng dòng nên một Dòng nhập ra
        // nhiều giá trị được. Giữ lại các giá trị phân biệt để màn xem trước nói ra con số thật.
        $distinct = function (callable $of) use ($importable): array {
            $values = array_values(array_unique(array_map($of, $importable), SORT_REGULAR));
            sort($values);

            return $values;
        };

        $line->forceFill([
            'valid_count' => $count(LineClass::Valid),
            'renewal_count' => $count(LineClass::Renewal),
            'invalid_count' => $count(LineClass::Invalid),
            'file_duplicate_count' => $count(LineClass::FileDuplicate),
            'stock_duplicate_count' => $count(LineClass::StockDuplicate),
            'total_cost' => array_sum(array_map(fn (ClassifiedLine $row): int => $row->unitCost, $importable)),
            'preview' => [
                'rejected' => array_map(fn (ClassifiedLine $row): array => [
                    'line' => $row->lineNumber,
                    'class' => $row->class->value,
                    'reason' => (string) $row->reason,
                ], $rejected),
                'sample' => array_map(fn (ClassifiedLine $row): array => self::sample($product, $row), array_slice($importable, 0, self::SAMPLE_SIZE)),
                'ignored_columns' => $ignoredColumns,
                'slots' => $distinct(fn (ClassifiedLine $row): int => $row->slots),
                'expires_on' => $distinct(fn (ClassifiedLine $row): ?string => $row->expiresOn),
            ],
        ])->save();
    }

    /**
     * @return array<string, string>
     */
    private static function sample(Product $product, ClassifiedLine $row): array
    {
        $sample = MaskedContent::of($product->contentFields, $row->values);

        if ($product->form() === StockForm::Account) {
            $sample['Số slot'] = (string) $row->slots;
        }

        if ($row->expiresOn !== null) {
            $sample['Hạn sử dụng'] = CarbonImmutable::parse($row->expiresOn)->format('d/m/Y');
        }

        return $sample;
    }

    private static function defaults(Batch $batch, BatchLine $line, Product $product): LineDefaults
    {
        $expiry = match (true) {
            $line->expires_on !== null => ExpiryRule::on($line->expires_on),
            $line->expires_after_days !== null => ExpiryRule::afterDays($line->expires_after_days),
            default => null,
        };

        return new LineDefaults(
            receivedOn: $batch->received_on,
            unitCost: $line->unit_cost,
            slots: $product->form() === StockForm::OneTimeCode ? 1 : ($line->slots ?? $product->default_slots),
            expiresOn: $expiry?->resolve($batch->received_on),
        );
    }

    private function forgetPending(Batch $batch): void
    {
        foreach ($batch->lines as $line) {
            $this->pending->forget($line);
        }
    }

    /**
     * Xác nhận và sửa cùng một điều kiện: bản kiểm tra đã xong và còn trong hạn. Trước đó chưa
     * có gì để xem mà quyết, sau đó thì hàng đã vào kho hoặc nội dung tạm đã bị xoá.
     *
     * @param  string  $action  việc đang làm, để câu cuối đọc tự nhiên ("chưa xác nhận được")
     *
     * @throws InvalidBatch
     */
    private static function ensureValidated(Batch $batch, string $action): void
    {
        match ($batch->status) {
            BatchStatus::Validated => null,
            BatchStatus::Confirmed => throw new InvalidBatch('Lô nhập đã xác nhận và đã đóng.'),
            BatchStatus::Discarded => throw new InvalidBatch('Lô nhập đã bị bỏ.'),
            BatchStatus::Expired => throw self::expired(),
            BatchStatus::ValidationFailed => throw new InvalidBatch('Lô nhập kiểm tra thất bại; hãy tạo lại Lô nhập.'),
            BatchStatus::Validating => throw new InvalidBatch("Lô nhập chưa kiểm tra xong, chưa {$action} được."),
        };

        if ($batch->created_at !== null && $batch->created_at->lt(self::staleCutoff())) {
            throw self::expired();
        }
    }

    private static function expired(): InvalidBatch
    {
        return new InvalidBatch(sprintf(
            'Bản kiểm tra quá %d giờ chưa xác nhận nên nội dung tạm đã bị xoá; hãy tạo lại Lô nhập.',
            (int) config('inventory.intake.pending_ttl_hours'),
        ));
    }

    /**
     * File upload tạm của Livewire (bản rõ) còn sót khi form nhập hàng lỗi hoặc bị bỏ dở.
     */
    private static function purgeUploadsWrittenBefore(CarbonImmutable $cutoff): void
    {
        $storage = FileUploadConfiguration::storage();

        foreach ($storage->files(FileUploadConfiguration::directory()) as $path) {
            if ($storage->lastModified($path) < $cutoff->getTimestamp()) {
                $storage->delete($path);
            }
        }
    }

    private static function staleCutoff(): CarbonImmutable
    {
        return CarbonImmutable::now()->subHours((int) config('inventory.intake.pending_ttl_hours'));
    }

    private static function maxLines(): int
    {
        return (int) config('inventory.intake.max_lines');
    }

    /**
     * Mỗi Dòng nhập của Lô nhập phải có mặt đúng một lần: nội dung không sửa được nên tập Dòng
     * nhập cũng không đổi, và một Dòng nhập bị bỏ quên sẽ im lặng giữ giá trị cũ.
     *
     * @param  Collection<int, BatchLine>  $lines  theo id
     *
     * @throws InvalidBatch
     */
    private static function validateRevision(Collection $lines, BatchRevision $revision): void
    {
        $seen = [];

        foreach ($revision->lines as $lineRevision) {
            $id = (int) $lineRevision->line->getKey();
            $line = $lines->get($id) ?? throw new InvalidBatch('Dòng nhập không thuộc Lô nhập này.');

            if (isset($seen[$id])) {
                throw new InvalidBatch("Dòng nhập \"{$line->product->name}\" được nêu hai lần trong cùng lần sửa.");
            }

            $seen[$id] = true;
            self::validateUnitValues($line->product, $lineRevision->unitCost, $lineRevision->slots, $lineRevision->expiry?->days);
        }

        foreach ($lines as $line) {
            if (! isset($seen[$line->id])) {
                throw new InvalidBatch(sprintf(
                    'Phải nêu Giá trị áp cho Đơn vị hàng của mọi Dòng nhập; thiếu Dòng nhập "%s".',
                    $line->product->name,
                ));
            }
        }
    }

    /**
     * Luật của Giá trị áp cho Đơn vị hàng khai ở Dòng nhập, dùng chung cho lần gửi đầu và lần
     * sửa để hai đường ra cùng một thông báo.
     *
     * @throws InvalidBatch
     */
    private static function validateUnitValues(Product $product, int $unitCost, ?int $slots, ?int $expiryDays): void
    {
        $name = $product->name;

        if ($unitCost < 0) {
            throw new InvalidBatch("Giá vốn của Dòng nhập \"{$name}\" không được âm.");
        }

        if ($slots !== null && $product->form() === StockForm::OneTimeCode && $slots !== 1) {
            throw new InvalidBatch("Mã dùng một lần luôn có đúng 1 slot (Dòng nhập \"{$name}\").");
        }

        if ($slots !== null && ($slots < 1 || $slots > LineClassifier::MAX_SLOTS)) {
            throw new InvalidBatch(sprintf('Số slot của Dòng nhập "%s" phải từ 1 đến %s.', $name, self::formatCount(LineClassifier::MAX_SLOTS)));
        }

        if ($expiryDays !== null && $expiryDays < 0) {
            throw new InvalidBatch("Số ngày Hạn sử dụng của Dòng nhập \"{$name}\" không được âm.");
        }
    }

    /**
     * @throws InvalidBatch
     */
    private static function validateDraft(BatchDraft $draft): void
    {
        if ($draft->lines === []) {
            throw new InvalidBatch('Lô nhập phải có ít nhất một Dòng nhập.');
        }

        if ($draft->invoiceTotal !== null && $draft->invoiceTotal < 0) {
            throw new InvalidBatch('Tổng tiền hoá đơn không được âm.');
        }

        if ($draft->supplements !== null
            && ! Batch::query()->whereKey($draft->supplements->getKey())->where('status', BatchStatus::Confirmed)->exists()) {
            throw new InvalidBatch('Chỉ bổ sung cho Lô nhập đã xác nhận.');
        }

        if ($draft->supplierClaim !== null) {
            self::validateReplacementGoodsDraft($draft, $draft->supplierClaim);
        }

        $maxBytes = (int) config('inventory.intake.max_bytes');
        $products = [];

        foreach ($draft->lines as $line) {
            $name = $line->product->name;

            if (isset($products[$line->product->getKey()])) {
                throw new InvalidBatch("Sản phẩm \"{$name}\" có hai Dòng nhập; mỗi Sản phẩm một Dòng nhập.");
            }

            $products[$line->product->getKey()] = true;

            self::validateUnitValues($line->product, $line->unitCost, $line->slots, $line->expiry?->days);

            if (trim($line->content) === '') {
                throw new InvalidBatch("Dòng nhập \"{$name}\" chưa có nội dung.");
            }

            if (strlen($line->content) > $maxBytes) {
                throw new InvalidBatch(sprintf('Dòng nhập "%s" vượt giới hạn %s mỗi file hoặc danh sách dán.', $name, Number::fileSize($maxBytes)));
            }

            if ($line->source === IntakeSource::Paste && $line->separator === '') {
                throw new InvalidBatch("Dòng nhập \"{$name}\" chưa chọn ký tự phân tách.");
            }
        }
    }

    /**
     * Lô nhập hàng thay thế: cùng Nhà cung cấp với Khiếu nại Đã giải quyết có kết quả Hàng thay thế,
     * mọi Dòng nhập Giá vốn 0.
     *
     * @throws InvalidBatch
     */
    private static function validateReplacementGoodsDraft(BatchDraft $draft, SupplierClaim $claim): void
    {
        $eligible = SupplierClaim::query()->acceptsReplacementGoods()->whereKey($claim->getKey())->first();

        if ($eligible === null) {
            throw new InvalidBatch('Chỉ nhập hàng thay thế cho Khiếu nại Đã giải quyết có kết quả Hàng thay thế.');
        }

        if ($eligible->supplier_id !== (int) $draft->supplier->getKey()) {
            throw new InvalidBatch('Lô nhập hàng thay thế phải cùng Nhà cung cấp với Khiếu nại.');
        }

        if ($eligible->replacementGoodsImported() >= $eligible->replacementGoodsAllowance()) {
            throw new InvalidBatch(sprintf('Khiếu nại #%d đã nhập đủ %d Đơn vị hàng thay thế.', $eligible->id, $eligible->replacementGoodsAllowance()));
        }

        foreach ($draft->lines as $line) {
            if ($line->unitCost !== 0) {
                throw new InvalidBatch("Hàng thay thế từ Khiếu nại nhà cung cấp có Giá vốn 0 (Dòng nhập \"{$line->product->name}\").");
            }
        }
    }

    private static function formatCount(int $number): string
    {
        return number_format($number, 0, ',', '.');
    }

    private static function blankToNull(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : trim($value);
    }
}
