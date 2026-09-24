<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PaymentMethod;
use App\Models\UtilityBill;
use App\Services\AuditLogger;
use App\Services\DocumentService;
use App\Services\ExpenseService;
use App\Support\BranchContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class UtilityBillController extends Controller
{
    public function __construct(
        protected ExpenseService $expenses,
        protected BranchContext $branchContext,
        protected AuditLogger $audit,
    ) {
    }

    public function index(Request $request): View
    {
        $this->authorize('utilities.manage');

        $query = UtilityBill::query()
            ->visibleTo($request->user())
            ->with(['paymentMethod:id,label_ar', 'branch:id,uuid,name', 'expense:id,uuid,reference'])
            ->when($request->filled('service_type'), fn ($q) => $q->where('service_type', $request->input('service_type')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('billing_month'), fn ($q) => $q->where('billing_month', $request->input('billing_month')));

        return view('admin.utilities.index', [
            'bills' => (clone $query)->orderByDesc('due_date')->paginate(20)->withQueryString(),
            'unpaidTotal' => round((float) (clone $query)->unpaid()->sum('amount'), 2),
            'overdue' => UtilityBill::query()->visibleTo($request->user())->unpaid()
                ->where('due_date', '<', now()->toDateString())->count(),
            'serviceTypes' => UtilityBill::SERVICE_TYPES,
            'methods' => PaymentMethod::active()->pluck('label_ar', 'id')->all(),
        ]);
    }

    public function store(Request $request, DocumentService $documents): RedirectResponse
    {
        $this->authorize('utilities.manage');

        $branchId = $this->branchContext->defaultForWrite($request->user());
        abort_if($branchId === null, 422, 'يجب اختيار فرع أولاً.');

        $data = $request->validate([
            'service_type' => ['required', Rule::in(array_keys(UtilityBill::SERVICE_TYPES))],
            'billing_month' => [
                'required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/',
                // One bill per service per month per branch.
                Rule::unique('utility_bills')->where(fn ($q) => $q
                    ->where('branch_id', $branchId)
                    ->where('service_type', $request->input('service_type'))
                    ->whereNull('deleted_at')),
            ],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:999999'],
            'due_date' => ['required', 'date'],
            'invoice_number' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:500'],
            'attachment' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:8192'],
        ], [
            'billing_month.unique' => 'توجد فاتورة مسجلة لهذه الخدمة في نفس الشهر.',
        ], [
            'service_type' => 'نوع الخدمة',
            'billing_month' => 'شهر الفاتورة',
            'amount' => 'المبلغ',
            'due_date' => 'تاريخ الاستحقاق',
            'invoice_number' => 'رقم الفاتورة',
        ]);

        $bill = UtilityBill::create(array_merge(
            collect($data)->except('attachment')->all(),
            [
                'branch_id' => $branchId,
                'status' => 'unpaid',
                'created_by' => $request->user()->id,
            ],
        ));

        if ($request->hasFile('attachment')) {
            $documents->store($request->file('attachment'), $bill, 'invoice', 'فاتورة '.$bill->serviceLabel());
        }

        $this->audit->logCreate('utility_bill.created', $bill, 'تسجيل فاتورة خدمات');

        return back()->with('toast', ['type' => 'success', 'message' => 'تم تسجيل الفاتورة.']);
    }

    /** Pay a bill — books it into the expense ledger and the cashbox. */
    public function pay(Request $request, UtilityBill $utilityBill): RedirectResponse
    {
        $this->authorize('utilities.manage');
        abort_unless($request->user()->canAccessBranch($utilityBill->branch_id), 403);

        $data = $request->validate([
            'paid_on' => ['required', 'date', 'before_or_equal:today'],
            'payment_method_id' => ['required', 'integer', 'exists:payment_methods,id'],
        ], [], [
            'paid_on' => 'تاريخ الدفع',
            'payment_method_id' => 'طريقة الدفع',
        ]);

        $this->expenses->payUtilityBill($utilityBill, $data['paid_on'], (int) $data['payment_method_id']);

        return back()->with('toast', ['type' => 'success', 'message' => 'تم دفع الفاتورة وتسجيلها في المصاريف.']);
    }
}
