<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Diccionario de alias verificados de ejercicios.
 *
 * Las rutinas (sobre todo las viejas guardadas como JSON por nombre) usan
 * nombres libres que NO coinciden con `exercises.name`/`local_name`. Esta tabla
 * permite vincular esos nombres al ejercicio real del catálogo de forma
 * auditable y permanente, sin depender de fuzzy en tiempo de respuesta.
 *
 *  - normalized_alias: nombre del alias normalizado (minúsculas, sin tildes…)
 *  - is_verified:      solo los verificados se aplican automáticamente
 *  - confidence/source/notes: trazabilidad de cómo se creó el alias
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('exercise_aliases')) {
            return;
        }

        Schema::create('exercise_aliases', function (Blueprint $table) {
            $table->id();
            $table->string('alias_name');
            $table->string('normalized_alias')->index();
            $table->foreignId('exercise_id')->constrained('exercises')->cascadeOnDelete();
            $table->string('source')->default('manual'); // manual | seed | audit
            $table->decimal('confidence', 4, 3)->default(1.000);
            $table->boolean('is_verified')->default(false)->index();
            $table->text('notes')->nullable();
            $table->timestamps();

            // Un alias normalizado apunta a un único ejercicio (idempotente).
            $table->unique(['normalized_alias', 'exercise_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exercise_aliases');
    }
};
