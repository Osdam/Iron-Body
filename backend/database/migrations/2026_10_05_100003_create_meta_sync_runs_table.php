<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cada intento de sincronizar Meta Ads, salga bien o mal.
 *
 * Es lo que permite distinguir un gasto de $0 de un gasto que no se sabe. Una
 * suma vacía no dice nada por sí sola: dice «cero» solo si una pasada CON ÉXITO
 * cubrió esos días. Por eso se guarda el rango que pidió cada pasada y cómo
 * terminó, y no solo las cifras.
 *
 * `error_message` se guarda saneado (sin tokens y con los ids largos
 * enmascarados), porque llega hasta el CRM como motivo del error. Un fallo
 * interno (`internal`) solo guarda un texto genérico: su detalle (host, base,
 * SQL) se queda en el log del canal y en `failed_jobs`.
 *
 * `ad_account_id` acota la cobertura a la cuenta configurada: si la cuenta
 * cambia, las pasadas de la anterior no pueden dar por cubierto un periodo de la
 * nueva.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_sync_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 32)->default('insights');
            // running | ok | failed | not_configured
            $table->string('status', 16);
            // schedule | manual
            $table->string('trigger', 16);
            $table->string('ad_account_id', 64)->nullable();
            // Días de la cuenta publicitaria, ambos inclusive.
            $table->date('range_from')->nullable();
            $table->date('range_to')->nullable();
            $table->unsignedInteger('rows_upserted')->default(0);
            // permission | rate_limit | transient | other | interrupted |
            // internal, o el motivo de un not_configured.
            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            // Las consultas calientes: el último intento y el último éxito.
            $table->index(['kind', 'status', 'id'], 'meta_sync_runs_kind_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_sync_runs');
    }
};
