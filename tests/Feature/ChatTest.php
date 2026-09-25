<?php

namespace Tests\Feature;

use App\Events\MessageRead;
use App\Events\MessageSent;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Trainee;
use App\Models\Trainer;
use App\Models\User;
use App\Services\ChatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Trainer-trainee messaging.
 *
 * The property under test throughout is that membership — not a permission —
 * decides access, and that an administrator is *not* a member. A system that
 * let any staff token read a private thread would be worse than one with no
 * chat at all.
 */
class ChatTest extends TestCase
{
    use RefreshDatabase;

    protected Trainee $trainee;

    protected Trainer $trainer;

    protected User $traineeUser;

    protected User $trainerUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFoundation();

        $this->trainer = Trainer::factory()->create(['branch_id' => $this->branch->id]);
        $this->trainee = Trainee::factory()->create([
            'branch_id' => $this->branch->id,
            'trainer_id' => $this->trainer->id,
        ]);

        $this->traineeUser = $this->userWithRole('trainee');
        $this->trainee->forceFill(['user_id' => $this->traineeUser->id])->save();

        $this->trainerUser = $this->userWithRole('trainer');
        $this->trainer->forceFill(['user_id' => $this->trainerUser->id])->save();

        $this->traineeUser = $this->traineeUser->fresh();
        $this->trainerUser = $this->trainerUser->fresh();
    }

    protected function conversation(): Conversation
    {
        return app(ChatService::class)->conversationFor($this->trainee->fresh());
    }

    public function test_a_trainee_opens_their_thread_and_it_is_created_once(): void
    {
        Sanctum::actingAs($this->traineeUser);

        $first = $this->getJson('/api/v1/chat/my-conversation')->assertOk()->json('data.id');
        $second = $this->getJson('/api/v1/chat/my-conversation')->assertOk()->json('data.id');

        $this->assertSame($first, $second, 'opening twice must not create two threads');
        $this->assertSame(1, Conversation::count());
    }

    public function test_each_side_is_shown_the_other_party(): void
    {
        Sanctum::actingAs($this->traineeUser);

        $this->getJson('/api/v1/chat/my-conversation')
            ->assertOk()
            ->assertJsonPath('data.my_role', 'trainee')
            ->assertJsonPath('data.other_party.name', $this->trainer->full_name);

        Sanctum::actingAs($this->trainerUser);

        $this->getJson('/api/v1/chat/conversations')
            ->assertOk()
            ->assertJsonPath('data.0.my_role', 'trainer')
            ->assertJsonPath('data.0.other_party.name', $this->trainee->full_name);
    }

    public function test_sending_a_message_notifies_the_other_side_only(): void
    {
        Event::fake([MessageSent::class]);

        $conversation = $this->conversation();

        Sanctum::actingAs($this->traineeUser);

        $this->postJson("/api/v1/chat/conversations/{$conversation->uuid}/messages", [
            'body' => 'مرحباً أستاذ، هل يمكن تأجيل حصة الغد؟',
        ])->assertCreated()->assertJsonPath('data.sender_role', 'trainee');

        $conversation->refresh();

        // The unread badge belongs to the recipient, never the sender.
        $this->assertSame(1, $conversation->trainer_unread);
        $this->assertSame(0, $conversation->trainee_unread);
        $this->assertStringContainsString('تأجيل', (string) $conversation->last_message_preview);

        Event::assertDispatched(MessageSent::class);
    }

    public function test_an_empty_message_is_refused(): void
    {
        $conversation = $this->conversation();

        Sanctum::actingAs($this->traineeUser);

        $this->postJson("/api/v1/chat/conversations/{$conversation->uuid}/messages", [
            'body' => '   ',
        ])->assertStatus(422);

        $this->assertSame(0, Message::count());
    }

    /** The whole authorization model in one test. */
    public function test_an_administrator_is_not_a_participant(): void
    {
        $conversation = $this->conversation();

        Sanctum::actingAs($this->admin());

        $this->getJson("/api/v1/chat/conversations/{$conversation->uuid}/messages")
            ->assertForbidden();

        $this->postJson("/api/v1/chat/conversations/{$conversation->uuid}/messages", [
            'body' => 'أنا المدير وأريد الكتابة',
        ])->assertForbidden();
    }

    public function test_another_trainee_cannot_read_the_thread(): void
    {
        $conversation = $this->conversation();

        $stranger = Trainee::factory()->create([
            'branch_id' => $this->branch->id,
            'trainer_id' => $this->trainer->id,
        ]);
        $strangerUser = $this->userWithRole('trainee');
        $stranger->forceFill(['user_id' => $strangerUser->id])->save();

        Sanctum::actingAs($strangerUser->fresh());

        $this->getJson("/api/v1/chat/conversations/{$conversation->uuid}/messages")
            ->assertForbidden();
    }

    public function test_another_trainer_cannot_read_the_thread(): void
    {
        $conversation = $this->conversation();

        $otherTrainer = Trainer::factory()->create(['branch_id' => $this->branch->id]);
        $otherUser = $this->userWithRole('trainer');
        $otherTrainer->forceFill(['user_id' => $otherUser->id])->save();

        Sanctum::actingAs($otherUser->fresh());

        $this->getJson("/api/v1/chat/conversations/{$conversation->uuid}/messages")
            ->assertForbidden();
    }

    public function test_reading_clears_the_badge_and_marks_the_other_sides_messages(): void
    {
        Event::fake([MessageRead::class, MessageSent::class]);

        $conversation = $this->conversation();

        Sanctum::actingAs($this->traineeUser);
        $this->postJson("/api/v1/chat/conversations/{$conversation->uuid}/messages", [
            'body' => 'سؤال',
        ])->assertCreated();

        Sanctum::actingAs($this->trainerUser);
        $this->postJson("/api/v1/chat/conversations/{$conversation->uuid}/read")
            ->assertOk()
            ->assertJsonPath('data.updated', 1);

        $conversation->refresh();

        $this->assertSame(0, $conversation->trainer_unread);
        $this->assertNotNull(Message::first()->read_at);

        // The trainee's own message must not be marked read by its sender.
        Sanctum::actingAs($this->traineeUser);
        $this->postJson("/api/v1/chat/conversations/{$conversation->uuid}/read")
            ->assertOk()
            ->assertJsonPath('data.updated', 0);

        Event::assertDispatched(MessageRead::class);
    }

    public function test_history_pages_backwards_without_repeating(): void
    {
        $conversation = $this->conversation();

        Sanctum::actingAs($this->traineeUser);

        foreach (range(1, 5) as $index) {
            $this->postJson("/api/v1/chat/conversations/{$conversation->uuid}/messages", [
                'body' => "رسالة {$index}",
            ])->assertCreated();
        }

        $first = $this->getJson("/api/v1/chat/conversations/{$conversation->uuid}/messages?limit=2")
            ->assertOk()
            ->assertJsonPath('meta.has_more', true);

        $cursor = $first->json('meta.next_before');

        $second = $this->getJson(
            "/api/v1/chat/conversations/{$conversation->uuid}/messages?limit=2&before={$cursor}",
        )->assertOk();

        $firstIds = collect($first->json('data'))->pluck('id');
        $secondIds = collect($second->json('data'))->pluck('id');

        $this->assertCount(2, $firstIds);
        $this->assertCount(2, $secondIds);
        $this->assertEmpty(
            $firstIds->intersect($secondIds),
            'a page must not repeat a message from the previous page',
        );
    }

    /**
     * The polling call returns only what is new.
     *
     * The app checks every two seconds, so this must not re-send the visible
     * thread each time — that is the difference between a near-empty response and
     * thirty messages, thirty times a minute, per open chat.
     */
    public function test_the_after_cursor_returns_only_newer_messages(): void
    {
        $conversation = $this->conversation();

        Sanctum::actingAs($this->traineeUser);

        foreach (['واحد', 'اثنان'] as $text) {
            $this->postJson("/api/v1/chat/conversations/{$conversation->uuid}/messages", [
                'body' => $text,
            ])->assertCreated();
        }

        $anchor = Message::orderByDesc('id')->value('uuid');

        // Nothing newer yet.
        $this->getJson("/api/v1/chat/conversations/{$conversation->uuid}/messages?after={$anchor}")
            ->assertOk()
            ->assertJsonPath('meta.is_tail', true)
            ->assertJsonCount(0, 'data');

        // The trainer replies.
        Sanctum::actingAs($this->trainerUser);
        $this->postJson("/api/v1/chat/conversations/{$conversation->uuid}/messages", [
            'body' => 'وصلني سؤالك',
        ])->assertCreated();

        Sanctum::actingAs($this->traineeUser);

        $response = $this->getJson(
            "/api/v1/chat/conversations/{$conversation->uuid}/messages?after={$anchor}",
        )->assertOk()->assertJsonCount(1, 'data');

        $this->assertSame('وصلني سؤالك', $response->json('data.0.body'));
        $this->assertSame('trainer', $response->json('data.0.sender_role'));
    }

    /** An unknown cursor falls back to the newest page rather than erroring. */
    public function test_an_unknown_after_cursor_returns_the_latest_page(): void
    {
        $conversation = $this->conversation();

        Sanctum::actingAs($this->traineeUser);

        $this->postJson("/api/v1/chat/conversations/{$conversation->uuid}/messages", [
            'body' => 'رسالة',
        ])->assertCreated();

        $this->getJson(
            "/api/v1/chat/conversations/{$conversation->uuid}/messages?after=does-not-exist",
        )->assertOk()
            ->assertJsonPath('meta.is_tail', false)
            ->assertJsonCount(1, 'data');
    }

    // ------------------------------------------------------------ attachments

    public function test_an_image_is_stored_privately_and_served_through_the_api(): void
    {
        Storage::fake('private');

        $conversation = $this->conversation();

        Sanctum::actingAs($this->traineeUser);

        $response = $this->postJson("/api/v1/chat/conversations/{$conversation->uuid}/messages", [
            'type' => 'image',
            'attachment' => UploadedFile::fake()->image('lesson.jpg'),
        ])->assertCreated();

        $message = Message::first();

        $this->assertSame('image', $message->type);
        Storage::disk('private')->assertExists($message->attachment_path);

        // The path is an internal detail and must never reach a client.
        $response->assertJsonMissing(['attachment_path' => $message->attachment_path]);
        $this->assertNotNull($response->json('data.attachment_url'));

        $this->get($response->json('data.attachment_url'))->assertOk();
    }

    public function test_an_attachment_is_refused_to_a_non_participant(): void
    {
        Storage::fake('private');

        $conversation = $this->conversation();

        Sanctum::actingAs($this->traineeUser);

        $url = $this->postJson("/api/v1/chat/conversations/{$conversation->uuid}/messages", [
            'type' => 'image',
            'attachment' => UploadedFile::fake()->image('private.jpg'),
        ])->assertCreated()->json('data.attachment_url');

        // A leaked URL is useless outside the conversation.
        Sanctum::actingAs($this->admin());
        $this->get($url)->assertForbidden();
    }

    public function test_a_disguised_file_is_rejected_on_its_real_type(): void
    {
        Storage::fake('private');

        $conversation = $this->conversation();

        Sanctum::actingAs($this->traineeUser);

        // A .jpg name on a PHP file is the oldest upload trick there is. Built
        // as a real UploadedFile rather than a fake one on purpose:
        // `UploadedFile::fake()` reports a MIME type guessed from the extension,
        // so it would sail through the very check being tested. A real upload is
        // sniffed from its bytes and reports text/x-php.
        $path = tempnam(sys_get_temp_dir(), 'chat').'.jpg';
        file_put_contents($path, '<?php echo "x";');

        $this->post("/api/v1/chat/conversations/{$conversation->uuid}/messages", [
            'type' => 'image',
            'attachment' => new UploadedFile($path, 'shell.jpg', 'image/jpeg', null, true),
        ], ['Accept' => 'application/json'])->assertStatus(422);

        $this->assertSame(0, Message::count());
        $this->assertEmpty(Storage::disk('private')->allFiles());
    }

    public function test_a_voice_note_keeps_its_duration(): void
    {
        Storage::fake('private');

        $conversation = $this->conversation();

        Sanctum::actingAs($this->traineeUser);

        $this->postJson("/api/v1/chat/conversations/{$conversation->uuid}/messages", [
            'type' => 'audio',
            'duration_seconds' => 14,
            'attachment' => UploadedFile::fake()->create('note.m4a', 120, 'audio/mp4'),
        ])->assertCreated()
            ->assertJsonPath('data.type', 'audio')
            ->assertJsonPath('data.duration_seconds', 14);

        $this->assertSame('🎤 رسالة صوتية', Message::first()->preview());
    }

    /**
     * The voice note a real Android recorder produces.
     *
     * An m4a is an MP4 container, so depending on the `ftyp` brand the same AAC
     * recording is sniffed as `audio/x-m4a`, `video/mp4` or `application/mp4`.
     * The first version of the whitelist held only `audio/*` spellings and
     * rejected genuine recordings from the app with "نوع الملف غير مدعوم" — this
     * pins each brand so that cannot come back.
     *
     */
    public function test_every_container_brand_a_recorder_writes_is_accepted(): void
    {
        Storage::fake('private');

        $conversation = $this->conversation();

        // Real ISO-BMFF headers, one per brand a phone may write. Written as
        // escapes rather than literal bytes so the file stays valid UTF-8.
        $brands = [
            'M4A ' => "\x00\x00\x00\x20ftypM4A \x00\x00\x02\x00M4A mp42isom",
            'mp42' => "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom",
            'adts' => "\xFF\xF1\x50\x80\x00\x1F\xFC".str_repeat("\x00", 512),
        ];

        foreach ($brands as $label => $header) {
            $path = tempnam(sys_get_temp_dir(), 'note').'.m4a';
            file_put_contents($path, $header.str_repeat("\x00", 2048));

            Sanctum::actingAs($this->traineeUser);

            $this->post(
                "/api/v1/chat/conversations/{$conversation->uuid}/messages",
                [
                    'type' => 'audio',
                    'duration_seconds' => 7,
                    'attachment' => new UploadedFile($path, 'note.m4a', 'audio/mp4', null, true),
                ],
                ['Accept' => 'application/json'],
            )->assertCreated("brand {$label} must be accepted as a voice note");
        }

        $this->assertSame(
            count($brands),
            Message::where('type', 'audio')->count(),
            'every brand should have produced a stored voice note',
        );
    }

    public function test_a_closed_thread_accepts_nothing(): void
    {
        $conversation = $this->conversation();
        $conversation->forceFill(['is_closed' => true])->save();

        Sanctum::actingAs($this->traineeUser);

        $this->postJson("/api/v1/chat/conversations/{$conversation->uuid}/messages", [
            'body' => 'هل من أحد؟',
        ])->assertStatus(422);
    }

    public function test_a_trainee_with_no_trainer_is_told_why(): void
    {
        $this->trainee->forceFill(['trainer_id' => null])->save();

        Sanctum::actingAs($this->traineeUser);

        $this->getJson('/api/v1/chat/my-conversation')
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }
}
