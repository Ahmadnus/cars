<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Trainee;
use App\Services\PaymentService;
use App\Services\PdfService;
use App\Support\BranchContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class PaymentController extends Controller
{
    public function __construct(
        protected PaymentService $payments,
        protected BranchContext $branchContext,
    ) {
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Payment::class);

        $query = Payment::query()
            ->visibleTo($request->user())
            ->with(['trainee:id,uuid,full_name,trainee_number', 'paymentMethod:id,label_ar', 'receiver:id,name'])
            ->when($request->filled('search'), function (Builder $q) use ($request) {
                $term = trim($request->string('search'));

                $q->where('receipt_number', 'like', "%{$term}%")
                    ->orWhereHas('trainee', fn (Builder $t) => $t
                        ->where('full_name', 'like', "%{$term}%")
                        ->orWhere('trainee_number', 'like', "%{$term}%"));
            })
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->input('status')))
            ->when($request->filled('payment_method_id'), fn (Builder $q) => $q->where('payment_method_id', $request->integer('payment_method_id')))
            ->when($request->filled('from'), fn (Builder $q) => $q->whereDate('paid_on', '>=', $request->date('from')))
            ->when($request->filled('to'), fn (Builder $q) => $q->whereDate('paid_on', '<=', $request->date('to')));

        // Totals reflect the filtered set, not the visible page.
        $total = (clone $query)->where('status', 'completed')->sum('amount');

        return view('admin.payments.index', [
            'payments' => $query->orderByDesc('paid_on')->orderByDesc('id')->paginate(20)->withQueryString(),
            'total' => round((float) $total, 2),
            'methods' => PaymentMethod::active()->pluck('label_ar', 'id')->all(),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', Payment::class);

        $trainee = $request->filled('trainee_id')
            ? Trainee::query()->visibleTo($request->user())->find($request->integer('trainee_id'))
            : null;

        return view('admin.payments.create', [
            'trainee' => $trainee,
            'trainees' => Trainee::query()->visibleTo($request->user())->active()
                ->orderBy('full_name')->get(['id', 'full_name', 'trainee_number'])
                ->mapWithKeys(fn ($t) => [$t->id => "{$t->full_name} ({$t->trainee_number})"])->all(),
            'packages' => $trainee
                ? $trainee->packages()->whereIn('status', ['active', 'completed'])->get()
                : collect(),
            'methods' => PaymentMethod::active()->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Payment::class);

        $data = $request->validate([
            'trainee_id' => ['required', 'integer', Rule::exists('trainees', 'id')->whereNull('deleted_at')],
            'trainee_package_id' => ['nullable', 'integer', 'exists:trainee_packages,id'],
            'payment_method_id' => ['required', 'integer', 'exists:payment_methods,id'],
            'source' => ['required', Rule::in(['package', 'extra_lesson', 'other'])],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:999999'],
            'paid_on' => ['required', 'date', 'before_or_equal:today'],
            'reference_number' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [], [
            'trainee_id' => 'المتدرب',
            'trainee_package_id' => 'الباقة',
            'payment_method_id' => 'طريقة الدفع',
            'amount' => 'المبلغ',
            'paid_on' => 'تاريخ الدفع',
            'reference_number' => 'رقم المرجع',
        ]);

        $trainee = Trainee::findOrFail($data['trainee_id']);
        abort_unless($request->user()->canAccessBranch($trainee->branch_id), 403);

        $payment = $this->payments->record($data, $trainee->branch_id);

        return redirect()
            ->route('admin.payments.show', $payment)
            ->with('toast', ['type' => 'success', 'message' => "تم تسجيل الدفعة برقم إيصال {$payment->receipt_number}."]);
    }

    public function show(Payment $payment): View
    {
        $this->authorize('view', $payment);

        return view('admin.payments.show', [
            'payment' => $payment->load(['trainee', 'traineePackage', 'paymentMethod', 'receiver', 'voider', 'branch']),
        ]);
    }

    /** Void a payment — reverses the cash movement and the paid amount. */
    public function void(Request $request, Payment $payment): RedirectResponse
    {
        $this->authorize('void', $payment);

        $data = $request->validate(
            ['reason' => ['required', 'string', 'max:255']],
            [],
            ['reason' => 'سبب الإلغاء'],
        );

        $this->payments->void($payment, $data['reason']);

        return back()->with('toast', ['type' => 'success', 'message' => 'تم إلغاء الدفعة وعكس أثرها المالي.']);
    }

    public function receipt(Payment $payment, PdfService $pdf): Response
    {
        $this->authorize('downloadReceipt', $payment);

        return response($pdf->paymentReceipt($payment), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="receipt-'.$payment->receipt_number.'.pdf"',
        ]);
    }

    /** Enrolments for a trainee — feeds the payment form's package selector. */
    public function traineePackages(Request $request, Trainee $trainee): \Illuminate\Http\JsonResponse
    {
        $this->authorize('view', $trainee);

        return response()->json([
            'packages' => $trainee->packages()
                ->whereIn('status', ['active', 'completed'])
                ->get()
                ->map(fn ($p) => [
                    'id' => $p->id,
                    'name' => $p->package_name,
                    'total' => (float) $p->total_amount,
                    'paid' => (float) $p->paid_amount,
                    'remaining' => $p->remainingAmount(),
                ]),
        ]);
    }
}
