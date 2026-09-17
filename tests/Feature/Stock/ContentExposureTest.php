<?php

use App\Filament\Pages\DispatchFreezePage;
use App\Inventory\Access\MissingRole;
use App\Inventory\Access\Role;
use App\Inventory\Catalog\ContentFieldDraft;
use App\Inventory\Catalog\ContentFieldType;
use App\Inventory\Catalog\StockForm;
use App\Inventory\Catalog\SupplierDirectory;
use App\Inventory\Dispatch\AffectedDelivery;
use App\Inventory\Dispatch\DispatchDraft;
use App\Inventory\Dispatch\DispatchFreeze;
use App\Inventory\Dispatch\DispatchLineDraft;
use App\Inventory\Dispatch\ManualDispatch;
use App\Inventory\Dispatch\SalesChannelDirectory;
use App\Inventory\Dispatch\SalesChannelDraft;
use App\Inventory\Encryption\KeyFingerprints;
use App\Inventory\Stock\ContentExposure;
use App\Inventory\Stock\InvalidVoid;
use App\Inventory\Stock\SlotStatus;
use App\Inventory\Stock\StockUnitStatus;
use App\Inventory\Stock\StockVoid;
use App\Inventory\Stock\VoidReason;
use App\Models\Delivery;
use App\Models\Slot;
use App\Models\StockLedgerEntry;
use App\Models\StockUnit;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
 * Xử lý hàng bị coi là đã lộ: Huỷ hàng hàng loạt theo Sản phẩm với lý do Lộ nội dung, và liệt kê
 * lần giao Tài khoản còn trong Hạn bảo hành làm Lần giao bị ảnh hưởng.
 */

beforeEach(function () {
    Storage::fake('intake');
    $this->seed(RoleSeeder::class);
    app(KeyFingerprints::class)->register();
    $this->travelTo(CarbonImmutable::parse('2026-09-17 10:00'));

    $this->admin = staffMember(Role::Owner);
    $this->seller = staffMember(Role::BanHang);
    $this->exposure = app(ContentExposure::class);
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
        defaultSlots: 3,
        warrantyDays: 30,
    );
    $this->steam = productOf(
        StockForm::OneTimeCode,
        [new ContentFieldDraft('code', 'Mã thẻ', dedupeKey: true)],
        'Steam Wallet 100k',
        'STEAM-100K',
        warrantyDays: 30,
    );

    stockUp($this->netflix, "a@shop.test\tpw-a\nb@shop.test\tpw-b");
    stockUp($this->steam, "CODE-1\nCODE-2");

    // Một Slot Netflix và một Mã Steam đã giao cho khách.
    $this->dispatch = app(ManualDispatch::class)->create($this->seller, new DispatchDraft(
        $this->shopee,
        'SP-1',
        [new DispatchLineDraft($this->netflix, 1), new DispatchLineDraft($this->steam, 1)],
        customer: 'Anh Minh',
    ));
});

it('Huỷ hàng hàng loạt theo Sản phẩm với lý do Lộ nội dung; Slot Đã giao giữ nguyên', function () {
    $delivered = Delivery::query()->whereHas('stockUnit', fn ($units) => $units->where('product_id', $this->netflix->id))->sole();
    $before = StockLedgerEntry::max('id');

    $tally = $this->exposure->voidExposed($this->admin, $this->netflix, '  Lộ cả khoá lẫn backup  ');

    expect($tally->voidedUnits)->toBe(2)
        ->and($tally->voidedSlots)->toBe(5)
        ->and($tally->keptUnits)->toBe(0)
        ->and(StockUnit::query()->where('product_id', $this->netflix->id)->pluck('status')->all())
        ->toBe([StockUnitStatus::Voided, StockUnitStatus::Voided])
        ->and($delivered->slot->fresh()->status)->toBe(SlotStatus::Delivered)
        // Không giải phóng Khoá chống trùng: đây là Huỷ hàng, không phải Huỷ nhập.
        ->and(StockUnit::query()->where('product_id', $this->netflix->id)->pluck('holds_dedupe_key')->all())->toBe([true, true])
        // Sản phẩm khác không bị đụng tới.
        ->and(Slot::query()->whereHas('stockUnit', fn ($units) => $units->where('product_id', $this->steam->id))
            ->where('status', SlotStatus::InStock)->count())->toBe(1);

    $entries = StockLedgerEntry::query()->where('id', '>', $before)->get();

    expect($entries)->toHaveCount(7)
        ->and($entries->first()->reason)->toBe('Huỷ hàng hàng loạt Sản phẩm "Netflix 1 tháng": Lộ nội dung: Lộ cả khoá lẫn backup')
        ->and($entries->first()->actor_id)->toBe($this->admin->id)
        ->and(Slot::query()->where('status', SlotStatus::Voided)->pluck('void_reason')->unique()->all())
        ->toBe([VoidReason::ContentExposed]);
});

it('phạm vi cả kho huỷ mọi Sản phẩm, đúng kịch bản lộ cả khoá lẫn dữ liệu của ADR 0001', function () {
    $tally = $this->exposure->voidExposed($this->admin, null, 'Lộ cả khoá mã hoá lẫn backup');

    // Netflix: 2 Đơn vị hàng (5 Slot Còn hàng, 1 đã giao). Steam: 2 Mã (1 Slot còn, 1 đã giao).
    expect($tally->voidedUnits)->toBe(4)
        ->and($tally->voidedSlots)->toBe(6)
        ->and(StockUnit::query()->where('status', StockUnitStatus::Active)->count())->toBe(0)
        ->and(Slot::query()->where('status', SlotStatus::InStock)->count())->toBe(0)
        // Hàng khách đang dùng không bị đụng tới.
        ->and(Slot::query()->where('status', SlotStatus::Delivered)->count())->toBe(2)
        ->and(StockLedgerEntry::query()->latest('id')->first()->reason)
        ->toBe('Huỷ hàng hàng loạt cả kho: Lộ nội dung: Lộ cả khoá mã hoá lẫn backup');
});

