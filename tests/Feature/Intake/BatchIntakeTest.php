<?php

use App\Inventory\Access\MissingRole;
use App\Inventory\Access\Role;
use App\Inventory\Catalog\ContentFieldDraft;
use App\Inventory\Catalog\ContentFieldType;
use App\Inventory\Catalog\InvalidSupplier;
use App\Inventory\Catalog\ProductCatalog;
use App\Inventory\Catalog\ProductDraft;
use App\Inventory\Catalog\ProductHasStock;
use App\Inventory\Catalog\ProductType;
use App\Inventory\Catalog\SupplierDirectory;
use App\Inventory\Encryption\KeyFingerprintMismatch;
use App\Inventory\Encryption\KeyFingerprints;
use App\Inventory\Intake\BatchDraft;
use App\Inventory\Intake\BatchIntake;
use App\Inventory\Intake\BatchLineDraft;
use App\Inventory\Intake\BatchStatus;
use App\Inventory\Intake\InvalidBatch;
use App\Inventory\Intake\LineClass;
use App\Inventory\Intake\RejectedLine;
use App\Inventory\Stock\SlotStatus;
use App\Inventory\Stock\StockUnitStatus;
use App\Models\Batch;
use App\Models\Product;
use App\Models\StockLedgerEntry;
use App\Models\StockUnit;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    app(KeyFingerprints::class)->register();

    $this->intake = app(BatchIntake::class);
    $this->admin = staffMember(Role::QuanTri);
    $this->supplier = app(SupplierDirectory::class)->create($this->admin, 'Kinguin');
});

/**
 * Steam Wallet: một trường duy nhất, nhạy cảm, là Khoá chống trùng.
 */
function steamWallet(string $code = 'STEAM-100K'): Product
{
    return app(ProductCatalog::class)->create(staffMember(Role::QuanTri), new ProductDraft(
        type: ProductType::OneTimeCode,
        name: "Steam Wallet {$code}",
        code: $code,
        fields: [new ContentFieldDraft('code', 'Mã thẻ', dedupeKey: true)],
    ));
}

/**
 * Thẻ Garena: Serial không nhạy cảm, Mã thẻ 12 chữ số nhạy cảm là Khoá chống trùng,
 * Ghi chú tuỳ chọn nhạy cảm.
 */
function garenaCard(): Product
{
    return app(ProductCatalog::class)->create(staffMember(Role::QuanTri), new ProductDraft(
        type: ProductType::OneTimeCode,
        name: 'Thẻ Garena 100k',
        code: 'GARENA-100K',
        fields: [
            new ContentFieldDraft('serial', 'Serial', sensitive: false),
            new ContentFieldDraft('pin', 'Mã thẻ', ContentFieldType::Number, pattern: '\d{12}', dedupeKey: true),
            new ContentFieldDraft('note', 'Ghi chú', required: false),
        ],
    ));
}

function pasteBatch(Product $product, string $content, int $unitCost = 95_000, string $separator = "\t"): BatchDraft
{
    return new BatchDraft(
        supplier: test()->supplier,
        receivedOn: CarbonImmutable::parse('2026-09-15'),
        lines: [new BatchLineDraft($product, $unitCost, $content, $separator)],
        documentNumber: 'HD-0915',
        note: 'Tỷ giá 25.000',
    );
}

