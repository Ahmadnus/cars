<?php

namespace Tests\Feature;

use App\Models\Trainee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The portal is the only surface a trainee can reach, so what it refuses
 * matters as much as what it renders: a trainee must not be able to open the
 * admin dashboard, read another trainee's file, or reach any financial screen.
 */
class TraineePortalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFoundation();
    }

    protected function trainee(array $attributes = []): Trainee
    {
        return Trainee::factory()->create(array_merge(
            ['branch_id' => $this->branch->id],
            $attributes,
        ));
    }

    protected function traineeUser(Trainee $trainee): User
    {
        $user = $this->userWithRole('trainee');

        $trainee->forceFill(['user_id' => $user->id])->save();

        return $user->fresh();
    }

    public function test_a_trainee_sees_their_own_file(): void
    {
        $trainee = $this->trainee();
        $user = $this->traineeUser($trainee);

        $this->actingAsUser($user)
            ->get(route('portal.index'))
            ->assertOk()
            ->assertSee($trainee->full_name)
            ->assertSee($trainee->trainee_number);
    }

    public function test_the_portal_never_names_another_trainee(): void
    {
        $mine = $this->trainee();
        $other = $this->trainee(['full_name' => 'متدرب آخر لا يظهر']);

        $this->actingAsUser($this->traineeUser($mine))
            ->get(route('portal.index'))
            ->assertOk()
            ->assertDontSee($other->full_name)
            ->assertDontSee($other->trainee_number);
    }

    public function test_a_login_with_no_trainee_file_is_refused(): void
    {
        $this->actingAsUser($this->userWithRole('trainee'))
            ->get(route('portal.index'))
            ->assertForbidden();
    }

    public function test_a_trainee_cannot_reach_the_admin_dashboard(): void
    {
        $user = $this->traineeUser($this->trainee());

        $this->actingAsUser($user)->get(route('admin.dashboard'))->assertForbidden();
    }

    /** Money screens are the ones that would hurt most, so they are named. */
    public function test_a_trainee_cannot_reach_financial_screens(): void
    {
        $user = $this->traineeUser($this->trainee());

        foreach (['/payments', '/cashbox', '/profit'] as $path) {
            $this->actingAsUser($user)
                ->get($path)
                ->assertForbidden();
        }
    }

    public function test_a_trainee_cannot_reach_another_trainees_admin_page(): void
    {
        $other = $this->trainee();
        $user = $this->traineeUser($this->trainee());

        $this->actingAsUser($user)
            ->get('/trainees/'.$other->uuid)
            ->assertForbidden();
    }

    public function test_the_portal_requires_authentication(): void
    {
        $this->get(route('portal.index'))->assertRedirect(route('login'));
    }

    public function test_login_sends_a_trainee_to_the_portal_not_the_dashboard(): void
    {
        $user = $this->traineeUser($this->trainee());

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'secret-password',
        ])->assertRedirect(route('portal.index'));
    }

    public function test_login_still_sends_staff_to_the_dashboard(): void
    {
        $user = $this->userWithRole('center_manager');

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'secret-password',
        ])->assertRedirect(route('admin.dashboard'));
    }

    public function test_the_trainee_role_holds_no_sensitive_permission(): void
    {
        $user = $this->traineeUser($this->trainee());

        foreach ([
            'dashboard.view', 'trainees.view', 'trainees.financial',
            'payments.view', 'cashbox.view', 'profit.view', 'salaries.view',
            'appointments.view', 'reports.view',
        ] as $permission) {
            $this->assertFalse(
                $user->can($permission),
                "a trainee must not hold {$permission}",
            );
        }

        $this->assertTrue($user->can('portal.view'));
    }
}
