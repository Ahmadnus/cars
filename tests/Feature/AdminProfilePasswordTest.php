<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * A dashboard user changing their own password.
 *
 * The screen existed and had no test, which is the same as not knowing whether
 * it works. What is pinned here is what an admin is actually relying on when
 * they use it: the new password works, the old one stops, the current password
 * is required so a walk-up at an unlocked desk cannot take the account, and app
 * sessions signed in with the old password are closed — an admin changing their
 * password because someone else knew it would otherwise leave that person's
 * phone signed in.
 */
class AdminProfilePasswordTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFoundation();
    }

    public function test_an_admin_changes_their_own_password(): void
    {
        $admin = $this->admin();

        $this->actingAsUser($admin);

        $this->patch(route('admin.profile.password'), [
            'current_password' => 'secret-password',
            'password' => 'Markaz!2026x',
            'password_confirmation' => 'Markaz!2026x',
        ])->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('Markaz!2026x', $admin->fresh()->password));

        // The new one signs in and the old one does not, which is the point.
        $this->flushSession();
        app('auth')->forgetGuards();

        $this->post(route('login.store'), [
            'email' => $admin->email,
            'password' => 'Markaz!2026x',
        ])->assertRedirect();

        $this->assertAuthenticatedAs($admin->fresh());

        $this->flushSession();
        app('auth')->forgetGuards();

        $this->post(route('login.store'), [
            'email' => $admin->email,
            'password' => 'secret-password',
        ])->assertSessionHasErrors('email');
    }

    /** They stay signed in here: changing a password is not signing out. */
    public function test_the_current_browser_session_survives(): void
    {
        $admin = $this->admin();

        $this->actingAsUser($admin);

        $this->patch(route('admin.profile.password'), [
            'current_password' => 'secret-password',
            'password' => 'Markaz!2026x',
            'password_confirmation' => 'Markaz!2026x',
        ])->assertSessionHasNoErrors();

        $this->get(route('admin.profile.edit'))->assertOk();
    }

    public function test_the_current_password_is_required(): void
    {
        $admin = $this->admin();

        $this->actingAsUser($admin);

        $this->patch(route('admin.profile.password'), [
            'current_password' => 'not-the-one',
            'password' => 'Markaz!2026x',
            'password_confirmation' => 'Markaz!2026x',
        ])->assertSessionHasErrors('current_password');

        $this->assertTrue(Hash::check('secret-password', $admin->fresh()->password));
    }

    public function test_the_new_password_must_be_confirmed_and_strong(): void
    {
        $admin = $this->admin();

        $this->actingAsUser($admin);

        // Mistyped confirmation.
        $this->patch(route('admin.profile.password'), [
            'current_password' => 'secret-password',
            'password' => 'Markaz!2026x',
            'password_confirmation' => 'Markaz!2026y',
        ])->assertSessionHasErrors('password');

        // A staff account reaches money and other people's files, so it keeps
        // the full policy — unlike a trainee's handed-out password.
        $this->patch(route('admin.profile.password'), [
            'current_password' => 'secret-password',
            'password' => 'abc123',
            'password_confirmation' => 'abc123',
        ])->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check('secret-password', $admin->fresh()->password));
    }

    /**
     * App sessions signed in with the old password are closed.
     *
     * The common reason to change a password is that someone else knew it.
     * Leaving their phone signed in would make the change mostly theatre.
     */
    public function test_app_tokens_are_revoked(): void
    {
        $admin = $this->admin();

        $token = $admin->createToken('phone')->plainTextToken;

        $this->actingAsUser($admin);

        $this->patch(route('admin.profile.password'), [
            'current_password' => 'secret-password',
            'password' => 'Markaz!2026x',
            'password_confirmation' => 'Markaz!2026x',
        ])->assertSessionHasNoErrors();

        $this->flushSession();
        app('auth')->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized();
    }

    /** The menu names it, so nobody has to guess it lives under "my profile". */
    public function test_the_menu_offers_it_by_name(): void
    {
        $this->actingAsUser($this->admin());

        $this->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('تغيير كلمة المرور');
    }

    /** Every dashboard user, not only an admin. */
    public function test_a_receptionist_can_change_their_own_too(): void
    {
        $receptionist = $this->userWithRole('receptionist');

        $this->actingAsUser($receptionist);

        $this->patch(route('admin.profile.password'), [
            'current_password' => 'secret-password',
            'password' => 'Markaz!2026x',
            'password_confirmation' => 'Markaz!2026x',
        ])->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('Markaz!2026x', $receptionist->fresh()->password));
    }
}
