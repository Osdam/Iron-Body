<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Refacturación tras nota crédito: qué factura (ya anulada ante la DIAN por
 * una nota crédito validada) vuelve a facturar cada operación del lote nuevo.
 * Aditiva y nula: los lotes existentes no reemplazan nada.
 *
 * Sin clave foránea a propósito: en SQLite añadirla obliga a reconstruir la
 * tabla, y la reconstrucción pierde el WHERE del índice único parcial
 * (invoice_bundle_items_active_origin_unique), que es la protección contra la
 * doble facturación. Una columna nula indexada es un ADD COLUMN simple en
 * PostgreSQL y en SQLite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_bundle_items', function (Blueprint $table): void {
            $table->unsignedBigInteger('replaces_invoice_id')->nullable()->after('active')->index();
        });
    }

    public function down(): void
    {
        Schema::table('invoice_bundle_items', function (Blueprint $table): void {
            $table->dropIndex(['replaces_invoice_id']);
            $table->dropColumn('replaces_invoice_id');
        });
    }
};
