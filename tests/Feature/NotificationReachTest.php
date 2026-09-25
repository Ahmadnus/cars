<?php

namespace Tests\Feature;

use App\Events\BookingRequestUpdated;
use App\Models\BookingRequest;
use App\Models\Package;
use App\Models\Trainee;
use App\Models\Trainer;
use App\Notifications\SystemNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Who actually hears about what.
 *
 * A notification that fires into the void is worse than none: staff stop
 * trusting the bell. These tests assert the *recipients*, not just that
 * something was sent — and that the ones who must not hear about a thing do not.
 */
class NotificationReachTest extends TestCase
{
    use RefreshDatabase;

    protected Trainee $trainee;

    protected Trainer $trainer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFoundation();

        // The database channel is what the bell reads, so it has to be on.
        $this->settings()->set('notifications.enable_database', true);

        $this->trainer = Trainer::factory()->create(['branch_id' => $this->branch->id]);
        $this->trainee = Trainee::factory()->create([
            'branch_id' => $this->branch->id,
            'trainer_id' => $this->trainer->id,
        ]);
    }

    protected function settings(): \App\Services\SettingsRepository
    {
        return app(\App\Services\SettingsRepository::class);
    }

    /**
     * A scheduled lesson, which a reschedule or cancellation request must name.
     *
     * The API requires it: asking to move "a lesson" without saying which is not
     * an actionable request.
     */
    protected function scheduledLesson(): \App\Models\TrainingSession
    {
        $package = app(\App\Services\PackageService::class)->assign(
            $this->trainee->fresh(),
            Package::factory()->create(['lessons_count' => 10, 'price' => 200]),
            ['discount_percent' => 0],
        );

        return \App\Models\TrainingSession::create([
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

    /** A trainee who can raise a request from the app. */
    protected function actingAsTrainee(): void
    {
        $user = $this->userWithRole('trainee');
        $this->trainee->forceFill(['user_id' => $user->id])->save();

        Sanctum::actingAs($user->fresh());
    }

    // ------------------------------------------------------- booking requests

    public function test_a_booking_request_notifies_the_staff_who_can_act_on_it(): void
    {
        Notification::fake();
        Event::fake([BookingRequestUpdated::class]);

        $receptionist = $this->userWithRole('receptionist');
        $manager = $this->userWithRole('center_manager');

        // A trainer cannot approve bookings, so should not be pulled in.
        $otherTrainerUser = $this->userWithRole('trainer');

        $this->actingAsTrainee();

        $this->postJson('/api/v1/booking-requests', [
            'type' => 'booking',
            'requested_date' => $this->workingDay(minimumOffset: 3)->toDateString(),
            'requested_start_time' => '10:00',
            'trainee_note' => 'أفضّل الصباح',
        ])->assertCreated();

        Notification::assertSentTo($receptionist, function (SystemNotification $notification) {
            return str_contains($notification->title, 'حجز');
        });

        Notification::assertSentTo($manager, SystemNotification::class);
        Notification::assertNotSentTo($otherTrainerUser, SystemNotification::class);

        // And the dashboard queue is told, so the row appears without a refresh.
        Event::assertDispatched(BookingRequestUpdated::class);
    }

    /**
     * A reschedule must not read as a plain new booking.
     *
     * The distinction matters operationally: a reschedule means a lesson already
     * on the calendar is about to be wrong.
     */
    public function test_a_reschedule_request_says_so_in_the_title(): void
    {
        Notification::fake();

        $receptionist = $this->userWithRole('receptionist');

        $this->actingAsTrainee();

        $lesson = $this->scheduledLesson();

        $this->postJson('/api/v1/booking-requests', [
            'type' => 'reschedule',
            'training_session_id' => $lesson->uuid,
            'requested_date' => $this->workingDay(minimumOffset: 4)->toDateString(),
            'requested_start_time' => '12:00',
        ])->assertCreated();

        Notification::assertSentTo($receptionist, function (SystemNotification $notification) {
            return str_contains($notification->title, 'تأجيل')
                && $notification->type === 'booking_request.reschedule';
        });
    }

    public function test_a_cancellation_request_says_so_too(): void
    {
        Notification::fake();

        $receptionist = $this->userWithRole('receptionist');

        $this->actingAsTrainee();

        $lesson = $this->scheduledLesson();

        $this->postJson('/api/v1/booking-requests', [
            'type' => 'cancellation',
            'training_session_id' => $lesson->uuid,
            'trainee_note' => 'ظرف طارئ',
        ])->assertCreated();

        Notification::assertSentTo(
            $receptionist,
            fn (SystemNotification $notification) => str_contains($notification->title, 'إلغاء'),
        );
    }

    /** The decision comes back to the trainee who asked. */
    public function test_resolving_a_request_notifies_the_trainee(): void
    {
        $this->actingAsTrainee();

        $created = $this->postJson('/api/v1/booking-requests', [
            'type' => 'booking',
            'requested_date' => $this->workingDay(minimumOffset: 3)->toDateString(),
            'requested_start_time' => '10:00',
        ])->assertCreated();

        Notification::fake();

        $request = BookingRequest::firstOrFail();

        app(\App\Services\NotificationService::class)->bookingRequestResolved(
            $request->forceFill(['status' => 'rejected'])->fresh(),
        );

        Notification::assertSentTo($this->trainee->fresh()->user, SystemNotification::class);

        $this->assertNotNull($created->json('data.id'));
    }

    // ---------------------------------------------------------- registrations

    public function test_a_join_request_notifies_the_staff_who_may_approve_it(): void
    {
        Notification::fake();

        config(['otp.expose_code' => true, 'otp.enable_fixed_codes' => false]);

        $receptionist = $this->userWithRole('receptionist');

        // A supervisor may read the queue but not decide, so is not notified.
        $supervisor = $this->userWithRole('training_supervisor');

        $code = $this->postJson('/api/v1/public/registrations/request-code', [
            'phone' => '0791112223',
        ])->assertOk()->json('data.code');

        $this->postJson('/api/v1/public/registrations', [
            'code' => $code,
            'full_name' => 'طالب جديد للاختبار',
            'phone' => '0791112223',
            'branch_id' => $this->branch->uuid,
        ])->assertCreated();

        Notification::assertSentTo(
            $receptionist,
            fn (SystemNotification $notification) => $notification->type === 'registration.submitted',
        );

        Notification::assertNotSentTo($supervisor, SystemNotification::class);
    }

    // ------------------------------------------------------------- the others

    public function test_being_assigned_a_package_notifies_the_trainee(): void
    {
        Notification::fake();

        $this->actingAsTrainee();

        app(\App\Services\PackageService::class)->assign(
            $this->trainee->fresh(),
            Package::factory()->create(['lessons_count' => 10, 'price' => 200]),
            ['discount_percent' => 0],
        );

        Notification::assertSentTo(
            $this->trainee->fresh()->user,
            fn (SystemNotification $notification) => $notification->type === 'package.assigned',
        );
    }

    public function test_a_no_show_notifies_the_trainee_and_the_staff(): void
    {
        Notification::fake();

        $receptionist = $this->userWithRole('receptionist');

        $this->actingAsTrainee();

        $package = app(\App\Services\PackageService::class)->assign(
            $this->trainee->fresh(),
            Package::factory()->create(['lessons_count' => 10, 'price' => 200]),
            ['discount_percent' => 0],
        );

        $session = \App\Models\TrainingSession::create([
            'branch_id' => $this->branch->id,
            'trainee_id' => $this->trainee->id,
            'trainer_id' => $this->trainer->id,
            'trainee_package_id' => $package->id,
            'scheduled_date' => $this->pastWorkingDay()->toDateString(),
            'start_time' => '09:00',
            'end_time' => '09:45',
            'duration_minutes' => 45,
            'status' => 'scheduled',
        ]);

        app(\App\Services\TrainingSessionService::class)->markNoShow($session, 'لم يحضر');

        Notification::assertSentTo(
            $this->trainee->fresh()->user,
            fn (SystemNotification $notification) => $notification->type === 'session.no_show',
        );

        Notification::assertSentTo($receptionist, SystemNotification::class);
    }

    /**
     * Voiding money tells finance and the trainee.
     *
     * A reversal changes what someone owes, so hiding it would be the one case
     * where silence is actually harmful.
     */
    public function test_voiding_a_payment_notifies_finance_and_the_trainee(): void
    {
        $this->actingAsTrainee();

        $package = app(\App\Services\PackageService::class)->assign(
            $this->trainee->fresh(),
            Package::factory()->create(['lessons_count' => 10, 'price' => 200]),
            ['discount_percent' => 0],
        );

        $payment = app(\App\Services\PaymentService::class)->record([
            'trainee_id' => $this->trainee->id,
            'trainee_package_id' => $package->id,
            'amount' => 50,
            'payment_method_id' => \App\Models\PaymentMethod::first()->id,
            'paid_on' => now()->toDateString(),
        ], $this->branch->id);

        Notification::fake();

        $accountant = $this->userWithRole('accountant');

        app(\App\Services\PaymentService::class)->void($payment, 'خطأ في الإدخال');

        Notification::assertSentTo(
            $accountant,
            fn (SystemNotification $notification) => $notification->type === 'payment.voided',
        );

        Notification::assertSentTo($this->trainee->fresh()->user, SystemNotification::class);
    }

    // ------------------------------------------------------------ the devices

    /** The dashboard registers the browser as a device, like a phone. */
    public function test_a_browser_can_register_for_push(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/v1/notifications/device', [
            'push_token' => 'web-token-'.str_repeat('a', 60),
            'platform' => 'web',
            'app' => 'dashboard',
        ])->assertOk();

        $this->assertDatabaseHas('device_tokens', ['platform' => 'web', 'app' => 'dashboard']);
    }

    public function test_an_unknown_platform_is_refused(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/v1/notifications/device', [
            'push_token' => 'token',
            'platform' => 'smart-fridge',
        ])->assertStatus(422)->assertJsonValidationErrors('platform');
    }
}