it('Nhập kho dán Mã dùng một lần, xem trước rồi xác nhận thì hàng vào kho', function () {
    $product = steamWallet();
    $clerk = staffMember(Role::NhapKho);

    $batch = $this->intake->submit($clerk, pasteBatch($product, "AAAA-BBBB-CCCC\n\nDDDD-EEEE-FFFF\n"));

    $preview = $this->intake->preview($this->admin, $batch);

    expect($preview->status)->toBe(BatchStatus::Validated)
        ->and($preview->lines)->toHaveCount(1)
        ->and($preview->lines[0])
        ->validCount->toBe(2)
        ->invalidCount->toBe(0)
        ->fileDuplicateCount->toBe(0)
        ->stockDuplicateCount->toBe(0)
        ->rejected->toBe([])
        ->totalCost->toBe(190_000)
        ->sample->toBe([['Mã thẻ' => '••••••'], ['Mã thẻ' => '••••••']]);

    expect(StockUnit::count())->toBe(0);

    $this->intake->confirm($clerk, $batch);

    $batch = Batch::findOrFail($batch->id);

    expect($batch)
        ->status->toBe(BatchStatus::Confirmed)
        ->supplier_id->toBe($this->supplier->id)
        ->document_number->toBe('HD-0915')
        ->note->toBe('Tỷ giá 25.000')
        ->and($batch->received_on->toDateString())->toBe('2026-09-15')
        ->and($batch->confirmed_by)->toBe($clerk->id)
        ->and($batch->rejectedInvalidCount())->toBe(0)
        ->and($batch->rejectedDuplicateCount())->toBe(0);

    $units = StockUnit::with('slots')->orderBy('id')->get();

    expect($units)->toHaveCount(2)
        ->and($units->pluck('status')->unique()->all())->toBe([StockUnitStatus::Active])
        ->and($units->pluck('unit_cost')->all())->toBe([95_000, 95_000])
        ->and($units->map(fn (StockUnit $unit) => $unit->slots->pluck('status')->all())->all())
        ->toBe([[SlotStatus::InStock], [SlotStatus::InStock]])
        ->and($units[0]->maskedContent())->toBe(['Mã thẻ' => '••••••'])
        ->and(Product::withCount('inStockSlots')->find($product->id)->in_stock_slots_count)->toBe(2)
        ->and($product->fresh()->hasStock())->toBeTrue();
});

it('xếp dòng sai kiểu, sai regex, thiếu trường bắt buộc hoặc thừa cột vào lỗi định dạng kèm lý do', function () {
    $batch = $this->intake->submit($this->admin, pasteBatch(garenaCard(), implode("\n", [
        "SR001\t123456789012",
        "SR002\t12345",
        "SR003\tabcdefghijkl",
        "\t123456789013",
        "SR005\t123456789014\tghi chú\tcột thừa",
        'SR006|123456789015',
        "SR007\t123456789016\tthẻ tặng",
    ])));

    $line = $this->intake->preview($this->admin, $batch)->lines[0];

    expect($line)
        ->validCount->toBe(2)
        ->invalidCount->toBe(5)
        ->fileDuplicateCount->toBe(0)
        ->stockDuplicateCount->toBe(0)
        ->totalCost->toBe(190_000)
        ->and($line->rejected)->toEqual([
            new RejectedLine(2, LineClass::Invalid, 'Trường "Mã thẻ" không khớp định dạng khai báo.'),
            new RejectedLine(3, LineClass::Invalid, 'Trường "Mã thẻ" không phải số.'),
            new RejectedLine(4, LineClass::Invalid, 'Thiếu giá trị cho trường "Serial".'),
            new RejectedLine(5, LineClass::Invalid, 'Dòng có 4 cột, Sản phẩm chỉ có 3 Trường nội dung.'),
            new RejectedLine(6, LineClass::Invalid, 'Thiếu giá trị cho trường "Mã thẻ".'),
        ]);
});

it('chọn được ký tự phân tách; mẫu hợp lệ che trường nhạy cảm và hiện trường không nhạy cảm', function () {
    $batch = $this->intake->submit($this->admin, pasteBatch(garenaCard(), "SR001 | 123456789012 | thẻ tặng\nSR002|123456789013", separator: '|'));

    expect($this->intake->preview($this->admin, $batch)->lines[0])
        ->validCount->toBe(2)
        ->sample->toBe([
            ['Serial' => 'SR001', 'Mã thẻ' => '••••••', 'Ghi chú' => '••••••'],
            ['Serial' => 'SR002', 'Mã thẻ' => '••••••', 'Ghi chú' => '••••••'],
        ]);

    $this->intake->confirm($this->admin, $batch);

    expect(StockUnit::orderBy('id')->get()->map->maskedContent()->all())->toBe([
        ['Serial' => 'SR001', 'Mã thẻ' => '••••••', 'Ghi chú' => '••••••'],
        ['Serial' => 'SR002', 'Mã thẻ' => '••••••', 'Ghi chú' => '••••••'],
    ]);
});

