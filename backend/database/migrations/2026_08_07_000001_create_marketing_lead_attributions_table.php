<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * De dónde vino cada prospecto, en una entidad consultable.
 *
 * Hoy el `referral` de Meta —el bloque que dice qué anuncio tocó alguien antes
 * de escribir— acaba dentro de `marketing_messages.metadata`, enterrado en un
 * JSON por mensaje. Sirve para mirarlo de a uno y no sirve para nada más: no se
 * puede agrupar por campaña, ni saber qué anuncio trae ventas, ni distinguir
 * por dónde llegó alguien la primera vez de por dónde volvió.
 *
 * Dos decisiones que sostienen el diseño:
 *
 *  · **El primer contacto no se sobrescribe nunca.** Es la pregunta que de
 *    verdad importa —«¿qué nos lo trajo?»— y se pierde para siempre si la
 *    segunda visita pisa a la primera. El último contacto sí se actualiza, y
 *    ambos conviven en la misma fila.
 *
 *  · **Se conserva el payload original.** Lo normalizado es una lectura; el
 *    crudo es la prueba. Si mañana cambia el formato de Meta o se descubre un
 *    campo que se estaba ignorando, la evidencia sigue ahí.
 *
 * Sobre las columnas de campaña: el `referral` de WhatsApp Cloud API entrega
 * `source_type`, `source_id`, `source_url`, `headline`, `body`, `media_type` y
 * `ctwa_clid`. NO entrega identificadores separados de campaña, conjunto o
 * creatividad. Las columnas existen porque otras integraciones sí los aportan
 * (y porque el `ctwa_clid` permite cruzarlos más adelante), pero se quedan
 * nulas mientras nadie los provea: no se inventa lo que no llega.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_lead_attributions', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger('marketing_lead_id');
            $table->unsignedBigInteger('marketing_conversation_id')->nullable();
            // Identificador del contacto en el canal (wa_id). Sobrevive aunque
            // el lead se fusione o se recree.
            $table->string('contact_id')->nullable();

            // ── Qué originó el contacto ──────────────────────────────────
            // ad | organic | referral | search | direct | unknown
            $table->string('source_type')->default('unknown');
            // whatsapp | instagram | facebook | web | …
            $table->string('source_platform')->nullable();

            $table->string('campaign_id')->nullable();
            $table->string('campaign_name')->nullable();
            $table->string('adset_id')->nullable();
            $table->string('adset_name')->nullable();
            $table->string('ad_id')->nullable();
            $table->string('ad_name')->nullable();
            $table->string('creative_id')->nullable();
            // ctwa_clid: el identificador de clic de click-to-WhatsApp.
            $table->string('click_id')->nullable();
            $table->text('source_url')->nullable();

            // Qué se le prometió. Texto del ANUNCIANTE, no del cliente.
            $table->string('advertised_product')->nullable();
            $table->unsignedBigInteger('advertised_plan_id')->nullable();
            $table->text('headline')->nullable();
            $table->text('body')->nullable();
            $table->string('media_type')->nullable();

            // ── Primer y último contacto ─────────────────────────────────
            $table->timestamp('first_touch_at')->nullable();
            $table->string('first_touch_source_type')->nullable();
            $table->string('first_touch_ad_id')->nullable();
            $table->timestamp('last_touch_at')->nullable();
            $table->string('last_touch_source_type')->nullable();
            $table->string('last_touch_ad_id')->nullable();

            $table->timestamp('received_at')->nullable();

            // high | medium | low | unknown. Sin evidencia → unknown, siempre.
            $table->string('attribution_confidence')->default('unknown');
            // Por qué se concluyó esto. Una atribución sin evidencia no vale.
            $table->jsonb('evidence')->nullable();
            $table->jsonb('raw_referral_payload')->nullable();

            // Barrera contra el mismo referral llegando dos veces por un
            // reintento del webhook.
            $table->string('dedupe_key')->nullable()->unique();
            $table->uuid('correlation_id')->nullable();

            $table->timestamps();

            // Un lead tiene UNA fila de atribución: dentro viven el primer y el
            // último contacto. Varias filas obligarían a decidir cuál vale.
            $table->unique('marketing_lead_id');

            // Índices para la analítica por pauta: son consultas de agregación
            // sobre columnas concretas, no búsquedas dentro del JSON.
            $table->index('campaign_id');
            $table->index('ad_id');
            $table->index('marketing_conversation_id');
            $table->index('received_at');
            $table->index(['source_type', 'received_at']);
            $table->index('click_id');

            $table->foreign('marketing_lead_id')
                ->references('id')->on('marketing_leads')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_lead_attributions');
    }
};
