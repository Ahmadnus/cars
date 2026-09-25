<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\BookingRequestResource;
use App\Http\Resources\PaymentResource;
use App\Http\Resources\SkillEvaluationResource;
use App\Http\Resources\TraineePackageResource;
use App\Http\Resources\TrainingSessionResource;
use App\Models\Trainee;
use App\Services\AppointmentService;
use App\Services\PaymentService;
use App\Services\TraineeBalanceService;
use App\Services\TrainingSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The signed-in trainee's own file, for the Trainee app.
 *
 * Every query is rooted at `$request->user()->trainee`, so there is no id in
 * any route for a client to substitute. This is why a trainee needs none of the
 * center-wide permissions: `trainees.view` would expose all twenty trainees,
 * while these endpoints can only ever return one.
 */
class MeController extends ApiController
{
    public function __construct(
        protected TraineeBalanceService $balances,
        protected TrainingSessionService $sessions,
        protected PaymentService $payments,
    ) {
    }

    /** Everything the home screen needs, in one round trip. */
    public function home(Request $request): JsonResponse
    {
        $trainee = $this->trainee($request);
        $package = $trainee->activePackage();

        $upcoming = $trainee->trainingSessions()
            ->with(['trainer:id,uuid,full_name,phone', 'vehicle:id,uuid,name,plate_number'])
            ->scheduled()
            ->whereDate('scheduled_date', '>=', now()->toDateString())
            ->orderBy('scheduled_date')->orderBy('start_time')
            ->limit(5)
            ->get();

        $next = $upcoming->first();

        return $this->ok([
            'trainee' => [
                'id' => $trainee->uuid,
                'full_name' => $trainee->full_name,
                'trainee_number' => $trainee->trainee_number,
                'status' => $trainee->status,
                'license_type' => $trainee->license_type,
                'exam_date' => $trainee->exam_date?->toDateString(),
                'branch' => $trainee->branch?->name,
            ],
            'trainer' => $trainee->trainer ? [
                'id' => $trainee->trainer->uuid,
                'full_name' => $trainee->trainer->full_name,
                'phone' => $trainee->trainer->phone,
            ] : null,
            'package' => $package ? new TraineePackageResource($package) : null,
            'balance' => $package ? $this->balances->summary($package) : null,
            'next_lesson' => $next ? new TrainingSessionResource($next) : null,
            'upcoming' => TrainingSessionResource::collection($upcoming),
            'progress' => [
                'readiness_percent' => $this->sessions->readinessPercent($trainee->id),
                'completed_lessons' => $package?->completedLessons() ?? 0,
                'remaining_lessons' => $package?->remainingLessons() ?? 0,
            ],
            // A trainee may always see their own account, and only their own.
            'financial' => [
                'outstanding' => round($this->payments->outstandingForTrainee($trainee), 2),
                'paid' => round((float) $trainee->payments()->completed()->sum('amount'), 2),
            ],
            'unread_notifications' => $request->user()->unreadNotifications()->count(),
        ]);
    }

    /** The trainee's own lessons, newest first, optionally filtered by status. */
    public function sessions(Request $request): JsonResponse
    {
        $trainee = $this->trainee($request);

        $sessions = $trainee->trainingSessions()
            ->with(['trainer:id,uuid,full_name', 'vehicle:id,uuid,name,plate_number'])
            ->when(
                $request->filled('status'),
                fn ($q) => $q->where('training_sessions.status', $request->input('status')),
            )
            ->orderByDesc('scheduled_date')->orderByDesc('start_time')
            ->paginate($this->perPage());

        return $this->paginated($sessions, TrainingSessionResource::class);
    }

    public function show(Request $request, string $session): JsonResponse
    {
        $trainee = $this->trainee($request);

        $record = $trainee->trainingSessions()
            ->with(['trainer:id,uuid,full_name,phone', 'vehicle:id,uuid,name,plate_number', 'skills.skill'])
            ->where('training_sessions.uuid', $session)
            ->first();

        if (! $record) {
            return $this->failed('الحصة غير موجودة.', status: 404);
        }

        return $this->ok(new TrainingSessionResource($record));
    }

    public function skills(Request $request): JsonResponse
    {
        $trainee = $this->trainee($request);

        return $this->ok([
            'readiness_percent' => $this->sessions->readinessPercent($trainee->id),
            'skills' => SkillEvaluationResource::collection(
                $trainee->skillEvaluations()->with('skill')->get()
                    ->sortBy(fn ($evaluation) => $evaluation->skill?->sort_order ?? 0)
                    ->values()
            ),
        ]);
    }

