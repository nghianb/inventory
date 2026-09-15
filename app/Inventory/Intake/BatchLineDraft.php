<?php

namespace App\Inventory\Intake;

use App\Models\Product;
use SensitiveParameter;

/**
 * Dòng nhập nhân viên gửi đi kiểm tra, cho đúng một Sản phẩm.
 *
 * - Dán văn bản: mỗi dòng một Đơn vị hàng, các Trường nội dung xếp theo thứ tự khai báo
 *   trên Sản phẩm, phân tách bằng `separator`. Sản phẩm một trường thì cả dòng là giá trị.
 * - File CSV/XLSX: bắt buộc dòng tiêu đề trùng tên (định danh hoặc tên hiển thị) Trường nội
 *   dung; cột `slot`, `han_su_dung`, `gia_von` ghi đè giá trị của Dòng nhập; cột khác bị bỏ qua.
 *
 * Giá trị ghi đè theo tầng Sản phẩm → Dòng nhập → cột file.
 */
final readonly class BatchLineDraft
{
    public const DEFAULT_SEPARATOR = "\t";

    /**
     * @param  int  $unitCost  Giá vốn mỗi Đơn vị hàng, VND
     * @param  string  $content  văn bản dán hoặc nội dung file
     * @param  ?int  $slots  số slot mỗi Tài khoản; null thì theo Sản phẩm
     */
    public function __construct(
        public Product $product,
        public int $unitCost,
        #[SensitiveParameter] public string $content,
        public string $separator = self::DEFAULT_SEPARATOR,
        public ?int $slots = null,
        public ?ExpiryRule $expiry = null,
        public IntakeSource $source = IntakeSource::Paste,
        public ?string $fileName = null,
    ) {}

    /**
     * Dòng nhập từ file đã upload; loại file theo đuôi tên file.
     *
     * @throws InvalidBatch
     */
    public static function file(
        Product $product,
        int $unitCost,
        #[SensitiveParameter] string $content,
        string $fileName,
        ?int $slots = null,
        ?ExpiryRule $expiry = null,
    ): self {
        $source = IntakeSource::fromFileName($fileName)
            ?? throw new InvalidBatch("File \"{$fileName}\" không phải CSV hoặc XLSX.");

        return new self($product, $unitCost, $content, ',', $slots, $expiry, $source, $fileName);
    }
}
