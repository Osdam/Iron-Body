<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tokens de re-enrolamiento biométrico: de un solo uso, vida corta y ligados a
 * un miembro + el ticket facial (challenge OTP verificado). Autorizan SUSTITUIR
 * la referencia facial sólo después de un segundo factor (OTP confirmado). El
 * token nunca se guarda en claro: se persiste su hash.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('member_reenrollment_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            // Ticket facial (uuid del MemberAuthChallenge) al que queda atado.
            $table->string('challenge_uuid')->index();
            // Hash del token de un solo uso (nunca en claro).
            $table->string('token_hash');
            $table->string('reason')->nullable();
            // pending | used | expired
            $table->string('status')->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('device_id')->nullable();
            $table->string('ip_address', 64)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            $table->index(['member_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_reenrollment_tokens');
    }
};
