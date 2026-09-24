<?php

namespace App\Providers;

use App\Models\Branch;
use App\Models\BookingRequest;
use App\Models\Document;
use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\Expense;
use App\Models\Package;
use App\Models\Payment;
use App\Models\Payroll;
use App\Models\Role;
use App\Models\Trainee;
use App\Models\Trainer;
use App\Models\TrainerCompensationRecord;
use App\Models\TrainingSession;
use App\Models\User;
use App\Models\Vehicle;
use App\Policies\BookingRequestPolicy;
use App\Policies\BranchPolicy;
use App\Policies\DocumentPolicy;
use App\Policies\EmployeeAdvancePolicy;
use App\Policies\EmployeePolicy;
use App\Policies\ExpensePolicy;
use App\Policies\PackagePolicy;
use App\Policies\PaymentPolicy;
use App\Policies\PayrollPolicy;
use App\Policies\RolePolicy;
use App\Policies\TraineePolicy;
use App\Policies\TrainerCompensationRecordPolicy;
use App\Policies\TrainerPolicy;
use App\Policies\TrainingSessionPolicy;
use App\Policies\UserPolicy;
use App\Policies\VehiclePolicy;
use App\Support\Permissions;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    protected $policies = [
        Trainee::class => TraineePolicy::class,
        Trainer::class => TrainerPolicy::class,
        Employee::class => EmployeePolicy::class,
        Vehicle::class => VehiclePolicy::class,
        Package::class => PackagePolicy::class,
        TrainingSession::class => TrainingSessionPolicy::class,
        BookingRequest::class => BookingRequestPolicy::class,
        Payment::class => PaymentPolicy::class,
        Expense::class => ExpensePolicy::class,
        Payroll::class => PayrollPolicy::class,
        EmployeeAdvance::class => EmployeeAdvancePolicy::class,
        TrainerCompensationRecord::class => TrainerCompensationRecordPolicy::class,
        Document::class => DocumentPolicy::class,
        User::class => UserPolicy::class,
        Role::class => RolePolicy::class,
        Branch::class => BranchPolicy::class,
    ];

    public function boot(): void
    {
        $this->registerPolicies();

        // The super admin bypasses policies outright. Everyone else, including
        // a center manager, goes through the normal checks.
        Gate::before(fn (User $user) => $user->isSuperAdmin() ? true : null);

        // Every catalogue permission is also usable as a bare gate, so a
        // controller can write $this->authorize('profit.view') for the checks
        // that guard data rather than a specific record.
        foreach (Permissions::all() as $permission) {
            Gate::define($permission, fn (User $user) => $user->hasPermission($permission));
        }
    }
}
