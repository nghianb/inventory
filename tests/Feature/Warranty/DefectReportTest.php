<?php

use App\Inventory\Access\MissingRole;
use App\Inventory\Access\Role;
use App\Inventory\Catalog\ContentFieldDraft;
use App\Inventory\Catalog\ContentFieldType;
use App\Inventory\Catalog\ProductCatalog;
use App\Inventory\Catalog\ProductDraft;
use App\Inventory\Catalog\ProductType;
use App\Inventory\Catalog\SupplierDirectory;
use App\Inventory\Dispatch\AffectedDelivery;
use App\Inventory\Dispatch\CorrectionDraft;
use App\Inventory\Dispatch\CorrectiveDelivery;
use App\Inventory\Dispatch\DispatchDraft;
use App\Inventory\Dispatch\DispatchLineDraft;
use App\Inventory\Dispatch\ManualDispatch;
use App\Inventory\Dispatch\SalesChannelDirectory;
use App\Inventory\Dispatch\SalesChannelDraft;
use App\Inventory\Encryption\KeyFingerprints;
use App\Inventory\Intake\BatchDraft;
use App\Inventory\Intake\BatchIntake;
use App\Inventory\Intake\BatchLineDraft;
use App\Inventory\Reveal\ContentReveal;
use App\Inventory\Reveal\InvalidReveal;
use App\Inventory\Reveal\RevealContextType;
use App\Inventory\Stock\InvalidVoid;
use App\Inventory\Stock\SellableStock;
use App\Inventory\Stock\SlotStatus;
use App\Inventory\Stock\StockUnitStatus;
use App\Inventory\Stock\StockVoid;
use App\Inventory\Stock\VoidReason;
use App\Inventory\Warranty\DefectReportDraft;
use App\Inventory\Warranty\DefectReporting;
use App\Inventory\Warranty\DefectReportStatus;
use App\Inventory\Warranty\DefectScope;
use App\Inventory\Warranty\InvalidDefectReport;
use App\Models\DefectReport;
use App\Models\Delivery;
use App\Models\Dispatch;
use App\Models\Product;
use App\Models\RevealLogEntry;
use App\Models\StockLedgerEntry;
use App\Models\StockUnit;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('intake');
    Storage::fake('local');
    $this->seed(RoleSeeder::class);
    app(KeyFingerprints::class)->register();
    $this->travelTo(CarbonImmutable::parse('2026-09-15 10:00'));

    $this->admin = staffMember(Role::QuanTri);
    $this->seller = staffMember(Role::BanHang);
    $this->reports = app(DefectReporting::class);
    $this->supplier = app(SupplierDirectory::class)->create($this->admin, 'Kinguin');
    $this->shopee = app(SalesChannelDirectory::class)->create($this->admin, new SalesChannelDraft('Shopee', requiresExternalRef: true));

    $catalog = app(ProductCatalog::class);
    $this->steam = $catalog->create($this->admin, new ProductDraft(
        type: ProductType::OneTimeCode,
        name: 'Steam Wallet 100k',
        code: 'STEAM-100K',
        fields: [new ContentFieldDraft('serial', 'Serial', sensitive: false), new ContentFieldDraft('code', 'Mã thẻ', dedupeKey: true)],
        warrantyDays: 7,
    ));
    $this->netflix = $catalog->create($this->admin, new ProductDraft(
        type: ProductType::Account,
        name: 'Netflix 1 tháng',
        code: 'NETFLIX-1M',
        fields: [
            new ContentFieldDraft('username', 'Tên đăng nhập', ContentFieldType::Email, sensitive: false, dedupeKey: true),
            new ContentFieldDraft('password', 'Mật khẩu'),
        ],
        defaultSlots: 3,
        warrantyDays: 30,
    ));
});

function defectStock(Product $product, string $content): void
{
    $intake = app(BatchIntake::class);
    $intake->confirm(test()->admin, $intake->submit(test()->admin, new BatchDraft(
        supplier: test()->supplier,
        receivedOn: CarbonImmutable::today(),
        lines: [new BatchLineDraft($product, 90_000, $content)],
    )));
}

/**
 * @param  array<int, int>  $quantities  số Slot theo id Sản phẩm
 */
