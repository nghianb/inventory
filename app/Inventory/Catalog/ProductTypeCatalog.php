<?php

namespace App\Inventory\Catalog;

use App\Inventory\Access\MissingRole;
use App\Inventory\Access\Role;
use App\Inventory\Access\RoleGate;
use App\Inventory\Dispatch\DeliveryTemplate;
use App\Models\ProductType;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Loại sản phẩm: chỉ Quản trị tạo, sửa, Ngừng dùng, xoá. Sửa Loại là sửa mọi Sản phẩm thuộc
 * nó, nên đi qua bước xem trước ({@see preview()}) rồi mới ghi.
 *
 * Bản xem trước chỉ để Quản trị nhìn: {@see update()} tự phân hạng lại dưới khoá hàng, vì
 * giữa lúc xem và lúc bấm xác nhận có thể vừa có Lô nhập làm một Sản phẩm của Loại có hàng.
 */
class ProductTypeCatalog
{
    /**
     * Trang Sửa Loại sản phẩm hiện đúng câu này để nói trước cái mà bản xem trước sẽ nói lại.
     * Giữ một bản cạnh {@see classify()} để lời giải thích không trôi khỏi phân hạng thật.
     */
    public const LOCKED_CHANGES_NOTE = 'Chỉ thêm được trường tuỳ chọn, đổi tên hiển thị, đổi tên Loại và Mẫu giao hàng. Mọi thay đổi khác (kiểu trường, regex, cờ bắt buộc, cờ nhạy cảm, Khoá chống trùng, thứ tự trường, chuẩn hoá, Dạng hàng, xoá trường) bị từ chối trọn gói, không áp cho Sản phẩm nào cả.';

    private const FIELD_KEY_FORMAT = '/^[a-z][a-z0-9_]*$/';

    public function __construct(private RoleGate $roles) {}

    /**
     * @throws MissingRole
     * @throws InvalidProductConfiguration
     */
    public function create(User $actor, ProductTypeDraft $draft): ProductType
    {
        $this->roles->authorize($actor, Role::Owner);
        self::validate($draft);

        return self::guardName($draft, fn (): ProductType => DB::transaction(
            fn (): ProductType => self::save(new ProductType, $draft),
        ));
    }

    /**
     * Thay đổi mà bản sửa này gây ra, để Quản trị xác nhận trước khi ghi.
     *
     * @throws MissingRole
     * @throws InvalidProductConfiguration
     */
    public function preview(User $actor, ProductType $type, ProductTypeDraft $draft): ProductTypeChange
    {
        $this->roles->authorize($actor, Role::Owner);
        self::validate($draft, $type);

        return self::change($type, $draft);
    }

    /**
     * Thay toàn bộ khai báo bằng bản mới, áp cho mọi Sản phẩm của Loại. Trường nội dung được
     * đối chiếu theo định danh: trường có sẵn được sửa, trường mới được thêm, trường vắng mặt
     * bị xoá.
     *
     * @throws MissingRole
     * @throws InvalidProductConfiguration
     * @throws LockedProductConfiguration Loại đã có Sản phẩm có hàng và bản sửa chạm dữ liệu đã lưu
     */
    public function update(User $actor, ProductType $type, ProductTypeDraft $draft): ProductType
    {
        $this->roles->authorize($actor, Role::Owner);

        return self::guardName($draft, fn (): ProductType => DB::transaction(function () use ($type, $draft): ProductType {
            $current = ProductType::query()->lockForUpdate()->findOrFail($type->getKey());

            self::validate($draft, $current);

            // Khoá luôn hàng Sản phẩm của Loại, đúng bảng và đúng thứ tự id mà Nhập hàng khoá khi
            // đặt `stocked_at`. Chỉ khoá hàng product_types thì một Lô nhập đang ghi dở vẫn hiện ra
            // là "chưa có hàng", và hàng vừa vào kho sẽ mang nội dung mã hoá cùng hash Khoá chống
            // trùng tính theo đúng khai báo mà chỗ này vừa đổi.
            $current->products()->orderBy('id')->lockForUpdate()->get();

            $change = self::change($current, $draft);

            // Từ chối trọn gói: không áp phần áp được rồi bỏ lặng phần còn lại (ADR 0004).
            if ($change->isRejected()) {
                throw new LockedProductConfiguration($change->rejectionMessage());
            }

            return self::save($current, $draft);
        }));
    }

