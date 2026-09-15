<?php

namespace App\Filament\Resources\Dispatches\Pages;

use App\Filament\Resources\Dispatches\DispatchResource;
use Filament\Resources\Pages\ViewRecord;

/**
 * Trang xem Phiếu xuất: thông tin đơn, Dòng xuất và Lần giao ở dạng che.
 */
class ViewDispatch extends ViewRecord
{
    protected static string $resource = DispatchResource::class;
}
