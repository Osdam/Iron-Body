<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Convierte `marketing_messages` en su propio outbox para lo saliente.
 *
 * Hasta ahora un envío fallido se marcaba 'failed' y ahí moría: si Meta
 * devolvía un 429 por límite de tasa —algo perfectamente normal y pasajero— ese
 * mensaje no se volvía a intentar nunca y el prospecto se quedaba sin
 * respuesta. Tampoco había forma de distinguir "falló y conviene insistir" de
 * "falló y no va a mejorar".
 *
 * Se resuelve en la misma tabla en vez de crear una aparte porque el mensaje YA
 * es la unidad de trabajo: tiene el destinatario, el cuerpo y el hilo. Una
 * tabla espejo obligaría a mantener dos verdades sincronizadas.
 *
 * La clave de la seguridad está en `meta_message_id`: si tiene valor, Meta ya
 * aceptó el mensaje y ningún reintento puede volver a enviarlo. Cloud API no
 * ofrece claves de idempotencia, así que esa comprobación es nuestra única
 * defensa real contra escribirle dos veces a la misma persona.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_messages', function (Blueprint $table): void {
            if (! Schema::hasColumn('marketing_messages', 'send_attempts')) {
                $table->unsignedSmallInteger('send_attempts')->default(0)->after('status');
            }
            if (! Schema::hasColumn('marketing_messages', 'next_attempt_at')) {
                // Cuándo puede volver a intentarse. Null = no hay reintento
                // pendiente (ni entregado, ni descartado).
                $table->timestamp('next_attempt_at')->nullable()->after('send_attempts');
            }
            if (! Schema::hasColumn('marketing_messages', 'last_error_code')) {
                $table->unsignedInteger('last_error_code')->nullable()->after('next_attempt_at');
            }
            if (! Schema::hasColumn('marketing_messages', 'last_error_message')) {
                $table->text('last_error_message')->nullable()->after('last_error_code');
            }
            if (! Schema::hasColumn('marketing_messages', 'correlation_id')) {
                // El hilo completo: qué mensaje entrante provocó esta respuesta.
                $table->uuid('correlation_id')->nullable()->after('last_error_message');
            }
        });

        Schema::table('marketing_messages', function (Blueprint $table): void {
            // La cola de reenvío: "lo que toca reintentar ahora". Parcial por
            // status para que no crezca con los millones de mensajes entregados.
            $table->index(['status', 'next_attempt_at'], 'marketing_messages_outbox_idx');
            $table->index('correlation_id');
        });
    }

    public function down(): void
    {
        Schema::table('marketing_messages', function (Blueprint $table): void {
            $table->dropIndex('marketing_messages_outbox_idx');
            $table->dropIndex(['correlation_id']);
            $table->dropColumn([
                'send_attempts', 'next_attempt_at', 'last_error_code',
                'last_error_message', 'correlation_id',
            ]);
        });
    }
};
