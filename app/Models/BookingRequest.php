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
    ];

    protected function casts(): array
    {
        return [
            'requested_date' => 'date',
            'resolved_at' => 'datetime',
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
}
