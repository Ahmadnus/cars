<?php

namespace Tests\Feature;

use App\Models\OtpCode;
use App\Models\Trainee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Phone + passcode login, the Trainee app's only way in.
 *
 * The cases that matter here are the ones that would let someone in without
 * the code: guessing it, replaying a spent one, or falling back to a password
 * the trainee is not supposed to have.
 */
class OtpLoginTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFoundation();

        config()->set('otp.enable_fixed_codes', false);
        config()->set('otp.expose_code', true);

        $this->user = $this->userWithRole('trainee', ['phone' => '0791234567']);
        Trainee::factory()->create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->user->id,
        ]);
    }

    protected function requestCode(string $phone = '0791234567'): string
    {
        $response = $this->postJson('/api/v1/auth/otp/request', ['phone' => $phone]);
        $response->assertOk();

        return $response->json('data.debug_code');
    }

    public function test_a_passcode_logs_a_trainee_in(): void
    {
        $code = $this->requestCode();

        $this->postJson('/api/v1/auth/otp/verify', [
            'phone' => '0791234567',
            'code' => $code,
            'device_name' => 'iphone',
        ])->assertOk()->assertJsonPath('success', true)->assertJsonStructure(['data' => ['token', 'user']]);
    }

    public function test_the_phone_number_may_be_written_in_international_form(): void
    {
        $code = $this->requestCode('+962 79 123 4567');

        $this->postJson('/api/v1/auth/otp/verify', [
            'phone' => '00962791234567',
            'code' => $code,
            'device_name' => 'iphone',
        ])->assertOk();
    }

    public function test_a_wrong_passcode_is_rejected_and_burns_an_attempt(): void
    {
        $this->requestCode();

        $this->postJson('/api/v1/auth/otp/verify', [
            'phone' => '0791234567',
            'code' => '000000',
            'device_name' => 'iphone',
        ])->assertStatus(401);

        $this->assertSame(1, OtpCode::latest('id')->first()->attempts);
    }

    public function test_a_passcode_cannot_be_used_twice(): void
    {
        $code = $this->requestCode();
        $payload = ['phone' => '0791234567', 'code' => $code, 'device_name' => 'iphone'];

        $this->postJson('/api/v1/auth/otp/verify', $payload)->assertOk();
        $this->postJson('/api/v1/auth/otp/verify', $payload)->assertStatus(401);
    }

    public function test_requesting_a_new_passcode_retires_the_previous_one(): void
    {
        $first = $this->requestCode();

        // The resend cooldown is a separate brake; this test is about the
        // older code, so wind the clock past it rather than disabling it.
        $this->travel(2)->minutes();
        $this->requestCode();

        $this->postJson('/api/v1/auth/otp/verify', [
            'phone' => '0791234567',
            'code' => $first,
            'device_name' => 'iphone',
        ])->assertStatus(401);
    }

    public function test_an_expired_passcode_is_rejected(): void
    {
        $code = $this->requestCode();

        $this->travel(config('otp.ttl_minutes') + 1)->minutes();

        $this->postJson('/api/v1/auth/otp/verify', [
            'phone' => '0791234567',
            'code' => $code,
            'device_name' => 'iphone',
        ])->assertStatus(401);
    }

    public function test_an_unknown_number_gets_the_same_answer_but_no_passcode(): void
    {
        $this->postJson('/api/v1/auth/otp/request', ['phone' => '0799999999'])
            ->assertOk()
            ->assertJsonPath('data.debug_code', null);

        $this->assertDatabaseCount('otp_codes', 0);
    }

    public function test_a_second_passcode_cannot_be_requested_immediately(): void
    {
        $this->requestCode();

        $this->postJson('/api/v1/auth/otp/request', ['phone' => '0791234567'])
            ->assertStatus(429);
    }

    /**
     * A trainee may still use a password.
     *
     * Passcode-only is the stronger design and the intended destination, but the
     * Trainee app has no passcode screen yet and the credentials already issued
     * to trainees are passwords — so closing this door now would lock every
     * existing trainee out. This test pins the current behaviour so the change
     * is a deliberate one when the screen lands, not a silent regression.
     */
    public function test_a_trainee_can_still_sign_in_with_a_password_for_now(): void
    {
        $this->user->forceFill(['password' => Hash::make('secret-password')])->save();

        $this->postJson('/api/v1/auth/login', [
            'phone' => '0791234567',
            'password' => 'secret-password',
            'device_name' => 'iphone',
        ])->assertOk();
    }

    public function test_staff_can_still_sign_in_with_a_password(): void
    {
        $staff = $this->userWithRole('receptionist', ['phone' => '0787654321']);

        $this->postJson('/api/v1/auth/login', [
            'phone' => $staff->phone,
            'password' => 'secret-password',
            'device_name' => 'desktop',
        ])->assertOk();
    }

    public function test_a_fixed_demo_passcode_signs_the_matching_account_in(): void
    {
        config()->set('otp.enable_fixed_codes', true);
        config()->set('otp.fixed_codes', ['0791234567' => '424242']);

        $this->postJson('/api/v1/auth/otp/request', ['phone' => '0791234567'])->assertOk();

        $this->postJson('/api/v1/auth/otp/verify', [
            'phone' => '0791234567',
            'code' => '424242',
            'device_name' => 'iphone',
        ])->assertOk();
    }

    /**
     * The guard that makes fixed passcodes safe to ship.
     *
     * A passcode that never changes is a standing credential. It is acceptable
     * on a demo trainee; on an account that can reach money, salaries or private
     * conversations it is a permanent way in. The service refuses it there no
     * matter how the environment is configured — which is what lets the feature
     * exist at all.
     */
    public function test_a_fixed_passcode_is_refused_for_a_privileged_account(): void
    {
        $manager = $this->userWithRole('center_manager', ['phone' => '0796000001']);

        config()->set('otp.enable_fixed_codes', true);
        config()->set('otp.fixed_codes', ['0796000001' => '424242']);
        config()->set('otp.expose_code', false);

        $this->postJson('/api/v1/auth/otp/request', ['phone' => '0796000001'])->assertOk();

        // The demo code does not work — a rejected passcode is a 401.
        $this->postJson('/api/v1/auth/otp/verify', [
            'phone' => '0796000001',
            'code' => '424242',
            'device_name' => 'iphone',
        ])->assertStatus(401);

        // …and a real random code was issued instead, so the account is still
        // reachable by its owner through the normal flow.
        $this->assertDatabaseHas('otp_codes', ['phone' => '0796000001']);
        $this->assertNotNull($manager->fresh());
    }

    /** A super admin is refused even without any named permission. */
    public function test_a_fixed_passcode_is_refused_for_a_super_admin(): void
    {
        $this->admin()->forceFill(['phone' => '0796000002'])->save();

        config()->set('otp.enable_fixed_codes', true);
        config()->set('otp.fixed_codes', ['0796000002' => '111111']);
        config()->set('otp.expose_code', false);

        $this->postJson('/api/v1/auth/otp/request', ['phone' => '0796000002'])->assertOk();

        $this->postJson('/api/v1/auth/otp/verify', [
            'phone' => '0796000002',
            'code' => '111111',
            'device_name' => 'iphone',
        ])->assertStatus(401);
    }

    /** A trainer holds no sensitive permission, so a demo code is allowed. */
    public function test_a_fixed_passcode_works_for_a_demo_trainer(): void
    {
        $this->userWithRole('trainer', ['phone' => '0796000003']);

        config()->set('otp.enable_fixed_codes', true);
        config()->set('otp.fixed_codes', ['0796000003' => '666666']);
        config()->set('otp.expose_code', false);

        $this->postJson('/api/v1/auth/otp/request', ['phone' => '0796000003'])->assertOk();

        $this->postJson('/api/v1/auth/otp/verify', [
            'phone' => '0796000003',
            'code' => '666666',
            'device_name' => 'android',
        ])->assertOk();
    }

    public function test_a_disabled_account_cannot_sign_in(): void
    {
        $code = $this->requestCode();
        $this->user->forceFill(['status' => 'inactive'])->save();

        $this->postJson('/api/v1/auth/otp/verify', [
            'phone' => '0791234567',
            'code' => $code,
            'device_name' => 'iphone',
        ])->assertStatus(403);
    }
}
