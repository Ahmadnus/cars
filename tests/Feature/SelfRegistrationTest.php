<?php

namespace Tests\Feature;

use App\Events\RegistrationRequestUpdated;
use App\Models\Document;
use App\Models\RegistrationRequest;
use App\Models\Trainee;
use App\Models\User;
use App\Services\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Public self-registration.
 *
 * The only place in the system a stranger can write to, and deliberately without
 * a passcode: the center wanted the lowest barrier, and the request is inert
 * until staff act on it. So the tests are written around the thing that actually
 * protects it — that nothing reaches the training records without approval, that
 * one number cannot stack up open requests, and that a number already enrolled is
 * refused.
 */
class SelfRegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFoundation();
    }

    /** @return array<string, mixed> */
    protected function form(array $overrides = []): array
    {
        return array_merge([
            'full_name' => 'رامي سامر الحديد',
            'phone' => '0791234567',
            'national_id' => '9981234567',
            'birth_date' => '2002-04-11',
            'gender' => 'male',
            'city' => 'عمّان',
            'license_type' => 'private',
            'notes' => 'أفضّل التدريب صباحاً',
        ], $overrides);
    }

    // ------------------------------------------------------------- submitting

    public function test_anyone_can_see_the_options_without_an_account(): void
    {
        $this->getJson('/api/v1/public/registrations/options')
            ->assertOk()
            ->assertJsonStructure(['data' => ['branches', 'license_types', 'genders']]);
    }

    /**
     * A stranger applies with no passcode at all.
     *
     * The barrier is a member of staff reading it, not a code — so what this pins
     * is that the request stays inert: no trainee, no trainee number, no login.
     */
    public function test_a_stranger_can_apply_with_no_passcode(): void
    {
        Event::fake([RegistrationRequestUpdated::class]);

        $response = $this->postJson('/api/v1/public/registrations', $this->form())
            ->assertCreated();

        $reference = $response->json('data.reference');

        $this->assertNotNull($reference);
        $this->assertDatabaseHas('registration_requests', [
            'reference' => $reference,
            'phone' => '0791234567',
            'status' => RegistrationRequest::STATUS_PENDING,
        ]);

        // The whole point: nothing has entered the training records.
        $this->assertSame(0, Trainee::count());
        $this->assertSame(0, User::whereHas('roles', fn ($q) => $q->where('name', 'trainee'))->count());

        Event::assertDispatched(RegistrationRequestUpdated::class);
    }

    /**
     * The number is not treated as verified.
     *
     * Nothing has checked it, and the queue labels it so a reviewer knows to
     * phone the applicant rather than assume.
     */
    public function test_the_phone_is_recorded_as_unverified(): void
    {
        $this->postJson('/api/v1/public/registrations', $this->form())->assertCreated();

        $request = RegistrationRequest::firstOrFail();

        $this->assertNull($request->phone_verified_at);
        $this->assertFalse($request->isVerified());
    }

    /** One open request per number, so the queue cannot be stacked up. */
    public function test_a_second_open_request_from_one_number_is_refused(): void
    {
        $this->postJson('/api/v1/public/registrations', $this->form())->assertCreated();

        $this->postJson('/api/v1/public/registrations', $this->form([
            'full_name' => 'نفس الرقم مرة ثانية',
        ]))->assertStatus(422);

        $this->assertSame(1, RegistrationRequest::count());
    }

    /**
     * A refused application frees the number.
     *
     * Someone rejected for a missing document has to be able to apply again
     * once they have it.
     */
    public function test_a_rejected_number_may_apply_again(): void
    {
        $first = $this->submitted();

        Sanctum::actingAs($this->userWithRole('receptionist'));
        $this->postJson("/api/v1/registrations/{$first->uuid}/reject", [
            'reason' => 'الرقم الوطني ناقص.',
        ])->assertOk();

        // Back to being a stranger.
        app('auth')->forgetGuards();

        $this->postJson('/api/v1/public/registrations', $this->form())->assertCreated();

        $this->assertSame(2, RegistrationRequest::count());
    }

    public function test_the_name_and_phone_are_required(): void
    {
        $this->postJson('/api/v1/public/registrations', ['city' => 'عمّان'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['full_name', 'phone']);
    }

    public function test_an_enrolled_number_is_refused(): void
    {
        Trainee::factory()->create(['branch_id' => $this->branch->id, 'phone' => '0799999999']);

        $this->postJson('/api/v1/public/registrations', $this->form([
            'phone' => '0799999999',
        ]))->assertStatus(422);

        $this->assertSame(0, RegistrationRequest::count());
    }

    // ---------------------------------------------------------------- status

    public function test_an_applicant_can_follow_their_request_by_reference(): void
    {
        $reference = $this->postJson('/api/v1/public/registrations', $this->form())
            ->assertCreated()
            ->json('data.reference');

        $this->getJson("/api/v1/public/registrations/{$reference}")
            ->assertOk()
            ->assertJsonPath('data.reference', $reference)
            ->assertJsonPath('data.status', 'pending')
            // Personal detail stays out of the public shape.
            ->assertJsonMissingPath('data.national_id')
            ->assertJsonMissingPath('data.address');
    }

    public function test_an_unknown_reference_is_not_found(): void
    {
        $this->getJson('/api/v1/public/registrations/RQ-26-NOPE00')->assertNotFound();
    }

    // ---------------------------------------------------------------- review

    protected function submitted(): RegistrationRequest
    {
        $this->postJson('/api/v1/public/registrations', $this->form())->assertCreated();

        return RegistrationRequest::firstOrFail();
    }

    public function test_the_queue_requires_permission(): void
    {
        $this->submitted();

        // A trainee has no business reading applications.
        $traineeUser = $this->userWithRole('trainee');
        Sanctum::actingAs($traineeUser);
        $this->getJson('/api/v1/registrations')->assertForbidden();

        // A trainer neither.
        Sanctum::actingAs($this->userWithRole('trainer'));
        $this->getJson('/api/v1/registrations')->assertForbidden();

        Sanctum::actingAs($this->userWithRole('receptionist'));
        $this->getJson('/api/v1/registrations')->assertOk()->assertJsonPath('meta.open_count', 1);
    }

    public function test_approving_creates_the_trainee_and_a_login(): void
    {
        $request = $this->submitted();

        Sanctum::actingAs($this->userWithRole('receptionist'));

        $response = $this->postJson("/api/v1/registrations/{$request->uuid}/approve", [
            'branch_id' => $this->branch->uuid,
        ])->assertOk();

        $number = $response->json('data.trainee.trainee_number');
        $password = $response->json('data.login.password');

        $this->assertNotNull($number);
        $this->assertNotNull($password, 'the one-time password is shown once');

        $trainee = Trainee::firstOrFail();

        $this->assertSame('رامي سامر الحديد', $trainee->full_name);
        $this->assertSame('new', $trainee->status, 'a fresh file is not yet in training');
        $this->assertNotNull($trainee->user_id, 'the applicant can sign in');

        $request->refresh();
        $this->assertSame(RegistrationRequest::STATUS_APPROVED, $request->status);
        $this->assertSame($trainee->id, $request->trainee_id);

        // The generated credential really works.
        $this->postJson('/api/v1/auth/otp/request', ['phone' => $trainee->phone])->assertOk();
    }

    public function test_approving_can_skip_the_login(): void
    {
        $request = $this->submitted();

        Sanctum::actingAs($this->userWithRole('receptionist'));

        $this->postJson("/api/v1/registrations/{$request->uuid}/approve", [
            'branch_id' => $this->branch->uuid,
            'create_login' => false,
        ])->assertOk()->assertJsonPath('data.login', null);

        $this->assertNull(Trainee::firstOrFail()->user_id);
    }

    public function test_a_request_cannot_be_approved_twice(): void
    {
        $request = $this->submitted();

        Sanctum::actingAs($this->userWithRole('receptionist'));

        $this->postJson("/api/v1/registrations/{$request->uuid}/approve", [
            'branch_id' => $this->branch->uuid,
        ])->assertOk();

        // A second approval would mint a second trainee from one application.
        $this->postJson("/api/v1/registrations/{$request->uuid}/approve", [
            'branch_id' => $this->branch->uuid,
        ])->assertStatus(422);

        $this->assertSame(1, Trainee::count());
    }

    public function test_rejecting_records_a_reason_the_applicant_can_read(): void
    {
        $request = $this->submitted();

        Sanctum::actingAs($this->userWithRole('receptionist'));

        $this->postJson("/api/v1/registrations/{$request->uuid}/reject", [
            'reason' => 'الرقم الوطني غير مطابق للاسم.',
        ])->assertOk();

        $this->assertSame(0, Trainee::count());

        $this->getJson("/api/v1/public/registrations/{$request->reference}")
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.decision_reason', 'الرقم الوطني غير مطابق للاسم.');
    }

    public function test_a_rejection_needs_a_reason(): void
    {
        $request = $this->submitted();

        Sanctum::actingAs($this->userWithRole('receptionist'));

        $this->postJson("/api/v1/registrations/{$request->uuid}/reject", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');
    }

    public function test_a_supervisor_may_read_the_queue_but_not_decide(): void
    {
        $request = $this->submitted();

        // Reading applications is part of supervising training; letting someone
        // into the records is a reception/management decision.
        Sanctum::actingAs($this->userWithRole('training_supervisor'));

        $this->getJson('/api/v1/registrations')->assertOk();

        $this->postJson("/api/v1/registrations/{$request->uuid}/approve", [
            'branch_id' => $this->branch->uuid,
        ])->assertForbidden();
    }

    public function test_approval_without_a_branch_is_refused(): void
    {
        // Submitted without choosing a branch.
        $this->postJson('/api/v1/public/registrations', $this->form([
            'branch_id' => null,
        ]))->assertCreated();

        $request = RegistrationRequest::firstOrFail();

        Sanctum::actingAs($this->userWithRole('receptionist'));

        $this->postJson("/api/v1/registrations/{$request->uuid}/approve", [])
            ->assertStatus(422);

        $this->assertSame(0, Trainee::count());
    }

    // ---------------------------------------------------------- the ID photo

    /**
     * The applicant attaches a photo of their ID.
     *
     * It hangs off the request rather than off a trainee, because approval has
     * not happened yet and may never happen.
     */
    public function test_an_applicant_can_attach_a_photo_of_their_id(): void
    {
        Storage::fake(config('filesystems.private_disk', 'private'));

        $this->postJson('/api/v1/public/registrations', $this->form([
            'id_photo' => UploadedFile::fake()->image('id.jpg', 900, 600),
        ]))->assertCreated();

        $request = RegistrationRequest::firstOrFail();
        $photo = $request->idPhoto();

        $this->assertNotNull($photo, 'the photo is attached to the request');
        $this->assertSame('identity', $photo->category);
        $this->assertSame(RegistrationRequest::class, $photo->documentable_type);
        $this->assertSame($request->id, $photo->documentable_id);

        Storage::disk($photo->disk)->assertExists($photo->path);
    }

    /** The photo is optional: the form is accepted without one. */
    public function test_the_photo_is_optional(): void
    {
        $this->postJson('/api/v1/public/registrations', $this->form())
            ->assertCreated();

        $this->assertNull(RegistrationRequest::firstOrFail()->idPhoto());
    }

    /** A PDF is refused, and nothing is filed. */
    public function test_only_an_image_is_accepted(): void
    {
        $this->postJson('/api/v1/public/registrations', $this->form([
            'id_photo' => UploadedFile::fake()->create('id.pdf', 100, 'application/pdf'),
        ]))->assertStatus(422)->assertJsonValidationErrors('id_photo');

        $this->assertSame(0, RegistrationRequest::count());
    }

    /**
     * On approval the photo follows the applicant into their trainee file.
     *
     * Re-pointed, not copied: one row and one file on disk, and it appears in
     * the trainee's documents tab without staff re-uploading anything.
     */
    public function test_the_photo_moves_to_the_trainee_on_approval(): void
    {
        Storage::fake(config('filesystems.private_disk', 'private'));

        $this->postJson('/api/v1/public/registrations', $this->form([
            'id_photo' => UploadedFile::fake()->image('id.jpg'),
        ]))->assertCreated();

        $request = RegistrationRequest::firstOrFail();
        $photoId = $request->idPhoto()->id;

        Sanctum::actingAs($this->userWithRole('receptionist'));

        $this->postJson("/api/v1/registrations/{$request->uuid}/approve", [
            'branch_id' => $this->branch->uuid,
        ])->assertOk();

        $trainee = Trainee::firstOrFail();
        $photo = Document::findOrFail($photoId);

        $this->assertSame(Trainee::class, $photo->documentable_type);
        $this->assertSame($trainee->id, $photo->documentable_id);
        $this->assertSame($trainee->branch_id, $photo->branch_id);
        $this->assertSame(1, Document::count(), 'moved, not duplicated');
    }

    // ----------------------------------------------------- the licence types

    /** The combined private-and-motorcycle course is on offer. */
    public function test_the_private_and_motorcycle_category_is_offered(): void
    {
        $types = $this->getJson('/api/v1/public/registrations/options')
            ->assertOk()
            ->json('data.license_types');

        $this->assertContains('private_motorcycle', array_column($types, 'value'));
        $this->assertContains('خصوصي ودراجة', array_column($types, 'label'));
    }

    public function test_an_applicant_can_choose_it(): void
    {
        $this->postJson('/api/v1/public/registrations', $this->form([
            'license_type' => 'private_motorcycle',
        ]))->assertCreated();

        $this->assertSame('private_motorcycle', RegistrationRequest::firstOrFail()->license_type);
    }

    /** A category nobody offers is a malformed request, not a new category. */
    public function test_an_unknown_licence_category_is_refused(): void
    {
        $this->postJson('/api/v1/public/registrations', $this->form([
            'license_type' => 'spaceship',
        ]))->assertStatus(422)->assertJsonValidationErrors('license_type');
    }
}
