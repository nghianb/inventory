<?php

use App\Inventory\Access\MissingRole;
use App\Inventory\Access\Role;
use App\Inventory\Catalog\ContentFieldDraft;
use App\Inventory\Catalog\ContentFieldType;
use App\Inventory\Catalog\InvalidProductConfiguration;
use App\Inventory\Catalog\LockedProductConfiguration;
use App\Inventory\Catalog\ProductCatalog;
use App\Inventory\Catalog\ProductDraft;
use App\Inventory\Catalog\ProductHasStock;
use App\Inventory\Catalog\ProductTypeCatalog;
use App\Inventory\Catalog\ProductTypeDraft;
use App\Inventory\Catalog\StockForm;
use App\Models\Product;
use Database\Seeders\RoleSeeder;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->catalog = app(ProductCatalog::class);
    $this->admin = staffMember(Role::Owner);
    $this->accountType = productTypeOf(StockForm::Account, [
        new ContentFieldDraft('username', 'Tên đăng nhập', ContentFieldType::Email, dedupeKey: true),
        new ContentFieldDraft('password', 'Mật khẩu'),
    ], name: 'Tài khoản streaming');
});

/**
 * Sản phẩm Netflix thuộc Loại Tài khoản streaming dựng sẵn trong beforeEach.
 */
function netflixDraft(mixed ...$overrides): ProductDraft
{
    return new ProductDraft(...[
        'productType' => test()->accountType,
        'name' => 'Netflix Premium 1 tháng',
        'code' => 'NETFLIX-1M',
        'defaultSlots' => 4,
        'warrantyDays' => 30,
        ...$overrides,
    ]);
}

it('Quản trị tạo Sản phẩm thuộc một Loại sản phẩm, đọc Trường nội dung qua Loại', function () {
    $product = $this->catalog->create($this->admin, netflixDraft(lowStockThreshold: 5))->fresh();

    expect($product)
        ->product_type_id->toBe($this->accountType->id)
        ->name->toBe('Netflix Premium 1 tháng')
        ->code->toBe('NETFLIX-1M')
        ->default_slots->toBe(4)
        ->warranty_days->toBe(30)
        ->min_remaining_days->toBe(0)
        ->low_stock_threshold->toBe(5)
        ->and($product->form())->toBe(StockForm::Account)
        ->and($product->normalization())->toEqual($this->accountType->normalization())
        ->and($product->isDiscontinued())->toBeFalse()
        ->and($product->hasStock())->toBeFalse()
        ->and($product->contentFields->pluck('key')->all())->toBe(['username', 'password'])
        ->and($product->dedupeKeyField()->key)->toBe('username');
});

it('mọi Sản phẩm cùng Loại đọc ra đúng một bộ Trường nội dung', function () {
    $netflix = $this->catalog->create($this->admin, netflixDraft());
    $spotify = $this->catalog->create($this->admin, netflixDraft(name: 'Spotify 1 tháng', code: 'SPOTIFY-1M'));

    expect($netflix->fresh()->contentFields->pluck('id')->all())
        ->toBe($spotify->fresh()->contentFields->pluck('id')->all());
});

it('chỉ Quản trị tạo được Sản phẩm', function (Role $role) {
    expect(fn () => $this->catalog->create(staffMember($role), netflixDraft()))
        ->toThrow(MissingRole::class);

    expect(Product::count())->toBe(0);
})->with([
    'Nhập kho' => Role::NhapKho,
    'Bán hàng' => Role::BanHang,
]);

it('từ chối cấu hình Sản phẩm không hợp lệ', function (ProductDraft $draft) {
    expect(fn () => $this->catalog->create($this->admin, $draft))
        ->toThrow(InvalidProductConfiguration::class);

    expect(Product::count())->toBe(0);
})->with([
    'tên Sản phẩm để trống' => fn () => netflixDraft(name: ' '),
    'Mã sản phẩm sai định dạng' => fn () => netflixDraft(code: 'netflix 1 tháng'),
    'Tài khoản không có slot' => fn () => netflixDraft(defaultSlots: 0),
    'thời hạn bảo hành âm' => fn () => netflixDraft(warrantyDays: -1),
    'Hạn còn lại tối thiểu âm' => fn () => netflixDraft(minRemainingDays: -1),
    'Ngưỡng sắp hết âm' => fn () => netflixDraft(lowStockThreshold: -1),
    'Loại Dạng hàng Mã dùng một lần mà nhiều slot' => fn () => netflixDraft(
        productType: productTypeOf(StockForm::OneTimeCode, [new ContentFieldDraft('code', 'Mã thẻ', dedupeKey: true)]),
        defaultSlots: 2,
    ),
]);

