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
 * Danh mục Sản phẩm: chỉ Quản trị tạo, sửa, Ngừng bán, xoá.
 */
class ProductCatalog
{
    private const CODE_FORMAT = '/^[A-Z0-9][A-Z0-9._-]*$/';

    private const FIELD_KEY_FORMAT = '/^[a-z][a-z0-9_]*$/';

    public function __construct(private RoleGate $roles) {}

    /**
     * @throws MissingRole
     * @throws InvalidProductConfiguration
     */
    public function create(User $actor, ProductDraft $draft): Product
    {
        $this->roles->authorize($actor, Role::QuanTri);
        self::validate($draft);

        return self::guardCode($draft, fn (): Product => DB::transaction(
            fn (): Product => self::save(new Product, $draft),
        ));
    }

    /**
     * Thay toàn bộ cấu hình bằng bản mới. Trường nội dung được đối chiếu theo định danh:
     * trường có sẵn được sửa, trường mới được thêm, trường vắng mặt bị xoá.
     *
     * @throws MissingRole
     * @throws InvalidProductConfiguration
     */
    public function update(User $actor, Product $product, ProductDraft $draft): Product
    {
        $this->roles->authorize($actor, Role::QuanTri);

        return self::guardCode($draft, fn (): Product => DB::transaction(function () use ($product, $draft): Product {
            $current = Product::query()->lockForUpdate()->findOrFail($product->getKey());

            self::validate($draft, $current);

            // Kênh bán loại API tham chiếu Sản phẩm bằng Mã sản phẩm. Xuất kho khoá chia sẻ hàng
            // Sản phẩm, nên phiếu đang tạo cũng được tính.
            if ($draft->code !== $current->code && $current->hasDispatch()) {
                throw new LockedProductConfiguration('Sản phẩm đã có Phiếu xuất: không đổi được Mã sản phẩm.');
            }

            if ($current->hasStock()) {
                self::ensureOnlyUnlockedChanges($current, $draft);
            }

            return self::save($current, $draft);
        }));
    }

    /**
     * Sản phẩm đã có hàng chỉ được thêm trường tuỳ chọn hoặc đổi tên hiển thị; các
     * cấu hình không ảnh hưởng hàng đã nhập (tên, slot mặc định, hạn...) vẫn sửa được.
     *
     * @throws LockedProductConfiguration
     */
    private static function ensureOnlyUnlockedChanges(Product $current, ProductDraft $draft): void
    {
        $fail = fn (string $message) => throw new LockedProductConfiguration("Sản phẩm đã có hàng: {$message}");

        if ($draft->type !== $current->type) {
            $fail('không đổi được loại Sản phẩm.');
        }

        if ($draft->normalization() != $current->normalization()) {
            $fail('không đổi được tuỳ chọn chuẩn hoá.');
        }

        $drafts = collect($draft->fields)->keyBy('key');

        foreach ($current->contentFields as $field) {
            $next = $drafts->get($field->key) ?? $fail("không xoá được trường \"{$field->label}\".");

            if ($next->dedupeKey !== $field->is_dedupe_key) {
                $fail('không đổi được Khoá chống trùng.');
            }

            if ($next->sensitive !== $field->sensitive) {
                $fail("không đổi được cờ nhạy cảm của trường \"{$field->label}\".");
            }

            if ($next->type !== $field->type || $next->pattern !== $field->pattern || $next->required !== $field->required) {
                $fail("trường \"{$field->label}\" chỉ đổi được tên hiển thị.");
            }
        }

        $added = $drafts->except($current->contentFields->pluck('key')->all());

        if ($added->contains(fn (ContentFieldDraft $field): bool => $field->required)) {
            $fail('chỉ thêm được trường tuỳ chọn.');
        }
    }

