<?php

use App\Filament\Resources\ProductTypes\Pages\CreateProductType;
use App\Filament\Resources\ProductTypes\Pages\EditProductType;
use App\Filament\Resources\ProductTypes\Pages\ListProductTypes;
use App\Filament\Resources\ProductTypes\ProductTypeResource;
use App\Inventory\Access\Role;
use App\Inventory\Catalog\ContentFieldDraft;
use App\Inventory\Catalog\StockForm;
use App\Models\ProductType;
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

/**
 * Loại Thẻ nạp dựng sẵn: Serial không nhạy cảm, Mã thẻ là Khoá chống trùng.
 */
function cardProductType(): ProductType
{
    return productTypeOf(StockForm::OneTimeCode, [
        new ContentFieldDraft('serial', 'Serial', sensitive: false),
        new ContentFieldDraft('card_code', 'Mã thẻ', dedupeKey: true),
    ], name: 'Thẻ nạp');
}

/**
 * State form khớp Loại Thẻ nạp, để test chỉ phải nêu phần mình đổi.
 *
 * @param  list<array<string, mixed>>|null  $fields
 * @return array<string, mixed>
 */
function cardTypeForm(?array $fields = null, mixed ...$overrides): array
{
    return [
        'name' => 'Thẻ nạp',
        'form' => StockForm::OneTimeCode->value,
        'case_insensitive' => true,
        'strip_separators' => true,
        'delivery_template' => null,
        'fields' => $fields ?? [
            ['key' => 'serial', 'label' => 'Serial', 'type' => 'text', 'pattern' => null, 'required' => true, 'sensitive' => false, 'dedupe_key' => false],
            ['key' => 'card_code', 'label' => 'Mã thẻ', 'type' => 'text', 'pattern' => null, 'required' => true, 'sensitive' => true, 'dedupe_key' => true],
        ],
        ...$overrides,
    ];
}

it('mọi Vai trò xem được danh sách Loại sản phẩm, chỉ Quản trị vào được trang Tạo', function (Role $role, bool $canCreate) {
    $this->actingAs(staffMember($role))
        ->get(ProductTypeResource::getUrl('index'))
        ->assertOk();

    $this->get(ProductTypeResource::getUrl('create'))->assertStatus($canCreate ? 200 : 403);

    Livewire::test(ListProductTypes::class)
        ->{$canCreate ? 'assertActionVisible' : 'assertActionHidden'}(CreateAction::class);
})->with([
    'Quản trị' => [Role::Owner, true],
    'Nhập kho' => [Role::NhapKho, false],
    'Bán hàng' => [Role::BanHang, false],
]);

it('Quản trị khai một Loại sản phẩm kèm Trường nội dung, xong về danh sách', function () {
    $this->actingAs(staffMember(Role::Owner));

    Livewire::test(CreateProductType::class)
        ->fillForm(cardTypeForm())
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect(ProductTypeResource::getUrl('index'));

    $type = ProductType::sole();

    expect($type)
        ->name->toBe('Thẻ nạp')
        ->form->toBe(StockForm::OneTimeCode)
        ->and($type->contentFields->pluck('key')->all())->toBe(['serial', 'card_code'])
        ->and($type->dedupeKeyField()->key)->toBe('card_code');
});

it('lỗi khai báo ở trang Tạo thành thông báo, không ghi gì và ở lại form', function () {
    $this->actingAs(staffMember(Role::Owner));
    cardProductType();

    Livewire::test(CreateProductType::class)
        ->fillForm(cardTypeForm())
        ->call('create')
        ->assertNotified('Loại sản phẩm "Thẻ nạp" đã có.')
        ->assertNoRedirect();

    expect(ProductType::count())->toBe(1);
});

it('trang Sửa xem trước thay đổi và nêu Sản phẩm chịu ảnh hưởng trước khi ghi', function () {
    $this->actingAs(staffMember(Role::Owner));
    $type = cardProductType();
    productIn($type, 'Thẻ Garena 100k', 'GARENA-100K');

    $page = Livewire::test(EditProductType::class, ['record' => $type->getRouteKey()])
        ->fillForm(cardTypeForm([
            ['key' => 'serial', 'label' => 'Số seri', 'type' => 'text', 'pattern' => null, 'required' => true, 'sensitive' => false, 'dedupe_key' => false],
            ['key' => 'card_code', 'label' => 'Mã thẻ', 'type' => 'text', 'pattern' => null, 'required' => true, 'sensitive' => true, 'dedupe_key' => true],
            ['key' => 'note', 'label' => 'Ghi chú', 'type' => 'text', 'pattern' => null, 'required' => false, 'sensitive' => true, 'dedupe_key' => false],
        ]))
        ->mountAction('saveWithPreview');

    // Thân modal dựng phía client nên không nằm trong HTML của response: soi thẳng mô tả của
    // action đang mở.
    expect((string) $page->instance()->getMountedAction()->getModalDescription())
        ->toContain('Áp cho mọi Sản phẩm của Loại:')
        ->toContain('Đổi tên hiển thị trường "Serial" thành "Số seri".')
        ->toContain('Thêm trường tuỳ chọn "Ghi chú".')
        ->toContain('Sản phẩm thuộc Loại: GARENA-100K.');

    // Chưa xác nhận thì chưa ghi gì.
    expect($type->fresh()->contentFields->pluck('label')->all())->toBe(['Serial', 'Mã thẻ']);
});

