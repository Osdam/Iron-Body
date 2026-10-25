<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Factura electrónica MULTICONCEPTO (emisión manual desde el CRM).
 *
 * Una factura agrupa varias operaciones ya cobradas de un mismo cliente: el
 * plan, una venta de cafetería, el piso de entrenamiento personalizado que
 * asumió el cliente. `electronic_invoices` sigue teniendo UN origen; aquí ese
 * origen es el lote (`invoice_bundles`), y el lote dice qué operaciones
 * respalda (`invoice_bundle_items`).
 *
 * La regla «una operación no respalda dos facturas activas» no se confía a una
 * comprobación en PHP: la impone el índice único PARCIAL sobre las filas
 * activas. Dos administradores que reservan la misma operación a la vez no
 * pueden pasar los dos; el segundo choca con el índice.
 *
 * Aditiva: no toca tablas existentes. `down()` las quita.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_bundles', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('member_id')->constrained('members');
            // Lo genera el CRM al abrir la vista previa: un doble clic o un
            // reintento de red con la misma clave devuelve el mismo lote.
            $table->string('idempotency_key', 80)->unique();
            // Decisión expresa del adquiriente (null = política automática).
            $table->boolean('final_consumer')->nullable();
            $table->decimal('total', 14, 2);
            // Huella del contenido que la persona vio y confirmó.
            $table->string('preview_hash', 64);
            $table->unsignedBigInteger('created_by_admin_id')->nullable();
            $table->string('created_by_name', 120)->nullable();
            $table->timestamps();
        });

        Schema::create('invoice_bundle_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_bundle_id')->constrained('invoice_bundles')->cascadeOnDelete();
            // payment | product_sale | commission_charge
            $table->string('origin_type', 30);
            $table->unsignedBigInteger('origin_id');
            $table->string('description', 255);
            $table->timestamp('occurred_at')->nullable();
            $table->decimal('amount', 14, 2);
            // Deja de reservar la operación solo cuando la factura del lote se
            // anula sin haber llegado a la DIAN (ver ManualInvoiceBundleService).
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['origin_type', 'origin_id']);
        });

        // PostgreSQL y SQLite admiten índices parciales con la misma sintaxis.
        DB::statement(
            'CREATE UNIQUE INDEX invoice_bundle_items_active_origin_unique '
            .'ON invoice_bundle_items (origin_type, origin_id) WHERE active'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_bundle_items');
        Schema::dropIfExists('invoice_bundles');
    }
};
