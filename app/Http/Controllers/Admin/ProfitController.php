<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ProfitService;
use App\Support\BranchContext;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Profit and loss.
 *
 * Every figure comes from ProfitService, which reads actual payments and
 * expenses — the page never adds up numbers shown elsewhere on the dashboard.
 */
class ProfitController extends Controller
{
    public function __construct(
        protected ProfitService $profit,
        protected BranchContext $branchContext,
    ) {
    }

    public function index(Request $request): View
    {
        $this->authorize('profit.view');

        [$from, $to, $preset] = $this->resolvePeriod($request);
        $branchIds = $this->branchContext->scopeIds($request->user()) ?: [0];

        $summary = $this->profit->summary($from, $to, $branchIds);

        // A short range reads better day by day; a long one by month.
        $useDaily = $from->diffInDays($to) <= 62;

        return view('admin.profit.index', [
            'summary' => $summary,
            'categories' => $this->profit->expensesByCategory($from, $to, $branchIds),
            'series' => $useDaily
                ? $this->profit->dailySeries($from, $to, $branchIds)
                : $this->profit->monthlySeries($from, $to, $branchIds),
            'seriesKey' => $useDaily ? 'date' : 'period',
            'from' => $from,
            'to' => $to,
            'preset' => $preset,
        ]);
    }

    /** @return array{0: Carbon, 1: Carbon, 2: string} */
    protected function resolvePeriod(Request $request): array
    {
        $preset = $request->input('preset', 'month');

        return match ($preset) {
            'today' => [now()->startOfDay(), now()->endOfDay(), 'today'],
            'week' => [now()->startOfWeek(Carbon::SUNDAY), now()->endOfWeek(Carbon::SATURDAY), 'week'],
            'quarter' => [now()->startOfQuarter(), now()->endOfQuarter(), 'quarter'],
            'year' => [now()->startOfYear(), now()->endOfYear(), 'year'],
            'custom' => [
                $request->filled('from') ? Carbon::parse($request->input('from')) : now()->startOfMonth(),
                $request->filled('to') ? Carbon::parse($request->input('to')) : now()->endOfMonth(),
                'custom',
            ],
            default => [now()->startOfMonth(), now()->endOfMonth(), 'month'],
        };
    }
}
