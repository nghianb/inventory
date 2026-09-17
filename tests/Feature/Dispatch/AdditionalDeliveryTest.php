<?php

use App\Inventory\Access\MissingRole;
use App\Inventory\Access\Role;
use App\Inventory\Catalog\ContentFieldDraft;
use App\Inventory\Catalog\ContentFieldType;
use App\Inventory\Catalog\ProductCatalog;
use App\Inventory\Catalog\StockForm;
use App\Inventory\Catalog\SupplierDirectory;
use App\Inventory\Dispatch\DeliveredContent;
use App\Inventory\Dispatch\DispatchDraft;
use App\Inventory\Dispatch\DispatchLineDraft;
use App\Inventory\Dispatch\DispatchLineKind;
use App\Inventory\Dispatch\DispatchProblem;
use App\Inventory\Dispatch\DispatchStatus;
use App\Inventory\Dispatch\InvalidDispatch;
use App\Inventory\Dispatch\ManualDispatch;
use App\Inventory\Dispatch\OutOfStock;
use App\Inventory\Dispatch\SalesChannelDirectory;
use App\Inventory\Dispatch\SalesChannelDraft;
use App\Inventory\Dispatch\Shortage;
use App\Inventory\Encryption\KeyFingerprints;
use App\Inventory\Intake\BatchDraft;
use App\Inventory\Intake\BatchIntake;
use App\Inventory\Intake\BatchLineDraft;
use App\Inventory\Reveal\ContentReveal;
use App\Inventory\Reveal\InvalidReveal;
use App\Inventory\Stock\SlotStatus;
use App\Models\Delivery;
use App\Models\Dispatch;
use App\Models\DispatchLine;
use App\Models\Product;
use App\Models\RevealLogEntry;
use App\Models\Slot;
use App\Models\StockLedgerEntry;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('intake');
    $this->seed(RoleSeeder::class);
    app(KeyFingerprints::class)->register();
    $this->travelTo(CarbonImmutable::parse('2026-09-15 10:00'));

    $this->admin = staffMember(Role::Owner);
    $this->seller = staffMember(Role::BanHang);
    $this->otherSeller = staffMember(Role::BanHang);
    $this->manual = app(ManualDispatch::class);
    $this->reveal = app(ContentReveal::class);
    $this->supplier = app(SupplierDirectory::class)->create($this->admin, 'Kinguin');
    $this->shopee = app(SalesChannelDirectory::class)->create($this->admin, new SalesChannelDraft('Shopee', requiresExternalRef: true));

    $this->steam = productOf(
        StockForm::OneTimeCode,
        [new ContentFieldDraft('serial', 'Serial', sensitive: false), new ContentFieldDraft('code', 'Mã thẻ', dedupeKey: true)],
        'Steam Wallet 100k',
        'STEAM-100K',
        warrantyDays: 7,
    );
    $this->netflix = productOf(
        StockForm::Account,
        [
            new ContentFieldDraft('username', 'Tên đăng nhập', ContentFieldType::Email, sensitive: false, dedupeKey: true),
            new ContentFieldDraft('password', 'Mật khẩu'),
        ],
        'Netflix 1 tháng',
        'NETFLIX-1M',
        defaultSlots: 2,
        warrantyDays: 30,
    );
});

function additionalStock(Product $product, string $content): void
{
    $intake = app(BatchIntake::class);
    $intake->confirm(test()->admin, $intake->submit(test()->admin, new BatchDraft(
        supplier: test()->supplier,
        receivedOn: CarbonImmutable::today(),
        lines: [new BatchLineDraft($product, 100_000, $content)],
    )));
}

/**
 * @param  list<array{0: ?Product, 1: int, 2?: ?int}>  $lines  Sản phẩm, số lượng, Giá bán
 * @return list<DispatchLineDraft>
 */
function additionalLines(array $lines): array
{
    return array_map(fn (array $line): DispatchLineDraft => new DispatchLineDraft(...$line), $lines);
}

/**
 * Phiếu xuất SP-001 đã qua màn kết quả: một Steam (100.000 ₫) và một Slot Netflix.
 */
function completedOrder(): Dispatch
{
    $dispatch = test()->manual->create(test()->seller, new DispatchDraft(
        test()->shopee,
        'SP-001',
        additionalLines([[test()->steam, 1, 100_000], [test()->netflix, 1]]),
        customer: 'Anh Minh',
        note: 'Khách quen',
    ));
    test()->reveal->revealDispatchResult(test()->seller, $dispatch);

    return $dispatch;
}

