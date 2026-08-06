<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adjuntos de WhatsApp (imagen, audio, nota de voz, video, documento, sticker).
 *
 * En la base SOLO vive el expediente del archivo: dónde está, cuánto pesa, qué
 * es realmente y si se pudo descargar. El binario nunca entra a PostgreSQL —ni
 * como bytea ni como base64—: un gimnasio recibiendo fotos y notas de voz a
 * diario inflaría la BD y con ella cada backup y cada restore.
 *
 * Hay DOS MIME a propósito. `declared_mime_type` es lo que Meta dice que es;
 * `detected_mime_type` es lo que el archivo resultó ser al inspeccionar sus
 * bytes. Cuando no coinciden estamos ante un archivo disfrazado y se rechaza.
 *
 * `sha256` es el hash que calculamos nosotros al descargar. Sirve para
 * deduplicar (el mismo folleto reenviado veinte veces ocupa una vez) y para
 * demostrar que el archivo servido hoy es el que llegó aquel día.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_message_attachments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('message_id');
            $table->string('direction')->default('inbound');   // inbound | outbound
            $table->string('kind');                            // image|audio|video|document|sticker

            // Identificador de Meta. Su URL de descarga caduca en minutos, por
            // eso la descarga se encola de inmediato y no se guarda la URL.
            $table->string('media_id')->nullable()->index();

            $table->string('declared_mime_type')->nullable();
            $table->string('detected_mime_type')->nullable();
            $table->string('meta_sha256', 128)->nullable();    // el que reporta Meta
            $table->string('sha256', 64)->nullable()->index(); // el que calculamos
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('original_filename')->nullable();   // saneado, nunca usado como ruta

            // Almacenamiento privado. `path` es relativo al disco: jamás se
            // expone al navegador, que solo recibe URLs firmadas de corta vida.
            $table->string('disk')->nullable();
            $table->string('path')->nullable();
            $table->string('thumbnail_path')->nullable();

            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->boolean('voice')->default(false);          // nota de voz vs audio adjunto

            // pending → downloading → stored | failed | rejected | expired
            $table->string('status')->default('pending');
            $table->string('failure_reason')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('downloaded_at')->nullable();
            // Retención: pasada esta fecha el binario se borra y queda la ficha.
            $table->timestamp('expires_at')->nullable();

            // Transcripción de audio: opcional, asíncrona y siempre atada al
            // audio original con su nivel de confianza.
            $table->text('transcript')->nullable();
            $table->string('transcript_status')->nullable();   // pending|done|failed|skipped
            $table->decimal('transcript_confidence', 4, 3)->nullable();

            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index(['message_id', 'id']);
            // Cola de descargas y barrido de retención.
            $table->index(['status', 'created_at']);
            $table->index('expires_at');

            $table->foreign('message_id')->references('id')->on('marketing_messages')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_message_attachments');
    }
};
