<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('packages', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('branch_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('license_type', 40)->default('private');
            $table->unsignedSmallInteger('lessons_count');
            $table->unsignedSmallInteger('lesson_duration_minutes')->default(45);
            $table->decimal('price', 12, 2);
            $table->decimal('extra_lesson_price', 12, 2)->default(0);
            $table->decimal('max_discount_percent', 5, 2)->default(0);
            $table->unsignedSmallInteger('validity_days')->nullable();
            $table->text('description')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->softDeletes();
            $table->timestamps();

            $table->index(['branch_id', 'status']);
        });

        // A trainee's enrolment in a package. Financial totals live here;
        // the lesson balance is derived from lesson_transactions.
        Schema::create('trainee_packages', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trainee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('package_id')->constrained()->restrictOnDelete();

            // Package terms are copied at enrolment so later price edits never
            // rewrite historical contracts.
            $table->string('package_name');
            $table->unsignedSmallInteger('lessons_count');
            $table->unsignedSmallInteger('lesson_duration_minutes');
            $table->decimal('unit_price', 12, 2);
            $table->decimal('extra_lesson_price', 12, 2)->default(0);

            $table->decimal('gross_amount', 12, 2);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->string('discount_reason')->nullable();
            $table->decimal('total_amount', 12, 2);      // gross - discount + extras
            $table->decimal('extras_amount', 12, 2)->default(0);
            $table->decimal('paid_amount', 12, 2)->default(0);

            $table->date('started_on');
            $table->date('expires_on')->nullable();
            $table->enum('status', ['active', 'completed', 'expired', 'cancelled'])->default('active');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['trainee_id', 'status']);
            $table->index(['branch_id', 'status']);
        });

        // Append-only lesson ledger. The remaining balance is SUM(quantity).
        Schema::create('lesson_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trainee_package_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trainee_id')->constrained()->cascadeOnDelete();
            $table->enum('type', [
                'package_credit',   // + lessons purchased
                'extra_credit',     // + additional paid lesson
                'manual_credit',    // + correction
                'consumption',      // - completed lesson
                'no_show',          // - lesson burnt by absence
                'late_cancellation',// - lesson burnt by policy
                'refund',           // - credit removed
                'manual_debit',     // - correction
            ]);
            $table->smallInteger('quantity'); // signed: credits positive, debits negative
            $table->foreignId('training_session_id')->nullable();
            $table->string('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['trainee_package_id', 'created_at']);
            $table->index(['trainee_id', 'type']);
            $table->index('training_session_id');
        });

        Schema::create('training_skills', function (Blueprint $table) {
            $table->id();
            $table->string('name_ar');
            $table->string('code', 60)->unique();
            $table->string('description')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();
        });

        // The single scheduled-and-delivered lesson entity. The calendar and the
        // /api/v1/appointments endpoints are scheduling-focused projections of this
        // table — see README "Appointments vs training sessions".
        Schema::create('training_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trainee_id')->constrained()->restrictOnDelete();
            $table->foreignId('trainer_id')->constrained()->restrictOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('trainee_package_id')->nullable()->constrained()->nullOnDelete();

            $table->date('scheduled_date');
            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedSmallInteger('duration_minutes');

            $table->enum('status', ['scheduled', 'completed', 'cancelled', 'postponed', 'no_show'])
                ->default('scheduled');

            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('overall_rating', ['needs_training', 'average', 'good', 'very_good', 'excellent'])->nullable();
            $table->text('strengths')->nullable();
            $table->text('weaknesses')->nullable();
            $table->text('trainer_notes')->nullable();
            $table->text('next_requirements')->nullable();

            $table->string('cancellation_reason')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            // Set on the original row when it is moved to a new slot.
            $table->foreignId('rescheduled_to_id')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['branch_id', 'scheduled_date', 'status']);
            $table->index(['trainer_id', 'scheduled_date']);
            $table->index(['trainee_id', 'scheduled_date']);
            $table->index(['vehicle_id', 'scheduled_date']);
            $table->index('scheduled_date');
        });

        Schema::table('lesson_transactions', function (Blueprint $table) {
            $table->foreign('training_session_id')->references('id')->on('training_sessions')->nullOnDelete();
        });

        Schema::table('training_sessions', function (Blueprint $table) {
            $table->foreign('rescheduled_to_id')->references('id')->on('training_sessions')->nullOnDelete();
        });

        // Skills practised in one session, with the rating given that day.
        Schema::create('training_session_skills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('training_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('training_skill_id')->constrained()->cascadeOnDelete();
            $table->enum('rating', ['not_started', 'needs_training', 'average', 'good', 'very_good', 'excellent'])
                ->default('not_started');
            $table->string('note')->nullable();
            $table->timestamps();

            $table->unique(['training_session_id', 'training_skill_id'], 'session_skill_unique');
        });

        // Rolling current level per trainee per skill.
        Schema::create('trainee_skill_evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trainee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('training_skill_id')->constrained()->cascadeOnDelete();
            $table->enum('level', ['not_started', 'needs_training', 'average', 'good', 'very_good', 'excellent'])
                ->default('not_started');
            $table->foreignId('last_session_id')->nullable()->constrained('training_sessions')->nullOnDelete();
            $table->foreignId('evaluated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('evaluated_at')->nullable();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->unique(['trainee_id', 'training_skill_id']);
        });

        // Requests raised from the Trainee app and triaged in the dashboard.
        Schema::create('booking_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trainee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('training_session_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('type', ['booking', 'reschedule', 'cancellation'])->default('booking');
            $table->date('requested_date')->nullable();
            $table->time('requested_start_time')->nullable();
            $table->foreignId('preferred_trainer_id')->nullable()->constrained('trainers')->nullOnDelete();
            $table->text('trainee_note')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected', 'rescheduled', 'cancelled'])->default('pending');
            $table->text('admin_note')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            // The session created or moved as a result of approving this request.
            $table->foreignId('resulting_session_id')->nullable()->constrained('training_sessions')->nullOnDelete();
            $table->timestamps();

            $table->index(['branch_id', 'status']);
            $table->index(['trainee_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_requests');
        Schema::dropIfExists('trainee_skill_evaluations');
        Schema::dropIfExists('training_session_skills');
        Schema::table('lesson_transactions', function (Blueprint $table) {
            $table->dropForeign(['training_session_id']);
        });
        Schema::dropIfExists('training_sessions');
        Schema::dropIfExists('training_skills');
        Schema::dropIfExists('lesson_transactions');
        Schema::dropIfExists('trainee_packages');
        Schema::dropIfExists('packages');
    }
};
