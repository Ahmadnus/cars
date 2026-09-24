<?php

namespace App\Console\Commands;

use App\Models\Payroll;
use App\Models\TraineePackage;
use App\Models\UtilityBill;
use App\Services\NotificationService;
use App\Services\SettingsRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Daily sweep for things that need a human: low lesson balances, overdue
 * utility bills and unpaid salaries around pay day.
 */
class CheckFinancialAlerts extends Command
{
    protected $signature = 'alerts:daily';

    protected $description = 'فحص يومي للتنبيهات: رصيد الحصص، الفواتير المستحقة، الرواتب';

    public function handle(NotificationService $notifications, SettingsRepository $settings): int
    {
        $this->markOverdueBills();
        $this->lowLessonBalances($notifications, $settings);
        $this->dueUtilityBills($notifications);
        $this->duePayrolls($notifications, $settings);

        return self::SUCCESS;
    }

    /** Flip unpaid bills past their due date to "overdue". */
    protected function markOverdueBills(): void
    {
        $count = UtilityBill::query()
            ->where('status', 'unpaid')
            ->where('due_date', '<', now()->toDateString())
            ->update(['status' => 'overdue']);

        if ($count > 0) {
            $this->line("تم تعليم {$count} فاتورة كمتأخرة.");
        }
    }

    protected function lowLessonBalances(NotificationService $notifications, SettingsRepository $settings): void
    {
        $threshold = $settings->int('training.low_balance_threshold', 2);

        // Enrolments whose ledger sum has fallen to the threshold but is not
        // yet exhausted — an exhausted package is a different conversation.
        $packages = TraineePackage::query()
            ->where('trainee_packages.status', 'active')
            ->leftJoin('lesson_transactions', 'trainee_packages.id', '=', 'lesson_transactions.trainee_package_id')
            ->groupBy('trainee_packages.id')
            ->havingRaw('COALESCE(SUM(lesson_transactions.quantity), 0) BETWEEN 1 AND ?', [$threshold])
            ->select('trainee_packages.id')
            ->pluck('trainee_packages.id');

        $notified = 0;

        foreach (TraineePackage::with('trainee.user')->whereIn('id', $packages)->get() as $package) {
            $notifications->lowLessonBalance($package, $package->remainingLessons());
            $notified++;
        }

        $this->line("تم التنبيه على {$notified} متدرب برصيد منخفض.");
    }

    protected function dueUtilityBills(NotificationService $notifications): void
    {
        $bills = UtilityBill::query()
            ->unpaid()
            ->whereBetween('due_date', [now()->toDateString(), now()->addDays(3)->toDateString()])
            ->get();

        foreach ($bills as $bill) {
            $notifications->utilityBillDue($bill);
        }

        $this->line("تم التنبيه على {$bills->count()} فاتورة خدمات مستحقة.");
    }

    protected function duePayrolls(NotificationService $notifications, SettingsRepository $settings): void
    {
        // Only nag from pay day onwards; before that the salaries are not late.
        if (now()->day < $settings->int('payroll.pay_day', 1)) {
            return;
        }

        $payrolls = Payroll::query()
            ->outstanding()
            ->where('period', now()->subMonth()->format('Y-m'))
            ->with('employee')
            ->get();

        foreach ($payrolls as $payroll) {
            $notifications->payrollDue($payroll);
        }

        $this->line("تم التنبيه على {$payrolls->count()} راتب مستحق الصرف.");
    }
}
