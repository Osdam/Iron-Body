<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cierre del Objetivo 3: lo que el panel necesita de Meta para decidir dónde
 * invertir. Aditiva: no toca ni borra nada de lo que ya hay.
 *
 * meta_ad_insights_daily gana las métricas ADITIVAS que faltaban (clics en el
 * enlace, conversaciones de mensajería iniciadas y respondidas, vistas de la
 * página de destino) y la lista cruda de `actions` de Meta, para poder
 * recalcular sin volver a pedirla. Todas con valor por defecto: las filas que ya
 * existen siguen valiendo.
 *
 * meta_ad_reach_snapshots guarda el ALCANCE de un rango de días tal como lo
 * calcula Meta. El alcance son personas únicas: el de 30 días no es la suma del
 * de cada día, y sumarlo daría una cifra que no cuadra con el Administrador de
 * anuncios. Por eso se pide a Meta para el rango entero y se guarda con sus
 * fechas exactas; el panel solo lo enseña para ese mismo rango.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meta_ad_insights_daily', function (Blueprint $table): void {
            $table->unsignedBigInteger('inline_link_clicks')->default(0);
            $table->unsignedBigInteger('messaging_conversations_started')->default(0);
            $table->unsignedBigInteger('messaging_conversations_replied')->default(0);
            $table->unsignedBigInteger('landing_page_views')->default(0);
            // [{action_type, value}] tal cual lo da Meta, solo con esos dos campos.
            $table->json('actions')->nullable();
        });

        Schema::create('meta_ad_reach_snapshots', function (Blueprint $table): void {
            $table->id();
            // Sin el prefijo `act_`, como en meta_ad_insights_daily.
            $table->string('ad_account_id', 64);
            // account | campaign
            $table->string('level', 16);
            // El id de la campaña; en el nivel account, el de la cuenta.
            $table->string('entity_id', 64);
            // Días de la cuenta, ambos inclusive, como `date` en meta_ad_insights_daily.
            $table->date('date_from');
            $table->date('date_to');
            $table->unsignedBigInteger('reach')->default(0);
            $table->unsignedBigInteger('impressions')->default(0);
            $table->decimal('frequency', 10, 4)->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['ad_account_id', 'level', 'entity_id', 'date_from', 'date_to'], 'meta_ad_reach_snapshots_unique');
            $table->index(['ad_account_id', 'date_from', 'date_to'], 'meta_ad_reach_snapshots_range_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_ad_reach_snapshots');

        Schema::table('meta_ad_insights_daily', function (Blueprint $table): void {
            $table->dropColumn([
                'inline_link_clicks', 'messaging_conversations_started', 'messaging_conversations_replied',
                'landing_page_views', 'actions',
            ]);
        });
    }
};
