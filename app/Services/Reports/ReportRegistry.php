<?php

namespace App\Services\Reports;

use App\Models\Expense;
use App\Models\Payment;
use App\Models\Payroll;
use App\Models\TraineePackage;
use App\Models\TrainerCompensationRecord;
use App\Models\TrainingSession;
use App\Models\UtilityBill;
use App\Models\VehicleMaintenance;
use Illuminate\Database\Eloquent\Builder;

/**
 * Every report the system offers, declared once.
 *
 * Each entry is a ReportDefinition: columns, the permission that guards it, and
 * a closure returning the query. ReportService does the rest — filtering,
 * totals, pagination, PDF/Excel/CSV export — so a new report is a new entry
 * here and nothing else.
 */
class ReportRegistry
{
    /** @var array<string, ReportDefinition>|null */
    protected ?array $reports = null;

    /** @return array<string, ReportDefinition> */
    public function all(): array
    {
        return $this->reports ??= collect($this->definitions())
            ->keyBy(fn (ReportDefinition $r) => $r->key)
            ->all();
    }

    public function find(string $key): ?ReportDefinition
    {
        return $this->all()[$key] ?? null;
    }

    /** Reports the given user may run, grouped for the reports index. */
    public function availableTo(\App\Models\User $user): array
    {
        $grouped = [];

        foreach ($this->all() as $report) {
            if (! $user->hasPermission($report->permission)) {
                continue;
            }

            $grouped[$report->group ?? 'عام'][] = $report;
        }

        return $grouped;
    }

