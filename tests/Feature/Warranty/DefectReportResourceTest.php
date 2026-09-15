<?php

use App\Filament\Resources\DefectReports\DefectReportResource;
use App\Filament\Resources\DefectReports\Pages\ListDefectReports;
use App\Filament\Resources\DefectReports\Pages\ViewDefectReport;
use App\Filament\Resources\Dispatches\Widgets\DispatchDeliveries;
use App\Filament\Resources\StockUnits\Pages\ViewStockUnit;
use App\Filament\Resources\StockUnits\StockUnitResource;
use App\Inventory\Access\Role;
use App\Inventory\Catalog\ContentFieldDraft;
use App\Inventory\Catalog\ContentFieldType;
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
use App\Inventory\Stock\StockUnitStatus;
use App\Inventory\Warranty\DefectReportDraft;
use App\Inventory\Warranty\DefectReporting;
use App\Inventory\Warranty\DefectReportStatus;
use App\Inventory\Warranty\DefectScope;
use App\Models\DefectReport;
use App\Models\Dispatch;
use App\Models\RevealLogEntry;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('intake');
    Storage::fake('local');
    $this->seed(RoleSeeder::class);
    app(KeyFingerprints::class)->register();
    $this->travelTo(CarbonImmutable::parse('2026-09-15 10:00'));

    $this->admin = staffMember(Role::QuanTri);
    $this->seller = staffMember(Role::BanHang);
    $this->shopee = app(SalesChannelDirectory::class)->create($this->admin, new SalesChannelDraft('Shopee', requiresExternalRef: true));
    $this->netflix = app(ProductCatalog::class)->create($this->admin, new ProductDraft(
        type: ProductType::Account,
        name: 'Netflix 1 tháng',
        code: 'NETFLIX-1M',
        fields: [
            new ContentFieldDraft('username', 'Tên đăng nhập', ContentFieldType::Email, sensitive: false, dedupeKey: true),
            new ContentFieldDraft('password', 'Mật khẩu'),
        ],
        defaultSlots: 3,
        warrantyDays: 30,
    ));

    $intake = app(BatchIntake::class);
    $intake->confirm($this->admin, $intake->submit($this->admin, new BatchDraft(
        supplier: app(SupplierDirectory::class)->create($this->admin, 'Kinguin'),
        receivedOn: CarbonImmutable::today(),
        lines: [new BatchLineDraft($this->netflix, 90_000, "a@shop.test\tpw-a")],
    )));
});

function panelOrder(string $ref, int $quantity, string $customer): Dispatch
{
    return app(ManualDispatch::class)->create(test()->seller, new DispatchDraft(test()->shopee, $ref, [new DispatchLineDraft(test()->netflix, $quantity)], customer: $customer));
}

it('Báo lỗi từ bảng Lần giao cho nhiều Slot: mô tả bắt buộc, ảnh tuỳ chọn; tạo lại sau Bác bỏ thì hiện lịch sử Bác bỏ', function () {
    $dispatch = panelOrder('SP-001', 2, 'Anh Minh');
    [$first, $second] = $dispatch->deliveries()->orderBy('deliveries.id')->get()->all();
    $this->actingAs($this->seller);

    Livewire::test(DispatchDeliveries::class, ['record' => $dispatch])
        ->selectTableRecords([$first->id, $second->id])
        ->callAction(TestAction::make('report')->table()->bulk(), data: ['description' => ''])
        ->assertHasActionErrors(['description' => 'required'])
        ->setActionData(['description' => 'Không đăng nhập được', 'screenshot' => UploadedFile::fake()->image('anh.png')])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertNotified('Đã tạo 2 Báo lỗi Chờ xác minh.');

    $reports = DefectReport::orderBy('id')->get();

    expect($reports->map(fn (DefectReport $report) => [$report->delivery_id, $report->status, $report->description])->all())->toBe([
        [$first->id, DefectReportStatus::Pending, 'Không đăng nhập được'],
        [$second->id, DefectReportStatus::Pending, 'Không đăng nhập được'],
    ])
        ->and($reports->pluck('screenshot_path')->unique())->toHaveCount(1)
        ->and(Storage::disk('local')->exists((string) $reports[0]->screenshot_path))->toBeTrue();

    Livewire::test(DispatchDeliveries::class, ['record' => $dispatch])
        ->assertSee('Chờ xác minh')
        ->assertActionHidden(TestAction::make('report')->table($first));

    app(DefectReporting::class)->reject($this->seller, $reports[0], 'Khách nhập sai mật khẩu');

    Livewire::test(DispatchDeliveries::class, ['record' => $dispatch])
        ->mountAction(TestAction::make('report')->table($first))
        ->assertMountedActionModalSee(['Các lần Bác bỏ trước', 'Không đăng nhập được', 'Khách nhập sai mật khẩu'])
        ->setActionData(['description' => 'Lại không đăng nhập được'])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect(DefectReport::where('delivery_id', $first->id)->where('status', DefectReportStatus::Pending)->value('description'))->toBe('Lại không đăng nhập được');
});

