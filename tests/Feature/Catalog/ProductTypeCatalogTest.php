<?php

use App\Inventory\Access\MissingRole;
use App\Inventory\Access\Role;
use App\Inventory\Catalog\ContentFieldDraft;
use App\Inventory\Catalog\ContentFieldType;
use App\Inventory\Catalog\InvalidProductConfiguration;
use App\Inventory\Catalog\LockedProductConfiguration;
use App\Inventory\Catalog\ProductCatalog;
use App\Inventory\Catalog\ProductDraft;
use App\Inventory\Catalog\ProductTypeCatalog;
use App\Inventory\Catalog\ProductTypeDraft;
use App\Inventory\Catalog\ProductTypeInUse;
use App\Inventory\Catalog\StockForm;
use App\Inventory\Encryption\Normalization;
use App\Models\ProductType;
use Database\Seeders\RoleSeeder;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->catalog = app(ProductTypeCatalog::class);
    $this->admin = staffMember(Role::Owner);
});

/**
 * Loại Thẻ nạp: Serial không nhạy cảm, Mã thẻ là Khoá chống trùng.
 *
 * @param  list<ContentFieldDraft>|null  $fields
 */
function cardTypeDraft(?array $fields = null, mixed ...$overrides): ProductTypeDraft
{
    return new ProductTypeDraft(...[
        'name' => 'Thẻ nạp',
        'form' => StockForm::OneTimeCode,
        'fields' => $fields ?? [
            new ContentFieldDraft('serial', 'Serial', sensitive: false),
            new ContentFieldDraft('card_code', 'Mã thẻ', ContentFieldType::Number, pattern: '\d{12}', dedupeKey: true),
        ],
        ...$overrides,
    ]);
}

it('Quản trị tạo Loại sản phẩm kèm Dạng hàng, Trường nội dung và Khoá chống trùng', function () {
    $type = $this->catalog->create($this->admin, cardTypeDraft())->fresh();

    expect($type)
        ->name->toBe('Thẻ nạp')
        ->form->toBe(StockForm::OneTimeCode)
        ->and($type->isDiscontinued())->toBeFalse()
        ->and($type->contentFields->pluck('key')->all())->toBe(['serial', 'card_code'])
        ->and($type->dedupeKeyField()->key)->toBe('card_code')
        ->and($type->normalization())->toEqual(StockForm::OneTimeCode->defaultNormalization());

    expect($type->contentFields->firstWhere('key', 'card_code'))
        ->label->toBe('Mã thẻ')
        ->type->toBe(ContentFieldType::Number)
        ->pattern->toBe('\d{12}')
        ->required->toBeTrue()
        ->sensitive->toBeTrue();
});

it('chỉ Quản trị tạo được Loại sản phẩm', function (Role $role) {
    expect(fn () => $this->catalog->create(staffMember($role), cardTypeDraft()))
        ->toThrow(MissingRole::class);

    expect(ProductType::count())->toBe(0);
})->with([
    'Nhập kho' => Role::NhapKho,
    'Bán hàng' => Role::BanHang,
]);

it('tuỳ chọn chuẩn hoá mặc định theo Dạng hàng', function (StockForm $form, string $raw, string $normalized) {
    $type = $this->catalog->create($this->admin, cardTypeDraft(form: $form));

    expect($type->fresh()->normalization()->apply($raw))->toBe($normalized);
})->with([
    'Mã dùng một lần: bỏ hoa thường và gạch ngang' => [StockForm::OneTimeCode, ' abcd-EFGH ijkl ', 'abcdefghijkl'],
    'Tài khoản: bỏ hoa thường, giữ ký tự bên trong' => [StockForm::Account, ' Khach.Hang-01@Mail.com ', 'khach.hang-01@mail.com'],
]);

it('Quản trị chọn tuỳ chọn chuẩn hoá riêng cho Loại', function () {
    $type = $this->catalog->create($this->admin, cardTypeDraft(
        normalization: new Normalization(caseInsensitive: false, stripSeparators: true),
    ));

    expect($type->fresh()->normalization())->toEqual(new Normalization(caseInsensitive: false, stripSeparators: true));
});

