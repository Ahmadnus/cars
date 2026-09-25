<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\ExpenseCategory;
use App\Models\Trainer;
use App\Models\TrainerCompensationPayment;
use App\Models\TrainerCompensationRecord;
use App\Models\TrainerCompensationRule;
use App\Models\TrainingSession;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Trainer pay: the five compensation models, statements and payouts.
 *
 * Every figure is derived on the server from completed lessons and the rule in
 * force during the period. Nothing here trusts a client-supplied total, and a
 * statement can only be recalculated while it is still a draft.
 */
class TrainerCompensationService
{
    public function __construct(
        protected ExpenseService $expenses,
        protected NumberGenerator $numbers,
        protected AuditLogger $audit,
    ) {
    }

    /**
     * Build or refresh a trainer's statement for one month.
     *
     * @param  string  $period  YYYY-MM
     * @param  array{bonuses?:float, deductions?:float, notes?:string|null}  $options
     */
    public function calculate(Trainer $trainer, string $period, array $options = []): TrainerCompensationRecord
    {
        return DB::transaction(function () use ($trainer, $period, $options) {
            $this->assertValidPeriod($period);

            $record = TrainerCompensationRecord::where('trainer_id', $trainer->id)
                ->where('period', $period)
                ->lockForUpdate()
                ->first();

            if ($record && ! $record->isDraft()) {
                throw BusinessRuleException::make(
                    'لا يمكن إعادة احتساب كشف معتمد أو مصروف. يجب إلغاؤه أولاً.',
                );
            }

            $original = $record?->getOriginal();

            [$start, $end] = $this->periodBounds($period);
            $rule = $trainer->ruleOn($end);

            if (! $rule) {
                throw BusinessRuleException::make(
                    'لا توجد قاعدة أجور فعّالة لهذا المدرب في الفترة المحددة.',
                );
            }

            $figures = $this->compute(
                $rule,
                $this->lessonStats($trainer, $start, $end),
                (float) ($options['bonuses'] ?? $record?->bonuses ?? 0),
                (float) ($options['deductions'] ?? $record?->deductions ?? 0),
            );

            $record = TrainerCompensationRecord::updateOrCreate(
                ['trainer_id' => $trainer->id, 'period' => $period],
                $figures + [
                    'branch_id' => $trainer->branch_id,
                    'trainer_compensation_rule_id' => $rule->id,
                    'status' => 'draft',
                    'notes' => $options['notes'] ?? $record?->notes,
                    'created_by' => auth()->id(),
                ],
            );

            $original
                ? $this->audit->logUpdate('trainer_compensation.recalculated', $record, $original)
                : $this->audit->logCreate('trainer_compensation.created', $record, "احتساب أجر المدرب لشهر {$period}");

            return $record->fresh(['trainer', 'rule']);
        });
    }

    /** @return Collection<int, TrainerCompensationRecord> */
    public function calculateForBranch(int $branchId, string $period): Collection
    {
        return Trainer::where('branch_id', $branchId)
            ->where('status', 'active')
            ->get()
            ->map(function (Trainer $trainer) use ($period) {
                try {
                    return $this->calculate($trainer, $period);
                } catch (BusinessRuleException) {
                    // A trainer with no rule in force is skipped rather than
                    // failing the whole batch; the UI reports the gap.
                    return null;
                }
            })
            ->filter()
            ->values();
    }

    /** Freeze a statement so it can be paid. */
    public function approve(TrainerCompensationRecord $record): TrainerCompensationRecord
    {
        return DB::transaction(function () use ($record) {
            if (! $record->isDraft()) {
                throw BusinessRuleException::make('تم اعتماد هذا الكشف مسبقاً.');
            }

            $record->update(['status' => 'approved']);

            $this->audit->log(
                action: 'trainer_compensation.approved',
                subject: $record,
                after: ['net_amount' => $record->net_amount],
                description: 'اعتماد كشف أجر مدرب',
            );

            return $record->fresh();
        });
    }

