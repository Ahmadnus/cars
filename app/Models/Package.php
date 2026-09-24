<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Package extends Model
{
    use BelongsToBranch;
    use HasFactory;
    use HasUuid;
    use SoftDeletes;

    protected $fillable = [
        'branch_id', 'name', 'license_type', 'lessons_count', 'lesson_duration_minutes',
        'price', 'extra_lesson_price', 'max_discount_percent', 'validity_days',
        'description', 'status',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'extra_lesson_price' => 'decimal:2',
            'max_discount_percent' => 'decimal:2',
            'lessons_count' => 'integer',
            'lesson_duration_minutes' => 'integer',
            'validity_days' => 'integer',
        ];
    }

    public function enrolments(): HasMany
    {
        return $this->hasMany(TraineePackage::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    /**
     * Packages with no branch are shared across the whole organization; a
     * branch-owned package is only offered at that branch.
     */
    public function scopeAvailableAt(Builder $query, ?int $branchId): Builder
    {
        return $query->where(function (Builder $q) use ($branchId) {
            $q->whereNull('branch_id');

            if ($branchId) {
                $q->orWhere('branch_id', $branchId);
            }
        });
    }

    public function pricePerLesson(): float
    {
        return $this->lessons_count > 0 ? round((float) $this->price / $this->lessons_count, 2) : 0.0;
    }
}
