<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El expediente del consentimiento.
 *
 * `do_not_contact` es un booleano en la fila del lead: dice el estado de AHORA
 * y no dice nada de cómo se llegó a él. Mientras sólo se cerraba la puerta eso
 * bastaba. Desde que existe una vía de reapertura no basta: quien reabre un
 * contacto está autorizando a escribirle a alguien que pidió que no, y eso hay
 * que poder defenderlo después con nombre, motivo y fecha.
 *
 * La tabla es APPEND-ONLY por diseño: cada cambio de consentimiento escribe una
 * fila y ninguna se edita. Un expediente que se puede reescribir no prueba nada.
 *
 * La migración NO toca ningún lead: sólo crea la tabla. Los que ya están
 * marcados siguen exactamente igual, que es lo que tiene que pasar.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('marketing_consent_events')) {
            return;
        }

        Schema::create('marketing_consent_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('marketing_lead_id')->constrained()->cascadeOnDelete();

            // Antes y después, los dos, para que la fila se lea sola sin
            // reconstruir la historia entera.
            $table->boolean('from_do_not_contact');
            $table->boolean('to_do_not_contact');
            $table->string('from_consent_status', 32)->nullable();
            $table->string('to_consent_status', 32)->nullable();

            // QUIÉN lo autorizó y POR QUÉ. `source` es vocabulario cerrado
            // (lo valida ConsentAuthority); `reason` es prosa del operador.
            $table->string('source', 40);
            $table->string('reason', 300);

            // Hay dos vías y sólo una tiene persona detrás: la administrativa.
            // Cuando reabre la propia persona con sus palabras, el actor es
            // nulo y la prueba es la cita.
            $table->unsignedBigInteger('actor_admin_id')->nullable();
            $table->string('evidence', 300)->nullable();
            $table->unsignedBigInteger('marketing_message_id')->nullable();

            $table->timestamps();

            $table->index(['marketing_lead_id', 'id']);
            $table->index('source');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_consent_events');
    }
};
