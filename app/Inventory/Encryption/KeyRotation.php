<?php

namespace App\Inventory\Encryption;

use App\Inventory\Security\SecurityEvent;
use App\Inventory\Security\SecurityLog;
use App\Models\StockUnit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Xoay một khoá mã hoá của kho: Người vận hành server thêm phiên bản khoá mới vào `.env` rồi chạy
 * lệnh `inventory:keys:rotate` (ADR 0001: chỉ dòng lệnh, không có nút trong Filament). Mỗi loại
 * khoá xoay một kiểu:
 *
 * - **Nội dung**: mã hoá lại từng chunk các Đơn vị hàng còn ở phiên bản cũ. Khoá cũ nằm trong
 *   danh sách khoá cũ nên kho chạy bình thường suốt lúc chạy: bản ghi chưa mã hoá lại vẫn đọc được.
 * - **HMAC**: tính lại mọi Khoá chống trùng từ nội dung gốc (không cần khoá HMAC cũ). Kho không
 *   bao giờ giữ song song hai hash, nên nhập hàng tạm dừng trong lúc chạy ({@see rotatingDedupeKeys()}),
 *   còn xuất kho vẫn chạy vì Thứ tự xuất không đụng tới Khoá chống trùng.
 * - **Backup**: chỉ đăng ký dấu vân tay phiên bản mới; dữ liệu trong app không đổi.
 *
 * Lệnh chạy lại được sau khi bị ngắt: mỗi bản ghi mang phiên bản khoá đã dùng, nên lần chạy sau
 * chỉ làm nốt phần còn lại.
 */
final class KeyRotation
{
    private const CHUNK = 500;

    public function __construct(
        private KeyRing $keys,
        private KeyFingerprints $fingerprints,
        private ContentCrypto $crypto,
        private SecurityLog $log,
    ) {}

    /**
     * @throws InvalidKeyConfiguration khoá trong môi trường thiếu hoặc sai định dạng
     * @throws KeyFingerprintMismatch khoá trong môi trường không khớp dấu vân tay đã đăng ký
     */
    public function rotate(KeyPurpose $purpose): KeyRotationSummary
    {
        $startedAt = CarbonImmutable::now();
        $key = $this->keys->current($purpose);
        $from = $this->fingerprints->latestVersion($purpose);

        // Đăng ký trước khi đụng dữ liệu: dấu vân tay chưa có thì mọi tiến trình ghi đang từ chối chạy.
        $this->fingerprints->register($purpose);
        $this->fingerprints->verify();

        // Ghi ngay lúc bắt đầu: lần chạy bị ngắt giữa chừng vẫn để lại dấu vết trong Nhật ký bảo mật.
        $this->log->record(SecurityEvent::KeyRotationStarted, details: [
            'purpose' => $purpose->value,
            'from_version' => $from,
            'to_version' => $key->version,
            'fingerprint' => KeyFingerprints::fingerprint($key),
            'started_at' => $startedAt->toIso8601String(),
        ]);

        $rewritten = match ($purpose) {
            KeyPurpose::Content => $this->reencryptContent($key),
            KeyPurpose::Hmac => $this->recomputeDedupeHashes($key),
            KeyPurpose::Backup => 0,
        };

        $summary = new KeyRotationSummary(
            purpose: $purpose,
            fromVersion: $from,
            toVersion: $key->version,
            fingerprint: KeyFingerprints::fingerprint($key),
            rewritten: $rewritten,
            startedAt: $startedAt,
            finishedAt: CarbonImmutable::now(),
        );

        $this->log->record(SecurityEvent::KeyRotationFinished, details: $summary->details());

        return $summary;
    }

    /**
     * Kho còn Đơn vị hàng nào chưa tính lại Khoá chống trùng theo khoá HMAC đang cấu hình không:
     * hoặc lệnh xoay khoá đang chạy dở, hoặc một lần chạy đã bị ngắt giữa chừng. Nhập hàng phải
     * dừng chừng nào còn, vì hash mới ghi vào không so được với hash cũ nên dòng trùng lọt qua.
     * Xuất kho không đọc Khoá chống trùng nên không bị ảnh hưởng.
     *
     * min/max chạy trên cột đã đánh index nên không quét bảng.
     *
     * @throws InvalidKeyConfiguration
     */
    public static function rotatingDedupeKeys(): bool
    {
        $version = app(KeyRing::class)->current(KeyPurpose::Hmac)->version;

        $range = DB::table('stock_units')
            ->selectRaw('min(dedupe_key_version) AS lo, max(dedupe_key_version) AS hi')
            ->first();

        // Kho rỗng: lo là null, không có gì để tính lại.
        return $range?->lo !== null && ((int) $range->lo !== $version || (int) $range->hi !== $version);
    }

