<?php

namespace App\Filament\Resources\Batches\Pages;

use App\Filament\Resources\Batches\BatchResource;
use App\Filament\Support\HandlesBusinessErrors;
use App\Filament\Support\InventoryAction;
use App\Inventory\Intake\BatchDraft;
use App\Inventory\Intake\BatchIntake;
use App\Inventory\Intake\BatchLineDraft;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierClaim;
use Carbon\CarbonImmutable;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Tạo Lô nhập nhiều Dòng nhập (dán văn bản hoặc file), rồi chuyển sang trang xem trước.
 * File upload không lưu lại: nội dung đọc vào module Kho (mã hoá ngay) và file tạm của
 * Livewire bị xoá dù gửi thành công hay không.
 */
class CreateBatch extends CreateRecord
{
    use HandlesBusinessErrors;

    protected static string $resource = BatchResource::class;

    /** Tham số query: mở trang tạo Lô nhập hàng thay thế cho Khiếu nại nhà cung cấp. */
    public const CLAIM_QUERY = 'khieu-nai';

    protected static bool $canCreateAnother = false;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $uploads = [];
        $lines = [];
        $claim = filled($data['supplier_claim_id'] ?? null) ? SupplierClaim::query()->findOrFail($data['supplier_claim_id']) : null;

        try {
            foreach ($data['lines'] as $line) {
                $upload = ($line['source'] ?? 'paste') === 'file' ? self::upload($line['file']) : null;

                if ($upload !== null) {
                    $uploads[] = $upload;
                }

                // Hàng thay thế từ Khiếu nại: form ẩn Giá vốn, luôn 0.
                $lines[] = self::line($claim === null ? $line : [...$line, 'unit_cost' => 0], $upload);
            }

            return $this->attempt(fn (): Model => app(BatchIntake::class)->submit(InventoryAction::actor(), new BatchDraft(
                supplier: Supplier::query()->findOrFail($data['supplier_id']),
                receivedOn: CarbonImmutable::parse($data['received_on']),
                lines: $lines,
                documentNumber: $data['document_number'] ?? null,
                note: $data['note'] ?? null,
                // Tổng tiền hoá đơn nhập ở màn xem trước, nơi đã có Tổng Giá vốn để đối chiếu (ADR 0006).
                supplierClaim: $claim,
            )));
        } finally {
            foreach ($uploads as $upload) {
                $upload->delete();
            }
        }
    }

    private static function upload(mixed $state): TemporaryUploadedFile
    {
        $file = is_array($state) ? reset($state) : $state;
        assert($file instanceof TemporaryUploadedFile);

        return $file;
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private static function line(array $line, ?TemporaryUploadedFile $upload): BatchLineDraft
    {
        $product = Product::query()->with(['contentFields', 'productType'])->findOrFail($line['product_id']);
        $unitCost = (int) $line['unit_cost'];
        $slots = filled($line['slots'] ?? null) ? (int) $line['slots'] : null;
        $expiry = BatchResource::expiryRule($line);

        if ($upload !== null) {
            return BatchLineDraft::file($product, $unitCost, (string) $upload->get(), $upload->getClientOriginalName(), $slots, $expiry);
        }

        return new BatchLineDraft(
            product: $product,
            unitCost: $unitCost,
            content: (string) $line['content'],
            // Sản phẩm một Trường nội dung thì form ẩn ô phân tách, và Filament loại field bị
            // ẩn khỏi state: không có gì để tách nên giá trị nào cũng như nhau.
            separator: BatchResource::SEPARATORS[$line['separator'] ?? 'tab'][0],
            slots: $slots,
            expiry: $expiry,
        );
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Đã gửi Lô nhập đi kiểm tra.';
    }

    protected function getRedirectUrl(): string
    {
        return BatchResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
