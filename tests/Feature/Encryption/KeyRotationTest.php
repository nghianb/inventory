<?php

use App\Inventory\Access\Role;
use App\Inventory\Catalog\ContentFieldDraft;
use App\Inventory\Catalog\ContentFieldType;
use App\Inventory\Catalog\ProductCatalog;
use App\Inventory\Catalog\ProductDraft;
use App\Inventory\Catalog\ProductType;
use App\Inventory\Catalog\SupplierDirectory;
use App\Inventory\Dispatch\DispatchDraft;
use App\Inventory\Dispatch\DispatchLineDraft;
use App\Inventory\Dispatch\DispatchStatus;
use App\Inventory\Dispatch\ManualDispatch;
use App\Inventory\Dispatch\SalesChannelDirectory;
use App\Inventory\Dispatch\SalesChannelDraft;
use App\Inventory\Encryption\ContentCrypto;
use App\Inventory\Encryption\DedupeHashesStale;
use App\Inventory\Encryption\DedupeLookup;
use App\Inventory\Encryption\EncryptedContent;
use App\Inventory\Encryption\KeyFingerprints;
use App\Inventory\Encryption\KeyPurpose;
use App\Inventory\Intake\BatchDraft;
use App\Inventory\Intake\BatchIntake;
use App\Inventory\Intake\BatchLineDraft;
use App\Inventory\Intake\InvalidBatch;
use App\Inventory\Reveal\ContentReveal;
use App\Inventory\Reveal\RevealActor;
use App\Inventory\Reveal\RevealContext;
use App\Inventory\Security\SecurityEvent;
use App\Models\SecurityLogEntry;
use App\Models\Slot;
use App\Models\StockUnit;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
 * Xoay khoá mã hoá bằng lệnh trên server (ADR 0001: không có nút trong Filament). Kho trong các
 * test này bắt đầu ở phiên bản khoá đầu tiên, rồi chính test đổi cấu hình sang phiên bản sau và
 * chạy lệnh, đúng như Người vận hành server sửa .env rồi chạy lệnh.
 */

const CONTENT_V1 = '1:base64:T5GFObgdym7AKwyt0v4qsJVASSLQFeWrTM9NT+Vr9Zw=';

const CONTENT_V2 = '2:base64:BCVd/gqwBoC1GS5+NwvO8g6FCqUkcbvfamTBkhqbH1M=';

const HMAC_V2 = '2:base64:+aDuV69xpMmq8HrVKo6jtB43YKS+Sd8UDwlDy4k5kgk=';

const BACKUP_V2 = '2:base64:T5GFObgdym7AKwyt0v4qsJVASSLQFeWrTM9NT+Vr9Zw=';

beforeEach(function () {
    Storage::fake('intake');
    $this->seed(RoleSeeder::class);

    config(['inventory.keys.content' => CONTENT_V1, 'inventory.keys.content_previous' => []]);
    app(KeyFingerprints::class)->register();

    $this->admin = staffMember(Role::Owner);
    $this->supplier = app(SupplierDirectory::class)->create($this->admin, 'Kinguin');
    $this->garena = app(ProductCatalog::class)->create($this->admin, new ProductDraft(
        type: ProductType::OneTimeCode,
        name: 'Thẻ Garena 100k',
        code: 'GARENA-100K',
        fields: [
            new ContentFieldDraft('serial', 'Serial', sensitive: false),
            new ContentFieldDraft('pin', 'Mã thẻ', ContentFieldType::Number, pattern: '\d{12}', dedupeKey: true),
        ],
    ));

    stockUp($this->garena, "SR001\t100000000001\nSR002\t100000000002");
});

it('xoay khoá nội dung: mã hoá lại mọi Đơn vị hàng sang phiên bản mới, nội dung vẫn giải mã đúng', function () {
    config(['inventory.keys.content' => CONTENT_V2, 'inventory.keys.content_previous' => [CONTENT_V1]]);

    // Nội dung Lô nhập chờ xác nhận nằm mã hoá trên disk: lệnh phải nhắc giữ khoá cũ tới khi hết hạn.
    $this->artisan('inventory:keys:rotate content')
        ->expectsOutputToContain('INVENTORY_CONTENT_PREVIOUS_KEYS')
        ->assertSuccessful();

    expect(StockUnit::orderBy('id')->pluck('secret_key_version')->all())->toBe([2, 2]);

    // Bỏ hẳn khoá cũ khỏi môi trường: còn bản ghi nào chưa mã hoá lại thì đọc nội dung sẽ nổ.
    config(['inventory.keys.content_previous' => []]);

    $revealed = Slot::query()->orderBy('id')->get()
        ->map(fn (Slot $slot): array => app(ContentReveal::class)->reveal(
            RevealActor::staff($this->admin),
            $slot,
            RevealContext::inStock(),
            'Đối chiếu sau khi xoay khoá',
        )->fields)
        ->all();

    expect($revealed)->toBe([
        ['Serial' => 'SR001', 'Mã thẻ' => '100000000001'],
        ['Serial' => 'SR002', 'Mã thẻ' => '100000000002'],
    ]);
});

