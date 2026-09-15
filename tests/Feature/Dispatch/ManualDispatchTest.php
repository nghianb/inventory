<?php

use App\Inventory\Access\MissingRole;
use App\Inventory\Access\Role;
use App\Inventory\Catalog\ContentFieldDraft;
use App\Inventory\Catalog\ContentFieldType;
use App\Inventory\Catalog\LockedProductConfiguration;
use App\Inventory\Catalog\ProductCatalog;
use App\Inventory\Catalog\ProductDraft;
use App\Inventory\Catalog\ProductType;
use App\Inventory\Catalog\SupplierDirectory;
use App\Inventory\Dispatch\DeliveredContent;
use App\Inventory\Dispatch\DispatchDraft;
use App\Inventory\Dispatch\DispatchLineDraft;
use App\Inventory\Dispatch\DispatchLineKind;
use App\Inventory\Dispatch\DispatchProblem;
use App\Inventory\Dispatch\DispatchResultFormat;
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
use App\Inventory\Intake\ExpiryRule;
use App\Inventory\Intake\ImportReversal;
use App\Inventory\Reveal\ContentReveal;
use App\Inventory\Reveal\InvalidReveal;
use App\Inventory\Reveal\RevealContextType;
use App\Inventory\Stock\SellableStock;
use App\Inventory\Stock\SlotStatus;
use App\Inventory\Stock\StockLevel;
use App\Models\Batch;
use App\Models\Delivery;
use App\Models\Dispatch;
use App\Models\DispatchLine;
use App\Models\Product;
use App\Models\RevealLogEntry;
use App\Models\SalesChannel;
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

    $this->admin = staffMember(Role::QuanTri);
    $this->seller = staffMember(Role::BanHang);
    $this->manual = app(ManualDispatch::class);
    $this->supplier = app(SupplierDirectory::class)->create($this->admin, 'Kinguin');

    $channels = app(SalesChannelDirectory::class);
    $this->shopee = $channels->create($this->admin, new SalesChannelDraft('Shopee', requiresExternalRef: true));
    $this->zalo = $channels->create($this->admin, new SalesChannelDraft('Zalo'));

    $catalog = app(ProductCatalog::class);
    $this->steam = $catalog->create($this->admin, new ProductDraft(
        type: ProductType::OneTimeCode,
        name: 'Steam Wallet 100k',
        code: 'STEAM-100K',
        fields: [new ContentFieldDraft('serial', 'Serial', sensitive: false), new ContentFieldDraft('code', 'Mã thẻ', dedupeKey: true)],
        warrantyDays: 7,
    ));
    $this->netflix = $catalog->create($this->admin, netflixDispatchDraft());
});

function netflixDispatchDraft(mixed ...$overrides): ProductDraft
{
    return new ProductDraft(...[
        'type' => ProductType::Account,
        'name' => 'Netflix 1 tháng',
        'code' => 'NETFLIX-1M',
        'fields' => [
            new ContentFieldDraft('username', 'Tên đăng nhập', ContentFieldType::Email, sensitive: false, dedupeKey: true),
            new ContentFieldDraft('password', 'Mật khẩu'),
        ],
        'defaultSlots' => 2,
        'warrantyDays' => 30,
        'lowStockThreshold' => 2,
        ...$overrides,
    ]);
}

/**
 * Nhập và xác nhận một Lô nhập một Dòng nhập vào kho.
 */
function dispatchStock(Product $product, string $content, ?ExpiryRule $expiry = null): Batch
{
    $intake = app(BatchIntake::class);

    return $intake->confirm(test()->admin, $intake->submit(test()->admin, new BatchDraft(
        supplier: test()->supplier,
        receivedOn: CarbonImmutable::today(),
        lines: [new BatchLineDraft($product, 100_000, $content, expiry: $expiry)],
    )));
}

/**
 * @param  list<array{0: ?Product, 1: int, 2?: ?int}>  $lines  Sản phẩm, số lượng, Giá bán
 */
function dispatchOrder(?SalesChannel $channel, array $lines, ?string $ref = null, ?string $customer = null, ?string $note = null): DispatchDraft
{
    return new DispatchDraft(
        channel: $channel,
        externalRef: $ref,
        lines: array_map(fn (array $line): DispatchLineDraft => new DispatchLineDraft(...$line), $lines),
        customer: $customer,
        note: $note,
    );
}

/**
 * Giá trị trường không nhạy cảm của các Đơn vị hàng đã giao theo phiếu, theo thứ tự giao.
 *
 * @return list<string>
 */
function deliveredValues(Dispatch $dispatch, string $key): array
{
    return Delivery::query()
        ->whereIn('dispatch_line_id', $dispatch->lines()->pluck('id'))
        ->orderBy('id')
        ->with('stockUnit')
        ->get()
        ->map(fn (Delivery $delivery): string => $delivery->stockUnit->content[$key])
        ->all();
}

