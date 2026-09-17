<?php

use App\Inventory\Access\MissingRole;
use App\Inventory\Access\Role;
use App\Inventory\Catalog\ContentFieldDraft;
use App\Inventory\Catalog\ContentFieldType;
use App\Inventory\Catalog\InvalidSupplier;
use App\Inventory\Catalog\ProductCatalog;
use App\Inventory\Catalog\ProductHasStock;
use App\Inventory\Catalog\StockForm;
use App\Inventory\Catalog\SupplierDirectory;
use App\Inventory\Encryption\KeyFingerprintMismatch;
use App\Inventory\Encryption\KeyFingerprints;
use App\Inventory\Intake\BatchDraft;
use App\Inventory\Intake\BatchIntake;
use App\Inventory\Intake\BatchLineDraft;
use App\Inventory\Intake\BatchStatus;
use App\Inventory\Intake\ExpiryRule;
use App\Inventory\Intake\IntakeSource;
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
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

beforeEach(function () {
    Storage::fake('intake');
    $this->seed(RoleSeeder::class);
    app(KeyFingerprints::class)->register();

    $this->intake = app(BatchIntake::class);
    $this->admin = staffMember(Role::Owner);
    $this->supplier = app(SupplierDirectory::class)->create($this->admin, 'Kinguin');
});

/**
 * Steam Wallet: một trường duy nhất, nhạy cảm, là Khoá chống trùng.
 */
function steamWallet(string $code = 'STEAM-100K'): Product
{
    return productOf(
        StockForm::OneTimeCode,
        [new ContentFieldDraft('code', 'Mã thẻ', dedupeKey: true)],
        "Steam Wallet {$code}",
        $code,
    );
}

/**
 * Thẻ Garena: Serial không nhạy cảm, Mã thẻ 12 chữ số nhạy cảm là Khoá chống trùng,
 * Ghi chú tuỳ chọn nhạy cảm.
 */
function garenaCard(): Product
{
    return productOf(
        StockForm::OneTimeCode,
        [
            new ContentFieldDraft('serial', 'Serial', sensitive: false),
            new ContentFieldDraft('pin', 'Mã thẻ', ContentFieldType::Number, pattern: '\d{12}', dedupeKey: true),
            new ContentFieldDraft('note', 'Ghi chú', required: false),
        ],
        'Thẻ Garena 100k',
        'GARENA-100K',
    );
}

/**
 * Tài khoản: username (email) là Khoá chống trùng, password nhạy cảm.
 */
function streamingAccount(string $code = 'NETFLIX-1M', int $defaultSlots = 4): Product
{
    return productOf(
        StockForm::Account,
        [
            new ContentFieldDraft('username', 'Tên đăng nhập', ContentFieldType::Email, sensitive: false, dedupeKey: true),
            new ContentFieldDraft('password', 'Mật khẩu'),
        ],
        "Tài khoản {$code}",
        $code,
        defaultSlots: $defaultSlots,
    );
}

/**
 * @param  list<BatchLineDraft>  $lines
 */
function batchOf(array $lines, string $receivedOn = '2026-09-15', ?int $invoiceTotal = null, ?Batch $supplements = null): BatchDraft
{
    return new BatchDraft(
        supplier: test()->supplier,
        receivedOn: CarbonImmutable::parse($receivedOn),
        lines: $lines,
        invoiceTotal: $invoiceTotal,
        supplements: $supplements,
    );
}

/**
 * @param  list<list<mixed>>  $rows
 */
