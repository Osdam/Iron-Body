<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Transacciones de pasarela (ePayco). Tabla separada de `payments` (legado de
 * administración) para no romper nada de lo existente. Cuando una transacción
 * queda `approved` y trae user_id/plan_id, el servicio crea además el registro
 * legado en `payments` para que el dashboard y la extensión de membresía sigan
 * funcionando igual que antes.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->string('idempotency_key')->unique();
            $table->unsignedBigInteger('order_id')->nullable()->index();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('plan_id')->nullable()->index();
            $table->decimal('amount', 12, 2)->default(0);
            $table->string('currency', 3)->default('COP');
            // pending | processing | approved | failed | cancelled | expired
            $table->string('status')->default('pending')->index();
            $table->string('provider')->default('epayco');
            $table->string('provider_ref')->nullable()->index(); // x_ref_payco / x_transaction_id
            $table->text('checkout_url')->nullable();
            $table->string('description')->nullable();
            $table->string('failure_reason')->nullable();
            $table->json('customer')->nullable();
            $table->json('raw_response')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_transactions');
    }
};
