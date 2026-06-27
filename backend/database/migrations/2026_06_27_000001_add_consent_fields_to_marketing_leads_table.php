<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aditivo y seguro: consentimiento / do-not-contact / trazabilidad de escalado
 * para el agente comercial. NO altera columnas existentes ni borra datos. Todo
 * nullable o con default seguro. (Habeas Data / Ley 1581 + ventana WhatsApp.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_leads', function (Blueprint $table): void {
            // Bandera dura: si true, el agente JAMÁS contacta a este lead.
            $table->boolean('do_not_contact')->default(false)->after('assigned_to');
            // Estado/origen del consentimiento de contacto comercial.
            $table->string('consent_status')->nullable()->after('do_not_contact'); // granted | denied | pending | unknown
            $table->string('consent_source')->nullable()->after('consent_status'); // ads | whatsapp | web | manual...
            $table->timestamp('consent_at')->nullable()->after('consent_source');
            // Trazabilidad del último escalado a humano.
            $table->timestamp('last_human_takeover_at')->nullable()->after('consent_at');
            $table->string('human_takeover_reason')->nullable()->after('last_human_takeover_at');
            // Metadata flexible del lead (no existía). JSON nullable.
            $table->json('metadata')->nullable()->after('human_takeover_reason');

            $table->index('do_not_contact');
        });
    }

    public function down(): void
    {
        Schema::table('marketing_leads', function (Blueprint $table): void {
            $table->dropIndex(['do_not_contact']);
            $table->dropColumn([
                'do_not_contact', 'consent_status', 'consent_source', 'consent_at',
                'last_human_takeover_at', 'human_takeover_reason', 'metadata',
            ]);
        });
    }
};
