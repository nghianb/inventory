<?php

use App\Filament\Resources\Batches\Pages\CreateBatch;
use App\Filament\Resources\SupplierClaims\Pages\CreateSupplierClaim;
use App\Filament\Resources\SupplierClaims\Pages\ViewSupplierClaim;
use App\Filament\Resources\SupplierClaims\RelationManagers\ClaimUnitsRelationManager;
use App\Filament\Resources\SupplierClaims\SupplierClaimResource;
use App\Filament\Resources\SupplierClaims\Widgets\UnclaimedDefectiveUnits;
use App\Inventory\Access\Role;
use App\Inventory\Catalog\ContentFieldDraft;
use App\Inventory\Catalog\ContentFieldType;
use App\Inventory\Catalog\ProductCatalog;
use App\Inventory\Catalog\ProductDraft;
use App\Inventory\Catalog\ProductType;
use App\Inventory\Catalog\SupplierDirectory;
use App\Inventory\Claims\ClaimOutcome;
use App\Inventory\Claims\SupplierClaims;
use App\Inventory\Claims\SupplierClaimStatus;
use App\Inventory\Encryption\KeyFingerprints;
use App\Inventory\Intake\BatchDraft;
use App\Inventory\Intake\BatchIntake;
use App\Inventory\Intake\BatchLineDraft;
use App\Inventory\Reveal\RevealContextType;
use App\Inventory\Stock\StockDefect;
use App\Models\Batch;
use App\Models\RevealLogEntry;
use App\Models\StockUnit;
use App\Models\SupplierClaim;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
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
    $this->stocker = staffMember(Role::NhapKho);
    $this->netflix = app(ProductCatalog::class)->create($this->admin, new ProductDraft(
        type: ProductType::Account,
        name: 'Netflix 1 tháng',
        code: 'NETFLIX-1M',
        fields: [
            new ContentFieldDraft('username', 'Tên đăng nhập', ContentFieldType::Email, sensitive: false, dedupeKey: true),
            new ContentFieldDraft('password', 'Mật khẩu'),
        ],
        defaultSlots: 3,
    ));

    $intake = app(BatchIntake::class);
    $this->kinguin = app(SupplierDirectory::class)->create($this->admin, 'Kinguin');
    $this->g2a = app(SupplierDirectory::class)->create($this->admin, 'G2A');
    $import = fn ($supplier, string $content) => $intake->confirm($this->admin, $intake->submit($this->admin, new BatchDraft(
        supplier: $supplier,
        receivedOn: CarbonImmutable::today(),
        lines: [new BatchLineDraft($this->netflix, 90_000, $content)],
    )));
    $import($this->kinguin, "a@shop.test\tpw-a\nb@shop.test\tpw-b");
    $import($this->g2a, "g@shop.test\tpw-g");

    $unit = fn (string $username): StockUnit => StockUnit::where('content->username', $username)->sole();
    [$this->a, $this->b, $this->g] = [$unit('a@shop.test'), $unit('b@shop.test'), $unit('g@shop.test')];

    foreach ([$this->a, $this->b, $this->g] as $defective) {
        app(StockDefect::class)->markDefective($this->admin, $defective, 'Nhà cung cấp thu hồi');
    }
});

it('Quản trị và Nhập kho vào được trang Khiếu nại nhà cung cấp, Bán hàng thì không', function (Role $role, bool $sees) {
    $this->actingAs(staffMember($role));

    $this->get(SupplierClaimResource::getUrl('index'))->assertStatus($sees ? 200 : 403);
    $this->get(SupplierClaimResource::getUrl('create'))->assertStatus($sees ? 200 : 403);
})->with([
    'Quản trị' => [Role::QuanTri, true],
    'Nhập kho' => [Role::NhapKho, true],
    'Bán hàng' => [Role::BanHang, false],
]);

it('Nhập kho tạo Khiếu nại từ bảng Đơn vị hàng Lỗi chưa khiếu nại; khác Nhà cung cấp thì báo lỗi', function () {
    $this->actingAs($this->stocker);

    Livewire::test(UnclaimedDefectiveUnits::class)
        ->assertCanSeeTableRecords([$this->a, $this->b, $this->g])
        ->selectTableRecords([$this->a->id, $this->g->id])
        ->callAction(TestAction::make('createClaim')->table()->bulk())
        ->assertNotified("Đơn vị hàng #{$this->g->id} không thuộc Nhà cung cấp Kinguin.");

    expect(SupplierClaim::count())->toBe(0);

    Livewire::test(UnclaimedDefectiveUnits::class)
        ->selectTableRecords([$this->b->id, $this->a->id])
        ->callAction(TestAction::make('createClaim')->table()->bulk())
        ->assertRedirect(SupplierClaimResource::getUrl('view', ['record' => SupplierClaim::query()->sole()]));

    expect(SupplierClaim::query()->sole())->supplier_id->toBe($this->kinguin->id)->status->toBe(SupplierClaimStatus::Draft);

    Livewire::test(UnclaimedDefectiveUnits::class)
        ->assertCanNotSeeTableRecords([$this->a, $this->b])
        ->assertCanSeeTableRecords([$this->g]);
});

