<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La dimensión de anuncios de Meta: campañas, conjuntos y anuncios.
 *
 * El referral de Click-to-WhatsApp solo trae el id del anuncio. La relación
 * anuncio → conjunto → campaña, y sus nombres, los da Meta; aquí se guardan tal
 * como llegan en la sincronización para resolver un `ad_id` sin inventar nada.
 * Nunca se deduce una campaña por coincidencia de fechas.
 *
 * `campaign_id` y `adset_id` describen la jerarquía de la fila, incluida ella
 * misma: una campaña lleva su propio id en `campaign_id`; un conjunto, el de su
 * campaña y el suyo en `adset_id`; un anuncio, los de sus dos padres.
 *
 * Cada fila lleva la cuenta publicitaria de la que vino, y la cuenta forma parte
 * de la clave. Si cambia META_AD_ACCOUNT_ID, lo sincronizado de la cuenta
 * anterior se conserva, pero ya no resuelve leads ni sale en el panel con un
 * gasto que la cuenta nueva no conoce: se lee con
 * `MetaAdEntity::forConfiguredAccount()`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_ad_entities', function (Blueprint $table): void {
            $table->id();
            // Sin el prefijo `act_`, como en meta_ad_insights_daily.
            $table->string('ad_account_id', 64);
            // campaign | adset | ad
            $table->string('level', 16);
            $table->string('entity_id', 64);
            $table->string('name')->nullable();
            $table->string('campaign_id', 64)->nullable();
            $table->string('adset_id', 64)->nullable();
            // ACTIVE, PAUSED, ARCHIVED… tal cual lo da Meta. Nulo si no se pidió.
            $table->string('effective_status', 32)->nullable();
            $table->timestamp('synced_at')->nullable();

            $table->unique(['ad_account_id', 'level', 'entity_id'], 'meta_ad_entities_account_level_entity_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_ad_entities');
    }
};