it('Bán hàng xuất Phiếu xuất nhiều dòng: giao ngay, phiếu Hoàn tất, Slot Đã giao và ghi Sổ biến động kho', function () {
    dispatchStock($this->steam, "SR1\tAAAA-0001\nSR2\tAAAA-0002\nSR3\tAAAA-0003");
    dispatchStock($this->netflix, "a@shop.test\tpw");
    $before = StockLedgerEntry::max('id');

    $dispatch = $this->manual->create($this->seller, dispatchOrder(
        $this->shopee,
        [[$this->steam, 2, 190_000], [$this->netflix, 2]],
        ref: ' SP-001 ',
        customer: 'Anh Minh 0901234567',
        note: 'Giao gấp',
    ));

    expect($dispatch->fresh())
        ->status->toBe(DispatchStatus::Completed)
        ->sales_channel_id->toBe($this->shopee->id)
        ->external_ref->toBe('SP-001')
        ->customer->toBe('Anh Minh 0901234567')
        ->note->toBe('Giao gấp')
        ->created_by->toBe($this->seller->id)
        ->and($dispatch->lines()->get()->map(fn (DispatchLine $line) => [$line->product_id, $line->kind, $line->quantity, $line->sale_price])->all())
        ->toBe([
            [$this->steam->id, DispatchLineKind::Sale, 2, 190_000],
            [$this->netflix->id, DispatchLineKind::Sale, 2, null],
        ])
        ->and(Delivery::count())->toBe(4)
        ->and(Delivery::distinct()->count('slot_id'))->toBe(4)
        ->and(Slot::where('status', SlotStatus::Delivered)->pluck('id')->sort()->values()->all())
        ->toBe(Delivery::pluck('slot_id')->sort()->values()->all())
        ->and(Slot::where('status', SlotStatus::InStock)->count())->toBe(1);

    $rows = StockLedgerEntry::where('id', '>', $before)->get();

    expect($rows)->toHaveCount(4)
        ->and($rows->map(fn (StockLedgerEntry $row) => [$row->from_status, $row->to_status, $row->actor_id, $row->reason])->unique()->values()->all())
        ->toBe([['in-stock', 'delivered', $this->seller->id, "Giao hàng theo Phiếu xuất #{$dispatch->id}"]])
        ->and($rows->pluck('slot_id')->sort()->values()->all())->toBe(Delivery::pluck('slot_id')->sort()->values()->all());
});

it('mã đơn ngoài để trống ở kênh không bắt buộc thì tự sinh PX-YYYYMMDD-NNNN, đếm theo ngày và bỏ qua mã đã có', function () {
    dispatchStock($this->steam, "SR1\tA-1\nSR2\tA-2\nSR3\tA-3\nSR4\tA-4");
    $order = fn (?string $ref = null) => $this->manual->create($this->seller, dispatchOrder($this->zalo, [[$this->steam, 1]], ref: $ref))->external_ref;

    expect($order())->toBe('PX-20260915-0001')
        ->and($order('   '))->toBe('PX-20260915-0002');

    $this->travelTo(CarbonImmutable::parse('2026-09-16 00:30'));

    expect($order('PX-20260916-0001'))->toBe('PX-20260916-0001')
        ->and($order())->toBe('PX-20260916-0002');
});

