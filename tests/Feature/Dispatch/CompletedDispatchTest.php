<?php

use App\Inventory\Access\MissingRole;
use App\Inventory\Access\Role;
use App\Inventory\Catalog\ContentFieldDraft;
use App\Inventory\Catalog\ContentFieldType;
use App\Inventory\Catalog\ProductCatalog;
use App\Inventory\Catalog\ProductDraft;
use App\Inventory\Catalog\ProductType;
use App\Inventory\Catalog\SupplierDirectory;
use App\Inventory\Dispatch\DeliveryLookup;
use App\Inventory\Dispatch\DispatchDraft;
use App\Inventory\Dispatch\DispatchEdit;
use App\Inventory\Dispatch\DispatchEditor;
use App\Inventory\Dispatch\DispatchLineDraft;
use App\Inventory\Dispatch\DispatchProblem;
use App\Inventory\Dispatch\DispatchStatus;
use App\Inventory\Dispatch\InvalidDispatch;
use App\Inventory\Dispatch\ManualDispatch;
use App\Inventory\Dispatch\SalesChannelDirectory;
use App\Inventory\Dispatch\SalesChannelDraft;
use App\Inventory\Encryption\KeyFingerprints;
use App\Inventory\Encryption\Normalization;
use App\Inventory\Intake\BatchDraft;
use App\Inventory\Intake\BatchIntake;
use App\Inventory\Intake\BatchLineDraft;
use App\Inventory\Reveal\ContentReveal;
use App\Inventory\Reveal\InvalidReveal;
use App\Inventory\Reveal\RevealContextType;
use App\Models\Delivery;
use App\Models\Dispatch;
use App\Models\DispatchLine;
use App\Models\DispatchRevision;
use App\Models\Product;
use App\Models\RevealLogEntry;
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
    $this->otherSeller = staffMember(Role::BanHang);
    $this->manual = app(ManualDispatch::class);
    $this->reveal = app(ContentReveal::class);
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
        normalization: new Normalization(caseInsensitive: true, stripSeparators: true),
    ));
    $this->netflix = $catalog->create($this->admin, new ProductDraft(
        type: ProductType::Account,
        name: 'Netflix 1 tháng',
        code: 'NETFLIX-1M',
        fields: [
            new ContentFieldDraft('username', 'Tên đăng nhập', ContentFieldType::Email, sensitive: false, dedupeKey: true),
            new ContentFieldDraft('password', 'Mật khẩu'),
        ],
        defaultSlots: 2,
        warrantyDays: 30,
    ));
});

function completedStock(Product $product, string $content): void
{
    $intake = app(BatchIntake::class);
    $intake->confirm(test()->admin, $intake->submit(test()->admin, new BatchDraft(
        supplier: test()->supplier,
        receivedOn: CarbonImmutable::today(),
        lines: [new BatchLineDraft($product, 100_000, $content)],
    )));
}

/**
 * @param  list<array{0: Product, 1: int, 2?: ?int}>  $lines
 */
function completedDispatch(array $lines, ?string $ref = null, ?string $customer = null, mixed $channel = null): Dispatch
{
    return test()->manual->create(test()->seller, new DispatchDraft(
        channel: $channel ?? test()->zalo,
        externalRef: $ref,
        lines: array_map(fn (array $line): DispatchLineDraft => new DispatchLineDraft(...$line), $lines),
        customer: $customer,
    ));
}

