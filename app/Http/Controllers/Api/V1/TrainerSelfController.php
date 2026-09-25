<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Trainer;
use App\Models\TrainerCompensationRecord;
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

    /** The trainer record behind the token. See MeController::trainee(). */
    protected function trainer(Request $request): Trainer
    {
        $trainer = $request->user()->trainer;

        abort_if(! $trainer, 403, 'هذا الحساب غير مرتبط بملف مدرب.');

        return $trainer;
    }
}
