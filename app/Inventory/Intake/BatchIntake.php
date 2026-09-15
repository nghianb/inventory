<?php

namespace App\Inventory\Intake;

use App\Inventory\Access\MissingRole;
use App\Inventory\Access\Role;
use App\Inventory\Access\RoleGate;
use App\Inventory\Catalog\ProductType;
use App\Inventory\Encryption\ContentCrypto;
use App\Inventory\Encryption\EncryptedContent;
use App\Inventory\Encryption\KeyFingerprintMismatch;
use App\Inventory\Encryption\KeyFingerprints;
use App\Inventory\Stock\MaskedContent;
use App\Inventory\Stock\SlotStatus;
use App\Inventory\Stock\StockLedger;
use App\Inventory\Stock\StockTransition;
use App\Inventory\Stock\StockUnitStatus;
use App\Models\Batch;
use App\Models\BatchLine;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Nhập hàng hai pha. Nhân viên gửi Lô nhập (văn bản dán được mã hoá ngay) → job phân loại
 * từng dòng để xem trước → nhân viên xác nhận thì chỉ phần hợp lệ vào kho. Lúc ghi thật
 * phân loại lại dưới khoá hàng Sản phẩm và chèn với ON CONFLICT, nên cấu hình Sản phẩm
 * đổi hay Lô nhập khác vừa ghi cùng mã cũng không làm hỏng kho.
 */
class BatchIntake
{
    private const SAMPLE_SIZE = 5;

    private const INSERT_CHUNK = 1_000;

    public function __construct(
        private RoleGate $roles,
        private KeyFingerprints $fingerprints,
        private ContentCrypto $crypto,
        private LineClassifier $classifier,
        private StockLedger $ledger,
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

        $batch = DB::transaction(function () use ($actor, $draft): Batch {
            $batch = new Batch;
            $batch->forceFill([
                'supplier_id' => $draft->supplier->getKey(),
                'received_on' => $draft->receivedOn->toDateString(),
                'document_number' => self::blankToNull($draft->documentNumber),
                'note' => self::blankToNull($draft->note),
                'status' => BatchStatus::Validating,
                'created_by' => $actor->getKey(),
            ])->save();

            foreach ($draft->lines as $line) {
                $pending = $this->crypto->encrypt($line->content);

                (new BatchLine)->forceFill([
                    'batch_id' => $batch->id,
                    'product_id' => $line->product->getKey(),
                    'unit_cost' => $line->unitCost,
                    'separator' => $line->separator,
                    'pending_ciphertext' => $pending->ciphertext,
                    'pending_key_version' => $pending->keyVersion,
                ])->save();
            }

            return $batch;
        });

        // Người gọi có thể đang trong transaction (trang Filament): job chỉ chạy khi Lô nhập đã commit.
        ValidateBatch::dispatch($batch)->afterCommit();

        return $batch->refresh();
    }

    /**
     * Pha 1, chạy trong job: phân loại từng dòng theo cấu hình hiện tại của Sản phẩm.
     */
    public function validate(Batch $batch): void
    {
        $this->fingerprints->verify();

        DB::transaction(function () use ($batch): void {
            $current = Batch::query()->lockForUpdate()->findOrFail($batch->getKey());

            if ($current->status !== BatchStatus::Validating) {
                return;
            }

            foreach ($current->lines()->with('product.contentFields')->get() as $line) {
                self::recordClassification($line, $line->product, $this->classifyPending($line, $line->product));
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
                'validation_error' => $exception instanceof KeyFingerprintMismatch
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
                validCount: $line->valid_count,
                invalidCount: $line->invalid_count,
                fileDuplicateCount: $line->file_duplicate_count,
                stockDuplicateCount: $line->stock_duplicate_count,
                rejected: array_map(
                    fn (array $row): RejectedLine => new RejectedLine($row['line'], LineClass::from($row['class']), $row['reason']),
                    $line->preview['rejected'] ?? [],
                ),
                sample: $line->preview['sample'] ?? [],
                totalCost: $line->valid_count * $line->unit_cost,
            ))->values()->all(),
        );
    }

    /**
     * Pha 2: ghi phần hợp lệ vào kho, mỗi Đơn vị hàng một Slot Còn hàng, rồi đóng Lô nhập.
     *
     * @throws MissingRole
     * @throws KeyFingerprintMismatch
     * @throws InvalidBatch
     */
    public function confirm(User $actor, Batch $batch): Batch
    {
        $this->roles->authorize($actor, Role::NhapKho);
        $this->fingerprints->verify();

        return DB::transaction(function () use ($actor, $batch): Batch {
            $current = Batch::query()->lockForUpdate()->findOrFail($batch->getKey());

            match ($current->status) {
                BatchStatus::Validated => null,
                BatchStatus::Confirmed => throw new InvalidBatch('Lô nhập đã xác nhận và đã đóng.'),
                default => throw new InvalidBatch('Lô nhập chưa kiểm tra xong, chưa xác nhận được.'),
            };

            foreach ($current->lines as $line) {
                // Cùng khoá hàng ProductCatalog dùng khi sửa: không nhập theo cấu hình đang bị đổi.
                $product = Product::query()->lockForUpdate()->with('contentFields')->findOrFail($line->product_id);

                $classified = $this->store($actor, $current, $line, $product, $this->classifyPending($line, $product));
                self::recordClassification($line, $product, $classified);

                $line->forceFill(['pending_ciphertext' => null, 'pending_key_version' => null])->save();

                if ($line->valid_count > 0 && ! $product->hasStock()) {
                    $product->forceFill(['stocked_at' => now()])->save();
                }
            }

            $current->forceFill([
                'status' => BatchStatus::Confirmed,
                'confirmed_by' => $actor->getKey(),
                'confirmed_at' => now(),
            ])->save();

            return $current;
        });
    }

