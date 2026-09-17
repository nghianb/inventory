<?php

use App\Inventory\Access\MissingRole;
use App\Inventory\Access\Role;
use App\Inventory\Catalog\ContentFieldDraft;
use App\Inventory\Catalog\ContentFieldType;
use App\Inventory\Catalog\ProductCatalog;
use App\Inventory\Catalog\ProductDraft;
use App\Inventory\Catalog\ProductType;
use App\Inventory\Catalog\SupplierDirectory;
use App\Inventory\Claims\ClaimOutcome;
use App\Inventory\Claims\ClaimOutcomeDraft;
use App\Inventory\Claims\InvalidSupplierClaim;
use App\Inventory\Claims\SupplierClaims;
use App\Inventory\Claims\SupplierClaimStatus;
use App\Inventory\Encryption\KeyFingerprints;
use App\Inventory\Intake\BatchDraft;
use App\Inventory\Intake\BatchIntake;
use App\Inventory\Intake\BatchLineDraft;
use App\Inventory\Intake\BatchStatus;
use App\Inventory\Intake\InvalidBatch;
use App\Inventory\Reveal\ContentReveal;
use App\Inventory\Reveal\InvalidReveal;
use App\Inventory\Reveal\RevealActor;
use App\Inventory\Reveal\RevealContext;
use App\Inventory\Reveal\RevealContextType;
use App\Inventory\Stock\StockDefect;
use App\Models\RevealLogEntry;
use App\Models\Slot;
use App\Models\StockUnit;
use App\Models\SupplierClaimUnit;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('intake');
    $this->seed(RoleSeeder::class);
    app(KeyFingerprints::class)->register();
    $this->travelTo(CarbonImmutable::parse('2026-09-15 10:00'));

    $this->admin = staffMember(Role::Owner);
    $this->stocker = staffMember(Role::NhapKho);
    $this->seller = staffMember(Role::BanHang);
    $this->claims = app(SupplierClaims::class);
    $this->netflix = app(ProductCatalog::class)->create($this->admin, new ProductDraft(
        type: ProductType::Account,
        name: 'Netflix 1 tháng',
        code: 'NETFLIX-1M',
        fields: [
            new ContentFieldDraft('username', 'Tên đăng nhập', ContentFieldType::Email, sensitive: false, dedupeKey: true),
            new ContentFieldDraft('password', 'Mật khẩu'),
        ],
        defaultSlots: 3,
    ));

    $intake = app(BatchIntake::class);
    $this->kinguin = app(SupplierDirectory::class)->create($this->admin, 'Kinguin');
    $this->g2a = app(SupplierDirectory::class)->create($this->admin, 'G2A');
    $import = fn ($supplier, string $content) => $intake->confirm($this->admin, $intake->submit($this->admin, new BatchDraft(
        supplier: $supplier,
        receivedOn: CarbonImmutable::today(),
        lines: [new BatchLineDraft($this->netflix, 90_000, $content)],
    )));
    $import($this->kinguin, "a@shop.test\tpw-a\nb@shop.test\tpw-b\nc@shop.test\tpw-c");
    $import($this->g2a, "g@shop.test\tpw-g");

    $unit = fn (string $username): StockUnit => StockUnit::where('content->username', $username)->sole();
    [$this->a, $this->b, $this->c, $this->g] = [$unit('a@shop.test'), $unit('b@shop.test'), $unit('c@shop.test'), $unit('g@shop.test')];

    foreach ([$this->a, $this->b, $this->g] as $defective) {
        app(StockDefect::class)->markDefective($this->admin, $defective, 'Nhà cung cấp thu hồi');
    }
});

/**
 * @return list<int> id Đơn vị hàng Lỗi chưa khiếu nại
 */
function unclaimedIds(): array
{
    return app(SupplierClaims::class)->unclaimedDefectiveUnits()->orderBy('stock_units.id')->pluck('stock_units.id')->all();
}

/**
 * @return list<int> id Đơn vị hàng còn trong khiếu nại
 */