function defectOrder(string $ref, array $quantities, ?string $customer = null): Dispatch
{
    $lines = collect($quantities)->map(fn (int $quantity, int $productId) => new DispatchLineDraft(Product::findOrFail($productId), $quantity))->values()->all();

    return app(ManualDispatch::class)->create(test()->seller, new DispatchDraft(test()->shopee, $ref, $lines, customer: $customer));
}

/**
 * @return list<Delivery>
 */
function defectDeliveries(Dispatch $dispatch): array
{
    return $dispatch->deliveries()->orderBy('deliveries.id')->get()->all();
}

it('Bán hàng tạo Báo lỗi từ Phiếu xuất cho nhiều Slot, mỗi Slot một Báo lỗi Chờ xác minh; áp dụng cho Tài khoản và Mã dùng một lần', function () {
    defectStock($this->steam, "SR1\tA-1");
    defectStock($this->netflix, "a@shop.test\tpw-a");
    $dispatch = defectOrder('SP-001', [$this->steam->id => 1, $this->netflix->id => 1]);
    [$code, $account] = defectDeliveries($dispatch);
    $this->travel(2)->days();

    $created = $this->reports->report($this->seller, [$code, $account], new DefectReportDraft('  Khách báo mã không nạp được  ', screenshot: UploadedFile::fake()->image('anh.png')));
    $path = $created[0]->screenshot_path;

    expect($created)->toHaveCount(2)
        ->and(DefectReport::orderBy('id')->get()->map(fn (DefectReport $report) => [
            $report->delivery_id, $report->slot_id, $report->stock_unit_id, $report->status, $report->description,
            $report->screenshot_path, $report->created_by, $report->created_at->toDateTimeString(),
        ])->all())->toBe([
            [$code->id, $code->slot_id, $code->stock_unit_id, DefectReportStatus::Pending, 'Khách báo mã không nạp được', $path, $this->seller->id, '2026-09-17 10:00:00'],
            [$account->id, $account->slot_id, $account->stock_unit_id, DefectReportStatus::Pending, 'Khách báo mã không nạp được', $path, $this->seller->id, '2026-09-17 10:00:00'],
        ])
        ->and($path)->toStartWith('defect-reports/')
        ->and(Storage::disk('local')->allFiles('defect-reports'))->toBe([$path]);
});

it('ngoài Hạn bảo hành hoặc thời hạn bảo hành 0 thì Bán hàng không tạo được Báo lỗi; Quản trị vượt được kèm lý do', function () {
    $free = app(ProductCatalog::class)->create($this->admin, new ProductDraft(
        type: ProductType::OneTimeCode,
        name: 'Quà tặng',
        code: 'GIFT',
        fields: [new ContentFieldDraft('code', 'Mã', dedupeKey: true)],
    ));
    defectStock($this->steam, "SR1\tA-1");
    defectStock($free, 'G-1');
    [$code, $gift] = defectDeliveries(defectOrder('SP-001', [$this->steam->id => 1, $free->id => 1]));
    $draft = new DefectReportDraft('Mã lỗi');
    $this->travel(7)->days();

    expect($this->reports->canReport($this->seller, $code))->toBeTrue()
        ->and($this->reports->canReport($this->seller, $gift))->toBeFalse()
        ->and(fn () => $this->reports->report($this->seller, [$gift], $draft))
        ->toThrow(InvalidDefectReport::class, "Lần giao {$gift->unitLabel()}: Sản phẩm không có bảo hành; chỉ Quản trị tạo Báo lỗi được, kèm lý do.");

    $this->travel(1)->days();

    expect($this->reports->canReport($this->seller, $code))->toBeFalse()
        ->and($this->reports->canReport($this->admin, $code))->toBeTrue()
        ->and(fn () => $this->reports->report($this->seller, [$code], $draft))
        ->toThrow(InvalidDefectReport::class, "Lần giao {$code->unitLabel()}: đã quá Hạn bảo hành 22/09/2026; chỉ Quản trị tạo Báo lỗi được, kèm lý do.")
        ->and(fn () => $this->reports->report($this->admin, [$code, $gift], $draft))
        ->toThrow(InvalidDefectReport::class, 'Báo lỗi ngoài Hạn bảo hành phải nhập lý do.')
        ->and(DefectReport::count())->toBe(0);

    $created = $this->reports->report($this->admin, [$code, $gift], new DefectReportDraft('Mã lỗi', overrideReason: '  Khách quen  '));

    expect(collect($created)->map(fn (DefectReport $report) => $report->warranty_override_reason)->all())->toBe(['Khách quen', 'Khách quen']);
});

