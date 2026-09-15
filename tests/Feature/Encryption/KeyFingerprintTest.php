<?php

use App\Inventory\Encryption\KeyFingerprintMismatch;
use App\Inventory\Encryption\KeyFingerprints;
use App\Inventory\Security\SecurityEvent;
use App\Models\SecurityLogEntry;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

const OTHER_KEY = '1:base64:+aDuV69xpMmq8HrVKo6jtB43YKS+Sd8UDwlDy4k5kgk=';

it('chấp nhận các khoá có dấu vân tay khớp với DB và ghi Nhật ký bảo mật khi đăng ký', function () {
    app(KeyFingerprints::class)->register();

    app(KeyFingerprints::class)->verify();

    expect(SecurityLogEntry::orderBy('id')->pluck('details')->all())->toEqualCanonicalizing([
        ['purpose' => 'content', 'version' => 2],
        ['purpose' => 'content', 'version' => 1],
        ['purpose' => 'hmac', 'version' => 1],
        ['purpose' => 'backup', 'version' => 1],
    ])->and(SecurityLogEntry::pluck('event')->unique()->all())->toBe([SecurityEvent::KeyFingerprintRegistered])
        ->and(SecurityLogEntry::pluck('details')->toJson())->not->toContain('base64');
});

it('từ chối khoá sai dù cùng phiên bản, thông báo rõ khoá nào và không lộ giá trị khoá', function (string $configKey, string $label) {
    app(KeyFingerprints::class)->register();

    config(["inventory.keys.{$configKey}" => OTHER_KEY]);

    expect(fn () => app(KeyFingerprints::class)->verify())
        ->toThrow(function (KeyFingerprintMismatch $e) use ($label) {
            expect($e->getMessage())->toContain("{$label} phiên bản 1")
                ->and($e->getMessage())->not->toContain('+aDuV69');
        });
})->with([
    'khoá HMAC' => ['hmac', 'khoá mã hoá HMAC'],
    'khoá backup' => ['backup', 'khoá mã hoá backup'],
]);

it('từ chối khoá nội dung cũ bị thay bằng khoá khác', function () {
    app(KeyFingerprints::class)->register();

    config(['inventory.keys.content_previous' => [OTHER_KEY]]);

    expect(fn () => app(KeyFingerprints::class)->verify())
        ->toThrow(KeyFingerprintMismatch::class, 'khoá mã hoá nội dung phiên bản 1');
});

it('từ chối khoá chưa đăng ký dấu vân tay', function () {
    expect(fn () => app(KeyFingerprints::class)->verify())
        ->toThrow(KeyFingerprintMismatch::class, 'chưa đăng ký');
});

it('từ chối khi môi trường dùng phiên bản khoá cũ hơn phiên bản đã đăng ký', function () {
    app(KeyFingerprints::class)->register();

    config([
        'inventory.keys.content' => '1:base64:T5GFObgdym7AKwyt0v4qsJVASSLQFeWrTM9NT+Vr9Zw=',
        'inventory.keys.content_previous' => [],
    ]);

    expect(fn () => app(KeyFingerprints::class)->verify())
        ->toThrow(KeyFingerprintMismatch::class, 'khoá mã hoá nội dung: môi trường dùng phiên bản 1');
});

it('không ghi đè dấu vân tay đã đăng ký bằng khoá khác cùng phiên bản', function () {
    app(KeyFingerprints::class)->register();
    $original = config('inventory.keys.hmac');

    config(['inventory.keys.hmac' => OTHER_KEY]);

    expect(fn () => app(KeyFingerprints::class)->register())
        ->toThrow(KeyFingerprintMismatch::class, 'khoá mã hoá HMAC phiên bản 1: dấu vân tay không khớp với DB');

    config(['inventory.keys.hmac' => $original]);

    app(KeyFingerprints::class)->verify();
    expect(SecurityLogEntry::count())->toBe(4);
});

it('PostgreSQL chặn sửa, xoá và truncate dấu vân tay đã đăng ký', function (string $sql) {
    app(KeyFingerprints::class)->register();

    // Savepoint riêng để lỗi không làm hỏng transaction của RefreshDatabase.
    expect(fn () => DB::transaction(fn () => DB::statement($sql)))
        ->toThrow(QueryException::class, 'chỉ-ghi-thêm');
})->with([
    'update' => "UPDATE encryption_key_fingerprints SET fingerprint = repeat('0', 64)",
    'delete' => 'DELETE FROM encryption_key_fingerprints',
    'truncate' => 'TRUNCATE encryption_key_fingerprints',
]);
