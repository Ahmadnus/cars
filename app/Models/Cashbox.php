<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cashbox extends Model
{
    use BelongsToBranch;
    use HasFactory;
    use HasUuid;

    protected $fillable = ['branch_id', 'name', 'opening_balance', 'current_balance', 'status'];

    protected function casts(): array
    {
        return [
            'opening_balance' => 'decimal:2',
            'current_balance' => 'decimal:2',
        ];
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(CashboxTransaction::class);
    }

    public function closings(): HasMany
    {
        return $this->hasMany(CashboxClosing::class);
    }

    /**
     * Recompute the balance straight from the ledger.
     *
     * Used by the integrity check command; normal operation relies on the
     * denormalised current_balance that CashboxService maintains.
     */
    public function recalculatedBalance(): float
    {
        $in = (float) $this->transactions()->where('direction', 'in')->sum('amount');
        $out = (float) $this->transactions()->where('direction', 'out')->sum('amount');

        return round((float) $this->opening_balance + $in - $out, 2);
    }

    public function isConsistent(): bool
    {
        return abs($this->recalculatedBalance() - (float) $this->current_balance) < 0.01;
    }
}
