<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Trainee;
use App\Models\Trainer;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A pre-delivery sweep: every role against every surface.
 *
 * The other test files each pin one feature. This one asks the question an
 * auditor would ask before handover — for each role, does every page they should
 * reach actually open, and does every page they should not reach actually
 * refuse? Both halves matter: a permission that wrongly refuses makes staff work
 * around the system, and one that wrongly allows puts an accountant in a trainee's
 * evaluations or a receptionist in the profit report.
 *
 * Written as data rather than as one test per page, so adding a role or a screen
 * is one line and the sweep stays complete.
 */
class RoleScenarioAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFoundation();
    }

    /**
     * Dashboard pages and the permission each is meant to need.
     *
     * @return array<string, string>
     */
    protected function pages(): array
    {
        return [
            'admin.dashboard' => 'dashboard.view',
            'admin.trainees.index' => 'trainees.view',
            'admin.trainees.create' => 'trainees.create',
            'admin.trainers.index' => 'trainers.view',
            'admin.trainers.create' => 'trainers.create',
            'admin.employees.index' => 'employees.view',
            'admin.sessions.index' => 'appointments.view',
            'admin.calendar.index' => 'appointments.view',
            'admin.booking-requests.index' => 'booking_requests.manage',
            'admin.booking-requests.decisions' => 'booking_requests.manage',
            'admin.registrations.index' => 'registrations.view',
            'admin.packages.index' => 'packages.view',
            'admin.skills.index' => 'evaluations.view',
            'admin.payments.index' => 'payments.view',
            'admin.expenses.index' => 'expenses.view',
            'admin.payroll.index' => 'payroll.view',
            'admin.cashbox.index' => 'cashbox.view',
            'admin.profit.index' => 'profit.view',
            'admin.reports.index' => 'reports.view',
            'admin.trainer-compensation.index' => 'trainer_compensation.view',
            'admin.vehicles.index' => 'vehicles.view',
            'admin.maintenance.index' => 'vehicles.view',
            'admin.utilities.index' => 'utilities.manage',
            'admin.recurring-expenses.index' => 'recurring_expenses.manage',
            'admin.advances.index' => 'advances.manage',
            'admin.users.index' => 'users.manage',
            'admin.roles.index' => 'roles.manage',
            'admin.branches.index' => 'branches.manage',
            'admin.settings.edit' => 'settings.manage',
            'admin.audit-logs.index' => 'audit_logs.view',
        ];
    }

    /** Every dashboard role, plus the two app roles that must not reach it. */
    protected function roles(): array
    {
        return array_keys(Permissions::ROLE_LABELS);
    }

    protected function holds(string $role, string $permission): bool
    {
        $granted = Permissions::ROLE_DEFAULTS[$role] ?? [];

        // A system admin is defined as '*' rather than by listing every
        // permission, so it holds whatever is asked of it.
        return in_array('*', $granted, true) || in_array($permission, $granted, true);
    }

    /**
     * Every page, against every role, in both directions.
     *
     * A single test rather than one per pair: the failure message names the
     * exact role and route, and 180 separate test methods would hide that in
     * noise.
     */
    public function test_every_page_matches_the_permission_it_claims(): void
    {
        $failures = [];

        foreach ($this->roles() as $role) {
            $user = $this->userWithRole($role);

            foreach ($this->pages() as $route => $permission) {
                $this->actingAsUser($user->fresh());

                $status = $this->get(route($route))->getStatusCode();
                $allowed = $this->holds($role, $permission);

                if ($allowed && $status !== 200) {
                    $failures[] = "{$role} should reach {$route} but got {$status}";
                }

                if (! $allowed && $status === 200) {
                    $failures[] = "{$role} must NOT reach {$route} but it opened";
                }

                $this->flushSession();
                app('auth')->forgetGuards();
            }
        }

        $this->assertSame([], $failures, "\n".implode("\n", $failures)."\n");
    }

    /**
     * The money pages, checked by their own rule.
     *
     * Profit is the one figure the center treats as the owner's: a receptionist
     * and a supervisor run the place day to day and still must not see it.
     */
    public function test_only_the_money_roles_see_profit(): void
    {
        foreach (['receptionist', 'training_supervisor', 'trainer', 'trainee'] as $role) {
            $this->actingAsUser($this->userWithRole($role));

            $this->get(route('admin.profit.index'))->assertForbidden();

            $this->flushSession();
            app('auth')->forgetGuards();
        }

        foreach (['accountant', 'center_manager'] as $role) {
            $this->actingAsUser($this->userWithRole($role));

            $this->get(route('admin.profit.index'))->assertOk();

            $this->flushSession();
            app('auth')->forgetGuards();
        }
    }

    /**
     * And the API says the same thing.
     *
     * A permission enforced only in Blade would leave the figure one curl away,
     * which is the failure the center asked about by name.
     */
    public function test_the_api_refuses_profit_to_a_role_without_it(): void
    {
        foreach (['receptionist', 'training_supervisor', 'trainer'] as $role) {
            Sanctum::actingAs($this->userWithRole($role));

            $this->getJson('/api/v1/reports/profit')->assertForbidden();

            app('auth')->forgetGuards();
        }

        Sanctum::actingAs($this->userWithRole('accountant'));
        $this->getJson('/api/v1/reports/profit')->assertOk();
    }

    /**
     * An app account reaches nothing that runs the center.
     *
     * A trainee holds only `portal.view` and is shut out of every dashboard
     * screen. A trainer holds `dashboard.view` and `trainees.view` by design —
     * those are what their app reads — so the boundary for them is not "no
     * dashboard" but "nothing that administers anyone": no money, no users, no
     * settings, no audit trail. What they can open is scoped to themselves,
     * which is asserted separately.
     */
    public function test_app_accounts_reach_nothing_administrative(): void
    {
        $administrative = [
            'admin.payments.index',
            'admin.expenses.index',
            'admin.payroll.index',
            'admin.cashbox.index',
            'admin.profit.index',
            'admin.users.index',
            'admin.roles.index',
            'admin.branches.index',
            'admin.settings.edit',
            'admin.audit-logs.index',
            'admin.employees.index',
            'admin.trainer-compensation.index',
        ];

        foreach (['trainee', 'trainer'] as $role) {
            $this->actingAsUser($this->userWithRole($role));

            foreach ($administrative as $route) {
                $this->get(route($route))->assertForbidden();
            }

            $this->flushSession();
            app('auth')->forgetGuards();
        }

        // And a trainee reaches no dashboard page at all: their surface is the
        // portal and the app.
        $this->actingAsUser($this->userWithRole('trainee'));

        foreach (['admin.dashboard', 'admin.trainees.index', 'admin.sessions.index'] as $route) {
            $this->get(route($route))->assertForbidden();
        }
    }

    /**
     * A trainer on the dashboard sees their own trainees, not the branch's.
     *
     * The trainer role holds `trainees.view` so that their app can list the
     * people they teach. The dashboard read that as "every trainee at the
     * branch", so the same permission answered differently on two surfaces —
     * and the policy already refuses to *open* a colleague's trainee, so the
     * list was exposing names and numbers with nowhere to go.
     */
    public function test_a_trainer_sees_only_their_own_trainees_and_lessons(): void
    {
        $mine = Trainer::factory()->create(['branch_id' => $this->branch->id]);
        $theirs = Trainer::factory()->create(['branch_id' => $this->branch->id]);

        $trainerUser = $this->userWithRole('trainer');
        $mine->forceFill(['user_id' => $trainerUser->id])->save();

        $myTrainee = Trainee::factory()->create([
            'branch_id' => $this->branch->id,
            'trainer_id' => $mine->id,
            'full_name' => 'متدرب عندي',
        ]);

        $otherTrainee = Trainee::factory()->create([
            'branch_id' => $this->branch->id,
            'trainer_id' => $theirs->id,
            'full_name' => 'متدرب عند زميلي',
        ]);

        $this->actingAsUser($trainerUser->fresh());

        $this->get(route('admin.trainees.index'))
            ->assertOk()
            ->assertSee('متدرب عندي')
            ->assertDontSee('متدرب عند زميلي');

        // And opening the colleague's trainee directly is refused, as before.
        $this->get(route('admin.trainees.show', $otherTrainee))->assertForbidden();
        $this->get(route('admin.trainees.show', $myTrainee))->assertOk();

        // The same rule on the diary: the sessions list and the calendar.
        $mineLesson = $this->lessonFor($mine, $myTrainee);
        $theirsLesson = $this->lessonFor($theirs, $otherTrainee);

        $this->get(route('admin.sessions.index'))
            ->assertOk()
            ->assertSee($myTrainee->trainee_number)
            ->assertDontSee($otherTrainee->trainee_number);

        $this->get(route('admin.calendar.index', ['date' => $mineLesson->scheduled_date->toDateString()]))
            ->assertOk()
            ->assertDontSee($otherTrainee->trainee_number);

        $this->assertNotNull($theirsLesson);
    }

    /** A reception account still sees the whole branch, as it must. */
    public function test_reception_still_sees_everyone(): void
    {
        $trainer = Trainer::factory()->create(['branch_id' => $this->branch->id]);

        Trainee::factory()->create([
            'branch_id' => $this->branch->id,
            'trainer_id' => $trainer->id,
            'full_name' => 'متدرب الفرع',
        ]);

        $this->actingAsUser($this->userWithRole('receptionist'));

        $this->get(route('admin.trainees.index'))->assertOk()->assertSee('متدرب الفرع');
    }

    protected function lessonFor(Trainer $trainer, Trainee $trainee): \App\Models\TrainingSession
    {
        return \App\Models\TrainingSession::create([
            'branch_id' => $this->branch->id,
            'trainee_id' => $trainee->id,
            'trainer_id' => $trainer->id,
            'scheduled_date' => $this->workingDay(minimumOffset: 2)->toDateString(),
            'start_time' => '09:00',
            'end_time' => '09:45',
            'duration_minutes' => 45,
            'status' => 'scheduled',
        ]);
    }

    /**
     * Writes are checked too, not only the pages that list things.
     *
     * A role that cannot open a page but can still POST to its store route is
     * the classic half-protected screen.
     */
    public function test_a_read_only_role_cannot_write(): void
    {
        // A receptionist may read trainers but not create or edit them.
        $this->actingAsUser($this->userWithRole('receptionist'));

        $this->get(route('admin.trainers.index'))->assertOk();
        $this->get(route('admin.trainers.create'))->assertForbidden();

        $this->post(route('admin.trainers.store'), [
            'full_name' => 'محاولة',
            'phone' => '0790009999',
            'employment_date' => now()->subYear()->toDateString(),
            'status' => 'active',
            'branch_id' => $this->branch->id,
        ])->assertForbidden();

        $this->assertSame(0, Trainer::where('phone', '0790009999')->count());

        $this->flushSession();
        app('auth')->forgetGuards();

        // An accountant may read trainees but not create one.
        $this->actingAsUser($this->userWithRole('accountant'));

        $this->get(route('admin.trainees.index'))->assertOk();

        $this->post(route('admin.trainees.store'), [
            'full_name' => 'محاولة',
            'phone' => '0790008888',
            'license_type' => 'private',
            'registration_date' => now()->toDateString(),
            'branch_id' => $this->branch->id,
        ])->assertForbidden();

        $this->assertSame(0, Trainee::where('phone', '0790008888')->count());
    }

    /**
     * Issuing a login is not a back door into the users screen.
     *
     * The account panels sit on trainee, trainer and employee pages; a role that
     * may not administer those records must not reach them there either.
     */
    public function test_account_panels_follow_their_own_permission(): void
    {
        $trainee = Trainee::factory()->create(['branch_id' => $this->branch->id, 'phone' => '0790007777']);
        $trainer = Trainer::factory()->create(['branch_id' => $this->branch->id, 'phone' => '0790007778']);
        $employee = Employee::factory()->create(['branch_id' => $this->branch->id, 'phone' => '0790007779']);

        // An accountant administers money, not people.
        $this->actingAsUser($this->userWithRole('accountant'));

        $this->post(route('admin.trainees.account', $trainee))->assertForbidden();
        $this->post(route('admin.trainers.account', $trainer))->assertForbidden();
        $this->post(route('admin.employees.account', $employee))->assertForbidden();

        $this->assertNull($trainee->fresh()->user_id);
        $this->assertNull($trainer->fresh()->user_id);
        $this->assertNull($employee->fresh()->user_id);
    }

    /** Nobody but a super admin mints another super admin. */
    public function test_a_manager_cannot_create_a_super_admin(): void
    {
        $manager = $this->userWithRole('center_manager');

        $this->actingAsUser($manager);

        $this->post(route('admin.users.store'), [
            'name' => 'محاولة ترقية',
            'email' => 'escalate@markaz.test',
            'password' => 'Markaz!2026x',
            'password_confirmation' => 'Markaz!2026x',
            'branch_id' => $this->branch->id,
            'roles' => [\App\Models\Role::where('name', 'system_admin')->firstOrFail()->id],
            'status' => 'active',
            'can_access_all_branches' => '1',
        ]);

        $created = User::where('email', 'escalate@markaz.test')->first();

        if ($created) {
            $this->assertFalse(
                (bool) $created->can_access_all_branches,
                'a non-super-admin handed out all-branch access',
            );
            $this->assertFalse($created->isSuperAdmin(), 'a non-super-admin minted a super admin');
        }
    }
}
