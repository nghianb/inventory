<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use App\Filament\Support\InventoryAction;
use App\Inventory\Catalog\ProductCatalog;
use App\Models\Product;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Sửa cấu hình một Sản phẩm. Trang riêng chứ không phải modal, và Sửa mới là cái mở
 * nhiều hơn Tạo: đổi ngưỡng, thêm trường tuỳ chọn, sửa Mẫu giao hàng.
 *
 * Adapter mỏng: mọi quy tắc nằm ở ProductCatalog; form chỉ khoá sẵn ô bị khoá và giải
 * thích, lỗi nghiệp vụ thành thông báo và giữ nguyên form.
 */
class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    /**
     * Ngừng bán và Xoá dùng chung định nghĩa với hàng của bảng; xoá xong thì bản ghi không
     * còn, nên về danh sách.
     *
     * @return list<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            ProductResource::discontinueAction(),
            ProductResource::deleteAction()->successRedirectUrl(ProductResource::getUrl('index')),
        ];
    }

    /**
     * Form dựng lại từ bản ghi: có Trường nội dung và hai cờ has_stock/has_dispatch mà
     * cột của bảng products không có.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return ProductResource::formData($this->product());
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        assert($record instanceof Product);

        try {
            return app(ProductCatalog::class)->update(InventoryAction::actor(), $record, ProductResource::draftFromForm($data));
        } catch (Throwable $exception) {
            if (! InventoryAction::isBusinessError($exception)) {
                throw $exception;
            }

            Notification::make()->danger()->title($exception->getMessage())->send();
            $this->halt(shouldRollbackDatabaseTransaction: true);

            throw $exception;
        }
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Đã lưu Sản phẩm.';
    }

    private function product(): Product
    {
        $record = $this->getRecord();
        assert($record instanceof Product);

        return $record;
    }
}