it('tên Loại sản phẩm là duy nhất, không phân biệt hoa thường', function () {
    $this->catalog->create($this->admin, cardTypeDraft());

    expect(fn () => $this->catalog->create($this->admin, cardTypeDraft(name: ' thẻ NẠP ')))
        ->toThrow(InvalidProductConfiguration::class, 'Loại sản phẩm "thẻ NẠP" đã có.');

    expect(ProductType::count())->toBe(1);
});

it('từ chối khai báo Loại sản phẩm không hợp lệ', function (ProductTypeDraft $draft) {
    expect(fn () => $this->catalog->create($this->admin, $draft))
        ->toThrow(InvalidProductConfiguration::class);

    expect(ProductType::count())->toBe(0);
})->with([
    'tên Loại để trống' => fn () => cardTypeDraft(name: '  '),
    'không có Trường nội dung' => fn () => cardTypeDraft([]),
    'không có Khoá chống trùng' => fn () => cardTypeDraft([
        new ContentFieldDraft('serial', 'Serial'),
        new ContentFieldDraft('card_code', 'Mã thẻ'),
    ]),
    'hai Khoá chống trùng' => fn () => cardTypeDraft([
        new ContentFieldDraft('serial', 'Serial', dedupeKey: true),
        new ContentFieldDraft('card_code', 'Mã thẻ', dedupeKey: true),
    ]),
    'Khoá chống trùng là trường tuỳ chọn' => fn () => cardTypeDraft([
        new ContentFieldDraft('card_code', 'Mã thẻ', required: false, dedupeKey: true),
    ]),
    'trùng định danh trường' => fn () => cardTypeDraft([
        new ContentFieldDraft('serial', 'Serial', dedupeKey: true),
        new ContentFieldDraft('serial', 'Mã thẻ'),
    ]),
    'định danh trường sai định dạng' => fn () => cardTypeDraft([
        new ContentFieldDraft('Mã thẻ', 'Mã thẻ', dedupeKey: true),
    ]),
    'tên hiển thị trường để trống' => fn () => cardTypeDraft([
        new ContentFieldDraft('card_code', '  ', dedupeKey: true),
    ]),
    'regex không hợp lệ' => fn () => cardTypeDraft([
        new ContentFieldDraft('card_code', 'Mã thẻ', pattern: '[a-z', dedupeKey: true),
    ]),
    'định danh trùng biến của Mẫu giao hàng' => fn () => cardTypeDraft([
        new ContentFieldDraft('card_code', 'Mã thẻ', dedupeKey: true),
        new ContentFieldDraft('ma_don', 'Mã đơn gốc'),
    ]),
]);

it('Định danh và tên hiển thị Trường nội dung dùng chung một không gian tên trong Loại', function (ProductTypeDraft $draft, string $message) {
    expect(fn () => $this->catalog->create($this->admin, $draft))
        ->toThrow(InvalidProductConfiguration::class, $message);

    expect(ProductType::count())->toBe(0);
})->with([
    'hai trường trùng tên hiển thị' => [fn () => cardTypeDraft([
        new ContentFieldDraft('serial', 'Mã', dedupeKey: true),
        new ContentFieldDraft('card_code', '  mã  '),
    ]), 'Tên hiển thị trường "Mã" bị trùng.'],
    'tên hiển thị trùng định danh trường khác' => [fn () => cardTypeDraft([
        new ContentFieldDraft('serial', 'Mã', dedupeKey: true),
        new ContentFieldDraft('card_code', ' Serial '),
    ]), 'Tên hiển thị trường "Serial" trùng định danh trường "serial".'],
]);

it('Mẫu giao hàng của Loại chỉ dùng được biến đã khai', function () {
    expect(fn () => $this->catalog->create($this->admin, cardTypeDraft(deliveryTemplate: 'Mã: {{ma_the}} {{serial}}')))
        ->toThrow(InvalidProductConfiguration::class, 'Mẫu giao hàng dùng biến không có: {{ma_the}}.');

    $type = $this->catalog->create($this->admin, cardTypeDraft(deliveryTemplate: "Serial {{serial}}\nMã {{card_code}}"));

    expect($type->fresh()->delivery_template)->toBe("Serial {{serial}}\nMã {{card_code}}");
});

