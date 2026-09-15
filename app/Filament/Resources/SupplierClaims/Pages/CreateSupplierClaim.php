<?php

namespace App\Filament\Resources\SupplierClaims\Pages;

use App\Filament\Resources\SupplierClaims\SupplierClaimResource;
use App\Filament\Support\InventoryAction;
use App\Inventory\Claims\SupplierClaims;
use App\Models\StockUnit;
use App\Models\Supplier;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Tạo Khiếu nại Nháp: chọn Nhà cung cấp rồi các Đơn vị hàng Lỗi chưa khiếu nại của họ.
 */
class CreateSupplierClaim extends CreateRecord
{
    protected static string $resource = SupplierClaimResource::class;

    protected static bool $canCreateAnother = false;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(SupplierClaims::class)->create(
                InventoryAction::actor(),
                Supplier::query()->findOrFail($data['supplier_id']),
                StockUnit::query()->whereKey(array_map('intval', (array) $data['stock_unit_ids']))->orderBy('id')->get()->all(),
                $data['note'] ?? null,
            );
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
        return 'Đã tạo Khiếu nại Nháp.';
    }

    protected function getRedirectUrl(): string
    {
        return SupplierClaimResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
