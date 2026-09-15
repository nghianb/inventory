<?php

namespace App\Inventory\Intake;

use App\Models\Product;
use SensitiveParameter;

/**
 * Dòng nhập dạng dán văn bản: mỗi dòng một Đơn vị hàng, các Trường nội dung xếp theo
 * thứ tự khai báo trên Sản phẩm, phân tách bằng `separator`. Sản phẩm một trường thì
 * cả dòng là giá trị.
 */
final readonly class BatchLineDraft
{
    public const DEFAULT_SEPARATOR = "\t";

    /**
     * @param  int  $unitCost  Giá vốn mỗi Đơn vị hàng, VND
     */
    public function __construct(
        public Product $product,
        public int $unitCost,
        #[SensitiveParameter] public string $content,
        public string $separator = self::DEFAULT_SEPARATOR,
    ) {}
}