    public function packages(Request $request): JsonResponse
    {
        $trainee = $this->trainee($request);

        return $this->ok(
            TraineePackageResource::collection(
                $trainee->packages()->with('package')->orderByDesc('created_at')->get()
            ),
        );
    }

    public function payments(Request $request): JsonResponse
    {
        $trainee = $this->trainee($request);

        $payments = $trainee->payments()
            ->with('paymentMethod')
            ->orderByDesc('paid_on')
            ->paginate($this->perPage());

        return $this->paginated($payments, PaymentResource::class, meta: [
            'outstanding' => round($this->payments->outstandingForTrainee($trainee), 2),
            'paid' => round((float) $trainee->payments()->completed()->sum('amount'), 2),
        ]);
    }

    /**
     * Free times the trainee could ask for, for the booking screen.
     *
     * The center-wide slots endpoint takes a trainer id and needs
     * `appointments.view`, which a trainee must never hold. This one reads the
     * trainee's own trainer by default and will only accept another trainer
     * who works at the trainee's own branch — so it cannot be used to map a
     * different branch's schedule.
     */
    public function slots(Request $request, AppointmentService $appointments): JsonResponse
    {
        $trainee = $this->trainee($request);

        $data = $request->validate([
            'date' => ['required', 'date'],
            'trainer_id' => ['nullable', 'string'],
        ], [], ['date' => 'التاريخ', 'trainer_id' => 'المدرب']);

        $trainer = $data['trainer_id'] ?? null
            ? $this->branchTrainers($trainee)->firstWhere('uuid', $data['trainer_id'])
            : $trainee->trainer;

        if (! $trainer) {
            return $this->failed('لم يتم تحديد مدرب. تواصل مع المركز.', status: 404);
        }

        $date = \Illuminate\Support\Carbon::parse($data['date']);

        // Booking into the past is never a real request, and letting it
        // through would put an unactionable row in the admin's queue.
        if ($date->isBefore(now()->startOfDay())) {
            return $this->ok([
                'trainer' => ['id' => $trainer->uuid, 'full_name' => $trainer->full_name],
                'date' => $date->toDateString(),
                'slots' => [],
            ]);
        }

        $duration = $trainee->activePackage()?->package?->lesson_duration_minutes;

        return $this->ok([
            'trainer' => ['id' => $trainer->uuid, 'full_name' => $trainer->full_name],
            'date' => $date->toDateString(),
            'slots' => $appointments->availableSlots($trainer, $date, $duration),
        ]);
    }

    /** Trainers the trainee may name as a preference on a request. */
    public function trainers(Request $request): JsonResponse
    {
        $trainee = $this->trainee($request);

        return $this->ok(
            $this->branchTrainers($trainee)
                ->map(fn ($trainer) => [
                    'id' => $trainer->uuid,
                    'full_name' => $trainer->full_name,
                    'is_mine' => $trainer->id === $trainee->trainer_id,
                ])
                ->values(),
        );
    }

    /**
     * The trainee's own open requests, so the app can show what is still
     * waiting on the office rather than letting them ask twice.
     */
    public function bookingRequests(Request $request): JsonResponse
    {
        $trainee = $this->trainee($request);

        $requests = $trainee->bookingRequests()
            ->with(['trainingSession:id,uuid,scheduled_date,start_time,end_time,status', 'preferredTrainer:id,uuid,full_name'])
            ->orderByDesc('created_at')
            ->paginate($this->perPage());

        return $this->paginated($requests, BookingRequestResource::class, meta: [
            'pending' => $trainee->bookingRequests()->where('status', 'pending')->count(),
        ]);
    }

    /**
     * Active trainers at the trainee's branch. Deliberately name-only: this is
     * a picker, not a staff directory.
     *
     * @return \Illuminate\Support\Collection<int, \App\Models\Trainer>
     */
    protected function branchTrainers(Trainee $trainee): \Illuminate\Support\Collection
    {
        return \App\Models\Trainer::query()
            ->where('branch_id', $trainee->branch_id)
            ->where('status', 'active')
            ->orderBy('full_name')
            ->get(['id', 'uuid', 'full_name', 'branch_id', 'work_start_time', 'work_end_time']);
    }

    /**
     * The trainee record behind the token.
     *
     * A `trainee` token with no linked file is a provisioning mistake; failing
     * loudly is safer than returning an empty file that looks like "no data".
     */
    protected function trainee(Request $request): Trainee
    {
        $trainee = $request->user()->trainee;

        abort_if(! $trainee, 403, 'هذا الحساب غير مرتبط بملف متدرب.');

        return $trainee->load('trainer:id,uuid,full_name,phone', 'branch:id,uuid,name');
    }
}