function xlsxFile(array $rows): string
{
    $path = (string) tempnam(sys_get_temp_dir(), 'xlsx-test');
    $writer = new Writer;
    $writer->openToFile($path);

    // Ô ngày có định dạng ngày như file thật; thiếu định dạng thì XLSX chỉ lưu số serial.
    $dateStyle = (new Style)->setFormat('dd/mm/yyyy');

    foreach ($rows as $row) {
        $writer->addRow(new Row(array_map(
            fn (mixed $value) => Cell::fromValue($value, $value instanceof DateTimeInterface ? $dateStyle : null),
            $row,
        )));
    }

    $writer->close();

    return tap((string) file_get_contents($path), fn () => unlink($path));
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

it('một Lô nhập nhiều Dòng nhập; Tài khoản tạo đủ số Slot theo Sản phẩm hoặc Dòng nhập, Giá vốn Slot chia đều', function () {
    $netflix = streamingAccount();
    $spotify = streamingAccount('SPOTIFY-1M', defaultSlots: 1);
    $steam = steamWallet();

    $batch = $this->intake->submit($this->admin, batchOf([
        new BatchLineDraft($netflix, 200_000, "a@shop.test\tpw1\nb@shop.test\tpw2"),
        new BatchLineDraft($spotify, 100_000, "c@shop.test\tpw3", slots: 3),
        new BatchLineDraft($steam, 95_000, 'AAAA-BBBB'),
    ]));

    $preview = $this->intake->preview($this->admin, $batch);

    expect($preview->lines)->toHaveCount(3)
        ->and(array_map(fn ($line) => [$line->productName, $line->validCount, $line->totalCost], $preview->lines))->toBe([
            ['Tài khoản NETFLIX-1M', 2, 400_000],
            ['Tài khoản SPOTIFY-1M', 1, 100_000],
            ['Steam Wallet STEAM-100K', 1, 95_000],
        ])
        ->and($preview->lines[0]->sample)->toBe([
            ['Tên đăng nhập' => 'a@shop.test', 'Mật khẩu' => '••••••', 'Số slot' => '4'],
            ['Tên đăng nhập' => 'b@shop.test', 'Mật khẩu' => '••••••', 'Số slot' => '4'],
        ])
        ->and($preview->totalCost())->toBe(595_000);

    $this->intake->confirm($this->admin, $batch);

    $units = StockUnit::with('slots')->orderBy('id')->get();

    expect($units->map(fn (StockUnit $unit) => [$unit->kind, $unit->unit_cost, $unit->slots->pluck('cost')->all()])->all())->toBe([
        [StockForm::Account, 200_000, [50_000, 50_000, 50_000, 50_000]],
        [StockForm::Account, 200_000, [50_000, 50_000, 50_000, 50_000]],
        [StockForm::Account, 100_000, [33_334, 33_333, 33_333]],
        [StockForm::OneTimeCode, 95_000, [95_000]],
    ])
        ->and(Product::withCount('inStockSlots')->find($netflix->id)->in_stock_slots_count)->toBe(8)
        ->and(StockLedgerEntry::count())->toBe(4 + 8 + 3 + 1);
});

it('Hạn sử dụng khai ở Dòng nhập theo ngày cụ thể hoặc N ngày kể từ ngày nhập, áp cả cho Mã dùng một lần', function () {
    $batch = $this->intake->submit($this->admin, batchOf([
        new BatchLineDraft(steamWallet(), 95_000, 'AAAA-BBBB', expiry: ExpiryRule::on(CarbonImmutable::parse('2027-01-31'))),
        new BatchLineDraft(streamingAccount(), 100_000, "a@shop.test\tpw", expiry: ExpiryRule::afterDays(30)),
    ]));

    expect(array_map(fn ($line) => $line->sample, $this->intake->preview($this->admin, $batch)->lines))->toBe([
        [['Mã thẻ' => '••••••', 'Hạn sử dụng' => '31/01/2027']],
        [['Tên đăng nhập' => 'a@shop.test', 'Mật khẩu' => '••••••', 'Số slot' => '4', 'Hạn sử dụng' => '15/10/2026']],
    ]);

    $this->intake->confirm($this->admin, $batch);

    expect(StockUnit::orderBy('id')->get()->map(fn (StockUnit $unit) => $unit->expires_on->toDateString())->all())
        ->toBe(['2027-01-31', '2026-10-15']);
});

it('upload CSV: tiêu đề theo Trường nội dung, cột thừa bị bỏ qua và báo lại, cột slot, han_su_dung, gia_von ghi đè Dòng nhập', function () {
    $csv = implode("\n", [
        'Tên đăng nhập,password,slot,han_su_dung,gia_von,Ghi chú NCC',
        'a@shop.test,pw1,,,,hàng mới',
        'b@shop.test,pw2,2,2026-12-31,150.000,',
        'c@shop.test,pw3,0,,,',
        'd@shop.test,pw4,,31/02/2026,,',
        'e@shop.test,pw5,,,-5,',
        'f@shop.test,pw6,1,30,,',
        ',,,,,',
        '"g@shop.test","pw,7",,,,',
    ]);

    $batch = $this->intake->submit($this->admin, batchOf(
        [BatchLineDraft::file(streamingAccount(), 120_000, $csv, 'netflix.csv', expiry: ExpiryRule::afterDays(90))],
        invoiceTotal: 500_000,
    ));

    $preview = $this->intake->preview($this->admin, $batch);
    $line = $preview->lines[0];

    expect($line)
        ->source->toBe(IntakeSource::Csv)
        ->fileName->toBe('netflix.csv')
        ->validCount->toBe(4)
        ->invalidCount->toBe(3)
        ->ignoredColumns->toBe(['Ghi chú NCC'])
        ->totalCost->toBe(510_000)
        ->and($line->rejected)->toEqual([
            new RejectedLine(4, LineClass::Invalid, 'Cột slot phải là số nguyên từ 1 đến 1.000.'),
            new RejectedLine(5, LineClass::Invalid, 'Cột han_su_dung phải là ngày (YYYY-MM-DD hoặc DD/MM/YYYY) hoặc số ngày kể từ ngày nhập.'),
            new RejectedLine(6, LineClass::Invalid, 'Cột gia_von phải là số tiền VND nguyên, không âm.'),
        ])
        ->and($preview->invoiceDifference())->toBe(-10_000);

    $this->intake->confirm($this->admin, $batch);

    expect(StockUnit::with('slots')->orderBy('id')->get()->map(fn (StockUnit $unit) => [
        $unit->content['username'],
        $unit->unit_cost,
        $unit->expires_on->toDateString(),
        $unit->slots->pluck('cost')->all(),
    ])->all())->toBe([
        ['a@shop.test', 120_000, '2026-12-14', [30_000, 30_000, 30_000, 30_000]],
        ['b@shop.test', 150_000, '2026-12-31', [75_000, 75_000]],
        ['f@shop.test', 120_000, '2026-10-15', [120_000]],
        ['g@shop.test', 120_000, '2026-12-14', [30_000, 30_000, 30_000, 30_000]],
    ]);
});

it('upload XLSX: đọc sheet đầu, ô số và ô ngày thành chuỗi', function () {
    $content = xlsxFile([
        ['Mã thẻ', 'GIA_VON', 'han_su_dung', 'slot'],
        ['AAAA-BBBB', 90_000, new DateTimeImmutable('2027-01-31'), 1],
        [123456789012, null, null, null],
        [],
        ['aaaa bbbb', null, null, null],
        ['CCCC-DDDD', null, null, 3],
    ]);

    $batch = $this->intake->submit($this->admin, batchOf([BatchLineDraft::file(steamWallet(), 95_000, $content, 'the-cao.xlsx')]));

    $line = $this->intake->preview($this->admin, $batch)->lines[0];

    expect($line)
        ->source->toBe(IntakeSource::Xlsx)
        ->validCount->toBe(2)
        ->totalCost->toBe(185_000)
        ->and($line->rejected)->toEqual([
            new RejectedLine(5, LineClass::FileDuplicate, 'Trùng Khoá chống trùng với dòng 2.'),
            new RejectedLine(6, LineClass::Invalid, 'Mã dùng một lần luôn có đúng 1 slot.'),
        ])
        ->and($line->sample)->toBe([
            ['Mã thẻ' => '••••••', 'Hạn sử dụng' => '31/01/2027'],
            ['Mã thẻ' => '••••••'],
        ]);
});

it('dòng trùng Khoá chống trùng với Dòng nhập đứng trước trong cùng Lô nhập xếp vào trùng trong file', function () {
    $batch = $this->intake->submit($this->admin, batchOf([
        new BatchLineDraft(steamWallet(), 95_000, "AAAA-BBBB\nCCCC-DDDD"),
        new BatchLineDraft(steamWallet('STEAM-200K'), 190_000, "EEEE\ncccc dddd"),
    ]));

    $lines = $this->intake->preview($this->admin, $batch)->lines;

    expect($lines[1])
        ->validCount->toBe(1)
        ->fileDuplicateCount->toBe(1)
        ->and($lines[1]->rejected)->toEqual([
            new RejectedLine(2, LineClass::FileDuplicate, 'Trùng Khoá chống trùng với dòng 2 của Dòng nhập "Steam Wallet STEAM-100K".'),
        ]);

    $this->intake->confirm($this->admin, $batch);

    expect(StockUnit::count())->toBe(3);
});

it('nhập lại Tài khoản đã quá Hạn sử dụng hoặc đã Huỷ hàng thành Đơn vị hàng mới liên kết với cái cũ; Tài khoản còn hạn là trùng trong kho', function () {
    $netflix = streamingAccount();
    $this->intake->confirm($this->admin, $this->intake->submit($this->admin, batchOf([
        new BatchLineDraft($netflix, 100_000, "old@shop.test\tpw", expiry: ExpiryRule::on(CarbonImmutable::parse('2026-10-15'))),
    ])));
    $this->intake->confirm($this->admin, $this->intake->submit($this->admin, batchOf([
        new BatchLineDraft(streamingAccount('SPOTIFY-1M'), 50_000, "live@shop.test\tpw\nvoided@shop.test\tpw"),
    ])));
    $old = StockUnit::where('content->username', 'old@shop.test')->sole();
    $voided = StockUnit::where('content->username', 'voided@shop.test')->sole();
    // Chưa có thao tác Huỷ hàng trong module: đặt trạng thái trực tiếp.
    StockUnit::whereKey($voided->id)->update(['status' => StockUnitStatus::Voided]);

    $this->travelTo(CarbonImmutable::parse('2026-10-15 23:00'));

    $sameDay = $this->intake->submit($this->admin, batchOf([new BatchLineDraft(streamingAccount('NETFLIX-3M'), 1, "old@shop.test\tpw")], receivedOn: '2026-10-15'));

    expect($this->intake->preview($this->admin, $sameDay)->lines[0]->stockDuplicateCount)->toBe(1);

    $this->travelTo(CarbonImmutable::parse('2026-10-16 09:00'));

    $batch = $this->intake->submit($this->admin, batchOf([
        new BatchLineDraft(streamingAccount('NETFLIX-6M'), 250_000, "OLD@shop.test\tpw-moi\nlive@shop.test\tpw\nnew@shop.test\tpw\nvoided@shop.test\tpw", slots: 2),
    ], receivedOn: '2026-10-16'));

    $line = $this->intake->preview($this->admin, $batch)->lines[0];

    expect($line)
        ->validCount->toBe(1)
        ->renewalCount->toBe(2)
        ->stockDuplicateCount->toBe(1)
        ->totalCost->toBe(750_000)
        ->and($line->rejected)->toEqual([new RejectedLine(2, LineClass::StockDuplicate, 'Khoá chống trùng đã có trong kho.')]);

    $this->intake->confirm($this->admin, $batch, skipStockDuplicates: true);

    $renewals = StockUnit::with('slots')->where('batch_line_id', Batch::findOrFail($batch->id)->lines[0]->id)->orderBy('id')->get();

    expect($renewals->map(fn (StockUnit $unit) => [$unit->content['username'], $unit->renews_stock_unit_id, $unit->slots->count()])->all())->toBe([
        ['OLD@shop.test', $old->id, 2],
        ['new@shop.test', null, 2],
        ['voided@shop.test', $voided->id, 2],
    ]);

    $again = $this->intake->submit($this->admin, batchOf([new BatchLineDraft($netflix, 1, "old@shop.test\tpw")], receivedOn: '2026-10-16'));

    expect($this->intake->preview($this->admin, $again)->lines[0]->stockDuplicateCount)->toBe(1);
});

it('DB chặn hai Tài khoản cùng Khoá chống trùng cùng chiếm khoá', function () {
    $this->intake->confirm($this->admin, $this->intake->submit($this->admin, batchOf([new BatchLineDraft(streamingAccount(), 1, "a@shop.test\tpw")])));
    $unit = StockUnit::sole();

    expect(fn () => DB::transaction(fn () => DB::table('stock_units')->insert([
        ...collect($unit->getAttributes())->except(['id', 'content', 'expires_on', 'renews_stock_unit_id'])->all(),
    ])))->toThrow(QueryException::class, 'stock_units_account_dedupe');
});

it('dòng trùng trong kho chỉ được bỏ qua sau khi tick xác nhận riêng', function () {
    $product = steamWallet();
    $this->intake->confirm($this->admin, $this->intake->submit($this->admin, pasteBatch($product, 'AAAA-BBBB')));
    $batch = $this->intake->submit($this->admin, pasteBatch($product, "aaaabbbb\nCCCC-DDDD"));

    expect(fn () => $this->intake->confirm($this->admin, $batch))
        ->toThrow(InvalidBatch::class, 'Có 1 dòng trùng trong kho; hãy tick xác nhận bỏ qua các dòng này rồi xác nhận lại.')
        ->and(Batch::findOrFail($batch->id)->status)->toBe(BatchStatus::Validated)
        ->and(StockUnit::count())->toBe(1);

    $this->intake->confirm($this->admin, $batch, skipStockDuplicates: true);

    expect(StockUnit::count())->toBe(2);
});

it('nội dung chờ xác nhận nằm mã hoá trên disk nhập hàng, xoá khi xác nhận hoặc bỏ', function () {
    $disk = Storage::disk('intake');
    $confirmed = $this->intake->submit($this->admin, pasteBatch(garenaCard(), "SR001\t123456789012\tmật khẩu ví"));
    $discarded = $this->intake->submit($this->admin, pasteBatch(steamWallet(), 'ZZZZ-9999'));

    expect($disk->allFiles())->toHaveCount(2)
        ->and(collect($disk->allFiles())->map(fn (string $path) => $disk->get($path))->implode("\n"))
        ->not->toContain('123456789012')->not->toContain('ZZZZ-9999');

    $this->intake->confirm($this->admin, $confirmed);
    $this->intake->discard($this->admin, $discarded);

    expect($disk->allFiles())->toBe([])
        ->and(Batch::findOrFail($discarded->id)->status)->toBe(BatchStatus::Discarded)
        ->and(fn () => $this->intake->confirm($this->admin, $discarded))->toThrow(InvalidBatch::class, 'Lô nhập đã bị bỏ.')
        ->and(fn () => $this->intake->discard($this->admin, $confirmed))->toThrow(InvalidBatch::class, 'Lô nhập đã xác nhận, không bỏ được.')
        ->and(fn () => $this->intake->discard(staffMember(Role::BanHang), $discarded))->toThrow(MissingRole::class);
});

it('bản kiểm tra chưa xác nhận quá 24 giờ: không xác nhận được, lệnh dọn xoá nội dung tạm và file upload tạm', function () {
    $product = steamWallet();
    $stale = $this->intake->submit($this->admin, pasteBatch($product, 'AAAA-BBBB'));
    $this->travel(23)->hours();
    $fresh = $this->intake->submit($this->admin, pasteBatch($product, 'CCCC-DDDD'));
    $this->travel(2)->hours();

    expect(fn () => $this->intake->confirm($this->admin, $stale))
        ->toThrow(InvalidBatch::class, 'Bản kiểm tra quá 24 giờ chưa xác nhận nên nội dung tạm đã bị xoá; hãy tạo lại Lô nhập.');

    Storage::fake(FileUploadConfiguration::disk());
    $uploads = FileUploadConfiguration::storage();
    $staleUpload = FileUploadConfiguration::directory().'/stale-upload.csv';
    $uploads->put($staleUpload, 'a@shop.test,pw');
    touch($uploads->path($staleUpload), now()->subHours(25)->getTimestamp());

    $this->artisan('inventory:intake:purge')->assertSuccessful();

    expect(Batch::findOrFail($stale->id)->status)->toBe(BatchStatus::Expired)
        ->and(Batch::findOrFail($fresh->id)->status)->toBe(BatchStatus::Validated)
        ->and(Storage::disk('intake')->allFiles())->toHaveCount(1)
        ->and($uploads->allFiles(FileUploadConfiguration::directory()))->toBe([]);

    $this->intake->confirm($this->admin, $fresh);

    expect(StockUnit::count())->toBe(1);
});

it('Lô nhập bổ sung trỏ về Lô nhập đã xác nhận; xem trước đối chiếu tổng tiền hoá đơn', function () {
    $product = steamWallet();
    $first = $this->intake->submit($this->admin, pasteBatch($product, 'AAAA'));
    $this->intake->confirm($this->admin, $first);

    $second = $this->intake->submit($this->admin, batchOf([new BatchLineDraft($product, 95_000, "BBBB\nCCCC")], invoiceTotal: 190_000, supplements: $first));

    expect($this->intake->preview($this->admin, $second))
        ->supplementsBatchId->toBe($first->id)
        ->invoiceTotal->toBe(190_000)
        ->invoiceDifference()->toBe(0)
        ->and(Batch::findOrFail($second->id)->supplements->id)->toBe($first->id)
        ->and(fn () => $this->intake->submit($this->admin, batchOf([new BatchLineDraft($product, 1, 'DDDD')], supplements: $second)))
        ->toThrow(InvalidBatch::class, 'Chỉ bổ sung cho Lô nhập đã xác nhận.');
});

it('Giá vốn không sửa được sau khi nhập', function () {
    $this->intake->confirm($this->admin, $this->intake->submit($this->admin, batchOf([new BatchLineDraft(streamingAccount(), 100_000, "a@shop.test\tpw")])));

    expect(fn () => DB::transaction(fn () => DB::table('stock_units')->update(['unit_cost' => 1])))
        ->toThrow(QueryException::class, 'Giá vốn không sửa được sau khi nhập')
        ->and(fn () => DB::transaction(fn () => DB::table('slots')->update(['cost' => 1])))
        ->toThrow(QueryException::class, 'Giá vốn không sửa được sau khi nhập')
        ->and(StockUnit::sole()->unit_cost)->toBe(100_000);
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
        ->implode("\n")
        .collect(Storage::disk('intake')->allFiles())->map(fn (string $path) => Storage::disk('intake')->get($path))->implode("\n");

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

    $this->intake->confirm($this->admin, $batch, skipStockDuplicates: true);

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

it('kiểm tra trùng lại lúc ghi: Lô nhập khác đã nhập cùng mã sau bước xem trước thì vẫn phải tick', function () {
    $product = steamWallet();
    $earlier = $this->intake->submit($this->admin, pasteBatch($product, "SHARED-CODE\nONLY-EARLIER"));
    $later = $this->intake->submit($this->admin, pasteBatch(steamWallet('STEAM-200K'), 'shared code'));

    expect($this->intake->preview($this->admin, $earlier)->lines[0]->validCount)->toBe(2);

    $this->intake->confirm($this->admin, $later);

    expect(fn () => $this->intake->confirm($this->admin, $earlier))
        ->toThrow(InvalidBatch::class, 'Có 1 dòng trùng trong kho; hãy tick xác nhận bỏ qua các dòng này rồi xác nhận lại.')
        ->and($this->intake->preview($this->admin, $earlier))
        ->status->toBe(BatchStatus::Validated)
        ->and($this->intake->preview($this->admin, $earlier)->lines[0])
        ->validCount->toBe(1)
        ->stockDuplicateCount->toBe(1)
        ->and(StockUnit::count())->toBe(1);

    $this->intake->confirm($this->admin, $earlier, skipStockDuplicates: true);

    expect(Batch::findOrFail($earlier->id)->rejectedDuplicateCount())->toBe(1)
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
    'hai Dòng nhập cùng Sản phẩm' => [fn () => batchOf([
        new BatchLineDraft($product = steamWallet(), 1, 'AAAA'),
        new BatchLineDraft($product, 1, 'BBBB'),
    ]), 'Sản phẩm "Steam Wallet STEAM-100K" có hai Dòng nhập; mỗi Sản phẩm một Dòng nhập.'],
    'Mã dùng một lần nhiều slot' => [fn () => batchOf([new BatchLineDraft(steamWallet(), 1, 'AAAA', slots: 2)]), 'Mã dùng một lần luôn có đúng 1 slot (Dòng nhập "Steam Wallet STEAM-100K").'],
    'số slot vượt giới hạn' => [fn () => batchOf([new BatchLineDraft(streamingAccount(), 1, "a@b.vn\tpw", slots: 1_001)]), 'Số slot của Dòng nhập "Tài khoản NETFLIX-1M" phải từ 1 đến 1.000.'],
    'tổng tiền hoá đơn âm' => [fn () => batchOf([new BatchLineDraft(steamWallet(), 1, 'AAAA')], invoiceTotal: -1), 'Tổng tiền hoá đơn không được âm.'],
    'vượt số dòng' => [function () {
        config(['inventory.intake.max_lines' => 2]);

        return pasteBatch(steamWallet(), "AAAA\nBBBB\n\nCCCC");
    }, 'Dòng nhập "Steam Wallet STEAM-100K" có 3 dòng, vượt giới hạn 2 dòng mỗi file hoặc danh sách dán.'],
    'vượt dung lượng' => [function () {
        config(['inventory.intake.max_bytes' => 1024]);

        return pasteBatch(steamWallet(), str_repeat("AAAA-BBBB\n", 103));
    }, 'Dòng nhập "Steam Wallet STEAM-100K" vượt giới hạn 1 KB mỗi file hoặc danh sách dán.'],
    'file không phải CSV/XLSX' => [fn () => batchOf([BatchLineDraft::file(steamWallet(), 1, 'AAAA', 'codes.pdf')]), 'File "codes.pdf" không phải CSV hoặc XLSX.'],
    'file thiếu dòng tiêu đề' => [fn () => batchOf([BatchLineDraft::file(steamWallet(), 1, "\n\n", 'codes.csv')]), 'Dòng nhập "Steam Wallet STEAM-100K" chưa có nội dung.'],
    'file thiếu cột bắt buộc' => [fn () => batchOf([BatchLineDraft::file(streamingAccount(), 1, "password,email\npw,a@b.vn", 'netflix.csv')]), 'Dòng nhập "Tài khoản NETFLIX-1M": File thiếu cột cho Trường nội dung bắt buộc: Tên đăng nhập.'],
    'file chỉ có dòng tiêu đề' => [fn () => batchOf([BatchLineDraft::file(steamWallet(), 1, "code\n", 'codes.csv')]), 'Dòng nhập "Steam Wallet STEAM-100K" chưa có nội dung.'],
    'file XLSX hỏng' => [fn () => batchOf([BatchLineDraft::file(steamWallet(), 1, 'không phải zip', 'codes.xlsx')]), 'Dòng nhập "Steam Wallet STEAM-100K": File XLSX không đọc được.'],
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
