<?php

use App\Inventory\Access\MissingRole;
use App\Inventory\Access\Role;
use App\Inventory\Catalog\ContentFieldDraft;
use App\Inventory\Catalog\ContentFieldType;
use App\Inventory\Catalog\ProductCatalog;
use App\Inventory\Catalog\StockForm;
use App\Inventory\Catalog\SupplierDirectory;
use App\Inventory\Dispatch\CorrectionDraft;
use App\Inventory\Dispatch\CorrectiveDelivery;
use App\Inventory\Dispatch\DispatchDraft;
use App\Inventory\Dispatch\DispatchEdit;
use App\Inventory\Dispatch\DispatchEditor;
use App\Inventory\Dispatch\DispatchLineDraft;
use App\Inventory\Dispatch\DispatchLineKind;
use App\Inventory\Dispatch\InvalidDispatch;
use App\Inventory\Dispatch\ManualDispatch;
use App\Inventory\Dispatch\OutOfStock;
use App\Inventory\Dispatch\SalesChannelDirectory;
use App\Inventory\Dispatch\SalesChannelDraft;
use App\Inventory\Encryption\KeyFingerprints;
use App\Inventory\Intake\BatchDraft;
use App\Inventory\Intake\BatchIntake;
use App\Inventory\Intake\BatchLineDraft;
use App\Inventory\Intake\ExpiryRule;
use App\Inventory\Reveal\ContentReveal;
use App\Inventory\Reveal\InvalidReveal;
use App\Inventory\Reveal\RevealContextType;
use App\Inventory\Stock\SellableStock;
use App\Inventory\Stock\SlotStatus;
use App\Inventory\Stock\StockUnitStatus;
use App\Inventory\Warranty\DefectReportDraft;
use App\Inventory\Warranty\DefectReporting;
use App\Inventory\Warranty\DefectResolution;
use App\Inventory\Warranty\DefectScope;
use App\Inventory\Warranty\InvalidDefectReport;
use App\Inventory\Warranty\InvalidReplacement;
use App\Inventory\Warranty\ReplacementAvailability;
use App\Inventory\Warranty\ReplacementDelivery;
use App\Inventory\Warranty\ReplacementDraft;
use App\Models\DefectReport;
use App\Models\Delivery;
use App\Models\Dispatch;
use App\Models\Product;
use App\Models\Replacement;
use App\Models\RevealLogEntry;
use App\Models\StockLedgerEntry;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('intake');
    $this->seed(RoleSeeder::class);
    app(KeyFingerprints::class)->register();
    $this->travelTo(CarbonImmutable::parse('2026-09-15 10:00'));

    $this->admin = staffMember(Role::Owner);
    $this->seller = staffMember(Role::BanHang);
    $this->reports = app(DefectReporting::class);
    $this->replacements = app(ReplacementDelivery::class);
    $this->supplier = app(SupplierDirectory::class)->create($this->admin, 'Kinguin');
    $this->shopee = app(SalesChannelDirectory::class)->create($this->admin, new SalesChannelDraft('Shopee', requiresExternalRef: true));

    $this->steam = productOf(
        StockForm::OneTimeCode,
        [new ContentFieldDraft('serial', 'Serial', sensitive: false), new ContentFieldDraft('code', 'Mã thẻ', dedupeKey: true)],
        'Steam Wallet 100k',
        'STEAM-100K',
        warrantyDays: 7,
    );
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
});

function replacementStock(Product $product, string $content, ?ExpiryRule $expiry = null): void
{
    $intake = app(BatchIntake::class);
    $intake->confirm(test()->admin, $intake->submit(test()->admin, new BatchDraft(
        supplier: test()->supplier,
        receivedOn: CarbonImmutable::today(),
        lines: [new BatchLineDraft($product, 90_000, $content, expiry: $expiry)],
    )));
}

