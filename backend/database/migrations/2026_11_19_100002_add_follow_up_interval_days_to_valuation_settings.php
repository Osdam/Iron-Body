<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * INTERVALO DE SEGUIMIENTO DEL GIMNASIO (CONTRATO_CICLO_VALORACIONES.md §3).
 *
 * Cada cuántos días toca la próxima valoración de un socio que no tiene uno
 * propio (`member_valuation_follow_ups.interval_days`). NULL = el gimnasio no
 * hace seguimiento: sin intervalo propio, el socio no tiene «próximo control».
 *
 * NACE EN 60 —también en la fila que ya existe: el DEFAULT de ADD COLUMN la
 * rellena—, que es el corte fijo del detector `evaluation.outdated` de hoy. Así
 * el seguimiento arranca con la regla que el gimnasio ya tenía.
 *
 * ADITIVA Y COMPATIBLE CON EL CÓDIGO VIEJO: el código anterior no la lee ni la
 * escribe (sus ajustes van por `ValuationSetting::FIELDS`), y si repone la fila
 * con `create()`, el DEFAULT la deja en 60.
 *
 * En PostgreSQL, además, un CHECK con los límites de la API (1..365): la base
 * los hace cumplir aunque alguien escriba sin pasar por el código.
 *
 * Reversible: `down()` quita el CHECK y la columna.
 */
return new class extends Migration
{
    private const CHECK = 'valuation_settings_follow_up_interval_chk';

    public function up(): void
    {
        Schema::table('valuation_settings', function (Blueprint $table): void {
            $table->smallInteger('follow_up_interval_days')->nullable()->default(60);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE valuation_settings ADD CONSTRAINT '.self::CHECK
                .' CHECK (follow_up_interval_days IS NULL OR follow_up_interval_days BETWEEN 1 AND 365)');
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE valuation_settings DROP CONSTRAINT IF EXISTS '.self::CHECK);
        }

        Schema::table('valuation_settings', function (Blueprint $table): void {
            $table->dropColumn('follow_up_interval_days');
        });
    }
};
