<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Comida del día (desayuno/almuerzo/cena/meriendas) por miembro y fecha.
 *
 * Un registro por (member_id, log_date, meal_type). Los alimentos de la comida
 * cuelgan de nutrition_meal_items. La fecha la calcula el backend (TZ Bogotá).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nutrition_meal_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('member_id');
            $table->date('log_date');
            $table->string('meal_type'); // breakfast | lunch | dinner | snacks
            $table->timestamps();

            $table->unique(['member_id', 'log_date', 'meal_type']);
            $table->index(['member_id', 'log_date']);
            $table->foreign('member_id')->references('id')->on('members')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nutrition_meal_logs');
    }
};
