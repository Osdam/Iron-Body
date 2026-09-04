<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guías nutricionales que un ENTRENADOR publica para un socio.
 *
 * Gemela de `professional_assessments` a propósito: mismo ciclo de vida —draft
 * (editable por su autor) → published (INMUTABLE) → amended (una versión
 * posterior la reemplaza; la anterior queda como histórico) → voided (anulada
 * con motivo)—, misma cadena de versiones por `parent_id` y mismo uuid como
 * clave de ruta. Inventar un segundo patrón para lo mismo garantizaría que uno
 * de los dos acabara con arreglos que al otro le faltan.
 *
 * Por qué las medidas se COPIAN aquí en vez de referenciarse: una guía
 * publicada es un documento con fecha. Si mañana el entrenador corrige la
 * valoración de la que salió, la guía de hace tres meses debe seguir diciendo
 * con qué números se escribió. `source_assessment_id` conserva la procedencia,
 * pero los valores que se leen son los de estas columnas.
 *
 * El plan de comidas va en JSON y no en una tabla hija porque no se consulta ni
 * se agrega por comida: se lee entero, siempre, como parte del documento. Una
 * tabla aparte solo añadiría una unión para reconstruir algo que nunca se pide
 * en trozos. Sin FK de motor, como el resto del dominio (compatibilidad SQLite
 * en tests).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nutrition_guides', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->unsignedBigInteger('member_id')->index();
            $table->unsignedBigInteger('trainer_id')->index();
            // Versión anterior que esta corrección reemplaza (cadena de versiones).
            $table->unsignedBigInteger('parent_id')->nullable()->index();
            // De qué valoración salieron las medidas. Solo procedencia: los
            // valores vigentes son los congelados abajo.
            $table->unsignedBigInteger('source_assessment_id')->nullable()->index();

            $table->string('trainer_type', 40)->nullable();
            $table->string('status', 20)->default('draft'); // draft|published|amended|voided
            $table->unsignedInteger('version')->default(1);

            // Qué persigue la guía.
            $table->string('objective')->nullable();
            $table->text('objective_description')->nullable();
            $table->string('training_stage')->nullable();

            // SNAPSHOT antropométrico al publicar. Congelado a propósito.
            $table->decimal('weight_kg', 6, 2)->nullable();
            $table->decimal('height_cm', 6, 2)->nullable();
            $table->decimal('body_fat_pct', 5, 2)->nullable();
            $table->decimal('muscle_mass_pct', 5, 2)->nullable();
            $table->decimal('visceral_fat', 5, 2)->nullable();
            $table->unsignedInteger('basal_kcal')->nullable();
            $table->unsignedTinyInteger('age_years')->nullable();

            // Plan de alimentación: lista ordenada de comidas configurables.
            // [{label, time, description, order}] — ni el número ni los nombres
            // están fijados: no todos los socios hacen desayuno-almuerzo-cena.
            $table->json('meals')->nullable();

            $table->text('recommendations')->nullable();
            $table->text('restrictions')->nullable();
            $table->text('supplements')->nullable();
            $table->text('notes')->nullable();

            $table->text('amendment_reason')->nullable();
            $table->text('void_reason')->nullable();

            $table->timestamp('published_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->timestamps();

            $table->index(['member_id', 'status']);
            $table->index(['trainer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nutrition_guides');
    }
};
