<?php

use App\Filament\Resources\Dispatches\DispatchResource;
use App\Filament\Resources\Dispatches\Pages\CreateDispatch;
use App\Filament\Resources\Dispatches\Pages\DispatchResult;
use App\Filament\Resources\Dispatches\Pages\ListDispatches;
use App\Filament\Resources\Dispatches\Pages\ViewDispatch;
use App\Filament\Resources\Dispatches\Widgets\DispatchDeliveries;
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
use App\Inventory\Dispatch\DispatchLineKind;
use App\Inventory\Dispatch\DispatchStatus;
use App\Inventory\Dispatch\ManualDispatch;
use App\Inventory\Dispatch\SalesChannelDirectory;
use App\Inventory\Dispatch\SalesChannelDraft;
use App\Inventory\Encryption\KeyFingerprints;
use App\Inventory\Intake\BatchDraft;
use App\Inventory\Intake\BatchIntake;
use App\Inventory\Intake\BatchLineDraft;
use App\Inventory\Reveal\ContentReveal;
use App\Inventory\Reveal\RevealContextType;
use App\Inventory\Stock\StockDefect;
use App\Models\Dispatch;
use App\Models\DispatchLine;
use App\Models\DispatchRevision;
use App\Models\RevealLogEntry;
use App\Models\SalesChannel;
use App\Models\StockUnit;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Filament\Actions\CreateAction;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Repeater;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('intake');
    Repeater::fake();
    $this->seed(RoleSeeder::class);
    app(KeyFingerprints::class)->register();
    $this->travelTo(CarbonImmutable::parse('2026-09-15 10:00'));

    $this->admin = staffMember(Role::Owner);
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
    'Quản trị' => [Role::Owner, 200, 200],
    'Bán hàng' => [Role::BanHang, 200, 403],
    'Nhập kho' => [Role::NhapKho, 403, 403],
]);

it('form xuất kho hiện Tồn lỗi riêng cạnh Tồn bán được', function () {
    $this->actingAs($this->seller);
    $form = ['sales_channel_id' => $this->zalo->id, 'lines' => [['product_id' => $this->steam->id, 'quantity' => 1]]];

    Livewire::test(CreateDispatch::class)->fillForm($form)->assertSee('Tồn bán được: 3')->assertDontSee('Tồn lỗi');

    app(StockDefect::class)->markDefective($this->admin, StockUnit::where('content->serial', 'SR1')->sole(), 'Nhà cung cấp thu hồi');

    Livewire::test(CreateDispatch::class)->fillForm($form)->assertSee('Tồn bán được: 2')->assertSee('Tồn lỗi: 1');
});

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
        ->assertDontSee('AAAA-0001');

    Livewire::test(DispatchDeliveries::class, ['record' => $dispatch])
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

it('màn kết quả từ 50 Slot chỉ hiện bảng dạng che, không Copy từng Slot; Copy tất cả và Tải file ghi Nhật ký xem mã', function () {
    $intake = app(BatchIntake::class);
    $intake->confirm($this->admin, $intake->submit($this->admin, new BatchDraft(
        supplier: app(SupplierDirectory::class)->create($this->admin, 'G2A'),
        receivedOn: CarbonImmutable::today(),
        lines: [new BatchLineDraft($this->steam, 95_000, implode("\n", array_map(fn (int $i) => sprintf("BULK%d\tBULK-%04d", $i, $i), range(1, 50))))],
    )));
    $dispatch = app(ManualDispatch::class)->create($this->seller, new DispatchDraft($this->zalo, null, [new DispatchLineDraft($this->steam, 50)]));
    $this->actingAs($this->seller);

    $page = Livewire::test(DispatchResult::class, ['record' => $dispatch->getRouteKey()])
        ->assertSee('Xuất kho thành công · 50 Slot')
        ->assertSee('Mã thẻ: ••••••')
        ->assertDontSee('AAAA-0001')
        ->assertDontSee('BULK-0001')
        ->assertDontSeeHtml('clipboard.writeText');

    expect(RevealLogEntry::count())->toBe(0);

    $page->callAction(TestAction::make('copyAll')->schemaComponent('resultActions'))->assertHasNoActionErrors();

    expect(RevealLogEntry::count())->toBe(50);

    $page->callAction(TestAction::make('downloadCSV')->schemaComponent('resultActions'))->assertFileDownloaded("phieu-xuat-{$dispatch->id}.csv");

    expect(RevealLogEntry::count())->toBe(100);
});