it('báo lỗi kiểm tra và không tạo Phiếu xuất', function (Closure $draft, string $message) {
    dispatchStock($this->steam, "SR1\tA-1\nSR2\tA-2\nSR3\tA-3");
    dispatchStock($this->netflix, "a@shop.test\tpw");
    config(['inventory.dispatch.max_slots' => 3]);
    $draft = $draft();

    expect(array_map(fn (DispatchProblem $problem) => $problem->message, $this->manual->check($this->seller, $draft)))->toContain($message)
        ->and(fn () => $this->manual->create($this->seller, $draft))->toThrow(InvalidDispatch::class, $message)
        ->and(Dispatch::count())->toBe(0)
        ->and(Slot::where('status', SlotStatus::InStock)->count())->toBe(5);
})->with([
    'thiếu Kênh bán' => [fn () => dispatchOrder(null, [[test()->steam, 1]]), 'Chưa chọn Kênh bán.'],
    'thiếu mã đơn bắt buộc' => [fn () => dispatchOrder(test()->shopee, [[test()->steam, 1]], ref: ' '), 'Kênh bán "Shopee" bắt buộc mã đơn ngoài.'],
    'không có Dòng xuất' => [fn () => dispatchOrder(test()->zalo, []), 'Phiếu xuất phải có ít nhất một Dòng xuất.'],
    'Dòng xuất chưa chọn Sản phẩm' => [fn () => dispatchOrder(test()->zalo, [[test()->steam, 1], [null, 1]]), 'Dòng xuất thứ 2 chưa chọn Sản phẩm.'],
    'hai dòng cùng Sản phẩm' => [fn () => dispatchOrder(test()->zalo, [[test()->steam, 1], [test()->steam, 1]]), 'Sản phẩm "Steam Wallet 100k" có hai Dòng xuất; mỗi Sản phẩm một Dòng xuất.'],
    'số lượng dưới 1' => [fn () => dispatchOrder(test()->zalo, [[test()->steam, 0]]), 'Số lượng của Dòng xuất "Steam Wallet 100k" phải từ 1 trở lên.'],
    'Giá bán âm' => [fn () => dispatchOrder(test()->zalo, [[test()->steam, 1, -1]]), 'Giá bán của Dòng xuất "Steam Wallet 100k" không được âm.'],
    'vượt giới hạn Slot' => [fn () => dispatchOrder(test()->zalo, [[test()->steam, 2], [test()->netflix, 2]]), 'Phiếu xuất có 4 Slot, vượt giới hạn 3 Slot mỗi phiếu.'],
    'Kênh bán đã ẩn' => [function () {
        app(SalesChannelDirectory::class)->hide(test()->admin, test()->zalo);

        return dispatchOrder(test()->zalo, [[test()->steam, 1]]);
    }, 'Kênh bán "Zalo" đã ngừng dùng.'],
    'Sản phẩm Ngừng bán' => [function () {
        app(ProductCatalog::class)->discontinue(test()->admin, test()->steam);

        return dispatchOrder(test()->zalo, [[test()->steam, 1]]);
    }, 'Sản phẩm "Steam Wallet 100k" đã Ngừng bán.'],
]);

it('giới hạn Slot mỗi Phiếu xuất mặc định 1.000', function () {
    expect(array_map(fn (DispatchProblem $problem) => $problem->message, $this->manual->check($this->seller, dispatchOrder($this->zalo, [[$this->steam, 1_001]]))))
        ->toBe(['Phiếu xuất có 1.001 Slot, vượt giới hạn 1.000 Slot mỗi phiếu.']);
});

it('mã đơn ngoài trùng trong cùng Kênh bán bị chặn kèm phiếu cũ; kênh khác dùng lại được', function () {
    dispatchStock($this->steam, "SR1\tA-1\nSR2\tA-2\nSR3\tA-3");
    $first = $this->manual->create($this->seller, dispatchOrder($this->shopee, [[$this->steam, 1]], ref: 'SP-001'));

    expect($this->manual->check($this->seller, dispatchOrder($this->shopee, [[$this->steam, 1]], ref: ' SP-001')))
        ->toEqual([new DispatchProblem('Mã đơn ngoài "SP-001" đã có trong Kênh bán "Shopee".', $first->id)])
        ->and(fn () => $this->manual->create($this->seller, dispatchOrder($this->shopee, [[$this->steam, 1]], ref: 'SP-001')))
        ->toThrow(InvalidDispatch::class, 'Mã đơn ngoài "SP-001" đã có trong Kênh bán "Shopee".')
        ->and($this->manual->create($this->seller, dispatchOrder($this->zalo, [[$this->steam, 1]], ref: 'SP-001'))->external_ref)->toBe('SP-001')
        ->and(Dispatch::count())->toBe(2);
});

it('thiếu hàng thì báo từng dòng cần bao nhiêu, còn bao nhiêu; không giao gì và không chiếm mã đơn', function () {
    dispatchStock($this->steam, "SR1\tA-1");
    dispatchStock($this->netflix, "a@shop.test\tpw\nb@shop.test\tpw");
    $draft = dispatchOrder($this->shopee, [[$this->steam, 3], [$this->netflix, 5]], ref: 'SP-9');

    expect($this->manual->shortages($this->seller, $draft))->toEqual([
        new Shortage($this->steam->id, 'Steam Wallet 100k', needed: 3, available: 1),
        new Shortage($this->netflix->id, 'Netflix 1 tháng', needed: 5, available: 4),
    ]);

    try {
        $this->manual->create($this->seller, $draft);
        $this->fail('Phải báo thiếu hàng.');
    } catch (OutOfStock $exception) {
        expect($exception->shortages)->toHaveCount(2)
            ->and($exception->getMessage())->toBe('Không đủ hàng, không giao gì: "Steam Wallet 100k" cần 3, còn 1; "Netflix 1 tháng" cần 5, còn 4.');
    }

    expect(Dispatch::count())->toBe(0)
        ->and(Delivery::count())->toBe(0)
        ->and(Slot::where('status', SlotStatus::InStock)->count())->toBe(5)
        ->and(StockLedgerEntry::where('to_status', 'delivered')->count())->toBe(0);

    dispatchStock($this->steam, "SR2\tA-2\nSR3\tA-3");
    dispatchStock($this->netflix, "c@shop.test\tpw");

    expect($this->manual->shortages($this->seller, $draft))->toBe([])
        ->and($this->manual->create($this->seller, $draft)->external_ref)->toBe('SP-9');
});

