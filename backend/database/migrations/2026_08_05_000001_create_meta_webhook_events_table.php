<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bitácora cruda de lo que Meta nos entregó, ANTES de trabajar sobre ello.
 *
 * Hasta hoy el webhook validaba la firma y despachaba el payload a la cola; si
 * el worker moría a mitad, ese mensaje del prospecto se perdía sin rastro. Esta
 * tabla es el sistema de registro: se escribe de forma síncrona dentro del
 * request de Meta y sobrevive a cualquier fallo posterior, así que un evento
 * siempre se puede reprocesar (marketing:replay-webhook) o revisar.
 *
 * `payload_hash` (SHA-256 del cuerpo crudo) es único: una reentrega de Meta —o
 * un replay malicioso del mismo cuerpo firmado— reconoce el evento existente en
 * lugar de crear otro. La idempotencia por meta_message_id sigue vigente aguas
 * abajo; ésta es la primera barrera, no la única.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_webhook_events', function (Blueprint $table): void {
            $table->id();
            // Hilo que une webhook → job → mensaje → decisión → envío → status.
            $table->uuid('correlation_id')->index();
            // sha256 del cuerpo crudo: barrera anti-replay / anti-reentrega.
            $table->string('payload_hash', 64)->unique();
            $table->string('object')->nullable();          // whatsapp_business_account | instagram | page
            $table->string('phone_number_id')->nullable(); // número al que llegó
            $table->jsonb('payload');                      // cuerpo verificado por firma (sin cabeceras ni secretos)
            $table->unsignedInteger('payload_bytes')->default(0);
            $table->unsignedSmallInteger('messages_count')->default(0);
            $table->unsignedSmallInteger('statuses_count')->default(0);

            // pending → processing → processed | failed → dead
            $table->string('status')->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('last_error_class')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            // Cola de trabajo pendiente y panel de IRON GUARD: "qué quedó sin procesar".
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_webhook_events');
    }
};