    /**
     * @return list<ClassifiedLine>
     */
    private function classifyPending(BatchLine $line, Product $product): array
    {
        $content = $this->crypto->decrypt(new EncryptedContent((string) $line->pending_ciphertext, (int) $line->pending_key_version));

        return $this->classifier->classify($product, $content, $line->separator);
    }

    /**
     * Chèn các dòng hợp lệ. Dòng bị Lô nhập khác chiếm mã trước (ON CONFLICT) chuyển thành trùng trong kho.
     *
     * @param  list<ClassifiedLine>  $classified
     * @return list<ClassifiedLine>
     */
    private function store(User $actor, Batch $batch, BatchLine $line, Product $product, array $classified): array
    {
        $sensitive = $product->contentFields->where('sensitive', true)->pluck('key')->flip()->all();
        $valid = array_filter($classified, fn (ClassifiedLine $row): bool => $row->class === LineClass::Valid);
        $inserted = [];

        foreach (array_chunk($valid, self::INSERT_CHUNK) as $chunk) {
            $now = now();

            $units = DB::table('stock_units')->insertOrIgnoreReturning(array_map(function (ClassifiedLine $row) use ($line, $product, $sensitive, $now): array {
                $secret = array_intersect_key($row->values, $sensitive);
                $plain = array_diff_key($row->values, $sensitive);
                $encrypted = $secret === [] ? null : $this->crypto->encrypt((string) json_encode($secret));

                return [
                    'batch_line_id' => $line->id,
                    'product_id' => $product->id,
                    'kind' => $product->type->value,
                    'status' => StockUnitStatus::Active->value,
                    'unit_cost' => $line->unit_cost,
                    'dedupe_hash' => $row->dedupeHash,
                    'content' => $plain === [] ? null : json_encode($plain),
                    'secret_ciphertext' => $encrypted?->ciphertext,
                    'secret_key_version' => $encrypted?->keyVersion,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }, $chunk), ['id', 'dedupe_hash']);

            $slotRows = [];
            $transitions = [];

            foreach ($units as $unit) {
                $inserted[$unit->dedupe_hash] = true;
                $transitions[] = StockTransition::unitCreated((int) $unit->id);
                $slotRows[] = [
                    'stock_unit_id' => $unit->id,
                    'status' => SlotStatus::InStock->value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            foreach (DB::table('slots')->insertOrIgnoreReturning($slotRows, ['id', 'stock_unit_id']) as $slot) {
                $transitions[] = StockTransition::slotCreated((int) $slot->stock_unit_id, (int) $slot->id);
            }

            $this->ledger->append($actor, $transitions, "Nhập hàng theo Lô nhập #{$batch->id}");
        }

        return array_map(
            fn (ClassifiedLine $row): ClassifiedLine => $row->class === LineClass::Valid && ! isset($inserted[$row->dedupeHash])
                ? $row->asStockDuplicate()
                : $row,
            $classified,
        );
    }

    /**
     * @param  list<ClassifiedLine>  $classified
     */
    private static function recordClassification(BatchLine $line, Product $product, array $classified): void
    {
        $count = fn (LineClass $class): int => count(array_filter($classified, fn (ClassifiedLine $row): bool => $row->class === $class));
        $valid = array_values(array_filter($classified, fn (ClassifiedLine $row): bool => $row->class === LineClass::Valid));
        $rejected = array_values(array_filter($classified, fn (ClassifiedLine $row): bool => $row->class !== LineClass::Valid));

        $line->forceFill([
            'valid_count' => $count(LineClass::Valid),
            'invalid_count' => $count(LineClass::Invalid),
            'file_duplicate_count' => $count(LineClass::FileDuplicate),
            'stock_duplicate_count' => $count(LineClass::StockDuplicate),
            'preview' => [
                'rejected' => array_map(fn (ClassifiedLine $row): array => [
                    'line' => $row->lineNumber,
                    'class' => $row->class->value,
                    'reason' => (string) $row->reason,
                ], $rejected),
                'sample' => array_map(
                    fn (ClassifiedLine $row): array => MaskedContent::of($product->contentFields, $row->values),
                    array_slice($valid, 0, self::SAMPLE_SIZE),
                ),
            ],
        ])->save();
    }

    /**
     * @throws InvalidBatch
     */
    private static function validateDraft(BatchDraft $draft): void
    {
        if ($draft->lines === []) {
            throw new InvalidBatch('Lô nhập phải có ít nhất một Dòng nhập.');
        }

        foreach ($draft->lines as $line) {
            $name = $line->product->name;

            if ($line->product->type !== ProductType::OneTimeCode) {
                throw new InvalidBatch("Chưa hỗ trợ nhập Tài khoản (Sản phẩm \"{$name}\").");
            }

            if ($line->unitCost < 0) {
                throw new InvalidBatch("Giá vốn của Dòng nhập \"{$name}\" không được âm.");
            }

            if (trim($line->content) === '') {
                throw new InvalidBatch("Dòng nhập \"{$name}\" chưa có nội dung.");
            }

            if ($line->separator === '') {
                throw new InvalidBatch("Dòng nhập \"{$name}\" chưa chọn ký tự phân tách.");
            }
        }
    }

    private static function blankToNull(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : trim($value);
    }
}
