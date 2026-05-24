<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Índices para el endpoint /api/app/payments — el historial filtra por
 * member_id (o user_id como fallback para pagos legados del CRM) y ordena
 * por created_at DESC. Sin estos índices el listado hace full scan.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->index(['member_id', 'created_at'], 'payments_member_created_idx');
            $table->index(['user_id', 'created_at'], 'payments_user_created_idx');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('payments_member_created_idx');
            $table->dropIndex('payments_user_created_idx');
        });
    }
};
