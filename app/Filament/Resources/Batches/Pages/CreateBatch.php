<?php

namespace App\Filament\Resources\Batches\Pages;

use App\Filament\Resources\Batches\BatchResource;
use App\Filament\Support\InventoryAction;
use App\Inventory\Intake\BatchDraft;
use App\Inventory\Intake\BatchIntake;
use App\Inventory\Intake\BatchLineDraft;
use App\Inventory\Intake\ExpiryRule;
use App\Models\Batch;
use App\Models\Product;
use App\Models\Supplier;
use Carbon\CarbonImmutable;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;

/**
 * Tạo Lô nhập nhiều Dòng nhập (dán văn bản hoặc file), rồi chuyển sang trang xem trước.
 * File upload không lưu lại: nội dung đọc vào module Kho (mã hoá ngay) và file tạm của
 * Livewire bị xoá dù gửi thành công hay không.
 */
class CreateBatch extends CreateRecord
{
    protected static string $resource = BatchResource::class;

    protected static bool $canCreateAnother = false;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $uploads = [];
        $lines = [];

        try {
            foreach ($data['lines'] as $line) {
                $upload = ($line['source'] ?? 'paste') === 'file' ? self::upload($line['file']) : null;

                if ($upload !== null) {
                    $uploads[] = $upload;
                }

                $lines[] = self::line($line, $upload);
            }

            return app(BatchIntake::class)->submit(InventoryAction::actor(), new BatchDraft(
                supplier: Supplier::query()->findOrFail($data['supplier_id']),
                receivedOn: CarbonImmutable::parse($data['received_on']),
                lines: $lines,
                documentNumber: $data['document_number'] ?? null,
                note: $data['note'] ?? null,
                invoiceTotal: filled($data['invoice_total'] ?? null) ? (int) $data['invoice_total'] : null,
                supplements: filled($data['supplements_batch_id'] ?? null) ? Batch::query()->findOrFail($data['supplements_batch_id']) : null,
            ));
        } catch (Throwable $exception) {
            if (! InventoryAction::isBusinessError($exception)) {
                throw $exception;
            }

            Notification::make()->danger()->title($exception->getMessage())->send();
            $this->halt(shouldRollbackDatabaseTransaction: true);

            throw $exception;
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
        $product = Product::query()->with('contentFields')->findOrFail($line['product_id']);
        $unitCost = (int) $line['unit_cost'];
        $slots = filled($line['slots'] ?? null) ? (int) $line['slots'] : null;
        $expiry = match ($line['expiry_mode'] ?? 'none') {
            'date' => ExpiryRule::on(CarbonImmutable::parse($line['expires_on'])),
            'days' => ExpiryRule::afterDays((int) $line['expires_after_days']),
            default => null,
        };

        if ($upload !== null) {
            return BatchLineDraft::file($product, $unitCost, (string) $upload->get(), $upload->getClientOriginalName(), $slots, $expiry);
        }

        return new BatchLineDraft(
            product: $product,
            unitCost: $unitCost,
            content: (string) $line['content'],
            separator: BatchResource::SEPARATORS[$line['separator']][0],
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
