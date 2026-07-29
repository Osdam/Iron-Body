<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Semántica explícita del precio configurado (Pricing V2).
 *
 * `pricing_mode` declara qué significa plans.price / products.sale_price:
 *   - legacy_inclusive : el precio YA contiene el IVA (comportamiento histórico).
 *   - base_plus_tax    : el precio es la BASE y el IVA se suma antes de cobrar.
 *
 * DEFAULT legacy_inclusive de forma deliberada: esta migración NO cambia el
 * comportamiento de ningún registro existente. La migración a base_plus_tax se
 * hace registro por registro desde el CRM, con confirmación explícita, porque
 * aumenta el total cobrado al cliente.
 *
 * `billing_enabled` separa "es un plan de acceso" de "se factura". El plan
 * Demo App Review (precio 0, sin tarifa) puede quedar en false sin perder
 * acceso a módulos y sin bloquear el diagnóstico fiscal. NO se toca por nombre
 * aquí: se configura después por ID con billing:set-plan-billing-status.
 *
 * `price_includes_tax` se conserva intacto (todavía lo leen rutas legacy).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->string('pricing_mode', 32)->default('legacy_inclusive')->after('price_includes_tax');
            $table->boolean('billing_enabled')->default(true)->after('pricing_mode');
            $table->index(['active', 'billing_enabled'], 'plans_active_billing_idx');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->string('pricing_mode', 32)->default('legacy_inclusive')->after('price_includes_tax');
            $table->boolean('billing_enabled')->default(true)->after('pricing_mode');
            $table->index(['active', 'billing_enabled'], 'products_active_billing_idx');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropIndex('plans_active_billing_idx');
            $table->dropColumn(['pricing_mode', 'billing_enabled']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('products_active_billing_idx');
            $table->dropColumn(['pricing_mode', 'billing_enabled']);
        });
    }
};
