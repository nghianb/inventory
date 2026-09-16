<?php

use App\Inventory\Access\MissingRole;
use App\Inventory\Access\Role;
use App\Inventory\Catalog\ContentFieldDraft;
use App\Inventory\Catalog\ContentFieldType;
use App\Inventory\Catalog\ProductCatalog;
use App\Inventory\Catalog\ProductDraft;
use App\Inventory\Catalog\ProductType;
use App\Inventory\Catalog\SupplierDirectory;
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
use App\Inventory\Reports\DefectRateReport;
use App\Inventory\Reports\DefectRateReportFilter;
use App\Inventory\Reports\DefectRateReportRow;
use App\Inventory\Reports\ReportFormat;
use App\Inventory\Stock\StockDefect;
use App\Inventory\Warranty\DefectReportDraft;
use App\Inventory\Warranty\DefectReporting;
use App\Inventory\Warranty\DefectScope;
use App\Models\Batch;
use App\Models\Delivery;
use App\Models\Product;
use App\Models\RevealLogEntry;
use App\Models\SecurityLogEntry;
use App\Models\StockUnit;
use App\Models\Supplier;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Reader\XLSX\Reader;

beforeEach(function () {
    Storage::fake('intake');
    $this->seed(RoleSeeder::class);
    app(KeyFingerprints::class)->register();

    $this->admin = staffMember(Role::QuanTri);
    $this->stocker = staffMember(Role::NhapKho);
    $this->seller = staffMember(Role::BanHang);
    $this->report = app(DefectRateReport::class);

    $catalog = app(ProductCatalog::class);
    $this->netflix = $catalog->create($this->admin, new ProductDraft(
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
    $this->steam = $catalog->create($this->admin, new ProductDraft(
        type: ProductType::OneTimeCode,
        name: 'Steam 100K',
        code: 'STEAM-100K',
        fields: [new ContentFieldDraft('code', 'Mã thẻ', dedupeKey: true)],
        warrantyDays: 7,
    ));

    $directory = app(SupplierDirectory::class);
    $this->kinguin = $directory->create($this->admin, 'Kinguin');
    $this->g2a = $directory->create($this->admin, 'G2A');

    $this->shopee = app(SalesChannelDirectory::class)->create($this->admin, new SalesChannelDraft('Shopee'));

    // Lứa nhập tháng 9, Kinguin: 2 Tài khoản Netflix (3 Slot mỗi cái) và 1 mã Steam.
    $this->travelTo(CarbonImmutable::parse('2026-09-05 09:00'));
    $this->kinguinBatch = defectRateImport($this->kinguin, [
        new BatchLineDraft($this->netflix, 90_000, "a@shop.test\tpw-a\nb@shop.test\tpw-b"),
        new BatchLineDraft($this->steam, 100_000, 'STEAM-1'),
    ]);

    // Lứa nhập tháng 9, G2A: 1 Tài khoản Netflix.
    defectRateImport($this->g2a, [new BatchLineDraft($this->netflix, 60_000, "g@shop.test\tpw-g")]);

    $this->travelTo(CarbonImmutable::parse('2026-09-15 10:00'));
    $unit = fn (string $username): StockUnit => StockUnit::where('content->username', $username)->sole();
    [$this->a, $this->b, $this->g] = [$unit('a@shop.test'), $unit('b@shop.test'), $unit('g@shop.test')];
});

/**
 * @param  list<BatchLineDraft>  $lines
 */
function defectRateImport(Supplier $supplier, array $lines): Batch
{
    $intake = app(BatchIntake::class);

    return $intake->confirm(test()->admin, $intake->submit(test()->admin, new BatchDraft(
        supplier: $supplier,
        receivedOn: CarbonImmutable::today(),
        lines: $lines,
    )));
}

function defectRateSell(Product $product, int $quantity): Delivery
{
    app(ManualDispatch::class)->create(test()->seller, new DispatchDraft(
        test()->shopee,
        'SP-'.uniqid(),
        [new DispatchLineDraft($product, $quantity, 500_000)],
    ));

    return Delivery::query()->latest('id')->firstOrFail();
}

function septemberDefectRange(): DefectRateReportFilter
{
    return new DefectRateReportFilter(CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-30'));
}

/**
 * @param  list<DefectRateReportRow>  $rows
 * @return array<string, array<string, int|string|null>> dòng báo cáo theo "Nhà cung cấp · Mã sản phẩm"
 */
function defectRateRows(array $rows): array
{
    return collect($rows)->mapWithKeys(fn (DefectRateReportRow $row) => [
        $row->supplierName.' · '.($row->code ?? 'Tổng') => [
            'intakeUnits' => $row->intakeUnits,
            'deliveredUnits' => $row->deliveredUnits,
            'defectiveUnits' => $row->defectiveUnits,
            'defectiveInStockUnits' => $row->defectiveInStockUnits,
            'rejectedLines' => $row->rejectedLines,
            'rate' => $row->defectRate(),
        ],
    ])->all();
}

it('chia Nhà cung cấp × Sản phẩm và có dòng tổng theo Nhà cung cấp', function () {
    // 3 Slot của Tài khoản a, rồi 1 Slot của Tài khoản b: hai Đơn vị hàng đã giao.
    defectRateSell($this->netflix, 3);
    defectRateSell($this->netflix, 1);
    app(StockDefect::class)->markDefective($this->admin, $this->a, 'Nhà cung cấp thu hồi');

    expect(defectRateRows($this->report->rows($this->stocker, septemberDefectRange())))->toBe([
        // Tỉ lệ lỗi tính trên Đơn vị hàng, không phải Slot: a Lỗi trên 2 Đơn vị hàng đã giao.
        'G2A · NETFLIX-1M' => [
            'intakeUnits' => 1,
            'deliveredUnits' => 0,
            'defectiveUnits' => 0,
            'defectiveInStockUnits' => 0,
            'rejectedLines' => 0,
            'rate' => null,
        ],
        'G2A · Tổng' => [
            'intakeUnits' => 1,
            'deliveredUnits' => 0,
            'defectiveUnits' => 0,
            'defectiveInStockUnits' => 0,
            'rejectedLines' => 0,
            'rate' => null,
        ],
        'Kinguin · NETFLIX-1M' => [
            'intakeUnits' => 2,
            'deliveredUnits' => 2,
            'defectiveUnits' => 1,
            'defectiveInStockUnits' => 0,
            'rejectedLines' => 0,
            'rate' => 0.5,
        ],
        'Kinguin · STEAM-100K' => [
            'intakeUnits' => 1,
            'deliveredUnits' => 0,
            'defectiveUnits' => 0,
            'defectiveInStockUnits' => 0,
            'rejectedLines' => 0,
            'rate' => null,
        ],
        // Dòng tổng cộng số Đơn vị hàng của mọi Sản phẩm rồi mới chia, không phải trung bình các tỉ lệ.
        'Kinguin · Tổng' => [
            'intakeUnits' => 3,
            'deliveredUnits' => 2,
            'defectiveUnits' => 1,
            'defectiveInStockUnits' => 0,
            'rejectedLines' => 0,
            'rate' => 0.5,
        ],
    ]);
});

it('Khôi phục làm giảm tử số', function () {
    defectRateSell($this->netflix, 3);
    defectRateSell($this->netflix, 1);
    app(StockDefect::class)->markDefective($this->admin, $this->a, 'Nhà cung cấp thu hồi');

    expect(defectRateRows($this->report->rows($this->admin, septemberDefectRange()))['Kinguin · NETFLIX-1M'])
        ->toMatchArray(['deliveredUnits' => 2, 'defectiveUnits' => 1, 'rate' => 0.5]);

    app(StockDefect::class)->restore($this->admin, $this->a->fresh(), 'Nhà cung cấp sửa được hàng');

    // Mẫu số giữ nguyên: hàng vẫn đã giao, chỉ không còn Lỗi.
    expect(defectRateRows($this->report->rows($this->admin, septemberDefectRange()))['Kinguin · NETFLIX-1M'])
        ->toMatchArray(['deliveredUnits' => 2, 'defectiveUnits' => 0, 'rate' => 0.0]);
});

it('Báo lỗi chỉ Slot không tính, Báo lỗi cả Đơn vị hàng thì tính', function () {
    $first = defectRateSell($this->netflix, 1);
    defectRateSell($this->netflix, 2);
    $second = defectRateSell($this->netflix, 1);

    $reporting = app(DefectReporting::class);
    $onlySlot = $reporting->report($this->seller, [$first], new DefectReportDraft('Một profile bị phá'))[0];
    $reporting->confirm($this->admin, $onlySlot, DefectScope::Slot, 'Chỉ hỏng một profile');

    expect(defectRateRows($this->report->rows($this->admin, septemberDefectRange()))['Kinguin · NETFLIX-1M'])
        ->toMatchArray(['deliveredUnits' => 2, 'defectiveUnits' => 0, 'rate' => 0.0]);

    $wholeUnit = $reporting->report($this->seller, [$second], new DefectReportDraft('Không đăng nhập được'))[0];
    $reporting->confirm($this->admin, $wholeUnit, DefectScope::Unit, 'Tài khoản hỏng hẳn');

    expect(defectRateRows($this->report->rows($this->admin, septemberDefectRange()))['Kinguin · NETFLIX-1M'])
        ->toMatchArray(['deliveredUnits' => 2, 'defectiveUnits' => 1, 'rate' => 0.5]);
});

it('Giao thay không phải hàng lỗi, và lần giao bị Giao thay không tính là đã giao', function () {
    $wrong = defectRateSell($this->netflix, 1);
    app(CorrectiveDelivery::class)->correct($this->seller, $wrong, new CorrectionDraft(null, contentSent: false));

    // Tài khoản a chỉ có một lần giao và nó đã bị Giao thay: khách nhận Slot của Tài khoản b.
    expect(defectRateRows($this->report->rows($this->admin, septemberDefectRange()))['Kinguin · NETFLIX-1M'])
        ->toMatchArray(['intakeUnits' => 2, 'deliveredUnits' => 1, 'defectiveUnits' => 0, 'rate' => 0.0]);
});

it("'Lỗi trong kho' là Đơn vị hàng Lỗi chưa giao Slot nào, không vào Tỉ lệ lỗi", function () {
    defectRateSell($this->netflix, 3);
    app(StockDefect::class)->markDefective($this->admin, $this->a, 'Nhà cung cấp thu hồi');
    app(StockDefect::class)->markDefective($this->admin, $this->b, 'Hỏng trong kho');

    expect(defectRateRows($this->report->rows($this->admin, septemberDefectRange()))['Kinguin · NETFLIX-1M'])
        ->toMatchArray([
            'deliveredUnits' => 1,
            'defectiveUnits' => 1,
            'defectiveInStockUnits' => 1,
            'rate' => 1.0,
        ]);
});

it("cột 'chất lượng file nhập' đếm dòng lỗi và trùng, không gộp vào Tỉ lệ lỗi", function () {
    // Một dòng hợp lệ, một dòng trùng trong file, một dòng sai định dạng email.
    defectRateImport($this->kinguin, [
        new BatchLineDraft($this->netflix, 90_000, "q@shop.test\tpw-q\nq@shop.test\tpw-q2\nkhong-phai-email\tpw-x"),
    ]);
    defectRateSell($this->netflix, 3);
    app(StockDefect::class)->markDefective($this->admin, $this->a, 'Nhà cung cấp thu hồi');

    expect(defectRateRows($this->report->rows($this->stocker, septemberDefectRange()))['Kinguin · NETFLIX-1M'])
        ->toMatchArray([
            'intakeUnits' => 3,
            'deliveredUnits' => 1,
            'defectiveUnits' => 1,
            // Dòng bị bỏ không phải hàng lỗi: Tỉ lệ lỗi vẫn là 1/1.
            'rate' => 1.0,
            'rejectedLines' => 2,
        ]);
});

it('tính theo lứa nhập: chỉ Đơn vị hàng có Lô nhập xác nhận trong kỳ, hàng Huỷ nhập không tính', function () {
    defectRateSell($this->netflix, 3);
    app(StockDefect::class)->markDefective($this->admin, $this->a, 'Nhà cung cấp thu hồi');

    $august = new DefectRateReportFilter(CarbonImmutable::parse('2026-08-01'), CarbonImmutable::parse('2026-08-31'));
    expect($this->report->rows($this->admin, $august))->toBe([]);

    app(ImportReversal::class)->reverse($this->admin, $this->kinguinBatch->lines()->where('product_id', $this->steam->id)->sole());

    // Mã Steam chưa giao bị Huỷ nhập: coi như chưa từng vào kho, nhưng Dòng nhập vẫn là một lứa nhập.
    expect(defectRateRows($this->report->rows($this->admin, septemberDefectRange()))['Kinguin · STEAM-100K'])
        ->toMatchArray(['intakeUnits' => 0, 'deliveredUnits' => 0, 'rate' => null]);
});

it('lọc Nhà cung cấp và Sản phẩm', function () {
    $filter = new DefectRateReportFilter(
        CarbonImmutable::parse('2026-09-01'),
        CarbonImmutable::parse('2026-09-30'),
        supplierIds: [$this->kinguin->id],
        productIds: [$this->steam->id],
    );

    expect(array_keys(defectRateRows($this->report->rows($this->stocker, $filter))))
        ->toBe(['Kinguin · STEAM-100K', 'Kinguin · Tổng']);
});

it('chỉ Quản trị và Nhập kho xem được', function () {
    foreach ([$this->admin, $this->stocker] as $viewer) {
        expect($this->report->canView($viewer))->toBeTrue();
    }

    expect($this->report->canView($this->seller))->toBeFalse()
        ->and(fn () => $this->report->rows($this->seller, septemberDefectRange()))->toThrow(MissingRole::class)
        ->and(fn () => $this->report->export($this->seller, septemberDefectRange(), ReportFormat::Csv))->toThrow(MissingRole::class);

    $this->stocker->forceFill(['deactivated_at' => now()])->save();
    expect(fn () => $this->report->rows($this->stocker, septemberDefectRange()))->toThrow(MissingRole::class);
});

it('xuất CSV, không ghi Nhật ký xem mã hay Nhật ký bảo mật', function () {
    defectRateSell($this->netflix, 3);
    app(StockDefect::class)->markDefective($this->admin, $this->a, 'Nhà cung cấp thu hồi');
    $reveals = RevealLogEntry::count();
    $security = SecurityLogEntry::count();

    $export = $this->report->export($this->stocker, septemberDefectRange(), ReportFormat::Csv);
    $rows = array_map(
        fn (string $line) => str_getcsv($line, ',', '"', ''),
        explode("\n", trim(substr($export->contents, 3))),
    );

    expect($export->fileName)->toBe('bao-cao-ti-le-loi-2026-09-01-2026-09-30.csv')
        ->and($rows[0])->toBe([
            'Nhà cung cấp', 'Mã sản phẩm', 'Sản phẩm', 'Đơn vị hàng nhập', 'Đã giao',
            'Đơn vị hàng Lỗi', 'Tỉ lệ lỗi', 'Lỗi trong kho', 'Dòng lỗi/trùng khi nhập',
        ])
        ->and($rows[1])->toBe(['G2A', 'NETFLIX-1M', 'Netflix 1 tháng', '1', '0', '0', '', '0', '0'])
        ->and($rows[2])->toBe(['G2A', 'Tổng', '', '1', '0', '0', '', '0', '0'])
        ->and($rows[3])->toBe(['Kinguin', 'NETFLIX-1M', 'Netflix 1 tháng', '2', '1', '1', '100,0%', '0', '0'])
        ->and(RevealLogEntry::count())->toBe($reveals)
        ->and(SecurityLogEntry::count())->toBe($security);
});

it('xuất XLSX cùng cột với CSV', function () {
    $export = $this->report->export($this->admin, septemberDefectRange(), ReportFormat::Xlsx);
    $path = tempnam(sys_get_temp_dir(), 'defect-rate-report');
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

    expect($export->fileName)->toBe('bao-cao-ti-le-loi-2026-09-01-2026-09-30.xlsx')
        ->and($rows[0])->toBe(array_values($this->report->columns()))
        ->and($rows[1])->toBe(['G2A', 'NETFLIX-1M', 'Netflix 1 tháng', 1, 0, 0, '', 0, 0]);
});