it('Mã sản phẩm là duy nhất', function () {
    $this->catalog->create($this->admin, netflixDraft());

    expect(fn () => $this->catalog->create($this->admin, netflixDraft(name: 'Netflix bản sao')))
        ->toThrow(InvalidProductConfiguration::class, 'Mã sản phẩm NETFLIX-1M đã được dùng.');

    expect(Product::count())->toBe(1);
});

it('Quản trị sửa được cấu hình riêng của Sản phẩm', function () {
    $product = $this->catalog->create($this->admin, netflixDraft());

    $this->catalog->update($this->admin, $product, netflixDraft(
        name: 'Netflix 30 ngày',
        code: 'NETFLIX-30D',
        defaultSlots: 5,
        warrantyDays: 25,
        minRemainingDays: 2,
        lowStockThreshold: 10,
    ));

    expect($product->fresh())
        ->name->toBe('Netflix 30 ngày')
        ->code->toBe('NETFLIX-30D')
        ->default_slots->toBe(5)
        ->warranty_days->toBe(25)
        ->min_remaining_days->toBe(2)
        ->low_stock_threshold->toBe(10);
});

it('sửa Sản phẩm vẫn kiểm tra cấu hình và cho giữ nguyên Mã sản phẩm của chính nó', function () {
    $product = $this->catalog->create($this->admin, netflixDraft());
    $this->catalog->create($this->admin, netflixDraft(name: 'Netflix 3 tháng', code: 'NETFLIX-3M'));

    $this->catalog->update($this->admin, $product, netflixDraft(name: 'Netflix Premium 30 ngày'));

    expect($product->fresh()->name)->toBe('Netflix Premium 30 ngày')
        ->and(fn () => $this->catalog->update($this->admin, $product, netflixDraft(code: 'NETFLIX-3M')))
        ->toThrow(InvalidProductConfiguration::class, 'Mã sản phẩm NETFLIX-3M đã được dùng.');

    expect($product->fresh()->code)->toBe('NETFLIX-1M');
});

it('chỉ Quản trị sửa được Sản phẩm', function (Role $role) {
    $product = $this->catalog->create($this->admin, netflixDraft());

    expect(fn () => $this->catalog->update(staffMember($role), $product, netflixDraft(name: 'Đổi tên')))
        ->toThrow(MissingRole::class);

    expect($product->fresh()->name)->toBe('Netflix Premium 1 tháng');
})->with([
    'Nhập kho' => Role::NhapKho,
    'Bán hàng' => Role::BanHang,
]);

it('Sản phẩm chưa có hàng chuyển được sang Loại sản phẩm khác', function () {
    $product = $this->catalog->create($this->admin, netflixDraft());
    $codeType = productTypeOf(StockForm::OneTimeCode, [
        new ContentFieldDraft('card_code', 'Mã thẻ', dedupeKey: true),
    ], name: 'Thẻ nạp');

    $this->catalog->update($this->admin, $product, netflixDraft(productType: $codeType, defaultSlots: 1));

    $product = $product->fresh();

    expect($product->product_type_id)->toBe($codeType->id)
        ->and($product->form())->toBe(StockForm::OneTimeCode)
        ->and($product->contentFields->pluck('key')->all())->toBe(['card_code']);
});

it('Sản phẩm đã có hàng không chuyển được sang Loại sản phẩm khác', function () {
    $product = withStock($this->catalog->create($this->admin, netflixDraft()));
    $other = productTypeOf(StockForm::Account, [
        new ContentFieldDraft('username', 'Tên đăng nhập', dedupeKey: true),
    ], name: 'Tài khoản khác');

    expect(fn () => $this->catalog->update($this->admin, $product, netflixDraft(productType: $other)))
        ->toThrow(LockedProductConfiguration::class, ProductCatalog::STOCK_LOCKS_PRODUCT_TYPE);

    expect($product->fresh()->product_type_id)->toBe($this->accountType->id);
});

it('Sản phẩm đã có hàng vẫn sửa được tên, mã, slot, hạn và ngưỡng của riêng nó', function () {
    $product = withStock($this->catalog->create($this->admin, netflixDraft()));

    $this->catalog->update($this->admin, $product, netflixDraft(
        name: 'Netflix 30 ngày',
        code: 'NETFLIX-30D',
        defaultSlots: 5,
        warrantyDays: 25,
    ));

    expect($product->fresh())
        ->name->toBe('Netflix 30 ngày')
        ->code->toBe('NETFLIX-30D')
        ->default_slots->toBe(5)
        ->warranty_days->toBe(25);
});

