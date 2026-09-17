<?php

use App\Inventory\Access\Role;
use App\Inventory\Api\ApiKeys;
use App\Inventory\Catalog\ContentFieldDraft;
use App\Inventory\Catalog\ProductCatalog;
use App\Inventory\Catalog\ProductDraft;
use App\Inventory\Catalog\ProductType;
use App\Inventory\Catalog\SupplierDirectory;
use App\Inventory\Dispatch\DispatchStatus;
use App\Inventory\Dispatch\SalesChannelDirectory;
use App\Inventory\Dispatch\SalesChannelDraft;
use App\Inventory\Dispatch\SalesChannelType;
use App\Inventory\Encryption\KeyFingerprints;
use App\Inventory\Stock\SellableStock;
use App\Inventory\Stock\SlotStatus;
use App\Models\ApiKey;
use App\Models\Delivery;
use App\Models\Dispatch;
use App\Models\RevealLogEntry;
use App\Models\Slot;
use App\Models\StockLedgerEntry;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

/*
 * Luồng hai bước của kênh API: website giữ hàng trong lúc khách thanh toán, rồi xác nhận hoặc huỷ.
 * Test ở tầng HTTP, dùng travel-time cho hết hạn giữ.
 */

beforeEach(function () {
    Storage::fake('intake');
    $this->seed(RoleSeeder::class);
    app(KeyFingerprints::class)->register();
    $this->travelTo(CarbonImmutable::parse('2026-09-16 10:00'));

    $this->admin = staffMember(Role::Owner);
    $this->channels = app(SalesChannelDirectory::class);
    $this->website = $this->channels->create($this->admin, new SalesChannelDraft('Website', SalesChannelType::Api));
    $this->secret = app(ApiKeys::class)->issue($this->admin, $this->website)->secret;
    $this->supplier = app(SupplierDirectory::class)->create($this->admin, 'Kinguin');

    $this->netflix = app(ProductCatalog::class)->create($this->admin, new ProductDraft(
        type: ProductType::Account,
        name: 'Netflix 1 tháng',
        code: 'NETFLIX-1M',
        fields: [
            new ContentFieldDraft('username', 'Tên đăng nhập', sensitive: false, dedupeKey: true),
            new ContentFieldDraft('password', 'Mật khẩu'),
        ],
        defaultSlots: 2,
        warrantyDays: 30,
    ));
});

/**
 * Gửi một đơn của website; mặc định là đơn xin Giữ hàng.
 *
 * @param  array<string, mixed>  $overrides
 */
function postHold(array $overrides = []): TestResponse
{
    return apiAs()->postJson('/api/v1/dispatches', [
        'external_ref' => 'WEB-1001',
        'hold' => true,
        'lines' => [['product_code' => 'NETFLIX-1M', 'quantity' => 2, 'sale_price' => 190_000]],
        ...$overrides,
    ]);
}

/**
 * Xác nhận hoặc huỷ một phiếu đã có, theo mã đơn ngoài.
 */
function postHoldAction(string $action, string $ref = 'WEB-1001'): TestResponse
{
    return apiAs()->postJson("/api/v1/dispatches/{$ref}/{$action}");
}

it('tạo phiếu Đang giữ: Slot Còn hàng → Đã giữ với hạn theo Kênh bán, chưa giao gì', function () {
    stockUp($this->netflix, "a@shop.test\tpw1");

    $response = postHold();

    $dispatch = Dispatch::sole();
    $key = ApiKey::sole();

    $response->assertCreated()
        ->assertJsonPath('dispatch.status', 'holding')
        ->assertJsonPath('dispatch.hold_expires_at', $dispatch->hold_expires_at?->toIso8601String())
        ->assertJsonPath('dispatch.deliveries', [])
        ->assertJsonPath('dispatch.lines', [['product_code' => 'NETFLIX-1M', 'quantity' => 2, 'sale_price' => 190_000]]);

    expect($dispatch)
        ->status->toBe(DispatchStatus::Holding)
        ->completed_at->toBeNull()
        ->created_by->toBeNull()
        ->created_by_api_key_id->toBe($key->id)
        // Hạn Giữ hàng mặc định của kênh API là 15 phút.
        ->and($dispatch->hold_expires_at?->toDateTimeString())->toBe('2026-09-16 10:15:00')
        ->and(Slot::where('status', SlotStatus::Reserved)->count())->toBe(2)
        ->and(Slot::where('status', SlotStatus::InStock)->count())->toBe(0)
        ->and(Delivery::count())->toBe(0)
        // Giữ hàng chưa trả nội dung nên chưa có dòng Nhật ký xem mã nào.
        ->and(RevealLogEntry::count())->toBe(0)
        // Đã giữ không thuộc Tồn bán được: đơn khác không lấy được phần hàng này.
        ->and(app(SellableStock::class)->count($this->netflix))->toBe(0);

    $ledger = StockLedgerEntry::query()->where('to_status', SlotStatus::Reserved->value)->get();

    expect($ledger)->toHaveCount(2)
        ->and($ledger->map(fn (StockLedgerEntry $row) => [$row->from_status, $row->actor_id, $row->api_key_id, $row->reason])->unique()->values()->all())
        ->toBe([[SlotStatus::InStock->value, null, $key->id, "Giữ hàng theo Phiếu xuất #{$dispatch->id}"]]);
});