it('xem trước số lượng trước khi huỷ, theo Sản phẩm và cả kho', function () {
    expect($this->exposure->plan($this->admin, null))
        ->voidedUnits->toBe(4)
        ->voidedSlots->toBe(6);

    $plan = $this->exposure->plan($this->admin, $this->netflix);

    expect($plan->voidedUnits)->toBe(2)
        ->and($plan->voidedSlots)->toBe(5)
        ->and($plan->keptUnits)->toBe(0)
        // Chưa huỷ gì.
        ->and(StockUnit::query()->where('status', StockUnitStatus::Voided)->count())->toBe(0);
});

it('bỏ lại Đơn vị hàng đang có Slot Đã giữ cho một Phiếu xuất', function () {
    $unit = StockUnit::where('content->username', 'b@shop.test')->sole();
    $slot = $unit->slots()->where('status', SlotStatus::InStock)->orderBy('id')->firstOrFail();

    // Giữ tay một Slot: phiếu Đang giữ của kênh API cũng để lại đúng dấu vết này.
    $slot->forceFill(['status' => SlotStatus::Reserved])->save();
    DB::table('slot_holds')->insert([
        'dispatch_line_id' => $this->dispatch->lines()->first()->id,
        'slot_id' => $slot->id,
        'stock_unit_id' => $unit->id,
        'held_at' => now(),
    ]);

    $tally = $this->exposure->voidExposed($this->admin, $this->netflix, 'Lộ nội dung');

    expect($tally->voidedUnits)->toBe(1)
        ->and($tally->keptUnits)->toBe(1)
        ->and($unit->fresh()->status)->toBe(StockUnitStatus::Active);
});

it('chỉ Quản trị, phải nhập lý do, và làm được khi kho đang tạm dừng', function () {
    app(DispatchFreeze::class)->freeze($this->admin, 'Nghi lộ nội dung');

    expect(fn () => $this->exposure->voidExposed($this->seller, $this->netflix, 'Lộ'))->toThrow(MissingRole::class)
        ->and(fn () => $this->exposure->voidExposed($this->admin, $this->netflix, '   '))->toThrow(InvalidVoid::class, 'phải nhập lý do')
        ->and(StockUnit::query()->where('status', StockUnitStatus::Voided)->count())->toBe(0);

    // Huỷ hàng không lấy thêm hàng ra khỏi kho, nên tạm dừng không chặn nó.
    expect($this->exposure->voidExposed($this->admin, $this->netflix, 'Lộ nội dung')->voidedUnits)->toBe(2);

    expect(fn () => $this->exposure->voidExposed($this->admin, $this->netflix, 'Lộ nội dung'))
        ->toThrow(InvalidVoid::class, 'Không còn Đơn vị hàng nào huỷ được');
});

it('liệt kê lần giao Tài khoản còn trong Hạn bảo hành; Mã dùng một lần không vào danh sách', function () {
    $affected = AffectedDelivery::forExposedAccounts();

    expect($affected)->toHaveCount(1)
        ->and($affected[0]->externalRef)->toBe('SP-1')
        ->and($affected[0]->customer)->toBe('Anh Minh')
        ->and($affected[0]->channelName)->toBe('Shopee')
        ->and($affected[0]->label())->toContain('Anh Minh')
        ->and(AffectedDelivery::forExposedAccounts($this->steam))->toBe([])
        ->and(AffectedDelivery::forExposedAccounts($this->netflix))->toHaveCount(1);

    // Quá Hạn bảo hành (30 ngày) thì khách không còn được bảo hành, không cần liên hệ nữa.
    $this->travelTo(CarbonImmutable::parse('2026-10-18 10:00'));

    expect(AffectedDelivery::forExposedAccounts())->toBe([]);
});

it('Slot đã Huỷ hàng không còn là Lần giao bị ảnh hưởng', function () {
    $delivery = Delivery::query()->whereHas('stockUnit', fn ($units) => $units->where('product_id', $this->netflix->id))->sole();

    app(StockVoid::class)->voidSlot($this->admin, $delivery->slot, VoidReason::WrongDelivery);

    expect(AffectedDelivery::forExposedAccounts())->toBe([]);
});

it('panel: Quản trị huỷ hàng loạt và thấy bảng Lần giao bị ảnh hưởng', function () {
    $this->actingAs($this->admin);
    $delivery = Delivery::query()->whereHas('stockUnit', fn ($units) => $units->where('product_id', $this->netflix->id))->sole();

    Livewire::test(DispatchFreezePage::class)
        ->assertCanSeeTableRecords([$delivery])
        ->callAction('voidExposed', data: [
            'scope' => 'product',
            'product_id' => $this->netflix->id,
            'note' => 'Lộ cả khoá lẫn backup',
        ])
        ->assertHasNoActionErrors();

    expect(StockUnit::query()->where('product_id', $this->netflix->id)->pluck('status')->all())
        ->toBe([StockUnitStatus::Voided, StockUnitStatus::Voided])
        // Khách vẫn đang dùng Slot đã giao: vẫn phải liên hệ.
        ->and(AffectedDelivery::forExposedAccounts())->toHaveCount(1);
});