it('nội dung nhạy cảm không bao giờ nằm dạng rõ trong DB, kể cả khi đang chờ xác nhận', function () {
    $product = garenaCard();
    $dump = fn (): string => collect(['batches', 'batch_lines', 'stock_units', 'slots', 'stock_ledger_entries', 'jobs'])
        ->map(fn (string $table) => DB::table($table)->get()->toJson(JSON_UNESCAPED_UNICODE))
        ->implode("\n");

    $batch = $this->intake->submit($this->admin, pasteBatch($product, "SR001\t123456789012\tmật khẩu ví"));

    expect($dump())->not->toContain('123456789012')->not->toContain('mật khẩu ví');

    $this->intake->confirm($this->admin, $batch);

    expect($dump())->not->toContain('123456789012')->not->toContain('mật khẩu ví')
        ->and($dump())->toContain('SR001');
});

it('xếp dòng trùng Khoá chống trùng sau chuẩn hoá vào trùng trong file, giữ dòng đầu', function () {
    $batch = $this->intake->submit($this->admin, pasteBatch(steamWallet(), "abcd-efgh-ijkl\nMNOP-QRST\nABCD EFGH IJKL\n  abcdefghijkl  "));

    $line = $this->intake->preview($this->admin, $batch)->lines[0];

    expect($line)
        ->validCount->toBe(2)
        ->fileDuplicateCount->toBe(2)
        ->totalCost->toBe(190_000)
        ->and($line->rejected)->toEqual([
            new RejectedLine(3, LineClass::FileDuplicate, 'Trùng Khoá chống trùng với dòng 1.'),
            new RejectedLine(4, LineClass::FileDuplicate, 'Trùng Khoá chống trùng với dòng 1.'),
        ]);
});

it('xếp Mã dùng một lần đã có trong kho vào trùng trong kho, kể cả khi thuộc Sản phẩm khác', function () {
    $first = $this->intake->submit($this->admin, pasteBatch(steamWallet(), 'AAAA-BBBB-CCCC'));
    $this->intake->confirm($this->admin, $first);

    $batch = $this->intake->submit($this->admin, pasteBatch(steamWallet('STEAM-200K'), "aaaabbbbcccc\nDDDD-EEEE-FFFF"));

    $line = $this->intake->preview($this->admin, $batch)->lines[0];

    expect($line)
        ->validCount->toBe(1)
        ->stockDuplicateCount->toBe(1)
        ->and($line->rejected)->toEqual([
            new RejectedLine(1, LineClass::StockDuplicate, 'Khoá chống trùng đã có trong kho.'),
        ]);
});

it('xác nhận chỉ ghi phần hợp lệ và Lô nhập lưu số dòng lỗi, số dòng trùng', function () {
    $product = steamWallet();
    $this->intake->confirm($this->admin, $this->intake->submit($this->admin, pasteBatch($product, 'EXISTING-01')));

    $batch = $this->intake->submit($this->admin, pasteBatch($product, implode("\n", [
        'NEW-0001',
        '   ',
        "NEW\x80BROKEN",
        'new 0001',
        'existing01',
        'NEW-0002',
    ]), unitCost: 50_000));

    $this->intake->confirm($this->admin, $batch);

    $batch = Batch::findOrFail($batch->id);
    $preview = $this->intake->preview($this->admin, $batch);

    expect($batch)
        ->status->toBe(BatchStatus::Confirmed)
        ->and($batch->rejectedInvalidCount())->toBe(1)
        ->and($batch->rejectedDuplicateCount())->toBe(2)
        ->and($preview->lines[0])
        ->validCount->toBe(2)
        ->totalCost->toBe(100_000)
        ->and($preview->lines[0]->rejected)->toEqual([
            new RejectedLine(3, LineClass::Invalid, 'Dòng không phải văn bản UTF-8 hợp lệ.'),
            new RejectedLine(4, LineClass::FileDuplicate, 'Trùng Khoá chống trùng với dòng 1.'),
            new RejectedLine(5, LineClass::StockDuplicate, 'Khoá chống trùng đã có trong kho.'),
        ])
        ->and(StockUnit::where('batch_line_id', $batch->lines[0]->id)->count())->toBe(2)
        ->and(Product::withCount('inStockSlots')->find($product->id)->in_stock_slots_count)->toBe(3);
});

