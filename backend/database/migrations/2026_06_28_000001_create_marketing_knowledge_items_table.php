<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Base de conocimiento comercial oficial de Iron Body (Fase 3.5). Alimenta el
 * prompt de OpenAI con información REAL y editable (identidad, ubicación,
 * horarios, políticas, objeciones, tono, restricciones, faq). Aditivo y aislado:
 * no toca pagos, facturación ni Wompi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_knowledge_items', function (Blueprint $table): void {
            $table->id();
            $table->string('category')->index();   // business_identity | location | ...
            $table->string('key')->unique();        // identificador estable para upsert
            $table->string('title')->nullable();
            $table->text('content');
            $table->integer('priority')->default(100); // menor = más prioritario
            $table->boolean('is_active')->default(true);
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_until')->nullable();
            $table->string('source')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['category', 'is_active', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_knowledge_items');
    }
};
