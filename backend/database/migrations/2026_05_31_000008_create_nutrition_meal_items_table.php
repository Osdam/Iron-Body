<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Alimento concreto registrado dentro de una comida (snapshot de macros).
 *
 * Guarda los macros YA calculados para la cantidad registrada (snapshot): así
 * el historial no cambia aunque luego se edite el alimento del catálogo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nutrition_meal_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('meal_log_id');
            $table->unsignedBigInteger('food_item_id')->nullable(); // null = personalizado libre
            $table->string('custom_name')->nullable();
            $table->decimal('quantity', 8, 2)->default(1);
            $table->string('serving_label')->nullable();
            // Snapshot de macros para la cantidad registrada.
            $table->decimal('calories', 8, 2)->default(0);
            $table->decimal('protein_g', 7, 2)->default(0);
            $table->decimal('carbs_g', 7, 2)->default(0);
            $table->decimal('fat_g', 7, 2)->default(0);
            $table->timestamps();

            $table->index('meal_log_id');
            $table->foreign('meal_log_id')->references('id')->on('nutrition_meal_logs')->cascadeOnDelete();
            $table->foreign('food_item_id')->references('id')->on('nutrition_food_items')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nutrition_meal_items');
    }
};
