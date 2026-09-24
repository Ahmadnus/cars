<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A trainee's enrolment in a package.
 *
 * Package terms are copied here at enrolment so that later price changes never
 * rewrite a signed contract. The lesson balance is never stored — it is always
 * the sum of the append-only lesson_transactions ledger.
 */
class TraineePackage extends Model
{
    use BelongsToBranch;
    use HasFactory;
    use HasUuid;
    use SoftDeletes;

    protected $fillable = [
        'branch_id', 'trainee_id', 'package_id', 'package_name', 'lessons_count',
        'lesson_duration_minutes', 'unit_price', 'extra_lesson_price', 'gross_amount',
        'discount_amount', 'discount_reason', 'total_amount', 'extras_amount',
        'paid_amount', 'started_on', 'expires_on', 'status', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'started_on' => 'date',
            'expires_on' => 'date',
            'lessons_count' => 'integer',
            'lesson_duration_minutes' => 'integer',
            'unit_price' => 'decimal:2',
            'extra_lesson_price' => 'decimal:2',
            'gross_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'extras_amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
        ];
    }

    public function trainee(): BelongsTo
    {
        return $this->belongsTo(Trainee::class);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lessonTransactions(): HasMany
    {
        return $this->hasMany(LessonTransaction::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function trainingSessions(): HasMany
    {
        return $this->hasMany(TrainingSession::class);
    }

    // ------------------------------------------------------------------
    // Lesson balance — always derived, never stored
    // ------------------------------------------------------------------

    /** Net remaining lessons: the signed sum of the whole ledger. */
    public function remainingLessons(): int
    {
        return (int) $this->lessonTransactions()->sum('quantity');
    }

    public function creditedLessons(): int
    {
        return (int) $this->lessonTransactions()->where('quantity', '>', 0)->sum('quantity');
    }

    public function consumedLessons(): int
    {
        return (int) abs($this->lessonTransactions()->where('quantity', '<', 0)->sum('quantity'));
    }

    public function completedLessons(): int
    {
        return (int) abs($this->lessonTransactions()->where('type', 'consumption')->sum('quantity'));
    }

    public function extraLessons(): int
    {
        return (int) $this->lessonTransactions()->where('type', 'extra_credit')->sum('quantity');
    }

    public function progressPercent(): int
    {
        $credited = $this->creditedLessons();

        return $credited > 0 ? (int) round($this->completedLessons() / $credited * 100) : 0;
    }

    // ------------------------------------------------------------------
    // Money
    // ------------------------------------------------------------------

    public function remainingAmount(): float
    {
        return round((float) $this->total_amount - (float) $this->paid_amount, 2);
    }

    public function isFullyPaid(): bool
    {
        return $this->remainingAmount() <= 0.009;
    }

    public function isExpired(): bool
    {
        return $this->expires_on !== null && $this->expires_on->isPast();
    }
}
