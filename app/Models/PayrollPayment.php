<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** A disbursement against a payroll — full or partial. */
class PayrollPayment extends Model
{
    use BelongsToBranch;
    use HasUuid;

    protected $fillable = [
        'payroll_id', 'branch_id', 'receipt_number', 'amount', 'paid_on',
        'payment_method_id', 'reference_number', 'expense_id', 'paid_by', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_on' => 'date',
        ];
    }

    public function payroll(): BelongsTo
    {
        return $this->belongsTo(Payroll::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    /** The expense row this disbursement booked into the ledger. */
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
