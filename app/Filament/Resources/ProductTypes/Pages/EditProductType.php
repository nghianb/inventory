<?php

namespace App\Filament\Resources\ProductTypes\Pages;

use App\Filament\Resources\ProductTypes\ProductTypeResource;
use App\Filament\Support\HandlesBusinessErrors;
use App\Filament\Support\InventoryAction;
use App\Inventory\Catalog\InvalidProductConfiguration;
use App\Inventory\Catalog\ProductTypeCatalog;
use App\Models\ProductType;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Sửa một Loại sản phẩm. Sửa Loại là sửa mọi Sản phẩm thuộc nó, nên nút Lưu mở một bước xem
 * trước: thay đổi nào áp xuống, Sản phẩm nào chịu ảnh hưởng, và khi Loại đã có Sản phẩm có
 * hàng thì thay đổi nào bị từ chối trọn gói (ADR 0004).
 *
 * Bản xem trước chỉ để đọc. ProductTypeCatalog::update() tự phân hạng lại dưới khoá hàng, vì
 * giữa lúc xem và lúc bấm xác nhận có thể vừa có Lô nhập làm một Sản phẩm của Loại có hàng.
 */
class EditProductType extends EditRecord
{
    use HandlesBusinessErrors;

    protected static string $resource = ProductTypeResource::class;

    /**
     * @return list<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            ProductTypeResource::discontinueAction(),
            ProductTypeResource::deleteAction()->successRedirectUrl(ProductTypeResource::getUrl('index')),
        ];
    }

    /**
     * Thay nút Lưu mặc định bằng một action riêng để bước xem trước mở được.
     *
     * Nút Lưu của EditRecord là action dạng submit form, và Filament phân giải action theo method
     * `<tên>Action()` trên trang; `save()` của EditRecord trả về void nên không phân giải ra Action
     * nào. Gắn modal thẳng lên nút Lưu mặc định thì modal không bao giờ mở. Action này có
     * {@see saveWithPreviewAction()} đúng quy ước tên nên mount được.
     *
     * @return array<Action>
     */
    protected function getFormActions(): array
    {
        return [
            $this->saveWithPreviewAction(),
            $this->getCancelFormAction(),
        ];
    }

    public function saveWithPreviewAction(): Action
    {
        return Action::make('saveWithPreview')
            ->label('Lưu')
            ->requiresConfirmation()
            ->modalHeading('Xác nhận sửa Loại sản phẩm')
            ->modalDescription(fn (): string => $this->previewText())
            ->modalSubmitActionLabel('Xác nhận ghi')
            ->keyBindings(['mod+s'])
            ->action(fn () => $this->save());
    }

    /**
     * Lời của bước xem trước. Khai báo không hợp lệ (thiếu Khoá chống trùng, regex sai...) nói
     * luôn ở đây, không bắt Quản trị bấm xác nhận rồi mới báo.
     */
    private function previewText(): string
    {
        try {
            $change = app(ProductTypeCatalog::class)->preview(
                InventoryAction::actor(),
                $this->productType(),
                ProductTypeResource::draftFromForm($this->form->getState()),
            );
        } catch (InvalidProductConfiguration $exception) {
            return $exception->getMessage();
        }

        if ($change->isEmpty()) {
            return 'Không có thay đổi nào để ghi.';
        }

        return collect([
            self::sentence('Áp cho mọi Sản phẩm của Loại:', $change->applied),
            self::sentence('Bị từ chối trọn gói, không ghi gì cả:', $change->rejected),
            $change->blockingProducts === [] ? null : 'Sản phẩm đang chặn: '.implode(', ', $change->blockingProducts).'.',
            $change->affectedProducts === []
                ? 'Chưa Sản phẩm nào dùng Loại này.'
                : 'Sản phẩm thuộc Loại: '.implode(', ', $change->affectedProducts).'.',
        ])->filter()->implode(' ');
    }

    /**
     * @param  list<string>  $items
     */
    private static function sentence(string $heading, array $items): ?string
    {
        return $items === [] ? null : $heading.' '.implode(' ', $items);
    }

    /**
     * Form dựng lại từ bản ghi: có Trường nội dung và danh sách Sản phẩm đang chặn mà cột của
     * bảng product_types không có.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return ProductTypeResource::formData($this->productType());
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        assert($record instanceof ProductType);

        return $this->attempt(fn (): Model => app(ProductTypeCatalog::class)->update(
            InventoryAction::actor(),
            $record,
            ProductTypeResource::draftFromForm($data),
        ));
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Đã lưu Loại sản phẩm.';
    }

    private function productType(): ProductType
    {
        $record = $this->getRecord();
        assert($record instanceof ProductType);

        return $record;
    }
}
