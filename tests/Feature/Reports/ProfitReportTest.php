<?php

use App\Inventory\Access\MissingRole;
use App\Inventory\Access\Role;
use App\Inventory\Catalog\ContentFieldDraft;
use App\Inventory\Catalog\ContentFieldType;
use App\Inventory\Catalog\StockForm;
use App\Inventory\Catalog\SupplierDirectory;
use App\Inventory\Claims\ClaimOutcomeDraft;
use App\Inventory\Claims\SupplierClaims;
use App\Inventory\Dispatch\CorrectionDraft;
use App\Inventory\Dispatch\CorrectiveDelivery;
use App\Inventory\Dispatch\DispatchDraft;
use App\Inventory\Dispatch\DispatchEdit;
use App\Inventory\Dispatch\DispatchEditor;
use App\Inventory\Dispatch\DispatchLineDraft;
use App\Inventory\Dispatch\ManualDispatch;
use App\Inventory\Dispatch\SalesChannelDirectory;
use App\Inventory\Dispatch\SalesChannelDraft;
use App\Inventory\Encryption\KeyFingerprints;
use App\Inventory\Intake\BatchDraft;
use App\Inventory\Intake\BatchIntake;
use App\Inventory\Intake\BatchLineDraft;
use App\Inventory\Intake\ExpiryRule;
use App\Inventory\Intake\ImportReversal;
use App\Inventory\Reports\ProfitReport;
use App\Inventory\Reports\ProfitReportFilter;
use App\Inventory\Reports\ProfitReportRow;
use App\Inventory\Reports\ReportFormat;
use App\Inventory\Stock\StockDefect;
use App\Inventory\Stock\StockVoid;
use App\Inventory\Stock\VoidReason;
use App\Inventory\Warranty\DefectReportDraft;
use App\Inventory\Warranty\DefectReporting;
use App\Inventory\Warranty\DefectScope;
use App\Inventory\Warranty\ReplacementDelivery;
use App\Inventory\Warranty\ReplacementDraft;
use App\Models\Batch;
use App\Models\Delivery;
use App\Models\Dispatch;
use App\Models\Product;
use App\Models\SalesChannel;
use App\Models\StockUnit;
use App\Models\Supplier;
use App\Models\SupplierClaim;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('intake');
    $this->seed(RoleSeeder::class);
    app(KeyFingerprints::class)->register();

    $this->admin = staffMember(Role::Owner);
    $this->stocker = staffMember(Role::NhapKho);
    $this->seller = staffMember(Role::BanHang);
    $this->report = app(ProfitReport::class);

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
    $this->steam = productOf(
        StockForm::OneTimeCode,
        [new ContentFieldDraft('code', 'Mã thẻ', dedupeKey: true)],
        'Steam 100K',
        'STEAM-100K',
        warrantyDays: 7,
    );

    $directory = app(SupplierDirectory::class);
    $this->kinguin = $directory->create($this->admin, 'Kinguin');
    $this->g2a = $directory->create($this->admin, 'G2A');

    $channels = app(SalesChannelDirectory::class);
    $this->shopee = $channels->create($this->admin, new SalesChannelDraft('Shopee'));
    $this->zalo = $channels->create($this->admin, new SalesChannelDraft('Zalo'));

    // Tháng 9, Kinguin: 2 Tài khoản Netflix (3 Slot, Giá vốn Slot 30.000) và 1 mã Steam 100.000.
    $this->travelTo(CarbonImmutable::parse('2026-09-05 09:00'));
    $this->kinguinBatch = profitImport($this->kinguin, [
        new BatchLineDraft($this->netflix, 90_000, "a@shop.test\tpw-a\nb@shop.test\tpw-b"),
        new BatchLineDraft($this->steam, 100_000, 'STEAM-1'),
    ]);

    $this->travelTo(CarbonImmutable::parse('2026-09-15 10:00'));
    $unit = fn (string $username): StockUnit => StockUnit::where('content->username', $username)->sole();
    [$this->a, $this->b] = [$unit('a@shop.test'), $unit('b@shop.test')];
});

/**
 * @param  list<BatchLineDraft>  $lines
 */
function profitImport(Supplier $supplier, array $lines, ?SupplierClaim $claim = null): Batch
{
    $intake = app(BatchIntake::class);

    return $intake->confirm(test()->admin, $intake->submit(test()->admin, new BatchDraft(
        supplier: $supplier,
        receivedOn: CarbonImmutable::today(),
        lines: $lines,
        supplierClaim: $claim,
    )));
}