    /**
     * Loại bị ẩn khỏi ô chọn khi tạo Sản phẩm mới; Sản phẩm đang dùng nó không đổi gì.
     *
     * @throws MissingRole
     */
    public function discontinue(User $actor, ProductType $type): void
    {
        $this->roles->authorize($actor, Role::Owner);

        $type->forceFill(['discontinued_at' => $type->discontinued_at ?? now()])->save();
    }

    /**
     * Chỉ xoá được Loại chưa Sản phẩm nào dùng (khai báo nhầm).
     *
     * @throws MissingRole
     * @throws ProductTypeInUse
     */
    public function delete(User $actor, ProductType $type): void
    {
        $this->roles->authorize($actor, Role::Owner);

        DB::transaction(function () use ($type): void {
            $current = ProductType::query()->lockForUpdate()->findOrFail($type->getKey());

            if ($current->products()->exists()) {
                throw new ProductTypeInUse;
            }

            $current->delete();
        });
    }

    /**
     * Phân hạng bản sửa và tra Sản phẩm đang chặn. Loại chưa có Sản phẩm nào có hàng thì mọi
     * thay đổi đều áp được, kể cả hạng chạm dữ liệu đã lưu.
     */
    private static function change(ProductType $type, ProductTypeDraft $draft): ProductTypeChange
    {
        [$applied, $unsafe] = self::classify($type, $draft);
        $blocking = $unsafe === [] ? [] : $type->stockedProducts()->orderBy('code')->pluck('code')->all();

        return new ProductTypeChange(
            applied: $blocking === [] ? [...$applied, ...$unsafe] : $applied,
            rejected: $blocking === [] ? [] : $unsafe,
            blockingProducts: $blocking,
            affectedProducts: $type->products()->orderBy('code')->pluck('code')->all(),
        );
    }

    /**
     * Chia thay đổi làm hai hạng theo ADR 0004: hạng áp xuống được (không đụng tới
     * `stock_units.content`, `secret_ciphertext` hay `dedupe_hash`) và hạng chạm dữ liệu đã lưu.
     *
     * Đổi phân hạng ở đây thì sửa luôn {@see LOCKED_CHANGES_NOTE}, câu mà trang Sửa hiện ra
     * trước khi Quản trị kịp đâm vào giới hạn.
     *
     * @return array{list<string>, list<string>}
     */
    private static function classify(ProductType $type, ProductTypeDraft $draft): array
    {
        $applied = [];
        $unsafe = [];
        $name = trim($draft->name);

        if ($name !== $type->name) {
            $applied[] = "Đổi tên Loại thành \"{$name}\".";
        }

        if ($draft->form !== $type->form) {
            $unsafe[] = 'Đổi Dạng hàng.';
        }

        if ($draft->normalization() != $type->normalization()) {
            $unsafe[] = 'Đổi tuỳ chọn chuẩn hoá Khoá chống trùng.';
        }

        if (DeliveryTemplate::normalize($draft->deliveryTemplate) !== $type->delivery_template) {
            $applied[] = 'Đổi Mẫu giao hàng của Loại; Sản phẩm có mẫu riêng không bị đụng tới.';
        }

        $drafts = collect($draft->fields)->keyBy('key');

        foreach ($type->contentFields as $field) {
            $next = $drafts->get($field->key);

            if ($next === null) {
                $unsafe[] = "Xoá trường \"{$field->label}\".";

                continue;
            }

            if (trim($next->label) !== $field->label) {
                $applied[] = "Đổi tên hiển thị trường \"{$field->label}\" thành \"".trim($next->label).'".';
            }

            if ($next->dedupeKey !== $field->is_dedupe_key) {
                $unsafe[] = 'Đổi Khoá chống trùng.';
            }

            if ($next->sensitive !== $field->sensitive) {
                $unsafe[] = "Đổi cờ nhạy cảm của trường \"{$field->label}\".";
            }

            if ($next->type !== $field->type || $next->pattern !== $field->pattern || $next->required !== $field->required) {
                $unsafe[] = "Đổi kiểu, regex hoặc cờ bắt buộc của trường \"{$field->label}\".";
            }
        }

        foreach ($drafts->except($type->contentFields->pluck('key')->all()) as $field) {
            $label = trim($field->label);

            if ($field->required) {
                $unsafe[] = "Thêm trường bắt buộc \"{$label}\".";
            } else {
                $applied[] = "Thêm trường tuỳ chọn \"{$label}\".";
            }
        }

        // Thứ tự trường là thứ tự cột khi dán danh sách lúc nhập hàng: đảo nó là đổi cách đọc
        // file nhập của mọi Sản phẩm thuộc Loại, dù không đụng tới hàng đã nằm trong kho.
        $currentKeys = $type->contentFields->pluck('key')->all();
        $draftKeys = array_map(fn (ContentFieldDraft $field): string => $field->key, $draft->fields);

        if (array_values(array_intersect($draftKeys, $currentKeys)) !== array_values(array_intersect($currentKeys, $draftKeys))) {
            $unsafe[] = 'Đổi thứ tự Trường nội dung.';
        }

        return [$applied, array_values(array_unique($unsafe))];
    }

