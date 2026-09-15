<?php

use App\Inventory\Encryption\ContentCrypto;
use App\Inventory\Encryption\Normalization;

it('tính HMAC-SHA256 đầy đủ bằng khoá HMAC trên chuỗi đã trim, NFKC và bỏ ký tự vô hình', function () {
    // Kỳ vọng tính độc lập bằng openssl: HMAC-SHA256("ABCD1234") với khoá HMAC test.
    $expected = '8f9e8d6f384eb43ea7bc7c7b820e76b2941e9216c9997f5bbd6717d3e9390230';

    $crypto = app(ContentCrypto::class);

    expect($crypto->dedupeHash('ABCD1234', new Normalization))->toBe($expected)
        ->and($crypto->dedupeHash("\u{FEFF} ＡＢ\u{00AD}ＣＤ１２３４\u{200B}\t", new Normalization))->toBe($expected);
});

it('cùng mã viết khác nhau ra cùng HMAC khi bật tuỳ chọn chuẩn hoá', function () {
    $crypto = app(ContentCrypto::class);
    $relaxed = new Normalization(caseInsensitive: true, stripSeparators: true);

    $variants = ['ABCD-1234-EFGH', 'abcd-1234-efgh', 'Abcd 1234 Efgh', 'ABCD–1234—EFGH', 'abcd1234efgh'];

    expect(array_unique(array_map(fn (string $code) => $crypto->dedupeHash($code, $relaxed), $variants)))->toHaveCount(1)
        ->and($crypto->dedupeHash('ABCD-1234-EFGH', $relaxed))->not->toBe($crypto->dedupeHash('ABCD-1234-EFGX', $relaxed));
});

it('phân biệt hoa thường và gạch ngang khi không bật tuỳ chọn', function () {
    $crypto = app(ContentCrypto::class);

    expect($crypto->dedupeHash('abcd1234', new Normalization))->not->toBe($crypto->dedupeHash('ABCD1234', new Normalization))
        ->and($crypto->dedupeHash('ABCD-1234', new Normalization))->not->toBe($crypto->dedupeHash('ABCD1234', new Normalization))
        ->and($crypto->dedupeHash('ABCD-1234', new Normalization(caseInsensitive: true)))
        ->not->toBe($crypto->dedupeHash('ABCD1234', new Normalization(caseInsensitive: true)))
        ->and($crypto->dedupeHash('abcd-1234', new Normalization(stripSeparators: true)))
        ->not->toBe($crypto->dedupeHash('ABCD1234', new Normalization(stripSeparators: true)));
});