    /**
     * Không Giữ hàng hay Giao hàng mới cho Sản phẩm; Đổi hàng của lần giao cũ vẫn dùng được.
     *
     * @throws MissingRole
     */
    public function discontinue(User $actor, Product $product): void
    {
        $this->roles->authorize($actor, Role::QuanTri);

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
        $this->roles->authorize($actor, Role::QuanTri);

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
            'type' => $draft->type,
            'name' => trim($draft->name),
            'code' => $draft->code,
            'default_slots' => $draft->defaultSlots,
            'warranty_days' => $draft->warrantyDays,
            'min_remaining_days' => $draft->minRemainingDays,
            'low_stock_threshold' => $draft->lowStockThreshold,
            'case_insensitive' => $draft->normalization()->caseInsensitive,
            'strip_separators' => $draft->normalization()->stripSeparators,
            'delivery_template' => DeliveryTemplate::normalize($draft->deliveryTemplate),
        ])->save();

        $keys = array_map(fn (ContentFieldDraft $field): string => $field->key, $draft->fields);

        $product->contentFields()->whereNotIn('key', $keys)->delete();
        // Bỏ cờ trước để chuyển Khoá chống trùng sang trường khác không vướng unique index.
        // Nạp trường sau câu này để dirty-check thấy cờ cần đặt lại.
        $product->contentFields()->update(['is_dedupe_key' => false]);
        $existing = $product->contentFields()->get()->keyBy('key');

        foreach ($draft->fields as $position => $field) {
            ($existing->get($field->key) ?? $product->contentFields()->make())->forceFill([
                'key' => $field->key,
                'label' => trim($field->label),
                'type' => $field->type,
                'pattern' => $field->pattern,
                'required' => $field->required,
                'sensitive' => $field->sensitive,
                'is_dedupe_key' => $field->dedupeKey,
                'position' => $position,
            ])->save();
        }

        return $product->unsetRelation('contentFields');
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

        if (trim($draft->name) === '') {
            $fail('Tên Sản phẩm không được để trống.');
        }

        if (preg_match(self::CODE_FORMAT, $draft->code) !== 1) {
            $fail('Mã sản phẩm chỉ gồm chữ in hoa không dấu, chữ số, dấu chấm, gạch ngang, gạch dưới.');
        }

        if ($draft->type === ProductType::OneTimeCode && $draft->defaultSlots !== 1) {
            $fail('Mã dùng một lần luôn có đúng một slot.');
        }

        if ($draft->defaultSlots < 1) {
            $fail('Số slot mặc định phải từ 1 trở lên.');
        }

        if ($draft->warrantyDays < 0 || $draft->minRemainingDays < 0 || ($draft->lowStockThreshold ?? 0) < 0) {
            $fail('Thời hạn bảo hành, Hạn còn lại tối thiểu và Ngưỡng sắp hết không được âm.');
        }

        if ($draft->fields === []) {
            $fail('Sản phẩm phải có ít nhất một Trường nội dung.');
        }

        $keys = [];

        foreach ($draft->fields as $field) {
            if (preg_match(self::FIELD_KEY_FORMAT, $field->key) !== 1) {
                $fail("Định danh trường \"{$field->key}\" chỉ gồm chữ thường không dấu, chữ số, gạch dưới và bắt đầu bằng chữ.");
            }

            if (in_array($field->key, $keys, true)) {
                $fail("Định danh trường \"{$field->key}\" bị trùng.");
            }

            $keys[] = $field->key;

            if (in_array($field->key, DeliveryTemplate::BUILT_IN, true)) {
                $fail("Định danh trường \"{$field->key}\" trùng tên biến của Mẫu giao hàng.");
            }

            if (trim($field->label) === '') {
                $fail("Tên hiển thị của trường \"{$field->key}\" không được để trống.");
            }

            if ($field->pattern !== null && ContentPattern::compile($field->pattern) === null) {
                $fail("Regex của trường \"{$field->key}\" không hợp lệ.");
            }

            if ($field->dedupeKey && ! $field->required) {
                $fail('Khoá chống trùng phải là trường bắt buộc.');
            }
        }

        // Nội dung giao khách và dạng che đánh chỉ mục theo tên hiển thị, còn cột file nhập khớp
        // theo định danh hoặc tên hiển thị: hai trường cùng tên thì một trường bị ghi đè.
        $keyOf = [];
        $labelOf = [];

        foreach ($draft->fields as $field) {
            $keyOf[ContentFieldName::normalize($field->key)] = $field->key;
        }

        foreach ($draft->fields as $field) {
            $label = trim($field->label);
            $name = ContentFieldName::normalize($label);

            if (isset($labelOf[$name])) {
                $fail("Tên hiển thị trường \"{$labelOf[$name]}\" bị trùng.");
            }

            if (isset($keyOf[$name]) && $keyOf[$name] !== $field->key) {
                $fail("Tên hiển thị trường \"{$label}\" trùng định danh trường \"{$keyOf[$name]}\".");
            }

            $labelOf[$name] = $label;
        }

        if (count(array_filter($draft->fields, fn (ContentFieldDraft $field): bool => $field->dedupeKey)) !== 1) {
            $fail('Phải chọn đúng một Trường nội dung làm Khoá chống trùng.');
        }

        $unknown = DeliveryTemplate::unknownVariables((string) $draft->deliveryTemplate, $keys);

        if ($unknown !== []) {
            $fail('Mẫu giao hàng dùng biến không có: '.implode(', ', array_map(fn (string $name): string => "{{{$name}}}", $unknown)).'.');
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
