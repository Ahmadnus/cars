<?php

namespace Tests\Feature;

use App\Models\BookingRequest;
use App\Models\Branch;
use App\Models\Trainee;
use App\Models\Trainer;
use App\Models\TrainerCompensationRecord;
use App\Services\TrainerCompensationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The self-service endpoints added for the two apps: a trainee's booking
 * screen and a trainer's own pay statement.
 *
 * Both are `me/*` routes with no id in them, and both are reachable without
 * the center-wide permission that covers the same data. These tests hold that
 * line: the trainee may see availability but not the schedule, and the trainer
 * may see their own pay but not a colleague's.
 */
class SelfServiceApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFoundation();
    }

    // ------------------------------------------------------------ trainee

    protected function trainee(): Trainee
    {
        $user = $this->userWithRole('trainee');
        $trainer = Trainer::factory()->create(['branch_id' => $this->branch->id]);

        $trainee = Trainee::factory()->create([
            'branch_id' => $this->branch->id,
            'user_id' => $user->id,
            'trainer_id' => $trainer->id,
        ]);

        Sanctum::actingAs($user->fresh());

        return $trainee;
    }

    public function test_a_trainee_sees_availability_for_their_own_trainer(): void
    {
        $this->trainee();

        $this->getJson('/api/v1/me/slots?date='.$this->workingDay()->toDateString())
            ->assertOk()
            ->assertJsonStructure(['data' => ['trainer', 'date', 'slots']]);
    }

    public function test_a_trainee_cannot_ask_for_a_trainer_from_another_branch(): void
    {
        $trainee = $this->trainee();

        $other = Trainer::factory()->create([
            'branch_id' => Branch::where('code', 'ZRQ')->firstOrFail()->id,
        ]);

        $this->getJson('/api/v1/me/slots?date='.$this->workingDay()->toDateString().'&trainer_id='.$other->uuid)
            ->assertStatus(404);

        $this->assertNotSame($other->branch_id, $trainee->branch_id);
    }

    public function test_availability_in_the_past_is_empty_rather_than_bookable(): void
    {
        $this->trainee();

        $this->getJson('/api/v1/me/slots?date='.now()->subWeek()->toDateString())
            ->assertOk()
            ->assertJsonPath('data.slots', []);
    }

    public function test_the_trainer_picker_lists_only_the_trainees_own_branch(): void
    {
        $this->trainee();

        Trainer::factory()->create([
            'branch_id' => Branch::where('code', 'ZRQ')->firstOrFail()->id,
        ]);

        $this->getJson('/api/v1/me/trainers')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_a_trainee_still_cannot_reach_the_center_wide_availability_endpoint(): void
    {
        $this->trainee();

        $this->getJson('/api/v1/appointments/available-slots?trainer_id=x&date=2026-01-01')
            ->assertStatus(403);
    }

    public function test_a_trainee_sees_their_own_booking_requests(): void
    {
        $trainee = $this->trainee();

        BookingRequest::create([
            'branch_id' => $trainee->branch_id,
            'trainee_id' => $trainee->id,
            'type' => 'booking',
            'requested_date' => $this->workingDay()->toDateString(),
            'requested_start_time' => '10:00',
            'status' => 'pending',
        ]);

        $this->getJson('/api/v1/me/booking-requests')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.pending', 1);
    }

    // ------------------------------------------------------------ trainer

    protected function trainerUser(): array
    {
        $user = $this->userWithRole('trainer');
        $trainer = Trainer::factory()->create([
            'branch_id' => $this->branch->id,
            'user_id' => $user->id,
        ]);

        app(TrainerCompensationService::class)->setRule($trainer, [
            'model' => 'per_lesson',
            'per_lesson_rate' => 5,
            'effective_from' => now()->startOfYear()->toDateString(),
        ]);

        Sanctum::actingAs($user->fresh());

        return [$user->fresh(), $trainer->fresh()];
    }

    public function test_a_trainer_sees_a_running_total_for_the_current_month(): void
    {
        $this->trainerUser();

        $this->getJson('/api/v1/me/compensation')
            ->assertOk()
            ->assertJsonPath('data.is_provisional', true)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonStructure(['data' => ['breakdown' => ['base_salary', 'lesson_earnings']]]);
    }

    public function test_an_issued_statement_is_returned_instead_of_a_preview(): void
    {
        [, $trainer] = $this->trainerUser();
        $period = now()->format('Y-m');

        app(TrainerCompensationService::class)->calculate($trainer, $period);

        $this->getJson('/api/v1/me/compensation?period='.$period)
            ->assertOk()
            ->assertJsonPath('data.is_provisional', false)
            ->assertJsonPath('data.status', 'draft');
    }

    public function test_a_trainer_only_ever_sees_their_own_statements(): void
    {
        [, $trainer] = $this->trainerUser();

        $colleague = Trainer::factory()->create(['branch_id' => $this->branch->id]);

        TrainerCompensationRecord::create([
            'branch_id' => $this->branch->id,
            'trainer_id' => $colleague->id,
            'period' => now()->subMonth()->format('Y-m'),
            'model' => 'per_lesson',
            'net_amount' => 900,
            'status' => 'approved',
        ]);

        app(TrainerCompensationService::class)->calculate($trainer, now()->format('Y-m'));

        $this->getJson('/api/v1/me/compensation/history')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.period', now()->format('Y-m'));
    }

    public function test_a_malformed_period_is_rejected(): void
    {
        $this->trainerUser();

        $this->getJson('/api/v1/me/compensation?period=2026-13')->assertStatus(422);
    }

    public function test_an_account_with_no_trainer_file_gets_nothing(): void
    {
        Sanctum::actingAs($this->userWithRole('receptionist'));

        $this->getJson('/api/v1/me/compensation')->assertStatus(403);
    }
}
