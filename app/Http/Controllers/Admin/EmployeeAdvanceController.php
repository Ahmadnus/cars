<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\PaymentMethod;
use App\Services\PayrollService;
use App\Support\BranchContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class EmployeeAdvanceController extends Controller
{
    public function __construct(
        protected PayrollService $payroll,
        protected BranchContext $branchContext,
    ) {
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', EmployeeAdvance::class);

        $advances = EmployeeAdvance::query()
            ->visibleTo($request->user())
            ->with(['employee:id,uuid,full_name,employee_number', 'paymentMethod:id,label_ar'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('employee_id'), fn ($q) => $q->where('employee_id', $request->integer('employee_id')))
            ->orderByDesc('granted_on')
            ->paginate(20)
            ->withQueryString();

        return view('admin.advances.index', [
            'advances' => $advances,
            'outstanding' => round((float) EmployeeAdvance::query()->visibleTo($request->user())
                ->active()->sum('remaining_amount'), 2),
            'employees' => Employee::query()->visibleTo($request->user())->active()
                ->orderBy('full_name')->pluck('full_name', 'id')->all(),
            'methods' => PaymentMethod::active()->pluck('label_ar', 'id')->all(),
            'statuses' => self::statuses(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', EmployeeAdvance::class);

        $data = $request->validate([
            'employee_id' => ['required', 'integer', Rule::exists('employees', 'id')->whereNull('deleted_at')],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:999999'],
            'granted_on' => ['required', 'date', 'before_or_equal:today'],
            'installments_count' => ['required', 'integer', 'min:1', 'max:36'],
            'payment_method_id' => ['required', 'integer', 'exists:payment_methods,id'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [], [
            'employee_id' => 'الموظف',
            'amount' => 'قيمة السلفة',
            'granted_on' => 'تاريخ الصرف',
            'installments_count' => 'عدد الأقساط',
            'payment_method_id' => 'طريقة الدفع',
        ]);

        $employee = Employee::findOrFail($data['employee_id']);
        abort_unless($request->user()->canAccessBranch($employee->branch_id), 403);

        $advance = $this->payroll->grantAdvance(
            $employee,
            (float) $data['amount'],
            $data['granted_on'],
            (int) $data['installments_count'],
            (int) $data['payment_method_id'],
            $data['notes'] ?? null,
        );

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'تم صرف السلفة. سيتم خصم '.money($advance->installment_amount).' شهرياً من الراتب.',
        ]);
    }

    public function show(EmployeeAdvance $advance): View
    {
        $this->authorize('view', $advance);

        return view('admin.advances.show', [
            'advance' => $advance->load([
                'employee', 'paymentMethod', 'creator',
                'deductions.payroll',
            ]),
        ]);
    }

    /** @return array<string, string> */
    public static function statuses(): array
    {
        return [
            'active' => 'قائمة',
            'settled' => 'مسددة',
            'cancelled' => 'ملغاة',
        ];
    }
}