it('Loại chưa Sản phẩm nào có hàng thì sửa được mọi khai báo', function () {
    $type = $this->catalog->create($this->admin, cardTypeDraft());
    productIn($type, 'Thẻ Garena 100k', 'GARENA-100K');

    $this->catalog->update($this->admin, $type, new ProductTypeDraft(
        name: 'Tài khoản game',
        form: StockForm::Account,
        fields: [
            new ContentFieldDraft('username', 'Tên đăng nhập', ContentFieldType::Email, dedupeKey: true),
            new ContentFieldDraft('password', 'Mật khẩu'),
        ],
        normalization: new Normalization(caseInsensitive: false, stripSeparators: false),
    ));

    $type = $type->fresh();

    expect($type)
        ->name->toBe('Tài khoản game')
        ->form->toBe(StockForm::Account)
        ->and($type->contentFields->pluck('key')->all())->toBe(['username', 'password'])
        ->and($type->dedupeKeyField()->key)->toBe('username')
        ->and($type->normalization())->toEqual(new Normalization(caseInsensitive: false, stripSeparators: false));
});

it('Loại đã có Sản phẩm có hàng vẫn thêm được trường tuỳ chọn và đổi tên hiển thị, áp cho mọi Sản phẩm', function () {
    $type = $this->catalog->create($this->admin, cardTypeDraft());
    $stocked = withStock(productIn($type, 'Thẻ Garena 100k', 'GARENA-100K'));
    $fresh = productIn($type, 'Thẻ Garena 50k', 'GARENA-50K');

    $this->catalog->update($this->admin, $type, cardTypeDraft([
        new ContentFieldDraft('serial', 'Số seri', sensitive: false),
        new ContentFieldDraft('card_code', 'Mã nạp', ContentFieldType::Number, pattern: '\d{12}', dedupeKey: true),
        new ContentFieldDraft('note', 'Ghi chú', required: false),
    ], name: 'Thẻ nạp game'));

    expect($type->fresh()->name)->toBe('Thẻ nạp game');

    foreach ([$stocked, $fresh] as $product) {
        expect($product->fresh()->contentFields->pluck('label', 'key')->all())->toBe([
            'serial' => 'Số seri',
            'card_code' => 'Mã nạp',
            'note' => 'Ghi chú',
        ]);
    }
});

it('Loại đã có Sản phẩm có hàng từ chối trọn gói mọi thay đổi chạm dữ liệu đã lưu', function (ProductTypeDraft $draft) {
    $type = $this->catalog->create($this->admin, cardTypeDraft());
    withStock(productIn($type, 'Thẻ Garena 100k', 'GARENA-100K'));

    expect(fn () => $this->catalog->update($this->admin, $type, $draft))
        ->toThrow(LockedProductConfiguration::class);

    $type = $type->fresh();

    // Không ghi gì cả: cả phần áp được nằm chung bản sửa cũng không vào.
    expect($type)
        ->name->toBe('Thẻ nạp')
        ->form->toBe(StockForm::OneTimeCode)
        ->and($type->normalization())->toEqual(StockForm::OneTimeCode->defaultNormalization())
        ->and($type->contentFields->pluck('label', 'key')->all())->toBe(['serial' => 'Serial', 'card_code' => 'Mã thẻ'])
        ->and($type->dedupeKeyField()->key)->toBe('card_code')
        ->and($type->contentFields->firstWhere('key', 'serial')->sensitive)->toBeFalse();
})->with([
    'đổi Dạng hàng' => fn () => cardTypeDraft(form: StockForm::Account),
    'đổi tuỳ chọn chuẩn hoá' => fn () => cardTypeDraft(normalization: new Normalization(caseInsensitive: false)),
    'đổi Khoá chống trùng' => fn () => cardTypeDraft([
        new ContentFieldDraft('serial', 'Serial', sensitive: false, dedupeKey: true),
        new ContentFieldDraft('card_code', 'Mã thẻ', ContentFieldType::Number, pattern: '\d{12}'),
    ]),
    'bật cờ nhạy cảm' => fn () => cardTypeDraft([
        new ContentFieldDraft('serial', 'Serial'),
        new ContentFieldDraft('card_code', 'Mã thẻ', ContentFieldType::Number, pattern: '\d{12}', dedupeKey: true),
    ]),
    'xoá trường' => fn () => cardTypeDraft([
        new ContentFieldDraft('card_code', 'Mã thẻ', ContentFieldType::Number, pattern: '\d{12}', dedupeKey: true),
    ]),
    'thêm trường bắt buộc' => fn () => cardTypeDraft([
        new ContentFieldDraft('serial', 'Serial', sensitive: false),
        new ContentFieldDraft('card_code', 'Mã thẻ', ContentFieldType::Number, pattern: '\d{12}', dedupeKey: true),
        new ContentFieldDraft('pin', 'Mã PIN'),
    ]),
    'đổi kiểu trường' => fn () => cardTypeDraft([
        new ContentFieldDraft('serial', 'Serial', sensitive: false),
        new ContentFieldDraft('card_code', 'Mã thẻ', pattern: '\d{12}', dedupeKey: true),
    ]),
    'đổi regex' => fn () => cardTypeDraft([
        new ContentFieldDraft('serial', 'Serial', sensitive: false),
        new ContentFieldDraft('card_code', 'Mã thẻ', ContentFieldType::Number, pattern: '\d{10}', dedupeKey: true),
    ]),
    'đổi trường bắt buộc thành tuỳ chọn' => fn () => cardTypeDraft([
        new ContentFieldDraft('serial', 'Serial', required: false, sensitive: false),
        new ContentFieldDraft('card_code', 'Mã thẻ', ContentFieldType::Number, pattern: '\d{12}', dedupeKey: true),
    ]),
    'đảo thứ tự trường' => fn () => cardTypeDraft([
        new ContentFieldDraft('card_code', 'Mã thẻ', ContentFieldType::Number, pattern: '\d{12}', dedupeKey: true),
        new ContentFieldDraft('serial', 'Serial', sensitive: false),
    ]),
    // Đổi tên Loại và tên hiển thị áp được, bật cờ nhạy cảm thì không: cả gói bị từ chối.
    'kèm cả thay đổi áp được' => fn () => cardTypeDraft([
        new ContentFieldDraft('serial', 'Số seri'),
        new ContentFieldDraft('card_code', 'Mã thẻ', ContentFieldType::Number, pattern: '\d{12}', dedupeKey: true),
    ], name: 'Thẻ nạp game'),
]);

