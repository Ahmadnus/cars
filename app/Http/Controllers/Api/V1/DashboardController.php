<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\TraineePackageResource;
use App\Http\Resources\TrainingSessionResource;
use App\Services\DashboardService;
use App\Services\PaymentService;
use App\Services\TrainingSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Home screens for the three clients.
 *
 * The admin dashboard payload is assembled by the same DashboardService the web
 * dashboard uses, so a permission a user lacks means the block is never
 * computed — there is nothing for the client to accidentally display.
 */
class DashboardController extends ApiController
{
    public function __construct(protected DashboardService $dashboard)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('dashboard.view');

        return $this->ok($this->dashboard->build($request->user()));
    }

    /** The Trainer app's home screen. */
    public function trainerHome(Request $request, TrainingSessionService $sessions): JsonResponse
    {
        $trainer = $request->user()->trainer;

        if (! $trainer) {
            return $this->failed('هذا الحساب غير مرتبط بملف مدرب.', status: 403);
        }

        $trainer->loadMissing('branch');

        $today = $trainer->trainingSessions()
            ->with(['trainee:id,uuid,full_name,phone,trainee_number', 'vehicle:id,uuid,name,plate_number'])
            ->whereDate('scheduled_date', now()->toDateString())
            ->orderBy('start_time')
            ->get();

        $upcoming = $trainer->trainingSessions()
            ->with(['trainee:id,uuid,full_name,phone,trainee_number', 'vehicle:id,uuid,name,plate_number'])
            ->scheduled()
            ->whereDate('scheduled_date', '>', now()->toDateString())
            ->orderBy('scheduled_date')->orderBy('start_time')
            ->limit(20)
            ->get();

        $monthStart = now()->startOfMonth()->toDateString();

        return $this->ok([
            'trainer' => [
                'id' => $trainer->uuid,
                'full_name' => $trainer->full_name,
                'trainer_number' => $trainer->trainer_number,
                'phone' => $trainer->phone,
                'branch' => $trainer->branch?->name,
            ],
            // The trainer's own vehicles, included here rather than behind
            // /vehicles: this is their own record, so it needs no broader
            // `vehicles.view` grant.
            'vehicles' => \App\Http\Resources\VehicleResource::collection(
                $trainer->vehicles()->orderBy('name')->get()
            ),
            'today' => TrainingSessionResource::collection($today),
            'upcoming' => TrainingSessionResource::collection($upcoming),
            'stats' => [
                'today_total' => $today->count(),
                'today_completed' => $today->where('status', 'completed')->count(),
                'month_completed' => $trainer->trainingSessions()
                    ->completed()->where('scheduled_date', '>=', $monthStart)->count(),
                'active_trainees' => $trainer->trainees()->active()->count(),
            ],
        ]);
    }

    /** The Trainee app's home screen. */
    public function traineeHome(Request $request, TrainingSessionService $sessions, PaymentService $payments): JsonResponse
    {
        $trainee = $request->user()->trainee;

        if (! $trainee) {
            return $this->failed('هذا الحساب غير مرتبط بملف متدرب.', status: 403);
        }

        $package = $trainee->activePackage();

        $upcoming = $trainee->trainingSessions()
            ->with(['trainer:id,uuid,full_name', 'vehicle:id,uuid,name,plate_number'])
            ->scheduled()
            ->whereDate('scheduled_date', '>=', now()->toDateString())
            ->orderBy('scheduled_date')->orderBy('start_time')
            ->limit(10)
            ->get();

        return $this->ok([
            'trainee' => [
                'id' => $trainee->uuid,
                'full_name' => $trainee->full_name,
                'trainee_number' => $trainee->trainee_number,
                'status' => $trainee->status,
                'exam_date' => $trainee->exam_date?->toDateString(),
            ],
            'trainer' => $trainee->trainer ? [
                'id' => $trainee->trainer->uuid,
                'full_name' => $trainee->trainer->full_name,
                'phone' => $trainee->trainer->phone,
            ] : null,
            'package' => $package ? new TraineePackageResource($package) : null,
            'upcoming' => TrainingSessionResource::collection($upcoming),
            'progress' => [
                'readiness_percent' => $sessions->readinessPercent($trainee->id),
                'completed_lessons' => $package?->completedLessons() ?? 0,
                'remaining_lessons' => $package?->remainingLessons() ?? 0,
            ],
            // A trainee may always see their own balance.
            'financial' => [
                'outstanding' => $payments->outstandingForTrainee($trainee),
            ],
            'unread_notifications' => $request->user()->unreadNotifications()->count(),
        ]);
    }
}
