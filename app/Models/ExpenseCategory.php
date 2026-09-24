<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExpenseCategory extends Model
{
    /**
     * Profit-and-loss buckets. Categories map onto these so the P&L keeps the
     * same shape even when a center renames or adds its own categories.
     */
    public const BUCKETS = [
        'trainer_compensation' => 'أجور المدربين',
        'salaries' => 'رواتب الموظفين',
        'rent' => 'الإيجار',
        'utilities' => 'الخدمات والفواتير',
        'vehicles' => 'المركبات والوقود',
        'marketing' => 'التسويق',
        'administrative' => 'مصاريف إدارية',
        'other' => 'مصاريف أخرى',
    ];

    protected $fillable = ['code', 'name_ar', 'profit_bucket', 'is_system', 'sort_order', 'status'];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function recurringExpenses(): HasMany
    {
        return $this->hasMany(RecurringExpense::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active')->orderBy('sort_order');
    }

    public function bucketLabel(): string
    {
        return self::BUCKETS[$this->profit_bucket] ?? self::BUCKETS['other'];
    }
}
