<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Role;
use App\Models\Trainee;
use App\Models\Trainer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The office issuing a login, with a password it chooses.
 *
 * Trainees, trainers and employees never register: a member of staff creates the
 * account from the person's page and reads the password out. What these tests
 * protect is that the office's typed password is honoured and still has to be a
 * real password, that the login works by phone *and* by email, and that issuing
 * one from a trainee page cannot hand out staff permissions.
 */
class AccountIssuingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFoundation();
    }

    /** Leave the office's session behind: the login route is for guests. */
    protected function signOut(): void
    {
        $this->flushSession();
        app('auth')->forgetGuards();
    }

    protected function roleId(string $name): int
    {
        return Role::where('name', $name)->firstOrFail()->id;
    }

    // ------------------------------------------------------------------ typing

    public function test_the_office_can_set_the_password_itself(): void
    {
        $trainee = Trainee::factory()->create([
            'branch_id' => $this->branch->id,
            'phone' => '0791110001',
        ]);

        $this->actingAsUser($this->admin());

        $this->post(route('admin.trainees.account', $trainee), [
            'login_password' => 'Markaz26',
            'login_password_confirmation' => 'Markaz26',
        ])->assertSessionHasNoErrors();

        $trainee->refresh();

        $this->assertNotNull($trainee->user_id);
        $this->assertTrue(Hash::check('Markaz26', $trainee->user->password));

        // What was typed is what is shown back, so the receptionist reads out
        // the password they just agreed with the trainee on the phone.
        $this->assertSame('Markaz26', session('issued_credentials')['password']);
    }

    public function test_a_typed_password_must_be_confirmed(): void
    {
        $trainee = Trainee::factory()->create([
            'branch_id' => $this->branch->id,
            'phone' => '0791110002',
        ]);

        $this->actingAsUser($this->admin());

        $this->post(route('admin.trainees.account', $trainee), [
            'login_password' => 'Markaz26',
            'login_password_confirmation' => 'Markaz25',
        ])->assertSessionHasErrors('login_password');

        $this->assertNull($trainee->fresh()->user_id);
    }

    /**
     * Letters or digits, six to eight — what the center asked for.
     *
     * These are dictated over the phone, so the policy is deliberately looser
     * than a staff password (see App\Support\IssuedPassword). It is still a
     * policy: too short, too long, or with characters nobody can dictate is
     * refused.
     */
    public function test_the_password_must_match_the_issued_policy(): void
    {
        $trainee = Trainee::factory()->create([
            'branch_id' => $this->branch->id,
            'phone' => '0791110003',
        ]);

        $this->actingAsUser($this->admin());

        foreach (['12345', 'abc123456789', 'pass word', 'كلمةسر123', 'Mark!26'] as $rejected) {
            $this->post(route('admin.trainees.account', $trainee), [
                'login_password' => $rejected,
                'login_password_confirmation' => $rejected,
            ])->assertSessionHasErrors('login_password');
        }

        $this->assertNull($trainee->fresh()->user_id);

        // Six digits, eight digits, letters only and a mix all pass.
        foreach (['123456', '12345678', 'abcdefgh', 'Kj7mN2pQ'] as $accepted) {
            $this->post(route('admin.trainees.account', $trainee), [
                'login_password' => $accepted,
                'login_password_confirmation' => $accepted,
            ])->assertSessionHasNoErrors();
        }
    }

    /** What the system generates passes its own rules. */
    public function test_a_generated_password_is_dictatable(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $password = \App\Support\IssuedPassword::generate();

            $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{6,8}$/', $password);

            // No 0/O or 1/l/I: those are what get misheard on a phone line.
            $this->assertDoesNotMatchRegularExpression('/[0O1lI]/', $password);

            // Both letters and digits, so it is not a guessable word.
            $this->assertMatchesRegularExpression('/[A-Za-z]/', $password);
            $this->assertMatchesRegularExpression('/[0-9]/', $password);
        }
    }

    /** Left blank, one is generated — the office does not have to invent one. */
    public function test_a_blank_password_is_generated(): void
    {
        $trainee = Trainee::factory()->create([
            'branch_id' => $this->branch->id,
            'phone' => '0791110004',
        ]);

        $this->actingAsUser($this->admin());

        $this->post(route('admin.trainees.account', $trainee))->assertSessionHasNoErrors();

        $password = session('issued_credentials')['password'];

        $this->assertNotEmpty($password);
        $this->assertTrue(Hash::check($password, $trainee->fresh()->user->password));
    }

    // ------------------------------------------------------------ signing in

    public function test_the_issued_account_signs_in_by_phone_and_by_email(): void
    {
        $employee = Employee::factory()->create([
            'branch_id' => $this->branch->id,
            'phone' => '0791110005',
        ]);

        $this->actingAsUser($this->admin());

        $this->post(route('admin.employees.account', $employee), [
            'login_email' => 'reception@markaz.test',
            'login_password' => 'Markaz26',
            'login_password_confirmation' => 'Markaz26',
            'login_roles' => [$this->roleId('receptionist')],
        ])->assertSessionHasNoErrors();

        $this->assertNotNull($employee->fresh()->user_id);

        // By phone, which is what the office reads out. The office's own session
        // is signed out first: the login route is for guests.
        $this->signOut();
        $this->post(route('login.store'), [
            'email' => '0791110005',
            'password' => 'Markaz26',
        ])->assertRedirect();

        $this->assertAuthenticatedAs($employee->fresh()->user);

        // And by the email, for whoever prefers it.
        $this->signOut();

        $this->post(route('login.store'), [
            'email' => 'reception@markaz.test',
            'password' => 'Markaz26',
        ])->assertRedirect();

        $this->assertAuthenticatedAs($employee->fresh()->user);
    }

    /** A number typed with a country code or dashes still finds the account. */
    public function test_a_phone_typed_differently_still_signs_in(): void
    {
        $trainer = Trainer::factory()->create([
            'branch_id' => $this->branch->id,
            'phone' => '0791110006',
        ]);

        $this->actingAsUser($this->admin());

        $this->post(route('admin.trainers.account', $trainer), [
            'login_password' => 'Markaz26',
            'login_password_confirmation' => 'Markaz26',
        ])->assertSessionHasNoErrors();

        $this->signOut();

        $this->post(route('login.store'), [
            'email' => '+962 79-111-0006',
            'password' => 'Markaz26',
        ])->assertRedirect();

        $this->assertAuthenticatedAs($trainer->fresh()->user);
    }

    public function test_a_wrong_password_is_refused_with_one_message(): void
    {
        $trainee = Trainee::factory()->create([
            'branch_id' => $this->branch->id,
            'phone' => '0791110007',
        ]);

        $this->actingAsUser($this->admin());
        $this->post(route('admin.trainees.account', $trainee), [
            'login_password' => 'Markaz26',
            'login_password_confirmation' => 'Markaz26',
        ]);

        $this->signOut();

        $this->post(route('login.store'), [
            'email' => '0791110007',
            'password' => 'not-the-password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    // ---------------------------------------------------------------- roles

    /**
     * A trainee's login gets the trainee role and nothing else.
     *
     * The roles field is ignored on that page on purpose: a trainee page that
     * could grant `receptionist` would be a way to hand out staff permissions
     * from a screen nobody thinks of as the users screen.
     */
    public function test_a_trainee_account_cannot_be_given_staff_roles(): void
    {
        $trainee = Trainee::factory()->create([
            'branch_id' => $this->branch->id,
            'phone' => '0791110008',
        ]);

        $this->actingAsUser($this->admin());

        $this->post(route('admin.trainees.account', $trainee), [
            'login_password' => 'Markaz26',
            'login_password_confirmation' => 'Markaz26',
            'login_roles' => [$this->roleId('receptionist'), $this->roleId('accountant')],
        ])->assertSessionHasNoErrors();

        $user = $trainee->fresh()->user;

        $this->assertTrue($user->hasRole('trainee'));
        $this->assertFalse($user->hasRole('receptionist'));
        $this->assertFalse($user->hasRole('accountant'));

        // And the permissions follow: no reading other people's files.
        $this->assertFalse($user->hasPermission('trainees.view'));
        $this->assertFalse($user->hasPermission('payments.view'));
    }

    public function test_a_trainer_account_gets_the_trainer_role(): void
    {
        $trainer = Trainer::factory()->create([
            'branch_id' => $this->branch->id,
            'phone' => '0791110009',
        ]);

        $this->actingAsUser($this->admin());

        $this->post(route('admin.trainers.account', $trainer))->assertSessionHasNoErrors();

        $this->assertTrue($trainer->fresh()->user->hasRole('trainer'));
    }

    /** An employee's login has to say what they may see. */
    public function test_an_employee_account_needs_a_role(): void
    {
        $employee = Employee::factory()->create([
            'branch_id' => $this->branch->id,
            'phone' => '0791110010',
        ]);

        $this->actingAsUser($this->admin());

        $this->post(route('admin.employees.account', $employee), [
            'login_password' => 'Markaz26',
            'login_password_confirmation' => 'Markaz26',
        ])->assertSessionHasErrors('login_roles');

        $this->assertNull($employee->fresh()->user_id);
    }

    // ----------------------------------------------------------- collisions

    public function test_a_phone_already_signing_someone_in_is_refused(): void
    {
        $existing = $this->userWithRole('receptionist', ['phone' => '0791110011']);

        $trainee = Trainee::factory()->create([
            'branch_id' => $this->branch->id,
            'phone' => '0791110011',
        ]);

        $this->actingAsUser($this->admin());

        $this->post(route('admin.trainees.account', $trainee))->assertSessionHasErrors();

        $this->assertNull($trainee->fresh()->user_id);
        $this->assertSame(1, User::where('phone', '0791110011')->count());
        $this->assertSame($existing->id, User::where('phone', '0791110011')->first()->id);
    }

    public function test_an_email_already_in_use_is_refused(): void
    {
        $this->userWithRole('accountant', ['email' => 'taken@markaz.test']);

        $trainee = Trainee::factory()->create([
            'branch_id' => $this->branch->id,
            'phone' => '0791110012',
        ]);

        $this->actingAsUser($this->admin());

        $this->post(route('admin.trainees.account', $trainee), [
            'login_email' => 'taken@markaz.test',
        ])->assertSessionHasErrors('login_email');

        $this->assertNull($trainee->fresh()->user_id);
    }

    /**
     * Setting a password on a record that has no login yet just works.
     *
     * This is the case the edit page has to handle: staff open a trainee, type a
     * password, and expect them to be able to sign in. Refusing because there is
     * no account yet would be a distinction only the database cares about.
     */
    public function test_setting_a_password_on_a_record_with_no_account_creates_one(): void
    {
        $trainee = Trainee::factory()->create([
            'branch_id' => $this->branch->id,
            'phone' => '0791110017',
        ]);

        $this->assertNull($trainee->user_id);

        $this->actingAsUser($this->admin());

        $this->post(route('admin.trainees.account', $trainee), [
            'login_password' => 'Markaz26',
            'login_password_confirmation' => 'Markaz26',
        ])->assertSessionHasNoErrors();

        $trainee->refresh();
        $this->assertNotNull($trainee->user_id);

        $this->flushSession();
        app('auth')->forgetGuards();

        $this->postJson('/api/v1/auth/login', [
            'phone' => '0791110017',
            'password' => 'Markaz26',
            'device_name' => 'app',
        ])->assertOk();
    }

    /**
     * An account already on that number, belonging to nobody, is adopted.
     *
     * This is what used to fail with "the phone is used by another account": a
     * login created before the record was linked — by the old join flow, or by
     * hand — left staff with a trainee they could not give a password to. The
     * number is the login name here, so that account is this person.
     */
    public function test_an_unlinked_account_on_the_same_number_is_adopted(): void
    {
        $orphan = User::factory()->create([
            'branch_id' => $this->branch->id,
            'phone' => '0791110018',
            'status' => 'inactive',
        ]);

        $trainee = Trainee::factory()->create([
            'branch_id' => $this->branch->id,
            'phone' => '0791110018',
        ]);

        $this->actingAsUser($this->admin());

        $this->post(route('admin.trainees.account', $trainee), [
            'login_password' => 'Markaz26',
            'login_password_confirmation' => 'Markaz26',
        ])->assertSessionHasNoErrors();

        $trainee->refresh();

        $this->assertSame($orphan->id, $trainee->user_id, 'the existing login was adopted, not duplicated');
        $this->assertSame(1, User::where('phone', '0791110018')->count());

        $orphan->refresh();
        $this->assertSame('active', $orphan->status, 'an inactive orphan is reactivated');
        $this->assertTrue($orphan->hasRole('trainee'));
        $this->assertTrue(Hash::check('Markaz26', $orphan->password));
    }

    /**
     * A staff account on that number is never adopted.
     *
     * Attaching a trainee record to a receptionist's login would give the
     * trainee that receptionist's permissions — the record gains an account and
     * the account keeps its roles. Refused by name so the office can fix it.
     */
    public function test_a_staff_account_on_the_same_number_is_not_adopted(): void
    {
        $staff = $this->userWithRole('receptionist', ['phone' => '0791110019']);

        $trainee = Trainee::factory()->create([
            'branch_id' => $this->branch->id,
            'phone' => '0791110019',
        ]);

        $this->actingAsUser($this->admin());

        $this->post(route('admin.trainees.account', $trainee), [
            'login_password' => 'Markaz26',
            'login_password_confirmation' => 'Markaz26',
        ])->assertSessionHasErrors();

        $this->assertNull($trainee->fresh()->user_id);
        $this->assertFalse($staff->fresh()->hasRole('trainee'));
        $this->assertTrue(Hash::check('secret-password', $staff->fresh()->password));
    }

    /** A login another trainee already uses stays theirs. */
    public function test_an_account_owned_by_another_record_is_refused(): void
    {
        $other = Trainee::factory()->create([
            'branch_id' => $this->branch->id,
            'phone' => '0791110020',
        ]);

        $this->actingAsUser($this->admin());
        $this->post(route('admin.trainees.account', $other))->assertSessionHasNoErrors();
        $this->flushSession();

        // A second record entered with the same number by mistake.
        $duplicate = Trainee::factory()->create([
            'branch_id' => $this->branch->id,
            'phone' => '0791110020',
            'full_name' => 'سجل مكرر',
        ]);

        $this->post(route('admin.trainees.account', $duplicate))->assertSessionHasErrors();

        $this->assertNull($duplicate->fresh()->user_id);
        $this->assertNotNull($other->fresh()->user_id);
    }

    // ------------------------------------------------------ creating in one go

    public function test_a_trainee_can_be_registered_with_a_login_in_one_step(): void
    {
        $this->actingAsUser($this->admin());

        $this->post(route('admin.trainees.store'), [
            'full_name' => 'سارة الخطيب',
            'phone' => '0791110013',
            'license_type' => 'private',
            'registration_date' => now()->toDateString(),
            'branch_id' => $this->branch->id,
            'create_login' => '1',
            'login_password' => 'Markaz26',
            'login_password_confirmation' => 'Markaz26',
        ])->assertSessionHasNoErrors();

        $trainee = Trainee::where('phone', '0791110013')->firstOrFail();

        $this->assertNotNull($trainee->user_id);
        $this->assertTrue($trainee->user->hasRole('trainee'));
    }

    public function test_an_employee_can_be_added_with_a_login_in_one_step(): void
    {
        $this->actingAsUser($this->admin());

        $this->post(route('admin.employees.store'), [
            'full_name' => 'خالد المصري',
            'phone' => '0791110014',
            'position' => 'receptionist',
            'employment_date' => now()->subMonth()->toDateString(),
            'base_salary' => 400,
            'allowances' => 50,
            'status' => 'active',
            'branch_id' => $this->branch->id,
            'create_login' => '1',
            'login_password' => 'Markaz26',
            'login_password_confirmation' => 'Markaz26',
            'login_roles' => [$this->roleId('receptionist')],
        ])->assertSessionHasNoErrors();

        $employee = Employee::where('phone', '0791110014')->firstOrFail();

        $this->assertNotNull($employee->user_id);
        $this->assertTrue($employee->user->hasRole('receptionist'));
    }

    /** Nothing is created behind the office's back when the box is not ticked. */
    public function test_no_account_is_created_unless_asked_for(): void
    {
        $this->actingAsUser($this->admin());

        $this->post(route('admin.trainees.store'), [
            'full_name' => 'بدون حساب',
            'phone' => '0791110015',
            'license_type' => 'private',
            'registration_date' => now()->toDateString(),
            'branch_id' => $this->branch->id,
        ])->assertSessionHasNoErrors();

        $this->assertNull(Trainee::where('phone', '0791110015')->firstOrFail()->user_id);
    }

    // ---------------------------------------------------------- permissions

    public function test_issuing_a_login_needs_permission_over_the_record(): void
    {
        $trainee = Trainee::factory()->create([
            'branch_id' => $this->branch->id,
            'phone' => '0791110016',
        ]);

        // An accountant reads money, not trainee records.
        $this->actingAsUser($this->userWithRole('accountant'));

        $this->post(route('admin.trainees.account', $trainee))->assertForbidden();

        $this->assertNull($trainee->fresh()->user_id);
    }
}
