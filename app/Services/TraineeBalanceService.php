<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\LessonTransaction;
use App\Models\TraineePackage;
use App\Models\TrainingSession;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of the lesson ledger.
 *
 * Balances are never stored and never overwritten: every movement appends a
 * signed row, and the remaining balance is the sum of those rows. A debit takes
 * a row lock on the enrolment first, so two concurrent lesson completions
 * cannot both pass the "enough lessons left" check.
 *
 * Callers MUST already be inside DB::transaction().
 */
class TraineeBalanceService
{
    public function __construct(
        protected AuditLogger $audit,
        protected SettingsRepository $settings,
    ) {
    }

    /** Remaining lessons, read under a lock so it is safe to decide on. */
    public function lockedBalance(TraineePackage $package): int
    {
        TraineePackage::whereKey($package->id)->lockForUpdate()->first();

        return (int) LessonTransaction::where('trainee_package_id', $package->id)->sum('quantity');
    }

    public function balance(TraineePackage $package): int
    {
        return (int) LessonTransaction::where('trainee_package_id', $package->id)->sum('quantity');
    }

    // ------------------------------------------------------------------
    // Credits
    // ------------------------------------------------------------------

    /** Opening credit written once, when a package is assigned. */
    public function creditPackage(TraineePackage $package, int $lessons, ?string $description = null): LessonTransaction
    {
        return $this->record(
            $package,
            'package_credit',
            $lessons,
            $description ?? "رصيد باقة {$package->package_name}",
        );
    }

    public function creditExtraLessons(TraineePackage $package, int $lessons, ?string $description = null): LessonTransaction
    {
        $this->assertPositive($lessons);

        return $this->record($package, 'extra_credit', $lessons, $description ?? 'حصص إضافية');
    }

    public function creditManual(TraineePackage $package, int $lessons, string $reason): LessonTransaction
    {
        $this->assertPositive($lessons);

        $transaction = $this->record($package, 'manual_credit', $lessons, $reason);

        $this->audit->log(
            action: 'lesson_balance.manual_credit',
            subject: $package,
            after: ['lessons' => $lessons, 'balance' => $this->balance($package)],
            reason: $reason,
        );

        return $transaction;
    }

    // ------------------------------------------------------------------
    // Debits
    // ------------------------------------------------------------------

    /**
     * Consume one lesson for a completed session.
     *
     * @throws BusinessRuleException when the balance cannot cover it
     */
    public function consumeForSession(TraineePackage $package, TrainingSession $session): LessonTransaction
    {
        $this->assertCanDebit($package, 1);

        return $this->record(
            $package,
            'consumption',
            -1,
            'حصة بتاريخ '.$session->scheduled_date->format('Y-m-d'),
            $session,
        );
    }

    /** Burn a lesson because the trainee did not show up. */
    public function burnForNoShow(TraineePackage $package, TrainingSession $session): ?LessonTransaction
    {
        if (! $this->settings->bool('cancellation.burn_lesson_on_no_show', true)) {
            return null;
        }

        if ($this->lockedBalance($package) < 1) {
            return null; // nothing left to burn; the absence is still recorded on the session
        }

        return $this->record(
            $package,
            'no_show',
            -1,
            'عدم حضور — حصة بتاريخ '.$session->scheduled_date->format('Y-m-d'),
            $session,
        );
    }

    /** Burn a lesson for a cancellation made inside the notice window. */
    public function burnForLateCancellation(TraineePackage $package, TrainingSession $session): ?LessonTransaction
    {
        if (! $this->settings->bool('cancellation.burn_lesson_on_late_cancel', true)) {
            return null;
        }

        if ($this->lockedBalance($package) < 1) {
            return null;
        }

        return $this->record(
            $package,
            'late_cancellation',
            -1,
            'إلغاء متأخر — حصة بتاريخ '.$session->scheduled_date->format('Y-m-d'),
            $session,
        );
    }

    public function debitManual(TraineePackage $package, int $lessons, string $reason): LessonTransaction
    {
        $this->assertPositive($lessons);
        $this->assertCanDebit($package, $lessons);

        $transaction = $this->record($package, 'manual_debit', -$lessons, $reason);

        $this->audit->log(
            action: 'lesson_balance.manual_debit',
            subject: $package,
            after: ['lessons' => -$lessons, 'balance' => $this->balance($package)],
            reason: $reason,
        );

        return $transaction;
    }

    /**
     * Give a lesson back — used when a completed session is reopened or a
     * cancellation is reversed.
     */
    public function refundSession(TrainingSession $session, string $reason): ?LessonTransaction
    {
        $debits = LessonTransaction::where('training_session_id', $session->id)
            ->where('quantity', '<', 0)
            ->get();

        if ($debits->isEmpty()) {
            return null;
        }

        $package = $session->traineePackage;

        if (! $package) {
            return null;
        }

        $total = (int) abs($debits->sum('quantity'));

        return $this->record($package, 'manual_credit', $total, 'استرجاع رصيد — '.$reason, $session);
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    protected function record(
        TraineePackage $package,
        string $type,
        int $quantity,
        ?string $description = null,
        ?TrainingSession $session = null,
    ): LessonTransaction {
        return LessonTransaction::create([
            'trainee_package_id' => $package->id,
            'trainee_id' => $package->trainee_id,
            'type' => $type,
            'quantity' => $quantity,
            'training_session_id' => $session?->id,
            'description' => $description,
            'created_by' => auth()->id(),
        ]);
    }

    protected function assertCanDebit(TraineePackage $package, int $lessons): void
    {
        if ($this->settings->bool('training.allow_negative_balance', false)) {
            return;
        }

        $balance = $this->lockedBalance($package);

        if ($balance < $lessons) {
            throw BusinessRuleException::make(
                "لا يوجد رصيد حصص كافٍ لدى المتدرب. الرصيد المتبقي: {$balance} حصة.",
            );
        }
    }

    protected function assertPositive(int $lessons): void
    {
        if ($lessons <= 0) {
            throw BusinessRuleException::make('عدد الحصص يجب أن يكون أكبر من صفر.');
        }
    }

    /**
     * Rebuild the summary counters shown on a trainee profile.
     *
     * @return array<string, int>
     */
    public function summary(TraineePackage $package): array
    {
        $rows = LessonTransaction::where('trainee_package_id', $package->id)
            ->selectRaw('type, SUM(quantity) as total')
            ->groupBy('type')
            ->pluck('total', 'type');

        $credited = 0;
        $consumed = 0;

        foreach ($rows as $type => $total) {
            if ((int) $total > 0) {
                $credited += (int) $total;
            } else {
                $consumed += (int) abs($total);
            }
        }

        $sessions = DB::table('training_sessions')
            ->where('trainee_package_id', $package->id)
            ->whereNull('deleted_at')
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'credited' => $credited,
            'consumed' => $consumed,
            'remaining' => $credited - $consumed,
            'completed' => (int) ($sessions['completed'] ?? 0),
            'scheduled' => (int) ($sessions['scheduled'] ?? 0),
            'postponed' => (int) ($sessions['postponed'] ?? 0),
            'cancelled' => (int) ($sessions['cancelled'] ?? 0),
            'no_show' => (int) ($sessions['no_show'] ?? 0),
        ];
    }
}