it('Thứ tự xuất: Tài khoản đã giao dở trước, rồi Hạn sử dụng gần nhất, hàng không có hạn xếp sau, rồi hàng nhập trước', function () {
    dispatchStock($this->netflix, "nohan-cu@shop.test\tpw");
    dispatchStock($this->netflix, "muon@shop.test\tpw", ExpiryRule::on(CarbonImmutable::parse('2026-10-30')));
    dispatchStock($this->netflix, "som@shop.test\tpw", ExpiryRule::on(CarbonImmutable::parse('2026-09-20')));
    dispatchStock($this->netflix, "som-nhap-sau@shop.test\tpw", ExpiryRule::on(CarbonImmutable::parse('2026-09-20')));
    dispatchStock($this->netflix, "nohan-moi@shop.test\tpw");
    $take = fn (int $quantity) => deliveredValues($this->manual->create($this->seller, dispatchOrder($this->zalo, [[$this->netflix, $quantity]])), 'username');

    expect($take(1))->toBe(['som@shop.test'])
        ->and($take(1))->toBe(['som@shop.test'])
        ->and($take(3))->toBe(['som-nhap-sau@shop.test', 'som-nhap-sau@shop.test', 'muon@shop.test'])
        ->and($take(1))->toBe(['muon@shop.test'])
        ->and($take(3))->toBe(['nohan-cu@shop.test', 'nohan-cu@shop.test', 'nohan-moi@shop.test'])
        ->and($take(1))->toBe(['nohan-moi@shop.test']);
});

it('bỏ qua Slot không đạt Hạn còn lại tối thiểu, quá Hạn sử dụng hoặc của Đơn vị hàng không Hoạt động', function () {
    $garena = app(ProductCatalog::class)->create($this->admin, new ProductDraft(
        type: ProductType::OneTimeCode,
        name: 'Garena 50k',
        code: 'GARENA-50K',
        fields: [new ContentFieldDraft('serial', 'Serial', sensitive: false, dedupeKey: true)],
        minRemainingDays: 3,
    ));
    $this->travelTo(CarbonImmutable::parse('2026-09-01 09:00'));
    dispatchStock($garena, 'QUAHAN', ExpiryRule::on(CarbonImmutable::parse('2026-09-14')));
    dispatchStock($garena, 'CON2NGAY', ExpiryRule::on(CarbonImmutable::parse('2026-09-17')));
    dispatchStock($garena, 'CON3NGAY', ExpiryRule::on(CarbonImmutable::parse('2026-09-18')));
    app(ImportReversal::class)->reverse($this->admin, dispatchStock($garena, 'HUYNHAP', ExpiryRule::on(CarbonImmutable::parse('2026-09-15'))));
    dispatchStock($garena, 'KHONGHAN');
    $this->travelTo(CarbonImmutable::parse('2026-09-15 23:59'));

    expect(app(SellableStock::class)->count($garena))->toBe(2)
        ->and($this->manual->shortages($this->seller, dispatchOrder($this->zalo, [[$garena, 3]])))
        ->toEqual([new Shortage($garena->id, 'Garena 50k', needed: 3, available: 2)])
        ->and(deliveredValues($this->manual->create($this->seller, dispatchOrder($this->zalo, [[$garena, 2]])), 'serial'))
        ->toBe(['CON3NGAY', 'KHONGHAN']);
});

it('Tồn bán được và mức badge: xanh khi trên Ngưỡng sắp hết, vàng khi không vượt ngưỡng, đỏ khi hết', function () {
    $stock = app(SellableStock::class);

    expect($stock->count($this->netflix))->toBe(0)
        ->and($stock->level($this->netflix))->toBe(StockLevel::Empty);

    dispatchStock($this->netflix, "a@shop.test\tpw");

    expect($stock->count($this->netflix))->toBe(2)
        ->and($stock->level($this->netflix))->toBe(StockLevel::Low);

    dispatchStock($this->netflix, "b@shop.test\tpw");
    dispatchStock($this->steam, "SR1\tA-1");

    expect($stock->count($this->netflix))->toBe(4)
        ->and($stock->level($this->netflix))->toBe(StockLevel::Ok)
        ->and($stock->level($this->steam))->toBe(StockLevel::Ok)
        ->and($stock->counts([$this->netflix->id, $this->steam->id]))->toBe([$this->netflix->id => 4, $this->steam->id => 1]);

    app(ProductCatalog::class)->discontinue($this->admin, $this->steam);

    expect($stock->count($this->steam->fresh()))->toBe(0);
});

