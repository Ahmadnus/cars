<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Money received from a trainee.
 *
 * Payments are never edited or deleted once recorded — a mistake is voided,
 * which reverses the cash movement and the enrolment's paid amount while
 * leaving the original row and its receipt number intact.
 */
class Payment extends Model
{
    use BelongsToBranch;
    use HasFactory;
    use HasUuid;
    use SoftDeletes;

    protected $fillable = [
        'branch_id', 'receipt_number', 'trainee_id', 'trainee_package_id',
        'payment_method_id', 'source', 'amount', 'paid_on', 'reference_number',
        'notes', 'status', 'void_reason', 'voided_by', 'voided_at', 'received_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_on' => 'date',
            'voided_at' => 'datetime',
        ];
    }

    public function trainee(): BelongsTo
    {
        return $this->belongsTo(Trainee::class);
    }

    public function traineePackage(): BelongsTo
    {
        return $this->belongsTo(TraineePackage::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function cashboxTransactions(): MorphMany
    {
        return $this->morphMany(CashboxTransaction::class, 'sourceable');
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    /** Only completed payments count as revenue. */
    public function scopeCompleted(Builder $query): Builder
    {
        // Qualified: this scope is used alongside joins in the reports.
        return $query->where('payments.status', 'completed');
    }

    public function isVoided(): bool
    {
        return $this->status === 'voided';
    }
}
