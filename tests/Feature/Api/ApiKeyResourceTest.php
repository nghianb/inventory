<?php

use App\Filament\Resources\ApiKeys\ApiKeyResource;
use App\Inventory\Access\Role;
use App\Inventory\Api\ApiKeys;
use App\Inventory\Dispatch\SalesChannelDirectory;
use App\Inventory\Dispatch\SalesChannelDraft;
use App\Inventory\Dispatch\SalesChannelType;
use Database\Seeders\RoleSeeder;

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