it('Hạn bảo hành = min(ngày giao + thời hạn bảo hành lúc giao, Hạn sử dụng), không đổi khi Sản phẩm sửa thời hạn bảo hành sau đó', function () {
    dispatchStock($this->netflix, "ngan@shop.test\tpw", ExpiryRule::on(CarbonImmutable::parse('2026-09-25')));
    dispatchStock($this->netflix, "dai@shop.test\tpw", ExpiryRule::on(CarbonImmutable::parse('2026-12-31')));
    dispatchStock($this->netflix, "khonghan@shop.test\tpw");

    $first = $this->manual->create($this->seller, dispatchOrder($this->zalo, [[$this->netflix, 3]]));
    $warranty = fn (Dispatch $dispatch) => Delivery::query()
        ->whereIn('dispatch_line_id', $dispatch->lines()->pluck('id'))
        ->orderBy('id')
        ->get()
        ->map(fn (Delivery $delivery) => [$delivery->warranty_days, $delivery->warrantyEndsOn()->toDateString()])
        ->all();

    expect($warranty($first))->toBe([[30, '2026-09-25'], [30, '2026-09-25'], [30, '2026-10-15']]);

    $this->travelTo(CarbonImmutable::parse('2026-09-16 08:00'));
    app(ProductCatalog::class)->update($this->admin, $this->netflix, netflixDispatchDraft(warrantyDays: 90));
    $second = $this->manual->create($this->seller, dispatchOrder($this->zalo, [[$this->netflix, 2]]));

    expect($warranty($first))->toBe([[30, '2026-09-25'], [30, '2026-09-25'], [30, '2026-10-15']])
        ->and($warranty($second))->toBe([[90, '2026-12-15'], [90, '2026-12-15']]);
});

it('Bán hàng và Quản trị xuất được, Nhập kho thì không', function () {
    dispatchStock($this->steam, "SR1\tA-1");
    $draft = dispatchOrder($this->zalo, [[$this->steam, 1]]);
    $clerk = staffMember(Role::NhapKho);

    expect(fn () => $this->manual->check($clerk, $draft))->toThrow(MissingRole::class)
        ->and(fn () => $this->manual->create($clerk, $draft))->toThrow(MissingRole::class)
        ->and(Dispatch::count())->toBe(0)
        ->and($this->manual->create($this->admin, $draft)->status)->toBe(DispatchStatus::Completed);
});

it('Mã sản phẩm không đổi được khi Sản phẩm đã có Phiếu xuất; cấu hình khác vẫn sửa được', function () {
    $catalog = app(ProductCatalog::class);
    $catalog->update($this->admin, $this->netflix, netflixDispatchDraft(code: 'NETFLIX-30D'));
    dispatchStock($this->netflix, "a@shop.test\tpw");
    $catalog->update($this->admin, $this->netflix, netflixDispatchDraft(code: 'NETFLIX-1M'));

    $this->manual->create($this->seller, dispatchOrder($this->zalo, [[$this->netflix, 1]]));

    expect(fn () => $catalog->update($this->admin, $this->netflix, netflixDispatchDraft(code: 'NETFLIX-30D')))
        ->toThrow(LockedProductConfiguration::class, 'Sản phẩm đã có Phiếu xuất: không đổi được Mã sản phẩm.');

    $catalog->update($this->admin, $this->netflix, netflixDispatchDraft(name: 'Netflix 30 ngày'));

    expect($this->netflix->fresh())->code->toBe('NETFLIX-1M')->name->toBe('Netflix 30 ngày');
});