it('ngoài Hạn bảo hành chỉ Quản trị Báo lỗi được, bắt buộc lý do', function () {
    $dispatch = panelOrder('SP-001', 1, 'Anh Minh');
    $delivery = $dispatch->deliveries()->firstOrFail();
    $this->travel(31)->days();
    $this->actingAs($this->seller);

    Livewire::test(DispatchDeliveries::class, ['record' => $dispatch])
        ->assertActionHidden(TestAction::make('report')->table($delivery));

    $this->actingAs($this->admin);

    Livewire::test(DispatchDeliveries::class, ['record' => $dispatch])
        ->callAction(TestAction::make('report')->table($delivery), data: ['description' => 'Bị khoá'])
        ->assertNotified('Báo lỗi ngoài Hạn bảo hành phải nhập lý do.')
        ->setActionData(['description' => 'Bị khoá', 'override_reason' => 'Khách VIP'])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect(DefectReport::sole()->warranty_override_reason)->toBe('Khách VIP');
});

it('xác minh ở trang Báo lỗi: Xem mã ghi Nhật ký xem mã, Xác nhận cả Đơn vị hàng, rồi Báo lỗi hàng loạt cho Lần giao bị ảnh hưởng', function () {
    $delivery = panelOrder('SP-001', 1, 'Anh Minh')->deliveries()->firstOrFail();
    $lan = panelOrder('SP-002', 1, 'Chị Lan')->deliveries()->firstOrFail();
    [$report] = app(DefectReporting::class)->report($this->seller, [$delivery], new DefectReportDraft('Tài khoản bị khoá'));
    $this->actingAs($this->seller);

    Livewire::test(ViewDefectReport::class, ['record' => $report->getRouteKey()])
        ->assertSee(['Tài khoản bị khoá', 'SP-001', 'Anh Minh', 'Chờ xác minh'])
        ->assertDontSee('pw-a')
        ->assertActionHidden('confirmAffected')
        ->callAction('reveal')
        ->assertActionMounted('revealedContent')
        ->assertMountedActionModalSee('Mật khẩu: pw-a');

    expect(RevealLogEntry::sole())->context->toBe(RevealContextType::DefectReport)->context_id->toBe($report->id);

    Livewire::test(ViewDefectReport::class, ['record' => $report->getRouteKey()])
        ->mountAction('confirm')
        ->assertActionDataSet(['scope' => DefectScope::Unit->value])
        ->setActionData(['note' => ''])
        ->callMountedAction()
        ->assertHasActionErrors(['note' => 'required'])
        ->setActionData(['note' => 'Đăng nhập thử bị khoá'])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertNotified('Đã Xác nhận Báo lỗi.')
        ->assertActionHidden('confirm')
        ->assertActionHidden('reject')
        ->assertActionHidden('reveal')
        ->mountAction('confirmAffected')
        ->assertMountedActionModalSee(['Chị Lan', 'SP-002', 'không tự Đổi hàng'])
        ->setActionData(['deliveries' => [(string) $lan->id]])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertNotified('Đã tạo 1 Báo lỗi tự Xác nhận.')
        // Không còn lần giao nào tạo Báo lỗi được: ẩn nút thay vì để lần bấm sau luôn thất bại.
        ->assertActionHidden('confirmAffected');

    expect($delivery->stockUnit->fresh()->status)->toBe(StockUnitStatus::Defective)
        ->and(DefectReport::where('delivery_id', $lan->id)->sole())
        ->status->toBe(DefectReportStatus::Confirmed)
        ->source_defect_report_id->toBe($report->id);
});

