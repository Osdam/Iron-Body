<?php

namespace App\Services\Outreach\Run;

use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * LA RESERVA ATÓMICA de presupuesto (contrato H9, D9-8 etapa 5, §3
 * protección 12, L9-2, L9-30; §13.1 #16): la tabla de contadores
 * `outreach_budget_counters`, por día de Bogotá × alcance × modo, con la
 * comprobación EN EL MISMO UPDATE (`… WHERE reserved + n <= cap`): 0 filas =
 * sin presupuesto. Nunca se lee y después se escribe; el CHECK
 * `reserved <= cap` de la base está debajo por si alguien lo olvidara.
 *
 * Alcances (una parcela por cada uno, todas o ninguna, dentro de la
 * transacción de quien planifica):
 *  - `channel:<canal>`: el cupo diario del canal (y, en WhatsApp, su
 *    presupuesto en micros de dólar);
 *  - `strategy:<uuid>`: el tope de contactos al día de la estrategia
 *    (`limits.contactsPerDay`);
 *  - `phone:<phone_ref>`: UN contacto al día por teléfono (L9-30), con el
 *    seudónimo del número.
 *
 * El techo vigente es el MENOR entre el que guarda la fila (nació con la
 * configuración de ese momento) y el que la configuración dice ahora: subir
 * el techo lo amplía (se eleva la fila); bajarlo se aplica al instante sin
 * violar el CHECK (lo ya reservado se queda). EN SOMBRA O POR EL CANAL
 * SIMULADO NO SE RESERVA NADA (la base lo rechaza: `ojob_budget_chk`).
 */
final class BudgetReservations
{
    public const SCOPE_CHANNEL = 'channel:';

    public const SCOPE_STRATEGY = 'strategy:';

    public const SCOPE_PHONE = 'phone:';

    /** Una parcela: lo que se reserva en un alcance y su techo de ahora. @return array{scope: string, units: int, micros: int, capUnits: int, capMicros: int} */
    public static function parcel(string $scope, int $units, int $micros, int $capUnits, int $capMicros): array
    {
        if (preg_match('/^(channel:(assisted|whatsapp_template|voice_call)|strategy:[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}|phone:[0-9a-f]{64})$/D', $scope) !== 1) {
            throw new InvalidArgumentException('Alcance de la reserva fuera de su forma.');
        }
        if ($units < 0 || $micros < 0 || $capUnits < 0 || $capMicros < 0) {
            throw new InvalidArgumentException('Una reserva no es negativa.');
        }

        return ['scope' => $scope, 'units' => $units, 'micros' => $micros, 'capUnits' => $capUnits, 'capMicros' => $capMicros];
    }

    /**
     * Reserva TODAS las parcelas o ninguna. Llamar dentro de la transacción
     * del trabajo: si devuelve el alcance agotado, quien llama deshace la
     * transacción (las parcelas ya tomadas vuelven solas).
     *
     * @param  list<array{scope: string, units: int, micros: int, capUnits: int, capMicros: int}>  $parcels
     * @return string|null el primer alcance sin presupuesto, o null si todo quedó reservado
     */
    public function reserve(string $budgetDay, string $mode, array $parcels, DateTimeInterface $now): ?string
    {
        foreach ($parcels as $p) {
            if ($p['units'] === 0 && $p['micros'] === 0) {
                continue;
            }
            $this->ensureCounter($budgetDay, $mode, $p, $now);
            $filas = DB::update(self::reserveSql(), self::reserveBindings($budgetDay, $mode, $p, $now));
            if ($filas !== 1) {
                return $p['scope'];
            }
        }

        return null;
    }

    /**
     * LA SENTENCIA de la reserva (D9-8, etapa 5; L9-2): la comprobación va en
     * el mismo UPDATE, contra el techo de la fila Y el de la configuración de
     * ahora. Pública para que la prueba de la carrera (#16) dispare
     * exactamente esta sentencia desde varias conexiones.
     */
    public static function reserveSql(): string
    {
        return 'UPDATE '.OutreachTables::BUDGETS.' SET reserved_units = reserved_units + ?, reserved_micros = reserved_micros + ?, updated_at = ? '
            .'WHERE budget_day = ? AND scope = ? AND mode = ? '
            .'AND reserved_units + ? <= cap_units AND reserved_micros + ? <= cap_micros '
            .'AND reserved_units + ? <= ? AND reserved_micros + ? <= ?';
    }

    /**
     * @param  array{scope: string, units: int, micros: int, capUnits: int, capMicros: int}  $p
     * @return list<int|string>
     */
    public static function reserveBindings(string $budgetDay, string $mode, array $p, DateTimeInterface $now): array
    {
        return [$p['units'], $p['micros'], $now->format('Y-m-d H:i:s'), $budgetDay, $p['scope'], $mode,
            $p['units'], $p['micros'], $p['units'], $p['capUnits'], $p['micros'], $p['capMicros']];
    }

    /**
     * Libera lo que un trabajo había reservado (cancelar, vencer o fallar
     * antes de salir: D9-8). Nunca por debajo de cero.
     *
     * @param  list<array{scope: string, units: int, micros: int}>  $parcels
     */
    public function release(string $budgetDay, string $mode, array $parcels, DateTimeInterface $now): void
    {
        foreach ($parcels as $p) {
            if (($p['units'] ?? 0) === 0 && ($p['micros'] ?? 0) === 0) {
                continue;
            }
            DB::update(
                'UPDATE '.OutreachTables::BUDGETS.' SET reserved_units = CASE WHEN reserved_units >= ? THEN reserved_units - ? ELSE 0 END, '
                .'reserved_micros = CASE WHEN reserved_micros >= ? THEN reserved_micros - ? ELSE 0 END, updated_at = ? '
                .'WHERE budget_day = ? AND scope = ? AND mode = ?',
                [$p['units'], $p['units'], $p['micros'], $p['micros'], $now->format('Y-m-d H:i:s'), $budgetDay, $p['scope'], $mode],
            );
        }
    }

    /** Lo reservado hoy en un alcance (lo que N4 compara con el techo). @return array{units: int, micros: int} */
    public function used(string $budgetDay, string $mode, string $scope): array
    {
        $f = DB::table(OutreachTables::BUDGETS)->where('budget_day', $budgetDay)->where('scope', $scope)->where('mode', $mode)->first(['reserved_units', 'reserved_micros']);

        return ['units' => $f === null ? 0 : (int) $f->reserved_units, 'micros' => $f === null ? 0 : (int) $f->reserved_micros];
    }

    /**
     * Las parcelas de un trabajo (lo que reservó al planificarse), para
     * liberarlas: el canal, su estrategia y su teléfono.
     *
     * @return list<array{scope: string, units: int, micros: int}>
     */
    public static function parcelsOfJob(object $job, string $strategyUuid): array
    {
        $unidades = (int) $job->reserved_units;
        $micros = (int) $job->reserved_micros;
        if ($unidades === 0 && $micros === 0) {
            return [];
        }
        $parcelas = [
            ['scope' => self::SCOPE_CHANNEL.$job->channel, 'units' => $unidades, 'micros' => $micros],
            ['scope' => self::SCOPE_STRATEGY.strtolower($strategyUuid), 'units' => $unidades, 'micros' => 0],
        ];
        if ($job->phone_ref !== null) {
            $parcelas[] = ['scope' => self::SCOPE_PHONE.$job->phone_ref, 'units' => $unidades, 'micros' => 0];
        }

        return $parcelas;
    }

    /** La fila del contador, con el techo de AHORA; si ya existe con un techo menor, se eleva (nunca se baja: el CHECK lo impediría). */
    private function ensureCounter(string $budgetDay, string $mode, array $p, DateTimeInterface $now): void
    {
        DB::table(OutreachTables::BUDGETS)->insertOrIgnore([
            'budget_day' => $budgetDay,
            'scope' => $p['scope'],
            'mode' => $mode,
            'cap_units' => $p['capUnits'],
            'cap_micros' => $p['capMicros'],
            'reserved_units' => 0,
            'reserved_micros' => 0,
            'created_at' => $now->format('Y-m-d H:i:s'),
            'updated_at' => $now->format('Y-m-d H:i:s'),
        ]);
        DB::update(
            'UPDATE '.OutreachTables::BUDGETS.' SET cap_units = CASE WHEN cap_units < ? THEN ? ELSE cap_units END, '
            .'cap_micros = CASE WHEN cap_micros < ? THEN ? ELSE cap_micros END, updated_at = ? '
            .'WHERE budget_day = ? AND scope = ? AND mode = ? AND (cap_units < ? OR cap_micros < ?)',
            [$p['capUnits'], $p['capUnits'], $p['capMicros'], $p['capMicros'], $now->format('Y-m-d H:i:s'), $budgetDay, $p['scope'], $mode, $p['capUnits'], $p['capMicros']],
        );
    }
}
