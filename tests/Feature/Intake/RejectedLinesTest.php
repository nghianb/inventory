<?php

use App\Inventory\Access\MissingRole;
use App\Inventory\Access\Role;
use App\Inventory\Catalog\ContentFieldDraft;
use App\Inventory\Catalog\ContentFieldType;
use App\Inventory\Catalog\ProductCatalog;
use App\Inventory\Catalog\ProductDraft;
use App\Inventory\Catalog\ProductType;
use App\Inventory\Catalog\SupplierDirectory;
use App\Inventory\Encryption\KeyFingerprints;
use App\Inventory\Intake\BatchDraft;
use App\Inventory\Intake\BatchIntake;
use App\Inventory\Intake\BatchLineDraft;
use App\Inventory\Intake\InvalidBatch;
use App\Inventory\Intake\RejectedLinesExport;
use App\Inventory\Reveal\RevealContextType;
use App\Models\Batch;
use App\Models\BatchLine;
use App\Models\RevealLogEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('intake');
    $this->seed(RoleSeeder::class);
    app(KeyFingerprints::class)->register();

    $this->intake = app(BatchIntake::class);
    $this->admin = staffMember(Role::QuanTri);
    $this->clerk = staffMember(Role::NhapKho);
    $this->supplier = app(SupplierDirectory::class)->create($this->admin, 'Kinguin');
    $this->product = app(ProductCatalog::class)->create($this->admin, new ProductDraft(
        type: ProductType::OneTimeCode,
        name: 'Thẻ Garena 100k',
        code: 'GARENA-100K',
        fields: [
            new ContentFieldDraft('serial', 'Serial', sensitive: false),
            new ContentFieldDraft('pin', 'Mã thẻ', ContentFieldType::Number, pattern: '\d{12}', dedupeKey: true),
            new ContentFieldDraft('note', 'Ghi chú', required: false),
        ],
    ));
});

function garenaBatchBy(User $actor, BatchLineDraft $line): Batch
{
    return app(BatchIntake::class)->submit($actor, new BatchDraft(
        supplier: test()->supplier,
        receivedOn: CarbonImmutable::parse('2026-09-15'),
        lines: [$line],
    ));
}

/**
 * Các dòng của CSV tải về, bỏ BOM; mỗi dòng là danh sách ô.
 *
 * @return list<list<string>>
 */
function csvRowsOf(RejectedLinesExport $export): array
{
    expect($export->csv)->toStartWith("\xEF\xBB\xBF");

    $handle = fopen('php://memory', 'r+');
    fwrite($handle, substr($export->csv, 3));
    rewind($handle);
    $rows = [];

    while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
        $rows[] = $row;
    }

    fclose($handle);

    return $rows;
}

/**
 * Bỏ cột Lý do (câu chữ do bộ phân loại quyết định) để so phần còn lại.
 *
 * @param  list<list<string>>  $rows
 * @return list<list<string>>
 */
function withoutReason(array $rows): array
{
    return array_map(fn (array $row): array => [$row[0], $row[1], ...array_slice($row, 3)], $rows);
}

it('người tạo Lô nhập tải CSV dòng bị bỏ ở màn xem trước; mỗi lần tải ghi một dòng Nhật ký xem mã ngữ cảnh Lô nhập', function () {
    $batch = garenaBatchBy($this->clerk, new BatchLineDraft($this->product, 50_000, "SR001\t123456789012\nSR002\tabc\nSR003\t123456789012\n\nSR004\t111111111111\tghi chú\tthừa"));
    $line = BatchLine::sole();

    $export = $this->intake->rejectedLines($this->clerk, $line);
    $rows = csvRowsOf($export);

    expect($export->fileName)->toBe("lo-nhap-{$batch->id}-GARENA-100K-dong-bi-bo.csv")
        ->and(withoutReason($rows))->toBe([
            ['Dòng', 'Loại', 'Nội dung'],
            ['2', 'Lỗi định dạng', "SR002\tabc"],
            ['3', 'Trùng trong file', "SR003\t123456789012"],
            ['5', 'Lỗi định dạng', "SR004\t111111111111\tghi chú\tthừa"],
        ])
        ->and($rows[0][2])->toBe('Lý do')
        ->and($rows[3][2])->toBe('Dòng có 4 cột, Sản phẩm chỉ có 3 Trường nội dung.');

    $entry = RevealLogEntry::sole();

    expect($entry)
        ->user_id->toBe($this->clerk->id)
        ->api_key_id->toBeNull()
        ->slot_id->toBeNull()
        ->stock_unit_id->toBeNull()
        ->context->toBe(RevealContextType::Batch)
        ->context_id->toBe($batch->id)
        ->reason->toBe("Tải dòng bị bỏ của Dòng nhập #{$line->id} \"Thẻ Garena 100k\"");

    $this->intake->rejectedLines($this->clerk, $line);

    expect(RevealLogEntry::count())->toBe(2);
});