it('xác nhận phiếu Đang giữ: giao đúng Slot đã giữ, phiếu Hoàn tất, trả nội dung và ghi Nhật ký xem mã', function () {
    stockUp($this->netflix, "a@shop.test\tpw1");
    postHold()->assertCreated();

    $reserved = Slot::where('status', SlotStatus::Reserved)->orderBy('id')->pluck('id')->all();
    $this->travel(5)->minutes();

    $response = postHoldAction('confirm');
    $dispatch = Dispatch::sole();

    $response->assertOk()
        ->assertJsonPath('dispatch.status', 'completed')
        ->assertJsonCount(2, 'dispatch.deliveries')
        ->assertJsonPath('dispatch.deliveries.0.status', 'active')
        ->assertJsonPath('dispatch.deliveries.0.fields', ['username' => 'a@shop.test', 'password' => 'pw1']);

    expect($dispatch)->status->toBe(DispatchStatus::Completed)
        ->and($dispatch->completed_at?->toDateTimeString())->toBe('2026-09-16 10:05:00')
        // Đúng những Slot đã giữ, không phải chọn lại từ đầu.
        ->and(Delivery::orderBy('slot_id')->pluck('slot_id')->all())->toBe($reserved)
        ->and(Slot::where('status', SlotStatus::Delivered)->count())->toBe(2)
        ->and(Slot::where('status', SlotStatus::Reserved)->count())->toBe(0)
        ->and(RevealLogEntry::count())->toBe(2);

    // Sổ biến động kho kể đủ hai chặng; chặng Đã giữ → Đã giao không được biến mất.
    expect(StockLedgerEntry::query()->whereNotNull('slot_id')->orderBy('id')->get()
        ->map(fn (StockLedgerEntry $row) => [$row->from_status, $row->to_status])->unique()->values()->all())
        ->toBe([
            [null, SlotStatus::InStock->value],
            [SlotStatus::InStock->value, SlotStatus::Reserved->value],
            [SlotStatus::Reserved->value, SlotStatus::Delivered->value],
        ]);
});

it('huỷ phiếu Đang giữ: nhả Slot về Còn hàng, phiếu Đã huỷ là trạng thái cuối', function () {
    stockUp($this->netflix, "a@shop.test\tpw1");
    postHold()->assertCreated();

    postHoldAction('cancel')
        ->assertOk()
        ->assertJsonPath('dispatch.status', 'cancelled')
        ->assertJsonPath('dispatch.deliveries', []);

    expect(Dispatch::sole()->status)->toBe(DispatchStatus::Cancelled)
        ->and(Slot::where('status', SlotStatus::InStock)->count())->toBe(2)
        ->and(Slot::where('status', SlotStatus::Reserved)->count())->toBe(0)
        ->and(Delivery::count())->toBe(0)
        ->and(app(SellableStock::class)->count($this->netflix))->toBe(2);

    // Mã đơn ngoài bị chiếm vĩnh viễn: gửi lại đơn ấy là xung đột, không phải một đơn mới.
    postHold()->assertStatus(409)->assertJsonPath('error.code', 'dispatch_conflict');
    postHoldAction('confirm')->assertStatus(409)->assertJsonPath('error.code', 'dispatch_conflict');

    expect(Dispatch::count())->toBe(1)
        ->and(Slot::where('status', SlotStatus::InStock)->count())->toBe(2);
});

it('job nhả Slot quá hạn giữ và chuyển phiếu sang Hết hạn giữ', function () {
    stockUp($this->netflix, "a@shop.test\tpw1");
    postHold()->assertCreated();
    $dispatchId = Dispatch::sole()->id;

    // Chưa tới hạn thì job không đụng gì.
    $this->travel(14)->minutes();
    $this->artisan('inventory:dispatches:release-holds')->assertSuccessful();

    expect(Dispatch::sole()->status)->toBe(DispatchStatus::Holding)
        ->and(Slot::where('status', SlotStatus::Reserved)->count())->toBe(2);

    $this->travel(2)->minutes();
    $this->artisan('inventory:dispatches:release-holds')->assertSuccessful();

    expect(Dispatch::sole()->status)->toBe(DispatchStatus::HoldExpired)
        ->and(Slot::where('status', SlotStatus::InStock)->count())->toBe(2)
        ->and(Slot::where('status', SlotStatus::Reserved)->count())->toBe(0)
        ->and(app(SellableStock::class)->count($this->netflix))->toBe(2);

    $released = StockLedgerEntry::query()->where('from_status', SlotStatus::Reserved->value)->get();

    expect($released)->toHaveCount(2)
        ->and($released->map(fn (StockLedgerEntry $row) => [$row->to_status, $row->actor_id, $row->api_key_id, $row->reason])->unique()->values()->all())
        // Hết hạn là việc của kho, không phải của nhân viên hay của Khoá API nào.
        ->toBe([[SlotStatus::InStock->value, null, null, "Hết hạn giữ Phiếu xuất #{$dispatchId}"]]);
});

