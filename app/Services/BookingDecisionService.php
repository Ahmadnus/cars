<?php

namespace App\Services;

use App\Events\BookingRequestUpdated;
use App\Exceptions\BusinessRuleException;
use App\Models\BookingRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The trainer's decision on a reschedule or cancellation.
 *
 * Two decisions sit on one request, in order: the trainer answers for their own
 * diary, then the office applies it to the calendar. Keeping them separate is
 * the point — the office should not move a lesson the trainer has not agreed to,
 * and a month later the record has to show who agreed as well as who applied it.
 *
 * Only the trainer the lesson belongs to may answer. That is checked here rather
 * than in a controller, so every caller gets it.
 */
class BookingDecisionService
{
    public function __construct(
        protected NotificationService $notifications,
        protected AuditLogger $audit,
    ) {
    }

    /**
     * Mark a request as needing the trainer.
     *
     * Called when the request is raised. A plain booking is left null: reception
     * schedules those, and there is no existing lesson for a trainer to defend.
     */
    public function markAwaitingTrainer(BookingRequest $request): void
    {
        if (! $request->needsTrainerDecision()) {
            return;
        }

        $request->forceFill(['trainer_decision' => BookingRequest::TRAINER_PENDING])->save();
    }

    public function approve(BookingRequest $request, User $trainerUser, ?string $note = null): BookingRequest
    {
        return $this->decide($request, $trainerUser, BookingRequest::TRAINER_APPROVED, $note);
    }

    public function reject(BookingRequest $request, User $trainerUser, string $note): BookingRequest
    {
        return $this->decide($request, $trainerUser, BookingRequest::TRAINER_REJECTED, $note);
    }

    protected function decide(
        BookingRequest $request,
        User $trainerUser,
        string $decision,
        ?string $note,
    ): BookingRequest {
        $this->assertTrainerMayDecide($request, $trainerUser);

        $updated = DB::transaction(function () use ($request, $trainerUser, $decision, $note) {
            // Re-read under a lock: two taps on a slow connection must not both
            // record a decision, and the second would overwrite the first.
            $locked = BookingRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();

            if ($locked->trainer_decision !== BookingRequest::TRAINER_PENDING) {
                throw BusinessRuleException::make(
                    'تم اتخاذ قرار بهذا الطلب مسبقاً ('.($locked->trainerDecisionLabel() ?? '—').').',
                );
            }

            if ($locked->status !== 'pending') {
                throw BusinessRuleException::make('تم إغلاق هذا الطلب من قبل الإدارة.');
            }

            $locked->forceFill([
                'trainer_decision' => $decision,
                'trainer_note' => $note,
                'trainer_decided_by' => $trainerUser->id,
                'trainer_decided_at' => now(),
            ])->save();

            $this->audit->log(
                action: 'booking_request.trainer_'.$decision,
                subject: $locked,
                after: ['trainer_decision' => $decision],
                description: 'قرار المدرب على '.$this->typeLabel($locked),
                reason: $note,
            );

            return $locked->fresh();
        });

        // Side effects after the commit: a provider timeout must not undo a
        // decision the trainer has already made.
        $this->announce($updated, $trainerUser, $decision);

        return $updated;
    }

    /**
     * Only the trainer the lesson belongs to.
     *
     * A trainer answering for a colleague's diary would be worse than no approval
     * step at all, so this is a refusal rather than a warning.
     */
    protected function assertTrainerMayDecide(BookingRequest $request, User $user): void
    {
        $trainer = $user->trainer;

        if (! $trainer) {
            throw BusinessRuleException::make('هذا الحساب غير مرتبط بملف مدرب.');
        }

        if (! $request->needsTrainerDecision()) {
            throw BusinessRuleException::make('هذا النوع من الطلبات لا يحتاج موافقة المدرب.');
        }

        $owning = $request->decidingTrainer();

        if (! $owning || $owning->id !== $trainer->id) {
            throw BusinessRuleException::make('هذا الطلب لا يخص حصصك.');
        }
    }

    /**
     * Tell the office, naming the trainer.
     *
     * "وافق المدرب عمر الزعبي على تأجيل حصة سارة" is what a receptionist can act
     * on; "تم تحديث طلب" is not.
     */
    protected function announce(BookingRequest $request, User $trainerUser, string $decision): void
    {
        try {
            $this->notifications->bookingRequestTrainerDecision($request, $trainerUser, $decision);
        } catch (\Throwable $e) {
            report($e);
        }

        try {
            BookingRequestUpdated::dispatch($request, 'trainer_'.$decision);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    protected function typeLabel(BookingRequest $request): string
    {
        return match ($request->type) {
            'reschedule' => 'طلب تأجيل',
            'cancellation' => 'طلب إلغاء',
            default => 'طلب حجز',
        };
    }
}
