<?php

namespace Tests\Feature;

use App\Events\RegistrationRequestUpdated;
use App\Models\RegistrationRequest;
use App\Models\Trainee;
use App\Models\User;
use App\Services\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Public self-registration.
 *
 * This is the only place in the system a stranger can write to, so the tests
 * are written around what must *not* happen: no trainee created without staff
 * approval, no request accepted under an unproven phone number, and no way to
 * learn from the responses who is already enrolled.
 */
class SelfRegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFoundation();

        // Codes are returned in the response outside production, which is how
        // the app can be driven without a live SMS provider.
        config(['otp.expose_code' => true, 'otp.enable_fixed_codes' => false]);
    }

    /** Ask for a code and read it back, the way the app does. */
    protected function codeFor(string $phone): string
    {
        $response = $this->postJson('/api/v1/public/registrations/request-code', [
            'phone' => $phone,
        ])->assertOk();

        return (string) $response->json('data.code');
    }

    /** @return array<string, mixed> */
    protected function form(array $overrides = []): array
    {
        return array_merge([
            'full_name' => 'رامي سامر الحديد',
            'phone' => '0791234567',
            'national_id' => '9981234567',
            'birth_date' => '2002-04-11',
            'gender' => 'male',
            'city' => 'عمّان',
            'license_type' => 'private',
            'notes' => 'أفضّل التدريب صباحاً',
        ], $overrides);
    }

    // ------------------------------------------------------------- submitting

    public function test_anyone_can_see_the_options_without_an_account(): void
    {
        $this->getJson('/api/v1/public/registrations/options')
            ->assertOk()
            ->assertJsonStructure(['data' => ['branches', 'license_types', 'genders']]);
    }

    public function test_a_stranger_can_submit_a_request_after_proving_their_phone(): void
    {
        Event::fake([RegistrationRequestUpdated::class]);

        $code = $this->codeFor('0791234567');

        $response = $this->postJson('/api/v1/public/registrations', $this->form([
            'code' => $code,
        ]))->assertCreated();

        $reference = $response->json('data.reference');

        $this->assertNotNull($reference);
        $this->assertDatabaseHas('registration_requests', [
            'reference' => $reference,
            'phone' => '0791234567',
            'status' => RegistrationRequest::STATUS_PENDING,
        ]);

        // The whole point: nothing has entered the training records.
        $this->assertSame(0, Trainee::count());
        $this->assertSame(0, User::whereHas('roles', fn ($q) => $q->where('name', 'trainee'))->count());

        Event::assertDispatched(RegistrationRequestUpdated::class);
    }

    public function test_a_request_without_a_valid_code_is_refused(): void
    {
        $this->codeFor('0791234567');

        $this->postJson('/api/v1/public/registrations', $this->form([
            'code' => '000000',
        ]))->assertStatus(422);

        $this->assertSame(0, RegistrationRequest::count());
    }

    public function test_a_request_with_no_code_at_all_is_refused(): void
    {
        $this->postJson('/api/v1/public/registrations', $this->form())
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');

        $this->assertSame(0, RegistrationRequest::count());
    }

    public function test_a_code_cannot_be_reused_for_a_second_request(): void
    {
        $code = $this->codeFor('0791234567');

        $this->postJson('/api/v1/public/registrations', $this->form(['code' => $code]))
            ->assertCreated();

        // Same code, different applicant: the code was consumed.
        $this->postJson('/api/v1/public/registrations', $this->form([
            'code' => $code,
            'full_name' => 'شخص آخر تماماً',
        ]))->assertStatus(422);

        $this->assertSame(1, RegistrationRequest::count());
    }

    /**
     * A login passcode must not double as a registration passcode.
     *
     * Separate purposes are what stop a code phished for one flow being
     * replayed in the other.
     */
    public function test_a_login_code_cannot_be_used_to_register(): void
    {
        $user = $this->userWithRole('receptionist', ['phone' => '0791234567']);

        $login = app(OtpService::class)->request('0791234567', null, 'login');

        $this->assertNotNull($login['code'], 'the login flow should expose a code in tests');

        $this->postJson('/api/v1/public/registrations', $this->form([
            'code' => $login['code'],
        ]))->assertStatus(422);

        $this->assertSame(0, RegistrationRequest::count());
        $this->assertNotNull($user->fresh());
    }

    public function test_a_second_code_cannot_be_requested_immediately(): void
    {
        $this->codeFor('0791234567');

        $this->postJson('/api/v1/public/registrations/request-code', ['phone' => '0791234567'])
            ->assertStatus(429);
    }

    /**
     * The answer must not reveal who is already enrolled.
     *
     * An existing trainee and an unknown number have to be indistinguishable at
     * the code step, or the endpoint becomes a customer lookup.
     */
    public function test_requesting_a_code_reveals_nothing_about_enrolment(): void
    {
        Trainee::factory()->create(['branch_id' => $this->branch->id, 'phone' => '0799999999']);

        $known = $this->postJson('/api/v1/public/registrations/request-code', ['phone' => '0799999999'])
            ->assertOk();

        $unknown = $this->postJson('/api/v1/public/registrations/request-code', ['phone' => '0788888888'])
            ->assertOk();

        $this->assertSame($known->json('message'), $unknown->json('message'));
        $this->assertSame(
            array_keys($known->json('data')),
            array_keys($unknown->json('data')),
        );
    }

    public function test_an_enrolled_number_is_refused_at_submission_not_at_the_code_step(): void
    {
        Trainee::factory()->create(['branch_id' => $this->branch->id, 'phone' => '0799999999']);

        $code = $this->codeFor('0799999999');

        $this->postJson('/api/v1/public/registrations', $this->form([
            'phone' => '0799999999',
            'code' => $code,
        ]))->assertStatus(422);

        $this->assertSame(0, RegistrationRequest::count());
    }

    // ---------------------------------------------------------------- status

    public function test_an_applicant_can_follow_their_request_by_reference(): void
    {
        $code = $this->codeFor('0791234567');

        $reference = $this->postJson('/api/v1/public/registrations', $this->form(['code' => $code]))
            ->assertCreated()
            ->json('data.reference');

        $this->getJson("/api/v1/public/registrations/{$reference}")
            ->assertOk()
            ->assertJsonPath('data.reference', $reference)
            ->assertJsonPath('data.status', 'pending')
            // Personal detail stays out of the public shape.
            ->assertJsonMissingPath('data.national_id')
            ->assertJsonMissingPath('data.address');
    }

    public function test_an_unknown_reference_is_not_found(): void
    {
        $this->getJson('/api/v1/public/registrations/RQ-26-NOPE00')->assertNotFound();
    }

    // ---------------------------------------------------------------- review

    protected function submitted(): RegistrationRequest
    {
        $code = $this->codeFor('0791234567');

        $this->postJson('/api/v1/public/registrations', $this->form(['code' => $code]))
            ->assertCreated();

        return RegistrationRequest::firstOrFail();
    }

    public function test_the_queue_requires_permission(): void
    {
        $this->submitted();

        // A trainee has no business reading applications.
        $traineeUser = $this->userWithRole('trainee');
        Sanctum::actingAs($traineeUser);
        $this->getJson('/api/v1/registrations')->assertForbidden();

        // A trainer neither.
        Sanctum::actingAs($this->userWithRole('trainer'));
        $this->getJson('/api/v1/registrations')->assertForbidden();

        Sanctum::actingAs($this->userWithRole('receptionist'));
        $this->getJson('/api/v1/registrations')->assertOk()->assertJsonPath('meta.open_count', 1);
    }

    public function test_approving_creates_the_trainee_and_a_login(): void
    {
        $request = $this->submitted();

        Sanctum::actingAs($this->userWithRole('receptionist'));

        $response = $this->postJson("/api/v1/registrations/{$request->uuid}/approve", [
            'branch_id' => $this->branch->uuid,
        ])->assertOk();

        $number = $response->json('data.trainee.trainee_number');
        $password = $response->json('data.login.password');

        $this->assertNotNull($number);
        $this->assertNotNull($password, 'the one-time password is shown once');

        $trainee = Trainee::firstOrFail();

        $this->assertSame('رامي سامر الحديد', $trainee->full_name);
        $this->assertSame('new', $trainee->status, 'a fresh file is not yet in training');
        $this->assertNotNull($trainee->user_id, 'the applicant can sign in');

        $request->refresh();
        $this->assertSame(RegistrationRequest::STATUS_APPROVED, $request->status);
        $this->assertSame($trainee->id, $request->trainee_id);

        // The generated credential really works.
        $this->postJson('/api/v1/auth/otp/request', ['phone' => $trainee->phone])->assertOk();
    }

    public function test_approving_can_skip_the_login(): void
    {
        $request = $this->submitted();

        Sanctum::actingAs($this->userWithRole('receptionist'));

        $this->postJson("/api/v1/registrations/{$request->uuid}/approve", [
            'branch_id' => $this->branch->uuid,
            'create_login' => false,
        ])->assertOk()->assertJsonPath('data.login', null);

        $this->assertNull(Trainee::firstOrFail()->user_id);
    }

    public function test_a_request_cannot_be_approved_twice(): void
    {
        $request = $this->submitted();

        Sanctum::actingAs($this->userWithRole('receptionist'));

        $this->postJson("/api/v1/registrations/{$request->uuid}/approve", [
            'branch_id' => $this->branch->uuid,
        ])->assertOk();

        // A second approval would mint a second trainee from one application.
        $this->postJson("/api/v1/registrations/{$request->uuid}/approve", [
            'branch_id' => $this->branch->uuid,
        ])->assertStatus(422);

        $this->assertSame(1, Trainee::count());
    }

    public function test_rejecting_records_a_reason_the_applicant_can_read(): void
    {
        $request = $this->submitted();

        Sanctum::actingAs($this->userWithRole('receptionist'));

        $this->postJson("/api/v1/registrations/{$request->uuid}/reject", [
            'reason' => 'الرقم الوطني غير مطابق للاسم.',
        ])->assertOk();

        $this->assertSame(0, Trainee::count());

        $this->getJson("/api/v1/public/registrations/{$request->reference}")
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.decision_reason', 'الرقم الوطني غير مطابق للاسم.');
    }

    public function test_a_rejection_needs_a_reason(): void
    {
        $request = $this->submitted();

        Sanctum::actingAs($this->userWithRole('receptionist'));

        $this->postJson("/api/v1/registrations/{$request->uuid}/reject", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');
    }

    public function test_a_supervisor_may_read_the_queue_but_not_decide(): void
    {
        $request = $this->submitted();

        // Reading applications is part of supervising training; letting someone
        // into the records is a reception/management decision.
        Sanctum::actingAs($this->userWithRole('training_supervisor'));

        $this->getJson('/api/v1/registrations')->assertOk();

        $this->postJson("/api/v1/registrations/{$request->uuid}/approve", [
            'branch_id' => $this->branch->uuid,
        ])->assertForbidden();
    }

    public function test_approval_without_a_branch_is_refused(): void
    {
        $code = $this->codeFor('0791234567');

        // Submitted without choosing a branch.
        $this->postJson('/api/v1/public/registrations', $this->form([
            'code' => $code,
            'branch_id' => null,
        ]))->assertCreated();

        $request = RegistrationRequest::firstOrFail();

        Sanctum::actingAs($this->userWithRole('receptionist'));

        $this->postJson("/api/v1/registrations/{$request->uuid}/approve", [])
            ->assertStatus(422);

        $this->assertSame(0, Trainee::count());
    }
}
