<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BookingRequest;
use App\Models\Trainer;
use App\Services\AppointmentService;
use App\Services\AuditLogger;
use App\Services\NotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Triage of requests raised from the Trainee app.
 *
 * Approving a booking or reschedule runs the same AppointmentService used by
 * the dashboard, so conflict rules and balance checks apply identically no
 * matter where the request came from.
 */
class BookingRequestController extends Controller
{
    public function __construct(
        protected AppointmentService $appointments,
        protected NotificationService $notifications,
        protected AuditLogger $audit,
    ) {
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', BookingRequest::class);

        return view('admin.booking-requests.index', [
            'requests' => BookingRequest::query()
                ->visibleTo($request->user())
                ->with(['trainee:id,uuid,full_name,trainee_number,phone', 'preferredTrainer:id,uuid,full_name', 'trainingSession', 'resolver:id,name'])
                ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')), fn ($q) => $q->pending())
                ->when($request->filled('type'), fn ($q) => $q->where('type', $request->input('type')))
                ->orderByDesc('created_at')
                ->paginate(20)
                ->withQueryString(),
            'pendingCount' => BookingRequest::query()->visibleTo($request->user())->pending()->count(),
            'trainers' => Trainer::query()->visibleTo($request->user())->active()
                ->orderBy('full_name')->pluck('full_name', 'id')->all(),
            'statuses' => self::statuses(),
            'types' => self::types(),
        ]);
    }

    public function approve(Request $request, BookingRequest $bookingRequest): RedirectResponse
    {
        $this->authorize('resolve', $bookingRequest);

        $data = $request->validate([
            'trainer_id' => ['required', 'integer', Rule::exists('trainers', 'id')->whereNull('deleted_at')],
            'vehicle_id' => ['nullable', 'integer', Rule::exists('vehicles', 'id')->whereNull('deleted_at')],
            'scheduled_date' => ['required', 'date'],
            'start_time' => ['required', 'date_format:H:i'],
            'duration_minutes' => ['nullable', 'integer', 'min:15', 'max:300'],
            'admin_note' => ['nullable', 'string', 'max:500'],
        ], [], [
            'trainer_id' => 'المدرب',
            'scheduled_date' => 'تاريخ الحصة',
            'start_time' => 'وقت البداية',
        ]);

        DB::transaction(function () use ($bookingRequest, $data, $request) {
            if ($bookingRequest->type === 'reschedule' && $bookingRequest->trainingSession) {
                // Move the existing lesson rather than creating a second one.
                $session = $this->appointments->reschedule(
                    $bookingRequest->trainingSession,
                    $data,
                    'موافقة على طلب إعادة جدولة من المتدرب',
                );

                $status = 'rescheduled';
            } else {
                $session = $this->appointments->schedule(
                    array_merge($data, ['trainee_id' => $bookingRequest->trainee_id]),
                    $bookingRequest->branch_id,
                );

                $status = 'approved';
            }

            $bookingRequest->update([
                'status' => $status,
                'admin_note' => $data['admin_note'] ?? null,
                'resolved_by' => $request->user()->id,
                'resolved_at' => now(),
                'resulting_session_id' => $session->id,
            ]);

            $this->audit->log(
                action: 'booking_request.approved',
                subject: $bookingRequest,
                after: ['status' => $status, 'session_id' => $session->id],
                description: 'الموافقة على طلب حجز',
            );

            $this->notifications->bookingRequestResolved($bookingRequest->fresh());
        });

        return back()->with('toast', ['type' => 'success', 'message' => 'تمت الموافقة على الطلب وحجز الموعد.']);
    }

    public function reject(Request $request, BookingRequest $bookingRequest): RedirectResponse
    {
        $this->authorize('resolve', $bookingRequest);

        $data = $request->validate(
            ['admin_note' => ['required', 'string', 'max:500']],
            [],
            ['admin_note' => 'سبب الرفض'],
        );

        DB::transaction(function () use ($bookingRequest, $data, $request) {
            $bookingRequest->update([
                'status' => 'rejected',
                'admin_note' => $data['admin_note'],
                'resolved_by' => $request->user()->id,
                'resolved_at' => now(),
            ]);

            $this->audit->log(
                action: 'booking_request.rejected',
                subject: $bookingRequest,
                after: ['status' => 'rejected'],
                reason: $data['admin_note'],
            );

            $this->notifications->bookingRequestResolved($bookingRequest->fresh());
        });

        return back()->with('toast', ['type' => 'success', 'message' => 'تم رفض الطلب وإشعار المتدرب.']);
    }

    /** Approve a cancellation request by cancelling the underlying lesson. */
    public function approveCancellation(Request $request, BookingRequest $bookingRequest): RedirectResponse
    {
        $this->authorize('resolve', $bookingRequest);

        abort_unless($bookingRequest->type === 'cancellation' && $bookingRequest->trainingSession, 422);

        $data = $request->validate(['admin_note' => ['nullable', 'string', 'max:500']]);

        DB::transaction(function () use ($bookingRequest, $data, $request) {
            $this->appointments->cancel(
                $bookingRequest->trainingSession,
                $data['admin_note'] ?: 'إلغاء بناءً على طلب المتدرب',
            );

            $bookingRequest->update([
                'status' => 'cancelled',
                'admin_note' => $data['admin_note'] ?? null,
                'resolved_by' => $request->user()->id,
                'resolved_at' => now(),
            ]);

            $this->notifications->bookingRequestResolved($bookingRequest->fresh());
        });

        return back()->with('toast', ['type' => 'success', 'message' => 'تم إلغاء الحصة بناءً على الطلب.']);
    }

    /** @return array<string, string> */
    public static function statuses(): array
    {
        return [
            'pending' => 'معلّق',
            'approved' => 'تمت الموافقة',
            'rejected' => 'مرفوض',
            'rescheduled' => 'تمت إعادة الجدولة',
            'cancelled' => 'ملغى',
        ];
    }

    /** @return array<string, string> */
    public static function types(): array
    {
        return [
            'booking' => 'طلب حجز',
            'reschedule' => 'طلب تأجيل',
            'cancellation' => 'طلب إلغاء',
        ];
    }
}
