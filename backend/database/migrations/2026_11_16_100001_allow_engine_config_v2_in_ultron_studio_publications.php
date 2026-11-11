<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ENMIENDA M-1 DEL HITO 5 (`ULTRON_STUDIO_H5_CONTRACT.md`, «Enmienda M-1»;
 * `plans/STUDIO_MOTORES_PLAN.md` §B.4, paso 3, E13 y R10):
 * `uspub_engine_contract_chk` admite un SEGUNDO contrato de publicación,
 * `ultron-engine-config/2`, además de `ultron-engine-config/1`:
 *
 *  · `ultron-engine-config/1` — el del H5 (`2026_10_25_100002`,
 *    `DOMAIN_CHECKS`): sin cambios y primero. Las entradas de «volver a
 *    Legacy» se siguen escribiendo SIEMPRE en `/1` (R10).
 *  · `ultron-engine-config/2` — NUEVO: la misma entrada que `/1` con
 *    `governed` y `prompts` en cada parte no nula; lo escribe el Studio solo
 *    con la llave de prompts del motor encendida.
 *
 * SOLO POSTGRESQL. En SQLite (la suite) la tabla no tiene CHECK de dominio:
 * SQLite no admite añadir restricciones a una tabla existente (H5,
 * `2026_10_25_100002`) y no hay disparador que mire `engine_contract` (los
 * cuatro de la cadena son `uspub_forbid_update`, `uspub_forbid_delete`,
 * `uspub_chain_next` y `uspub_seq_min`). No hay equivalente que ampliar: el
 * contrato lo vigila el Studio, que es el único que escribe la cadena, y la
 * puerta PG (`ops/pgunit.sh`) es obligatoria. Aquí up() y down() no hacen nada.
 *
 * DROP + ADD EN UNA SOLA SENTENCIA (el patrón de `usv_status_chk` en el H5 y
 * de V9/SC-3, `2026_11_13_100001` y `2026_11_14_100001`): un solo cerrojo
 * ACCESS EXCLUSIVE sobre `ultron_studio_publications` y ningún instante sin
 * CHECK. El ADD valida las filas que ya hay; todas son `/1` por el CHECK
 * anterior, así que no puede fallar por datos (en producción hay 0 filas).
 *
 * EL BLINDAJE DE LA TABLA NO CAMBIA. No toca filas, columnas, índices,
 * disparadores (`uspub_forbid_update`, `uspub_forbid_delete`,
 * `uspub_forbid_truncate`, `uspub_chain_next`) ni privilegios (el REVOKE
 * UPDATE, DELETE del H7). Lo COMPRUEBA: antes y después, el mismo número de
 * CHECK en `ultron_studio_%`, exactamente un `uspub_engine_contract_chk`
 * validado, los mismos disparadores de la tabla, la misma ACL (`relacl`) y
 * el mismo número de filas por contrato. Si algo no cuadra, falla y no se
 * registra. Se niega a ampliar dos veces (un CHECK que ya admite `/2` no es
 * el esquema que espera) o sobre un esquema sin el CHECK del H5.
 *
 * CERROJOS. Si la sesión no trae `lock_timeout` (el de `ironbody-migrate` es
 * de 5 s), la migración pone `SET LOCAL lock_timeout = '5s'`: nunca espera sin
 * límite. ACCESS EXCLUSIVE choca también con una lectura en una transacción
 * abierta (la del motor, `UltronPublishedConfig`, una por commit de ULTRON
 * con la llave del Studio encendida): con ella, cae limpia (55P03), no queda
 * registrada y se reintenta. Mientras espera (como mucho el `lock_timeout`),
 * las lecturas nuevas de la cadena hacen cola detrás.
 *
 * down() SE NIEGA si hay alguna entrada con un contrato distinto de `/1` (el
 * CHECK del H5 no la admitiría, y la cadena es de solo inserción: nunca se
 * borra; R10, punto de no retorno). Toma el cerrojo ANTES de contar, para que
 * nadie inserte un `/2` entre el recuento y el ALTER. Sin ellas, restaura el
 * CHECK EXACTO del H5 (lo lee de su migración: una sola verdad), con el mismo
 * recuento. Nunca borra filas. En producción no se ejecuta nunca
 * (`migrate:rollback` está prohibido): para volver al código de antes basta
 * «volver a Legacy» en el Studio (escribe `/1`) y devolver el código (R10).
 */
