<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Leads comerciales (Instagram / Facebook / WhatsApp / Ads / orgánico).
 * Fuente de verdad en Laravel. Enlaza opcionalmente a la campaña de origen y,
 * cuando se convierte, al miembro real (members.id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_leads', function (Blueprint $table): void {
            $table->id();
            $table->string('channel');                       // instagram|facebook|whatsapp|ads|organic
            $table->string('source')->nullable();            // detalle de origen
            $table->string('meta_user_id')->nullable();      // PSID / WA id / IGSID
            $table->string('phone')->nullable();
            $table->string('instagram_username')->nullable();
            $table->string('name')->nullable();
            $table->string('status')->default('new');        // new|interested|hot|warm|cold|unqualified|discarded|converted|needs_human
            $table->string('temperature')->default('cold');  // hot|warm|cold
            $table->string('objective')->nullable();         // objetivo fitness declarado
            $table->string('assigned_to')->nullable();       // asesor humano (si aplica)
            $table->unsignedBigInteger('campaign_id')->nullable();
            $table->unsignedBigInteger('member_id')->nullable();
            $table->timestamp('first_message_at')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->timestamps();

            $table->index(['channel', 'status']);
            $table->index('temperature');
            $table->index('campaign_id');
            $table->index('member_id');
            $table->index(['meta_user_id', 'channel']);

            $table->foreign('campaign_id')->references('id')->on('marketing_campaigns')->nullOnDelete();
            $table->foreign('member_id')->references('id')->on('members')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_leads');
    }
};
