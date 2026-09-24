<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\AdvanceDeduction;
use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\ExpenseCategory;
use App\Models\Payroll;
use App\Models\PayrollPayment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Monthly salaries, advances and disbursement.
 *
 * Every figure is computed here from the employee record and the outstanding
 * advances; nothing is accepted from the client except bonuses and manual
 * deductions. Advance installments are taken under a row lock and capped at the
 * remaining balance, so an advance can never be over-collected.
 */
class PayrollService
{
    public function __construct(
        protected ExpenseService $expenses,
        protected CashboxService $cashbox,
        protected NumberGenerator $numbers,
        protected AuditLogger $audit,
        protected NotificationService $notifications,
    ) {
    }

    /**
     * Create or refresh one employee's payroll for a month.
     *
     * @param  string  $period  YYYY-MM
     * @param  array{bonuses?:float, deductions?:float, notes?:string|null, collect_advances?:bool}  $options
     */
    public function generate(Employee $employee, string $period, array $options = []): Payroll
    {
        return DB::transaction(function () use ($employee, $period, $options) {
            $this->assertValidPeriod($period);

            $payroll = Payroll::where('employee_id', $employee->id)->where('period', $period)->lockForUpdate()->first();

            if ($payroll && $payroll->isLocked()) {
                throw BusinessRuleException::make(
                    'لا يمكن إعادة احتساب راتب تم صرفه أو إلغاؤه. يمكن إنشاء تسوية بدلاً من ذلك.',
                );
            }

            $original = $payroll?->getOriginal();

            $base = round((float) $employee->base_salary, 2);
            $allowances = round((float) $employee->allowances, 2);
            $bonuses = round((float) ($options['bonuses'] ?? $payroll?->bonuses ?? 0), 2);
            $deductions = round((float) ($options['deductions'] ?? $payroll?->deductions ?? 0), 2);

            if ($bonuses < 0 || $deductions < 0) {
                throw BusinessRuleException::make('المكافآت والاستقطاعات لا يمكن أن تكون بقيم سالبة.');
            }

            $payroll = Payroll::updateOrCreate(
                ['employee_id' => $employee->id, 'period' => $period],
                [
                    'branch_id' => $employee->branch_id,
                    'base_salary' => $base,
                    'allowances' => $allowances,
                    'bonuses' => $bonuses,
                    'deductions' => $deductions,
                    'advance_deductions' => 0,
                    'net_salary' => 0,
                    'status' => 'unpaid',
                    'notes' => $options['notes'] ?? $payroll?->notes,
                    'created_by' => auth()->id(),
                ],
            );

            // Recalculating replaces any previous advance collection for this month.
            $this->releaseAdvanceDeductions($payroll);

            $advanceTotal = ($options['collect_advances'] ?? true)
                ? $this->collectAdvances($payroll, $employee, round($base + $allowances + $bonuses - $deductions, 2))
                : 0.0;

            $net = round($base + $allowances + $bonuses - $deductions - $advanceTotal, 2);

            if ($net < 0) {
                throw BusinessRuleException::make('صافي الراتب لا يمكن أن يكون سالباً. راجع الاستقطاعات.');
            }

            $payroll->forceFill([
                'advance_deductions' => $advanceTotal,
                'net_salary' => $net,
            ])->save();

            $original
                ? $this->audit->logUpdate('payroll.recalculated', $payroll, $original)
                : $this->audit->logCreate('payroll.created', $payroll, "إنشاء كشف راتب لشهر {$period}");

            return $payroll->fresh(['employee', 'advanceDeductions.advance']);
        });
    }

    /**
     * Generate payroll for every active employee at a branch.
     *
     * @return Collection<int, Payroll>
     */
    public function generateForBranch(int $branchId, string $period): Collection
    {
        return Employee::where('branch_id', $branchId)
            ->where('status', 'active')
            ->get()
            ->map(fn (Employee $employee) => $this->generate($employee, $period))
            ->values();
    }

    /**
     * Pay a salary, in full or in part.
     *
     * Writes the disbursement, books the expense, moves the cash and updates
     * the payroll status together.
     */
    public function pay(Payroll $payroll, float $amount, string $paidOn, int $paymentMethodId, ?string $reference = null, ?string $notes = null): PayrollPayment
    {
        return DB::transaction(function () use ($payroll, $amount, $paidOn, $paymentMethodId, $reference, $notes) {
            $payroll = Payroll::whereKey($payroll->id)->lockForUpdate()->firstOrFail();

            if ($payroll->isCancelled()) {
                throw BusinessRuleException::make('لا يمكن صرف راتب ملغى.');
            }

            $amount = round($amount, 2);
            $remaining = $payroll->remainingAmount();

            if ($amount <= 0) {
                throw BusinessRuleException::make(
                    'قيمة الصرف يجب أن تكون أكبر من صفر.',
                    ['amount' => ['قيمة الصرف يجب أن تكون أكبر من صفر.']],
                );
            }

            if ($amount - $remaining > 0.009) {
                throw BusinessRuleException::make(
                    sprintf('قيمة الصرف تتجاوز المبلغ المتبقي (%.2f).', $remaining),
                    ['amount' => ['المبلغ أكبر من المتبقي.']],
                );
            }

            $payment = PayrollPayment::create([
                'payroll_id' => $payroll->id,
                'branch_id' => $payroll->branch_id,
                'receipt_number' => $this->numbers->payrollReceipt(),
                'amount' => $amount,
                'paid_on' => $paidOn,
                'payment_method_id' => $paymentMethodId,
                'reference_number' => $reference,
                'paid_by' => auth()->id(),
                'notes' => $notes,
            ]);

            $expense = $this->expenses->record([
                'expense_category_id' => $this->salaryCategoryId(),
                'payment_method_id' => $paymentMethodId,
                'title' => 'راتب '.$payroll->employee->full_name.' — '.$payroll->period,
                'amount' => $amount,
                'spent_on' => $paidOn,
                'beneficiary' => $payroll->employee->full_name,
                'notes' => 'إيصال '.$payment->receipt_number,
            ], $payroll->branch_id, $payment);

            $payment->forceFill(['expense_id' => $expense->id])->save();

            $paid = round((float) $payroll->paid_amount + $amount, 2);

            $payroll->forceFill([
                'paid_amount' => $paid,
                'status' => $paid + 0.009 >= (float) $payroll->net_salary ? 'paid' : 'partially_paid',
            ])->save();

            $this->audit->log(
                action: 'payroll.paid',
                subject: $payroll,
                before: ['paid_amount' => (float) $payroll->paid_amount - $amount],
                after: ['paid_amount' => $paid, 'receipt_number' => $payment->receipt_number],
                description: 'صرف راتب',
            );

            return $payment->fresh(['payroll.employee', 'paymentMethod', 'expense']);
        });
    }

