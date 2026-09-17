<?php

use App\Inventory\Access\Role;
use App\Inventory\Api\ApiKeys;
use App\Inventory\Catalog\ContentFieldDraft;
use App\Inventory\Catalog\ProductCatalog;
use App\Inventory\Catalog\ProductDraft;
use App\Inventory\Catalog\ProductType;
use App\Inventory\Catalog\SupplierDirectory;
use App\Inventory\Dispatch\DispatchFreeze;
use App\Inventory\Dispatch\DispatchStatus;
use App\Inventory\Dispatch\SalesChannelDirectory;
use App\Inventory\Dispatch\SalesChannelDraft;
use App\Inventory\Dispatch\SalesChannelType;
use App\Inventory\Encryption\KeyFingerprints;
use App\Inventory\Stock\SlotStatus;
use App\Models\Delivery;
use App\Models\Dispatch;
use App\Models\Slot;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

/*
 * API xuất kho khi kho đang Tạm dừng xuất kho: website nhận một mã lỗi riêng, `dispatch_frozen`, và
 * không đơn nào giữ hay giao được. Đường huỷ đơn và đường đọc lại phiếu vẫn chạy: chúng không lấy
 * thêm hàng ra khỏi kho.
 */

beforeEach(function () {
    Storage::fake('intake');
    $this->seed(RoleSeeder::class);
    app(KeyFingerprints::class)->register();
    $this->travelTo(CarbonImmutable::parse('2026-09-17 10:00'));

    $this->admin = staffMember(Role::Owner);
    $this->freeze = app(DispatchFreeze::class);
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

    stockUp($this->netflix, "a@shop.test\tpw-a");
});

/**
 * @param  array<string, mixed>  $overrides
 */
function postFrozenOrder(array $overrides = []): TestResponse
{
    return apiAs()->postJson('/api/v1/dispatches', [
        'external_ref' => 'WEB-1001',
        'lines' => [['product_code' => 'NETFLIX-1M', 'quantity' => 1, 'sale_price' => 190_000]],
        ...$overrides,
    ]);
}

it('đơn giữ và giao ngay bị từ chối với mã lỗi riêng; không Slot nào rời kho', function () {
    $this->freeze->freeze($this->admin, 'Khôi phục từ backup');

    postFrozenOrder()
        ->assertStatus(503)
        ->assertJsonPath('error.code', 'dispatch_frozen')
        // Lý do tạm dừng là chuyện nội bộ của shop, không trả ra cho website.
        ->assertJsonMissing(['message' => 'Khôi phục từ backup']);

    expect(Dispatch::count())->toBe(0)
        ->and(Delivery::count())->toBe(0)
        ->and(Slot::query()->where('status', SlotStatus::InStock)->count())->toBe(2);
});

it('đơn xin Giữ hàng cũng bị từ chối', function () {
    $this->freeze->freeze($this->admin, 'Nghi lộ nội dung');

    postFrozenOrder(['hold' => true])->assertStatus(503)->assertJsonPath('error.code', 'dispatch_frozen');

    expect(Dispatch::count())->toBe(0)
        ->and(Slot::query()->where('status', SlotStatus::Reserved)->count())->toBe(0);
});

it('phiếu Đang giữ không xác nhận được, nhưng vẫn huỷ và đọc lại được', function () {
    postFrozenOrder(['hold' => true])->assertCreated();

    $this->freeze->freeze($this->admin, 'Khôi phục từ backup');

    apiAs()->postJson('/api/v1/dispatches/WEB-1001/confirm')
        ->assertStatus(503)
        ->assertJsonPath('error.code', 'dispatch_frozen');

    expect(Dispatch::query()->sole()->status)->toBe(DispatchStatus::Holding)
        ->and(Delivery::count())->toBe(0);

    // Đọc lại phiếu không đụng tới hàng.
    apiAs()->getJson('/api/v1/dispatches/WEB-1001')->assertOk();

    // Huỷ đơn nhả hàng về kho: không có lý do gì để chặn.
    apiAs()->postJson('/api/v1/dispatches/WEB-1001/cancel')->assertOk();

    expect(Dispatch::query()->sole()->status)->toBe(DispatchStatus::Cancelled)
        ->and(Slot::query()->where('status', SlotStatus::InStock)->count())->toBe(2);
});

it('kiểm tra tồn vẫn trả lời, và kho mở lại thì đơn đi tiếp bình thường', function () {
    $this->freeze->freeze($this->admin, 'Khôi phục từ backup');

    apiAs()->getJson('/api/v1/stock?product_codes[]=NETFLIX-1M')->assertOk();

    $this->freeze->unfreeze($this->admin, 'Đã đối chiếu xong');

    postFrozenOrder()->assertCreated()->assertJsonPath('dispatch.status', DispatchStatus::Completed->value);

    expect(Delivery::count())->toBe(1);
});
