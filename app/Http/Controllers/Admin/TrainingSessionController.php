<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTrainingSessionRequest;
use App\Http\Requests\UpdateTrainingSessionRequest;
use App\Models\Trainee;
use App\Models\Trainer;
use App\Models\TrainingSession;
use App\Models\TrainingSkill;
use App\Models\Vehicle;
use App\Services\AppointmentService;
use App\Services\TrainingSessionService;
use App\Support\BranchContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TrainingSessionController extends Controller
{
    public function __construct(
        protected AppointmentService $appointments,
        protected TrainingSessionService $sessions,
        protected BranchContext $branchContext,
    ) {
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', TrainingSession::class);

        $sessions = TrainingSession::query()
            ->visibleTo($request->user())
            ->with(['trainee:id,uuid,full_name,trainee_number', 'trainer:id,uuid,full_name', 'vehicle:id,uuid,name'])
            ->when($request->filled('search'), function (Builder $q) use ($request) {
                $term = trim($request->string('search'));

                $q->whereHas('trainee', fn (Builder $t) => $t
                    ->where('full_name', 'like', "%{$term}%")
                    ->orWhere('trainee_number', 'like', "%{$term}%"));
            })
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->input('status')))
            ->when($request->filled('trainer_id'), fn (Builder $q) => $q->where('trainer_id', $request->integer('trainer_id')))
            ->when($request->filled('vehicle_id'), fn (Builder $q) => $q->where('vehicle_id', $request->integer('vehicle_id')))
            ->when($request->filled('from'), fn (Builder $q) => $q->whereDate('scheduled_date', '>=', $request->date('from')))
            ->when($request->filled('to'), fn (Builder $q) => $q->whereDate('scheduled_date', '<=', $request->date('to')))
            ->orderByDesc('scheduled_date')
            ->orderByDesc('start_time')
            ->paginate(20)
            ->withQueryString();

        return view('admin.sessions.index', [
            'sessions' => $sessions,
            'trainers' => $this->trainerOptions($request),
            'vehicles' => $this->vehicleOptions($request),
            'statuses' => self::statuses(),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', TrainingSession::class);

        return view('admin.sessions.create', [
            'trainees' => $this->traineeOptions($request),
            'trainers' => $this->trainerOptions($request),
            'vehicles' => $this->vehicleOptions($request),
            'defaultDuration' => settings('training.default_lesson_duration', 45),
            'prefill' => [
                'trainee_id' => $request->integer('trainee_id') ?: null,
                'scheduled_date' => $request->input('date', now()->toDateString()),
            ],
        ]);
    }

    public function store(StoreTrainingSessionRequest $request): RedirectResponse
    {
        $session = $this->appointments->schedule(
            $request->validated(),
            $this->branchContext->defaultForWrite($request->user()),
        );

        return redirect()
            ->route('admin.sessions.show', $session)
            ->with('toast', ['type' => 'success', 'message' => 'تم حجز الحصة بنجاح.']);
    }

    public function show(TrainingSession $session): View
    {
        $this->authorize('view', $session);

        $session->load([
            'trainee.trainer', 'trainer', 'vehicle', 'traineePackage',
            'skills.skill', 'completer', 'canceller', 'creator',
        ]);

        return view('admin.sessions.show', [
            'session' => $session,
            'skills' => TrainingSkill::active()->get(),
            'ratings' => self::ratings(),
        ]);
    }

    public function edit(Request $request, TrainingSession $session): View
    {
        $this->authorize('update', $session);

        return view('admin.sessions.edit', [
            'session' => $session,
            'trainers' => $this->trainerOptions($request),
            'vehicles' => $this->vehicleOptions($request),
        ]);
    }

    /** Move a lesson — the service re-checks conflicts server-side. */
    public function update(UpdateTrainingSessionRequest $request, TrainingSession $session): RedirectResponse
    {
        $this->appointments->reschedule(
            $session,
            $request->validated(),
            $request->input('reason'),
        );

        return redirect()
            ->route('admin.sessions.show', $session)
            ->with('toast', ['type' => 'success', 'message' => 'تم تعديل موعد الحصة.']);
    }

    public function complete(Request $request, TrainingSession $session): RedirectResponse
    {
        $this->authorize('complete', $session);

        $data = $request->validate([
            'overall_rating' => ['nullable', 'in:needs_training,average,good,very_good,excellent'],
            'strengths' => ['nullable', 'string', 'max:2000'],
            'weaknesses' => ['nullable', 'string', 'max:2000'],
            'trainer_notes' => ['nullable', 'string', 'max:2000'],
            'next_requirements' => ['nullable', 'string', 'max:2000'],
            'duration_minutes' => ['nullable', 'integer', 'min:10', 'max:300'],
            'skills' => ['nullable', 'array'],
            'skills.*.skill_id' => ['required_with:skills', 'integer', 'exists:training_skills,id'],
            'skills.*.rating' => ['required_with:skills', 'in:not_started,needs_training,average,good,very_good,excellent'],
            'skills.*.note' => ['nullable', 'string', 'max:255'],
        ], [], [
            'overall_rating' => 'التقييم العام',
            'duration_minutes' => 'مدة الحصة',
        ]);

        $this->sessions->complete($session, $data);

        return redirect()
            ->route('admin.sessions.show', $session)
            ->with('toast', ['type' => 'success', 'message' => 'تم إنهاء الحصة وخصم حصة من رصيد المتدرب.']);
    }

    public function cancel(Request $request, TrainingSession $session): RedirectResponse
    {
        $this->authorize('cancel', $session);

        $validated = $request->validate(
            ['reason' => ['required', 'string', 'max:255']],
            [],
            ['reason' => 'سبب الإلغاء'],
        );

        $this->appointments->cancel($session, $validated['reason']);

        return back()->with('toast', ['type' => 'success', 'message' => 'تم إلغاء الحصة.']);
    }

    public function postpone(Request $request, TrainingSession $session): RedirectResponse
    {
        $this->authorize('cancel', $session);

        $validated = $request->validate(
            ['reason' => ['required', 'string', 'max:255']],
            [],
            ['reason' => 'سبب التأجيل'],
        );

        $this->appointments->postpone($session, $validated['reason']);

        return back()->with('toast', ['type' => 'success', 'message' => 'تم تأجيل الحصة.']);
    }

    public function noShow(Request $request, TrainingSession $session): RedirectResponse
    {
        $this->authorize('complete', $session);

        $validated = $request->validate(['note' => ['nullable', 'string', 'max:255']]);

        $this->sessions->markNoShow($session, $validated['note'] ?? null);

        return back()->with('toast', ['type' => 'warning', 'message' => 'تم تسجيل عدم حضور المتدرب.']);
    }

    public function reopen(Request $request, TrainingSession $session): RedirectResponse
    {
        $this->authorize('reopen', $session);

        $validated = $request->validate(
            ['reason' => ['required', 'string', 'max:255']],
            [],
            ['reason' => 'سبب إعادة الفتح'],
        );

        $this->sessions->reopen($session, $validated['reason']);

        return back()->with('toast', ['type' => 'success', 'message' => 'تمت إعادة فتح الحصة وإرجاع الحصة إلى الرصيد.']);
    }

    /** Free slots for a trainer on a date — used by the booking form. */
    public function availableSlots(Request $request): \Illuminate\Http\JsonResponse
    {
        $this->authorize('create', TrainingSession::class);

        $validated = $request->validate([
            'trainer_id' => ['required', 'integer', 'exists:trainers,id'],
            'date' => ['required', 'date'],
            'duration_minutes' => ['nullable', 'integer', 'min:10', 'max:300'],
            'vehicle_id' => ['nullable', 'integer', 'exists:vehicles,id'],
        ]);

        $trainer = Trainer::findOrFail($validated['trainer_id']);
        abort_unless($request->user()->canAccessBranch($trainer->branch_id), 403);

        return response()->json([
            'slots' => $this->appointments->availableSlots(
                $trainer,
                \Carbon\Carbon::parse($validated['date']),
                $validated['duration_minutes'] ?? null,
                $validated['vehicle_id'] ?? null,
            ),
        ]);
    }

    // ------------------------------------------------------------------

    protected function traineeOptions(Request $request): array
    {
        return Trainee::query()
            ->visibleTo($request->user())
            ->active()
            ->orderBy('full_name')
            ->get(['id', 'full_name', 'trainee_number'])
            ->mapWithKeys(fn ($t) => [$t->id => "{$t->full_name} ({$t->trainee_number})"])
            ->all();
    }

    protected function trainerOptions(Request $request): array
    {
        return Trainer::query()->visibleTo($request->user())->active()
            ->orderBy('full_name')->pluck('full_name', 'id')->all();
    }

    protected function vehicleOptions(Request $request): array
    {
        return Vehicle::query()->visibleTo($request->user())->bookable()
            ->orderBy('name')
            ->get(['id', 'name', 'plate_number'])
            ->mapWithKeys(fn ($v) => [$v->id => "{$v->name} — {$v->plate_number}"])
            ->all();
    }

    /** @return array<string, string> */
    public static function statuses(): array
    {
        return [
            'scheduled' => 'مجدولة',
            'completed' => 'منجزة',
            'cancelled' => 'ملغاة',
            'postponed' => 'مؤجلة',
            'no_show' => 'عدم حضور',
        ];
    }

    /** @return array<string, string> */
    public static function ratings(): array
    {
        return [
            'not_started' => 'لم يبدأ',
            'needs_training' => 'يحتاج تدريب',
            'average' => 'متوسط',
            'good' => 'جيد',
            'very_good' => 'جيد جداً',
            'excellent' => 'ممتاز',
        ];
    }
}
