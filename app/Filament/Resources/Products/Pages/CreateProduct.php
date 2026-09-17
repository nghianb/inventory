<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use App\Filament\Support\InventoryAction;
use App\Inventory\Catalog\ProductCatalog;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Khai báo một Sản phẩm mới. Trang riêng chứ không phải modal: form có repeater Trường
 * nội dung, khai một Sản phẩm 3–4 trường trong khung modal là vừa cuộn vừa đoán.
 *
 * Adapter mỏng: mọi quy tắc nằm ở ProductCatalog; lỗi nghiệp vụ thành thông báo và giữ
 * nguyên form để Quản trị sửa, không mất công khai lại.
 */
class CreateProduct extends CreateRecord
{
    protected static string $resource = ProductResource::class;

    /** Mỗi Sản phẩm có bộ Trường nội dung riêng nên form reset sạch chẳng tiết kiệm gì. */
    protected static bool $canCreateAnother = false;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(ProductCatalog::class)->create(InventoryAction::actor(), ProductResource::draftFromForm($data));
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
        return 'Đã tạo Sản phẩm.';
    }

    protected function getRedirectUrl(): string
    {
        return ProductResource::getUrl('index');
    }
}
