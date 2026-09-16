<?php

namespace App\Inventory\Dispatch;

use App\Inventory\Access\MissingRole;
use App\Inventory\Access\Role;
use App\Inventory\Access\RoleGate;
use App\Models\ApiKey;
use App\Models\Dispatch;
use App\Models\SalesChannel;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Danh mục Kênh bán: chỉ Quản trị khai báo, sửa, ẩn. Kênh không bao giờ bị xoá vì Phiếu xuất
 * tham chiếu tới nó; tên là duy nhất, không phân biệt hoa thường.
 */
class SalesChannelDirectory
{
    /**
     * Hạn Giữ hàng tối đa của một kênh API: một ngày. Giữ lâu hơn thì hàng nằm chết trong kho.
     */
    private const MAX_HOLD_MINUTES = 1_440;

    public function __construct(private RoleGate $roles) {}

    /**
     * @throws MissingRole
     * @throws InvalidSalesChannel
     */
    public function create(User $actor, SalesChannelDraft $draft): SalesChannel
    {
        $this->roles->authorize($actor, Role::QuanTri);

        return self::save(new SalesChannel, $draft);
    }

    /**
     * @throws MissingRole
     * @throws InvalidSalesChannel
     */
    public function update(User $actor, SalesChannel $channel, SalesChannelDraft $draft): SalesChannel
    {
        $this->roles->authorize($actor, Role::QuanTri);

        return self::save($channel, $draft);
    }

    /**
     * Ngừng dùng: kênh không còn chọn được khi tạo Phiếu xuất, và Khoá API của kênh hết gọi vào kho được.
     *
     * @throws MissingRole
     */
    public function hide(User $actor, SalesChannel $channel): void
    {
        $this->roles->authorize($actor, Role::QuanTri);

        $channel->forceFill(['hidden_at' => $channel->hidden_at ?? now()])->save();
    }

    /**
     * @throws MissingRole
     */
    public function unhide(User $actor, SalesChannel $channel): void
    {
        $this->roles->authorize($actor, Role::QuanTri);

        $channel->forceFill(['hidden_at' => null])->save();
    }

    /**
     * @throws InvalidSalesChannel
     */
    private static function save(SalesChannel $channel, SalesChannelDraft $draft): SalesChannel
    {
        $name = trim($draft->name);

        if ($name === '') {
            throw new InvalidSalesChannel('Tên Kênh bán không được để trống.');
        }

        $holdMinutes = $draft->holdMinutes ?? (int) config('inventory.api.hold_minutes');

        if ($holdMinutes < 1 || $holdMinutes > self::MAX_HOLD_MINUTES) {
            throw new InvalidSalesChannel(sprintf(
                'Hạn Giữ hàng phải từ 1 đến %s phút.',
                number_format(self::MAX_HOLD_MINUTES, 0, ',', '.'),
            ));
        }

        // Đổi loại sau khi kênh đã chạy sẽ để lại Phiếu xuất của loại cũ và Khoá API mồ côi.
        if ($channel->exists && $channel->type !== $draft->type && self::hasHistory($channel)) {
            throw new InvalidSalesChannel("Kênh bán \"{$channel->name}\" đã có Phiếu xuất hoặc Khoá API nên không đổi loại được.");
        }

        $taken = SalesChannel::query()
            ->whereRaw('lower(name) = lower(?)', [$name])
            ->when($channel->exists, fn ($query) => $query->whereKeyNot($channel->getKey()))
            ->exists();

        if ($taken) {
            throw self::nameTaken($name);
        }

        try {
            $channel->forceFill([
                'name' => $name,
                'type' => $draft->type,
                'requires_external_ref' => $draft->requiresExternalRef,
                'hold_minutes' => $holdMinutes,
                // Kênh API mặc định bắt buộc Giá bán để báo cáo Lãi/lỗ của đơn web không bị hổng.
                'requires_sale_price' => $draft->requiresSalePrice ?? ($draft->type === SalesChannelType::Api),
            ])->save();
        } catch (UniqueConstraintViolationException) {
            // Hai Quản trị cùng tạo một tên: unique index trên lower(name) bắt trường hợp đồng thời.
            throw self::nameTaken($name);
        }

        return $channel;
    }

    private static function hasHistory(SalesChannel $channel): bool
    {
        return Dispatch::query()->where('sales_channel_id', $channel->getKey())->exists()
            || ApiKey::query()->where('sales_channel_id', $channel->getKey())->exists();
    }

    private static function nameTaken(string $name): InvalidSalesChannel
    {
        return new InvalidSalesChannel("Kênh bán \"{$name}\" đã có.");
    }
}
