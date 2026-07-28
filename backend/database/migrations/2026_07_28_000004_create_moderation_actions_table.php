<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Acciones de moderación aplicadas por un administrador.
 *
 * Una acción es el HECHO administrativo ("advertí a X", "oculté la story Y",
 * "restringí publicación 24 h"). Cuando la acción implica restringir al
 * miembro, además se materializa una fila en `member_suspensions` (el estado
 * consultable en caliente). Separarlas permite revocar la sanción sin borrar
 * la traza de que existió.
 *
 * `idempotency_key` evita que un doble click / reintento de red aplique dos
 * veces la misma decisión.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('moderation_actions')) {
            return;
        }

        Schema::create('moderation_actions', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();

            $table->unsignedBigInteger('report_id')->nullable();

            $table->unsignedBigInteger('target_member_id')->nullable();
            $table->unsignedBigInteger('target_story_id')->nullable();

            // warn | hide_content | restore_content | remove_content |
            // restrict_posting | suspend_social | suspend_full | dismiss | revoke
            $table->string('action_type', 40);
            // story_posting | story_interaction | social_features |
            // full_app_access | content_only
            $table->string('scope', 32)->default('content_only');

            // null = permanente/indefinida (solo con permiso elevado).
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();

            // Motivo PÚBLICO (se muestra al usuario sancionado).
            $table->string('reason', 300)->nullable();
            // Notas internas — NUNCA salen hacia la app.
            $table->text('internal_notes')->nullable();

            $table->unsignedBigInteger('created_by_admin_id')->nullable();
            $table->unsignedBigInteger('revoked_by_admin_id')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoke_reason', 300)->nullable();

            // Anti doble ejecución de decisiones críticas.
            $table->string('idempotency_key', 100)->nullable()->unique();

            $table->timestamps();

            $table->index('report_id', 'moderation_actions_report_idx');
            $table->index('target_member_id', 'moderation_actions_target_idx');
            $table->index('target_story_id', 'moderation_actions_story_idx');
            $table->index(['action_type', 'created_at'], 'moderation_actions_type_idx');
        });

        try {
            Schema::table('moderation_actions', function (Blueprint $table) {
                $table->foreign('report_id', 'moderation_actions_report_fk')
                    ->references('id')->on('content_reports')->nullOnDelete();
                $table->foreign('created_by_admin_id', 'moderation_actions_admin_fk')
                    ->references('id')->on('admins')->nullOnDelete();
                $table->foreign('revoked_by_admin_id', 'moderation_actions_revoker_fk')
                    ->references('id')->on('admins')->nullOnDelete();
            });
        } catch (Throwable $e) {
            // Integridad garantizada por el servicio de moderación.
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('moderation_actions');
    }
};
