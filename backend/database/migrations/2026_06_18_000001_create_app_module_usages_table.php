<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracking de uso de módulos de la app (para module.discovery — Fase 3).
 *
 * Tabla PREPARADA: la pobla la instrumentación de Flutter (aún no implementada).
 * Mientras esté vacía, el detector module.discovery se salta (no inventa uso).
 * No tiene impacto en producción hasta que se empiece a escribir en ella.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('app_module_usages')) {
            return;
        }

        Schema::create('app_module_usages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('member_id');
            $table->string('module_key', 80);   // iron_ai_chat, nutrition_log, evaluations, …
            $table->string('action', 80)->nullable(); // open | use | complete …
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index(['member_id', 'module_key']);
            $table->index(['member_id', 'created_at']);
            $table->foreign('member_id')->references('id')->on('members')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_module_usages');
    }
};