it('lần hiển thị đầu màn kết quả trả nội dung theo mẫu mặc định và ghi Nhật ký xem mã ngữ cảnh Giao hàng cho mỗi Slot', function () {
    dispatchStock($this->steam, "SR1\tAAAA-0001\nSR2\tAAAA-0002");
    $dispatch = $this->manual->create($this->seller, dispatchOrder($this->zalo, [[$this->steam, 2]]));
    $deliveries = Delivery::orderBy('id')->get();

    $result = app(ContentReveal::class)->revealDispatchResult($this->seller, $dispatch)->slots;

    expect(array_map(fn (DeliveredContent $slot) => [$slot->deliveryId, $slot->productName, $slot->slotId, $slot->fields, $slot->message], $result))->toBe([
        [$deliveries[0]->id, 'Steam Wallet 100k', $deliveries[0]->slot_id, ['Serial' => 'SR1', 'Mã thẻ' => 'AAAA-0001'], "Serial: SR1\nMã thẻ: AAAA-0001"],
        [$deliveries[1]->id, 'Steam Wallet 100k', $deliveries[1]->slot_id, ['Serial' => 'SR2', 'Mã thẻ' => 'AAAA-0002'], "Serial: SR2\nMã thẻ: AAAA-0002"],
    ])
        ->and(DeliveredContent::copyAll($result))->toBe("Serial: SR1\nMã thẻ: AAAA-0001\n\n----------\n\nSerial: SR2\nMã thẻ: AAAA-0002");

    $entries = RevealLogEntry::orderBy('id')->get();

    expect($entries->map(fn (RevealLogEntry $entry) => [$entry->user_id, $entry->context, $entry->context_id, $entry->slot_id, $entry->reason])->all())->toBe([
        [$this->seller->id, RevealContextType::Delivery, $deliveries[0]->id, $deliveries[0]->slot_id, "Màn kết quả Phiếu xuất #{$dispatch->id}"],
        [$this->seller->id, RevealContextType::Delivery, $deliveries[1]->id, $deliveries[1]->slot_id, "Màn kết quả Phiếu xuất #{$dispatch->id}"],
    ])
        ->and(json_encode(DB::table('reveal_log_entries')->get()))->not->toContain('AAAA-0001');

    expect(fn () => app(ContentReveal::class)->revealDispatchResult($this->seller, $dispatch))
        ->toThrow(InvalidReveal::class, 'Màn kết quả chỉ hiện một lần ngay sau khi xuất kho.')
        ->and(RevealLogEntry::count())->toBe(2);
});

it('chỉ người tạo Phiếu xuất xem được màn kết quả', function () {
    dispatchStock($this->steam, "SR1\tAAAA-0001");
    $dispatch = $this->manual->create($this->seller, dispatchOrder($this->zalo, [[$this->steam, 1]]));

    expect(fn () => app(ContentReveal::class)->revealDispatchResult($this->admin, $dispatch))
        ->toThrow(InvalidReveal::class, 'Chỉ người tạo Phiếu xuất xem được màn kết quả.')
        ->and(fn () => app(ContentReveal::class)->revealDispatchResult(staffMember(Role::NhapKho), $dispatch))
        ->toThrow(MissingRole::class)
        ->and(RevealLogEntry::count())->toBe(0)
        ->and(app(ContentReveal::class)->revealDispatchResult($this->seller, $dispatch)->slots)->toHaveCount(1);
});

it('màn kết quả ghép nội dung theo Mẫu giao hàng của từng Sản phẩm; Sản phẩm chưa có mẫu dùng mẫu mặc định', function () {
    app(ProductCatalog::class)->update($this->admin, $this->netflix, netflixDispatchDraft(
        deliveryTemplate: "{{san_pham}} · đơn {{ma_don}}\nĐăng nhập: {{username}} / {{ password }}\nHạn sử dụng: {{han_su_dung}}\nBảo hành đến: {{han_bao_hanh}}\nKhông đổi mật khẩu.",
    ));
    dispatchStock($this->netflix, "a@shop.test\tpw-1", ExpiryRule::on(CarbonImmutable::parse('2026-09-25')));
    dispatchStock($this->steam, "SR1\tAAAA-0001");
    $dispatch = $this->manual->create($this->seller, dispatchOrder($this->shopee, [[$this->netflix, 1], [$this->steam, 1]], ref: 'SP-7'));

    $result = app(ContentReveal::class)->revealDispatchResult($this->seller, $dispatch);

    expect(array_map(fn (DeliveredContent $slot) => $slot->message, $result->slots))->toBe([
        "Netflix 1 tháng · đơn SP-7\nĐăng nhập: a@shop.test / pw-1\nHạn sử dụng: 25/09/2026\nBảo hành đến: 25/09/2026\nKhông đổi mật khẩu.",
        "Serial: SR1\nMã thẻ: AAAA-0001",
    ]);
});

it('biến Hạn sử dụng của hàng không có hạn hiện "Không thời hạn"; Hạn bảo hành = ngày giao + thời hạn bảo hành', function () {
    app(ProductCatalog::class)->update($this->admin, $this->steam, new ProductDraft(
        type: ProductType::OneTimeCode,
        name: 'Steam Wallet 100k',
        code: 'STEAM-100K',
        fields: [new ContentFieldDraft('serial', 'Serial', sensitive: false), new ContentFieldDraft('code', 'Mã thẻ', dedupeKey: true)],
        warrantyDays: 7,
        deliveryTemplate: '{{code}} | {{han_su_dung}} | {{han_bao_hanh}}',
    ));
    dispatchStock($this->steam, "SR1\tAAAA-0001");
    $dispatch = $this->manual->create($this->seller, dispatchOrder($this->zalo, [[$this->steam, 1]]));

    expect(app(ContentReveal::class)->revealDispatchResult($this->seller, $dispatch)->slots[0]->message)
        ->toBe('AAAA-0001 | Không thời hạn | 22/09/2026');
});

