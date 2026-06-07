<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Entradas de tracking: un alimento agregado a una comida en una fecha. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nutrition_entries', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->foreignId('food_id')->constrained('nutrition_foods')->cascadeOnDelete();
            $table->string('meal_type'); // breakfast|lunch|dinner|snack
            $table->date('entry_date');
            $table->decimal('quantity', 10, 2);
            $table->string('unit');
            $table->decimal('serving_multiplier', 10, 3)->nullable();

            // Macros FINALES calculados por el backend (nunca por Flutter).
            $table->decimal('calories', 10, 2)->default(0);
            $table->decimal('protein', 10, 2)->default(0);
            $table->decimal('carbs', 10, 2)->default(0);
            $table->decimal('fat', 10, 2)->default(0);
            $table->decimal('sugar', 10, 2)->nullable();
            $table->decimal('fiber', 10, 2)->nullable();
            $table->decimal('sodium', 10, 2)->nullable();
            $table->decimal('saturated_fat', 10, 2)->nullable();

            $table->string('notes')->nullable();
            $table->timestamps();

            $table->index(['member_id', 'entry_date']);
            $table->index(['member_id', 'entry_date', 'meal_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nutrition_entries');
    }
};
