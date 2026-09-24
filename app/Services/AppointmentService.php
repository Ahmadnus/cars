<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Branch;
use App\Models\Trainee;
use App\Models\Trainer;
use App\Models\TrainingSession;
use App\Models\Vehicle;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Booking, moving and cancelling lessons.
 *
 * Conflict detection lives here and nowhere else. The dashboard may pre-check
 * availability for a nicer UX, but every write re-runs the same checks on the
 * server inside a transaction — the UI is never trusted.
 */
class AppointmentService
{
    public function __construct(
        protected AuditLogger $audit,
        protected SettingsRepository $settings,
        protected TraineeBalanceService $balances,
        protected NotificationService $notifications,
    ) {
    }

    /**
     * Book a lesson.
     *
     * @param  array{trainee_id:int, trainer_id:int, vehicle_id?:int|null, scheduled_date:string, start_time:string, duration_minutes?:int}  $data
     */
    public function schedule(array $data, ?int $branchId = null): TrainingSession
    {
        return DB::transaction(function () use ($data, $branchId) {
            $trainee = Trainee::findOrFail($data['trainee_id']);
            $trainer = Trainer::findOrFail($data['trainer_id']);
            $vehicle = ! empty($data['vehicle_id']) ? Vehicle::findOrFail($data['vehicle_id']) : null;
            $branchId ??= $trainee->branch_id;

            $duration = (int) ($data['duration_minutes']
                ?? $this->settings->int('training.default_lesson_duration', 45));

            $date = Carbon::parse($data['scheduled_date']);
            $start = $this->normaliseTime($data['start_time']);
            $end = $this->addMinutes($start, $duration);

            $this->assertBookable($trainee, $trainer, $vehicle, $branchId);
            $this->assertWithinWorkingHours($branchId, $date, $start, $end, $trainer);
            $this->assertNoConflicts($date, $start, $end, $trainer->id, $trainee->id, $vehicle?->id);

            $package = $trainee->activePackage();

            if ($package) {
                $this->assertBalanceCoversBooking($package, $trainee);
            } elseif (! $this->settings->bool('training.allow_negative_balance', false)) {
                // Booking a lesson that could never be completed only creates a
                // dead slot, so it is refused here rather than at completion.
                throw BusinessRuleException::make(
                    'لا توجد باقة نشطة لهذا المتدرب. يجب إسناد باقة أو إضافة حصص قبل الحجز.',
                    ['trainee_id' => ['لا يوجد رصيد حصص لهذا المتدرب.']],
                );
            }

            $session = TrainingSession::create([
                'branch_id' => $branchId,
                'trainee_id' => $trainee->id,
                'trainer_id' => $trainer->id,
                'vehicle_id' => $vehicle?->id,
                'trainee_package_id' => $package?->id,
                'scheduled_date' => $date->toDateString(),
                'start_time' => $start,
                'end_time' => $end,
                'duration_minutes' => $duration,
                'status' => 'scheduled',
                'created_by' => auth()->id(),
            ]);

            if ($trainee->status === 'new') {
                $trainee->update(['status' => 'in_training']);
            }

            $this->audit->logCreate('appointment.created', $session, 'حجز حصة تدريبية');
            $this->notifications->appointmentBooked($session);

            return $session->fresh(['trainee', 'trainer', 'vehicle']);
        });
    }

