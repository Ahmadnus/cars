<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\User;
use App\Services\Reports\ReportDefinition;
use App\Services\Reports\ReportRegistry;
use App\Support\BranchContext;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

/**
 * Runs the declared reports.
 *
 * Filtering, branch scoping, totals, pagination and export all happen here, so
 * every report behaves identically and a new one needs no bespoke code. Branch
 * scoping is applied from the user's own grants, never from the request.
 */
class ReportService
{
    public function __construct(
        protected ReportRegistry $registry,
        protected BranchContext $branchContext,
        protected PdfService $pdf,
        protected ExportService $exports,
    ) {
    }

    public function definition(string $key): ReportDefinition
    {
        $report = $this->registry->find($key);

        if (! $report) {
            throw BusinessRuleException::make('التقرير المطلوب غير موجود.', [], 404);
        }

        return $report;
    }

    /**
     * Execute a report.
     *
     * @param  array<string, mixed>  $filters
     * @return array{
     *     report: ReportDefinition, rows: LengthAwarePaginator|Collection,
     *     totals: array<string, float>, filters: array<string, mixed>
     * }
     */
    public function run(string $key, array $filters, ?User $user = null, ?int $perPage = 50): array
    {
        $user ??= auth()->user();
        $report = $this->definition($key);

        if ($user && ! $user->hasPermission($report->permission)) {
            throw BusinessRuleException::make('لا تملك صلاحية عرض هذا التقرير.', [], 403);
        }

        $filters = $this->normaliseFilters($report, $filters, $user);
        $query = $this->buildQuery($report, $filters);

        // Totals come from a clone before pagination, so the footer reflects
        // the whole filtered set rather than the visible page.
        $totals = $this->totals($report, clone $query);

        $rows = $perPage === null
            ? $query->get()
            : $query->paginate($perPage)->withQueryString();

        return [
            'report' => $report,
            'rows' => $rows,
            'totals' => $totals,
            'filters' => $filters,
        ];
    }

    /** Every row, unpaginated — used by the export paths. */
    public function rows(string $key, array $filters, ?User $user = null): Collection
    {
        return $this->run($key, $filters, $user, null)['rows'];
    }

    public function exportCsv(string $key, array $filters, ?User $user = null): \Symfony\Component\HttpFoundation\Response
    {
        $result = $this->run($key, $filters, $user, null);

        return $this->exports->csv(
            $result['report']->columnLabels(),
            $this->tabulate($result['report'], $result['rows']),
            $this->filename($result['report'], 'csv'),
        );
    }

    public function exportExcel(string $key, array $filters, ?User $user = null): \Symfony\Component\HttpFoundation\Response
    {
        $result = $this->run($key, $filters, $user, null);

        return $this->exports->xlsx(
            $result['report']->title,
            $result['report']->columnLabels(),
            $this->tabulate($result['report'], $result['rows']),
            $this->filename($result['report'], 'xlsx'),
        );
    }

    public function exportPdf(string $key, array $filters, ?User $user = null): \Symfony\Component\HttpFoundation\Response
    {
        $result = $this->run($key, $filters, $user, null);
        $report = $result['report'];

        $bytes = $this->pdf->report(
            title: $report->title,
            columns: $report->columns,
            rows: $result['rows']->map(fn ($row) => $this->rowToArray($row))->all(),
            meta: $this->metaLines($result['filters']),
            totals: $result['totals'],
        );

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$this->filename($report, 'pdf').'"',
        ]);
    }

    // ------------------------------------------------------------------
    // Query building
    // ------------------------------------------------------------------

    protected function buildQuery(ReportDefinition $report, array $filters): EloquentBuilder|QueryBuilder
    {
        $query = ($report->query)($filters);
        $table = $query instanceof EloquentBuilder ? $query->getModel()->getTable() : $query->from;

        // Branch scoping is non-negotiable and always applied.
        $query->whereIn("{$table}.branch_id", $filters['branch_ids']);

        if ($report->accepts('from') && ! empty($filters['from'])) {
            $query->whereDate($this->dateColumn($table), '>=', $filters['from']);
        }

        if ($report->accepts('to') && ! empty($filters['to'])) {
            $query->whereDate($this->dateColumn($table), '<=', $filters['to']);
        }

        if ($report->accepts('period') && ! empty($filters['period'])) {
            $query->where("{$table}.period", $filters['period']);
        }

        foreach (['status', 'trainer_id', 'vehicle_id', 'expense_category_id', 'payment_method_id'] as $key) {
            if ($report->accepts($key) && ! empty($filters[$key])) {
                $query->where("{$table}.{$key}", $filters[$key]);
            }
        }

        return $query;
    }

    /** The date column each report table is filtered on. */
    protected function dateColumn(string $table): string
    {
        return match ($table) {
            'payments' => 'paid_on',
            'expenses' => 'spent_on',
            'training_sessions' => 'scheduled_date',
            'trainees' => 'registration_date',
            'vehicle_maintenances' => 'service_date',
            'utility_bills' => 'due_date',
            default => 'created_at',
        };
    }

    /**
     * Resolve the request's filters against what the user may actually see.
     *
     * A branch_id the user has no grant for is dropped rather than honoured.
     */
    protected function normaliseFilters(ReportDefinition $report, array $filters, ?User $user): array
    {
        $allowed = $user ? $user->accessibleBranchIds() : [];
        $requested = $filters['branch_id'] ?? null;

        $branchIds = $requested && in_array((int) $requested, $allowed, true)
            ? [(int) $requested]
            : ($this->branchContext->scopeIds($user) ?: $allowed);

        return array_merge($filters, [
            'from' => $filters['from'] ?? now()->startOfMonth()->toDateString(),
            'to' => $filters['to'] ?? now()->endOfMonth()->toDateString(),
            'branch_id' => $requested,
            'branch_ids' => $branchIds ?: [0],
        ]);
    }

    /** @return array<string, float> */
    protected function totals(ReportDefinition $report, EloquentBuilder|QueryBuilder $query): array
    {
        if (empty($report->sumColumns)) {
            return [];
        }

        $rows = $query->get();

        $totals = [];

        foreach ($report->sumColumns as $column) {
            $totals[$column] = round((float) $rows->sum(fn ($row) => (float) ($row->{$column} ?? 0)), 2);
        }

        return $totals;
    }

    /** Flatten result rows into ordered arrays for the export writers. */
    protected function tabulate(ReportDefinition $report, Collection $rows): array
    {
        $keys = $report->columnKeys();

        return $rows->map(function ($row) use ($keys) {
            $data = $this->rowToArray($row);

            return array_map(fn (string $key) => $data[$key] ?? '', $keys);
        })->all();
    }

    /**
     * Flatten one result row to a plain array.
     *
     * A report query may return Eloquent models or stdClass rows. Casting a
     * model with (array) yields mangled, null-byte-prefixed keys rather than
     * its attributes, so models are unwrapped explicitly.
     *
     * @return array<string, mixed>
     */
    protected function rowToArray(mixed $row): array
    {
        if ($row instanceof \Illuminate\Database\Eloquent\Model) {
            return $row->getAttributes();
        }

        return (array) $row;
    }

    protected function metaLines(array $filters): array
    {
        return array_filter([
            'الفترة' => ($filters['from'] ?? '').' — '.($filters['to'] ?? ''),
            'الشهر' => $filters['period'] ?? null,
            'تاريخ الإصدار' => Carbon::now()->format('Y-m-d H:i'),
        ]);
    }

    protected function filename(ReportDefinition $report, string $extension): string
    {
        return $report->key.'-'.now()->format('Ymd-His').'.'.$extension;
    }
}
