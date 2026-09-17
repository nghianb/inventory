<?php

namespace App\Filament\Resources\ProductTypes\Pages;

use App\Filament\Resources\ProductTypes\ProductTypeResource;
use App\Filament\Support\HandlesBusinessErrors;
use App\Filament\Support\InventoryAction;
use App\Inventory\Catalog\ProductTypeCatalog;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Khai báo một Loại sản phẩm mới. Trang riêng chứ không phải modal: form có repeater Trường
 * nội dung, khai 3–4 trường trong khung modal là vừa cuộn vừa đoán.
 *
 * Adapter mỏng: mọi quy tắc nằm ở ProductTypeCatalog; lỗi nghiệp vụ thành thông báo và giữ
 * nguyên form để Quản trị sửa. Loại mới chưa Sản phẩm nào dùng nên không cần xem trước.
 */
class CreateProductType extends CreateRecord
{
    use HandlesBusinessErrors;

    protected static string $resource = ProductTypeResource::class;

    protected static bool $canCreateAnother = false;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return $this->attempt(fn (): Model => app(ProductTypeCatalog::class)->create(
            InventoryAction::actor(),
            ProductTypeResource::draftFromForm($data),
        ));
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Đã tạo Loại sản phẩm.';
    }

    protected function getRedirectUrl(): string
    {
        return ProductTypeResource::getUrl('index');
    }
}
