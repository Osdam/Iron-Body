<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payload congelado + conciliación del comprobante (Pricing V2).
 *
 * `payload_snapshot` es el cuerpo EXACTO que se enviará a Factus, construido una
 * sola vez. Los reintentos lo reutilizan literalmente: un cambio posterior de
 * precio o de tarifa ya no puede alterar una factura pendiente.
 *
 * `reconciliation_*` registra el guardarraíl que compara el total del
 * comprobante con el total congelado del origen ANTES de llamar a Factus.
 *
 * NO se tocan subtotal / discount / tax_total / total: los comprobantes
 * históricos (8 validated, 8 pending, 1 cancelled) conservan sus importes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('electronic_invoices', function (Blueprint $table) {
            // Payload EXACTO congelado (ya saneado) + líneas para el CRM.
            $table->json('payload_snapshot')->nullable()->after('response_payload');
            $table->json('line_items_snapshot')->nullable()->after('payload_snapshot');

            // Total bruto congelado del origen (pago o venta) al momento de armar.
            $table->decimal('source_amount_snapshot', 14, 2)->nullable()->after('line_items_snapshot');

            // Guardarraíl de conciliación: pending | ok | failed | skipped.
            $table->string('reconciliation_status', 20)->nullable()->after('source_amount_snapshot');
            $table->decimal('reconciliation_difference', 14, 2)->nullable()->after('reconciliation_status');
            $table->timestamp('reconciled_at')->nullable()->after('reconciliation_difference');

            $table->string('pricing_rules_version', 32)->nullable()->after('reconciled_at');

            $table->index('reconciliation_status', 'electronic_invoices_reconciliation_idx');
        });
    }

    public function down(): void
    {
        Schema::table('electronic_invoices', function (Blueprint $table) {
            $table->dropIndex('electronic_invoices_reconciliation_idx');
            $table->dropColumn([
                'payload_snapshot', 'line_items_snapshot', 'source_amount_snapshot',
                'reconciliation_status', 'reconciliation_difference', 'reconciled_at',
                'pricing_rules_version',
            ]);
        });
    }
};
