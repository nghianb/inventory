<?php

use App\Filament\Pages\Dashboard;
use App\Filament\Pages\DispatchFreezePage;
use App\Filament\Pages\StockReportPage;
use App\Filament\Resources\Batches\BatchResource;
use App\Filament\Resources\Batches\Pages\ListBatches;
use App\Filament\Resources\DefectReports\DefectReportResource;
use App\Filament\Resources\Dispatches\DispatchResource;
use App\Filament\Resources\SupplierClaims\SupplierClaimResource;
use App\Filament\Widgets\IntakeQueue;
use App\Filament\Widgets\OutboundQueue;
use App\Filament\Widgets\OwnerQueue;
use App\Filament\Widgets\StockAlerts;
use App\Filament\Widgets\StockSummary;
use App\Inventory\Access\Role;
use App\Inventory\Api\ApiKeys;
use App\Inventory\Catalog\ContentFieldDraft;
use App\Inventory\Catalog\StockForm;
use App\Inventory\Catalog\SupplierDirectory;
use App\Inventory\Claims\SupplierClaims;
use App\Inventory\Dispatch\DispatchDraft;
use App\Inventory\Dispatch\DispatchFreeze;
use App\Inventory\Dispatch\DispatchLineDraft;
use App\Inventory\Dispatch\DispatchStatus;
use App\Inventory\Dispatch\HoldExpiry;
use App\Inventory\Dispatch\ManualDispatch;
use App\Inventory\Dispatch\SalesChannelDirectory;
use App\Inventory\Dispatch\SalesChannelDraft;
use App\Inventory\Dispatch\SalesChannelType;
use App\Inventory\Encryption\KeyFingerprints;
use App\Inventory\Intake\BatchDraft;
use App\Inventory\Intake\BatchIntake;
use App\Inventory\Intake\BatchLineDraft;
use App\Inventory\Stock\StockDefect;
use App\Inventory\Warranty\DefectReportDraft;
use App\Inventory\Warranty\DefectReporting;
use App\Inventory\Warranty\DefectScope;
use App\Models\Batch;
use App\Models\DefectReport;
use App\Models\Delivery;
use App\Models\StockUnit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
 * Trang Tổng quan là hàng đợi việc cần làm. Bốn widget tách theo *công việc* (Xuất hàng / Nhập hàng)
 * chứ không theo Vai trò, nên không widget nào đổi số ô theo người xem.
 */

beforeEach(function () {
    Storage::fake('intake');
    $this->seed(RoleSeeder::class);
    app(KeyFingerprints::class)->register();
    $this->travelTo(CarbonImmutable::parse('2026-09-16 10:00'));

    $this->admin = staffMember(Role::Owner);
    $this->stocker = staffMember(Role::NhapKho);
    $this->seller = staffMember(Role::BanHang);

    $this->supplier = app(SupplierDirectory::class)->create($this->admin, 'Kinguin');
    $channels = app(SalesChannelDirectory::class);
    $this->zalo = $channels->create($this->admin, new SalesChannelDraft('Zalo'));
    $this->website = $channels->create($this->admin, new SalesChannelDraft('Website', SalesChannelType::Api));
    $this->secret = app(ApiKeys::class)->issue($this->admin, $this->website)->secret;

    $this->netflix = productOf(
        StockForm::Account,
        [
            new ContentFieldDraft('username', 'Tên đăng nhập', sensitive: false, dedupeKey: true),
            new ContentFieldDraft('password', 'Mật khẩu'),
        ],
        'Netflix 1 tháng',
        'NETFLIX-1M',
        defaultSlots: 2,
        warrantyDays: 30,
        lowStockThreshold: 5,
    );
});

/**
 * Ô của một widget stats, theo nhãn. Đi qua Livewire để chắc chắn widget dựng được thật, rồi đọc
 * thẳng các Stat thay vì bới HTML.
 *
 * @param  class-string  $widget
 * @return array<string, Stat>
 */
function dashboardStats(string $widget): array
{
    $instance = Livewire::test($widget)->instance();
    $stats = (fn (): array => $this->getStats())->call($instance);

    return collect($stats)->mapWithKeys(fn (Stat $stat): array => [(string) $stat->getLabel() => $stat])->all();
}