    /** Pay a statement, in full or in part. */
    public function pay(TrainerCompensationRecord $record, float $amount, string $paidOn, int $paymentMethodId, ?string $reference = null, ?string $notes = null): TrainerCompensationPayment
    {
        return DB::transaction(function () use ($record, $amount, $paidOn, $paymentMethodId, $reference, $notes) {
            $record = TrainerCompensationRecord::whereKey($record->id)->lockForUpdate()->firstOrFail();

            if (! $record->isPayable()) {
                throw BusinessRuleException::make('يجب اعتماد الكشف قبل الصرف.');
            }

            $amount = round($amount, 2);
            $remaining = $record->remainingAmount();

            if ($amount <= 0) {
                throw BusinessRuleException::make('قيمة الصرف يجب أن تكون أكبر من صفر.');
            }

            if ($amount - $remaining > 0.009) {
                throw BusinessRuleException::make(
                    sprintf('قيمة الصرف تتجاوز المبلغ المتبقي (%.2f).', $remaining),
                );
            }

            $payment = TrainerCompensationPayment::create([
                'trainer_compensation_record_id' => $record->id,
                'branch_id' => $record->branch_id,
                'receipt_number' => $this->numbers->trainerPayoutReceipt(),
                'amount' => $amount,
                'paid_on' => $paidOn,
                'payment_method_id' => $paymentMethodId,
                'reference_number' => $reference,
                'paid_by' => auth()->id(),
                'notes' => $notes,
            ]);

            $expense = $this->expenses->record([
                'expense_category_id' => $this->compensationCategoryId(),
                'payment_method_id' => $paymentMethodId,
                'title' => 'أجر المدرب '.$record->trainer->full_name.' — '.$record->period,
                'amount' => $amount,
                'spent_on' => $paidOn,
                'beneficiary' => $record->trainer->full_name,
                'notes' => 'إيصال '.$payment->receipt_number,
            ], $record->branch_id, $payment);

            $payment->forceFill(['expense_id' => $expense->id])->save();

            $paid = round((float) $record->paid_amount + $amount, 2);

            $record->forceFill([
                'paid_amount' => $paid,
                'status' => $paid + 0.009 >= (float) $record->net_amount ? 'paid' : 'partially_paid',
            ])->save();

            $this->audit->log(
                action: 'trainer_compensation.paid',
                subject: $record,
                before: ['paid_amount' => $paid - $amount],
                after: ['paid_amount' => $paid, 'receipt_number' => $payment->receipt_number],
                description: 'صرف أجر مدرب',
            );

            return $payment->fresh(['record.trainer', 'paymentMethod']);
        });
    }

    /**
     * Open a new rule and close the previous one the day before.
     *
     * Rules are versioned rather than edited so historical statements always
     * reproduce from the rule that was actually in force.
     */
    public function setRule(Trainer $trainer, array $data): TrainerCompensationRule
    {
        return DB::transaction(function () use ($trainer, $data) {
            $effectiveFrom = Carbon::parse($data['effective_from']);

            $current = $trainer->compensationRules()
                ->whereNull('effective_to')
                ->orderByDesc('effective_from')
                ->lockForUpdate()
                ->first();

            if ($current) {
                if ($effectiveFrom->lte($current->effective_from)) {
                    throw BusinessRuleException::make(
                        'تاريخ سريان القاعدة الجديدة يجب أن يكون بعد تاريخ القاعدة الحالية.',
                    );
                }

                $current->update(['effective_to' => $effectiveFrom->copy()->subDay()->toDateString()]);
            }

            $rule = TrainerCompensationRule::create([
                'branch_id' => $trainer->branch_id,
                'trainer_id' => $trainer->id,
                'model' => $data['model'],
                'base_salary' => round((float) ($data['base_salary'] ?? 0), 2),
                'per_lesson_rate' => round((float) ($data['per_lesson_rate'] ?? 0), 2),
                'revenue_percentage' => round((float) ($data['revenue_percentage'] ?? 0), 2),
                'effective_from' => $effectiveFrom->toDateString(),
                'notes' => $data['notes'] ?? null,
                'created_by' => auth()->id(),
            ]);

            $this->audit->log(
                action: 'trainer_compensation.rule_changed',
                subject: $rule,
                before: $current ? $current->only(['model', 'base_salary', 'per_lesson_rate', 'revenue_percentage']) : null,
                after: $rule->only(['model', 'base_salary', 'per_lesson_rate', 'revenue_percentage']),
                description: 'تغيير قاعدة أجر المدرب',
            );

            return $rule;
        });
    }

