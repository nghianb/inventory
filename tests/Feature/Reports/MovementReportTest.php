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
use App\Inventory\Dispatch\DispatchLineDraft;
use App\Inventory\Dispatch\ManualDispatch;
use App\Inventory\Dispatch\SalesChannelDirectory;
use App\Inventory\Dispatch\SalesChannelDraft;
use App\Inventory\Encryption\KeyFingerprints;
use App\Inventory\Intake\BatchDraft;
use App\Inventory\Intake\BatchIntake;
use App\Inventory\Intake\BatchLineDraft;
use App\Inventory\Intake\ImportReversal;
use App\Inventory\Reports\MovementReport;
use App\Inventory\Reports\MovementReportFilter;
use App\Inventory\Reports\MovementReportRow;
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
use App\Models\RevealLogEntry;
use App\Models\SalesChannel;
use App\Models\SecurityLogEntry;
use App\Models\StockUnit;
use App\Models\Supplier;
use App\Models\SupplierClaim;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Reader\XLSX\Reader;

beforeEach(function () {
    Storage::fake('intake');
    $this->seed(RoleSeeder::class);
    app(KeyFingerprints::class)->register();

    $this->admin = staffMember(Role::Owner);
    $this->stocker = staffMember(Role::NhapKho);
    $this->seller = staffMember(Role::BanHang);
    $this->report = app(MovementReport::class);

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

    // Tháng 8, Kinguin: 2 Tài khoản Netflix (3 Slot, Giá vốn Slot 30.000) và 1 mã Steam 100.000.
    $this->travelTo(CarbonImmutable::parse('2026-08-10 09:00'));
    $this->augustBatch = movementImport($this->kinguin, [
        new BatchLineDraft($this->netflix, 90_000, "a@shop.test\tpw-a\nb@shop.test\tpw-b"),
        new BatchLineDraft($this->steam, 100_000, 'STEAM-1'),
    ]);

    // Tháng 9, G2A: 1 Tài khoản Netflix, Giá vốn Slot 20.000.
    $this->travelTo(CarbonImmutable::parse('2026-09-05 09:00'));
    movementImport($this->g2a, [new BatchLineDraft($this->netflix, 60_000, "g@shop.test\tpw-g")]);

    $this->travelTo(CarbonImmutable::parse('2026-09-15 10:00'));
    $unit = fn (string $username): StockUnit => StockUnit::where('content->username', $username)->sole();
    [$this->a, $this->b, $this->g] = [$unit('a@shop.test'), $unit('b@shop.test'), $unit('g@shop.test')];
});

/**
 * @param  list<BatchLineDraft>  $lines
 */
function movementImport(Supplier $supplier, array $lines, ?SupplierClaim $claim = null): Batch
{
    $intake = app(BatchIntake::class);

    return $intake->confirm(test()->admin, $intake->submit(test()->admin, new BatchDraft(
        supplier: $supplier,
        receivedOn: CarbonImmutable::today(),
        lines: $lines,
        supplierClaim: $claim,
    )));
}

function movementSell(SalesChannel $channel, string $ref, Product $product, int $quantity, ?int $salePrice = null): Dispatch
{
    return app(ManualDispatch::class)->create(test()->seller, new DispatchDraft($channel, $ref, [new DispatchLineDraft($product, $quantity, $salePrice)]));
}

function augustRange(): MovementReportFilter
{
    return new MovementReportFilter(CarbonImmutable::parse('2026-08-01'), CarbonImmutable::parse('2026-08-31'));
}

