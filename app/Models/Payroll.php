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

/** One employee's salary for one month. Every figure is computed server-side. */
class Payroll extends Model
{
    use BelongsToBranch;
    use HasFactory;
    use HasUuid;
    use SoftDeletes;

    protected $fillable = [
        'branch_id', 'employee_id', 'period', 'base_salary', 'allowances', 'bonuses',
        'deductions', 'advance_deductions', 'net_salary', 'paid_amount', 'status',
        'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'base_salary' => 'decimal:2',
            'allowances' => 'decimal:2',
            'bonuses' => 'decimal:2',
            'deductions' => 'decimal:2',
            'advance_deductions' => 'decimal:2',
            'net_salary' => 'decimal:2',
            'paid_amount' => 'decimal:2',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PayrollPayment::class);
    }

    public function advanceDeductions(): HasMany
    {
        return $this->hasMany(AdvanceDeduction::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeForPeriod(Builder $query, string $period): Builder
    {
        return $query->where('period', $period);
    }

    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->whereIn('status', ['unpaid', 'partially_paid']);
    }

    public function remainingAmount(): float
    {
        return round((float) $this->net_salary - (float) $this->paid_amount, 2);
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    /** A payroll that has money against it can no longer be recalculated. */
    public function isLocked(): bool
    {
        return (float) $this->paid_amount > 0 || $this->isCancelled();
    }

    public function periodLabel(): string
    {
        return \Carbon\Carbon::createFromFormat('Y-m', $this->period)->translatedFormat('F Y');
    }
}
