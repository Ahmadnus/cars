<?php

namespace Tests\Feature;

use App\Models\Trainee;
use App\Models\Trainer;
use App\Models\User;
use App\Notifications\SystemNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Changing a password from the apps, and asking the office for a new one.
 *
 * There is no automatic reset in this system and that is a decision, not a gap:
 * these accounts sign in by phone, the email on a phone-only account is one the
 * office invented, and there is no SMS gateway. So "I forgot it" becomes a job
 * for staff, handed over on the same channel that established who the person is.
 *
 * What is worth pinning: a trainee can change their own password and is not held
 * to a policy they cannot type; the old password stops working and other devices
 * are signed out; and the reset request cannot be used to find out who trains
 * here.
 */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFoundation();
        $this->settings()->set('notifications.enable_database', true);
    }

    protected function settings(): \App\Services\SettingsRepository
    {
        return app(\App\Services\SettingsRepository::class);
    }

    /** A trainee with a login, as the office would have issued it. */
    protected function traineeAccount(string $phone = '0791110021'): Trainee
    {
        $trainee = Trainee::factory()->create([
            'branch_id' => $this->branch->id,
            'phone' => $phone,
        ]);

        $this->actingAsUser($this->admin());

        $this->post(route('admin.trainees.account', $trainee), [
            'login_password' => 'Kj7mN2pQ',
            'login_password_confirmation' => 'Kj7mN2pQ',
        ])->assertSessionHasNoErrors();

        $this->flushSession();
        app('auth')->forgetGuards();

        return $trainee->fresh();
    }

    // ------------------------------------------------------ changing your own

    public function test_a_trainee_can_change_their_own_password(): void
    {
        $trainee = $this->traineeAccount();

        Sanctum::actingAs($trainee->user);

        $this->postJson('/api/v1/auth/change-password', [
            'current_password' => 'Kj7mN2pQ',
            'password' => 'newpass22',
            'password_confirmation' => 'newpass22',
        ])->assertOk();

        $this->assertTrue(Hash::check('newpass22', $trainee->user->fresh()->password));

        app('auth')->forgetGuards();

        // The new one works and the old one does not, which is the whole point.
        $this->postJson('/api/v1/auth/login', [
            'phone' => $trainee->phone,
            'password' => 'newpass22',
            'device_name' => 'app',
        ])->assertOk();

        $this->postJson('/api/v1/auth/login', [
            'phone' => $trainee->phone,
            'password' => 'Kj7mN2pQ',
            'device_name' => 'app',
        ])->assertStatus(401);
    }

    public function test_the_current_password_is_required_to_change_it(): void
    {
        $trainee = $this->traineeAccount('0791110022');

        Sanctum::actingAs($trainee->user);

        $this->postJson('/api/v1/auth/change-password', [
            'current_password' => 'not-it',
            'password' => 'newpass22',
            'password_confirmation' => 'newpass22',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('current_password');

        $this->assertTrue(Hash::check('Kj7mN2pQ', $trainee->user->fresh()->password));
    }

    /**
     * A trainee is held to the handed-out policy, not the staff one.
     *
     * Six alphanumeric characters pass here and would fail Password::defaults();
     * that is deliberate, because a trainee who cannot type their password
     * writes it down instead.
     */
    public function test_a_trainee_may_choose_a_simple_password(): void
    {
        $trainee = $this->traineeAccount('0791110023');

        Sanctum::actingAs($trainee->user);

        $this->postJson('/api/v1/auth/change-password', [
            'current_password' => 'Kj7mN2pQ',
            'password' => 'abc123',
            'password_confirmation' => 'abc123',
        ])->assertOk();

        $this->assertTrue(Hash::check('abc123', $trainee->user->fresh()->password));
    }

    public function test_a_trainee_password_still_has_a_floor(): void
    {
        $trainee = $this->traineeAccount('0791110024');

        Sanctum::actingAs($trainee->user);

        foreach (['abc', '12345', 'has space', 'كلمةسر'] as $rejected) {
            $this->postJson('/api/v1/auth/change-password', [
                'current_password' => 'Kj7mN2pQ',
                'password' => $rejected,
                'password_confirmation' => $rejected,
            ])->assertStatus(422)->assertJsonValidationErrors('password');
        }

        $this->assertTrue(Hash::check('Kj7mN2pQ', $trainee->user->fresh()->password));
    }

    /** Staff keep the full policy: they reach money and other people's files. */
    public function test_a_staff_account_keeps_the_strict_policy(): void
    {
        $accountant = $this->userWithRole('accountant');

        Sanctum::actingAs($accountant);

        $this->postJson('/api/v1/auth/change-password', [
            'current_password' => 'secret-password',
            'password' => 'abc123',
            'password_confirmation' => 'abc123',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    /** A changed password logs the other devices out. */
    public function test_other_devices_are_signed_out(): void
    {
        $trainee = $this->traineeAccount('0791110025');
        $user = $trainee->user;

        $otherDevice = $user->createToken('old phone')->plainTextToken;

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/auth/change-password', [
            'current_password' => 'Kj7mN2pQ',
            'password' => 'newpass22',
            'password_confirmation' => 'newpass22',
        ])->assertOk();

        app('auth')->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$otherDevice)
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized();
    }

    // -------------------------------------------------- asking for a new one

    public function test_asking_for_a_reset_tells_the_staff_who_can_do_it(): void
    {
        $trainee = $this->traineeAccount('0791110026');

        Notification::fake();

        $receptionist = $this->userWithRole('receptionist');

        // An accountant cannot edit trainee records, so is not pulled in.
        $accountant = $this->userWithRole('accountant');

        app('auth')->forgetGuards();

        $this->postJson('/api/v1/auth/password/reset-request', [
            'phone' => $trainee->phone,
        ])->assertOk();

        Notification::assertSentTo($receptionist, function (SystemNotification $notification) use ($trainee) {
            return $notification->type === 'account.password_reset_requested'
                && str_contains($notification->body, $trainee->full_name);
        });

        Notification::assertNotSentTo($accountant, SystemNotification::class);
    }

    public function test_a_trainer_asking_reaches_whoever_administers_trainers(): void
    {
        $trainer = Trainer::factory()->create([
            'branch_id' => $this->branch->id,
            'phone' => '0791110027',
        ]);

        $this->actingAsUser($this->admin());
        $this->post(route('admin.trainers.account', $trainer))->assertSessionHasNoErrors();
        $this->flushSession();
        app('auth')->forgetGuards();

        Notification::fake();

        $manager = $this->userWithRole('center_manager');

        app('auth')->forgetGuards();

        $this->postJson('/api/v1/auth/password/reset-request', [
            'phone' => '0791110027',
        ])->assertOk();

        Notification::assertSentTo($manager, function (SystemNotification $notification) {
            return $notification->type === 'account.password_reset_requested'
                && str_contains($notification->body, 'مدرب');
        });
    }

    /**
     * An unknown number gets the same answer as a known one.
     *
     * Otherwise the screen becomes a way to ask "does this person train here",
     * which is exactly what a stranger with a phone book would do.
     */
    public function test_an_unknown_number_is_answered_identically(): void
    {
        $trainee = $this->traineeAccount('0791110028');

        Notification::fake();
        $receptionist = $this->userWithRole('receptionist');

        app('auth')->forgetGuards();

        // The unknown number first, so "nothing was raised" is unambiguous: a
        // known request legitimately reaches every member of staff who may act,
        // so counting totals afterwards would prove nothing.
        $unknown = $this->postJson('/api/v1/auth/password/reset-request', [
            'phone' => '0799999998',
        ])->assertOk();

        Notification::assertNothingSent();

        $known = $this->postJson('/api/v1/auth/password/reset-request', [
            'phone' => $trainee->phone,
        ])->assertOk();

        $this->assertSame($known->json('message'), $unknown->json('message'));

        Notification::assertSentTo($receptionist, SystemNotification::class);
    }

    /** Nothing is reset by asking: only a member of staff can do that. */
    public function test_asking_does_not_change_the_password(): void
    {
        $trainee = $this->traineeAccount('0791110029');
        $before = $trainee->user->password;

        app('auth')->forgetGuards();

        $this->postJson('/api/v1/auth/password/reset-request', [
            'phone' => $trainee->phone,
        ])->assertOk();

        $this->assertSame($before, $trainee->user->fresh()->password);

        // The old password still works — nobody has been locked out by a
        // stranger typing their number.
        $this->postJson('/api/v1/auth/login', [
            'phone' => $trainee->phone,
            'password' => 'Kj7mN2pQ',
            'device_name' => 'app',
        ])->assertOk();
    }

    public function test_the_phone_is_required(): void
    {
        $this->postJson('/api/v1/auth/password/reset-request', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');
    }

    /** The office resetting it is what actually ends the lockout. */
    public function test_a_staff_reset_issues_a_password_that_works(): void
    {
        $trainee = $this->traineeAccount('0791110030');

        $this->actingAsUser($this->admin());

        $this->post(route('admin.trainees.account', $trainee))->assertSessionHasNoErrors();

        $issued = session('issued_credentials')['password'];

        $this->flushSession();
        app('auth')->forgetGuards();

        $this->postJson('/api/v1/auth/login', [
            'phone' => $trainee->phone,
            'password' => $issued,
            'device_name' => 'app',
        ])->assertOk();

        // And the one the trainee had forgotten is dead.
        $this->postJson('/api/v1/auth/login', [
            'phone' => $trainee->phone,
            'password' => 'Kj7mN2pQ',
            'device_name' => 'app',
        ])->assertStatus(401);
    }

    /** A suspended account cannot be revived by asking for a password. */
    public function test_a_suspended_account_stays_suspended(): void
    {
        $trainee = $this->traineeAccount('0791110031');

        $this->actingAsUser($this->admin());
        $this->post(route('admin.trainees.account.suspend', $trainee))->assertSessionHasNoErrors();
        $this->flushSession();
        app('auth')->forgetGuards();

        $this->postJson('/api/v1/auth/password/reset-request', [
            'phone' => $trainee->phone,
        ])->assertOk();

        $this->assertSame('suspended', User::findOrFail($trainee->user_id)->status);
    }
}
