<?php

namespace App\Inventory\Catalog;

use App\Inventory\Access\MissingRole;
use App\Inventory\Access\Role;
use App\Inventory\Access\RoleGate;
use App\Inventory\Dispatch\DeliveryTemplate;
use App\Models\BatchLine;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Danh mục Sản phẩm: chỉ Quản trị tạo, sửa, Ngừng bán, xoá. Trường nội dung, Dạng hàng và
 * chuẩn hoá Khoá chống trùng thuộc Loại sản phẩm ({@see ProductTypeCatalog}), không ở đây;
 * Sản phẩm chỉ lệch khỏi Loại đúng một chỗ là Mẫu giao hàng (ADR 0004).
 */
class ProductCatalog
{
    /**
     * Panel hiện đúng hai câu này để giải thích ô bị khoá; giữ một bản để lời giải thích
     * không trôi khỏi quy tắc thật.
     */
    public const DISPATCH_LOCKS_CODE = 'Sản phẩm đã có Phiếu xuất: không đổi được Mã sản phẩm.';

    public const STOCK_LOCKS_PRODUCT_TYPE = 'Sản phẩm đã có hàng: không chuyển được sang Loại sản phẩm khác.';

    private const CODE_FORMAT = '/^[A-Z0-9][A-Z0-9._-]*$/';

    public function __construct(private RoleGate $roles) {}

    /**
     * @throws MissingRole
     * @throws InvalidProductConfiguration
     */
    public function create(User $actor, ProductDraft $draft): Product
    {
        $this->roles->authorize($actor, Role::Owner);
        self::validate($draft);

        return self::guardCode($draft, fn (): Product => DB::transaction(
            fn (): Product => self::save(new Product, $draft),
        ));
    }

    /**
     * @throws MissingRole
     * @throws InvalidProductConfiguration
     * @throws LockedProductConfiguration
     */
    public function update(User $actor, Product $product, ProductDraft $draft): Product
    {
        $this->roles->authorize($actor, Role::Owner);

        return self::guardCode($draft, fn (): Product => DB::transaction(function () use ($product, $draft): Product {
            $current = Product::query()->lockForUpdate()->findOrFail($product->getKey());

            self::validate($draft, $current);

            // Kênh bán loại API tham chiếu Sản phẩm bằng Mã sản phẩm. Xuất kho khoá chia sẻ hàng
            // Sản phẩm, nên phiếu đang tạo cũng được tính.
            if ($draft->code !== $current->code && $current->hasDispatch()) {
                throw new LockedProductConfiguration(self::DISPATCH_LOCKS_CODE);
            }

            // Hàng đã nhập mang nội dung mã hoá theo bộ Trường nội dung của Loại cũ và hash Khoá
            // chống trùng tính theo luật chuẩn hoá của Loại cũ: chuyển Loại là bỏ rơi cả hai.
            if ($draft->productType->getKey() !== $current->product_type_id && $current->hasStock()) {
                throw new LockedProductConfiguration(self::STOCK_LOCKS_PRODUCT_TYPE);
            }

            return self::save($current, $draft);
        }));
    }

    /**
     * Không Giữ hàng hay Giao hàng mới cho Sản phẩm; Đổi hàng của lần giao cũ vẫn dùng được.
     *
     * @throws MissingRole
     */
    public function discontinue(User $actor, Product $product): void
    {
        $this->roles->authorize($actor, Role::Owner);

        $product->forceFill(['discontinued_at' => $product->discontinued_at ?? now()])->save();
    }

    /**
     * Chỉ xoá được Sản phẩm chưa từng có hàng (khai báo nhầm).
     *
     * @throws MissingRole
     * @throws ProductHasStock
     */
    public function delete(User $actor, Product $product): void
    {
        $this->roles->authorize($actor, Role::Owner);

        DB::transaction(function () use ($product): void {
            $current = Product::query()->lockForUpdate()->findOrFail($product->getKey());

            if ($current->hasStock()) {
                throw new ProductHasStock;
            }

            if (BatchLine::query()->where('product_id', $current->getKey())->exists()) {
                throw new ProductHasStock('Sản phẩm đã có Lô nhập không xoá được, chỉ Ngừng bán.');
            }

            $current->delete();
        });
    }