it('Giao thêm vào Phiếu xuất Hoàn tất: Dòng xuất mới loại Giao thêm theo Thứ tự xuất, dòng cũ và thông tin đơn giữ nguyên, ghi Sổ biến động kho', function () {
    additionalStock($this->steam, "SR1\tAAAA-0001\nSR2\tAAAA-0002\nSR3\tAAAA-0003");
    additionalStock($this->netflix, "a@shop.test\tpw-a\nb@shop.test\tpw-b");
    $dispatch = completedOrder();
    $before = StockLedgerEntry::max('id');

    $result = $this->manual->addLines($this->otherSeller, $dispatch, additionalLines([[$this->steam, 2, 190_000], [$this->netflix, 1]]));

    expect($result->fresh())
        ->status->toBe(DispatchStatus::Completed)
        ->external_ref->toBe('SP-001')
        ->customer->toBe('Anh Minh')
        ->note->toBe('Khách quen')
        ->created_by->toBe($this->seller->id)
        ->and($dispatch->lines()->get()->map(fn (DispatchLine $line) => [$line->product_id, $line->kind, $line->quantity, $line->sale_price])->all())
        ->toBe([
            [$this->steam->id, DispatchLineKind::Sale, 1, 100_000],
            [$this->netflix->id, DispatchLineKind::Sale, 1, null],
            [$this->steam->id, DispatchLineKind::Additional, 2, 190_000],
            [$this->netflix->id, DispatchLineKind::Additional, 1, null],
        ])
        // Slot thứ hai của Tài khoản đã giao dở được chọn trước Tài khoản mới.
        ->and(Delivery::orderBy('id')->with('stockUnit')->get()->map(fn (Delivery $delivery) => $delivery->stockUnit->content['serial'] ?? $delivery->stockUnit->content['username'])->all())
        ->toBe(['SR1', 'a@shop.test', 'SR2', 'SR3', 'a@shop.test'])
        ->and(Slot::where('status', SlotStatus::InStock)->count())->toBe(2);

    $rows = StockLedgerEntry::where('id', '>', $before)->get();

    expect($rows)->toHaveCount(3)
        ->and($rows->map(fn (StockLedgerEntry $row) => [$row->from_status, $row->to_status, $row->actor_id, $row->reason])->unique()->values()->all())
        ->toBe([['in-stock', 'delivered', $this->otherSeller->id, "Giao thêm theo Phiếu xuất #{$dispatch->id}"]]);
});

it('màn kết quả sau Giao thêm chỉ hiện Slot vừa giao, một lần, cho người vừa Giao thêm', function () {
    additionalStock($this->steam, "SR1\tAAAA-0001\nSR2\tAAAA-0002");
    additionalStock($this->netflix, "a@shop.test\tpw-a");
    $dispatch = completedOrder();
    $logged = RevealLogEntry::count();

    $this->manual->addLines($this->otherSeller, $dispatch, additionalLines([[$this->steam, 1]]));
    $added = Delivery::orderByDesc('id')->firstOrFail();

    expect(fn () => $this->reveal->revealDispatchResult($this->seller, $dispatch))
        ->toThrow(InvalidReveal::class, 'Chỉ người vừa xuất kho xem được màn kết quả.');

    $result = $this->reveal->revealDispatchResult($this->otherSeller, $dispatch);

    expect($result->masked)->toBeFalse()
        ->and(array_map(fn (DeliveredContent $slot) => [$slot->deliveryId, $slot->message], $result->slots))
        ->toBe([[$added->id, "Serial: SR2\nMã thẻ: AAAA-0002"]])
        ->and(RevealLogEntry::count())->toBe($logged + 1)
        ->and($this->reveal->copyAllDispatchResult($this->otherSeller, $dispatch))->toBe("Serial: SR2\nMã thẻ: AAAA-0002")
        ->and(fn () => $this->reveal->revealDispatchResult($this->otherSeller, $dispatch))
        ->toThrow(InvalidReveal::class, 'Màn kết quả chỉ hiện một lần ngay sau khi xuất kho.');
});

