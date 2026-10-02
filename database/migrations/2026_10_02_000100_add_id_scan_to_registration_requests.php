<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What was read off the applicant's ID photo.
 *
 * Kept beside the applicant's own answers rather than written over them: the two
 * disagree often enough — a nickname on the form, a full four-part name on the
 * card — and the reviewer needs to see both to decide which one goes into the
 * licence paperwork. Overwriting would also throw away the only record of what
 * the applicant actually typed.
 *
 * One JSON column rather than a column per field, because none of it is ever
 * queried: it is read whole on one screen, and the fields the office accepts are
 * copied onto the trainee at approval, where they already have columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('registration_requests', function (Blueprint $table) {
            $table->json('id_scan')->nullable()->after('notes');

            // Separate from the payload so "has this been read" is answerable
            // without unpacking JSON, and so a stale reading is visible as stale.
            $table->timestamp('id_scanned_at')->nullable()->after('id_scan');
        });
    }

    public function down(): void
    {
        Schema::table('registration_requests', function (Blueprint $table) {
            $table->dropColumn(['id_scan', 'id_scanned_at']);
        });
    }
};
