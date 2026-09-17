<?php

use App\Filament\Pages\DispatchFreezePage;
use App\Inventory\Access\MissingRole;
use App\Inventory\Access\Role;
use App\Inventory\Catalog\ContentFieldDraft;
use App\Inventory\Catalog\ContentFieldType;
use App\Inventory\Catalog\StockForm;
use App\Inventory\Catalog\SupplierDirectory;
use App\Inventory\Dispatch\DispatchDraft;
use App\Inventory\Dispatch\DispatchFreeze;
use App\Inventory\Dispatch\DispatchLineDraft;
use App\Inventory\Dispatch\DispatchLineKind;
use App\Inventory\Dispatch\DispatchStatus;
use App\Inventory\Dispatch\InvalidDispatch;
use App\Inventory\Dispatch\ManualDispatch;
use App\Inventory\Dispatch\RecordedDeliveryDraft;
use App\Inventory\Dispatch\RecordedLostDelivery;
use App\Inventory\Dispatch\RecordedSlot;
use App\Inventory\Dispatch\SalesChannelDirectory;
use App\Inventory\Dispatch\SalesChannelDraft;
use App\Inventory\Encryption\KeyFingerprints;
use App\Inventory\Stock\SellableStock;
use App\Inventory\Stock\SlotStatus;
use App\Models\Delivery;
use App\Models\Dispatch;
use App\Models\RevealLogEntry;
use App\Models\Slot;
use App\Models\StockLedgerEntry;
use App\Models\StockUnit;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
 * Ghi nhận giao bù: Quản trị chép lại một lần Giao hàng đã thực sự xảy ra nhưng mất khi khôi phục từ
 * backup. Slot chọn đích danh qua Khoá chống trùng — ngoại lệ duy nhất cho Thứ tự xuất — và làm được
 * cả khi kho đang Tạm dừng xuất kho.
 */

beforeEach(function () {
    Storage::fake('intake');
    $this->seed(RoleSeeder::class);
    app(KeyFingerprints::class)->register();
    $this->travelTo(CarbonImmutable::parse('2026-09-17 10:00'));

    $this->admin = staffMember(Role::Owner);
    $this->seller = staffMember(Role::BanHang);
    $this->recorded = app(RecordedLostDelivery::class);
    $this->supplier = app(SupplierDirectory::class)->create($this->admin, 'Kinguin');
    $this->shopee = app(SalesChannelDirectory::class)->create($this->admin, new SalesChannelDraft('Shopee'));

    $this->netflix = productOf(
        StockForm::Account,
        [
            new ContentFieldDraft('username', 'Tên đăng nhập', ContentFieldType::Email, sensitive: false, dedupeKey: true),
            new ContentFieldDraft('password', 'Mật khẩu'),
        ],
        'Netflix 1 tháng',
        'NETFLIX-1M',
        defaultSlots: 2,
        warrantyDays: 30,
    );

    stockUp($this->netflix, "a@shop.test\tpw-a\nb@shop.test\tpw-b");

    $this->unit = StockUnit::where('content->username', 'a@shop.test')->sole();
    $this->slot = $this->unit->slots()->where('status', SlotStatus::InStock)->orderBy('id')->firstOrFail();
});

it('tra Khoá chống trùng trả các Slot Còn hàng ở dạng che, không ghi Nhật ký xem mã', function () {
    $candidates = $this->recorded->candidates($this->admin, 'A@Shop.test');

    expect($candidates)->toHaveCount(2)
        ->and($candidates[0])->toBeInstanceOf(RecordedSlot::class)
        ->and($candidates[0]->stockUnitId)->toBe($this->unit->id)
        ->and($candidates[0]->label())->toContain('Netflix 1 tháng')
        ->and($candidates[0]->label())->toContain('a@shop.test')
        // Mật khẩu là trường nhạy cảm: che hoàn toàn.
        ->and($candidates[0]->label())->not->toContain('pw-a')
        ->and(RevealLogEntry::count())->toBe(0)
        ->and($this->recorded->candidates($this->admin, 'khong-co-trong-kho'))->toBe([]);
});

