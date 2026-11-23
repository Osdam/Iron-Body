<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * E19-3 DEL H8 (enmienda de DF-S3 del 22-11-2026; contrato H8, fila E19-3;
 * informe `plans/informes-cierre/informe-be-voz2.md` §3 y su sección de
 * database-specialist): una saliente `ambiguous` que NO llegó a iniciarse
 * tiene salida, `ambiguous → failed_to_start` («no llegó a iniciarse», D8-13:
 * la reserva se libera y no cuenta para la frecuencia).
 *
 * Rehace SOLO el disparador `vc_status_forward` de DB8
 * (`2026_11_10_100002`), en los dos motores, con el WHEN de su `forwardOnly()`
 * sobre el grafo de DB8 (`CALL_TRANSITIONS`) MÁS esa transición, el mismo
 * evento (`BEFORE UPDATE OF status`), la misma función (`voice_forbid()`) y el
 * mismo motivo. Todo lo de DB8 se lee de su migración (una sola verdad); aquí
 * solo está lo que se añade ({@see ADDED_TRANSITIONS}).
 *
 * NINGÚN CHECK CAMBIA. Una `ambiguous` es siempre una saliente pedida por
 * Laravel (solo se llega desde `requested`; una entrante no puede estarlo,
 * `vc_direction_status_chk`, ni un tramo, que nace en lo que ve el proveedor,
 * `vc_starts_live`) y sin ningún id del proveedor (`vc_provider_binding_chk`:
 * `ambiguous` y `failed_to_start` están en el mismo grupo de «sin ids»). Como
 * `failed_to_start` exige además `ended_at` (`vc_ended_chk`) y ningún motivo de
 * política (`vc_authorization_chk`), la fila que la base admite es exactamente
 * la que deja el servicio (`VoiceAmbiguousResolution::transitionValues()`).
 *
 * CERROJOS (PG). `CREATE OR REPLACE TRIGGER` (PostgreSQL 14) toma SHARE ROW
 * EXCLUSIVE sobre `voice_calls` (frena a quien escribe, no a quien lee; un
 * DROP TRIGGER tomaría ACCESS EXCLUSIVE: medido en 14.24 el 22-11-2026) y no
 * hay ningún instante sin disparador. Si la sesión no trae `lock_timeout`,
 * `SET LOCAL lock_timeout = '5s'`: con una transacción abierta que escribe en
 * la tabla, cae limpia (55P03) y se reintenta. En SQLite, DROP y CREATE en la
 * misma transacción. No toca ninguna fila, ninguna otra tabla ni ningún
 * privilegio: la aplicación (`iron_app`) ya tiene UPDATE en `voice_calls`.
 *
 * Con su RECUENTO: antes y después, exactamente un `vc_status_forward`, el
 * mismo número de disparadores en `voice_%` y, en PG, con `voice_forbid()` y
 * encendido; si no, falla y no se registra. Con `--pretend` no se lee nada.
 *
 * down() devuelve el disparador EXACTO de DB8. Es reversible: el disparador no
 * revisa las filas que ya existen y una fila cerrada así (`failed_to_start`,
 * un final) cumple igual el esquema de DB8. Lo que NO revierte es el código:
 * `VoiceCall::TRANSITIONS` tiene que volver a la vez (sin la migración, el
 * servicio creería admitida una transición que la base rechaza con P0001).
 */
