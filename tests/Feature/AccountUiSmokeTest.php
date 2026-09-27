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

    /**
     * The panel is on the tab that opens, not one the user has to find.
     *
     * assertSee alone cannot tell: the trainee page is tabbed with Alpine, and
     * the markup of a hidden tab is still in the response. It shipped hidden
     * under "الملاحظات" for exactly that reason, so this asserts position — the
     * panel has to appear before the second tab's section starts.
     */
    public function test_the_trainee_account_panel_is_on_the_first_tab(): void
    {
        $trainee = Trainee::factory()->create(['branch_id' => $this->branch->id]);

        $html = $this->get(route('admin.trainees.show', $trainee))->assertOk()->content();

        $panel = strpos($html, 'حساب التطبيق');

        // The section, not the tab button: the button for every tab is rendered
        // near the top, so measuring against that would pass wherever the panel
        // sat. `x-show` only ever wraps a section.
        $secondSection = strpos($html, "x-show=\"tab === 'training'\"");

        $this->assertNotFalse($panel, 'the account panel is missing');
        $this->assertNotFalse($secondSection, 'the tab markup changed — check this test still measures what it claims');
        $this->assertLessThan(
            $secondSection,
            $panel,
            'the account panel is outside the overview tab, so staff cannot see it without hunting',
        );
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