it('mỗi Vai trò thấy đúng widget của công việc mình làm, số ô không đổi theo người xem', function (?Role $role, array $visible) {
    $this->actingAs($role === null ? User::factory()->withTwoFactor()->create() : staffMember($role));

    $this->get(Dashboard::getUrl())->assertOk();

    $all = [
        OutboundQueue::class => 4,
        IntakeQueue::class => 4,
        StockSummary::class => 3,
        OwnerQueue::class => 2,
    ];

    foreach ($all as $widget => $count) {
        expect($widget::canView())->toBe(in_array($widget, $visible, true));

        if ($widget::canView()) {
            expect(dashboardStats($widget))->toHaveCount($count);
        }
    }
})->with([
    'Quản trị' => [Role::Owner, [OutboundQueue::class, IntakeQueue::class, StockSummary::class, OwnerQueue::class]],
    'Nhập kho' => [Role::NhapKho, [IntakeQueue::class, StockSummary::class]],
    'Bán hàng' => [Role::BanHang, [OutboundQueue::class, StockSummary::class]],
    'không vai trò' => [null, []],
]);

it('widget xếp trên bảng cảnh báo, và Tổng quan không còn widget mặc định của Filament', function () {
    $this->actingAs($this->admin);

    expect(OutboundQueue::getSort())->toBeLessThan(IntakeQueue::getSort())
        ->and(IntakeQueue::getSort())->toBeLessThan(StockSummary::getSort())
        ->and(StockSummary::getSort())->toBeLessThan(OwnerQueue::getSort())
        ->and(OwnerQueue::getSort())->toBeLessThan(StockAlerts::getSort());

    $widgets = collect(app(Dashboard::class)->getWidgets())->map(fn (string $widget): string => class_basename($widget));

    expect($widgets)->not->toContain('AccountWidget')
        ->and($widgets)->not->toContain('FilamentInfoWidget');
});

it('kho sạch việc thì mọi ô hàng đợi vẫn hiện, bằng 0 và xám', function () {
    $this->actingAs($this->admin);

    foreach ([OutboundQueue::class, IntakeQueue::class, OwnerQueue::class] as $widget) {
        foreach (dashboardStats($widget) as $label => $stat) {
            if ($label === 'Tạm dừng xuất kho') {
                continue;
            }

            expect($stat->getValue())->toBe('0', "{$label} phải là 0")
                ->and($stat->getColor())->toBe('gray', "{$label} phải xám")
                ->and($stat->getDescription())->toBe('Không còn việc')
                // Ô bằng 0 vẫn bấm được: nhân viên còn muốn xem danh sách rỗng để tin là sạch thật.
                ->and($stat->getUrl())->not->toBeNull();
        }
    }
});

it('widget Xuất hàng đếm Phiếu xuất và Báo lỗi đang kẹt, mỗi ô dẫn tới đúng danh sách', function () {
    stockUp($this->netflix, "a@shop.test\tpw-a\nb@shop.test\tpw-b\nc@shop.test\tpw-c");

    // Hai Phiếu xuất của kênh API: một để hết hạn giữ, một còn Đang giữ.
    $hold = fn (string $ref) => $this->withHeaders(['Authorization' => 'Bearer '.$this->secret])
        ->postJson('/api/v1/dispatches', [
            'external_ref' => $ref,
            'hold' => true,
            'lines' => [['product_code' => 'NETFLIX-1M', 'quantity' => 1, 'sale_price' => 95_000]],
        ])->assertCreated();

    $hold('WEB-1');
    $this->travel(20)->minutes();
    expect(app(HoldExpiry::class)->releaseExpired())->toBe(1);
    $hold('WEB-2');

    // Một lần giao thật để có Báo lỗi: một cái Chờ xác minh, một cái đã Xác nhận nên Chờ đổi.
    app(ManualDispatch::class)->create($this->seller, new DispatchDraft($this->zalo, null, [new DispatchLineDraft($this->netflix, 2)]));
    [$first, $second] = Delivery::query()->orderBy('id')->get()->all();
    $reporting = app(DefectReporting::class);
    [$pending] = $reporting->report($this->seller, [$first], new DefectReportDraft('Không đăng nhập được'));
    [$confirmed] = $reporting->report($this->seller, [$second], new DefectReportDraft('Sai mật khẩu'));
    $reporting->confirm($this->admin, $confirmed, DefectScope::Slot, 'Đúng là hỏng');

    $this->actingAs($this->seller);
    $stats = dashboardStats(OutboundQueue::class);

    expect($stats['Phiếu xuất Đang giữ']->getValue())->toBe('1')
        ->and($stats['Phiếu xuất Hết hạn giữ']->getValue())->toBe('1')
        ->and($stats['Báo lỗi Chờ xác minh']->getValue())->toBe('1')
        ->and($stats['Báo lỗi Chờ đổi']->getValue())->toBe('1');

    expect($stats['Phiếu xuất Đang giữ']->getUrl())
        ->toBe(DispatchResource::getUrl('index', ['filters' => ['status' => ['value' => DispatchStatus::Holding->value]]]))
        ->and($stats['Phiếu xuất Hết hạn giữ']->getUrl())
        ->toBe(DispatchResource::getUrl('index', ['filters' => ['status' => ['value' => DispatchStatus::HoldExpired->value]]]))
        // Danh sách Báo lỗi đã có sẵn tab cho đúng ba tập này, nên ô dẫn vào tab chứ không đẻ bộ lọc song song.
        ->and($stats['Báo lỗi Chờ xác minh']->getUrl())->toBe(DefectReportResource::getUrl('index', ['tab' => 'pending']))
        ->and($stats['Báo lỗi Chờ đổi']->getUrl())->toBe(DefectReportResource::getUrl('index', ['tab' => 'awaiting']));

    expect($pending->fresh()->status->value)->toBe('pending');
});

