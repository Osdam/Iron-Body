<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trazabilidad de la cancelación de solicitudes de factura.
 *
 * La tabla ya tenía un estado `cancelled`, pero no registraba POR QUÉ ni si la
 * solicitud podía reintentarse. Ese vacío importa: siete solicitudes pendientes
 * resultaron ser transacciones de Wompi en SANDBOX (tarjeta de prueba 4242) y
 * cancelarlas sin dejar constancia del motivo las volvería indistinguibles de
 * una cancelación comercial legítima.
 *
 * `retry_allowed` es la parte operativa: impide que un job, un barrido de
 * reintentos o un botón del CRM resucite una solicitud que nunca debió
 * facturarse.
 *
 * Solo añade columnas: ninguna factura existente se modifica.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('electronic_invoices', function (Blueprint $table) {
            // Motivo legible y estable (p. ej. `sandbox_test`), no prosa libre:
            // sirve para filtrar y para auditar.
            $table->string('cancellation_reason', 60)->nullable()->after('failure_reason');

            // Por defecto TRUE para no alterar el comportamiento de las filas ya
            // existentes; solo se pone en false de forma explícita.
            $table->boolean('retry_allowed')->default(true)->after('cancellation_reason');

            $table->timestamp('cancelled_at')->nullable()->after('retry_allowed');
            $table->string('cancelled_by')->nullable()->after('cancelled_at');

            $table->index(['status', 'retry_allowed']);
        });
    }

    public function down(): void
    {
        Schema::table('electronic_invoices', function (Blueprint $table) {
            $table->dropIndex(['status', 'retry_allowed']);
            $table->dropColumn(['cancellation_reason', 'retry_allowed', 'cancelled_at', 'cancelled_by']);
        });
    }
};
