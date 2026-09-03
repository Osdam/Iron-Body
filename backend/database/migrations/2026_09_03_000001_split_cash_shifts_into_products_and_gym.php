<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dos cajas monetarias independientes sobre la MISMA tabla: productos y gimnasio.
 *
 * `cash_shifts` nació asumiendo una sola caja, y el índice parcial
 * `cash_shifts_one_open_idx` lo grababa en el esquema: como máximo un turno
 * abierto EN TODO EL SISTEMA. Ahora deben poder convivir uno de productos y uno
 * de gimnasio, pero nunca dos del mismo tipo. Eso es exactamente lo que dice el
 * índice nuevo, sustituyendo `(status)` por `(type)`.
 *
 * El intercambio de índices va en ESTA misma migración y no en dos: entre el
 * DROP y el CREATE no existe el invariante, y esa ventana no debe sobrevivir al
 * final de una transacción.
 *
 * Todo lo demás es aditivo. Los turnos existentes quedan como `products`, que es
 * lo que de hecho eran: la caja nació para el mostrador y `product_sales` es la
 * única fuente que tenía enlace.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_shifts', function (Blueprint $table) {
            // Default 'products' = backfill implícito de todo lo existente.
            $table->string('type', 16)->default('products')->after('id');

            // Desglose por medio de pago, congelado al cerrar. Hasta ahora solo
            // se guardaba efectivo y total: no se podía reconstruir un cierre
            // sin volver a consultar las ventas, que para entonces ya pueden
            // haber cambiado.
            $table->decimal('transfer_total', 12, 2)->nullable()->after('cash_sales_total');
            $table->decimal('card_total', 12, 2)->nullable()->after('transfer_total');
            $table->decimal('wompi_total', 12, 2)->nullable()->after('card_total');
            $table->decimal('other_total', 12, 2)->nullable()->after('wompi_total');
            $table->unsignedInteger('operations_count')->nullable()->after('other_total');

            // Resumen legible generado en el cierre. Sustituye a la observación
            // manual obligatoria: el operador ya no tiene que escribir nada.
            $table->text('auto_observation')->nullable()->after('closing_notes');

            // Política con la que se abrió ESTE turno. Se congela por turno y no
            // se lee de la configuración al cerrar: si mañana cambia la política,
            // los cierres viejos deben seguir explicándose solos.
            $table->string('opening_policy', 16)->nullable()->after('opening_amount');

            $table->index(['type', 'status']);
            $table->index(['type', 'opened_at']);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            // Un turno abierto POR TIPO. products y gym pueden estar abiertas a
            // la vez; dos products o dos gym, jamás.
            Schema::getConnection()->statement('DROP INDEX IF EXISTS cash_shifts_one_open_idx');
            Schema::getConnection()->statement(
                "CREATE UNIQUE INDEX cash_shifts_one_open_per_type_idx ON cash_shifts ((type)) WHERE status = 'open'"
            );
        }

        Schema::table('payments', function (Blueprint $table) {
            // NULLable a propósito y SIN backfill histórico: los 8.614 pagos
            // anteriores no pertenecieron a ningún turno, y asignarles uno sería
            // fabricar auditoría. Los pagos de pasarela seguirán siendo NULL
            // siempre, por diseño (ver App\Support\Caja\PaymentOrigin).
            $table->foreignId('cash_shift_id')->nullable()->after('paid_at')
                ->constrained('cash_shifts')->nullOnDelete();
            $table->index('cash_shift_id');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['cash_shift_id']);
            $table->dropColumn('cash_shift_id');
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            Schema::getConnection()->statement('DROP INDEX IF EXISTS cash_shifts_one_open_per_type_idx');
            // Restaurar el invariante anterior solo es posible si no quedan dos
            // turnos abiertos de tipos distintos; si los hay, hay que cerrar uno
            // antes de revertir. Se avisa con un error claro en vez de fallar
            // con un choque de índice ilegible.
            $abiertos = (int) Schema::getConnection()->table('cash_shifts')->where('status', 'open')->count();
            if ($abiertos > 1) {
                throw new RuntimeException(
                    "No se puede revertir: hay {$abiertos} turnos abiertos y el esquema anterior solo admite uno. Cierra los sobrantes primero."
                );
            }
            Schema::getConnection()->statement(
                "CREATE UNIQUE INDEX cash_shifts_one_open_idx ON cash_shifts ((status)) WHERE status = 'open'"
            );
        }

        Schema::table('cash_shifts', function (Blueprint $table) {
            $table->dropIndex(['type', 'status']);
            $table->dropIndex(['type', 'opened_at']);
            $table->dropColumn([
                'type', 'transfer_total', 'card_total', 'wompi_total',
                'other_total', 'operations_count', 'auto_observation', 'opening_policy',
            ]);
        });
    }
};