    private static function save(ProductType $type, ProductTypeDraft $draft): ProductType
    {
        $type->forceFill([
            'name' => trim($draft->name),
            'form' => $draft->form,
            'case_insensitive' => $draft->normalization()->caseInsensitive,
            'strip_separators' => $draft->normalization()->stripSeparators,
            'delivery_template' => DeliveryTemplate::normalize($draft->deliveryTemplate),
        ])->save();

        $keys = array_map(fn (ContentFieldDraft $field): string => $field->key, $draft->fields);

        $type->contentFields()->whereNotIn('key', $keys)->delete();
        // Bỏ cờ trước để chuyển Khoá chống trùng sang trường khác không vướng unique index.
        // Nạp trường sau câu này để dirty-check thấy cờ cần đặt lại.
        $type->contentFields()->update(['is_dedupe_key' => false]);
        $existing = $type->contentFields()->get()->keyBy('key');

        foreach ($draft->fields as $position => $field) {
            ($existing->get($field->key) ?? $type->contentFields()->make())->forceFill([
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

        return $type->unsetRelation('contentFields');
    }

    /**
     * Hai Quản trị cùng đặt một tên Loại: kiểm tra trước chỉ bắt được trường hợp tuần tự,
     * unique index trên lower(name) bắt nốt trường hợp đồng thời.
     *
     * @template T
     *
     * @param  callable(): T  $write
     * @return T
     */
    private static function guardName(ProductTypeDraft $draft, callable $write): mixed
    {
        try {
            return $write();
        } catch (UniqueConstraintViolationException) {
            throw self::nameTaken(trim($draft->name));
        }
    }

    /**
     * @throws InvalidProductConfiguration
     */
    private static function validate(ProductTypeDraft $draft, ?ProductType $current = null): void
    {
        $fail = fn (string $message) => throw new InvalidProductConfiguration($message);
        $name = trim($draft->name);

        if ($name === '') {
            $fail('Tên Loại sản phẩm không được để trống.');
        }

        if ($draft->fields === []) {
            $fail('Loại sản phẩm phải có ít nhất một Trường nội dung.');
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
            $normalized = ContentFieldName::normalize($label);

            if (isset($labelOf[$normalized])) {
                $fail("Tên hiển thị trường \"{$labelOf[$normalized]}\" bị trùng.");
            }

            if (isset($keyOf[$normalized]) && $keyOf[$normalized] !== $field->key) {
                $fail("Tên hiển thị trường \"{$label}\" trùng định danh trường \"{$keyOf[$normalized]}\".");
            }

            $labelOf[$normalized] = $label;
        }

        if (count(array_filter($draft->fields, fn (ContentFieldDraft $field): bool => $field->dedupeKey)) !== 1) {
            $fail('Phải chọn đúng một Trường nội dung làm Khoá chống trùng.');
        }

        $unknown = DeliveryTemplate::unknownVariables((string) $draft->deliveryTemplate, $keys);

        if ($unknown !== []) {
            $fail('Mẫu giao hàng dùng biến không có: '.implode(', ', array_map(fn (string $variable): string => "{{{$variable}}}", $unknown)).'.');
        }

        $taken = ProductType::query()
            ->whereRaw('lower(name) = lower(?)', [$name])
            ->when($current, fn ($query) => $query->whereKeyNot($current->getKey()))
            ->exists();

        if ($taken) {
            throw self::nameTaken($name);
        }
    }

    private static function nameTaken(string $name): InvalidProductConfiguration
    {
        return new InvalidProductConfiguration("Loại sản phẩm \"{$name}\" đã có.");
    }
}
