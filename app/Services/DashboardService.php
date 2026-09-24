<?php

namespace App\Services;

use App\Models\BookingRequest;
use App\Models\Cashbox;
use App\Models\EmployeeAdvance;
use App\Models\Payroll;
use App\Models\RecurringExpense;
use App\Models\Trainee;
use App\Models\TraineePackage;
use App\Models\TrainerCompensationRecord;
use App\Models\TrainingSession;
use App\Models\User;
use App\Models\UtilityBill;
use App\Support\BranchContext;
use Illuminate\Support\Facades\DB;

/**
 * Assembles the dashboard.
 *
 * Each block is gated on the permission it needs and simply absent when the
 * user lacks it — the figures are never computed and never reach the response,
 * so a receptionist's page contains no profit data to hide. Branch scoping
 * comes from the user's grants, not from the request.
 */
class DashboardService
{
    public function __construct(
        protected ProfitService $profit,
        protected BranchContext $branchContext,
        protected SettingsRepository $settings,
    ) {
    }

    public function build(User $user): array
    {
        $branchIds = $this->branchContext->scopeIds($user) ?: [0];

        $data = [
            'today' => $this->today($branchIds),
            'training' => $this->trainingStats($branchIds),
        ];

        if ($user->hasPermission('dashboard.financials')) {
            $data['finance'] = $this->finance($branchIds, $user);
        }

        $data['pending'] = $this->pending($branchIds, $user);
        $data['charts'] = $this->charts($branchIds, $user);

        return $data;
    }

    // ------------------------------------------------------------------
    // Blocks
    // ------------------------------------------------------------------

    protected function today(array $branchIds): array
    {
        $today = now()->toDateString();

        $sessions = TrainingSession::query()
            ->whereIn('branch_id', $branchIds)
            ->whereDate('scheduled_date', $today)
            ->selectRaw('status, COUNT(*) as total, COUNT(DISTINCT trainee_id) as trainees, COUNT(DISTINCT trainer_id) as trainers')
            ->groupBy('status')
            ->get();

        $upcoming = TrainingSession::query()
            ->whereIn('branch_id', $branchIds)
            ->scheduled()
            ->whereDate('scheduled_date', $today)
            ->where('start_time', '>=', now()->format('H:i:s'))
            ->with(['trainee:id,full_name', 'trainer:id,full_name', 'vehicle:id,name'])
            ->orderBy('start_time')
            ->limit(6)
            ->get();

        return [
            'lessons_total' => (int) $sessions->sum('total'),
            'lessons_completed' => (int) $sessions->firstWhere('status', 'completed')?->total ?? 0,
            'lessons_scheduled' => (int) $sessions->firstWhere('status', 'scheduled')?->total ?? 0,
            'trainees_count' => (int) TrainingSession::whereIn('branch_id', $branchIds)
                ->whereDate('scheduled_date', $today)->distinct('trainee_id')->count('trainee_id'),
            'trainers_count' => (int) TrainingSession::whereIn('branch_id', $branchIds)
                ->whereDate('scheduled_date', $today)->distinct('trainer_id')->count('trainer_id'),
            'upcoming' => $upcoming,
        ];
    }

    protected function trainingStats(array $branchIds): array
    {
        $counts = Trainee::query()
            ->whereIn('branch_id', $branchIds)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $active = collect($counts)->except(Trainee::CLOSED_STATUSES)->sum();

        return [
            'active' => (int) $active,
            'new_this_month' => (int) Trainee::whereIn('branch_id', $branchIds)
                ->where('registration_date', '>=', now()->startOfMonth()->toDateString())
                ->count(),
            'ready_for_exam' => (int) ($counts['ready_for_exam'] ?? 0),
            'exam_scheduled' => (int) ($counts['exam_scheduled'] ?? 0),
            'completed' => (int) ($counts['completed'] ?? 0) + (int) ($counts['passed'] ?? 0),
            'by_status' => $counts,
        ];
    }

    protected function finance(array $branchIds, User $user): array
    {
        $today = now();
        $monthStart = now()->startOfMonth();
        $monthEnd = now()->endOfMonth();

        $finance = [
            'revenue_today' => $this->profit->revenue($today, $today, $branchIds),
            'expenses_today' => $this->profit->expenses($today, $today, $branchIds),
            'revenue_month' => $this->profit->revenue($monthStart, $monthEnd, $branchIds),
            'expenses_month' => $this->profit->expenses($monthStart, $monthEnd, $branchIds),
        ];

        $finance['net_month'] = $user->hasPermission('profit.view')
            ? round($finance['revenue_month'] - $finance['expenses_month'], 2)
            : null;

        $finance['cashbox_balance'] = $user->hasPermission('cashbox.view')
            ? round((float) Cashbox::whereIn('branch_id', $branchIds)->sum('current_balance'), 2)
            : null;

        return $finance;
    }

