<?php

use App\Inventory\Access\MissingRole;
use App\Inventory\Access\Role;
use App\Inventory\Catalog\ContentFieldDraft;
use App\Inventory\Catalog\ContentFieldType;
use App\Inventory\Catalog\ProductCatalog;
use App\Inventory\Catalog\ProductDraft;
use App\Inventory\Catalog\ProductType;
use App\Inventory\Catalog\SupplierDirectory;
use App\Inventory\Claims\ClaimOutcomeDraft;
use App\Inventory\Claims\SupplierClaims;
use App\Inventory\Dispatch\SalesChannelDirectory;
use App\Inventory\Dispatch\SalesChannelDraft;
use App\Inventory\Encryption\KeyFingerprints;
use App\Inventory\Intake\BatchDraft;
use App\Inventory\Intake\BatchIntake;
use App\Inventory\Intake\BatchLineDraft;
use App\Inventory\Reports\ProfitReportFilter;
use App\Inventory\Reports\SupplierLossReport;
use App\Inventory\Reports\SupplierLossRow;
use App\Inventory\Stock\StockDefect;
use App\Inventory\Stock\StockVoid;
use App\Inventory\Stock\VoidReason;
use App\Models\Batch;
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
    $this->report = app(SupplierLossReport::class);

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

    $directory = app(SupplierDirectory::class);
    $this->kinguin = $directory->create($this->admin, 'Kinguin');
    $this->g2a = $directory->create($this->admin, 'G2A');
    app(SalesChannelDirectory::class)->create($this->admin, new SalesChannelDraft('Shopee'));

    // Mỗi Tài khoản 3 Slot, Giá vốn 90.000 nên Giá vốn Slot 30.000.
    $this->travelTo(CarbonImmutable::parse('2026-09-05 09:00'));
    supplierLossImport($this->kinguin, "a@shop.test\tpw-a\nb@shop.test\tpw-b");

    $this->travelTo(CarbonImmutable::parse('2026-09-15 10:00'));
    $unit = fn (string $username): StockUnit => StockUnit::where('content->username', $username)->sole();
    [$this->a, $this->b] = [$unit('a@shop.test'), $unit('b@shop.test')];
});

function supplierLossImport(Supplier $supplier, string $content, ?SupplierClaim $claim = null): Batch
{
    $intake = app(BatchIntake::class);

    return $intake->confirm(test()->admin, $intake->submit(test()->admin, new BatchDraft(
        supplier: $supplier,
        receivedOn: CarbonImmutable::today(),
        lines: [new BatchLineDraft(test()->netflix, 90_000, $content)],
        supplierClaim: $claim,
    )));
}