function replacementOrder(string $ref, Product $product, int $quantity = 1, ?int $salePrice = null): Dispatch
{
    return app(ManualDispatch::class)->create(test()->seller, new DispatchDraft(test()->shopee, $ref, [new DispatchLineDraft($product, $quantity, $salePrice)]));
}

/**
 * Báo lỗi đã Xác nhận cho một lần giao.
 */
function confirmedDefect(Delivery $delivery, DefectScope $scope = DefectScope::Unit): DefectReport
{
    [$report] = test()->reports->report(test()->seller, [$delivery], new DefectReportDraft('Không dùng được'));
    test()->reports->confirm(test()->seller, $report, $scope, 'Đã kiểm tra');

    return $report->fresh();
}

function codeProduct(string $name, string $code, int $warrantyDays = 30, int $minRemainingDays = 0): Product
{
    return productOf(
        StockForm::OneTimeCode,
        [new ContentFieldDraft('code', 'Mã', sensitive: false, dedupeKey: true)],
        $name,
        $code,
        warrantyDays: $warrantyDays,
        minRemainingDays: $minRemainingDays,
    );
}

it('Xác nhận Báo lỗi đặt Kết quả xử lý Chờ đổi và vào danh sách Chờ đổi; Không đổi bắt buộc lý do', function () {
    replacementStock($this->steam, "SR1\tA-1\nSR2\tA-2\nSR3\tA-3");
    [$a, $b, $c] = replacementOrder('SP-001', $this->steam, 3)->deliveries()->orderBy('deliveries.id')->get()->all();
    $unit = confirmedDefect($a);
    $slot = confirmedDefect($b, DefectScope::Slot);
    [$pending] = $this->reports->report($this->seller, [$c], new DefectReportDraft('Chưa kiểm tra'));

    expect($unit->resolution)->toBe(DefectResolution::AwaitingReplacement)
        ->and($slot->resolution)->toBe(DefectResolution::AwaitingReplacement)
        ->and($pending->fresh()->resolution)->toBeNull()
        ->and(DefectReport::awaitingReplacement()->orderBy('id')->pluck('id')->all())->toBe([$unit->id, $slot->id])
        ->and($this->reports->canDeclineReplacement($this->seller, $unit))->toBeTrue()
        ->and($this->reports->canDeclineReplacement($this->seller, $pending))->toBeFalse()
        ->and(fn () => $this->reports->declineReplacement($this->seller, $unit, '   ', refunded: true))
        ->toThrow(InvalidDefectReport::class, 'Không đổi phải nhập lý do.');

    $this->travel(1)->hours();
    $this->reports->declineReplacement($this->seller, $unit, '  Hết hàng, đã hoàn tiền  ', refunded: true);

    expect($unit->fresh())
        ->resolution->toBe(DefectResolution::NotReplaced)
        ->resolution_note->toBe('Hết hàng, đã hoàn tiền')
        ->refunded->toBeTrue()
        ->resolved_by->toBe($this->seller->id)
        ->resolved_at->toEqual(now()->toImmutable())
        ->and(DefectReport::awaitingReplacement()->pluck('id')->all())->toBe([$slot->id])
        ->and($this->reports->canDeclineReplacement($this->seller, $unit->fresh()))->toBeFalse()
        ->and(fn () => $this->reports->declineReplacement($this->seller, $unit, 'Lần nữa', refunded: false))
        ->toThrow(InvalidDefectReport::class, 'Báo lỗi đã có Kết quả xử lý Không đổi; không đặt lại được.')
        ->and(fn () => $this->reports->declineReplacement($this->seller, $pending, 'Chưa xác minh', refunded: false))
        ->toThrow(InvalidDefectReport::class, 'Chỉ đặt Kết quả xử lý cho Báo lỗi Xác nhận.');

    $this->reports->declineReplacement($this->seller, $slot, 'Khách không cần nữa', refunded: false);

    expect($slot->fresh()->refunded)->toBeFalse();
});