    private static function save(Product $product, ProductDraft $draft): Product
    {
        $product->forceFill([
            'product_type_id' => $draft->productType->getKey(),
            'name' => trim($draft->name),
            'code' => $draft->code,
            'default_slots' => $draft->defaultSlots,
            'warranty_days' => $draft->warrantyDays,
            'min_remaining_days' => $draft->minRemainingDays,
            'low_stock_threshold' => $draft->lowStockThreshold,
            'delivery_template' => DeliveryTemplate::normalize($draft->deliveryTemplate),
        ])->save();

        // Đổi Loại là đổi cả bộ Trường nội dung đọc qua Loại: bỏ bản đã nạp để lần đọc sau đúng.
        return $product->unsetRelation('productType')->unsetRelation('contentFields');
    }

    /**
     * Hai Quản trị cùng đặt một Mã sản phẩm: kiểm tra trước chỉ bắt được trường hợp
     * tuần tự, unique index bắt nốt trường hợp đồng thời.
     *
     * @template T
     *
     * @param  callable(): T  $write
     * @return T
     */
    private static function guardCode(ProductDraft $draft, callable $write): mixed
    {
        try {
            return $write();
        } catch (UniqueConstraintViolationException) {
            throw self::codeTaken($draft->code);
        }
    }

    /**
     * @throws InvalidProductConfiguration
     */
    private static function validate(ProductDraft $draft, ?Product $current = null): void
    {
        $fail = fn (string $message) => throw new InvalidProductConfiguration($message);
        $type = $draft->productType;

        if (trim($draft->name) === '') {
            $fail('Tên Sản phẩm không được để trống.');
        }

        if (preg_match(self::CODE_FORMAT, $draft->code) !== 1) {
            $fail('Mã sản phẩm chỉ gồm chữ in hoa không dấu, chữ số, dấu chấm, gạch ngang, gạch dưới.');
        }

        // Loại Ngừng dùng không nhận Sản phẩm mới, nhưng Sản phẩm đang thuộc nó vẫn sửa được.
        if ($type->isDiscontinued() && $type->getKey() !== $current?->product_type_id) {
            $fail("Loại sản phẩm \"{$type->name}\" đã Ngừng dùng, không chọn cho Sản phẩm được nữa.");
        }

        if ($type->form === StockForm::OneTimeCode && $draft->defaultSlots !== 1) {
            $fail('Mã dùng một lần luôn có đúng một slot.');
        }

        if ($draft->defaultSlots < 1) {
            $fail('Số slot mặc định phải từ 1 trở lên.');
        }

        if ($draft->warrantyDays < 0 || $draft->minRemainingDays < 0 || ($draft->lowStockThreshold ?? 0) < 0) {
            $fail('Thời hạn bảo hành, Hạn còn lại tối thiểu và Ngưỡng sắp hết không được âm.');
        }

        $unknown = DeliveryTemplate::unknownVariables(
            (string) $draft->deliveryTemplate,
            $type->contentFields->pluck('key')->all(),
        );

        if ($unknown !== []) {
            $fail('Mẫu giao hàng dùng biến không có: '.implode(', ', array_map(fn (string $variable): string => "{{{$variable}}}", $unknown)).'.');
        }

        $codeTaken = Product::query()
            ->where('code', $draft->code)
            ->when($current, fn ($query) => $query->whereKeyNot($current->getKey()))
            ->exists();

        if ($codeTaken) {
            throw self::codeTaken($draft->code);
        }
    }

    private static function codeTaken(string $code): InvalidProductConfiguration
    {
        return new InvalidProductConfiguration("Mã sản phẩm {$code} đã được dùng.");
    }
}
