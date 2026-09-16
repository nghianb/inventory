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
use App\Inventory\Reports\DispatchProfitReport;
use App\Inventory\Reports\DispatchProfitRow;
use App\Inventory\Reports\ProfitReportFilter;
use App\Inventory\Warranty\DefectReportDraft;
use App\Inventory\Warranty\DefectReporting;
use App\Inventory\Warranty\DefectScope;
use App\Inventory\Warranty\ReplacementDelivery;
use App\Inventory\Warranty\ReplacementDraft;
use App\Models\Delivery;
use App\Models\Product;
use App\Models\SalesChannel;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('intake');
    $this->seed(RoleSeeder::class);
    app(KeyFingerprints::class)->register();

    $this->admin = staffMember(Role::QuanTri);
    $this->seller = staffMember(Role::BanHang);
    $this->report = app(DispatchProfitReport::class);

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

    $this->kinguin = app(SupplierDirectory::class)->create($this->admin, 'Kinguin');
    $this->shopee = app(SalesChannelDirectory::class)->create($this->admin, new SalesChannelDraft('Shopee'));
    $this->zalo = app(SalesChannelDirectory::class)->create($this->admin, new SalesChannelDraft('Zalo'));

    // Giá vốn Slot 30.000: mỗi Tài khoản 3 Slot, Giá vốn 90.000.
    $this->travelTo(CarbonImmutable::parse('2026-09-05 09:00'));
    $intake = app(BatchIntake::class);
    $intake->confirm($this->admin, $intake->submit($this->admin, new BatchDraft(
        supplier: $this->kinguin,
        receivedOn: CarbonImmutable::today(),
        lines: [new BatchLineDraft($this->netflix, 90_000, "a@shop.test\tpw-a\nb@shop.test\tpw-b")],
    )));

    $this->travelTo(CarbonImmutable::parse('2026-09-15 10:00'));
});

function dispatchProfitSell(SalesChannel $channel, string $ref, Product $product, int $quantity, ?int $salePrice = null): void
{
    app(ManualDispatch::class)->create(test()->seller, new DispatchDraft($channel, $ref, [new DispatchLineDraft($product, $quantity, $salePrice)]));
}

function dispatchProfitSeptember(): ProfitReportFilter
{
    return new ProfitReportFilter(CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-30'));
}

/**
 * @param  list<DispatchProfitRow>  $rows
 * @return array<string, array<string, int|string>> theo Mã đơn ngoài
 */
function dispatchProfitRows(array $rows): array
{
    return collect($rows)->mapWithKeys(fn (DispatchProfitRow $row) => [$row->externalRef => [
        'channel' => $row->cell('channel'),
        'salePrice' => $row->salePrice,
        'cogs' => $row->cogs,
        'grossProfit' => $row->grossProfit(),
        'replacementCost' => $row->replacementCost,
    ]])->all();
}

it('mỗi Phiếu xuất một dòng với Giá bán, Giá vốn và Lãi gộp', function () {
    dispatchProfitSell($this->shopee, 'SP-1', $this->netflix, 2, 500_000);
    dispatchProfitSell($this->zalo, 'ZL-1', $this->netflix, 1, 150_000);

    expect(dispatchProfitRows($this->report->rows($this->admin, dispatchProfitSeptember())))->toBe([
        'SP-1' => [
            'channel' => 'Shopee',
            'salePrice' => 500_000,
            'cogs' => 60_000,
            'grossProfit' => 440_000,
            'replacementCost' => 0,
        ],
        'ZL-1' => [
            'channel' => 'Zalo',
            'salePrice' => 150_000,
            'cogs' => 30_000,
            'grossProfit' => 120_000,
            'replacementCost' => 0,
        ],
    ]);
});

it("'Chi phí đổi hàng phát sinh' là cột tham khảo, không trừ vào Lãi gộp của phiếu", function () {
    dispatchProfitSell($this->shopee, 'SP-1', $this->netflix, 1, 500_000);

    $reporting = app(DefectReporting::class);
    $report = $reporting->report($this->seller, [Delivery::sole()], new DefectReportDraft('Không đăng nhập được'))[0];
    $reporting->confirm($this->admin, $report, DefectScope::Slot, 'Đúng là lỗi');
    app(ReplacementDelivery::class)->replace($this->seller, $report->fresh(), new ReplacementDraft);

    expect(dispatchProfitRows($this->report->rows($this->admin, dispatchProfitSeptember()))['SP-1'])->toMatchArray([
        // Lãi gộp vẫn là 500.000 − 30.000: Chi phí đổi hàng chỉ trừ vào Lãi ròng kho.
        'cogs' => 30_000,
        'grossProfit' => 470_000,
        'replacementCost' => 30_000,
    ]);
});

it('Slot giao nhầm đã Huỷ hàng không làm dày Giá vốn của phiếu', function () {
    dispatchProfitSell($this->shopee, 'SP-1', $this->netflix, 1, 500_000);
    app(CorrectiveDelivery::class)->correct($this->seller, Delivery::sole(), new CorrectionDraft(null, contentSent: false));

    expect(dispatchProfitRows($this->report->rows($this->admin, dispatchProfitSeptember()))['SP-1'])->toMatchArray([
        'cogs' => 30_000,
        'grossProfit' => 470_000,
    ]);
});

it('Phiếu xuất chưa ghi Giá bán không có dòng', function () {
    dispatchProfitSell($this->shopee, 'SP-1', $this->netflix, 1, 500_000);
    dispatchProfitSell($this->zalo, 'ZL-1', $this->netflix, 1);

    expect(array_keys(dispatchProfitRows($this->report->rows($this->admin, dispatchProfitSeptember()))))->toBe(['SP-1']);
});

it('lọc Kênh bán chỉ còn phiếu của kênh đó', function () {
    dispatchProfitSell($this->shopee, 'SP-1', $this->netflix, 1, 500_000);
    dispatchProfitSell($this->zalo, 'ZL-1', $this->netflix, 1, 150_000);

    $filter = new ProfitReportFilter(
        CarbonImmutable::parse('2026-09-01'),
        CarbonImmutable::parse('2026-09-30'),
        salesChannelId: $this->shopee->id,
    );

    expect(array_keys(dispatchProfitRows($this->report->rows($this->admin, $filter))))->toBe(['SP-1']);
});

it('chỉ Quản trị xem được chi tiết theo Phiếu xuất', function () {
    expect($this->report->canView($this->admin))->toBeTrue()
        ->and($this->report->canView($this->seller))->toBeFalse()
        ->and(fn () => $this->report->rows($this->seller, dispatchProfitSeptember()))->toThrow(MissingRole::class);
});