    // ------------------------------------------------------------------
    // Advances
    // ------------------------------------------------------------------

    /**
     * Grant an advance. The money leaves the cashbox immediately and the
     * repayment schedule starts with the next payroll run.
     */
    public function grantAdvance(Employee $employee, float $amount, string $grantedOn, int $installments, int $paymentMethodId, ?string $notes = null): EmployeeAdvance
    {
        return DB::transaction(function () use ($employee, $amount, $grantedOn, $installments, $paymentMethodId, $notes) {
            $amount = round($amount, 2);

            if ($amount <= 0) {
                throw BusinessRuleException::make('قيمة السلفة يجب أن تكون أكبر من صفر.');
            }

            if ($installments < 1) {
                throw BusinessRuleException::make('عدد الأقساط يجب أن يكون واحداً على الأقل.');
            }

            $advance = EmployeeAdvance::create([
                'branch_id' => $employee->branch_id,
                'employee_id' => $employee->id,
                'amount' => $amount,
                'granted_on' => $grantedOn,
                'installments_count' => $installments,
                'installment_amount' => round($amount / $installments, 2),
                'deducted_amount' => 0,
                'remaining_amount' => $amount,
                'payment_method_id' => $paymentMethodId,
                'status' => 'active',
                'notes' => $notes,
                'created_by' => auth()->id(),
            ]);

            $this->expenses->record([
                'expense_category_id' => $this->salaryCategoryId(),
                'payment_method_id' => $paymentMethodId,
                'title' => 'سلفة للموظف '.$employee->full_name,
                'amount' => $amount,
                'spent_on' => $grantedOn,
                'beneficiary' => $employee->full_name,
            ], $employee->branch_id, $advance);

            $this->audit->logCreate('advance.granted', $advance, 'منح سلفة لموظف');

            return $advance->fresh();
        });
    }

    /**
     * Take this month's installment from each active advance.
     *
     * Each installment is capped by both the advance's remaining balance and
     * what is left of the salary, so neither can go negative.
     */
    protected function collectAdvances(Payroll $payroll, Employee $employee, float $payableBeforeAdvances): float
    {
        $advances = EmployeeAdvance::where('employee_id', $employee->id)
            ->where('status', 'active')
            ->where('remaining_amount', '>', 0)
            ->orderBy('granted_on')
            ->lockForUpdate()
            ->get();

        $collected = 0.0;
        $headroom = $payableBeforeAdvances;

        foreach ($advances as $advance) {
            if ($headroom <= 0.009) {
                break;
            }

            $installment = min($advance->nextInstallment(), round($headroom, 2));

            if ($installment <= 0.009) {
                continue;
            }

            AdvanceDeduction::create([
                'employee_advance_id' => $advance->id,
                'payroll_id' => $payroll->id,
                'amount' => $installment,
            ]);

            $remaining = round((float) $advance->remaining_amount - $installment, 2);

            $advance->forceFill([
                'deducted_amount' => round((float) $advance->deducted_amount + $installment, 2),
                'remaining_amount' => max(0, $remaining),
                'status' => $remaining <= 0.009 ? 'settled' : 'active',
            ])->save();

            if ($advance->status === 'settled') {
                $this->notifications->advanceSettled($advance);
            }

            $collected = round($collected + $installment, 2);
            $headroom = round($headroom - $installment, 2);
        }

        return $collected;
    }

    /** Undo this payroll's advance collection, so it can be recalculated. */
    protected function releaseAdvanceDeductions(Payroll $payroll): void
    {
        $deductions = AdvanceDeduction::where('payroll_id', $payroll->id)->get();

        foreach ($deductions as $deduction) {
            $advance = EmployeeAdvance::whereKey($deduction->employee_advance_id)->lockForUpdate()->first();

            if ($advance) {
                $advance->forceFill([
                    'deducted_amount' => round(max(0, (float) $advance->deducted_amount - (float) $deduction->amount), 2),
                    'remaining_amount' => round((float) $advance->remaining_amount + (float) $deduction->amount, 2),
                    'status' => 'active',
                ])->save();
            }

            $deduction->delete();
        }
    }

    protected function assertValidPeriod(string $period): void
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
            throw BusinessRuleException::make('صيغة الشهر غير صحيحة. الصيغة المطلوبة: YYYY-MM.');
        }
    }

    protected function salaryCategoryId(): int
    {
        return (int) ExpenseCategory::where('code', 'employee_salaries')->value('id')
            ?: (int) ExpenseCategory::where('profit_bucket', 'salaries')->value('id');
    }
}
