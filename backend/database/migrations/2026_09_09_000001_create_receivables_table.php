<?php

use App\Enums\CashShiftType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuentas por cobrar: dinero que alguien debe al gimnasio.
 *
 * UN SOLO MOTOR. «Plan pagado a medias» y «agua que se llevó un empleado» son
 * la misma cosa —una obligación con saldo— y comparten tabla. Dos motores
 * separados acabarían discrepando en el saldo, que es justo lo que no puede
 * pasar con dinero. La interfaz puede presentarlos como secciones distintas.
 *
 * PAGADO Y SALDO NO SE GUARDAN. Se derivan de `receivable_payments`. Dos
 * columnas que pueden contradecirse acaban contradiciéndose: basta un fallo a
 * mitad de una transacción, un job reintentado o una corrección a mano para que
 * el saldo almacenado deje de coincidir con la suma de sus abonos, y a partir
 * de ahí nadie sabe cuál de los dos números es el bueno. Con el saldo derivado
 * esa pregunta no existe.
 *
 * `status` SÍ se guarda, y es lo único redundante que se admite: no es un
 * importe sino una consecuencia, se recalcula en la misma transacción que el
 * abono y sirve para filtrar e indexar sin recorrer los pagos de cada fila.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receivables', function (Blueprint $table): void {
            $table->id();

            // ── Quién debe ──────────────────────────────────────────────────
            // Siempre un socio: tanto quien compra un plan a plazos como el
            // empleado que consume a crédito lo son (`members.is_staff`
            // distingue al segundo). No hace falta una tabla de empleados.
            $table->foreignId('member_id')->constrained('members')->restrictOnDelete();

            // ── Qué caja le corresponde ─────────────────────────────────────
            // El dinero de un abono tiene que entrar en la caja del concepto
            // que lo originó: un crédito de cafetería se cobra en la caja de
            // productos, un plan a plazos en la del gimnasio. Sin esto habría
            // que adivinarlo al abonar, y adivinar dinero es descuadrarlo.
            $table->string('type', 16)->default(CashShiftType::PRODUCTS->value);

            $table->string('concept', 160);

            // ── Cuánto ──────────────────────────────────────────────────────
            // El TOTAL de la obligación. Lo pagado y el saldo se derivan.
            $table->decimal('total_amount', 12, 2);

            // pending | partially_paid | paid | cancelled
            $table->string('status', 20)->default('pending');

            // ── De dónde viene ──────────────────────────────────────────────
            // Polimórfico: una venta de producto a crédito o el cobro de un
            // plan. Nullable porque una deuda puede nacer sin documento previo
            // (un ajuste acordado en mostrador).
            $table->nullableMorphs('source');

            // ── Quién la creó ───────────────────────────────────────────────
            $table->foreignId('created_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('created_by_name')->nullable();

            $table->text('notes')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            // Las dos consultas reales: «qué debe esta persona» y «qué queda
            // por cobrar en esta caja».
            $table->index(['member_id', 'status']);
            $table->index(['type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receivables');
    }
};