it('Báo lỗi trong Hạn bảo hành không lưu lý do vượt', function () {
    defectStock($this->steam, "SR1\tA-1");
    [$code] = defectDeliveries(defectOrder('SP-001', [$this->steam->id => 1]));

    [$report] = $this->reports->report($this->admin, [$code], new DefectReportDraft('Mã lỗi', overrideReason: 'Không cần'));

    expect($report->warranty_override_reason)->toBeNull();
});

it('Báo lỗi không tạo được và không đổi gì', function (Closure $arrange, string $exception) {
    defectStock($this->netflix, "a@shop.test\tpw-a");
    $dispatch = defectOrder('SP-001', [$this->netflix->id => 2]);
    [$first, $second] = defectDeliveries($dispatch);
    [$actor, $draft, $message] = $arrange($first, $second);
    $before = DefectReport::orderBy('id')->get()->toArray();

    expect(fn () => $this->reports->report($actor, [$first, $second], $draft))->toThrow($exception, $message)
        ->and(DefectReport::orderBy('id')->get()->toArray())->toEqual($before);
})->with([
    'mô tả trống' => [fn () => [test()->seller, new DefectReportDraft('   '), 'Báo lỗi phải có mô tả.'], InvalidDefectReport::class],
    'Slot đã có Báo lỗi Chờ xác minh' => [function (Delivery $first, Delivery $second) {
        test()->reports->report(test()->seller, [$second], new DefectReportDraft('Lần trước'));

        return [test()->seller, new DefectReportDraft('Lần sau'), "Lần giao {$second->unitLabel()}: Slot đã có Báo lỗi Chờ xác minh."];
    }, InvalidDefectReport::class],
    'lần giao đã bị huỷ' => [function (Delivery $first) {
        DB::table('slots')->where('id', $first->slot_id)->update(['status' => SlotStatus::Voided->value]);

        return [test()->seller, new DefectReportDraft('Lỗi'), "Lần giao {$first->unitLabel()}: Slot không còn Đã giao; không Báo lỗi được."];
    }, InvalidDefectReport::class],
    'Nhập kho' => [fn () => [staffMember(Role::NhapKho), new DefectReportDraft('Lỗi'), ''], MissingRole::class],
]);

it('trong lúc Chờ xác minh, Slot Còn hàng của cùng Đơn vị hàng không thuộc Tồn bán được và không được chọn khi xuất; Bác bỏ thì mở bán lại', function () {
    defectStock($this->netflix, "a@shop.test\tpw-a\nb@shop.test\tpw-b");
    [$delivery] = defectDeliveries(defectOrder('SP-001', [$this->netflix->id => 1]));
    $stock = app(SellableStock::class);

    expect($stock->count($this->netflix))->toBe(5);

    [$report] = $this->reports->report($this->seller, [$delivery], new DefectReportDraft('Sai mật khẩu'));
    [$next] = defectDeliveries(defectOrder('SP-002', [$this->netflix->id => 1]));

    // Tài khoản a đã giao dở nhưng đang tạm ngừng: phiếu sau lấy Tài khoản b.
    expect($next->stockUnit->content['username'])->toBe('b@shop.test')
        ->and($stock->count($this->netflix))->toBe(2);

    $this->reports->reject($this->seller, $report, '  Khách nhập sai  ');

    expect($report->fresh())
        ->status->toBe(DefectReportStatus::Rejected)
        ->verification_note->toBe('Khách nhập sai')
        ->verified_by->toBe($this->seller->id)
        ->scope->toBeNull()
        ->and($delivery->stockUnit->fresh()->status)->toBe(StockUnitStatus::Active)
        ->and($stock->count($this->netflix))->toBe(4);
});

