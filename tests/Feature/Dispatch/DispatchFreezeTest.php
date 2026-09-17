<?php

use App\Filament\Pages\DispatchFreezePage;
use App\Inventory\Access\MissingRole;
use App\Inventory\Access\Role;
use App\Inventory\Catalog\ContentFieldDraft;
use App\Inventory\Catalog\ProductCatalog;
use App\Inventory\Catalog\ProductDraft;
use App\Inventory\Catalog\ProductType;
use App\Inventory\Catalog\SupplierDirectory;
use App\Inventory\Dispatch\CorrectionDraft;
use App\Inventory\Dispatch\CorrectiveDelivery;
use App\Inventory\Dispatch\DispatchDraft;
use App\Inventory\Dispatch\DispatchFreeze;
use App\Inventory\Dispatch\DispatchFrozen;
use App\Inventory\Dispatch\DispatchLineDraft;
use App\Inventory\Dispatch\InvalidDispatch;
use App\Inventory\Dispatch\ManualDispatch;
use App\Inventory\Dispatch\SalesChannelDirectory;
use App\Inventory\Dispatch\SalesChannelDraft;
use App\Inventory\Dispatch\SlotPicker;
use App\Inventory\Encryption\KeyFingerprints;
use App\Inventory\Security\SecurityEvent;
use App\Inventory\Stock\SellableStock;
use App\Inventory\Stock\SlotStatus;
use App\Inventory\Stock\StockVoid;
use App\Inventory\Stock\VoidReason;
use App\Models\Delivery;
use App\Models\Dispatch;
use App\Models\SecurityLogEntry;
use App\Models\Slot;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
 * Tạm dừng xuất kho: cờ toàn kho chặn mọi đường Giữ hàng và Giao hàng, nhưng không đụng tới nhập
 * hàng, Huỷ hàng hay Ghi nhận giao bù. Test HTTP cho đường API nằm ở tests/Feature/Api/ApiFreezeTest.
 */

beforeEach(function () {
    Storage::fake('intake');
    $this->seed(RoleSeeder::class);
    app(KeyFingerprints::class)->register();
    $this->travelTo(CarbonImmutable::parse('2026-09-17 10:00'));

    $this->admin = staffMember(Role::Owner);
    $this->seller = staffMember(Role::BanHang);
    $this->freeze = app(DispatchFreeze::class);
    $this->manual = app(ManualDispatch::class);
    $this->supplier = app(SupplierDirectory::class)->create($this->admin, 'Kinguin');
    $this->zalo = app(SalesChannelDirectory::class)->create($this->admin, new SalesChannelDraft('Zalo'));
    $this->steam = app(ProductCatalog::class)->create($this->admin, new ProductDraft(
        type: ProductType::OneTimeCode,
        name: 'Steam Wallet 100k',
        code: 'STEAM-100K',
        fields: [new ContentFieldDraft('code', 'Mã thẻ', dedupeKey: true)],
        warrantyDays: 7,
    ));

    stockUp($this->steam, "CODE-1\nCODE-2\nCODE-3\nCODE-4");
});

/**
 * Một Phiếu xuất một Slot qua đường xuất kho thủ công.
 */
function sell(): Dispatch
{
    return test()->manual->create(test()->seller, new DispatchDraft(
        test()->zalo,
        null,
        [new DispatchLineDraft(test()->steam, 1)],
    ));
}

it('Quản trị bật Tạm dừng xuất kho kèm lý do và ghi Nhật ký bảo mật', function () {
    $this->freeze->freeze($this->admin, '  Nghi lộ nội dung  ');

    $state = $this->freeze->state();
    $entry = SecurityLogEntry::query()->where('event', SecurityEvent::DispatchFrozen)->sole();

    expect($state->isFrozen())->toBeTrue()
        ->and($state->reason)->toBe('Nghi lộ nội dung')
        ->and($state->frozenAt)->toEqual(now()->toImmutable())
        ->and($state->actorLabel())->toBe($this->admin->name)
        ->and($entry->actor_id)->toBe($this->admin->id)
        ->and($entry->details)->toBe(['reason' => 'Nghi lộ nội dung']);
});

it('đang tạm dừng thì xuất kho thủ công bị từ chối và không Slot nào rời kho', function () {
    $this->freeze->freeze($this->admin, 'Nghi lộ nội dung');

    expect(fn () => sell())->toThrow(DispatchFrozen::class, 'Nghi lộ nội dung')
        ->and(Dispatch::count())->toBe(0)
        ->and(Slot::query()->where('status', SlotStatus::Delivered)->count())->toBe(0)
        ->and(app(SellableStock::class)->count($this->steam))->toBe(4);
});

it('đang tạm dừng thì Giao thêm và Giao thay cũng bị từ chối', function () {
    $dispatch = sell();
    $delivery = Delivery::query()->sole();

    $this->freeze->freeze($this->admin, 'Khôi phục từ backup');

    expect(fn () => $this->manual->addLines($this->seller, $dispatch, [new DispatchLineDraft($this->steam, 1)]))
        ->toThrow(DispatchFrozen::class)
        ->and(fn () => app(CorrectiveDelivery::class)->correct($this->seller, $delivery, new CorrectionDraft($this->steam, contentSent: false)))
        ->toThrow(DispatchFrozen::class)
        // Giao thay lùi trọn vẹn: Slot giao nhầm chưa bị Huỷ hàng.
        ->and($delivery->slot->fresh()->status)->toBe(SlotStatus::Delivered)
        ->and(Delivery::count())->toBe(1);
});