it('Bác bỏ ở trang Báo lỗi bắt buộc ghi chú', function () {
    $delivery = panelOrder('SP-001', 1, 'Anh Minh')->deliveries()->firstOrFail();
    [$report] = app(DefectReporting::class)->report($this->seller, [$delivery], new DefectReportDraft('Không vào được'));
    $this->actingAs($this->admin);

    Livewire::test(ViewDefectReport::class, ['record' => $report->getRouteKey()])
        ->callAction('reject', data: ['note' => ''])
        ->assertHasActionErrors(['note' => 'required'])
        ->setActionData(['note' => 'Khách nhập sai'])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect($report->fresh())->status->toBe(DefectReportStatus::Rejected)->verification_note->toBe('Khách nhập sai');
});

it('danh sách Báo lỗi có tab Chờ xác minh quá 24 giờ; Bán hàng và Quản trị vào được, Nhập kho thì không', function () {
    [$old, $recent] = panelOrder('SP-001', 2, 'Anh Minh')->deliveries()->orderBy('deliveries.id')->get()->all();
    [$oldReport] = app(DefectReporting::class)->report($this->seller, [$old], new DefectReportDraft('Cũ'));
    $this->travel(23)->hours();
    [$recentReport] = app(DefectReporting::class)->report($this->seller, [$recent], new DefectReportDraft('Mới'));
    $this->travel(2)->hours();
    $this->actingAs($this->seller);

    Livewire::test(ListDefectReports::class)
        ->assertCanSeeTableRecords([$oldReport, $recentReport])
        ->set('activeTab', 'overdue')
        ->assertCanSeeTableRecords([$oldReport])
        ->assertCanNotSeeTableRecords([$recentReport]);

    $this->get(DefectReportResource::getUrl('index'))->assertOk();
    $this->get(DefectReportResource::getUrl('view', ['record' => $oldReport]))->assertOk();

    $this->actingAs(staffMember(Role::NhapKho));

    $this->get(DefectReportResource::getUrl('index'))->assertForbidden();
});

it('Đơn vị hàng Lỗi hiện Lần giao bị ảnh hưởng ở chi tiết Đơn vị hàng kèm trạng thái Báo lỗi; Báo lỗi liên kết sang đó; Nhập kho không thấy khách', function () {
    $delivery = panelOrder('SP-001', 1, 'Anh Minh')->deliveries()->firstOrFail();
    $lan = panelOrder('SP-002', 1, 'Chị Lan')->deliveries()->firstOrFail();
    panelOrder('SP-003', 1, 'Chị Hoa');
    $reports = app(DefectReporting::class);
    [$report] = $reports->report($this->seller, [$delivery], new DefectReportDraft('Bị khoá'));
    $unit = ['record' => $delivery->stock_unit_id];
    $this->actingAs($this->seller);

    Livewire::test(ViewStockUnit::class, $unit)->assertDontSee('Lần giao bị ảnh hưởng');

    $reports->confirm($this->seller, $report, DefectScope::Unit, 'Bị khoá thật');
    $reports->confirmAffected($this->seller, $report, [$lan]);

    Livewire::test(ViewStockUnit::class, $unit)
        ->assertSeeInOrder(['Lần giao bị ảnh hưởng', 'SP-001', 'Anh Minh', 'Xác nhận', 'SP-002', 'Chị Lan', 'Xác nhận', 'SP-003', 'Chị Hoa', 'Chưa có Báo lỗi']);

    Livewire::test(ViewDefectReport::class, ['record' => $report->getRouteKey()])
        ->assertSeeHtml(StockUnitResource::getUrl('view', $unit));

    $this->actingAs(staffMember(Role::NhapKho));

    Livewire::test(ViewStockUnit::class, $unit)->assertDontSee(['Lần giao bị ảnh hưởng', 'Chị Lan']);
});
