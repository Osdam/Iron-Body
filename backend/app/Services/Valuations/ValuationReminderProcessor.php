<?php

namespace App\Services\Valuations;

use App\Models\ValuationAppointment;
use App\Models\ValuationReminder;
use App\Support\Access\TrainerMemberScope;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * El procesador del outbox de avisos (`valuations:process-reminders`, cada
 * minuto, contrato §5). Sin colas.
 *
 *  1. Las `processing` de hace más de 10 min vuelven a `pending`: un proceso que
 *     murió a mitad no deja un aviso colgado para siempre.
 *  2. Reclama hasta 100 vencidas (`pending`, o `error` cuya hora de reintento
 *     llegó). En PostgreSQL con `FOR UPDATE SKIP LOCKED`, y cada fila pasa a
 *     `processing` con un UPDATE condicionado a su estado: aunque el comando
 *     corra dos veces a la vez, cada aviso tiene UN dueño.
 *  3. Antes de enviar, con la fila bloqueada, comprueba que sigue valiendo: la
 *     cita existe, la versión es la misma, el estado admite el aviso, el inicio
 *     sigue en el futuro (recordatorios y aviso interno previo) y el canal del
 *     socio está activo EN LOS AJUSTES DE AHORA. Si no, `skipped` con el motivo.
 *  4. Envía por {@see ValuationNotifier} (idempotente por `event_key`: un
 *     reproceso no duplica ni la fila ni el push).
 *  5. Correcto → `accepted`. Excepción → `error` y reintento a los 5 y a los
 *     15 minutos; al tercer intento, `failed`. Nunca sin fin.
 *
 * El contenido del mensaje no se escribe en el log: solo ids, tipo y clase del
 * error.
 */
final class ValuationReminderProcessor
{
    public const BATCH = 100;

    public const MAX_ATTEMPTS = 3;

    public const STALE_PROCESSING_MINUTES = 10;

    /**
     * «Sin atender» deja de tener sentido pasadas 2 h de la hora: tras una caída
     * del scheduler no se dispara de golpe (y con sonido) por citas de la mañana.
     */
    public const UNATTENDED_MAX_LATE_MINUTES = 120;

    /** Minutos hasta el siguiente intento, según los intentos ya hechos. */
    private const RETRY_AFTER = [1 => 5, 2 => 15];

    /** Avisos que exigen que la cita siga abierta (programada o confirmada). */
    private const NEED_OPEN = [
        ValuationReminder::KIND_CONFIRMATION,
        ValuationReminder::KIND_REMINDER_LONG,
        ValuationReminder::KIND_REMINDER_SHORT,
        ValuationReminder::KIND_INTERNAL_UPCOMING,
        ValuationReminder::KIND_INTERNAL_UNATTENDED,
    ];

    /**
     * Avisos que caducan cuando la cita ya empezó. La confirmación también: tras
     * una caída del scheduler, «tu valoración quedó programada» para una cita
     * que ya pasó no le sirve a nadie.
     */
    private const NEED_FUTURE_START = [
        ValuationReminder::KIND_CONFIRMATION,
        ValuationReminder::KIND_REMINDER_LONG,
        ValuationReminder::KIND_REMINDER_SHORT,
        ValuationReminder::KIND_INTERNAL_UPCOMING,
    ];

    public function __construct(
        private readonly ValuationNotifier $notifier,
        private readonly ValuationSettings $settings,
    ) {}

    /** @return array{recovered: int, exhausted: int, claimed: int, accepted: int, skipped: int, error: int, failed: int} */
    public function run(int $limite = self::BATCH): array
    {
        $resumen = ['recovered' => 0, 'exhausted' => 0, 'claimed' => 0, 'accepted' => 0, 'skipped' => 0, 'error' => 0, 'failed' => 0];

        $resumen['recovered'] = $this->recoverOrphans();
        $resumen['exhausted'] = $this->failExhausted();

        $mios = $this->claim($limite);
        $resumen['claimed'] = count($mios);

        foreach ($mios as $id) {
            $resultado = $this->process($id);
            if (isset($resumen[$resultado])) {
                $resumen[$resultado]++;
            }
        }

        return $resumen;
    }

    /** 1. Reinicio a medias: `processing` viejas vuelven a la cola. */
    public function recoverOrphans(): int
    {
        $ahora = Carbon::now()->utc();

        return ValuationReminder::query()
            ->where('status', ValuationReminder::STATUS_PROCESSING)
            ->where('updated_at', '<', ValuationAvailability::db($ahora->copy()->subMinutes(self::STALE_PROCESSING_MINUTES)))
            ->update(['status' => ValuationReminder::STATUS_PENDING, 'updated_at' => $ahora]);
    }

    /** Las que agotaron los intentos (p. ej. tres caídas del proceso) no se reclaman más. */
    private function failExhausted(): int
    {
        $ahora = Carbon::now()->utc();

        return ValuationReminder::query()
            ->whereIn('status', ValuationReminder::WAITING_STATUSES)
            ->where('attempts', '>=', self::MAX_ATTEMPTS)
            ->update([
                'status' => ValuationReminder::STATUS_FAILED,
                'last_error' => 'max_attempts',
                'processed_at' => $ahora,
                'updated_at' => $ahora,
            ]);
    }

