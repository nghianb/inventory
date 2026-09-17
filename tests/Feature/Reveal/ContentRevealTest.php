<?php

use App\Inventory\Access\MissingRole;
use App\Inventory\Access\Role;
use App\Inventory\Catalog\ContentFieldDraft;
use App\Inventory\Catalog\ContentFieldType;
use App\Inventory\Catalog\StockForm;
use App\Inventory\Catalog\SupplierDirectory;
use App\Inventory\Encryption\KeyFingerprints;
use App\Inventory\Intake\BatchDraft;
use App\Inventory\Intake\BatchIntake;
use App\Inventory\Intake\BatchLineDraft;
use App\Inventory\Reveal\ContentReveal;
use App\Inventory\Reveal\InvalidReveal;
use App\Inventory\Reveal\RevealActor;
use App\Inventory\Reveal\RevealContext;
use App\Inventory\Reveal\RevealContextType;
use App\Inventory\Security\AppendOnlyViolation;
use App\Inventory\Stock\SlotStatus;
use App\Models\RevealLogEntry;
use App\Models\Slot;
use App\Models\StockUnit;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('intake');
    $this->seed(RoleSeeder::class);
    app(KeyFingerprints::class)->register();

    $this->reveal = app(ContentReveal::class);
    $this->admin = staffMember(Role::Owner);

    $product = productOf(
        StockForm::OneTimeCode,
        [
            new ContentFieldDraft('serial', 'Serial', sensitive: false),
            new ContentFieldDraft('pin', 'Mã thẻ', ContentFieldType::Number, pattern: '\d{12}', dedupeKey: true),
            new ContentFieldDraft('note', 'Ghi chú', required: false),
        ],
        'Thẻ Garena 100k',
        'GARENA-100K',
    );

    $intake = app(BatchIntake::class);
    $batch = $intake->submit($this->admin, new BatchDraft(
        supplier: app(SupplierDirectory::class)->create($this->admin, 'Kinguin'),
        receivedOn: CarbonImmutable::parse('2026-09-15'),
        lines: [new BatchLineDraft($product, 95_000, "SR001\t123456789012\tví cũ")],
    ));
    $intake->confirm($this->admin, $batch);

    $this->slot = Slot::sole();
});

it('Quản trị xem nội dung Slot Còn hàng kèm lý do: trả nội dung đầy đủ và ghi đúng một dòng Nhật ký xem mã', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-15 10:00:00', 'Asia/Ho_Chi_Minh'));

    $content = $this->reveal->reveal(RevealActor::staff($this->admin), $this->slot, RevealContext::inStock(), '  Khách hỏi lại mã  ');

    expect($content->fields)->toBe(['Serial' => 'SR001', 'Mã thẻ' => '123456789012', 'Ghi chú' => 'ví cũ']);

    $entry = RevealLogEntry::sole();

    expect($entry)
        ->user_id->toBe($this->admin->id)
        ->api_key_id->toBeNull()
        ->slot_id->toBe($this->slot->id)
        ->stock_unit_id->toBe($this->slot->stock_unit_id)
        ->context->toBe(RevealContextType::InStock)
        ->context_id->toBeNull()
        ->reason->toBe('Khách hỏi lại mã')
        ->and($entry->occurred_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-09-15 03:00:00')
        ->and(json_encode(DB::table('reveal_log_entries')->get()))->not->toContain('123456789012');
});

it('mỗi lần xem ghi thêm một dòng Nhật ký xem mã', function () {
    $this->reveal->reveal(RevealActor::staff($this->admin), $this->slot, RevealContext::inStock(), 'Lần 1');
    $this->reveal->reveal(RevealActor::staff($this->admin), $this->slot, RevealContext::inStock(), 'Lần 2');

    expect(RevealLogEntry::orderBy('id')->pluck('reason')->all())->toBe(['Lần 1', 'Lần 2']);
});

it('Quản trị xem hàng Còn hàng bắt buộc nhập lý do', function (?string $reason) {
    expect(fn () => $this->reveal->reveal(RevealActor::staff($this->admin), $this->slot, RevealContext::inStock(), $reason))
        ->toThrow(InvalidReveal::class, 'Xem nội dung hàng Còn hàng phải nhập lý do.')
        ->and(RevealLogEntry::count())->toBe(0);
})->with([
    'không có' => [null],
    'để trống' => ['   '],
]);

it('vai trò khác Quản trị không xem được hàng Còn hàng và không để lại nội dung', function (Role $role) {
    expect(fn () => $this->reveal->reveal(RevealActor::staff(staffMember($role)), $this->slot, RevealContext::inStock(), 'Tò mò'))
        ->toThrow(MissingRole::class)
        ->and(RevealLogEntry::count())->toBe(0);
})->with([
    'Nhập kho' => [Role::NhapKho],
    'Bán hàng' => [Role::BanHang],
]);