it('Báo lỗi hàng loạt tự Xác nhận cũng Chờ đổi', function () {
    replacementStock($this->netflix, "a@shop.test\tpw-a");
    $first = replacementOrder('SP-001', $this->netflix)->deliveries()->firstOrFail();
    $second = replacementOrder('SP-002', $this->netflix)->deliveries()->firstOrFail();

    [$affected] = $this->reports->confirmAffected($this->seller, confirmedDefect($first), [$second]);

    expect($affected->resolution)->toBe(DefectResolution::AwaitingReplacement);
});

it('Đổi hàng cùng Sản phẩm: giao Slot của Đơn vị hàng khác vào Dòng xuất loại Đổi hàng của Phiếu xuất gốc, kế thừa Hạn bảo hành, lưu Chi phí đổi hàng; kể cả Phạm vi chỉ Slot', function () {
    replacementStock($this->netflix, "a@shop.test\tpw-a\nb@shop.test\tpw-b");
    $dispatch = replacementOrder('SP-001', $this->netflix, salePrice: 150_000);
    $broken = $dispatch->deliveries()->firstOrFail();
    $this->travel(10)->days();
    $report = confirmedDefect($broken, DefectScope::Slot);
    $before = StockLedgerEntry::max('id');

    $replacement = $this->replacements->replace($this->seller, $report, new ReplacementDraft);
    $new = $replacement->delivery;

    expect($replacement->sequence)->toBe(1)
        ->and($replacement)
        ->defect_report_id->toBe($report->id)
        ->original_delivery_id->toBe($broken->id)
        ->cost->toBe(30_000)
        ->defective_product_id->toBe($this->netflix->id)
        ->supplier_id->toBe($this->supplier->id)
        ->product_change_reason->toBeNull()
        ->short_expiry_accepted->toBeFalse()
        ->created_by->toBe($this->seller->id)
        // Slot chỉ lỗi thì Đơn vị hàng vẫn Hoạt động, nhưng không chọn lại Tài khoản vừa lỗi.
        ->and($new->stockUnit->content['username'])->toBe('b@shop.test')
        ->and($new->delivered_by)->toBe($this->seller->id)
        ->and($new->delivered_at)->toEqual(now()->toImmutable())
        ->and($new->warrantyEndsOn())->toEqual(CarbonImmutable::parse('2026-10-15'))
        ->and($new->dispatchLine)
        ->dispatch_id->toBe($dispatch->id)
        ->product_id->toBe($this->netflix->id)
        ->kind->toBe(DispatchLineKind::Replacement)
        ->quantity->toBe(1)
        ->sale_price->toBeNull()
        ->and($broken->dispatchLine->fresh())->quantity->toBe(1)->sale_price->toBe(150_000)
        ->and($broken->slot->fresh()->status)->toBe(SlotStatus::Delivered)
        ->and($report->fresh())
        ->resolution->toBe(DefectResolution::Replaced)
        ->resolved_by->toBe($this->seller->id)
        ->and(StockLedgerEntry::where('id', '>', $before)->get()->map(fn (StockLedgerEntry $row) => [$row->from_status, $row->to_status, $row->reason])->all())
        ->toBe([['in-stock', 'delivered', "Đổi hàng theo Báo lỗi #{$report->id}, thay lần giao #{$broken->id}"]]);
});

