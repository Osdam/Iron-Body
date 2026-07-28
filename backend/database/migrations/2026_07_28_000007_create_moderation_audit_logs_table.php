<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bitácora APPEND-ONLY de moderación.
 *
 * Se escribe una fila por cada hecho relevante (reporte creado, caso asignado,
 * transición de estado, sanción aplicada/revocada, apelación resuelta,
 * bloqueo/desbloqueo). El modelo `ModerationAuditLog` bloquea `update` y
 * `delete` a nivel de aplicación; la tabla no tiene `updated_at` para que un
 * UPDATE accidental sea evidente en revisión.
 *
 * PROHIBIDO guardar aquí: tokens, contraseñas, headers Authorization, URLs
 * firmadas completas, secretos de Firebase, o el texto íntegro de contenido
 * sensible. `before_data`/`after_data` se sanean antes de persistir
 * (ver App\Support\Moderation\AuditSanitizer).
 *
 * IP: se guarda HASHEADA (HMAC con APP_KEY) — permite correlacionar campañas
 * coordinadas sin almacenar la dirección en claro.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('moderation_audit_logs')) {
            return;
        }

        Schema::create('moderation_audit_logs', function (Blueprint $table) {
            $table->id();

            // member | admin | system
            $table->string('actor_type', 16);
            $table->unsignedBigInteger('actor_id')->nullable();

            // report_submitted, member_blocked, moderation_action_applied, ...
            $table->string('action', 64);

            $table->string('entity_type', 48);
            $table->unsignedBigInteger('entity_id')->nullable();

            $table->json('before_data')->nullable();
            $table->json('after_data')->nullable();

            // HMAC de la IP — nunca la IP en claro.
            $table->string('ip_hash', 64)->nullable();
            // User-agent RESUMIDO (familia/plataforma), no la cadena completa.
            $table->string('user_agent', 120)->nullable();
            $table->string('request_id', 64)->nullable();

            // Solo created_at: la tabla es inmutable por diseño.
            $table->timestamp('created_at')->nullable();

            $table->index(['entity_type', 'entity_id'], 'moderation_audit_entity_idx');
            $table->index(['actor_type', 'actor_id'], 'moderation_audit_actor_idx');
            $table->index(['action', 'created_at'], 'moderation_audit_action_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('moderation_audit_logs');
    }
};
