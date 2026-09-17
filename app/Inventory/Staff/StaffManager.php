<?php

namespace App\Inventory\Staff;

use App\Inventory\Access\MissingRole;
use App\Inventory\Access\Role;
use App\Inventory\Access\RoleGate;
use App\Inventory\Security\SecurityEvent;
use App\Inventory\Security\SecurityLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SensitiveParameter;
use Spatie\Permission\Models\Role as RoleModel;

/**
 * Quản lý nhân viên: chỉ Quản trị tạo nhân viên, đổi Vai trò, Khoá nhân viên và
 * reset 2FA. Ngoại lệ duy nhất là Quản trị đầu tiên của kho, do lệnh artisan trên
 * server tạo lúc chưa có Quản trị nào để mà cho phép. Mỗi thao tác ghi Nhật ký bảo
 * mật. Không có thao tác xoá nhân viên. Kho luôn còn ít nhất một Quản trị đang hoạt động.
 */
class StaffManager
{
    public function __construct(private RoleGate $roles, private SecurityLog $log) {}

    /**
     * @param  list<Role>  $roles
     *
     * @throws MissingRole
     */
    public function create(User $actor, string $name, string $email, #[SensitiveParameter] string $password, array $roles): User
    {
        $this->roles->authorize($actor, Role::Owner);

        return DB::transaction(function () use ($actor, $name, $email, $password, $roles): User {
            $staff = User::create(['name' => $name, 'email' => $email, 'password' => $password]);
            $staff->syncRoles($roles);

            $this->log->record(SecurityEvent::StaffCreated, $staff, actor: $actor, details: [
                'roles' => self::roleNames($roles),
            ]);

            return $staff;
        });
    }

    /**
     * Kho đã có Quản trị chưa, kể cả Quản trị đang bị Khoá nhân viên. Để lệnh artisan từ
     * chối sớm, trước khi bắt người vận hành gõ gì; luật thật vẫn nằm ở
     * {@see self::createFirstOwner()}, nơi có khoá chống hai lần chạy song song.
     */
    public function hasOwner(): bool
    {
        return User::role(Role::Owner->value)->exists();
    }

    /**
     * Tạo Quản trị đầu tiên của kho. Chỉ gọi từ lệnh artisan trên server: kho chưa có
     * Quản trị nào nên không có ai trong app thực hiện được, actor để trống.
     *
     * @throws OwnerAlreadyExists
     */
    public function createFirstOwner(string $name, string $email, #[SensitiveParameter] string $password): User
    {
        return DB::transaction(function () use ($name, $email, $password): User {
            $this->ensureNoOwner();

            $owner = User::create(['name' => $name, 'email' => $email, 'password' => $password]);
            $owner->syncRoles([Role::Owner]);

            $this->log->record(SecurityEvent::StaffCreated, $owner, details: [
                'roles' => self::roleNames([Role::Owner]),
                'via' => 'artisan',
            ]);

            return $owner;
        });
    }

    /**
     * Thay toàn bộ Vai trò của nhân viên bằng danh sách mới.
     *
     * @param  list<Role>  $roles
     *
     * @throws MissingRole
     * @throws LastActiveOwner
     */
    public function changeRoles(User $actor, User $staff, array $roles): void
    {
        $this->roles->authorize($actor, Role::Owner);

        DB::transaction(function () use ($actor, $staff, $roles): void {
            if (! in_array(Role::Owner, $roles, true)) {
                $this->ensureNotLastActiveOwner($staff);
            }

            $from = self::roleNames($staff->roles()->pluck('name')->map(fn (string $name): Role => Role::from($name))->all());

            $staff->syncRoles($roles);

            $this->log->record(SecurityEvent::RolesChanged, $staff, actor: $actor, details: [
                'from' => $from,
                'to' => self::roleNames($roles),
            ]);
        });
    }

    /**
     * Khoá nhân viên có hiệu lực ngay: phiên đang mở bị cắt ở request kế tiếp
     * (middleware Authenticate của panel) và cookie "ghi nhớ" cũ mất hiệu lực.
     *
     * @throws MissingRole
     * @throws LastActiveOwner
     */
    public function deactivate(User $actor, User $staff): void
    {
        $this->roles->authorize($actor, Role::Owner);

        DB::transaction(function () use ($actor, $staff): void {
            $this->ensureNotLastActiveOwner($staff);

            $staff->forceFill([
                'deactivated_at' => now(),
                'remember_token' => Str::random(60),
            ])->save();

            $this->log->record(SecurityEvent::StaffDeactivated, $staff, actor: $actor);
        });
    }

    /**
     * @throws MissingRole
     */
    public function reactivate(User $actor, User $staff): void
    {
        $this->roles->authorize($actor, Role::Owner);

        DB::transaction(fn () => $this->unlock($staff, actor: $actor));
    }

    /**
     * Xoá 2FA hiện có; nhân viên phải thiết lập lại ngay ở lần vào panel kế tiếp.
     *
     * @throws MissingRole
     */
    public function resetTwoFactor(User $actor, User $staff): void
    {
        $this->roles->authorize($actor, Role::Owner);

        DB::transaction(fn () => $this->clearTwoFactor($staff, actor: $actor));
    }

    /**
     * Khôi phục quyền truy cập cho Quản trị tự khoá mình ngoài hệ thống. Chỉ gọi từ
     * lệnh artisan trên server: không có Quản trị nào thực hiện nên actor để trống.
     *
     * @throws MissingRole nếu nhân viên không mang Vai trò Quản trị
     */
    public function recoverOwnerAccess(User $owner, bool $reactivate, bool $resetTwoFactor): void
    {
        if (! $owner->hasRole(Role::Owner)) {
            throw new MissingRole($owner, [Role::Owner]);
        }

        DB::transaction(function () use ($owner, $reactivate, $resetTwoFactor): void {
            $details = ['via' => 'artisan'];

            if ($reactivate) {
                $this->unlock($owner, details: $details);
            }

            if ($resetTwoFactor) {
                $this->clearTwoFactor($owner, details: $details);
            }
        });
    }

    /**
     * @param  array<string, string>  $details
     */
    private function unlock(User $staff, ?User $actor = null, array $details = []): void
    {
        $staff->forceFill(['deactivated_at' => null])->save();

        $this->log->record(SecurityEvent::StaffReactivated, $staff, actor: $actor, details: $details);
    }

    /**
     * @param  array<string, string>  $details
     */
    private function clearTwoFactor(User $staff, ?User $actor = null, array $details = []): void
    {
        $staff->forceFill([
            'app_authentication_secret' => null,
            'app_authentication_recovery_codes' => null,
        ])->save();

        $this->log->record(SecurityEvent::TwoFactorReset, $staff, actor: $actor, details: $details);
    }

    /**
     * Quản trị bị khoá vẫn tính là đã có: kho chỉ thiếu Quản trị đúng một lần, ngay sau
     * khi cài. Phải gọi trong transaction.
     *
     * @throws OwnerAlreadyExists
     */
    private function ensureNoOwner(): void
    {
        $this->lockOwnerRole();

        if ($this->hasOwner()) {
            throw new OwnerAlreadyExists;
        }
    }

    /**
     * Phải gọi trong transaction.
     *
     * @throws LastActiveOwner
     */
    private function ensureNotLastActiveOwner(User $staff): void
    {
        $this->lockOwnerRole();

        $activeOwnerIds = User::role(Role::Owner->value)
            ->whereNull('deactivated_at')
            ->pluck('id');

        if ($activeOwnerIds->contains($staff->getKey()) && $activeOwnerIds->count() === 1) {
            throw new LastActiveOwner($staff);
        }
    }

    /**
     * Mọi thao tác đụng tới số Quản trị của kho khoá cùng một hàng (Vai trò Quản trị) nên
     * chạy tuần tự; đếm sau khi có khoá mới thấy thay đổi vừa commit của thao tác trước.
     * Phải gọi trong transaction.
     */
    private function lockOwnerRole(): void
    {
        RoleModel::query()
            ->where('name', Role::Owner->value)
            ->lockForUpdate()
            ->first();
    }

    /**
     * Tên Vai trò theo thứ tự khai báo, để nhật ký không phụ thuộc thứ tự chọn.
     *
     * @param  array<Role>  $roles
     * @return list<string>
     */
    private static function roleNames(array $roles): array
    {
        return array_values(array_map(
            fn (Role $role): string => $role->value,
            array_filter(Role::cases(), fn (Role $role): bool => in_array($role, $roles, true)),
        ));
    }
}
