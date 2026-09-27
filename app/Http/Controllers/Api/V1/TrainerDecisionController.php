<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\BookingRequestResource;
use App\Models\BookingRequest;
use App\Services\BookingDecisionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reschedule and cancellation requests waiting on the signed-in trainer.
 *
 * Built like the rest of the trainer's own surfaces: every query is rooted at
 * `$request->user()->trainer`, so a trainer sees only requests against their own
 * lessons and cannot answer for a colleague. That check lives in the service, so
 * approving by id is refused even if the id is guessed.
 *
 * No `permission:` middleware. Answering for your own diary is not a
 * center-wide capability — `appointments.cancel` would let a trainer cancel
 * anyone's lesson, which is the opposite of what this is for.
 */
class TrainerDecisionController extends ApiController
{
    public function __construct(protected BookingDecisionService $decisions)
    {
    }

    /** What is waiting on me. */
    public function index(Request $request): JsonResponse
    {
        $trainer = $request->user()->trainer;

        if (! $trainer) {
            return $this->failed('هذا الحساب غير مرتبط بملف مدرب.', status: 403);
        }

        $requests = BookingRequest::query()
            ->awaitingTrainerId($trainer->id)
            ->where('booking_requests.status', 'pending')
            ->with([
                'trainee:id,uuid,full_name,phone',
                'trainingSession:id,uuid,scheduled_date,start_time,end_time,status',
                'preferredTrainer:id,uuid,full_name',
            ])
            ->orderBy('requested_date')
            ->orderBy('requested_start_time')
            ->paginate($this->perPage());

        return $this->paginated($requests, BookingRequestResource::class, meta: [
            'awaiting_count' => BookingRequest::awaitingTrainerId($trainer->id)
                ->where('booking_requests.status', 'pending')
                ->count(),
        ]);
    }

    public function approve(Request $request, BookingRequest $bookingRequest): JsonResponse
    {
        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:500'],
        ], [], ['note' => 'الملاحظة']);

        $updated = $this->decisions->approve(
            $bookingRequest,
            $request->user(),
            $data['note'] ?? null,
        );

        return $this->ok(
            new BookingRequestResource($updated->load('trainee', 'trainingSession')),
            'تمت موافقتك. ستقوم الإدارة بتنفيذ التغيير.',
        );
    }

    /**
     * Refuse it, with a reason.
     *
     * Required, because the trainee and the office both read it — a bare refusal
     * would send a receptionist to the phone to ask why.
     */
    public function reject(Request $request, BookingRequest $bookingRequest): JsonResponse
    {
        $data = $request->validate([
            'note' => ['required', 'string', 'min:3', 'max:500'],
        ], [], ['note' => 'سبب الرفض']);

        $updated = $this->decisions->reject($bookingRequest, $request->user(), $data['note']);

        return $this->ok(
            new BookingRequestResource($updated->load('trainee', 'trainingSession')),
            'تم تسجيل رفضك وإبلاغ الإدارة والمتدرب.',
        );
    }
}
