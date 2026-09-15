<?php

namespace App\Filament\Resources\Batches\Pages;

use App\Filament\Resources\Batches\BatchResource;
use App\Filament\Support\InventoryAction;
use App\Inventory\Intake\BatchDraft;
use App\Inventory\Intake\BatchIntake;
use App\Inventory\Intake\BatchLineDraft;
use App\Models\Product;
use App\Models\Supplier;
use Carbon\CarbonImmutable;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Tạo Lô nhập một Dòng nhập bằng văn bản dán, rồi chuyển sang trang xem trước.
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
        try {
            return app(BatchIntake::class)->submit(InventoryAction::actor(), new BatchDraft(
                supplier: Supplier::query()->findOrFail($data['supplier_id']),
                receivedOn: CarbonImmutable::parse($data['received_on']),
                lines: [new BatchLineDraft(
                    product: Product::query()->with('contentFields')->findOrFail($data['product_id']),
                    unitCost: (int) $data['unit_cost'],
                    content: (string) $data['content'],
                    separator: BatchResource::SEPARATORS[$data['separator']][0],
                )],
                documentNumber: $data['document_number'] ?? null,
                note: $data['note'] ?? null,
            ));
        } catch (Throwable $exception) {
            if (! InventoryAction::isBusinessError($exception)) {
                throw $exception;
            }

            Notification::make()->danger()->title($exception->getMessage())->send();
            $this->halt(shouldRollbackDatabaseTransaction: true);

            throw $exception;
        }
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