    /**
     * Move a lesson to a new slot, and optionally a new trainer or vehicle.
     *
     * @param  array{scheduled_date?:string, start_time?:string, duration_minutes?:int, trainer_id?:int, vehicle_id?:int|null}  $data
     */
    public function reschedule(TrainingSession $session, array $data, ?string $reason = null): TrainingSession
    {
        return DB::transaction(function () use ($session, $data, $reason) {
            $this->assertMutable($session);

            $original = $session->getOriginal();

            $trainer = isset($data['trainer_id'])
                ? Trainer::findOrFail($data['trainer_id'])
                : $session->trainer;

            $vehicle = array_key_exists('vehicle_id', $data)
                ? ($data['vehicle_id'] ? Vehicle::findOrFail($data['vehicle_id']) : null)
                : $session->vehicle;

            $duration = (int) ($data['duration_minutes'] ?? $session->duration_minutes);
            $date = Carbon::parse($data['scheduled_date'] ?? $session->scheduled_date->toDateString());
            $start = $this->normaliseTime($data['start_time'] ?? (string) $session->start_time);
            $end = $this->addMinutes($start, $duration);

            $this->assertBookable($session->trainee, $trainer, $vehicle, $session->branch_id);
            $this->assertWithinWorkingHours($session->branch_id, $date, $start, $end, $trainer);
            $this->assertNoConflicts($date, $start, $end, $trainer->id, $session->trainee_id, $vehicle?->id, $session->id);

            $session->update([
                'trainer_id' => $trainer->id,
                'vehicle_id' => $vehicle?->id,
                'scheduled_date' => $date->toDateString(),
                'start_time' => $start,
                'end_time' => $end,
                'duration_minutes' => $duration,
                'status' => 'scheduled',
            ]);

            $this->audit->logUpdate('appointment.rescheduled', $session, $original, $reason);
            $this->notifications->appointmentChanged($session);

            return $session->fresh(['trainee', 'trainer', 'vehicle']);
        });
    }

    /**
     * Cancel a lesson.
     *
     * A cancellation made inside the notice window burns a lesson, per the
     * cancellation policy in settings.
     */
    public function cancel(TrainingSession $session, string $reason): TrainingSession
    {
        return DB::transaction(function () use ($session, $reason) {
            $this->assertMutable($session);

            $original = $session->getOriginal();
            $isLate = $this->isInsideNoticeWindow($session);

            $session->update([
                'status' => 'cancelled',
                'cancellation_reason' => $reason,
                'cancelled_by' => auth()->id(),
                'cancelled_at' => now(),
            ]);

            if ($isLate && $session->traineePackage) {
                $this->balances->burnForLateCancellation($session->traineePackage, $session);
            }

            $this->audit->log(
                action: 'appointment.cancelled',
                subject: $session,
                before: ['status' => $original['status']],
                after: ['status' => 'cancelled', 'late_cancellation' => $isLate],
                reason: $reason,
            );

            $this->notifications->appointmentCancelled($session);

            return $session->fresh();
        });
    }

    /** Mark a lesson as postponed without deciding a new slot yet. */
    public function postpone(TrainingSession $session, string $reason): TrainingSession
    {
        return DB::transaction(function () use ($session, $reason) {
            $this->assertMutable($session);

            $original = $session->getOriginal();
            $session->update(['status' => 'postponed', 'cancellation_reason' => $reason]);

            $this->audit->logUpdate('appointment.postponed', $session, $original, $reason);
            $this->notifications->appointmentChanged($session);

            return $session->fresh();
        });
    }

    // ------------------------------------------------------------------
    // Conflict detection
    // ------------------------------------------------------------------

    /**
     * Reject a slot that collides with an existing booking.
     *
     * Two lessons overlap when one starts before the other ends and ends after
     * the other starts. Only sessions still holding a slot are considered.
     */
    public function assertNoConflicts(
        CarbonInterface $date,
        string $start,
        string $end,
        int $trainerId,
        int $traineeId,
        ?int $vehicleId,
        ?int $ignoreSessionId = null,
    ): void {
        $conflicts = $this->findConflicts($date, $start, $end, $trainerId, $traineeId, $vehicleId, $ignoreSessionId);

        if ($conflicts->isEmpty()) {
            return;
        }

        $messages = [];

        if ($conflicts->has('trainer')) {
            $messages['trainer_id'] = 'لا يمكن حجز هذا الموعد لأن المدرب لديه حصة أخرى في نفس الوقت ('
                .$conflicts['trainer']->timeRange().').';
        }

        if ($conflicts->has('trainee')) {
            $messages['trainee_id'] = 'لا يمكن حجز هذا الموعد لأن المتدرب لديه حصة أخرى في نفس الوقت ('
                .$conflicts['trainee']->timeRange().').';
        }

        if ($conflicts->has('vehicle')) {
            $messages['vehicle_id'] = 'لا يمكن حجز هذا الموعد لأن المركبة محجوزة في نفس الوقت ('
                .$conflicts['vehicle']->timeRange().').';
        }

        throw new BusinessRuleException(
            'يوجد تعارض في الموعد المطلوب.',
            array_map(fn ($m) => [$m], $messages),
        );
    }