it('Đổi hàng chọn theo Thứ tự xuất nhưng thay Hạn còn lại tối thiểu bằng Hạn sử dụng phủ Hạn bảo hành kế thừa', function () {
    $garena = codeProduct('Garena 50k', 'GARENA-50K', warrantyDays: 30, minRemainingDays: 60);
    replacementStock($garena, 'GOC');
    $broken = replacementOrder('SP-001', $garena)->deliveries()->firstOrFail();
    replacementStock($garena, 'SOM', ExpiryRule::on(CarbonImmutable::parse('2026-10-10')));
    replacementStock($garena, 'VUA', ExpiryRule::on(CarbonImmutable::parse('2026-10-20')));
    replacementStock($garena, 'XA', ExpiryRule::on(CarbonImmutable::parse('2026-12-31')));
    $report = confirmedDefect($broken);

    expect($this->replacements->preview($this->seller, $report))
        ->toHaveProperty('sequence', 1)
        ->warrantyEndsOn->toEqual(CarbonImmutable::parse('2026-10-15'))
        ->requiresApproval->toBeFalse()
        ->availability->toBe(ReplacementAvailability::Covering)
        ->candidateExpiresOn->toEqual(CarbonImmutable::parse('2026-10-20'))
        // VUA không đạt Hạn còn lại tối thiểu 60 ngày nên không thuộc Tồn bán được.
        ->and(app(SellableStock::class)->count($garena))->toBe(1);

    $replacement = $this->replacements->replace($this->seller, $report, new ReplacementDraft);

    expect($replacement->delivery->stockUnit->content['code'])->toBe('VUA')
        ->and($replacement->delivery->warrantyEndsOn())->toEqual(CarbonImmutable::parse('2026-10-15'));
});

it('không có Slot phủ Hạn bảo hành thì cảnh báo; chấp nhận thì đổi Slot hạn dài nhất, Hạn bảo hành không vượt Hạn sử dụng', function () {
    $garena = codeProduct('Garena 50k', 'GARENA-50K');
    replacementStock($garena, 'GOC');
    $broken = replacementOrder('SP-001', $garena)->deliveries()->firstOrFail();
    replacementStock($garena, 'SOM1', ExpiryRule::on(CarbonImmutable::parse('2026-09-20')));
    replacementStock($garena, 'SOM2', ExpiryRule::on(CarbonImmutable::parse('2026-10-05')));
    $report = confirmedDefect($broken);
    $slots = fn () => DB::table('slots')->orderBy('id')->get()->all();
    $before = $slots();

    expect($this->replacements->preview($this->seller, $report))
        ->availability->toBe(ReplacementAvailability::ShorterOnly)
        ->candidateExpiresOn->toEqual(CarbonImmutable::parse('2026-10-05'))
        ->and(fn () => $this->replacements->replace($this->seller, $report, new ReplacementDraft))
        ->toThrow(InvalidReplacement::class, 'Không có Slot nào có Hạn sử dụng phủ Hạn bảo hành 15/10/2026; Slot hạn dài nhất hết hạn 05/10/2026. Chấp nhận Slot hạn ngắn hơn để Đổi hàng.')
        ->and($slots())->toEqual($before)
        ->and(Replacement::count())->toBe(0);

    $replacement = $this->replacements->replace($this->seller, $report, new ReplacementDraft(acceptShorterExpiry: true));

    expect($replacement->short_expiry_accepted)->toBeTrue()
        ->and($replacement->delivery->stockUnit->content['code'])->toBe('SOM2')
        ->and($replacement->delivery->warrantyEndsOn())->toEqual(CarbonImmutable::parse('2026-10-05'));
});

it('Đổi hàng sang Sản phẩm khác bắt buộc lý do; Chi phí đổi hàng vẫn gắn Sản phẩm của Đơn vị hàng lỗi', function () {
    replacementStock($this->steam, "SR1\tA-1");
    replacementStock($this->netflix, "a@shop.test\tpw-a");
    $broken = replacementOrder('SP-001', $this->steam)->deliveries()->firstOrFail();
    $report = confirmedDefect($broken);

    expect($this->replacements->preview($this->seller, $report, $this->netflix))->availability->toBe(ReplacementAvailability::Covering)
        ->and(fn () => $this->replacements->replace($this->seller, $report, new ReplacementDraft($this->netflix, productChangeReason: '  ')))
        ->toThrow(InvalidReplacement::class, 'Đổi hàng sang Sản phẩm khác phải nhập lý do.');

    $replacement = $this->replacements->replace($this->seller, $report, new ReplacementDraft($this->netflix, productChangeReason: '  Hết Steam  '));

    expect($replacement)
        ->product_change_reason->toBe('Hết Steam')
        ->defective_product_id->toBe($this->steam->id)
        ->cost->toBe(30_000)
        ->and($replacement->delivery->dispatchLine)->product_id->toBe($this->netflix->id)->kind->toBe(DispatchLineKind::Replacement)
        // Kế thừa Hạn bảo hành 7 ngày của lần giao Steam, không lấy 30 ngày của Netflix.
        ->and($replacement->delivery->warrantyEndsOn())->toEqual(CarbonImmutable::parse('2026-09-22'));
});

