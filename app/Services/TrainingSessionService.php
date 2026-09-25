<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\TraineeSkillEvaluation;
use App\Models\TrainingSession;
use App\Models\TrainingSessionSkill;
use App\Models\TrainingSkill;
use Illuminate\Support\Facades\DB;

/**
 * Delivering a lesson: completion, evaluation, absence and reopening.
 *
 * Completion is the single most important transaction in the system. It
 * validates state, consumes exactly one lesson from the append-only ledger,
 * records the evaluation, rolls the trainee's skill levels forward, raises the
 * notification and writes the audit entry — all or nothing.
 */
class TrainingSessionService
{
    public function __construct(
        protected TraineeBalanceService $balances,
        protected AuditLogger $audit,
        protected NotificationService $notifications,
        protected SettingsRepository $settings,
    ) {
    }

    /**
     * Complete a lesson and record its evaluation.
     *
     * @param  array{
     *     overall_rating?: string|null,
     *     strengths?: string|null,
     *     weaknesses?: string|null,
     *     trainer_notes?: string|null,
     *     next_requirements?: string|null,
     *     skills?: array<int, array{skill_id:int, rating:string, note?:string|null}>,
     *     duration_minutes?: int|null,
     * }  $data
     */
    public function complete(TrainingSession $session, array $data = []): TrainingSession
    {
        return DB::transaction(function () use ($session, $data) {
            // 1 & 2 — state must allow completion.
            $this->assertCompletable($session);

            $original = $session->getOriginal();
            $package = $session->traineePackage ?? $session->trainee->activePackage();

            // 3 — balance must cover the lesson (checked under a row lock).
            $consumption = null;

            if ($package) {
                $consumption = $this->balances->consumeForSession($package, $session);
            } elseif (! $this->settings->bool('training.allow_negative_balance', false)) {
                throw BusinessRuleException::make('لا توجد باقة نشطة لهذا المتدرب، لا يمكن خصم الحصة.');
            }

            // 4 & 5 — record the completion.
            $session->fill([
                'status' => 'completed',
                'completed_at' => now(),
                'completed_by' => auth()->id(),
                'trainee_package_id' => $package?->id ?? $session->trainee_package_id,
                'overall_rating' => $data['overall_rating'] ?? $session->overall_rating,
                'strengths' => $data['strengths'] ?? $session->strengths,
                'weaknesses' => $data['weaknesses'] ?? $session->weaknesses,
                'trainer_notes' => $data['trainer_notes'] ?? $session->trainer_notes,
                'next_requirements' => $data['next_requirements'] ?? $session->next_requirements,
            ]);

            if (! empty($data['duration_minutes'])) {
                $session->duration_minutes = (int) $data['duration_minutes'];
            }

            $session->save();

            // 6 & 7 — evaluation and rolling skill levels.
            if (! empty($data['skills'])) {
                $this->recordSkills($session, $data['skills']);
            }

            // 8 — trainee progress.
            $this->advanceTraineeStatus($session);

            // 9 — compensation eligibility is derived from completed lessons, so
            // nothing to write here; the statement recalculates from this row.

            // 10 — notify, and warn when the balance is running out.
            $this->notifications->sessionEvaluated($session);

            if ($package) {
                $remaining = $this->balances->balance($package);
                $threshold = $this->settings->int('training.low_balance_threshold', 2);

                if ($remaining > 0 && $remaining <= $threshold) {
                    $this->notifications->lowLessonBalance($package, $remaining);
                }

                if ($remaining <= 0) {
                    $package->update(['status' => 'completed']);
                }
            }

            // 11 — audit trail.
            $this->audit->log(
                action: 'session.completed',
                subject: $session,
                before: ['status' => $original['status']],
                after: [
                    'status' => 'completed',
                    'overall_rating' => $session->overall_rating,
                    'lesson_transaction_id' => $consumption?->id,
                ],
                description: 'إنهاء حصة تدريبية',
            );

            return $session->fresh(['trainee', 'trainer', 'vehicle', 'skills.skill']);
        });
    }