it('kiểm tra trùng lại lúc ghi: Lô nhập khác đã nhập cùng mã sau bước xem trước', function () {
    $product = steamWallet();
    $earlier = $this->intake->submit($this->admin, pasteBatch($product, "SHARED-CODE\nONLY-EARLIER"));
    $later = $this->intake->submit($this->admin, pasteBatch(steamWallet('STEAM-200K'), 'shared code'));

    expect($this->intake->preview($this->admin, $earlier)->lines[0]->validCount)->toBe(2);

    $this->intake->confirm($this->admin, $later);
    $this->intake->confirm($this->admin, $earlier);

    expect($this->intake->preview($this->admin, $earlier)->lines[0])
        ->validCount->toBe(1)
        ->stockDuplicateCount->toBe(1)
        ->and(Batch::findOrFail($earlier->id)->rejectedDuplicateCount())->toBe(1)
        ->and(StockUnit::count())->toBe(2);
});

it('DB chặn hai Đơn vị hàng Mã dùng một lần cùng Khoá chống trùng', function () {
    $this->intake->confirm($this->admin, $this->intake->submit($this->admin, pasteBatch(steamWallet(), 'AAAA-BBBB')));
    $unit = StockUnit::sole();

    expect(fn () => DB::transaction(fn () => DB::table('stock_units')->insert([
        ...collect($unit->getAttributes())->except(['id', 'content'])->all(),
        'batch_line_id' => $unit->batch_line_id,
    ])))->toThrow(QueryException::class, 'stock_units_one_time_code_dedupe');
});

it('mỗi Đơn vị hàng và Slot vừa tạo ghi đúng một dòng Sổ biến động kho', function () {
    $clerk = staffMember(Role::NhapKho);
    $batch = $this->intake->submit($clerk, pasteBatch(steamWallet(), "AAAA-BBBB\nCCCC-DDDD"));
    $this->intake->confirm($clerk, $batch);

    $rows = StockLedgerEntry::orderBy('id')->get();
    $units = StockUnit::with('slots')->orderBy('id')->get();

    expect($rows)->toHaveCount(4)
        ->and($rows->pluck('actor_id')->unique()->all())->toBe([$clerk->id])
        ->and($rows->pluck('reason')->unique()->all())->toBe(["Nhập hàng theo Lô nhập #{$batch->id}"])
        ->and($rows->map(fn (StockLedgerEntry $row) => [$row->stock_unit_id, $row->slot_id, $row->from_status, $row->to_status])->all())
        ->toEqualCanonicalizing([
            [$units[0]->id, null, null, 'active'],
            [$units[1]->id, null, null, 'active'],
            [$units[0]->id, $units[0]->slots[0]->id, null, 'in-stock'],
            [$units[1]->id, $units[1]->slots[0]->id, null, 'in-stock'],
        ]);

    expect(fn () => DB::transaction(fn () => DB::statement("UPDATE stock_ledger_entries SET reason = 'sửa'")))
        ->toThrow(QueryException::class, 'chỉ-ghi-thêm');
});