it('widget Nhập hàng đếm Lô nhập chờ xác nhận và Khiếu nại, mỗi ô dẫn tới đúng danh sách', function () {
    stockUp($this->netflix, "a@shop.test\tpw-a\nb@shop.test\tpw-b\nc@shop.test\tpw-c");

    // Ba Đơn vị hàng Lỗi: một để trống, một vào Khiếu nại Nháp, một vào Khiếu nại Đã gửi.
    $defect = app(StockDefect::class);
    $units = StockUnit::query()->orderBy('id')->get();
    foreach ($units as $unit) {
        $defect->markDefective($this->admin, $unit, 'Nhà cung cấp thu hồi');
    }

    $claims = app(SupplierClaims::class);
    $claims->create($this->admin, $this->supplier, [$units[1]]);
    $claims->send($this->admin, $claims->create($this->admin, $this->supplier, [$units[2]]));

    // Hai Lô nhập dừng ở màn xem trước, một cái đã quá hạn xác nhận mà job dọn chưa chạy: ô chỉ
    // đếm cái còn cứu được.
    $intake = app(BatchIntake::class);
    $submit = fn (string $content) => $intake->submit($this->stocker, new BatchDraft(
        supplier: $this->supplier,
        receivedOn: CarbonImmutable::today(),
        lines: [new BatchLineDraft($this->netflix, 95_000, $content)],
    ));
    $stale = $submit("d@shop.test\tpw-d");
    Batch::whereKey($stale->id)->update(['created_at' => now()->subHours(25)]);
    $fresh = $submit("e@shop.test\tpw-e");

    $this->actingAs($this->stocker);
    $stats = dashboardStats(IntakeQueue::class);

    expect($stats['Lô nhập Chờ xác nhận']->getValue())->toBe('1')
        ->and($stats['Đơn vị hàng Lỗi chưa khiếu nại']->getValue())->toBe('1')
        ->and($stats['Khiếu nại Nháp']->getValue())->toBe('1')
        ->and($stats['Khiếu nại Đã gửi']->getValue())->toBe('1');

    expect($stats['Lô nhập Chờ xác nhận']->getUrl())
        ->toBe(BatchResource::getUrl('index', ['filters' => ['awaiting_confirmation' => ['isActive' => true]]]))
        // Bảng Đơn vị hàng Lỗi chưa khiếu nại nằm sẵn trên trang Khiếu nại, không cần bộ lọc riêng.
        ->and($stats['Đơn vị hàng Lỗi chưa khiếu nại']->getUrl())->toBe(SupplierClaimResource::getUrl('index'))
        ->and($stats['Khiếu nại Nháp']->getUrl())
        ->toBe(SupplierClaimResource::getUrl('index', ['filters' => ['status' => ['value' => 'draft']]]))
        ->and($stats['Khiếu nại Đã gửi']->getUrl())
        ->toBe(SupplierClaimResource::getUrl('index', ['filters' => ['status' => ['value' => 'sent']]]));

    // Ô đếm 1 thì danh sách nó dẫn tới cũng phải đúng 1 dòng, không phải cả lô đã quá hạn.
    Livewire::test(ListBatches::class)
        ->filterTable('awaiting_confirmation')
        ->assertCanSeeTableRecords([$fresh])
        ->assertCanNotSeeTableRecords([$stale]);
});

