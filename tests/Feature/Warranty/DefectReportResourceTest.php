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
use App\Inventory\Intake\ExpiryRule;
use App\Inventory\Reveal\RevealContextType;
use App\Inventory\Stock\StockUnitStatus;
use App\Inventory\Warranty\DefectReportDraft;
use App\Inventory\Warranty\DefectReporting;
use App\Inventory\Warranty\DefectReportStatus;
use App\Inventory\Warranty\DefectResolution;
use App\Inventory\Warranty\DefectScope;
use App\Inventory\Warranty\ReplacementDelivery;
use App\Inventory\Warranty\ReplacementDraft;
use App\Models\DefectReport;
use App\Models\Delivery;
use App\Models\Dispatch;
use App\Models\Product;
use App\Models\Replacement;
use App\Models\RevealLogEntry;
use App\Models\Supplier;
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

    $this->admin = staffMember(Role::Owner);
    $this->seller = staffMember(Role::BanHang);
    $this->shopee = app(SalesChannelDirectory::class)->create($this->admin, new SalesChannelDraft('Shopee', requiresExternalRef: true));
    $this->netflix = productOf(
        StockForm::Account,
        [
            new ContentFieldDraft('username', 'Tên đăng nhập', ContentFieldType::Email, sensitive: false, dedupeKey: true),
            new ContentFieldDraft('password', 'Mật khẩu'),
        ],
        'Netflix 1 tháng',
        'NETFLIX-1M',
        defaultSlots: 3,
        warrantyDays: 30,
    );

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
    // Hai Báo lỗi tồn đọng chứ không một: tab lọc bằng truy vấn con, một dòng thì lọc sai vẫn chạy được.
    [$first, $second, $recent] = panelOrder('SP-001', 3, 'Anh Minh')->deliveries()->orderBy('deliveries.id')->get()->all();
    $overdue = app(DefectReporting::class)->report($this->seller, [$first, $second], new DefectReportDraft('Cũ'));
    $this->travel(23)->hours();
    [$recentReport] = app(DefectReporting::class)->report($this->seller, [$recent], new DefectReportDraft('Mới'));
    $this->travel(2)->hours();
    $this->actingAs($this->seller);

    Livewire::test(ListDefectReports::class)
        ->assertCanSeeTableRecords([...$overdue, $recentReport])
        ->set('activeTab', 'overdue')
        ->assertCanSeeTableRecords($overdue)
        ->assertCanNotSeeTableRecords([$recentReport]);

    $this->get(DefectReportResource::getUrl('index'))->assertOk();
    $this->get(DefectReportResource::getUrl('view', ['record' => $overdue[0]]))->assertOk();

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

function panelStock(Product $product, string $content, ?ExpiryRule $expiry = null): void
{
    $intake = app(BatchIntake::class);
    $intake->confirm(test()->admin, $intake->submit(test()->admin, new BatchDraft(
        supplier: Supplier::query()->firstOrFail(),
        receivedOn: CarbonImmutable::today(),
        lines: [new BatchLineDraft($product, 90_000, $content, expiry: $expiry)],
    )));
}

