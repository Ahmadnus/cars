<?php

namespace Tests\Feature;

use App\Exceptions\BusinessRuleException;
use App\Models\Cashbox;
use App\Models\Employee;
use App\Models\ExpenseCategory;
use App\Models\Package;
use App\Models\PaymentMethod;
use App\Models\Trainee;
use App\Services\CashboxService;
use App\Services\ExpenseService;
use App\Services\PackageService;
use App\Services\PaymentService;
use App\Services\PayrollService;
use App\Services\ProfitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Money must never silently drift. These tests pin the invariants that make the
 * financial modules trustworthy: the cashbox always equals its ledger, voided
 * records reverse cleanly, advances cannot be over-collected, and profit is
 * computed from transactions rather than from displayed totals.
 */
class FinancialIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected PaymentMethod $cash;

    protected PaymentMethod $transfer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFoundation();
        $this->actingAsUser($this->admin());

        $this->cash = PaymentMethod::where('code', 'cash')->firstOrFail();
        $this->transfer = PaymentMethod::where('code', 'bank_transfer')->firstOrFail();
    }

    // ------------------------------------------------------------ payments

    public function test_a_cash_payment_increases_the_cashbox(): void
    {
        [$trainee, $enrolment] = $this->enrolled();
        $cashbox = app(CashboxService::class)->forBranch($this->branch);

        app(PaymentService::class)->record([
            'trainee_id' => $trainee->id,
            'trainee_package_id' => $enrolment->id,
            'payment_method_id' => $this->cash->id,
            'amount' => 100,
            'paid_on' => now()->toDateString(),
        ], $this->branch->id);

        $this->assertEqualsWithDelta(100.0, (float) $cashbox->fresh()->current_balance, 0.001);
        $this->assertEqualsWithDelta(100.0, (float) $enrolment->fresh()->paid_amount, 0.001);
    }

    public function test_a_bank_transfer_does_not_move_the_cashbox(): void
    {
        [$trainee, $enrolment] = $this->enrolled();
        $cashbox = app(CashboxService::class)->forBranch($this->branch);

        app(PaymentService::class)->record([
            'trainee_id' => $trainee->id,
            'trainee_package_id' => $enrolment->id,
            'payment_method_id' => $this->transfer->id,
            'amount' => 100,
            'paid_on' => now()->toDateString(),
            'reference_number' => 'TRX-1',
        ], $this->branch->id);

        $this->assertEqualsWithDelta(0.0, (float) $cashbox->fresh()->current_balance, 0.001);
        $this->assertEqualsWithDelta(100.0, (float) $enrolment->fresh()->paid_amount, 0.001);
    }

    public function test_a_payment_cannot_exceed_what_is_owed(): void
    {
        [$trainee, $enrolment] = $this->enrolled(); // 150 total

        $this->expectException(BusinessRuleException::class);

        app(PaymentService::class)->record([
            'trainee_id' => $trainee->id,
            'trainee_package_id' => $enrolment->id,
            'payment_method_id' => $this->cash->id,
            'amount' => 500,
            'paid_on' => now()->toDateString(),
        ], $this->branch->id);
    }

    public function test_voiding_a_payment_reverses_the_cash_and_the_paid_amount(): void
    {
        [$trainee, $enrolment] = $this->enrolled();
        $service = app(PaymentService::class);
        $cashbox = app(CashboxService::class)->forBranch($this->branch);

        $payment = $service->record([
            'trainee_id' => $trainee->id,
            'trainee_package_id' => $enrolment->id,
            'payment_method_id' => $this->cash->id,
            'amount' => 100,
            'paid_on' => now()->toDateString(),
        ], $this->branch->id);

        $service->void($payment, 'أُدخلت بالخطأ');

        $this->assertSame('voided', $payment->fresh()->status);
        $this->assertEqualsWithDelta(0.0, (float) $cashbox->fresh()->current_balance, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $enrolment->fresh()->paid_amount, 0.001);

        // The original row survives — nothing is deleted.
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'amount' => '100.00']);
        // And the ledger keeps both sides of the correction.
        $this->assertSame(2, $cashbox->fresh()->transactions()->count());
    }

    public function test_a_payment_cannot_be_voided_twice(): void
    {
        [$trainee, $enrolment] = $this->enrolled();
        $service = app(PaymentService::class);

        $payment = $service->record([
            'trainee_id' => $trainee->id,
            'trainee_package_id' => $enrolment->id,
            'payment_method_id' => $this->cash->id,
            'amount' => 50,
            'paid_on' => now()->toDateString(),
        ], $this->branch->id);

        $service->void($payment, 'خطأ');

        $this->expectException(BusinessRuleException::class);
        $service->void($payment->fresh(), 'مرة أخرى');
    }

    // ------------------------------------------------------------- cashbox

    public function test_the_cashbox_always_matches_its_ledger(): void
    {
        $service = app(CashboxService::class);
        $cashbox = $service->forBranch($this->branch);

        \Illuminate\Support\Facades\DB::transaction(function () use ($service, $cashbox) {
            $service->deposit($cashbox, 500, 'opening', description: 'رصيد افتتاحي');
            $service->withdraw($cashbox, 120, 'expense', description: 'مصروف');
            $service->deposit($cashbox, 75.50, 'payment', description: 'دفعة');
        });

        $cashbox->refresh();

        $this->assertEqualsWithDelta(455.50, (float) $cashbox->current_balance, 0.001);
        $this->assertTrue($cashbox->isConsistent());
    }

    public function test_every_ledger_row_records_the_resulting_balance(): void
    {
        $service = app(CashboxService::class);
        $cashbox = $service->forBranch($this->branch);

        \Illuminate\Support\Facades\DB::transaction(function () use ($service, $cashbox) {
            $service->deposit($cashbox, 200, 'opening');
            $service->withdraw($cashbox, 50, 'expense');
        });

        $balances = $cashbox->transactions()->orderBy('id')->pluck('balance_after')->map(fn ($v) => (float) $v);

        $this->assertSame([200.0, 150.0], $balances->all());
    }

    public function test_daily_closing_reports_a_difference_when_the_count_is_short(): void
    {
        $service = app(CashboxService::class);
        $cashbox = $service->forBranch($this->branch);

        \Illuminate\Support\Facades\DB::transaction(fn () => $service->deposit($cashbox, 300, 'payment'));

        $closing = $service->close($cashbox->fresh(), now(), countedBalance: 290);

        $this->assertEqualsWithDelta(300.0, (float) $closing->expected_balance, 0.001);
        $this->assertEqualsWithDelta(-10.0, (float) $closing->difference, 0.001);
        $this->assertFalse($closing->isBalanced());
    }

    public function test_the_cashbox_cannot_be_closed_twice_for_one_day(): void
    {
        $service = app(CashboxService::class);
        $cashbox = $service->forBranch($this->branch);

        $service->close($cashbox, now(), 0);

        $this->expectException(BusinessRuleException::class);
        $service->close($cashbox->fresh(), now(), 0);
    }

    // ------------------------------------------------------------- payroll

    public function test_payroll_computes_the_net_salary_server_side(): void
    {
        $employee = Employee::factory()->create([
            'branch_id' => $this->branch->id,
            'base_salary' => 600,
            'allowances' => 100,
        ]);

        $payroll = app(PayrollService::class)->generate($employee, now()->format('Y-m'), [
            'bonuses' => 50,
            'deductions' => 25,
        ]);

        // 600 + 100 + 50 - 25 = 725
        $this->assertEqualsWithDelta(725.0, (float) $payroll->net_salary, 0.001);
        $this->assertSame('unpaid', $payroll->status);
    }

    public function test_an_advance_is_collected_in_installments_and_never_over_deducted(): void
    {
        $employee = Employee::factory()->create([
            'branch_id' => $this->branch->id,
            'base_salary' => 600,
            'allowances' => 0,
        ]);

        $payrollService = app(PayrollService::class);

        // 300 over 3 months = 100 a month.
        $advance = $payrollService->grantAdvance($employee, 300, now()->toDateString(), 3, $this->cash->id);

        $first = $payrollService->generate($employee, now()->format('Y-m'));
        $this->assertEqualsWithDelta(100.0, (float) $first->advance_deductions, 0.001);
        $this->assertEqualsWithDelta(500.0, (float) $first->net_salary, 0.001);
        $this->assertEqualsWithDelta(200.0, (float) $advance->fresh()->remaining_amount, 0.001);

        $second = $payrollService->generate($employee, now()->addMonth()->format('Y-m'));
        $third = $payrollService->generate($employee, now()->addMonths(2)->format('Y-m'));
        $this->assertEqualsWithDelta(0.0, (float) $advance->fresh()->remaining_amount, 0.001);
        $this->assertSame('settled', $advance->fresh()->status);

        // A fourth month must collect nothing — the advance is settled.
        $fourth = $payrollService->generate($employee, now()->addMonths(3)->format('Y-m'));
        $this->assertEqualsWithDelta(0.0, (float) $fourth->advance_deductions, 0.001);
        $this->assertEqualsWithDelta(600.0, (float) $fourth->net_salary, 0.001);

        // Total collected equals the advance exactly, never more.
        $this->assertEqualsWithDelta(300.0, (float) $advance->fresh()->deducted_amount, 0.001);
    }

    public function test_recalculating_a_payroll_does_not_double_collect_an_advance(): void
    {
        $employee = Employee::factory()->create(['branch_id' => $this->branch->id, 'base_salary' => 600, 'allowances' => 0]);
        $service = app(PayrollService::class);

        $advance = $service->grantAdvance($employee, 300, now()->toDateString(), 3, $this->cash->id);

        $service->generate($employee, now()->format('Y-m'));
        $service->generate($employee, now()->format('Y-m'), ['bonuses' => 20]);

        // Still only one installment taken despite two calculation runs.
        $this->assertEqualsWithDelta(100.0, (float) $advance->fresh()->deducted_amount, 0.001);
        $this->assertEqualsWithDelta(200.0, (float) $advance->fresh()->remaining_amount, 0.001);
    }

    public function test_paying_a_salary_books_an_expense_and_moves_the_cash(): void
    {
        $employee = Employee::factory()->create(['branch_id' => $this->branch->id, 'base_salary' => 400, 'allowances' => 0]);
        $service = app(PayrollService::class);
        $cashbox = app(CashboxService::class)->forBranch($this->branch);

        $payroll = $service->generate($employee, now()->format('Y-m'));
        $payment = $service->pay($payroll, 400, now()->toDateString(), $this->cash->id);

        $this->assertSame('paid', $payroll->fresh()->status);
        $this->assertNotNull($payment->expense_id);
        $this->assertEqualsWithDelta(-400.0, (float) $cashbox->fresh()->current_balance, 0.001);
    }

    public function test_a_salary_cannot_be_overpaid(): void
    {
        $employee = Employee::factory()->create(['branch_id' => $this->branch->id, 'base_salary' => 400, 'allowances' => 0]);
        $service = app(PayrollService::class);

        $payroll = $service->generate($employee, now()->format('Y-m'));

        $this->expectException(BusinessRuleException::class);
        $service->pay($payroll, 500, now()->toDateString(), $this->cash->id);
    }

    // -------------------------------------------------------------- profit

    public function test_profit_is_revenue_minus_expenses_from_real_transactions(): void
    {
        [$trainee, $enrolment] = $this->enrolled();

        app(PaymentService::class)->record([
            'trainee_id' => $trainee->id,
            'trainee_package_id' => $enrolment->id,
            'payment_method_id' => $this->cash->id,
            'amount' => 150,
            'paid_on' => now()->toDateString(),
        ], $this->branch->id);

        app(ExpenseService::class)->record([
            'expense_category_id' => ExpenseCategory::where('code', 'rent')->value('id'),
            'payment_method_id' => $this->cash->id,
            'title' => 'إيجار',
            'amount' => 60,
            'spent_on' => now()->toDateString(),
        ], $this->branch->id);

        $summary = app(ProfitService::class)->summary(now()->startOfMonth(), now()->endOfMonth(), [$this->branch->id]);

        $this->assertEqualsWithDelta(150.0, $summary['revenue'], 0.001);
        $this->assertEqualsWithDelta(60.0, $summary['expenses'], 0.001);
        $this->assertEqualsWithDelta(90.0, $summary['net_profit'], 0.001);
    }

    public function test_voided_payments_and_cancelled_expenses_are_excluded_from_profit(): void
    {
        [$trainee, $enrolment] = $this->enrolled();
        $payments = app(PaymentService::class);
        $expenses = app(ExpenseService::class);

        $payment = $payments->record([
            'trainee_id' => $trainee->id,
            'trainee_package_id' => $enrolment->id,
            'payment_method_id' => $this->cash->id,
            'amount' => 150,
            'paid_on' => now()->toDateString(),
        ], $this->branch->id);

        $expense = $expenses->record([
            'expense_category_id' => ExpenseCategory::where('code', 'rent')->value('id'),
            'payment_method_id' => $this->cash->id,
            'title' => 'إيجار',
            'amount' => 60,
            'spent_on' => now()->toDateString(),
        ], $this->branch->id);

        $payments->void($payment, 'خطأ');
        $expenses->cancel($expense, 'خطأ');

        $summary = app(ProfitService::class)->summary(now()->startOfMonth(), now()->endOfMonth(), [$this->branch->id]);

        $this->assertEqualsWithDelta(0.0, $summary['revenue'], 0.001);
        $this->assertEqualsWithDelta(0.0, $summary['expenses'], 0.001);
        $this->assertEqualsWithDelta(0.0, $summary['net_profit'], 0.001);
    }

    // ------------------------------------------------------------------

    /** @return array{0: Trainee, 1: \App\Models\TraineePackage} */
    protected function enrolled(): array
    {
        $trainee = Trainee::factory()->create(['branch_id' => $this->branch->id]);
        $enrolment = app(PackageService::class)->assign($trainee, Package::factory()->create(['price' => 150]));

        return [$trainee->fresh(), $enrolment];
    }
}
