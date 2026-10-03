<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cómo cerró el decide un turno cuando lo cerró sin commit.
 *
 * El decide no crea filas, así que un turno que cortó la pausa del agente, o
 * que relevó un mensaje más nuevo, no dejaba rastro. Sin rastro, el vigía no lo
 * distinguía de un turno que murió en n8n, y en el canario eso era un freno
 * falso que solo se suelta a mano.
 *
 * Columna propia y no `last_error`: esa la reescribe el job que entrega el
 * evento al terminar, al fallar y al reintentar, y n8n le responde DESPUÉS del
 * decide, al final del turno. Esta solo la escribe el decide
 * (`UltronDecideService::recordOutcome()`).
 *
 * Nullable y sin valor por defecto: en PostgreSQL añadirla no reescribe la
 * tabla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_automation_events', function (Blueprint $table): void {
            $table->string('decide_outcome', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('marketing_automation_events', function (Blueprint $table): void {
            $table->dropColumn('decide_outcome');
        });
    }
};
