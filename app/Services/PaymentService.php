<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Trainee;
use App\Models\TraineePackage;
use Illuminate\Support\Facades\DB;

/**
 * Money received from trainees.
 *
 * Recording a payment moves three things together: the payment row, the
 * enrolment's paid amount, and (for cash-like methods) the cashbox ledger.
 * Voiding reverses all three without deleting anything.
 */
class PaymentService
{
    public function __construct(
        protected CashboxService $cashbox,
        protected NumberGenerator $numbers,
        protected AuditLogger $audit,
        protected NotificationService $notifications,
    ) {
    }

    /**
     * @param  array{
     *     trainee_id:int, trainee_package_id?:int|null, payment_method_id:int,
     *     amount:float, paid_on:string, source?:string,
     *     reference_number?:string|null, notes?:string|null
     * }  $data
     */
    public function record(array $data, ?int $branchId = null): Payment
    {
        return DB::transaction(function () use ($data, $branchId) {
            $trainee = Trainee::findOrFail($data['trainee_id']);
            $method = PaymentMethod::findOrFail($data['payment_method_id']);
            $branchId ??= $trainee->branch_id;

            $amount = round((float) $data['amount'], 2);

            if ($amount <= 0) {
                throw BusinessRuleException::make(
                    'قيمة الدفعة يجب أن تكون أكبر من صفر.',
                    ['amount' => ['قيمة الدفعة يجب أن تكون أكبر من صفر.']],
                );
            }

            if ($method->requires_reference && empty($data['reference_number'])) {
                throw BusinessRuleException::make(
                    'طريقة الدفع المختارة تتطلب رقم مرجع.',
                    ['reference_number' => ['رقم المرجع مطلوب لطريقة الدفع هذه.']],
                );
            }

            $package = null;

            if (! empty($data['trainee_package_id'])) {
                $package = TraineePackage::whereKey($data['trainee_package_id'])->lockForUpdate()->firstOrFail();

                if ($package->trainee_id !== $trainee->id) {
                    throw BusinessRuleException::make('الباقة المحددة لا تعود لهذا المتدرب.');
                }

                $this->assertNotOverpaying($package, $amount);
            }

            $payment = Payment::create([
                'branch_id' => $branchId,
                'receipt_number' => $this->numbers->paymentReceipt(),
                'trainee_id' => $trainee->id,
                'trainee_package_id' => $package?->id,
                'payment_method_id' => $method->id,
                'source' => $data['source'] ?? 'package',
                'amount' => $amount,
                'paid_on' => $data['paid_on'],
                'reference_number' => $data['reference_number'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => 'completed',
                'received_by' => auth()->id(),
            ]);

            if ($package) {
                $package->forceFill([
                    'paid_amount' => round((float) $package->paid_amount + $amount, 2),
                ])->save();
            }

            if ($method->movesCash()) {
                $this->cashbox->deposit(
                    cashbox: $this->cashbox->forBranch($branchId),
                    amount: $amount,
                    category: 'payment',
                    source: $payment,
                    description: "دفعة من {$trainee->full_name} — إيصال {$payment->receipt_number}",
                    date: $payment->paid_on,
                );
            }

            $this->audit->logCreate('payment.recorded', $payment, 'تسجيل دفعة من متدرب');
            $this->notifications->paymentRecorded($payment);

            return $payment->fresh(['trainee', 'paymentMethod', 'traineePackage']);
        });
    }

    /**
     * Void a payment.
     *
     * The row and its receipt number survive; the cash movement and the
     * enrolment's paid amount are reversed, and the reason is required.
     */
    public function void(Payment $payment, string $reason): Payment
    {
        $voided = DB::transaction(function () use ($payment, $reason) {
            if ($payment->isVoided()) {
                throw BusinessRuleException::make('هذه الدفعة ملغاة مسبقاً.');
            }

            $original = $payment->getOriginal();

            if ($payment->trainee_package_id) {
                $package = TraineePackage::whereKey($payment->trainee_package_id)->lockForUpdate()->first();

                if ($package) {
                    $package->forceFill([
                        'paid_amount' => round(max(0, (float) $package->paid_amount - (float) $payment->amount), 2),
                    ])->save();
                }
            }

            $this->cashbox->reverseFor($payment, "إلغاء دفعة {$payment->receipt_number}: {$reason}");

            $payment->update([
                'status' => 'voided',
                'void_reason' => $reason,
                'voided_by' => auth()->id(),
                'voided_at' => now(),
            ]);

            $this->audit->log(
                action: 'payment.voided',
                subject: $payment,
                before: ['status' => $original['status'], 'amount' => $original['amount']],
                after: ['status' => 'voided'],
                reason: $reason,
            );

            return $payment->fresh();
        });

        /*
         | Announced after the commit.
         |
         | A void moves money and adjusts what a trainee owes, so both the
         | finance staff and the trainee need to know. It is sent outside the
         | transaction because a slow provider must not hold locks on the payment,
         | the package and the cashbox — and a provider timeout must never roll
         | back a reversal that has already been recorded.
         */
        try {
            $this->notifications->paymentVoided($voided, $reason);
        } catch (\Throwable $e) {
            report($e);
        }

        return $voided;
    }

    /** Total still owed by a trainee across every active enrolment. */
    public function outstandingForTrainee(Trainee $trainee): float
    {
        return round(
            (float) $trainee->packages()
                ->whereIn('status', ['active', 'completed'])
                ->get()
                ->sum(fn (TraineePackage $p) => max(0, $p->remainingAmount())),
            2,
        );
    }

    protected function assertNotOverpaying(TraineePackage $package, float $amount): void
    {
        $remaining = $package->remainingAmount();

        if ($amount - $remaining > 0.009) {
            throw BusinessRuleException::make(
                sprintf('قيمة الدفعة تتجاوز المبلغ المتبقي على الباقة (%.2f).', $remaining),
                ['amount' => ['قيمة الدفعة أكبر من المبلغ المتبقي.']],
            );
        }
    }
}