function septemberRange(): MovementReportFilter
{
    return new MovementReportFilter(CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-30'));
}

/**
 * @param  list<MovementReportRow>  $rows
 * @return array<string, array<string, int>> các dòng báo cáo theo Mã sản phẩm
 */
function movementRows(array $rows): array
{
    return collect($rows)->mapWithKeys(fn (MovementReportRow $row) => [$row->code => [
        'inSlots' => $row->inSlots,
        'inReplacementSlots' => $row->inReplacementSlots,
        'inCost' => $row->inCost,
        'soldSlots' => $row->soldSlots,
        'replacementSlots' => $row->replacementSlots,
        'correctiveSlots' => $row->correctiveSlots,
        'outCost' => $row->outCost,
        'saleTotal' => $row->saleTotal,
        'voidedSlots' => $row->voidedSlots,
        'defectiveSlots' => $row->defectiveSlots,
    ]])->all();
}

it('mỗi Sản phẩm một dòng, Nhập đếm Slot theo ngày xác nhận Lô nhập kèm tổng Giá vốn', function () {
    expect(movementRows($this->report->rows($this->admin, augustRange())))->toBe([
        'NETFLIX-1M' => [
            'inSlots' => 6,
            'inReplacementSlots' => 0,
            'inCost' => 180_000,
            'soldSlots' => 0,
            'replacementSlots' => 0,
            'correctiveSlots' => 0,
            'outCost' => 0,
            'saleTotal' => 0,
            'voidedSlots' => 0,
            'defectiveSlots' => 0,
        ],
        'STEAM-100K' => [
            'inSlots' => 1,
            'inReplacementSlots' => 0,
            'inCost' => 100_000,
            'soldSlots' => 0,
            'replacementSlots' => 0,
            'correctiveSlots' => 0,
            'outCost' => 0,
            'saleTotal' => 0,
            'voidedSlots' => 0,
            'defectiveSlots' => 0,
        ],
    ]);
});

it('hàng thay thế từ Khiếu nại nhà cung cấp nằm trong cột Nhập và có cột riêng', function () {
    app(StockDefect::class)->markDefective($this->admin, $this->a, 'Nhà cung cấp thu hồi');
    $claims = app(SupplierClaims::class);
    $claim = $claims->create($this->stocker, $this->kinguin, [$this->a]);
    $claims->send($this->stocker, $claim);
    $claims->resolve($this->stocker, $claim, [$claim->claimUnits()->sole()->id => ClaimOutcomeDraft::replacementGoods()]);

    movementImport($this->kinguin, [new BatchLineDraft($this->netflix, 0, "r@shop.test\tpw-r")], $claim);

    expect(movementRows($this->report->rows($this->stocker, septemberRange()))['NETFLIX-1M'])->toMatchArray([
        // 3 Slot của G2A và 3 Slot hàng thay thế; hàng thay thế có Giá vốn 0.
        'inSlots' => 6,
        'inReplacementSlots' => 3,
        'inCost' => 60_000,
    ]);
});

it('Huỷ nhập làm số Nhập của kỳ cũ giảm đi, như hàng chưa từng vào kho', function () {
    expect(movementRows($this->report->rows($this->admin, augustRange()))['NETFLIX-1M']['inSlots'])->toBe(6);

    app(ImportReversal::class)->reverse($this->admin, $this->augustBatch->lines()->where('product_id', $this->netflix->id)->sole());

    // Cả hai Tài khoản Netflix của tháng 8 bị Huỷ nhập: Sản phẩm không còn biến động nào trong kỳ.
    $august = movementRows($this->report->rows($this->admin, augustRange()));
    expect($august)->not->toHaveKey('NETFLIX-1M')
        ->and($august['STEAM-100K']['inSlots'])->toBe(1);
});

it('Xuất tách Giao bán (gồm Giao thêm), Đổi hàng và Giao thay, kèm tổng Giá vốn xuất', function () {
    $dispatch = movementSell($this->shopee, 'SP-1', $this->netflix, 1, 500_000);
    $sold = Delivery::sole();

    // Giao thêm vào cùng phiếu: vẫn là Giao bán.
    app(ManualDispatch::class)->addLines($this->seller, $dispatch, [new DispatchLineDraft($this->netflix, 1, 400_000)]);
    $additional = Delivery::query()->latest('id')->first();

    // Giao thay lần Giao thêm: Huỷ hàng Slot giao nhầm rồi giao Slot của Đơn vị hàng khác.
    app(CorrectiveDelivery::class)->correct($this->seller, $additional, new CorrectionDraft(null, contentSent: false));

    // Đổi hàng cho lần Giao bán đầu: Báo lỗi Xác nhận chỉ Slot rồi giao Slot thay thế.
    $report = app(DefectReporting::class)->report($this->seller, [$sold], new DefectReportDraft('Không đăng nhập được'))[0];
    app(DefectReporting::class)->confirm($this->admin, $report, DefectScope::Slot, 'Đúng là lỗi');
    app(ReplacementDelivery::class)->replace($this->seller, $report->fresh(), new ReplacementDraft);

    expect(movementRows($this->report->rows($this->admin, septemberRange()))['NETFLIX-1M'])->toMatchArray([
        // Lần Giao thêm đã bị Giao thay nên không còn là hàng ra; chỉ còn lần Giao bán đầu.
        'soldSlots' => 1,
        'replacementSlots' => 1,
        'correctiveSlots' => 1,
        // 3 Slot rời kho, đều là hàng Kinguin Giá vốn Slot 30.000.
        'outCost' => 90_000,
        // Slot giao nhầm không biến mất khỏi báo cáo: nó nằm ở cột Huỷ hàng.
        'voidedSlots' => 1,
    ]);
});

it('tổng Giá bán theo Dòng xuất có lần Giao hàng đầu trong khoảng; Dòng xuất không ghi Giá bán không tính', function () {
    movementSell($this->shopee, 'SP-1', $this->netflix, 1, 500_000);
    movementSell($this->zalo, 'SP-2', $this->netflix, 2, 700_000);
    movementSell($this->shopee, 'SP-3', $this->netflix, 1);

    expect(movementRows($this->report->rows($this->admin, septemberRange()))['NETFLIX-1M'])->toMatchArray([
        'soldSlots' => 4,
        'saleTotal' => 1_200_000,
    ])
        // Kỳ trước lần giao: hàng đã nhập nhưng chưa bán được đồng nào.
        ->and(movementRows($this->report->rows($this->admin, augustRange()))['NETFLIX-1M'])->toMatchArray([
            'soldSlots' => 0,
            'saleTotal' => 0,
        ]);
});

it('Huỷ hàng và Chuyển Tồn lỗi đếm Slot rời vòng đời bán trong khoảng', function () {
    app(StockVoid::class)->voidUnit($this->admin, $this->b, VoidReason::DiscontinuedLot, 'Ngừng kinh doanh lô');
    app(StockDefect::class)->markDefective($this->admin, $this->g, 'Nhà cung cấp thu hồi');

    expect(movementRows($this->report->rows($this->admin, septemberRange()))['NETFLIX-1M'])->toMatchArray([
        'voidedSlots' => 3,
        'defectiveSlots' => 3,
    ])
        ->and(movementRows($this->report->rows($this->admin, augustRange()))['NETFLIX-1M'])->toMatchArray([
            'voidedSlots' => 0,
            'defectiveSlots' => 0,
        ]);
});

it('lọc Sản phẩm', function () {
    $filter = new MovementReportFilter(CarbonImmutable::parse('2026-08-01'), CarbonImmutable::parse('2026-09-30'), productIds: [$this->steam->id]);

    expect(array_keys(movementRows($this->report->rows($this->admin, $filter))))->toBe(['STEAM-100K']);
});

it('lọc Nhà cung cấp chỉ đếm hàng của họ và ẩn cột Giá bán', function () {
    // Thứ tự xuất lấy hàng nhập trước, nên Slot giao ra là hàng Kinguin.
    movementSell($this->shopee, 'SP-1', $this->netflix, 1, 500_000);
    $filter = new MovementReportFilter(CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-30'), supplierId: $this->g2a->id);

    expect(movementRows($this->report->rows($this->stocker, $filter))['NETFLIX-1M'])->toMatchArray([
        'inSlots' => 3,
        'inCost' => 60_000,
        'soldSlots' => 0,
        'outCost' => 0,
    ])
        // Giá bán là của cả Dòng xuất, có thể gồm hàng của nhiều Nhà cung cấp: không chia được.
        ->and(array_keys($this->report->columns($this->stocker, $filter)))->not->toContain('sale_total')
        ->and(array_keys($this->report->columns($this->stocker, septemberRange())))->toContain('sale_total');
});

it('lọc Kênh bán chỉ còn phần Xuất, vì Nhập, Huỷ hàng và Chuyển Tồn lỗi không đi qua Kênh bán nào', function () {
    movementSell($this->shopee, 'SP-1', $this->netflix, 1, 500_000);
    movementSell($this->zalo, 'SP-2', $this->netflix, 2, 700_000);
    $filter = new MovementReportFilter(CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-30'), salesChannelId: $this->shopee->id);

    expect(movementRows($this->report->rows($this->admin, $filter))['NETFLIX-1M'])->toMatchArray([
        'soldSlots' => 1,
        'saleTotal' => 500_000,
        'outCost' => 30_000,
    ])
        ->and(array_keys($this->report->columns($this->admin, $filter)))
        ->not->toContain('in_slots', 'in_replacement_slots', 'in_cost', 'voided_slots', 'defective_slots');
});

it('cả ba vai trò xem được; Bán hàng chỉ thấy số lượng, không Giá vốn, Giá bán hay Nhà cung cấp', function () {
    $valueColumns = ['in_cost', 'out_cost', 'sale_total'];

    foreach ([$this->admin, $this->stocker] as $viewer) {
        expect($this->report->canView($viewer))->toBeTrue()
            ->and($this->report->seesValues($viewer))->toBeTrue()
            ->and(array_keys($this->report->columns($viewer, septemberRange())))->toContain(...$valueColumns);
    }

    expect($this->report->canView($this->seller))->toBeTrue()
        ->and($this->report->seesValues($this->seller))->toBeFalse()
        ->and(array_keys($this->report->columns($this->seller, septemberRange())))->not->toContain(...$valueColumns)
        ->and($this->report->rows($this->seller, septemberRange()))->toHaveCount(1)
        ->and(fn () => $this->report->rows($this->seller, new MovementReportFilter(
            CarbonImmutable::parse('2026-09-01'),
            CarbonImmutable::parse('2026-09-30'),
            supplierId: $this->g2a->id,
        )))->toThrow(MissingRole::class);

    $this->seller->forceFill(['deactivated_at' => now()])->save();
    expect(fn () => $this->report->rows($this->seller, septemberRange()))->toThrow(MissingRole::class);
});

it('xuất CSV áp cột theo vai trò, không ghi Nhật ký xem mã hay Nhật ký bảo mật', function () {
    movementSell($this->shopee, 'SP-1', $this->netflix, 1, 500_000);
    $reveals = RevealLogEntry::count();
    $security = SecurityLogEntry::count();

    $csv = fn ($viewer) => array_map(
        fn (string $line) => str_getcsv($line, ',', '"', ''),
        explode("\n", trim(substr($this->report->export($viewer, septemberRange(), ReportFormat::Csv)->contents, 3))),
    );

    $admin = $csv($this->admin);
    $seller = $csv($this->seller);

    expect($admin[0])->toBe([
        'Mã sản phẩm', 'Sản phẩm', 'Nhập', 'Trong đó hàng thay thế', 'Giá vốn nhập', 'Giao bán',
        'Đổi hàng', 'Giao thay', 'Giá vốn xuất', 'Giá bán', 'Huỷ hàng', 'Chuyển Tồn lỗi',
    ])
        ->and($admin[1])->toBe(['NETFLIX-1M', 'Netflix 1 tháng', '3', '0', '60000', '1', '0', '0', '30000', '500000', '0', '0'])
        ->and($seller[0])->not->toContain('Giá vốn nhập', 'Giá vốn xuất', 'Giá bán')
        ->and($seller[1])->toBe(['NETFLIX-1M', 'Netflix 1 tháng', '3', '0', '1', '0', '0', '0', '0'])
        ->and(RevealLogEntry::count())->toBe($reveals)
        ->and(SecurityLogEntry::count())->toBe($security);
});

it('xuất XLSX cùng cột với CSV, tên file theo khoảng ngày', function () {
    $export = $this->report->export($this->seller, septemberRange(), ReportFormat::Xlsx);
    $path = tempnam(sys_get_temp_dir(), 'movement-report');
    file_put_contents($path, $export->contents);

    $reader = new Reader;
    $reader->open($path);
    $rows = [];
    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = $row->toArray();
        }
    }
    $reader->close();
    unlink($path);

    expect($export->fileName)->toBe('bao-cao-nhap-xuat-2026-09-01-2026-09-30.xlsx')
        ->and($rows[0])->toBe(array_values($this->report->columns($this->seller, septemberRange())))
        ->and($rows[1])->toBe(['NETFLIX-1M', 'Netflix 1 tháng', 3, 0, 0, 0, 0, 0, 0]);
});

it('chỉ Sản phẩm có biến động trong khoảng mới có dòng', function () {
    // Tháng 9 chỉ có Lô nhập G2A của Netflix: Steam không có dòng nào.
    expect(movementRows($this->report->rows($this->admin, septemberRange())))->toHaveKeys(['NETFLIX-1M'])
        ->and(movementRows($this->report->rows($this->admin, septemberRange())))->not->toHaveKey('STEAM-100K')
        ->and($this->report->rows($this->admin, septemberRange())[0]->inSlots)->toBe(3)
        ->and($this->report->rows($this->admin, septemberRange())[0]->inCost)->toBe(60_000);
});