return new class extends Migration
{
    public const TABLE = 'voice_calls';

    public const TRIGGER = 'vc_status_forward';

    public const LOCK_TIMEOUT = '5s';

    /** La migración de DB8 que define el disparador (y que down() restaura tal cual). */
    public const H8_MIGRATION = '2026_11_10_100002_create_voice_tables';

    /** E19-3: lo único que se añade al grafo de DB8. */
    public const ADDED_TRANSITIONS = ['ambiguous' => ['failed_to_start']];

    /** CREATE OR REPLACE TRIGGER existe desde PostgreSQL 14 (producción: 14). */
    public const MIN_PG_VERSION = 140000;

    public function up(): void
    {
        $this->apply(self::graph());
    }

    public function down(): void
    {
        $this->apply(self::h8()::CALL_TRANSITIONS);
    }

    /** El grafo de DB8 más E19-3 (lo añadido, al final de su estado de origen). */
    public static function graph(): array
    {
        $grafo = self::h8()::CALL_TRANSITIONS;
        foreach (self::ADDED_TRANSITIONS as $desde => $hacia) {
            $grafo[$desde] = array_values(array_unique([...$grafo[$desde], ...$hacia]));
        }

        return $grafo;
    }

    /** La sentencia que crea el disparador con un grafo, como la escribe DB8 en cada motor. */
    public static function triggerSql(array $grafo, string $driver): string
    {
        $h8 = self::h8();
        [$tabla, $evento, $columnas, , $motivo] = $h8::rowTriggers()[self::TRIGGER];
        $cabecera = ' TRIGGER '.self::TRIGGER.' BEFORE '.$evento.' OF '.implode(', ', $columnas).' ON '.$tabla.' FOR EACH ROW WHEN ('.$h8::forwardOnly($grafo).') ';

        return $driver === 'pgsql'
            ? 'CREATE OR REPLACE'.$cabecera.'EXECUTE FUNCTION '.$h8::FUNCTION_NAME.'('.self::literal($motivo).')'
            : 'CREATE'.$cabecera.'BEGIN SELECT RAISE(ABORT, '.self::literal($h8::message($motivo, $evento, $tabla)).'); END';
    }

    /** @param  array<string, list<string>>  $grafo */
    private function apply(array $grafo): void
    {
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
            throw new RuntimeException("voice (E19-3): driver de base de datos no soportado: {$driver}. Solo pgsql y sqlite.");
        }

        // Laravel ya la envuelve en una transacción; esta (un SAVEPOINT dentro de ella) la hace atómica también fuera del migrador.
        DB::transaction(function () use ($grafo, $driver): void {
            if ($driver === 'pgsql') {
                $this->boundLockWait();
                $this->assertPostgresVersion();
            }
            $antes = $this->footprint($driver);
            if ($antes !== null && $antes['named'] !== 1) {
                throw new RuntimeException('voice (E19-3): '.self::TRIGGER.' no existe exactamente una vez en '.self::TABLE." ({$antes['named']}): el esquema no es el de DB8 y la migración no se aplica.");
            }

            if ($driver === 'sqlite') {
                DB::statement('DROP TRIGGER '.self::TRIGGER);
            }
            DB::statement(self::triggerSql($grafo, $driver));

            $despues = $this->footprint($driver);
            if ($antes !== null && $despues !== null
                && ($despues['named'] !== 1 || $despues['triggers'] !== $antes['triggers'] || ($driver === 'pgsql' && $despues['guard'] !== 1))) {
                throw new RuntimeException('voice (E19-3): el recuento no cuadra tras rehacer '.self::TRIGGER
                    ." (disparadores en voice_%: {$antes['triggers']} → {$despues['triggers']}; con ese nombre: {$despues['named']}). La migración no se registra.");
            }
        });
    }

    /**
     * Cuántos disparadores hay en `voice_%`, cuántos se llaman
     * `vc_status_forward` en `voice_calls` y (PG) cuántos de esos usan
     * `voice_forbid()` y están encendidos. null con `--pretend`.
     *
     * @return array{triggers: int, named: int, guard: int}|null
     */
    private function footprint(string $driver): ?array
    {
        if (DB::connection()->pretending()) {
            return null;
        }
        if ($driver === 'sqlite') {
            return [
                // SQLite no escapa `_` en LIKE sin ESCAPE: el prefijo, exacto.
                'triggers' => (int) DB::table('sqlite_master')->where('type', 'trigger')->whereRaw("substr(tbl_name, 1, 6) = 'voice_'")->count(),
                'named' => (int) DB::table('sqlite_master')->where('type', 'trigger')->where('tbl_name', self::TABLE)->where('name', self::TRIGGER)->count(),
                'guard' => 1,
            ];
        }

        return [
            'triggers' => (int) DB::scalar("SELECT count(*) FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid WHERE NOT t.tgisinternal AND c.relname LIKE 'voice\\_%'"),
            'named' => (int) DB::scalar('SELECT count(*) FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid WHERE NOT t.tgisinternal AND c.relname = ? AND t.tgname = ?',
                [self::TABLE, self::TRIGGER]),
            'guard' => (int) DB::scalar("SELECT count(*) FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid JOIN pg_proc p ON p.oid = t.tgfoid
                WHERE NOT t.tgisinternal AND c.relname = ? AND t.tgname = ? AND p.proname = ? AND t.tgenabled = 'O'", [self::TABLE, self::TRIGGER, self::h8()::FUNCTION_NAME]),
        ];
    }

    private function assertPostgresVersion(): void
    {
        if (DB::connection()->pretending()) {
            return;
        }
        $version = (int) DB::scalar("SELECT current_setting('server_version_num')");
        if ($version < self::MIN_PG_VERSION) {
            throw new RuntimeException("voice (E19-3): hace falta PostgreSQL 14 o posterior (CREATE OR REPLACE TRIGGER); este servidor es {$version}. La migración no se aplica.");
        }
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

    private static function h8(): object
    {
        static $h8 = null;

        return $h8 ??= require database_path('migrations/'.self::H8_MIGRATION.'.php');
    }

    private static function literal(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }
};