    /** @return Collection<string, TrainingSession> keyed by what collided */
    public function findConflicts(
        CarbonInterface $date,
        string $start,
        string $end,
        int $trainerId,
        int $traineeId,
        ?int $vehicleId,
        ?int $ignoreSessionId = null,
    ): Collection {
        $overlapping = function (Builder $query) use ($date, $start, $end, $ignoreSessionId) {
            $query->whereDate('scheduled_date', $date->toDateString())
                ->whereIn('status', TrainingSession::BLOCKING_STATUSES)
                ->where('start_time', '<', $end)
                ->where('end_time', '>', $start);

            if ($ignoreSessionId) {
                $query->whereKeyNot($ignoreSessionId);
            }
        };

        $found = collect();

        $trainerClash = TrainingSession::query()->where('trainer_id', $trainerId)->tap($overlapping)->first();
        if ($trainerClash) {
            $found['trainer'] = $trainerClash;
        }

        $traineeClash = TrainingSession::query()->where('trainee_id', $traineeId)->tap($overlapping)->first();
        if ($traineeClash) {
            $found['trainee'] = $traineeClash;
        }

        if ($vehicleId) {
            $vehicleClash = TrainingSession::query()->where('vehicle_id', $vehicleId)->tap($overlapping)->first();
            if ($vehicleClash) {
                $found['vehicle'] = $vehicleClash;
            }
        }

        return $found;
    }

    /**
     * Free slots for a trainer on one day, respecting branch hours, the
     * trainer's own schedule and existing bookings.
     *
     * @return array<int, array{start: string, end: string}>
     */
    public function availableSlots(Trainer $trainer, CarbonInterface $date, ?int $durationMinutes = null, ?int $vehicleId = null): array
    {
        $duration = $durationMinutes ?? $this->settings->int('training.default_lesson_duration', 45);
        $step = max(5, $this->settings->int('training.slot_step_minutes', 15));

        $branch = Branch::find($trainer->branch_id);
        $hours = $branch?->hoursFor($date);

        if (! $hours) {
            return [];
        }

        $windowStart = max($hours['open'], substr((string) ($trainer->work_start_time ?: $hours['open']), 0, 5));
        $windowEnd = min($hours['close'], substr((string) ($trainer->work_end_time ?: $hours['close']), 0, 5));

        $booked = TrainingSession::query()
            ->whereDate('scheduled_date', $date->toDateString())
            ->whereIn('status', TrainingSession::BLOCKING_STATUSES)
            ->where(function (Builder $q) use ($trainer, $vehicleId) {
                $q->where('trainer_id', $trainer->id);

                if ($vehicleId) {
                    $q->orWhere('vehicle_id', $vehicleId);
                }
            })
            ->get(['start_time', 'end_time']);

        $slots = [];
        $cursor = $windowStart;

        while ($this->addMinutes($cursor, $duration) <= $windowEnd) {
            $slotEnd = $this->addMinutes($cursor, $duration);

            $taken = $booked->contains(
                fn ($s) => substr((string) $s->start_time, 0, 5) < $slotEnd
                    && substr((string) $s->end_time, 0, 5) > $cursor
            );

            if (! $taken) {
                $slots[] = ['start' => $cursor, 'end' => $slotEnd];
            }

            $cursor = $this->addMinutes($cursor, $step);
        }

        return $slots;
    }

