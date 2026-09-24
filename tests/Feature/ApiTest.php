<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\PaymentMethod;
use App\Models\Trainee;
use App\Models\Trainer;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\AppointmentService;
use App\Services\PackageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The API the Flutter apps will consume.
 *
 * These assert the contract the clients depend on — the response envelope, the
 * token lifecycle — and, most importantly, that authorization is enforced on
 * the API exactly as it is in the dashboard.
 */
class ApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFoundation();
    }

    // --------------------------------------------------------------- auth

    public function test_login_returns_a_token_and_the_user(): void
    {
        $user = $this->userWithRole('center_manager');

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'secret-password',
            'device_name' => 'Pixel 8',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'success', 'message',
                'data' => ['token', 'user' => ['id', 'name', 'email', 'permissions']],
                'meta',
            ])
            ->assertJsonPath('success', true);

        $this->assertNotEmpty($response->json('data.token'));
        $this->assertDatabaseHas('personal_access_tokens', ['name' => 'Pixel 8', 'tokenable_id' => $user->id]);
    }

    public function test_login_rejects_a_wrong_password_without_revealing_the_account(): void
    {
        $user = $this->userWithRole('receptionist');

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
            'device_name' => 'Pixel 8',
        ])
            ->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'بيانات الدخول غير صحيحة.');

        // The same message for an address that does not exist at all.
        $this->postJson('/api/v1/auth/login', [
            'email' => 'nobody@example.com',
            'password' => 'wrong-password',
            'device_name' => 'Pixel 8',
        ])->assertJsonPath('message', 'بيانات الدخول غير صحيحة.');
    }

    public function test_login_is_refused_for_an_inactive_account(): void
    {
        $user = $this->userWithRole('trainer', ['status' => 'inactive']);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'secret-password',
            'device_name' => 'Pixel 8',
        ])->assertStatus(403);
    }

    public function test_protected_endpoints_reject_an_unauthenticated_request(): void
    {
        $this->getJson('/api/v1/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('success', false);

        $this->getJson('/api/v1/trainees')->assertStatus(401);
    }

    public function test_logout_revokes_the_current_token(): void
    {
        $user = $this->userWithRole('center_manager');

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'secret-password',
            'device_name' => 'Pixel 8',
        ])->json('data.token');

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();

        // The token row is gone, so the credential cannot be replayed. The
        // guard is forgotten first because the test container memoises the
        // resolved user between requests within one test.
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'name' => 'Pixel 8',
        ]);

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    // ------------------------------------------------------- authorization

    public function test_a_receptionist_cannot_reach_financial_endpoints(): void
    {
        Sanctum::actingAs($this->userWithRole('receptionist'));

        foreach (['/api/v1/reports/profit', '/api/v1/cashbox', '/api/v1/payroll', '/api/v1/expenses'] as $url) {
            $this->getJson($url)
                ->assertStatus(403)
                ->assertJsonPath('success', false);
        }
    }

    public function test_an_accountant_can_read_profit_but_not_manage_trainees(): void
    {
        Sanctum::actingAs($this->userWithRole('accountant'));

        $this->getJson('/api/v1/reports/profit')
            ->assertOk()
            ->assertJsonStructure(['data' => ['revenue', 'expenses', 'net_profit', 'margin']]);

        $this->postJson('/api/v1/trainees', ['full_name' => 'x'])->assertStatus(403);
    }

    public function test_a_trainer_only_sees_their_own_lessons(): void
    {
        $trainerUser = $this->userWithRole('trainer');
        $mine = Trainer::factory()->create(['branch_id' => $this->branch->id, 'user_id' => $trainerUser->id]);
        $theirs = Trainer::factory()->create(['branch_id' => $this->branch->id]);

        $this->actingAsUser($this->admin());
        $myLesson = $this->lessonFor($mine, '09:00');
        $otherLesson = $this->lessonFor($theirs, '11:00');

        Sanctum::actingAs($trainerUser->fresh());

        $ids = collect($this->getJson('/api/v1/training-sessions')->assertOk()->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($myLesson->uuid));
        $this->assertFalse($ids->contains($otherLesson->uuid));
    }

    public function test_a_trainee_only_sees_their_own_payments(): void
    {
        $this->actingAsUser($this->admin());

        $traineeUser = $this->userWithRole('trainer'); // any login; linked below
        $mine = Trainee::factory()->create(['branch_id' => $this->branch->id, 'user_id' => $traineeUser->id]);
        $theirs = Trainee::factory()->create(['branch_id' => $this->branch->id]);

        $cash = PaymentMethod::where('code', 'cash')->firstOrFail();
        $payments = app(\App\Services\PaymentService::class);

        foreach ([$mine, $theirs] as $trainee) {
            $enrolment = app(PackageService::class)->assign($trainee, Package::factory()->create());

            $payments->record([
                'trainee_id' => $trainee->id,
                'trainee_package_id' => $enrolment->id,
                'payment_method_id' => $cash->id,
                'amount' => 25,
                'paid_on' => now()->toDateString(),
            ], $this->branch->id);
        }

        // Grant the trainee's login the ability to list payments at all.
        $permission = \App\Models\Permission::where('name', 'payments.view')->firstOrFail();
        $traineeUser->permissionOverrides()->sync([$permission->id => ['granted' => true]]);
        $traineeUser->forgetPermissionCache();

        Sanctum::actingAs($traineeUser->fresh());

        $names = collect($this->getJson('/api/v1/payments')->assertOk()->json('data'))
            ->pluck('trainee.full_name');

        $this->assertTrue($names->contains($mine->full_name));
        $this->assertFalse($names->contains($theirs->full_name));
    }

    // ------------------------------------------------------------ contract

    public function test_every_response_uses_the_same_envelope(): void
    {
        Sanctum::actingAs($this->admin());

        $this->getJson('/api/v1/trainees')
            ->assertOk()
            ->assertJsonStructure(['success', 'message', 'data', 'meta']);

        $this->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonStructure(['success', 'message', 'data', 'meta']);
    }

    public function test_validation_errors_use_the_documented_shape(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/v1/trainees', [])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['success', 'message', 'errors' => ['full_name', 'phone']]);
    }

    public function test_a_missing_record_returns_a_structured_404(): void
    {
        Sanctum::actingAs($this->admin());

        $this->getJson('/api/v1/trainees/00000000-0000-0000-0000-000000000000')
            ->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'العنصر المطلوب غير موجود.');
    }

    public function test_pagination_metadata_is_returned(): void
    {
        Trainee::factory()->count(3)->create(['branch_id' => $this->branch->id]);

        Sanctum::actingAs($this->admin());

        $this->getJson('/api/v1/trainees?per_page=2')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.has_more', true)
            ->assertJsonCount(2, 'data');
    }

    // ------------------------------------------------------- trainee flows

    public function test_a_trainee_can_raise_a_booking_request(): void
    {
        $user = $this->userWithRole('trainer');
        $trainee = Trainee::factory()->create(['branch_id' => $this->branch->id, 'user_id' => $user->id]);

        Sanctum::actingAs($user->fresh());

        $this->postJson('/api/v1/booking-requests', [
            'type' => 'booking',
            'requested_date' => $this->workingDay()->toDateString(),
            'requested_start_time' => '10:00',
            'trainee_note' => 'أفضّل الفترة الصباحية',
        ])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'pending');

        $this->assertDatabaseHas('booking_requests', ['trainee_id' => $trainee->id, 'status' => 'pending']);
    }

    public function test_a_trainee_cannot_raise_a_request_against_another_trainees_lesson(): void
    {
        $user = $this->userWithRole('trainer');
        Trainee::factory()->create(['branch_id' => $this->branch->id, 'user_id' => $user->id]);

        $this->actingAsUser($this->admin());
        $trainer = Trainer::factory()->create(['branch_id' => $this->branch->id]);
        $foreignLesson = $this->lessonFor($trainer, '13:00');

        Sanctum::actingAs($user->fresh());

        $this->postJson('/api/v1/booking-requests', [
            'type' => 'cancellation',
            'training_session_id' => $foreignLesson->uuid,
        ])->assertStatus(403);
    }

    public function test_the_trainer_home_screen_returns_todays_lessons(): void
    {
        $user = $this->userWithRole('trainer');
        $trainer = Trainer::factory()->create(['branch_id' => $this->branch->id, 'user_id' => $user->id]);

        Sanctum::actingAs($user->fresh());

        $this->getJson('/api/v1/home/trainer')
            ->assertOk()
            ->assertJsonStructure(['data' => ['trainer', 'vehicles', 'today', 'upcoming', 'stats']]);
    }

    public function test_the_trainer_home_lists_their_own_vehicles_without_the_vehicles_permission(): void
    {
        $user = $this->userWithRole('trainer');
        $trainer = Trainer::factory()->create(['branch_id' => $this->branch->id, 'user_id' => $user->id]);

        $mine = Vehicle::factory()->create([
            'branch_id' => $this->branch->id,
            'assigned_trainer_id' => $trainer->id,
        ]);
        $unassigned = Vehicle::factory()->create(['branch_id' => $this->branch->id]);

        Sanctum::actingAs($user->fresh());

        // The broad endpoint stays closed to them...
        $this->getJson('/api/v1/vehicles')->assertStatus(403);

        // ...but their own assigned vehicles come through on their home screen.
        $plates = collect($this->getJson('/api/v1/home/trainer')->assertOk()->json('data.vehicles'))
            ->pluck('plate_number');

        $this->assertTrue($plates->contains($mine->plate_number));
        $this->assertFalse($plates->contains($unassigned->plate_number));
    }

    public function test_a_login_without_a_trainer_profile_cannot_use_the_trainer_home(): void
    {
        Sanctum::actingAs($this->userWithRole('receptionist'));

        $this->getJson('/api/v1/home/trainer')
            ->assertStatus(403)
            ->assertJsonPath('success', false);
    }

    // ------------------------------------------------------------------

    /** A booked lesson for the given trainer, on a day everyone works. */
    protected function lessonFor(Trainer $trainer, string $time): \App\Models\TrainingSession
    {
        $trainee = Trainee::factory()->create(['branch_id' => $this->branch->id]);
        app(PackageService::class)->assign($trainee, Package::factory()->create());

        return app(AppointmentService::class)->schedule([
            'trainee_id' => $trainee->id,
            'trainer_id' => $trainer->id,
            'vehicle_id' => Vehicle::factory()->create(['branch_id' => $this->branch->id])->id,
            'scheduled_date' => $this->workingDay()->toDateString(),
            'start_time' => $time,
        ], $this->branch->id);
    }
}
