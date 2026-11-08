<?php

namespace App\Services\Telephony;

use Illuminate\Support\Facades\DB;

/**
 * Los cerrojos de la telefonía (contrato H8, D8-12: «cerrojo consultivo … en
 * la transacción»). En PostgreSQL, READ COMMITTED primero y después el
 * cerrojo consultivo de transacción, ANTES de leer nada (la lección B-2 de G:
 * en REPEATABLE READ la instantánea la tomaría la propia sentencia del
 * cerrojo). SQLite escribe en serie: no hace falta.
 *
 * Un solo cerrojo para crear llamadas (entrantes y salientes): la
 * concurrencia, los cupos del día y la reserva de coste se leen y se escriben
 * dentro de él. El índice único `vc_one_live_outbound` es el respaldo en la
 * base (una saliente viva por número).
 */
final class VoiceLocks
{
    public const GATE = 'voice:gate';

    public const BRAKE = 'voice:brake';

    /** Llamar como primera sentencia de la transacción. */
    public static function take(string $key): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            DB::statement('SELECT pg_advisory_xact_lock(?)', [crc32($key)]);
        }
    }

    public static function gate(): void
    {
        self::take(self::GATE);
    }
}