it('xác nhận phiếu Hết hạn giữ thì giữ lại hàng rồi giao', function () {
    stockUp($this->netflix, "a@shop.test\tpw1");
    postHold()->assertCreated();

    $this->travel(16)->minutes();
    $this->artisan('inventory:dispatches:release-holds')->assertSuccessful();

    expect(Dispatch::sole()->status)->toBe(DispatchStatus::HoldExpired);

    postHoldAction('confirm')
        ->assertOk()
        ->assertJsonPath('dispatch.status', 'completed')
        ->assertJsonCount(2, 'dispatch.deliveries');

    expect(Dispatch::sole()->status)->toBe(DispatchStatus::Completed)
        ->and(Slot::where('status', SlotStatus::Delivered)->count())->toBe(2);
});

it('xác nhận phiếu Hết hạn giữ khi hàng đã bị đơn khác lấy mất thì báo hết hàng, phiếu giữ nguyên', function () {
    stockUp($this->netflix, "a@shop.test\tpw1");
    postHold()->assertCreated();

    $this->travel(16)->minutes();
    $this->artisan('inventory:dispatches:release-holds')->assertSuccessful();

    // Một đơn khác lấy hết phần hàng vừa được nhả ra.
    postHold(['external_ref' => 'WEB-1002', 'hold' => false])->assertCreated();

    postHoldAction('confirm')
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'out_of_stock')
        ->assertJsonPath('error.shortages', [['product_code' => 'NETFLIX-1M', 'needed' => 2, 'available' => 0]]);

    expect(Dispatch::query()->where('external_ref', 'WEB-1001')->sole()->status)->toBe(DispatchStatus::HoldExpired)
        ->and(Delivery::count())->toBe(2);
});

it('xác nhận lại phiếu Hoàn tất trả đúng nội dung cũ, không giao thêm, vẫn ghi Nhật ký xem mã', function () {
    stockUp($this->netflix, "a@shop.test\tpw1\nb@shop.test\tpw2");
    postHold()->assertCreated();

    $first = postHoldAction('confirm')->assertOk();
    $again = postHoldAction('confirm')->assertOk();

    expect($again->json('dispatch.deliveries'))->toBe($first->json('dispatch.deliveries'))
        ->and($again->json('dispatch.status'))->toBe('completed')
        ->and(Delivery::count())->toBe(2)
        ->and(Slot::where('status', SlotStatus::InStock)->count())->toBe(2)
        // Lần trả nội dung nào cũng ghi Nhật ký xem mã.
        ->and(RevealLogEntry::count())->toBe(4);
});

it('gửi lại đơn xin giữ với mã đơn đang giữ trả lại đúng phiếu ấy, không giữ thêm Slot', function () {
    stockUp($this->netflix, "a@shop.test\tpw1\nb@shop.test\tpw2");

    $first = postHold()->assertCreated();
    $again = postHold()->assertOk();

    expect($again->json('dispatch.id'))->toBe($first->json('dispatch.id'))
        ->and($again->json('dispatch.status'))->toBe('holding')
        ->and(Dispatch::count())->toBe(1)
        ->and(Slot::where('status', SlotStatus::Reserved)->count())->toBe(2)
        ->and(Slot::where('status', SlotStatus::InStock)->count())->toBe(2);
});

it('không huỷ được phiếu đã Hoàn tất: hàng đã ra khỏi kho', function () {
    stockUp($this->netflix, "a@shop.test\tpw1");
    postHold()->assertCreated();
    postHoldAction('confirm')->assertOk();

    postHoldAction('cancel')->assertStatus(409)->assertJsonPath('error.code', 'dispatch_conflict');

    expect(Dispatch::sole()->status)->toBe(DispatchStatus::Completed)
        ->and(Slot::where('status', SlotStatus::Delivered)->count())->toBe(2);
});

it('thao tác trên mã đơn chưa có phiếu nào thì báo không tìm thấy', function (string $action) {
    postHoldAction($action, 'WEB-KHONG-CO')
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'dispatch_not_found');
})->with(['confirm', 'cancel']);
