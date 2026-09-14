<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quién anuló una obligación y por qué.
 *
 * `cancelled_at` ya existía pero no lo escribía nadie: el estado `cancelled`
 * estaba declarado y el predicado de mora ya lo excluía, pero no había forma de
 * llegar a él. Estas dos columnas son lo único que faltaba para que anular sea
 * una operación de verdad y no un estado inalcanzable.
 *
 * POR QUÉ EN LA FILA Y NO SOLO EN LA AUDITORÍA. `AuditLog` guarda el hecho, y
 * lo seguirá guardando; pero el mostrador necesita ver «anulada por Ana —
 * cobrada por error» junto a la deuda, en un listado de veinte. Resolverlo
 * contra la auditoría sería una consulta por fila para pintar una etiqueta.
 * `receivable_payments` ya resuelve exactamente esto igual, con `reversed_by` y
 * `reversal_reason`: esto es el mismo patrón, no uno nuevo.
 *
 * Se limpian al reabrir, porque describen el estado ACTUAL. Lo que pasó queda
 * en la auditoría, que es append-only y no se toca.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('receivables', function (Blueprint $table): void {
            $table->foreignId('cancelled_by')->nullable()->after('cancelled_at')
                ->constrained('admins')->nullOnDelete();
            $table->string('cancellation_reason')->nullable()->after('cancelled_by');
        });
    }

    public function down(): void
    {
        Schema::table('receivables', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn('cancellation_reason');
        });
    }
};
