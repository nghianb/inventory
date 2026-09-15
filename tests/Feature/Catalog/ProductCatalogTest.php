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
use App\Inventory\Catalog\ProductType;
use App\Inventory\Encryption\Normalization;
use App\Models\Product;
use Database\Seeders\RoleSeeder;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->catalog = app(ProductCatalog::class);
});

/**
 * Sản phẩm Tài khoản Netflix: username là Khoá chống trùng, password nhạy cảm.
 *
 * @param  list<ContentFieldDraft>|null  $fields
 */
function netflixDraft(?array $fields = null, mixed ...$overrides): ProductDraft
{
    return new ProductDraft(...[
        'type' => ProductType::Account,
        'name' => 'Netflix Premium 1 tháng',
        'code' => 'NETFLIX-1M',
        'fields' => $fields ?? [
            new ContentFieldDraft('username', 'Tên đăng nhập', ContentFieldType::Email, dedupeKey: true),
            new ContentFieldDraft('password', 'Mật khẩu'),
        ],
        'defaultSlots' => 4,
        'warrantyDays' => 30,
        ...$overrides,
    ]);
}

it('Quản trị tạo Sản phẩm Tài khoản kèm Trường nội dung và Khoá chống trùng', function () {
    $product = $this->catalog->create(staffMember(Role::QuanTri), netflixDraft(lowStockThreshold: 5));

    $product = $product->fresh();

    expect($product)
        ->type->toBe(ProductType::Account)
        ->name->toBe('Netflix Premium 1 tháng')
        ->code->toBe('NETFLIX-1M')
        ->default_slots->toBe(4)
        ->warranty_days->toBe(30)
        ->min_remaining_days->toBe(0)
        ->low_stock_threshold->toBe(5)
        ->and($product->isDiscontinued())->toBeFalse()
        ->and($product->hasStock())->toBeFalse()
        ->and($product->contentFields->pluck('key')->all())->toBe(['username', 'password'])
        ->and($product->dedupeKeyField()->key)->toBe('username');

    expect($product->contentFields->firstWhere('key', 'password'))
        ->label->toBe('Mật khẩu')
        ->type->toBe(ContentFieldType::Text)
        ->pattern->toBeNull()
        ->required->toBeTrue()
        ->sensitive->toBeTrue();
});

it('chỉ Quản trị tạo được Sản phẩm', function (Role $role) {
    expect(fn () => $this->catalog->create(staffMember($role), netflixDraft()))
        ->toThrow(MissingRole::class);

    expect(Product::count())->toBe(0);
})->with([
    'Nhập kho' => Role::NhapKho,
    'Bán hàng' => Role::BanHang,
]);

it('tuỳ chọn chuẩn hoá mặc định theo loại Sản phẩm', function (ProductType $type, string $raw, string $normalized) {
    $product = $this->catalog->create(staffMember(Role::QuanTri), netflixDraft(type: $type, defaultSlots: 1));

    expect($product->fresh()->normalization()->apply($raw))->toBe($normalized);
})->with([
    'Mã dùng một lần: bỏ hoa thường và gạch ngang' => [ProductType::OneTimeCode, ' abcd-EFGH ijkl ', 'abcdefghijkl'],
    'Tài khoản: bỏ hoa thường, giữ ký tự bên trong' => [ProductType::Account, ' Khach.Hang-01@Mail.com ', 'khach.hang-01@mail.com'],
]);

it('Quản trị chọn tuỳ chọn chuẩn hoá riêng cho Sản phẩm', function () {
    $product = $this->catalog->create(staffMember(Role::QuanTri), netflixDraft(
        normalization: new Normalization(caseInsensitive: false, stripSeparators: true),
    ));

    expect($product->fresh()->normalization())->toEqual(new Normalization(caseInsensitive: false, stripSeparators: true));
});