it('Sản phẩm gốc đã Ngừng bán vẫn Đổi hàng cùng Sản phẩm được', function () {
    replacementStock($this->steam, "SR1\tA-1\nSR2\tA-2");
    $report = confirmedDefect(replacementOrder('SP-001', $this->steam)->deliveries()->firstOrFail());
    app(ProductCatalog::class)->discontinue($this->admin, $this->steam);

    expect($this->replacements->replace($this->seller, $report, new ReplacementDraft)->delivery->stockUnit->content['serial'])->toBe('SR2');
});

it('chuỗi Đổi hàng luôn trỏ về lần giao gốc và kế thừa Hạn bảo hành gốc; từ lần đổi thứ 3 Bán hàng yêu cầu, Quản trị duyệt rồi Bán hàng mới Đổi hàng được', function () {
    replacementStock($this->steam, "SR1\tA-1\nSR2\tA-2\nSR3\tA-3\nSR4\tA-4\nSR5\tA-5");
    $original = replacementOrder('SP-001', $this->steam)->deliveries()->firstOrFail();
    $firstReport = confirmedDefect($original);

    expect($this->replacements->canRequestApproval($this->seller, $firstReport))->toBeFalse()
        ->and(fn () => $this->replacements->requestApproval($this->seller, $firstReport))
        ->toThrow(InvalidReplacement::class, 'Chỉ từ lần đổi thứ 3 trong chuỗi mới cần Quản trị duyệt.');

    $first = $this->replacements->replace($this->seller, $firstReport, new ReplacementDraft);
    $this->travel(2)->days();
    $second = $this->replacements->replace($this->seller, confirmedDefect($first->delivery), new ReplacementDraft);
    $third = confirmedDefect($second->delivery);

    expect([$first->sequence, $second->sequence, $first->approved_by, $second->approved_by])->toBe([1, 2, null, null])
        ->and($second->original_delivery_id)->toBe($original->id)
        ->and($second->delivery->warrantyEndsOn())->toEqual(CarbonImmutable::parse('2026-09-22'))
        ->and($this->replacements->preview($this->seller, $third))->toHaveProperty('sequence', 3)->requiresApproval->toBeTrue()
        ->and($this->replacements->canReplace($this->seller, $third))->toBeFalse()
        ->and($this->replacements->canReplace($this->admin, $third))->toBeTrue()
        ->and($this->replacements->canRequestApproval($this->admin, $third))->toBeFalse()
        ->and(fn () => $this->replacements->replace($this->seller, $third, new ReplacementDraft))
        ->toThrow(InvalidReplacement::class, 'Lần đổi thứ 3 trong chuỗi cần Quản trị duyệt.');

    $this->travel(1)->hours();
    $this->replacements->requestApproval($this->seller, $third);

    expect($third->fresh())
        ->replacement_approval_requested_by->toBe($this->seller->id)
        ->replacement_approval_requested_at->toEqual(now()->toImmutable())
        ->and(DefectReport::awaitingApproval()->pluck('id')->all())->toBe([$third->id])
        ->and($this->replacements->canRequestApproval($this->seller, $third->fresh()))->toBeFalse()
        ->and(fn () => $this->replacements->requestApproval($this->seller, $third))->toThrow(InvalidReplacement::class, 'Đã yêu cầu Quản trị duyệt Đổi hàng.')
        ->and(fn () => $this->replacements->approve($this->seller, $third))->toThrow(MissingRole::class)
        ->and($this->replacements->canApprove($this->seller, $third->fresh()))->toBeFalse()
        ->and($this->replacements->canApprove($this->admin, $third->fresh()))->toBeTrue();

    $this->replacements->approve($this->admin, $third);

    expect($third->fresh()->replacement_approved_by)->toBe($this->admin->id)
        ->and(DefectReport::awaitingApproval()->count())->toBe(0)
        ->and($this->replacements->canReplace($this->seller, $third->fresh()))->toBeTrue()
        ->and(fn () => $this->replacements->approve($this->admin, $third))->toThrow(InvalidReplacement::class, 'Đổi hàng đã được Quản trị duyệt.');

    $approved = $this->replacements->replace($this->seller, $third->fresh(), new ReplacementDraft);

    expect([$approved->sequence, $approved->original_delivery_id, $approved->approved_by, $approved->created_by])->toBe([3, $original->id, $this->admin->id, $this->seller->id])
        ->and(fn () => $this->replacements->approve($this->admin, $third))->toThrow(InvalidReplacement::class, 'Báo lỗi đã có Kết quả xử lý Đã đổi; không Đổi hàng được nữa.');

    // Quản trị tự Đổi hàng thì không cần duyệt riêng: chính họ là người duyệt.
    $byAdmin = $this->replacements->replace($this->admin, confirmedDefect($approved->delivery), new ReplacementDraft);

    expect([$byAdmin->sequence, $byAdmin->approved_by])->toBe([4, $this->admin->id]);
});

