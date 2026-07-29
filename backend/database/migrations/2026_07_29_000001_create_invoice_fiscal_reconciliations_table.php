<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bitácora de reconciliación fiscal contra el proveedor (Factus/DIAN).
 *
 * Por qué una tabla nueva y no columnas en `electronic_invoices`:
 *
 *  1. Para un documento ya validado, la AUTORIDAD fiscal es el documento que
 *     está en la DIAN, no las columnas locales. Se descubrió que IBFE1 figura
 *     localmente con IVA 0,00 mientras el documento validado discrimina
 *     12.773,11 (19 %). Guardar el valor del proveedor encima del local
 *     destruiría justamente la evidencia de esa discrepancia.
 *  2. La reconciliación es APPEND-ONLY: cada consulta deja una fila nueva con
 *     lo que decía el local en ese momento, lo que devolvió el proveedor y la
 *     diferencia. Así se puede reconstruir cuándo apareció la divergencia.
 *
 * Esta tabla NO modifica ningún importe contable. Corregir los libros exige
 * aprobación del contador y es una decisión aparte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_fiscal_reconciliations', function (Blueprint $table) {
            $table->id();

            // Sin clave foránea con borrado en cascada: la evidencia debe
            // sobrevivir a cualquier limpieza de la tabla de facturas.
            $table->unsignedBigInteger('electronic_invoice_id')->index();

            $table->string('invoice_number')->nullable()->index();
            $table->string('reconciliation_status', 20)->index(); // reconciled | mismatch | unavailable
            $table->string('unavailable_reason')->nullable();

            // Lo que decía el registro local en el instante de la consulta.
            $table->decimal('local_subtotal', 14, 2)->nullable();
            $table->decimal('local_tax_total', 14, 2)->nullable();
            $table->decimal('local_total', 14, 2)->nullable();
            $table->string('local_status', 30)->nullable();

            // Lo que devolvió el proveedor (autoridad para documentos validados).
            $table->decimal('provider_taxable_amount', 14, 2)->nullable();
            $table->decimal('provider_tax_amount', 14, 2)->nullable();
            $table->decimal('provider_total', 14, 2)->nullable();
            $table->decimal('provider_rate', 6, 2)->nullable();
            $table->string('provider_tribute_code', 10)->nullable();
            $table->boolean('provider_is_excluded')->nullable();
            $table->string('provider_cufe', 200)->nullable();
            $table->boolean('provider_is_validated')->nullable();
            $table->timestamp('provider_validated_at')->nullable();

            // Diferencias calculadas, en centavos, para no depender de floats.
            $table->json('differences')->nullable();

            // Instantánea SANITIZADA de la respuesta y su huella, para peritaje.
            $table->json('provider_snapshot')->nullable();
            $table->string('provider_payload_hash', 64)->nullable();

            // Quién y cuándo.
            $table->string('actor')->nullable();
            $table->timestamp('fetched_at')->nullable();

            $table->timestamps();

            $table->index(['electronic_invoice_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_fiscal_reconciliations');
    }
};
