<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Self-registration, chat and push devices.
 *
 * These three features share one migration because they arrive together and
 * reference each other: an approved registration becomes a trainee, a trainee
 * gets a conversation with their trainer, and both ends need a device token to
 * be told about a new message while the app is closed.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
        | Push devices.
        |
        | One row per install, keyed by the provider token. A token can move
        | between accounts when a phone is handed over, so the token is unique
        | on its own and the owning user is simply overwritten.
        */
        Schema::create('device_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token', 512);
            $table->string('platform', 20);           // android | ios
            $table->string('app', 30)->default('trainee'); // trainee | trainer
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            // Providers issue tokens far longer than an index key may be, so the
            // uniqueness is enforced on a hash of the token instead.
            $table->string('token_hash', 64)->unique();
            $table->index(['user_id', 'platform']);
        });

        /*
        | Join requests from the public app.
        |
        | A request is deliberately *not* a trainee: nothing enters the training
        | records until a member of staff approves it, so a stranger filling the
        | form cannot create a file, consume a number, or appear in any report.
        */
        Schema::create('registration_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();

            // A short human reference the applicant can quote on the phone.
            $table->string('reference', 20)->unique();

            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();

            $table->string('full_name');
            $table->string('phone', 30);
            $table->string('secondary_phone', 30)->nullable();
            $table->string('national_id', 30)->nullable();
            $table->date('birth_date')->nullable();
            $table->string('gender', 10)->nullable();
            $table->string('city')->nullable();
            $table->string('address')->nullable();
            $table->string('license_type', 30)->nullable();
            $table->text('notes')->nullable();

            // Proof the applicant controls the phone, established by passcode
            // before the request is accepted. Without it the form would be an
            // open channel for junk under other people's numbers.
            $table->timestamp('phone_verified_at')->nullable();

            $table->string('status', 20)->default('pending'); // pending|reviewing|approved|rejected|withdrawn
            $table->text('decision_reason')->nullable();

            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();

            // Set once approved, so the request keeps a link to what it became.
            $table->foreignId('trainee_id')->nullable()->constrained()->nullOnDelete();

            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('phone');
        });

        /*
        | One conversation per trainee-trainer pair.
        |
        | Unread counters are stored per side rather than derived, because the
        | badge is read on every poll and counting unread rows each time would
        | scan the whole message table.
        */
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();

            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('trainee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trainer_id')->constrained()->cascadeOnDelete();

            $table->timestamp('last_message_at')->nullable();
            $table->string('last_message_preview')->nullable();

            $table->unsignedInteger('trainee_unread')->default(0);
            $table->unsignedInteger('trainer_unread')->default(0);

            // Staff can freeze a conversation without deleting its history.
            $table->boolean('is_closed')->default(false);

            $table->timestamps();

            $table->unique(['trainee_id', 'trainer_id'], 'conversation_pair_unique');
            $table->index('last_message_at');
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();

            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();

            // Which side sent it, kept denormalised so rendering a thread needs
            // no join back to trainee/trainer to decide left or right.
            $table->string('sender_role', 20);        // trainee | trainer | staff

            $table->string('type', 20)->default('text'); // text | image | audio
            $table->text('body')->nullable();

            // Attachments live on the private disk and are served only through
            // an authenticated, policy-checked route — never by the web server.
            $table->string('attachment_path')->nullable();
            $table->string('attachment_mime', 100)->nullable();
            $table->unsignedInteger('attachment_size')->nullable();
            $table->unsignedSmallInteger('duration_seconds')->nullable();

            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();

            $table->timestamps();

            $table->index(['conversation_id', 'id']);
            $table->index(['conversation_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversations');
        Schema::dropIfExists('registration_requests');
        Schema::dropIfExists('device_tokens');
    }
};
