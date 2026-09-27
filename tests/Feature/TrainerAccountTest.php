<?php

namespace Tests\Feature;

use App\Models\Trainer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The office issuing a trainer their app login.
 *
 * Trainers never register: a trainer profile is a staff record with other
 * people's training files behind it, so the administration creates the account
 * and reads the password out. These tests pin the two things that make that
 * safe — the account really works on the app, and issuing one is not a way to
 * mint any other kind of user.
 */
class TrainerAccountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFoundation();
    }

    protected function trainer(array $attributes = []): Trainer
    {
        return Trainer::factory()->create(array_merge([
            'branch_id' => $this->branch->id,
            'phone' => '0790001122',
        ], $attributes));
    }

    public function test_the_office_issues_a_login_the_trainer_can_actually_use(): void
    {
        $trainer = $this->trainer();

        $this->actingAsUser($this->admin());

        $response = $this->post(route('admin.trainers.account', $trainer));

        $response->assertSessionHasNoErrors();

        $credentials = session('issued_credentials');

        $this->assertNotNull($credentials, 'the password is shown once');
        $this->assertSame('0790001122', $credentials['phone']);

        $trainer->refresh();
        $this->assertNotNull($trainer->user_id, 'the profile is linked to the login');

        $user = $trainer->user;

        $this->assertTrue($user->hasRole('trainer'), 'without the role every screen would be forbidden');
        $this->assertSame('active', $user->status);
        $this->assertTrue(Hash::check($credentials['password'], $user->password));

        // The point of the whole thing: it signs in on the app, and the trainer
        // app resolves a trainer from it.
        $login = $this->postJson('/api/v1/auth/login', [
            'phone' => '0790001122',
            'password' => $credentials['password'],
            'device_name' => 'Trainer app test',
        ])->assertOk();

        $token = $login->json('data.token');
        $this->assertNotNull($token);

        // The office's own session is still open in this test; drop it so the
        // bearer token is what authenticates, as it is from the app.
        $this->flushSession();
        app('auth')->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/me/decisions')
            ->assertOk();
    }

    public function test_a_new_trainer_can_be_created_with_a_login_in_one_step(): void
    {
        $this->actingAsUser($this->admin());

        $this->post(route('admin.trainers.store'), [
            'full_name' => 'عمر الزعبي',
            'phone' => '0790003344',
            'employment_date' => now()->subYear()->toDateString(),
            'status' => 'active',
            'branch_id' => $this->branch->id,
            'create_login' => '1',
        ])->assertSessionHasNoErrors();

        $trainer = Trainer::where('phone', '0790003344')->firstOrFail();

        $this->assertNotNull($trainer->user_id);
        $this->assertNotNull(session('issued_credentials'));
    }

    /** Without the box ticked, no account is created behind the office's back. */
    public function test_a_trainer_can_be_created_without_a_login(): void
    {
        $this->actingAsUser($this->admin());

        $this->post(route('admin.trainers.store'), [
            'full_name' => 'سامر القيسي',
            'phone' => '0790005566',
            'employment_date' => now()->subYear()->toDateString(),
            'status' => 'active',
            'branch_id' => $this->branch->id,
        ])->assertSessionHasNoErrors();

        $this->assertNull(Trainer::where('phone', '0790005566')->firstOrFail()->user_id);
    }

    /**
     * Resetting replaces the password rather than creating a second account.
     *
     * A trainer who lost their password is the common case, and a second user
     * row would leave the app authenticating against the wrong one.
     */
    public function test_resetting_replaces_the_password_on_the_same_account(): void
    {
        $trainer = $this->trainer();

        $this->actingAsUser($this->admin());

        $this->post(route('admin.trainers.account', $trainer));
        $first = session('issued_credentials')['password'];

        $userId = $trainer->fresh()->user_id;

        $this->post(route('admin.trainers.account', $trainer));
        $second = session('issued_credentials')['password'];

        $this->assertNotSame($first, $second);
        $this->assertSame($userId, $trainer->fresh()->user_id, 'the same login, a new password');
        $this->assertSame(1, User::where('phone', '0790001122')->count());

        // The old one stops working, which is the reason for resetting it.
        $this->postJson('/api/v1/auth/login', [
            'phone' => '0790001122',
            'password' => $first,
            'device_name' => 'Trainer app test',
        ])->assertStatus(401);

        $this->postJson('/api/v1/auth/login', [
            'phone' => '0790001122',
            'password' => $second,
            'device_name' => 'Trainer app test',
        ])->assertOk();
    }

    /** A number already signing someone in is named, not silently collided with. */
    public function test_a_phone_already_used_by_another_account_is_refused(): void
    {
        $existing = $this->userWithRole('receptionist', ['phone' => '0790001122']);
        $trainer = $this->trainer();

        $this->actingAsUser($this->admin());

        $this->post(route('admin.trainers.account', $trainer))->assertSessionHasErrors();

        $this->assertNull($trainer->fresh()->user_id);
        $this->assertSame(1, User::where('phone', '0790001122')->count());
        $this->assertSame($existing->id, User::where('phone', '0790001122')->first()->id);
    }

    /** Suspension closes the door but keeps the records. */
    public function test_suspending_the_account_keeps_the_trainer_record(): void
    {
        $trainer = $this->trainer();

        $this->actingAsUser($this->admin());

        $this->post(route('admin.trainers.account', $trainer));
        $password = session('issued_credentials')['password'];

        $this->post(route('admin.trainers.account.suspend', $trainer))->assertSessionHasNoErrors();

        $trainer->refresh();

        $this->assertNotNull($trainer->user_id, 'the profile still names who trained whom');
        $this->assertSame('suspended', $trainer->user->status);

        $this->postJson('/api/v1/auth/login', [
            'phone' => '0790001122',
            'password' => $password,
            'device_name' => 'Trainer app test',
            // A disabled account is refused as disabled, not as a wrong
            // password: the trainer needs to know to call the office.
        ])->assertStatus(403);
    }

    /** Issuing a login is a trainers permission, not an open door to Users. */
    public function test_a_receptionist_without_the_permission_cannot_issue_one(): void
    {
        $trainer = $this->trainer();

        // A receptionist may read trainers but not administer them.
        $this->actingAsUser($this->userWithRole('receptionist'));

        $this->post(route('admin.trainers.account', $trainer))->assertForbidden();

        $this->assertNull($trainer->fresh()->user_id);
    }
}
