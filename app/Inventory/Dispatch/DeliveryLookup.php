<?php

namespace App\Inventory\Dispatch;

use App\Inventory\Access\MissingRole;
use App\Inventory\Access\Role;
use App\Inventory\Access\RoleGate;
use App\Inventory\Encryption\DedupeLookup;
use App\Inventory\Encryption\KeyFingerprintMismatch;
use App\Inventory\Encryption\KeyFingerprints;
use App\Models\Delivery;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use SensitiveParameter;

/**
 * Tra lần Giao hàng theo Khoá chống trùng khách gửi tới ({@see DedupeLookup}). Chỉ trả bản ghi,
 * không giải mã nên không ghi Nhật ký xem mã.
 */
class DeliveryLookup
{
    public function __construct(
        private RoleGate $roles,
        private KeyFingerprints $fingerprints,
        private DedupeLookup $dedupe,
    ) {}

    /**
     * @return Collection<int, Delivery> theo thứ tự giao
     *
     * @throws MissingRole
     * @throws KeyFingerprintMismatch
     */
    public function byDedupeKey(User $actor, #[SensitiveParameter] string $value): Collection
    {
        $this->roles->authorize($actor, Role::BanHang);
        // Sai khoá HMAC thì mọi hash lệch và tra cứu âm thầm không thấy gì: báo lỗi thay vì trả rỗng.
        $this->fingerprints->verify();

        $units = $this->dedupe->matchingUnits($value);

        if ($units === null) {
            return new Collection;
        }

        return Delivery::query()
            ->whereIn('stock_unit_id', $units->select('stock_units.id'))
            ->with(['dispatchLine.dispatch.salesChannel', 'dispatchLine.product', 'slot', 'stockUnit'])
            ->orderBy('id')
            ->get();
    }
}
