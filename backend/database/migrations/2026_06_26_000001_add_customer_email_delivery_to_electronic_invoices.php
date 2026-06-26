<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Envío PROPIO (SMTP) del comprobante al correo del cliente, como fallback al
 * envío nativo de Factus (que en producción devolvió send_email=false y
 * customer.email=null). Esto NO toca la emisión: la factura ya quedó validada;
 * el correo es best-effort y su fallo jamás revierte el comprobante.
 *
 * Campos de trazabilidad del envío propio (independientes de Factus/DIAN):
 *   - customer_email_sent_at      : marca de idempotencia (no reenviar).
 *   - customer_email_status       : queued | sent | failed.
 *   - customer_email_failure_reason: motivo del último fallo (sin datos sensibles).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('electronic_invoices', function (Blueprint $table) {
            $table->timestamp('customer_email_sent_at')->nullable()->after('issued_at');
            $table->string('customer_email_status')->nullable()->after('customer_email_sent_at');
            $table->text('customer_email_failure_reason')->nullable()->after('customer_email_status');
        });
    }

    public function down(): void
    {
        Schema::table('electronic_invoices', function (Blueprint $table) {
            $table->dropColumn([
                'customer_email_sent_at',
                'customer_email_status',
                'customer_email_failure_reason',
            ]);
        });
    }
};