it('Xem mã lần giao: Bán hàng xem được mọi Phiếu xuất trong Hạn bảo hành, mỗi lần ghi Nhật ký xem mã ngữ cảnh Giao hàng', function () {
    completedStock($this->steam, "SR1\tAAAA-0001");
    $dispatch = completedDispatch([[$this->steam, 1]]);
    $delivery = Delivery::sole();

    $content = $this->reveal->revealDelivery($this->otherSeller, $delivery);

    expect($content->fields)->toBe(['Serial' => 'SR1', 'Mã thẻ' => 'AAAA-0001'])
        ->and($content->message)->toBe("Serial: SR1\nMã thẻ: AAAA-0001")
        ->and($content->warrantyEndsOn->toDateString())->toBe('2026-09-22');

    $this->reveal->revealDelivery($this->otherSeller, $delivery);

    expect(RevealLogEntry::orderBy('id')->get()->map(fn (RevealLogEntry $entry) => [$entry->user_id, $entry->context, $entry->context_id, $entry->slot_id, $entry->reason])->all())->toBe([
        [$this->otherSeller->id, RevealContextType::Delivery, $delivery->id, $delivery->slot_id, "Xem mã Phiếu xuất #{$dispatch->id}"],
        [$this->otherSeller->id, RevealContextType::Delivery, $delivery->id, $delivery->slot_id, "Xem mã Phiếu xuất #{$dispatch->id}"],
    ])
        ->and(json_encode(DB::table('reveal_log_entries')->get()))->not->toContain('AAAA-0001');
});

it('Xem mã lần giao quá Hạn bảo hành chỉ Quản trị; Nhập kho không xem được', function () {
    completedStock($this->steam, "SR1\tAAAA-0001");
    completedDispatch([[$this->steam, 1]]);
    $delivery = Delivery::sole();

    expect(fn () => $this->reveal->revealDelivery(staffMember(Role::NhapKho), $delivery))->toThrow(MissingRole::class);

    $this->travelTo(CarbonImmutable::parse('2026-09-22 23:59'));

    expect($this->reveal->canRevealDelivery($this->seller, $delivery))->toBeTrue()
        ->and($this->reveal->revealDelivery($this->seller, $delivery)->fields)->toHaveKey('Mã thẻ');

    $this->travelTo(CarbonImmutable::parse('2026-09-23 00:00'));

    expect($this->reveal->canRevealDelivery($this->seller, $delivery))->toBeFalse()
        ->and(fn () => $this->reveal->revealDelivery($this->seller, $delivery))
        ->toThrow(InvalidReveal::class, 'Lần giao đã quá Hạn bảo hành 22/09/2026; chỉ Quản trị xem được mã.')
        ->and($this->reveal->canRevealDelivery($this->admin, $delivery))->toBeTrue()
        ->and($this->reveal->revealDelivery($this->admin, $delivery)->fields)->toHaveKey('Mã thẻ')
        ->and(RevealLogEntry::count())->toBe(2);
});