function claimedIds($claim): array
{
    return $claim->claimUnits()->where('active', true)->reorder('stock_unit_id')->pluck('stock_unit_id')->all();
}

it('Nhập kho tạo Khiếu nại Nháp gồm Đơn vị hàng Lỗi của đúng một Nhà cung cấp; chúng rời danh sách Lỗi chưa khiếu nại', function () {
    expect(unclaimedIds())->toBe([$this->a->id, $this->b->id, $this->g->id]);

    $claim = $this->claims->create($this->stocker, $this->kinguin, [$this->b, $this->a], '  Thu hồi đợt 9  ');

    expect($claim->fresh())
        ->status->toBe(SupplierClaimStatus::Draft)
        ->supplier_id->toBe($this->kinguin->id)
        ->note->toBe('Thu hồi đợt 9')
        ->created_by->toBe($this->stocker->id)
        ->and(claimedIds($claim))->toBe([$this->a->id, $this->b->id])
        ->and(unclaimedIds())->toBe([$this->g->id]);
});

it('Khiếu nại chỉ nhận Đơn vị hàng Lỗi, của đúng Nhà cung cấp, chưa nằm trong khiếu nại khác; Bán hàng không làm được', function () {
    expect(fn () => $this->claims->create($this->stocker, $this->kinguin, []))
        ->toThrow(InvalidSupplierClaim::class, 'Khiếu nại phải có ít nhất một Đơn vị hàng.')
        ->and(fn () => $this->claims->create($this->stocker, $this->kinguin, [$this->a, $this->c]))
        ->toThrow(InvalidSupplierClaim::class, "Đơn vị hàng #{$this->c->id} không Lỗi; chỉ khiếu nại Đơn vị hàng Lỗi.")
        ->and(fn () => $this->claims->create($this->stocker, $this->kinguin, [$this->a, $this->g]))
        ->toThrow(InvalidSupplierClaim::class, "Đơn vị hàng #{$this->g->id} không thuộc Nhà cung cấp Kinguin.")
        ->and(fn () => $this->claims->create($this->seller, $this->kinguin, [$this->a]))
        ->toThrow(MissingRole::class);

    $this->claims->create($this->stocker, $this->kinguin, [$this->a]);

    expect(fn () => $this->claims->create($this->admin, $this->kinguin, [$this->b, $this->a]))
        ->toThrow(InvalidSupplierClaim::class, "Đơn vị hàng #{$this->a->id} đã nằm trong Khiếu nại #")
        ->and(SupplierClaimUnit::count())->toBe(1);
});

it('Nháp thêm và gỡ được Đơn vị hàng; gửi rồi thì không sửa được, gửi phải còn Đơn vị hàng', function () {
    $claim = $this->claims->create($this->stocker, $this->kinguin, [$this->a]);
    $this->claims->addUnits($this->stocker, $claim, [$this->b]);
    $this->claims->removeUnit($this->stocker, $claim->claimUnits()->where('stock_unit_id', $this->a->id)->sole());

    expect(claimedIds($claim))->toBe([$this->b->id])
        ->and(unclaimedIds())->toBe([$this->a->id, $this->g->id])
        ->and($claim->claimUnits()->where('stock_unit_id', $this->a->id)->sole())
        ->active->toBeFalse()
        ->removed_at->toEqual(now()->toImmutable())
        ->removal_reason->toBe('Gỡ khỏi Khiếu nại Nháp');

    // Gỡ rồi thêm lại được.
    $this->claims->addUnits($this->stocker, $claim, [$this->a]);
    $this->claims->send($this->stocker, $claim);

    expect($claim->fresh())->status->toBe(SupplierClaimStatus::Sent)->sent_by->toBe($this->stocker->id)->sent_at->toEqual(now()->toImmutable())
        ->and(claimedIds($claim))->toBe([$this->a->id, $this->b->id])
        ->and(fn () => $this->claims->addUnits($this->stocker, $claim, [$this->g]))->toThrow(InvalidSupplierClaim::class, 'Chỉ sửa được Khiếu nại Nháp.')
        ->and(fn () => $this->claims->removeUnit($this->stocker, $claim->claimUnits()->first()))->toThrow(InvalidSupplierClaim::class, 'Chỉ sửa được Khiếu nại Nháp.')
        ->and(fn () => $this->claims->send($this->stocker, $claim))->toThrow(InvalidSupplierClaim::class, 'Chỉ gửi được Khiếu nại Nháp.');

    $empty = $this->claims->create($this->stocker, $this->g2a, [$this->g]);
    $this->claims->removeUnit($this->stocker, $empty->claimUnits()->sole());

    expect(fn () => $this->claims->send($this->stocker, $empty))->toThrow(InvalidSupplierClaim::class, 'Khiếu nại không còn Đơn vị hàng nào để gửi.');
});

