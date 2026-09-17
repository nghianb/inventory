<?php

use App\Inventory\Access\Role;
use App\Inventory\Api\ApiKeys;
use App\Inventory\Catalog\ContentFieldDraft;
use App\Inventory\Catalog\ProductCatalog;
use App\Inventory\Catalog\ProductDraft;
use App\Inventory\Catalog\ProductType;
use App\Inventory\Catalog\SupplierDirectory;
use App\Inventory\Dispatch\CorrectionDraft;
use App\Inventory\Dispatch\CorrectiveDelivery;
use App\Inventory\Dispatch\SalesChannelDirectory;
use App\Inventory\Dispatch\SalesChannelDraft;
use App\Inventory\Dispatch\SalesChannelType;
use App\Inventory\Encryption\KeyFingerprints;
use App\Models\Delivery;
use App\Models\RevealLogEntry;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

/*
 * Đọc lại phiếu cho trang đơn của khách: website hỏi lại kho bằng mã đơn của nó và nhận về mọi lần
 * Giao hàng kèm trạng thái. Test ở tầng HTTP, dùng travel-time cho Hạn bảo hành.
 */

beforeEach(function () {
    Storage::fake('intake');
    $this->seed(RoleSeeder::class);
    app(KeyFingerprints::class)->register();
    $this->travelTo(CarbonImmutable::parse('2026-09-16 10:00'));

    $this->admin = staffMember(Role::Owner);
    $this->seller = staffMember(Role::BanHang);
    $this->website = app(SalesChannelDirectory::class)->create($this->admin, new SalesChannelDraft('Website', SalesChannelType::Api));
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
 * Gửi một đơn giữ-và-giao-ngay để có phiếu mà đọc lại.
 *
 * @param  array<string, mixed>  $overrides
 */
function postReadOrder(array $overrides = []): TestResponse
{
    return apiAs()->postJson('/api/v1/dispatches', [
        'external_ref' => 'WEB-2001',
        'lines' => [['product_code' => 'NETFLIX-1M', 'quantity' => 2, 'sale_price' => 190_000]],
        ...$overrides,
    ]);
}

function readDispatch(string $ref = 'WEB-2001'): TestResponse
{
    return apiAs()->getJson("/api/v1/dispatches/{$ref}");
}

it('đọc lại phiếu trong Hạn bảo hành trả nội dung từng lần giao và ghi Nhật ký xem mã', function () {
    stockUp($this->netflix, "a@shop.test\tpw1");
    postReadOrder()->assertCreated();

    $this->travel(1)->day();
    $response = readDispatch();

    $response->assertOk()
        ->assertJsonPath('dispatch.external_ref', 'WEB-2001')
        ->assertJsonPath('dispatch.status', 'completed')
        ->assertJsonCount(2, 'dispatch.deliveries')
        ->assertJsonPath('dispatch.deliveries.0.status', 'active')
        ->assertJsonPath('dispatch.deliveries.0.replaces_delivery_id', null)
        ->assertJsonPath('dispatch.deliveries.0.fields', ['username' => 'a@shop.test', 'password' => 'pw1'])
        ->assertJsonPath('dispatch.deliveries.0.warranty_ends_on', '2026-10-16');

    // Hai lần trả nội dung lúc giao, hai lần nữa lúc đọc lại.
    expect(RevealLogEntry::count())->toBe(4);
});

it('đọc lại phiếu sau Hạn bảo hành chỉ trả thông tin phiếu, vẫn liệt kê lần giao', function () {
    stockUp($this->netflix, "a@shop.test\tpw1");
    postReadOrder()->assertCreated();

    // Bảo hành 30 ngày kể từ 16/09/2026, nên 17/10 là đã quá hạn.
    $this->travelTo(CarbonImmutable::parse('2026-10-17 09:00'));

    $response = readDispatch();
    $deliveries = collect($response->json('dispatch.deliveries'));

    $response->assertOk()->assertJsonPath('dispatch.external_ref', 'WEB-2001');

    expect($deliveries)->toHaveCount(2)
        ->and($deliveries->pluck('text')->all())->toBe([null, null])
        ->and($deliveries->pluck('fields')->all())->toBe([null, null])
        ->and($deliveries->pluck('warranty_ends_on')->unique()->all())->toBe(['2026-10-16'])
        // Không trả nội dung thì không ghi thêm dòng Nhật ký xem mã nào.
        ->and(RevealLogEntry::count())->toBe(2);
});

it('lần giao đã bị Giao thay không trả nội dung, lần giao thay thế thì có', function () {
    stockUp($this->netflix, "a@shop.test\tpw1\nb@shop.test\tpw2");
    postReadOrder()->assertCreated();

    [$first, $second] = Delivery::orderBy('id')->get()->all();

    // Nhân viên phát hiện giao nhầm và Giao thay lần giao đầu bằng Slot của Đơn vị hàng khác.
    app(CorrectiveDelivery::class)->correct($this->seller, $first, new CorrectionDraft(product: null, contentSent: false));

    $replacement = Delivery::orderByDesc('id')->firstOrFail();
    $logged = RevealLogEntry::count();
    $response = readDispatch();
    $deliveries = collect($response->json('dispatch.deliveries'))->keyBy('id');

    $response->assertOk()->assertJsonCount(3, 'dispatch.deliveries');

    expect($deliveries->keys()->all())->toBe([$first->id, $second->id, $replacement->id])
        // Mã cũ đã bị huỷ, trả ra chỉ làm khách nhầm.
        ->and($deliveries[$first->id]['status'])->toBe('replaced')
        ->and($deliveries[$first->id]['text'])->toBeNull()
        ->and($deliveries[$first->id]['fields'])->toBeNull()
        // Lần giao thay thế mang nội dung, và nói rõ nó thay cho lần giao nào.
        ->and($deliveries[$replacement->id]['status'])->toBe('active')
        ->and($deliveries[$replacement->id]['replaces_delivery_id'])->toBe($first->id)
        ->and($deliveries[$replacement->id]['fields'])->toBe(['username' => 'b@shop.test', 'password' => 'pw2'])
        ->and($deliveries[$second->id]['status'])->toBe('active')
        ->and($deliveries[$second->id]['fields'])->toBe(['username' => 'a@shop.test', 'password' => 'pw1'])
        // Chỉ hai lần giao còn hiệu lực được ghi Nhật ký xem mã.
        ->and(RevealLogEntry::count())->toBe($logged + 2);
});

it('đọc lại phiếu Đang giữ: có thông tin phiếu và hạn giữ, chưa có lần giao nào', function () {
    stockUp($this->netflix, "a@shop.test\tpw1");
    postReadOrder(['hold' => true])->assertCreated();

    readDispatch()
        ->assertOk()
        ->assertJsonPath('dispatch.status', 'holding')
        ->assertJsonPath('dispatch.deliveries', [])
        ->assertJsonPath('dispatch.lines', [['product_code' => 'NETFLIX-1M', 'quantity' => 2, 'sale_price' => 190_000]]);

    expect(RevealLogEntry::count())->toBe(0);
});

it('đọc lại mã đơn chưa có phiếu nào thì báo không tìm thấy', function () {
    readDispatch('WEB-KHONG-CO')
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'dispatch_not_found');
});
