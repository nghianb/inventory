<?php

use App\Filament\Resources\Batches\Pages\ViewBatch;
use App\Filament\Resources\Dispatches\DispatchResource;
use App\Filament\Resources\Dispatches\Widgets\DispatchRevealLogEntries;
use App\Filament\Resources\RevealLogEntries\RevealLogEntryResource;
use App\Filament\Resources\StockUnits\Pages\ViewStockUnit;
use App\Filament\Resources\StockUnits\RelationManagers\RevealLogEntriesRelationManager;
use App\Filament\Resources\StockUnits\RelationManagers\SlotsRelationManager;
use App\Filament\Resources\StockUnits\StockUnitResource;
use App\Inventory\Access\Role;
use App\Inventory\Catalog\ContentFieldDraft;
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
use App\Inventory\Reveal\ContentReveal;
use App\Inventory\Reveal\RevealActor;
use App\Inventory\Reveal\RevealContext;
use App\Inventory\Reveal\RevealContextType;
use App\Models\BatchLine;
use App\Models\RevealLogEntry;
use App\Models\Slot;
use App\Models\StockUnit;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('intake');
    $this->seed(RoleSeeder::class);
    app(KeyFingerprints::class)->register();

    $this->admin = staffMember(Role::Owner);
    $this->clerk = staffMember(Role::NhapKho);
    $this->product = productOf(
        StockForm::OneTimeCode,
        [new ContentFieldDraft('code', 'Mã thẻ', dedupeKey: true)],
        'Steam Wallet 100k',
        'STEAM-100K',
    );

    // Dòng 2 trùng dòng 1: một Đơn vị hàng, một dòng bị bỏ.
    $this->batch = app(BatchIntake::class)->submit($this->clerk, new BatchDraft(
        supplier: app(SupplierDirectory::class)->create($this->admin, 'Kinguin'),
        receivedOn: CarbonImmutable::parse('2026-09-15'),
        lines: [new BatchLineDraft($this->product, 95_000, "AAAA-BBBB\naaaa-bbbb")],
    ));
});

it('chỉ Quản trị vào được Nhật ký xem mã', function (Role $role, int $status) {
    $this->actingAs(staffMember($role))
        ->get(RevealLogEntryResource::getUrl('index'))
        ->assertStatus($status);
})->with([
    'Quản trị' => [Role::Owner, 200],
    'Nhập kho' => [Role::NhapKho, 403],
    'Bán hàng' => [Role::BanHang, 403],
]);

it('Quản trị xem nội dung Slot Còn hàng ở chi tiết Đơn vị hàng kèm lý do; lịch sử xem hiện ai, khi nào', function () {
    app(BatchIntake::class)->confirm($this->clerk, $this->batch);
    $unit = StockUnit::sole();
    $slot = Slot::sole();
    $this->actingAs($this->admin);

    $this->get(StockUnitResource::getUrl('view', ['record' => $unit]))
        ->assertOk()
        ->assertSee('Đã được xem bởi')
        ->assertDontSee('AAAA-BBBB');

    $slots = Livewire::test(SlotsRelationManager::class, ['ownerRecord' => $unit, 'pageClass' => ViewStockUnit::class])
        ->callAction(TestAction::make('reveal')->table($slot), data: ['reason' => ''])
        ->assertHasActionErrors(['reason' => 'required']);

    expect(RevealLogEntry::count())->toBe(0);

    $slots->setActionData(['reason' => 'Khách hỏi lại mã'])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertActionMounted('revealedContent')
        ->assertMountedActionModalSee(['Mã thẻ', 'AAAA-BBBB']);

    $entry = RevealLogEntry::sole();

    expect($entry)
        ->user_id->toBe($this->admin->id)
        ->slot_id->toBe($slot->id)
        ->context->toBe(RevealContextType::InStock)
        ->reason->toBe('Khách hỏi lại mã');

    Livewire::test(RevealLogEntriesRelationManager::class, ['ownerRecord' => $unit, 'pageClass' => ViewStockUnit::class])
        ->assertCanSeeTableRecords([$entry])
        ->assertSee($this->admin->name)
        ->assertSee('Khách hỏi lại mã');

    $this->get(RevealLogEntryResource::getUrl('index'))
        ->assertSee('Quản trị xem hàng Còn hàng')
        ->assertSee('Khách hỏi lại mã')
        ->assertDontSee('AAAA-BBBB');
});

it('Nhập kho và Bán hàng mở chi tiết Đơn vị hàng nhưng không có nút xem nội dung, không thấy lịch sử xem', function (Role $role) {
    app(BatchIntake::class)->confirm($this->clerk, $this->batch);
    $unit = StockUnit::sole();
    $this->actingAs(staffMember($role));

    $this->get(StockUnitResource::getUrl('view', ['record' => $unit]))
        ->assertOk()
        ->assertDontSee('Đã được xem bởi');

    Livewire::test(SlotsRelationManager::class, ['ownerRecord' => $unit, 'pageClass' => ViewStockUnit::class])
        ->assertActionHidden(TestAction::make('reveal')->table(Slot::sole()));

    expect(RevealLogEntriesRelationManager::canViewForRecord($unit, ViewStockUnit::class))->toBeFalse();
})->with([
    'Nhập kho' => [Role::NhapKho],
    'Bán hàng' => [Role::BanHang],
]);

