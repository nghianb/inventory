<?php

use App\Filament\Resources\Batches\Pages\ViewBatch;
use App\Filament\Resources\RevealLogEntries\RevealLogEntryResource;
use App\Filament\Resources\StockUnits\Pages\ViewStockUnit;
use App\Filament\Resources\StockUnits\RelationManagers\RevealLogEntriesRelationManager;
use App\Filament\Resources\StockUnits\RelationManagers\SlotsRelationManager;
use App\Filament\Resources\StockUnits\StockUnitResource;
use App\Inventory\Access\Role;
use App\Inventory\Catalog\ContentFieldDraft;
use App\Inventory\Catalog\ProductCatalog;
use App\Inventory\Catalog\ProductDraft;
use App\Inventory\Catalog\ProductType;
use App\Inventory\Catalog\SupplierDirectory;
use App\Inventory\Encryption\KeyFingerprints;
use App\Inventory\Intake\BatchDraft;
use App\Inventory\Intake\BatchIntake;
use App\Inventory\Intake\BatchLineDraft;
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

    $this->admin = staffMember(Role::QuanTri);
    $this->clerk = staffMember(Role::NhapKho);
    $product = app(ProductCatalog::class)->create($this->admin, new ProductDraft(
        type: ProductType::OneTimeCode,
        name: 'Steam Wallet 100k',
        code: 'STEAM-100K',
        fields: [new ContentFieldDraft('code', 'Mã thẻ', dedupeKey: true)],
    ));

    // Dòng 2 trùng dòng 1: một Đơn vị hàng, một dòng bị bỏ.
    $this->batch = app(BatchIntake::class)->submit($this->clerk, new BatchDraft(
        supplier: app(SupplierDirectory::class)->create($this->admin, 'Kinguin'),
        receivedOn: CarbonImmutable::parse('2026-09-15'),
        lines: [new BatchLineDraft($product, 95_000, "AAAA-BBBB\naaaa-bbbb")],
    ));
});

it('chỉ Quản trị vào được Nhật ký xem mã', function (Role $role, int $status) {
    $this->actingAs(staffMember($role))
        ->get(RevealLogEntryResource::getUrl('index'))
        ->assertStatus($status);
})->with([
    'Quản trị' => [Role::QuanTri, 200],
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
