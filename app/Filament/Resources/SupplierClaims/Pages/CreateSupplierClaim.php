<?php

namespace App\Filament\Resources\SupplierClaims\Pages;

use App\Filament\Resources\SupplierClaims\SupplierClaimResource;
use App\Filament\Support\HandlesBusinessErrors;
use App\Filament\Support\InventoryAction;
use App\Inventory\Claims\SupplierClaims;
use App\Models\StockUnit;
use App\Models\Supplier;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Tạo Khiếu nại Nháp: chọn Nhà cung cấp rồi các Đơn vị hàng Lỗi chưa khiếu nại của họ.
 */
class CreateSupplierClaim extends CreateRecord
{
    use HandlesBusinessErrors;

    protected static string $resource = SupplierClaimResource::class;

    protected static bool $canCreateAnother = false;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return $this->attempt(fn (): Model => app(SupplierClaims::class)->create(
            InventoryAction::actor(),
            Supplier::query()->findOrFail($data['supplier_id']),
            StockUnit::query()->whereKey(array_map('intval', (array) $data['stock_unit_ids']))->orderBy('id')->get()->all(),
            $data['note'] ?? null,
        ));
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
