<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\RegistrationRequest;
use App\Models\Trainee;
use App\Services\IdCards\IdCardReader;
use App\Services\IdCards\IdCardReading;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeIdCardReader;
use Tests\TestCase;

/**
 * Filling a form from a photo of an ID.
 *
 * The provider is faked — a real reading costs money and would make the suite
 * depend on how well a photograph scanned — so what is actually pinned here is
 * everything around it, which is where this feature can do harm: that a reading
 * stores nothing by itself, that it is read once rather than once per page view,
 * that spending the center's money needs the permission that decides on requests,
 * and above all that nothing reaches a trainee's file without a member of staff
 * choosing it.
 */
class IdCardScanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFoundation();

        Storage::fake(config('filesystems.private_disk', 'private'));
    }

    /** Bind a reader holding a card, and hand it back so calls can be counted. */
    protected function reader(array $fields = [], bool $enabled = true): FakeIdCardReader
    {
        $reader = $enabled
            ? FakeIdCardReader::withCard($fields)
            : new FakeIdCardReader([], false);

        $this->app->instance(IdCardReader::class, $reader);

        return $reader;
    }

    /** @return array<string, mixed> */
    protected function form(array $overrides = []): array
    {
        return array_merge([
            'full_name' => 'رامي الحديد',
            'phone' => '0791234567',
            'license_type' => 'private',
        ], $overrides);
    }

    protected function requestWithPhoto(array $overrides = []): RegistrationRequest
    {
        $this->postJson('/api/v1/public/registrations', $this->form($overrides + [
            'id_photo' => UploadedFile::fake()->image('id.jpg', 900, 600),
        ]))->assertCreated();

        return RegistrationRequest::firstOrFail();
    }

    // ------------------------------------------------------ the applicant's app

    /**
     * The applicant has the card read while they still hold it.
     *
     * The reason this endpoint exists at all: they are the one person who can
     * retake the photo and see at a glance that the number is wrong.
     */
    public function test_an_applicant_can_have_their_id_read_before_submitting(): void
    {
        $this->reader();

        $response = $this->postJson('/api/v1/public/registrations/scan-id', [
            'id_photo' => UploadedFile::fake()->image('id.jpg', 900, 600),
        ])->assertOk();

        $this->assertSame(IdCardReading::OUTCOME_READ, $response->json('data.outcome'));
        $this->assertSame('9981234567', $response->json('data.fields.national_id'));
        $this->assertSame('1998-04-11', $response->json('data.fields.birth_date'));
        $this->assertSame('male', $response->json('data.fields.gender'));
    }

    /** Reading is not filing: no request, no stored photo, nothing to review. */
    public function test_a_reading_stores_nothing(): void
    {
        $this->reader();

        $this->postJson('/api/v1/public/registrations/scan-id', [
            'id_photo' => UploadedFile::fake()->image('id.jpg'),
        ])->assertOk();

        $this->assertSame(0, RegistrationRequest::count());
        $this->assertSame(0, Document::count());
    }

    public function test_the_endpoint_refuses_anything_that_is_not_an_image(): void
    {
        $this->reader();

        $this->postJson('/api/v1/public/registrations/scan-id', [
            'id_photo' => UploadedFile::fake()->create('id.pdf', 100, 'application/pdf'),
        ])->assertStatus(422)->assertJsonValidationErrors('id_photo');
    }

    /**
     * With no provider configured the app is told so plainly, and still gets a
     * success envelope: filling the form is a convenience on an optional field,
     * and an applicant mid-form should not meet an error they cannot act on.
     */
    public function test_an_unconfigured_reader_answers_without_failing_the_form(): void
    {
        $this->reader(enabled: false);

        $response = $this->postJson('/api/v1/public/registrations/scan-id', [
            'id_photo' => UploadedFile::fake()->image('id.jpg'),
        ])->assertOk();

        $this->assertSame(IdCardReading::OUTCOME_DISABLED, $response->json('data.outcome'));
        $this->assertSame([], $response->json('data.fields'));
    }

    /**
     * The cap on what the center pays for in a day.
     *
     * The per-IP throttle on the route stops one phone looping; this is what
     * stops a thousand of them, so it is pinned here rather than left to a
     * reading of the config.
     */
    public function test_public_readings_stop_at_the_daily_limit(): void
    {
        config(['id_reader.daily_limit' => 1]);

        $reader = $this->reader();

        $first = $this->postJson('/api/v1/public/registrations/scan-id', [
            'id_photo' => UploadedFile::fake()->image('id.jpg'),
        ])->assertOk();

        $second = $this->postJson('/api/v1/public/registrations/scan-id', [
            'id_photo' => UploadedFile::fake()->image('id.jpg'),
        ])->assertOk();

        $this->assertSame(IdCardReading::OUTCOME_READ, $first->json('data.outcome'));
        $this->assertSame(IdCardReading::OUTCOME_FAILED, $second->json('data.outcome'));

        // The point of the cap: the second one was never paid for.
        $this->assertCount(1, $reader->calls);
    }

    /** Staff are not locked out of the queue by public traffic. */
    public function test_the_daily_limit_does_not_apply_to_staff(): void
    {
        config(['id_reader.daily_limit' => 1]);

        $reader = $this->reader();
        $request = $this->requestWithPhoto();

        $this->postJson('/api/v1/public/registrations/scan-id', [
            'id_photo' => UploadedFile::fake()->image('id.jpg'),
        ])->assertOk();

        $this->actingAs($this->userWithRole('receptionist'))
            ->post("/registrations/{$request->uuid}/scan-id")
            ->assertRedirect();

        $this->assertSame('9981234567', $request->fresh()->idReading()?->nationalId);
        $this->assertCount(2, $reader->calls);
    }

    // -------------------------------------------------------------- the queue

    public function test_staff_can_have_an_attached_photo_read_and_it_is_kept(): void
    {
        $reader = $this->reader();
        $request = $this->requestWithPhoto();

        $this->actingAs($this->userWithRole('receptionist'))
            ->post("/registrations/{$request->uuid}/scan-id")
            ->assertRedirect();

        $request->refresh();

        $this->assertNotNull($request->id_scanned_at);
        $this->assertSame('9981234567', $request->idReading()?->nationalId);
        $this->assertCount(1, $reader->calls);
    }

    /**
     * The queue is reloaded and polled constantly, so the stored reading is what
     * it shows. A page view that re-read the card would spend money on every
     * refresh.
     */
    public function test_the_queue_shows_the_stored_reading_without_reading_again(): void
    {
        $reader = $this->reader();
        $request = $this->requestWithPhoto();

        $user = $this->userWithRole('receptionist');

        $this->actingAs($user)->post("/registrations/{$request->uuid}/scan-id");
        $this->actingAs($user)->get('/registrations')->assertOk()->assertSee('9981234567', false);

        $this->assertCount(1, $reader->calls);
    }

    /** A supervisor may read the queue without being able to spend on it. */
    public function test_reading_a_card_needs_the_permission_that_decides(): void
    {
        $this->reader();
        $request = $this->requestWithPhoto();

        $this->actingAs($this->userWithRole('training_supervisor'))
            ->post("/registrations/{$request->uuid}/scan-id")
            ->assertForbidden();

        $this->assertNull($request->fresh()->id_scanned_at);
    }

    public function test_a_request_with_no_photo_is_told_so(): void
    {
        $this->reader();

        $this->postJson('/api/v1/public/registrations', $this->form())->assertCreated();
        $request = RegistrationRequest::firstOrFail();

        $this->actingAs($this->userWithRole('receptionist'))
            ->post("/registrations/{$request->uuid}/scan-id")
            ->assertRedirect();

        $this->assertNull($request->fresh()->id_scanned_at);
    }

    // ------------------------------------------------------------- approving

    /**
     * The card's details reach the trainee only because a reviewer ticked the box.
     */
    public function test_approving_with_the_reading_puts_the_cards_details_on_the_trainee(): void
    {
        $this->reader();
        $request = $this->requestWithPhoto(['full_name' => 'رامي الحديد']);

        $user = $this->userWithRole('receptionist');

        $this->actingAs($user)->post("/registrations/{$request->uuid}/scan-id");

        $this->actingAs($user)->post("/registrations/{$request->uuid}/approve", [
            'branch_id' => $this->branch->id,
            'use_scan' => '1',
            'create_login' => '0',
        ])->assertRedirect();

        $trainee = Trainee::firstOrFail();

        $this->assertSame('رامي سامر محمود الحديد', $trainee->full_name);
        $this->assertSame('9981234567', $trainee->national_id);
        $this->assertSame('1998-04-11', $trainee->birth_date->toDateString());
        $this->assertSame('male', $trainee->gender);
    }

    /** Unticked, the applicant's own answers are what gets filed. */
    public function test_approving_without_it_keeps_what_the_applicant_typed(): void
    {
        $this->reader();
        $request = $this->requestWithPhoto(['full_name' => 'رامي الحديد']);

        $user = $this->userWithRole('receptionist');

        $this->actingAs($user)->post("/registrations/{$request->uuid}/scan-id");

        $this->actingAs($user)->post("/registrations/{$request->uuid}/approve", [
            'branch_id' => $this->branch->id,
            'create_login' => '0',
        ])->assertRedirect();

        $trainee = Trainee::firstOrFail();

        $this->assertSame('رامي الحديد', $trainee->full_name);
        $this->assertNull($trainee->national_id);
    }

    /**
     * A national number already on a file is almost always someone applying
     * again, which is a conversation rather than a second trainee.
     */
    public function test_approval_is_refused_when_the_national_number_is_already_on_a_trainee(): void
    {
        $this->reader();
        $request = $this->requestWithPhoto();

        Trainee::factory()->create([
            'branch_id' => $this->branch->id,
            'national_id' => '9981234567',
        ]);

        $user = $this->userWithRole('receptionist');

        $this->actingAs($user)->post("/registrations/{$request->uuid}/scan-id");

        $this->actingAs($user)
            ->from('/registrations')
            ->post("/registrations/{$request->uuid}/approve", [
                'branch_id' => $this->branch->id,
                'use_scan' => '1',
                'create_login' => '0',
            ])
            ->assertRedirect('/registrations')
            ->assertSessionHasErrors('national_id');

        // Still waiting on a decision, and no second file was created.
        $this->assertTrue($request->fresh()->isOpen());
        $this->assertSame(1, Trainee::count());
    }

    // ---------------------------------------------------------------- the desk

    public function test_the_desk_form_can_have_a_photo_read(): void
    {
        $this->reader();

        $response = $this->actingAs($this->userWithRole('receptionist'))
            ->post('/trainees/scan-id', [
                'id_photo' => UploadedFile::fake()->image('id.jpg', 900, 600),
            ])->assertOk();

        $this->assertSame('رامي سامر محمود الحديد', $response->json('fields.full_name'));
        $this->assertSame('9981234567', $response->json('fields.national_id'));
    }

    /**
     * The control is on the form, pointed at the right endpoint.
     *
     * A smoke test, and the only cover the form's own script has: it fills the
     * boxes in the browser, which a request test cannot exercise.
     */
    public function test_the_desk_form_offers_to_read_a_photo(): void
    {
        $this->reader();

        $this->actingAs($this->userWithRole('receptionist'))
            ->get('/trainees/create/new')
            ->assertOk()
            ->assertSee('صورة الهوية')
            ->assertSee('وتُعبّأ الحقول الفارغة تلقائياً', false)
            // The endpoint reaches the page through `@js`, so it arrives
            // JSON-encoded — slashes escaped and all.
            ->assertSee(str_replace('/', '\/', route('admin.trainees.scan-id')), false);
    }

    /** With no reader configured the form says what the photo is still for. */
    public function test_the_desk_form_still_takes_the_photo_with_no_reader(): void
    {
        $this->reader(enabled: false);

        $this->actingAs($this->userWithRole('receptionist'))
            ->get('/trainees/create/new')
            ->assertOk()
            ->assertSee('تُحفظ مع مستندات المتدرب.', false)
            ->assertDontSee('وتُعبّأ الحقول الفارغة تلقائياً', false);
    }

    /**
     * The photo the form was filled from becomes the trainee's identity
     * document — the same category and disk as one that arrived with a join
     * request, so both routes into the system leave the same file on the record.
     */
    public function test_a_trainee_registered_with_an_id_photo_keeps_it(): void
    {
        $this->reader();

        $this->actingAs($this->userWithRole('receptionist'))
            ->post('/trainees', [
                'full_name' => 'رامي سامر محمود الحديد',
                'phone' => '0791234567',
                'national_id' => '9981234567',
                'birth_date' => '1998-04-11',
                'gender' => 'male',
                'license_type' => 'private',
                'registration_date' => now()->toDateString(),
                'branch_id' => $this->branch->id,
                'id_photo' => UploadedFile::fake()->image('id.jpg', 900, 600),
            ])->assertRedirect();

        $trainee = Trainee::firstOrFail();
        $document = Document::where('documentable_type', Trainee::class)
            ->where('documentable_id', $trainee->id)
            ->firstOrFail();

        $this->assertSame('identity', $document->category);
        Storage::disk($document->disk)->assertExists($document->path);
    }
}
