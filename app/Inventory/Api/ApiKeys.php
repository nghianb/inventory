<?php

namespace App\Inventory\Api;

use App\Inventory\Access\MissingRole;
use App\Inventory\Access\Role;
use App\Inventory\Access\RoleGate;
use App\Inventory\Dispatch\SalesChannelType;
use App\Inventory\Security\SecurityEvent;
use App\Inventory\Security\SecurityLog;
use App\Models\ApiKey;
use App\Models\SalesChannel;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SensitiveParameter;

/**
 * Khoá API của các Kênh bán loại API: chỉ Quản trị tạo, xoay và thu hồi, và mỗi thao tác ghi
 * Nhật ký bảo mật không kèm giá trị khoá. Bí mật chỉ hiện một lần lúc tạo; kho lưu SHA-256 của
 * nó, đủ nhanh để tra mỗi request và không dựng lại được giá trị gốc (bí mật là 48 ký tự ngẫu
 * nhiên nên không sợ dò từ điển như mật khẩu).
 */
class ApiKeys
{
    /**
     * Số Khoá API cùng hoạt động của một Kênh bán: hai, vừa đủ để xoay khoá mà website không đứt.
     */
    private const MAX_ACTIVE = 2;

    private const SECRET_LENGTH = 48;

    private const PREFIX_LENGTH = 8;

    public function __construct(private RoleGate $roles, private SecurityLog $log) {}

    /**
     * Khoá mới cho một Kênh bán loại API. Giá trị khoá trong kết quả là lần duy nhất kho đọc được nó.
     *
     * @throws MissingRole
     * @throws InvalidApiKey
     */
    public function issue(User $actor, SalesChannel $channel, ?string $label = null): IssuedApiKey
    {
        $this->roles->authorize($actor, Role::QuanTri);

        return $this->add($actor, $channel, $label, null, SecurityEvent::ApiKeyCreated);
    }

    /**
     * Xoay khoá: cấp khoá mới bên cạnh khoá cũ. Hai khoá cùng hoạt động cho tới khi Quản trị thu hồi
     * khoá cũ, để website đổi khoá xong mới cắt.
     *
     * @throws MissingRole
     * @throws InvalidApiKey
     */
    public function rotate(User $actor, ApiKey $key, ?string $label = null): IssuedApiKey
    {
        $this->roles->authorize($actor, Role::QuanTri);

        if ($key->isRevoked()) {
            throw new InvalidApiKey('Khoá API đã thu hồi.');
        }

        return $this->add($actor, $key->salesChannel()->firstOrFail(), $label ?? $key->label, $key, SecurityEvent::ApiKeyRotated);
    }

    /**
     * Thu hồi: khoá hết gọi vào kho được ngay. Bản ghi ở lại vì Nhật ký xem mã và Phiếu xuất
     * tham chiếu tới nó.
     *
     * @throws MissingRole
     * @throws InvalidApiKey
     */
    public function revoke(User $actor, ApiKey $key): ApiKey
    {
        $this->roles->authorize($actor, Role::QuanTri);

        return DB::transaction(function () use ($actor, $key): ApiKey {
            $current = ApiKey::query()->lockForUpdate()->findOrFail($key->getKey());

            if ($current->isRevoked()) {
                throw new InvalidApiKey('Khoá API đã thu hồi.');
            }

            $current->forceFill(['revoked_at' => now(), 'revoked_by' => $actor->getKey()])->save();
            $this->record(SecurityEvent::ApiKeyRevoked, $actor, $current);

            return $current;
        });
    }

    /**
     * Khoá API đứng sau một bí mật website gửi tới, hoặc null khi khoá sai, đã thu hồi, hay Kênh bán
     * đã ngừng dùng. Ghi lại lần dùng gần nhất để Quản trị thấy khoá nào còn sống.
     */
    public function authenticate(#[SensitiveParameter] string $secret): ?ApiKey
    {
        $key = ApiKey::query()
            ->active()
            ->with('salesChannel')
            ->where('key_hash', self::hash($secret))
            ->first();

        if ($key === null || $key->salesChannel->isHidden()) {
            return null;
        }

        // Ghi thưa: Quản trị chỉ cần biết khoá còn sống hay không, nên không đáng một UPDATE mỗi request.
        if ($key->last_used_at === null || $key->last_used_at->addMinute()->isPast()) {
            $key->forceFill(['last_used_at' => now()])->save();
        }

        return $key;
    }

    /**
     * Khoá còn hoạt động của một Kênh bán, cũ trước.
     *
     * @return Collection<int, ApiKey>
     */
    public function active(SalesChannel $channel): Collection
    {
        return ApiKey::query()
            ->active()
            ->where('sales_channel_id', $channel->getKey())
            ->orderBy('id')
            ->get();
    }

    /**
     * @throws InvalidApiKey
     */
    private function add(User $actor, SalesChannel $channel, ?string $label, ?ApiKey $rotates, SecurityEvent $event): IssuedApiKey
    {
        $label = trim((string) $label) === '' ? null : trim((string) $label);
        $secret = Str::random(self::SECRET_LENGTH);

        return DB::transaction(function () use ($actor, $channel, $label, $rotates, $event, $secret): IssuedApiKey {
            // Khoá hàng Kênh bán: hai Quản trị cùng tạo khoá không vượt được giới hạn khoá hoạt động.
            $current = SalesChannel::query()->lockForUpdate()->findOrFail($channel->getKey());

            if ($current->type !== SalesChannelType::Api) {
                throw new InvalidApiKey("Kênh bán \"{$current->name}\" không phải loại API nên không có Khoá API.");
            }

            if ($current->isHidden()) {
                throw new InvalidApiKey("Kênh bán \"{$current->name}\" đã ngừng dùng.");
            }

            if ($this->active($current)->count() >= self::MAX_ACTIVE) {
                throw new InvalidApiKey(sprintf(
                    'Kênh bán "%s" đã có %d Khoá API hoạt động; thu hồi bớt trước khi tạo thêm.',
                    $current->name,
                    self::MAX_ACTIVE,
                ));
            }

            $key = new ApiKey;
            $key->forceFill([
                'sales_channel_id' => $current->getKey(),
                'label' => $label,
                'key_hash' => self::hash($secret),
                'prefix' => substr($secret, 0, self::PREFIX_LENGTH),
                'created_by' => $actor->getKey(),
                'rotates_api_key_id' => $rotates?->getKey(),
            ])->save();

            $this->record($event, $actor, $key);

            return new IssuedApiKey($key, $secret);
        });
    }

    /**
     * Nhật ký bảo mật của một thao tác Khoá API. Không bao giờ chứa giá trị khoá.
     */
    private function record(SecurityEvent $event, User $actor, ApiKey $key): void
    {
        $this->log->record($event, actor: $actor, details: [
            'api_key_id' => $key->id,
            'sales_channel' => $key->salesChannel->name,
            'label' => $key->label,
        ]);
    }

    private static function hash(#[SensitiveParameter] string $secret): string
    {
        return hash('sha256', $secret);
    }
}