it('mỗi lần xoay ghi Nhật ký bảo mật lúc bắt đầu và lúc xong, kèm phiên bản cũ/mới và dấu vân tay, không có giá trị khoá', function () {
    config(['inventory.keys.content' => CONTENT_V2, 'inventory.keys.content_previous' => [CONTENT_V1]]);
    $this->travelTo(CarbonImmutable::parse('2026-09-17 09:00'));

    $this->artisan('inventory:keys:rotate content')->assertSuccessful();

    $rotation = SecurityLogEntry::query()
        ->whereIn('event', [SecurityEvent::KeyRotationStarted, SecurityEvent::KeyRotationFinished])
        ->orderBy('id')
        ->get();

    $registered = DB::table('encryption_key_fingerprints')->where('purpose', 'content')->where('version', 2)->value('fingerprint');

    expect($rotation->pluck('event')->all())->toBe([SecurityEvent::KeyRotationStarted, SecurityEvent::KeyRotationFinished])
        ->and($rotation->first()->details)->toMatchArray(['purpose' => 'content', 'from_version' => 1, 'to_version' => 2])
        ->and($rotation->last()->details)->toEqualCanonicalizing([
            'purpose' => 'content',
            'from_version' => 1,
            'to_version' => 2,
            'fingerprint' => $registered,
            'rewritten' => 2,
            'started_at' => '2026-09-17T09:00:00+07:00',
            'finished_at' => '2026-09-17T09:00:00+07:00',
        ])
        ->and(SecurityLogEntry::pluck('details')->toJson())->not->toContain('BCVd/');
});

it('xoay khoá HMAC: tính lại mọi Khoá chống trùng, tra cứu và chống trùng khi nhập vẫn đúng', function () {
    $before = StockUnit::orderBy('id')->pluck('dedupe_hash')->all();

    config(['inventory.keys.hmac' => HMAC_V2]);

    $this->artisan('inventory:keys:rotate hmac')->assertSuccessful();

    // Hash đổi hẳn (khoá HMAC khác), nhưng tra theo Khoá chống trùng vẫn ra đúng Đơn vị hàng cũ.
    expect(StockUnit::orderBy('id')->pluck('dedupe_hash')->all())->not->toBe($before)
        ->and(app(DedupeLookup::class)->matchingUnits('100000000001')?->pluck('id')->all())
        ->toBe([StockUnit::orderBy('id')->firstOrFail()->id]);

    // Và mã đã có trong kho vẫn bị bắt trùng khi nhập lại.
    $batch = app(BatchIntake::class)->submit($this->admin, new BatchDraft(
        supplier: $this->supplier,
        receivedOn: CarbonImmutable::parse('2026-09-18'),
        lines: [new BatchLineDraft($this->garena, 95_000, "SR003\t100000000001")],
    ));

    expect(app(BatchIntake::class)->preview($this->admin, $batch)->lines[0])
        ->validCount->toBe(0)
        ->stockDuplicateCount->toBe(1)
        ->and(SecurityLogEntry::pluck('details')->toJson())->not->toContain('+aDuV69');
});

it('xoay khoá HMAC chưa xong: nhập hàng tạm dừng, xuất kho vẫn chạy, xong thì nhập lại được', function () {
    // Đúng trạng thái giữa chừng: khoá mới đã đăng ký dấu vân tay, kho còn bản ghi ở khoá cũ.
    config(['inventory.keys.hmac' => HMAC_V2]);
    app(KeyFingerprints::class)->register(KeyPurpose::Hmac);

    $draft = fn (): BatchDraft => new BatchDraft(
        supplier: $this->supplier,
        receivedOn: CarbonImmutable::parse('2026-09-18'),
        lines: [new BatchLineDraft($this->garena, 95_000, "SR003\t100000000003")],
    );

    // Thứ tự xuất không đụng tới Khoá chống trùng, nên xuất kho vẫn chạy suốt lúc xoay khoá.
    $channel = app(SalesChannelDirectory::class)->create($this->admin, new SalesChannelDraft('Zalo'));
    $dispatch = app(ManualDispatch::class)->create($this->admin, new DispatchDraft($channel, null, [new DispatchLineDraft($this->garena, 1)]));

    expect($dispatch->status)->toBe(DispatchStatus::Completed)
        ->and(fn () => app(BatchIntake::class)->submit($this->admin, $draft()))
        ->toThrow(InvalidBatch::class, 'Đang xoay khoá mã hoá HMAC')
        // Tra cứu theo Khoá chống trùng cũng không được im lặng trả rỗng giữa chừng.
        ->and(fn () => app(DedupeLookup::class)->matchingUnits('100000000001'))
        ->toThrow(DedupeHashesStale::class);

    $this->artisan('inventory:keys:rotate hmac')->assertSuccessful();

    $batch = app(BatchIntake::class)->submit($this->admin, $draft());

    expect(app(BatchIntake::class)->preview($this->admin, $batch)->lines[0]->validCount)->toBe(1);
});

