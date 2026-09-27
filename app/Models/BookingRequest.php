<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A request raised from the Trainee app and triaged in the dashboard. */
class BookingRequest extends Model
{
    use BelongsToBranch;
    use HasFactory;
    use HasUuid;

    protected $fillable = [
        'branch_id', 'trainee_id', 'training_session_id', 'type', 'requested_date',
        'requested_start_time', 'preferred_trainer_id', 'trainee_note', 'status',
        'admin_note', 'resolved_by', 'resolved_at', 'resulting_session_id',
        'trainer_decision', 'trainer_note', 'trainer_decided_by', 'trainer_decided_at',
    ];

    /*
    | The trainer's say, for a reschedule.
    |
    | Moving a lesson is the trainer's diary, so they decide and the office
    | applies it. `null` means the request never needed them — a plain booking,
    | which reception schedules on its own.
    */
    public const TRAINER_PENDING = 'pending';

    public const TRAINER_APPROVED = 'approved';

    public const TRAINER_REJECTED = 'rejected';

    /** Every state the trainer's decision can be in, for filtering. */
    public const TRAINER_DECISIONS = [self::TRAINER_PENDING, self::TRAINER_APPROVED, self::TRAINER_REJECTED];

    /** Types that wait on the trainer before the office can act. */
    public const TRAINER_DECIDED_TYPES = ['reschedule', 'cancellation'];

    protected function casts(): array
    {
        return [
            'requested_date' => 'date',
            'resolved_at' => 'datetime',
            'trainer_decided_at' => 'datetime',
        ];
    }

    public function trainee(): BelongsTo
    {
        return $this->belongsTo(Trainee::class);
    }

    /** The existing lesson a reschedule or cancellation refers to. */
    public function trainingSession(): BelongsTo
    {
        return $this->belongsTo(TrainingSession::class);
    }

    public function resultingSession(): BelongsTo
    {
        return $this->belongsTo(TrainingSession::class, 'resulting_session_id');
    }

    public function preferredTrainer(): BelongsTo
    {
        return $this->belongsTo(Trainer::class, 'preferred_trainer_id');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    // ------------------------------------------------------ the trainer's say

    /** Whether this type of request needs the trainer before the office. */
    public function needsTrainerDecision(): bool
    {
        return in_array($this->type, self::TRAINER_DECIDED_TYPES, true);
    }

    public function awaitingTrainer(): bool
    {
        return $this->needsTrainerDecision()
            && $this->trainer_decision === self::TRAINER_PENDING;
    }

    public function trainerApproved(): bool
    {
        return $this->trainer_decision === self::TRAINER_APPROVED;
    }

    public function trainerRejected(): bool
    {
        return $this->trainer_decision === self::TRAINER_REJECTED;
    }

    /**
     * Whether the office may act on this now.
     *
     * A reschedule the trainer has not answered is not the office's to apply —
     * doing so would move a lesson out from under the trainer.
     */
    public function readyForOffice(): bool
    {
        return ! $this->awaitingTrainer();
    }

    public function trainerDecisionLabel(): ?string
    {
        return match ($this->trainer_decision) {
            self::TRAINER_PENDING => 'بانتظار موافقة المدرب',
            self::TRAINER_APPROVED => 'وافق المدرب',
            self::TRAINER_REJECTED => 'رفض المدرب',
            default => null,
        };
    }

    /**
     * The trainer whose diary this request touches.
     *
     * A reschedule names an existing lesson, so its trainer decides. A booking
     * has none yet, so the preferred trainer stands in.
     */
    public function decidingTrainer(): ?Trainer
    {
        return $this->trainingSession?->trainer ?? $this->preferredTrainer;
    }

    public function trainerDecider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'trainer_decided_by');
    }

    /** Requests waiting on a given trainer. */
    public function scopeAwaitingTrainerId(Builder $query, int $trainerId): Builder
    {
        return $query
            ->where('booking_requests.trainer_decision', self::TRAINER_PENDING)
            ->where(function (Builder $q) use ($trainerId) {
                $q->where('booking_requests.preferred_trainer_id', $trainerId)
                    ->orWhereHas(
                        'trainingSession',
                        fn ($s) => $s->where('training_sessions.trainer_id', $trainerId),
                    );
            });
    }
}
