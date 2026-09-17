<?php

use App\Inventory\Access\Role;
use App\Inventory\Api\ApiKeys;
use App\Inventory\Catalog\ContentFieldDraft;
use App\Inventory\Catalog\ProductCatalog;
use App\Inventory\Catalog\StockForm;
use App\Inventory\Catalog\SupplierDirectory;
use App\Inventory\Dispatch\DispatchLineKind;
use App\Inventory\Dispatch\DispatchStatus;
use App\Inventory\Dispatch\SalesChannelDirectory;
use App\Inventory\Dispatch\SalesChannelDraft;
use App\Inventory\Dispatch\SalesChannelType;
use App\Inventory\Encryption\KeyFingerprints;
use App\Inventory\Intake\ExpiryRule;
use App\Inventory\Reveal\RevealContextType;
use App\Inventory\Stock\SlotStatus;
use App\Models\ApiKey;
use App\Models\Delivery;
use App\Models\Dispatch;
use App\Models\DispatchLine;
use App\Models\RevealLogEntry;
use App\Models\Slot;
use App\Models\StockLedgerEntry;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

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

    $this->netflix = productOf(
        StockForm::Account,
        [
            new ContentFieldDraft('username', 'Tên đăng nhập', sensitive: false, dedupeKey: true),
            new ContentFieldDraft('password', 'Mật khẩu'),
        ],
        'Netflix 1 tháng',
        'NETFLIX-1M',
        defaultSlots: 2,
        warrantyDays: 30,
        deliveryTemplate: "{{san_pham}} cho đơn {{ma_don}}\nTài khoản: {{username}} / {{password}}\nHạn sử dụng: {{han_su_dung}} · Bảo hành tới {{han_bao_hanh}}",
    );
    $this->steam = productOf(
        StockForm::OneTimeCode,
        [new ContentFieldDraft('code', 'Mã thẻ', dedupeKey: true)],
        'Steam Wallet 100k',
        'STEAM-100K',
    );
});

/**
 * Gửi một đơn của website vào kho.
 *
 * @param  array<string, mixed>  $order
 */
function postOrder(array $order, ?string $secret = null): TestResponse
{
    return apiAs($secret)->postJson('/api/v1/dispatches', $order);
}

