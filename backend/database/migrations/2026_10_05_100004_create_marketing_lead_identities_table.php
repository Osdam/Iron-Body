<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quién es, dentro del CRM, la persona que hay detrás de cada lead.
 *
 * Es una tabla DERIVADA: la calcula LeadIdentityResolver y se puede vaciar y
 * rehacer sin perder nada. Vive aparte, y no como columna de `marketing_leads`,
 * a propósito: `marketing_leads.member_id` lo escribe una persona al aceptar un
 * reclamo de pago, y lo leen el Inbox, ULTRON y la Supervisión. Una coincidencia
 * de teléfono es una pista que sirve para medir, no una prueba, y escribirla ahí
 * la convertiría en un hecho para pantallas que no la esperan.
 *
 *   method      explicit (lead.member_id) | phone_member | phone_user | none | ambiguous
 *   candidates  cuántas personas distintas casaron por teléfono: 0, 1 o más
 *
 * Con más de un candidato no se enlaza a nadie (`ambiguous`): familias que
 * comparten número o líneas recicladas. Atribuir el pago de otra persona es
 * peor que no atribuirlo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_lead_identities', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger('marketing_lead_id');

            // La persona financiera (payments.user_id) y su ficha de socio, por
            // la que van los abonos (receivables.member_id). Cualquiera de las
            // dos puede faltar: hay usuarios sin ficha y fichas sin usuario.
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('member_id')->nullable();

            $table->string('method', 16);
            $table->unsignedInteger('candidates')->default(0);
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            // Una fila por lead: resolver dos veces actualiza, no duplica.
            $table->unique('marketing_lead_id');
            $table->index('user_id');
            $table->index('member_id');

            $table->foreign('marketing_lead_id')
                ->references('id')->on('marketing_leads')->cascadeOnDelete();
            // Si la persona desaparece, el vínculo se vacía y la siguiente
            // resolución lo recalcula; no se arrastra un id que ya no responde.
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('member_id')->references('id')->on('members')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_lead_identities');
    }
};
