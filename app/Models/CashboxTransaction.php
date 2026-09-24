<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One immutable line of the cash ledger.
 *
 * Written only by CashboxService, always inside the same transaction that moves
 * the cashbox balance. Never updated or deleted — corrections are posted as a
 * reversing row pointing back at the original through reverses_id.
 */
class CashboxTransaction extends Model
{
    use BelongsToBranch;
    use HasUuid;

    protected $fillable = [
        'cashbox_id', 'branch_id', 'direction', 'amount', 'balance_after', 'category',
        'sourceable_type', 'sourceable_id', 'description', 'transaction_date',
        'reverses_id', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'transaction_date' => 'date',
        ];
    }

    public function cashbox(): BelongsTo
    {
        return $this->belongsTo(Cashbox::class);
    }

    public function sourceable(): MorphTo
    {
        return $this->morphTo();
    }

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Signed amount, for summing a ledger extract. */
    public function signedAmount(): float
    {
        return $this->direction === 'in' ? (float) $this->amount : -(float) $this->amount;
    }
}
