<?php

use App\Inventory\Access\MissingRole;
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
use App\Inventory\Intake\ExpiryRule;
use App\Inventory\Reports\ReportFormat;
use App\Inventory\Reports\StockReport;
use App\Inventory\Reports\StockReportFilter;
use App\Inventory\Reports\StockReportRow;
use App\Inventory\Stock\SellableStock;
use App\Inventory\Stock\SlotStatus;
use App\Inventory\Stock\StockDefect;
use App\Inventory\Warranty\DefectReportDraft;
use App\Inventory\Warranty\DefectReporting;
use App\Models\Delivery;
use App\Models\RevealLogEntry;
use App\Models\SecurityLogEntry;
use App\Models\Slot;
use App\Models\StockUnit;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Reader\XLSX\Reader;

beforeEach(function () {
    Storage::fake('intake');
    $this->seed(RoleSeeder::class);
    app(KeyFingerprints::class)->register();
    $this->travelTo(CarbonImmutable::parse('2026-09-15 10:00'));

    $this->admin = staffMember(Role::Owner);
    $this->stocker = staffMember(Role::NhapKho);
    $this->seller = staffMember(Role::BanHang);
    $this->report = app(StockReport::class);

    $catalog = app(ProductCatalog::class);
    $netflix = fn (?int $lowStockThreshold) => new ProductDraft(
        type: ProductType::Account,
        name: 'Netflix 1 tháng',
        code: 'NETFLIX-1M',
        fields: [
            new ContentFieldDraft('username', 'Tên đăng nhập', ContentFieldType::Email, sensitive: false, dedupeKey: true),
            new ContentFieldDraft('password', 'Mật khẩu'),
        ],
        defaultSlots: 3,
        warrantyDays: 30,
        minRemainingDays: 5,
        lowStockThreshold: $lowStockThreshold,
    );
    $this->netflix = $catalog->create($this->admin, $netflix(5));
    $this->setNetflixThreshold = fn (?int $threshold) => $catalog->update($this->admin, $this->netflix->fresh(), $netflix($threshold));
    $this->steam = $catalog->create($this->admin, new ProductDraft(
        type: ProductType::OneTimeCode,
        name: 'Steam 100K',
        code: 'STEAM-100K',
        fields: [new ContentFieldDraft('code', 'Mã thẻ', dedupeKey: true)],
    ));

    $directory = app(SupplierDirectory::class);
    $this->kinguin = $directory->create($this->admin, 'Kinguin');
    $this->g2a = $directory->create($this->admin, 'G2A');

    $intake = app(BatchIntake::class);
    $import = fn ($supplier, array $lines) => $intake->confirm($this->admin, $intake->submit($this->admin, new BatchDraft(
        supplier: $supplier,
        receivedOn: CarbonImmutable::today(),
        lines: $lines,
    )));
    $today = CarbonImmutable::today();
    // Netflix Kinguin: Giá vốn Slot 30.000. a, d không có hạn; b còn 3 ngày (không đạt Hạn còn lại tối thiểu 5).
    $import($this->kinguin, [
        new BatchLineDraft($this->netflix, 90_000, "a@shop.test\tpw-a\nd@shop.test\tpw-d"),
        new BatchLineDraft($this->steam, 100_000, 'STEAM-1'),
    ]);
    $import($this->kinguin, [new BatchLineDraft($this->netflix, 90_000, "b@shop.test\tpw-b", expiry: ExpiryRule::on($today->addDays(3)))]);
    // Netflix G2A: Giá vốn Slot 20.000, còn 6 ngày.
    $import($this->g2a, [new BatchLineDraft($this->netflix, 60_000, "g@shop.test\tpw-g", expiry: ExpiryRule::on($today->addDays(6)))]);

    $unit = fn (string $username): StockUnit => StockUnit::where('content->username', $username)->sole();
    [$this->a, $this->b, $this->d, $this->g] = [$unit('a@shop.test'), $unit('b@shop.test'), $unit('d@shop.test'), $unit('g@shop.test')];

    // Thứ tự xuất: hạn gần nhất trước, b không đạt Hạn còn lại tối thiểu, nên giao Slot đầu của g.
    app(ManualDispatch::class)->create($this->seller, new DispatchDraft(
        app(SalesChannelDirectory::class)->create($this->admin, new SalesChannelDraft('Zalo')),
        null,
        [new DispatchLineDraft($this->netflix, 1)],
    ));
    $delivery = Delivery::sole();
    expect($delivery->stock_unit_id)->toBe($this->g->id);

    // g: Báo lỗi Chờ xác minh làm hai Slot còn lại tạm ngừng.
    app(DefectReporting::class)->report($this->seller, [$delivery], new DefectReportDraft('Không đăng nhập được'));
    // d: Lỗi, ba Slot thành Tồn lỗi.
    app(StockDefect::class)->markDefective($this->admin, $this->d, 'Nhà cung cấp thu hồi');
    // a: một Slot Đã giữ. Kho chưa có thao tác Giữ hàng riêng (API xuất kho), nên đặt thẳng trạng thái.
    Slot::whereKey($this->a->slots->first()->id)->update(['status' => SlotStatus::Reserved]);
});

