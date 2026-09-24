<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How one trainer is paid, valid for a date window.
 *
 * Rules are never edited in place once a period has been calculated against
 * them — a change closes the old rule and opens a new one, so historical
 * statements always reproduce.
 */
class TrainerCompensationRule extends Model
{
    use BelongsToBranch;
    use HasFactory;
    use HasUuid;

    public const MODELS = [
        'monthly_salary' => 'راتب شهري',
        'per_lesson' => 'مبلغ ثابت لكل حصة',
        'revenue_percentage' => 'نسبة من إيراد الحصص',
        'salary_plus_percentage' => 'راتب + نسبة',
        'salary_plus_per_lesson' => 'راتب + مبلغ لكل حصة',
    ];

    protected $fillable = [
        'branch_id', 'trainer_id', 'model', 'base_salary', 'per_lesson_rate',
        'revenue_percentage', 'effective_from', 'effective_to', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'base_salary' => 'decimal:2',
            'per_lesson_rate' => 'decimal:2',
            'revenue_percentage' => 'decimal:2',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    public function trainer(): BelongsTo
    {
        return $this->belongsTo(Trainer::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function modelLabel(): string
    {
        return self::MODELS[$this->model] ?? $this->model;
    }

    public function usesSalary(): bool
    {
        return in_array($this->model, [
            'monthly_salary', 'salary_plus_percentage', 'salary_plus_per_lesson',
        ], true);
    }

    public function usesPerLesson(): bool
    {
        return in_array($this->model, ['per_lesson', 'salary_plus_per_lesson'], true);
    }

    public function usesPercentage(): bool
    {
        return in_array($this->model, ['revenue_percentage', 'salary_plus_percentage'], true);
    }

    public function isOpenEnded(): bool
    {
        return $this->effective_to === null;
    }
}
