<?php

use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Products\ProductResource;
use App\Inventory\Access\Role;
use App\Inventory\Catalog\ContentFieldDraft;
use App\Inventory\Catalog\ProductCatalog;
use App\Inventory\Catalog\ProductTypeCatalog;
use App\Inventory\Catalog\StockForm;
use App\Inventory\Catalog\SupplierDirectory;
use App\Inventory\Dispatch\DispatchDraft;
use App\Inventory\Dispatch\DispatchLineDraft;
use App\Inventory\Dispatch\ManualDispatch;
use App\Inventory\Dispatch\SalesChannelDirectory;
use App\Inventory\Dispatch\SalesChannelDraft;
use App\Inventory\Encryption\KeyFingerprints;
use App\Inventory\Intake\BatchDraft;
use App\Inventory\Intake\BatchIntake;
use App\Inventory\Intake\BatchLineDraft;
use App\Models\Product;
use App\Models\ProductType;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Filament\Actions\CreateAction;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Section;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

/**
 * Loại Thẻ nạp, Dạng hàng Mã dùng một lần: một trường duy nhất là Khoá chống trùng.
 */
function cardType(): ProductType
{
    return productTypeOf(
        StockForm::OneTimeCode,
        [new ContentFieldDraft('code', 'Mã thẻ', dedupeKey: true)],
        name: 'Thẻ nạp',
    );
}

function steamWalletProduct(bool $stocked = false): Product
{
    $product = productIn(cardType(), 'Steam Wallet 100k', 'STEAM-100K');

    return $stocked ? withStock($product) : $product;
}

it('mọi Vai trò xem được danh sách Sản phẩm, chỉ Quản trị vào được trang Tạo', function (Role $role, bool $canCreate) {
    $this->actingAs(staffMember($role))
        ->get(ProductResource::getUrl('index'))
        ->assertOk();

    $this->get(ProductResource::getUrl('create'))->assertStatus($canCreate ? 200 : 403);

    Livewire::test(ListProducts::class)
        ->{$canCreate ? 'assertActionVisible' : 'assertActionHidden'}(CreateAction::class);
})->with([
    'Quản trị' => [Role::Owner, true],
    'Nhập kho' => [Role::NhapKho, false],
    'Bán hàng' => [Role::BanHang, false],
]);

