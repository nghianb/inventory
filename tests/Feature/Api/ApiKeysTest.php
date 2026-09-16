<?php

use App\Inventory\Access\MissingRole;
use App\Inventory\Access\Role;
use App\Inventory\Api\ApiKeys;
use App\Inventory\Api\InvalidApiKey;
use App\Inventory\Dispatch\SalesChannelDirectory;
use App\Inventory\Dispatch\SalesChannelDraft;
use App\Inventory\Dispatch\SalesChannelType;
use App\Inventory\Security\SecurityEvent;
use App\Models\ApiKey;
use App\Models\SecurityLogEntry;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->keys = app(ApiKeys::class);
    $this->channels = app(SalesChannelDirectory::class);
    $this->admin = staffMember(Role::QuanTri);
    $this->website = $this->channels->create($this->admin, new SalesChannelDraft('Website', SalesChannelType::Api, requiresExternalRef: true));
});

it('Quản trị tạo Khoá API: bí mật hiện một lần, kho chỉ lưu hash', function () {
    $issued = $this->keys->issue($this->admin, $this->website, 'Website chính');

    expect($issued->secret)->toBeString()->not->toBe('')
        ->and($issued->key->sales_channel_id)->toBe($this->website->id)
        ->and($issued->key->label)->toBe('Website chính')
        ->and($issued->key->isRevoked())->toBeFalse()
        // Không cột nào của bảng chứa giá trị khoá.
        ->and(json_encode(DB::table('api_keys')->sole()))->not->toContain($issued->secret)
        ->and($this->keys->authenticate($issued->secret)?->id)->toBe($issued->key->id)
        ->and($this->keys->authenticate($issued->secret.'x'))->toBeNull();
});

it('chỉ Quản trị tạo, thu hồi và xoay Khoá API', function (Role $role) {
    $staff = staffMember($role);
    $issued = $this->keys->issue($this->admin, $this->website);

    expect(fn () => $this->keys->issue($staff, $this->website))->toThrow(MissingRole::class)
        ->and(fn () => $this->keys->rotate($staff, $issued->key))->toThrow(MissingRole::class)
        ->and(fn () => $this->keys->revoke($staff, $issued->key))->toThrow(MissingRole::class)
        ->and(ApiKey::count())->toBe(1)
        ->and($issued->key->fresh()->isRevoked())->toBeFalse();
})->with([
    'Nhập kho' => [Role::NhapKho],
    'Bán hàng' => [Role::BanHang],
]);

it('chỉ Kênh bán loại API mới có Khoá API', function () {
    $shopee = $this->channels->create($this->admin, new SalesChannelDraft('Shopee'));

    expect(fn () => $this->keys->issue($this->admin, $shopee))
        ->toThrow(InvalidApiKey::class, 'Kênh bán "Shopee" không phải loại API nên không có Khoá API.')
        ->and(ApiKey::count())->toBe(0);
});

it('xoay khoá: hai khoá cùng hoạt động cho tới khi Quản trị thu hồi khoá cũ', function () {
    $old = $this->keys->issue($this->admin, $this->website);
    $new = $this->keys->rotate($this->admin, $old->key);

    expect($this->keys->authenticate($old->secret)?->id)->toBe($old->key->id)
        ->and($this->keys->authenticate($new->secret)?->id)->toBe($new->key->id)
        ->and($new->secret)->not->toBe($old->secret)
        ->and($new->key->rotates_api_key_id)->toBe($old->key->id)
        ->and($this->keys->active($this->website)->modelKeys())->toBe([$old->key->id, $new->key->id]);

    $this->keys->revoke($this->admin, $old->key);

    expect($this->keys->authenticate($old->secret))->toBeNull()
        ->and($this->keys->authenticate($new->secret)?->id)->toBe($new->key->id)
        ->and($this->keys->active($this->website)->modelKeys())->toBe([$new->key->id])
        // Khoá đã thu hồi không bị xoá: Nhật ký xem mã còn tham chiếu tới nó.
        ->and(ApiKey::count())->toBe(2)
        ->and(fn () => $this->keys->revoke($this->admin, $old->key->fresh()))
        ->toThrow(InvalidApiKey::class, 'Khoá API đã thu hồi.');
});

it('mỗi Kênh bán nhiều nhất hai Khoá API cùng hoạt động', function () {
    $first = $this->keys->issue($this->admin, $this->website);
    $this->keys->rotate($this->admin, $first->key);

    expect(fn () => $this->keys->issue($this->admin, $this->website))
        ->toThrow(InvalidApiKey::class, 'Kênh bán "Website" đã có 2 Khoá API hoạt động; thu hồi bớt trước khi tạo thêm.')
        ->and(ApiKey::count())->toBe(2);

    $this->keys->revoke($this->admin, $first->key);

    expect($this->keys->issue($this->admin, $this->website)->key->isRevoked())->toBeFalse()
        ->and(ApiKey::count())->toBe(3);
});

it('Kênh bán ngừng dùng không tạo được Khoá API và khoá cũ hết xác thực được', function () {
    $issued = $this->keys->issue($this->admin, $this->website);
    $this->channels->hide($this->admin, $this->website);

    expect(fn () => $this->keys->issue($this->admin, $this->website->fresh()))
        ->toThrow(InvalidApiKey::class, 'Kênh bán "Website" đã ngừng dùng.')
        ->and($this->keys->authenticate($issued->secret))->toBeNull();
});

it('mỗi thao tác Khoá API ghi Nhật ký bảo mật, không bao giờ chứa giá trị khoá', function () {
    $issued = $this->keys->issue($this->admin, $this->website, 'Website chính');
    $rotated = $this->keys->rotate($this->admin, $issued->key);
    $this->keys->revoke($this->admin, $issued->key);

    $entries = SecurityLogEntry::query()->orderBy('id')->get();

    expect($entries->pluck('event')->all())->toBe([
        SecurityEvent::ApiKeyCreated,
        SecurityEvent::ApiKeyRotated,
        SecurityEvent::ApiKeyRevoked,
    ])
        ->and($entries->pluck('actor_id')->unique()->all())->toBe([$this->admin->id])
        // jsonb sắp lại khoá khi lưu nên chỉ so nội dung, không so thứ tự.
        ->and($entries->first()->details)->toEqualCanonicalizing([
            'api_key_id' => $issued->key->id,
            'sales_channel' => 'Website',
            'label' => 'Website chính',
        ])
        ->and(json_encode($entries))->not->toContain($issued->secret)
        ->and(json_encode($entries))->not->toContain($rotated->secret);
});

it('ghi lại lần cuối Khoá API được dùng', function () {
    $issued = $this->keys->issue($this->admin, $this->website);

    expect($issued->key->last_used_at)->toBeNull();

    $this->travelTo(CarbonImmutable::parse('2026-09-16 10:30'));
    $this->keys->authenticate($issued->secret);

    expect($issued->key->fresh()->last_used_at?->format('Y-m-d H:i'))->toBe('2026-09-16 10:30');
});