    /** @return array<int, ReportDefinition> */
    protected function definitions(): array
    {
        return [
            // ---------------------------------------------------------- financial
            new ReportDefinition(
                key: 'revenue',
                title: 'تقرير الإيرادات',
                permission: 'reports.financial',
                group: 'التقارير المالية',
                description: 'كل الدفعات المحصّلة خلال الفترة.',
                columns: [
                    'receipt_number' => ['label' => 'رقم الإيصال'],
                    'paid_on' => ['label' => 'التاريخ', 'type' => 'date'],
                    'trainee_name' => ['label' => 'المتدرب'],
                    'method' => ['label' => 'طريقة الدفع'],
                    'branch_name' => ['label' => 'الفرع'],
                    'amount' => ['label' => 'المبلغ', 'type' => 'money'],
                ],
                sumColumns: ['amount'],
                filters: ['from', 'to', 'branch_id', 'payment_method_id'],
                query: fn (array $f) => Payment::query()
                    ->completed()
                    ->leftJoin('trainees', 'payments.trainee_id', '=', 'trainees.id')
                    ->join('payment_methods', 'payments.payment_method_id', '=', 'payment_methods.id')
                    ->join('branches', 'payments.branch_id', '=', 'branches.id')
                    ->select([
                        'payments.receipt_number',
                        'payments.paid_on',
                        'trainees.full_name as trainee_name',
                        'payment_methods.label_ar as method',
                        'branches.name as branch_name',
                        'payments.amount',
                    ])
                    ->orderByDesc('payments.paid_on'),
            ),

            new ReportDefinition(
                key: 'expenses',
                title: 'تقرير المصاريف',
                permission: 'reports.financial',
                group: 'التقارير المالية',
                description: 'كل المصاريف المسجلة خلال الفترة.',
                columns: [
                    'reference' => ['label' => 'المرجع'],
                    'spent_on' => ['label' => 'التاريخ', 'type' => 'date'],
                    'title' => ['label' => 'البيان'],
                    'category' => ['label' => 'التصنيف'],
                    'beneficiary' => ['label' => 'المستفيد'],
                    'method' => ['label' => 'طريقة الدفع'],
                    'branch_name' => ['label' => 'الفرع'],
                    'amount' => ['label' => 'المبلغ', 'type' => 'money'],
                ],
                sumColumns: ['amount'],
                filters: ['from', 'to', 'branch_id', 'expense_category_id', 'payment_method_id'],
                query: fn (array $f) => Expense::query()
                    ->recorded()
                    ->join('expense_categories', 'expenses.expense_category_id', '=', 'expense_categories.id')
                    ->join('payment_methods', 'expenses.payment_method_id', '=', 'payment_methods.id')
                    ->join('branches', 'expenses.branch_id', '=', 'branches.id')
                    ->select([
                        'expenses.reference',
                        'expenses.spent_on',
                        'expenses.title',
                        'expense_categories.name_ar as category',
                        'expenses.beneficiary',
                        'payment_methods.label_ar as method',
                        'branches.name as branch_name',
                        'expenses.amount',
                    ])
                    ->orderByDesc('expenses.spent_on'),
            ),

            new ReportDefinition(
                key: 'trainee-debts',
                title: 'تقرير ذمم المتدربين',
                permission: 'reports.financial',
                group: 'التقارير المالية',
                description: 'المبالغ المتبقية على المتدربين.',
                columns: [
                    'trainee_number' => ['label' => 'رقم المتدرب'],
                    'trainee_name' => ['label' => 'المتدرب'],
                    'phone' => ['label' => 'الهاتف'],
                    'package_name' => ['label' => 'الباقة'],
                    'total_amount' => ['label' => 'الإجمالي', 'type' => 'money'],
                    'paid_amount' => ['label' => 'المدفوع', 'type' => 'money'],
                    'remaining' => ['label' => 'المتبقي', 'type' => 'money'],
                ],
                sumColumns: ['total_amount', 'paid_amount', 'remaining'],
                filters: ['branch_id'],
                query: fn (array $f) => TraineePackage::query()
                    ->join('trainees', 'trainee_packages.trainee_id', '=', 'trainees.id')
                    ->whereIn('trainee_packages.status', ['active', 'completed'])
                    ->whereRaw('trainee_packages.total_amount > trainee_packages.paid_amount')
                    ->select([
                        'trainees.trainee_number',
                        'trainees.full_name as trainee_name',
                        'trainees.phone',
                        'trainee_packages.package_name',
                        'trainee_packages.total_amount',
                        'trainee_packages.paid_amount',
                    ])
                    ->selectRaw('(trainee_packages.total_amount - trainee_packages.paid_amount) as remaining')
                    ->orderByDesc('remaining'),
            ),

            new ReportDefinition(
                key: 'payroll',
                title: 'تقرير الرواتب',
                permission: 'salaries.view',
                group: 'التقارير المالية',
                description: 'كشوف رواتب الموظفين.',
                columns: [
                    'period' => ['label' => 'الشهر'],
                    'employee_name' => ['label' => 'الموظف'],
                    'position' => ['label' => 'الوظيفة'],
                    'base_salary' => ['label' => 'الراتب الأساسي', 'type' => 'money'],
                    'allowances' => ['label' => 'البدلات', 'type' => 'money'],
                    'bonuses' => ['label' => 'المكافآت', 'type' => 'money'],
                    'deductions' => ['label' => 'الاستقطاعات', 'type' => 'money'],
                    'advance_deductions' => ['label' => 'أقساط السلف', 'type' => 'money'],
                    'net_salary' => ['label' => 'الصافي', 'type' => 'money'],
                    'paid_amount' => ['label' => 'المصروف', 'type' => 'money'],
                ],
                sumColumns: ['base_salary', 'allowances', 'bonuses', 'deductions', 'advance_deductions', 'net_salary', 'paid_amount'],
                filters: ['period', 'branch_id'],
                query: fn (array $f) => Payroll::query()
                    ->join('employees', 'payrolls.employee_id', '=', 'employees.id')
                    ->where('payrolls.status', '!=', 'cancelled')
                    ->select([
                        'payrolls.period',
                        'employees.full_name as employee_name',
                        'employees.position',
                        'payrolls.base_salary',
                        'payrolls.allowances',
                        'payrolls.bonuses',
                        'payrolls.deductions',
                        'payrolls.advance_deductions',
                        'payrolls.net_salary',
                        'payrolls.paid_amount',
                    ])
                    ->orderByDesc('payrolls.period'),
            ),

            new ReportDefinition(
                key: 'trainer-compensation',
                title: 'تقرير أجور المدربين',
                permission: 'trainer_compensation.view',
                group: 'التقارير المالية',
                description: 'كشوف أجور المدربين حسب الشهر.',
                columns: [
                    'period' => ['label' => 'الشهر'],
                    'trainer_name' => ['label' => 'المدرب'],
                    'lessons_count' => ['label' => 'عدد الحصص', 'type' => 'number'],
                    'training_minutes' => ['label' => 'دقائق التدريب', 'type' => 'number'],
                    'attributed_revenue' => ['label' => 'الإيراد المنسوب', 'type' => 'money'],
                    'gross_amount' => ['label' => 'الإجمالي', 'type' => 'money'],
                    'net_amount' => ['label' => 'الصافي', 'type' => 'money'],
                    'paid_amount' => ['label' => 'المصروف', 'type' => 'money'],
                ],
                sumColumns: ['lessons_count', 'attributed_revenue', 'gross_amount', 'net_amount', 'paid_amount'],
                filters: ['period', 'branch_id'],
                query: fn (array $f) => TrainerCompensationRecord::query()
                    ->join('trainers', 'trainer_compensation_records.trainer_id', '=', 'trainers.id')
                    ->where('trainer_compensation_records.status', '!=', 'cancelled')
                    ->select([
                        'trainer_compensation_records.period',
                        'trainers.full_name as trainer_name',
                        'trainer_compensation_records.lessons_count',
                        'trainer_compensation_records.training_minutes',
                        'trainer_compensation_records.attributed_revenue',
                        'trainer_compensation_records.gross_amount',
                        'trainer_compensation_records.net_amount',
                        'trainer_compensation_records.paid_amount',
                    ])
                    ->orderByDesc('trainer_compensation_records.period'),
            ),

            new ReportDefinition(
                key: 'utilities',
                title: 'تقرير فواتير الخدمات',
                permission: 'reports.financial',
                group: 'التقارير المالية',
                columns: [
                    'billing_month' => ['label' => 'شهر الفاتورة'],
                    'service_type' => ['label' => 'الخدمة'],
                    'branch_name' => ['label' => 'الفرع'],
                    'due_date' => ['label' => 'تاريخ الاستحقاق', 'type' => 'date'],
                    'paid_on' => ['label' => 'تاريخ الدفع', 'type' => 'date'],
                    'status' => ['label' => 'الحالة'],
                    'amount' => ['label' => 'المبلغ', 'type' => 'money'],
                ],
                sumColumns: ['amount'],
                filters: ['from', 'to', 'branch_id'],
                query: fn (array $f) => UtilityBill::query()
                    ->join('branches', 'utility_bills.branch_id', '=', 'branches.id')
                    ->select([
                        'utility_bills.billing_month',
                        'utility_bills.service_type',
                        'branches.name as branch_name',
                        'utility_bills.due_date',
                        'utility_bills.paid_on',
                        'utility_bills.status',
                        'utility_bills.amount',
                    ])
                    ->orderByDesc('utility_bills.due_date'),
            ),

            // ---------------------------------------------------------- training
            new ReportDefinition(
                key: 'training-sessions',
                title: 'تقرير الحصص التدريبية',
                permission: 'reports.view',
                group: 'تقارير التدريب',
                columns: [
                    'scheduled_date' => ['label' => 'التاريخ', 'type' => 'date'],
                    'start_time' => ['label' => 'الوقت'],
                    'trainee_name' => ['label' => 'المتدرب'],
                    'trainer_name' => ['label' => 'المدرب'],
                    'vehicle_name' => ['label' => 'المركبة'],
                    'duration_minutes' => ['label' => 'المدة (د)', 'type' => 'number'],
                    'status' => ['label' => 'الحالة', 'type' => 'session_status'],
                ],
                sumColumns: ['duration_minutes'],
                filters: ['from', 'to', 'branch_id', 'trainer_id', 'status'],
                query: fn (array $f) => TrainingSession::query()
                    ->join('trainees', 'training_sessions.trainee_id', '=', 'trainees.id')
                    ->join('trainers', 'training_sessions.trainer_id', '=', 'trainers.id')
                    ->leftJoin('vehicles', 'training_sessions.vehicle_id', '=', 'vehicles.id')
                    ->select([
                        'training_sessions.scheduled_date',
                        'training_sessions.start_time',
                        'trainees.full_name as trainee_name',
                        'trainers.full_name as trainer_name',
                        'vehicles.name as vehicle_name',
                        'training_sessions.duration_minutes',
                        'training_sessions.status',
                    ])
                    ->orderByDesc('training_sessions.scheduled_date'),
            ),

            new ReportDefinition(
                key: 'trainer-activity',
                title: 'تقرير أداء المدربين',
                permission: 'reports.view',
                group: 'تقارير المدربين',
                description: 'عدد الحصص المنجزة وساعات التدريب لكل مدرب.',
                columns: [
                    'trainer_name' => ['label' => 'المدرب'],
                    'branch_name' => ['label' => 'الفرع'],
                    'completed' => ['label' => 'حصص منجزة', 'type' => 'number'],
                    'cancelled' => ['label' => 'ملغاة', 'type' => 'number'],
                    'no_show' => ['label' => 'عدم حضور', 'type' => 'number'],
                    'minutes' => ['label' => 'دقائق التدريب', 'type' => 'number'],
                ],
                sumColumns: ['completed', 'cancelled', 'no_show', 'minutes'],
                filters: ['from', 'to', 'branch_id'],
                query: fn (array $f) => TrainingSession::query()
                    ->join('trainers', 'training_sessions.trainer_id', '=', 'trainers.id')
                    ->join('branches', 'training_sessions.branch_id', '=', 'branches.id')
                    ->groupBy('trainers.id', 'trainers.full_name', 'branches.name')
                    ->select(['trainers.full_name as trainer_name', 'branches.name as branch_name'])
                    ->selectRaw("SUM(training_sessions.status = 'completed') as completed")
                    ->selectRaw("SUM(training_sessions.status = 'cancelled') as cancelled")
                    ->selectRaw("SUM(training_sessions.status = 'no_show') as no_show")
                    ->selectRaw("SUM(CASE WHEN training_sessions.status = 'completed' THEN training_sessions.duration_minutes ELSE 0 END) as minutes")
                    ->orderByDesc('completed'),
            ),

            new ReportDefinition(
                key: 'trainees',
                title: 'تقرير المتدربين',
                permission: 'reports.view',
                group: 'تقارير المتدربين',
                columns: [
                    'trainee_number' => ['label' => 'رقم المتدرب'],
                    'full_name' => ['label' => 'الاسم'],
                    'phone' => ['label' => 'الهاتف'],
                    'license_type' => ['label' => 'نوع الرخصة'],
                    'trainer_name' => ['label' => 'المدرب'],
                    'branch_name' => ['label' => 'الفرع'],
                    'registration_date' => ['label' => 'تاريخ التسجيل', 'type' => 'date'],
                    'status' => ['label' => 'الحالة', 'type' => 'trainee_status'],
                ],
                filters: ['from', 'to', 'branch_id', 'status', 'trainer_id'],
                query: fn (array $f) => \App\Models\Trainee::query()
                    ->leftJoin('trainers', 'trainees.trainer_id', '=', 'trainers.id')
                    ->join('branches', 'trainees.branch_id', '=', 'branches.id')
                    ->select([
                        'trainees.trainee_number',
                        'trainees.full_name',
                        'trainees.phone',
                        'trainees.license_type',
                        'trainers.full_name as trainer_name',
                        'branches.name as branch_name',
                        'trainees.registration_date',
                        'trainees.status',
                    ])
                    ->orderByDesc('trainees.registration_date'),
            ),

            // ---------------------------------------------------------- vehicles
            new ReportDefinition(
                key: 'vehicle-expenses',
                title: 'تقرير مصاريف المركبات',
                permission: 'reports.view',
                group: 'تقارير المركبات',
                columns: [
                    'service_date' => ['label' => 'التاريخ', 'type' => 'date'],
                    'vehicle_name' => ['label' => 'المركبة'],
                    'plate_number' => ['label' => 'رقم اللوحة'],
                    'type' => ['label' => 'النوع'],
                    'title' => ['label' => 'البيان'],
                    'provider' => ['label' => 'الجهة'],
                    'cost' => ['label' => 'التكلفة', 'type' => 'money'],
                ],
                sumColumns: ['cost'],
                filters: ['from', 'to', 'branch_id', 'vehicle_id'],
                query: fn (array $f) => VehicleMaintenance::query()
                    ->join('vehicles', 'vehicle_maintenances.vehicle_id', '=', 'vehicles.id')
                    ->select([
                        'vehicle_maintenances.service_date',
                        'vehicles.name as vehicle_name',
                        'vehicles.plate_number',
                        'vehicle_maintenances.type',
                        'vehicle_maintenances.title',
                        'vehicle_maintenances.provider',
                        'vehicle_maintenances.cost',
                    ])
                    ->orderByDesc('vehicle_maintenances.service_date'),
            ),
        ];
    }
}
