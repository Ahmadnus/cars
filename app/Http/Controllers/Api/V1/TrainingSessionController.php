<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\StoreTrainingSessionRequest;
use App\Http\Resources\TrainingSessionResource;
use App\Http\Resources\TrainingSkillResource;
use App\Models\Trainer;
use App\Models\TrainingSession;
use App\Models\TrainingSkill;
use App\Services\AppointmentService;
use App\Services\TrainingSessionService;
use App\Support\BranchContext;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lessons for the Trainer app.
 *
 * `/appointments` and `/training-sessions` are the same resource: the first is
 * the scheduling view, the second the delivery view. Both run the same service
 * code as the dashboard, so conflict and balance rules apply identically.
 */
class TrainingSessionController extends ApiController
{
    public function __construct(
        protected AppointmentService $appointments,
        protected TrainingSessionService $sessions,
        protected BranchContext $branchContext,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', TrainingSession::class);

        $sessions = TrainingSession::query()
            ->visibleTo($request->user())
            ->with([
                'trainee:id,uuid,full_name,phone,trainee_number',
                'trainer:id,uuid,full_name',
                'vehicle:id,uuid,name,plate_number',
            ])
            // A trainer's app only ever lists their own lessons.
            ->when($request->user()->trainer && ! $request->user()->hasPermission('appointments.update'),
                fn (Builder $q) => $q->where('trainer_id', $request->user()->trainer->id))
            // A trainee's app only ever lists their own lessons.
            ->when($request->user()->trainee,
                fn (Builder $q) => $q->where('trainee_id', $request->user()->trainee->id))
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->input('status')))
            ->when($request->filled('date'), fn (Builder $q) => $q->whereDate('scheduled_date', $request->date('date')))
            ->when($request->filled('from'), fn (Builder $q) => $q->whereDate('scheduled_date', '>=', $request->date('from')))
            ->when($request->filled('to'), fn (Builder $q) => $q->whereDate('scheduled_date', '<=', $request->date('to')))
            ->orderBy('scheduled_date', $request->input('direction', 'desc'))
            ->orderBy('start_time')
            ->paginate($this->perPage());

        return $this->paginated($sessions, TrainingSessionResource::class);
    }

    /** Today's lessons — the Trainer app's home screen. */
    public function today(Request $request): JsonResponse
    {
        $this->authorize('viewAny', TrainingSession::class);

        $sessions = TrainingSession::query()
            ->visibleTo($request->user())
            ->with(['trainee:id,uuid,full_name,phone,trainee_number', 'vehicle:id,uuid,name,plate_number', 'trainer:id,uuid,full_name'])
            ->whereDate('scheduled_date', now()->toDateString())
            ->when($request->user()->trainer, fn (Builder $q) => $q->where('trainer_id', $request->user()->trainer->id))
            ->when($request->user()->trainee, fn (Builder $q) => $q->where('trainee_id', $request->user()->trainee->id))
            ->orderBy('start_time')
            ->get();

        return $this->ok(TrainingSessionResource::collection($sessions));
    }

    public function show(TrainingSession $session): JsonResponse
    {
        $this->authorize('view', $session);

        return $this->ok(new TrainingSessionResource(
            $session->load(['trainee', 'trainer', 'vehicle', 'skills.skill'])
        ));
    }

    public function store(StoreTrainingSessionRequest $request): JsonResponse
    {
        $session = $this->appointments->schedule(
            $request->validated(),
            $this->branchContext->defaultForWrite($request->user()),
        );

        return $this->created(
            new TrainingSessionResource($session->load(['trainee', 'trainer', 'vehicle'])),
            'تم حجز الحصة بنجاح.',
        );
    }

    public function update(Request $request, TrainingSession $session): JsonResponse
    {
        $this->authorize('update', $session);

        $data = $request->validate([
            'trainer_id' => ['nullable', 'integer', 'exists:trainers,id'],
            'vehicle_id' => ['nullable', 'integer', 'exists:vehicles,id'],
            'scheduled_date' => ['required', 'date'],
            'start_time' => ['required', 'date_format:H:i'],
            'duration_minutes' => ['nullable', 'integer', 'min:15', 'max:300'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $session = $this->appointments->reschedule($session, $data, $data['reason'] ?? null);

        return $this->ok(
            new TrainingSessionResource($session->load(['trainee', 'trainer', 'vehicle'])),
            'تم تعديل موعد الحصة.',
        );
    }

    /**
     * Complete a lesson and record its evaluation — the Trainer app's main
     * write operation. Consumes exactly one lesson from the trainee's balance.
     */
    public function complete(Request $request, TrainingSession $session): JsonResponse
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
        ]);

        $session = $this->sessions->complete($session, $data);

        return $this->ok(
            new TrainingSessionResource($session->load(['trainee', 'trainer', 'vehicle', 'skills.skill'])),
            'تم إنهاء الحصة وخصم حصة من رصيد المتدرب.',
        );
    }

    public function cancel(Request $request, TrainingSession $session): JsonResponse
    {
        $this->authorize('cancel', $session);

        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return $this->ok(
            new TrainingSessionResource($this->appointments->cancel($session, $data['reason'])),
            'تم إلغاء الحصة.',
        );
    }

    public function noShow(Request $request, TrainingSession $session): JsonResponse
    {
        $this->authorize('complete', $session);

        $data = $request->validate(['note' => ['nullable', 'string', 'max:255']]);

        return $this->ok(
            new TrainingSessionResource($this->sessions->markNoShow($session, $data['note'] ?? null)),
            'تم تسجيل عدم حضور المتدرب.',
        );
    }

    /** Free slots for a trainer on a date — used by the booking screens. */
    public function availableSlots(Request $request): JsonResponse
    {
        $data = $request->validate([
            'trainer_id' => ['required', 'string'],
            'date' => ['required', 'date'],
            'duration_minutes' => ['nullable', 'integer', 'min:10', 'max:300'],
        ]);

        $trainer = Trainer::where('uuid', $data['trainer_id'])->firstOrFail();
        abort_unless($request->user()->canAccessBranch($trainer->branch_id), 403);

        return $this->ok([
            'slots' => $this->appointments->availableSlots(
                $trainer,
                Carbon::parse($data['date']),
                $data['duration_minutes'] ?? null,
            ),
        ]);
    }

    /** The configurable syllabus, for the evaluation screen. */
    public function skills(): JsonResponse
    {
        return $this->ok(TrainingSkillResource::collection(TrainingSkill::active()->get()));
    }
}