it('Đổi hàng ở trang Báo lỗi: hiện Hạn bảo hành kế thừa, đổi xong hiện mã một lần và ghi Nhật ký xem mã ngữ cảnh Đổi hàng; bảng Lần giao hiện Đổi hàng cho', function () {
    panelStock($this->netflix, "b@shop.test\tpw-b");
    $dispatch = panelOrder('SP-001', 1, 'Anh Minh');
    $delivery = $dispatch->deliveries()->firstOrFail();
    $reports = app(DefectReporting::class);
    [$report] = $reports->report($this->seller, [$delivery], new DefectReportDraft('Bị khoá'));
    $this->actingAs($this->seller);

    Livewire::test(ViewDefectReport::class, ['record' => $report->getRouteKey()])
        ->assertActionHidden('replace')
        ->assertActionHidden('decline');

    $reports->confirm($this->seller, $report, DefectScope::Unit, 'Bị khoá thật');
    $this->travel(3)->days();

    Livewire::test(ViewDefectReport::class, ['record' => $report->getRouteKey()])
        ->assertSee('Chờ đổi')
        ->mountAction('replace')
        ->assertActionDataSet(['product_id' => $this->netflix->id])
        ->assertMountedActionModalSee(['Hạn bảo hành kế thừa: 15/10/2026', 'Lần đổi thứ 1 trong chuỗi'])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertNotified('Đã Đổi hàng.')
        ->assertActionMounted('revealedContent')
        ->assertMountedActionModalSee('Mật khẩu: pw-b');

    $replacement = Replacement::sole();

    expect($report->fresh()->resolution)->toBe(DefectResolution::Replaced)
        ->and(RevealLogEntry::sole())->context->toBe(RevealContextType::Replacement)->context_id->toBe($replacement->id);

    Livewire::test(ViewDefectReport::class, ['record' => $report->getRouteKey()])
        ->assertSee(['Đã đổi', $replacement->delivery->unitLabel()])
        ->assertDontSee('pw-b')
        ->assertActionHidden('replace')
        ->assertActionHidden('decline');

    Livewire::test(DispatchDeliveries::class, ['record' => $dispatch])
        ->assertTableColumnStateSet('replaces', $delivery->unitLabel(), $replacement->delivery_id);
});

it('Đổi hàng sang Sản phẩm khác bắt buộc lý do; không có Slot phủ Hạn bảo hành thì phải chấp nhận Slot hạn ngắn hơn', function () {
    $garena = productOf(
        StockForm::OneTimeCode,
        [new ContentFieldDraft('code', 'Mã', dedupeKey: true)],
        'Garena 50k',
        'GARENA-50K',
        warrantyDays: 30,
    );
    panelStock($garena, 'G-1', ExpiryRule::on(CarbonImmutable::parse('2026-10-01')));
    $delivery = panelOrder('SP-001', 1, 'Anh Minh')->deliveries()->firstOrFail();
    [$report] = app(DefectReporting::class)->report($this->seller, [$delivery], new DefectReportDraft('Bị khoá'));
    app(DefectReporting::class)->confirm($this->seller, $report, DefectScope::Unit, 'Bị khoá thật');
    $this->actingAs($this->seller);

    Livewire::test(ViewDefectReport::class, ['record' => $report->getRouteKey()])
        ->mountAction('replace')
        ->assertMountedActionModalSee('Hết hàng')
        ->setActionData(['product_id' => $garena->id])
        ->assertMountedActionModalSee('Slot hạn dài nhất hết hạn 01/10/2026')
        ->callMountedAction()
        ->assertHasActionErrors(['product_change_reason' => 'required', 'accept_shorter_expiry' => 'accepted'])
        ->setActionData(['product_change_reason' => 'Hết Netflix', 'accept_shorter_expiry' => true])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertActionMounted('revealedContent');

    expect(Replacement::sole())
        ->product_change_reason->toBe('Hết Netflix')
        ->short_expiry_accepted->toBeTrue();
});

