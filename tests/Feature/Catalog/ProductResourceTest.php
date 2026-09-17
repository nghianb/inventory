<?php

use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Products\ProductResource;
use App\Inventory\Access\Role;
use App\Inventory\Catalog\ContentFieldDraft;
use App\Inventory\Catalog\ProductCatalog;
use App\Inventory\Catalog\ProductDraft;
use App\Inventory\Catalog\ProductType;
use App\Inventory\Catalog\SupplierDirectory;
use App\Inventory\Dispatch\DispatchDraft;
use App\Inventory\Dispatch\DispatchLineDraft;
use App\Inventory\Dispatch\ManualDispatch;
use App\Inventory\Dispatch\SalesChannelDirectory;
use App\Inventory\Dispatch\SalesChannelDraft;
use App\Inventory\Encryption\KeyFingerprints;
use App\Inventory\Encryption\Normalization;
use App\Inventory\Intake\BatchDraft;
use App\Inventory\Intake\BatchIntake;
use App\Inventory\Intake\BatchLineDraft;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Filament\Actions\CreateAction;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Repeater;
use Filament\Schemas\Components\Section;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->undoRepeaterFake = Repeater::fake();
});

afterEach(function () {
    ($this->undoRepeaterFake)();
});

function steamWalletProduct(User $admin, bool $stocked = false): Product
{
    $product = app(ProductCatalog::class)->create($admin, new ProductDraft(
        type: ProductType::OneTimeCode,
        name: 'Steam Wallet 100k',
        code: 'STEAM-100K',
        fields: [new ContentFieldDraft('code', 'Mã thẻ', dedupeKey: true)],
    ));

    if ($stocked) {
        // Tạm đánh dấu đã có hàng cho tới khi có thao tác Nhập hàng.
        $product->forceFill(['stocked_at' => now()])->save();
    }

    return $product;
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

it('Quản trị tạo Sản phẩm kèm Trường nội dung ở trang riêng, xong về danh sách', function () {
    $this->actingAs(staffMember(Role::Owner));

    Livewire::test(CreateProduct::class)
        ->fillForm([
            'type' => ProductType::Account->value,
            'name' => 'Netflix Premium 1 tháng',
            'code' => 'NETFLIX-1M',
            'default_slots' => 4,
            'warranty_days' => 30,
            'min_remaining_days' => 0,
            'low_stock_threshold' => 5,
            'case_insensitive' => true,
            'strip_separators' => false,
            'fields' => [
                ['key' => 'username', 'label' => 'Tên đăng nhập', 'type' => 'email', 'pattern' => null, 'required' => true, 'sensitive' => true, 'dedupe_key' => true],
                ['key' => 'password', 'label' => 'Mật khẩu', 'type' => 'text', 'pattern' => null, 'required' => true, 'sensitive' => true, 'dedupe_key' => false],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect(ProductResource::getUrl('index'));

    $product = Product::sole();

    expect($product)
        ->code->toBe('NETFLIX-1M')
        ->default_slots->toBe(4)
        ->low_stock_threshold->toBe(5)
        ->and($product->dedupeKeyField()->key)->toBe('username')
        ->and($product->contentFields->pluck('key')->all())->toBe(['username', 'password']);
});

it('lỗi nghiệp vụ ở trang Tạo thành thông báo, không ghi gì và ở lại form', function () {
    $this->actingAs($admin = staffMember(Role::Owner));
    steamWalletProduct($admin);

    Livewire::test(CreateProduct::class)
        ->fillForm([
            'type' => ProductType::OneTimeCode->value,
            'name' => 'Steam Wallet 200k',
            'code' => 'STEAM-100K',
            'warranty_days' => 0,
            'min_remaining_days' => 0,
            'case_insensitive' => true,
            'strip_separators' => true,
            'fields' => [
                ['key' => 'code', 'label' => 'Mã thẻ', 'type' => 'text', 'pattern' => null, 'required' => true, 'sensitive' => true, 'dedupe_key' => true],
            ],
        ])
        ->call('create')
        ->assertNotified('Mã sản phẩm STEAM-100K đã được dùng.')
        ->assertNoRedirect();

    expect(Product::count())->toBe(1);
});

it('Quản trị sửa Sản phẩm ở trang riêng, lưu xong ở lại trang', function () {
    $this->actingAs($admin = staffMember(Role::Owner));
    $product = steamWalletProduct($admin);

    Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->assertSchemaStateSet([
            'type' => ProductType::OneTimeCode->value,
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

it('Quản trị Ngừng bán Sản phẩm; Sản phẩm đã có hàng không có nút xoá', function () {
    $this->actingAs($admin = staffMember(Role::Owner));
    $stocked = steamWalletProduct($admin, stocked: true);

    Livewire::test(ListProducts::class)
        ->assertActionHidden(TestAction::make('delete')->table($stocked))
        ->callAction(TestAction::make('discontinue')->table($stocked))
        ->assertActionHidden(TestAction::make('discontinue')->table($stocked));

    expect($stocked->fresh()->isDiscontinued())->toBeTrue();
});

it('trang Sửa báo lỗi khi đổi cấu hình bị khoá của Sản phẩm đã có hàng', function () {
    $this->actingAs($admin = staffMember(Role::Owner));
    $stocked = steamWalletProduct($admin, stocked: true);

    Livewire::test(EditProduct::class, ['record' => $stocked->getRouteKey()])
        // Khối Chuẩn hoá có key riêng nên khoá phẳng của ô mang tiền tố khối.
        ->assertFormFieldDisabled('normalization.case_insensitive')
        ->fillForm(['case_insensitive' => false])
        ->call('save')
        ->assertNotified('Sản phẩm đã có hàng: không đổi được tuỳ chọn chuẩn hoá.')
        ->assertNoRedirect();

    expect($stocked->fresh()->case_insensitive)->toBeTrue();
});

it('bày một mạch cuộn như variant A: mỗi khối một card chiếm trọn bề ngang, Trường nội dung cũng có card', function () {
    $this->actingAs(staffMember(Role::Owner));

    $page = Livewire::test(CreateProduct::class)->assertSchemaComponentExists('content-fields');

    // Trang resource ép lưới 2 cột khi form không tự khai cột (CreateRecord::defaultForm), làm
    // card Thông tin chỉ ăn nửa bề ngang thay vì một mạch cuộn. `columns(1)` ghi vào breakpoint
    // `lg` — đúng chỗ lưới 2 cột bật lên.
    expect($page->instance()->form->getColumns('lg'))->toBe(1);
});

it('form xếp Trường nội dung lên ngay dưới Thông tin và thu gọn sẵn hai khối có mặc định dùng được', function () {
    $collapsed = fn (bool $expected): Closure => fn (Section $section): bool => $section->isCollapsed() === $expected;

    $this->actingAs($admin = staffMember(Role::Owner));

    Livewire::test(CreateProduct::class)
        ->assertSeeHtmlInOrder(['Thông tin', 'Trường nội dung', 'Chuẩn hoá Khoá chống trùng', 'Mẫu giao hàng'])
        ->assertSchemaComponentExists('normalization', checkComponentUsing: $collapsed(true))
        ->assertSchemaComponentExists('template', checkComponentUsing: $collapsed(true));

    // Mã dùng một lần dựng sẵn: chuẩn hoá đúng mặc định của loại, chưa có Mẫu giao hàng.
    $product = steamWalletProduct($admin);

    Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->assertSchemaComponentExists('normalization', checkComponentUsing: $collapsed(true))
        ->assertSchemaComponentExists('template', checkComponentUsing: $collapsed(true));

    app(ProductCatalog::class)->update($admin, $product, new ProductDraft(
        type: ProductType::OneTimeCode,
        name: 'Steam Wallet 100k',
        code: 'STEAM-100K',
        fields: [new ContentFieldDraft('code', 'Mã thẻ', dedupeKey: true)],
        normalization: new Normalization(caseInsensitive: true, stripSeparators: false),
        deliveryTemplate: 'Mã thẻ của bạn: {{code}}',
    ));

    // Khối đang mang giá trị khác mặc định thì mở sẵn, không ai phải bấm mở mới thấy mình đã đổi gì.
    Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->assertSchemaComponentExists('normalization', checkComponentUsing: $collapsed(false))
        ->assertSchemaComponentExists('template', checkComponentUsing: $collapsed(false))
        // Ô Loại là live(): đổi nó không được đóng sập khối đang mở dở.
        ->fillForm(['type' => ProductType::Account->value])
        ->assertSchemaComponentExists('normalization', checkComponentUsing: $collapsed(false))
        ->assertSchemaComponentExists('template', checkComponentUsing: $collapsed(false));
});

it('trang Sửa giải thích cấu hình bị khoá của Sản phẩm đã có hàng, không phải để Quản trị đâm vào mới biết', function () {
    $this->actingAs($admin = staffMember(Role::Owner));
    $product = steamWalletProduct($admin);

    Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->assertDontSee('Cấu hình bị khoá');

    $product->forceFill(['stocked_at' => now()])->save();

    Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->assertSee('Cấu hình bị khoá')
        ->assertSee('Sản phẩm đã có hàng: không đổi được Loại')
        ->assertSee('Vẫn thêm được trường tuỳ chọn và đổi tên hiển thị.')
        // Đã có hàng mà chưa từng xuất: Mã sản phẩm vẫn đổi được nên không nhắc tới.
        ->assertDontSee('Sản phẩm đã có Phiếu xuất')
        ->assertFormFieldEnabled('code');
});

it('cùng một hộp giải thích thêm dòng Mã sản phẩm khi Sản phẩm đã có Phiếu xuất', function () {
    app(KeyFingerprints::class)->register();
    $admin = staffMember(Role::Owner);
    $seller = staffMember(Role::BanHang);
    $product = steamWalletProduct($admin);

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
        ->assertSee('Sản phẩm đã có hàng: không đổi được Loại')
        ->assertSee('Sản phẩm đã có Phiếu xuất: không đổi được Mã sản phẩm.')
        ->assertFormFieldDisabled('code');
});

it('header trang Sửa có Xoá, xoá xong về danh sách', function () {
    $this->actingAs($admin = staffMember(Role::Owner));
    $product = steamWalletProduct($admin);

    Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->callAction('delete')
        ->assertRedirect(ProductResource::getUrl('index'));

    expect(Product::count())->toBe(0);
});

it('header trang Sửa có Ngừng bán; Sản phẩm đã có hàng không có nút xoá', function () {
    $this->actingAs($admin = staffMember(Role::Owner));
    $stocked = steamWalletProduct($admin, stocked: true);

    Livewire::test(EditProduct::class, ['record' => $stocked->getRouteKey()])
        ->assertActionHidden('delete')
        ->callAction('discontinue')
        ->assertActionHidden('discontinue');

    expect($stocked->fresh()->isDiscontinued())->toBeTrue();
});

it('Nhập kho và Bán hàng không sửa, không Ngừng bán, không xoá được Sản phẩm', function (Role $role) {
    $product = steamWalletProduct(staffMember(Role::Owner));
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