it('Nhập kho tạo Khiếu nại Nháp ở trang tạo', function () {
    $this->actingAs($this->stocker);

    Livewire::test(CreateSupplierClaim::class)
        ->fillForm(['supplier_id' => $this->kinguin->id, 'stock_unit_ids' => [$this->a->id, $this->b->id], 'note' => 'Đợt 9'])
        ->call('create')
        ->assertHasNoFormErrors();

    $claim = SupplierClaim::query()->sole();

    expect($claim)->note->toBe('Đợt 9')
        ->and($claim->claimUnits()->pluck('stock_unit_id')->sort()->values()->all())->toBe([$this->a->id, $this->b->id]);
});

it('trang chi tiết: gỡ, thêm, gửi, Xem mã, giải quyết rồi nhập hàng thay thế Giá vốn 0', function () {
    $this->actingAs($this->stocker);
    $claim = app(SupplierClaims::class)->create($this->stocker, $this->kinguin, [$this->a, $this->b]);
    [$rowA, $rowB] = $claim->claimUnits;
    $units = fn () => Livewire::test(ClaimUnitsRelationManager::class, ['ownerRecord' => $claim->fresh(), 'pageClass' => ViewSupplierClaim::class]);

    Livewire::test(ViewSupplierClaim::class, ['record' => $claim->getRouteKey()])
        ->assertActionHidden('resolve')
        ->assertActionHidden('importReplacementGoods');

    $units()->callAction(TestAction::make('remove')->table($rowB));

    expect($rowB->fresh()->active)->toBeFalse();

    $page = Livewire::test(ViewSupplierClaim::class, ['record' => $claim->getRouteKey()])
        ->callAction('addUnits', data: ['stock_unit_ids' => [$this->b->id]])
        ->assertHasNoActionErrors()
        ->callAction('send')
        ->assertActionHidden('addUnits')
        ->assertActionVisible('resolve');

    $rowB2 = $claim->claimUnits()->where('active', true)->where('stock_unit_id', $this->b->id)->sole();

    $units()
        ->assertActionHidden(TestAction::make('remove')->table($rowA))
        ->callAction(TestAction::make('reveal')->table($rowA))
        ->assertActionMounted('revealedContent')
        ->assertMountedActionModalSee(['Mật khẩu', 'pw-a']);

    expect(RevealLogEntry::count())->toBe($this->a->slots()->count())
        ->and(RevealLogEntry::pluck('context')->unique()->all())->toBe([RevealContextType::SupplierClaim])
        ->and(RevealLogEntry::pluck('context_id')->unique()->all())->toBe([$claim->id]);

    $page
        ->callAction('resolve', data: ['outcomes' => [
            ['claim_unit_id' => $rowA->id, 'unit' => 'A', 'outcome' => ClaimOutcome::Refund->value, 'refund_amount' => 60000, 'refunded_on' => '2026-09-14', 'note' => null],
            ['claim_unit_id' => $rowB2->id, 'unit' => 'B', 'outcome' => ClaimOutcome::ReplacementGoods->value, 'note' => 'Gửi bù'],
        ]])
        ->assertHasNoActionErrors()
        ->assertActionHidden('cancel')
        ->assertActionVisible('importReplacementGoods');

    expect($claim->fresh()->status)->toBe(SupplierClaimStatus::Resolved)
        ->and($rowA->fresh())->outcome->toBe(ClaimOutcome::Refund)->refund_amount->toBe(60_000)
        ->and($rowB2->fresh())->outcome->toBe(ClaimOutcome::ReplacementGoods)->outcome_note->toBe('Gửi bù');

    Livewire::withQueryParams(['khieu-nai' => $claim->id])
        ->test(CreateBatch::class)
        ->assertFormSet(['supplier_claim_id' => $claim->id, 'supplier_id' => $this->kinguin->id])
        ->fillForm([
            'received_on' => '2026-09-15',
            'lines' => [[
                'product_id' => $this->netflix->id,
                'source' => 'paste',
                'separator' => 'tab',
                'content' => "r@shop.test\tpw-r",
            ]],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $batch = Batch::query()->where('supplier_claim_id', $claim->id)->sole();
    app(BatchIntake::class)->confirm($this->stocker, $batch);

    expect(StockUnit::where('content->username', 'r@shop.test')->sole()->unit_cost)->toBe(0);
});

it('Huỷ Khiếu nại ở trang chi tiết phải nhập lý do', function () {
    $this->actingAs($this->stocker);
    $claim = app(SupplierClaims::class)->create($this->stocker, $this->kinguin, [$this->a]);

    Livewire::test(ViewSupplierClaim::class, ['record' => $claim->getRouteKey()])
        ->callAction('cancel', data: ['reason' => null])
        ->assertHasActionErrors(['reason' => 'required'])
        ->setActionData(['reason' => 'Nhầm Nhà cung cấp'])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertActionHidden('cancel');

    expect($claim->fresh())->status->toBe(SupplierClaimStatus::Cancelled)->cancel_reason->toBe('Nhầm Nhà cung cấp');
});
