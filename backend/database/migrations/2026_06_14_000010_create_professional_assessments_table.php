<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Valoraciones PROFESIONALES creadas por entrenadores. Distintas de la
 * autoevaluación del miembro (`physical_evaluations`), que sigue intacta.
 *
 * Ciclo de vida: draft (editable por el autor) → submitted (INMUTABLE) →
 * amended (una versión posterior la reemplaza; la anterior queda como histórico)
 * → voided (anulada con motivo). Nunca se sobrescribe una valoración enviada: la
 * corrección crea una versión nueva enlazada por `parent_id`.
 *
 * El miembro puede verla y marcarla como leída; jamás editarla. Medidas en
 * columnas decimales tipadas. Sin FK de motor (compatibilidad SQLite en tests).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('professional_assessments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->unsignedBigInteger('member_id')->index();
            $table->unsignedBigInteger('trainer_id')->index();
            // Versión anterior que esta enmienda reemplaza (cadena de versiones).
            $table->unsignedBigInteger('parent_id')->nullable()->index();

            $table->string('trainer_type', 40)->nullable();     // floor | functional
            $table->string('location')->nullable();             // sede
            $table->string('status', 20)->default('draft');     // draft|submitted|amended|voided
            $table->unsignedInteger('version')->default(1);

            // Composición corporal y medidas (cm/kg/%).
            $table->decimal('weight_kg', 6, 2)->nullable();
            $table->decimal('height_cm', 6, 2)->nullable();
            $table->decimal('body_fat_pct', 5, 2)->nullable();
            $table->decimal('muscle_mass_pct', 5, 2)->nullable();
            $table->decimal('waist_cm', 6, 2)->nullable();
            $table->decimal('hip_cm', 6, 2)->nullable();
            $table->decimal('chest_cm', 6, 2)->nullable();
            $table->decimal('arm_cm', 6, 2)->nullable();
            $table->decimal('leg_cm', 6, 2)->nullable();

            $table->text('observations')->nullable();
            $table->text('recommendations')->nullable();
            $table->text('amendment_reason')->nullable();
            $table->text('void_reason')->nullable();

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->timestamps();

            $table->index(['member_id', 'status']);
            $table->index(['trainer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('professional_assessments');
    }
};
