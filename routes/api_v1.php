<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BookingRequestController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\FinanceController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\TraineeController;
use App\Http\Controllers\Api\V1\TrainerController;
use App\Http\Controllers\Api\V1\TrainingSessionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1
|--------------------------------------------------------------------------
|
| Consumed by the Trainer and Trainee Flutter apps. Every endpoint returns the
| same envelope (success / message / data / meta) and every protected route
| declares the permission it needs — the same permission the dashboard uses, so
| the two surfaces can never drift apart.
|
*/

Route::post('auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:10,1')
    ->name('auth.login');

Route::middleware(['auth:sanctum', 'active'])->group(function () {

    // ------------------------------------------------------------------ auth
    Route::prefix('auth')->name('auth.')->group(function () {
        Route::get('me', [AuthController::class, 'me'])->name('me');
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');
        Route::post('logout-all', [AuthController::class, 'logoutAll'])->name('logout-all');
        Route::post('change-password', [AuthController::class, 'changePassword'])->name('change-password');
    });

    // ------------------------------------------------------------- dashboards
    Route::get('dashboard', [DashboardController::class, 'index'])
        ->middleware('permission:dashboard.view')->name('dashboard');

    Route::get('home/trainer', [DashboardController::class, 'trainerHome'])->name('home.trainer');
    Route::get('home/trainee', [DashboardController::class, 'traineeHome'])->name('home.trainee');

    // --------------------------------------------------------------- trainees
    Route::post('trainees', [TraineeController::class, 'store'])
        ->middleware('permission:trainees.create')->name('trainees.store');

    Route::patch('trainees/{trainee}', [TraineeController::class, 'update'])
        ->middleware('permission:trainees.update')->name('trainees.update');

    Route::middleware('permission:trainees.view')->group(function () {
        Route::get('trainees', [TraineeController::class, 'index'])->name('trainees.index');
        Route::get('trainees/{trainee}/sessions', [TraineeController::class, 'sessions'])->name('trainees.sessions');
        Route::get('trainees/{trainee}/packages', [TraineeController::class, 'packages'])->name('trainees.packages');
        Route::get('trainees/{trainee}/evaluations', [TraineeController::class, 'evaluations'])->name('trainees.evaluations');
        Route::get('trainees/{trainee}', [TraineeController::class, 'show'])->name('trainees.show');
    });

    // --------------------------------------------------------------- trainers
    Route::get('vehicles', [TrainerController::class, 'vehicles'])
        ->middleware('permission:vehicles.view')->name('vehicles.index');

    Route::middleware('permission:trainers.view')->group(function () {
        Route::get('trainers', [TrainerController::class, 'index'])->name('trainers.index');
        Route::get('trainers/{trainer}/schedule', [TrainerController::class, 'schedule'])->name('trainers.schedule');
        Route::get('trainers/{trainer}', [TrainerController::class, 'show'])->name('trainers.show');
    });

    Route::get('training-skills', [TrainingSessionController::class, 'skills'])
        ->middleware('permission:evaluations.view')->name('training-skills');

    /*
     | Appointments and training sessions are the same underlying resource — see
     | the README. Both prefixes are exposed because the two apps think in
     | different terms: the Trainee app books "appointments", the Trainer app
     | delivers "training sessions".
     */
    foreach (['appointments', 'training-sessions'] as $prefix) {
        Route::prefix($prefix)->name($prefix.'.')->group(function () {
            Route::post('/', [TrainingSessionController::class, 'store'])
                ->middleware('permission:appointments.create')->name('store');

            Route::middleware('permission:appointments.view')->group(function () {
                Route::get('/', [TrainingSessionController::class, 'index'])->name('index');
                Route::get('today', [TrainingSessionController::class, 'today'])->name('today');
                Route::get('available-slots', [TrainingSessionController::class, 'availableSlots'])->name('slots');
                Route::get('{session}', [TrainingSessionController::class, 'show'])->name('show');
            });

            Route::patch('{session}', [TrainingSessionController::class, 'update'])
                ->middleware('permission:appointments.update')->name('update');

            Route::post('{session}/cancel', [TrainingSessionController::class, 'cancel'])
                ->middleware('permission:appointments.cancel')->name('cancel');

            Route::middleware('permission:appointments.complete')->group(function () {
                Route::post('{session}/complete', [TrainingSessionController::class, 'complete'])->name('complete');
                Route::post('{session}/no-show', [TrainingSessionController::class, 'noShow'])->name('no-show');
            });
        });
    }

    // -------------------------------------------------------- booking requests
    Route::prefix('booking-requests')->name('booking-requests.')->group(function () {
        Route::get('/', [BookingRequestController::class, 'index'])->name('index');
        Route::post('/', [BookingRequestController::class, 'store'])->name('store');
        Route::post('{bookingRequest}/withdraw', [BookingRequestController::class, 'withdraw'])->name('withdraw');
        Route::get('{bookingRequest}', [BookingRequestController::class, 'show'])->name('show');
    });

    // ---------------------------------------------------------------- finance
    Route::post('payments', [FinanceController::class, 'storePayment'])
        ->middleware('permission:payments.create')->name('payments.store');

    Route::post('payments/{payment}/void', [FinanceController::class, 'voidPayment'])
        ->middleware('permission:payments.void')->name('payments.void');

    Route::get('payments', [FinanceController::class, 'payments'])
        ->middleware('permission:payments.view')->name('payments.index');

    Route::post('expenses', [FinanceController::class, 'storeExpense'])
        ->middleware('permission:expenses.create')->name('expenses.store');

    Route::get('expenses', [FinanceController::class, 'expenses'])
        ->middleware('permission:expenses.view')->name('expenses.index');

    Route::get('payroll', [FinanceController::class, 'payroll'])
        ->middleware('permission:payroll.view')->name('payroll.index');

    Route::get('cashbox', [FinanceController::class, 'cashbox'])
        ->middleware('permission:cashbox.view')->name('cashbox.index');

    // ---------------------------------------------------------------- reports
    Route::prefix('reports')->name('reports.')->group(function () {
        Route::get('revenue', [FinanceController::class, 'revenueReport'])
            ->middleware('permission:reports.financial')->name('revenue');

        Route::get('expenses', [FinanceController::class, 'expensesReport'])
            ->middleware('permission:reports.financial')->name('expenses');

        // Profit is deliberately a separate permission from revenue.
        Route::get('profit', [FinanceController::class, 'profitReport'])
            ->middleware('permission:profit.view')->name('profit');
    });

    // ---------------------------------------------------------- notifications
    Route::prefix('notifications')->name('notifications.')->group(function () {
        Route::get('/', [NotificationController::class, 'index'])->name('index');
        Route::post('read-all', [NotificationController::class, 'markAllRead'])->name('read-all');
        Route::post('device', [NotificationController::class, 'registerDevice'])->name('device');
        Route::post('{id}/read', [NotificationController::class, 'markRead'])->name('read');
    });
});