it('trang xem phiếu: Dòng xuất có Loại, Lần giao dạng che; Bán hàng Xem mã có xác nhận trong Hạn bảo hành, ghi Nhật ký xem mã; không thấy Giá vốn và Nhà cung cấp', function () {
    $dispatch = app(ManualDispatch::class)->create($this->admin, new DispatchDraft($this->shopee, 'SP-001', [new DispatchLineDraft($this->steam, 2, 190_000)], 'Anh Minh'));
    $delivery = $dispatch->deliveries()->orderBy('deliveries.id')->firstOrFail();
    $this->actingAs($this->seller);

    $this->get(DispatchResource::getUrl('view', ['record' => $dispatch]))
        ->assertOk()
        ->assertSee(['Giao bán', '190.000 ₫', 'Lần giao', 'Mã thẻ: ••••••', 'Xem mã', 'Lịch sử sửa phiếu', 'Chưa sửa lần nào.'])
        ->assertDontSee(['AAAA-0001', '95.000', 'Kinguin']);

    Livewire::test(DispatchDeliveries::class, ['record' => $dispatch])
        ->assertCanSeeTableRecords($dispatch->deliveries)
        ->mountAction(TestAction::make('reveal')->table($delivery))
        ->assertMountedActionModalSee('Lần xem được ghi vào Nhật ký xem mã.')
        ->assertMountedActionModalDontSee('AAAA-0001')
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertActionMounted('revealedDelivery')
        ->assertMountedActionModalSee(['Serial: SR1', 'Mã thẻ: AAAA-0001']);

    expect(RevealLogEntry::sole())
        ->user_id->toBe($this->seller->id)
        ->context->toBe(RevealContextType::Delivery)
        ->context_id->toBe($delivery->id);

    $this->travelTo(CarbonImmutable::parse('2026-09-16 00:00'));

    Livewire::test(DispatchDeliveries::class, ['record' => $dispatch])
        ->assertActionHidden(TestAction::make('reveal')->table($delivery));

    $this->actingAs($this->admin);

    Livewire::test(DispatchDeliveries::class, ['record' => $dispatch])
        ->assertActionVisible(TestAction::make('reveal')->table($delivery));

    $this->actingAs(staffMember(Role::NhapKho));

    $this->get(DispatchResource::getUrl('view', ['record' => $dispatch]))->assertForbidden();
});

it('Sửa phiếu Hoàn tất bằng modal ghi Lịch sử sửa phiếu; mã đơn trùng báo lỗi và không lưu; phiếu không Hoàn tất thì không sửa được', function () {
    $manual = app(ManualDispatch::class);
    $manual->create($this->seller, new DispatchDraft($this->shopee, 'SP-000', [new DispatchLineDraft($this->steam, 1)]));
    $dispatch = $manual->create($this->seller, new DispatchDraft($this->shopee, 'SP-001', [new DispatchLineDraft($this->steam, 1, 100_000)], 'Anh Minh'));
    $line = $dispatch->lines->sole();
    $this->actingAs($this->seller);

    Livewire::test(ViewDispatch::class, ['record' => $dispatch->getRouteKey()])
        ->callAction('edit', data: ['external_ref' => 'SP-000'])
        ->assertNotified('Mã đơn ngoài "SP-000" đã có trong Kênh bán "Shopee".');

    expect(DispatchRevision::count())->toBe(0);

    Livewire::test(ViewDispatch::class, ['record' => $dispatch->getRouteKey()])
        ->callAction('edit', data: ['external_ref' => 'SP-002', 'customer' => 'Anh Minh 0901', 'sale_prices' => ["line_{$line->id}" => 120000]])
        ->assertHasNoActionErrors()
        ->assertNotified('Đã sửa Phiếu xuất.')
        ->assertSee(['SP-002', 'Anh Minh 0901', '120.000 ₫', '100.000 ₫']);

    expect($dispatch->fresh())->external_ref->toBe('SP-002')->customer->toBe('Anh Minh 0901')
        ->and($line->fresh()->sale_price)->toBe(120_000)
        ->and(DispatchRevision::count())->toBe(3);

    DB::table('dispatches')->where('id', $dispatch->id)->update(['status' => DispatchStatus::Cancelled->value]);

    Livewire::test(ViewDispatch::class, ['record' => $dispatch->getRouteKey()])
        ->assertActionHidden('edit');
});

