<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Branch extends Model
{
    use HasFactory;
    use HasUuid;
    use SoftDeletes;

    protected $fillable = [
        'organization_id', 'name', 'code', 'address', 'phone',
        'email', 'logo_path', 'working_hours', 'status',
    ];

    protected function casts(): array
    {
        return ['working_hours' => 'array'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'branch_user')->withTimestamps();
    }

    public function trainees(): HasMany
    {
        return $this->hasMany(Trainee::class);
    }

    public function trainers(): HasMany
    {
        return $this->hasMany(Trainer::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    public function trainingSessions(): HasMany
    {
        return $this->hasMany(TrainingSession::class);
    }

    public function cashboxes(): HasMany
    {
        return $this->hasMany(Cashbox::class);
    }

    public function primaryCashbox(): ?Cashbox
    {
        return $this->cashboxes()->where('status', 'active')->orderBy('id')->first();
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Working window for a given date, or null when the branch is closed.
     *
     * @return array{open: string, close: string}|null
     */
    public function hoursFor(CarbonInterface $date): ?array
    {
        $day = (int) $date->dayOfWeek; // Carbon: 0 = Sunday

        foreach ($this->working_hours ?? [] as $entry) {
            if ((int) ($entry['day'] ?? -1) !== $day) {
                continue;
            }

            if (! empty($entry['closed'])) {
                return null;
            }

            return ['open' => $entry['open'] ?? '00:00', 'close' => $entry['close'] ?? '23:59'];
        }

        return null;
    }
}
