<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\Trainee;
use App\Models\Trainer;
use App\Models\TrainingSession;
use App\Notifications\SystemNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A trainer moving their own lesson, with nobody's approval.
 *
 * The center decided the diary belongs to the trainer: they are the one who
 * knows they cannot make 08:00, and routing that through the office only delays
 * the trainee being told. So there is no approval step — which makes the other
 * guarantees matter more, and those are what these tests pin: it is only ever
 * their own lesson, the office and the trainee are both told who moved it, and
 * the calendar's own rules still apply. A trainer with no approver above them
 * must not be able to double-book themselves.
 */
class TrainerRescheduleTest extends TestCase
{
    use RefreshDatabase;

    protected Trainer $trainer;

    protected Trainee $trainee;

    protected \App\Models\User $trainerUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFoundation();

        $this->settings()->set('notifications.enable_database', true);

        $this->trainer = Trainer::factory()->create([
            'branch_id' => $this->branch->id,
            'full_name' => 'عمر الزعبي',
        ]);

        $this->trainee = Trainee::factory()->create([
            'branch_id' => $this->branch->id,
            'trainer_id' => $this->trainer->id,
            'full_name' => 'سارة الخطيب',
        ]);

        $this->trainerUser = $this->userWithRole('trainer');
        $this->trainer->forceFill(['user_id' => $this->trainerUser->id])->save();
        $this->trainerUser = $this->trainerUser->fresh();
    }

    protected function settings(): \App\Services\SettingsRepository
    {
        return app(\App\Services\SettingsRepository::class);
    }

    protected function lesson(?Trainer $trainer = null, string $time = '09:00', int $offset = 2): TrainingSession
    {
        $package = app(\App\Services\PackageService::class)->assign(
            $this->trainee->fresh(),
            Package::factory()->create(['lessons_count' => 10, 'price' => 200]),
            ['discount_percent' => 0],
        );

        return TrainingSession::create([
            'branch_id' => $this->branch->id,
            'trainee_id' => $this->trainee->id,
            'trainer_id' => ($trainer ?? $this->trainer)->id,
            'trainee_package_id' => $package->id,
            'scheduled_date' => $this->workingDay(minimumOffset: $offset)->toDateString(),
            'start_time' => $time,
            'end_time' => substr($time, 0, 2).':45',
            'duration_minutes' => 45,
            'status' => 'scheduled',
        ]);
    }

    public function test_a_trainer_moves_their_own_lesson_without_asking_anyone(): void
    {
        $lesson = $this->lesson();
        $newDate = $this->workingDay(minimumOffset: 6)->toDateString();

        Sanctum::actingAs($this->trainerUser);

        $this->postJson("/api/v1/me/sessions/{$lesson->uuid}/reschedule", [
            'scheduled_date' => $newDate,
            'start_time' => '13:00',
            'reason' => 'لدي التزام صباحي',
        ])->assertOk();

        $lesson->refresh();

        $this->assertSame($newDate, $lesson->scheduled_date->toDateString());
        $this->assertSame('13:00:00', substr((string) $lesson->start_time, 0, 8));
        $this->assertSame('scheduled', $lesson->status, 'the lesson stays on the calendar');

        // No request was raised: nothing is waiting on anyone's approval.
        $this->assertSame(0, \App\Models\BookingRequest::count());
    }

    /** The trainee hears about it, because their morning just changed. */
    public function test_the_trainee_is_notified(): void
    {
        $lesson = $this->lesson();

        $traineeUser = $this->userWithRole('trainee');
        $this->trainee->forceFill(['user_id' => $traineeUser->id])->save();

        Notification::fake();

        Sanctum::actingAs($this->trainerUser);

        $this->postJson("/api/v1/me/sessions/{$lesson->uuid}/reschedule", [
            'scheduled_date' => $this->workingDay(minimumOffset: 6)->toDateString(),
            'start_time' => '13:00',
        ])->assertOk();

        Notification::assertSentTo($traineeUser, function (SystemNotification $notification) {
            return $notification->type === 'appointment.moved_by_trainer';
        });
    }

    /**
     * Nobody else hears about it — not the office, not other trainers.
     *
     * The recipient list used to include everyone holding `appointments.view`,
     * and the trainer role holds it: one trainee's schedule change was
     * announced to every trainer at the branch. The office keeps its visibility
     * through the audit log, which is asserted below.
     */
    public function test_nobody_else_is_notified(): void
    {
        $lesson = $this->lesson();

        $traineeUser = $this->userWithRole('trainee');
        $this->trainee->forceFill(['user_id' => $traineeUser->id])->save();

        Notification::fake();

        $receptionist = $this->userWithRole('receptionist');
        $manager = $this->userWithRole('center_manager');

        // Another trainer at the same branch: this trainee is not theirs.
        $colleagueUser = $this->userWithRole('trainer');
        Trainer::factory()->create([
            'branch_id' => $this->branch->id,
            'user_id' => $colleagueUser->id,
        ]);

        Sanctum::actingAs($this->trainerUser);

        $this->postJson("/api/v1/me/sessions/{$lesson->uuid}/reschedule", [
            'scheduled_date' => $this->workingDay(minimumOffset: 6)->toDateString(),
            'start_time' => '13:00',
        ])->assertOk();

        Notification::assertSentTo($traineeUser, SystemNotification::class);

        foreach ([$receptionist, $manager, $colleagueUser, $this->trainerUser] as $other) {
            Notification::assertNotSentTo($other, SystemNotification::class);
        }

        // Exactly one recipient, so a new branch in the fan-out cannot slip past
        // the checks above.
        Notification::assertSentTimes(SystemNotification::class, 1);
    }

    /** Addressed to the trainee, since they are the only one reading it. */
    public function test_the_trainee_is_told_what_changed(): void
    {
        $lesson = $this->lesson();
        $previous = $lesson->scheduled_date->format('Y-m-d').' 09:00';

        $traineeUser = $this->userWithRole('trainee');
        $this->trainee->forceFill(['user_id' => $traineeUser->id])->save();

        Notification::fake();

        Sanctum::actingAs($this->trainerUser);

        $this->postJson("/api/v1/me/sessions/{$lesson->uuid}/reschedule", [
            'scheduled_date' => $this->workingDay(minimumOffset: 6)->toDateString(),
            'start_time' => '13:00',
            'reason' => 'ظرف طارئ',
        ])->assertOk();

        Notification::assertSentTo($traineeUser, function (SystemNotification $notification) use ($previous) {
            return str_contains($notification->title, 'حصتك')
                && str_contains($notification->body, 'عمر الزعبي')
                // What it was, not only what it became.
                && str_contains($notification->body, $previous)
                && str_contains($notification->body, 'ظرف طارئ');
        });
    }

    public function test_the_move_is_audited(): void
    {
        $lesson = $this->lesson();

        Sanctum::actingAs($this->trainerUser);

        $this->postJson("/api/v1/me/sessions/{$lesson->uuid}/reschedule", [
            'scheduled_date' => $this->workingDay(minimumOffset: 6)->toDateString(),
            'start_time' => '13:00',
        ])->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'appointment.rescheduled',
            'user_id' => $this->trainerUser->id,
        ]);
    }

    // ---------------------------------------------------------- the limits

    /** A colleague's lesson is not theirs to move, and reads as not found. */
    public function test_a_trainer_cannot_move_someone_elses_lesson(): void
    {
        $colleague = Trainer::factory()->create(['branch_id' => $this->branch->id]);
        $theirs = $this->lesson($colleague);

        Sanctum::actingAs($this->trainerUser);

        $this->postJson("/api/v1/me/sessions/{$theirs->uuid}/reschedule", [
            'scheduled_date' => $this->workingDay(minimumOffset: 6)->toDateString(),
            'start_time' => '13:00',
        ])->assertNotFound();

        $theirs->refresh();
        $this->assertSame('09:00:00', substr((string) $theirs->start_time, 0, 8));
    }

    /**
     * The calendar's rules still apply.
     *
     * This is what makes "no approval" safe: nobody is checking behind the
     * trainer, so the service has to refuse a slot they are already teaching in.
     */
    public function test_a_trainer_cannot_double_book_themselves(): void
    {
        $morning = $this->lesson(time: '09:00');
        $afternoon = $this->lesson(time: '13:00');

        Sanctum::actingAs($this->trainerUser);

        // Move the morning lesson on top of the afternoon one.
        $this->postJson("/api/v1/me/sessions/{$morning->uuid}/reschedule", [
            'scheduled_date' => $afternoon->scheduled_date->toDateString(),
            'start_time' => '13:00',
        ])->assertStatus(422);

        $morning->refresh();
        $this->assertSame('09:00:00', substr((string) $morning->start_time, 0, 8));
    }

    public function test_a_lesson_cannot_be_moved_into_the_past(): void
    {
        $lesson = $this->lesson();

        Sanctum::actingAs($this->trainerUser);

        $this->postJson("/api/v1/me/sessions/{$lesson->uuid}/reschedule", [
            'scheduled_date' => now()->subDay()->toDateString(),
            'start_time' => '13:00',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('scheduled_date');
    }

    /** A trainee's token cannot reach this, whatever id it sends. */
    public function test_a_trainee_cannot_move_a_lesson(): void
    {
        $lesson = $this->lesson();

        $traineeUser = $this->userWithRole('trainee');
        $this->trainee->forceFill(['user_id' => $traineeUser->id])->save();

        Sanctum::actingAs($traineeUser->fresh());

        $this->postJson("/api/v1/me/sessions/{$lesson->uuid}/reschedule", [
            'scheduled_date' => $this->workingDay(minimumOffset: 6)->toDateString(),
            'start_time' => '13:00',
        ])->assertForbidden();

        $lesson->refresh();
        $this->assertSame('09:00:00', substr((string) $lesson->start_time, 0, 8));
    }

    /** A lesson already recorded is history, not a diary entry. */
    public function test_a_completed_lesson_cannot_be_moved(): void
    {
        $lesson = $this->lesson();
        $lesson->forceFill(['status' => 'completed'])->save();

        Sanctum::actingAs($this->trainerUser);

        $this->postJson("/api/v1/me/sessions/{$lesson->uuid}/reschedule", [
            'scheduled_date' => $this->workingDay(minimumOffset: 6)->toDateString(),
            'start_time' => '13:00',
        ])->assertStatus(422);
    }
}