it('Sửa phiếu không hiện ô Giá bán cho Dòng xuất loại Giao thay', function () {
    $dispatch = app(ManualDispatch::class)->create($this->seller, new DispatchDraft($this->shopee, 'SP-010', [new DispatchLineDraft($this->steam, 1, 100_000)]));
    $sale = $dispatch->lines->sole();
    // Dựng thẳng dòng Giao thay: ở đây chỉ cần một dòng loại ấy để xem form, không cần cả một lần
    // Giao thay sang Sản phẩm khác. Giá bán để trống vì DB không cho dòng này có Giá bán.
    $corrective = new DispatchLine;
    $corrective->forceFill([
        'dispatch_id' => $dispatch->id,
        'product_id' => $this->steam->id,
        'kind' => DispatchLineKind::Corrective,
        'quantity' => 1,
    ])->save();
    $this->actingAs($this->seller);

    Livewire::test(ViewDispatch::class, ['record' => $dispatch->getRouteKey()])
        ->mountAction('edit')
        ->assertSchemaComponentVisible("sale_prices.line_{$sale->id}")
        ->assertSchemaComponentHidden("sale_prices.line_{$corrective->id}");

    Livewire::test(ViewDispatch::class, ['record' => $dispatch->getRouteKey()])
        ->callAction('edit', data: ['external_ref' => 'SP-010', 'sale_prices' => ["line_{$sale->id}" => 120_000]])
        ->assertHasNoActionErrors()
        ->assertNotified('Đã sửa Phiếu xuất.');

    expect($sale->fresh()->sale_price)->toBe(120_000)
        ->and($corrective->fresh()->sale_price)->toBeNull()
        // Ô ẩn thì Sửa phiếu không đụng tới dòng ấy: không có dòng lịch sử nào cho nó.
        ->and(DispatchRevision::where('dispatch_line_id', $corrective->id)->count())->toBe(0);
});

it('tìm Phiếu xuất theo mã đơn ngoài, khách, Kênh bán, người tạo, khoảng ngày và trường không nhạy cảm', function () {
    $manual = app(ManualDispatch::class);
    $first = $manual->create($this->seller, new DispatchDraft($this->shopee, 'SP-001', [new DispatchLineDraft($this->steam, 1)], 'Anh Minh'));
    $this->travelTo(CarbonImmutable::parse('2026-09-17 09:00'));
    $second = $manual->create($this->admin, new DispatchDraft($this->zalo, null, [new DispatchLineDraft($this->steam, 1)], 'Chị Lan'));
    $this->actingAs($this->seller);

    Livewire::test(ListDispatches::class)
        ->searchTable('SP-001')
        ->assertCanSeeTableRecords([$first])
        ->assertCanNotSeeTableRecords([$second])
        ->searchTable('chị lan')
        ->assertCanSeeTableRecords([$second])
        ->assertCanNotSeeTableRecords([$first])
        ->searchTable(null)
        ->filterTable('sales_channel_id', $this->zalo->id)
        ->assertCanSeeTableRecords([$second])
        ->assertCanNotSeeTableRecords([$first])
        ->resetTableFilters()
        ->filterTable('created_by', $this->seller->id)
        ->assertCanSeeTableRecords([$first])
        ->assertCanNotSeeTableRecords([$second])
        ->resetTableFilters()
        ->filterTable('created_at', ['from' => '2026-09-16', 'until' => '2026-09-17'])
        ->assertCanSeeTableRecords([$second])
        ->assertCanNotSeeTableRecords([$first])
        ->filterTable('created_at', ['from' => null, 'until' => '2026-09-15'])
        ->assertCanSeeTableRecords([$first])
        ->assertCanNotSeeTableRecords([$second])
        ->resetTableFilters()
        ->filterTable('delivered_content', ['term' => 'sr2'])
        ->assertCanSeeTableRecords([$second])
        ->assertCanNotSeeTableRecords([$first])
        ->filterTable('delivered_content', ['term' => 'AAAA-0002'])
        ->assertCanNotSeeTableRecords([$first, $second]);
});

