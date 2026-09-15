<?php

namespace App\Inventory\Catalog;

/**
 * Lỗi nghiệp vụ: sửa cấu hình bị khoá của Sản phẩm đã có hàng. Hash Khoá chống trùng
 * và nội dung đã mã hoá của hàng cũ phụ thuộc vào cấu hình đó.
 */
class LockedProductConfiguration extends InvalidProductConfiguration {}
