<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recuperación de rutinas de Iron IA (docs/iron-ia/RECUPERACION-RUTINAS.md).
 *
 * Una fila por rutina ORIGINAL tratada en una campaña. Es el seguimiento de una
 * operación excepcional, no un estado de la rutina: las rutinas siguen siendo
 * rutinas normales y los flujos de siempre (generar, editar, publicar, retirar)
 * no dependen de esta tabla.
 *
 * Solo se añade una tabla: no se toca ninguna existente. Las referencias a
 * rutinas quedan a null si alguien borra la rutina (no se bloquea nada en otro
 * módulo por una campaña de recuperación).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('iron_ia_recovery_items', function (Blueprint $table) {
            $table->id();
            $table->string('campaign', 64);
            $table->foreignId('original_routine_id')->nullable()->constrained('routines')->nullOnDelete();
            $table->unsignedBigInteger('member_id')->index();
            $table->unsignedBigInteger('trainer_id')->nullable();
            $table->foreignId('proposal_routine_id')->nullable()->unique()->constrained('routines')->nullOnDelete();
            $table->unsignedBigInteger('run_id')->nullable();
            // pending → generating → proposed → published | discarded; failed se reintenta.
            $table->string('status', 20)->index();
            $table->unsignedTinyInteger('original_days')->nullable();
            $table->unsignedTinyInteger('proposed_days');
            $table->unsignedTinyInteger('routine_power')->nullable();
            $table->string('proposal_base_name')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('error_code', 60)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->unsignedInteger('tokens')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('proposed_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->unsignedBigInteger('published_by_trainer_id')->nullable();
            $table->timestamp('original_retired_at')->nullable();
            $table->timestamp('discarded_at')->nullable();
            $table->timestamps();

            // Idempotencia: una rutina original, una vez por campaña.
            $table->unique(['campaign', 'original_routine_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('iron_ia_recovery_items');
    }
};