    /**
     * Lessons delivered and revenue attributable to a trainer in a period.
     *
     * Revenue is attributed per completed lesson at the enrolment's unit price,
     * which is what the trainee actually contracted to pay — not the current
     * catalogue price.
     *
     * @return array{lessons: int, minutes: int, revenue: float}
     */
    /**
     * Apply one compensation rule to one month's lesson figures.
     *
     * Pure: it writes nothing and reads nothing beyond its arguments, which is
     * what lets the trainer's own app show a live running total for the month
     * in progress without creating a draft statement the office did not ask
     * for. `calculate()` persists exactly what this returns, so the preview a
     * trainer sees and the statement the office issues can never disagree.
     *
     * @param  array{lessons:int, minutes:int, revenue:float}  $stats
     * @return array<string, mixed> Columns of trainer_compensation_records
     */
    public function compute(
        TrainerCompensationRule $rule,
        array $stats,
        float $bonuses = 0,
        float $deductions = 0,
    ): array {
        $bonuses = round($bonuses, 2);
        $deductions = round($deductions, 2);

        if ($bonuses < 0 || $deductions < 0) {
            throw BusinessRuleException::make('المكافآت والاستقطاعات لا يمكن أن تكون بقيم سالبة.');
        }

        $baseSalary = $rule->usesSalary() ? round((float) $rule->base_salary, 2) : 0.0;

        $lessonEarnings = $rule->usesPerLesson()
            ? round((float) $rule->per_lesson_rate * $stats['lessons'], 2)
            : 0.0;

        $percentageEarnings = $rule->usesPercentage()
            ? round($stats['revenue'] * (float) $rule->revenue_percentage / 100, 2)
            : 0.0;

        $gross = round($baseSalary + $lessonEarnings + $percentageEarnings + $bonuses, 2);
        $net = round($gross - $deductions, 2);

        if ($net < 0) {
            throw BusinessRuleException::make('صافي أجر المدرب لا يمكن أن يكون سالباً.');
        }

        return [
            'model' => $rule->model,
            'lessons_count' => $stats['lessons'],
            'training_minutes' => $stats['minutes'],
            'attributed_revenue' => $stats['revenue'],
            'base_salary' => $baseSalary,
            'lesson_earnings' => $lessonEarnings,
            'percentage_earnings' => $percentageEarnings,
            'bonuses' => $bonuses,
            'deductions' => $deductions,
            'gross_amount' => $gross,
            'net_amount' => $net,
        ];
    }

    public function lessonStats(Trainer $trainer, Carbon $start, Carbon $end): array
    {
        $row = TrainingSession::query()
            // Columns are qualified because of the join below.
            ->where('training_sessions.trainer_id', $trainer->id)
            ->where('training_sessions.status', 'completed')
            ->whereBetween('training_sessions.scheduled_date', [$start->toDateString(), $end->toDateString()])
            ->leftJoin('trainee_packages', 'training_sessions.trainee_package_id', '=', 'trainee_packages.id')
            ->selectRaw('COUNT(*) as lessons')
            ->selectRaw('COALESCE(SUM(training_sessions.duration_minutes), 0) as minutes')
            ->selectRaw('COALESCE(SUM(trainee_packages.unit_price), 0) as revenue')
            ->first();

        return [
            'lessons' => (int) ($row->lessons ?? 0),
            'minutes' => (int) ($row->minutes ?? 0),
            'revenue' => round((float) ($row->revenue ?? 0), 2),
        ];
    }

    /** @return array{0: Carbon, 1: Carbon} */
    public function periodBounds(string $period): array
    {
        $start = Carbon::createFromFormat('Y-m-d', $period.'-01')->startOfMonth();

        return [$start, $start->copy()->endOfMonth()];
    }

    protected function assertValidPeriod(string $period): void
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
            throw BusinessRuleException::make('صيغة الشهر غير صحيحة. الصيغة المطلوبة: YYYY-MM.');
        }
    }

    protected function compensationCategoryId(): int
    {
        return (int) ExpenseCategory::where('code', 'trainer_compensation')->value('id')
            ?: (int) ExpenseCategory::where('profit_bucket', 'trainer_compensation')->value('id');
    }
}
