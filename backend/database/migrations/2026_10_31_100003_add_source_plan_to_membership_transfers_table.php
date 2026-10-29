<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TRASPASO DE MEMBRESÍA: el plan COMPRADO, además del transferido.
 *
 * Con un traslado de plan vivo, la beneficiaria recibe el plan VIGENTE de la
 * titular («Élite») y no el del cobro («TOTAL ACCESS - ELITE»). `plan_id` /
 * `plan_name` siguen siendo lo transferido; esto guarda de dónde salió:
 *   · `source_plan_id` / `source_plan_name`: el plan del cobro de origen, con
 *     el nombre congelado (sobrevive aunque el plan cambie de nombre);
 *   · `plan_transfer_item_id`: el ítem de traslado que justifica la diferencia
 *     (nulo cuando no la hay).
 *
 * SIN llaves foráneas, a propósito: añadir una a `membership_transfers` en
 * SQLite reconstruye la tabla y Laravel recrearía el índice único PARCIAL
 * `membership_transfers_one_live_per_payment` sin su WHERE (un traspaso por
 * cobro PARA SIEMPRE, no uno vivo). Son referencias históricas con el nombre
 * congelado al lado, como `payment_transactions.plan_id`.
 *
 * Aditiva y reversible; las filas anteriores quedan con las tres en nulo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('membership_transfers', function (Blueprint $table): void {
            $table->unsignedBigInteger('source_plan_id')->nullable();
            $table->string('source_plan_name', 100)->nullable();
            $table->unsignedBigInteger('plan_transfer_item_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('membership_transfers', function (Blueprint $table): void {
            $table->dropColumn(['source_plan_id', 'source_plan_name', 'plan_transfer_item_id']);
        });
    }
};
