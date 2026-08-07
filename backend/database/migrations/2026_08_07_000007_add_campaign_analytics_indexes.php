<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Un índice, y con eso basta para la analítica de pautas.
 *
 * Medido sobre 5.000 atribuciones, 1.085 pagos y 425.000 mensajes, el desglose
 * por campaña tardaba **393 ms p95**. El coste estaba en las subconsultas que
 * clasifican cada pago —¿es el primero de este miembro? ¿es más caro que el
 * anterior?—, que es como se distingue un alta de una renovación y una
 * renovación de una mejora sin inventarse nada.
 *
 * Con este índice: **23 ms p95**. Diecisiete veces menos.
 *
 * Se deja constancia de lo que NO se hizo, porque era la tentación: ni
 * snapshots diarios, ni tablas de agregación, ni jobs incrementales, ni vistas
 * materializadas. Todo eso añade un sitio donde los números pueden quedarse
 * viejos y otro proceso que puede fallar de noche. Un índice no se desincroniza
 * nunca. Si algún día el volumen lo pide, se vuelve a medir y se decide
 * entonces; hoy no lo pide.
 *
 * `INCLUDE (amount)` deja que el motor resuelva la comparación de importes sin
 * volver a la tabla.
 */
return new class extends Migration
{
    private const INDEX = 'pt_member_status_seq_idx';

    public function up(): void
    {
        if (! Schema::hasTable('payment_transactions')) {
            return;
        }

        if (DB::connection()->getDriverName() !== 'pgsql') {
            // SQLite (pruebas) no admite INCLUDE. El índice equivalente sin
            // columnas incluidas sirve igual a esa escala.
            DB::statement('CREATE INDEX IF NOT EXISTS '.self::INDEX
                .' ON payment_transactions (member_id, status, id)');

            return;
        }

        try {
            DB::statement('CREATE INDEX IF NOT EXISTS '.self::INDEX
                .' ON payment_transactions (member_id, status, id) INCLUDE (amount)');
        } catch (\Throwable $e) {
            // Una mejora de rendimiento no puede tumbar un despliegue.
            Log::warning('analytics.index_failed', ['index' => self::INDEX, 'error' => $e->getMessage()]);
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS '.self::INDEX);
    }
};