it('từ 50 Slot trở lên màn kết quả chỉ hiện dạng che: không có nội dung, không ghi Nhật ký xem mã, tải lại trang trong thời hạn tải vẫn hiện lại dạng che', function (int $quantity, bool $masked, int $logged) {
    dispatchStock($this->steam, implode("\n", array_map(fn (int $i) => sprintf("SR%d\tCODE-%04d", $i, $i), range(1, $quantity))));
    $dispatch = $this->manual->create($this->seller, dispatchOrder($this->zalo, [[$this->steam, $quantity]]));

    $result = app(ContentReveal::class)->revealDispatchResult($this->seller, $dispatch);

    expect($result->masked)->toBe($masked)
        ->and($result->slots)->toHaveCount($quantity)
        ->and($result->slots[0]->fields)->toBe(['Serial' => 'SR1', 'Mã thẻ' => $masked ? '••••••' : 'CODE-0001'])
        ->and(str_contains((string) json_encode(array_map(fn (DeliveredContent $slot) => [$slot->fields, $slot->message], $result->slots)), 'CODE-0001'))->toBe(! $masked)
        ->and($result->slots[0]->message === null)->toBe($masked)
        ->and(RevealLogEntry::count())->toBe($logged);

    if ($masked) {
        $this->travel(30)->minutes();

        expect(app(ContentReveal::class)->revealDispatchResult($this->seller, $dispatch))
            ->masked->toBeTrue()
            ->and(RevealLogEntry::count())->toBe(0);

        $this->travel(1)->minute();
    }

    expect(fn () => app(ContentReveal::class)->revealDispatchResult($this->seller, $dispatch))
        ->toThrow(InvalidReveal::class, 'Màn kết quả chỉ hiện một lần ngay sau khi xuất kho.');
})->with([
    '49 Slot hiện nội dung' => [49, false, 49],
    '50 Slot chỉ hiện dạng che' => [50, true, 0],
]);

/**
 * Phiếu xuất hai Slot đã qua màn kết quả: Netflix theo Mẫu giao hàng riêng, Steam theo mẫu mặc định.
 */
function revealedTwoProductDispatch(): Dispatch
{
    app(ProductCatalog::class)->update(test()->admin, test()->netflix, netflixDispatchDraft(deliveryTemplate: "{{username}} / {{password}}\nBảo hành đến {{han_bao_hanh}}"));
    dispatchStock(test()->netflix, "a@shop.test\tpw-1", ExpiryRule::on(CarbonImmutable::parse('2026-09-25')));
    dispatchStock(test()->steam, "SR1\tAAAA-0001");
    $dispatch = test()->manual->create(test()->seller, dispatchOrder(test()->shopee, [[test()->netflix, 1], [test()->steam, 1]], ref: 'SP-7'));
    app(ContentReveal::class)->revealDispatchResult(test()->seller, $dispatch);

    return $dispatch;
}

it('tải TXT theo Mẫu giao hàng và CSV mỗi Slot một dòng, mỗi Trường nội dung một cột; mỗi lần tải ghi Nhật ký xem mã cho mọi Slot', function () {
    $dispatch = revealedTwoProductDispatch();
    $deliveries = Delivery::orderBy('id')->get();
    $reveal = app(ContentReveal::class);

    $txt = $reveal->exportDispatchResult($this->seller, $dispatch, DispatchResultFormat::Txt);

    expect($txt->fileName)->toBe("phieu-xuat-{$dispatch->id}.txt")
        ->and($txt->contentType)->toBe('text/plain; charset=UTF-8')
        ->and($txt->contents)->toBe("a@shop.test / pw-1\nBảo hành đến 25/09/2026\n\n----------\n\nSerial: SR1\nMã thẻ: AAAA-0001\n");

    $csv = $reveal->exportDispatchResult($this->seller, $dispatch, DispatchResultFormat::Csv);

    expect($csv->fileName)->toBe("phieu-xuat-{$dispatch->id}.csv")
        ->and($csv->contentType)->toBe('text/csv; charset=UTF-8')
        ->and(str_starts_with($csv->contents, "\xEF\xBB\xBF"))->toBeTrue()
        ->and(array_map(fn (string $row) => str_getcsv($row, escape: ''), explode("\n", trim(substr($csv->contents, 3)))))->toBe([
            ['Sản phẩm', 'Tên đăng nhập', 'Mật khẩu', 'Serial', 'Mã thẻ', 'Hạn sử dụng', 'Hạn bảo hành'],
            ['Netflix 1 tháng', 'a@shop.test', 'pw-1', '', '', '25/09/2026', '25/09/2026'],
            ['Steam Wallet 100k', '', '', 'SR1', 'AAAA-0001', '', '22/09/2026'],
        ]);

    expect(RevealLogEntry::orderBy('id')->get()->map(fn (RevealLogEntry $entry) => [$entry->user_id, $entry->context, $entry->context_id, $entry->slot_id, $entry->reason])->slice(2)->values()->all())->toBe([
        [$this->seller->id, RevealContextType::Delivery, $deliveries[0]->id, $deliveries[0]->slot_id, "Tải TXT Phiếu xuất #{$dispatch->id}"],
        [$this->seller->id, RevealContextType::Delivery, $deliveries[1]->id, $deliveries[1]->slot_id, "Tải TXT Phiếu xuất #{$dispatch->id}"],
        [$this->seller->id, RevealContextType::Delivery, $deliveries[0]->id, $deliveries[0]->slot_id, "Tải CSV Phiếu xuất #{$dispatch->id}"],
        [$this->seller->id, RevealContextType::Delivery, $deliveries[1]->id, $deliveries[1]->slot_id, "Tải CSV Phiếu xuất #{$dispatch->id}"],
    ]);
});

