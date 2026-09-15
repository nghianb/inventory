<?php

namespace App\Inventory\Intake;

use App\Inventory\Encryption\ContentCrypto;
use App\Inventory\Encryption\EncryptedContent;
use App\Models\BatchLine;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use SensitiveParameter;

/**
 * Nội dung Dòng nhập chờ xác nhận (văn bản dán hoặc file gốc), mã hoá bằng khoá nội dung,
 * nằm trên ổ local của server (disk `inventory.intake.disk`), không trong DB nên không vào
 * backup. Xoá khi Lô nhập được xác nhận hoặc bỏ, hoặc khi bản kiểm tra hết hạn.
 */
class PendingContentStore
{
    public function __construct(private ContentCrypto $crypto) {}

    public function put(BatchLine $line, #[SensitiveParameter] string $content): void
    {
        $encrypted = $this->crypto->encrypt($content);

        self::disk()->put(self::path($line), $encrypted->keyVersion.':'.$encrypted->ciphertext);
    }

    /**
     * @throws InvalidBatch nội dung tạm đã bị xoá
     */
    public function get(BatchLine $line): string
    {
        $stored = self::disk()->exists(self::path($line)) ? self::disk()->get(self::path($line)) : null;

        if ($stored === null || ! str_contains($stored, ':')) {
            throw new InvalidBatch('Nội dung tạm của Lô nhập đã bị xoá (đã bỏ hoặc hết hạn); hãy tạo lại Lô nhập.');
        }

        [$version, $ciphertext] = explode(':', $stored, 2);

        return $this->crypto->decrypt(new EncryptedContent($ciphertext, (int) $version));
    }

    public function forget(BatchLine $line): void
    {
        self::disk()->delete(self::path($line));
    }

    /**
     * Xoá nội dung tạm không thuộc Dòng nhập nào còn chờ xác nhận. Chỉ xoá file ghi trước mốc
     * `writtenBefore`, để không đụng file của Lô nhập đang gửi mà transaction chưa commit.
     *
     * @param  list<int>  $pendingLineIds
     */
    public function purgeExcept(array $pendingLineIds, CarbonImmutable $writtenBefore): void
    {
        $keep = array_fill_keys($pendingLineIds, true);

        foreach (self::disk()->files('batch-lines') as $path) {
            if (! isset($keep[(int) basename($path)]) && self::disk()->lastModified($path) < $writtenBefore->getTimestamp()) {
                self::disk()->delete($path);
            }
        }
    }

    private static function path(BatchLine $line): string
    {
        return "batch-lines/{$line->id}";
    }

    private static function disk(): Filesystem
    {
        return Storage::disk((string) config('inventory.intake.disk'));
    }
}