it('Xác nhận Phạm vi cả Đơn vị hàng: Đơn vị hàng chuyển Lỗi, ghi Sổ biến động kho; người tạo tự xác minh được', function () {
    defectStock($this->netflix, "a@shop.test\tpw-a");
    [$delivery] = defectDeliveries(defectOrder('SP-001', [$this->netflix->id => 1]));
    [$report] = $this->reports->report($this->seller, [$delivery], new DefectReportDraft('Tài khoản bị khoá'));
    $this->travel(3)->hours();
    $before = StockLedgerEntry::max('id');

    $this->reports->confirm($this->seller, $report, DefectScope::Unit, 'Đăng nhập thử bị khoá');

    expect($report->fresh())
        ->status->toBe(DefectReportStatus::Confirmed)
        ->scope->toBe(DefectScope::Unit)
        ->verification_note->toBe('Đăng nhập thử bị khoá')
        ->verified_by->toBe($this->seller->id)
        ->verified_at->toEqual(CarbonImmutable::parse('2026-09-15 13:00'))
        ->and($delivery->stockUnit->fresh()->status)->toBe(StockUnitStatus::Defective)
        ->and($delivery->slot->fresh()->status)->toBe(SlotStatus::Delivered)
        ->and(app(SellableStock::class)->count($this->netflix))->toBe(0)
        ->and(StockLedgerEntry::where('id', '>', (int) $before)->get()->map(fn (StockLedgerEntry $row) => [
            $row->stock_unit_id, $row->slot_id, $row->from_status, $row->to_status, $row->reason, $row->actor_id,
        ])->all())->toBe([
            [$delivery->stock_unit_id, null, 'active', 'defective', "Báo lỗi #{$report->id} Xác nhận cả Đơn vị hàng: Đăng nhập thử bị khoá", $this->seller->id],
        ]);
});

it('Xác nhận Phạm vi chỉ Slot: Đơn vị hàng vẫn Hoạt động, Slot còn trong kho bán lại được, không ghi Sổ biến động kho', function () {
    defectStock($this->netflix, "a@shop.test\tpw-a");
    [$delivery] = defectDeliveries(defectOrder('SP-001', [$this->netflix->id => 1]));
    [$report] = $this->reports->report($this->seller, [$delivery], new DefectReportDraft('Profile bị xoá'));
    $before = StockLedgerEntry::count();

    $this->reports->confirm($this->admin, $report, DefectScope::Slot, 'Khách khác phá profile');

    expect($report->fresh())->status->toBe(DefectReportStatus::Confirmed)->scope->toBe(DefectScope::Slot)
        ->and($delivery->stockUnit->fresh()->status)->toBe(StockUnitStatus::Active)
        ->and(StockLedgerEntry::count())->toBe($before)
        ->and(app(SellableStock::class)->count($this->netflix))->toBe(2)
        ->and($this->reports->affectedDeliveries($this->admin, $report->fresh()))->toBe([]);
});

it('xác minh không được và không đổi gì', function (Closure $arrange, string $exception) {
    defectStock($this->netflix, "a@shop.test\tpw-a");
    [$delivery] = defectDeliveries(defectOrder('SP-001', [$this->netflix->id => 1]));
    [$report] = $this->reports->report($this->seller, [$delivery], new DefectReportDraft('Lỗi'));
    [$verify, $message] = $arrange($report);
    $snapshot = fn () => [DefectReport::orderBy('id')->get()->toArray(), StockUnit::orderBy('id')->get(['id', 'status'])->toArray(), StockLedgerEntry::count()];
    $before = $snapshot();

    expect($verify)->toThrow($exception, $message)
        ->and($snapshot())->toEqual($before);
})->with([
    'Xác nhận thiếu ghi chú' => [fn (DefectReport $report) => [fn () => test()->reports->confirm(test()->seller, $report, DefectScope::Unit, '  '), 'Xác minh Báo lỗi phải có ghi chú.'], InvalidDefectReport::class],
    'Bác bỏ thiếu ghi chú' => [fn (DefectReport $report) => [fn () => test()->reports->reject(test()->seller, $report, ''), 'Xác minh Báo lỗi phải có ghi chú.'], InvalidDefectReport::class],
    'đã xác minh' => [function (DefectReport $report) {
        test()->reports->reject(test()->seller, $report, 'Không lỗi');

        return [fn () => test()->reports->confirm(test()->seller, $report, DefectScope::Unit, 'Lỗi thật'), 'Báo lỗi đã Bác bỏ; không xác minh lại được.'];
    }, InvalidDefectReport::class],
    'cả Đơn vị hàng đã Huỷ hàng' => [function (DefectReport $report) {
        app(StockVoid::class)->voidUnit(test()->admin, $report->stockUnit, VoidReason::ContentExposed);

        return [fn () => test()->reports->confirm(test()->seller, $report, DefectScope::Unit, 'Lỗi thật'), 'Đơn vị hàng đang Đã huỷ; chỉ Xác nhận được Phạm vi lỗi Chỉ Slot.'];
    }, InvalidDefectReport::class],
    'Nhập kho' => [fn (DefectReport $report) => [fn () => test()->reports->reject(staffMember(Role::NhapKho), $report, 'Không lỗi'), ''], MissingRole::class],
]);

