<?php

use App\Filament\Resources\Dispatches\DispatchResource;
use App\Filament\Resources\Dispatches\Pages\CreateDispatch;
use App\Filament\Resources\Dispatches\Pages\DispatchResult;
use App\Filament\Resources\Dispatches\Pages\ViewDispatch;
use App\Filament\Resources\SalesChannels\Pages\ManageSalesChannels;
use App\Filament\Resources\SalesChannels\SalesChannelResource;
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
use App\Inventory\Intake\BatchDraft;
use App\Inventory\Intake\BatchIntake;
use App\Inventory\Intake\BatchLineDraft;
use App\Inventory\Reveal\RevealContextType;
use App\Models\Dispatch;
use App\Models\RevealLogEntry;
use App\Models\SalesChannel;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Filament\Actions\CreateAction;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Repeater;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('intake');
    Repeater::fake();
    $this->seed(RoleSeeder::class);
    app(KeyFingerprints::class)->register();
    $this->travelTo(CarbonImmutable::parse('2026-09-15 10:00'));

    $this->admin = staffMember(Role::QuanTri);
    $this->seller = staffMember(Role::BanHang);
    $this->shopee = app(SalesChannelDirectory::class)->create($this->admin, new SalesChannelDraft('Shopee', requiresExternalRef: true));
    $this->zalo = app(SalesChannelDirectory::class)->create($this->admin, new SalesChannelDraft('Zalo'));
    $this->steam = app(ProductCatalog::class)->create($this->admin, new ProductDraft(
        type: ProductType::OneTimeCode,
        name: 'Steam Wallet 100k',
        code: 'STEAM-100K',
        fields: [new ContentFieldDraft('serial', 'Serial', sensitive: false), new ContentFieldDraft('code', 'Mã thẻ', dedupeKey: true)],
    ));

    $intake = app(BatchIntake::class);
    $intake->confirm($this->admin, $intake->submit($this->admin, new BatchDraft(
        supplier: app(SupplierDirectory::class)->create($this->admin, 'Kinguin'),
        receivedOn: CarbonImmutable::today(),
        lines: [new BatchLineDraft($this->steam, 95_000, "SR1\tAAAA-0001\nSR2\tAAAA-0002\nSR3\tAAAA-0003")],
    )));
});

it('Quản trị và Bán hàng vào được Phiếu xuất, Nhập kho thì không; chỉ Quản trị vào Kênh bán', function (Role $role, int $dispatches, int $channels) {
    $this->actingAs(staffMember($role));

    $this->get(DispatchResource::getUrl('index'))->assertStatus($dispatches);
    $this->get(DispatchResource::getUrl('create'))->assertStatus($dispatches);
    $this->get(SalesChannelResource::getUrl('index'))->assertStatus($channels);
})->with([
    'Quản trị' => [Role::QuanTri, 200, 200],
    'Bán hàng' => [Role::BanHang, 200, 403],
    'Nhập kho' => [Role::NhapKho, 403, 403],
]);

it('Bán hàng tạo Phiếu xuất: modal xác nhận không có nội dung mã, màn kết quả hiện nội dung đúng một lần', function () {
    $this->actingAs($this->seller);

    $page = Livewire::test(CreateDispatch::class)
        ->fillForm([
            'sales_channel_id' => $this->shopee->id,
            'external_ref' => 'SP-001',
            'customer' => 'Anh Minh 0901234567',
            'lines' => [['product_id' => $this->steam->id, 'quantity' => 2, 'sale_price' => 190000]],
        ])
        ->assertSee('Tồn bán được: 3')
        ->assertSee('1 dòng · 2 Slot · Tổng Giá bán: 190.000 ₫')
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertActionMounted('confirmDispatch')
        ->assertMountedActionModalSee(['Shopee', 'SP-001', 'Anh Minh 0901234567', 'Steam Wallet 100k', '190.000 ₫'])
        ->assertMountedActionModalDontSee(['AAAA-0001', 'SR1'])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $dispatch = Dispatch::sole();
    $page->assertRedirect(DispatchResource::getUrl('result', ['record' => $dispatch]));

    Livewire::test(DispatchResult::class, ['record' => $dispatch->getRouteKey()])
        ->assertSee('Xuất kho thành công · 2 Slot')
        ->assertSee('Mã thẻ: AAAA-0001')
        ->assertSee('Mã thẻ: AAAA-0002')
        ->assertDontSee('AAAA-0003');

    expect(RevealLogEntry::where('context', RevealContextType::Delivery)->where('user_id', $this->seller->id)->count())->toBe(2);

    Livewire::test(DispatchResult::class, ['record' => $dispatch->getRouteKey()])
        ->assertSee('Màn kết quả chỉ hiện một lần ngay sau khi xuất kho.')
        ->assertDontSee('AAAA-0001');

    expect(RevealLogEntry::count())->toBe(2);

    Livewire::test(ViewDispatch::class, ['record' => $dispatch->getRouteKey()])
        ->assertSee('SP-001')
        ->assertSee('Anh Minh 0901234567')
        ->assertSee('Mã thẻ: ••••••')
        ->assertSee('15/09/2026')
        ->assertDontSee('AAAA-0001');
});

