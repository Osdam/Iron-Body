<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registro real de rutinas completadas por el miembro desde la app.
 * Base para la notificación/push "Rutina completada".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('routine_completions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('member_id')->index();
            $table->unsignedBigInteger('routine_id')->index();
            $table->timestamp('completed_at');
            $table->string('source')->default('app');
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['member_id', 'completed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('routine_completions');
    }
};
