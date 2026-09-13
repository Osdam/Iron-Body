<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fecha límite de pago de una obligación.
 *
 * DATE y no TIMESTAMP: lo que se pacta en el mostrador es un día ("págame el
 * 15"), no un instante. Con un timestamp habría que inventarse una hora, y un
 * socio que paga el 15 a las ocho de la noche aparecería vencido desde la
 * medianoche. Con una fecha, el día pactado es suyo entero.
 *
 * NULLABLE y sin relleno retroactivo: las obligaciones que ya existen nacieron
 * sin plazo pactado, y asignarles uno ahora las convertiría en morosas por
 * decisión nuestra. Sin fecha no hay vencimiento, y por tanto no hay bloqueo.
 *
 * No se añade ninguna columna de estado: la mora se DERIVA del saldo y de esta
 * fecha, igual que el saldo se deriva de los abonos. Guardar un booleano de
 * mora sería tener dos verdades que pueden discrepar, y cuando discrepan nadie
 * sabe cuál vale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('receivables', function (Blueprint $table): void {
            $table->date('due_at')->nullable()->after('status');

            // La consulta del detector es exactamente estas tres columnas. Las
            // dos primeras ya tenían índice propio; este lo extiende sin
            // sustituirlo, porque (type,status) sigue sirviendo a los listados
            // de Caja que no filtran por fecha.
            $table->index(['type', 'status', 'due_at'], 'receivables_type_status_due_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('receivables', function (Blueprint $table): void {
            $table->dropIndex('receivables_type_status_due_at_index');
            $table->dropColumn('due_at');
        });
    }
};
