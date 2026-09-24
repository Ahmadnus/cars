<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** A payout against a trainer's compensation statement. */
class TrainerCompensationPayment extends Model
{
    use BelongsToBranch;
    use HasUuid;

    protected $fillable = [
        'trainer_compensation_record_id', 'branch_id', 'receipt_number', 'amount',
        'paid_on', 'payment_method_id', 'reference_number', 'expense_id', 'paid_by', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_on' => 'date',
        ];
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(TrainerCompensationRecord::class, 'trainer_compensation_record_id');
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function cashboxTransactions(): MorphMany
    {
        return $this->morphMany(CashboxTransaction::class, 'sourceable');
    }
}
