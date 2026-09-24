<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ExpenseCategory;
use App\Models\PaymentMethod;
use App\Models\RecurringExpense;
use App\Services\AuditLogger;
use App\Services\ExpenseService;
use App\Support\BranchContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class RecurringExpenseController extends Controller
{
    public function __construct(
        protected ExpenseService $expenses,
        protected BranchContext $branchContext,
        protected AuditLogger $audit,
    ) {
    }

    public function index(Request $request): View
    {
        $this->authorize('recurring_expenses.manage');

        $templates = RecurringExpense::query()
            ->visibleTo($request->user())
            ->with(['category:id,name_ar', 'paymentMethod:id,label_ar', 'branch:id,uuid,name'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->orderBy('name')
            ->get();

        return view('admin.recurring-expenses.index', [
            'templates' => $templates,
            'due' => $templates->filter(fn (RecurringExpense $t) => $t->status === 'active' && $t->isDue()),
            'monthlyTotal' => round((float) $templates->where('status', 'active')
                ->where('frequency', 'monthly')->sum('amount'), 2),
            'categories' => ExpenseCategory::active()->pluck('name_ar', 'id')->all(),
            'methods' => PaymentMethod::active()->pluck('label_ar', 'id')->all(),
            'frequencies' => self::frequencies(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('recurring_expenses.manage');

        $data = $this->validateTemplate($request);
        $branchId = $this->branchContext->defaultForWrite($request->user());

        abort_if($branchId === null, 422, 'يجب اختيار فرع أولاً.');

        $template = RecurringExpense::create(array_merge($data, ['branch_id' => $branchId]));
        $this->audit->logCreate('recurring_expense.created', $template, 'إنشاء مصروف متكرر');

        return back()->with('toast', ['type' => 'success', 'message' => 'تم إنشاء المصروف المتكرر.']);
    }

    public function update(Request $request, RecurringExpense $recurringExpense): RedirectResponse
    {
        $this->authorize('recurring_expenses.manage');
        abort_unless($request->user()->canAccessBranch($recurringExpense->branch_id), 403);

        $original = $recurringExpense->getOriginal();
        $recurringExpense->update($this->validateTemplate($request));
        $this->audit->logUpdate('recurring_expense.updated', $recurringExpense, $original);

        return back()->with('toast', ['type' => 'success', 'message' => 'تم تحديث المصروف المتكرر.']);
    }

    /** Post this period's occurrence now, rather than waiting for the scheduler. */
    public function generate(Request $request, RecurringExpense $recurringExpense): RedirectResponse
    {
        $this->authorize('expenses.create');
        abort_unless($request->user()->canAccessBranch($recurringExpense->branch_id), 403);

        $due = $recurringExpense->nextDueDate();

        if (! $due) {
            return back()->with('toast', ['type' => 'error', 'message' => 'انتهت فترة هذا المصروف المتكرر.']);
        }

        $expense = $this->expenses->fromRecurring($recurringExpense, $due->toDateString());

        return redirect()
            ->route('admin.expenses.show', $expense)
            ->with('toast', ['type' => 'success', 'message' => "تم تسجيل المصروف برقم {$expense->reference}."]);
    }

    public function destroy(Request $request, RecurringExpense $recurringExpense): RedirectResponse
    {
        $this->authorize('recurring_expenses.manage');
        abort_unless($request->user()->canAccessBranch($recurringExpense->branch_id), 403);

        $this->audit->logDelete('recurring_expense.deleted', $recurringExpense);
        $recurringExpense->delete();

        return back()->with('toast', ['type' => 'success', 'message' => 'تم حذف المصروف المتكرر.']);
    }

    protected function validateTemplate(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'expense_category_id' => ['required', 'integer', 'exists:expense_categories,id'],
            'payment_method_id' => ['nullable', 'integer', 'exists:payment_methods,id'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:999999'],
            'frequency' => ['required', Rule::in(array_keys(self::frequencies()))],
            'due_day' => ['required', 'integer', 'min:1', 'max:31'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['nullable', 'date', 'after:starts_on'],
            'auto_post' => ['nullable', 'boolean'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [], [
            'name' => 'اسم المصروف',
            'expense_category_id' => 'التصنيف',
            'payment_method_id' => 'طريقة الدفع',
            'amount' => 'المبلغ',
            'frequency' => 'التكرار',
            'due_day' => 'يوم الاستحقاق',
            'starts_on' => 'تاريخ البدء',
            'ends_on' => 'تاريخ الانتهاء',
            'status' => 'الحالة',
        ]);
    }

    /** @return array<string, string> */
    public static function frequencies(): array
    {
        return [
            'monthly' => 'شهري',
            'quarterly' => 'ربع سنوي',
            'yearly' => 'سنوي',
        ];
    }
}
