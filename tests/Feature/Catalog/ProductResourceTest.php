<?php

use App\Filament\Resources\Products\Pages\ManageProducts;
use App\Filament\Resources\Products\ProductResource;
use App\Inventory\Access\Role;
use App\Inventory\Catalog\ContentFieldDraft;
use App\Inventory\Catalog\ProductCatalog;
use App\Inventory\Catalog\ProductDraft;
use App\Inventory\Catalog\ProductType;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Filament\Actions\CreateAction;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Repeater;
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

it('mọi Vai trò xem được danh sách Sản phẩm, chỉ Quản trị thấy nút tạo', function (Role $role, bool $canCreate) {
    $this->actingAs(staffMember($role))
        ->get(ProductResource::getUrl('index'))
        ->assertOk();

    Livewire::test(ManageProducts::class)
        ->{$canCreate ? 'assertActionVisible' : 'assertActionHidden'}(CreateAction::class);
})->with([
    'Quản trị' => [Role::QuanTri, true],
    'Nhập kho' => [Role::NhapKho, false],
    'Bán hàng' => [Role::BanHang, false],
]);

it('Quản trị tạo Sản phẩm kèm Trường nội dung từ panel', function () {
    $this->actingAs(staffMember(Role::QuanTri));

    Livewire::test(ManageProducts::class)
        ->callAction(CreateAction::class, data: [
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
        ->assertHasNoFormErrors();

    $product = Product::sole();

    expect($product)
        ->code->toBe('NETFLIX-1M')
        ->default_slots->toBe(4)
        ->low_stock_threshold->toBe(5)
        ->and($product->dedupeKeyField()->key)->toBe('username')
        ->and($product->contentFields->pluck('key')->all())->toBe(['username', 'password']);
});

it('Quản trị Ngừng bán Sản phẩm; Sản phẩm đã có hàng không có nút xoá', function () {
    $this->actingAs($admin = staffMember(Role::QuanTri));
    $stocked = steamWalletProduct($admin, stocked: true);

    Livewire::test(ManageProducts::class)
        ->assertActionHidden(TestAction::make('delete')->table($stocked))
        ->callAction(TestAction::make('discontinue')->table($stocked))
        ->assertActionHidden(TestAction::make('discontinue')->table($stocked));

    expect($stocked->fresh()->isDiscontinued())->toBeTrue();
});

it('panel báo lỗi khi sửa cấu hình bị khoá của Sản phẩm đã có hàng', function () {
    $this->actingAs($admin = staffMember(Role::QuanTri));
    $stocked = steamWalletProduct($admin, stocked: true);

    Livewire::test(ManageProducts::class)
        ->callAction(TestAction::make('edit')->table($stocked), data: ['case_insensitive' => false])
        ->assertNotified('Sản phẩm đã có hàng: không đổi được tuỳ chọn chuẩn hoá.');

    expect($stocked->fresh()->case_insensitive)->toBeTrue();
});

it('Nhập kho và Bán hàng không sửa, không Ngừng bán, không xoá được Sản phẩm', function (Role $role) {
    $product = steamWalletProduct(staffMember(Role::QuanTri));
    $this->actingAs(staffMember($role));

    Livewire::test(ManageProducts::class)
        ->assertActionHidden(TestAction::make('edit')->table($product))
        ->assertActionHidden(TestAction::make('discontinue')->table($product))
        ->assertActionHidden(TestAction::make('delete')->table($product));
})->with([
    'Nhập kho' => Role::NhapKho,
    'Bán hàng' => Role::BanHang,
]);
