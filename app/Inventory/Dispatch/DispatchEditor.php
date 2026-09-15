<?php

namespace App\Inventory\Dispatch;

use App\Inventory\Access\MissingRole;
use App\Inventory\Access\Role;
use App\Inventory\Access\RoleGate;
use App\Models\Dispatch;
use App\Models\DispatchLine;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Sửa thông tin Phiếu xuất Hoàn tất: mã đơn ngoài (vẫn duy nhất trong Kênh bán), khách, ghi chú
 * và Giá bán từng Dòng xuất. Kênh bán, Dòng xuất và Slot không sửa được. Mỗi trường đổi giá trị
 * ghi một dòng lịch sử sửa phiếu trong cùng transaction; không đụng Sổ biến động kho.
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

        try {
            return DB::transaction(function () use ($actor, $dispatch, $edit, $ref): Dispatch {
                $current = Dispatch::query()->with(['salesChannel', 'lines.product'])->lockForUpdate()->findOrFail($dispatch->getKey());

                if ($current->status !== DispatchStatus::Completed) {
                    throw new InvalidDispatch([new DispatchProblem('Chỉ sửa được Phiếu xuất Hoàn tất.')]);
                }

                $problems = self::problems($current, $edit, $ref);

                if ($problems !== []) {
                    throw new InvalidDispatch($problems);
                }

                $revisions = [];
                $record = function (DispatchRevisionField $field, int|string|null $old, int|string|null $new, ?DispatchLine $line = null) use (&$revisions): void {
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

                $record(DispatchRevisionField::ExternalRef, $current->external_ref, $ref);
                $record(DispatchRevisionField::Customer, $current->customer, $customer);
                $record(DispatchRevisionField::Note, $current->note, $note);
                $current->forceFill(['external_ref' => $ref, 'customer' => $customer, 'note' => $note])->save();

                foreach ($edit->salePrices as $lineId => $price) {
                    $line = $current->lines->firstOrFail('id', $lineId);
                    $record(DispatchRevisionField::SalePrice, $line->sale_price, $price, $line);
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
        } catch (UniqueConstraintViolationException) {
            // Phiếu khác vừa chiếm đúng mã này giữa lúc kiểm tra và lúc lưu.
            throw new InvalidDispatch([ManualDispatch::duplicateRef($dispatch->salesChannel, $ref)]);
        }
    }

    /**
     * @return list<DispatchProblem>
     */
    private static function problems(Dispatch $dispatch, DispatchEdit $edit, string $ref): array
    {
        $problems = [];

        if ($ref === '') {
            $problems[] = new DispatchProblem('Mã đơn ngoài không được để trống.');
        } elseif (mb_strlen($ref) > ManualDispatch::MAX_REF_LENGTH) {
            $problems[] = new DispatchProblem(sprintf('Mã đơn ngoài dài quá %d ký tự.', ManualDispatch::MAX_REF_LENGTH));
        } elseif ($ref !== $dispatch->external_ref && Dispatch::query()->where('sales_channel_id', $dispatch->sales_channel_id)->where('external_ref', $ref)->exists()) {
            $problems[] = ManualDispatch::duplicateRef($dispatch->salesChannel, $ref);
        }

        foreach ($edit->salePrices as $lineId => $price) {
            $line = $dispatch->lines->firstWhere('id', $lineId);

            if ($line === null) {
                $problems[] = new DispatchProblem("Dòng xuất #{$lineId} không thuộc Phiếu xuất #{$dispatch->id}.");
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
