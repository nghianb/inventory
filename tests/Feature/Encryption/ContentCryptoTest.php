<?php

use App\Inventory\Encryption\ContentCrypto;
use App\Inventory\Encryption\InvalidKeyConfiguration;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;

it('mã hoá bằng aes-256-gcm với khoá nội dung riêng, không lộ plaintext trong giá trị lưu', function () {
    $encrypted = app(ContentCrypto::class)->encrypt('XXXX-YYYY-ZZZZ-1234');

    $contentKey = base64_decode('BCVd/gqwBoC1GS5+NwvO8g6FCqUkcbvfamTBkhqbH1M=');
    $payload = json_decode(base64_decode($encrypted->ciphertext), true);

    expect($encrypted->ciphertext)->not->toContain('XXXX-YYYY-ZZZZ-1234')
        ->and(base64_decode($payload['value']))->not->toContain('XXXX-YYYY-ZZZZ-1234')
        ->and($encrypted->keyVersion)->toBe(2)
        ->and((new Encrypter($contentKey, 'aes-256-gcm'))->decryptString($encrypted->ciphertext))->toBe('XXXX-YYYY-ZZZZ-1234')
        ->and(app(ContentCrypto::class)->decrypt($encrypted))->toBe('XXXX-YYYY-ZZZZ-1234')
        ->and(fn () => Crypt::decryptString($encrypted->ciphertext))->toThrow(DecryptException::class);
});

it('giải mã được bản ghi mã hoá bằng khoá nội dung cũ sau khi xoay khoá', function () {
    $oldKey = '1:base64:T5GFObgdym7AKwyt0v4qsJVASSLQFeWrTM9NT+Vr9Zw=';

    config(['inventory.keys.content' => $oldKey, 'inventory.keys.content_previous' => []]);
    $encryptedBeforeRotation = app(ContentCrypto::class)->encrypt('user@mail.test');

    config([
        'inventory.keys.content' => '2:base64:BCVd/gqwBoC1GS5+NwvO8g6FCqUkcbvfamTBkhqbH1M=',
        'inventory.keys.content_previous' => [$oldKey],
    ]);

    expect($encryptedBeforeRotation->keyVersion)->toBe(1)
        ->and(app(ContentCrypto::class)->decrypt($encryptedBeforeRotation))->toBe('user@mail.test');

    config(['inventory.keys.content_previous' => []]);

    expect(fn () => app(ContentCrypto::class)->decrypt($encryptedBeforeRotation))
        ->toThrow(InvalidKeyConfiguration::class, 'khoá mã hoá nội dung phiên bản 1');
});