it('ghi nhận vào Phiếu xuất mới: phiếu Hoàn tất, Dòng xuất loại Ghi nhận giao bù, Slot Đã giao', function () {
    $delivery = $this->recorded->record($this->admin, new RecordedDeliveryDraft(
        slot: $this->slot,
        channel: $this->shopee,
        externalRef: 'SP-2026-77',
        customer: 'Anh Minh',
        salePrice: 120_000,
        deliveredAt: CarbonImmutable::parse('2026-09-15 08:30'),
        reason: 'Mất khi khôi phục backup ngày 17/09',
    ));

    $dispatch = $delivery->dispatchLine->dispatch;
    $line = $delivery->dispatchLine;

    expect($dispatch->status)->toBe(DispatchStatus::Completed)
        ->and($dispatch->external_ref)->toBe('SP-2026-77')
        ->and($dispatch->customer)->toBe('Anh Minh')
        ->and($line->kind)->toBe(DispatchLineKind::Recorded)
        ->and($line->kind->label())->toBe('Ghi nhận giao bù')
        ->and($line->quantity)->toBe(1)
        ->and($line->sale_price)->toBe(120_000)
        ->and($this->slot->fresh()->status)->toBe(SlotStatus::Delivered)
        // Mốc giao là lúc khách thực nhận, nên Hạn bảo hành đếm từ đó chứ không từ lúc ghi nhận.
        ->and($delivery->delivered_at)->toEqual(CarbonImmutable::parse('2026-09-15 08:30'))
        ->and($delivery->warrantyEndsOn()->toDateString())->toBe('2026-10-15')
        ->and($delivery->delivered_by)->toBe($this->admin->id)
        ->and(app(SellableStock::class)->count($this->netflix))->toBe(3);

    expect(StockLedgerEntry::query()->latest('id')->first())
        ->slot_id->toBe($this->slot->id)
        ->from_status->toBe('in-stock')
        ->to_status->toBe('delivered')
        ->actor_id->toBe($this->admin->id)
        ->reason->toBe("Ghi nhận giao bù vào Phiếu xuất #{$dispatch->id}: Mất khi khôi phục backup ngày 17/09");
});

it('ghi nhận vào Phiếu xuất Hoàn tất đã có, không đụng Dòng xuất cũ', function () {
    $dispatch = app(ManualDispatch::class)->create($this->seller, new DispatchDraft(
        $this->shopee,
        'SP-2026-01',
        [new DispatchLineDraft($this->netflix, 1, 100_000)],
    ));

    $delivery = $this->recorded->record($this->admin, new RecordedDeliveryDraft(
        slot: Slot::query()->where('status', SlotStatus::InStock)->orderBy('id')->firstOrFail(),
        dispatch: $dispatch,
        reason: 'Khách đã nhận nhưng phiếu mất dòng',
    ));

    expect($delivery->dispatchLine->dispatch_id)->toBe($dispatch->id)
        ->and($delivery->dispatchLine->sale_price)->toBeNull()
        ->and($dispatch->lines()->count())->toBe(2)
        ->and($dispatch->deliveries()->count())->toBe(2)
        ->and($dispatch->lines()->first()->kind)->toBe(DispatchLineKind::Sale)
        ->and(Dispatch::count())->toBe(1);
});

it('làm được khi kho đang Tạm dừng xuất kho: đó chính là lúc cần nó', function () {
    app(DispatchFreeze::class)->freeze($this->admin, 'Khôi phục từ backup');

    $delivery = $this->recorded->record($this->admin, new RecordedDeliveryDraft(
        slot: $this->slot,
        channel: $this->shopee,
        reason: 'Mất khi khôi phục backup',
    ));

    expect($delivery->slot_id)->toBe($this->slot->id)
        ->and($this->slot->fresh()->status)->toBe(SlotStatus::Delivered);
});