it('Giải quyết ghi kết quả từng Đơn vị hàng: bồi hoàn tiền kèm số tiền và ngày, hàng thay thế, bị từ chối', function () {
    $claim = $this->claims->create($this->stocker, $this->kinguin, [$this->a, $this->b]);

    expect(fn () => $this->claims->resolve($this->stocker, $claim, []))->toThrow(InvalidSupplierClaim::class, 'Chỉ giải quyết được Khiếu nại Đã gửi.');

    $this->claims->send($this->stocker, $claim);
    [$unitA, $unitB] = $claim->claimUnits;
    $this->travel(3)->days();

    expect(fn () => $this->claims->resolve($this->stocker, $claim, [$unitA->id => ClaimOutcomeDraft::rejected()]))
        ->toThrow(InvalidSupplierClaim::class, "Đơn vị hàng #{$this->b->id} chưa có kết quả.")
        ->and(fn () => $this->claims->resolve($this->stocker, $claim, [$unitA->id => ClaimOutcomeDraft::refund(0, CarbonImmutable::today()), $unitB->id => ClaimOutcomeDraft::rejected()]))
        ->toThrow(InvalidSupplierClaim::class, "Bồi hoàn tiền của Đơn vị hàng #{$this->a->id} phải có số tiền lớn hơn 0.")
        ->and(fn () => $this->claims->resolve($this->stocker, $claim, [$unitA->id => new ClaimOutcomeDraft(ClaimOutcome::Refund, 50_000), $unitB->id => ClaimOutcomeDraft::rejected()]))
        ->toThrow(InvalidSupplierClaim::class, "Bồi hoàn tiền của Đơn vị hàng #{$this->a->id} phải có ngày.")
        ->and(fn () => $this->claims->resolve($this->stocker, $claim, [$unitA->id => ClaimOutcomeDraft::refund(50_000, CarbonImmutable::tomorrow()), $unitB->id => ClaimOutcomeDraft::rejected()]))
        ->toThrow(InvalidSupplierClaim::class, "Ngày bồi hoàn tiền của Đơn vị hàng #{$this->a->id} không được sau hôm nay.")
        ->and(fn () => $this->claims->resolve($this->seller, $claim, []))->toThrow(MissingRole::class)
        ->and($claim->fresh()->status)->toBe(SupplierClaimStatus::Sent);

    $this->claims->resolve($this->stocker, $claim, [
        $unitA->id => ClaimOutcomeDraft::refund(60_000, CarbonImmutable::yesterday(), '  Chuyển khoản  '),
        $unitB->id => ClaimOutcomeDraft::replacementGoods(),
    ]);

    expect($claim->fresh())->status->toBe(SupplierClaimStatus::Resolved)->resolved_by->toBe($this->stocker->id)->resolved_at->toEqual(now()->toImmutable())
        ->and($claim->claimUnits()->get()->map(fn (SupplierClaimUnit $row) => [$row->outcome, $row->refund_amount, $row->refunded_on?->toDateString(), $row->outcome_note])->all())
        ->toBe([
            [ClaimOutcome::Refund, 60_000, '2026-09-17', 'Chuyển khoản'],
            [ClaimOutcome::ReplacementGoods, null, null, null],
        ])
        // Đã khiếu nại xong: không quay lại danh sách chưa khiếu nại, không huỷ được.
        ->and(unclaimedIds())->toBe([$this->g->id])
        ->and(fn () => $this->claims->cancel($this->stocker, $claim, 'Nhầm'))->toThrow(InvalidSupplierClaim::class, 'Chỉ huỷ được Khiếu nại Nháp hoặc Đã gửi.');
});

