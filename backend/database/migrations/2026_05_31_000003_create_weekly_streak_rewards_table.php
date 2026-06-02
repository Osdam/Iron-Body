<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Beneficios/recompensas por racha — editables desde el CRM.
 *
 * Cada fila es un beneficio que se desbloquea al alcanzar `required_days`
 * días activos en la semana. La app calcula el estado (desbloqueado /
 * en progreso / bloqueado) comparando contra los días activos reales.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weekly_streak_rewards', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('config_id')->nullable();
            $table->unsignedSmallInteger('required_days');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('image_url', 1000)->nullable();
            $table->string('badge_label')->nullable();
            $table->string('reward_type')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
            $table->index('required_days');

            $table->foreign('config_id')->references('id')->on('weekly_streak_configs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weekly_streak_rewards');
    }
};