it('tạo lại Báo lỗi sau Bác bỏ được; hiện lịch sử các lần Bác bỏ của Slot', function () {
    defectStock($this->netflix, "a@shop.test\tpw-a");
    [$delivery, $other] = defectDeliveries(defectOrder('SP-001', [$this->netflix->id => 2]));
    [$first] = $this->reports->report($this->seller, [$delivery], new DefectReportDraft('Lần 1'));
    $this->reports->reject($this->seller, $first, 'Khách nhập sai');
    [$otherReport] = $this->reports->report($this->seller, [$other], new DefectReportDraft('Slot khác'));
    $this->reports->reject($this->seller, $otherReport, 'Không lỗi');
    [$second] = $this->reports->report($this->seller, [$delivery], new DefectReportDraft('Lần 2'));
    $this->reports->reject($this->admin, $second, 'Vẫn đăng nhập được');

    expect($this->reports->canReport($this->seller, $delivery))->toBeTrue()
        ->and($this->reports->rejectedReports($this->seller, [$delivery])->map(fn (DefectReport $report) => [$report->description, $report->verification_note])->all())
        ->toBe([['Lần 2', 'Vẫn đăng nhập được'], ['Lần 1', 'Khách nhập sai']]);

    [$third] = $this->reports->report($this->seller, [$delivery], new DefectReportDraft('Lần 3'));
    $this->reports->confirm($this->seller, $third, DefectScope::Slot, 'Lỗi thật');

    expect($this->reports->canReport($this->seller, $delivery))->toBeFalse()
        ->and(fn () => $this->reports->report($this->seller, [$delivery], new DefectReportDraft('Lần 4')))
        ->toThrow(InvalidDefectReport::class, "Lần giao {$delivery->unitLabel()}: Slot đã có Báo lỗi Xác nhận.");
});

it('xác minh xem mã ghi Nhật ký xem mã ngữ cảnh Báo lỗi; chỉ khi Chờ xác minh', function () {
    defectStock($this->steam, "SR1\tA-1");
    [$delivery] = defectDeliveries(defectOrder('SP-001', [$this->steam->id => 1]));
    [$report] = $this->reports->report($this->seller, [$delivery], new DefectReportDraft('Mã lỗi'));
    $reveal = app(ContentReveal::class);

    expect($reveal->canRevealDefectReport($this->seller, $report))->toBeTrue()
        ->and($reveal->revealDefectReport($this->seller, $report)->fields)->toBe(['Serial' => 'SR1', 'Mã thẻ' => 'A-1'])
        ->and(RevealLogEntry::sole())
        ->user_id->toBe($this->seller->id)
        ->slot_id->toBe($delivery->slot_id)
        ->context->toBe(RevealContextType::DefectReport)
        ->context_id->toBe($report->id)
        ->reason->toBe("Xác minh Báo lỗi #{$report->id}");

    $this->reports->reject($this->seller, $report, 'Không lỗi');

    expect($reveal->canRevealDefectReport($this->seller, $report->fresh()))->toBeFalse()
        ->and(fn () => $reveal->revealDefectReport($this->seller, $report))->toThrow(InvalidReveal::class, 'Chỉ xem mã để xác minh Báo lỗi Chờ xác minh.')
        ->and(fn () => $reveal->revealDefectReport(staffMember(Role::NhapKho), $report))->toThrow(MissingRole::class)
        ->and(RevealLogEntry::count())->toBe(1);
});