    // ------------------------------------------------------------------
    // Guards
    // ------------------------------------------------------------------

    protected function assertBookable(Trainee $trainee, Trainer $trainer, ?Vehicle $vehicle, int $branchId): void
    {
        if ($trainee->isClosed()) {
            throw BusinessRuleException::make('لا يمكن حجز حصص لمتدرب منتهٍ أو ملغى.');
        }

        if (! $trainer->isActive()) {
            throw BusinessRuleException::make('المدرب غير متاح حالياً.', ['trainer_id' => ['المدرب غير نشط.']]);
        }

        if ($vehicle && ! $vehicle->isBookable()) {
            throw BusinessRuleException::make(
                'المركبة غير متاحة للحجز.',
                ['vehicle_id' => ['المركبة في الصيانة أو غير نشطة.']],
            );
        }

        // Everything on one lesson must belong to the same branch.
        foreach (['المتدرب' => $trainee, 'المدرب' => $trainer, 'المركبة' => $vehicle] as $label => $entity) {
            if ($entity && (int) $entity->branch_id !== (int) $branchId) {
                throw BusinessRuleException::make("{$label} لا ينتمي إلى نفس الفرع.");
            }
        }
    }

    protected function assertWithinWorkingHours(
        int $branchId,
        CarbonInterface $date,
        string $start,
        string $end,
        Trainer $trainer,
    ): void {
        if ($start >= $end) {
            throw BusinessRuleException::make('وقت بداية الحصة يجب أن يسبق وقت النهاية.');
        }

        $branch = Branch::find($branchId);
        $hours = $branch?->hoursFor($date);

        if (! $hours) {
            throw BusinessRuleException::make('الفرع مغلق في هذا اليوم.');
        }

        if ($start < $hours['open'] || $end > $hours['close']) {
            throw BusinessRuleException::make(
                "الموعد خارج ساعات عمل الفرع ({$hours['open']} - {$hours['close']}).",
            );
        }

        if (! $trainer->worksAt($date, $start, $end)) {
            throw BusinessRuleException::make(
                'الموعد خارج أوقات دوام المدرب.',
                ['trainer_id' => ['المدرب لا يداوم في هذا الوقت.']],
            );
        }
    }

    protected function assertBalanceCoversBooking(\App\Models\TraineePackage $package, Trainee $trainee): void
    {
        if ($this->settings->bool('training.allow_negative_balance', false)) {
            return;
        }

        $remaining = $this->balances->balance($package);

        $alreadyBooked = TrainingSession::where('trainee_package_id', $package->id)
            ->where('status', 'scheduled')
            ->count();

        if ($remaining - $alreadyBooked < 1) {
            throw BusinessRuleException::make(
                "لا يوجد رصيد حصص كافٍ لدى المتدرب. الرصيد المتبقي: {$remaining} حصة، والمحجوز مسبقاً: {$alreadyBooked}.",
            );
        }
    }

    protected function assertMutable(TrainingSession $session): void
    {
        if ($session->isSettled()) {
            throw BusinessRuleException::make('لا يمكن تعديل حصة منتهية أو ملغاة.');
        }
    }

    protected function isInsideNoticeWindow(TrainingSession $session): bool
    {
        $notice = $this->settings->int('cancellation.min_notice_hours', 12);

        return now()->diffInHours($session->startsAt(), false) < $notice;
    }

    // ------------------------------------------------------------------
    // Time helpers — times are handled as "HH:MM" strings throughout
    // ------------------------------------------------------------------

    protected function normaliseTime(string $time): string
    {
        return substr($time, 0, 5);
    }

    protected function addMinutes(string $time, int $minutes): string
    {
        return Carbon::createFromFormat('H:i', $this->normaliseTime($time))->addMinutes($minutes)->format('H:i');
    }
}
