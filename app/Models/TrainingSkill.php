<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TrainingSkill extends Model
{
    use HasFactory;

    /** Evaluation levels, weakest first — order matters for progress maths. */
    public const LEVELS = ['not_started', 'needs_training', 'average', 'good', 'very_good', 'excellent'];

    protected $fillable = ['name_ar', 'code', 'description', 'sort_order', 'status'];

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    public function sessionSkills(): HasMany
    {
        return $this->hasMany(TrainingSessionSkill::class);
    }

    public function evaluations(): HasMany
    {
        return $this->hasMany(TraineeSkillEvaluation::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active')->orderBy('sort_order');
    }

    /** 0-100 score for a level, used to average a trainee's overall readiness. */
    public static function levelScore(string $level): int
    {
        $index = array_search($level, self::LEVELS, true);

        return $index === false ? 0 : (int) round($index / (count(self::LEVELS) - 1) * 100);
    }
}
