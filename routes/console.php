<?php

use App\Console\Commands\CheckFinancialAlerts;
use App\Console\Commands\GenerateRecurringExpenses;
use App\Console\Commands\SendLessonReminders;
use App\Console\Commands\VerifyFinancialIntegrity;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled tasks
|--------------------------------------------------------------------------
|
| Driven by a single cron entry (see the README):
|   * * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
|
| withoutOverlapping() guards the long-running sweeps so a slow night cannot
| stack two copies on top of each other.
|
*/

// Lesson reminders: hourly, because the reminder window is configurable and a
// daily run would miss lessons booked the same day.
Schedule::command(SendLessonReminders::class)
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

// Recurring expenses: every morning before the office opens.
Schedule::command(GenerateRecurringExpenses::class)
    ->dailyAt('06:00')
    ->withoutOverlapping();

// Balance, bill and salary alerts: after the recurring expenses have posted,
// so a freshly generated expense is reflected in the same day's alerts.
Schedule::command(CheckFinancialAlerts::class)
    ->dailyAt('07:00')
    ->withoutOverlapping();

// Integrity check: nightly, and again the first of the month. It only reports —
// a failure is meant to be read, not silently repaired.
Schedule::command(VerifyFinancialIntegrity::class)
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->emailOutputOnFailure(config('mail.admin_address') ?: null);

// Housekeeping.
Schedule::command('auth:clear-resets')->daily();
Schedule::command('sanctum:prune-expired --hours=24')->daily();
Schedule::command('otp:prune')->daily();
Schedule::command('queue:prune-batches --hours=48')->daily();