it('widget Tồn kho nói cùng con số với báo cáo Tồn kho, không ô tiền nào', function () {
    stockUp($this->netflix, "a@shop.test\tpw-a\nb@shop.test\tpw-b\nc@shop.test\tpw-c");
    app(StockDefect::class)->markDefective($this->admin, StockUnit::query()->orderBy('id')->first(), 'Nhà cung cấp thu hồi');

    $this->actingAs($this->seller);
    $stats = dashboardStats(StockSummary::class);

    // 3 Đơn vị hàng × 2 Slot; một đơn vị chuyển Lỗi nên 4 Slot bán được, 2 Slot Tồn lỗi.
    expect($stats['Tồn bán được']->getValue())->toBe('4')
        ->and($stats['Tồn lỗi']->getValue())->toBe('2')
        // Ngưỡng sắp hết 5, Tồn bán được 4: Netflix bị cảnh báo.
        ->and($stats['Sản phẩm sắp hết']->getValue())->toBe('1');

    expect(array_keys($stats))->toBe(['Tồn bán được', 'Tồn lỗi', 'Sản phẩm sắp hết']);

    // Tồn bán được là chính cái báo cáo, nên ô dẫn thẳng vào đó không kèm bộ lọc nào.
    expect($stats['Tồn bán được']->getUrl())->toBe(StockReportPage::getUrl())
        ->and($stats['Tồn lỗi']->getUrl())->toBe(StockReportPage::getUrl(['filters' => ['defective' => ['isActive' => true]]]))
        ->and($stats['Sản phẩm sắp hết']->getUrl())->toBe(StockReportPage::getUrl(['filters' => ['low_stock' => ['isActive' => true]]]));
});

it('kho rỗng thì ô Tồn bán được báo động chứ không mừng', function () {
    $this->actingAs($this->seller);
    $stats = dashboardStats(StockSummary::class);

    // Ô tồn kho không theo quy ước "Không còn việc" của hàng đợi: 0 Slot bán được là tin xấu.
    expect($stats['Tồn bán được']->getValue())->toBe('0')
        ->and($stats['Tồn bán được']->getColor())->toBe('danger')
        ->and($stats['Tồn bán được']->getDescription())->toBe('Không còn Slot nào giao được')
        ->and($stats['Tồn lỗi']->getColor())->toBe('gray')
        // Kho rỗng thì mọi Sản phẩm có Ngưỡng sắp hết đều đang chạm ngưỡng, kể cả khi chưa từng nhập.
        ->and($stats['Sản phẩm sắp hết']->getValue())->toBe('1')
        ->and($stats['Sản phẩm sắp hết']->getColor())->toBe('warning');
});

it('widget Quản trị đếm Đổi hàng chờ duyệt và nói kho đang dừng hay đang chạy', function () {
    stockUp($this->netflix, "a@shop.test\tpw-a\nb@shop.test\tpw-b");

    app(ManualDispatch::class)->create($this->seller, new DispatchDraft($this->zalo, null, [new DispatchLineDraft($this->netflix, 1)]));
    $reporting = app(DefectReporting::class);
    [$report] = $reporting->report($this->seller, [Delivery::sole()], new DefectReportDraft('Không đăng nhập được'));
    $reporting->confirm($this->admin, $report, DefectScope::Slot, 'Đúng là hỏng');
    // Từ lần đổi thứ 3 Bán hàng phải xin duyệt; ở đây chỉ cần trạng thái "đã xin, chưa duyệt".
    DefectReport::whereKey($report->id)->update([
        'replacement_approval_requested_by' => $this->seller->id,
        'replacement_approval_requested_at' => now(),
    ]);

    $this->actingAs($this->admin);

    $approval = dashboardStats(OwnerQueue::class)['Đổi hàng chờ duyệt'];
    expect($approval->getValue())->toBe('1')
        ->and($approval->getUrl())->toBe(DefectReportResource::getUrl('index', ['tab' => 'approval']));

    $freeze = dashboardStats(OwnerQueue::class)['Tạm dừng xuất kho'];
    expect($freeze->getValue())->toBe('Đang chạy')
        ->and($freeze->getColor())->toBe('gray')
        ->and($freeze->getUrl())->toBe(DispatchFreezePage::getUrl());

    app(DispatchFreeze::class)->freeze($this->admin, 'Khôi phục từ backup');

    $frozen = dashboardStats(OwnerQueue::class)['Tạm dừng xuất kho'];
    expect($frozen->getValue())->toBe('Đang tạm dừng')
        ->and($frozen->getColor())->toBe('danger')
        ->and($frozen->getDescription())->toBe('Khôi phục từ backup');
});