    /** Record an absence; by policy this normally burns the lesson. */
    public function markNoShow(TrainingSession $session, ?string $note = null): TrainingSession
    {
        $updated = DB::transaction(function () use ($session, $note) {
            if ($session->isSettled()) {
                throw BusinessRuleException::make('لا يمكن تعديل حصة منتهية أو ملغاة.');
            }

            $original = $session->getOriginal();
            $package = $session->traineePackage;
            $burn = null;

            if ($package) {
                $burn = $this->balances->burnForNoShow($package, $session);
            }

            $session->update([
                'status' => 'no_show',
                'trainer_notes' => $note ?? $session->trainer_notes,
            ]);

            $this->audit->log(
                action: 'session.no_show',
                subject: $session,
                before: ['status' => $original['status']],
                after: ['status' => 'no_show', 'lesson_burnt' => $burn !== null],
                reason: $note,
            );

            return $session->fresh();
        });

        /*
         | Announced after the commit, never inside the transaction.
         |
         | A notification talks to a push or SMS provider over the network. Doing
         | that with the transaction open would hold row locks on the lesson and
         | the lesson balance for as long as the provider takes to answer, and a
         | provider timeout would roll back a no-show that really happened.
         */
        try {
            $this->notifications->sessionMissed($updated);
        } catch (\Throwable $e) {
            report($e);
        }

        return $updated;
    }

    /**
     * Reopen a completed lesson, returning the consumed lesson to the balance.
     *
     * Kept deliberately narrow and audited: it exists to undo a mis-click, not
     * to edit history.
     */
    public function reopen(TrainingSession $session, string $reason): TrainingSession
    {
        return DB::transaction(function () use ($session, $reason) {
            if (! in_array($session->status, ['completed', 'no_show'], true)) {
                throw BusinessRuleException::make('لا يمكن إعادة فتح حصة لم يتم إنهاؤها.');
            }

            $original = $session->getOriginal();

            $this->balances->refundSession($session, $reason);

            $session->update([
                'status' => 'scheduled',
                'completed_at' => null,
                'completed_by' => null,
            ]);

            $this->audit->log(
                action: 'session.reopened',
                subject: $session,
                before: ['status' => $original['status']],
                after: ['status' => 'scheduled'],
                reason: $reason,
            );

            return $session->fresh();
        });
    }

    /**
     * Save the skills practised and roll each one forward on the trainee's
     * profile, so the profile always reflects the most recent assessment.
     *
     * @param  array<int, array{skill_id:int, rating:string, note?:string|null}>  $skills
     */
    public function recordSkills(TrainingSession $session, array $skills): void
    {
        foreach ($skills as $entry) {
            $skillId = (int) ($entry['skill_id'] ?? 0);
            $rating = $entry['rating'] ?? null;

            if (! $skillId || ! in_array($rating, TrainingSkill::LEVELS, true)) {
                continue;
            }

            TrainingSessionSkill::updateOrCreate(
                ['training_session_id' => $session->id, 'training_skill_id' => $skillId],
                ['rating' => $rating, 'note' => $entry['note'] ?? null],
            );

            TraineeSkillEvaluation::updateOrCreate(
                ['trainee_id' => $session->trainee_id, 'training_skill_id' => $skillId],
                [
                    'level' => $rating,
                    'last_session_id' => $session->id,
                    'evaluated_by' => auth()->id(),
                    'evaluated_at' => now(),
                    'note' => $entry['note'] ?? null,
                ],
            );
        }
    }

    /**
     * Overall readiness, 0-100, averaged across the configured skills.
     *
     * Skills never assessed count as zero so the figure reflects the whole
     * syllabus rather than only what has been touched.
     */
    public function readinessPercent(int $traineeId): int
    {
        $skillCount = TrainingSkill::where('status', 'active')->count();

        if ($skillCount === 0) {
            return 0;
        }

        $total = TraineeSkillEvaluation::where('trainee_id', $traineeId)
            ->get()
            ->sum(fn (TraineeSkillEvaluation $e) => $e->score());

        return (int) round($total / $skillCount);
    }

    protected function assertCompletable(TrainingSession $session): void
    {
        if ($session->status === 'completed') {
            throw BusinessRuleException::make('تم إنهاء هذه الحصة مسبقاً.');
        }

        if (in_array($session->status, ['cancelled', 'no_show'], true)) {
            throw BusinessRuleException::make('لا يمكن إنهاء حصة ملغاة أو مسجلة كعدم حضور.');
        }

        if ($session->startsAt()->isFuture()) {
            throw BusinessRuleException::make('لا يمكن إنهاء حصة لم يحن موعدها بعد.');
        }
    }

    /** Move a trainee out of "new" on their first completed lesson. */
    protected function advanceTraineeStatus(TrainingSession $session): void
    {
        $trainee = $session->trainee;

        if ($trainee && in_array($trainee->status, ['new', 'suspended'], true)) {
            $trainee->update(['status' => 'in_training']);
        }
    }
}
