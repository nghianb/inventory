<?php

use App\Inventory\Access\Role;
use App\Inventory\Api\ApiKeys;
use App\Inventory\Catalog\ContentFieldDraft;
use App\Inventory\Catalog\ProductCatalog;
use App\Inventory\Catalog\ProductDraft;
use App\Inventory\Catalog\ProductType;
use App\Inventory\Catalog\SupplierDirectory;
use App\Inventory\Dispatch\SalesChannelDirectory;
use App\Inventory\Dispatch\SalesChannelDraft;
use App\Inventory\Dispatch\SalesChannelType;
use App\Inventory\Encryption\KeyFingerprints;
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
    $this->keys = app(ApiKeys::class);
    $this->channels = app(SalesChannelDirectory::class);
    $this->website = $this->channels->create($this->admin, new SalesChannelDraft('Website', SalesChannelType::Api));
    $this->secret = $this->keys->issue($this->admin, $this->website)->secret;
    $this->supplier = app(SupplierDirectory::class)->create($this->admin, 'Kinguin');

    $catalog = app(ProductCatalog::class);
    $this->netflix = $catalog->create($this->admin, new ProductDraft(
        type: ProductType::Account,
        name: 'Netflix 1 tháng',
        code: 'NETFLIX-1M',
        fields: [
            new ContentFieldDraft('username', 'Tên đăng nhập', sensitive: false, dedupeKey: true),
            new ContentFieldDraft('password', 'Mật khẩu'),
        ],
        defaultSlots: 2,
    ));
    $this->steam = $catalog->create($this->admin, new ProductDraft(
        type: ProductType::OneTimeCode,
        name: 'Steam Wallet 100k',
        code: 'STEAM-100K',
        fields: [new ContentFieldDraft('code', 'Mã thẻ', dedupeKey: true)],
    ));
});

/**
 * Gọi kiểm tra tồn bằng một Khoá API.
 *
 * @param  list<string>  $codes
 */
function checkStock(?string $secret, array $codes): TestResponse
{
    $query = http_build_query(['product_codes' => $codes]);

    return test()
        ->withHeaders($secret === null ? [] : ['Authorization' => "Bearer {$secret}"])
        ->getJson("/api/v1/stock?{$query}");
}

it('trả số Slot Tồn bán được theo Mã sản phẩm, đúng thứ tự hỏi, và không giữ hàng', function () {
    stockUp($this->netflix, "a@shop.test\tpw\nb@shop.test\tpw");
    stockUp($this->steam, 'AAAA-0001');

    $response = checkStock($this->secret, ['STEAM-100K', 'NETFLIX-1M']);

    $response->assertOk()->assertExactJson(['stock' => [
        ['product_code' => 'STEAM-100K', 'available' => 1],
        ['product_code' => 'NETFLIX-1M', 'available' => 4],
    ]]);

    // Chỉ tham khảo: hỏi lại vẫn ra đúng bấy nhiêu, không Slot nào bị giữ.
    checkStock($this->secret, ['NETFLIX-1M'])->assertOk()->assertJsonPath('stock.0.available', 4);
});

it('Sản phẩm Ngừng bán trả 0', function () {
    stockUp($this->steam, 'AAAA-0001');
    app(ProductCatalog::class)->discontinue($this->admin, $this->steam);

    checkStock($this->secret, ['STEAM-100K'])->assertOk()->assertJsonPath('stock.0.available', 0);
});

it('Mã sản phẩm không có trong kho thì báo lỗi, không trả tồn của dòng nào', function () {
    stockUp($this->steam, 'AAAA-0001');

    checkStock($this->secret, ['STEAM-100K', 'KHONG-CO'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'invalid_request')
        ->assertJsonPath('error.problems', ['Không có Sản phẩm với Mã sản phẩm "KHONG-CO".']);
});

it('thiếu Mã sản phẩm thì báo lỗi', function () {
    checkStock($this->secret, [])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'invalid_request');
});

it('từ chối khi thiếu Khoá API, khoá sai, hoặc khoá đã thu hồi', function () {
    $revoked = $this->keys->issue($this->admin, $this->website);
    $this->keys->revoke($this->admin, $revoked->key);

    foreach ([null, 'khong-phai-khoa', $revoked->secret] as $secret) {
        checkStock($secret, ['STEAM-100K'])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'unauthorized');
    }
});

it('hai khoá cùng gọi được trong lúc xoay, khoá cũ hết dùng khi bị thu hồi', function () {
    stockUp($this->steam, 'AAAA-0001');
    $old = $this->keys->issue($this->admin, $this->website);
    $this->keys->revoke($this->admin, $this->keys->authenticate($this->secret));
    $new = $this->keys->rotate($this->admin, $old->key);

    checkStock($old->secret, ['STEAM-100K'])->assertOk();
    checkStock($new->secret, ['STEAM-100K'])->assertOk();

    $this->keys->revoke($this->admin, $old->key->fresh());

    checkStock($old->secret, ['STEAM-100K'])->assertStatus(401);
    checkStock($new->secret, ['STEAM-100K'])->assertOk();
});

it('Kênh bán ngừng dùng thì Khoá API của kênh hết gọi được', function () {
    $this->channels->hide($this->admin, $this->website);

    checkStock($this->secret, ['STEAM-100K'])->assertStatus(401);
});

it('khoá sai cũng bị rate limit, để đường dò khoá không phải đường duy nhất không có trần', function () {
    config(['inventory.api.rate_limit_per_minute' => 2]);

    checkStock('khong-phai-khoa', ['STEAM-100K'])->assertStatus(401);
    checkStock('khong-phai-khoa', ['STEAM-100K'])->assertStatus(401);
    checkStock('khong-phai-khoa', ['STEAM-100K'])
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'rate_limited');

    // Hạn mức của đường hỏng tính theo IP, không ăn vào hạn mức của khoá đúng.
    checkStock($this->secret, ['STEAM-100K'])->assertOk();
});

it('rate limit tính riêng theo từng Khoá API', function () {
    config(['inventory.api.rate_limit_per_minute' => 2]);
    $other = $this->keys->issue($this->admin, $this->website);

    checkStock($this->secret, ['STEAM-100K'])->assertOk();
    checkStock($this->secret, ['STEAM-100K'])->assertOk();
    checkStock($this->secret, ['STEAM-100K'])
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'rate_limited');

    // Khoá khác còn nguyên hạn mức của nó.
    checkStock($other->secret, ['STEAM-100K'])->assertOk();
});
