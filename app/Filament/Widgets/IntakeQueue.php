<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Batches\BatchResource;
use App\Filament\Resources\SupplierClaims\SupplierClaimResource;
use App\Filament\Support\InventoryAction;
use App\Filament\Support\QueueStat;
use App\Inventory\Claims\SupplierClaims;
use App\Inventory\Claims\SupplierClaimStatus;
use App\Models\Batch;
use App\Models\SupplierClaim;
use Filament\Widgets\StatsOverviewWidget;

/**
 * Việc Nhập hàng còn dang dở, trên trang Tổng quan. Xem {@see OutboundQueue} về lý do tách theo công
 * việc chứ không theo Vai trò.
 */
class IntakeQueue extends StatsOverviewWidget
{
    protected static ?int $sort = 20;

    protected ?string $heading = 'Nhập hàng';

    public static function canView(): bool
    {
        return InventoryAction::actor()->can('viewAny', Batch::class);
    }

    public function mount(): void
    {
        abort_unless(self::canView(), 403);
    }

    protected function getStats(): array
    {
        return [
            // Ô nặng nhất: Lô nhập Chờ xác nhận *hết hạn*, quên là mất nội dung tạm và phải nhập lại
            // từ đầu. Đếm và link đi cùng một scope, nếu không thì ô ghi 0 còn danh sách mở ra đầy
            // dòng — đúng những lô đã quá hạn mà scope sinh ra để loại.
            QueueStat::make(
                'Lô nhập Chờ xác nhận',
                Batch::query()->awaitingConfirmation()->count(),
                BatchResource::getUrl('index', ['filters' => ['awaiting_confirmation' => ['isActive' => true]]]),
                description: 'Quá hạn xác nhận là mất nội dung tạm',
                color: 'danger',
            ),
            // Cùng query với bảng UnclaimedDefectiveUnits, nằm ngay trên trang đích.
            QueueStat::make(
                'Đơn vị hàng Lỗi chưa khiếu nại',
                app(SupplierClaims::class)->unclaimedDefectiveUnits()->count(),
                SupplierClaimResource::getUrl('index'),
                description: 'Gom lại thành Khiếu nại để đòi bồi hoàn',
            ),
            QueueStat::make(
                'Khiếu nại Nháp',
                SupplierClaim::query()->where('status', SupplierClaimStatus::Draft)->count(),
                self::claims(SupplierClaimStatus::Draft),
                description: 'Đã gom hàng, chưa gửi Nhà cung cấp',
            ),
            QueueStat::make(
                'Khiếu nại Đã gửi',
                SupplierClaim::query()->where('status', SupplierClaimStatus::Sent)->count(),
                self::claims(SupplierClaimStatus::Sent),
                description: 'Đang chờ Nhà cung cấp trả lời',
                color: 'gray',
            ),
        ];
    }

    private static function claims(SupplierClaimStatus $status): string
    {
        return SupplierClaimResource::getUrl('index', ['filters' => ['status' => ['value' => $status->value]]]);
    }
}