function profitSell(SalesChannel $channel, string $ref, Product $product, int $quantity, ?int $salePrice = null): Dispatch
{
    return app(ManualDispatch::class)->create(test()->seller, new DispatchDraft($channel, $ref, [new DispatchLineDraft($product, $quantity, $salePrice)]));
}

/**
 * Một Tài khoản Netflix của G2A (3 Slot, Giá vốn Slot 20.000) có Hạn sử dụng, nên **Thứ tự xuất** lấy
 * nó trước hàng Kinguin không hạn: một Dòng xuất đủ lớn sẽ gồm Slot của cả hai Nhà cung cấp.
 */
function profitImportG2A(): Batch
{
    return profitImport(test()->g2a, [new BatchLineDraft(
        test()->netflix,
        60_000,
        "g@shop.test\tpw-g",
        expiry: ExpiryRule::on(CarbonImmutable::parse('2026-12-01')),
    )]);
}

function septemberProfit(): ProfitReportFilter
{
    return new ProfitReportFilter(CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-30'));
}

/**
 * Đổi hàng cho lần giao đầu tiên, đi qua Báo lỗi Xác nhận Phạm vi chỉ Slot để Đơn vị hàng vẫn Hoạt
 * động: chỉ còn Chi phí đổi hàng, không lẫn Tổn thất hàng Lỗi.
 */
function profitReplaceOnlySlot(Delivery $delivery): void
{
    $reporting = app(DefectReporting::class);
    $report = $reporting->report(test()->seller, [$delivery], new DefectReportDraft('Không đăng nhập được'))[0];
    $reporting->confirm(test()->admin, $report, DefectScope::Slot, 'Đúng là lỗi');

    app(ReplacementDelivery::class)->replace(test()->seller, $report->fresh(), new ReplacementDraft);
}

/**
 * @param  list<ProfitReportRow>  $rows
 * @return array<string, array<string, int|string>> tầng Lãi gộp theo Mã sản phẩm
 */
function profitRows(array $rows): array
{
    return collect($rows)->mapWithKeys(fn (ProfitReportRow $row) => [$row->cell('code') => [
        'revenue' => $row->revenue,
        'cogs' => $row->cogs,
        'grossProfit' => $row->grossProfit(),
        'margin' => $row->cell('margin'),
    ]])->all();
}

/**
 * @param  list<ProfitReportRow>  $rows
 * @return array<string, array<string, int>> tầng Điều chỉnh theo Mã sản phẩm
 */
function profitAdjustments(array $rows): array
{
    return collect($rows)->mapWithKeys(fn (ProfitReportRow $row) => [$row->cell('code') => [
        'replacementCost' => $row->replacementCost,
        'defectiveLoss' => $row->defectiveLoss,
        'wrongDeliveryLoss' => $row->wrongDeliveryLoss,
        'contentExposedLoss' => $row->contentExposedLoss,
        'discontinuedLotLoss' => $row->discontinuedLotLoss,
        'expiryLoss' => $row->expiryLoss,
        'reimbursement' => $row->reimbursement,
        'adjustments' => $row->adjustments(),
        'netProfit' => $row->netProfit(),
    ]])->all();
}

it('Lãi gộp là Giá bán trừ Giá vốn Slot khách thực nhận, mỗi Sản phẩm một dòng', function () {
    profitSell($this->shopee, 'SP-1', $this->netflix, 2, 500_000);

    expect(profitRows($this->report->rows($this->admin, septemberProfit()))['NETFLIX-1M'])->toBe([
        'revenue' => 500_000,
        // 2 Slot Netflix, Giá vốn Slot 30.000.
        'cogs' => 60_000,
        'grossProfit' => 440_000,
        'margin' => '88,0%',
    ]);
});

it('Sản phẩm chưa bán gì trong kỳ thì không có dòng', function () {
    profitSell($this->shopee, 'SP-1', $this->netflix, 1, 500_000);

    expect(array_keys(profitRows($this->report->rows($this->admin, septemberProfit()))))->not->toContain('STEAM-100K');
});

it('Tổn thất hàng Lỗi tính Giá vốn Slot còn trong kho, và mất đi khi Khôi phục', function () {
    $defect = app(StockDefect::class);
    $defect->markDefective($this->admin, $this->b, 'Nhà cung cấp thu hồi');

    expect(profitAdjustments($this->report->rows($this->admin, septemberProfit()))['NETFLIX-1M'])->toMatchArray([
        // Cả 3 Slot của Đơn vị hàng còn trong kho, Giá vốn Slot 30.000.
        'defectiveLoss' => 90_000,
        'adjustments' => 90_000,
        'netProfit' => -90_000,
    ]);

    $defect->restore($this->admin, $this->b->fresh(), 'Nhà cung cấp sửa được hàng');

    // Không còn khoản nào trong kỳ: Sản phẩm rời khỏi báo cáo, con số của kỳ cũ đổi theo dữ liệu hiện tại.
    expect(array_keys(profitAdjustments($this->report->rows($this->admin, septemberProfit()))))->not->toContain('NETFLIX-1M');
});

it('Tổn thất Huỷ hàng tách theo từng lý do', function () {
    app(StockVoid::class)->voidUnit($this->admin, $this->a, VoidReason::DiscontinuedLot, 'Ngừng kinh doanh lô');

    expect(profitAdjustments($this->report->rows($this->admin, septemberProfit()))['NETFLIX-1M'])->toMatchArray([
        'discontinuedLotLoss' => 90_000,
        'contentExposedLoss' => 0,
        'wrongDeliveryLoss' => 0,
        'adjustments' => 90_000,
    ]);
});

it('Chi phí đổi hàng là Giá vốn Slot giao ra khi Đổi hàng, không trừ vào Lãi gộp của phiếu gốc', function () {
    profitSell($this->shopee, 'SP-1', $this->netflix, 1, 500_000);
    profitReplaceOnlySlot(Delivery::sole());

    $rows = $this->report->rows($this->admin, septemberProfit());

    expect(profitRows($rows)['NETFLIX-1M'])->toMatchArray([
        // Phiếu gốc vẫn là 1 Slot khách nhận: Slot thay thế không làm dày Giá vốn hàng bán.
        'revenue' => 500_000,
        'cogs' => 30_000,
        'grossProfit' => 470_000,
    ])
        ->and(profitAdjustments($rows)['NETFLIX-1M'])->toMatchArray([
            'replacementCost' => 30_000,
            'adjustments' => 30_000,
            'netProfit' => 440_000,
        ]);
});

it('Tổn thất hết hạn tính vào ngày hết hạn, chỉ Slot còn trong kho', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-20 09:00'));
    profitImport($this->kinguin, [new BatchLineDraft(
        $this->steam,
        50_000,
        'STEAM-SAP-HET-HAN',
        expiry: ExpiryRule::on(CarbonImmutable::parse('2026-09-25')),
    )]);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00'));

    expect(profitAdjustments($this->report->rows($this->admin, septemberProfit()))['STEAM-100K'])->toMatchArray([
        'expiryLoss' => 50_000,
        'adjustments' => 50_000,
    ]);
});

