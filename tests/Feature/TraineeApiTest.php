<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\PaymentMethod;
use App\Models\Trainee;
use App\Models\Trainer;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\PackageService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The API the Trainee app consumes.
 *
 * The safety property under test is structural: no `me/*` route takes an id, so
 * a trainee token cannot address another trainee. These tests assert that the
 * center-wide endpoints stay closed to that token as well — a trainee holding
 * `trainees.view` would have been able to list every trainee in the center.
 */
class TraineeApiTest extends TestCase
{
    use RefreshDatabase;

    protected Trainee $trainee;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFoundation();

        $this->trainee = Trainee::factory()->create(['branch_id' => $this->branch->id]);
        $this->user = $this->userWithRole('trainee');
        $this->trainee->forceFill(['user_id' => $this->user->id])->save();
        $this->user = $this->user->fresh();
    }

    protected function actingAsTrainee(): static
    {
        Sanctum::actingAs($this->user);

        return $this;
    }

    /** Give the trainee a package, a finished lesson and a payment. */
    protected function seedFile(): void
    {
        $trainer = Trainer::factory()->create(['branch_id' => $this->branch->id]);
        $package = Package::factory()->create(['lessons_count' => 10, 'price' => 300]);

        $this->trainee->forceFill(['trainer_id' => $trainer->id])->save();

        $assigned = app(PackageService::class)->assign($this->trainee->fresh(), $package, [
            'discount_percent' => 0,
        ]);

        TrainingSession::create([
            'branch_id' => $this->branch->id,
            'trainee_id' => $this->trainee->id,
            'trainer_id' => $trainer->id,
            'trainee_package_id' => $assigned->id,
            'scheduled_date' => $this->workingDay()->toDateString(),
            'start_time' => '09:00',
            'end_time' => '09:45',
            'duration_minutes' => 45,
            'status' => 'scheduled',
        ]);

        app(PaymentService::class)->record([
            'trainee_id' => $this->trainee->id,
            'trainee_package_id' => $assigned->id,
            'amount' => 100,
            'payment_method_id' => PaymentMethod::first()->id,
            'paid_on' => now()->toDateString(),
        ], $this->branch->id);
    }

    public function test_home_returns_the_trainees_own_file(): void
    {
        $this->seedFile();

        $response = $this->actingAsTrainee()
            ->getJson('/api/v1/me/home')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.trainee.trainee_number', $this->trainee->trainee_number)
            ->assertJsonStructure([
                'data' => [
                    'trainee', 'package', 'balance', 'next_lesson', 'upcoming',
                    'progress' => ['readiness_percent', 'completed_lessons', 'remaining_lessons'],
                    'financial' => ['outstanding', 'paid'],
                ],
            ]);

        $this->assertSame(10, $response->json('data.balance.credited'));
        $this->assertSame(200.0, (float) $response->json('data.financial.outstanding'));
        $this->assertSame(100.0, (float) $response->json('data.financial.paid'));
    }

    public function test_sessions_lists_only_the_trainees_own_lessons(): void
    {
        $this->seedFile();

        $other = Trainee::factory()->create(['branch_id' => $this->branch->id]);
        $trainer = Trainer::factory()->create(['branch_id' => $this->branch->id]);

        $foreign = TrainingSession::create([
            'branch_id' => $this->branch->id,
            'trainee_id' => $other->id,
            'trainer_id' => $trainer->id,
            'scheduled_date' => $this->workingDay()->toDateString(),
            'start_time' => '11:00',
            'end_time' => '11:45',
            'duration_minutes' => 45,
            'status' => 'scheduled',
        ]);

        $response = $this->actingAsTrainee()->getJson('/api/v1/me/sessions')->assertOk();

        $ids = collect($response->json('data'))->pluck('id');

        $this->assertCount(1, $ids);
        $this->assertSame(
            $this->trainee->trainingSessions()->value('uuid'),
            $ids->first(),
        );
        $this->assertNotContains($foreign->uuid, $ids);
    }

    public function test_a_lesson_belonging_to_someone_else_is_not_found(): void
    {
        $other = Trainee::factory()->create(['branch_id' => $this->branch->id]);
        $trainer = Trainer::factory()->create(['branch_id' => $this->branch->id]);

        $foreign = TrainingSession::create([
            'branch_id' => $this->branch->id,
            'trainee_id' => $other->id,
            'trainer_id' => $trainer->id,
            'scheduled_date' => $this->workingDay()->toDateString(),
            'start_time' => '11:00',
            'end_time' => '11:45',
            'duration_minutes' => 45,
            'status' => 'scheduled',
        ]);

        // Guessing another trainee's lesson id must read as absent, not denied:
        // a 403 would confirm the record exists.
        $this->actingAsTrainee()
            ->getJson('/api/v1/me/sessions/'.$foreign->uuid)
            ->assertNotFound();
    }

    public function test_skills_and_payments_and_packages_are_reachable(): void
    {
        $this->seedFile();

        $this->actingAsTrainee()->getJson('/api/v1/me/skills')
            ->assertOk()
            ->assertJsonStructure(['data' => ['readiness_percent', 'skills']]);

        $this->actingAsTrainee()->getJson('/api/v1/me/packages')->assertOk();

        $this->actingAsTrainee()->getJson('/api/v1/me/payments')
            ->assertOk()
            ->assertJsonPath('meta.paid', 100)
            ->assertJsonPath('meta.outstanding', 200);
    }

    /**
     * The whole reason `me/*` exists: a trainee token must not reach anything
     * that spans the center.
     */
    public function test_a_trainee_token_cannot_reach_center_wide_endpoints(): void
    {
        foreach ([
            '/api/v1/dashboard',
            '/api/v1/trainees',
            '/api/v1/trainers',
            '/api/v1/payments',
            '/api/v1/cashbox',
            '/api/v1/payroll',
            '/api/v1/reports/profit',
            '/api/v1/reports/revenue',
            '/api/v1/training-sessions',
        ] as $endpoint) {
            $this->actingAsTrainee()
                ->getJson($endpoint)
                ->assertForbidden();
        }
    }

    public function test_no_financial_figure_of_the_center_leaks_into_the_home_payload(): void
    {
        $this->seedFile();

        $payload = $this->actingAsTrainee()->getJson('/api/v1/me/home')->json('data');

        // The trainee's own account is fine; anything center-wide is not.
        foreach (['profit', 'revenue', 'expenses', 'cashbox', 'salaries'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $payload);
            $this->assertArrayNotHasKey($forbidden, $payload['financial']);
        }
    }

    public function test_a_trainee_login_with_no_file_is_refused(): void
    {
        $orphan = $this->userWithRole('trainee');

        Sanctum::actingAs($orphan);

        $this->getJson('/api/v1/me/home')->assertForbidden();
    }

    public function test_the_endpoints_require_a_token(): void
    {
        $this->getJson('/api/v1/me/home')->assertUnauthorized();
        $this->getJson('/api/v1/me/sessions')->assertUnauthorized();
    }

    public function test_a_trainee_can_raise_a_booking_request_but_not_book_directly(): void
    {
        $this->seedFile();

        // Booking goes through a request a human approves; the trainee has no
        // `appointments.create`, so the direct route stays shut.
        $this->actingAsTrainee()
            ->postJson('/api/v1/appointments', [])
            ->assertForbidden();

        $this->actingAsTrainee()
            ->postJson('/api/v1/booking-requests', [
                'type' => 'booking',
                'requested_date' => $this->workingDay(minimumOffset: 3)->toDateString(),
                'requested_start_time' => '10:00',
                'trainee_note' => 'أفضّل الصباح',
            ])
            ->assertCreated();
    }
}
