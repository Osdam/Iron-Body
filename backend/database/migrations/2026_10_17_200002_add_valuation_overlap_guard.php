<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * RED DE SEGURIDAD de la agenda de valoraciones, SOLO EN POSTGRESQL.
 *
 * La protección principal contra dos citas del mismo entrenador a la vez es el
 * servicio: transacción + cerrojo de la fila del entrenador y después de la del
 * socio, y las comprobaciones repetidas dentro. Esto es la segunda puerta, la
 * que no depende de que todo el código pase por el servicio:
 *
 *   EXCLUDE USING gist (trainer_id WITH =, tsrange(starts_at, ends_at, '[)') WITH &&)
 *     WHERE (status IN ('scheduled','confirmed','in_progress'))
 *
 * `[)`: una cita que empieza cuando acaba otra no choca. El margen
 * (`buffer_minutes`) NO está aquí —es un ajuste que cambia—; lo aplica el
 * servicio. El servicio traduce el 23P01 de esta restricción a 409 `slot_taken`.
 *
 * Además, CHECKs que la base hace cumplir aunque alguien escriba sin pasar por
 * el código:
 *  · `ends_at > starts_at`;
 *  · el DOMINIO de `valuation_appointments.status` y de
 *    `valuation_reminders.status/channel/kind` (revisión de BD M3: un estado
 *    escrito distinto, p. ej. 'SCHEDULED', esquivaría la EXCLUDE).
 * Las listas van escritas aquí y no tomadas de los modelos: esta migración se
 * aplica ANTES de publicar el código (procedimiento de despliegue).
 *
 * ¿Y SI NO SE PUEDE INSTALAR btree_gist? La EXCLUDE la necesita
 * (`trainer_id WITH =`). Solo se tolera lo que significa «aquí no se puede
 * instalar» ({@see self::TOLERATED_EXTENSION_ERRORS}): se registra un aviso
 * `valuations.overlap_guard.btree_gist_unavailable` y la migración termina SIN
 * la EXCLUDE (la agenda funciona igual, protegida solo por el cerrojo). CUALQUIER
 * OTRO error —un `lock_timeout` (55P03), un `statement_timeout` (57014), un
 * interbloqueo…— se propaga: la migración falla, NO queda registrada y se
 * vuelve a intentar. Antes se tragaba todo y una espera de cerrojo dejaba la red
 * fuera, en silencio y para siempre (revisión de BD I3). Los CHECK y la EXCLUDE
 * no se toleran nunca.
 *
 * SQLite (la suite): no hace nada.
 */
return new class extends Migration
{
    private const EXCLUDE = 'valuation_appointments_no_overlap';

    private const CHECK = 'valuation_appointments_ends_after_starts';

    /**
     * SQLSTATE de CREATE EXTENSION que significan «no se puede instalar aquí»:
     *  · 42501 insufficient_privilege — el rol no tiene CREATE en la base (no es
     *    su dueño) o la extensión no es «trusted»;
     *  · 58P01 undefined_file — la extensión no está en el servidor (falta el
     *    paquete contrib);
     *  · 0A000 feature_not_supported — la instalación no la admite.
     * Comprobados en PostgreSQL 14.24 los dos primeros (informe de valoraciones).
     */
    public const TOLERATED_EXTENSION_ERRORS = ['42501', '58P01', '0A000'];

    /** CHECK de dominio por tabla: nombre → [columna, valores válidos]. */
    public const DOMAIN_CHECKS = [
        'valuation_appointments' => [
            'valuation_appointments_status_chk' => ['status', ['scheduled', 'confirmed', 'in_progress', 'completed', 'no_show', 'cancelled']],
        ],
        'valuation_reminders' => [
            'valuation_reminders_status_chk' => ['status', ['pending', 'processing', 'accepted', 'skipped', 'error', 'failed', 'cancelled']],
            'valuation_reminders_channel_chk' => ['channel', ['member_app', 'crm']],
            'valuation_reminders_kind_chk' => ['kind', ['confirmation', 'reminder_long', 'reminder_short', 'cancellation', 'rescheduled', 'internal_upcoming', 'internal_unattended']],
        ],
    ];

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE valuation_appointments ADD CONSTRAINT '.self::CHECK.' CHECK (ends_at > starts_at)');

        foreach (self::DOMAIN_CHECKS as $tabla => $checks) {
            foreach ($checks as $nombre => [$columna, $valores]) {
                $lista = implode(', ', array_map(fn (string $v) => "'".$v."'", $valores));
                DB::statement("ALTER TABLE {$tabla} ADD CONSTRAINT {$nombre} CHECK ({$columna} IN ({$lista}))");
            }
        }

        if (! $this->installExtension('btree_gist')) {
            return;
        }

        DB::statement(
            'ALTER TABLE valuation_appointments ADD CONSTRAINT '.self::EXCLUDE.' '
            ."EXCLUDE USING gist (trainer_id WITH =, tsrange(starts_at, ends_at, '[)') WITH &&) "
            ."WHERE (status IN ('scheduled','confirmed','in_progress'))"
        );
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // La extensión se deja: es inocua y otra cosa puede usarla.
        DB::statement('ALTER TABLE valuation_appointments DROP CONSTRAINT IF EXISTS '.self::EXCLUDE);
        foreach (self::DOMAIN_CHECKS as $tabla => $checks) {
            foreach (array_keys($checks) as $nombre) {
                DB::statement("ALTER TABLE {$tabla} DROP CONSTRAINT IF EXISTS {$nombre}");
            }
        }
        DB::statement('ALTER TABLE valuation_appointments DROP CONSTRAINT IF EXISTS '.self::CHECK);
    }

    /**
     * ¿Está (o se pudo instalar) la extensión? `false` solo con un error de
     * {@see self::TOLERATED_EXTENSION_ERRORS}, y con un aviso en el log; con
     * cualquier otro, la excepción sigue su camino.
     *
     * Va en un SAVEPOINT (`DB::transaction` anidada en la de la migración): en
     * PostgreSQL un error deja abortada la transacción, y sin volver al
     * savepoint fallaría todo lo que viniera detrás.
     */
    public function installExtension(string $extension): bool
    {
        if (preg_match('/^[a-z_][a-z0-9_]*$/', $extension) !== 1) {
            throw new InvalidArgumentException("Nombre de extensión no válido: {$extension}");
        }
        if (DB::table('pg_extension')->where('extname', $extension)->exists()) {
            return true;
        }

        try {
            DB::transaction(fn () => DB::statement("CREATE EXTENSION IF NOT EXISTS {$extension}"));

            return true;
        } catch (QueryException $e) {
            if (! self::isMissingExtension($e)) {
                throw $e;
            }

            Log::warning('valuations.overlap_guard.btree_gist_unavailable', [
                'extension' => $extension,
                'sqlstate' => self::sqlState($e),
                'error' => $e->getPrevious()?->getMessage() ?? $e->getMessage(),
                'effect' => 'Migración aplicada SIN la restricción EXCLUDE valuation_appointments_no_overlap: '
                    .'las citas quedan protegidas solo por el cerrojo del servicio. Instalar la extensión y crear '
                    .'la restricción a mano (procedimiento de despliegue de la agenda de valoraciones).',
            ]);

            return false;
        }
    }

    /** ¿Es un error de «no se puede instalar la extensión aquí»? */
    public static function isMissingExtension(QueryException $e): bool
    {
        return in_array(self::sqlState($e), self::TOLERATED_EXTENSION_ERRORS, true);
    }

    private static function sqlState(QueryException $e): string
    {
        return (string) ($e->errorInfo[0] ?? $e->getCode());
    }
};