/**
 * @return array<string, array<string, mixed>> các dòng báo cáo theo Mã sản phẩm
 */
function stockRows(array $rows): array
{
    return collect($rows)->mapWithKeys(fn (StockReportRow $row) => [$row->code => [
        'sellable' => $row->sellable,
        'reserved' => $row->reserved,
        'paused' => $row->paused,
        'belowMinRemaining' => $row->belowMinRemaining,
        'defective' => $row->defective,
        'discontinuedSlots' => $row->discontinuedSlots,
        'stockUnits' => $row->stockUnits,
        'stockValue' => $row->stockValue,
        'defectiveValue' => $row->defectiveValue,
        'expiringSlots' => $row->expiringSlots,
        'expiringCost' => $row->expiringCost,
        'lowStock' => $row->lowStock,
    ]])->all();
}

it('mỗi Sản phẩm một dòng, đếm theo Slot từng cột, kèm số Đơn vị hàng và giá trị tồn', function () {
    expect(stockRows($this->report->rows($this->admin, new StockReportFilter)))->toBe([
        'NETFLIX-1M' => [
            'sellable' => 2,
            'reserved' => 1,
            'paused' => 2,
            'belowMinRemaining' => 3,
            'defective' => 3,
            'discontinuedSlots' => 0,
            'stockUnits' => 4,
            // a 3 Slot, b 3 × 30.000; g 2 × 20.000. Tồn lỗi (d) tách sang defectiveValue.
            'stockValue' => 220_000,
            'defectiveValue' => 90_000,
            // b 3 Slot hết hạn trong 3 ngày, g 2 Slot trong 6 ngày; Tồn lỗi đã tính Tổn thất nên không vào.
            'expiringSlots' => 5,
            'expiringCost' => 130_000,
            'lowStock' => true,
        ],
        'STEAM-100K' => [
            'sellable' => 1,
            'reserved' => 0,
            'paused' => 0,
            'belowMinRemaining' => 0,
            'defective' => 0,
            'discontinuedSlots' => 0,
            'stockUnits' => 1,
            'stockValue' => 100_000,
            'defectiveValue' => 0,
            'expiringSlots' => 0,
            'expiringCost' => 0,
            'lowStock' => false,
        ],
    ]);
});

it('Tồn bán được dùng chung định nghĩa với form xuất kho', function () {
    $rows = collect($this->report->rows($this->admin, new StockReportFilter))->keyBy('productId');
    $stock = app(SellableStock::class);

    foreach ([$this->netflix, $this->steam] as $product) {
        expect($rows[$product->id]->sellable)->toBe($stock->count($product));
    }
});

it('Slot quá Hạn sử dụng không còn là tồn; Hết hạn trong N ngày theo N chọn khi xem', function () {
    $this->travel(4)->days();

    // b đã quá hạn; g còn 2 ngày: không đạt Hạn còn lại tối thiểu nhưng đang tạm ngừng.
    expect(stockRows($this->report->rows($this->admin, new StockReportFilter(expiringWithinDays: 1)))['NETFLIX-1M'])->toMatchArray([
        'sellable' => 2,
        'paused' => 2,
        'belowMinRemaining' => 0,
        'stockUnits' => 3,
        // a 3 × 30.000, g 2 × 20.000; Tồn lỗi (d) không vào giá trị tồn.
        'stockValue' => 130_000,
        'defectiveValue' => 90_000,
        'expiringSlots' => 0,
        'expiringCost' => 0,
    ])
        ->and(stockRows($this->report->rows($this->admin, new StockReportFilter(expiringWithinDays: 2)))['NETFLIX-1M'])->toMatchArray([
            'expiringSlots' => 2,
            'expiringCost' => 40_000,
        ]);
});

