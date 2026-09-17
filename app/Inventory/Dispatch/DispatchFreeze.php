<?php

namespace App\Inventory\Dispatch;

use App\Inventory\Access\MissingRole;
use App\Inventory\Access\RoleGate;
use App\Inventory\Security\SecurityEvent;
use App\Inventory\Security\SecurityLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Tạm dừng xuất kho: trạng thái toàn kho trong đó không Kênh bán nào, kể cả kênh API, Giữ hàng hay
 * Giao hàng được. Chỉ Quản trị bật hoặc tắt, kèm lý do, và mỗi lần ghi Nhật ký bảo mật. Kho cũng vào
 * trạng thái này sau khi khôi phục từ backup (lệnh `inventory:dispatches:freeze`), lúc chưa có ai
 * đăng nhập.
 *
 * Nhập hàng, Huỷ hàng và Ghi nhận giao bù vẫn chạy khi đang tạm dừng: không cái nào lấy thêm hàng ra
 * khỏi kho. Ghi nhận giao bù còn là việc *phải* làm được lúc này, vì nó ghi lại đúng các lần giao đã
 * mất khi khôi phục.
 */
class DispatchFreeze
{
    /** Bảng một hàng; xem migration create_dispatch_freeze_table. */
    private const TABLE = 'dispatch_freeze';

    private const ROW = 1;

    public function __construct(private RoleGate $roles, private SecurityLog $log) {}

    /**
     * Chặn một lần Giữ hàng hoặc Giao hàng khi kho đang tạm dừng. Người gọi chạy trong transaction:
     * khoá chia sẻ giữ cờ đứng yên tới lúc lần giao commit, nên bật tạm dừng không cắt ngang lần
     * giao đang chạy dở, và lần giao bắt đầu sau đó luôn thấy cờ đã bật.
     *
     * @throws DispatchFrozen
     */
    public static function guard(): void
    {
        $row = self::row()->sharedLock()->first(['frozen_at', 'reason']);

        if ($row?->frozen_at !== null) {
            throw new DispatchFrozen((string) $row->reason);
        }
    }

    /**
     * Trạng thái lúc này, không khoá gì. Chỉ để hiển thị.
     */
    public function state(): FreezeState
    {
        $row = self::row()->first(['frozen_at', 'reason', 'actor_id']);

        if ($row?->frozen_at === null) {
            return new FreezeState;
        }

        return new FreezeState(
            frozenAt: CarbonImmutable::parse((string) $row->frozen_at),
            reason: (string) $row->reason,
            actor: $row->actor_id === null ? null : User::query()->find($row->actor_id),
        );
    }

    /**
     * Quản trị bật Tạm dừng xuất kho kèm lý do.
     *
     * @throws MissingRole
     * @throws InvalidDispatch
     */
    public function freeze(User $actor, string $reason): void
    {
        $this->roles->authorize($actor);
        $reason = self::requiredReason($reason, 'Bật Tạm dừng xuất kho phải nhập lý do.');

        DB::transaction(function () use ($actor, $reason): void {
            if (! $this->apply($actor, $reason)) {
                throw new InvalidDispatch([new DispatchProblem('Kho đã đang Tạm dừng xuất kho.')]);
            }
        });
    }

    /**
     * Quản trị tắt Tạm dừng xuất kho kèm lý do (thường là "đã đối chiếu xong").
     *
     * @throws MissingRole
     * @throws InvalidDispatch
     */
    public function unfreeze(User $actor, string $reason): void
    {
        $this->roles->authorize($actor);
        $reason = self::requiredReason($reason, 'Tắt Tạm dừng xuất kho phải nhập lý do.');

        DB::transaction(function () use ($actor, $reason): void {
            if (self::lock()?->frozen_at === null) {
                throw new InvalidDispatch([new DispatchProblem('Kho không ở trạng thái Tạm dừng xuất kho.')]);
            }

            self::write(null, null, null);
            // Lý do tắt chỉ có chỗ trong nhật ký: bảng chỉ giữ trạng thái hiện tại.
            $this->log->record(SecurityEvent::DispatchUnfrozen, actor: $actor, details: ['reason' => $reason]);
        });
    }

    /**
     * Lệnh trên server bật Tạm dừng xuất kho sau khi khôi phục từ backup: không có nhân viên nào
     * thực hiện, nên dòng Nhật ký bảo mật không mang người thực hiện.
     *
     * @return bool false khi kho đã đang tạm dừng sẵn
     */
    public function freezeFromServer(string $reason): bool
    {
        return DB::transaction(fn (): bool => $this->apply(null, $reason));
    }

    /**
     * Bật cờ và ghi Nhật ký bảo mật, trong transaction của người gọi.
     *
     * @return bool false khi kho đã đang tạm dừng: không ghi đè lý do cũ, vì lý do đầu tiên mới là
     *              lý do kho dừng
     */
    private function apply(?User $actor, string $reason): bool
    {
        if (self::lock()?->frozen_at !== null) {
            return false;
        }

        self::write(CarbonImmutable::now(), $reason, $actor);
        $this->log->record(SecurityEvent::DispatchFrozen, actor: $actor, details: ['reason' => $reason]);

        return true;
    }

    /**
     * Hàng cờ, đã khoá độc quyền: hai lần bật/tắt chạy lần lượt, và lệnh bật chờ mọi lần giao đang
     * chạy dở (chúng giữ khoá chia sẻ trên chính hàng này) commit xong.
     */
    private static function lock(): ?stdClass
    {
        return self::row()->lockForUpdate()->first(['frozen_at']);
    }

    private static function write(?CarbonImmutable $frozenAt, ?string $reason, ?User $actor): void
    {
        self::row()->update([
            'frozen_at' => $frozenAt,
            'reason' => $reason,
            'actor_id' => $actor?->getKey(),
            'updated_at' => now(),
        ]);
    }

    private static function row(): Builder
    {
        return DB::table(self::TABLE)->where('id', self::ROW);
    }

    /**
     * @throws InvalidDispatch
     */
    private static function requiredReason(string $reason, string $message): string
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidDispatch([new DispatchProblem($message)]);
        }

        return $reason;
    }
}
