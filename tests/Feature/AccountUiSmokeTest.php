<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Trainee;
use App\Models\Trainer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The login fields and panels actually render.
 *
 * A Blade component that throws only shows up when a page is opened, and these
 * six pages are the ones the office uses every day.
 */
class AccountUiSmokeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFoundation();
        $this->actingAsUser($this->admin());
    }

    public function test_the_add_forms_offer_a_password(): void
    {
        foreach (['trainees', 'trainers', 'employees'] as $section) {
            $this->get(route("admin.{$section}.create"))
                ->assertOk()
                ->assertSee('login_password', false)
                ->assertSee('create_login', false);
        }
    }

    public function test_the_employee_form_asks_which_roles(): void
    {
        $this->get(route('admin.employees.create'))
            ->assertOk()
            ->assertSee('login_roles[]', false);
    }

    /** A trainee's login has no role picker: it follows from what they are. */
    public function test_the_trainee_form_has_no_role_picker(): void
    {
        $this->get(route('admin.trainees.create'))
            ->assertOk()
            ->assertDontSee('login_roles[]', false);
    }

    public function test_the_person_pages_show_the_account_panel(): void
    {
        $trainee = Trainee::factory()->create(['branch_id' => $this->branch->id]);
        $trainer = Trainer::factory()->create(['branch_id' => $this->branch->id]);
        $employee = Employee::factory()->create(['branch_id' => $this->branch->id]);

        foreach ([
            route('admin.trainees.show', $trainee),
            route('admin.trainers.show', $trainer),
            route('admin.employees.show', $employee),
        ] as $url) {
            $this->get($url)->assertOk()->assertSee('إنشاء حساب دخول');
        }
    }
}
