<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasUuid;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Trainer extends Model
{
    use BelongsToBranch;
    use HasFactory;
    use HasUuid;
    use SoftDeletes;

    protected $fillable = [
        'branch_id', 'user_id', 'trainer_number', 'full_name', 'phone', 'national_id',
        'birth_date', 'address', 'photo_path', 'employment_date', 'license_types',
        'working_days', 'work_start_time', 'work_end_time', 'status', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'employment_date' => 'date',
            'license_types' => 'array',
            'working_days' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function trainees(): HasMany
    {
        return $this->hasMany(Trainee::class);
    }

    public function trainingSessions(): HasMany
    {
        return $this->hasMany(TrainingSession::class);
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class, 'assigned_trainer_id');
    }

    public function compensationRules(): HasMany
    {
        return $this->hasMany(TrainerCompensationRule::class);
    }

    public function compensationRecords(): HasMany
    {
        return $this->hasMany(TrainerCompensationRecord::class);
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** The compensation rule in force on a given date. */
    public function ruleOn(CarbonInterface $date): ?TrainerCompensationRule
    {
        return $this->compensationRules()
            ->where('effective_from', '<=', $date->toDateString())
            ->where(function (Builder $q) use ($date) {
                $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date->toDateString());
            })
            ->orderByDesc('effective_from')
            ->first();
    }

    /** True when the trainer's own weekly schedule covers this moment. */
    public function worksAt(CarbonInterface $date, string $startTime, string $endTime): bool
    {
        $days = $this->working_days;

        if (! empty($days) && ! in_array((int) $date->dayOfWeek, array_map('intval', $days), true)) {
            return false;
        }

        if ($this->work_start_time && $startTime < substr((string) $this->work_start_time, 0, 5)) {
            return false;
        }

        if ($this->work_end_time && $endTime > substr((string) $this->work_end_time, 0, 5)) {
            return false;
        }

        return true;
    }
}