it('sửa phiếu Hoàn tất: mã đơn ngoài, khách, ghi chú, Giá bán; mỗi trường đổi ghi lịch sử ai, khi nào, cũ → mới, không ghi Sổ biến động kho', function () {
    completedStock($this->steam, "SR1\tAAAA-0001\nSR2\tAAAA-0002");
    completedStock($this->netflix, "a@shop.test\tpw");
    $dispatch = completedDispatch([[$this->steam, 2, 190_000], [$this->netflix, 1]], ref: 'ZL-001', customer: 'Anh Minh');
    [$steamLine, $netflixLine] = $dispatch->lines->all();
    $ledger = StockLedgerEntry::count();
    $editor = app(DispatchEditor::class);
    $this->travel(1)->hour();

    $edited = $editor->edit($this->otherSeller, $dispatch, new DispatchEdit(
        externalRef: ' ZL-002 ',
        customer: 'Anh Minh 0901234567',
        note: 'Khách hỏi lại',
        salePrices: [$steamLine->id => 180_000, $netflixLine->id => 90_000],
    ));

    $history = fn () => DispatchRevision::orderBy('id')->get()->map(fn (DispatchRevision $revision) => [
        $revision->actor_id, $revision->occurred_at->format('d/m/Y H:i'), $revision->label(), $revision->old_value, $revision->new_value,
    ])->all();

    expect($edited)->external_ref->toBe('ZL-002')->customer->toBe('Anh Minh 0901234567')->note->toBe('Khách hỏi lại')
        ->and($edited->lines->map(fn (DispatchLine $line) => [$line->product_id, $line->quantity, $line->sale_price])->all())->toBe([
            [$this->steam->id, 2, 180_000],
            [$this->netflix->id, 1, 90_000],
        ])
        ->and($edited->totalSalePrice())->toBe(270_000)
        ->and(Delivery::count())->toBe(3)
        ->and(StockLedgerEntry::count())->toBe($ledger)
        ->and($history())->toBe([
            [$this->otherSeller->id, '15/09/2026 11:00', 'Mã đơn ngoài', 'ZL-001', 'ZL-002'],
            [$this->otherSeller->id, '15/09/2026 11:00', 'Khách', 'Anh Minh', 'Anh Minh 0901234567'],
            [$this->otherSeller->id, '15/09/2026 11:00', 'Ghi chú', null, 'Khách hỏi lại'],
            [$this->otherSeller->id, '15/09/2026 11:00', 'Giá bán · Steam Wallet 100k', '190000', '180000'],
            [$this->otherSeller->id, '15/09/2026 11:00', 'Giá bán · Netflix 1 tháng', null, '90000'],
        ]);

    $editor->edit($this->seller, $edited, new DispatchEdit('ZL-002', 'Anh Minh 0901234567', '  ', [$netflixLine->id => null]));

    expect(array_slice($history(), 5))->toBe([
        [$this->seller->id, '15/09/2026 11:00', 'Ghi chú', 'Khách hỏi lại', null],
        [$this->seller->id, '15/09/2026 11:00', 'Giá bán · Netflix 1 tháng', '90000', null],
    ])
        ->and($edited->fresh()->lines->pluck('sale_price')->all())->toBe([180_000, null]);
});

it('sửa phiếu vẫn kiểm tra mã đơn ngoài trùng trong Kênh bán, không để trống, Giá bán không âm; chỉ phiếu Hoàn tất; Nhập kho không sửa được', function () {
    completedStock($this->steam, "SR1\tAAAA-0001\nSR2\tAAAA-0002\nSR3\tAAAA-0003");
    $first = completedDispatch([[$this->steam, 1]], ref: 'ZL-001');
    $dispatch = completedDispatch([[$this->steam, 1]], ref: 'ZL-002');
    $otherChannel = completedDispatch([[$this->steam, 1]], ref: 'SP-001', channel: $this->shopee);
    $editor = app(DispatchEditor::class);
    $edit = fn (mixed ...$changes) => new DispatchEdit(...['externalRef' => 'ZL-002', 'customer' => null, 'note' => null, 'salePrices' => [], ...$changes]);
    $problems = function (Closure $call): array {
        try {
            $call();
        } catch (InvalidDispatch $exception) {
            return $exception->problems;
        }

        return [];
    };

    expect($problems(fn () => $editor->edit($this->seller, $dispatch, $edit(
        externalRef: 'ZL-001',
        salePrices: [$dispatch->lines->sole()->id => -1, $first->lines->sole()->id => 5],
    ))))->toEqual([
        new DispatchProblem('Mã đơn ngoài "ZL-001" đã có trong Kênh bán "Zalo".', $first->id),
        new DispatchProblem('Giá bán của Dòng xuất "Steam Wallet 100k" không được âm.'),
        new DispatchProblem("Dòng xuất #{$first->lines->sole()->id} không thuộc Phiếu xuất #{$dispatch->id}."),
    ])
        ->and(fn () => $editor->edit($this->seller, $dispatch, $edit(externalRef: '  ')))->toThrow(InvalidDispatch::class, 'Mã đơn ngoài không được để trống.')
        ->and(fn () => $editor->edit($this->seller, $dispatch, $edit(externalRef: str_repeat('X', 101))))->toThrow(InvalidDispatch::class, 'Mã đơn ngoài dài quá 100 ký tự.')
        ->and(fn () => $editor->edit(staffMember(Role::NhapKho), $dispatch, $edit()))->toThrow(MissingRole::class)
        ->and($editor->edit($this->seller, $dispatch, $edit(externalRef: 'SP-001'))->external_ref)->toBe('SP-001')
        ->and($otherChannel->fresh()->external_ref)->toBe('SP-001');

    DB::table('dispatches')->where('id', $first->id)->update(['status' => DispatchStatus::Cancelled->value]);

    expect(fn () => $editor->edit($this->seller, $first, $edit(externalRef: 'ZL-001', customer: 'Khách')))
        ->toThrow(InvalidDispatch::class, 'Chỉ sửa được Phiếu xuất Hoàn tất.')
        ->and(DispatchRevision::count())->toBe(1)
        ->and($first->fresh()->customer)->toBeNull();
});