it('Đơn vị hàng chuyển Lỗi thì liệt kê Lần giao bị ảnh hưởng và tạo Báo lỗi hàng loạt tự Xác nhận; không tự Đổi hàng', function () {
    defectStock($this->netflix, "a@shop.test\tpw-a");
    [$delivery] = defectDeliveries(defectOrder('SP-001', [$this->netflix->id => 1], customer: 'Anh Minh'));
    [$lan] = defectDeliveries(defectOrder('SP-002', [$this->netflix->id => 1], customer: 'Chị Lan'));
    [$hoa] = defectDeliveries(defectOrder('SP-003', [$this->netflix->id => 1], customer: 'Chị Hoa'));
    [$report] = $this->reports->report($this->seller, [$delivery], new DefectReportDraft('Tài khoản bị khoá'));

    expect($this->reports->affectedDeliveries($this->seller, $report))->toBe([]);

    $this->reports->confirm($this->seller, $report, DefectScope::Unit, 'Bị khoá thật');
    $report->refresh();

    expect(collect($this->reports->affectedDeliveries($this->seller, $report))->map(fn (AffectedDelivery $affected) => [$affected->deliveryId, $affected->customer])->all())
        ->toBe([[$lan->id, 'Chị Lan'], [$hoa->id, 'Chị Hoa']]);

    $this->travel(1)->hours();
    $created = $this->reports->confirmAffected($this->seller, $report, [$lan, $hoa]);

    expect(collect($created)->map(fn (DefectReport $bulk) => [
        $bulk->delivery_id, $bulk->status, $bulk->scope, $bulk->source_defect_report_id, $bulk->created_by, $bulk->verified_by,
        $bulk->description, $bulk->verification_note, $bulk->verified_at->toDateTimeString(),
    ])->all())->toBe([
        [$lan->id, DefectReportStatus::Confirmed, DefectScope::Unit, $report->id, $this->seller->id, $this->seller->id, 'Tài khoản bị khoá', "Tự Xác nhận theo Báo lỗi #{$report->id}", '2026-09-15 11:00:00'],
        [$hoa->id, DefectReportStatus::Confirmed, DefectScope::Unit, $report->id, $this->seller->id, $this->seller->id, 'Tài khoản bị khoá', "Tự Xác nhận theo Báo lỗi #{$report->id}", '2026-09-15 11:00:00'],
    ])
        ->and(Delivery::count())->toBe(3)
        ->and(fn () => $this->reports->confirmAffected($this->seller, $report, [$lan]))
        ->toThrow(InvalidDefectReport::class, "Lần giao {$lan->unitLabel()}: Slot đã có Báo lỗi Xác nhận.")
        ->and(fn () => $this->reports->confirmAffected($this->seller, $report, [$delivery]))
        ->toThrow(InvalidDefectReport::class, "Lần giao {$delivery->unitLabel()}: không phải Lần giao bị ảnh hưởng của Báo lỗi #{$report->id}.");
});

it('Báo lỗi hàng loạt theo cùng quy tắc Hạn bảo hành; chỉ từ Báo lỗi Xác nhận cả Đơn vị hàng', function () {
    defectStock($this->netflix, "a@shop.test\tpw-a");
    [$old] = defectDeliveries(defectOrder('SP-001', [$this->netflix->id => 1]));
    $this->travel(40)->days();
    [$delivery] = defectDeliveries(defectOrder('SP-002', [$this->netflix->id => 1]));
    [$report] = $this->reports->report($this->seller, [$delivery], new DefectReportDraft('Bị khoá'));

    expect(fn () => $this->reports->confirmAffected($this->seller, $report, [$old]))
        ->toThrow(InvalidDefectReport::class, "Chỉ tạo Báo lỗi hàng loạt từ Báo lỗi Xác nhận cả Đơn vị hàng; Báo lỗi #{$report->id} chưa làm Đơn vị hàng chuyển Lỗi.");

    $this->reports->confirm($this->seller, $report, DefectScope::Unit, 'Bị khoá thật');

    expect(fn () => $this->reports->confirmAffected($this->seller, $report, [$old]))
        ->toThrow(InvalidDefectReport::class, "Lần giao {$old->unitLabel()}: đã quá Hạn bảo hành 15/10/2026; chỉ Quản trị tạo Báo lỗi được, kèm lý do.")
        ->and($this->reports->confirmAffected($this->admin, $report, [$old], overrideReason: 'Khách VIP'))->sequence(
            fn ($bulk) => $bulk->warranty_override_reason->toBe('Khách VIP'),
        );
});

