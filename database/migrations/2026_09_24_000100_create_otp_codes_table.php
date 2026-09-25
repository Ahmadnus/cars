<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One-time passcodes for phone login.
 *
 * The code itself is never stored in readable form — only a hash — so a dump
 * of this table cannot be replayed against a live account. Rows are kept after
 * consumption as an audit trail and pruned by `otp:prune`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('otp_codes', function (Blueprint $table) {
            $table->id();
            // Normalised local form (07XXXXXXXX), so a request sent as +962…
            // and one sent as 07… hit the same row.
            $table->string('phone', 30);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code_hash');
            $table->string('purpose', 30)->default('login');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('channel', 20)->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index(['phone', 'purpose', 'consumed_at']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('otp_codes');
    }
};
