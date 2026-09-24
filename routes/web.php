<?php

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\BookingRequestController;
use App\Http\Controllers\Admin\BranchController;
use App\Http\Controllers\Admin\CalendarController;
use App\Http\Controllers\Admin\CashboxController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DocumentController;
use App\Http\Controllers\Admin\EmployeeAdvanceController;
use App\Http\Controllers\Admin\EmployeeController;
use App\Http\Controllers\Admin\ExpenseController;
use App\Http\Controllers\Admin\NotificationController;
use App\Http\Controllers\Admin\PackageController;
use App\Http\Controllers\Admin\PaymentController;
use App\Http\Controllers\Admin\PayrollController;
use App\Http\Controllers\Admin\ProfileController;
use App\Http\Controllers\Admin\ProfitController;
use App\Http\Controllers\Admin\RecurringExpenseController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\TraineeController;
use App\Http\Controllers\Admin\TraineeNoteController;
use App\Http\Controllers\Admin\TraineePackageController;
use App\Http\Controllers\Admin\TrainerCompensationController;
use App\Http\Controllers\Admin\TrainerController;
use App\Http\Controllers\Admin\TrainingSessionController;
use App\Http\Controllers\Admin\TrainingSkillController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\UtilityBillController;
use App\Http\Controllers\Admin\VehicleController;
use App\Http\Controllers\Admin\VehicleMaintenanceController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Portal\PortalController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Guest routes
|--------------------------------------------------------------------------
*/

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->name('login.store')->middleware('throttle:10,1');

    Route::get('/forgot-password', [PasswordResetController::class, 'requestForm'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'sendLink'])
        ->name('password.email')->middleware('throttle:6,1');

    Route::get('/reset-password/{token}', [PasswordResetController::class, 'resetForm'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'reset'])->name('password.update');
});

Route::post('/logout', [LoginController::class, 'destroy'])->name('logout')->middleware('auth');

Route::redirect('/', '/dashboard');

/*
|--------------------------------------------------------------------------
| Trainee portal
|--------------------------------------------------------------------------
|
| A trainee's own file, and the only authenticated surface their role can
| reach. There is no id in the route: the controller resolves the record from
| the signed-in user, so one trainee cannot address another's data.
|
*/

Route::middleware(['auth', 'active', 'permission:portal.view'])
    ->prefix('portal')->name('portal.')
    ->group(function () {
        Route::get('/', [PortalController::class, 'index'])->name('index');
    });

/*
|--------------------------------------------------------------------------
| Admin dashboard
|--------------------------------------------------------------------------
|
| Every route declares the permission it requires. Policies inside the
| controllers re-check per record, and the services enforce the business rules
| again — the three layers are deliberate, not redundant.
|
*/