    protected function pending(array $branchIds, User $user): array
    {
        $pending = [];

        if ($user->hasPermission('booking_requests.manage')) {
            $pending['booking_requests'] = (int) BookingRequest::whereIn('branch_id', $branchIds)->pending()->count();
        }

        if ($user->hasPermission('trainees.financial') || $user->hasPermission('payments.view')) {
            $pending['trainee_debts'] = round((float) TraineePackage::whereIn('branch_id', $branchIds)
                ->whereIn('status', ['active', 'completed'])
                ->whereColumn('total_amount', '>', 'paid_amount')
                ->sum(DB::raw('total_amount - paid_amount')), 2);
        }

        if ($user->hasPermission('payroll.view')) {
            $pending['payroll_due'] = round((float) Payroll::whereIn('branch_id', $branchIds)
                ->outstanding()
                ->sum(DB::raw('net_salary - paid_amount')), 2);
        }

        if ($user->hasPermission('trainer_compensation.view')) {
            $pending['trainer_compensation_due'] = round((float) TrainerCompensationRecord::whereIn('branch_id', $branchIds)
                ->outstanding()
                ->sum(DB::raw('net_amount - paid_amount')), 2);
        }

        if ($user->hasPermission('expenses.view')) {
            $pending['recurring_due'] = RecurringExpense::whereIn('branch_id', $branchIds)
                ->active()
                ->get()
                ->filter(fn (RecurringExpense $e) => $e->isDue())
                ->count();

            $pending['utility_bills_due'] = (int) UtilityBill::whereIn('branch_id', $branchIds)
                ->unpaid()
                ->where('due_date', '<=', now()->addDays(7)->toDateString())
                ->count();
        }

        if ($user->hasPermission('advances.manage')) {
            $pending['advances_outstanding'] = round((float) EmployeeAdvance::whereIn('branch_id', $branchIds)
                ->active()
                ->sum('remaining_amount'), 2);
        }

        $pending['low_balance_trainees'] = $this->lowBalanceCount($branchIds);

        return $pending;
    }

    protected function charts(array $branchIds, User $user): array
    {
        $charts = [
            'sessions' => $this->sessionsSeries($branchIds),
            'trainee_growth' => $this->traineeGrowthSeries($branchIds),
        ];

        if ($user->hasPermission('dashboard.financials')) {
            $charts['revenue_expenses'] = $this->profit->monthlySeries(
                now()->subMonths(5)->startOfMonth(),
                now()->endOfMonth(),
                $branchIds,
            );
        }

        if ($user->hasPermission('reports.financial')) {
            $charts['expense_categories'] = array_slice(
                $this->profit->expensesByCategory(now()->startOfMonth(), now()->endOfMonth(), $branchIds),
                0,
                6,
            );
        }

        return $charts;
    }

    // ------------------------------------------------------------------
    // Series
    // ------------------------------------------------------------------

    protected function sessionsSeries(array $branchIds): array
    {
        $rows = TrainingSession::query()
            ->whereIn('branch_id', $branchIds)
            ->where('scheduled_date', '>=', now()->subDays(29)->toDateString())
            ->selectRaw('scheduled_date as day, COUNT(*) as total')
            ->groupBy('day')
            ->pluck('total', 'day');

        $series = [];
        $cursor = now()->subDays(29)->startOfDay();

        while ($cursor->lte(now())) {
            $key = $cursor->toDateString();
            $series[] = ['date' => $key, 'total' => (int) ($rows[$key] ?? 0)];
            $cursor->addDay();
        }

        return $series;
    }

    protected function traineeGrowthSeries(array $branchIds): array
    {
        $rows = Trainee::query()
            ->whereIn('branch_id', $branchIds)
            ->where('registration_date', '>=', now()->subMonths(5)->startOfMonth()->toDateString())
            ->selectRaw("DATE_FORMAT(registration_date, '%Y-%m') as period, COUNT(*) as total")
            ->groupBy('period')
            ->pluck('total', 'period');

        $series = [];
        $cursor = now()->subMonths(5)->startOfMonth();

        while ($cursor->lte(now())) {
            $key = $cursor->format('Y-m');
            $series[] = ['period' => $key, 'total' => (int) ($rows[$key] ?? 0)];
            $cursor->addMonth();
        }

        return $series;
    }

    /** Active enrolments whose remaining lessons have dropped to the threshold. */
    protected function lowBalanceCount(array $branchIds): int
    {
        $threshold = $this->settings->int('training.low_balance_threshold', 2);

        return (int) TraineePackage::query()
            ->whereIn('trainee_packages.branch_id', $branchIds)
            ->where('trainee_packages.status', 'active')
            ->leftJoin('lesson_transactions', 'trainee_packages.id', '=', 'lesson_transactions.trainee_package_id')
            ->groupBy('trainee_packages.id')
            ->havingRaw('COALESCE(SUM(lesson_transactions.quantity), 0) BETWEEN 1 AND ?', [$threshold])
            ->get(['trainee_packages.id'])
            ->count();
    }
}