return new class extends Migration
{
    public const TABLE = 'ultron_studio_publications';

    public const CHECK = 'uspub_engine_contract_chk';

    public const COLUMN = 'engine_contract';

    public const CONTRACT_V1 = 'ultron-engine-config/1';

    public const CONTRACT_V2 = 'ultron-engine-config/2';

    /** Lo que admite el CHECK ampliado, en este orden. */
    public const CONTRACTS = [self::CONTRACT_V1, self::CONTRACT_V2];

    public const LOCK_TIMEOUT = '5s';

    /** La migración del H5 que define el CHECK que se amplía (y que down() restaura tal cual). */
    public const H5_GUARDS_MIGRATION = '2026_10_25_100002_add_ultron_studio_publication_guards';

    /** El CHECK ampliado, escrito como lo escribe el H5 (`{columna} IN (…)`). */
    public static function expression(): string
    {
        return self::COLUMN.' IN ('.implode(', ', array_map(self::literal(...), self::CONTRACTS)).')';
    }

    /** El CHECK del H5, tal cual lo define su migración (una sola verdad). */
    public static function h5Expression(): string
    {
        $h5 = require database_path('migrations/'.self::H5_GUARDS_MIGRATION.'.php');
        [$column, $values] = $h5::DOMAIN_CHECKS[self::CHECK];

        return $column.' IN ('.implode(', ', array_map(self::literal(...), $values)).')';
    }

    public function up(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite') {
            // Sin CHECK de dominio en SQLite (ver la cabecera): nada que ampliar.
            return;
        }
        if ($driver !== 'pgsql') {
            throw self::unsupported($driver);
        }

        // Laravel ya la envuelve en una transacción; esta (un SAVEPOINT dentro de ella) la hace atómica también fuera del migrador.
        DB::transaction(function (): void {
            $this->boundLockWait();
            $antes = $this->footprint();
            if ($antes !== null) {
                if ($antes['named'] !== 1) {
                    throw new RuntimeException('ultron_studio (M-1): '.self::CHECK.' no existe exactamente una vez en '.self::TABLE
                        ." ({$antes['named']}): el esquema no es el del Hito 5 y la migración no se aplica.");
                }
                if (str_contains($antes['definition'], "'".self::CONTRACT_V2."'")) {
                    throw new RuntimeException('ultron_studio (M-1): '.self::CHECK.' ya admite «'.self::CONTRACT_V2.'»: el esquema no es el que esta migración espera.');
                }
                if (! str_contains($antes['definition'], "'".self::CONTRACT_V1."'")) {
                    throw new RuntimeException('ultron_studio (M-1): '.self::CHECK.' no admite «'.self::CONTRACT_V1.'»: el esquema no es el del Hito 5 y la migración no se aplica.');
                }
            }

            $this->replaceCheck(self::expression(), ifExists: false);

            $despues = $this->footprint();
            if ($antes !== null && $despues !== null) {
                $this->assertSameArmour($antes, $despues, 'ampliar');
                foreach (self::CONTRACTS as $contrato) {
                    if (! str_contains($despues['definition'], "'{$contrato}'")) {
                        throw new RuntimeException('ultron_studio (M-1): tras ampliar, '.self::CHECK." no admite «{$contrato}». La migración no se registra.");
                    }
                }
            }
        });
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite') {
            return;
        }
        if ($driver !== 'pgsql') {
            throw self::unsupported($driver);
        }

        DB::transaction(function (): void {
            $this->boundLockWait();
            // El cerrojo que va a pedir el ALTER, tomado ANTES de contar: así nadie
            // inserta un `/2` entre el recuento y el DROP. Con `--pretend` se escribe
            // igual, para que la vista previa lo enseñe.
            DB::statement('LOCK TABLE '.self::TABLE.' IN ACCESS EXCLUSIVE MODE');
            if (! DB::connection()->pretending()) {
                $otros = DB::table(self::TABLE)->where(self::COLUMN, '<>', self::CONTRACT_V1)
                    ->groupBy(self::COLUMN)->orderBy(self::COLUMN)->selectRaw(self::COLUMN.' AS contrato, count(*) AS filas')->get();
                if ($otros->isNotEmpty()) {
                    $detalle = $otros->map(static fn (object $f): string => "{$f->filas} con «{$f->contrato}»")->implode(', ');
                    throw new RuntimeException('ultron_studio (M-1): down() se niega a restaurar el CHECK del Hito 5 con entradas de la cadena en otro contrato que '
                        .'«'.self::CONTRACT_V1."» ({$detalle}): el CHECK del Hito 5 no las admite y la cadena es de solo inserción (no se borra; R10). "
                        .'Para volver al código de antes: «volver a Legacy» en el Studio (escribe /1) y devolver el código; el esquema se queda.');
                }
            }
            $antes = $this->footprint();

            $this->replaceCheck(self::h5Expression(), ifExists: true);

            $despues = $this->footprint();
            if ($antes !== null && $despues !== null) {
                $this->assertSameArmour($antes, $despues, 'restaurar');
                if (str_contains($despues['definition'], "'".self::CONTRACT_V2."'")) {
                    throw new RuntimeException('ultron_studio (M-1): tras restaurar, '.self::CHECK.' todavía admite «'.self::CONTRACT_V2.'». No se revierte.');
                }
            }
        });
    }

    /** DROP + ADD en una sola sentencia: un solo cerrojo y ningún instante sin CHECK. En up() sin IF EXISTS. */
    private function replaceCheck(string $expression, bool $ifExists): void
    {
        DB::statement('ALTER TABLE '.self::TABLE.' DROP CONSTRAINT '.($ifExists ? 'IF EXISTS ' : '').self::CHECK
            .', ADD CONSTRAINT '.self::CHECK.' CHECK ('.$expression.')');
    }

    /**
     * Lo que no puede cambiar al sustituir el CHECK: el número de CHECK del
     * Studio, uno solo con ese nombre y validado, los disparadores de la
     * cadena, su ACL y sus filas por contrato.
     *
     * @param  array{checks: int, named: int, validated: bool, definition: string, triggers: string, acl: string, rows: string}  $antes
     * @param  array{checks: int, named: int, validated: bool, definition: string, triggers: string, acl: string, rows: string}  $despues
     */
    private function assertSameArmour(array $antes, array $despues, string $que): void
    {
        $iguales = $despues['named'] === 1 && $despues['validated']
            && $despues['checks'] === $antes['checks']
            && $despues['triggers'] === $antes['triggers']
            && $despues['acl'] === $antes['acl']
            && $despues['rows'] === $antes['rows'];
        if (! $iguales) {
            throw new RuntimeException("ultron_studio (M-1): el recuento no cuadra tras {$que} ".self::CHECK
                ." (CHECK en ultron_studio_%: {$antes['checks']} → {$despues['checks']}; con ese nombre: {$despues['named']}, validado: "
                .($despues['validated'] ? 'sí' : 'no').'; disparadores, privilegios y filas por contrato '
                .($despues['triggers'] === $antes['triggers'] && $despues['acl'] === $antes['acl'] && $despues['rows'] === $antes['rows'] ? 'iguales' : 'DISTINTOS')
                .'). La migración no se registra.');
        }
    }

    /**
     * Cuántos CHECK hay en `ultron_studio_%`, cuántos se llaman
     * `uspub_engine_contract_chk` en la cadena, si está validado, cómo lo
     * escribe PostgreSQL, los disparadores de la cadena, su ACL y sus filas
     * por contrato. null con `--pretend`.
     *
     * @return array{checks: int, named: int, validated: bool, definition: string, triggers: string, acl: string, rows: string}|null
     */
    private function footprint(): ?array
    {
        if (DB::connection()->pretending()) {
            return null;
        }
        $checks = (int) DB::scalar("SELECT count(*) FROM pg_constraint k JOIN pg_class c ON c.oid = k.conrelid WHERE k.contype = 'c' AND c.relname LIKE 'ultron\\_studio\\_%'");
        $filas = DB::select("SELECT pg_get_constraintdef(k.oid) AS def, k.convalidated AS validado FROM pg_constraint k WHERE k.contype = 'c' AND k.conrelid = ?::regclass AND k.conname = ?",
            [self::TABLE, self::CHECK]);
        $triggers = (string) DB::scalar("SELECT coalesce(string_agg(tgname || ':' || tgenabled, ',' ORDER BY tgname), '') FROM pg_trigger WHERE NOT tgisinternal AND tgrelid = ?::regclass", [self::TABLE]);
        $acl = (string) DB::scalar('SELECT coalesce(relacl::text, \'\') FROM pg_class WHERE oid = ?::regclass', [self::TABLE]);
        $rows = (string) DB::scalar('SELECT coalesce(string_agg('.self::COLUMN." || '=' || n, ',' ORDER BY ".self::COLUMN.'), \'\') FROM (SELECT '.self::COLUMN.', count(*) AS n FROM '.self::TABLE.' GROUP BY 1) r');

        return [
            'checks' => $checks,
            'named' => count($filas),
            'validated' => (bool) ($filas[0]->validado ?? false),
            'definition' => (string) ($filas[0]->def ?? ''),
            'triggers' => $triggers,
            'acl' => $acl,
            'rows' => $rows,
        ];
    }

    /**
     * Sin un límite en la sesión, esta migración no espera más de
     * LOCK_TIMEOUT por un cerrojo: falla limpia (55P03), no queda registrada y
     * se reintenta. Si la sesión ya trae uno (el de `ironbody-migrate`), manda
     * ese. SET LOCAL: vale hasta el final de la transacción.
     */
    private function boundLockWait(): void
    {
        $sentencia = "SET LOCAL lock_timeout = '".self::LOCK_TIMEOUT."'";
        if (DB::connection()->pretending()) {
            DB::statement($sentencia);

            return;
        }
        if ((string) DB::scalar("SELECT current_setting('lock_timeout')") === '0') {
            DB::statement($sentencia);
        }
    }

    private static function literal(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }

    private static function unsupported(string $driver): RuntimeException
    {
        return new RuntimeException("ultron_studio (M-1): driver de base de datos no soportado: {$driver}. Solo pgsql y sqlite.");
    }
};
