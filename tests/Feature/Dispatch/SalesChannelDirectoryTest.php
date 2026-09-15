<?php

use App\Inventory\Access\MissingRole;
use App\Inventory\Access\Role;
use App\Inventory\Dispatch\InvalidSalesChannel;
use App\Inventory\Dispatch\SalesChannelDirectory;
use App\Inventory\Dispatch\SalesChannelDraft;
use App\Inventory\Dispatch\SalesChannelType;
use App\Models\SalesChannel;
use Database\Seeders\RoleSeeder;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->channels = app(SalesChannelDirectory::class);
    $this->admin = staffMember(Role::QuanTri);
});

it('Quản trị khai báo Kênh bán thủ công với cờ bắt buộc mã đơn ngoài', function () {
    $shopee = $this->channels->create($this->admin, new SalesChannelDraft('  Shopee ', requiresExternalRef: true));
    $zalo = $this->channels->create($this->admin, new SalesChannelDraft('Zalo'));

    expect($shopee->fresh())
        ->name->toBe('Shopee')
        ->type->toBe(SalesChannelType::Manual)
        ->requires_external_ref->toBeTrue()
        ->and($shopee->fresh()->isHidden())->toBeFalse()
        ->and($zalo->fresh()->requires_external_ref)->toBeFalse();
});

it('chỉ Quản trị khai báo, sửa và ẩn được Kênh bán', function (Role $role) {
    $channel = $this->channels->create($this->admin, new SalesChannelDraft('Shopee'));
    $staff = staffMember($role);

    expect(fn () => $this->channels->create($staff, new SalesChannelDraft('Zalo')))->toThrow(MissingRole::class)
        ->and(fn () => $this->channels->update($staff, $channel, new SalesChannelDraft('Shopee Mall')))->toThrow(MissingRole::class)
        ->and(fn () => $this->channels->hide($staff, $channel))->toThrow(MissingRole::class)
        ->and(SalesChannel::count())->toBe(1)
        ->and($channel->fresh()->isHidden())->toBeFalse();
})->with([
    'Nhập kho' => [Role::NhapKho],
    'Bán hàng' => [Role::BanHang],
]);

it('tên Kênh bán bắt buộc và duy nhất, không phân biệt hoa thường', function () {
    $this->channels->create($this->admin, new SalesChannelDraft('Shopee'));
    $zalo = $this->channels->create($this->admin, new SalesChannelDraft('Zalo'));

    expect(fn () => $this->channels->create($this->admin, new SalesChannelDraft('shopee')))
        ->toThrow(InvalidSalesChannel::class, 'Kênh bán "shopee" đã có.')
        ->and(fn () => $this->channels->create($this->admin, new SalesChannelDraft('  ')))
        ->toThrow(InvalidSalesChannel::class, 'Tên Kênh bán không được để trống.')
        ->and(fn () => $this->channels->update($this->admin, $zalo, new SalesChannelDraft('SHOPEE')))
        ->toThrow(InvalidSalesChannel::class, 'Kênh bán "SHOPEE" đã có.');

    $this->channels->update($this->admin, $zalo, new SalesChannelDraft('Zalo OA', requiresExternalRef: true));

    expect($zalo->fresh())->name->toBe('Zalo OA')->requires_external_ref->toBeTrue();
});

it('Kênh bán ngừng dùng thì ẩn khỏi danh sách kênh dùng được, không bị xoá, hiện lại được', function () {
    $shopee = $this->channels->create($this->admin, new SalesChannelDraft('Shopee'));
    $zalo = $this->channels->create($this->admin, new SalesChannelDraft('Zalo'));

    $this->channels->hide($this->admin, $zalo);

    expect($zalo->fresh()->isHidden())->toBeTrue()
        ->and(SalesChannel::query()->usable()->pluck('id')->all())->toBe([$shopee->id])
        ->and(SalesChannel::count())->toBe(2);

    $this->channels->unhide($this->admin, $zalo);

    expect($zalo->fresh()->isHidden())->toBeFalse()
        ->and(SalesChannel::query()->usable()->count())->toBe(2);
});