it('từ chối cấu hình Sản phẩm không hợp lệ', function (ProductDraft $draft) {
    expect(fn () => $this->catalog->create(staffMember(Role::QuanTri), $draft))
        ->toThrow(InvalidProductConfiguration::class);

    expect(Product::count())->toBe(0);
})->with([
    'không có Trường nội dung' => fn () => netflixDraft([]),
    'không có Khoá chống trùng' => fn () => netflixDraft([
        new ContentFieldDraft('username', 'Tên đăng nhập'),
        new ContentFieldDraft('password', 'Mật khẩu'),
    ]),
    'hai Khoá chống trùng' => fn () => netflixDraft([
        new ContentFieldDraft('username', 'Tên đăng nhập', dedupeKey: true),
        new ContentFieldDraft('password', 'Mật khẩu', dedupeKey: true),
    ]),
    'Khoá chống trùng là trường tuỳ chọn' => fn () => netflixDraft([
        new ContentFieldDraft('username', 'Tên đăng nhập', required: false, dedupeKey: true),
    ]),
    'trùng định danh trường' => fn () => netflixDraft([
        new ContentFieldDraft('username', 'Tên đăng nhập', dedupeKey: true),
        new ContentFieldDraft('username', 'Email'),
    ]),
    'định danh trường sai định dạng' => fn () => netflixDraft([
        new ContentFieldDraft('Tên đăng nhập', 'Tên đăng nhập', dedupeKey: true),
    ]),
    'tên hiển thị trường để trống' => fn () => netflixDraft([
        new ContentFieldDraft('username', '  ', dedupeKey: true),
    ]),
    'regex không hợp lệ' => fn () => netflixDraft([
        new ContentFieldDraft('username', 'Tên đăng nhập', pattern: '[a-z', dedupeKey: true),
    ]),
    'Mã dùng một lần có nhiều slot' => fn () => netflixDraft(type: ProductType::OneTimeCode, defaultSlots: 2),
    'Tài khoản không có slot' => fn () => netflixDraft(defaultSlots: 0),
    'tên Sản phẩm để trống' => fn () => netflixDraft(name: ' '),
    'Mã sản phẩm sai định dạng' => fn () => netflixDraft(code: 'netflix 1 tháng'),
    'thời hạn bảo hành âm' => fn () => netflixDraft(warrantyDays: -1),
    'Hạn còn lại tối thiểu âm' => fn () => netflixDraft(minRemainingDays: -1),
    'Ngưỡng sắp hết âm' => fn () => netflixDraft(lowStockThreshold: -1),
]);

it('Mã sản phẩm là duy nhất', function () {
    $admin = staffMember(Role::QuanTri);
    $this->catalog->create($admin, netflixDraft());

    expect(fn () => $this->catalog->create($admin, netflixDraft(name: 'Netflix bản sao')))
        ->toThrow(InvalidProductConfiguration::class, 'Mã sản phẩm NETFLIX-1M đã được dùng.');

    expect(Product::count())->toBe(1);
});

it('Quản trị sửa mọi cấu hình của Sản phẩm chưa có hàng', function () {
    $admin = staffMember(Role::QuanTri);
    $product = $this->catalog->create($admin, netflixDraft());

    $this->catalog->update($admin, $product, new ProductDraft(
        type: ProductType::OneTimeCode,
        name: 'Netflix gift card',
        code: 'NETFLIX-GIFT',
        fields: [
            new ContentFieldDraft('serial', 'Serial', sensitive: false),
            new ContentFieldDraft('card_code', 'Mã thẻ', ContentFieldType::Number, pattern: '\d{12}', dedupeKey: true),
        ],
        defaultSlots: 1,
        warrantyDays: 7,
        minRemainingDays: 3,
        lowStockThreshold: null,
        normalization: new Normalization(caseInsensitive: false, stripSeparators: false),
    ));

    $product = $product->fresh();

    expect($product)
        ->type->toBe(ProductType::OneTimeCode)
        ->name->toBe('Netflix gift card')
        ->code->toBe('NETFLIX-GIFT')
        ->default_slots->toBe(1)
        ->warranty_days->toBe(7)
        ->min_remaining_days->toBe(3)
        ->low_stock_threshold->toBeNull()
        ->and($product->normalization())->toEqual(new Normalization(caseInsensitive: false, stripSeparators: false))
        ->and($product->contentFields->pluck('key')->all())->toBe(['serial', 'card_code'])
        ->and($product->dedupeKeyField()->key)->toBe('card_code')
        ->and($product->contentFields->firstWhere('key', 'serial')->sensitive)->toBeFalse();

    expect($product->dedupeKeyField())
        ->label->toBe('Mã thẻ')
        ->type->toBe(ContentFieldType::Number)
        ->pattern->toBe('\d{12}');
});

it('sửa Sản phẩm vẫn kiểm tra cấu hình và cho giữ nguyên Mã sản phẩm của chính nó', function () {
    $admin = staffMember(Role::QuanTri);
    $product = $this->catalog->create($admin, netflixDraft());
    $this->catalog->create($admin, netflixDraft(code: 'NETFLIX-3M'));

    $this->catalog->update($admin, $product, netflixDraft(name: 'Netflix Premium 30 ngày'));

    expect($product->fresh()->dedupeKeyField()->key)->toBe('username');

    expect($product->fresh()->name)->toBe('Netflix Premium 30 ngày')
        ->and(fn () => $this->catalog->update($admin, $product, netflixDraft(code: 'NETFLIX-3M')))
        ->toThrow(InvalidProductConfiguration::class, 'Mã sản phẩm NETFLIX-3M đã được dùng.')
        ->and(fn () => $this->catalog->update($admin, $product, netflixDraft([])))
        ->toThrow(InvalidProductConfiguration::class);

    expect($product->fresh())
        ->code->toBe('NETFLIX-1M')
        ->contentFields->toHaveCount(2);
});

