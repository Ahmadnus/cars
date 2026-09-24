<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTraineeRequest;
use App\Http\Requests\UpdateTraineeRequest;
use App\Models\Branch;
use App\Models\Package;
use App\Models\Trainee;
use App\Models\Trainer;
use App\Models\TrainingSkill;
use App\Services\AuditLogger;
use App\Services\NumberGenerator;
use App\Services\PaymentService;
use App\Services\TraineeBalanceService;
use App\Services\TrainingSessionService;
use App\Support\BranchContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class TraineeController extends Controller
{
    public function __construct(
        protected BranchContext $branchContext,
        protected NumberGenerator $numbers,
        protected AuditLogger $audit,
        protected TraineeBalanceService $balances,
    ) {
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Trainee::class);

        $trainees = Trainee::query()
            ->visibleTo($request->user())
            ->with(['trainer:id,uuid,full_name', 'branch:id,uuid,name'])
            ->when($request->filled('search'), function (Builder $q) use ($request) {
                $term = trim($request->string('search'));

                $q->where(function (Builder $inner) use ($term) {
                    $inner->where('full_name', 'like', "%{$term}%")
                        ->orWhere('trainee_number', 'like', "%{$term}%")
                        ->orWhere('phone', 'like', "%{$term}%")
                        ->orWhere('national_id', 'like', "%{$term}%");
                });
            })
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->input('status')))
            ->when($request->filled('trainer_id'), fn (Builder $q) => $q->where('trainer_id', $request->integer('trainer_id')))
            ->when($request->filled('license_type'), fn (Builder $q) => $q->where('license_type', $request->input('license_type')))
            ->when($request->filled('from'), fn (Builder $q) => $q->whereDate('registration_date', '>=', $request->date('from')))
            ->when($request->filled('to'), fn (Builder $q) => $q->whereDate('registration_date', '<=', $request->date('to')))
            ->orderBy(
                in_array($request->input('sort'), ['full_name', 'registration_date', 'trainee_number', 'status'], true)
                    ? $request->input('sort')
                    : 'registration_date',
                $request->input('direction') === 'asc' ? 'asc' : 'desc',
            )
            ->paginate(20)
            ->withQueryString();

        return view('admin.trainees.index', [
            'trainees' => $trainees,
            'trainers' => $this->trainerOptions($request),
            'statuses' => $this->statuses(),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', Trainee::class);

        return view('admin.trainees.create', [
            'trainers' => $this->trainerOptions($request),
            'branches' => $this->branchOptions($request),
            'statuses' => $this->statuses(),
        ]);
    }

    public function store(StoreTraineeRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $trainee = DB::transaction(function () use ($data, $request) {
            $trainee = Trainee::create(array_merge($data, [
                'trainee_number' => $this->numbers->traineeNumber(),
                'branch_id' => $data['branch_id'] ?? $this->branchContext->defaultForWrite($request->user()),
                'status' => $data['status'] ?? 'new',
            ]));

            if ($request->hasFile('photo')) {
                $trainee->update([
                    'photo_path' => $request->file('photo')->store('trainees/photos', 'public'),
                ]);
            }

            $this->audit->logCreate('trainee.created', $trainee, 'إضافة متدرب جديد');

            return $trainee;
        });

        return redirect()
            ->route('admin.trainees.show', $trainee)
            ->with('toast', ['type' => 'success', 'message' => "تم تسجيل المتدرب برقم {$trainee->trainee_number}."]);
    }

    public function show(Request $request, Trainee $trainee, TrainingSessionService $sessions, PaymentService $payments): View
    {
        $this->authorize('view', $trainee);

        $trainee->load(['trainer', 'branch', 'user']);

        $activePackage = $trainee->activePackage();

        $data = [
            'trainee' => $trainee,
            'activePackage' => $activePackage,
            'balance' => $activePackage ? $this->balances->summary($activePackage) : null,
            'readiness' => $sessions->readinessPercent($trainee->id),
            'sessions' => $trainee->trainingSessions()
                ->with(['trainer:id,uuid,full_name', 'vehicle:id,uuid,name'])
                ->orderByDesc('scheduled_date')
                ->orderByDesc('start_time')
                ->paginate(10, ['*'], 'sessions_page'),
            'evaluations' => TrainingSkill::active()
                ->with(['evaluations' => fn ($q) => $q->where('trainee_id', $trainee->id)])
                ->get(),
            'notes' => $trainee->notes()->with('author:id,name')->limit(20)->get(),
            'documents' => $request->user()->can('manageDocuments', $trainee)
                ? $trainee->documents()->with('uploader:id,name')->latest()->get()
                : collect(),
        ];

        // Financial figures are only assembled for users allowed to see them.
        if ($request->user()->can('viewFinancials', $trainee)) {
            $data['packages'] = $trainee->packages()->latest('started_on')->get();
            $data['payments'] = $trainee->payments()->with('paymentMethod')->latest('paid_on')->limit(20)->get();
            $data['outstanding'] = $payments->outstandingForTrainee($trainee);
        }

        return view('admin.trainees.show', $data);
    }

    public function edit(Request $request, Trainee $trainee): View
    {
        $this->authorize('update', $trainee);

        return view('admin.trainees.edit', [
            'trainee' => $trainee,
            'trainers' => $this->trainerOptions($request),
            'branches' => $this->branchOptions($request),
            'statuses' => $this->statuses(),
        ]);
    }

    public function update(UpdateTraineeRequest $request, Trainee $trainee): RedirectResponse
    {
        $data = $request->validated();
        $original = $trainee->getOriginal();

        DB::transaction(function () use ($trainee, $data, $request, $original) {
            $trainee->fill($data);

            if ($request->hasFile('photo')) {
                $trainee->photo_path = $request->file('photo')->store('trainees/photos', 'public');
            }

            $trainee->save();

            $this->audit->logUpdate('trainee.updated', $trainee, $original);
        });

        return redirect()
            ->route('admin.trainees.show', $trainee)
            ->with('toast', ['type' => 'success', 'message' => 'تم تحديث بيانات المتدرب.']);
    }

    /**
     * Archive a trainee.
     *
     * A trainee with financial history is never removed from the database —
     * the record is soft-deleted and marked cancelled so reports still
     * reconcile.
     */
    public function destroy(Trainee $trainee): RedirectResponse
    {
        $this->authorize('delete', $trainee);

        DB::transaction(function () use ($trainee) {
            $trainee->update(['status' => 'cancelled']);
            $this->audit->logDelete('trainee.archived', $trainee);
            $trainee->delete();
        });

        return redirect()
            ->route('admin.trainees.index')
            ->with('toast', ['type' => 'success', 'message' => 'تم أرشفة المتدرب.']);
    }

    // ------------------------------------------------------------------
    // Option helpers
    // ------------------------------------------------------------------

    protected function trainerOptions(Request $request): array
    {
        return Trainer::query()
            ->visibleTo($request->user())
            ->active()
            ->orderBy('full_name')
            ->pluck('full_name', 'id')
            ->all();
    }

    protected function branchOptions(Request $request): array
    {
        return Branch::query()
            ->whereIn('id', $request->user()->accessibleBranchIds())
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /** @return array<string, string> */
    public static function statuses(): array
    {
        return [
            'new' => 'جديد',
            'in_training' => 'قيد التدريب',
            'suspended' => 'موقوف مؤقتاً',
            'ready_for_exam' => 'جاهز للامتحان',
            'exam_scheduled' => 'لديه موعد امتحان',
            'passed' => 'نجح في الامتحان',
            'failed' => 'رسب في الامتحان',
            'completed' => 'أنهى التدريب',
            'cancelled' => 'ملغى',
        ];
    }
}
