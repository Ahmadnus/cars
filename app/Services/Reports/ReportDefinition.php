<?php

namespace App\Services\Reports;

use Closure;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Declarative description of one report.
 *
 * A report says what it is called, which permission guards it, which columns it
 * has and how to build its query. Running, totalling, paginating and exporting
 * are handled once by ReportService, so adding a report is a matter of
 * declaring it rather than writing another controller and view.
 */
class ReportDefinition
{
    /**
     * @param  string  $key             url-safe identifier
     * @param  string  $title           Arabic title
     * @param  string  $permission      permission required to run it
     * @param  array<string, array{label: string, type?: string, align?: string}>  $columns
     * @param  Closure(array $filters): (EloquentBuilder|QueryBuilder)  $query
     * @param  array<int, string>  $sumColumns  columns to total in the footer
     * @param  array<int, string>  $filters     filter keys this report accepts
     */
    public function __construct(
        public string $key,
        public string $title,
        public string $permission,
        public array $columns,
        public Closure $query,
        public array $sumColumns = [],
        public array $filters = ['from', 'to', 'branch_id'],
        public ?string $group = null,
        public ?string $description = null,
    ) {
    }

    /** @return array<int, string> */
    public function columnKeys(): array
    {
        return array_keys($this->columns);
    }

    /** @return array<int, string> */
    public function columnLabels(): array
    {
        return array_map(fn (array $c) => $c['label'], $this->columns);
    }

    public function columnType(string $key): string
    {
        return $this->columns[$key]['type'] ?? 'text';
    }

    public function accepts(string $filter): bool
    {
        return in_array($filter, $this->filters, true);
    }
}
