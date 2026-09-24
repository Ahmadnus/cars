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

class Vehicle extends Model
{
    use BelongsToBranch;
    use HasFactory;
    use HasUuid;
    use SoftDeletes;

    /** Statuses in which a vehicle may be put on a lesson. */
    public const BOOKABLE_STATUSES = ['available', 'in_use'];

    protected $fillable = [
        'branch_id', 'assigned_trainer_id', 'name', 'plate_number', 'model', 'year',
        'transmission', 'license_type', 'status', 'insurance_number', 'insurance_expires_on',
        'registration_number', 'registration_expires_on', 'odometer_km', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'insurance_expires_on' => 'date',
            'registration_expires_on' => 'date',
            'year' => 'integer',
            'odometer_km' => 'integer',
        ];
    }

    public function assignedTrainer(): BelongsTo
    {
        return $this->belongsTo(Trainer::class, 'assigned_trainer_id');
    }

    public function maintenances(): HasMany
    {
        return $this->hasMany(VehicleMaintenance::class);
    }

    public function trainingSessions(): HasMany
    {
        return $this->hasMany(TrainingSession::class);
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function scopeBookable(Builder $query): Builder
    {
        return $query->whereIn('status', self::BOOKABLE_STATUSES);
    }

    public function isBookable(): bool
    {
        return in_array($this->status, self::BOOKABLE_STATUSES, true);
    }

    /** Papers expiring within the given window, for the vehicle alerts panel. */
    public function expiringPapers(int $withinDays = 30): array
    {
        $limit = now()->addDays($withinDays);
        $expiring = [];

        if ($this->insurance_expires_on && $this->insurance_expires_on->lte($limit)) {
            $expiring['insurance'] = $this->insurance_expires_on;
        }

        if ($this->registration_expires_on && $this->registration_expires_on->lte($limit)) {
            $expiring['registration'] = $this->registration_expires_on;
        }

        return $expiring;
    }
}
