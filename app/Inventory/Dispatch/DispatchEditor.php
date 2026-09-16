<?php

namespace App\Inventory\Dispatch;

use App\Inventory\Access\MissingRole;
use App\Inventory\Access\Role;
use App\Inventory\Access\RoleGate;
use App\Models\Dispatch;
use App\Models\DispatchLine;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Sửa thông tin Phiếu xuất Hoàn tất: mã đơn ngoài, khách, ghi chú và Giá bán từng Dòng xuất. Kênh
 * bán, Dòng xuất và Slot không sửa được. Mã đơn ngoài mới phải chưa bị phiếu khác chiếm; mã cũ vẫn
 * bị phiếu này chiếm vĩnh viễn. Mỗi trường đổi giá trị ghi một dòng lịch sử sửa phiếu trong cùng
 * transaction; không đụng Sổ biến động kho.
 */
class DispatchEditor
{
    public function __construct(private RoleGate $roles) {}

    /**
     * @throws MissingRole
     * @throws InvalidDispatch
     */
    public function edit(User $actor, Dispatch $dispatch, DispatchEdit $edit): Dispatch
    {
        $this->roles->authorize($actor, Role::BanHang);

        $ref = trim((string) $edit->externalRef);

        return DB::transaction(function () use ($actor, $dispatch, $edit, $ref): Dispatch {
            $current = Dispatch::query()->with(['salesChannel', 'lines.product'])->lockForUpdate()->findOrFail($dispatch->getKey());

            if ($current->status !== DispatchStatus::Completed) {
                throw new InvalidDispatch([new DispatchProblem('Chỉ sửa được Phiếu xuất Hoàn tất.')]);
            }

            $problems = self::problems($current, $edit, $ref);

            if ($problems !== []) {
                throw new InvalidDispatch($problems);
            }

            // Phiếu khác có thể vừa chiếm đúng mã này giữa lúc kiểm tra và lúc lưu.
            if (! ExternalRefs::claim($current->sales_channel_id, $ref, $current->id)) {
                throw new InvalidDispatch([ExternalRefs::taken($current->salesChannel, $ref)]);
            }

            $revisions = [];
            $trackChange = function (DispatchRevisionField $field, int|string|null $old, int|string|null $new, ?DispatchLine $line = null) use (&$revisions): void {
                if ($old !== $new) {
                    $revisions[] = [
                        'dispatch_line_id' => $line?->id,
                        'field' => $field->value,
                        'old_value' => $old === null ? null : (string) $old,
                        'new_value' => $new === null ? null : (string) $new,
                    ];
                }
            };

            $customer = self::blankToNull($edit->customer);
            $note = self::blankToNull($edit->note);

            $trackChange(DispatchRevisionField::ExternalRef, $current->external_ref, $ref);
            $trackChange(DispatchRevisionField::Customer, $current->customer, $customer);
            $trackChange(DispatchRevisionField::Note, $current->note, $note);
            $current->forceFill(['external_ref' => $ref, 'customer' => $customer, 'note' => $note])->save();

            foreach ($edit->salePrices as $lineId => $price) {
                $line = $current->lines->firstOrFail('id', $lineId);
                $trackChange(DispatchRevisionField::SalePrice, $line->sale_price, $price, $line);
                $line->forceFill(['sale_price' => $price])->save();
            }

            $now = now();

            DB::table('dispatch_revisions')->insert(array_map(fn (array $revision): array => [
                ...$revision,
                'dispatch_id' => $current->id,
                'actor_id' => $actor->getKey(),
                'occurred_at' => $now,
            ], $revisions));

            return Dispatch::query()->findOrFail($current->id);
        });
    }

    /**
     * Nhân viên có sửa được phiếu này lúc này không: Bán hàng, phiếu Hoàn tất. Để panel ẩn nút sửa,
     * không thay cho kiểm tra trong {@see edit()}.
     */
    public function canEdit(User $actor, Dispatch $dispatch): bool
    {
        return $dispatch->status === DispatchStatus::Completed && $this->roles->allows($actor, Role::BanHang);
    }

    /**
     * @return list<DispatchProblem>
     */
    private static function problems(Dispatch $dispatch, DispatchEdit $edit, string $ref): array
    {
        $problems = [];

        if ($ref === '') {
            $problems[] = new DispatchProblem('Mã đơn ngoài không được để trống.');
        } elseif (($problem = ExternalRefs::problem($dispatch->salesChannel, $ref, $dispatch->id)) !== null) {
            $problems[] = $problem;
        }

        foreach ($edit->salePrices as $lineId => $price) {
            $line = $dispatch->lines->firstWhere('id', $lineId);

            if ($line === null) {
                $problems[] = new DispatchProblem("Dòng xuất #{$lineId} không thuộc Phiếu xuất #{$dispatch->id}.");
            } elseif ($price !== null && ! $line->kind->allowsSalePrice()) {
                $problems[] = new DispatchProblem("Dòng xuất loại {$line->kind->label()} không có Giá bán.");
            } elseif ($price !== null && $price < 0) {
                $problems[] = new DispatchProblem("Giá bán của Dòng xuất \"{$line->product->name}\" không được âm.");
            }
        }

        return $problems;
    }

    private static function blankToNull(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : trim($value);
    }
}
