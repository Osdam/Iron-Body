<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Comprobantes electrónicos DIAN (facturas y notas crédito) emitidos vía Factus.
 *
 * Agregado propio, desacoplado del pago: el pago/venta DISPARA la emisión, pero
 * la factura tiene su propio ciclo de vida y reintentos sin bloquear el cobro.
 *
 * Relación polimórfica con la fuente (payment | product_sale). La clave de
 * idempotencia DURA es (source_type, source_id, type): una venta tiene a lo
 * sumo UNA factura y UNA nota crédito; los reintentos jamás duplican.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('electronic_invoices', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // Fuente polimórfica.
            $table->string('source_type');
            $table->unsignedBigInteger('source_id');

            // invoice | credit_note  (ver App\Enums\InvoiceType)
            $table->string('type')->default('invoice');
            // Para notas crédito: la factura que anulan.
            $table->foreignId('references_invoice_id')->nullable()
                ->constrained('electronic_invoices')->nullOnDelete();

            // Estado interno (ver App\Enums\InvoiceStatus).
            $table->string('status')->default('pending')->index();

            // Numeración fiscal (la asigna Factus por su rango/resolución).
            $table->string('numbering_range_id')->nullable();
            $table->string('prefix')->nullable();
            $table->string('number')->nullable();
            $table->string('full_number')->nullable()->index(); // prefijo+consecutivo

            // DIAN / Factus.
            $table->string('factus_id')->nullable()->index();
            $table->string('cufe')->nullable()->index();
            $table->string('dian_status')->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->text('qr_url')->nullable();
            $table->text('qr_data')->nullable();

            // Archivos (disco privado; servidos por endpoint autenticado).
            $table->string('pdf_path')->nullable();
            $table->text('pdf_url')->nullable();
            $table->string('xml_path')->nullable();
            $table->text('xml_url')->nullable();

            // Snapshot inmutable del adquiriente (lo facturado, congelado).
            $table->string('customer_doc_type')->nullable();
            $table->string('customer_doc_number')->nullable();
            $table->string('customer_dv')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_email')->nullable();
            $table->string('customer_phone')->nullable();
            $table->string('customer_address')->nullable();
            $table->string('customer_city_code')->nullable();
            $table->string('customer_department_code')->nullable();
            $table->boolean('is_final_consumer')->default(false);

            // Montos.
            $table->string('currency', 3)->default('COP');
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('discount', 14, 2)->default(0);
            $table->decimal('tax_total', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);

            // Integración (payloads SANEADOS; nunca secretos ni datos sensibles).
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->text('failure_reason')->nullable();
            $table->unsignedInteger('retry_count')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('issued_at')->nullable();

            // Quién la emitió manualmente (nullable: emisión automática).
            $table->unsignedBigInteger('created_by_admin_id')->nullable();

            $table->timestamps();

            // Idempotencia dura.
            $table->unique(['source_type', 'source_id', 'type'], 'electronic_invoices_source_type_unique');
            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('electronic_invoices');
    }
};
