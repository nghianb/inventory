<?php

use App\Filament\Resources\StockUnits\Pages\ListStockUnits;
use App\Inventory\Access\Role;
use App\Inventory\Catalog\ContentFieldDraft;
use App\Inventory\Catalog\ContentFieldType;
use App\Inventory\Catalog\StockForm;
use App\Inventory\Catalog\SupplierDirectory;
use App\Inventory\Dispatch\DispatchDraft;
use App\Inventory\Dispatch\DispatchLineDraft;
use App\Inventory\Dispatch\ManualDispatch;
use App\Inventory\Dispatch\SalesChannelDirectory;
use App\Inventory\Dispatch\SalesChannelDraft;
use App\Inventory\Encryption\KeyFingerprints;
use App\Inventory\Stock\StockDefect;
use App\Inventory\Warranty\DefectReportDraft;
use App\Inventory\Warranty\DefectReporting;
use App\Models\DefectReport;
use App\Models\StockUnit;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('intake');
    $this->seed(RoleSeeder::class);
    app(KeyFingerprints::class)->register();
    $this->travelTo(CarbonImmutable::parse('2026-09-15 10:00'));

    $this->admin = staffMember(Role::Owner);
    $this->seller = staffMember(Role::BanHang);
    $this->supplier = app(SupplierDirectory::class)->create($this->admin, 'Kinguin');
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
    $this->garena = productOf(
        StockForm::OneTimeCode,
        [new ContentFieldDraft('code', 'Mã', dedupeKey: true)],
        'Garena 50k',
        'GARENA-50K',
        warrantyDays: 30,
    );

    stockUp($this->netflix, "a@shop.test\tpw-a\nb@shop.test\tpw-b\nc@shop.test\tpw-c\nd@shop.test\tpw-d");
    stockUp($this->garena, 'G-1');

    $channel = app(SalesChannelDirectory::class)->create($this->admin, new SalesChannelDraft('Zalo'));
    $sell = fn (mixed $product, int $quantity): array => app(ManualDispatch::class)
        ->create($this->seller, new DispatchDraft($channel, null, [new DispatchLineDraft($product, $quantity)], customer: 'Anh Minh'))
        ->deliveries()->orderBy('id')->get()->all();

    // Tài khoản a: hai Slot Đã giao, mỗi Slot một Báo lỗi Chờ xác minh, còn một Slot trong kho.
    $this->paused = StockUnit::where('content->username', 'a@shop.test')->sole();
    app(DefectReporting::class)->report($this->seller, $sell($this->netflix, 2), new DefectReportDraft('Không đăng nhập được'));

    // Tài khoản b: chưa đụng tới, đủ điều kiện Tồn bán được.
    $this->sellable = StockUnit::where('content->username', 'b@shop.test')->sole();

    // Tài khoản c: Lỗi mà ba Slot vẫn còn trong kho.
    $this->defective = StockUnit::where('content->username', 'c@shop.test')->sole();
    app(StockDefect::class)->markDefective($this->admin, $this->defective, 'Nhà cung cấp thu hồi');

    // Tài khoản d: Hoạt động nhưng đã quá Hạn sử dụng, Slot vẫn còn trong kho.
    $this->expired = StockUnit::where('content->username', 'd@shop.test')->sole();
    $this->expired->forceFill(['expires_on' => CarbonImmutable::yesterday()])->save();

    // Mã G-1: Lỗi nhưng Slot duy nhất đã giao, không còn gì trong kho để khoá lại.
    $this->deliveredDefect = StockUnit::where('product_id', $this->garena->id)->sole();
    $sell($this->garena, 1);
    app(StockDefect::class)->markDefective($this->admin, $this->deliveredDefect, 'Khách báo lỗi');
});

