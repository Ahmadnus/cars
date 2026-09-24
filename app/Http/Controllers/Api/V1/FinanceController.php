<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\ExpenseResource;
use App\Http\Resources\PaymentResource;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Payroll;
use App\Models\Trainee;
use App\Services\ExpenseService;
use App\Services\PaymentService;
use App\Services\ProfitService;
use App\Support\BranchContext;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Financial endpoints.
 *
 * Every action here is guarded by its own permission at the route, checked
 * again by a policy, and enforced a third time by the service — the same three
 * layers the dashboard uses. Nothing computes money client-side.
 */
class FinanceController extends ApiController
{
    public function __construct(
        protected PaymentService $payments,
        protected ExpenseService $expenses,
        protected ProfitService $profit,
        protected BranchContext $branchContext,
    ) {
    }

    // ------------------------------------------------------------ payments

    public function payments(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Payment::class);

        $payments = Payment::query()
            ->visibleTo($request->user())
            ->with(['trainee:id,uuid,full_name', 'paymentMethod:id,code,label_ar', 'traineePackage'])
            // A trainee only ever sees their own payments.
            ->when($request->user()->trainee, fn (Builder $q) => $q->where('trainee_id', $request->user()->trainee->id))
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->input('status')))
            ->when($request->filled('from'), fn (Builder $q) => $q->whereDate('paid_on', '>=', $request->date('from')))
            ->when($request->filled('to'), fn (Builder $q) => $q->whereDate('paid_on', '<=', $request->date('to')))
            ->orderByDesc('paid_on')
            ->orderByDesc('id')
            ->paginate($this->perPage());

        return $this->paginated($payments, PaymentResource::class);
    }

    public function storePayment(Request $request): JsonResponse
    {
        $this->authorize('create', Payment::class);

        $data = $request->validate([
            'trainee_id' => ['required', 'string', 'exists:trainees,uuid'],
            'trainee_package_id' => ['nullable', 'string', 'exists:trainee_packages,uuid'],
            'payment_method_id' => ['required', 'integer', 'exists:payment_methods,id'],
            'source' => ['required', 'in:package,extra_lesson,other'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'paid_on' => ['required', 'date', 'before_or_equal:today'],
            'reference_number' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $trainee = Trainee::where('uuid', $data['trainee_id'])->firstOrFail();
        abort_unless($request->user()->canAccessBranch($trainee->branch_id), 403);

        // Translate public UUIDs to internal keys before handing to the service.
        $data['trainee_id'] = $trainee->id;

        if (! empty($data['trainee_package_id'])) {
            $data['trainee_package_id'] = \App\Models\TraineePackage::where('uuid', $data['trainee_package_id'])->value('id');
        }

        $payment = $this->payments->record($data, $trainee->branch_id);

        return $this->created(
            new PaymentResource($payment->load(['trainee', 'paymentMethod', 'traineePackage'])),
            "تم تسجيل الدفعة برقم إيصال {$payment->receipt_number}.",
        );
    }

    public function voidPayment(Request $request, Payment $payment): JsonResponse
    {
        $this->authorize('void', $payment);

        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return $this->ok(
            new PaymentResource($this->payments->void($payment, $data['reason'])),
            'تم إلغاء الدفعة وعكس أثرها المالي.',
        );
    }

    // ------------------------------------------------------------ expenses

    public function expenses(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Expense::class);

        $expenses = Expense::query()
            ->visibleTo($request->user())
            ->with(['category:id,code,name_ar,profit_bucket', 'paymentMethod:id,code,label_ar'])
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->input('status')))
            ->when($request->filled('from'), fn (Builder $q) => $q->whereDate('spent_on', '>=', $request->date('from')))
            ->when($request->filled('to'), fn (Builder $q) => $q->whereDate('spent_on', '<=', $request->date('to')))
            ->orderByDesc('spent_on')
            ->paginate($this->perPage());

        return $this->paginated($expenses, ExpenseResource::class);
    }

    public function storeExpense(Request $request): JsonResponse
    {
        $this->authorize('create', Expense::class);

        $data = $request->validate([
            'expense_category_id' => ['required', 'integer', 'exists:expense_categories,id'],
            'payment_method_id' => ['required', 'integer', 'exists:payment_methods,id'],
            'title' => ['required', 'string', 'max:200'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'spent_on' => ['required', 'date', 'before_or_equal:today'],
            'beneficiary' => ['nullable', 'string', 'max:150'],
            'invoice_number' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $branchId = $this->branchContext->defaultForWrite($request->user());
        abort_if($branchId === null, 422, 'يجب تحديد الفرع.');

        $expense = $this->expenses->record($data, $branchId);

        return $this->created(
            new ExpenseResource($expense->load(['category', 'paymentMethod'])),
            "تم تسجيل المصروف برقم {$expense->reference}.",
        );
    }

    // ------------------------------------------------------------- payroll

    public function payroll(Request $request): JsonResponse
    {
        $this->authorize('payroll.view');

        $period = $request->input('period', now()->format('Y-m'));

        $payrolls = Payroll::query()
            ->visibleTo($request->user())
            ->with('employee:id,uuid,full_name,employee_number,position')
            ->where('period', $period)
            ->orderBy('id')
            ->paginate($this->perPage());

        return $this->ok([
            'period' => $period,
            'items' => $payrolls->getCollection()->map(fn (Payroll $payroll) => [
                'id' => $payroll->uuid,
                'employee' => [
                    'id' => $payroll->employee?->uuid,
                    'full_name' => $payroll->employee?->full_name,
                    'position' => $payroll->employee?->position,
                ],
                'base_salary' => (float) $payroll->base_salary,
                'allowances' => (float) $payroll->allowances,
                'bonuses' => (float) $payroll->bonuses,
                'deductions' => (float) $payroll->deductions,
                'advance_deductions' => (float) $payroll->advance_deductions,
                'net_salary' => (float) $payroll->net_salary,
                'paid_amount' => (float) $payroll->paid_amount,
                'remaining_amount' => $payroll->remainingAmount(),
                'status' => $payroll->status,
            ])->all(),
        ], meta: [
            'current_page' => $payrolls->currentPage(),
            'last_page' => $payrolls->lastPage(),
            'total' => $payrolls->total(),
        ]);
    }

    // ------------------------------------------------------------- cashbox

    public function cashbox(Request $request): JsonResponse
    {
        $this->authorize('cashbox.view');

        $branchIds = $this->branchContext->scopeIds($request->user()) ?: [0];
        $boxes = \App\Models\Cashbox::whereIn('branch_id', $branchIds)->with('branch:id,uuid,name')->get();

        $from = $request->filled('from') ? Carbon::parse($request->input('from')) : now()->startOfMonth();
        $to = $request->filled('to') ? Carbon::parse($request->input('to')) : now()->endOfMonth();

        $transactions = \App\Models\CashboxTransaction::whereIn('branch_id', $branchIds)
            ->whereBetween('transaction_date', [$from->toDateString(), $to->toDateString()])
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->paginate($this->perPage());

        return $this->ok([
            'balance' => round((float) $boxes->sum('current_balance'), 2),
            'boxes' => $boxes->map(fn ($box) => [
                'id' => $box->uuid,
                'name' => $box->name,
                'branch' => $box->branch?->name,
                'balance' => (float) $box->current_balance,
                'is_consistent' => $box->isConsistent(),
            ])->all(),
            'transactions' => $transactions->getCollection()->map(fn ($t) => [
                'id' => $t->uuid,
                'direction' => $t->direction,
                'amount' => (float) $t->amount,
                'balance_after' => (float) $t->balance_after,
                'category' => $t->category,
                'description' => $t->description,
                'date' => $t->transaction_date?->toDateString(),
                'is_reversal' => $t->reverses_id !== null,
            ])->all(),
        ], meta: [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'current_page' => $transactions->currentPage(),
            'last_page' => $transactions->lastPage(),
        ]);
    }

    // -------------------------------------------------------------- reports

    public function revenueReport(Request $request): JsonResponse
    {
        $this->authorize('reports.financial');

        [$from, $to, $branchIds] = $this->reportScope($request);

        return $this->ok([
            'total' => $this->profit->revenue($from, $to, $branchIds),
            'series' => $this->profit->dailySeries($from, $to, $branchIds),
        ], meta: ['from' => $from->toDateString(), 'to' => $to->toDateString()]);
    }

    public function expensesReport(Request $request): JsonResponse
    {
        $this->authorize('reports.financial');

        [$from, $to, $branchIds] = $this->reportScope($request);

        return $this->ok([
            'total' => $this->profit->expenses($from, $to, $branchIds),
            'by_category' => $this->profit->expensesByCategory($from, $to, $branchIds),
            'by_bucket' => $this->profit->expenseBuckets($from, $to, $branchIds),
        ], meta: ['from' => $from->toDateString(), 'to' => $to->toDateString()]);
    }

    public function profitReport(Request $request): JsonResponse
    {
        // Guarded separately: seeing revenue is not the same as seeing profit.
        $this->authorize('profit.view');

        [$from, $to, $branchIds] = $this->reportScope($request);

        return $this->ok($this->profit->summary($from, $to, $branchIds));
    }

    /** @return array{0: Carbon, 1: Carbon, 2: array<int, int>} */
    protected function reportScope(Request $request): array
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        return [
            $request->filled('from') ? Carbon::parse($request->input('from')) : now()->startOfMonth(),
            $request->filled('to') ? Carbon::parse($request->input('to')) : now()->endOfMonth(),
            $this->branchContext->scopeIds($request->user()) ?: [0],
        ];
    }
}