it('Quản trị tạo Sản phẩm bằng cách chọn Loại sản phẩm, xong về danh sách', function () {
    $type = productTypeOf(StockForm::Account, [
        new ContentFieldDraft('username', 'Tên đăng nhập', dedupeKey: true),
        new ContentFieldDraft('password', 'Mật khẩu'),
    ], name: 'Tài khoản streaming');

    $this->actingAs(staffMember(Role::Owner));

    Livewire::test(CreateProduct::class)
        ->fillForm([
            'product_type_id' => $type->id,
            'name' => 'Netflix Premium 1 tháng',
            'code' => 'NETFLIX-1M',
            'default_slots' => 4,
            'warranty_days' => 30,
            'min_remaining_days' => 0,
            'low_stock_threshold' => 5,
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect(ProductResource::getUrl('index'));

    $product = Product::sole();

    expect($product)
        ->code->toBe('NETFLIX-1M')
        ->product_type_id->toBe($type->id)
        ->default_slots->toBe(4)
        ->low_stock_threshold->toBe(5)
        ->and($product->form())->toBe(StockForm::Account)
        ->and($product->contentFields->pluck('key')->all())->toBe(['username', 'password']);
});

it('trang Tạo bỏ hẳn Trường nội dung, Chuẩn hoá Khoá chống trùng và ô Dạng hàng', function () {
    $this->actingAs(staffMember(Role::Owner));

    // Soi cấu trúc form chứ không dò chữ trên trang: mô tả khối Mẫu giao hàng có nhắc tới
    // "Trường nội dung" một cách hợp lệ, nên assertDontSee sẽ bắt oan.
    $page = Livewire::test(CreateProduct::class)
        ->assertSchemaComponentExists('template')
        ->assertFormFieldExists('product_type_id')
        ->assertSchemaComponentDoesNotExist('content-fields')
        ->assertSchemaComponentDoesNotExist('normalization')
        ->assertFormFieldDoesNotExist('fields')
        ->assertFormFieldDoesNotExist('case_insensitive')
        ->assertFormFieldDoesNotExist('strip_separators')
        ->assertFormFieldDoesNotExist('type');

    // Một mạch cuộn: trang resource ép lưới 2 cột khi form không tự khai cột.
    expect($page->instance()->form->getColumns('lg'))->toBe(1);
});

it('ô Số slot mặc định chỉ hiện khi Loại có Dạng hàng Tài khoản', function () {
    $account = productTypeOf(StockForm::Account, [
        new ContentFieldDraft('username', 'Tên đăng nhập', dedupeKey: true),
    ], name: 'Tài khoản streaming');

    $this->actingAs(staffMember(Role::Owner));

    Livewire::test(CreateProduct::class)
        ->fillForm(['product_type_id' => cardType()->id])
        ->assertFormFieldHidden('default_slots')
        ->fillForm(['product_type_id' => $account->id])
        ->assertFormFieldVisible('default_slots');
});

it('Loại đã Ngừng dùng biến khỏi ô chọn khi tạo Sản phẩm mới, nhưng Sản phẩm cũ vẫn thấy Loại của mình', function () {
    $product = steamWalletProduct();
    $type = $product->productType;
    app(ProductTypeCatalog::class)->discontinue(staffMember(Role::Owner), $type);

    $this->actingAs(staffMember(Role::Owner));

    $options = fn (bool $expected): Closure => fn (Select $field): bool => array_key_exists($type->id, $field->getOptions()) === $expected;

    Livewire::test(CreateProduct::class)
        ->assertSchemaComponentExists('product_type_id', checkComponentUsing: $options(false));

    Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->assertSchemaComponentExists('product_type_id', checkComponentUsing: $options(true));
});

it('lỗi nghiệp vụ ở trang Tạo thành thông báo, không ghi gì và ở lại form', function () {
    $this->actingAs(staffMember(Role::Owner));
    $existing = steamWalletProduct();

    Livewire::test(CreateProduct::class)
        ->fillForm([
            'product_type_id' => $existing->product_type_id,
            'name' => 'Steam Wallet 200k',
            'code' => 'STEAM-100K',
            'warranty_days' => 0,
            'min_remaining_days' => 0,
        ])
        ->call('create')
        ->assertNotified('Mã sản phẩm STEAM-100K đã được dùng.')
        ->assertNoRedirect();

    expect(Product::count())->toBe(1);
});

it('Quản trị sửa Sản phẩm ở trang riêng, lưu xong ở lại trang', function () {
    $this->actingAs(staffMember(Role::Owner));
    $product = steamWalletProduct();

    Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->assertSchemaStateSet([
            'product_type_id' => $product->product_type_id,
            'name' => 'Steam Wallet 100k',
            'code' => 'STEAM-100K',
        ])
        ->fillForm(['name' => 'Steam Wallet 100k (2026)', 'low_stock_threshold' => 3])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified('Đã lưu Sản phẩm.')
        ->assertNoRedirect();

    expect($product->fresh())
        ->name->toBe('Steam Wallet 100k (2026)')
        ->low_stock_threshold->toBe(3);
});

it('Sản phẩm đã có hàng bị khoá ô Loại sản phẩm và được giải thích ngay đầu trang', function () {
    $this->actingAs(staffMember(Role::Owner));
    $product = steamWalletProduct();

    Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->assertDontSee('Cấu hình bị khoá')
        ->assertFormFieldEnabled('product_type_id');

    withStock($product);

    Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->assertSee('Cấu hình bị khoá')
        ->assertSee(ProductCatalog::STOCK_LOCKS_PRODUCT_TYPE)
        ->assertFormFieldDisabled('product_type_id')
        // Đã có hàng mà chưa từng xuất: Mã sản phẩm vẫn đổi được nên không nhắc tới.
        ->assertDontSee(ProductCatalog::DISPATCH_LOCKS_CODE)
        ->assertFormFieldEnabled('code');
});

it('cùng một hộp giải thích thêm dòng Mã sản phẩm khi Sản phẩm đã có Phiếu xuất', function () {
    app(KeyFingerprints::class)->register();
    $admin = staffMember(Role::Owner);
    $seller = staffMember(Role::BanHang);
    $product = steamWalletProduct();

    $intake = app(BatchIntake::class);
    $intake->confirm($admin, $intake->submit($admin, new BatchDraft(
        supplier: app(SupplierDirectory::class)->create($admin, 'Kinguin'),
        receivedOn: CarbonImmutable::today(),
        lines: [new BatchLineDraft($product, 90_000, "AAAA-0001\nAAAA-0002")],
    )));

    app(ManualDispatch::class)->create($seller, new DispatchDraft(
        app(SalesChannelDirectory::class)->create($admin, new SalesChannelDraft('Zalo')),
        null,
        [new DispatchLineDraft($product, 1)],
    ));

    $this->actingAs($admin);

    Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->assertSee('Cấu hình bị khoá')
        ->assertSee(ProductCatalog::STOCK_LOCKS_PRODUCT_TYPE)
        ->assertSee(ProductCatalog::DISPATCH_LOCKS_CODE)
        ->assertFormFieldDisabled('code');
});

it('khối Mẫu giao hàng thu gọn sẵn khi Sản phẩm chưa ghi đè mẫu riêng', function () {
    $collapsed = fn (bool $expected): Closure => fn (Section $section): bool => $section->isCollapsed() === $expected;

    $this->actingAs($admin = staffMember(Role::Owner));
    $product = steamWalletProduct();

    Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->assertSchemaComponentExists('template', checkComponentUsing: $collapsed(true));

    app(ProductCatalog::class)->update($admin, $product, ProductResource::draftFromForm([
        ...ProductResource::formData($product->fresh()),
        'delivery_template' => 'Mã thẻ của bạn: {{code}}',
    ]));

    // Sản phẩm đang ghi đè mẫu riêng thì mở sẵn, không ai phải bấm mở mới thấy mình đã đổi gì.
    Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->assertSchemaComponentExists('template', checkComponentUsing: $collapsed(false));
});

it('Quản trị Ngừng bán Sản phẩm; Sản phẩm đã có hàng không có nút xoá', function () {
    $this->actingAs(staffMember(Role::Owner));
    $stocked = steamWalletProduct(stocked: true);

    Livewire::test(ListProducts::class)
        ->assertActionHidden(TestAction::make('delete')->table($stocked))
        ->callAction(TestAction::make('discontinue')->table($stocked))
        ->assertActionHidden(TestAction::make('discontinue')->table($stocked));

    expect($stocked->fresh()->isDiscontinued())->toBeTrue();
});

it('header trang Sửa có Xoá, xoá xong về danh sách', function () {
    $this->actingAs(staffMember(Role::Owner));
    $product = steamWalletProduct();

    Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->callAction('delete')
        ->assertRedirect(ProductResource::getUrl('index'));

    expect(Product::count())->toBe(0);
});

it('header trang Sửa có Ngừng bán; Sản phẩm đã có hàng không có nút xoá', function () {
    $this->actingAs(staffMember(Role::Owner));
    $stocked = steamWalletProduct(stocked: true);

    Livewire::test(EditProduct::class, ['record' => $stocked->getRouteKey()])
        ->assertActionHidden('delete')
        ->callAction('discontinue')
        ->assertActionHidden('discontinue');

    expect($stocked->fresh()->isDiscontinued())->toBeTrue();
});

it('Nhập kho và Bán hàng không sửa, không Ngừng bán, không xoá được Sản phẩm', function (Role $role) {
    $product = steamWalletProduct();
    $this->actingAs(staffMember($role));

    $this->get(ProductResource::getUrl('edit', ['record' => $product]))->assertForbidden();

    Livewire::test(ListProducts::class)
        ->assertActionHidden(TestAction::make('edit')->table($product))
        ->assertActionHidden(TestAction::make('discontinue')->table($product))
        ->assertActionHidden(TestAction::make('delete')->table($product));
})->with([
    'Nhập kho' => Role::NhapKho,
    'Bán hàng' => Role::BanHang,
]);
