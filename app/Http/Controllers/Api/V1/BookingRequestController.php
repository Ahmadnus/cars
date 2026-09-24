<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\BookingRequestResource;
use App\Models\BookingRequest;
use App\Models\Trainer;
use App\Models\TrainingSession;
use App\Services\AppointmentService;
use App\Services\AuditLogger;
use App\Services\NotificationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Booking, reschedule and cancellation requests raised from the Trainee app.
 *
 * A request never changes the schedule by itself — it lands in the dashboard
 * for a human to approve, which is what keeps conflict rules and the lesson
 * ledger under staff control.
 */
class BookingRequestController extends ApiController
{
    public function __construct(
        protected AppointmentService $appointments,
        protected NotificationService $notifications,
        protected AuditLogger $audit,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $requests = BookingRequest::query()
            ->visibleTo($request->user())
            ->with(['trainee:id,uuid,full_name,phone', 'preferredTrainer:id,uuid,full_name', 'trainingSession'])
            // A trainee only ever sees their own requests.
            ->when($request->user()->trainee, fn (Builder $q) => $q->where('trainee_id', $request->user()->trainee->id))
            ->when(! $request->user()->trainee, fn (Builder $q) => $q->tap(function (Builder $inner) use ($request) {
                abort_unless($request->user()->hasPermission('booking_requests.manage'), 403);
            }))
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->input('status')))
            ->orderByDesc('created_at')
            ->paginate($this->perPage());

        return $this->paginated($requests, BookingRequestResource::class);
    }

    /** Raise a request. The trainee's own record is used, never a client-supplied id. */
    public function store(Request $request): JsonResponse
    {
        $trainee = $request->user()->trainee;

        if (! $trainee) {
            return $this->failed('هذا الحساب غير مرتبط بملف متدرب.', status: 403);
        }

        $data = $request->validate([
            'type' => ['required', 'in:booking,reschedule,cancellation'],
            'training_session_id' => ['nullable', 'string', 'exists:training_sessions,uuid'],
            'requested_date' => ['nullable', 'date', 'after_or_equal:today'],
            'requested_start_time' => ['nullable', 'date_format:H:i'],
            'preferred_trainer_id' => ['nullable', 'string', 'exists:trainers,uuid'],
            'trainee_note' => ['nullable', 'string', 'max:500'],
        ], [], [
            'type' => 'نوع الطلب',
            'requested_date' => 'التاريخ المطلوب',
            'requested_start_time' => 'الوقت المطلوب',
        ]);

        $session = null;

        if (! empty($data['training_session_id'])) {
            $session = TrainingSession::where('uuid', $data['training_session_id'])->firstOrFail();

            // A trainee may only reference a lesson that is genuinely theirs.
            if ($session->trainee_id !== $trainee->id) {
                return $this->failed('لا يمكن تقديم طلب على حصة لا تخصّك.', status: 403);
            }
        }

        if (in_array($data['type'], ['reschedule', 'cancellation'], true) && ! $session) {
            return $this->failed('يجب تحديد الحصة المطلوب تعديلها أو إلغاؤها.', [
                'training_session_id' => ['الحصة مطلوبة لهذا النوع من الطلبات.'],
            ]);
        }

        $trainer = ! empty($data['preferred_trainer_id'])
            ? Trainer::where('uuid', $data['preferred_trainer_id'])->first()
            : null;

        $bookingRequest = DB::transaction(function () use ($trainee, $data, $session, $trainer) {
            $bookingRequest = BookingRequest::create([
                'branch_id' => $trainee->branch_id,
                'trainee_id' => $trainee->id,
                'training_session_id' => $session?->id,
                'type' => $data['type'],
                'requested_date' => $data['requested_date'] ?? null,
                'requested_start_time' => $data['requested_start_time'] ?? null,
                'preferred_trainer_id' => $trainer?->id,
                'trainee_note' => $data['trainee_note'] ?? null,
                'status' => 'pending',
            ]);

            $this->audit->logCreate('booking_request.created', $bookingRequest, 'طلب من تطبيق المتدرب');

            // Tell the staff who can act on it.
            $this->notifications->notify(
                $this->staffFor($trainee->branch_id),
                'booking_request.created',
                'طلب جديد من متدرب',
                "قدّم المتدرب {$trainee->full_name} طلباً جديداً بانتظار المراجعة.",
                ['request_uuid' => $bookingRequest->uuid],
                route('admin.booking-requests.index', [], false),
                'warning',
            );

            return $bookingRequest;
        });

        return $this->created(
            new BookingRequestResource($bookingRequest->load(['trainee', 'preferredTrainer', 'trainingSession'])),
            'تم إرسال الطلب وسيتم مراجعته من قبل الإدارة.',
        );
    }

    public function show(Request $request, BookingRequest $bookingRequest): JsonResponse
    {
        $trainee = $request->user()->trainee;

        abort_unless(
            ($trainee && $bookingRequest->trainee_id === $trainee->id)
                || $request->user()->can('view', $bookingRequest),
            403,
        );

        return $this->ok(new BookingRequestResource(
            $bookingRequest->load(['trainee', 'preferredTrainer', 'trainingSession'])
        ));
    }

    /** A trainee may withdraw their own request while it is still pending. */
    public function withdraw(Request $request, BookingRequest $bookingRequest): JsonResponse
    {
        $trainee = $request->user()->trainee;

        abort_unless($trainee && $bookingRequest->trainee_id === $trainee->id, 403);

        if (! $bookingRequest->isPending()) {
            return $this->failed('لا يمكن سحب طلب تمت معالجته.');
        }

        $bookingRequest->update(['status' => 'cancelled', 'resolved_at' => now()]);

        return $this->ok(new BookingRequestResource($bookingRequest), 'تم سحب الطلب.');
    }

    /** @return \Illuminate\Support\Collection<int, \App\Models\User> */
    protected function staffFor(int $branchId): \Illuminate\Support\Collection
    {
        return \App\Models\User::query()
            ->where('status', 'active')
            ->where(fn ($q) => $q->where('branch_id', $branchId)->orWhere('can_access_all_branches', true))
            ->with('roles.permissions', 'permissionOverrides')
            ->get()
            ->filter(fn ($user) => $user->hasPermission('booking_requests.manage'));
    }
}
