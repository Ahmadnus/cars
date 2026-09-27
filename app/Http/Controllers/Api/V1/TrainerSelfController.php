<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\TrainingSessionResource;
use App\Models\Trainer;
use App\Models\TrainerCompensationRecord;
use App\Models\TrainingSession;
use App\Services\AppointmentService;
use App\Services\NotificationService;
use App\Services\TrainerCompensationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A trainer's own earnings, for the Trainer app.
 *
 * Built the same way as MeController: every query is rooted at
 * `$request->user()->trainer` and no route takes an id, so a trainer token can
 * only ever reach one statement. That is the whole reason this is separate
 * from the admin TrainerCompensationController — the trainer needs no
 * `trainer_compensation.view` permission to see their own figures, and
 * granting it would have shown them every colleague's pay.
 */
class TrainerSelfController extends ApiController
{
    public function __construct(protected TrainerCompensationService $compensation)
    {
    }

    /**
     * One month's statement.
     *
     * For a month the office has already issued, the stored record is
     * authoritative. For the month in progress there is usually no record yet,
     * so the figures are computed live from the same rule — clearly flagged as
     * a running total, because bonuses and deductions the office adds later
     * are not in it.
     */
    public function statement(Request $request): JsonResponse
    {
        $trainer = $this->trainer($request);

        $period = (string) $request->input('period', now()->format('Y-m'));

        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
            return $this->failed('صيغة الشهر غير صحيحة. الصيغة المطلوبة: YYYY-MM.');
        }

        $record = TrainerCompensationRecord::where('trainer_id', $trainer->id)
            ->where('period', $period)
            ->first();

        if ($record) {
            return $this->ok($this->present($record->toArray(), $period, $record));
        }

        [$start, $end] = $this->compensation->periodBounds($period);
        $rule = $trainer->ruleOn($end);
        $stats = $this->compensation->lessonStats($trainer, $start, $end);

        if (! $rule) {
            // No rule means the office has not set this trainer's terms yet.
            // Show the work done rather than an error — the lessons are real
            // even when the money is not decided.
            return $this->ok($this->present([
                'lessons_count' => $stats['lessons'],
                'training_minutes' => $stats['minutes'],
            ], $period, null, 'لم يتم تحديد قاعدة الأجور بعد. راجع الإدارة.'));
        }

