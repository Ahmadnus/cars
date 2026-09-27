<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The trainer's say on a reschedule.
 *
 * Moving a lesson is the trainer's diary, so they decide first and the office
 * applies it. That is two decisions on one request, and they cannot share a
 * column: the office needs to see *that* the trainer agreed and *who* agreed
 * before it touches the calendar, and an audit a month later has to show both.
 *
 * Held on the request rather than in a second table — it is one extra decision
 * on an existing row, not a new kind of thing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_requests', function (Blueprint $table) {
            // Null for a request the trainer has no say in — a plain booking,
            // which reception schedules — so "needs the trainer" is expressed by
            // the column being set at all.
            $table->string('trainer_decision', 20)->nullable()->after('trainee_note');

            $table->text('trainer_note')->nullable()->after('trainer_decision');

            $table->foreignId('trainer_decided_by')
                ->nullable()
                ->after('trainer_note')
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('trainer_decided_at')->nullable()->after('trainer_decided_by');

            // The queue a trainer opens is "what is waiting on me", so the index
            // is on the pair rather than on the decision alone.
            $table->index(['preferred_trainer_id', 'trainer_decision'], 'br_trainer_decision_idx');
        });
    }

    public function down(): void
    {
        Schema::table('booking_requests', function (Blueprint $table) {
            $table->dropIndex('br_trainer_decision_idx');
            $table->dropConstrainedForeignId('trainer_decided_by');
            $table->dropColumn(['trainer_decision', 'trainer_note', 'trainer_decided_at']);
        });
    }
};
