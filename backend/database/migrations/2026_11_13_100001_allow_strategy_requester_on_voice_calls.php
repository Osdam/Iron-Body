<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * V9 (voz por estrategia), ENMIENDA 17 DEL H8 (propuesta; contrato H9, D9-9.4,
 * §10 fila V9; informe `plans/informes-h9/informe-v9.md`): `vc_requester_chk`
 * admite un TERCER solicitante de una saliente, `strategy`, además de los dos
 * de DB8 (`2026_11_10_100002`, `vc_requester_chk`):
 *
 *  · `admin`    — una persona con `telephony.call_place`: su id, su nombre y su
 *                 `client_request_id` (sin cambios);
 *  · `system`   — solo un reintento de negocio (`attempt > 1`) (sin cambios);
 *  · `strategy` — NUEVO: una estrategia publicada del H9 por
 *                 `OutboundGate::requestForStrategy()`. Sin cuenta
 *                 (`requested_by_admin_id` y `requested_by_role` nulos), con la
 *                 referencia del trabajo del H9 como `client_request_id` (el uuid
 *                 de `outreach_jobs`, la idempotencia), su etiqueta
 *                 «Estrategia <clave>» como nombre y SIEMPRE un primer intento
 *                 (`attempt = 1`): un reintento de negocio del H9 es OTRO
 *                 trabajo con OTRO uuid, nunca una fila hija de esta.
 *
 * SOLO POSTGRESQL, como todos los CHECK de forma de DB8: en SQLite no hay CHECK
 * que reescribir (la suite) y la regla la vigila el servicio (la puerta es el
 * único sitio que escribe `requested_by_type`); la puerta PG es obligatoria.
 *
 * DROP + ADD EN UNA SOLA SENTENCIA (el patrón del H6 y de DB9 con el CHECK del
 * libro): un solo cerrojo ACCESS EXCLUSIVE sobre `voice_calls` y ningún
 * instante sin CHECK. Las filas que ya existen cumplen el CHECK nuevo por
 * construcción (las dos ramas de DB8 siguen literales y la nueva solo añade),
 * así que la validación no puede fallar por datos. Con su RECUENTO: antes y
 * después, exactamente un `vc_requester_chk` y el mismo número de CHECK en
 * `voice_%` (51 en DB8); si no, falla y no se registra.
 *
 * CERROJOS. Si la sesión no trae `lock_timeout` (el de `ironbody-migrate` es
 * de 5 s), la migración pone `SET LOCAL lock_timeout = '5s'`: nunca espera sin
 * límite; con una transacción abierta sobre `voice_calls`, cae limpia (55P03)
 * y se reintenta. La tabla está vacía en producción a la fecha (las salientes
 * siguen cerradas en el código: `CALLS_ENABLED_IN_CODE = false`).
 *
 * down() SE NIEGA si hay alguna fila con `requested_by_type = 'strategy'` (el
 * CHECK de DB8 no la admitiría) y, sin ellas, restaura el CHECK EXACTO de DB8
 * (lo lee de su migración: una sola verdad). Nunca borra filas. En producción
 * no se ejecuta nunca (`migrate:rollback` está prohibido, protección 11).
 *
 * Lo que NO cubre (lo garantiza `OutboundGate::requestForStrategy()` en su
 * transacción, bajo el cerrojo de la puerta): que el `client_request_id` sea
 * de verdad un trabajo vivo del H9, que el destino sea de la persona del
 * trabajo, la idempotencia por trabajo (la busca bajo el cerrojo; la base solo
 * tiene el UNIQUE por persona de DB8, que no ve dos filas sin cuenta) y que el
 * nombre sea el de una estrategia que existe.
 */
