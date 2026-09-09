<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Abonos: dinero REAL recibido contra una cuenta por cobrar.
 *
 * Cada fila es dinero que cambió de manos, así que lleva turno de caja y medio
 * de pago igual que una venta. Es la TERCERA fuente de dinero del arqueo, junto
 * a `product_sales` y `payments`: sin eso, un abono cobrado en efectivo dejaría
 * billetes en el cajón que el cierre no sabría explicar.
 *
 * UN ABONO NO ES UNA VENTA. No crea membresía, no descuenta inventario y no
 * genera otra obligación: solo reduce una que ya existía. Esa es la razón de
 * que esta tabla no viva colgada de `payments` ni de `product_sales`.
 *
 * SALDO ANTES Y DESPUÉS. Se guardan aunque el saldo sea derivable, porque son
 * el estado de cuenta: al leer el historial meses después hay que poder decir
 * qué saldo tenía la persona en ese momento sin reconstruirlo sumando hacia
 * atrás y sin depender de que las filas sigan todas ahí.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receivable_payments', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('receivable_id')->constrained('receivables')->cascadeOnDelete();

            $table->decimal('amount', 12, 2);

            // Vocabulario de PaymentMethodKind: solo `cash` suma al efectivo
            // esperado del arqueo. Lo demás no deja billetes en el cajón.
            $table->string('method', 80);

            // Turno donde entró el dinero. Nullable por la misma razón que en
            // `payments`: puede haber asientos sin turno (correcciones,
            // importaciones), y una FK obligatoria los haría imposibles.
            $table->foreignId('cash_shift_id')->nullable()
                ->constrained('cash_shifts')->nullOnDelete();

            // Estado de cuenta congelado en el instante del abono.
            $table->decimal('balance_before', 12, 2);
            $table->decimal('balance_after', 12, 2);

            // ── Idempotencia ────────────────────────────────────────────────
            // El identificador que manda el cliente. Un doble clic reenvía el
            // MISMO valor, y el índice único convierte el segundo intento en un
            // choque que el servicio resuelve devolviendo el abono ya
            // registrado. Deshabilitar el botón en Angular no protege de un
            // reintento de red, de un móvil con conexión intermitente ni de
            // dos pestañas abiertas.
            $table->string('client_request_id', 100)->nullable()->unique();

            // applied | reversed. Un abono NO se borra: el dinero entró y eso
            // no se puede deshacer borrando la fila. Si hubo error se revierte
            // dejando las dos filas visibles.
            $table->string('status', 16)->default('applied');
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('reversal_reason')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('created_by_name')->nullable();

            $table->string('reference', 120)->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            // Derivar el saldo de una cuenta y arquear un turno son las dos
            // consultas calientes.
            $table->index(['receivable_id', 'status']);
            $table->index(['cash_shift_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receivable_payments');
    }
};
