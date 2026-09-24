<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Employee;
use App\Services\AuditLogger;
use App\Services\NumberGenerator;
use App\Support\BranchContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class EmployeeController extends Controller
{
    public function __construct(
        protected BranchContext $branchContext,
        protected NumberGenerator $numbers,
        protected AuditLogger $audit,
    ) {
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Employee::class);

        $employees = Employee::query()
            ->visibleTo($request->user())
            ->with('branch:id,uuid,name')
            ->when($request->filled('search'), function (Builder $q) use ($request) {
                $term = trim($request->string('search'));

                $q->where(fn (Builder $i) => $i
                    ->where('full_name', 'like', "%{$term}%")
                    ->orWhere('employee_number', 'like', "%{$term}%")
                    ->orWhere('phone', 'like', "%{$term}%"));
            })
            ->when($request->filled('position'), fn (Builder $q) => $q->where('position', $request->input('position')))
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->input('status')))
            ->orderBy('full_name')
            ->paginate(20)
            ->withQueryString();

        return view('admin.employees.index', [
            'employees' => $employees,
            'positions' => self::positions(),
            'statuses' => self::statuses(),
            // Salary figures are a separate permission from seeing the roster.
            'canSeeSalaries' => $request->user()->hasPermission('salaries.view'),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', Employee::class);

        return view('admin.employees.create', [
            'branches' => $this->branchOptions($request),
            'positions' => self::positions(),
            'statuses' => self::statuses(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Employee::class);

        $data = $this->validateEmployee($request);

        $employee = DB::transaction(function () use ($data, $request) {
            $employee = Employee::create(array_merge($data, [
                'employee_number' => $this->numbers->employeeNumber(),
                'branch_id' => $data['branch_id'] ?? $this->branchContext->defaultForWrite($request->user()),
            ]));

            $this->audit->logCreate('employee.created', $employee, 'إضافة موظف جديد');

            return $employee;
        });

        return redirect()
            ->route('admin.employees.show', $employee)
            ->with('toast', ['type' => 'success', 'message' => 'تم إضافة الموظف بنجاح.']);
    }

    public function show(Request $request, Employee $employee): View
    {
        $this->authorize('view', $employee);

        $data = ['employee' => $employee->load(['branch', 'user'])];

        if ($request->user()->hasPermission('payroll.view')) {
            $data['payrolls'] = $employee->payrolls()->orderByDesc('period')->limit(12)->get();
        }

        if ($request->user()->hasPermission('advances.manage')) {
            $data['advances'] = $employee->advances()->orderByDesc('granted_on')->get();
            $data['outstandingAdvances'] = $employee->outstandingAdvances();
        }

        return view('admin.employees.show', $data);
    }

    public function edit(Request $request, Employee $employee): View
    {
        $this->authorize('update', $employee);

        return view('admin.employees.edit', [
            'employee' => $employee,
            'branches' => $this->branchOptions($request),
            'positions' => self::positions(),
            'statuses' => self::statuses(),
        ]);
    }

    public function update(Request $request, Employee $employee): RedirectResponse
    {
        $this->authorize('update', $employee);

        $data = $this->validateEmployee($request, $employee);
        $original = $employee->getOriginal();

        // A salary change is a sensitive edit and must carry a reason.
        $salaryChanged = (float) $data['base_salary'] !== (float) $employee->base_salary
            || (float) $data['allowances'] !== (float) $employee->allowances;

        if ($salaryChanged && settings('payroll.require_reason_on_edit', true)) {
            $request->validate(
                ['reason' => ['required', 'string', 'max:255']],
                [],
                ['reason' => 'سبب تعديل الراتب'],
            );
        }

        DB::transaction(function () use ($employee, $data, $original, $request, $salaryChanged) {
            $employee->update($data);

            $this->audit->logUpdate(
                $salaryChanged ? 'employee.salary_changed' : 'employee.updated',
                $employee,
                $original,
                $request->input('reason'),
            );
        });

        return redirect()
            ->route('admin.employees.show', $employee)
            ->with('toast', ['type' => 'success', 'message' => 'تم تحديث بيانات الموظف.']);
    }

    public function destroy(Employee $employee): RedirectResponse
    {
        $this->authorize('delete', $employee);

        if ($employee->advances()->active()->exists()) {
            return back()->with('toast', [
                'type' => 'error',
                'message' => 'لا يمكن أرشفة موظف لديه سلف غير مسددة.',
            ]);
        }

        DB::transaction(function () use ($employee) {
            $employee->update(['status' => 'terminated']);
            $this->audit->logDelete('employee.archived', $employee);
            $employee->delete();
        });

        return redirect()
            ->route('admin.employees.index')
            ->with('toast', ['type' => 'success', 'message' => 'تم أرشفة الموظف.']);
    }

    protected function validateEmployee(Request $request, ?Employee $employee = null): array
    {
        return $request->validate([
            'full_name' => ['required', 'string', 'max:150'],
            'phone' => ['required', 'string', 'max:30', 'regex:/^\+?[0-9\s\-]{7,20}$/'],
            'national_id' => [
                'nullable', 'string', 'max:30', 'regex:/^[0-9]{6,20}$/',
                Rule::unique('employees', 'national_id')->ignore($employee?->id)->whereNull('deleted_at'),
            ],
            'position' => ['required', Rule::in(array_keys(self::positions()))],
            'employment_date' => ['required', 'date', 'before_or_equal:today'],
            'base_salary' => ['required', 'numeric', 'min:0', 'max:999999'],
            'allowances' => ['required', 'numeric', 'min:0', 'max:999999'],
            'working_days' => ['nullable', 'array'],
            'working_days.*' => ['integer', 'between:0,6'],
            'work_start_time' => ['nullable', 'date_format:H:i'],
            'work_end_time' => ['nullable', 'date_format:H:i', 'after:work_start_time'],
            'branch_id' => ['nullable', 'integer', Rule::in($request->user()->accessibleBranchIds())],
            'status' => ['required', Rule::in(array_keys(self::statuses()))],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [], [
            'full_name' => 'الاسم الكامل',
            'phone' => 'رقم الهاتف',
            'national_id' => 'الرقم الوطني',
            'position' => 'الوظيفة',
            'employment_date' => 'تاريخ التعيين',
            'base_salary' => 'الراتب الأساسي',
            'allowances' => 'البدلات',
            'branch_id' => 'الفرع',
            'status' => 'الحالة',
        ]);
    }

    protected function branchOptions(Request $request): array
    {
        return Branch::query()
            ->whereIn('id', $request->user()->accessibleBranchIds())
            ->orderBy('name')->pluck('name', 'id')->all();
    }

    /** @return array<string, string> */
    public static function positions(): array
    {
        return [
            'manager' => 'مدير',
            'accountant' => 'محاسب',
            'receptionist' => 'موظف استقبال',
            'supervisor' => 'مشرف',
            'administrative' => 'موظف إداري',
        ];
    }

    /** @return array<string, string> */
    public static function statuses(): array
    {
        return [
            'active' => 'نشط',
            'on_leave' => 'في إجازة',
            'terminated' => 'منتهي الخدمة',
        ];
    }
}
