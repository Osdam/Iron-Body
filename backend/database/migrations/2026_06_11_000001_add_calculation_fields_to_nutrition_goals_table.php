<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enriquece nutrition_goals con la metadata del cálculo personalizado (estilo
 * Fitia): snapshot de los datos físicos usados, BMR/TDEE, fórmula versionada,
 * trazabilidad y estado. ADITIVA y reversible: las metas manuales existentes
 * siguen funcionando (source='manual', status='manual' por defecto).
 *
 * daily_calories/protein_g/carbs_g/fat_g (ya existentes) siguen siendo la meta
 * final canónica que consume la app. Aquí solo se agrega el "por qué".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nutrition_goals', function (Blueprint $table): void {
            // Snapshot de entradas del cálculo (auditable: la meta no cambia si
            // luego cambian peso/edad; se recalcula explícitamente).
            $table->string('objective', 40)->nullable()->after('goal_type');         // muscle_gain|fat_loss|endurance|strength|general_wellness
            $table->string('experience_level', 20)->nullable()->after('objective');  // beginner|intermediate|advanced
            $table->string('gender_identity', 40)->nullable()->after('experience_level');
            $table->string('metabolic_sex', 16)->nullable()->after('gender_identity'); // male|female|unspecified
            $table->unsignedSmallInteger('age_at_calculation')->nullable()->after('metabolic_sex');
            $table->date('birthdate')->nullable()->after('age_at_calculation');
            $table->decimal('weight_kg', 6, 2)->nullable()->after('birthdate');
            $table->decimal('height_cm', 6, 2)->nullable()->after('weight_kg');
            $table->decimal('target_weight_kg', 6, 2)->nullable()->after('height_cm');
            $table->string('activity_level', 20)->nullable()->after('target_weight_kg');
            $table->decimal('activity_factor', 4, 3)->nullable()->after('activity_level');
            $table->unsignedTinyInteger('training_days_per_week')->nullable()->after('activity_factor');
            $table->string('training_type', 40)->nullable()->after('training_days_per_week');
            $table->string('pace', 20)->nullable()->after('training_type'); // conservative|moderate|aggressive

            // Resultado del cálculo determinístico.
            $table->string('formula', 40)->nullable()->after('pace');
            $table->string('formula_version', 16)->nullable()->after('formula');
            $table->unsignedSmallInteger('bmr')->nullable()->after('formula_version');
            $table->unsignedSmallInteger('tdee')->nullable()->after('bmr');
            $table->unsignedSmallInteger('fiber_g')->nullable()->after('fat_g');

            // Trazabilidad / estado.
            $table->string('source', 20)->default('manual')->after('fiber_g');   // calculated|manual|staff|ai_explained
            $table->string('status', 24)->default('manual')->after('source');    // complete|manual|needs_recalculation
            $table->boolean('is_manual_override')->default(false)->after('status');
            $table->json('warnings')->nullable()->after('is_manual_override');
            $table->text('explanation')->nullable()->after('warnings');
            $table->timestamp('calculated_at')->nullable()->after('explanation');
        });
    }

    public function down(): void
    {
        Schema::table('nutrition_goals', function (Blueprint $table): void {
            $table->dropColumn([
                'objective', 'experience_level', 'gender_identity', 'metabolic_sex',
                'age_at_calculation', 'birthdate', 'weight_kg', 'height_cm',
                'target_weight_kg', 'activity_level', 'activity_factor',
                'training_days_per_week', 'training_type', 'pace',
                'formula', 'formula_version', 'bmr', 'tdee', 'fiber_g',
                'source', 'status', 'is_manual_override', 'warnings',
                'explanation', 'calculated_at',
            ]);
        });
    }
};