it('tìm theo Khoá chống trùng từ danh sách Phiếu xuất: trả lần giao và phiếu, không hiện nội dung, không ghi Nhật ký xem mã', function () {
    app(ManualDispatch::class)->create($this->admin, new DispatchDraft($this->shopee, 'SP-001', [new DispatchLineDraft($this->steam, 1)]));
    $this->actingAs($this->seller);

    Livewire::test(ListDispatches::class)
        ->callAction('lookupDedupeKey', data: ['value' => ' AAAA-0001 '])
        ->assertHasNoActionErrors()
        ->assertActionMounted('dedupeKeyResults')
        ->assertMountedActionModalSee(['Tìm thấy 1 lần giao', 'SP-001', 'Shopee', 'Steam Wallet 100k', '15/09/2026'])
        ->assertMountedActionModalDontSee(['AAAA-0001', 'SR1', '95.000', 'Kinguin']);

    Livewire::test(ListDispatches::class)
        ->callAction('lookupDedupeKey', data: ['value' => 'AAAA-9999'])
        ->assertActionMounted('dedupeKeyResults')
        ->assertMountedActionModalSee(['Tìm thấy 0 lần giao', 'Không có lần giao nào khớp.']);

    expect(RevealLogEntry::count())->toBe(0);
});

it('Giao thêm từ trang xem phiếu mở lại trang tạo với Thông tin đơn chỉ đọc; màn kết quả chỉ hiện Slot vừa giao', function () {
    $dispatch = app(ManualDispatch::class)->create($this->seller, new DispatchDraft($this->shopee, 'SP-001', [new DispatchLineDraft($this->steam, 1, 95_000)], 'Anh Minh'));
    app(ContentReveal::class)->revealDispatchResult($this->seller, $dispatch);
    $url = DispatchResource::getUrl('create', [CreateDispatch::ADDITIONAL_QUERY => $dispatch->id]);
    $this->actingAs($this->seller);

    Livewire::test(ViewDispatch::class, ['record' => $dispatch->getRouteKey()])
        ->assertActionVisible('additional')
        ->assertActionHasUrl('additional', $url);

    $this->get($url)->assertOk()->assertSee("Giao thêm · Phiếu xuất #{$dispatch->id}");

    Livewire::withQueryParams([CreateDispatch::ADDITIONAL_QUERY => $dispatch->id])
        ->test(CreateDispatch::class)
        ->assertSchemaStateSet(['sales_channel_id' => $this->shopee->id, 'external_ref' => 'SP-001', 'customer' => 'Anh Minh'])
        ->assertFormFieldDisabled('sales_channel_id')
        ->assertFormFieldDisabled('external_ref')
        ->assertFormFieldDisabled('customer')
        ->fillForm(['lines' => [['product_id' => $this->steam->id, 'quantity' => 1, 'sale_price' => 90000]]])
        ->call('create')
        ->assertActionMounted('confirmDispatch')
        ->assertMountedActionModalSee(['Xác nhận Giao thêm', "SP-001 (Phiếu xuất #{$dispatch->id})", 'Anh Minh', 'Steam Wallet 100k', '90.000 ₫'])
        ->assertMountedActionModalDontSee('AAAA-0002')
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertRedirect(DispatchResource::getUrl('result', ['record' => $dispatch]));

    expect(Dispatch::count())->toBe(1)
        ->and($dispatch->lines()->get()->map(fn (DispatchLine $line) => [$line->kind, $line->sale_price])->all())
        ->toBe([[DispatchLineKind::Sale, 95_000], [DispatchLineKind::Additional, 90_000]]);

    Livewire::test(DispatchResult::class, ['record' => $dispatch->getRouteKey()])
        ->assertSee('Giao thêm thành công · 1 Slot')
        ->assertSee('Mã thẻ: AAAA-0002')
        ->assertDontSee('AAAA-0001');

    $this->get(DispatchResource::getUrl('view', ['record' => $dispatch]))
        ->assertOk()
        ->assertSee(['Giao bán', 'Giao thêm', '185.000 ₫']);
});

