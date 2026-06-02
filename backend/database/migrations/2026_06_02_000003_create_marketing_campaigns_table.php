<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campañas Meta Ads. Espejo local de las métricas reales que devuelve la Graph
 * API (/insights); `raw_metrics` guarda el snapshot crudo saneado. No se
 * inventan números: si Meta no está habilitado, la tabla queda vacía.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_campaigns', function (Blueprint $table): void {
            $table->id();
            $table->string('meta_campaign_id')->nullable()->unique();
            $table->string('name');
            $table->string('status')->nullable();    // ACTIVE | PAUSED | ...
            $table->string('objective')->nullable();
            $table->decimal('spend', 12, 2)->default(0);
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('reach')->default(0);
            $table->unsignedBigInteger('clicks')->default(0);
            $table->unsignedInteger('leads')->default(0);
            $table->unsignedInteger('conversations')->default(0);
            $table->decimal('ctr', 8, 4)->nullable();
            $table->decimal('cpc', 12, 4)->nullable();
            $table->decimal('cpm', 12, 4)->nullable();
            $table->date('date_start')->nullable();
            $table->date('date_stop')->nullable();
            $table->jsonb('raw_metrics')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_campaigns');
    }
};
