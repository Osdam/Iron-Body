<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Turnos de caja: quién abrió, con cuánto, qué cobró y cómo cuadró al cerrar.
 *
 * Hasta ahora «Caja» era una pantalla, no una entidad: no existía forma de
 * saber quién estaba al mostrador ni de cuadrar el efectivo al final del día.
 *
 * UNA sola caja física. El gimnasio opera un único mostrador, así que no se
 * modela multi-terminal: sería complejidad sin uso. Lo que sí queda extensible
 * es el punto donde haría falta —añadir una columna `register_id` y ampliar el
 * índice parcial— sin rehacer nada.
 *
 * El índice único parcial es la garantía dura de que no haya dos turnos
 * abiertos a la vez. No se deja al código de la aplicación: dos peticiones
 * simultáneas de apertura ganarían la carrera las dos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_shifts', function (Blueprint $table) {
            $table->id();

            // open | closed  (ver App\Enums\CashShiftStatus)
            $table->string('status', 16)->default('open');

            $table->foreignId('opened_by')->constrained('admins')->restrictOnDelete();
            // Instantánea del nombre: el turno debe seguir diciendo quién lo
            // abrió aunque la cuenta se desactive o se renombre.
            $table->string('opened_by_name');
            $table->timestamp('opened_at');
            $table->decimal('opening_amount', 12, 2)->default(0);
            $table->text('opening_notes')->nullable();

            $table->foreignId('closed_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('closed_by_name')->nullable();
            $table->timestamp('closed_at')->nullable();

            // Congelados al cerrar: recalcularlos después daría otro número si
            // se corrige una venta, y el arqueo dejaría de ser auditable.
            $table->decimal('sales_total', 12, 2)->nullable();      // cobrado en el turno
            $table->decimal('cash_sales_total', 12, 2)->nullable(); // solo efectivo
            $table->decimal('expected_amount', 12, 2)->nullable();  // inicial + efectivo
            $table->decimal('counted_amount', 12, 2)->nullable();   // lo que se contó
            $table->decimal('difference', 12, 2)->nullable();       // contado - esperado
            $table->text('closing_notes')->nullable();

            // Cierre forzado por un supervisor sobre el turno de otra persona.
            $table->boolean('forced')->default(false);
            $table->string('forced_reason')->nullable();

            $table->timestamps();

            $table->index(['status', 'opened_at']);
            $table->index('opened_by');
        });

        // Como máximo UN turno abierto en todo el sistema. Índice parcial:
        // PostgreSQL lo aplica solo a las filas con status='open', así que los
        // turnos cerrados no compiten entre sí.
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            Schema::getConnection()->statement(
                "CREATE UNIQUE INDEX cash_shifts_one_open_idx ON cash_shifts ((status)) WHERE status = 'open'"
            );
        }

        Schema::table('product_sales', function (Blueprint $table) {
            $table->foreignId('cash_shift_id')->nullable()->after('cashier_name')
                ->constrained('cash_shifts')->nullOnDelete();
            $table->index('cash_shift_id');
        });
    }

    public function down(): void
    {
        Schema::table('product_sales', function (Blueprint $table) {
            $table->dropForeign(['cash_shift_id']);
            $table->dropColumn('cash_shift_id');
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            Schema::getConnection()->statement('DROP INDEX IF EXISTS cash_shifts_one_open_idx');
        }

        Schema::dropIfExists('cash_shifts');
    }
};