it('Mẫu giao hàng tìm theo ba bậc: Sản phẩm, rồi Loại, rồi mẫu mặc định', function () {
    $types = app(ProductTypeCatalog::class);
    $product = $this->catalog->create($this->admin, netflixDraft());

    // Bậc 3: không ai có mẫu.
    expect($product->fresh()->deliveryTemplate())->toBeNull();

    // Bậc 2: Loại có mẫu, Sản phẩm không ghi đè.
    $types->update($this->admin, $this->accountType, new ProductTypeDraft(
        name: 'Tài khoản streaming',
        form: StockForm::Account,
        fields: [
            new ContentFieldDraft('username', 'Tên đăng nhập', ContentFieldType::Email, dedupeKey: true),
            new ContentFieldDraft('password', 'Mật khẩu'),
        ],
        deliveryTemplate: 'Tài khoản: {{username}} / {{password}}',
    ));

    expect($product->fresh()->deliveryTemplate())->toBe('Tài khoản: {{username}} / {{password}}');

    // Bậc 1: Sản phẩm ghi đè mẫu riêng.
    $this->catalog->update($this->admin, $product, netflixDraft(deliveryTemplate: 'Netflix của bạn: {{username}}'));

    expect($product->fresh()->deliveryTemplate())->toBe('Netflix của bạn: {{username}}');

    // Loại đổi mẫu thì Sản phẩm đã ghi đè không bị đụng tới.
    $types->update($this->admin, $this->accountType->fresh(), new ProductTypeDraft(
        name: 'Tài khoản streaming',
        form: StockForm::Account,
        fields: [
            new ContentFieldDraft('username', 'Tên đăng nhập', ContentFieldType::Email, dedupeKey: true),
            new ContentFieldDraft('password', 'Mật khẩu'),
        ],
        deliveryTemplate: 'Mẫu mới của Loại: {{username}}',
    ));

    expect($product->fresh()->deliveryTemplate())->toBe('Netflix của bạn: {{username}}');

    // Bỏ mẫu riêng thì Sản phẩm rơi về mẫu của Loại.
    $this->catalog->update($this->admin, $product->fresh(), netflixDraft(deliveryTemplate: "  \n "));

    expect($product->fresh())
        ->delivery_template->toBeNull()
        ->and($product->fresh()->deliveryTemplate())->toBe('Mẫu mới của Loại: {{username}}');
});

it('Mẫu giao hàng của Sản phẩm chỉ dùng được biến của Loại', function () {
    expect(fn () => $this->catalog->create($this->admin, netflixDraft(deliveryTemplate: 'Mã: {{ma_the}} {{username}}')))
        ->toThrow(InvalidProductConfiguration::class, 'Mẫu giao hàng dùng biến không có: {{ma_the}}.');

    expect(Product::count())->toBe(0);
});

it('Quản trị Ngừng bán Sản phẩm', function () {
    $product = withStock($this->catalog->create($this->admin, netflixDraft()));

    $this->catalog->discontinue($this->admin, $product);

    expect($product->fresh()->isDiscontinued())->toBeTrue();
});

it('chỉ Quản trị Ngừng bán được Sản phẩm', function (Role $role) {
    $product = $this->catalog->create($this->admin, netflixDraft());

    expect(fn () => $this->catalog->discontinue(staffMember($role), $product))
        ->toThrow(MissingRole::class);

    expect($product->fresh()->isDiscontinued())->toBeFalse();
})->with([
    'Nhập kho' => Role::NhapKho,
    'Bán hàng' => Role::BanHang,
]);

it('Quản trị xoá được Sản phẩm chưa có hàng', function () {
    $product = $this->catalog->create($this->admin, netflixDraft());

    $this->catalog->delete($this->admin, $product);

    expect(Product::find($product->id))->toBeNull()
        ->and($this->catalog->create($this->admin, netflixDraft())->code)->toBe('NETFLIX-1M');
});

it('Sản phẩm đã có hàng không xoá được, chỉ Ngừng bán', function () {
    $product = withStock($this->catalog->create($this->admin, netflixDraft()));

    expect(fn () => $this->catalog->delete($this->admin, $product))
        ->toThrow(ProductHasStock::class, 'Sản phẩm đã có hàng không xoá được, chỉ Ngừng bán.');

    expect(Product::find($product->id))->not->toBeNull();
});

it('chỉ Quản trị xoá được Sản phẩm', function (Role $role) {
    $product = $this->catalog->create($this->admin, netflixDraft());

    expect(fn () => $this->catalog->delete(staffMember($role), $product))
        ->toThrow(MissingRole::class);

    expect(Product::find($product->id))->not->toBeNull();
})->with([
    'Nhập kho' => Role::NhapKho,
    'Bán hàng' => Role::BanHang,
]);