it('bồi hoàn tiền trừ vào Điều chỉnh theo ngày Khiếu nại Đã giải quyết', function () {
    app(StockDefect::class)->markDefective($this->admin, $this->b, 'Nhà cung cấp thu hồi');

    $claims = app(SupplierClaims::class);
    $claim = $claims->create($this->stocker, $this->kinguin, [$this->b]);
    $claims->send($this->stocker, $claim);
    $claims->resolve($this->stocker, $claim, [
        $claim->claimUnits()->sole()->id => ClaimOutcomeDraft::refund(70_000, CarbonImmutable::parse('2026-09-10')),
    ]);

    expect(profitAdjustments($this->report->rows($this->admin, septemberProfit()))['NETFLIX-1M'])->toMatchArray([
        'defectiveLoss' => 90_000,
        'reimbursement' => 70_000,
        'adjustments' => 20_000,
    ]);
});

it('Lãi ròng kho là Lãi gộp trừ Điều chỉnh', function () {
    profitSell($this->shopee, 'SP-1', $this->netflix, 2, 500_000);
    app(StockVoid::class)->voidUnit($this->admin, $this->b, VoidReason::ContentExposed, 'Nhân viên làm lộ nội dung');

    expect(profitAdjustments($this->report->rows($this->admin, septemberProfit()))['NETFLIX-1M'])->toMatchArray([
        'contentExposedLoss' => 90_000,
        'adjustments' => 90_000,
        // Lãi gộp 440.000 trừ 90.000 tổn thất.
        'netProfit' => 350_000,
    ]);
});

