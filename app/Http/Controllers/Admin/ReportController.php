<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\ExpenseCategory;
use App\Models\PaymentMethod;
use App\Models\Trainer;
use App\Models\Vehicle;
use App\Services\ReportService;
use App\Services\Reports\ReportRegistry;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * One controller for every report.
 *
 * ReportService applies the permission, the branch scope and the filters, so
 * adding a report means adding a ReportDefinition — not another controller.
 */
class ReportController extends Controller
{
    public function __construct(
        protected ReportService $reports,
        protected ReportRegistry $registry,
    ) {
    }

    public function index(Request $request): View
    {
        $this->authorize('reports.view');

        return view('admin.reports.index', [
            'groups' => $this->registry->availableTo($request->user()),
        ]);
    }

    public function show(Request $request, string $report): View
    {
        $this->authorize('reports.view');

        $result = $this->reports->run($report, $this->filters($request), $request->user());

        return view('admin.reports.show', array_merge($result, [
            'options' => $this->filterOptions($request),
        ]));
    }

    public function export(Request $request, string $report, string $format): Response
    {
        $this->authorize('reports.export');

        $filters = $this->filters($request);

        return match ($format) {
            'pdf' => $this->reports->exportPdf($report, $filters, $request->user()),
            'xlsx' => $this->reports->exportExcel($report, $filters, $request->user()),
            default => $this->reports->exportCsv($report, $filters, $request->user()),
        };
    }

    protected function filters(Request $request): array
    {
        return $request->only([
            'from', 'to', 'branch_id', 'period', 'status',
            'trainer_id', 'vehicle_id', 'expense_category_id', 'payment_method_id',
        ]);
    }

    protected function filterOptions(Request $request): array
    {
        return [
            'branches' => Branch::whereIn('id', $request->user()->accessibleBranchIds())
                ->orderBy('name')->pluck('name', 'id')->all(),
            'trainers' => Trainer::query()->visibleTo($request->user())
                ->orderBy('full_name')->pluck('full_name', 'id')->all(),
            'vehicles' => Vehicle::query()->visibleTo($request->user())
                ->orderBy('name')->pluck('name', 'id')->all(),
            'categories' => ExpenseCategory::active()->pluck('name_ar', 'id')->all(),
            'methods' => PaymentMethod::active()->pluck('label_ar', 'id')->all(),
        ];
    }
}