it('Huỷ Khiếu nại Nháp hoặc Đã gửi kèm lý do: Đơn vị hàng quay lại danh sách Lỗi chưa khiếu nại', function () {
    $claim = $this->claims->create($this->stocker, $this->kinguin, [$this->a, $this->b]);
    $this->claims->send($this->stocker, $claim);

    expect(fn () => $this->claims->cancel($this->stocker, $claim, '  '))->toThrow(InvalidSupplierClaim::class, 'Huỷ Khiếu nại phải nhập lý do.');

    $this->claims->cancel($this->stocker, $claim, 'Nhà cung cấp đóng cửa');

    expect($claim->fresh())->status->toBe(SupplierClaimStatus::Cancelled)->cancelled_by->toBe($this->stocker->id)->cancel_reason->toBe('Nhà cung cấp đóng cửa')
        ->and(claimedIds($claim))->toBe([])
        ->and(unclaimedIds())->toBe([$this->a->id, $this->b->id, $this->g->id]);

    // Khiếu nại lại được trong khiếu nại mới.
    expect(claimedIds($this->claims->create($this->stocker, $this->kinguin, [$this->a])))->toBe([$this->a->id]);
});

it('Khôi phục Đơn vị hàng gỡ nó khỏi khiếu nại chưa giải quyết; khiếu nại đã giải quyết giữ nguyên', function () {
    $draft = $this->claims->create($this->stocker, $this->kinguin, [$this->a, $this->b]);
    $sent = $this->claims->create($this->stocker, $this->g2a, [$this->g]);
    $this->claims->send($this->stocker, $sent);
    $defects = app(StockDefect::class);

    $defects->restore($this->admin, $this->a, 'Nhà cung cấp mở khoá');
    $defects->restore($this->admin, $this->g, 'Nhà cung cấp mở khoá');

    expect(claimedIds($draft))->toBe([$this->b->id])
        ->and($draft->claimUnits()->where('stock_unit_id', $this->a->id)->sole())->active->toBeFalse()->removal_reason->toBe('Khôi phục Đơn vị hàng: Nhà cung cấp mở khoá')
        ->and(claimedIds($sent))->toBe([])
        // Khiếu nại Đã gửi hết Đơn vị hàng thì tự huỷ; Nháp còn Đơn vị hàng giữ nguyên.
        ->and($sent->fresh())->status->toBe(SupplierClaimStatus::Cancelled)->cancelled_by->toBe($this->admin->id)->cancel_reason->toBe(SupplierClaims::AUTO_CANCEL_REASON)
        ->and($draft->fresh()->status)->toBe(SupplierClaimStatus::Draft);

    // Lỗi lại thì là hàng Lỗi chưa khiếu nại.
    $defects->markDefective($this->admin, $this->a, 'Hỏng lại');

    expect(unclaimedIds())->toBe([$this->a->id]);

    $this->claims->send($this->stocker, $draft);
    $this->claims->resolve($this->stocker, $draft, [$draft->claimUnits()->where('active', true)->sole()->id => ClaimOutcomeDraft::rejected()]);
    $defects->restore($this->admin, $this->b, 'Sửa xong');
    $resolvedRow = $draft->claimUnits()->where('active', true)->sole();

    // Đã Khôi phục là hàng bán được: không xem mã qua khiếu nại nữa.
    expect(claimedIds($draft))->toBe([$this->b->id])
        ->and(app(ContentReveal::class)->canRevealClaimUnit($this->stocker, $resolvedRow))->toBeFalse()
        ->and(fn () => app(ContentReveal::class)->revealClaimUnit($this->stocker, $resolvedRow))
        ->toThrow(InvalidReveal::class, 'Đơn vị hàng không còn Lỗi; không xem mã qua Khiếu nại được.');

    // Lỗi lại sau Khôi phục là lần Lỗi mới: khiếu nại lại được dù khiếu nại cũ đã giải quyết.
    $this->travel(1)->minute();
    $defects->markDefective($this->admin, $this->b, 'Hỏng lại');

    expect(unclaimedIds())->toBe([$this->a->id, $this->b->id])
        ->and(claimedIds($this->claims->create($this->stocker, $this->kinguin, [$this->b])))->toBe([$this->b->id])
        ->and(unclaimedIds())->toBe([$this->a->id]);
});

