<?php

namespace App\Inventory\Encryption;

use App\Inventory\Security\SecurityEvent;
use App\Inventory\Security\SecurityLog;
use App\Models\StockUnit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Xoay một khoá mã hoá của kho: Người vận hành server thêm phiên bản khoá mới vào `.env` rồi chạy
 * lệnh `inventory:keys:rotate` (ADR 0001: chỉ dòng lệnh, không có nút trong Filament). Mỗi loại
 * khoá xoay một kiểu:
 *
 * - **Nội dung**: mã hoá lại từng chunk các Đơn vị hàng còn ở phiên bản cũ. Khoá cũ nằm trong
 *   danh sách khoá cũ nên kho chạy bình thường suốt lúc chạy: bản ghi chưa mã hoá lại vẫn đọc được.
 * - **HMAC**: tính lại hash Khoá chống trùng từ chính nội dung hàng (không cần khoá HMAC cũ). Kho
 *   không bao giờ giữ song song hai hash, nên nhập hàng tạm dừng chừng nào còn bản ghi ở phiên bản
 *   cũ ({@see hasStaleDedupeHashes()}), còn xuất kho vẫn chạy vì Thứ tự xuất không đọc Khoá chống
 *   trùng. Lý do chọn cách này ở ADR 0003.
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
     * @return ?KeyRotationSummary null khi không có gì để xoay: môi trường vẫn ở phiên bản đã đăng
     *                             ký và mọi bản ghi đã dùng phiên bản ấy. Không ghi Nhật ký bảo
     *                             mật, vì một lần xoay không hề xảy ra thì không nên có dấu vết
     *                             như đã xảy ra.
     *
     * @throws InvalidKeyConfiguration khoá trong môi trường thiếu hoặc sai định dạng
     * @throws KeyFingerprintMismatch khoá trong môi trường không khớp dấu vân tay đã đăng ký
     * @throws KeyRotationFailed dữ liệu trong kho không xoay được
     */
    public function rotate(KeyPurpose $purpose): ?KeyRotationSummary
    {
        $startedAt = CarbonImmutable::now();
        $key = $this->keys->current($purpose);
        $from = $this->fingerprints->latestVersion($purpose);

        if ($from === $key->version && ! self::hasStaleUnits($key)) {
            return null;
        }

        // Đăng ký trước khi đụng dữ liệu: dấu vân tay chưa có thì mọi tiến trình ghi đang từ chối
        // chạy, mà xoay khoá nội dung phải để kho chạy bình thường suốt lúc chạy.
        $this->fingerprints->register($purpose);
        $this->fingerprints->verify();

        $identity = [
            'purpose' => $purpose->value,
            'from_version' => $from,
            'to_version' => $key->version,
            'fingerprint' => KeyFingerprints::fingerprint($key),
        ];

        // Ghi ngay lúc bắt đầu: lần chạy bị ngắt giữa chừng vẫn để lại dấu vết trong Nhật ký bảo mật.
        $this->log->record(SecurityEvent::KeyRotationStarted, details: [...$identity, 'started_at' => $startedAt->toIso8601String()]);

        $rewritten = match ($purpose) {
            KeyPurpose::Content => $this->reencryptContent($key),
            KeyPurpose::Hmac => $this->recomputeDedupeHashes($key),
            KeyPurpose::Backup => 0,
        };

        $finishedAt = CarbonImmutable::now();

        $this->log->record(SecurityEvent::KeyRotationFinished, details: [
            ...$identity,
            'rewritten' => $rewritten,
            'started_at' => $startedAt->toIso8601String(),
            'finished_at' => $finishedAt->toIso8601String(),
        ]);

        return new KeyRotationSummary($purpose, $from, $key->version, $rewritten, $startedAt, $finishedAt);
    }

    /**
     * Kho còn Đơn vị hàng nào chưa tính lại hash Khoá chống trùng theo khoá mã hoá HMAC đang cấu
     * hình không: hoặc lệnh xoay khoá đang chạy dở, hoặc một lần chạy đã bị ngắt giữa chừng. Nhập
     * hàng phải dừng chừng nào còn, vì hash mới ghi vào không so được với hash cũ nên dòng trùng
     * lọt qua; tra cứu theo Khoá chống trùng cũng không được im lặng trả rỗng. Xuất kho không đọc
     * Khoá chống trùng nên không bị ảnh hưởng.
     *
     * min/max chạy trên cột đã đánh index nên không quét bảng.
     *
     * @throws InvalidKeyConfiguration
     */
    public static function hasStaleDedupeHashes(): bool
    {
        $version = app(KeyRing::class)->current(KeyPurpose::Hmac)->version;

        $range = DB::table('stock_units')
            ->selectRaw('min(dedupe_hmac_version) AS lo, max(dedupe_hmac_version) AS hi')
            ->first();

        // Kho rỗng: lo là null, không có gì để tính lại.
        return $range?->lo !== null && ((int) $range->lo !== $version || (int) $range->hi !== $version);
    }

    /**
     * Còn bản ghi nào chưa theo phiên bản khoá này không. Khoá backup không đụng tới dữ liệu trong
     * app nên không bao giờ có.
     */
    private static function hasStaleUnits(VersionedKey $key): bool
    {
        $column = $key->purpose->versionColumn();

        return $column !== null && DB::table('stock_units')->where($column, '<>', $key->version)->exists();
    }

    /**
     * @return int số Đơn vị hàng đã mã hoá lại
     */
    private function reencryptContent(VersionedKey $key): int
    {
        $column = (string) $key->purpose->versionColumn();

        return $this->rewriteStaleUnits($column, $key->version, function (array $ids) use ($key, $column): int {
            $units = DB::table('stock_units')
                ->whereIn('id', $ids)
                ->where($column, '<>', $key->version)
                ->lockForUpdate()
                ->get(['id', 'secret_ciphertext', 'secret_key_version']);

            foreach ($units as $unit) {
                $encrypted = $this->crypto->encrypt($this->crypto->decrypt(new EncryptedContent(
                    (string) $unit->secret_ciphertext,
                    (int) $unit->secret_key_version,
                )));

                // Không đụng updated_at: mã hoá lại không phải một thay đổi nghiệp vụ của hàng.
                DB::table('stock_units')->where('id', $unit->id)->update([
                    'secret_ciphertext' => $encrypted->ciphertext,
                    'secret_key_version' => $encrypted->keyVersion,
                ]);
            }

            return $units->count();
        });
    }

    /**
     * @return int số Đơn vị hàng đã tính lại hash Khoá chống trùng
     */
    private function recomputeDedupeHashes(VersionedKey $key): int
    {
        $column = (string) $key->purpose->versionColumn();

        return $this->rewriteStaleUnits($column, $key->version, function (array $ids) use ($key, $column): int {
            $units = StockUnit::query()
                ->whereIn('id', $ids)
                ->where($column, '<>', $key->version)
                ->with('product.contentFields')
                ->lockForUpdate()
                ->get();

            foreach ($units as $unit) {
                DB::table('stock_units')->where('id', $unit->getKey())->update([
                    'dedupe_hash' => $this->crypto->dedupeHash($this->dedupeValue($unit), $unit->product->normalization()),
                    $column => $key->version,
                ]);
            }

            return $units->count();
        });
    }

    /**
     * Duyệt các Đơn vị hàng còn ở phiên bản khoá cũ theo từng chunk, mỗi chunk một transaction dưới
     * khoá hàng, nên một lần xem mã hay một Lô nhập chạy song song không đọc phải bản ghi nửa vời.
     * Bản ghi không có gì để ghi lại mang NULL ở cột phiên bản, mà `NULL <> n` là NULL, nên tự rơi
     * ra khỏi điều kiện.
     *
     * @param  callable(list<int>): int  $rewrite  ghi lại một chunk, trả về số bản ghi thực sự ghi
     */
    private function rewriteStaleUnits(string $versionColumn, int $version, callable $rewrite): int
    {
        $rewritten = 0;

        while (true) {
            $ids = array_map(
                static fn (mixed $id): int => (int) $id,
                DB::table('stock_units')
                    ->where($versionColumn, '<>', $version)
                    ->orderBy('id')
                    ->limit(self::CHUNK)
                    ->pluck('id')
                    ->all(),
            );

            if ($ids === []) {
                return $rewritten;
            }

            $rewritten += DB::transaction(fn (): int => $rewrite($ids));
        }
    }

    /**
     * Giá trị Khoá chống trùng dạng rõ của một Đơn vị hàng: trường không nhạy cảm nằm sẵn trong
     * `content`, trường nhạy cảm phải giải mã. Đây là việc trong module Mã hoá chứ không phải một
     * lần xem mã, nên không đi qua Ngữ cảnh xem mã và không ghi Nhật ký xem mã.
     *
     * @throws KeyRotationFailed
     */
    private function dedupeValue(StockUnit $unit): string
    {
        $field = $unit->product->dedupeKeyField();

        $value = $field->sensitive
            ? ($this->crypto->decryptFields($unit->secret_ciphertext, $unit->secret_key_version)[$field->key] ?? '')
            : (($unit->content ?? [])[$field->key] ?? '');

        if ($value === '') {
            // Nhập hàng không bao giờ ghi Khoá chống trùng rỗng: dừng hẳn thay vì ghi một hash
            // rỗng trùng với mọi bản ghi hỏng khác.
            throw KeyRotationFailed::missingDedupeValue((int) $unit->getKey());
        }

        return $value;
    }
}
