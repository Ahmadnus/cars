<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\TrainerResource;
use App\Http\Resources\TrainingSessionResource;
use App\Http\Resources\VehicleResource;
use App\Models\Trainer;
use App\Models\Vehicle;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TrainerController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Trainer::class);

        $trainers = Trainer::query()
            ->visibleTo($request->user())
            ->with('branch:id,uuid,name')
            ->withCount('trainees')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')), fn ($q) => $q->active())
            ->orderBy('full_name')
            ->paginate($this->perPage());

        return $this->paginated($trainers, TrainerResource::class);
    }

    public function show(Trainer $trainer): JsonResponse
    {
        $this->authorize('view', $trainer);

        return $this->ok(new TrainerResource($trainer->load(['branch', 'vehicles'])));
    }

    /** A trainer's schedule for a date range — the Trainer app's calendar. */
    public function schedule(Request $request, Trainer $trainer): JsonResponse
    {
        $this->authorize('view', $trainer);

        $from = $request->filled('from') ? Carbon::parse($request->input('from')) : now()->startOfWeek(Carbon::SUNDAY);
        $to = $request->filled('to') ? Carbon::parse($request->input('to')) : $from->copy()->addDays(6);

        $sessions = $trainer->trainingSessions()
            ->with(['trainee:id,uuid,full_name,phone,trainee_number', 'vehicle:id,uuid,name,plate_number'])
            ->whereBetween('scheduled_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('scheduled_date')->orderBy('start_time')
            ->get();

        return $this->ok(
            TrainingSessionResource::collection($sessions),
            meta: ['from' => $from->toDateString(), 'to' => $to->toDateString()],
        );
    }

    /** Vehicles available for booking — used by the lesson form. */
    public function vehicles(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Vehicle::class);

        return $this->ok(VehicleResource::collection(
            Vehicle::query()->visibleTo($request->user())->bookable()->orderBy('name')->get()
        ));
    }
}