it('Giao thêm thiếu hàng thì không thêm gì; phiếu cũ giữ nguyên, kể cả màn kết quả đang mở', function () {
    additionalStock($this->steam, "SR1\tAAAA-0001\nSR2\tAAAA-0002");
    additionalStock($this->netflix, "a@shop.test\tpw-a");
    $dispatch = completedOrder();
    $snapshot = fn () => [
        DB::table('dispatches')->where('id', $dispatch->id)->first(),
        DB::table('dispatch_lines')->orderBy('id')->get()->all(),
        DB::table('deliveries')->orderBy('id')->get()->all(),
        DB::table('slots')->orderBy('id')->get()->all(),
        StockLedgerEntry::count(),
    ];
    $before = $snapshot();
    $lines = additionalLines([[$this->steam, 1], [$this->netflix, 2]]);

    expect($this->manual->shortages($this->otherSeller, $lines))
        ->toEqual([new Shortage($this->netflix->id, 'NETFLIX-1M', 'Netflix 1 tháng', needed: 2, available: 1)]);

    expect(fn () => $this->manual->addLines($this->otherSeller, $dispatch, $lines))
        ->toThrow(OutOfStock::class, 'Không đủ hàng, không giao gì: "Netflix 1 tháng" cần 2, còn 1.')
        ->and($snapshot())->toEqual($before)
        ->and($this->reveal->copyAllDispatchResult($this->seller, $dispatch))->toContain('AAAA-0001');
});

it('Giao thêm báo lỗi kiểm tra và không thêm gì', function (Closure $lines, string $message) {
    additionalStock($this->steam, "SR1\tA-1\nSR2\tA-2\nSR3\tA-3");
    additionalStock($this->netflix, "a@shop.test\tpw");
    $dispatch = completedOrder();
    config(['inventory.dispatch.max_slots' => 4]);
    $lines = $lines($dispatch);

    expect(array_map(fn (DispatchProblem $problem) => $problem->message, $this->manual->checkAdditional($this->seller, $dispatch, $lines)))->toContain($message)
        ->and(fn () => $this->manual->addLines($this->seller, $dispatch, $lines))->toThrow(InvalidDispatch::class, $message)
        ->and(DispatchLine::count())->toBe(2)
        ->and(Delivery::count())->toBe(2);
})->with([
    'không có Dòng xuất' => [fn () => [], 'Giao thêm phải có ít nhất một Dòng xuất.'],
    'Dòng xuất chưa chọn Sản phẩm' => [fn () => additionalLines([[test()->steam, 1], [null, 1]]), 'Dòng xuất thứ 2 chưa chọn Sản phẩm.'],
    'hai dòng cùng Sản phẩm' => [fn () => additionalLines([[test()->steam, 1], [test()->steam, 1]]), 'Sản phẩm "Steam Wallet 100k" có hai Dòng xuất; mỗi Sản phẩm một Dòng xuất.'],
    'số lượng dưới 1' => [fn () => additionalLines([[test()->steam, 0]]), 'Số lượng của Dòng xuất "Steam Wallet 100k" phải từ 1 trở lên.'],
    'Giá bán âm' => [fn () => additionalLines([[test()->steam, 1, -1]]), 'Giá bán của Dòng xuất "Steam Wallet 100k" không được âm.'],
    'vượt giới hạn Slot tính cả Slot đã giao' => [fn () => additionalLines([[test()->steam, 3]]), 'Phiếu xuất có 5 Slot, vượt giới hạn 4 Slot mỗi phiếu.'],
    'Sản phẩm Ngừng bán' => [function () {
        app(ProductCatalog::class)->discontinue(test()->admin, test()->steam);

        return additionalLines([[test()->steam, 1]]);
    }, 'Sản phẩm "Steam Wallet 100k" đã Ngừng bán.'],
    'phiếu không Hoàn tất' => [function (Dispatch $dispatch) {
        DB::table('dispatches')->where('id', $dispatch->id)->update(['status' => DispatchStatus::Cancelled->value]);

        return additionalLines([[test()->steam, 1]]);
    }, 'Chỉ Giao thêm được vào Phiếu xuất Hoàn tất.'],
]);

it('Bán hàng và Quản trị Giao thêm được, Nhập kho thì không', function () {
    additionalStock($this->steam, "SR1\tA-1\nSR2\tA-2");
    $dispatch = $this->manual->create($this->seller, new DispatchDraft($this->shopee, 'SP-001', additionalLines([[$this->steam, 1]])));
    $stocker = staffMember(Role::NhapKho);

    expect($this->manual->canAddLines($this->seller, $dispatch))->toBeTrue()
        ->and($this->manual->canAddLines($this->admin, $dispatch))->toBeTrue()
        ->and($this->manual->canAddLines($stocker, $dispatch))->toBeFalse()
        ->and(fn () => $this->manual->addLines($stocker, $dispatch, additionalLines([[$this->steam, 1]])))->toThrow(MissingRole::class)
        ->and($this->manual->addLines($this->admin, $dispatch, additionalLines([[$this->steam, 1]]))->deliveries()->count())->toBe(2);

    DB::table('dispatches')->where('id', $dispatch->id)->update(['status' => DispatchStatus::Cancelled->value]);

    expect($this->manual->canAddLines($this->seller, $dispatch->fresh()))->toBeFalse();
});