it('Slot giao nhầm đã Huỷ hàng không vào Giá vốn hàng bán, mà thành Tổn thất giao nhầm', function () {
    profitSell($this->shopee, 'SP-1', $this->netflix, 1, 500_000);
    app(CorrectiveDelivery::class)->correct($this->seller, Delivery::sole(), new CorrectionDraft(null, contentSent: false));

    $rows = $this->report->rows($this->admin, septemberProfit());

    expect(profitRows($rows)['NETFLIX-1M'])->toMatchArray([
        // Khách vẫn chỉ nhận 1 Slot, dù kho đã xuất ra hai lần.
        'revenue' => 500_000,
        'cogs' => 30_000,
        'grossProfit' => 470_000,
    ])
        ->and(profitAdjustments($rows)['NETFLIX-1M'])->toMatchArray([
            'wrongDeliveryLoss' => 30_000,
            'netProfit' => 440_000,
        ]);
});

it('Giao thay sang Sản phẩm khác tính Giá vốn vào Dòng xuất gốc, không tách thành dòng của Sản phẩm kia', function () {
    profitSell($this->shopee, 'SP-1', $this->netflix, 1, 500_000);
    app(CorrectiveDelivery::class)->correct($this->seller, Delivery::sole(), new CorrectionDraft($this->steam, contentSent: false));

    $rows = $this->report->rows($this->admin, septemberProfit());

    expect(profitRows($rows)['NETFLIX-1M'])->toMatchArray([
        // Giá vốn Slot Steam giao bù (100.000) nằm cùng chỗ với Giá bán đã thu của Dòng xuất gốc.
        'revenue' => 500_000,
        'cogs' => 100_000,
        'grossProfit' => 400_000,
    ])
        // Steam không có doanh thu riêng, cũng không thành dòng 'Chưa có Giá bán': Dòng xuất loại Giao
        // thay không phải là dòng quên ghi Giá bán.
        ->and(array_keys(profitRows($rows)))->toBe(['NETFLIX-1M', 'Tổng']);
});

it("Dòng xuất chưa ghi Giá bán đứng ngoài doanh thu, gom vào dòng 'Chưa có Giá bán'", function () {
    profitSell($this->shopee, 'SP-1', $this->netflix, 1, 500_000);
    profitSell($this->zalo, 'SP-2', $this->netflix, 2);

    $rows = $this->report->rows($this->admin, septemberProfit());
    $unpriced = $rows[array_key_last($rows)];

    expect(profitRows($rows)['NETFLIX-1M'])->toMatchArray([
        'revenue' => 500_000,
        'cogs' => 30_000,
    ])
        ->and($unpriced->cell('code'))->toBe('Chưa có Giá bán')
        ->and($unpriced->cell('name'))->toBe('2 Slot')
        ->and($unpriced->cell('cogs'))->toBe(60_000)
        // Chưa biết thu về bao nhiêu thì không có doanh thu, Lãi gộp hay % biên để mà hiện.
        ->and($unpriced->cell('revenue'))->toBe('')
        ->and($unpriced->cell('gross_profit'))->toBe('')
        ->and($unpriced->cell('margin'))->toBe('');
});

it('dòng tổng cộng từng khoản rồi mới tính lại Lãi gộp và % biên', function () {
    profitSell($this->shopee, 'SP-1', $this->netflix, 1, 500_000);
    profitSell($this->zalo, 'SP-2', $this->steam, 1, 200_000);

    expect(profitRows($this->report->rows($this->admin, septemberProfit()))['Tổng'])->toBe([
        'revenue' => 700_000,
        'cogs' => 130_000,
        'grossProfit' => 570_000,
        // 570.000 / 700.000, không phải trung bình % biên của hai Sản phẩm.
        'margin' => '81,4%',
    ]);
});

it('sửa Giá bán làm đổi luôn con số của kỳ đã qua', function () {
    $dispatch = profitSell($this->shopee, 'SP-1', $this->netflix, 1, 500_000);

    expect(profitRows($this->report->rows($this->admin, septemberProfit()))['NETFLIX-1M']['revenue'])->toBe(500_000);

    app(DispatchEditor::class)->edit($this->admin, $dispatch, new DispatchEdit(
        $dispatch->external_ref,
        $dispatch->customer,
        $dispatch->note,
        salePrices: [$dispatch->lines()->sole()->id => 300_000],
    ));

    expect(profitRows($this->report->rows($this->admin, septemberProfit()))['NETFLIX-1M'])->toMatchArray([
        'revenue' => 300_000,
        'grossProfit' => 270_000,
    ]);
});

