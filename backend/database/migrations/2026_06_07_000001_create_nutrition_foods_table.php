<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo de alimentos del módulo nutricional premium. Fuente propia Iron Body,
 * caché de proveedores externos (Open Food Facts / USDA / Nutritionix), alimentos
 * creados por el usuario y borradores de OCR. Macros normalizados por 100g y por
 * porción. NO toca las tablas nutricionales previas (nutrition_food_items, etc.).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nutrition_foods', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('source')->default('iron_body'); // iron_body|open_food_facts|usda|nutritionix|user|ocr
            $table->string('external_id')->nullable()->index();
            $table->string('barcode')->nullable()->index();
            $table->string('name');
            $table->string('normalized_name')->nullable()->index();
            $table->string('brand')->nullable();
            $table->string('category')->nullable();
            $table->string('image_url', 1024)->nullable();
            $table->decimal('serving_size', 10, 2)->nullable();
            $table->string('serving_unit')->nullable();
            $table->decimal('package_quantity', 10, 2)->nullable();
            $table->string('package_unit')->nullable();

            foreach ([
                'calories', 'protein', 'carbs', 'fat',
                'sugar', 'fiber', 'sodium', 'saturated_fat',
            ] as $m) {
                $table->decimal($m . '_per_100g', 10, 2)->nullable();
            }
            foreach ([
                'calories', 'protein', 'carbs', 'fat',
                'sugar', 'fiber', 'sodium',
            ] as $m) {
                $table->decimal($m . '_per_serving', 10, 2)->nullable();
            }

            $table->boolean('verified')->default(false);
            $table->decimal('confidence_score', 4, 3)->nullable();
            $table->foreignId('created_by_member_id')->nullable()
                ->constrained('members')->nullOnDelete();
            $table->boolean('is_public')->default(false);
            $table->json('raw_payload')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['source', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nutrition_foods');
    }
};
