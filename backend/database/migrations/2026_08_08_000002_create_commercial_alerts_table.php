<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cosas concretas que alguien tiene que mirar.
 *
 * Tabla propia y no una fila más en `incidents`, aunque se parezcan. Un
 * incidente es una avería técnica —un worker caído, un disco lleno— y se cierra
 * arreglando algo. Una alerta comercial es una persona esperando: un pago a
 * medias, un cliente caliente al que nadie escribió, una pauta que promete lo
 * que ya no existe. Se cierran de forma distinta, las mira gente distinta y
 * mezclarlas obligaría a que cada consulta recordara excluir la otra.
 *
 * Y tampoco es un insight. Un insight EXPLICA una tendencia («esta campaña
 * convierte poco»); una alerta EXIGE una decisión sobre alguien concreto. Por
 * eso esto se persiste, se asigna y se cierra, y los insights se recalculan.
 *
 * El `fingerprint` único es lo que impide que cada evaluación abra otra alerta
 * por el mismo pago pendiente. Sin él, un cron cada quince minutos convierte
 * una situación en noventa y seis alertas al día y la bandeja se vuelve ruido.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commercial_alerts', function (Blueprint $table): void {
            $table->id();

            $table->string('type');
            $table->string('severity')->default('medium');
            $table->string('status')->default('open');

            // A quién afecta. Todos opcionales: «atribución desconocida
            // elevada» no habla de una persona concreta.
            $table->unsignedBigInteger('marketing_lead_id')->nullable();
            $table->unsignedBigInteger('member_id')->nullable();
            $table->unsignedBigInteger('marketing_conversation_id')->nullable();
            $table->unsignedBigInteger('commercial_opportunity_id')->nullable();
            $table->string('campaign_name')->nullable();
            $table->string('ad_id')->nullable();

            $table->string('title');
            $table->text('summary');
            $table->jsonb('evidence')->nullable();

            // Lo que se recomienda hacer. Es una recomendación: nada de esto
            // ejecuta una venta ni escribe a nadie por existir.
            $table->text('suggested_action')->nullable();
            /** Valor comercial en juego, si se puede calcular. Nulo != cero. */
            $table->decimal('opportunity_value', 12, 2)->nullable();

            $table->timestamp('detected_at');
            $table->timestamp('due_at')->nullable();

            $table->unsignedBigInteger('owner_admin_id')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolution')->nullable();
            $table->text('resolution_note')->nullable();

            // La misma situación no puede abrir dos alertas.
            $table->string('fingerprint')->unique();

            $table->timestamps();

            // La bandeja se lee siempre igual: abiertas, las graves primero.
            $table->index(['status', 'severity', 'detected_at']);
            $table->index(['type', 'status']);
            $table->index('marketing_lead_id');
            $table->index('owner_admin_id');
            $table->index('due_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commercial_alerts');
    }
};