it('lọc Nhà cung cấp áp cả hai tầng, doanh thu Dòng xuất chia đều theo Slot', function () {
    profitImportG2A();
    // Thứ tự xuất lấy 3 Slot G2A (có hạn) trước, rồi 1 Slot Kinguin.
    profitSell($this->shopee, 'SP-1', $this->netflix, 4, 800_000);
    app(StockVoid::class)->voidUnit($this->admin, $this->b, VoidReason::DiscontinuedLot, 'Ngừng kinh doanh lô');

    $ofSupplier = fn (int $supplierId): ProfitReportFilter => new ProfitReportFilter(
        CarbonImmutable::parse('2026-09-01'),
        CarbonImmutable::parse('2026-09-30'),
        supplierId: $supplierId,
    );

    expect(profitRows($this->report->rows($this->admin, septemberProfit()))['NETFLIX-1M'])->toMatchArray([
        'revenue' => 800_000,
        'cogs' => 90_000,
    ])
        ->and(profitRows($this->report->rows($this->admin, $ofSupplier($this->g2a->id)))['NETFLIX-1M'])->toMatchArray([
            // 3 trong 4 Slot của Dòng xuất là hàng G2A.
            'revenue' => 600_000,
            'cogs' => 60_000,
        ])
        ->and(profitRows($this->report->rows($this->admin, $ofSupplier($this->kinguin->id)))['NETFLIX-1M'])->toMatchArray([
            'revenue' => 200_000,
            'cogs' => 30_000,
        ])
        // Tầng Điều chỉnh cũng chỉ còn hàng của Nhà cung cấp đang lọc.
        ->and(profitAdjustments($this->report->rows($this->admin, $ofSupplier($this->kinguin->id)))['NETFLIX-1M']['discontinuedLotLoss'])->toBe(90_000)
        ->and(profitAdjustments($this->report->rows($this->admin, $ofSupplier($this->g2a->id)))['NETFLIX-1M']['discontinuedLotLoss'])->toBe(0);
});

it('lọc Kênh bán chỉ áp Lãi gộp và ẩn Điều chỉnh, Lãi ròng kho', function () {
    profitSell($this->shopee, 'SP-1', $this->netflix, 1, 500_000);
    profitSell($this->zalo, 'SP-2', $this->netflix, 1, 400_000);
    app(StockVoid::class)->voidUnit($this->admin, $this->b, VoidReason::ContentExposed, 'Nhân viên làm lộ nội dung');

    $filter = new ProfitReportFilter(
        CarbonImmutable::parse('2026-09-01'),
        CarbonImmutable::parse('2026-09-30'),
        salesChannelId: $this->shopee->id,
    );

    expect(profitRows($this->report->rows($this->admin, $filter))['NETFLIX-1M'])->toMatchArray([
        'revenue' => 500_000,
        'cogs' => 30_000,
    ])
        // Hàng lỗi, huỷ, hết hạn không đi qua Kênh bán nào: hiện lên là bịa ra một con số.
        ->and(array_keys($this->report->columns($filter)))
        ->not->toContain('defective_loss', 'content_exposed_loss', 'adjustments', 'net_profit')
        ->and(array_keys($this->report->columns(septemberProfit())))->toContain('adjustments', 'net_profit');
});

it('chỉ Quản trị xem được báo cáo Lãi/lỗ', function () {
    expect($this->report->canView($this->admin))->toBeTrue()
        ->and($this->report->canView($this->stocker))->toBeFalse()
        ->and($this->report->canView($this->seller))->toBeFalse()
        ->and(fn () => $this->report->rows($this->stocker, septemberProfit()))->toThrow(MissingRole::class)
        ->and(fn () => $this->report->rows($this->seller, septemberProfit()))->toThrow(MissingRole::class);
});

