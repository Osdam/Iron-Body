<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Metas nutricionales del miembro (calorías + macros). Fuente de verdad real
 * en PostgreSQL — reemplaza el almacenamiento local en SharedPreferences.
 *
 * Una meta activa por miembro (is_active). Al actualizar metas se desactiva la
 * anterior y se crea/actualiza la activa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nutrition_goals', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('member_id');
            $table->unsignedInteger('daily_calories');
            $table->unsignedInteger('protein_g');
            $table->unsignedInteger('carbs_g');
            $table->unsignedInteger('fat_g');
            $table->string('goal_type')->nullable(); // lose_fat | maintain | gain_muscle
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['member_id', 'is_active']);
            $table->foreign('member_id')->references('id')->on('members')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nutrition_goals');
    }
};