it('chỉ Quản trị; Slot, lý do và mốc giao được kiểm tra', function () {
    expect(fn () => $this->recorded->candidates($this->seller, 'a@shop.test'))->toThrow(MissingRole::class)
        ->and(fn () => $this->recorded->record($this->seller, new RecordedDeliveryDraft($this->slot, channel: $this->shopee, reason: 'x')))
        ->toThrow(MissingRole::class)
        ->and(fn () => $this->recorded->record($this->admin, new RecordedDeliveryDraft(null, channel: $this->shopee, reason: 'x')))
        ->toThrow(InvalidDispatch::class, 'Chưa chọn Slot')
        ->and(fn () => $this->recorded->record($this->admin, new RecordedDeliveryDraft($this->slot, channel: $this->shopee, reason: '  ')))
        ->toThrow(InvalidDispatch::class, 'phải nhập lý do')
        ->and(fn () => $this->recorded->record($this->admin, new RecordedDeliveryDraft(
            slot: $this->slot,
            channel: $this->shopee,
            deliveredAt: CarbonImmutable::now()->addDay(),
            reason: 'x',
        )))->toThrow(InvalidDispatch::class, 'tương lai')
        ->and(fn () => $this->recorded->record($this->admin, new RecordedDeliveryDraft($this->slot, reason: 'x')))
        ->toThrow(InvalidDispatch::class, 'chưa chọn Kênh bán')
        ->and(Delivery::count())->toBe(0);
});

it('Slot đã giao rồi thì không ghi nhận lại được', function () {
    $this->recorded->record($this->admin, new RecordedDeliveryDraft($this->slot, channel: $this->shopee, reason: 'Lần đầu'));

    expect(fn () => $this->recorded->record($this->admin, new RecordedDeliveryDraft($this->slot, channel: $this->shopee, reason: 'Lần hai')))
        ->toThrow(InvalidDispatch::class, 'chỉ ghi nhận được Slot Còn hàng')
        ->and(Delivery::count())->toBe(1);
});

it('chỉ ghi nhận vào phiếu Hoàn tất', function () {
    $dispatch = app(ManualDispatch::class)->create($this->seller, new DispatchDraft(
        $this->shopee,
        'SP-2026-02',
        [new DispatchLineDraft($this->netflix, 1)],
    ));
    $dispatch->forceFill(['status' => DispatchStatus::Cancelled])->save();

    expect(fn () => $this->recorded->record($this->admin, new RecordedDeliveryDraft(
        slot: Slot::query()->where('status', SlotStatus::InStock)->orderBy('id')->firstOrFail(),
        dispatch: $dispatch,
        reason: 'x',
    )))->toThrow(InvalidDispatch::class, 'chỉ Ghi nhận giao bù vào phiếu Hoàn tất');
});

it('panel: tra Khoá chống trùng rồi ghi nhận, làm được trong lúc kho đang dừng', function () {
    app(DispatchFreeze::class)->freeze($this->admin, 'Khôi phục từ backup');
    $this->actingAs($this->admin);

    Livewire::test(DispatchFreezePage::class)
        ->callAction('lookupSlot', data: ['dedupe_key' => 'a@shop.test'])
        ->assertHasNoActionErrors()
        ->setActionData([
            'slot_id' => $this->slot->id,
            'sales_channel_id' => $this->shopee->id,
            'external_ref' => 'SP-2026-99',
            'customer' => 'Chị Lan',
            'sale_price' => 150_000,
            'delivered_at' => '2026-09-16 09:00:00',
            'reason' => 'Mất khi khôi phục backup',
        ])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $delivery = Delivery::query()->sole();

    expect($delivery->slot_id)->toBe($this->slot->id)
        ->and($delivery->dispatchLine->kind)->toBe(DispatchLineKind::Recorded)
        ->and($delivery->dispatchLine->sale_price)->toBe(150_000)
        ->and($delivery->dispatchLine->dispatch->external_ref)->toBe('SP-2026-99');
});