Route::middleware(['auth', 'active'])->name('admin.')->group(function () {

    Route::get('/dashboard', [DashboardController::class, 'index'])
        ->middleware('permission:dashboard.view')->name('dashboard');

    Route::post('/branch/switch', [DashboardController::class, 'switchBranch'])->name('branch.switch');

    // ---------------------------------------------------------------- profile
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::patch('/profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');
    Route::delete('/profile/tokens/{tokenId}', [ProfileController::class, 'revokeToken'])->name('profile.tokens.revoke');

    // ------------------------------------------------------------- operations
    Route::middleware('permission:appointments.view')->group(function () {
        Route::get('/calendar', [CalendarController::class, 'index'])->name('calendar.index');
        Route::get('/sessions', [TrainingSessionController::class, 'index'])->name('sessions.index');
        Route::get('/sessions/slots', [TrainingSessionController::class, 'availableSlots'])->name('sessions.slots');
        Route::get('/sessions/{session}', [TrainingSessionController::class, 'show'])->name('sessions.show');
    });

    Route::middleware('permission:appointments.create')->group(function () {
        Route::get('/sessions/create/new', [TrainingSessionController::class, 'create'])->name('sessions.create');
        Route::post('/sessions', [TrainingSessionController::class, 'store'])->name('sessions.store');
    });

    Route::middleware('permission:appointments.update')->group(function () {
        Route::get('/sessions/{session}/edit', [TrainingSessionController::class, 'edit'])->name('sessions.edit');
        Route::patch('/sessions/{session}', [TrainingSessionController::class, 'update'])->name('sessions.update');
    });

    Route::middleware('permission:appointments.cancel')->group(function () {
        Route::post('/sessions/{session}/cancel', [TrainingSessionController::class, 'cancel'])->name('sessions.cancel');
        Route::post('/sessions/{session}/postpone', [TrainingSessionController::class, 'postpone'])->name('sessions.postpone');
        Route::post('/sessions/{session}/reopen', [TrainingSessionController::class, 'reopen'])->name('sessions.reopen');
    });

    Route::middleware('permission:appointments.complete')->group(function () {
        Route::post('/sessions/{session}/complete', [TrainingSessionController::class, 'complete'])->name('sessions.complete');
        Route::post('/sessions/{session}/no-show', [TrainingSessionController::class, 'noShow'])->name('sessions.no-show');
    });

    Route::middleware('permission:booking_requests.manage')->prefix('booking-requests')->name('booking-requests.')->group(function () {
        Route::get('/', [BookingRequestController::class, 'index'])->name('index');
        Route::post('/{bookingRequest}/approve', [BookingRequestController::class, 'approve'])->name('approve');
        Route::post('/{bookingRequest}/reject', [BookingRequestController::class, 'reject'])->name('reject');
        Route::post('/{bookingRequest}/cancel', [BookingRequestController::class, 'approveCancellation'])->name('cancel');
    });

    // --------------------------------------------------------------- trainees
    Route::middleware('permission:trainees.view')->group(function () {
        Route::get('/trainees', [TraineeController::class, 'index'])->name('trainees.index');
        Route::get('/trainees/{trainee}', [TraineeController::class, 'show'])->name('trainees.show');
        Route::get('/trainee-packages/{traineePackage}/ledger', [TraineePackageController::class, 'transactions'])
            ->name('trainee-packages.ledger');
    });

    Route::middleware('permission:trainees.create')->group(function () {
        Route::get('/trainees/create/new', [TraineeController::class, 'create'])->name('trainees.create');
        Route::post('/trainees', [TraineeController::class, 'store'])->name('trainees.store');
    });

    Route::middleware('permission:trainees.update')->group(function () {
        Route::get('/trainees/{trainee}/edit', [TraineeController::class, 'edit'])->name('trainees.edit');
        Route::patch('/trainees/{trainee}', [TraineeController::class, 'update'])->name('trainees.update');
        Route::post('/trainees/{trainee}/notes', [TraineeNoteController::class, 'store'])->name('trainees.notes.store');
        Route::delete('/trainee-notes/{note}', [TraineeNoteController::class, 'destroy'])->name('trainees.notes.destroy');
    });

    Route::delete('/trainees/{trainee}', [TraineeController::class, 'destroy'])
        ->middleware('permission:trainees.delete')->name('trainees.destroy');

    Route::middleware('permission:packages.assign')->group(function () {
        Route::get('/trainees/{trainee}/packages/assign', [TraineePackageController::class, 'create'])->name('trainees.packages.create');
        Route::post('/trainees/{trainee}/packages', [TraineePackageController::class, 'store'])->name('trainees.packages.store');
        Route::post('/trainee-packages/{traineePackage}/extra-lessons', [TraineePackageController::class, 'addExtraLessons'])->name('trainee-packages.extra');
        Route::post('/trainee-packages/{traineePackage}/adjust', [TraineePackageController::class, 'adjustBalance'])->name('trainee-packages.adjust');
        Route::post('/trainee-packages/{traineePackage}/cancel', [TraineePackageController::class, 'cancel'])->name('trainee-packages.cancel');
    });

    // --------------------------------------------------------------- packages
    Route::get('/packages', [PackageController::class, 'index'])
        ->middleware('permission:packages.view')->name('packages.index');

    Route::middleware('permission:packages.manage')->group(function () {
        Route::get('/packages/create', [PackageController::class, 'create'])->name('packages.create');
        Route::post('/packages', [PackageController::class, 'store'])->name('packages.store');
        Route::get('/packages/{package}/edit', [PackageController::class, 'edit'])->name('packages.edit');
        Route::patch('/packages/{package}', [PackageController::class, 'update'])->name('packages.update');
        Route::delete('/packages/{package}', [PackageController::class, 'destroy'])->name('packages.destroy');
    });

    // ----------------------------------------------------------------- skills
    Route::get('/skills', [TrainingSkillController::class, 'index'])
        ->middleware('permission:evaluations.view')->name('skills.index');

    Route::middleware('permission:skills.manage')->group(function () {
        Route::post('/skills', [TrainingSkillController::class, 'store'])->name('skills.store');
        Route::patch('/skills/{skill}', [TrainingSkillController::class, 'update'])->name('skills.update');
        Route::delete('/skills/{skill}', [TrainingSkillController::class, 'destroy'])->name('skills.destroy');
    });

    // --------------------------------------------------------------- trainers
    Route::middleware('permission:trainers.view')->group(function () {
        Route::get('/trainers', [TrainerController::class, 'index'])->name('trainers.index');
        Route::get('/trainers/{trainer}', [TrainerController::class, 'show'])->name('trainers.show');
        Route::get('/trainers/{trainer}/schedule', [TrainerController::class, 'schedule'])->name('trainers.schedule');
    });

    Route::middleware('permission:trainers.create')->group(function () {
        Route::get('/trainers/create/new', [TrainerController::class, 'create'])->name('trainers.create');
        Route::post('/trainers', [TrainerController::class, 'store'])->name('trainers.store');
    });

    Route::middleware('permission:trainers.update')->group(function () {
        Route::get('/trainers/{trainer}/edit', [TrainerController::class, 'edit'])->name('trainers.edit');
        Route::patch('/trainers/{trainer}', [TrainerController::class, 'update'])->name('trainers.update');
    });

    Route::delete('/trainers/{trainer}', [TrainerController::class, 'destroy'])
        ->middleware('permission:trainers.delete')->name('trainers.destroy');

    // --------------------------------------------------- trainer compensation
    Route::prefix('trainer-compensation')->name('trainer-compensation.')->group(function () {
        Route::middleware('permission:trainer_compensation.view')->group(function () {
            Route::get('/', [TrainerCompensationController::class, 'index'])->name('index');
            Route::get('/rules', [TrainerCompensationController::class, 'rules'])->name('rules');
            Route::get('/payments/{payment}/receipt', [TrainerCompensationController::class, 'receipt'])->name('receipt');
            Route::get('/{record}', [TrainerCompensationController::class, 'show'])->name('show');
        });

        Route::middleware('permission:trainer_compensation.manage')->group(function () {
            Route::post('/calculate', [TrainerCompensationController::class, 'calculate'])->name('calculate');
            Route::post('/{record}/approve', [TrainerCompensationController::class, 'approve'])->name('approve');
            Route::post('/rules/{trainer}', [TrainerCompensationController::class, 'storeRule'])->name('rules.store');
        });

        Route::post('/{record}/pay', [TrainerCompensationController::class, 'pay'])
            ->middleware('permission:trainer_compensation.pay')->name('pay');
    });

    // -------------------------------------------------------------- employees
    Route::middleware('permission:employees.view')->group(function () {
        Route::get('/employees', [EmployeeController::class, 'index'])->name('employees.index');
        Route::get('/employees/{employee}', [EmployeeController::class, 'show'])->name('employees.show');
    });

    Route::middleware('permission:employees.manage')->group(function () {
        Route::get('/employees/create/new', [EmployeeController::class, 'create'])->name('employees.create');
        Route::post('/employees', [EmployeeController::class, 'store'])->name('employees.store');
        Route::get('/employees/{employee}/edit', [EmployeeController::class, 'edit'])->name('employees.edit');
        Route::patch('/employees/{employee}', [EmployeeController::class, 'update'])->name('employees.update');
        Route::delete('/employees/{employee}', [EmployeeController::class, 'destroy'])->name('employees.destroy');
    });

    // ---------------------------------------------------------------- payroll
    Route::prefix('payroll')->name('payroll.')->group(function () {
        Route::middleware('permission:payroll.view')->group(function () {
            Route::get('/', [PayrollController::class, 'index'])->name('index');
            Route::get('/payments/{payrollPayment}/receipt', [PayrollController::class, 'receipt'])->name('receipt');
            Route::get('/{payroll}', [PayrollController::class, 'show'])->name('show');
        });

        Route::middleware('permission:payroll.create')->group(function () {
            Route::post('/generate', [PayrollController::class, 'generate'])->name('generate');
            Route::post('/{payroll}/recalculate', [PayrollController::class, 'recalculate'])->name('recalculate');
        });

        Route::post('/{payroll}/pay', [PayrollController::class, 'pay'])
            ->middleware('permission:payroll.pay')->name('pay');
    });

    // --------------------------------------------------------------- advances
    Route::middleware('permission:advances.manage')->prefix('advances')->name('advances.')->group(function () {
        Route::get('/', [EmployeeAdvanceController::class, 'index'])->name('index');
        Route::post('/', [EmployeeAdvanceController::class, 'store'])->name('store');
        Route::get('/{advance}', [EmployeeAdvanceController::class, 'show'])->name('show');
    });

    // --------------------------------------------------------------- vehicles
    Route::middleware('permission:vehicles.view')->group(function () {
        Route::get('/vehicles', [VehicleController::class, 'index'])->name('vehicles.index');
        Route::get('/maintenance', [VehicleMaintenanceController::class, 'index'])->name('maintenance.index');
        Route::get('/vehicles/{vehicle}', [VehicleController::class, 'show'])->name('vehicles.show');
    });

    Route::middleware('permission:vehicles.manage')->group(function () {
        Route::get('/vehicles/create/new', [VehicleController::class, 'create'])->name('vehicles.create');
        Route::post('/vehicles', [VehicleController::class, 'store'])->name('vehicles.store');
        Route::get('/vehicles/{vehicle}/edit', [VehicleController::class, 'edit'])->name('vehicles.edit');
        Route::patch('/vehicles/{vehicle}', [VehicleController::class, 'update'])->name('vehicles.update');
        Route::delete('/vehicles/{vehicle}', [VehicleController::class, 'destroy'])->name('vehicles.destroy');
        Route::post('/maintenance', [VehicleMaintenanceController::class, 'store'])->name('maintenance.store');
    });

    // --------------------------------------------------------------- payments
    Route::prefix('payments')->name('payments.')->group(function () {
        Route::middleware('permission:payments.create')->group(function () {
            Route::get('/create/new', [PaymentController::class, 'create'])->name('create');
            Route::post('/', [PaymentController::class, 'store'])->name('store');
            Route::get('/trainees/{trainee}/packages', [PaymentController::class, 'traineePackages'])->name('trainee-packages');
        });

        Route::middleware('permission:payments.view')->group(function () {
            Route::get('/', [PaymentController::class, 'index'])->name('index');
            Route::get('/{payment}', [PaymentController::class, 'show'])->name('show');
            Route::get('/{payment}/receipt', [PaymentController::class, 'receipt'])->name('receipt');
        });

        Route::post('/{payment}/void', [PaymentController::class, 'void'])
            ->middleware('permission:payments.void')->name('void');
    });

    // --------------------------------------------------------------- expenses
    Route::prefix('expenses')->name('expenses.')->group(function () {
        Route::middleware('permission:expenses.create')->group(function () {
            Route::get('/create/new', [ExpenseController::class, 'create'])->name('create');
            Route::post('/', [ExpenseController::class, 'store'])->name('store');
        });

        Route::middleware('permission:expenses.view')->group(function () {
            Route::get('/', [ExpenseController::class, 'index'])->name('index');
            Route::get('/{expense}', [ExpenseController::class, 'show'])->name('show');
        });

        Route::middleware('permission:expenses.update')->group(function () {
            Route::get('/{expense}/edit', [ExpenseController::class, 'edit'])->name('edit');
            Route::patch('/{expense}', [ExpenseController::class, 'update'])->name('update');
        });

        Route::post('/{expense}/cancel', [ExpenseController::class, 'cancel'])
            ->middleware('permission:expenses.delete')->name('cancel');
    });

    Route::middleware('permission:recurring_expenses.manage')->prefix('recurring-expenses')->name('recurring-expenses.')->group(function () {
        Route::get('/', [RecurringExpenseController::class, 'index'])->name('index');
        Route::post('/', [RecurringExpenseController::class, 'store'])->name('store');
        Route::patch('/{recurringExpense}', [RecurringExpenseController::class, 'update'])->name('update');
        Route::post('/{recurringExpense}/generate', [RecurringExpenseController::class, 'generate'])->name('generate');
        Route::delete('/{recurringExpense}', [RecurringExpenseController::class, 'destroy'])->name('destroy');
    });

    Route::middleware('permission:utilities.manage')->prefix('utilities')->name('utilities.')->group(function () {
        Route::get('/', [UtilityBillController::class, 'index'])->name('index');
        Route::post('/', [UtilityBillController::class, 'store'])->name('store');
        Route::post('/{utilityBill}/pay', [UtilityBillController::class, 'pay'])->name('pay');
    });

    // ---------------------------------------------------------------- cashbox
    Route::prefix('cashbox')->name('cashbox.')->group(function () {
        Route::get('/', [CashboxController::class, 'index'])
            ->middleware('permission:cashbox.view')->name('index');

        Route::middleware('permission:cashbox.manage')->group(function () {
            Route::post('/close', [CashboxController::class, 'close'])->name('close');
            Route::post('/adjust', [CashboxController::class, 'adjust'])->name('adjust');
        });
    });

    Route::get('/profit', [ProfitController::class, 'index'])
        ->middleware('permission:profit.view')->name('profit.index');

    // ---------------------------------------------------------------- reports
    Route::middleware('permission:reports.view')->prefix('reports')->name('reports.')->group(function () {
        Route::get('/', [ReportController::class, 'index'])->name('index');
        Route::get('/{report}/export/{format}', [ReportController::class, 'export'])
            ->whereIn('format', ['csv', 'xlsx', 'pdf'])->name('export');
        Route::get('/{report}', [ReportController::class, 'show'])->name('show');
    });

    // ---------------------------------------------------------- notifications
    Route::prefix('notifications')->name('notifications.')->group(function () {
        Route::get('/', [NotificationController::class, 'index'])->name('index');
        Route::post('/read-all', [NotificationController::class, 'markAllRead'])->name('read-all');
        Route::post('/{notification}/read', [NotificationController::class, 'markRead'])->name('read');
    });

    // -------------------------------------------------------------- documents
    Route::prefix('documents')->name('documents.')->group(function () {
        Route::post('/', [DocumentController::class, 'store'])->name('store');
        Route::get('/{document}/download', [DocumentController::class, 'download'])->name('download');
        Route::get('/{document}/view', [DocumentController::class, 'view'])->name('view');
        Route::delete('/{document}', [DocumentController::class, 'destroy'])->name('destroy');
    });

    // ------------------------------------------------------------- audit logs
    Route::middleware('permission:audit_logs.view')->prefix('audit-logs')->name('audit-logs.')->group(function () {
        Route::get('/', [AuditLogController::class, 'index'])->name('index');
        Route::get('/{auditLog}', [AuditLogController::class, 'show'])->name('show');
    });

    // ----------------------------------------------------- users & permissions
    Route::middleware('permission:users.manage')->group(function () {
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::get('/users/create', [UserController::class, 'create'])->name('users.create');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::get('/users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
        Route::patch('/users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::patch('/users/{user}/permissions', [UserController::class, 'updatePermissions'])->name('users.permissions');
        Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
    });

    Route::middleware('permission:roles.manage')->group(function () {
        Route::get('/roles', [RoleController::class, 'index'])->name('roles.index');
        Route::get('/roles/create', [RoleController::class, 'create'])->name('roles.create');
        Route::post('/roles', [RoleController::class, 'store'])->name('roles.store');
        Route::get('/roles/{role}/edit', [RoleController::class, 'edit'])->name('roles.edit');
        Route::patch('/roles/{role}', [RoleController::class, 'update'])->name('roles.update');
        Route::delete('/roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy');
    });

    Route::middleware('permission:branches.manage')->group(function () {
        Route::get('/branches', [BranchController::class, 'index'])->name('branches.index');
        Route::get('/branches/create', [BranchController::class, 'create'])->name('branches.create');
        Route::post('/branches', [BranchController::class, 'store'])->name('branches.store');
        Route::get('/branches/{branch}/edit', [BranchController::class, 'edit'])->name('branches.edit');
        Route::patch('/branches/{branch}', [BranchController::class, 'update'])->name('branches.update');
    });

    // --------------------------------------------------------------- settings
    Route::middleware('permission:settings.manage')->prefix('settings')->name('settings.')->group(function () {
        Route::get('/', [SettingsController::class, 'edit'])->name('edit');
        Route::patch('/', [SettingsController::class, 'update'])->name('update');
        Route::post('/payment-methods', [SettingsController::class, 'storePaymentMethod'])->name('payment-methods.store');
        Route::patch('/payment-methods/{paymentMethod}', [SettingsController::class, 'updatePaymentMethod'])->name('payment-methods.update');
        Route::post('/expense-categories', [SettingsController::class, 'storeExpenseCategory'])->name('expense-categories.store');
        Route::patch('/expense-categories/{expenseCategory}', [SettingsController::class, 'updateExpenseCategory'])->name('expense-categories.update');
    });
});
