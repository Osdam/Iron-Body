<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historial de estados de entrega de un mensaje saliente.
 *
 * `marketing_messages.status` guarda el estado ACTUAL; esta tabla guarda cómo
 * se llegó a él. Hace falta porque Meta entrega los callbacks fuera de orden:
 * un 'sent' puede llegar después de un 'read', y la reconciliación descarta ese
 * retroceso. Sin historial, esa decisión sería invisible y un "¿por qué figura
 * leído si Meta mandó sent?" no tendría respuesta.
 *
 * También es donde vive el código de error de Meta cuando un envío falla: sin
 * él, un "failed" en el inbox no le dice nada a quien atiende.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_message_statuses', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('message_id');
            $table->string('status');                       // sent|delivered|read|failed
            // Si false, el callback llegó tarde/fuera de orden y NO movió el
            // estado actual. Se guarda igual: es evidencia, no ruido.
            $table->boolean('applied')->default(true);
            $table->unsignedInteger('error_code')->nullable();
            $table->string('error_title')->nullable();
            $table->text('error_message')->nullable();
            // Momento que reporta Meta (epoch), distinto de cuándo lo recibimos.
            $table->timestamp('occurred_at')->nullable();
            $table->uuid('correlation_id')->nullable()->index();
            $table->jsonb('metadata')->nullable();          // conversation/pricing de Meta
            $table->timestamps();

            $table->index(['message_id', 'id']);
            // Panel de IRON GUARD: "errores de Meta por código en la última hora".
            $table->index(['error_code', 'created_at']);

            $table->foreign('message_id')->references('id')->on('marketing_messages')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_message_statuses');
    }
};
