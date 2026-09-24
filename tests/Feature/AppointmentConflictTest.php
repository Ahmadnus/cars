<?php

namespace Tests\Feature;

use App\Exceptions\BusinessRuleException;
use App\Models\Package;
use App\Models\Trainee;
use App\Models\Trainer;
use App\Models\Vehicle;
use App\Services\AppointmentService;
use App\Services\PackageService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Double-booking is the failure mode a scheduling system is judged on, so each
 * of the three resources is tested independently.
 */
class AppointmentConflictTest extends TestCase
{
    use RefreshDatabase;

    protected AppointmentService $appointments;

    protected Trainer $trainer;

    protected Vehicle $vehicle;

    protected string $date;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFoundation();
        $this->actingAsUser($this->admin());

        $this->appointments = app(AppointmentService::class);
        $this->trainer = Trainer::factory()->create(['branch_id' => $this->branch->id]);
        $this->vehicle = Vehicle::factory()->create(['branch_id' => $this->branch->id]);
        $this->date = $this->workingDay()->toDateString();
    }

    public function test_a_trainer_cannot_take_two_lessons_at_the_same_time(): void
    {
        $this->book($this->enrolled(), '10:00');

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('تعارض');

        // Different trainee and vehicle, same trainer and slot.
        $this->book($this->enrolled(), '10:00', vehicle: Vehicle::factory()->create(['branch_id' => $this->branch->id]));
    }

    public function test_a_trainee_cannot_take_two_lessons_at_the_same_time(): void
    {
        $trainee = $this->enrolled();
        $this->book($trainee, '10:00');

        $this->expectException(BusinessRuleException::class);

        $this->book(
            $trainee,
            '10:00',
            trainer: Trainer::factory()->create(['branch_id' => $this->branch->id]),
            vehicle: Vehicle::factory()->create(['branch_id' => $this->branch->id]),
        );
    }

    public function test_a_vehicle_cannot_be_used_by_two_lessons_at_the_same_time(): void
    {
        $this->book($this->enrolled(), '10:00');

        $this->expectException(BusinessRuleException::class);

        $this->book(
            $this->enrolled(),
            '10:00',
            trainer: Trainer::factory()->create(['branch_id' => $this->branch->id]),
        );
    }

    public function test_partially_overlapping_lessons_also_conflict(): void
    {
        $this->book($this->enrolled(), '10:00'); // 10:00 – 10:45

        $this->expectException(BusinessRuleException::class);

        // Starts inside the first lesson.
        $this->book($this->enrolled(), '10:30', vehicle: Vehicle::factory()->create(['branch_id' => $this->branch->id]));
    }

    public function test_back_to_back_lessons_are_allowed(): void
    {
        $first = $this->book($this->enrolled(), '10:00');   // ends 10:45
        $second = $this->book($this->enrolled(), '10:45', vehicle: Vehicle::factory()->create(['branch_id' => $this->branch->id]));

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame('scheduled', $second->status);
    }

    public function test_a_cancelled_lesson_frees_its_slot(): void
    {
        $session = $this->book($this->enrolled(), '10:00');
        $this->appointments->cancel($session, 'اعتذار المتدرب');

        $replacement = $this->book($this->enrolled(), '10:00');

        $this->assertSame('scheduled', $replacement->status);
    }

    public function test_a_lesson_outside_branch_working_hours_is_refused(): void
    {
        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('ساعات عمل الفرع');

        $this->book($this->enrolled(), '23:00');
    }

    public function test_a_lesson_on_a_closed_day_is_refused(): void
    {
        $friday = Carbon::parse($this->date)->next(Carbon::FRIDAY);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('مغلق');

        $this->appointments->schedule([
            'trainee_id' => $this->enrolled()->id,
            'trainer_id' => $this->trainer->id,
            'vehicle_id' => $this->vehicle->id,
            'scheduled_date' => $friday->toDateString(),
            'start_time' => '10:00',
        ], $this->branch->id);
    }

    public function test_rescheduling_ignores_the_lesson_being_moved(): void
    {
        $session = $this->book($this->enrolled(), '10:00');

        // Moving it to its own slot must not collide with itself.
        $moved = $this->appointments->reschedule($session, [
            'scheduled_date' => $this->date,
            'start_time' => '11:00',
        ]);

        $this->assertSame('11:00:00', $moved->start_time);
    }

    public function test_a_vehicle_in_maintenance_cannot_be_booked(): void
    {
        $broken = Vehicle::factory()->inMaintenance()->create(['branch_id' => $this->branch->id]);

        $this->expectException(BusinessRuleException::class);

        $this->book($this->enrolled(), '10:00', vehicle: $broken);
    }

    // ------------------------------------------------------------------

    protected function book(Trainee $trainee, string $time, ?Trainer $trainer = null, ?Vehicle $vehicle = null)
    {
        return $this->appointments->schedule([
            'trainee_id' => $trainee->id,
            'trainer_id' => ($trainer ?? $this->trainer)->id,
            'vehicle_id' => ($vehicle ?? $this->vehicle)->id,
            'scheduled_date' => $this->date,
            'start_time' => $time,
            'duration_minutes' => 45,
        ], $this->branch->id);
    }

    /** A trainee with lessons available, so booking is not refused for balance. */
    protected function enrolled(): Trainee
    {
        $trainee = Trainee::factory()->create(['branch_id' => $this->branch->id]);
        app(PackageService::class)->assign($trainee, Package::factory()->create());

        return $trainee->fresh();
    }

}
