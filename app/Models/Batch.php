<?php

namespace App\Models;

use App\Inventory\Intake\BatchIntake;
use App\Inventory\Intake\BatchStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Lô nhập: một lần nhập hàng từ một Nhà cung cấp. Chỉ tạo và xác nhận qua {@see BatchIntake}.
 *
 * @property int $id
 * @property int $supplier_id
 * @property CarbonImmutable $received_on
 * @property ?string $document_number
 * @property ?string $note
 * @property ?int $invoice_total
 * @property ?int $supplements_batch_id
 * @property BatchStatus $status
 * @property ?string $validation_error
 * @property int $created_by
 * @property ?int $confirmed_by
 * @property ?CarbonImmutable $confirmed_at
 * @property ?CarbonImmutable $created_at
 * @property-read Supplier $supplier
 * @property-read User $creator
 * @property-read Collection<int, BatchLine> $lines
 * @property-read ?Batch $supplements
 */
class Batch extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'received_on' => 'immutable_date',
            'invoice_total' => 'integer',
            'status' => BatchStatus::class,
            'confirmed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Lô nhập đã xác nhận mà lô này bổ sung.
     *
     * @return BelongsTo<Batch, $this>
     */
    public function supplements(): BelongsTo
    {
        return $this->belongsTo(Batch::class, 'supplements_batch_id');
    }

    /**
     * @return HasMany<BatchLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(BatchLine::class)->orderBy('id');
    }

    /**
     * Số dòng bị bỏ vì lỗi định dạng.
     */
    public function rejectedInvalidCount(): int
    {
        return (int) $this->lines->sum('invalid_count');
    }

    /**
     * Số dòng bị bỏ vì trùng trong file hoặc trùng trong kho.
     */
    public function rejectedDuplicateCount(): int
    {
        return (int) $this->lines->sum(fn (BatchLine $line): int => $line->file_duplicate_count + $line->stock_duplicate_count);
    }
}
