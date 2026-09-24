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
 * A single lesson, from the moment it is booked until it is delivered.
 *
 * This is the one source of truth for the calendar, conflict checking, lesson
 * consumption and trainer compensation — see README "Appointments vs training
 * sessions" for why these were not split into two tables.
 */
class TrainingSession extends Model
{
    use BelongsToBranch;
    use HasFactory;
    use HasUuid;
    use SoftDeletes;

    /** Statuses that still occupy a slot in the calendar. */
    public const BLOCKING_STATUSES = ['scheduled'];

    protected $fillable = [
        'branch_id', 'trainee_id', 'trainer_id', 'vehicle_id', 'trainee_package_id',
        'scheduled_date', 'start_time', 'end_time', 'duration_minutes', 'status',
        'completed_at', 'completed_by', 'overall_rating', 'strengths', 'weaknesses',
        'trainer_notes', 'next_requirements', 'cancellation_reason', 'cancelled_by',
        'cancelled_at', 'rescheduled_to_id', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_date' => 'date',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'duration_minutes' => 'integer',
        ];
    }

    public function trainee(): BelongsTo
    {
        return $this->belongsTo(Trainee::class);
    }

    public function trainer(): BelongsTo
    {
        return $this->belongsTo(Trainer::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function traineePackage(): BelongsTo
    {
        return $this->belongsTo(TraineePackage::class);
    }

    public function skills(): HasMany
    {
        return $this->hasMany(TrainingSessionSkill::class);
    }

    public function lessonTransactions(): HasMany
    {
        return $this->hasMany(LessonTransaction::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function rescheduledTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'rescheduled_to_id');
    }

    // ------------------------------------------------------------------
    // Scopes
    // ------------------------------------------------------------------

    public function scopeScheduled(Builder $query): Builder
    {
        return $query->where('training_sessions.status', 'scheduled');
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('training_sessions.status', 'completed');
    }

    public function scopeOnDate(Builder $query, string $date): Builder
    {
        return $query->whereDate('scheduled_date', $date);
    }

    public function scopeBetweenDates(Builder $query, string $from, string $to): Builder
    {
        return $query->whereBetween('scheduled_date', [$from, $to]);
    }

    /** Sessions that still hold a slot, and so can collide with a new booking. */
    public function scopeBlocking(Builder $query): Builder
    {
        return $query->whereIn('training_sessions.status', self::BLOCKING_STATUSES);
    }

    // ------------------------------------------------------------------
    // State
    // ------------------------------------------------------------------

    public function isScheduled(): bool
    {
        return $this->status === 'scheduled';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    /** A completed or cancelled lesson is settled and must not be edited. */
    public function isSettled(): bool
    {
        return in_array($this->status, ['completed', 'cancelled', 'no_show'], true);
    }

    public function startsAt(): \Carbon\CarbonInterface
    {
        return $this->scheduled_date->copy()->setTimeFromTimeString((string) $this->start_time);
    }

    public function endsAt(): \Carbon\CarbonInterface
    {
        return $this->scheduled_date->copy()->setTimeFromTimeString((string) $this->end_time);
    }

    public function isPast(): bool
    {
        return $this->endsAt()->isPast();
    }

    public function timeRange(): string
    {
        return substr((string) $this->start_time, 0, 5).' - '.substr((string) $this->end_time, 0, 5);
    }
}
