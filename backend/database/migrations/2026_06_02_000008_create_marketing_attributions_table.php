<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Atribución comercial: enlaza un lead con la venta/miembro/campaña reales.
 * El ingreso (`sale_amount`) proviene de pagos reales — NUNCA se inventa. Si no
 * hay atribución exacta, simplemente no se crea la fila (queda organic/unknown).
 *
 * `payment_id` y `membership_id` se guardan como referencias nullable sin FK
 * estricta (defensa: el dominio de membresías vive en users/payments).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_attributions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('lead_id');
            $table->unsignedBigInteger('member_id')->nullable();
            $table->unsignedBigInteger('campaign_id')->nullable();
            $table->decimal('sale_amount', 12, 2)->default(0);
            $table->unsignedBigInteger('membership_id')->nullable();
            $table->unsignedBigInteger('payment_id')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->timestamps();

            $table->index('campaign_id');
            $table->index('member_id');
            $table->foreign('lead_id')->references('id')->on('marketing_leads')->cascadeOnDelete();
            $table->foreign('campaign_id')->references('id')->on('marketing_campaigns')->nullOnDelete();
            $table->foreign('member_id')->references('id')->on('members')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_attributions');
    }
};