it('file CSV: giữ dòng tiêu đề gốc và các ô gốc của dòng bị bỏ', function () {
    garenaBatchBy($this->clerk, BatchLineDraft::file($this->product, 50_000, "Serial,Mã thẻ,Cột thừa\nSR001,123456789012,x\nSR002,abc,\"có, dấu phẩy\"\n", 'garena.csv'));

    expect(withoutReason(csvRowsOf($this->intake->rejectedLines($this->clerk, BatchLine::sole()))))->toBe([
        ['Dòng', 'Loại', 'Serial', 'Mã thẻ', 'Cột thừa'],
        ['3', 'Lỗi định dạng', 'SR002', 'abc', 'có, dấu phẩy'],
    ]);
});

it('ngay sau xác nhận vẫn tải được, gồm cả dòng thành trùng trong kho lúc ghi; quá thời hạn thì không tải được và lệnh dọn xoá', function () {
    $batch = garenaBatchBy($this->clerk, new BatchLineDraft($this->product, 50_000, "SR001\t123456789012\nSR002\tabc"));
    $this->intake->confirm($this->admin, garenaBatchBy($this->admin, new BatchLineDraft($this->product, 50_000, "SR009\t123456789012")));
    $this->intake->confirm($this->clerk, $batch, skipStockDuplicates: true);

    $disk = Storage::disk('intake');

    expect($disk->allFiles())->toHaveCount(1)
        ->and($disk->get($disk->allFiles()[0]))->not->toContain('SR002')->not->toContain('123456789012');

    expect(withoutReason(csvRowsOf($this->intake->rejectedLines($this->clerk, BatchLine::where('batch_id', $batch->id)->sole()))))->toBe([
        ['Dòng', 'Loại', 'Nội dung'],
        ['1', 'Trùng trong kho', "SR001\t123456789012"],
        ['2', 'Lỗi định dạng', "SR002\tabc"],
    ]);

    $this->artisan('inventory:intake:purge')->assertSuccessful();

    expect($disk->allFiles())->toHaveCount(1);

    $this->travel(31)->minutes();

    expect(fn () => $this->intake->rejectedLines($this->clerk, BatchLine::where('batch_id', $batch->id)->sole()))
        ->toThrow(InvalidBatch::class, 'Dòng bị bỏ chỉ tải được ở màn xem trước hoặc ngay sau khi xác nhận (trong 30 phút).')
        ->and(RevealLogEntry::count())->toBe(1);

    $this->artisan('inventory:intake:purge')->assertSuccessful();

    expect($disk->allFiles())->toBe([]);
});

it('chỉ người tạo Lô nhập tải được; người khác bị từ chối và không có dòng nhật ký', function () {
    garenaBatchBy($this->clerk, new BatchLineDraft($this->product, 50_000, "SR002\tabc"));
    $line = BatchLine::sole();

    expect(fn () => $this->intake->rejectedLines(staffMember(Role::NhapKho), $line))
        ->toThrow(InvalidBatch::class, 'Chỉ người tạo Lô nhập tải được dòng bị bỏ.')
        ->and(fn () => $this->intake->rejectedLines($this->admin, $line))
        ->toThrow(InvalidBatch::class, 'Chỉ người tạo Lô nhập tải được dòng bị bỏ.')
        ->and(fn () => $this->intake->rejectedLines(staffMember(Role::BanHang), $line))
        ->toThrow(MissingRole::class);

    $this->clerk->removeRole(Role::NhapKho);

    expect(fn () => $this->intake->rejectedLines($this->clerk->fresh(), $line))
        ->toThrow(MissingRole::class)
        ->and(RevealLogEntry::count())->toBe(0);
});

it('Lô nhập đã bỏ hoặc quá hạn xác nhận thì không tải được', function (Closure $end) {
    $batch = garenaBatchBy($this->clerk, new BatchLineDraft($this->product, 50_000, "SR002\tabc"));
    $end($batch);

    expect(fn () => $this->intake->rejectedLines($this->clerk, BatchLine::sole()))
        ->toThrow(InvalidBatch::class, 'Dòng bị bỏ chỉ tải được ở màn xem trước hoặc ngay sau khi xác nhận (trong 30 phút).')
        ->and(RevealLogEntry::count())->toBe(0);
})->with([
    'đã bỏ' => [fn (Batch $batch) => app(BatchIntake::class)->discard(test()->clerk, $batch)],
    'quá hạn xác nhận' => [function () {
        test()->travel(25)->hours();
        app(BatchIntake::class)->purgeExpired();
    }],
]);

it('Dòng nhập không có dòng bị bỏ thì không có gì để tải', function () {
    garenaBatchBy($this->clerk, new BatchLineDraft($this->product, 50_000, "SR001\t123456789012"));

    expect(fn () => $this->intake->rejectedLines($this->clerk, BatchLine::sole()))
        ->toThrow(InvalidBatch::class, 'Dòng nhập không có dòng bị bỏ.')
        ->and(RevealLogEntry::count())->toBe(0);
});