it('màn kết quả Đổi hàng ghi Nhật ký xem mã ngữ cảnh Đổi hàng, chỉ người vừa Đổi hàng và chỉ một lần', function () {
    replacementStock($this->netflix, "a@shop.test\tpw-a\nb@shop.test\tpw-b");
    $replacement = $this->replacements->replace($this->seller, confirmedDefect(replacementOrder('SP-001', $this->netflix)->deliveries()->firstOrFail()), new ReplacementDraft);
    $reveal = app(ContentReveal::class);

    expect(fn () => $reveal->revealReplacement($this->admin, $replacement))
        ->toThrow(InvalidReveal::class, 'Chỉ người vừa Đổi hàng xem được màn kết quả Đổi hàng.');

    $content = $reveal->revealReplacement($this->seller, $replacement);

    expect($content)
        ->deliveryId->toBe($replacement->delivery_id)
        ->fields->toBe(['Tên đăng nhập' => 'b@shop.test', 'Mật khẩu' => 'pw-b'])
        ->and(RevealLogEntry::sole())
        ->context->toBe(RevealContextType::Replacement)
        ->context_id->toBe($replacement->id)
        ->slot_id->toBe($replacement->delivery->slot_id)
        ->reason->toBe("Màn kết quả Đổi hàng #{$replacement->id}")
        ->and(fn () => $reveal->revealReplacement($this->seller, $replacement))
        ->toThrow(InvalidReveal::class, 'Màn kết quả Đổi hàng chỉ hiện một lần; xem lại qua Xem mã của lần giao.');
});

