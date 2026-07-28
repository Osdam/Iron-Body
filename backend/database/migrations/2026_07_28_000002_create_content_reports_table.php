<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reportes de contenido generado por usuarios (Stories).
 *
 * Fuente de verdad: PostgreSQL. Firebase Storage guarda SOLO el binario; el
 * caso, su estado y su evidencia viven aquí. Un reporte sobrevive a la
 * expiración (24 h) y al borrado de la Story: por eso guardamos
 * `content_id` como valor plano (no FK con cascade) además del snapshot.
 *
 * `public_id` (UUID) es el identificador que se muestra en el CRM y en la app:
 * evita enumeración de IDs secuenciales y no filtra el volumen del sistema.
 *
 * Anonimato del reportante: `reporter_member_id` NUNCA se serializa hacia el
 * CRM ni hacia la app. Es solo para deduplicar, aplicar rate limit y notificar
 * el cierre del caso.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('content_reports')) {
            return;
        }

        Schema::create('content_reports', function (Blueprint $table) {
            $table->id();
            // Identificador público estable (se expone; el `id` interno no).
            $table->uuid('public_id')->unique();

            // Quién reportó. Confidencial — resuelto SIEMPRE del bearer.
            $table->unsignedBigInteger('reporter_member_id');

            // A quién se reporta. Resuelto del autor REAL de la Story, jamás
            // de un campo enviado por el cliente.
            $table->unsignedBigInteger('reported_member_id')->nullable();
            // 'member' | 'user' — una Story también puede ser de un admin CRM.
            $table->string('reported_author_type', 16)->default('member');
            $table->unsignedBigInteger('reported_author_id')->nullable();

            // Contenido reportado. `content_id` es plano a propósito: debe
            // sobrevivir al borrado de la fila `stories`.
            $table->string('content_type', 32)->default('story');
            $table->unsignedBigInteger('content_id');

            // Catálogo cerrado (App\Support\Moderation\ReportReason).
            $table->string('reason_code', 48);
            // Texto libre OPCIONAL del reportante — saneado y acotado.
            $table->text('reason_detail')->nullable();

            // Máquina de estados (App\Support\Moderation\ReportStatus).
            $table->string('status', 32)->default('submitted');
            // low | medium | high | critical — derivado del motivo, no del cliente.
            $table->string('severity', 16)->default('medium');
            $table->unsignedSmallInteger('priority')->default(50);

            $table->unsignedBigInteger('assigned_admin_id')->nullable();

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('resolved_at')->nullable();

            // Resultado del caso (dismissed_no_violation, content_removed, ...).
            $table->string('resolution_code', 48)->nullable();
            $table->text('moderator_notes')->nullable();

            $table->timestamp('reporter_notified_at')->nullable();
            $table->timestamp('reported_user_notified_at')->nullable();

            // Control optimista: dos moderadores no pueden resolver el mismo
            // caso a la vez sin que el segundo reciba 409.
            $table->unsignedInteger('lock_version')->default(0);

            $table->timestamps();

            $table->index(['status', 'priority'], 'content_reports_queue_idx');
            $table->index(['content_type', 'content_id'], 'content_reports_content_idx');
            $table->index('reported_member_id', 'content_reports_reported_idx');
            $table->index('reporter_member_id', 'content_reports_reporter_idx');
            $table->index('assigned_admin_id', 'content_reports_assignee_idx');
            $table->index('submitted_at', 'content_reports_submitted_idx');

            // Un reportante no puede tener DOS reportes ABIERTOS sobre el mismo
            // contenido. La unicidad total se garantiza en servicio (el índice
            // parcial no es portable entre motores), pero este índice hace la
            // comprobación barata.
            $table->index(
                ['reporter_member_id', 'content_type', 'content_id', 'status'],
                'content_reports_dedup_idx'
            );
        });

        try {
            Schema::table('content_reports', function (Blueprint $table) {
                $table->foreign('reporter_member_id', 'content_reports_reporter_fk')
                    ->references('id')->on('members')->cascadeOnDelete();
                $table->foreign('assigned_admin_id', 'content_reports_admin_fk')
                    ->references('id')->on('admins')->nullOnDelete();
            });
        } catch (Throwable $e) {
            // La integridad efectiva la garantiza el servicio de moderación.
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('content_reports');
    }
};
