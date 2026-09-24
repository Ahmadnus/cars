<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A salary advance, repaid by installments deducted from future payrolls.
 *
 * remaining_amount is authoritative and only ever moved by PayrollService
 * inside a transaction, so an advance can never be over-deducted.
 */
class EmployeeAdvance extends Model
{
    use BelongsToBranch;
    use HasFactory;
    use HasUuid;
    use SoftDeletes;

    protected $fillable = [
        'branch_id', 'employee_id', 'amount', 'granted_on', 'installments_count',
        'installment_amount', 'deducted_amount', 'remaining_amount',
        'payment_method_id', 'status', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'granted_on' => 'date',
            'installments_count' => 'integer',
            'installment_amount' => 'decimal:2',
            'deducted_amount' => 'decimal:2',
            'remaining_amount' => 'decimal:2',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function deductions(): HasMany
    {
        return $this->hasMany(AdvanceDeduction::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active')->where('remaining_amount', '>', 0);
    }

    /** The installment to take this month, capped at what is still owed. */
    public function nextInstallment(): float
    {
        return round(min((float) $this->installment_amount, (float) $this->remaining_amount), 2);
    }

    public function isSettled(): bool
    {
        return $this->status === 'settled' || (float) $this->remaining_amount <= 0.009;
    }

    public function repaidPercent(): int
    {
        $total = (float) $this->amount;

        return $total > 0 ? (int) round((float) $this->deducted_amount / $total * 100) : 0;
    }
}