it('Không đổi ở trang Báo lỗi bắt buộc lý do; đã hoàn tiền thì nhắc sửa Giá bán của Dòng xuất; danh sách có tab Chờ đổi', function () {
    [$first, $second] = panelOrder('SP-001', 2, 'Anh Minh')->deliveries()->orderBy('deliveries.id')->get()->all();
    $reports = app(DefectReporting::class);
    [$refund, $pending] = $reports->report($this->seller, [$first, $second], new DefectReportDraft('Bị khoá'));
    $reports->confirm($this->seller, $refund, DefectScope::Slot, 'Đúng là lỗi');
    $this->actingAs($this->seller);

    Livewire::test(ListDefectReports::class)
        ->set('activeTab', 'awaiting')
        ->assertCanSeeTableRecords([$refund])
        ->assertCanNotSeeTableRecords([$pending]);

    Livewire::test(ViewDefectReport::class, ['record' => $refund->getRouteKey()])
        ->callAction('decline', data: ['reason' => ''])
        ->assertHasActionErrors(['reason' => 'required'])
        ->setActionData(['reason' => 'Hết hàng, đã hoàn tiền', 'refunded' => true])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertNotified('Đã đặt Không đổi. Hãy sửa Giá bán của Dòng xuất xuống số tiền shop thực giữ.')
        ->assertSee(['Không đổi', 'Hết hàng, đã hoàn tiền'])
        ->assertActionHidden('decline')
        ->assertActionHidden('replace');

    expect($refund->fresh())->resolution->toBe(DefectResolution::NotReplaced)->refunded->toBeTrue();

    Livewire::test(ListDefectReports::class)
        ->set('activeTab', 'awaiting')
        ->assertCanNotSeeTableRecords([$refund]);
});

it('lần đổi thứ 3: Bán hàng thấy cảnh báo và yêu cầu Quản trị duyệt; Quản trị duyệt từ tab Chờ Quản trị duyệt; sau đó Bán hàng Đổi hàng được', function () {
    panelStock($this->netflix, "b@shop.test\tpw-b\nc@shop.test\tpw-c\nd@shop.test\tpw-d");
    $reports = app(DefectReporting::class);
    $replacements = app(ReplacementDelivery::class);
    $confirmed = function (Delivery $delivery) use ($reports): DefectReport {
        [$report] = $reports->report($this->seller, [$delivery], new DefectReportDraft('Bị khoá'));
        $reports->confirm($this->seller, $report, DefectScope::Unit, 'Bị khoá thật');

        return $report->fresh();
    };
    $firstReport = $confirmed(panelOrder('SP-001', 1, 'Anh Minh')->deliveries()->firstOrFail());
    $first = $replacements->replace($this->seller, $firstReport, new ReplacementDraft);
    $second = $replacements->replace($this->seller, $confirmed($first->delivery), new ReplacementDraft);
    $third = $confirmed($second->delivery);
    $this->actingAs($this->seller);

    Livewire::test(ViewDefectReport::class, ['record' => $third->getRouteKey()])
        ->assertActionHidden('approveReplacement')
        ->mountAction('replace')
        ->assertMountedActionModalSee(['Lần đổi thứ 3 trong chuỗi', 'từ lần đổi thứ 3 cần Quản trị duyệt']);

    Livewire::test(ViewDefectReport::class, ['record' => $third->getRouteKey()])
        ->callAction('requestReplacementApproval')
        ->assertNotified('Đã yêu cầu Quản trị duyệt Đổi hàng.')
        ->assertActionHidden('requestReplacementApproval');

    $this->actingAs($this->admin);

    Livewire::test(ListDefectReports::class)
        ->set('activeTab', 'approval')
        ->assertCanSeeTableRecords([$third])
        ->assertCanNotSeeTableRecords([$firstReport]);

    Livewire::test(ViewDefectReport::class, ['record' => $third->getRouteKey()])
        ->assertSee('Yêu cầu duyệt Đổi hàng')
        ->assertActionHidden('requestReplacementApproval')
        ->callAction('approveReplacement')
        ->assertNotified('Đã duyệt Đổi hàng.')
        ->assertActionHidden('approveReplacement');

    $this->actingAs($this->seller);

    Livewire::test(ViewDefectReport::class, ['record' => $third->getRouteKey()])
        ->mountAction('replace')
        ->assertMountedActionModalSee('Quản trị đã duyệt lần đổi này')
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertActionMounted('revealedContent')
        ->assertMountedActionModalSee('Mật khẩu: pw-d');

    expect(Replacement::query()->orderByDesc('id')->firstOrFail()->approved_by)->toBe($this->admin->id);
});
