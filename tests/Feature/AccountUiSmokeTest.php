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

    /**
     * The edit pages carry a plain "reset the password" card.
     *
     * That is what brings staff to an edit page more often than a birth date, so
     * it is two fields and a button — no roles, no account plumbing — and it has
     * to be above the record's own form, because its form cannot be inside it.
     */
    public function test_the_edit_pages_offer_a_password_reset(): void
    {
        $trainee = Trainee::factory()->create(['branch_id' => $this->branch->id]);
        $trainer = Trainer::factory()->create(['branch_id' => $this->branch->id]);
        $employee = Employee::factory()->create(['branch_id' => $this->branch->id]);

        // Each needs a login before there is a password to reset.
        foreach ([
            ['admin.trainees.account', $trainee],
            ['admin.trainers.account', $trainer],
        ] as [$route, $subject]) {
            $this->post(route($route, $subject))->assertSessionHasNoErrors();
        }

        $this->post(route('admin.employees.account', $employee), [
            'login_roles' => [\App\Models\Role::where('name', 'receptionist')->firstOrFail()->id],
        ])->assertSessionHasNoErrors();

        // The credentials from those posts are still flashed, and the card shows
        // them instead of the fields on the very next request — which is the
        // point of them, but not what this test is measuring.
        $this->flushSession();

        foreach ([
            ['admin.trainees.edit', $trainee, 'admin.trainees.update'],
            ['admin.trainers.edit', $trainer, 'admin.trainers.update'],
            ['admin.employees.edit', $employee, 'admin.employees.update'],
        ] as [$page, $subject, $updateRoute]) {
            $html = $this->get(route($page, $subject->fresh()))->assertOk()->content();

            $this->assertStringContainsString('إعادة تعيين كلمة المرور', $html);
            $this->assertStringContainsString('login_password_confirmation', $html);

            // Nothing that belongs on the person's own page.
            $this->assertStringNotContainsString('login_roles[]', $html);
            $this->assertStringNotContainsString('تعطيل الحساب', $html);

            // Its form has to close before the record's form opens. Measured on
            // the PATCH marker and a closing tag rather than on the two URLs:
            // the update URL is a prefix of the account URL, so comparing those
            // two would always "pass" no matter where the card sat.
            $reset = strpos($html, route('admin.'.explode('.', $page)[1].'.account', $subject).'"');
            $record = strpos($html, 'name="_method" value="PATCH"');

            $this->assertNotFalse($reset, 'the reset form is missing from '.$page);
            $this->assertNotFalse($record, 'the record form is missing from '.$page);
            $this->assertLessThan($record, $reset, 'the reset card is below the record form');
            $this->assertLessThan(
                $record,
                strpos($html, '</form>', $reset),
                'the reset form never closes before the record form opens — nested, so it would post nothing',
            );
        }
    }

    /**
     * The fields show even when the record has no login yet.
     *
     * The card used to replace them with "there is no account" and a link
     * somewhere else, which is how the whole feature read as missing.
     */
    public function test_the_reset_fields_show_without_an_existing_account(): void
    {
        $trainee = Trainee::factory()->create(['branch_id' => $this->branch->id]);
        $trainer = Trainer::factory()->create(['branch_id' => $this->branch->id]);

        $this->assertNull($trainee->user_id);

        foreach ([
            route('admin.trainees.edit', $trainee),
            route('admin.trainers.edit', $trainer),
        ] as $url) {
            $this->get($url)
                ->assertOk()
                ->assertSee('login_password_confirmation', false)
                ->assertDontSee('لا يوجد حساب دخول لهذا السجل', false);
        }
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