it('xác nhận rồi thì thay đổi áp cho mọi Sản phẩm của Loại', function () {
    $this->actingAs(staffMember(Role::Owner));
    $type = cardProductType();
    $garena = productIn($type, 'Thẻ Garena 100k', 'GARENA-100K');
    $zing = productIn($type, 'Thẻ Zing 20k', 'ZING-20K');

    Livewire::test(EditProductType::class, ['record' => $type->getRouteKey()])
        ->fillForm(cardTypeForm([
            ['key' => 'serial', 'label' => 'Số seri', 'type' => 'text', 'pattern' => null, 'required' => true, 'sensitive' => false, 'dedupe_key' => false],
            ['key' => 'card_code', 'label' => 'Mã thẻ', 'type' => 'text', 'pattern' => null, 'required' => true, 'sensitive' => true, 'dedupe_key' => true],
            ['key' => 'note', 'label' => 'Ghi chú', 'type' => 'text', 'pattern' => null, 'required' => false, 'sensitive' => true, 'dedupe_key' => false],
        ]))
        ->callAction('saveWithPreview')
        ->assertHasNoFormErrors()
        ->assertNotified('Đã lưu Loại sản phẩm.');

    foreach ([$garena, $zing] as $product) {
        expect($product->fresh()->contentFields->pluck('label', 'key')->all())->toBe([
            'serial' => 'Số seri',
            'card_code' => 'Mã thẻ',
            'note' => 'Ghi chú',
        ]);
    }
});

it('Loại đã có Sản phẩm có hàng: hộp cảnh báo nêu đích danh Sản phẩm chặn, và bản sửa bị từ chối trọn gói', function () {
    $this->actingAs(staffMember(Role::Owner));
    $type = cardProductType();
    withStock(productIn($type, 'Thẻ Garena 100k', 'GARENA-100K'));

    $unsafe = cardTypeForm([
        ['key' => 'serial', 'label' => 'Số seri', 'type' => 'text', 'pattern' => null, 'required' => true, 'sensitive' => true, 'dedupe_key' => false],
        ['key' => 'card_code', 'label' => 'Mã thẻ', 'type' => 'text', 'pattern' => null, 'required' => true, 'sensitive' => true, 'dedupe_key' => true],
    ]);

    $page = Livewire::test(EditProductType::class, ['record' => $type->getRouteKey()])
        ->assertSee('Loại đã có Sản phẩm có hàng')
        ->assertSee('Sản phẩm đang chặn: GARENA-100K.')
        ->fillForm($unsafe)
        ->mountAction('saveWithPreview');

    expect((string) $page->instance()->getMountedAction()->getModalDescription())
        ->toContain('Bị từ chối trọn gói, không ghi gì cả:')
        ->toContain('Đổi cờ nhạy cảm của trường "Serial".');

    Livewire::test(EditProductType::class, ['record' => $type->getRouteKey()])
        ->fillForm($unsafe)
        ->callAction('saveWithPreview')
        ->assertNotified()
        ->assertNoRedirect();

    // Từ chối trọn gói: cả phần đổi tên hiển thị nằm chung bản sửa cũng không vào.
    expect($type->fresh()->contentFields->pluck('label', 'key')->all())
        ->toBe(['serial' => 'Serial', 'card_code' => 'Mã thẻ']);
});

it('trang Sửa của Loại chưa có hàng không hiện hộp cảnh báo', function () {
    $this->actingAs(staffMember(Role::Owner));
    $type = cardProductType();
    productIn($type, 'Thẻ Garena 100k', 'GARENA-100K');

    Livewire::test(EditProductType::class, ['record' => $type->getRouteKey()])
        ->assertDontSee('Loại đã có Sản phẩm có hàng');
});

it('Quản trị Ngừng dùng Loại; Loại đang có Sản phẩm không có nút xoá', function () {
    $this->actingAs(staffMember(Role::Owner));
    $type = cardProductType();
    productIn($type, 'Thẻ Garena 100k', 'GARENA-100K');

    Livewire::test(ListProductTypes::class)
        ->assertActionHidden(TestAction::make('delete')->table($type))
        ->callAction(TestAction::make('discontinue')->table($type))
        ->assertActionHidden(TestAction::make('discontinue')->table($type));

    expect($type->fresh()->isDiscontinued())->toBeTrue();
});

it('Quản trị xoá được Loại chưa Sản phẩm nào dùng, xoá xong về danh sách', function () {
    $this->actingAs(staffMember(Role::Owner));
    $type = cardProductType();

    Livewire::test(EditProductType::class, ['record' => $type->getRouteKey()])
        ->callAction('delete')
        ->assertNotified('Đã xoá Loại sản phẩm.')
        ->assertRedirect(ProductTypeResource::getUrl('index'));

    expect(ProductType::count())->toBe(0);
});

it('Nhập kho và Bán hàng không sửa, không Ngừng dùng, không xoá được Loại sản phẩm', function (Role $role) {
    $type = cardProductType();
    $this->actingAs(staffMember($role));

    $this->get(ProductTypeResource::getUrl('edit', ['record' => $type]))->assertForbidden();

    Livewire::test(ListProductTypes::class)
        ->assertActionHidden(TestAction::make('edit')->table($type))
        ->assertActionHidden(TestAction::make('discontinue')->table($type))
        ->assertActionHidden(TestAction::make('delete')->table($type));
})->with([
    'Nhập kho' => Role::NhapKho,
    'Bán hàng' => Role::BanHang,
]);