it('màn kết quả dạng che: Copy tất cả trả nội dung theo Mẫu giao hàng và ghi Nhật ký xem mã cho mọi Slot', function () {
    dispatchStock($this->steam, implode("\n", array_map(fn (int $i) => sprintf("SR%d\tCODE-%04d", $i, $i), range(1, 50))));
    $dispatch = $this->manual->create($this->seller, dispatchOrder($this->zalo, [[$this->steam, 50]]));
    $reveal = app(ContentReveal::class);
    $reveal->revealDispatchResult($this->seller, $dispatch);

    $text = $reveal->copyAllDispatchResult($this->seller, $dispatch);

    expect($text)->toStartWith("Serial: SR1\nMã thẻ: CODE-0001\n\n----------\n\nSerial: SR2\nMã thẻ: CODE-0002")
        ->and($text)->toEndWith("Serial: SR50\nMã thẻ: CODE-0050")
        ->and(RevealLogEntry::count())->toBe(50)
        ->and(RevealLogEntry::distinct()->count('slot_id'))->toBe(50)
        ->and(RevealLogEntry::pluck('reason')->unique()->all())->toBe(["Copy tất cả Phiếu xuất #{$dispatch->id}"]);
});

it('chỉ người tạo phiếu Copy tất cả và tải file được, từ màn kết quả trong 30 phút sau khi xuất kho', function () {
    dispatchStock($this->steam, "SR1\tAAAA-0001\nSR2\tAAAA-0002");
    $reveal = app(ContentReveal::class);
    $unrevealed = $this->manual->create($this->seller, dispatchOrder($this->zalo, [[$this->steam, 1]]));
    $dispatch = $this->manual->create($this->seller, dispatchOrder($this->zalo, [[$this->steam, 1]]));
    $reveal->revealDispatchResult($this->seller, $dispatch);
    $logged = RevealLogEntry::count();
    $closed = 'Chỉ lấy được nội dung từ màn kết quả, trong 30 phút sau khi màn kết quả hiện.';

    expect(fn () => $reveal->exportDispatchResult($this->seller, $unrevealed, DispatchResultFormat::Txt))->toThrow(InvalidReveal::class, $closed)
        ->and(fn () => $reveal->copyAllDispatchResult($this->seller, $unrevealed))->toThrow(InvalidReveal::class, $closed)
        ->and(fn () => $reveal->exportDispatchResult($this->admin, $dispatch, DispatchResultFormat::Csv))->toThrow(InvalidReveal::class, 'Chỉ người tạo Phiếu xuất xem được màn kết quả.')
        ->and(fn () => $reveal->copyAllDispatchResult(staffMember(Role::NhapKho), $dispatch))->toThrow(MissingRole::class);

    $this->travel(30)->minutes();

    expect($reveal->exportDispatchResult($this->seller, $dispatch, DispatchResultFormat::Csv)->contents)->toContain('AAAA-0002')
        ->and($reveal->copyAllDispatchResult($this->seller, $dispatch))->toBe("Serial: SR2\nMã thẻ: AAAA-0002");

    $this->travel(1)->minute();

    expect(fn () => $reveal->exportDispatchResult($this->seller, $dispatch, DispatchResultFormat::Txt))->toThrow(InvalidReveal::class, $closed)
        ->and(fn () => $reveal->copyAllDispatchResult($this->seller, $dispatch))->toThrow(InvalidReveal::class, $closed)
        ->and(RevealLogEntry::count())->toBe($logged + 2);
});
