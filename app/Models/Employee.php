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

class Employee extends Model
{
    use BelongsToBranch;
    use HasFactory;
    use HasUuid;
    use SoftDeletes;

    protected $fillable = [
        'branch_id', 'user_id', 'employee_number', 'full_name', 'phone', 'national_id',
        'position', 'employment_date', 'base_salary', 'allowances', 'working_days',
        'work_start_time', 'work_end_time', 'status', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'employment_date' => 'date',
            'base_salary' => 'decimal:2',
            'allowances' => 'decimal:2',
            'working_days' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function payrolls(): HasMany
    {
        return $this->hasMany(Payroll::class);
    }

    public function advances(): HasMany
    {
        return $this->hasMany(EmployeeAdvance::class);
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    /** Outstanding advance balance across all active advances. */
    public function outstandingAdvances(): float
    {
        return (float) $this->advances()->where('status', 'active')->sum('remaining_amount');
    }
}