it('bộ lọc sắp hết: Tồn bán được không vượt Ngưỡng sắp hết; không cảnh báo khi ngưỡng trống hoặc Sản phẩm Ngừng bán', function () {
    $lowOnly = new StockReportFilter(lowStockOnly: true);
    $codes = fn () => array_keys(stockRows($this->report->rows($this->admin, $lowOnly)));

    expect($codes())->toBe(['NETFLIX-1M']);

    // Ngưỡng bằng đúng Tồn bán được vẫn cảnh báo.
    ($this->setNetflixThreshold)(2);
    expect($codes())->toBe(['NETFLIX-1M']);

    ($this->setNetflixThreshold)(1);
    expect($codes())->toBe([]);

    ($this->setNetflixThreshold)(5);
    app(ProductCatalog::class)->discontinue($this->admin, $this->netflix->fresh());
    expect($codes())->toBe([]);
});

it('bộ lọc Hết hạn trong N ngày và Sản phẩm', function () {
    expect(array_keys(stockRows($this->report->rows($this->admin, new StockReportFilter(expiringOnly: true)))))->toBe(['NETFLIX-1M'])
        ->and(array_keys(stockRows($this->report->rows($this->admin, new StockReportFilter(expiringWithinDays: 2, expiringOnly: true)))))->toBe([])
        ->and(array_keys(stockRows($this->report->rows($this->admin, new StockReportFilter(productIds: [$this->steam->id])))))->toBe(['STEAM-100K']);
});

it('lọc theo Nhà cung cấp chỉ đếm hàng của Nhà cung cấp đó', function () {
    expect(stockRows($this->report->rows($this->stocker, new StockReportFilter(supplierId: $this->g2a->id))))->toBe([
        'NETFLIX-1M' => [
            'sellable' => 0,
            'reserved' => 0,
            'paused' => 2,
            'belowMinRemaining' => 0,
            'defective' => 0,
            'discontinuedSlots' => 0,
            'stockUnits' => 1,
            'stockValue' => 40_000,
            'defectiveValue' => 0,
            'expiringSlots' => 2,
            'expiringCost' => 40_000,
            'lowStock' => true,
        ],
    ]);

    // Sắp hết xét Tồn bán được của cả Sản phẩm (2), không phải của riêng G2A (0).
    ($this->setNetflixThreshold)(1);
    expect($this->report->rows($this->stocker, new StockReportFilter(supplierId: $this->g2a->id))[0]->lowStock)->toBeFalse()
        ->and($this->report->rows($this->stocker, new StockReportFilter(supplierId: $this->g2a->id, lowStockOnly: true)))->toBe([]);
});

it('Sản phẩm Ngừng bán không có Tồn bán được; Slot còn giao được của nó vào cột Ngừng bán còn lại', function () {
    app(ProductCatalog::class)->discontinue($this->admin, $this->steam);

    expect(stockRows($this->report->rows($this->admin, new StockReportFilter(productIds: [$this->steam->id]))))->toBe([
        'STEAM-100K' => [
            'sellable' => 0,
            'reserved' => 0,
            'paused' => 0,
            'belowMinRemaining' => 0,
            'defective' => 0,
            'discontinuedSlots' => 1,
            'stockUnits' => 1,
            'stockValue' => 100_000,
            'defectiveValue' => 0,
            'expiringSlots' => 0,
            'expiringCost' => 0,
            'lowStock' => false,
        ],
    ]);
});

