<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registra QUIÉN hace cada operación administrativa.
 *
 * Las columnas de autor que ya existían apuntaban a `users`, que es la tabla de
 * los miembros de la aplicación, no la del personal del CRM (`admins`). Y el
 * código las rellenaba con `$request->user()`, que en las rutas /api/admin/*
 * siempre vale null porque el administrador vive en un atributo del request.
 *
 * Resultado comprobado en producción: las 17 ventas sin cajero y los 2
 * movimientos de inventario sin autor. La traza decía qué pasó y con qué
 * existencias, pero no quién lo hizo.
 *
 * Se añaden columnas nuevas en vez de reutilizar las viejas: `cashier_user_id`
 * conserva su semántica declarada (un usuario de la app) por si algún día se
 * usa, y no se reescribe el significado de una columna existente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_sales', function (Blueprint $table) {
            $table->foreignId('cashier_admin_id')->nullable()->after('cashier_user_id')
                ->constrained('admins')->nullOnDelete();
            // Instantánea: el comprobante debe seguir diciendo quién cobró
            // aunque la cuenta se elimine después.
            $table->string('cashier_name')->nullable()->after('cashier_admin_id');
        });

        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->foreignId('admin_id')->nullable()->after('user_id')
                ->constrained('admins')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('product_sales', function (Blueprint $table) {
            $table->dropForeign(['cashier_admin_id']);
            $table->dropColumn(['cashier_admin_id', 'cashier_name']);
        });

        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropForeign(['admin_id']);
            $table->dropColumn('admin_id');
        });
    }
};
