<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\StoreTraineeRequest;
use App\Http\Requests\UpdateTraineeRequest;
use App\Http\Resources\SkillEvaluationResource;
use App\Http\Resources\TraineePackageResource;
use App\Http\Resources\TraineeResource;
use App\Http\Resources\TrainingSessionResource;
use App\Models\Trainee;
use App\Services\AuditLogger;
use App\Services\NumberGenerator;
use App\Services\TraineeBalanceService;
use App\Services\TrainingSessionService;
use App\Support\BranchContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TraineeController extends ApiController
{
    public function __construct(
        protected TraineeBalanceService $balances,
        protected NumberGenerator $numbers,
        protected AuditLogger $audit,
        protected BranchContext $branchContext,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Trainee::class);

        $trainees = Trainee::query()
            ->visibleTo($request->user())
            ->with(['trainer:id,uuid,full_name,phone', 'branch:id,uuid,name'])
            // A trainer only ever sees their own trainees through the API.
            ->when($request->user()->trainer && ! $request->user()->hasPermission('trainees.update'),
                fn (Builder $q) => $q->where('trainer_id', $request->user()->trainer->id))
            ->when($request->filled('search'), function (Builder $q) use ($request) {
                $term = trim($request->string('search'));

                $q->where(fn (Builder $i) => $i
                    ->where('full_name', 'like', "%{$term}%")
                    ->orWhere('trainee_number', 'like', "%{$term}%")
                    ->orWhere('phone', 'like', "%{$term}%"));
            })
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->input('status')))
            ->when($request->filled('trainer_id'), function (Builder $q) use ($request) {
                $q->whereHas('trainer', fn ($t) => $t->where('uuid', $request->input('trainer_id')));
            })
            ->orderBy($this->sortColumn($request), $request->input('direction') === 'asc' ? 'asc' : 'desc')
            ->paginate($this->perPage());

        return $this->paginated($trainees, TraineeResource::class);
    }

    public function show(Request $request, Trainee $trainee, TrainingSessionService $sessions): JsonResponse
    {
        $this->authorize('view', $trainee);

        $trainee->load(['trainer:id,uuid,full_name,phone', 'branch:id,uuid,name']);

        $package = $trainee->activePackage();

        // Attached rather than queried inside the resource, so the resource
        // stays a pure mapping and we avoid an N+1 on the list endpoint.
        $trainee->balance_summary = $package ? $this->balances->summary($package) : null;
        $trainee->progress_percent = $sessions->readinessPercent($trainee->id);

        return $this->ok(new TraineeResource($trainee));
    }

    public function store(StoreTraineeRequest $request): JsonResponse
    {
        $data = $request->validated();

        $trainee = DB::transaction(function () use ($data, $request) {
            $trainee = Trainee::create(array_merge($data, [
                'trainee_number' => $this->numbers->traineeNumber(),
                'branch_id' => $data['branch_id'] ?? $this->branchContext->defaultForWrite($request->user()),
                'status' => $data['status'] ?? 'new',
            ]));

            $this->audit->logCreate('trainee.created', $trainee, 'إضافة متدرب عبر التطبيق');

            return $trainee;
        });

        return $this->created(
            new TraineeResource($trainee->load(['trainer', 'branch'])),
            "تم تسجيل المتدرب برقم {$trainee->trainee_number}.",
        );
    }

    public function update(UpdateTraineeRequest $request, Trainee $trainee): JsonResponse
    {
        $original = $trainee->getOriginal();

        DB::transaction(function () use ($trainee, $request, $original) {
            $trainee->update($request->validated());
            $this->audit->logUpdate('trainee.updated', $trainee, $original);
        });

        return $this->ok(
            new TraineeResource($trainee->fresh(['trainer', 'branch'])),
            'تم تحديث بيانات المتدرب.',
        );
    }

    /** Lessons for one trainee. */
    public function sessions(Request $request, Trainee $trainee): JsonResponse
    {
        $this->authorize('view', $trainee);

        $sessions = $trainee->trainingSessions()
            ->with(['trainer:id,uuid,full_name', 'vehicle:id,uuid,name,plate_number'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->orderByDesc('scheduled_date')
            ->orderByDesc('start_time')
            ->paginate($this->perPage());

        return $this->paginated($sessions, TrainingSessionResource::class);
    }

    /** Enrolments and their lesson balances. */
    public function packages(Request $request, Trainee $trainee): JsonResponse
    {
        $this->authorize('view', $trainee);

        return $this->ok(
            TraineePackageResource::collection(
                $trainee->packages()->orderByDesc('started_on')->get()
            ),
        );
    }

    /** Current skill levels across the syllabus. */
    public function evaluations(Request $request, Trainee $trainee): JsonResponse
    {
        $this->authorize('view', $trainee);

        return $this->ok(
            SkillEvaluationResource::collection(
                $trainee->skillEvaluations()->with('skill')->get()
            ),
        );
    }

    protected function sortColumn(Request $request): string
    {
        $allowed = ['full_name', 'registration_date', 'trainee_number', 'status', 'created_at'];

        return in_array($request->input('sort'), $allowed, true)
            ? $request->input('sort')
            : 'registration_date';
    }
}
