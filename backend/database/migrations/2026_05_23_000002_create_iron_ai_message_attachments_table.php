<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * IRON IA multimodal — adjuntos de mensajes (audio / imagen / archivo).
 *
 * Cada adjunto pertenece a un mensaje (message_id) y a una conversación.
 * Ownership flexible (user/member/document) igual que el resto de IRON IA.
 * Los audios se guardan en disco privado (local) y las imágenes en `public`
 * para poder devolver una preview URL. No se exponen rutas privadas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('iron_ai_message_attachments', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('message_id')->nullable();
            $table->unsignedBigInteger('iron_ai_conversation_id')->nullable();
            $table->string('conversation_uuid')->nullable();

            // Ownership flexible (auditoría / aislamiento).
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('member_id')->nullable();
            $table->string('document')->nullable();

            // audio | image | file
            $table->string('type');
            $table->string('original_name')->nullable();
            $table->string('stored_path')->nullable();
            $table->string('disk')->nullable();          // local | public
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->integer('duration_seconds')->nullable(); // audio
            $table->text('transcript')->nullable();          // audio → texto
            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index('message_id');
            $table->index('iron_ai_conversation_id');
            $table->index('conversation_uuid');
            $table->index('type');
            $table->index('document');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('iron_ai_message_attachments');
    }
};
