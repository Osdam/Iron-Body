<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Configuración del módulo "Esta semana" — editable desde el CRM.
 *
 * Textos, metas e imágenes promocionales de la card de Home y de la
 * experiencia premium. La app consume SOLO la config activa (is_active).
 * Las imágenes se guardan como URL (Storage/CDN), nunca binarios en DB.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weekly_streak_configs', function (Blueprint $table): void {
            $table->id();
            $table->string('title')->default('Esta semana');
            $table->string('subtitle')->nullable();
            $table->unsignedSmallInteger('weekly_goal_days')->default(5);
            $table->string('hero_title')->nullable();
            $table->text('hero_description')->nullable();
            $table->string('hero_image_url', 1000)->nullable();
            $table->string('promo_image_url', 1000)->nullable();
            $table->string('cta_label')->nullable();
            $table->string('cta_route')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weekly_streak_configs');
    }
};