it('Xem mã Đơn vị hàng trong khiếu nại ghi Nhật ký xem mã ngữ cảnh Khiếu nại nhà cung cấp; Bán hàng và Đơn vị hàng đã gỡ thì không', function () {
    $claim = $this->claims->create($this->stocker, $this->kinguin, [$this->a, $this->b]);
    [$unitA, $unitB] = $claim->claimUnits;
    $reveal = app(ContentReveal::class);

    expect($reveal->revealClaimUnit($this->stocker, $unitA)->fields)->toBe(['Tên đăng nhập' => 'a@shop.test', 'Mật khẩu' => 'pw-a'])
        // Mỗi Slot của Tài khoản một dòng Nhật ký xem mã.
        ->and(RevealLogEntry::orderBy('id')->get()->map(fn (RevealLogEntry $row) => [$row->user_id, $row->context, $row->context_id, $row->stock_unit_id, $row->slot_id, $row->reason])->all())
        ->toBe($this->a->slots->map(fn (Slot $slot) => [$this->stocker->id, RevealContextType::SupplierClaim, $claim->id, $this->a->id, $slot->id, "Khiếu nại nhà cung cấp #{$claim->id}"])->all())
        ->and($reveal->canRevealClaimUnit($this->stocker, $unitA))->toBeTrue()
        ->and($reveal->canRevealClaimUnit($this->seller, $unitA))->toBeFalse()
        ->and(fn () => $reveal->revealClaimUnit($this->seller, $unitA))->toThrow(MissingRole::class);

    $this->claims->removeUnit($this->stocker, $unitB);

    expect($reveal->canRevealClaimUnit($this->stocker, $unitB->fresh()))->toBeFalse()
        ->and(fn () => $reveal->revealClaimUnit($this->admin, $unitB))->toThrow(InvalidReveal::class, 'Đơn vị hàng không còn nằm trong Khiếu nại; không xem mã qua Khiếu nại được.')
        ->and(fn () => $reveal->reveal(RevealActor::staff($this->admin), $this->a->slots[0], RevealContext::supplierClaim($claim)))
        ->toThrow(InvalidReveal::class, 'Nội dung Khiếu nại nhà cung cấp chỉ xem qua Xem mã của Đơn vị hàng trong khiếu nại.')
        ->and(RevealLogEntry::count())->toBe(3);

    // Khiếu nại đã giải quyết vẫn xem được để đối chiếu; đã huỷ thì không.
    $this->claims->send($this->stocker, $claim);
    $this->claims->resolve($this->stocker, $claim, [$unitA->id => ClaimOutcomeDraft::rejected()]);

    expect($reveal->revealClaimUnit($this->stocker, $unitA)->fields)->toHaveKey('Mật khẩu');

    $cancelled = $this->claims->create($this->stocker, $this->g2a, [$this->g]);
    $this->claims->cancel($this->stocker, $cancelled, 'Nhầm');

    expect(fn () => $reveal->revealClaimUnit($this->stocker, $cancelled->claimUnits()->sole()))->toThrow(InvalidReveal::class);
});

