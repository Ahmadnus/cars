<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\PayrollPayment;
use App\Models\PaymentMethod;
use App\Services\PayrollService;
use App\Services\PdfService;
use App\Support\BranchContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class PayrollController extends Controller
{
    public function __construct(
        protected PayrollService $payroll,
        protected BranchContext $branchContext,
    ) {
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Payroll::class);

        $period = $request->input('period', now()->format('Y-m'));

        $payrolls = Payroll::query()
            ->visibleTo($request->user())
            ->with(['employee:id,uuid,full_name,employee_number,position', 'branch:id,uuid,name'])
            ->where('period', $period)
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->orderBy('id')
            ->paginate(30)
            ->withQueryString();

        $totals = Payroll::query()
            ->visibleTo($request->user())
            ->where('period', $period)
            ->where('status', '!=', 'cancelled')
            ->selectRaw('SUM(net_salary) as net, SUM(paid_amount) as paid, COUNT(*) as count')
            ->first();

        return view('admin.payroll.index', [
            'payrolls' => $payrolls,
            'period' => $period,
            'periods' => $this->periodOptions(),
            'totals' => [
                'net' => round((float) ($totals->net ?? 0), 2),
                'paid' => round((float) ($totals->paid ?? 0), 2),
                'remaining' => round((float) ($totals->net ?? 0) - (float) ($totals->paid ?? 0), 2),
                'count' => (int) ($totals->count ?? 0),
            ],
            'statuses' => self::statuses(),
        ]);
    }

    /** Generate the whole branch's payroll for a month. */
    public function generate(Request $request): RedirectResponse
    {
        $this->authorize('payroll.create');

        $data = $request->validate(
            ['period' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/']],
            [],
            ['period' => 'الشهر'],
        );

        $branchId = $this->branchContext->defaultForWrite($request->user());
        abort_if($branchId === null, 422, 'يجب اختيار فرع قبل إنشاء كشف الرواتب.');

        $created = $this->payroll->generateForBranch($branchId, $data['period']);

        return redirect()
            ->route('admin.payroll.index', ['period' => $data['period']])
            ->with('toast', [
                'type' => 'success',
                'message' => "تم إنشاء/تحديث {$created->count()} كشف راتب لشهر {$data['period']}.",
            ]);
    }

    public function show(Payroll $payroll): View
    {
        $this->authorize('view', $payroll);

        return view('admin.payroll.show', [
            'payroll' => $payroll->load([
                'employee', 'branch', 'creator',
                'payments.paymentMethod', 'payments.payer',
                'advanceDeductions.advance',
            ]),
            'methods' => PaymentMethod::active()->pluck('label_ar', 'id')->all(),
        ]);
    }

    /** Recalculate a single payroll — refused once money has moved against it. */
    public function recalculate(Request $request, Payroll $payroll): RedirectResponse
    {
        $this->authorize('update', $payroll);

        $data = $request->validate([
            'bonuses' => ['required', 'numeric', 'min:0', 'max:999999'],
            'deductions' => ['required', 'numeric', 'min:0', 'max:999999'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [], [
            'bonuses' => 'المكافآت',
            'deductions' => 'الاستقطاعات',
        ]);

        $this->payroll->generate($payroll->employee, $payroll->period, $data);

        return back()->with('toast', ['type' => 'success', 'message' => 'تم إعادة احتساب الراتب.']);
    }

    public function pay(Request $request, Payroll $payroll): RedirectResponse
    {
        $this->authorize('pay', $payroll);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'paid_on' => ['required', 'date', 'before_or_equal:today'],
            'payment_method_id' => ['required', 'integer', 'exists:payment_methods,id'],
            'reference_number' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [], [
            'amount' => 'المبلغ',
            'paid_on' => 'تاريخ الصرف',
            'payment_method_id' => 'طريقة الدفع',
        ]);

        $payment = $this->payroll->pay(
            $payroll,
            (float) $data['amount'],
            $data['paid_on'],
            (int) $data['payment_method_id'],
            $data['reference_number'] ?? null,
            $data['notes'] ?? null,
        );

        return back()->with('toast', [
            'type' => 'success',
            'message' => "تم صرف الراتب برقم إيصال {$payment->receipt_number}.",
        ]);
    }

    public function receipt(PayrollPayment $payrollPayment, PdfService $pdf): Response
    {
        $this->authorize('view', $payrollPayment->payroll);

        return response($pdf->salaryReceipt($payrollPayment), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="salary-'.$payrollPayment->receipt_number.'.pdf"',
        ]);
    }

    /** @return array<string, string> */
    protected function periodOptions(): array
    {
        $options = [];

        for ($i = 0; $i < 18; $i++) {
            $month = now()->subMonths($i);
            $options[$month->format('Y-m')] = $month->translatedFormat('F Y');
        }

        return $options;
    }

    /** @return array<string, string> */
    public static function statuses(): array
    {
        return [
            'unpaid' => 'غير مدفوع',
            'partially_paid' => 'مدفوع جزئياً',
            'paid' => 'مدفوع بالكامل',
            'cancelled' => 'ملغى',
        ];
    }
}
