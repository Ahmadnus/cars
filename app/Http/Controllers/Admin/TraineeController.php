<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\IssuesLoginAccounts;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTraineeRequest;
use App\Http\Requests\UpdateTraineeRequest;
use App\Models\Branch;
use App\Models\Package;
use App\Models\Trainee;
use App\Models\Trainer;
use App\Models\TrainingSkill;
use App\Services\AccountService;
use App\Services\AuditLogger;
use App\Services\DocumentService;
use App\Services\IdCardService;
use App\Services\NumberGenerator;
use App\Services\PaymentService;
use App\Services\TraineeBalanceService;
use App\Services\TrainingSessionService;
use App\Support\BranchContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class TraineeController extends Controller
{
    use IssuesLoginAccounts;

    /** Form fields that are files rather than columns on the trainee. */
    protected const UPLOADS = ['photo', 'id_photo'];

    public function __construct(
        protected BranchContext $branchContext,
        protected NumberGenerator $numbers,
        protected AuditLogger $audit,
        protected TraineeBalanceService $balances,
        protected DocumentService $documents,
        protected IdCardService $cards,
    ) {
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Trainee::class);

        $trainees = Trainee::query()
            ->visibleTo($request->user())
            // The same rule the API applies: a trainer sees their own trainees
            // and no one else's. Without it this page answered differently from
            // /api/v1/trainees for the same account — and the policy already
            // refuses to open a colleague's trainee, so listing them here only
            // exposed names and numbers with nowhere to go.
            ->when(
                $request->user()->trainer && ! $request->user()->hasPermission('trainees.update'),
                fn (Builder $q) => $q->where('trainer_id', $request->user()->trainer->id),
            )
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
            // Whether the form offers to fill itself from an ID photo. Off when
            // no reader is configured, so the card never appears as a control
            // that silently does nothing.
            'canReadIds' => $this->cards->isEnabled(),
        ]);
    }

    public function store(StoreTraineeRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $trainee = DB::transaction(function () use ($data, $request) {
            // The uploads are not columns. They have to come out before the
            // model is filled, or strict mass assignment refuses the whole
            // record over an attribute that was never going to be stored.
            $trainee = Trainee::create(array_merge(Arr::except($data, self::UPLOADS), [
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

        $this->keepIdPhoto($request, $trainee);

        // The office creates the trainee's login here rather than making them
        // apply through the app: someone standing at the desk should leave able
        // to sign in. Outside the transaction above, so a clashing phone number
        // does not discard the trainee's file.
        if ($request->boolean('create_login')) {
            return $this->issueAccountFor(
                $trainee,
                $this->validateCredentials($request),
                'admin.trainees.show',
                "تم تسجيل المتدرب برقم {$trainee->trainee_number} وإنشاء حساب الدخول.",
            );
        }

        return redirect()
            ->route('admin.trainees.show', $trainee)
            ->with('toast', ['type' => 'success', 'message' => "تم تسجيل المتدرب برقم {$trainee->trainee_number}."]);
    }

    /**
     * Create the trainee's app login, or reset its password.
     *
     * Behind `trainees.update`: it administers one trainee's own access and
     * cannot reach any other account.
     */
    public function issueAccount(Request $request, Trainee $trainee): RedirectResponse
    {
        $this->authorize('update', $trainee);

        $data = $this->validateCredentials($request, $trainee->user_id);

        return $this->issueAccountFor($trainee, $data, 'admin.trainees.show');
    }

    /** Close the login. The training file, payments and evaluations stay. */
    public function suspendAccount(Trainee $trainee, AccountService $accounts): RedirectResponse
    {
        $this->authorize('update', $trainee);

        $accounts->suspend($trainee);

        return redirect()
            ->route('admin.trainees.show', $trainee)
            ->with('toast', ['type' => 'success', 'message' => 'تم تعطيل حساب المتدرب.']);
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
            'canReadIds' => $this->cards->isEnabled(),
        ]);
    }

    public function update(UpdateTraineeRequest $request, Trainee $trainee): RedirectResponse
    {
        $data = $request->validated();
        $original = $trainee->getOriginal();

        DB::transaction(function () use ($trainee, $data, $request, $original) {
            $trainee->fill(Arr::except($data, self::UPLOADS));

            if ($request->hasFile('photo')) {
                $trainee->photo_path = $request->file('photo')->store('trainees/photos', 'public');
            }

            $trainee->save();

            $this->audit->logUpdate('trainee.updated', $trainee, $original);
        });

        $this->keepIdPhoto($request, $trainee);

        return redirect()
            ->route('admin.trainees.show', $trainee)
            ->with('toast', ['type' => 'success', 'message' => 'تم تحديث بيانات المتدرب.']);
    }

    /**
     * Read a photo of an ID so the form in front of the receptionist fills
     * itself.
     *
     * Answers JSON rather than redirecting because of where it is used: someone
     * is halfway through a form with a phone number already typed, and a round
     * trip would either lose that or have to echo the whole form back to restore
     * it. The reading is handed to the page and the page fills the empty boxes.
     *
     * Nothing is stored here. The photo is read and dropped; it is kept only if
     * the form is actually submitted with it, which is where it becomes the
     * trainee's identity document.
     */
    public function scanId(Request $request): JsonResponse
    {
        $request->validate([
            'id_photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
        ], [], ['id_photo' => 'صورة الهوية']);

        $reading = $this->cards->readUpload($request->file('id_photo'));

        return response()->json([
            'outcome' => $reading->outcome,
            'fields' => $reading->fields(),
            'unclear' => $reading->unclear,
            'message' => $reading->message,
        ]);
    }

    /**
     * Keep the ID photo the form was filled from.
     *
     * Stored as the trainee's identity document, the same category and the same
     * private disk as a photo that arrived with a join request — so a trainee
     * registered at the desk ends up with the same file on their record as one
     * who applied through the app.
     *
     * Outside the transaction and swallowed on failure: the trainee is already
     * saved by now, and a document that failed to store is something staff can
     * upload again from the documents tab. Losing the record over it would not
     * be.
     */
    protected function keepIdPhoto(Request $request, Trainee $trainee): void
    {
        if (! $request->hasFile('id_photo')) {
            return;
        }

        try {
            $this->documents->store($request->file('id_photo'), $trainee, 'identity', 'صورة الهوية');
        } catch (\Throwable $e) {
            report($e);
        }
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