it('cả hai đường chọn Slot đều từ chối, nên Đổi hàng cũng không giao được', function () {
    $this->freeze->freeze($this->admin, 'Nghi lộ nội dung');

    // Đổi hàng đi qua lockAndPickReplacement; hai hàm này là cửa duy nhất hàng rời kho theo Thứ tự xuất.
    expect(fn () => DB::transaction(fn () => SlotPicker::lockAndPick([new DispatchLineDraft($this->steam, 1)])))
        ->toThrow(DispatchFrozen::class)
        ->and(fn () => DB::transaction(fn () => SlotPicker::lockAndPickReplacement($this->steam, CarbonImmutable::today(), [], false)))
        ->toThrow(DispatchFrozen::class);
});

it('nhập hàng và Huỷ hàng vẫn chạy khi đang tạm dừng', function () {
    $slot = Slot::query()->where('status', SlotStatus::InStock)->orderBy('id')->firstOrFail();
    $this->freeze->freeze($this->admin, 'Khôi phục từ backup');

    stockUp($this->steam, 'CODE-5');
    app(StockVoid::class)->voidSlot($this->admin, $slot, VoidReason::ContentExposed);

    expect($slot->fresh()->status)->toBe(SlotStatus::Voided)
        ->and(app(SellableStock::class)->count($this->steam))->toBe(4);
});

it('tắt Tạm dừng xuất kho thì xuất kho lại được, và ghi Nhật ký bảo mật', function () {
    $this->freeze->freeze($this->admin, 'Khôi phục từ backup');
    $this->freeze->unfreeze($this->admin, 'Đã đối chiếu xong đơn');

    $dispatch = sell();

    expect($this->freeze->state()->isFrozen())->toBeFalse()
        ->and($dispatch->deliveries()->count())->toBe(1)
        ->and(SecurityLogEntry::query()->where('event', SecurityEvent::DispatchUnfrozen)->sole()->details)
        ->toBe(['reason' => 'Đã đối chiếu xong đơn']);
});

it('chỉ Quản trị bật hoặc tắt, và phải nhập lý do', function () {
    expect(fn () => $this->freeze->freeze($this->seller, 'Nghi lộ nội dung'))->toThrow(MissingRole::class)
        ->and(fn () => $this->freeze->freeze($this->admin, '   '))->toThrow(InvalidDispatch::class, 'phải nhập lý do')
        ->and($this->freeze->state()->isFrozen())->toBeFalse();

    $this->freeze->freeze($this->admin, 'Nghi lộ nội dung');

    expect(fn () => $this->freeze->unfreeze($this->seller, 'Xong'))->toThrow(MissingRole::class)
        ->and($this->freeze->state()->isFrozen())->toBeTrue();
});

it('bật khi đã dừng, hoặc tắt khi chưa dừng, đều báo lỗi và không đổi lý do', function () {
    expect(fn () => $this->freeze->unfreeze($this->admin, 'Xong'))->toThrow(InvalidDispatch::class, 'không ở trạng thái');

    $this->freeze->freeze($this->admin, 'Lý do đầu tiên');

    expect(fn () => $this->freeze->freeze($this->admin, 'Lý do khác'))->toThrow(InvalidDispatch::class, 'đã đang Tạm dừng')
        ->and($this->freeze->state()->reason)->toBe('Lý do đầu tiên');
});

it('lệnh artisan đưa kho vào Tạm dừng xuất kho mà không cần nhân viên nào', function () {
    $this->artisan('inventory:dispatches:freeze')->assertSuccessful();

    $state = $this->freeze->state();
    $entry = SecurityLogEntry::query()->where('event', SecurityEvent::DispatchFrozen)->sole();

    expect($state->isFrozen())->toBeTrue()
        ->and($state->actor)->toBeNull()
        ->and($state->actorLabel())->toBe('Lệnh trên server')
        ->and($state->reason)->toContain('Khôi phục từ backup')
        ->and($entry->actor_id)->toBeNull()
        ->and(fn () => sell())->toThrow(DispatchFrozen::class);

    // Chạy lại không ghi đè lý do đầu tiên: đó mới là lý do kho dừng.
    $this->artisan('inventory:dispatches:freeze', ['--reason' => 'Lần hai'])->assertSuccessful();

    expect($this->freeze->state()->reason)->toContain('Khôi phục từ backup')
        ->and(SecurityLogEntry::query()->where('event', SecurityEvent::DispatchFrozen)->count())->toBe(1);
});

it('panel: Quản trị bật rồi tắt, trạng thái và badge hiện rõ; nhân viên khác không vào được', function () {
    $this->actingAs($this->seller);
    $this->get(DispatchFreezePage::getUrl())->assertForbidden();

    $this->actingAs($this->admin);

    expect(DispatchFreezePage::getNavigationBadge())->toBeNull();

    Livewire::test(DispatchFreezePage::class)
        ->assertSee('Kho đang xuất hàng bình thường')
        ->assertActionHidden('unfreeze')
        ->callAction('freeze', data: ['reason' => 'Nghi lộ nội dung'])
        ->assertHasNoActionErrors();

    expect($this->freeze->state()->isFrozen())->toBeTrue()
        ->and(DispatchFreezePage::getNavigationBadge())->toBe('Đang dừng');

    Livewire::test(DispatchFreezePage::class)
        ->assertSee('Kho đang Tạm dừng xuất kho')
        ->assertSee('Nghi lộ nội dung')
        ->assertActionHidden('freeze')
        ->callAction('unfreeze', data: ['reason' => 'Đã đối chiếu xong'])
        ->assertHasNoActionErrors();

    expect($this->freeze->state()->isFrozen())->toBeFalse();
});
