<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Payment;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Profit and loss, computed from actual transactions.
 *
 * Nothing here reads a dashboard figure or a cached total: revenue is the sum
 * of completed payments, costs are the sum of recorded expenses, and voided or
 * cancelled rows are excluded. The same method backs the screen, the API and
 * the PDF, so the three can never disagree.
 */
class ProfitService
{
    /**
     * @param  array<int, int>|null  $branchIds  null = every branch the caller may see
     * @return array{
     *     revenue: float, expenses: float, net_profit: float, margin: float,
     *     buckets: array<string, array{label: string, amount: float, percent: float}>,
     *     from: string, to: string
     * }
     */
    public function summary(CarbonInterface $from, CarbonInterface $to, ?array $branchIds = null): array
    {
        $revenue = $this->revenue($from, $to, $branchIds);
        $buckets = $this->expenseBuckets($from, $to, $branchIds);
        $expenses = round(array_sum(array_column($buckets, 'amount')), 2);
        $net = round($revenue - $expenses, 2);

        foreach ($buckets as $key => $bucket) {
            $buckets[$key]['percent'] = $expenses > 0 ? round($bucket['amount'] / $expenses * 100, 1) : 0.0;
        }

        return [
            'revenue' => $revenue,
            'expenses' => $expenses,
            'net_profit' => $net,
            'margin' => $revenue > 0 ? round($net / $revenue * 100, 1) : 0.0,
            'buckets' => $buckets,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
        ];
    }

    public function revenue(CarbonInterface $from, CarbonInterface $to, ?array $branchIds = null): float
    {
        return round((float) $this->scopedPayments($branchIds)
            ->whereBetween('paid_on', [$from->toDateString(), $to->toDateString()])
            ->sum('amount'), 2);
    }

    public function expenses(CarbonInterface $from, CarbonInterface $to, ?array $branchIds = null): float
    {
        return round((float) $this->scopedExpenses($branchIds)
            ->whereBetween('spent_on', [$from->toDateString(), $to->toDateString()])
            ->sum('amount'), 2);
    }

    /**
     * Expenses grouped into the fixed P&L buckets.
     *
     * Buckets with no spend are still returned at zero so the statement keeps
     * the same shape from month to month.
     *
     * @return array<string, array{label: string, amount: float, percent: float}>
     */
    public function expenseBuckets(CarbonInterface $from, CarbonInterface $to, ?array $branchIds = null): array
    {
        $rows = $this->scopedExpenses($branchIds)
            ->whereBetween('expenses.spent_on', [$from->toDateString(), $to->toDateString()])
            ->join('expense_categories', 'expenses.expense_category_id', '=', 'expense_categories.id')
            ->selectRaw('expense_categories.profit_bucket as bucket, SUM(expenses.amount) as total')
            ->groupBy('bucket')
            ->pluck('total', 'bucket');

        $buckets = [];

        foreach (ExpenseCategory::BUCKETS as $key => $label) {
            $buckets[$key] = [
                'label' => $label,
                'amount' => round((float) ($rows[$key] ?? 0), 2),
                'percent' => 0.0,
            ];
        }

        return $buckets;
    }

    /**
     * Spend by category, for the category breakdown report.
     *
     * @return array<int, array{category: string, bucket: string, amount: float, percent: float}>
     */
    public function expensesByCategory(CarbonInterface $from, CarbonInterface $to, ?array $branchIds = null): array
    {
        $rows = $this->scopedExpenses($branchIds)
            ->whereBetween('expenses.spent_on', [$from->toDateString(), $to->toDateString()])
            ->join('expense_categories', 'expenses.expense_category_id', '=', 'expense_categories.id')
            ->selectRaw('expense_categories.name_ar as category, expense_categories.profit_bucket as bucket, SUM(expenses.amount) as total')
            ->groupBy('category', 'bucket')
            ->orderByDesc('total')
            ->get();

        $total = round((float) $rows->sum('total'), 2);

        return $rows->map(fn ($row) => [
            'category' => $row->category,
            'bucket' => $row->bucket,
            'amount' => round((float) $row->total, 2),
            'percent' => $total > 0 ? round((float) $row->total / $total * 100, 1) : 0.0,
        ])->all();
    }

    /**
     * Revenue, expenses and profit per month across a range — the series behind
     * the dashboard chart.
     *
     * @return array<int, array{period: string, revenue: float, expenses: float, profit: float}>
     */
    public function monthlySeries(CarbonInterface $from, CarbonInterface $to, ?array $branchIds = null): array
    {
        $revenue = $this->scopedPayments($branchIds)
            ->whereBetween('paid_on', [$from->toDateString(), $to->toDateString()])
            ->selectRaw("DATE_FORMAT(paid_on, '%Y-%m') as period, SUM(amount) as total")
            ->groupBy('period')
            ->pluck('total', 'period');

        $expenses = $this->scopedExpenses($branchIds)
            ->whereBetween('spent_on', [$from->toDateString(), $to->toDateString()])
            ->selectRaw("DATE_FORMAT(spent_on, '%Y-%m') as period, SUM(amount) as total")
            ->groupBy('period')
            ->pluck('total', 'period');

        $series = [];
        $cursor = $from->copy()->startOfMonth();
        $last = $to->copy()->startOfMonth();

        while ($cursor->lte($last)) {
            $key = $cursor->format('Y-m');
            $r = round((float) ($revenue[$key] ?? 0), 2);
            $e = round((float) ($expenses[$key] ?? 0), 2);

            $series[] = [
                'period' => $key,
                'revenue' => $r,
                'expenses' => $e,
                'profit' => round($r - $e, 2),
            ];

            $cursor->addMonth();
        }

        return $series;
    }

    /**
     * Daily revenue and expenses across a range.
     *
     * @return array<int, array{date: string, revenue: float, expenses: float, profit: float}>
     */
    public function dailySeries(CarbonInterface $from, CarbonInterface $to, ?array $branchIds = null): array
    {
        $revenue = $this->scopedPayments($branchIds)
            ->whereBetween('paid_on', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('paid_on as day, SUM(amount) as total')
            ->groupBy('day')
            ->pluck('total', 'day');

        $expenses = $this->scopedExpenses($branchIds)
            ->whereBetween('spent_on', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('spent_on as day, SUM(amount) as total')
            ->groupBy('day')
            ->pluck('total', 'day');

        $series = [];
        $cursor = $from->copy()->startOfDay();

        while ($cursor->lte($to)) {
            $key = $cursor->toDateString();
            $r = round((float) ($revenue[$key] ?? 0), 2);
            $e = round((float) ($expenses[$key] ?? 0), 2);

            $series[] = ['date' => $key, 'revenue' => $r, 'expenses' => $e, 'profit' => round($r - $e, 2)];
            $cursor->addDay();
        }

        return $series;
    }

    protected function scopedPayments(?array $branchIds): Builder
    {
        $query = Payment::query()->completed();

        return $branchIds === null ? $query : $query->whereIn('payments.branch_id', $branchIds);
    }

    protected function scopedExpenses(?array $branchIds): Builder
    {
        $query = Expense::query()->recorded();

        return $branchIds === null ? $query : $query->whereIn('expenses.branch_id', $branchIds);
    }
}