it('danh sách Báo lỗi Chờ xác minh quá 24 giờ', function () {
    defectStock($this->netflix, "a@shop.test\tpw-a");
    [$old, $recent, $done] = defectDeliveries(defectOrder('SP-001', [$this->netflix->id => 3]));
    [$oldReport] = $this->reports->report($this->seller, [$old], new DefectReportDraft('Cũ'));
    [$doneReport] = $this->reports->report($this->seller, [$done], new DefectReportDraft('Đã xử lý'));
    $this->reports->reject($this->seller, $doneReport, 'Không lỗi');
    $this->travel(20)->hours();
    $this->reports->report($this->seller, [$recent], new DefectReportDraft('Mới'));
    $this->travel(4)->hours();

    expect(DefectReport::query()->overdue()->pluck('id')->all())->toBe([]);

    $this->travel(1)->seconds();

    expect(DefectReport::query()->overdue()->pluck('id')->all())->toBe([$oldReport->id]);

    config(['inventory.defect.backlog_hours' => 2]);

    expect(DefectReport::query()->overdue()->count())->toBe(2);
});

it('Slot có Báo lỗi Chờ xác minh không Huỷ hàng hay Giao thay được cho tới khi xác minh', function () {
    defectStock($this->steam, "SR1\tA-1\nSR2\tA-2");
    [$delivery] = defectDeliveries(defectOrder('SP-001', [$this->steam->id => 1]));
    [$report] = $this->reports->report($this->seller, [$delivery], new DefectReportDraft('Mã lỗi'));
    $corrective = app(CorrectiveDelivery::class);
    $voids = app(StockVoid::class);
    $message = "Slot đang có Báo lỗi Chờ xác minh #{$report->id}; hãy xác minh trước.";

    expect($voids->canVoidSlot($this->admin, $delivery->slot))->toBeFalse()
        ->and($corrective->canCorrect($this->seller, $delivery))->toBeFalse()
        ->and(fn () => $voids->voidSlot($this->admin, $delivery->slot, VoidReason::WrongDelivery))->toThrow(InvalidVoid::class, $message)
        ->and(fn () => $corrective->correct($this->seller, $delivery, new CorrectionDraft(null, contentSent: false)))->toThrow(InvalidVoid::class, $message)
        ->and($delivery->slot->fresh()->status)->toBe(SlotStatus::Delivered)
        ->and(Delivery::count())->toBe(1);

    $this->reports->reject($this->seller, $report, 'Giao nhầm mã chứ không lỗi');

    expect($voids->canVoidSlot($this->admin, $delivery->slot->fresh()))->toBeTrue()
        ->and($corrective->correct($this->seller, $delivery, new CorrectionDraft(null, contentSent: false))->corrects_delivery_id)->toBe($delivery->id);
});

it('ảnh chỉ được lưu khi Báo lỗi tạo thành công', function () {
    defectStock($this->steam, "SR1\tA-1");
    [$delivery] = defectDeliveries(defectOrder('SP-001', [$this->steam->id => 1]));
    $this->reports->report($this->seller, [$delivery], new DefectReportDraft('Lần trước'));

    expect(fn () => $this->reports->report($this->seller, [$delivery], new DefectReportDraft('Lần sau', screenshot: UploadedFile::fake()->image('anh.png'))))
        ->toThrow(InvalidDefectReport::class)
        ->and(Storage::disk('local')->allFiles('defect-reports'))->toBe([]);
});

it('dọn ảnh Báo lỗi không còn Báo lỗi nào trỏ tới sau một giờ', function () {
    defectStock($this->steam, "SR1\tA-1");
    [$delivery] = defectDeliveries(defectOrder('SP-001', [$this->steam->id => 1]));
    [$report] = $this->reports->report($this->seller, [$delivery], new DefectReportDraft('Lỗi', screenshot: UploadedFile::fake()->image('anh.png')));
    $disk = Storage::disk('local');
    $disk->put('defect-reports/mo-coi-cu.png', 'x');
    $this->travel(2)->hours();
    $disk->put('defect-reports/mo-coi-moi.png', 'x');
    touch($disk->path('defect-reports/mo-coi-moi.png'), now()->getTimestamp());
    touch($disk->path('defect-reports/mo-coi-cu.png'), now()->subHours(2)->getTimestamp());
    touch($disk->path((string) $report->screenshot_path), now()->subHours(2)->getTimestamp());

    $this->artisan('inventory:defect-reports:purge')->expectsOutput('Đã xoá 1 ảnh Báo lỗi không còn dùng.')->assertSuccessful();

    expect($disk->allFiles('defect-reports'))->toEqualCanonicalizing([(string) $report->screenshot_path, 'defect-reports/mo-coi-moi.png']);
});