it('từ chối nêu đích danh Sản phẩm đang chặn; Sản phẩm chưa có hàng cùng Loại vẫn bị chặn theo', function () {
    $type = $this->catalog->create($this->admin, cardTypeDraft());
    withStock(productIn($type, 'Thẻ Garena 100k', 'GARENA-100K'));
    withStock(productIn($type, 'Thẻ Zing 20k', 'ZING-20K'));
    $unstocked = productIn($type, 'Thẻ Garena 50k', 'GARENA-50K');

    expect(fn () => $this->catalog->update($this->admin, $type, cardTypeDraft([
        new ContentFieldDraft('card_code', 'Mã thẻ', ContentFieldType::Number, pattern: '\d{12}', dedupeKey: true),
    ])))->toThrow(LockedProductConfiguration::class, 'Sản phẩm đang chặn: GARENA-100K, ZING-20K.');

    // Hệ quả cố ý của ADR 0004: Sản phẩm chưa có hàng cũng không được áp.
    expect($unstocked->fresh()->contentFields->pluck('key')->all())->toBe(['serial', 'card_code']);
});

it('xem trước nói trước thay đổi nào áp xuống, thay đổi nào bị từ chối và Sản phẩm nào chịu ảnh hưởng', function () {
    $type = $this->catalog->create($this->admin, cardTypeDraft());
    productIn($type, 'Thẻ Garena 50k', 'GARENA-50K');

    $draft = cardTypeDraft([
        new ContentFieldDraft('serial', 'Số seri', sensitive: false),
        new ContentFieldDraft('card_code', 'Mã thẻ', ContentFieldType::Number, pattern: '\d{12}', dedupeKey: true),
        new ContentFieldDraft('note', 'Ghi chú', required: false),
    ]);

    // Chưa Sản phẩm nào có hàng: mọi thay đổi đều áp được.
    $change = $this->catalog->preview($this->admin, $type, $draft);

    expect($change->isRejected())->toBeFalse()
        ->and($change->rejected)->toBe([])
        ->and($change->blockingProducts)->toBe([])
        ->and($change->affectedProducts)->toBe(['GARENA-50K'])
        ->and($change->applied)->toContain('Đổi tên hiển thị trường "Serial" thành "Số seri".')
        ->and($change->applied)->toContain('Thêm trường tuỳ chọn "Ghi chú".');

    withStock(productIn($type, 'Thẻ Garena 100k', 'GARENA-100K'));

    $change = $this->catalog->preview($this->admin, $type, cardTypeDraft([
        new ContentFieldDraft('serial', 'Số seri'),
        new ContentFieldDraft('card_code', 'Mã thẻ', ContentFieldType::Number, pattern: '\d{12}', dedupeKey: true),
    ]));

    expect($change->isRejected())->toBeTrue()
        ->and($change->applied)->toBe(['Đổi tên hiển thị trường "Serial" thành "Số seri".'])
        ->and($change->rejected)->toBe(['Đổi cờ nhạy cảm của trường "Serial".'])
        ->and($change->blockingProducts)->toBe(['GARENA-100K'])
        ->and($change->affectedProducts)->toBe(['GARENA-100K', 'GARENA-50K']);
});

