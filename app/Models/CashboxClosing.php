<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A daily cash count, recording any difference against the expected balance. */
class CashboxClosing extends Model
{
    use BelongsToBranch;

    protected $fillable = [
        'cashbox_id', 'branch_id', 'closing_date', 'opening_balance', 'total_in',
        'total_out', 'expected_balance', 'counted_balance', 'difference', 'notes', 'closed_by',
    ];

    protected function casts(): array
    {
        return [
            'closing_date' => 'date',
            'opening_balance' => 'decimal:2',
            'total_in' => 'decimal:2',
            'total_out' => 'decimal:2',
            'expected_balance' => 'decimal:2',
            'counted_balance' => 'decimal:2',
            'difference' => 'decimal:2',
        ];
    }

    public function cashbox(): BelongsTo
    {
        return $this->belongsTo(Cashbox::class);
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function isBalanced(): bool
    {
        return abs((float) $this->difference) < 0.01;
    }
}