it('lỗi kiểm tra hiện đầu form, mã đơn trùng kèm link phiếu cũ, và không mở modal xác nhận', function () {
    $first = app(ManualDispatch::class)->create($this->seller, new DispatchDraft($this->shopee, 'SP-001', [new DispatchLineDraft($this->steam, 1)]));
    $this->actingAs($this->seller);

    Livewire::test(CreateDispatch::class)
        ->fillForm([
            'sales_channel_id' => $this->shopee->id,
            'external_ref' => 'SP-001',
            'lines' => [
                ['product_id' => $this->steam->id, 'quantity' => 1],
                ['product_id' => $this->steam->id, 'quantity' => 1],
            ],
        ])
        ->call('create')
        ->assertActionNotMounted('confirmDispatch')
        ->assertSee('Phiếu xuất chưa hợp lệ')
        ->assertSee('Mã đơn ngoài "SP-001" đã có trong Kênh bán "Shopee".')
        ->assertSee('Sản phẩm "Steam Wallet 100k" có hai Dòng xuất; mỗi Sản phẩm một Dòng xuất.')
        ->assertSeeHtml(e(DispatchResource::getUrl('view', ['record' => $first])))
        ->fillForm(['sales_channel_id' => null, 'external_ref' => null, 'lines' => [['product_id' => $this->steam->id, 'quantity' => 1]]])
        ->call('create')
        ->assertActionNotMounted('confirmDispatch')
        ->assertSee('Chưa chọn Kênh bán.')
        ->assertDontSee('Mã đơn ngoài "SP-001" đã có');

    expect(Dispatch::count())->toBe(1);
});

it('thiếu hàng thì modal báo từng dòng cần bao nhiêu, còn bao nhiêu và không cho giao', function () {
    $this->actingAs($this->seller);

    Livewire::test(CreateDispatch::class)
        ->fillForm([
            'sales_channel_id' => $this->zalo->id,
            'lines' => [['product_id' => $this->steam->id, 'quantity' => 5]],
        ])
        ->call('create')
        ->assertActionMounted('confirmDispatch')
        ->assertMountedActionModalSee(['Không đủ hàng', 'cần 5, còn 3.', 'Tự sinh khi xác nhận', 'Quay lại sửa']);

    expect(Dispatch::count())->toBe(0);
});

it('Quản trị khai báo, sửa và ngừng dùng Kênh bán từ panel', function () {
    $this->actingAs($this->admin);

    Livewire::test(ManageSalesChannels::class)
        ->callAction(CreateAction::class, data: ['name' => 'Facebook', 'requires_external_ref' => true])
        ->assertHasNoFormErrors();

    $facebook = SalesChannel::where('name', 'Facebook')->sole();

    expect($facebook->requires_external_ref)->toBeTrue();

    Livewire::test(ManageSalesChannels::class)
        ->callAction(TestAction::make('edit')->table($facebook), data: ['name' => 'Facebook Page', 'requires_external_ref' => false])
        ->assertHasNoFormErrors()
        ->callAction(TestAction::make('hide')->table($facebook))
        ->assertActionHidden(TestAction::make('hide')->table($facebook))
        ->callAction(CreateAction::class, data: ['name' => 'shopee'])
        ->assertNotified('Kênh bán "shopee" đã có.');

    expect($facebook->fresh())->name->toBe('Facebook Page')->requires_external_ref->toBeFalse()
        ->and($facebook->fresh()->isHidden())->toBeTrue();
});
