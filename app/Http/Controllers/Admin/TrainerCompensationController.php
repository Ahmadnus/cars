<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PaymentMethod;
use App\Models\Trainer;
use App\Models\TrainerCompensationPayment;
use App\Models\TrainerCompensationRecord;
use App\Models\TrainerCompensationRule;
use App\Services\PdfService;
use App\Services\TrainerCompensationService;
use App\Support\BranchContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class TrainerCompensationController extends Controller
{
    public function __construct(
        protected TrainerCompensationService $compensation,
        protected BranchContext $branchContext,
    ) {
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', TrainerCompensationRecord::class);

        $period = $request->input('period', now()->format('Y-m'));

        $records = TrainerCompensationRecord::query()
            ->visibleTo($request->user())
            ->with(['trainer:id,uuid,full_name,trainer_number', 'branch:id,uuid,name'])
            ->where('period', $period)
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->orderByDesc('net_amount')
            ->paginate(30)
            ->withQueryString();

        $totals = TrainerCompensationRecord::query()
            ->visibleTo($request->user())
            ->where('period', $period)
            ->where('status', '!=', 'cancelled')
            ->selectRaw('SUM(net_amount) as net, SUM(paid_amount) as paid, SUM(lessons_count) as lessons')
            ->first();

        return view('admin.trainer-compensation.index', [
            'records' => $records,
            'period' => $period,
            'periods' => $this->periodOptions(),
            'totals' => [
                'net' => round((float) ($totals->net ?? 0), 2),
                'paid' => round((float) ($totals->paid ?? 0), 2),
                'remaining' => round((float) ($totals->net ?? 0) - (float) ($totals->paid ?? 0), 2),
                'lessons' => (int) ($totals->lessons ?? 0),
            ],
            'statuses' => self::statuses(),
        ]);
    }

    /** Calculate statements for every active trainer at the branch. */
    public function calculate(Request $request): RedirectResponse
    {
        $this->authorize('trainer_compensation.manage');

        $data = $request->validate(
            ['period' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/']],
            [],
            ['period' => 'الشهر'],
        );

        $branchId = $this->branchContext->defaultForWrite($request->user());
        abort_if($branchId === null, 422, 'يجب اختيار فرع قبل احتساب الأجور.');

        $records = $this->compensation->calculateForBranch($branchId, $data['period']);

        return redirect()
            ->route('admin.trainer-compensation.index', ['period' => $data['period']])
            ->with('toast', [
                'type' => 'success',
                'message' => "تم احتساب أجور {$records->count()} مدرب لشهر {$data['period']}.",
            ]);
    }

    public function show(TrainerCompensationRecord $record): View
    {
        $this->authorize('view', $record);

        return view('admin.trainer-compensation.show', [
            'record' => $record->load(['trainer', 'rule', 'branch', 'payments.paymentMethod', 'payments.payer']),
            'methods' => PaymentMethod::active()->pluck('label_ar', 'id')->all(),
        ]);
    }

    public function approve(TrainerCompensationRecord $record): RedirectResponse
    {
        $this->authorize('approve', $record);

        $this->compensation->approve($record);

        return back()->with('toast', ['type' => 'success', 'message' => 'تم اعتماد الكشف، ويمكن الآن صرفه.']);
    }

    public function pay(Request $request, TrainerCompensationRecord $record): RedirectResponse
    {
        $this->authorize('pay', $record);

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

        $payment = $this->compensation->pay(
            $record,
            (float) $data['amount'],
            $data['paid_on'],
            (int) $data['payment_method_id'],
            $data['reference_number'] ?? null,
            $data['notes'] ?? null,
        );

        return back()->with('toast', [
            'type' => 'success',
            'message' => "تم صرف الأجر برقم إيصال {$payment->receipt_number}.",
        ]);
    }

    public function receipt(TrainerCompensationPayment $payment, PdfService $pdf): Response
    {
        $this->authorize('view', $payment->record);

        return response($pdf->trainerPayoutReceipt($payment), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="trainer-payout-'.$payment->receipt_number.'.pdf"',
        ]);
    }

    // ------------------------------------------------------------------
    // Compensation rules
    // ------------------------------------------------------------------

    public function rules(Request $request): View
    {
        $this->authorize('trainer_compensation.view');

        return view('admin.trainer-compensation.rules', [
            'trainers' => Trainer::query()->visibleTo($request->user())->active()
                ->with(['compensationRules' => fn ($q) => $q->orderByDesc('effective_from')])
                ->orderBy('full_name')->get(),
            'models' => TrainerCompensationRule::MODELS,
        ]);
    }

    /**
     * Set a trainer's compensation rule.
     *
     * The service closes the previous rule rather than editing it, so past
     * statements still reproduce from the terms that applied at the time.
     */
    public function storeRule(Request $request, Trainer $trainer): RedirectResponse
    {
        $this->authorize('trainer_compensation.manage');
        abort_unless($request->user()->canAccessBranch($trainer->branch_id), 403);

        $data = $request->validate([
            'model' => ['required', Rule::in(array_keys(TrainerCompensationRule::MODELS))],
            'base_salary' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'per_lesson_rate' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'revenue_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'effective_from' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [], [
            'model' => 'نموذج الأجر',
            'base_salary' => 'الراتب الأساسي',
            'per_lesson_rate' => 'المبلغ لكل حصة',
            'revenue_percentage' => 'النسبة',
            'effective_from' => 'تاريخ السريان',
        ]);

        $this->compensation->setRule($trainer, $data);

        return back()->with('toast', ['type' => 'success', 'message' => 'تم تحديث قاعدة أجر المدرب.']);
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
            'draft' => 'مسودة',
            'approved' => 'معتمد',
            'partially_paid' => 'مصروف جزئياً',
            'paid' => 'مصروف بالكامل',
            'cancelled' => 'ملغى',
        ];
    }
}
