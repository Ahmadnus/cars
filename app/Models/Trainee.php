<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Trainee extends Model
{
    use BelongsToBranch;
    use HasFactory;
    use HasUuid;
    use SoftDeletes;

    /** Statuses that mean the trainee is no longer consuming lessons. */
    public const CLOSED_STATUSES = ['completed', 'cancelled', 'passed'];

    protected $fillable = [
        'branch_id', 'user_id', 'trainer_id', 'trainee_number', 'full_name', 'phone',
        'secondary_phone', 'national_id', 'birth_date', 'gender', 'address', 'photo_path',
        'license_type', 'registration_date', 'status', 'exam_date', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'registration_date' => 'date',
            'exam_date' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function trainer(): BelongsTo
    {
        return $this->belongsTo(Trainer::class);
    }

    public function packages(): HasMany
    {
        return $this->hasMany(TraineePackage::class);
    }

    public function trainingSessions(): HasMany
    {
        return $this->hasMany(TrainingSession::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function lessonTransactions(): HasMany
    {
        return $this->hasMany(LessonTransaction::class);
    }

    public function skillEvaluations(): HasMany
    {
        return $this->hasMany(TraineeSkillEvaluation::class);
    }

    public function bookingRequests(): HasMany
    {
        return $this->hasMany(BookingRequest::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(TraineeNote::class)->latest();
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNotIn('status', self::CLOSED_STATUSES);
    }

    /** The enrolment lessons are currently drawn from. */
    public function activePackage(): ?TraineePackage
    {
        return $this->packages()
            ->where('status', 'active')
            ->orderByDesc('started_on')
            ->first();
    }

    public function isClosed(): bool
    {
        return in_array($this->status, self::CLOSED_STATUSES, true);
    }

    public function age(): ?int
    {
        return $this->birth_date?->age;
    }
}