it('Bán hàng không tạo, không xem trước, không xác nhận được Lô nhập', function () {
    $product = steamWallet();
    $seller = staffMember(Role::BanHang);

    expect(fn () => $this->intake->submit($seller, pasteBatch($product, 'AAAA')))->toThrow(MissingRole::class);

    $batch = $this->intake->submit($this->admin, pasteBatch($product, 'AAAA'));

    expect(fn () => $this->intake->preview($seller, $batch))->toThrow(MissingRole::class)
        ->and(fn () => $this->intake->confirm($seller, $batch))->toThrow(MissingRole::class)
        ->and(Batch::count())->toBe(1)
        ->and(StockUnit::count())->toBe(0);
});

it('từ chối ghi khi khoá mã hoá không khớp dấu vân tay', function () {
    $product = steamWallet();
    $batch = $this->intake->submit($this->admin, pasteBatch($product, 'AAAA'));
    config(['inventory.keys.hmac' => '1:base64:+aDuV69xpMmq8HrVKo6jtB43YKS+Sd8UDwlDy4k5kgk=']);

    expect(fn () => $this->intake->submit($this->admin, pasteBatch($product, 'BBBB')))->toThrow(KeyFingerprintMismatch::class)
        ->and(fn () => $this->intake->confirm($this->admin, $batch))->toThrow(KeyFingerprintMismatch::class)
        ->and(Batch::count())->toBe(1)
        ->and(StockUnit::count())->toBe(0);
});

it('Lô nhập đã xác nhận thì đóng', function () {
    $batch = $this->intake->submit($this->admin, pasteBatch(steamWallet(), 'AAAA'));
    $this->intake->confirm($this->admin, $batch);

    expect(fn () => $this->intake->confirm($this->admin, $batch))
        ->toThrow(InvalidBatch::class, 'Lô nhập đã xác nhận và đã đóng.')
        ->and(StockUnit::count())->toBe(1);
});

it('từ chối Lô nhập không hợp lệ', function (Closure $draft, string $message) {
    expect(fn () => $this->intake->submit($this->admin, $draft()))->toThrow(InvalidBatch::class, $message)
        ->and(Batch::count())->toBe(0);
})->with([
    'không có Dòng nhập' => [fn () => new BatchDraft(test()->supplier, CarbonImmutable::today(), []), 'Lô nhập phải có ít nhất một Dòng nhập.'],
    'Giá vốn âm' => [fn () => pasteBatch(steamWallet(), 'AAAA', unitCost: -1), 'Giá vốn của Dòng nhập "Steam Wallet STEAM-100K" không được âm.'],
    'chưa có nội dung' => [fn () => pasteBatch(steamWallet(), " \n\t\n"), 'Dòng nhập "Steam Wallet STEAM-100K" chưa có nội dung.'],
    'Sản phẩm Tài khoản' => [fn () => pasteBatch(app(ProductCatalog::class)->create(test()->admin, new ProductDraft(
        type: ProductType::Account,
        name: 'Netflix 1 tháng',
        code: 'NETFLIX-1M',
        fields: [new ContentFieldDraft('username', 'Tên đăng nhập', dedupeKey: true), new ContentFieldDraft('password', 'Mật khẩu')],
    )), "a@b.c\tpw"), 'Chưa hỗ trợ nhập Tài khoản (Sản phẩm "Netflix 1 tháng").'],
]);

it('Nhà cung cấp và Sản phẩm đã có Lô nhập, kể cả chưa xác nhận, không xoá được', function () {
    $product = steamWallet();
    $this->intake->submit($this->admin, pasteBatch($product, 'AAAA'));

    expect(fn () => app(SupplierDirectory::class)->delete($this->admin, $this->supplier))
        ->toThrow(InvalidSupplier::class, 'Nhà cung cấp "Kinguin" đã có Lô nhập, không xoá được.')
        ->and(fn () => app(ProductCatalog::class)->delete($this->admin, $product))
        ->toThrow(ProductHasStock::class)
        ->and($this->supplier->fresh())->not->toBeNull()
        ->and($product->fresh())->not->toBeNull();
});