/**
 * Đơn một dòng Netflix quen dùng trong file này.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function netflixOrder(array $overrides = []): array
{
    return [
        'external_ref' => 'WEB-1001',
        'customer' => 'Anh Minh 0901234567',
        'lines' => [['product_code' => 'NETFLIX-1M', 'quantity' => 2, 'sale_price' => 190_000]],
        ...$overrides,
    ];
}

it('giữ và giao ngay trong một lần gọi: phiếu Hoàn tất của Khoá API, Slot Đã giao, Sổ biến động kho ghi tác nhân Khoá API', function () {
    stockUp($this->netflix, "a@shop.test\tpw1");

    $response = postOrder(netflixOrder());

    $dispatch = Dispatch::sole();
    $key = ApiKey::sole();

    $response->assertCreated()
        ->assertJsonPath('dispatch.id', $dispatch->id)
        ->assertJsonPath('dispatch.external_ref', 'WEB-1001')
        ->assertJsonPath('dispatch.status', 'completed')
        ->assertJsonPath('dispatch.lines', [['product_code' => 'NETFLIX-1M', 'quantity' => 2, 'sale_price' => 190_000]])
        ->assertJsonCount(2, 'dispatch.deliveries');

    expect($dispatch)
        ->status->toBe(DispatchStatus::Completed)
        ->sales_channel_id->toBe($this->website->id)
        ->customer->toBe('Anh Minh 0901234567')
        // "Ai" của phiếu là Khoá API, không phải nhân viên.
        ->created_by->toBeNull()
        ->created_by_api_key_id->toBe($key->id)
        ->and(DispatchLine::sole())->kind->toBe(DispatchLineKind::Sale)->quantity->toBe(2)->sale_price->toBe(190_000)
        ->and(Delivery::count())->toBe(2)
        ->and(Slot::where('status', SlotStatus::Delivered)->count())->toBe(2)
        ->and(Slot::where('status', SlotStatus::InStock)->count())->toBe(0);

    $ledger = StockLedgerEntry::query()->where('to_status', SlotStatus::Delivered->value)->get();

    expect($ledger)->toHaveCount(2)
        ->and($ledger->map(fn (StockLedgerEntry $row) => [$row->actor_id, $row->api_key_id, $row->reason])->unique()->values()->all())
        ->toBe([[null, $key->id, "Giao hàng theo Phiếu xuất #{$dispatch->id}"]]);
});

it('nội dung mỗi Slot: tin nhắn ghép Mẫu giao hàng, Trường nội dung có khoá ổn định, Hạn sử dụng và Hạn bảo hành', function () {
    stockUp($this->netflix, "a@shop.test\tmatkhau", ExpiryRule::on(CarbonImmutable::parse('2026-12-31')));

    $response = postOrder(netflixOrder(['lines' => [['product_code' => 'NETFLIX-1M', 'quantity' => 1, 'sale_price' => 95_000]]]));

    $response->assertCreated()
        ->assertJsonPath('dispatch.deliveries.0.product_code', 'NETFLIX-1M')
        ->assertJsonPath('dispatch.deliveries.0.fields', ['username' => 'a@shop.test', 'password' => 'matkhau'])
        ->assertJsonPath('dispatch.deliveries.0.expires_on', '2026-12-31')
        // Hạn bảo hành = ngày giao + 30 ngày bảo hành, không vượt Hạn sử dụng.
        ->assertJsonPath('dispatch.deliveries.0.warranty_ends_on', '2026-10-16')
        ->assertJsonPath('dispatch.deliveries.0.text', implode("\n", [
            'Netflix 1 tháng cho đơn WEB-1001',
            'Tài khoản: a@shop.test / matkhau',
            'Hạn sử dụng: 31/12/2026 · Bảo hành tới 16/10/2026',
        ]))
        ->assertJsonPath('dispatch.deliveries.0.id', Delivery::sole()->id);
});

it('mỗi Slot trả về ghi một dòng Nhật ký xem mã, tác nhân là Khoá API, ngữ cảnh Giao hàng', function () {
    stockUp($this->netflix, "a@shop.test\tpw1");

    postOrder(netflixOrder())->assertCreated();

    $entries = RevealLogEntry::query()->orderBy('id')->get();
    $key = ApiKey::sole();

    expect($entries)->toHaveCount(2)
        ->and($entries->pluck('context')->unique()->all())->toBe([RevealContextType::Delivery])
        ->and($entries->pluck('user_id')->unique()->all())->toBe([null])
        ->and($entries->pluck('api_key_id')->unique()->all())->toBe([$key->id])
        ->and($entries->pluck('slot_id')->all())->toBe(Delivery::pluck('slot_id')->all())
        ->and($entries->first()->actorLabel())->toBe("Khoá API #{$key->id}")
        // Nhật ký không bao giờ chứa nội dung mã.
        ->and(json_encode($entries))->not->toContain('pw1');
});

it('gọi lại với Dòng xuất giống hệt trả đúng phiếu cũ và nội dung cũ, không giao thêm', function () {
    stockUp($this->netflix, "a@shop.test\tpw1\nb@shop.test\tpw2");

    $first = postOrder(netflixOrder())->assertCreated();
    $again = postOrder(netflixOrder())->assertOk();

    expect($again->json('dispatch.id'))->toBe($first->json('dispatch.id'))
        ->and($again->json('dispatch.deliveries'))->toBe($first->json('dispatch.deliveries'))
        ->and(Dispatch::count())->toBe(1)
        ->and(Delivery::count())->toBe(2)
        ->and(Slot::where('status', SlotStatus::InStock)->count())->toBe(2)
        // Lần trả nội dung nào cũng ghi Nhật ký xem mã.
        ->and(RevealLogEntry::count())->toBe(4);
});

it('gửi lại mã đơn cũ sau Hạn bảo hành vẫn liệt kê lần giao nhưng không trả nội dung', function () {
    stockUp($this->netflix, "a@shop.test\tpw1");

    $first = postOrder(netflixOrder())->assertCreated();

    expect($first->json('dispatch.deliveries'))->toHaveCount(2)
        ->and(RevealLogEntry::count())->toBe(2);

    // Bảo hành 30 ngày kể từ 16/09/2026, nên 17/10 là đã quá hạn.
    $this->travelTo(CarbonImmutable::parse('2026-10-17 09:00'));

    $again = postOrder(netflixOrder())->assertOk();
    $deliveries = collect($again->json('dispatch.deliveries'));

    expect($again->json('dispatch.id'))->toBe($first->json('dispatch.id'))
        ->and($again->json('dispatch.external_ref'))->toBe('WEB-1001')
        // Lần giao vẫn được liệt kê để website kể đúng lịch sử đơn, chỉ là không kèm nội dung.
        ->and($deliveries)->toHaveCount(2)
        ->and($deliveries->pluck('text')->all())->toBe([null, null])
        ->and($deliveries->pluck('fields')->all())->toBe([null, null])
        ->and($deliveries->pluck('status')->unique()->all())->toBe(['active'])
        // Không trả nội dung thì cũng không có dòng Nhật ký xem mã mới.
        ->and(RevealLogEntry::count())->toBe(2);
});

it('gọi lại cùng mã đơn nhưng Dòng xuất khác thì xung đột, không giao gì thêm', function (array $lines) {
    stockUp($this->netflix, "a@shop.test\tpw1\nb@shop.test\tpw2");
    stockUp($this->steam, 'AAAA-0001');

    $first = postOrder(netflixOrder())->assertCreated();

    postOrder(netflixOrder(['lines' => $lines]))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'dispatch_conflict')
        ->assertJsonPath('error.dispatch_id', $first->json('dispatch.id'));

    expect(Dispatch::count())->toBe(1)
        ->and(Delivery::count())->toBe(2);
})->with([
    'khác số lượng' => [[['product_code' => 'NETFLIX-1M', 'quantity' => 1, 'sale_price' => 190_000]]],
    'khác Giá bán' => [[['product_code' => 'NETFLIX-1M', 'quantity' => 2, 'sale_price' => 180_000]]],
    'khác Sản phẩm' => [[['product_code' => 'STEAM-100K', 'quantity' => 1, 'sale_price' => 190_000]]],
    'thêm dòng' => [[
        ['product_code' => 'NETFLIX-1M', 'quantity' => 2, 'sale_price' => 190_000],
        ['product_code' => 'STEAM-100K', 'quantity' => 1, 'sale_price' => 100_000],
    ]],
]);

it('hết hàng: báo từng dòng thiếu kèm số hiện có, không tạo phiếu và không chiếm mã đơn', function () {
    stockUp($this->netflix, "a@shop.test\tpw1");
    stockUp($this->steam, 'AAAA-0001');

    postOrder(netflixOrder(['lines' => [
        ['product_code' => 'NETFLIX-1M', 'quantity' => 5, 'sale_price' => 190_000],
        ['product_code' => 'STEAM-100K', 'quantity' => 3, 'sale_price' => 100_000],
    ]]))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'out_of_stock')
        ->assertJsonPath('error.shortages', [
            ['product_code' => 'NETFLIX-1M', 'needed' => 5, 'available' => 2],
            ['product_code' => 'STEAM-100K', 'needed' => 3, 'available' => 1],
        ]);

    expect(Dispatch::count())->toBe(0)
        ->and(Delivery::count())->toBe(0)
        ->and(Slot::where('status', SlotStatus::InStock)->count())->toBe(3);

    // Mã đơn chưa bị chiếm nên website gửi lại đúng mã ấy được khi kho có hàng.
    stockUp($this->netflix, "b@shop.test\tpw2\nc@shop.test\tpw3");

    postOrder(netflixOrder())->assertCreated()->assertJsonPath('dispatch.external_ref', 'WEB-1001');
});

it('Kênh bán bắt buộc Giá bán thì Dòng xuất thiếu Giá bán là lỗi', function () {
    stockUp($this->netflix, "a@shop.test\tpw1");

    postOrder(netflixOrder(['lines' => [['product_code' => 'NETFLIX-1M', 'quantity' => 2]]]))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'invalid_request')
        ->assertJsonPath('error.problems', ['Kênh bán "Website" bắt buộc Giá bán; Dòng xuất "NETFLIX-1M" chưa có.']);

    expect(Dispatch::count())->toBe(0);

    // Tắt cờ thì đơn không có Giá bán vào được.
    $this->channels->update($this->admin, $this->website, new SalesChannelDraft('Website', SalesChannelType::Api, requiresSalePrice: false));

    postOrder(netflixOrder(['lines' => [['product_code' => 'NETFLIX-1M', 'quantity' => 2]]]))->assertCreated();

    expect(DispatchLine::sole()->sale_price)->toBeNull();
});

it('báo thiếu Giá bán đúng Dòng xuất, kể cả khi đơn có Mã sản phẩm lạ đứng trước', function () {
    stockUp($this->netflix, "a@shop.test\tpw1");

    postOrder(netflixOrder(['lines' => [
        ['product_code' => 'KHONG-CO', 'quantity' => 1, 'sale_price' => 1_000],
        ['product_code' => 'NETFLIX-1M', 'quantity' => 2],
    ]]))
        ->assertStatus(422)
        ->assertJsonPath('error.problems', [
            'Không có Sản phẩm với Mã sản phẩm "KHONG-CO".',
            'Kênh bán "Website" bắt buộc Giá bán; Dòng xuất "NETFLIX-1M" chưa có.',
        ]);

    expect(Dispatch::count())->toBe(0);
});

it('báo lỗi đơn không hợp lệ và không tạo phiếu', function (array $order, string $problem) {
    stockUp($this->netflix, "a@shop.test\tpw1");

    postOrder(netflixOrder($order))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'invalid_request')
        ->assertJsonPath('error.problems', [$problem]);

    expect(Dispatch::count())->toBe(0)
        ->and(Slot::where('status', SlotStatus::InStock)->count())->toBe(2);
})->with([
    'thiếu mã đơn ngoài' => [['external_ref' => '  '], 'Đơn qua API phải có mã đơn ngoài.'],
    'không có Dòng xuất' => [['lines' => []], 'Phiếu xuất phải có ít nhất một Dòng xuất.'],
    'Mã sản phẩm lạ' => [
        ['lines' => [['product_code' => 'KHONG-CO', 'quantity' => 1, 'sale_price' => 1_000]]],
        'Không có Sản phẩm với Mã sản phẩm "KHONG-CO".',
    ],
    'số lượng dưới 1' => [
        ['lines' => [['product_code' => 'NETFLIX-1M', 'quantity' => 0, 'sale_price' => 1_000]]],
        'Số lượng của Dòng xuất "Netflix 1 tháng" phải từ 1 trở lên.',
    ],
    'Giá bán âm' => [
        ['lines' => [['product_code' => 'NETFLIX-1M', 'quantity' => 1, 'sale_price' => -1]]],
        'Giá bán của Dòng xuất "Netflix 1 tháng" không được âm.',
    ],
    'hai dòng cùng Sản phẩm' => [
        ['lines' => [
            ['product_code' => 'NETFLIX-1M', 'quantity' => 1, 'sale_price' => 1_000],
            ['product_code' => 'NETFLIX-1M', 'quantity' => 1, 'sale_price' => 1_000],
        ]],
        'Sản phẩm "Netflix 1 tháng" có hai Dòng xuất; mỗi Sản phẩm một Dòng xuất.',
    ],
]);

it('Sản phẩm Ngừng bán không giao qua API', function () {
    stockUp($this->netflix, "a@shop.test\tpw1");
    app(ProductCatalog::class)->discontinue($this->admin, $this->netflix);

    postOrder(netflixOrder())
        ->assertStatus(422)
        ->assertJsonPath('error.problems', ['Sản phẩm "Netflix 1 tháng" đã Ngừng bán.']);

    expect(Dispatch::count())->toBe(0);
});