it('Quản trị không thấy nút xem nội dung trên Slot đã rời Còn hàng', function () {
    app(BatchIntake::class)->confirm($this->clerk, $this->batch);
    Slot::query()->update(['status' => 'delivered']);
    $this->actingAs($this->admin);

    Livewire::test(SlotsRelationManager::class, ['ownerRecord' => StockUnit::sole(), 'pageClass' => ViewStockUnit::class])
        ->assertActionHidden(TestAction::make('reveal')->table(Slot::sole()));
});

it('Quản trị thấy Đã được xem bởi trên trang Phiếu xuất: mọi lượt xem của Slot đã giao, bất kể Ngữ cảnh xem mã', function () {
    app(BatchIntake::class)->confirm($this->clerk, $this->batch);
    $reveal = app(ContentReveal::class);

    // Lượt xem lúc hàng còn trong kho: Ngữ cảnh không phải Giao hàng nhưng vẫn là đã thấy mã của Slot.
    $reveal->reveal(RevealActor::staff($this->admin), Slot::sole(), RevealContext::inStock(), 'Khách hỏi lại mã');

    $seller = staffMember(Role::BanHang);
    $channel = app(SalesChannelDirectory::class)->create($this->admin, new SalesChannelDraft('Zalo'));
    $dispatch = app(ManualDispatch::class)->create($seller, new DispatchDraft($channel, 'SP-001', [new DispatchLineDraft($this->product, 1)], 'Anh Minh'));
    $delivery = $dispatch->deliveries()->firstOrFail();
    $reveal->revealDelivery($seller, $delivery);

    // Slot của Đơn vị hàng khác, không nằm trong phiếu: lượt xem của nó không được lẫn vào.
    $intake = app(BatchIntake::class);
    $intake->confirm($this->clerk, $intake->submit($this->clerk, new BatchDraft(
        supplier: app(SupplierDirectory::class)->create($this->admin, 'G2A'),
        receivedOn: CarbonImmutable::parse('2026-09-15'),
        lines: [new BatchLineDraft($this->product, 95_000, 'CCCC-DDDD')],
    )));
    $reveal->reveal(RevealActor::staff($this->admin), Slot::query()->whereKeyNot($delivery->slot_id)->sole(), RevealContext::inStock(), 'Kiểm tra hàng khác');

    $this->actingAs($this->admin);

    $this->get(DispatchResource::getUrl('view', ['record' => $dispatch]))
        ->assertOk()
        ->assertSee('Đã được xem bởi')
        ->assertDontSee('AAAA-BBBB');

    Livewire::test(DispatchRevealLogEntries::class, ['record' => $dispatch])
        ->assertCanSeeTableRecords(RevealLogEntry::where('slot_id', $delivery->slot_id)->get())
        ->assertSee(['Quản trị xem hàng Còn hàng', 'Giao hàng', 'Khách hỏi lại mã', $this->admin->name, $seller->name])
        ->assertDontSee('Kiểm tra hàng khác')
        ->assertDontSee('AAAA-BBBB');
});

it('Bán hàng và Nhập kho không thấy Đã được xem bởi trên trang Phiếu xuất', function () {
    app(BatchIntake::class)->confirm($this->clerk, $this->batch);
    $seller = staffMember(Role::BanHang);
    $channel = app(SalesChannelDirectory::class)->create($this->admin, new SalesChannelDraft('Zalo'));
    $dispatch = app(ManualDispatch::class)->create($seller, new DispatchDraft($channel, 'SP-001', [new DispatchLineDraft($this->product, 1)], 'Anh Minh'));
    app(ContentReveal::class)->revealDelivery($seller, $dispatch->deliveries()->firstOrFail());

    $this->actingAs($seller);

    $this->get(DispatchResource::getUrl('view', ['record' => $dispatch]))
        ->assertOk()
        ->assertDontSee('Đã được xem bởi');

    expect(DispatchRevealLogEntries::canView())->toBeFalse();

    $this->actingAs($this->clerk);

    $this->get(DispatchResource::getUrl('view', ['record' => $dispatch]))->assertForbidden();

    expect(DispatchRevealLogEntries::canView())->toBeFalse();
});

it('người tạo Lô nhập tải CSV dòng bị bỏ ở màn xem trước và ngay sau xác nhận; người khác và lúc sau không thấy nút', function () {
    $fileName = "lo-nhap-{$this->batch->id}-STEAM-100K-dong-bi-bo.csv";
    $line = BatchLine::sole();
    $this->actingAs($this->clerk);

    Livewire::test(ViewBatch::class, ['record' => $this->batch->getRouteKey()])
        ->callAction('downloadRejected', data: ['line' => $line->id])
        ->assertHasNoActionErrors()
        ->assertFileDownloaded($fileName)
        ->callAction('confirm')
        ->assertHasNoActionErrors()
        ->callAction('downloadRejected', data: ['line' => $line->id])
        ->assertFileDownloaded($fileName);

    expect(RevealLogEntry::where('context', RevealContextType::Batch)->where('context_id', $this->batch->id)->pluck('user_id')->all())
        ->toBe([$this->clerk->id, $this->clerk->id]);

    $this->actingAs($this->admin);

    Livewire::test(ViewBatch::class, ['record' => $this->batch->getRouteKey()])
        ->assertActionHidden('downloadRejected');

    $this->actingAs($this->clerk);
    $this->travel(31)->minutes();

    Livewire::test(ViewBatch::class, ['record' => $this->batch->getRouteKey()])
        ->assertActionHidden('downloadRejected');
});
