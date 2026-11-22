<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * HITO 9, paquete AI9: el contador de presupuesto del CLASIFICADOR DE
 * RESPUESTAS. Contrato: ULTRON_STUDIO_H9_CONTRACT.md, paquete AI9 («su
 * presupuesto»), D9-8 etapa 5 y L9-2 (la reserva atómica). Informe:
 * plans/informes-cierre/informe-be-clasificador.md.
 *
 * SOLO AMPLÍA un CHECK: `obud_scope_chk` de `outreach_budget_counters` admite
 * además el alcance `classifier:reply` (las llamadas y el coste del día del
 * clasificador), con la misma reserva en un solo UPDATE que ya usan los
 * canales. Ninguna tabla, columna, índice, disparador ni dato cambia; todas
 * las filas que valían siguen valiendo y lo que se rechazaba (un canal que no
 * gasta, un teléfono en claro, una estrategia sin uuid) se sigue rechazando.
 *
 * En SQLite no hay nada que hacer: los CHECK de la ejecución solo existen en
 * PostgreSQL (`2026_11_12_100002`, `upPostgres()`); allí la forma del alcance
 * la vigila `BudgetReservations::parcel()`.
 *
 * VUELTA ATRÁS: `down()` devuelve el CHECK estrecho SOLO si no hay ninguna
 * fila `classifier:*` (las reservas del día no se borran: `obud_forbid_delete`).
 * Con filas, se niega: a partir de la primera llamada del clasificador es
 * IRREVERSIBLE por diseño (como el resto de las migraciones del H9 en
 * producción). Para apagarlo no hace falta: basta la bandera o el cupo a 0.
 */
return new class extends Migration
{
    public const TABLE = 'outreach_budget_counters';

    public const CHECK = 'obud_scope_chk';

    public const LOCK_TIMEOUT = '5s';

    /** El CHECK de `2026_11_12_100002` (`rowChecks()`), tal cual. */
    public const NARROW = "scope ~ '^(channel:(assisted|whatsapp_template|voice_call)|strategy:[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}|phone:[0-9a-f]{64})$'";

    /** El mismo, con el alcance del clasificador. */
    public const WIDE = "scope ~ '^(channel:(assisted|whatsapp_template|voice_call)|strategy:[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}|phone:[0-9a-f]{64}|classifier:reply)$'";

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }
        $this->boundLockWait();
        // Una sentencia: no hay un instante sin CHECK.
        DB::statement('ALTER TABLE '.self::TABLE.' DROP CONSTRAINT '.self::CHECK.', ADD CONSTRAINT '.self::CHECK.' CHECK ('.self::WIDE.')');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }
        $this->boundLockWait();
        $pretending = DB::connection()->pretending();
        if (! $pretending) {
            DB::statement('LOCK TABLE '.self::TABLE.' IN SHARE ROW EXCLUSIVE MODE');
            $filas = (int) DB::scalar('SELECT count(*) FROM '.self::TABLE." WHERE scope LIKE 'classifier:%'");
            if ($filas > 0) {
                throw new RuntimeException("outreach (AI9): hay {$filas} fila(s) del contador del clasificador y las reservas del día no se borran: "
                    .'el CHECK estrecho no se puede devolver. Para dejar el clasificador sin efecto basta COMMERCIAL_AUTOMATION_CLASSIFIER_ENABLED=false.');
            }
        }
        DB::statement('ALTER TABLE '.self::TABLE.' DROP CONSTRAINT '.self::CHECK.', ADD CONSTRAINT '.self::CHECK.' CHECK ('.self::NARROW.')');
    }

    /** Como `2026_11_12_100002`: si nadie fijó una espera de cerrojo, 5 s (un ALTER no se queda colgado detrás de una consulta larga). */
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
};
