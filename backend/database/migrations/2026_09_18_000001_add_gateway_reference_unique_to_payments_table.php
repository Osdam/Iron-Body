<?php

use App\Support\Caja\PaymentOrigin;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La referencia de un cobro de PASARELA, única también en la base.
 *
 * `PaymentMembershipActivator::activate()` se llama a sí mismo «idempotencia
 * dura» porque usa `Payment::firstOrCreate(['reference' => ...])`. No lo era:
 * sin restricción en la base eso es un SELECT seguido de un INSERT, y entre
 * las dos sentencias cabe otro proceso. Los disparadores son varios y
 * concurrentes (webhook de Wompi, reconciliación, aceptación de cobro de
 * ULTRON), así que dos filas con la misma referencia significan doble cobro
 * contable, dos facturas electrónicas y la membresía extendida dos veces.
 * Medido en la suite: 2 pagos, 2 facturas y 60 días en vez de 30.
 *
 * POR QUÉ NO ES UN ÚNICO SOBRE TODA LA COLUMNA
 * --------------------------------------------
 * Auditados los datos de producción en solo lectura antes de escribir esto
 * (PostgreSQL 14.24, 2026-09-18):
 *
 *   payments ................................ 9 685 filas
 *   reference NULL .......................... 9  (todas de mostrador)
 *   reference no nula ....................... 9 676   (cadena vacía: 0)
 *   grupos de reference duplicada ........... 1 → 'REC-1001', 154 filas
 *   duplicados con origin='gateway' ......... 0
 *
 * Ese único grupo NO es el mismo cobro escrito dos veces: son 154 cobros de
 * MOSTRADOR distintos, 132 socios distintos, 9 importes distintos (de 1 a
 * 624 000), métodos card/cash/mixed/other/transfer y fechas repartidas entre
 * 2026-08-12 y 2026-09-19. Es una referencia REUTILIZADA: los 154 son la
 * totalidad de los cobros de mostrador que llevan referencia. Un único sobre
 * toda la columna fallaría al aplicarse y, aunque se saneara, rompería el
 * mostrador: ahí `reference` es un número de comprobante escrito a mano y el
 * dominio no le exige unicidad.
 *
 * Por eso el índice es PARCIAL y va acotado al origen que sí la exige:
 * `origin = 'gateway'`, que es el único camino que crea filas desde
 * `payment_transactions` —cuya `reference` ya es única en la base— y el único
 * en riesgo de acuñar un cobro real por duplicado. Hoy son 10 filas y cero
 * duplicados, así que la migración entra sin saneamiento previo.
 *
 * SIN `CONCURRENTLY`, a propósito: la tabla ocupa 2,8 MB (9 685 filas) y el
 * índice se construye en milisegundos. `CREATE INDEX` toma un lock SHARE que
 * bloquea escrituras mientras dura; a este tamaño es irrelevante, y
 * `CONCURRENTLY` no puede ejecutarse dentro de una transacción, lo que
 * obligaría a marcar la migración `$withinTransaction = false` y a perder la
 * atomicidad de la comprobación previa. Si algún día esta tabla creciera a
 * millones de filas, la conversión es: índice CONCURRENTLY fuera de
 * transacción, y verificar `indisvalid` al terminar.
 *
 * Reversible: `down()` elimina el índice y no toca ni una fila.
 */
return new class extends Migration
{
    private const INDEX = 'payments_gateway_reference_unique';

    /**
     * Mismo SQL en PostgreSQL (producción) y SQLite (suite): los dos soportan
     * índices únicos PARCIALES con la misma sintaxis, así que la restricción se
     * ejercita de verdad en los tests en vez de quedarse solo en producción.
     */
    private function sql(): string
    {
        return 'CREATE UNIQUE INDEX IF NOT EXISTS '.self::INDEX
            .' ON payments (reference)'
            ." WHERE origin = '".PaymentOrigin::GATEWAY->value."' AND reference IS NOT NULL";
    }

    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
            // Ni se salta en silencio ni se inventa equivalente: un motor sin
            // índices parciales necesita una decisión explícita, no que esta
            // migración pase de largo dejando el cobro sin protección.
            throw new RuntimeException(
                'El índice único parcial '.self::INDEX." no está escrito para el driver '{$driver}'."
                .' Añade aquí su equivalente antes de migrar.'
            );
        }

        // Comprobación previa: añadir un único sobre datos que no lo cumplen
        // falla a mitad de despliegue. Preferimos parar ANTES, con el motivo.
        $choques = DB::table('payments')
            ->select('reference')
            ->where('origin', PaymentOrigin::GATEWAY->value)
            ->whereNotNull('reference')
            ->groupBy('reference')
            ->havingRaw('count(*) > 1')
            ->get();

        if ($choques->isNotEmpty()) {
            throw new RuntimeException(
                'Hay '.$choques->count().' referencia(s) de pasarela repetidas en `payments`: '
                .'el índice único las rechazaría. Audítalas y decide qué hacer con ellas antes de migrar '
                ."(SELECT reference, count(*) FROM payments WHERE origin = 'gateway' "
                .'AND reference IS NOT NULL GROUP BY reference HAVING count(*) > 1).'
            );
        }

        DB::statement($this->sql());
    }

    public function down(): void
    {
        if (in_array(Schema::getConnection()->getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX IF EXISTS '.self::INDEX);
        }
    }
};