it('xem trước không ghi gì', function () {
    $type = $this->catalog->create($this->admin, cardTypeDraft());

    $this->catalog->preview($this->admin, $type, cardTypeDraft(name: 'Tên khác'));

    expect($type->fresh()->name)->toBe('Thẻ nạp');
});

it('Quản trị Ngừng dùng Loại; Sản phẩm mới không chọn được nữa, Sản phẩm cũ không đổi', function () {
    $type = $this->catalog->create($this->admin, cardTypeDraft());
    $product = productIn($type, 'Thẻ Garena 100k', 'GARENA-100K');

    $this->catalog->discontinue($this->admin, $type);

    expect($type->fresh()->isDiscontinued())->toBeTrue()
        ->and(ProductType::query()->notDiscontinued()->count())->toBe(0)
        ->and(fn () => productIn($type->fresh(), 'Thẻ Garena 50k', 'GARENA-50K'))
        ->toThrow(InvalidProductConfiguration::class, 'Loại sản phẩm "Thẻ nạp" đã Ngừng dùng, không chọn cho Sản phẩm được nữa.');

    // Sản phẩm cũ vẫn dùng Loại đó, vẫn sửa được.
    expect($product->fresh()->product_type_id)->toBe($type->id);

    app(ProductCatalog::class)->update($this->admin, $product->fresh(), new ProductDraft(
        productType: $type->fresh(),
        name: 'Thẻ Garena 100k (2026)',
        code: 'GARENA-100K',
    ));

    expect($product->fresh()->name)->toBe('Thẻ Garena 100k (2026)');
});

it('Quản trị xoá được Loại chưa Sản phẩm nào dùng', function () {
    $type = $this->catalog->create($this->admin, cardTypeDraft());

    $this->catalog->delete($this->admin, $type);

    expect(ProductType::find($type->id))->toBeNull()
        ->and($this->catalog->create($this->admin, cardTypeDraft())->name)->toBe('Thẻ nạp');
});

it('Loại đang có Sản phẩm không xoá được, chỉ Ngừng dùng', function () {
    $type = $this->catalog->create($this->admin, cardTypeDraft());
    productIn($type, 'Thẻ Garena 100k', 'GARENA-100K');

    expect(fn () => $this->catalog->delete($this->admin, $type))
        ->toThrow(ProductTypeInUse::class, 'Loại sản phẩm đang có Sản phẩm dùng nên không xoá được, chỉ Ngừng dùng.');

    expect(ProductType::find($type->id))->not->toBeNull();
});

it('chỉ Quản trị sửa, Ngừng dùng và xoá được Loại sản phẩm', function (Role $role) {
    $type = $this->catalog->create($this->admin, cardTypeDraft());
    $staff = staffMember($role);

    expect(fn () => $this->catalog->update($staff, $type, cardTypeDraft(name: 'Đổi tên')))->toThrow(MissingRole::class)
        ->and(fn () => $this->catalog->preview($staff, $type, cardTypeDraft(name: 'Đổi tên')))->toThrow(MissingRole::class)
        ->and(fn () => $this->catalog->discontinue($staff, $type))->toThrow(MissingRole::class)
        ->and(fn () => $this->catalog->delete($staff, $type))->toThrow(MissingRole::class);

    $type = $type->fresh();

    expect($type->name)->toBe('Thẻ nạp')
        ->and($type->isDiscontinued())->toBeFalse();
})->with([
    'Nhập kho' => Role::NhapKho,
    'Bán hàng' => Role::BanHang,
]);
