<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Corte operativo del historial de cierres.
 *
 * Al entregar la caja a un cliente, el historial arrastra los cierres de la
 * puesta en marcha: pruebas del flujo de apertura, y también días reales en los
 * que ya se cobró. Empezar con esa lista delante no ayuda a nadie.
 *
 * ARCHIVAR NO ES BORRAR, y esa es toda la idea. La fila se queda, sus totales
 * se quedan, y sobre todo se queda `payments.cash_shift_id`: el turno 11 de la
 * puesta en marcha cuadra 16 pagos por 1.739.000 pesos de socios reales, y esa
 * relación es la que permite explicar meses después de dónde salió ese dinero.
 * Borrar el turno no habría dado ningún error —la clave foránea es ON DELETE
 * SET NULL— y habría dejado los pagos huérfanos en silencio.
 *
 * `archived_at` decide UNA cosa: si el turno pertenece al historial operativo
 * que se enseña en Caja. No decide nada contable. Ningún servicio de dinero
 * —totales de turno, arqueos, ganancias, conciliación— puede mirarla: un turno
 * archivado sigue siendo contabilidad válida.
 *
 * Nullable y sin defecto a propósito: los turnos nuevos nacen sin archivar y
 * aparecen en el historial. Archivar es un acto administrativo excepcional, no
 * algo que ocurra solo con el tiempo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_shifts', function (Blueprint $table): void {
            $table->timestamp('archived_at')->nullable()->after('closed_at');
            // El historial siempre pregunta por los NO archivados; sin índice,
            // esa condición obliga a recorrer la tabla entera cada vez.
            $table->index(['archived_at', 'type'], 'cash_shifts_archived_type_index');
        });
    }

    public function down(): void
    {
        Schema::table('cash_shifts', function (Blueprint $table): void {
            $table->dropIndex('cash_shifts_archived_type_index');
            $table->dropColumn('archived_at');
        });
    }
};