it('chỉ Quản trị sửa được Sản phẩm', function (Role $role) {
    $product = $this->catalog->create(staffMember(Role::QuanTri), netflixDraft());

    expect(fn () => $this->catalog->update(staffMember($role), $product, netflixDraft(name: 'Đổi tên')))
        ->toThrow(MissingRole::class);

    expect($product->fresh()->name)->toBe('Netflix Premium 1 tháng');
})->with([
    'Nhập kho' => Role::NhapKho,
    'Bán hàng' => Role::BanHang,
]);

/**
 * Tạm đánh dấu Sản phẩm đã có hàng cho tới khi có thao tác Nhập hàng.
 */
function withStock(Product $product): Product
{
    $product->forceFill(['stocked_at' => now()])->save();

    return $product;
}

it('Sản phẩm đã có hàng vẫn đổi được tên hiển thị, thêm trường tuỳ chọn và sửa cấu hình không bị khoá', function () {
    $admin = staffMember(Role::QuanTri);
    $product = withStock($this->catalog->create($admin, netflixDraft()));

    $this->catalog->update($admin, $product, netflixDraft([
        new ContentFieldDraft('username', 'Email đăng nhập', ContentFieldType::Email, dedupeKey: true),
        new ContentFieldDraft('password', 'Mật khẩu tài khoản'),
        new ContentFieldDraft('recovery_email', 'Email khôi phục', ContentFieldType::Email, required: false, sensitive: false),
    ], name: 'Netflix 30 ngày', code: 'NETFLIX-30D', defaultSlots: 5, warrantyDays: 25, minRemainingDays: 2, lowStockThreshold: 10));

    $product = $product->fresh();

    expect($product)
        ->name->toBe('Netflix 30 ngày')
        ->code->toBe('NETFLIX-30D')
        ->default_slots->toBe(5)
        ->warranty_days->toBe(25)
        ->min_remaining_days->toBe(2)
        ->low_stock_threshold->toBe(10)
        ->and($product->dedupeKeyField()->key)->toBe('username')
        ->and($product->contentFields->pluck('label', 'key')->all())->toBe([
            'username' => 'Email đăng nhập',
            'password' => 'Mật khẩu tài khoản',
            'recovery_email' => 'Email khôi phục',
        ]);
});

it('Sản phẩm đã có hàng khoá Trường nội dung, Khoá chống trùng, cờ nhạy cảm và tuỳ chọn chuẩn hoá', function (ProductDraft $draft) {
    $admin = staffMember(Role::QuanTri);
    $product = withStock($this->catalog->create($admin, netflixDraft()));

    expect(fn () => $this->catalog->update($admin, $product, $draft))
        ->toThrow(LockedProductConfiguration::class);

    $product = $product->fresh();

    expect($product)
        ->type->toBe(ProductType::Account)
        ->and($product->normalization())->toEqual(ProductType::Account->defaultNormalization())
        ->and($product->dedupeKeyField()->key)->toBe('username')
        ->and($product->contentFields->pluck('key')->all())->toBe(['username', 'password'])
        ->and($product->contentFields->firstWhere('key', 'password'))
        ->sensitive->toBeTrue()
        ->required->toBeTrue();
})->with([
    'đổi loại Sản phẩm' => fn () => netflixDraft(type: ProductType::OneTimeCode, defaultSlots: 1),
    'đổi tuỳ chọn chuẩn hoá' => fn () => netflixDraft(normalization: new Normalization(caseInsensitive: false)),
    'đổi Khoá chống trùng' => fn () => netflixDraft([
        new ContentFieldDraft('username', 'Tên đăng nhập', ContentFieldType::Email),
        new ContentFieldDraft('password', 'Mật khẩu', dedupeKey: true),
    ]),
    'tắt cờ nhạy cảm' => fn () => netflixDraft([
        new ContentFieldDraft('username', 'Tên đăng nhập', ContentFieldType::Email, dedupeKey: true),
        new ContentFieldDraft('password', 'Mật khẩu', sensitive: false),
    ]),
    'xoá trường' => fn () => netflixDraft([
        new ContentFieldDraft('username', 'Tên đăng nhập', ContentFieldType::Email, dedupeKey: true),
    ]),
    'thêm trường bắt buộc' => fn () => netflixDraft([
        new ContentFieldDraft('username', 'Tên đăng nhập', ContentFieldType::Email, dedupeKey: true),
        new ContentFieldDraft('password', 'Mật khẩu'),
        new ContentFieldDraft('otp_secret', 'Mã 2FA'),
    ]),
    'đổi trường có sẵn sang tuỳ chọn' => fn () => netflixDraft([
        new ContentFieldDraft('username', 'Tên đăng nhập', ContentFieldType::Email, dedupeKey: true),
        new ContentFieldDraft('password', 'Mật khẩu', required: false),
    ]),
    'đổi kiểu trường' => fn () => netflixDraft([
        new ContentFieldDraft('username', 'Tên đăng nhập', ContentFieldType::Text, dedupeKey: true),
        new ContentFieldDraft('password', 'Mật khẩu'),
    ]),
    'đổi regex' => fn () => netflixDraft([
        new ContentFieldDraft('username', 'Tên đăng nhập', ContentFieldType::Email, dedupeKey: true),
        new ContentFieldDraft('password', 'Mật khẩu', pattern: '.{8,}'),
    ]),
]);

