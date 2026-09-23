<?php

use App\Filament\Resources\ApiKeys\ApiKeyResource;
use App\Filament\Resources\ApiKeys\Pages\ManageApiKeys;
use App\Inventory\Access\Role;
use App\Inventory\Api\ApiKeys;
use App\Inventory\Dispatch\SalesChannelDirectory;
use App\Inventory\Dispatch\SalesChannelDraft;
use App\Inventory\Dispatch\SalesChannelType;
use App\Models\ApiKey;
use Database\Seeders\RoleSeeder;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->admin = staffMember(Role::Owner);
    $this->website = app(SalesChannelDirectory::class)->create($this->admin, new SalesChannelDraft('Website', SalesChannelType::Api));
});

it('chỉ Quản trị mở được trang Khoá API', function (Role $role, int $status) {
    $this->actingAs(staffMember($role))
        ->get(ApiKeyResource::getUrl('index'))
        ->assertStatus($status);
})->with([
    'Quản trị' => [Role::Owner, 200],
    'Nhập kho' => [Role::NhapKho, 403],
    'Bán hàng' => [Role::BanHang, 403],
]);

it('trang Khoá API hiện khoá theo Kênh bán mà không hiện giá trị khoá', function () {
    $issued = app(ApiKeys::class)->issue($this->admin, $this->website, 'Website chính');

    $this->actingAs($this->admin)
        ->get(ApiKeyResource::getUrl('index'))
        ->assertOk()
        ->assertSee('Website chính')
        ->assertSee($issued->key->prefix)
        ->assertDontSee($issued->secret);
});

it('tạo Khoá API xong thì mở modal mang đúng giá trị khoá', function () {
    $this->actingAs($this->admin);

    $page = Livewire::test(ManageApiKeys::class)
        ->callAction('create', [
            'sales_channel_id' => $this->website->getKey(),
            'label' => 'Website chính',
        ])
        ->assertHasNoActionErrors()
        ->assertActionMounted(ApiKeyResource::SECRET_ACTION);

    $secret = mountedSecret($page);

    // Modal cầm đúng khoá vừa lưu, không phải một chuỗi nào khác.
    expect(hash('sha256', $secret))->toBe(ApiKey::query()->sole()->key_hash);

    $page->assertMountedActionModalSee([$secret, 'Website chính', 'Tôi đã lưu khoá']);
});

it('xoay khoá cũng mở modal đó với khoá mới', function () {
    $this->actingAs($this->admin);
    $old = app(ApiKeys::class)->issue($this->admin, $this->website, 'Website chính');

    $page = Livewire::test(ManageApiKeys::class)
        ->callAction(TestAction::make('rotate')->table($old->key))
        ->assertHasNoActionErrors()
        ->assertActionMounted(ApiKeyResource::SECRET_ACTION);

    $secret = mountedSecret($page);
    $new = app(ApiKeys::class)->active($this->website)->firstWhere('rotates_api_key_id', $old->key->getKey());

    expect($secret)->not->toBe($old->secret)
        ->and(hash('sha256', $secret))->toBe($new->key_hash);
});

/**
 * Giá trị khoá đi tới modal qua arguments của action, không qua form state.
 */
function mountedSecret(mixed $page): string
{
    return $page->get('mountedActions')[0]['arguments']['secret'];
}
