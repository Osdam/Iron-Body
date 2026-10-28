<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Potencias de Iron IA por plan (docs/iron-ia/POTENCIAS-IRON-IA.md §3).
 *
 * Dos niveles independientes, de 1 a 5, que el CRM edita junto al resto de las
 * capacidades del plan. ADITIVA: las filas que ya existen quedan en 1, que es
 * exactamente lo que se genera hoy, así que desplegar esto no cambia ningún
 * plan hasta que alguien suba un nivel.
 *
 * El dominio 1..5 lo valida el controlador; en PostgreSQL, además, un CHECK lo
 * hace cumplir aunque alguien escriba sin pasar por él (un 0 o un 9 no tendría
 * estrategia y caería al nivel 1 sin que nadie lo viera).
 *
 * Reversible: down() quita los CHECK y las dos columnas.
 */
return new class extends Migration
{
    public const CHECKS = [
        'membership_ai_capabilities_nutrition_power_chk' => 'nutrition_power',
        'membership_ai_capabilities_routine_power_chk' => 'routine_power',
    ];

    public function up(): void
    {
        Schema::table('membership_ai_capabilities', function (Blueprint $table): void {
            $table->unsignedSmallInteger('nutrition_power')->default(1);
            $table->unsignedSmallInteger('routine_power')->default(1);
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::CHECKS as $nombre => $columna) {
            DB::statement("ALTER TABLE membership_ai_capabilities ADD CONSTRAINT {$nombre} CHECK ({$columna} BETWEEN 1 AND 5)");
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            foreach (array_keys(self::CHECKS) as $nombre) {
                DB::statement("ALTER TABLE membership_ai_capabilities DROP CONSTRAINT IF EXISTS {$nombre}");
            }
        }

        Schema::table('membership_ai_capabilities', function (Blueprint $table): void {
            $table->dropColumn(['nutrition_power', 'routine_power']);
        });
    }
};
