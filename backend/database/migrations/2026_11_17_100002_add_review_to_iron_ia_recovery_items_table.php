<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Revisión de las propuestas de recuperación por el entrenador
 * (docs/iron-ia/RECUPERACION-REVISION-CONTRATO.md): lo que confirmó al publicar,
 * cuántas veces regeneró y qué propuestas quedaron sustituidas. Solo columnas nuevas y anulables en la tabla de la
 * campaña; ninguna otra tabla cambia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('iron_ia_recovery_items', function (Blueprint $table) {
            $table->json('confirmation')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->unsignedTinyInteger('regenerations')->default(0);
            // Llamadas al modelo pedidas al regenerar, salgan bien o mal: el tope de lo que se paga.
            $table->unsignedSmallInteger('regeneration_attempts')->default(0);
            // Propuestas anteriores, sustituidas al regenerar: ya no se publican.
            $table->json('superseded_routine_ids')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('iron_ia_recovery_items', function (Blueprint $table) {
            $table->dropColumn(['confirmation', 'confirmed_at', 'regenerations', 'regeneration_attempts', 'superseded_routine_ids']);
        });
    }
};
