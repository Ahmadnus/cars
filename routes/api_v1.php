<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BookingRequestController;
use App\Http\Controllers\Api\V1\ChatController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\FinanceController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\PublicRegistrationController;
use App\Http\Controllers\Api\V1\RegistrationReviewController;
use App\Http\Controllers\Api\V1\OtpController;
use App\Http\Controllers\Api\V1\TraineeController;
use App\Http\Controllers\Api\V1\TrainerController;
use App\Http\Controllers\Api\V1\TrainerSelfController;
use App\Http\Controllers\Api\V1\TrainingSessionController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
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

// Phone + passcode, which is how trainees sign in — they have no password.
// Throttled tighter than the password flow because each request can cost a
// provider message.
Route::post('auth/otp/request', [OtpController::class, 'request'])
    ->middleware('throttle:6,1')
    ->name('auth.otp.request');

Route::post('auth/otp/verify', [OtpController::class, 'verify'])
    ->middleware('throttle:10,1')
    ->name('auth.otp.verify');

/*
|--------------------------------------------------------------------------
| Public — no account required
|--------------------------------------------------------------------------
|
| Anyone who installs the Trainee app can apply to join. Throttles are tight
| because this is the only unauthenticated write surface in the system, and a
| join request costs a real SMS.
|
*/

Route::prefix('public/registrations')->name('public.registrations.')->group(function () {
    Route::get('options', [PublicRegistrationController::class, 'options'])
        ->middleware('throttle:30,1')->name('options');

    Route::post('request-code', [PublicRegistrationController::class, 'requestCode'])
        ->middleware('throttle:5,1')->name('request-code');

    Route::post('/', [PublicRegistrationController::class, 'store'])
        ->middleware('throttle:5,1')->name('store');

    // The reference is the credential, so guessing is rate limited too.
    Route::get('{reference}', [PublicRegistrationController::class, 'status'])
        ->middleware('throttle:20,1')->name('status');
});

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

    /*
     | The signed-in trainee's own file, for the Trainee app.
     |
     | These carry no `permission:` middleware because there is nothing to
     | authorise beyond being a trainee: no route takes an id, so each endpoint
     | can only ever return the caller's own record. Granting `trainees.view`
     | instead would have opened every trainee in the center.
     */
    Route::prefix('me')->name('me.')->group(function () {
        Route::get('home', [MeController::class, 'home'])->name('home');
        Route::get('sessions', [MeController::class, 'sessions'])->name('sessions');
        Route::get('sessions/{session}', [MeController::class, 'show'])->name('sessions.show');
        Route::get('skills', [MeController::class, 'skills'])->name('skills');
        Route::get('packages', [MeController::class, 'packages'])->name('packages');
        Route::get('payments', [MeController::class, 'payments'])->name('payments');

        // Booking screen. `slots` is the trainee-scoped twin of the
        // center-wide availability endpoint — see MeController::slots().
        Route::get('slots', [MeController::class, 'slots'])->name('slots');
        Route::get('trainers', [MeController::class, 'trainers'])->name('trainers');
        Route::get('booking-requests', [MeController::class, 'bookingRequests'])->name('booking-requests');

        // A trainer's own pay. Same no-id rule, so this needs no
        // trainer_compensation.view — see TrainerSelfController.
        Route::get('compensation', [TrainerSelfController::class, 'statement'])->name('compensation');
        Route::get('compensation/history', [TrainerSelfController::class, 'statements'])->name('compensation.history');
    });

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

    /*
     | Socket subscription authorization for mobile clients.
     |
     | Laravel's own `broadcasting/auth` sits on the web group and expects a
     | session cookie. The apps hold a bearer token, so they need the same
     | callback reached through the API guard — the channel rules in
     | routes/channels.php are shared, so both doors enforce the same checks.
     */
    Route::post('broadcasting/auth', function (Request $request) {
        return Broadcast::auth($request);
    })->name('broadcasting.auth');

    /*
     | Chat.
     |
     | No `permission:` middleware: membership of the thread is the
     | authorization, checked in the controller against the conversation
     | itself. A staff account is not a participant and is refused here —
     | oversight lives in the dashboard behind `chat.monitor`.
     */
    Route::prefix('chat')->name('chat.')->group(function () {
        Route::get('conversations', [ChatController::class, 'index'])->name('index');
        Route::get('my-conversation', [ChatController::class, 'mine'])->name('mine');

        Route::get('conversations/{conversation}/messages', [ChatController::class, 'messages'])
            ->name('messages');

        Route::post('conversations/{conversation}/messages', [ChatController::class, 'store'])
            // A burst of messages is normal; a flood is not.
            ->middleware('throttle:60,1')->name('messages.store');

        Route::post('conversations/{conversation}/read', [ChatController::class, 'markRead'])
            ->name('read');

        // Attachments are re-authorised on every fetch, so a leaked URL is
        // useless to anyone outside the conversation.
        Route::get('attachments/{message}', [ChatController::class, 'attachment'])
            ->name('attachment');
    });

    // -------------------------------------------------- registration review
    Route::prefix('registrations')->name('registrations.')->group(function () {
        Route::middleware('permission:registrations.view')->group(function () {
            Route::get('/', [RegistrationReviewController::class, 'index'])->name('index');
            Route::get('{registrationRequest}', [RegistrationReviewController::class, 'show'])->name('show');
        });

        Route::middleware('permission:registrations.manage')->group(function () {
            Route::post('{registrationRequest}/claim', [RegistrationReviewController::class, 'claim'])->name('claim');
            Route::post('{registrationRequest}/approve', [RegistrationReviewController::class, 'approve'])->name('approve');
            Route::post('{registrationRequest}/reject', [RegistrationReviewController::class, 'reject'])->name('reject');
        });
    });

    // ---------------------------------------------------------- notifications
    Route::prefix('notifications')->name('notifications.')->group(function () {
        Route::get('/', [NotificationController::class, 'index'])->name('index');
        Route::post('read-all', [NotificationController::class, 'markAllRead'])->name('read-all');
        Route::post('device', [NotificationController::class, 'registerDevice'])->name('device');
        Route::delete('device', [NotificationController::class, 'forgetDevice'])->name('device.forget');
        Route::post('{id}/read', [NotificationController::class, 'markRead'])->name('read');
    });
});
