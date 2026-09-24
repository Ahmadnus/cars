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

/** One trainer's compensation statement for one month. */
class TrainerCompensationRecord extends Model
{
    use BelongsToBranch;
    use HasFactory;
    use HasUuid;
    use SoftDeletes;

    protected $fillable = [
        'branch_id', 'trainer_id', 'trainer_compensation_rule_id', 'period', 'model',
        'lessons_count', 'training_minutes', 'attributed_revenue', 'base_salary',
        'lesson_earnings', 'percentage_earnings', 'bonuses', 'deductions',
        'gross_amount', 'net_amount', 'paid_amount', 'status', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'lessons_count' => 'integer',
            'training_minutes' => 'integer',
            'attributed_revenue' => 'decimal:2',
            'base_salary' => 'decimal:2',
            'lesson_earnings' => 'decimal:2',
            'percentage_earnings' => 'decimal:2',
            'bonuses' => 'decimal:2',
            'deductions' => 'decimal:2',
            'gross_amount' => 'decimal:2',
            'net_amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
        ];
    }

    public function trainer(): BelongsTo
    {
        return $this->belongsTo(Trainer::class);
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(TrainerCompensationRule::class, 'trainer_compensation_rule_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(TrainerCompensationPayment::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->whereIn('status', ['approved', 'partially_paid']);
    }

    public function remainingAmount(): float
    {
        return round((float) $this->net_amount - (float) $this->paid_amount, 2);
    }

    public function trainingHours(): float
    {
        return round($this->training_minutes / 60, 1);
    }

    /** Draft statements can be recalculated; anything else is frozen. */
    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isPayable(): bool
    {
        return in_array($this->status, ['approved', 'partially_paid'], true);
    }

    public function periodLabel(): string
    {
        return \Carbon\Carbon::createFromFormat('Y-m', $this->period)->translatedFormat('F Y');
    }
}
