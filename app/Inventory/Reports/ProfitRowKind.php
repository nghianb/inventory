<?php

namespace App\Inventory\Reports;

/**
 * Loại dòng của báo cáo Lãi/lỗ. Ngoài các dòng Sản phẩm, báo cáo còn một dòng tổng và một dòng gom
 * các Dòng xuất chưa ghi Giá bán — phần hàng đã rời kho nhưng chưa biết bán được bao nhiêu, nên
 * không vào doanh thu hay Lãi gộp của Sản phẩm nào.
 */
enum ProfitRowKind
{
    case Product;
    case Total;
    case Unpriced;
}
