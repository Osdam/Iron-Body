<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recomendaciones de IRON IA sobre nutrición. Guarda el contexto seguro enviado
 * y la respuesta estructurada de OpenAI, para auditoría e historial.
 *
 * NO se guardan tokens ni datos sensibles innecesarios: context_json contiene
 * solo el resumen mínimo que construye IronAiUserContextService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nutrition_ai_recommendations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('member_id');
            $table->date('recommendation_date');
            $table->jsonb('context_json')->nullable();
            $table->jsonb('response_json')->nullable();
            $table->text('summary')->nullable();
            $table->timestamps();

            $table->index(['member_id', 'recommendation_date']);
            $table->foreign('member_id')->references('id')->on('members')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nutrition_ai_recommendations');
    }
};
