<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * De dónde salió un plan: qué valoración lo originó y si lo escribió Iron IA.
 *
 * `nutrition_guides.source_assessment_id` ya existía. Le faltaba su gemelo en
 * `routines`, y sin él una valoración y la rutina que produjo quedaban sin
 * relación: se podía saber qué guía nació de qué medición, pero no qué rutina.
 * Con las dos columnas, la unidad queda expresada sin inventar una tabla
 * «plan integral» que no aportaría nada más que un id.
 *
 *   Valoración v1 ── Guía v1
 *                 └─ Rutina v1
 *   Valoración v2 ── Guía v2
 *                 └─ Rutina v2
 *
 * `generated_by_ai` es una marca, no un permiso: no cambia lo que la fila puede
 * hacer, solo permite distinguir después lo que propuso el modelo de lo que
 * escribió una persona. Sin ella, al revisar un plan meses más tarde no habría
 * forma de saber cuál de las dos cosas se está leyendo.
 *
 * Ambas nullable: todo lo que ya existe se queda como está, sin origen
 * declarado, que es exactamente lo que era.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('routines', function (Blueprint $table): void {
            $table->unsignedBigInteger('source_assessment_id')->nullable()->after('member_id');
            $table->boolean('generated_by_ai')->default(false)->after('source_assessment_id');

            // Si se anula la valoración de origen, la rutina NO desaparece: se
            // queda sin origen declarado. Borrarla destruiría el trabajo que el
            // entrenador ya revisó y publicó.
            $table->foreign('source_assessment_id')
                ->references('id')->on('professional_assessments')
                ->nullOnDelete();

            $table->index('source_assessment_id');
        });

        Schema::table('nutrition_guides', function (Blueprint $table): void {
            $table->boolean('generated_by_ai')->default(false)->after('source_assessment_id');
        });
    }

    public function down(): void
    {
        Schema::table('routines', function (Blueprint $table): void {
            $table->dropForeign(['source_assessment_id']);
            $table->dropIndex(['source_assessment_id']);
            $table->dropColumn(['source_assessment_id', 'generated_by_ai']);
        });

        Schema::table('nutrition_guides', function (Blueprint $table): void {
            $table->dropColumn('generated_by_ai');
        });
    }
};
