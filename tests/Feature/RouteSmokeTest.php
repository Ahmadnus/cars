<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\Package;
use App\Models\PaymentMethod;
use App\Models\Trainee;
use App\Models\Trainer;
use App\Models\Vehicle;
use App\Services\AppointmentService;
use App\Services\ExpenseService;
use App\Services\PackageService;
use App\Services\PaymentService;
use App\Services\PayrollService;
use App\Services\TrainerCompensationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Walks every GET route in the dashboard as a super admin, with real data
 * behind it, and asserts none of them errors.
 *
 * This is the broad safety net: unit tests cover the rules, this catches a
 * broken view, a missing binding or a bad query anywhere in the UI.
 */
class RouteSmokeTest extends TestCase
{
    use RefreshDatabase;

    /** Routes whose side effects or arguments make a blind GET meaningless. */
    protected const SKIP = [
        'admin.documents.download',
        'admin.documents.view',
        'admin.payroll.receipt',
        'admin.trainer-compensation.receipt',
        'admin.reports.export',
    ];

    public function test_every_dashboard_page_renders(): void
    {
        $this->seedFoundation();
        $this->actingAsUser($this->admin());

        $models = $this->buildFixtures();
        $failures = [];

        foreach (Route::getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }

            $name = $route->getName();

            if (! $name || ! str_starts_with($name, 'admin.') || in_array($name, self::SKIP, true)) {
                continue;
            }

            $parameters = $this->parametersFor($route->parameterNames(), $models);

            if ($parameters === null) {
                continue; // a parameter we have no fixture for
            }

            try {
                $response = $this->get(route($name, $parameters));

                if ($response->getStatusCode() >= 400) {
                    $failures[] = $name.' → '.$response->getStatusCode();
                }
            } catch (\Throwable $e) {
                $failures[] = $name.' → '.$e->getMessage();
            }
        }

        $this->assertSame([], $failures, "Routes that failed:\n".implode("\n", $failures));
    }

    /**
     * Map a route's parameter names onto the fixtures we created.
     *
     * @return array<string, mixed>|null null when the route needs something we have not built
     */
    protected function parametersFor(array $names, array $models): ?array
    {
        $parameters = [];

        foreach ($names as $name) {
            $value = match ($name) {
                'trainee' => $models['trainee'],
                'trainer' => $models['trainer'],
                'vehicle' => $models['vehicle'],
                'employee' => $models['employee'],
                'package' => $models['package'],
                'session' => $models['session'],
                'payment' => $models['payment'],
                'expense' => $models['expense'],
                'payroll' => $models['payroll'],
                'advance' => $models['advance'],
                'record' => $models['compensation'],
                'traineePackage' => $models['enrolment'],
                'user' => $models['user'],
                'role' => $models['role'],
                'branch' => $models['branch'],
                'auditLog' => $models['auditLog'],
                'report' => 'revenue',
                default => null,
            };

            if ($value === null) {
                return null;
            }

            $parameters[$name] = $value;
        }

        return $parameters;
    }

    /** One realistic record of every kind the dashboard can show. */
    protected function buildFixtures(): array
    {
        $trainer = Trainer::factory()->create(['branch_id' => $this->branch->id]);
        $vehicle = Vehicle::factory()->create(['branch_id' => $this->branch->id]);
        $employee = Employee::factory()->create(['branch_id' => $this->branch->id]);
        $trainee = Trainee::factory()->create(['branch_id' => $this->branch->id, 'trainer_id' => $trainer->id]);
        $package = Package::factory()->create();
        $cash = PaymentMethod::where('code', 'cash')->firstOrFail();

        $enrolment = app(PackageService::class)->assign($trainee, $package);

        $date = $this->pastWorkingDay();

        $session = app(AppointmentService::class)->schedule([
            'trainee_id' => $trainee->id,
            'trainer_id' => $trainer->id,
            'vehicle_id' => $vehicle->id,
            'scheduled_date' => $date->toDateString(),
            'start_time' => '10:00',
        ], $this->branch->id);

        $payment = app(PaymentService::class)->record([
            'trainee_id' => $trainee->id,
            'trainee_package_id' => $enrolment->id,
            'payment_method_id' => $cash->id,
            'amount' => 50,
            'paid_on' => now()->toDateString(),
        ], $this->branch->id);

        $expense = app(ExpenseService::class)->record([
            'expense_category_id' => \App\Models\ExpenseCategory::where('code', 'rent')->value('id'),
            'payment_method_id' => $cash->id,
            'title' => 'إيجار',
            'amount' => 100,
            'spent_on' => now()->toDateString(),
        ], $this->branch->id);

        $payrollService = app(PayrollService::class);
        $payroll = $payrollService->generate($employee, now()->format('Y-m'));
        $advance = $payrollService->grantAdvance($employee, 90, now()->toDateString(), 3, $cash->id);

        app(TrainerCompensationService::class)->setRule($trainer, [
            'model' => 'per_lesson',
            'per_lesson_rate' => 5,
            'effective_from' => now()->subYear()->toDateString(),
        ]);

        $compensation = app(TrainerCompensationService::class)->calculate($trainer, now()->format('Y-m'));

        return [
            'trainee' => $trainee,
            'trainer' => $trainer,
            'vehicle' => $vehicle,
            'employee' => $employee,
            'package' => $package,
            'enrolment' => $enrolment,
            'session' => $session,
            'payment' => $payment,
            'expense' => $expense,
            'payroll' => $payroll,
            'advance' => $advance,
            'compensation' => $compensation,
            'user' => auth()->user(),
            'role' => \App\Models\Role::where('name', 'receptionist')->firstOrFail(),
            'branch' => Branch::where('code', 'AMM')->firstOrFail(),
            'auditLog' => \App\Models\AuditLog::latest('id')->firstOrFail(),
        ];
    }
}
