<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Día nutricional del miembro (resumen diario sincronizado desde la app).
 * Un registro por miembro y fecha (upsert). Base para el push "Día nutricional".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nutrition_day_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('member_id')->index();
            $table->date('log_date');
            $table->float('calories')->default(0);
            $table->float('protein')->default(0);
            $table->float('carbs')->default(0);
            $table->float('fat')->default(0);
            $table->float('goal_calories')->default(0);
            $table->float('goal_protein')->default(0);
            $table->boolean('goal_met')->default(false);
            $table->string('source')->default('app');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['member_id', 'log_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nutrition_day_logs');
    }
};
