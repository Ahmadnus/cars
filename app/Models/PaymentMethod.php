<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentMethod extends Model
{
    protected $fillable = [
        'code', 'label_ar', 'affects_cashbox', 'requires_reference', 'sort_order', 'status',
    ];

    protected function casts(): array
    {
        return [
            'affects_cashbox' => 'boolean',
            'requires_reference' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active')->orderBy('sort_order');
    }

    public function movesCash(): bool
    {
        return (bool) $this->affects_cashbox;
    }
}
