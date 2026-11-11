<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SC-3 del cierre (R8-12; `plans/informes-h9/informe-sec-cierre.md` §6.3,
 * decisión de seguridad «SÍ, frenar»): `vbr_reason_chk` admite un SÉPTIMO
 * motivo del freno de voz, `webhook_unhealthy`, además de los seis de DB8
 * (`2026_11_10_100002`, `BRAKE_REASONS`):
 *
 *  · los seis de DB8 (`guard_violation`, `seal_mismatch`, `agent_drift`,
 *    `cost_cap`, `subaccount_alert`, `unknown_provider_call`): sin cambios y
 *    en su orden;
 *  · `webhook_unhealthy` — NUEVO: lo abre `voice:webhook-health`
 *    (`VoiceWebhookHealth`) cuando un problema DEFINITIVO del webhook
 *    post-llamada o de los secretos (desactivado, por una persona o por
 *    ElevenLabs tras fallos seguidos; no está; entrega en otra URL; no firma
 *    con HMAC; falta un secreto) deja las llamadas sin la guarda posterior.
 *    Lo incierto (no se pudo leer, lista truncada) no frena.
 *
 * SOLO POSTGRESQL, como todos los CHECK de forma de DB8: en SQLite no hay CHECK
 * que reescribir (la suite) y el motivo lo vigila `VoiceBrake::open()` con
 * `VoiceBrakeEntry::REASONS` (el único que escribe una apertura); la puerta PG
 * es obligatoria.
 *
 * DROP + ADD EN UNA SOLA SENTENCIA (el patrón de V9, `2026_11_13_100001`): un
 * solo cerrojo ACCESS EXCLUSIVE sobre `voice_brakes` y ningún instante sin
 * CHECK. Las filas que ya existen cumplen el CHECK nuevo por construcción (los
 * seis motivos siguen literales y el nuevo solo añade; una suelta tiene
 * `reason` nulo), así que la validación no puede fallar por datos. Con su
 * RECUENTO: antes y después, exactamente un `vbr_reason_chk` y el mismo número
 * de CHECK en `voice_%`; si no, falla y no se registra. Se niega a ampliar dos
 * veces (un esquema que ya admite el motivo no es el que espera).
 *
 * CERROJOS. Si la sesión no trae `lock_timeout` (el de `ironbody-migrate` es
 * de 5 s), la migración pone `SET LOCAL lock_timeout = '5s'`: nunca espera sin
 * límite; con una transacción abierta sobre `voice_brakes`, cae limpia (55P03)
 * y se reintenta. La tabla es diminuta (una fila por apertura o suelta).
 *
 * down() SE NIEGA si hay alguna apertura con `reason = 'webhook_unhealthy'` (el
 * CHECK de DB8 no la admitiría, y el freno es de SOLO INSERCIÓN: nunca se
 * borra) y, sin ellas, restaura el CHECK EXACTO de DB8 (lo lee de su
 * migración: una sola verdad), con el mismo recuento. Nunca borra filas. En
 * producción no se ejecuta nunca (`migrate:rollback` está prohibido,
 * protección 11).
 */
return new class extends Migration
{
    public const TABLE = 'voice_brakes';

    public const CHECK = 'vbr_reason_chk';

    public const REASON = 'webhook_unhealthy';

    public const LOCK_TIMEOUT = '5s';

    /** La migración de DB8 que define el CHECK que se amplía (y que down() restaura tal cual). */
    public const H8_MIGRATION = '2026_11_10_100002_create_voice_tables';

    /** El CHECK ampliado: los seis motivos de DB8, LITERALES y en su orden, y `webhook_unhealthy` al final. */
    public const EXPRESSION = "reason IS NULL OR reason IN ('guard_violation', 'seal_mismatch', 'agent_drift', 'cost_cap', 'subaccount_alert', 'unknown_provider_call', 'webhook_unhealthy')";

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
                    throw new RuntimeException('voice (SC-3): '.self::CHECK.' no existe exactamente una vez en '.self::TABLE." ({$antes['named']}): el esquema no es el de DB8 y la migración no se aplica.");
                }
                if (str_contains($antes['definition'], "'".self::REASON."'")) {
                    throw new RuntimeException('voice (SC-3): '.self::CHECK.' ya admite el motivo «'.self::REASON.'»: el esquema no es el que esta migración espera.');
                }
            }

            $this->replaceCheck(self::EXPRESSION, false);

            $despues = $this->footprint();
            if ($antes !== null && $despues !== null) {
                if ($despues['named'] !== 1 || $despues['checks'] !== $antes['checks'] || ! str_contains($despues['definition'], "'".self::REASON."'")) {
                    throw new RuntimeException('voice (SC-3): el recuento no cuadra tras ampliar '.self::CHECK
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
                $filas = (int) DB::table(self::TABLE)->where('reason', self::REASON)->count();
                if ($filas > 0) {
                    throw new RuntimeException("voice (SC-3): down() se niega a restaurar el CHECK de DB8 con {$filas} apertura(s) del freno por «".self::REASON.'»: '
                        .'el CHECK de DB8 no las admite y el freno es de solo inserción (no se borra). En producción no se revierte nunca (protección 11).');
                }
            }
            $antes = $this->footprint();

            $this->replaceCheck(self::h8Expression(), true);

            $despues = $this->footprint();
            if ($antes !== null && $despues !== null) {
                if ($despues['named'] !== 1 || $despues['checks'] !== $antes['checks'] || str_contains($despues['definition'], "'".self::REASON."'")) {
                    throw new RuntimeException('voice (SC-3): el recuento no cuadra tras restaurar '.self::CHECK
                        ." (CHECK en voice_%: {$antes['checks']} → {$despues['checks']}; con ese nombre: {$despues['named']}). No se revierte.");
                }
            }
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
     * Cuántos CHECK hay en `voice_%`, cuántos se llaman `vbr_reason_chk` en
     * `voice_brakes` y cómo lo escribe PostgreSQL. null con `--pretend`.
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
        return new RuntimeException("voice (SC-3): driver de base de datos no soportado: {$driver}. Solo pgsql y sqlite.");
    }
};