it('Quản trị đã bị Khoá nhân viên không xem được', function () {
    $this->admin->forceFill(['deactivated_at' => now()])->save();

    expect(fn () => $this->reveal->reveal(RevealActor::staff($this->admin), $this->slot, RevealContext::inStock(), 'Kiểm tra'))
        ->toThrow(MissingRole::class)
        ->and(RevealLogEntry::count())->toBe(0);
});

it('Khoá API không xem được hàng Còn hàng', function () {
    expect(fn () => $this->reveal->reveal(RevealActor::apiKey(7), $this->slot, RevealContext::inStock(), 'Website'))
        ->toThrow(InvalidReveal::class, 'Chỉ Quản trị xem được nội dung hàng Còn hàng.')
        ->and(RevealLogEntry::count())->toBe(0);
});

it('Slot không còn Còn hàng thì không xem được theo lý do tự do', function (SlotStatus $status) {
    DB::table('slots')->where('id', $this->slot->id)->update(['status' => $status->value]);

    expect(fn () => $this->reveal->reveal(RevealActor::staff($this->admin), $this->slot, RevealContext::inStock(), 'Kiểm tra'))
        ->toThrow(InvalidReveal::class, 'Slot không còn Còn hàng; nội dung chỉ xem được qua Ngữ cảnh xem mã.')
        ->and(RevealLogEntry::count())->toBe(0);
})->with([
    'Đã giữ' => [SlotStatus::Reserved],
    'Đã giao' => [SlotStatus::Delivered],
    'Đã huỷ' => [SlotStatus::Voided],
]);

it('Nhật ký xem mã ghi trong cùng transaction với việc giải mã: giải mã lỗi thì không có dòng nào và không trả nội dung', function () {
    DB::table('stock_units')->update(['secret_ciphertext' => 'hỏng']);

    expect(fn () => $this->reveal->reveal(RevealActor::staff($this->admin), $this->slot, RevealContext::inStock(), 'Kiểm tra'))
        ->toThrow(Exception::class)
        ->and(RevealLogEntry::count())->toBe(0);
});

it('Đơn vị hàng không có trường nhạy cảm vẫn xem được', function () {
    DB::table('stock_units')->update(['secret_ciphertext' => null, 'secret_key_version' => null]);

    expect($this->reveal->reveal(RevealActor::staff($this->admin), $this->slot, RevealContext::inStock(), 'Kiểm tra')->fields)
        ->toBe(['Serial' => 'SR001', 'Mã thẻ' => '', 'Ghi chú' => '']);
});

it('ứng dụng không sửa hay xoá được dòng Nhật ký xem mã', function (Closure $change) {
    $this->reveal->reveal(RevealActor::staff($this->admin), $this->slot, RevealContext::inStock(), 'Kiểm tra');

    $change(RevealLogEntry::sole());
})->with([
    'sửa' => [fn (RevealLogEntry $entry) => $entry->forceFill(['reason' => 'khác'])->save()],
    'xoá' => [fn (RevealLogEntry $entry) => $entry->delete()],
])->throws(AppendOnlyViolation::class);

it('PostgreSQL chặn sửa, xoá và truncate Nhật ký xem mã', function (string $sql) {
    $this->reveal->reveal(RevealActor::staff($this->admin), $this->slot, RevealContext::inStock(), 'Kiểm tra');

    expect(fn () => DB::transaction(fn () => DB::statement($sql)))
        ->toThrow(QueryException::class, 'chỉ-ghi-thêm');
})->with([
    'update' => "UPDATE reveal_log_entries SET reason = 'khác'",
    'delete' => 'DELETE FROM reveal_log_entries',
    'truncate' => 'TRUNCATE reveal_log_entries',
]);

it('DB bắt buộc mỗi dòng Nhật ký xem mã có đúng một tác nhân', function (?int $userId, ?int $apiKeyId) {
    expect(fn () => DB::transaction(fn () => DB::table('reveal_log_entries')->insert([
        'user_id' => $userId === 0 ? $this->admin->id : $userId,
        'api_key_id' => $apiKeyId,
        'context' => RevealContextType::InStock->value,
        'reason' => 'x',
    ])))->toThrow(QueryException::class);
})->with([
    'không có tác nhân' => [null, null],
    'cả nhân viên và Khoá API' => [0, 7],
]);

it('Nhật ký xem mã theo Đơn vị hàng', function () {
    $this->reveal->reveal(RevealActor::staff($this->admin), $this->slot, RevealContext::inStock(), 'Kiểm tra');

    expect(StockUnit::sole()->revealLogEntries()->pluck('reason')->all())->toBe(['Kiểm tra']);
});