it('tính lại được Khoá chống trùng của Sản phẩm có trường khoá không nhạy cảm', function () {
    $viettel = app(ProductCatalog::class)->create($this->admin, new ProductDraft(
        type: ProductType::OneTimeCode,
        name: 'Thẻ cào Viettel 50k',
        code: 'VIETTEL-50K',
        fields: [
            new ContentFieldDraft('serial', 'Serial', sensitive: false, dedupeKey: true),
            new ContentFieldDraft('pin', 'Mã thẻ'),
        ],
    ));

    stockUp($viettel, "SERIAL-9001\t999000111222");

    config(['inventory.keys.hmac' => HMAC_V2]);

    $this->artisan('inventory:keys:rotate hmac')->assertSuccessful();

    expect(app(DedupeLookup::class)->matchingUnits('SERIAL-9001')?->pluck('product_id')->all())->toBe([$viettel->id]);
});

it('xoay khoá backup: chỉ thêm dấu vân tay phiên bản mới, không đụng tới dữ liệu trong kho', function () {
    $before = StockUnit::orderBy('id')->get(['dedupe_hash', 'secret_ciphertext', 'secret_key_version'])->toArray();

    config(['inventory.keys.backup' => BACKUP_V2]);

    $this->artisan('inventory:keys:rotate backup')->assertSuccessful();

    expect(StockUnit::orderBy('id')->get(['dedupe_hash', 'secret_ciphertext', 'secret_key_version'])->toArray())->toBe($before)
        ->and(DB::table('encryption_key_fingerprints')->where('purpose', 'backup')->orderBy('version')->pluck('version')->all())->toBe([1, 2])
        ->and(SecurityLogEntry::query()->where('event', SecurityEvent::KeyRotationFinished)->value('details'))
        ->toMatchArray(['purpose' => 'backup', 'from_version' => 1, 'to_version' => 2, 'rewritten' => 0])
        ->and(SecurityLogEntry::pluck('details')->toJson())->not->toContain('T5GFObgd');
});

it('lệnh chạy lại được sau khi bị ngắt giữa chừng: chỉ làm nốt phần còn lại', function () {
    config(['inventory.keys.content' => CONTENT_V2, 'inventory.keys.content_previous' => [CONTENT_V1]]);

    // Lần chạy trước đã đăng ký dấu vân tay khoá mới (bước đầu tiên của lệnh) rồi mã hoá lại xong
    // Đơn vị hàng đầu thì bị giết.
    app(KeyFingerprints::class)->register(KeyPurpose::Content);
    $crypto = app(ContentCrypto::class);
    $done = StockUnit::orderBy('id')->firstOrFail();
    $encrypted = $crypto->encrypt($crypto->decrypt(new EncryptedContent((string) $done->secret_ciphertext, 1)));

    DB::table('stock_units')->where('id', $done->id)->update([
        'secret_ciphertext' => $encrypted->ciphertext,
        'secret_key_version' => $encrypted->keyVersion,
    ]);

    // Giữa chừng kho vẫn chạy bình thường: một Đơn vị hàng ở khoá mới, một còn ở khoá cũ.
    expect(StockUnit::orderBy('id')->pluck('secret_key_version')->all())->toBe([2, 1])
        ->and(app(ContentReveal::class)->reveal(
            RevealActor::staff($this->admin),
            Slot::query()->orderBy('id', 'desc')->firstOrFail(),
            RevealContext::inStock(),
            'Xem giữa lúc đang xoay khoá',
        )->fields)->toBe(['Serial' => 'SR002', 'Mã thẻ' => '100000000002']);

    $this->artisan('inventory:keys:rotate content')->assertSuccessful();

    $finished = SecurityLogEntry::query()->where('event', SecurityEvent::KeyRotationFinished)->orderBy('id')->pluck('details');

    expect($finished->last()['rewritten'])->toBe(1)
        ->and(StockUnit::orderBy('id')->pluck('secret_key_version')->all())->toBe([2, 2]);

    // Chạy lại khi không còn gì: không ghi thêm một lần "xoay khoá" chưa hề xảy ra vào nhật ký.
    $this->artisan('inventory:keys:rotate content')
        ->expectsOutputToContain('Không có gì để xoay')
        ->assertSuccessful();

    expect(SecurityLogEntry::query()->where('event', SecurityEvent::KeyRotationFinished)->count())->toBe(1);
});