    /**
     * Mã hoá lại các Đơn vị hàng còn ở phiên bản khoá nội dung cũ, mỗi chunk một transaction dưới
     * khoá hàng, nên một lần xem mã hay một Lô nhập chạy song song không đọc phải bản ghi nửa vời.
     *
     * @return int số Đơn vị hàng đã mã hoá lại
     */
    private function reencryptContent(VersionedKey $key): int
    {
        $rewritten = 0;

        while (true) {
            $ids = DB::table('stock_units')
                ->whereNotNull('secret_ciphertext')
                ->where('secret_key_version', '<>', $key->version)
                ->orderBy('id')
                ->limit(self::CHUNK)
                ->pluck('id')
                ->all();

            if ($ids === []) {
                return $rewritten;
            }

            $rewritten += DB::transaction(function () use ($ids, $key): int {
                $units = DB::table('stock_units')
                    ->whereIn('id', $ids)
                    ->where('secret_key_version', '<>', $key->version)
                    ->lockForUpdate()
                    ->get(['id', 'secret_ciphertext', 'secret_key_version']);

                foreach ($units as $unit) {
                    $plaintext = $this->crypto->decrypt(new EncryptedContent(
                        (string) $unit->secret_ciphertext,
                        (int) $unit->secret_key_version,
                    ));

                    $encrypted = $this->crypto->encrypt($plaintext);

                    // Không đụng updated_at: mã hoá lại không phải một thay đổi nghiệp vụ của hàng.
                    DB::table('stock_units')->where('id', $unit->id)->update([
                        'secret_ciphertext' => $encrypted->ciphertext,
                        'secret_key_version' => $encrypted->keyVersion,
                    ]);
                }

                return $units->count();
            });
        }
    }

    /**
     * Tính lại Khoá chống trùng của các Đơn vị hàng còn ở phiên bản khoá HMAC cũ. Không cần khoá
     * HMAC cũ: hash được tính lại từ chính nội dung gốc của hàng.
     *
     * @return int số Đơn vị hàng đã tính lại
     */
    private function recomputeDedupeHashes(VersionedKey $key): int
    {
        $rewritten = 0;

        while (true) {
            $ids = DB::table('stock_units')
                ->where('dedupe_key_version', '<>', $key->version)
                ->orderBy('id')
                ->limit(self::CHUNK)
                ->pluck('id')
                ->all();

            if ($ids === []) {
                return $rewritten;
            }

            $rewritten += DB::transaction(function () use ($ids, $key): int {
                $units = StockUnit::query()
                    ->whereIn('id', $ids)
                    ->where('dedupe_key_version', '<>', $key->version)
                    ->with('product.contentFields')
                    ->lockForUpdate()
                    ->get();

                foreach ($units as $unit) {
                    DB::table('stock_units')->where('id', $unit->getKey())->update([
                        'dedupe_hash' => $this->crypto->dedupeHash($this->dedupeValue($unit), $unit->product->normalization()),
                        'dedupe_key_version' => $key->version,
                    ]);
                }

                return $units->count();
            });
        }
    }

    /**
     * Giá trị Khoá chống trùng dạng rõ của một Đơn vị hàng: trường không nhạy cảm nằm sẵn trong
     * `content`, trường nhạy cảm phải giải mã. Đây là việc trong module Mã hoá chứ không phải một
     * lần xem mã, nên không đi qua Ngữ cảnh xem mã và không ghi Nhật ký xem mã.
     */
    private function dedupeValue(StockUnit $unit): string
    {
        $field = $unit->product->dedupeKeyField();

        $value = $field->sensitive
            ? ($this->secretValues($unit)[$field->key] ?? '')
            : ($unit->content[$field->key] ?? '');

        if ((string) $value === '') {
            // Nhập hàng không bao giờ ghi Khoá chống trùng rỗng: dừng hẳn thay vì ghi một hash
            // rỗng trùng với mọi bản ghi hỏng khác.
            throw new RuntimeException("Đơn vị hàng #{$unit->getKey()} không có giá trị Khoá chống trùng để tính lại hash.");
        }

        return (string) $value;
    }

    /**
     * @return array<string, string>
     */
    private function secretValues(StockUnit $unit): array
    {
        if ($unit->secret_ciphertext === null) {
            return [];
        }

        $decrypted = $this->crypto->decrypt(new EncryptedContent($unit->secret_ciphertext, (int) $unit->secret_key_version));

        return (array) json_decode($decrypted, true, flags: JSON_THROW_ON_ERROR);
    }
}