    /**
     * 2. Reclama. Devuelve SOLO los ids que esta corrida pasó a `processing`.
     *
     * @return list<int>
     */
    public function claim(int $limite): array
    {
        $ahora = Carbon::now()->utc();

        return DB::transaction(function () use ($ahora, $limite): array {
            $consulta = ValuationReminder::query()
                ->whereIn('status', ValuationReminder::WAITING_STATUSES)
                ->where('due_at', '<=', ValuationAvailability::db($ahora))
                ->where('attempts', '<', self::MAX_ATTEMPTS)
                ->orderBy('due_at')
                ->orderBy('id')
                ->limit($limite);
            if (DB::connection()->getDriverName() === 'pgsql') {
                $consulta->lock('for update skip locked');
            }

            $mios = [];
            foreach ($consulta->pluck('id') as $id) {
                $hecho = ValuationReminder::query()
                    ->whereKey($id)
                    ->whereIn('status', ValuationReminder::WAITING_STATUSES)
                    ->update([
                        'status' => ValuationReminder::STATUS_PROCESSING,
                        'attempts' => DB::raw('attempts + 1'),
                        'updated_at' => $ahora,
                    ]);
                if ($hecho === 1) {
                    $mios[] = (int) $id;
                }
            }

            return $mios;
        });
    }

    /** 3–5. Un aviso reclamado. Devuelve el estado en que queda. */
    public function process(int $id): string
    {
        return DB::transaction(function () use ($id): string {
            $aviso = ValuationReminder::query()->lockForUpdate()->find($id);
            if ($aviso === null || $aviso->status !== ValuationReminder::STATUS_PROCESSING) {
                return 'gone';
            }

            $ahora = Carbon::now()->utc();
            $cita = ValuationAppointment::query()
                ->with([
                    // Proceso de sistema: el alcance de entrenador no aplica.
                    'member' => fn ($q) => $q->withoutGlobalScope(TrainerMemberScope::NAME),
                    'trainer',
                ])
                ->find($aviso->appointment_id);

            $motivo = $this->skipReason($aviso, $cita, $ahora);
            if ($motivo !== null) {
                $aviso->forceFill([
                    'status' => ValuationReminder::STATUS_SKIPPED,
                    'last_error' => $motivo,
                    'processed_at' => $ahora,
                ])->save();

                return ValuationReminder::STATUS_SKIPPED;
            }

            try {
                // En su propio savepoint: un fallo al escribir la notificación
                // no deja abortada la transacción en la que se anota el error.
                $notificacion = DB::transaction(fn () => $aviso->channel === ValuationReminder::CHANNEL_MEMBER_APP
                    ? $this->notifier->toMember($cita->member, $cita, $aviso->kind, $ahora)
                    : $this->notifier->toCrm($cita, $aviso->kind, $ahora));
            } catch (Throwable $e) {
                Log::warning('valuations.reminder_failed', [
                    'reminder_id' => $aviso->id,
                    'kind' => $aviso->kind,
                    'channel' => $aviso->channel,
                    'attempt' => $aviso->attempts,
                    'error' => $e::class,
                ]);

                $agotado = (int) $aviso->attempts >= self::MAX_ATTEMPTS;
                $aviso->forceFill(array_merge(
                    [
                        'status' => $agotado ? ValuationReminder::STATUS_FAILED : ValuationReminder::STATUS_ERROR,
                        // Solo la clase y el SQLSTATE: el mensaje de una excepción de
                        // base de datos lleva los valores, y con ellos el texto del aviso.
                        'last_error' => mb_substr(class_basename($e).($e instanceof QueryException ? ' '.($e->errorInfo[0] ?? '') : ''), 0, 255),
                    ],
                    $agotado
                        ? ['processed_at' => $ahora]
                        : ['due_at' => $ahora->copy()->addMinutes(self::RETRY_AFTER[(int) $aviso->attempts] ?? 15)],
                ))->save();

                return $agotado ? ValuationReminder::STATUS_FAILED : ValuationReminder::STATUS_ERROR;
            }

            $aviso->forceFill([
                'status' => ValuationReminder::STATUS_ACCEPTED,
                'provider_ref' => (string) $notificacion->uuid,
                'processed_at' => $ahora,
                'last_error' => null,
            ])->save();

            return ValuationReminder::STATUS_ACCEPTED;
        });
    }

    /** null = se envía; si no, el motivo por el que se omite. */
    private function skipReason(ValuationReminder $aviso, ?ValuationAppointment $cita, Carbon $ahora): ?string
    {
        if ($cita === null) {
            return 'appointment_missing';
        }
        if ((int) $aviso->appointment_version !== (int) $cita->version) {
            return 'stale_version';
        }
        if (in_array($aviso->kind, self::NEED_OPEN, true) && ! $cita->isOpen()) {
            return 'appointment_inactive';
        }
        if (in_array($aviso->kind, self::NEED_FUTURE_START, true) && ! $cita->starts_at->copy()->utc()->gt($ahora)) {
            return 'expired';
        }
        if ($aviso->kind === ValuationReminder::KIND_INTERNAL_UNATTENDED
            && $ahora->greaterThan($cita->starts_at->copy()->utc()->addMinutes(self::UNATTENDED_MAX_LATE_MINUTES))) {
            return 'expired';
        }
        if ($aviso->channel === ValuationReminder::CHANNEL_MEMBER_APP) {
            if (! $this->settings->current()->member_app_enabled) {
                return 'channel_disabled';
            }
            if ($cita->member === null) {
                // Nunca se «arregla» enviando sin destinatario: sería un push a todos.
                return 'member_missing';
            }
        }

        return null;
    }
}