it('tìm theo Khoá chống trùng: chuẩn hoá theo từng Sản phẩm, khớp chính xác, trả lần Giao hàng và Phiếu xuất, không ghi Nhật ký xem mã', function () {
    completedStock($this->steam, "SR1\tAAAA-0001\nSR2\tAAAA-0002");
    completedStock($this->netflix, "a@shop.test\tpw");
    $steamDispatch = completedDispatch([[$this->steam, 1]], ref: 'ZL-001');
    $netflixFirst = completedDispatch([[$this->netflix, 1]], ref: 'ZL-002');
    $netflixSecond = completedDispatch([[$this->netflix, 1]], ref: 'ZL-003');
    $lookup = app(DeliveryLookup::class);
    $found = fn (string $value) => $lookup->byDedupeKey($this->seller, $value)
        ->map(fn (Delivery $delivery) => [$delivery->dispatchLine->dispatch->external_ref, $delivery->dispatchLine->product->name, $delivery->stock_unit_id])
        ->all();
    $steamUnit = Delivery::whereIn('dispatch_line_id', $steamDispatch->lines()->pluck('id'))->sole()->stock_unit_id;
    $netflixUnit = Delivery::whereIn('dispatch_line_id', $netflixFirst->lines()->pluck('id'))->sole()->stock_unit_id;

    expect($found(" aaaa 0001\u{200B} "))->toBe([['ZL-001', 'Steam Wallet 100k', $steamUnit]])
        ->and($found('a@shop.test'))->toBe([['ZL-002', 'Netflix 1 tháng', $netflixUnit], ['ZL-003', 'Netflix 1 tháng', $netflixUnit]])
        ->and($found('a@shop. test'))->toBe([])
        ->and($found('AAAA-000'))->toBe([])
        ->and($found('AAAA-0002'))->toBe([])
        ->and($found('   '))->toBe([])
        ->and(fn () => $lookup->byDedupeKey(staffMember(Role::NhapKho), 'AAAA-0001'))->toThrow(MissingRole::class)
        ->and(RevealLogEntry::count())->toBe(0);
});

it('tìm Phiếu xuất theo trường không nhạy cảm của hàng đã giao; trường nhạy cảm không tìm được', function () {
    completedStock($this->steam, "SR1\tAAAA-0001\nSR_9%\tAAAA-0002\nSR3\tAAAA-0003");
    completedStock($this->netflix, "a@shop.test\tpw");
    completedDispatch([[$this->steam, 2]], ref: 'ZL-001');
    completedDispatch([[$this->netflix, 2]], ref: 'ZL-002');
    $refs = fn (string $term) => Dispatch::query()->whereDeliveredContent($term)->orderBy('id')->pluck('external_ref')->all();

    expect($refs('sr1'))->toBe(['ZL-001'])
        ->and($refs('shop.test'))->toBe(['ZL-002'])
        ->and($refs('SR3'))->toBe([])
        ->and($refs('AAAA'))->toBe([])
        ->and($refs('pw'))->toBe([])
        ->and($refs('_9%'))->toBe(['ZL-001'])
        ->and($refs('%'))->toBe(['ZL-001'])
        ->and($refs('S_1'))->toBe([]);
});