it('Hàng thay thế vào kho bằng Lô nhập bình thường liên kết với Khiếu nại, Giá vốn 0 kể cả khi file có cột gia_von', function () {
    $claim = $this->claims->create($this->stocker, $this->kinguin, [$this->a, $this->b]);
    $this->claims->send($this->stocker, $claim);
    [$unitA, $unitB] = $claim->claimUnits;
    $intake = app(BatchIntake::class);
    $replacement = fn (int $cost = 0, $supplier = null, $forClaim = null) => new BatchDraft(
        supplier: $supplier ?? $this->kinguin,
        receivedOn: CarbonImmutable::today(),
        lines: [BatchLineDraft::file($this->netflix, $cost, "username,password,gia_von\nr@shop.test,pw-r,50000\ns@shop.test,pw-s,", 'thay-the.csv')],
        supplierClaim: $forClaim ?? $claim,
    );

    expect(fn () => $intake->submit($this->stocker, $replacement()))
        ->toThrow(InvalidBatch::class, 'Chỉ nhập hàng thay thế cho Khiếu nại Đã giải quyết có kết quả Hàng thay thế.');

    $this->claims->resolve($this->stocker, $claim, [$unitA->id => ClaimOutcomeDraft::replacementGoods(), $unitB->id => ClaimOutcomeDraft::replacementGoods()]);

    // Không nhập quá số Đơn vị hàng được thay: 3 Đơn vị hàng cho 2 kết quả Hàng thay thế thì không xác nhận được.
    $tooMany = $intake->submit($this->stocker, new BatchDraft(
        supplier: $this->kinguin,
        receivedOn: CarbonImmutable::today(),
        lines: [new BatchLineDraft($this->netflix, 0, "x@shop.test\tpw-x\ny@shop.test\tpw-y\nz@shop.test\tpw-z")],
        supplierClaim: $claim,
    ));

    expect(fn () => $intake->confirm($this->stocker, $tooMany))
        ->toThrow(InvalidBatch::class, "Khiếu nại #{$claim->id} chỉ có 2 Đơn vị hàng được Hàng thay thế; Lô nhập này làm số hàng thay thế đã nhập thành 3.")
        ->and(StockUnit::where('content->username', 'x@shop.test')->exists())->toBeFalse();

    expect(fn () => $intake->submit($this->stocker, $replacement(cost: 1)))
        ->toThrow(InvalidBatch::class, 'Hàng thay thế từ Khiếu nại nhà cung cấp có Giá vốn 0 (Dòng nhập "Netflix 1 tháng").')
        ->and(fn () => $intake->submit($this->stocker, $replacement(supplier: $this->g2a)))
        ->toThrow(InvalidBatch::class, 'Lô nhập hàng thay thế phải cùng Nhà cung cấp với Khiếu nại.')
        ->and(fn () => $intake->submit($this->seller, $replacement()))->toThrow(MissingRole::class);

    $batch = $intake->confirm($this->stocker, $intake->submit($this->stocker, $replacement()));
    $units = StockUnit::whereIn('content->username', ['r@shop.test', 's@shop.test'])->orderBy('id')->get();

    expect($batch->fresh())->supplier_claim_id->toBe($claim->id)
        // Lô nhập vượt số được thay vẫn Chờ xác nhận, không có hàng vào kho.
        ->and($claim->batches()->pluck('id')->all())->toBe([$tooMany->id, $batch->id])
        ->and($tooMany->fresh()->status)->toBe(BatchStatus::Validated)
        ->and($units)->toHaveCount(2)
        ->and($units->pluck('unit_cost')->all())->toBe([0, 0])
        ->and(Slot::whereIn('stock_unit_id', $units->pluck('id'))->pluck('cost')->unique()->all())->toBe([0])
        ->and($batch->lines()->sole()->total_cost)->toBe(0)
        ->and(fn () => $intake->submit($this->stocker, $replacement()))
        ->toThrow(InvalidBatch::class, "Khiếu nại #{$claim->id} đã nhập đủ 2 Đơn vị hàng thay thế.");
});
