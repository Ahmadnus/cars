<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasUuid;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Template that the scheduler turns into real expenses as each period falls due. */
class RecurringExpense extends Model
{
    use BelongsToBranch;
    use HasFactory;
    use HasUuid;
    use SoftDeletes;

    protected $fillable = [
        'branch_id', 'expense_category_id', 'payment_method_id', 'name', 'amount',
        'frequency', 'due_day', 'starts_on', 'ends_on', 'last_generated_on',
        'auto_post', 'status', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'due_day' => 'integer',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'last_generated_on' => 'date',
            'auto_post' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    /** Months between one occurrence and the next. */
    public function intervalMonths(): int
    {
        return match ($this->frequency) {
            'quarterly' => 3,
            'yearly' => 12,
            default => 1,
        };
    }

    /**
     * The next date this template should produce an expense for, or null when
     * it has run past its end date.
     */
    public function nextDueDate(?CarbonInterface $asOf = null): ?Carbon
    {
        $asOf = $asOf ? Carbon::parse($asOf) : now();

        $anchor = $this->last_generated_on
            ? $this->last_generated_on->copy()->addMonthsNoOverflow($this->intervalMonths())
            : $this->starts_on->copy();

        $due = $anchor->copy()->day(min($this->due_day, $anchor->daysInMonth));

        if ($due->lt($this->starts_on)) {
            $due = $this->starts_on->copy()->day(min($this->due_day, $this->starts_on->daysInMonth));
        }

        if ($this->ends_on && $due->gt($this->ends_on)) {
            return null;
        }

        return $due;
    }

    public function isDue(?CarbonInterface $asOf = null): bool
    {
        $asOf = $asOf ? Carbon::parse($asOf) : now();
        $due = $this->nextDueDate($asOf);

        return $due !== null && $due->lte($asOf);
    }
}