it('năm tab chia danh sách Đơn vị hàng theo việc cần làm; Lỗi đã giao hết Slot chỉ nằm ở Tất cả', function () {
    $this->actingAs($this->admin);
    $units = [$this->paused, $this->sellable, $this->defective, $this->expired, $this->deliveredDefect];
    $only = fn (StockUnit $unit): array => array_values(array_filter($units, fn (StockUnit $other): bool => $other->id !== $unit->id));

    Livewire::test(ListStockUnits::class)
        ->assertCanSeeTableRecords($units)
        ->set('activeTab', 'sellable')
        ->assertCanSeeTableRecords([$this->sellable])
        ->assertCanNotSeeTableRecords($only($this->sellable))
        ->set('activeTab', 'paused')
        ->assertCanSeeTableRecords([$this->paused])
        ->assertCanNotSeeTableRecords($only($this->paused))
        ->set('activeTab', 'defective')
        ->assertCanSeeTableRecords([$this->defective])
        ->assertCanNotSeeTableRecords($only($this->defective))
        ->set('activeTab', 'expired')
        ->assertCanSeeTableRecords([$this->expired])
        ->assertCanNotSeeTableRecords($only($this->expired));
});

it('ba tab việc cần làm không rời nhau: đơn vị vừa quá hạn vừa có Báo lỗi Chờ xác minh nằm ở cả hai', function () {
    $this->actingAs($this->admin);
    $this->paused->forceFill(['expires_on' => CarbonImmutable::yesterday()])->save();

    Livewire::test(ListStockUnits::class)
        ->set('activeTab', 'paused')
        ->assertCanSeeTableRecords([$this->paused])
        ->set('activeTab', 'expired')
        ->assertCanSeeTableRecords([$this->paused, $this->expired]);
});

it('chỉ ba tab việc cần làm có badge; badge đếm Đơn vị hàng chứ không đếm Slot hay Báo lỗi', function () {
    $this->actingAs($this->admin);

    $tabs = Livewire::test(ListStockUnits::class)->instance()->getTabs();

    expect(collect($tabs)->map(fn (Tab $tab): array => [$tab->getLabel(), $tab->getBadge(), $tab->getBadgeColor()])->values()->all())->toBe([
        ['Tất cả', null, null],
        ['Bán được', null, null],
        ['Tạm ngừng vì Báo lỗi', '1', 'danger'],
        ['Hàng Lỗi còn trong kho', '1', 'warning'],
        ['Quá hạn', '1', 'danger'],
    ]);
});

it('hai bộ lọc Sản phẩm và Trạng thái vẫn lọc như cũ bên trong tab đang chọn', function () {
    $this->actingAs($this->admin);

    Livewire::test(ListStockUnits::class)
        ->filterTable('product', $this->garena->id)
        ->assertCanSeeTableRecords([$this->deliveredDefect])
        ->assertCanNotSeeTableRecords([$this->sellable])
        ->filterTable('product', null)
        ->filterTable('status', 'defective')
        ->assertCanSeeTableRecords([$this->defective, $this->deliveredDefect])
        ->assertCanNotSeeTableRecords([$this->sellable, $this->paused, $this->expired]);
});

it('Đơn vị hàng chuyển Lỗi rồi Khôi phục thì rời tab Hàng Lỗi còn trong kho và về lại Bán được', function () {
    $this->actingAs($this->admin);
    app(StockDefect::class)->restore($this->admin, $this->defective, 'Nhà cung cấp mở khoá');

    Livewire::test(ListStockUnits::class)
        ->set('activeTab', 'defective')
        ->assertCanNotSeeTableRecords([$this->defective])
        ->set('activeTab', 'sellable')
        ->assertCanSeeTableRecords([$this->defective, $this->sellable]);
});

it('Báo lỗi Bác bỏ hết thì Đơn vị hàng rời tab Tạm ngừng vì Báo lỗi', function () {
    $this->actingAs($this->admin);
    $reports = app(DefectReporting::class);

    foreach (DefectReport::where('stock_unit_id', $this->paused->id)->get() as $report) {
        $reports->reject($this->seller, $report, 'Khách nhập sai mật khẩu');
    }

    Livewire::test(ListStockUnits::class)
        ->set('activeTab', 'paused')
        ->assertCanNotSeeTableRecords([$this->paused])
        ->set('activeTab', 'sellable')
        ->assertCanSeeTableRecords([$this->paused]);
});