it('Quản trị Ngừng bán Sản phẩm', function () {
    $admin = staffMember(Role::QuanTri);
    $product = withStock($this->catalog->create($admin, netflixDraft()));

    $this->catalog->discontinue($admin, $product);

    expect($product->fresh()->isDiscontinued())->toBeTrue();
});

it('chỉ Quản trị Ngừng bán được Sản phẩm', function (Role $role) {
    $product = $this->catalog->create(staffMember(Role::QuanTri), netflixDraft());

    expect(fn () => $this->catalog->discontinue(staffMember($role), $product))
        ->toThrow(MissingRole::class);

    expect($product->fresh()->isDiscontinued())->toBeFalse();
})->with([
    'Nhập kho' => Role::NhapKho,
    'Bán hàng' => Role::BanHang,
]);

it('Quản trị xoá được Sản phẩm chưa có hàng', function () {
    $admin = staffMember(Role::QuanTri);
    $product = $this->catalog->create($admin, netflixDraft());

    $this->catalog->delete($admin, $product);

    expect(Product::find($product->id))->toBeNull()
        ->and($this->catalog->create($admin, netflixDraft())->code)->toBe('NETFLIX-1M');
});

it('Sản phẩm đã có hàng không xoá được, chỉ Ngừng bán', function () {
    $admin = staffMember(Role::QuanTri);
    $product = withStock($this->catalog->create($admin, netflixDraft()));

    expect(fn () => $this->catalog->delete($admin, $product))
        ->toThrow(ProductHasStock::class, 'Sản phẩm đã có hàng không xoá được, chỉ Ngừng bán.');

    expect(Product::find($product->id))->not->toBeNull();
});

it('chỉ Quản trị xoá được Sản phẩm', function (Role $role) {
    $product = $this->catalog->create(staffMember(Role::QuanTri), netflixDraft());

    expect(fn () => $this->catalog->delete(staffMember($role), $product))
        ->toThrow(MissingRole::class);

    expect(Product::find($product->id))->not->toBeNull();
})->with([
    'Nhập kho' => Role::NhapKho,
    'Bán hàng' => Role::BanHang,
]);

it('Quản trị soạn Mẫu giao hàng với biến Trường nội dung, Hạn sử dụng, Hạn bảo hành, tên Sản phẩm, mã đơn; để trống thì dùng mẫu mặc định', function () {
    $admin = staffMember(Role::QuanTri);
    $template = "Cảm ơn bạn đã mua {{san_pham}} (đơn {{ ma_don }})\nTài khoản: {{username}} / {{password}}\nHạn: {{han_su_dung}} · Bảo hành đến {{han_bao_hanh}}";

    $product = $this->catalog->create($admin, netflixDraft(deliveryTemplate: $template));

    expect($product->fresh()->delivery_template)->toBe($template);

    $this->catalog->update($admin, $product, netflixDraft(deliveryTemplate: "  \n "));

    expect($product->fresh()->delivery_template)->toBeNull();
});

it('Mẫu giao hàng chỉ dùng được biến đã khai báo', function (ProductDraft $draft, string $message) {
    expect(fn () => $this->catalog->create(staffMember(Role::QuanTri), $draft))
        ->toThrow(InvalidProductConfiguration::class, $message);

    expect(Product::count())->toBe(0);
})->with([
    'biến không tồn tại' => [fn () => netflixDraft(deliveryTemplate: 'Mã: {{ma_the}} {{user_name}}'), 'Mẫu giao hàng dùng biến không có: {{ma_the}}, {{user_name}}.'],
    'Trường nội dung trùng tên biến có sẵn' => [fn () => netflixDraft([
        new ContentFieldDraft('username', 'Tên đăng nhập', dedupeKey: true),
        new ContentFieldDraft('ma_don', 'Mã đơn gốc'),
    ]), 'Định danh trường "ma_don" trùng tên biến của Mẫu giao hàng.'],
]);