it('Giao thêm thiếu hàng thì modal báo thiếu và không thêm gì; phiếu không Hoàn tất hoặc Nhập kho thì không mở được', function () {
    $dispatch = app(ManualDispatch::class)->create($this->seller, new DispatchDraft($this->shopee, 'SP-001', [new DispatchLineDraft($this->steam, 2)]));
    $url = DispatchResource::getUrl('create', [CreateDispatch::ADDITIONAL_QUERY => $dispatch->id]);
    $this->actingAs($this->seller);

    Livewire::withQueryParams([CreateDispatch::ADDITIONAL_QUERY => $dispatch->id])
        ->test(CreateDispatch::class)
        ->fillForm(['lines' => [['product_id' => $this->steam->id, 'quantity' => 5]]])
        ->call('create')
        ->assertActionMounted('confirmDispatch')
        ->assertMountedActionModalSee(['Không đủ hàng', 'cần 5, còn 1.', 'Quay lại sửa']);

    expect(DispatchLine::count())->toBe(1);

    $this->actingAs(staffMember(Role::NhapKho));
    $this->get($url)->assertForbidden();

    DB::table('dispatches')->where('id', $dispatch->id)->update(['status' => DispatchStatus::Cancelled->value]);
    $this->actingAs($this->seller);

    Livewire::test(ViewDispatch::class, ['record' => $dispatch->getRouteKey()])
        ->assertActionHidden('additional');

    $this->get($url)->assertForbidden();
});

it('Giao thay từ bảng Lần giao: modal tóm tắt lần giao sẽ bị huỷ và thời gian còn lại, xong thì hiện mã lần giao mới; quá 24 giờ chỉ Quản trị kèm lý do', function () {
    $dispatch = app(ManualDispatch::class)->create($this->seller, new DispatchDraft($this->shopee, 'SP-001', [new DispatchLineDraft($this->steam, 1)], 'Anh Minh'));
    $wrong = $dispatch->deliveries()->firstOrFail();
    $this->actingAs($this->seller);
    $this->travel(90)->minutes();

    Livewire::test(DispatchDeliveries::class, ['record' => $dispatch])
        ->mountAction(TestAction::make('correct')->table($wrong))
        ->assertMountedActionModalSee(['Steam Wallet 100k', $wrong->unitLabel(), 'giao lúc 15/09/2026 10:00', 'Còn 22 giờ 30 phút', 'Nội dung đã gửi cho khách chưa?'])
        ->assertMountedActionModalDontSee('AAAA-0001')
        ->setActionData(['content_sent' => 1, 'void_unit' => 1])
        ->assertMountedActionModalSee('Đơn vị hàng không còn lần giao nào khác.')
        ->setActionData(['content_sent' => 0, 'void_unit' => 0])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertActionMounted('revealedDelivery')
        ->assertMountedActionModalSee('Mã thẻ: AAAA-0002');

    $new = $dispatch->deliveries()->orderByDesc('deliveries.id')->firstOrFail();

    expect(DB::table('slots')->where('id', $wrong->slot_id)->value('status'))->toBe('voided')
        ->and($new->corrects_delivery_id)->toBe($wrong->id)
        ->and(RevealLogEntry::sole()->context_id)->toBe($new->id);

    Livewire::test(DispatchDeliveries::class, ['record' => $dispatch])
        ->assertSee(['Đã huỷ', 'Giao thay cho'])
        ->assertActionHidden(TestAction::make('correct')->table($wrong))
        ->assertActionVisible(TestAction::make('correct')->table($new));

    $this->travel(25)->hours();

    Livewire::test(DispatchDeliveries::class, ['record' => $dispatch])
        ->assertActionHidden(TestAction::make('correct')->table($new));

    $this->actingAs($this->admin);

    Livewire::test(DispatchDeliveries::class, ['record' => $dispatch])
        ->callAction(TestAction::make('correct')->table($new), data: ['content_sent' => 0])
        ->assertHasActionErrors(['reason' => 'required'])
        ->setActionData(['content_sent' => 0, 'reason' => 'Khách báo nhầm mã'])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertMountedActionModalSee('Mã thẻ: AAAA-0003');
});
