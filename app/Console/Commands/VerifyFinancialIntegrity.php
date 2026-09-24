<?php

namespace App\Console\Commands;

use App\Models\Cashbox;
use App\Models\EmployeeAdvance;
use App\Models\TraineePackage;
use Illuminate\Console\Command;

/**
 * Checks that the derived financial figures still agree with their ledgers.
 *
 * Nothing here repairs data — it reports. A mismatch means a bug or a manual
 * database edit, and both deserve a person looking at them rather than an
 * automatic "fix" that hides the cause.
 *
 * Exits non-zero when something is wrong, so it can gate a deployment or wake
 * a monitor.
 */
class VerifyFinancialIntegrity extends Command
{
    protected $signature = 'finance:verify';

    protected $description = 'التحقق من تطابق الأرصدة المالية مع سجلاتها';

    public function handle(): int
    {
        $problems = array_merge(
            $this->checkCashboxes(),
            $this->checkLessonBalances(),
            $this->checkAdvances(),
            $this->checkPackagePayments(),
        );

        if (empty($problems)) {
            $this->info('كل الأرصدة مطابقة لسجلاتها.');

            return self::SUCCESS;
        }

        $this->error('تم العثور على '.count($problems).' مشكلة:');

        foreach ($problems as $problem) {
            $this->line('  - '.$problem);
        }

        return self::FAILURE;
    }

    /** @return array<int, string> */
    protected function checkCashboxes(): array
    {
        $problems = [];

        foreach (Cashbox::with('branch')->get() as $cashbox) {
            if (! $cashbox->isConsistent()) {
                $problems[] = sprintf(
                    'صندوق "%s" (%s): الرصيد المسجّل %.2f بينما مجموع الحركات %.2f',
                    $cashbox->name,
                    $cashbox->branch?->name ?? '—',
                    (float) $cashbox->current_balance,
                    $cashbox->recalculatedBalance(),
                );
            }
        }

        return $problems;
    }

    /** @return array<int, string> */
    protected function checkLessonBalances(): array
    {
        $problems = [];

        foreach (TraineePackage::with('trainee')->get() as $package) {
            $balance = $package->remainingLessons();

            if ($balance < 0) {
                $problems[] = sprintf(
                    'رصيد حصص سالب للمتدرب "%s": %d',
                    $package->trainee?->full_name ?? '—',
                    $balance,
                );
            }
        }

        return $problems;
    }

    /** @return array<int, string> */
    protected function checkAdvances(): array
    {
        $problems = [];

        foreach (EmployeeAdvance::with(['employee', 'deductions'])->get() as $advance) {
            $collected = round((float) $advance->deductions->sum('amount'), 2);
            $recorded = round((float) $advance->deducted_amount, 2);

            if (abs($collected - $recorded) > 0.01) {
                $problems[] = sprintf(
                    'سلفة الموظف "%s": المسجّل %.2f بينما مجموع الأقساط %.2f',
                    $advance->employee?->full_name ?? '—',
                    $recorded,
                    $collected,
                );
            }

            if ($collected - (float) $advance->amount > 0.01) {
                $problems[] = sprintf(
                    'سلفة الموظف "%s": تم استقطاع %.2f وهو أكثر من قيمة السلفة %.2f',
                    $advance->employee?->full_name ?? '—',
                    $collected,
                    (float) $advance->amount,
                );
            }
        }

        return $problems;
    }

    /** @return array<int, string> */
    protected function checkPackagePayments(): array
    {
        $problems = [];

        $packages = TraineePackage::with(['trainee', 'payments'])->get();

        foreach ($packages as $package) {
            $received = round((float) $package->payments->where('status', 'completed')->sum('amount'), 2);
            $recorded = round((float) $package->paid_amount, 2);

            if (abs($received - $recorded) > 0.01) {
                $problems[] = sprintf(
                    'باقة المتدرب "%s": المدفوع المسجّل %.2f بينما مجموع الدفعات %.2f',
                    $package->trainee?->full_name ?? '—',
                    $recorded,
                    $received,
                );
            }

            if ($recorded - (float) $package->total_amount > 0.01) {
                $problems[] = sprintf(
                    'باقة المتدرب "%s": المدفوع %.2f يتجاوز إجمالي العقد %.2f',
                    $package->trainee?->full_name ?? '—',
                    $recorded,
                    (float) $package->total_amount,
                );
            }
        }

        return $problems;
    }
}
