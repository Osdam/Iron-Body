<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Evidencia congelada de un reporte.
 *
 * Problema que resuelve: una Story vive 24 h y puede borrarse por su autor en
 * cualquier momento. Si el moderador abre el caso después, no puede quedarse
 * sin nada que revisar. Al crear el reporte capturamos aquí la metadata y la
 * REFERENCIA al medio (ruta del objeto en el bucket), nunca una URL pública
 * permanente.
 *
 * Qué NO se guarda: tokens, URLs firmadas completas, credenciales de Firebase,
 * ni PII que no sea imprescindible. `media_url_snapshot` queda nullable y solo
 * se usa para stories legacy en disco Laravel, donde la ruta ya es pública por
 * diseño previo; para Firebase se guarda únicamente el `gs://`/objeto y el CRM
 * pide una URL firmada temporal cuando el moderador la necesita.
 *
 * Retención: `purge_after` marca cuándo el job de limpieza puede borrar el
 * binario. Mientras exista un caso abierto, el binario NO se borra.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('report_content_snapshots')) {
            return;
        }

        Schema::create('report_content_snapshots', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('report_id');

            // Identificador ESTABLE del contenido original (sobrevive al borrado).
            $table->unsignedBigInteger('original_story_id');
            $table->string('author_type', 16)->default('member');
            $table->unsignedBigInteger('author_member_id')->nullable();

            $table->string('media_type', 16)->default('image'); // image | video
            // Ruta del objeto en el almacenamiento (bucket Firebase o disco
            // Laravel). NO es una URL pública: el CRM firma una temporal.
            $table->string('media_storage_path', 1000)->nullable();
            $table->string('media_disk', 32)->default('firebase');
            // Solo para contenido legacy en disco público. Nullable a propósito.
            $table->string('media_url_snapshot', 1000)->nullable();

            $table->string('caption_snapshot', 500)->nullable();

            $table->timestamp('published_at')->nullable();
            $table->timestamp('expires_at')->nullable();

            // Hash de integridad del objeto cuando esté disponible (no se
            // recalcula descargando el binario; se usa el que reporte Storage).
            $table->string('checksum', 128)->nullable();

            // Metadata saneada (tipo, tamaño, duración). Nunca headers ni tokens.
            $table->json('metadata')->nullable();

            $table->timestamp('captured_at')->nullable();
            // Momento a partir del cual la limpieza puede borrar el binario.
            $table->timestamp('purge_after')->nullable();
            $table->timestamp('media_purged_at')->nullable();

            $table->timestamps();

            $table->index('report_id', 'report_snapshots_report_idx');
            $table->index('original_story_id', 'report_snapshots_story_idx');
            $table->index('purge_after', 'report_snapshots_purge_idx');
        });

        try {
            Schema::table('report_content_snapshots', function (Blueprint $table) {
                $table->foreign('report_id', 'report_snapshots_report_fk')
                    ->references('id')->on('content_reports')->cascadeOnDelete();
            });
        } catch (Throwable $e) {
            // Integridad garantizada por el servicio de evidencia.
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('report_content_snapshots');
    }
};