function supplierLossSeptember(): ProfitReportFilter
{
    return new ProfitReportFilter(CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-30'));
}

/**
 * @param  list<SupplierLossRow>  $rows
 * @return array<string, array<string, int>> theo tên Nhà cung cấp
 */
function supplierLossRows(array $rows): array
{
    return collect($rows)->mapWithKeys(fn (SupplierLossRow $row) => [$row->supplierName => [
        'defectiveLoss' => $row->defectiveLoss,
        'replacementCost' => $row->replacementCost,
        'reimbursement' => $row->reimbursement,
        'replacementGoodsUnits' => $row->replacementGoodsUnits,
        'netLoss' => $row->netLoss(),
    ]])->all();
}

it('mỗi Nhà cung cấp một dòng với Giá vốn hàng lỗi và Lỗ ròng', function () {
    app(StockDefect::class)->markDefective($this->admin, $this->a, 'Nhà cung cấp thu hồi');

    expect(supplierLossRows($this->report->rows($this->admin, supplierLossSeptember())))->toBe([
        'Kinguin' => [
            // 3 Slot còn trong kho, Giá vốn Slot 30.000.
            'defectiveLoss' => 90_000,
            'replacementCost' => 0,
            'reimbursement' => 0,
            'replacementGoodsUnits' => 0,
            'netLoss' => 90_000,
        ],
    ]);
});

it('bồi hoàn tiền trừ vào Lỗ ròng theo ngày giải quyết khiếu nại', function () {
    app(StockDefect::class)->markDefective($this->admin, $this->a, 'Nhà cung cấp thu hồi');

    $claims = app(SupplierClaims::class);
    $claim = $claims->create($this->stocker, $this->kinguin, [$this->a]);
    $claims->send($this->stocker, $claim);
    $claims->resolve($this->stocker, $claim, [
        $claim->claimUnits()->sole()->id => ClaimOutcomeDraft::refund(70_000, CarbonImmutable::parse('2026-09-10')),
    ]);

    expect(supplierLossRows($this->report->rows($this->admin, supplierLossSeptember()))['Kinguin'])->toMatchArray([
        'defectiveLoss' => 90_000,
        'reimbursement' => 70_000,
        'netLoss' => 20_000,
    ]);
});

it('bồi hoàn bằng hàng chỉ là cột tham khảo, không trừ vào Lỗ ròng', function () {
    app(StockDefect::class)->markDefective($this->admin, $this->a, 'Nhà cung cấp thu hồi');

    $claims = app(SupplierClaims::class);
    $claim = $claims->create($this->stocker, $this->kinguin, [$this->a]);
    $claims->send($this->stocker, $claim);
    $claims->resolve($this->stocker, $claim, [
        $claim->claimUnits()->sole()->id => ClaimOutcomeDraft::replacementGoods(),
    ]);

    expect(supplierLossRows($this->report->rows($this->admin, supplierLossSeptember()))['Kinguin'])->toMatchArray([
        'defectiveLoss' => 90_000,
        'reimbursement' => 0,
        'replacementGoodsUnits' => 1,
        // Hàng thay thế vào kho với Giá vốn 0 nên đã tự phản ánh khi bán: trừ nữa là tính hai lần.
        'netLoss' => 90_000,
    ]);
});

it('Tổn thất Huỷ hàng của shop không tính cho Nhà cung cấp', function () {
    app(StockVoid::class)->voidUnit($this->admin, $this->a, VoidReason::ContentExposed, 'Nhân viên làm lộ nội dung');

    // Lộ nội dung là lỗi của shop: Nhà cung cấp không có dòng nào.
    expect(supplierLossRows($this->report->rows($this->admin, supplierLossSeptember())))->toBe([]);
});

it('Khôi phục làm Nhà cung cấp rời khỏi báo cáo', function () {
    $defect = app(StockDefect::class);
    $defect->markDefective($this->admin, $this->a, 'Nhà cung cấp thu hồi');

    expect(supplierLossRows($this->report->rows($this->admin, supplierLossSeptember())))->toHaveKey('Kinguin');

    $defect->restore($this->admin, $this->a->fresh(), 'Nhà cung cấp sửa được hàng');

    expect(supplierLossRows($this->report->rows($this->admin, supplierLossSeptember())))->toBe([]);
});

it('lọc Nhà cung cấp chỉ còn dòng của họ', function () {
    supplierLossImport($this->g2a, "g@shop.test\tpw-g");
    app(StockDefect::class)->markDefective($this->admin, $this->a, 'Nhà cung cấp thu hồi');
    app(StockDefect::class)->markDefective($this->admin, StockUnit::where('content->username', 'g@shop.test')->sole(), 'Nhà cung cấp thu hồi');

    $filter = new ProfitReportFilter(
        CarbonImmutable::parse('2026-09-01'),
        CarbonImmutable::parse('2026-09-30'),
        supplierId: $this->g2a->id,
    );

    expect(array_keys(supplierLossRows($this->report->rows($this->admin, supplierLossSeptember()))))->toBe(['G2A', 'Kinguin'])
        ->and(array_keys(supplierLossRows($this->report->rows($this->admin, $filter))))->toBe(['G2A']);
});

it('chỉ Quản trị xem được báo cáo lỗ theo Nhà cung cấp', function () {
    expect($this->report->canView($this->admin))->toBeTrue()
        ->and($this->report->canView($this->stocker))->toBeFalse()
        ->and(fn () => $this->report->rows($this->stocker, supplierLossSeptember()))->toThrow(MissingRole::class);
});