return new class extends Migration
{
    public const TABLE = 'voice_calls';

    public const CHECK = 'vc_requester_chk';

    public const REQUESTER_STRATEGY = 'strategy';

    public const LOCK_TIMEOUT = '5s';

    /** La migración de DB8 que define el CHECK que se amplía (y que down() restaura tal cual). */
    public const H8_MIGRATION = '2026_11_10_100002_create_voice_tables';

    /**
     * El CHECK ampliado: las dos ramas de DB8, LITERALES, y la de `strategy`.
     * Toda columna que se compara sin `IS [NOT] NULL` delante es NOT NULL
     * (`direction`, `attempt`) o va protegida en la misma conjunción
     * (`requested_by_type IS NOT NULL`): ninguna rama puede dar NULL.
     */
    public const EXPRESSION = "((direction = 'inbound' OR transfer_of_call_id IS NOT NULL) AND requested_by_type IS NULL AND requested_by_admin_id IS NULL AND requested_by_name IS NULL AND client_request_id IS NULL) "
        ."OR (direction = 'outbound' AND transfer_of_call_id IS NULL AND requested_by_type IS NOT NULL AND ((requested_by_type = 'admin' AND requested_by_admin_id IS NOT NULL AND requested_by_name IS NOT NULL AND client_request_id IS NOT NULL) "
        ."OR (requested_by_type = 'system' AND requested_by_admin_id IS NULL AND attempt > 1) "
        ."OR (requested_by_type = 'strategy' AND requested_by_admin_id IS NULL AND requested_by_role IS NULL AND client_request_id IS NOT NULL AND attempt = 1 "
        ."AND requested_by_name IS NOT NULL AND requested_by_name ~ '^Estrategia [a-z0-9][a-z0-9_-]{2,47}$')))";

    public function up(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite') {
            // DB8: los CHECK de forma son solo de PostgreSQL; en SQLite no hay nada que reescribir.
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
                    throw new RuntimeException('voice (V9): '.self::CHECK.' no existe exactamente una vez en '.self::TABLE." ({$antes['named']}): el esquema no es el de DB8 y la migración no se aplica.");
                }
                if (str_contains($antes['definition'], "'".self::REQUESTER_STRATEGY."'")) {
                    throw new RuntimeException('voice (V9): '.self::CHECK.' ya admite el solicitante «strategy»: el esquema no es el que esta migración espera.');
                }
            }

            $this->replaceCheck(self::EXPRESSION, false);

            $despues = $this->footprint();
            if ($antes !== null && $despues !== null) {
                if ($despues['named'] !== 1 || $despues['checks'] !== $antes['checks'] || ! str_contains($despues['definition'], "'".self::REQUESTER_STRATEGY."'")) {
                    throw new RuntimeException('voice (V9): el recuento no cuadra tras ampliar '.self::CHECK
                        ." (CHECK en voice_%: {$antes['checks']} → {$despues['checks']}; con ese nombre: {$despues['named']}). La migración no se registra.");
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
            if (! DB::connection()->pretending()) {
                $filas = (int) DB::table(self::TABLE)->where('requested_by_type', self::REQUESTER_STRATEGY)->count();
                if ($filas > 0) {
                    throw new RuntimeException("voice (V9): down() se niega a restaurar el CHECK de DB8 con {$filas} llamada(s) pedida(s) por una estrategia: "
                        .'el CHECK de DB8 no las admite y las filas no se borran. En producción no se revierte nunca (protección 11).');
                }
            }
            $this->replaceCheck(self::h8Expression(), true);
        });
    }

    /** El CHECK de DB8, tal cual lo define su migración (una sola verdad). */
    public static function h8Expression(): string
    {
        $h8 = require database_path('migrations/'.self::H8_MIGRATION.'.php');

        return $h8::rowChecks()[self::TABLE][self::CHECK];
    }

    /** DROP + ADD en una sola sentencia: un solo cerrojo y ningún instante sin CHECK. En up() sin IF EXISTS. */
    private function replaceCheck(string $expression, bool $ifExists): void
    {
        DB::statement('ALTER TABLE '.self::TABLE.' DROP CONSTRAINT '.($ifExists ? 'IF EXISTS ' : '').self::CHECK
            .', ADD CONSTRAINT '.self::CHECK.' CHECK ('.$expression.')');
    }

    /**
     * Cuántos CHECK hay en `voice_%`, cuántos se llaman `vc_requester_chk` en
     * `voice_calls` y cómo lo escribe PostgreSQL. null con `--pretend`.
     *
     * @return array{checks: int, named: int, definition: string}|null
     */
    private function footprint(): ?array
    {
        if (DB::connection()->pretending()) {
            return null;
        }
        $checks = (int) DB::scalar("SELECT count(*) FROM pg_constraint k JOIN pg_class c ON c.oid = k.conrelid WHERE k.contype = 'c' AND c.relname LIKE 'voice\\_%'");
        $filas = DB::select("SELECT pg_get_constraintdef(k.oid) AS def FROM pg_constraint k JOIN pg_class c ON c.oid = k.conrelid WHERE k.contype = 'c' AND c.relname = ? AND k.conname = ?",
            [self::TABLE, self::CHECK]);

        return ['checks' => $checks, 'named' => count($filas), 'definition' => (string) ($filas[0]->def ?? '')];
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

    private static function unsupported(string $driver): RuntimeException
    {
        return new RuntimeException("voice (V9): driver de base de datos no soportado: {$driver}. Solo pgsql y sqlite.");
    }
};
