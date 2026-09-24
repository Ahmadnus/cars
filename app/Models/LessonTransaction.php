<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of the append-only lesson ledger.
 *
 * Quantity is signed: credits are positive, consumption negative. Rows are
 * written by TraineeBalanceService only, and are never updated or deleted — a
 * mistake is corrected by posting an opposing row.
 */
class LessonTransaction extends Model
{
    /** Types that add lessons to the balance. */
    public const CREDIT_TYPES = ['package_credit', 'extra_credit', 'manual_credit'];

    /** Types that remove lessons from the balance. */
    public const DEBIT_TYPES = ['consumption', 'no_show', 'late_cancellation', 'refund', 'manual_debit'];

    protected $fillable = [
        'trainee_package_id', 'trainee_id', 'type', 'quantity',
        'training_session_id', 'description', 'created_by',
    ];

    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }

    public function traineePackage(): BelongsTo
    {
        return $this->belongsTo(TraineePackage::class);
    }

    public function trainee(): BelongsTo
    {
        return $this->belongsTo(Trainee::class);
    }

    public function trainingSession(): BelongsTo
    {
        return $this->belongsTo(TrainingSession::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isCredit(): bool
    {
        return $this->quantity > 0;
    }
}