it('cảnh báo tồn kho gồm Sản phẩm sắp hết hoặc có hàng hết hạn trong N ngày', function () {
    $steamLow = app(ProductCatalog::class)->create($this->admin, new ProductDraft(
        type: ProductType::OneTimeCode,
        name: 'Steam 200K',
        code: 'STEAM-200K',
        fields: [new ContentFieldDraft('code', 'Mã thẻ', dedupeKey: true)],
        lowStockThreshold: 0,
    ));

    $alerts = fn (int $days) => array_keys(stockRows($this->report->rows($this->seller, new StockReportFilter(expiringWithinDays: $days, alertsOnly: true))));

    expect($alerts(7))->toBe(['NETFLIX-1M', 'STEAM-200K']);

    ($this->setNetflixThreshold)(null);
    expect($alerts(7))->toBe(['NETFLIX-1M', 'STEAM-200K'])
        ->and($alerts(2))->toBe(['STEAM-200K']);
});

it('cả ba vai trò xem được; Bán hàng không thấy cột giá trị và không lọc theo Nhà cung cấp', function () {
    $valueColumns = ['stock_value', 'expiring_cost'];

    foreach ([$this->admin, $this->stocker] as $viewer) {
        expect($this->report->seesCost($viewer))->toBeTrue()
            ->and(array_keys($this->report->columns($viewer, new StockReportFilter)))->toContain(...$valueColumns);
    }

    expect($this->report->seesCost($this->seller))->toBeFalse()
        ->and(array_keys($this->report->columns($this->seller, new StockReportFilter)))->not->toContain(...$valueColumns)
        ->and($this->report->rows($this->seller, new StockReportFilter))->toHaveCount(2)
        ->and(fn () => $this->report->rows($this->seller, new StockReportFilter(supplierId: $this->g2a->id)))->toThrow(MissingRole::class);

    $this->seller->forceFill(['deactivated_at' => now()])->save();
    expect(fn () => $this->report->rows($this->seller, new StockReportFilter))->toThrow(MissingRole::class);
});

it('xuất CSV áp cột theo vai trò, không ghi Nhật ký xem mã hay Nhật ký bảo mật', function () {
    $reveals = RevealLogEntry::count();
    $security = SecurityLogEntry::count();

    $csv = fn ($viewer) => array_map(
        fn (string $line) => str_getcsv($line, ',', '"', ''),
        explode("\n", trim(substr($this->report->export($viewer, new StockReportFilter(expiringWithinDays: 3), ReportFormat::Csv)->contents, 3))),
    );

    $admin = $csv($this->admin);
    $seller = $csv($this->seller);

    expect($admin[0])->toBe([
        'Mã sản phẩm', 'Sản phẩm', 'Trạng thái', 'Tồn bán được', 'Ngưỡng sắp hết', 'Sắp hết', 'Đã giữ',
        'Tạm ngừng', 'Không đạt Hạn còn lại tối thiểu', 'Tồn lỗi', 'Ngừng bán còn lại', 'Đơn vị hàng',
        'Giá trị tồn', 'Giá vốn Tồn lỗi', 'Hết hạn trong 3 ngày', 'Giá vốn sắp mất',
    ])
        ->and($admin[1])->toBe(['NETFLIX-1M', 'Netflix 1 tháng', 'Đang bán', '2', '5', 'Có', '1', '2', '3', '3', '0', '4', '220000', '90000', '3', '90000'])
        ->and($seller[0])->not->toContain('Giá trị tồn', 'Giá vốn Tồn lỗi', 'Giá vốn sắp mất')
        ->and($seller[1])->toBe(['NETFLIX-1M', 'Netflix 1 tháng', 'Đang bán', '2', '5', 'Có', '1', '2', '3', '3', '0', '4', '3'])
        ->and(RevealLogEntry::count())->toBe($reveals)
        ->and(SecurityLogEntry::count())->toBe($security);
});

it('xuất XLSX cùng cột với CSV', function () {
    $export = $this->report->export($this->seller, new StockReportFilter, ReportFormat::Xlsx);
    $path = tempnam(sys_get_temp_dir(), 'stock-report');
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

    expect($export->fileName)->toBe('bao-cao-ton-kho-2026-09-15.xlsx')
        ->and($rows[0])->toBe(array_values($this->report->columns($this->seller, new StockReportFilter)))
        ->and($rows[2])->toBe(['STEAM-100K', 'Steam 100K', 'Đang bán', 1, '', 'Không', 0, 0, 0, 0, 0, 1, 0]);
});
