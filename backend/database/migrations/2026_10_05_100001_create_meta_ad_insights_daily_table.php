<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El gasto real de Meta Ads, una fila por anuncio y día.
 *
 * Es la foto de lo que devuelve `/act_{id}/insights` con `level=ad` y
 * `time_increment=1`. El panel de mercadeo sumaba `marketing_campaigns.spend`,
 * una tabla que nadie sincronizaba, y por eso enseñaba $0. Esta tabla convive
 * con aquella: no la sustituye ni la toca.
 *
 * `date` es el DÍA DE LA CUENTA PUBLICITARIA, tal como lo parte Meta en su zona
 * horaria. No es un instante y no se convierte: guardarlo como medianoche UTC
 * corre el día cinco horas y lo gastado entre las 19:00 y la medianoche de
 * Bogotá acabaría en el día siguiente.
 *
 * La clave única (cuenta, día, anuncio) es lo que hace que sincronizar dos veces
 * actualice en vez de duplicar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_ad_insights_daily', function (Blueprint $table): void {
            $table->id();
            // Sin el prefijo `act_`.
            $table->string('ad_account_id', 64);
            $table->date('date');
            $table->string('campaign_id', 64)->nullable();
            $table->string('campaign_name')->nullable();
            $table->string('adset_id', 64)->nullable();
            $table->string('adset_name')->nullable();
            $table->string('ad_id', 64);
            $table->string('ad_name')->nullable();
            // En la moneda de la cuenta (`account_currency`), sin convertir.
            $table->decimal('spend', 14, 2)->default(0);
            $table->string('currency', 3)->nullable();
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('reach')->default(0);
            $table->unsignedBigInteger('clicks')->default(0);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['ad_account_id', 'date', 'ad_id'], 'meta_ad_insights_daily_account_date_ad_unique');
            $table->index('date');
            $table->index('campaign_id');
            $table->index('adset_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_ad_insights_daily');
    }
};