it('xuất CSV gồm dòng tổng, không ghi Nhật ký xem mã hay Nhật ký bảo mật', function () {
    profitSell($this->shopee, 'SP-1', $this->netflix, 1, 500_000);

    $csv = array_map(
        fn (string $line) => str_getcsv($line, ',', '"', ''),
        explode("\n", trim(substr($this->report->export($this->admin, septemberProfit(), ReportFormat::Csv)->contents, 3))),
    );

    expect($csv[0])->toBe([
        'Mã sản phẩm', 'Sản phẩm', 'Doanh thu', 'Giá vốn hàng bán', 'Lãi gộp', '% biên',
        'Chi phí đổi hàng', 'Tổn thất hàng Lỗi', 'Tổn thất giao nhầm', 'Tổn thất lộ nội dung',
        'Tổn thất ngừng kinh doanh lô', 'Tổn thất hết hạn', 'Bồi hoàn tiền', 'Điều chỉnh', 'Lãi ròng kho',
    ])
        ->and($csv[1])->toBe(['NETFLIX-1M', 'Netflix 1 tháng', '500000', '30000', '470000', '94,0%', '0', '0', '0', '0', '0', '0', '0', '0', '470000'])
        ->and($csv[2][0])->toBe('Tổng')
        ->and($this->report->export($this->admin, septemberProfit(), ReportFormat::Xlsx)->fileName)
        ->toBe('bao-cao-lai-lo-2026-09-01-2026-09-30.xlsx');
});

it('Đổi hàng sang Sản phẩm khác tính Chi phí đổi hàng cho Sản phẩm của Đơn vị hàng lỗi', function () {
    profitSell($this->shopee, 'SP-1', $this->netflix, 1, 500_000);

    $reporting = app(DefectReporting::class);
    $report = $reporting->report($this->seller, [Delivery::sole()], new DefectReportDraft('Không đăng nhập được'))[0];
    $reporting->confirm($this->admin, $report, DefectScope::Slot, 'Đúng là lỗi');
    // Slot thay thế là mã Steam, Giá vốn 100.000.
    app(ReplacementDelivery::class)->replace($this->seller, $report->fresh(), new ReplacementDraft(
        $this->steam,
        productChangeReason: 'Hết hàng Netflix, khách đồng ý đổi sang Steam',
    ));

    $adjustments = profitAdjustments($this->report->rows($this->admin, septemberProfit()));

    // Chi phí đổi hàng đè lên Netflix (Đơn vị hàng lỗi), không phải Steam (Slot giao ra).
    expect($adjustments['NETFLIX-1M']['replacementCost'])->toBe(100_000)
        ->and($adjustments)->not->toHaveKey('STEAM-100K');
});

it("lọc Sản phẩm áp cả dòng 'Chưa có Giá bán', không chỉ các dòng Sản phẩm", function () {
    profitSell($this->shopee, 'SP-1', $this->netflix, 1);
    profitSell($this->zalo, 'SP-2', $this->steam, 1);

    $onlySteam = new ProfitReportFilter(
        CarbonImmutable::parse('2026-09-01'),
        CarbonImmutable::parse('2026-09-30'),
        productIds: [$this->steam->id],
    );

    $all = $this->report->rows($this->admin, septemberProfit());
    $steam = $this->report->rows($this->admin, $onlySteam);

    // Không lọc: 1 Slot Netflix + 1 Slot Steam. Lọc Steam: chỉ còn Slot Steam, không cộng cả kho.
    expect($all[array_key_last($all)]->cell('cogs'))->toBe(130_000)
        ->and($all[array_key_last($all)]->cell('name'))->toBe('2 Slot')
        ->and($steam[array_key_last($steam)]->cell('cogs'))->toBe(100_000)
        ->and($steam[array_key_last($steam)]->cell('name'))->toBe('1 Slot');
});

it('hàng Huỷ nhập bị loại khỏi bồi hoàn tiền, như chưa từng vào kho', function () {
    app(StockDefect::class)->markDefective($this->admin, $this->b, 'Nhà cung cấp thu hồi');

    $claims = app(SupplierClaims::class);
    $claim = $claims->create($this->stocker, $this->kinguin, [$this->b]);
    $claims->send($this->stocker, $claim);
    $claims->resolve($this->stocker, $claim, [
        $claim->claimUnits()->sole()->id => ClaimOutcomeDraft::refund(70_000, CarbonImmutable::parse('2026-09-10')),
    ]);

    expect(profitAdjustments($this->report->rows($this->admin, septemberProfit()))['NETFLIX-1M']['reimbursement'])->toBe(70_000);

    app(StockDefect::class)->restore($this->admin, $this->b->fresh(), 'Trả lại nhà cung cấp, huỷ nhập');
    app(ImportReversal::class)->reverse(
        $this->admin,
        $this->kinguinBatch->lines()->where('product_id', $this->netflix->id)->sole(),
    );

    $adjustments = profitAdjustments($this->report->rows($this->admin, septemberProfit()));

    expect($adjustments)->not->toHaveKey('NETFLIX-1M');
});
