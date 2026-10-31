<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Coste y nivel de cada generación (docs/iron-ia/POTENCIAS-IRON-IA.md §4).
 *
 * Hasta ahora `nutrition_ai_runs` decía si una generación salió bien, pero no
 * cuánto costó ni cuánto tardó: el `usage` que devuelve OpenAI se descartaba y
 * la latencia solo iba al log. Con niveles que hacen varias llamadas, sin esto
 * no hay forma de saber qué cuesta cada plan.
 *
 * Una fila sigue siendo UNA generación (el guardián de coste cuenta filas): las
 * secciones de una generación por niveles se suman aquí y su detalle va en
 * `usage_json`.
 *
 * ADITIVA y nulable: las filas de los otros modos de nutrición y las anteriores
 * a esto quedan en null, que significa «no se midió». Reversible.
 */
return new class extends Migration
{
    private const COLUMNS = [
        'nutrition_power', 'routine_power',
        'prompt_tokens', 'completion_tokens', 'total_tokens',
        'latency_ms', 'usage_json',
    ];

    public function up(): void
    {
        Schema::table('nutrition_ai_runs', function (Blueprint $table): void {
            $table->unsignedSmallInteger('nutrition_power')->nullable();
            $table->unsignedSmallInteger('routine_power')->nullable();
            $table->unsignedInteger('prompt_tokens')->nullable();
            $table->unsignedInteger('completion_tokens')->nullable();
            $table->unsignedInteger('total_tokens')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            // Detalle por sección: tokens, finish_reason, latencia e intentos.
            $table->json('usage_json')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('nutrition_ai_runs', function (Blueprint $table): void {
            $table->dropColumn(self::COLUMNS);
        });
    }
};
