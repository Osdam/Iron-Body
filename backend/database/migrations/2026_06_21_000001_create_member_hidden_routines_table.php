<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ocultamiento de rutinas POR MIEMBRO (solo vista, no borra la rutina global).
 *
 * Un miembro puede quitar de su sección "Semi-personalizadas" un plan base del
 * gimnasio que no le interese. La rutina sigue existiendo para los demás; solo
 * se excluye de la respuesta de ESE miembro.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('member_hidden_routines')) {
            return;
        }

        Schema::create('member_hidden_routines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->foreignId('routine_id')->constrained('routines')->cascadeOnDelete();
            // Snapshot del tipo al ocultar (auditoría; no es autoritativo).
            $table->string('routine_type')->nullable();
            $table->string('reason')->nullable();
            $table->timestamp('hidden_at')->nullable();
            $table->timestamps();

            // Idempotente: un miembro oculta una rutina una sola vez.
            $table->unique(['member_id', 'routine_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_hidden_routines');
    }
};
