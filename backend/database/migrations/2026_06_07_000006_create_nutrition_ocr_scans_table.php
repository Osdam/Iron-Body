<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Escaneos OCR de etiqueta nutricional (draft hasta confirmación del usuario). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nutrition_ocr_scans', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->string('image_path', 1024)->nullable();
            $table->string('status')->default('pending'); // pending|processed|failed
            $table->longText('extracted_text')->nullable();
            $table->json('parsed_payload')->nullable();
            $table->decimal('confidence_score', 4, 3)->nullable();
            $table->string('error_message')->nullable();
            $table->foreignId('created_food_id')->nullable()
                ->constrained('nutrition_foods')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nutrition_ocr_scans');
    }
};
