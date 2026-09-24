<?php

namespace Tests\Feature;

use App\Exceptions\BusinessRuleException;
use App\Models\LessonTransaction;
use App\Models\Package;
use App\Models\Trainee;
use App\Models\Trainer;
use App\Models\TrainingSession;
use App\Models\Vehicle;
use App\Services\AppointmentService;
use App\Services\PackageService;
use App\Services\TraineeBalanceService;
use App\Services\TrainingSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The lesson ledger is the system's most important invariant: a trainee must
 * never consume a lesson they have not paid for, and the balance must always be
 * reconstructable from its transactions.
 */
class LessonBalanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFoundation();
        $this->actingAsUser($this->admin());
    }

    public function test_assigning_a_package_credits_the_ledger_once(): void
    {
        $trainee = Trainee::factory()->create(['branch_id' => $this->branch->id]);
        $package = Package::factory()->create(['lessons_count' => 10, 'price' => 150]);

        $enrolment = app(PackageService::class)->assign($trainee, $package);

        $this->assertSame(10, $enrolment->remainingLessons());
        $this->assertSame(1, $enrolment->lessonTransactions()->count());
        $this->assertDatabaseHas('lesson_transactions', [
            'trainee_package_id' => $enrolment->id,
            'type' => 'package_credit',
            'quantity' => 10,
        ]);
    }

    public function test_completing_a_lesson_deducts_exactly_one_lesson(): void
    {
        [$trainee, $enrolment] = $this->enrolledTrainee();
        $session = $this->pastSession($trainee);

        app(TrainingSessionService::class)->complete($session, ['overall_rating' => 'good']);

        $this->assertSame(9, $enrolment->fresh()->remainingLessons());
        $this->assertDatabaseHas('lesson_transactions', [
            'training_session_id' => $session->id,
            'type' => 'consumption',
            'quantity' => -1,
        ]);
    }

    public function test_balance_cannot_go_negative(): void
    {
        [$trainee, $enrolment] = $this->enrolledTrainee(lessons: 1);

        // Spend the single lesson.
        app(TraineeBalanceService::class)->debitManual($enrolment, 1, 'اختبار');
        $this->assertSame(0, $enrolment->fresh()->remainingLessons());

        $this->expectException(BusinessRuleException::class);
        app(TraineeBalanceService::class)->debitManual($enrolment, 1, 'تجاوز الرصيد');
    }

    public function test_a_lesson_cannot_be_completed_twice(): void
    {
        [$trainee] = $this->enrolledTrainee();
        $session = $this->pastSession($trainee);
        $service = app(TrainingSessionService::class);

        $service->complete($session, []);

        $this->expectException(BusinessRuleException::class);
        $service->complete($session->fresh(), []);
    }

    public function test_reopening_a_completed_lesson_returns_the_lesson(): void
    {
        [$trainee, $enrolment] = $this->enrolledTrainee();
        $session = $this->pastSession($trainee);
        $service = app(TrainingSessionService::class);

        $service->complete($session, []);
        $this->assertSame(9, $enrolment->fresh()->remainingLessons());

        $service->reopen($session->fresh(), 'تم الإنهاء بالخطأ');

        $this->assertSame(10, $enrolment->fresh()->remainingLessons());
        $this->assertSame('scheduled', $session->fresh()->status);
    }

    public function test_the_ledger_is_append_only_and_reconstructs_the_balance(): void
    {
        [$trainee, $enrolment] = $this->enrolledTrainee();
        $balances = app(TraineeBalanceService::class);

        $balances->creditExtraLessons($enrolment, 3);
        $balances->debitManual($enrolment, 2, 'تصحيح');

        $sum = LessonTransaction::where('trainee_package_id', $enrolment->id)->sum('quantity');

        $this->assertSame(11, (int) $sum);
        $this->assertSame(11, $enrolment->fresh()->remainingLessons());
        // Nothing was updated in place: three rows, one per movement.
        $this->assertSame(3, $enrolment->lessonTransactions()->count());
    }

    public function test_no_show_burns_a_lesson_per_policy(): void
    {
        [$trainee, $enrolment] = $this->enrolledTrainee();
        $session = $this->pastSession($trainee);

        app(TrainingSessionService::class)->markNoShow($session, 'لم يحضر');

        $this->assertSame('no_show', $session->fresh()->status);
        $this->assertSame(9, $enrolment->fresh()->remainingLessons());
    }

    // ------------------------------------------------------------------

    /** @return array{0: Trainee, 1: \App\Models\TraineePackage} */
    protected function enrolledTrainee(int $lessons = 10): array
    {
        $trainee = Trainee::factory()->create(['branch_id' => $this->branch->id]);
        $package = Package::factory()->create(['lessons_count' => $lessons]);
        $enrolment = app(PackageService::class)->assign($trainee, $package);

        return [$trainee->fresh(), $enrolment];
    }

    /** A lesson scheduled in the past, so it is eligible for completion. */
    protected function pastSession(Trainee $trainee): TrainingSession
    {
        $trainer = Trainer::factory()->create(['branch_id' => $this->branch->id]);
        $vehicle = Vehicle::factory()->create(['branch_id' => $this->branch->id]);

        $date = $this->pastWorkingDay();

        return app(AppointmentService::class)->schedule([
            'trainee_id' => $trainee->id,
            'trainer_id' => $trainer->id,
            'vehicle_id' => $vehicle->id,
            'scheduled_date' => $date->toDateString(),
            'start_time' => '10:00',
            'duration_minutes' => 45,
        ], $this->branch->id);
    }
}