        return $this->ok($this->present(
            $this->compensation->compute($rule, $stats),
            $period,
            null,
        ));
    }

    /** Past statements, newest first, for the history list. */
    public function statements(Request $request): JsonResponse
    {
        $trainer = $this->trainer($request);

        $records = TrainerCompensationRecord::where('trainer_id', $trainer->id)
            ->orderByDesc('period')
            ->paginate($this->perPage());

        return $this->ok(
            collect($records->items())->map(fn (TrainerCompensationRecord $record) => [
                'period' => $record->period,
                'lessons_count' => (int) $record->lessons_count,
                'training_hours' => round($record->training_minutes / 60, 1),
                'net_amount' => round((float) $record->net_amount, 2),
                'paid_amount' => round((float) $record->paid_amount, 2),
                'remaining' => round((float) $record->net_amount - (float) $record->paid_amount, 2),
                'status' => $record->status,
            ])->values(),
            meta: [
                'current_page' => $records->currentPage(),
                'last_page' => $records->lastPage(),
                'total' => $records->total(),
                'has_more' => $records->hasMorePages(),
            ],
        );
    }

    /**
     * Shape one month for the app.
     *
     * @param  array<string, mixed>  $figures
     * @return array<string, mixed>
     */
    protected function present(
        array $figures,
        string $period,
        ?TrainerCompensationRecord $record,
        ?string $note = null,
    ): array {
        $net = round((float) ($figures['net_amount'] ?? 0), 2);
        $paid = round((float) ($record?->paid_amount ?? 0), 2);

        return [
            'period' => $period,
            // A live total moves until the office closes the month; the app
            // says so rather than presenting it as a payable figure.
            'is_provisional' => $record === null,
            'status' => $record?->status ?? 'pending',
            'model' => $figures['model'] ?? null,
            'lessons_count' => (int) ($figures['lessons_count'] ?? 0),
            'training_minutes' => (int) ($figures['training_minutes'] ?? 0),
            'training_hours' => round(((int) ($figures['training_minutes'] ?? 0)) / 60, 1),
            'breakdown' => [
                'base_salary' => round((float) ($figures['base_salary'] ?? 0), 2),
                'lesson_earnings' => round((float) ($figures['lesson_earnings'] ?? 0), 2),
                'percentage_earnings' => round((float) ($figures['percentage_earnings'] ?? 0), 2),
                'bonuses' => round((float) ($figures['bonuses'] ?? 0), 2),
                'deductions' => round((float) ($figures['deductions'] ?? 0), 2),
            ],
            'gross_amount' => round((float) ($figures['gross_amount'] ?? 0), 2),
            'net_amount' => $net,
            'paid_amount' => $paid,
            'remaining' => round($net - $paid, 2),
            'payments' => $record
                ? $record->payments()->with('paymentMethod')->orderByDesc('paid_on')->get()
                    ->map(fn ($payment) => [
                        'receipt_number' => $payment->receipt_number,
                        'amount' => round((float) $payment->amount, 2),
                        'paid_on' => $payment->paid_on?->toDateString(),
                        'method' => $payment->paymentMethod?->label_ar,
                    ])->values()
                : [],
            'note' => $note,
        ];
    }

    /**
     * Move one of my own lessons.
     *
     * The center decided a trainer may reschedule their own diary without asking
     * anyone: they are the one who knows they cannot make 08:00, and routing that
     * through the office only delays the trainee being told. So there is no
     * approval step here — but the move is audited, the office is notified with
     * the trainer's name, and so is the trainee.
     *
     * Only the trainer's own lessons: the session is looked up through their own
     * relation, so another trainer's id reads as not found rather than forbidden.
     * And it goes through AppointmentService like any other move, so the same
     * conflict, working-hours and vehicle rules apply — a trainer cannot put
     * themselves in two cars at once just because nobody approves this.
     */
    public function reschedule(
        Request $request,
        string $session,
        AppointmentService $appointments,
        NotificationService $notifications,
    ): JsonResponse {
        $trainer = $this->trainer($request);

        $lesson = TrainingSession::where('uuid', $session)
            ->where('trainer_id', $trainer->id)
            ->firstOrFail();

        $data = $request->validate([
            'scheduled_date' => ['required', 'date', 'after_or_equal:today'],
            'start_time' => ['required', 'date_format:H:i'],
            'duration_minutes' => ['nullable', 'integer', 'min:15', 'max:300'],
            'reason' => ['nullable', 'string', 'max:500'],
        ], [], [
            'scheduled_date' => 'التاريخ',
            'start_time' => 'وقت البداية',
            'reason' => 'السبب',
        ]);

        // Captured before the move: the notification says what it was, and after
        // the update the old slot is gone.
        $previous = $lesson->scheduled_date?->format('Y-m-d').' '
            .substr((string) $lesson->start_time, 0, 5);

        $updated = $appointments->reschedule(
            $lesson,
            [
                'scheduled_date' => $data['scheduled_date'],
                'start_time' => $data['start_time'],
                'duration_minutes' => $data['duration_minutes'] ?? $lesson->duration_minutes,
            ],
            'تعديل من المدرب '.$trainer->full_name.($data['reason'] ?? '' ? ' — '.$data['reason'] : ''),
        );

        // After the commit: a provider timeout must not undo a move the calendar
        // has already taken.
        try {
            $notifications->appointmentMovedByTrainer($updated, $previous, $data['reason'] ?? null);
        } catch (\Throwable $e) {
            report($e);
        }

        return $this->ok(
            new TrainingSessionResource($updated->load('trainee', 'trainer', 'vehicle')),
            'تم تعديل موعد الحصة وإبلاغ المتدرب والإدارة.',
        );
    }

    /** The trainer record behind the token. See MeController::trainee(). */
    protected function trainer(Request $request): Trainer
    {
        $trainer = $request->user()->trainer;

        abort_if(! $trainer, 403, 'هذا الحساب غير مرتبط بملف مدرب.');

        return $trainer;
    }
}
