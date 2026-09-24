<?php

namespace App\Console\Commands;

use App\Models\TrainingSession;
use App\Services\NotificationService;
use App\Services\SettingsRepository;
use Illuminate\Console\Command;

/**
 * Reminds trainees and trainers about lessons coming up inside the configured
 * notice window.
 *
 * Runs hourly and only picks up lessons whose start time falls in the next
 * hour-slice of that window, so a lesson is reminded about exactly once.
 */
class SendLessonReminders extends Command
{
    protected $signature = 'lessons:send-reminders';

    protected $description = 'إرسال تذكير بالحصص التدريبية القادمة';

    public function handle(NotificationService $notifications, SettingsRepository $settings): int
    {
        $hours = $settings->int('notifications.lesson_reminder_hours', 24);

        $windowStart = now()->addHours($hours);
        $windowEnd = $windowStart->copy()->addHour();

        $sessions = TrainingSession::query()
            ->scheduled()
            ->with(['trainee.user', 'trainer.user'])
            ->whereBetween('scheduled_date', [
                $windowStart->toDateString(),
                $windowEnd->toDateString(),
            ])
            ->get()
            // The date filter is coarse; the exact start time decides.
            ->filter(fn (TrainingSession $session) => $session->startsAt()->between($windowStart, $windowEnd));

        foreach ($sessions as $session) {
            $notifications->appointmentReminder($session);
        }

        $this->info("تم إرسال {$sessions->count()} تذكير بالحصص القادمة.");

        return self::SUCCESS;
    }
}
