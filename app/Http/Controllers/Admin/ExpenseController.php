<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\PaymentMethod;
use App\Services\DocumentService;
use App\Services\ExpenseService;
use App\Support\BranchContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ExpenseController extends Controller
{
    public function __construct(
        protected ExpenseService $expenses,
        protected BranchContext $branchContext,
    ) {
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Expense::class);

        $query = Expense::query()
            ->visibleTo($request->user())
            ->with(['category:id,name_ar', 'paymentMethod:id,label_ar', 'creator:id,name', 'branch:id,uuid,name'])
            ->when($request->filled('search'), function (Builder $q) use ($request) {
                $term = trim($request->string('search'));

                $q->where(fn (Builder $i) => $i
                    ->where('title', 'like', "%{$term}%")
                    ->orWhere('reference', 'like', "%{$term}%")
                    ->orWhere('beneficiary', 'like', "%{$term}%")
                    ->orWhere('invoice_number', 'like', "%{$term}%"));
            })
            ->when($request->filled('expense_category_id'), fn (Builder $q) => $q->where('expense_category_id', $request->integer('expense_category_id')))
            ->when($request->filled('payment_method_id'), fn (Builder $q) => $q->where('payment_method_id', $request->integer('payment_method_id')))
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->input('status')))
            ->when($request->filled('from'), fn (Builder $q) => $q->whereDate('spent_on', '>=', $request->date('from')))
            ->when($request->filled('to'), fn (Builder $q) => $q->whereDate('spent_on', '<=', $request->date('to')));

        $total = (clone $query)->where('status', 'recorded')->sum('amount');

        return view('admin.expenses.index', [
            'expenses' => $query->orderByDesc('spent_on')->orderByDesc('id')->paginate(20)->withQueryString(),
            'total' => round((float) $total, 2),
            'categories' => ExpenseCategory::active()->pluck('name_ar', 'id')->all(),
            'methods' => PaymentMethod::active()->pluck('label_ar', 'id')->all(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Expense::class);

        return view('admin.expenses.create', [
            'categories' => ExpenseCategory::active()->pluck('name_ar', 'id')->all(),
            'methods' => PaymentMethod::active()->pluck('label_ar', 'id')->all(),
        ]);
    }

    public function store(Request $request, DocumentService $documents): RedirectResponse
    {
        $this->authorize('create', Expense::class);

        $data = $this->validateExpense($request);
        $branchId = $this->branchContext->defaultForWrite($request->user());

        abort_if($branchId === null, 422, 'يجب اختيار فرع قبل تسجيل المصروف.');

        $expense = $this->expenses->record($data, $branchId);

        if ($request->hasFile('receipt')) {
            $documents->store($request->file('receipt'), $expense, 'receipt', 'إيصال المصروف');
        }

        return redirect()
            ->route('admin.expenses.show', $expense)
            ->with('toast', ['type' => 'success', 'message' => "تم تسجيل المصروف برقم {$expense->reference}."]);
    }

    public function show(Expense $expense): View
    {
        $this->authorize('view', $expense);

        return view('admin.expenses.show', [
            'expense' => $expense->load([
                'category', 'paymentMethod', 'creator', 'canceller', 'branch',
                'documents.uploader', 'sourceable',
            ]),
        ]);
    }

    public function edit(Expense $expense): View
    {
        $this->authorize('update', $expense);

        return view('admin.expenses.edit', [
            'expense' => $expense,
            'categories' => ExpenseCategory::active()->pluck('name_ar', 'id')->all(),
            'methods' => PaymentMethod::active()->pluck('label_ar', 'id')->all(),
        ]);
    }

    public function update(Request $request, Expense $expense): RedirectResponse
    {
        $this->authorize('update', $expense);

        $data = $this->validateExpense($request);

        $request->validate(
            ['reason' => ['required', 'string', 'max:255']],
            [],
            ['reason' => 'سبب التعديل'],
        );

        $this->expenses->update($expense, $data, $request->input('reason'));

        return redirect()
            ->route('admin.expenses.show', $expense)
            ->with('toast', ['type' => 'success', 'message' => 'تم تعديل المصروف وتسجيل التغيير في سجل التدقيق.']);
    }

    /** Expenses are cancelled rather than deleted. */
    public function cancel(Request $request, Expense $expense): RedirectResponse
    {
        $this->authorize('cancel', $expense);

        $data = $request->validate(
            ['reason' => ['required', 'string', 'max:255']],
            [],
            ['reason' => 'سبب الإلغاء'],
        );

        $this->expenses->cancel($expense, $data['reason']);

        return back()->with('toast', ['type' => 'success', 'message' => 'تم إلغاء المصروف وعكس أثره على الصندوق.']);
    }

    protected function validateExpense(Request $request): array
    {
        return $request->validate([
            'expense_category_id' => ['required', 'integer', 'exists:expense_categories,id'],
            'payment_method_id' => ['required', 'integer', 'exists:payment_methods,id'],
            'title' => ['required', 'string', 'max:200'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:9999999'],
            'spent_on' => ['required', 'date', 'before_or_equal:today'],
            'beneficiary' => ['nullable', 'string', 'max:150'],
            'invoice_number' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'receipt' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:8192'],
        ], [], [
            'expense_category_id' => 'تصنيف المصروف',
            'payment_method_id' => 'طريقة الدفع',
            'title' => 'البيان',
            'amount' => 'المبلغ',
            'spent_on' => 'تاريخ الصرف',
            'beneficiary' => 'المستفيد',
            'invoice_number' => 'رقم الفاتورة',
            'receipt' => 'مرفق الإيصال',
        ]);
    }
}
