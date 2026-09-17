<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use App\Filament\Support\HandlesBusinessErrors;
use App\Filament\Support\InventoryAction;
use App\Inventory\Catalog\ProductCatalog;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Khai báo một Sản phẩm mới.
 *
 * Adapter mỏng: mọi quy tắc nằm ở ProductCatalog; lỗi nghiệp vụ thành thông báo và giữ
 * nguyên form để Quản trị sửa, không mất công khai lại.
 */
class CreateProduct extends CreateRecord
{
    use HandlesBusinessErrors;

    protected static string $resource = ProductResource::class;

    /**
     * Trường nội dung đã lên Loại sản phẩm nên khai một Sản phẩm giờ chỉ còn vài ô: khai
     * cả một dải Sản phẩm khác thời hạn của cùng Loại là việc có thật, và "Tạo thêm" tiết kiệm
     * đúng việc đó.
     */
    protected static bool $canCreateAnother = true;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return $this->attempt(fn (): Model => app(ProductCatalog::class)->create(
            InventoryAction::actor(),
            ProductResource::draftFromForm($data),
        ));
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
