<?php

namespace Tests\Feature;

use App\Models\BookingRequest;
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
 * The trainer's say on a reschedule, and the office's afterwards.
 *
 * Moving a lesson touches two decisions in order: the trainer owns the diary,
 * the office owns the calendar. What these tests protect is the order and the
 * ownership — the office cannot apply a move the trainer has not agreed to, a
 * trainer cannot answer for a colleague, and the record names who agreed rather
 * than only that someone did.
 */
class TrainerDecisionTest extends TestCase
{
    use RefreshDatabase;

    protected Trainer $trainer;

    protected Trainee $trainee;

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
    }

    protected function settings(): \App\Services\SettingsRepository
    {
        return app(\App\Services\SettingsRepository::class);
    }

    protected function scheduledLesson(): TrainingSession
    {
        $package = app(\App\Services\PackageService::class)->assign(
            $this->trainee->fresh(),
            Package::factory()->create(['lessons_count' => 10, 'price' => 200]),
            ['discount_percent' => 0],
        );

        return TrainingSession::create([
            'branch_id' => $this->branch->id,
            'trainee_id' => $this->trainee->id,
            'trainer_id' => $this->trainer->id,
            'trainee_package_id' => $package->id,
            'scheduled_date' => $this->workingDay(minimumOffset: 2)->toDateString(),
            'start_time' => '09:00',
            'end_time' => '09:45',
            'duration_minutes' => 45,
            'status' => 'scheduled',
        ]);
    }

    protected function actingAsTrainee(): void
    {
        $user = $this->userWithRole('trainee');
        $this->trainee->forceFill(['user_id' => $user->id])->save();

        Sanctum::actingAs($user->fresh());
    }

    /** The trainer who owns the lesson, signed in on the trainer app. */
    protected function actingAsOwningTrainer(): \App\Models\User
    {
        $user = $this->userWithRole('trainer');
        $this->trainer->forceFill(['user_id' => $user->id])->save();

        Sanctum::actingAs($user->fresh());

        return $user->fresh();
    }

    /**
     * Raise a reschedule the way the app does: a lesson, a new date, a new time.
     *
     * @return array{0: BookingRequest, 1: TrainingSession}
     */
    protected function raiseReschedule(): array
    {
        $lesson = $this->scheduledLesson();

        $this->actingAsTrainee();

        $this->postJson('/api/v1/booking-requests', [
            'type' => 'reschedule',
            'training_session_id' => $lesson->uuid,
            'requested_date' => $this->workingDay(minimumOffset: 5)->toDateString(),
            'requested_start_time' => '13:00',
        ])->assertCreated();

        return [BookingRequest::firstOrFail(), $lesson];
    }

    // ------------------------------------------------------------ the request

    /**
     * A reschedule must name the slot it wants.
     *
     * The apps use date and time pickers rather than a note, so a request with no
     * new slot is a bug on the client — refused here too, because the office
     * cannot act on a request that does not say when.
     */
    public function test_a_reschedule_without_a_date_and_time_is_refused(): void
    {
        $lesson = $this->scheduledLesson();

        $this->actingAsTrainee();

        $this->postJson('/api/v1/booking-requests', [
            'type' => 'reschedule',
            'training_session_id' => $lesson->uuid,
            'trainee_note' => 'أرجو التأجيل',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['requested_date', 'requested_start_time']);

        $this->assertSame(0, BookingRequest::count());
    }

    public function test_a_reschedule_waits_on_the_trainer(): void
    {
        [$request] = $this->raiseReschedule();

        $this->assertSame(BookingRequest::TRAINER_PENDING, $request->trainer_decision);
        $this->assertTrue($request->awaitingTrainer());
        $this->assertFalse($request->readyForOffice());
    }

    /** A plain booking has no lesson to defend, so no trainer is asked. */
    public function test_a_plain_booking_does_not_wait_on_a_trainer(): void
    {
        $this->actingAsTrainee();

        $this->postJson('/api/v1/booking-requests', [
            'type' => 'booking',
            'requested_date' => $this->workingDay(minimumOffset: 3)->toDateString(),
            'requested_start_time' => '10:00',
        ])->assertCreated();

        $request = BookingRequest::firstOrFail();

        $this->assertNull($request->trainer_decision);
        $this->assertFalse($request->awaitingTrainer());
        $this->assertTrue($request->readyForOffice());
    }

    // -------------------------------------------------------------- the queue

    public function test_the_trainer_sees_only_requests_against_their_own_lessons(): void
    {
        [$request] = $this->raiseReschedule();

        $this->actingAsOwningTrainer();

        $this->getJson('/api/v1/me/decisions')
            ->assertOk()
            ->assertJsonPath('meta.awaiting_count', 1)
            ->assertJsonPath('data.0.id', $request->uuid);

        // A colleague's queue is empty, rather than showing the request greyed
        // out: they have no business reading it at all.
        $colleagueUser = $this->userWithRole('trainer');
        Trainer::factory()->create([
            'branch_id' => $this->branch->id,
            'user_id' => $colleagueUser->id,
        ]);

        Sanctum::actingAs($colleagueUser->fresh());

        $this->getJson('/api/v1/me/decisions')
            ->assertOk()
            ->assertJsonPath('meta.awaiting_count', 0)
            ->assertJsonCount(0, 'data');
    }

    public function test_a_colleague_cannot_answer_for_another_trainers_diary(): void
    {
        [$request] = $this->raiseReschedule();

        $colleagueUser = $this->userWithRole('trainer');
        Trainer::factory()->create([
            'branch_id' => $this->branch->id,
            'user_id' => $colleagueUser->id,
        ]);

        Sanctum::actingAs($colleagueUser->fresh());

        $this->postJson("/api/v1/me/decisions/{$request->uuid}/approve")->assertStatus(422);

        $this->assertSame(BookingRequest::TRAINER_PENDING, $request->fresh()->trainer_decision);
    }

    // ----------------------------------------------------------- the decision

    public function test_the_trainer_approves_and_the_record_names_them(): void
    {
        [$request] = $this->raiseReschedule();

        $trainerUser = $this->actingAsOwningTrainer();

        $this->postJson("/api/v1/me/decisions/{$request->uuid}/approve", [
            'note' => 'الموعد الجديد مناسب.',
        ])
            ->assertOk()
            ->assertJsonPath('data.trainer_decision', 'approved')
            ->assertJsonPath('data.awaiting_trainer', false)
            ->assertJsonPath('data.ready_for_office', true);

        $request->refresh();

        $this->assertTrue($request->trainerApproved());
        $this->assertSame($trainerUser->id, $request->trainer_decided_by);
        $this->assertNotNull($request->trainer_decided_at);
        $this->assertSame('الموعد الجديد مناسب.', $request->trainer_note);

        // Still the office's to apply: the lesson has not moved.
        $this->assertSame('pending', $request->status);
    }

    /** The office is told, and told who agreed — that is the whole ask. */
    public function test_the_office_is_notified_naming_the_trainer(): void
    {
        [$request] = $this->raiseReschedule();

        Notification::fake();

        $receptionist = $this->userWithRole('receptionist');

        $this->actingAsOwningTrainer();

        $this->postJson("/api/v1/me/decisions/{$request->uuid}/approve")->assertOk();

        Notification::assertSentTo($receptionist, function (SystemNotification $notification) {
            return $notification->type === 'booking_request.trainer_approved'
                && str_contains($notification->body, 'عمر الزعبي');
        });
    }

    public function test_a_rejection_needs_a_reason(): void
    {
        [$request] = $this->raiseReschedule();

        $this->actingAsOwningTrainer();

        $this->postJson("/api/v1/me/decisions/{$request->uuid}/reject", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('note');

        $this->postJson("/api/v1/me/decisions/{$request->uuid}/reject", [
            'note' => 'عندي حصة أخرى في نفس الوقت.',
        ])->assertOk();

        $this->assertTrue($request->fresh()->trainerRejected());
    }

    /** Two taps on a slow connection must not both land. */
    public function test_a_decision_cannot_be_taken_twice(): void
    {
        [$request] = $this->raiseReschedule();

        $this->actingAsOwningTrainer();

        $this->postJson("/api/v1/me/decisions/{$request->uuid}/approve")->assertOk();

        $this->postJson("/api/v1/me/decisions/{$request->uuid}/reject", [
            'note' => 'تغيّر الموقف.',
        ])->assertStatus(422);

        $this->assertTrue($request->fresh()->trainerApproved());
    }

    // ------------------------------------------------------------- the office

    /**
     * The office cannot move a lesson the trainer has not answered on.
     *
     * This is the rule the whole feature exists for, so it is asserted on the
     * calendar and not only on the flash message: the lesson keeps its slot.
     */
    public function test_the_office_cannot_apply_a_reschedule_still_awaiting_the_trainer(): void
    {
        [$request, $lesson] = $this->raiseReschedule();

        app('auth')->forgetGuards();
        $this->actingAsUser($this->admin());

        $this->post(route('admin.booking-requests.approve', $request), [
            'trainer_id' => $this->trainer->id,
            'scheduled_date' => $this->workingDay(minimumOffset: 5)->toDateString(),
            'start_time' => '13:00',
        ])->assertSessionHasErrors();

        $this->assertSame('pending', $request->fresh()->status);
        $this->assertSame('09:00:00', substr((string) $lesson->fresh()->start_time, 0, 8));
    }

    public function test_the_office_cannot_apply_a_reschedule_the_trainer_refused(): void
    {
        [$request] = $this->raiseReschedule();

        $this->actingAsOwningTrainer();
        $this->postJson("/api/v1/me/decisions/{$request->uuid}/reject", [
            'note' => 'عندي التزام آخر.',
        ])->assertOk();

        app('auth')->forgetGuards();
        $this->actingAsUser($this->admin());

        $this->post(route('admin.booking-requests.approve', $request), [
            'trainer_id' => $this->trainer->id,
            'scheduled_date' => $this->workingDay(minimumOffset: 5)->toDateString(),
            'start_time' => '13:00',
        ])->assertSessionHasErrors();

        $this->assertSame('pending', $request->fresh()->status);
    }

    /** Once the trainer has agreed, the office applies it and the lesson moves. */
    public function test_the_office_applies_an_approved_reschedule(): void
    {
        [$request, $lesson] = $this->raiseReschedule();

        $this->actingAsOwningTrainer();
        $this->postJson("/api/v1/me/decisions/{$request->uuid}/approve")->assertOk();

        app('auth')->forgetGuards();
        $this->actingAsUser($this->admin());

        $newDate = $this->workingDay(minimumOffset: 5)->toDateString();

        $this->post(route('admin.booking-requests.approve', $request), [
            'trainer_id' => $this->trainer->id,
            'scheduled_date' => $newDate,
            'start_time' => '13:00',
        ])->assertSessionHasNoErrors();

        $this->assertSame('rescheduled', $request->fresh()->status);

        $lesson->refresh();
        $this->assertSame($newDate, $lesson->scheduled_date->toDateString());
        $this->assertSame('13:00:00', substr((string) $lesson->start_time, 0, 8));

        // The agreement survives the office's action: an audit a month later
        // still shows which trainer signed off.
        $this->assertSame(BookingRequest::TRAINER_APPROVED, $request->fresh()->trainer_decision);
    }

    // ------------------------------------------------- the office decisions page

    /**
     * The page the office reads to carry out what the trainers agreed to.
     *
     * Its job is to name the trainer and put the two slots next to each other,
     * so those are what is asserted rather than the layout.
     */
    public function test_the_decisions_page_lists_what_the_trainer_approved(): void
    {
        [$request] = $this->raiseReschedule();

        $this->actingAsOwningTrainer();
        $this->postJson("/api/v1/me/decisions/{$request->uuid}/approve", [
            'note' => 'الموعد الجديد مناسب.',
        ])->assertOk();

        app('auth')->forgetGuards();
        $this->actingAsUser($this->admin());

        $this->get(route('admin.booking-requests.decisions'))
            ->assertOk()
            ->assertSee('وافق المدرب', false)
            ->assertSee('عمر الزعبي', false)
            ->assertSee('سارة الخطيب', false)
            ->assertSee('الموعد الجديد مناسب.', false)
            // Approved by the trainer, still the office's to apply.
            ->assertSee('بانتظار تنفيذ الإدارة', false)
            ->assertSee('تنفيذ التأجيل', false);
    }

    /** Refused requests are on their own tab, and offer no "apply" button. */
    public function test_a_refused_request_is_listed_without_a_way_to_apply_it(): void
    {
        [$request] = $this->raiseReschedule();

        $this->actingAsOwningTrainer();
        $this->postJson("/api/v1/me/decisions/{$request->uuid}/reject", [
            'note' => 'عندي التزام آخر.',
        ])->assertOk();

        app('auth')->forgetGuards();
        $this->actingAsUser($this->admin());

        // Not on the approved tab, which is what the office works through.
        $this->get(route('admin.booking-requests.decisions'))
            ->assertOk()
            ->assertDontSee('سارة الخطيب', false);

        $this->get(route('admin.booking-requests.decisions', ['decision' => 'rejected']))
            ->assertOk()
            ->assertSee('رفض المدرب', false)
            ->assertSee('عندي التزام آخر.', false)
            ->assertDontSee('تنفيذ التأجيل', false);
    }

    /** One still waiting on the trainer shows as that, and offers no apply. */
    public function test_a_request_awaiting_the_trainer_is_listed_as_waiting(): void
    {
        $this->raiseReschedule();

        app('auth')->forgetGuards();
        $this->actingAsUser($this->admin());

        $this->get(route('admin.booking-requests.decisions', ['decision' => 'pending']))
            ->assertOk()
            ->assertSee('بانتظار قرار المدرب', false)
            ->assertDontSee('تنفيذ التأجيل', false);
    }

    /** A plain booking never reaches this page: no trainer was asked. */
    public function test_a_plain_booking_is_not_listed(): void
    {
        $this->actingAsTrainee();

        $this->postJson('/api/v1/booking-requests', [
            'type' => 'booking',
            'requested_date' => $this->workingDay(minimumOffset: 3)->toDateString(),
            'requested_start_time' => '10:00',
        ])->assertCreated();

        app('auth')->forgetGuards();
        $this->actingAsUser($this->admin());

        foreach (['approved', 'pending', 'rejected'] as $decision) {
            $this->get(route('admin.booking-requests.decisions', ['decision' => $decision]))
                ->assertOk()
                ->assertDontSee('سارة الخطيب', false);
        }
    }

    public function test_the_page_needs_permission(): void
    {
        // A trainer answers requests in the app; the office page is not theirs.
        $this->actingAsUser($this->userWithRole('trainer'));

        $this->get(route('admin.booking-requests.decisions'))->assertForbidden();
    }

    /** The dashboard row states it in words a receptionist can read. */
    public function test_the_queue_page_shows_who_approved_it(): void
    {
        [$request] = $this->raiseReschedule();

        $this->actingAsOwningTrainer();
        $this->postJson("/api/v1/me/decisions/{$request->uuid}/approve")->assertOk();

        app('auth')->forgetGuards();
        $this->actingAsUser($this->admin());

        $this->get(route('admin.booking-requests.index'))
            ->assertOk()
            ->assertSee('وافق المدرب', false)
            ->assertSee('عمر الزعبي', false);
    }
}