it('Đổi hàng không được và không đổi gì', function (Closure $arrange, string $exception, string $message) {
    replacementStock($this->steam, "SR1\tA-1\nSR2\tA-2");
    replacementStock($this->netflix, "a@shop.test\tpw-a");
    $broken = replacementOrder('SP-001', $this->steam)->deliveries()->firstOrFail();
    [$actor, $report, $draft] = $arrange($broken);
    $snapshot = fn () => [
        DB::table('dispatch_lines')->orderBy('id')->get()->all(),
        DB::table('deliveries')->orderBy('id')->get()->all(),
        DB::table('slots')->orderBy('id')->get()->all(),
        DB::table('defect_reports')->orderBy('id')->get()->all(),
        Replacement::count(),
        StockLedgerEntry::count(),
    ];
    $before = $snapshot();

    expect(fn () => $this->replacements->replace($actor, $report, $draft))->toThrow($exception, $message)
        ->and($snapshot())->toEqual($before);
})->with([
    'Báo lỗi Chờ xác minh' => [function (Delivery $broken) {
        [$report] = test()->reports->report(test()->seller, [$broken], new DefectReportDraft('Lỗi'));

        return [test()->seller, $report, new ReplacementDraft];
    }, InvalidReplacement::class, 'Chỉ Đổi hàng cho Báo lỗi Xác nhận.'],
    'Báo lỗi Không đổi' => [function (Delivery $broken) {
        $report = confirmedDefect($broken);
        test()->reports->declineReplacement(test()->seller, $report, 'Hoàn tiền', refunded: true);

        return [test()->seller, $report, new ReplacementDraft];
    }, InvalidReplacement::class, 'Báo lỗi đã có Kết quả xử lý Không đổi; không Đổi hàng được nữa.'],
    'thiếu hàng' => [function (Delivery $broken) {
        replacementOrder('SP-002', test()->steam);

        return [test()->seller, confirmedDefect($broken), new ReplacementDraft];
    }, OutOfStock::class, 'Không đủ hàng, không giao gì: "Steam Wallet 100k" cần 1, còn 0.'],
    'Sản phẩm khác Ngừng bán' => [function (Delivery $broken) {
        app(ProductCatalog::class)->discontinue(test()->admin, test()->netflix);

        return [test()->seller, confirmedDefect($broken), new ReplacementDraft(test()->netflix, productChangeReason: 'Hết Steam')];
    }, InvalidDispatch::class, 'Sản phẩm "Netflix 1 tháng" đã Ngừng bán.'],
    'Nhập kho' => [fn (Delivery $broken) => [staffMember(Role::NhapKho), confirmedDefect($broken), new ReplacementDraft], MissingRole::class, ''],
]);

it('Đơn vị hàng lỗi Phạm vi cả Đơn vị hàng không bị chọn lại khi Đổi hàng', function () {
    replacementStock($this->netflix, "a@shop.test\tpw-a\nb@shop.test\tpw-b");
    $report = confirmedDefect(replacementOrder('SP-001', $this->netflix)->deliveries()->firstOrFail());

    expect($report->stockUnit->status)->toBe(StockUnitStatus::Defective)
        ->and($this->replacements->replace($this->seller, $report, new ReplacementDraft)->delivery->stockUnit->content['username'])->toBe('b@shop.test');
});

it('lần giao Đổi hàng không Giao thay được; Dòng xuất loại Đổi hàng không có Giá bán', function () {
    replacementStock($this->steam, "SR1\tA-1\nSR2\tA-2\nSR3\tA-3");
    $dispatch = replacementOrder('SP-001', $this->steam, salePrice: 100_000);
    $new = $this->replacements->replace($this->seller, confirmedDefect($dispatch->deliveries()->firstOrFail()), new ReplacementDraft)->delivery;
    $corrective = app(CorrectiveDelivery::class);
    $editor = app(DispatchEditor::class);

    expect($corrective->canCorrect($this->seller, $new))->toBeFalse()
        ->and(fn () => $corrective->correct($this->seller, $new, new CorrectionDraft(product: null, contentSent: false)))
        ->toThrow(InvalidDispatch::class, 'Lần giao Đổi hàng không Giao thay được; hãy Báo lỗi rồi Đổi hàng.')
        ->and(fn () => $editor->edit($this->seller, $dispatch, new DispatchEdit('SP-001', null, null, [$new->dispatch_line_id => 50_000])))
        ->toThrow(InvalidDispatch::class, 'Dòng xuất loại Đổi hàng không có Giá bán.')
        ->and($editor->edit($this->seller, $dispatch, new DispatchEdit('SP-001', null, null, [$new->dispatch_line_id => null]))->id)->toBe($dispatch->id);
});
