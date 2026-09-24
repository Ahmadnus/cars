<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Package;
use App\Models\Trainee;
use App\Services\PackageService;
use App\Services\PaymentService;
use App\Support\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Permission and branch boundaries.
 *
 * The product requirement is explicit that a receptionist must not see profit,
 * salaries or the cashbox, and that branch access is enforced on the server —
 * so those are asserted directly rather than trusted to the UI.
 */
class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFoundation();
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
        $this->get('/payments')->assertRedirect('/login');
    }

    public function test_a_receptionist_cannot_reach_financial_screens(): void
    {
        $this->actingAsUser($this->userWithRole('receptionist'));

        foreach (['/profit', '/cashbox', '/payroll', '/expenses', '/payments', '/trainer-compensation'] as $url) {
            $this->get($url)->assertForbidden();
        }
    }

    public function test_a_receptionist_can_reach_the_operational_screens(): void
    {
        $this->actingAsUser($this->userWithRole('receptionist'));

        foreach (['/dashboard', '/trainees', '/calendar', '/sessions', '/booking-requests'] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_the_dashboard_omits_financial_data_for_a_receptionist(): void
    {
        $this->actingAsUser($this->userWithRole('receptionist'));

        $response = $this->get('/dashboard')->assertOk();

        // The block is not rendered at all, so there is nothing to leak.
        $response->assertViewHas('data', function (array $data) {
            return ! array_key_exists('finance', $data)
                && ! array_key_exists('payroll_due', $data['pending'])
                && ! array_key_exists('trainer_compensation_due', $data['pending']);
        });
    }

    public function test_an_accountant_sees_financial_data_on_the_dashboard(): void
    {
        $this->actingAsUser($this->userWithRole('accountant'));

        $this->get('/dashboard')->assertOk()->assertViewHas('data', function (array $data) {
            return array_key_exists('finance', $data)
                && array_key_exists('net_month', $data['finance']);
        });
    }

    public function test_an_accountant_cannot_manage_users_or_roles(): void
    {
        $this->actingAsUser($this->userWithRole('accountant'));

        $this->get('/users')->assertForbidden();
        $this->get('/roles')->assertForbidden();
    }

    public function test_a_trainer_cannot_open_the_trainee_list_of_another_branch(): void
    {
        $other = Branch::factory()->create(['organization_id' => $this->branch->organization_id]);
        $foreign = Trainee::factory()->create(['branch_id' => $other->id]);

        $this->actingAsUser($this->userWithRole('training_supervisor'));

        $this->get('/trainees/'.$foreign->uuid)->assertForbidden();
    }

    public function test_branch_scoping_hides_other_branches_from_lists(): void
    {
        $other = Branch::factory()->create(['organization_id' => $this->branch->organization_id]);

        $mine = Trainee::factory()->create(['branch_id' => $this->branch->id, 'full_name' => 'متدرب فرعي']);
        $theirs = Trainee::factory()->create(['branch_id' => $other->id, 'full_name' => 'متدرب فرع آخر']);

        $this->actingAsUser($this->userWithRole('receptionist'));

        $this->get('/trainees')
            ->assertOk()
            ->assertSee($mine->full_name)
            ->assertDontSee($theirs->full_name);
    }

    public function test_the_branch_selector_rejects_a_branch_the_user_cannot_access(): void
    {
        $other = Branch::factory()->create(['organization_id' => $this->branch->organization_id]);
        $user = $this->userWithRole('receptionist');

        $this->actingAsUser($user);

        $accepted = app(BranchContext::class)->set($other->id, $user);

        $this->assertFalse($accepted);
        $this->assertNotSame($other->id, app(BranchContext::class)->currentId());
    }

    public function test_a_super_admin_bypasses_permission_checks(): void
    {
        $this->actingAsUser($this->admin());

        foreach (['/dashboard', '/profit', '/cashbox', '/users', '/roles', '/settings', '/audit-logs'] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_a_deactivated_user_is_logged_out(): void
    {
        $user = $this->userWithRole('center_manager');
        $this->actingAsUser($user);

        $this->get('/dashboard')->assertOk();

        $user->update(['status' => 'inactive']);

        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_a_per_user_revocation_overrides_a_role_grant(): void
    {
        $user = $this->userWithRole('accountant');
        $this->assertTrue($user->hasPermission('profit.view'));

        $permission = \App\Models\Permission::where('name', 'profit.view')->firstOrFail();
        $user->permissionOverrides()->sync([$permission->id => ['granted' => false]]);
        $user->forgetPermissionCache();

        $this->assertFalse($user->fresh()->hasPermission('profit.view'));

        $this->actingAsUser($user->fresh());
        $this->get('/profit')->assertForbidden();
    }

    public function test_a_per_user_grant_adds_a_permission_the_role_lacks(): void
    {
        $user = $this->userWithRole('receptionist');
        $this->assertFalse($user->hasPermission('profit.view'));

        $permission = \App\Models\Permission::where('name', 'profit.view')->firstOrFail();
        $user->permissionOverrides()->sync([$permission->id => ['granted' => true]]);
        $user->forgetPermissionCache();

        $this->actingAsUser($user->fresh());
        $this->get('/profit')->assertOk();
    }

    public function test_a_receptionist_cannot_void_a_payment_through_the_route(): void
    {
        $trainee = Trainee::factory()->create(['branch_id' => $this->branch->id]);

        $this->actingAsUser($this->admin());
        $enrolment = app(PackageService::class)->assign($trainee, Package::factory()->create());

        $payment = app(PaymentService::class)->record([
            'trainee_id' => $trainee->id,
            'trainee_package_id' => $enrolment->id,
            'payment_method_id' => \App\Models\PaymentMethod::where('code', 'cash')->value('id'),
            'amount' => 50,
            'paid_on' => now()->toDateString(),
        ], $this->branch->id);

        $this->actingAsUser($this->userWithRole('receptionist'));

        $this->post("/payments/{$payment->uuid}/void", ['reason' => 'محاولة'])->assertForbidden();
        $this->assertSame('completed', $payment->fresh()->status);
    }
}
