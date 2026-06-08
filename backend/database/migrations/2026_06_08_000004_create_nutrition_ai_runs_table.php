<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trazabilidad de cada ejecución de IA del módulo Nutrición (auditoría + caché
 * por hash + cost guard). NO guarda imágenes ni prompts gigantes: solo el hash
 * de entrada y el resultado estructurado/validado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nutrition_ai_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->foreignId('food_id')->nullable()->constrained('nutrition_foods')->nullOnDelete();
            $table->string('barcode', 32)->nullable()->index();
            // label_image | ocr_text | estimate | insight | admin_review
            $table->string('mode', 30)->index();
            $table->string('provider', 30)->default('openai');
            $table->string('model', 80)->nullable();
            $table->string('input_hash', 64)->nullable()->index();
            $table->decimal('confidence_score', 4, 3)->nullable();
            // success | failed | timeout | rate_limited | rejected | validation_failed
            $table->string('status', 30)->index();
            $table->string('error_code', 60)->nullable();
            $table->string('prompt_version', 20)->nullable();
            $table->json('response_json')->nullable();
            $table->json('warnings')->nullable();
            $table->timestamps();

            $table->index(['member_id', 'created_at']);
            $table->index(['mode', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nutrition_ai_runs');
    }
};
