<?php

namespace App\Inventory\Encryption;

use App\Inventory\Security\SecurityEvent;
use App\Inventory\Security\SecurityLog;
use Illuminate\Support\Facades\DB;

/**
 * Đối chiếu khoá mã hoá trong môi trường với dấu vân tay lưu trong DB, để một khoá sai
 * (gõ nhầm .env, khôi phục DB với bộ khoá khác) không bao giờ ghi hỏng dữ liệu. Tiến
 * trình ghi (nhập, xuất) gọi verify() trước khi chạy.
 */
final class KeyFingerprints
{
    private const TABLE = 'encryption_key_fingerprints';

    private const FINGERPRINT_MESSAGE = 'inventory:key-fingerprint';

    private const UNREGISTERED = 'chưa đăng ký dấu vân tay trong DB';

    private const MISMATCHED = 'dấu vân tay không khớp với DB';

    public function __construct(private KeyRing $keys, private SecurityLog $log) {}

    /**
     * Đăng ký dấu vân tay của các khoá đang cấu hình mà DB chưa có. Không ghi đè dấu vân
     * tay đã có; mỗi khoá mới đăng ký ghi một dòng Nhật ký bảo mật.
     *
     * @param  ?KeyPurpose  $only  chỉ đăng ký một loại khoá, để lệnh xoay khoá không đăng ký nhầm
     *                             phiên bản mới của loại khoá khác đang dở dang trong `.env`
     * @return list<VersionedKey> các khoá vừa đăng ký
     *
     * @throws KeyFingerprintMismatch một khoá khác cùng loại và phiên bản đã được đăng ký
     * @throws InvalidKeyConfiguration
     */
    public function register(?KeyPurpose $only = null): array
    {
        $keys = [];

        foreach ($only === null ? KeyPurpose::cases() : [$only] as $purpose) {
            array_push($keys, $this->keys->current($purpose), ...$this->keys->previous($purpose));
        }

        return DB::transaction(function () use ($keys): array {
            $registered = $this->registered();
            $problems = array_map(fn (VersionedKey $key) => self::problem($registered, $key), $keys);

            if (in_array(self::MISMATCHED, $problems, true)) {
                throw new KeyFingerprintMismatch(self::describeAll($keys, $problems, self::MISMATCHED));
            }

            $new = array_values(array_filter($keys, fn (VersionedKey $key, int $i) => $problems[$i] === self::UNREGISTERED, ARRAY_FILTER_USE_BOTH));

            foreach ($new as $key) {
                DB::table(self::TABLE)->insert([
                    'purpose' => $key->purpose->value,
                    'version' => $key->version,
                    'fingerprint' => self::fingerprint($key),
                    'registered_at' => now(),
                ]);

                $this->log->record(SecurityEvent::KeyFingerprintRegistered, details: [
                    'purpose' => $key->purpose->value,
                    'version' => $key->version,
                ]);
            }

            return $new;
        });
    }

    /**
     * @throws KeyFingerprintMismatch
     */
    public function verify(): void
    {
        $registered = $this->registered();
        $problems = [];

        foreach (KeyPurpose::cases() as $purpose) {
            try {
                $current = $this->keys->current($purpose);
                $keys = [$current, ...$this->keys->previous($purpose)];
            } catch (InvalidKeyConfiguration $exception) {
                $problems[] = $exception->getMessage();

                continue;
            }

            foreach ($keys as $key) {
                if (($problem = self::problem($registered, $key)) !== null) {
                    $problems[] = "{$key->describe()}: {$problem}";
                }
            }

            $latest = max([0, ...array_keys($registered[$purpose->value] ?? [])]);

            if ($latest > $current->version) {
                $problems[] = "{$purpose->label()}: môi trường dùng phiên bản {$current->version} nhưng DB đã đăng ký phiên bản {$latest}";
            }
        }

        if ($problems !== []) {
            throw new KeyFingerprintMismatch($problems);
        }
    }

    /**
     * Phiên bản mới nhất của một loại khoá đã đăng ký trong DB, hoặc null khi chưa có phiên bản
     * nào. Lệnh xoay khoá dùng để ghi "phiên bản cũ" vào Nhật ký bảo mật.
     */
    public function latestVersion(KeyPurpose $purpose): ?int
    {
        $versions = array_keys($this->registered()[$purpose->value] ?? []);

        return $versions === [] ? null : max($versions);
    }

    /**
     * @return array<string, array<int, string>> dấu vân tay theo loại khoá rồi phiên bản
     */
    private function registered(): array
    {
        $registered = [];

        foreach (DB::table(self::TABLE)->get(['purpose', 'version', 'fingerprint']) as $row) {
            $registered[(string) $row->purpose][(int) $row->version] = (string) $row->fingerprint;
        }

        return $registered;
    }

    /**
     * @param  array<string, array<int, string>>  $registered
     */
    private static function problem(array $registered, VersionedKey $key): ?string
    {
        $stored = $registered[$key->purpose->value][$key->version] ?? null;

        return match (true) {
            $stored === null => self::UNREGISTERED,
            ! hash_equals($stored, self::fingerprint($key)) => self::MISMATCHED,
            default => null,
        };
    }

    /**
     * @param  list<VersionedKey>  $keys
     * @param  list<?string>  $problems
     * @return list<string>
     */
    private static function describeAll(array $keys, array $problems, string $problem): array
    {
        return array_map(
            fn (int $i) => "{$keys[$i]->describe()}: {$problem}",
            array_keys(array_filter($problems, fn (?string $found) => $found === $problem)),
        );
    }

    /**
     * HMAC của một chuỗi cố định bằng chính khoá: nhận ra khoá mà không lộ giá trị khoá, nên
     * ghi được vào Nhật ký bảo mật.
     *
     * @internal chỉ module Mã hoá dùng; nhận VersionedKey nên ngoài module cũng không gọi được.
     */
    public static function fingerprint(VersionedKey $key): string
    {
        return hash_hmac('sha256', self::FINGERPRINT_MESSAGE, $key->material);
    }
}
