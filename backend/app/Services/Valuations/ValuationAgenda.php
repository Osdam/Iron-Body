<?php

namespace App\Services\Valuations;

use App\Exceptions\ValuationException;
use App\Models\Admin;
use App\Models\Member;
use App\Models\Trainer;
use App\Models\ValuationAppointment;
use App\Models\ValuationAppointmentEvent;
use App\Models\ValuationReminder;
use App\Models\ValuationSetting;
use App\Services\Audit\AuditTrail;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Las ESCRITURAS de la agenda de valoraciones (contrato §3). Solo aquí.
 *
 * CONCURRENCIA. Crear y reprogramar corren en una transacción que bloquea
 * la fila del ENTRENADOR y después la del SOCIO, en ese orden siempre, y la de
 * la cita al final; y repiten DENTRO las reglas 6–10. En PostgreSQL el cerrojo
 * de entrenador y socio es `FOR NO KEY UPDATE` ({@see self::ROW_MUTEX}). Dos
 * reservas a la vez del mismo entrenador se ordenan: la segunda ve la primera y
 * recibe 409 `slot_taken`. En PostgreSQL la restricción EXCLUDE es la segunda
 * puerta, y su 23P01 se traduce al mismo `slot_taken`. Cancelar y las
 * transiciones solo bloquean la cita (no toman entrenador ni socio), así que no
 * pueden cruzarse en un interbloqueo con una reprogramación.
 *
 * IDEMPOTENCIA. Crear con el mismo `client_request_id` y el mismo actor y datos
 * devuelve la misma cita (200); con otro actor u otros datos, 409
 * `request_conflict`. Se comprueba antes y otra vez con el entrenador bloqueado
 * (el doble clic llega a la vez).
 *
 * CONCURRENCIA OPTIMISTA. Mover, cancelar, editar y cambiar de estado exigen
 * `expected_version`; si la cita cambió, 409 `appointment_changed` con la cita
 * como está. Repetir una transición al estado en que ya está (o una
 * reprogramación a donde ya está) es 200 con `changed:false`, para que el doble
 * clic no se convierta en un error.
 *
 * Cada cambio deja su evento en el historial append-only, su traza en
 * `audit_logs` (AuditTrail: actor, IP, sin datos del socio) y replanifica los
 * avisos en la MISMA transacción. Los avisos inmediatos de la bandeja del CRM
 * salen después de confirmar la transacción y nunca la rompen.
 */
final class ValuationAgenda
{
    /** Se puede iniciar desde 15 minutos antes de la hora. */
    public const START_EARLY_MINUTES = 15;

    /**
     * El cerrojo de las filas de `trainers` y `members` en PostgreSQL. Es un
     * MUTEX de la agenda, no una escritura: `FOR NO KEY UPDATE` choca consigo
     * mismo (serializa la agenda igual) pero NO con el `FOR KEY SHARE` que toma
     * la comprobación de FK de cualquier INSERT que apunte a esa fila. Con
     * `FOR UPDATE`, un INSERT en `physical_evaluations` (FK a socio y a
     * entrenador) esperaba a la agenda y podía interbloquearse con ella
     * (revisión de BD I1). SQLite no tiene cerrojos de fila: allí no se emite.
     */
    public const ROW_MUTEX = 'for no key update';

    private const ACTIONS = [
        'confirm' => [ValuationAppointment::STATUS_CONFIRMED, ValuationAppointmentEvent::ACTION_CONFIRMED, 'confirmed_at', 'Confirmó la valoración'],
        'start' => [ValuationAppointment::STATUS_IN_PROGRESS, ValuationAppointmentEvent::ACTION_STARTED, 'started_at', 'Inició la valoración'],
        'complete' => [ValuationAppointment::STATUS_COMPLETED, ValuationAppointmentEvent::ACTION_COMPLETED, 'completed_at', 'Completó la valoración'],
        'no_show' => [ValuationAppointment::STATUS_NO_SHOW, ValuationAppointmentEvent::ACTION_NO_SHOW, 'no_show_at', 'Marcó que el socio no asistió'],
    ];

    /** Desde qué estados se llega a cada uno. */
    private const FROM = [
        ValuationAppointment::STATUS_CONFIRMED => [ValuationAppointment::STATUS_SCHEDULED],
        ValuationAppointment::STATUS_IN_PROGRESS => [ValuationAppointment::STATUS_SCHEDULED, ValuationAppointment::STATUS_CONFIRMED],
        ValuationAppointment::STATUS_COMPLETED => [ValuationAppointment::STATUS_SCHEDULED, ValuationAppointment::STATUS_CONFIRMED, ValuationAppointment::STATUS_IN_PROGRESS],
        ValuationAppointment::STATUS_NO_SHOW => [ValuationAppointment::STATUS_SCHEDULED, ValuationAppointment::STATUS_CONFIRMED],
        ValuationAppointment::STATUS_CANCELLED => [ValuationAppointment::STATUS_SCHEDULED, ValuationAppointment::STATUS_CONFIRMED],
    ];

    public function __construct(
        private readonly ValuationAvailability $availability,
        private readonly ValuationSettings $settings,
        private readonly ValuationReminders $reminders,
        private readonly ValuationNotifier $notifier,
        private readonly ValuationPresenter $presenter,
        private readonly AuditTrail $audit,
    ) {}

    // ── Crear ───────────────────────────────────────────────────────────────

    /**
     * @param  array{client_request_id: string, member_id: int, trainer_id: int, type: string, starts_at: string, duration_minutes?: int|null, notes?: string|null, allow_past?: bool|null, past_reason?: string|null}  $datos
     * @return array{appointment: ValuationAppointment, created: bool}
     */
    public function create(ValuationAccess $access, array $datos, Request $request): array
    {
        $access->require(ValuationAccess::CREATE);
        $trainerId = (int) $datos['trainer_id'];
        $memberId = (int) $datos['member_id'];
        $access->requireCoversTrainer($trainerId);

        $ajustes = $this->settings->current();
        $duracion = (int) ($datos['duration_minutes'] ?? $ajustes->default_duration_minutes);
        $inicio = ValuationTime::parseInstant((string) $datos['starts_at']);
        $this->exigirMinutoExacto($inicio);
        $fin = $inicio->copy()->addMinutes($duracion);
        $idSolicitud = strtolower((string) $datos['client_request_id']);

        $firma = [
            'member_id' => $memberId, 'trainer_id' => $trainerId, 'type' => (string) $datos['type'],
            'starts_at' => $inicio, 'duration_minutes' => $duracion,
        ];

        // La repetición se reconoce antes de nada: un doble clic no tiene por
        // qué volver a pasar las reglas de hora (puede que ya esté «en el pasado»).
        if ($repetida = $this->repeticion($idSolicitud, $access->actor, $firma)) {
            return ['appointment' => $repetida, 'created' => false];
        }

        $socio = Member::query()->whereKey($memberId)->whereNull('anonymized_at')->first();
        if ($socio === null) {
            throw ValuationException::memberNotFound();
        }

        $pasado = (bool) ($datos['allow_past'] ?? false);
        $this->reglasDeHora($inicio, $ajustes, $pasado && $access->can(ValuationAccess::MANAGE_SETTINGS));
        $motivoPasado = $pasado && $inicio->lt(Carbon::now()->utc()) ? trim((string) ($datos['past_reason'] ?? '')) : null;

        try {
            $resultado = DB::transaction(function () use ($access, $datos, $request, $ajustes, $trainerId, $memberId, $inicio, $fin, $duracion, $idSolicitud, $firma, $motivoPasado): array {
                $this->bloquearEntrenadores([$trainerId]);
                $this->bloquearSocio($memberId);

                // Con el entrenador bloqueado: el otro clic ya terminó o espera.
                if ($repetida = $this->repeticion($idSolicitud, $access->actor, $firma)) {
                    return ['appointment' => $repetida, 'created' => false];
                }

                $this->exigirEntrenadorActivo($trainerId);
                $this->availability->assertBookable($trainerId, $memberId, $inicio, $fin, $ajustes);

                $cita = ValuationAppointment::create([
                    'client_request_id' => $idSolicitud,
                    'member_id' => $memberId,
                    'trainer_id' => $trainerId,
                    'type' => $datos['type'],
                    'status' => ValuationAppointment::STATUS_SCHEDULED,
                    'starts_at' => $inicio->copy()->utc(),
                    'ends_at' => $fin->copy()->utc(),
                    'duration_minutes' => $duracion,
                    'notes' => self::texto($datos['notes'] ?? null),
                    'source' => ValuationAppointment::SOURCE_CRM,
                    'version' => 1,
                    'created_by_admin_id' => $access->actor->id,
                    'created_by_name' => $access->actor->name,
                ]);

                $this->evento($cita, ValuationAppointmentEvent::ACTION_CREATED, $access->actor, [
                    'to_status' => $cita->status,
                    'to_starts_at' => $cita->starts_at,
                    'to_trainer_id' => $cita->trainer_id,
                    'reason' => $motivoPasado,
                    'metadata' => array_filter([
                        'type' => $cita->type,
                        'duration_minutes' => $duracion,
                        'allow_past' => $motivoPasado !== null ? true : null,
                    ]),
                ]);

                $this->reminders->plan($cita, $ajustes, ValuationReminder::KIND_CONFIRMATION);

                $this->audit->record($request, [
                    'action' => 'create', 'module' => 'Valoraciones', 'entity' => 'cita_valoracion',
                    'entity_id' => $cita->uuid,
                    'summary' => $motivoPasado !== null ? 'Agendó una valoración en el pasado' : 'Agendó una valoración',
                    'metadata' => [
                        'trainer_id' => $trainerId, 'member_id' => $memberId, 'type' => $cita->type,
                        'starts_at' => $cita->starts_at->copy()->utc()->toIso8601String(),
                        'duration_minutes' => $duracion,
                    ],
                ]);

                return ['appointment' => $cita, 'created' => true];
            });
        } catch (UniqueConstraintViolationException $e) {
            // Dos clics que llegaron a insertar a la vez (sin bloqueo que los
            // ordenara, p. ej. en SQLite): el segundo choca con el único de
            // `client_request_id`. Es la misma cita, o un conflicto.
            if ($repetida = $this->repeticion($idSolicitud, $access->actor, $firma)) {
                return ['appointment' => $repetida, 'created' => false];
            }
            throw $e;
        } catch (QueryException $e) {
            throw $this->traducir($e);
        }

        if ($resultado['created']) {
            $this->notifier->internalEvent($resultado['appointment'], 'created');
        }

        return $resultado;
    }

    // ── Editar (sin hora) ───────────────────────────────────────────────────

    /**
     * @param  array{expected_version: int, type?: string, notes?: string|null}  $datos
     * @return array{appointment: ValuationAppointment, changed: bool}
     */
    public function update(ValuationAccess $access, string $uuid, array $datos, Request $request): array
    {
        $access->require(ValuationAccess::UPDATE);
        $this->cargar($access, $uuid);

        return DB::transaction(function () use ($access, $uuid, $datos, $request): array {
            $cita = $this->bloquearCita($access, $uuid);
            $this->exigirVersion($cita, (int) $datos['expected_version'], $access);

            $cambios = [];
            if (array_key_exists('type', $datos) && $datos['type'] !== null && $datos['type'] !== $cita->type) {
                $cambios['type'] = ['from' => $cita->type, 'to' => $datos['type']];
                $cita->type = $datos['type'];
            }
            if (array_key_exists('notes', $datos)) {
                $notas = self::texto($datos['notes']);
                if ($notas !== $cita->notes) {
                    $cambios['notes'] = null; // el contenido no va al historial
                    $cita->notes = $notas;
                }
            }

            if ($cambios === []) {
                return ['appointment' => $cita, 'changed' => false];
            }

            // Editar notas o tipo NO sube la versión (contrato §1: sube con la
            // hora, el entrenador o el estado): los avisos ya planificados son
            // de esta versión y siguen valiendo.
            $cita->save();

            $this->evento($cita, ValuationAppointmentEvent::ACTION_UPDATED, $access->actor, [
                'metadata' => [
                    'fields' => array_keys($cambios),
                    'type' => $cambios['type'] ?? null,
                ],
            ]);

            $this->audit->record($request, [
                'action' => 'update', 'module' => 'Valoraciones', 'entity' => 'cita_valoracion',
                'entity_id' => $cita->uuid, 'summary' => 'Editó la valoración',
                'changes' => array_map(
                    fn ($campo) => $campo === 'type'
                        ? ['field' => 'type', 'from' => $cambios['type']['from'], 'to' => $cambios['type']['to']]
                        : ['field' => $campo],
                    array_keys($cambios),
                ),
            ]);

            return ['appointment' => $cita, 'changed' => true];
        });
    }

    // ── Reprogramar ─────────────────────────────────────────────────────────

    /**
     * @param  array{expected_version: int, starts_at: string, duration_minutes?: int|null, trainer_id?: int|null, reason?: string|null}  $datos
     * @return array{appointment: ValuationAppointment, changed: bool}
     */
    public function reschedule(ValuationAccess $access, string $uuid, array $datos, Request $request): array
    {
        $access->require(ValuationAccess::UPDATE);
        $actual = $this->cargar($access, $uuid);

        $destino = (int) ($datos['trainer_id'] ?? $actual->trainer_id);
        $access->requireCoversTrainer($destino);

        $ajustes = $this->settings->current();
        $duracion = (int) ($datos['duration_minutes'] ?? $actual->duration_minutes);
        $inicio = ValuationTime::parseInstant((string) $datos['starts_at']);
        $this->exigirMinutoExacto($inicio);
        $fin = $inicio->copy()->addMinutes($duracion);

        $mismoSitio = fn (ValuationAppointment $c): bool => $c->starts_at->copy()->utc()->equalTo($inicio)
            && (int) $c->trainer_id === $destino
            && (int) $c->duration_minutes === $duracion;

        // Reenviar donde ya está no es moverla (doble clic): ni reglas de hora.
        if ($actual->isOpen() && $mismoSitio($actual)) {
            return ['appointment' => $actual, 'changed' => false];
        }

        $this->reglasDeHora($inicio, $ajustes, false);

        try {
            $r = DB::transaction(function () use ($access, $uuid, $datos, $request, $ajustes, $actual, $destino, $duracion, $inicio, $fin, $mismoSitio): array {
                $this->bloquearEntrenadores([(int) $actual->trainer_id, $destino]);
                $this->bloquearSocio((int) $actual->member_id);
                $cita = $this->bloquearCita($access, $uuid);

                if ($cita->isOpen() && $mismoSitio($cita)) {
                    return ['appointment' => $cita, 'changed' => false];
                }
                $this->exigirVersion($cita, (int) $datos['expected_version'], $access);
                if (! $cita->isOpen()) {
                    throw ValuationException::invalidTransition(self::estado($cita->status), 'reprogramar');
                }

                $this->exigirEntrenadorActivo($destino);
                $this->availability->assertBookable($destino, (int) $cita->member_id, $inicio, $fin, $ajustes, (int) $cita->id);

                $antes = [
                    'status' => $cita->status,
                    'starts_at' => $cita->starts_at->copy()->utc(),
                    'trainer_id' => (int) $cita->trainer_id,
                    'duration_minutes' => (int) $cita->duration_minutes,
                ];

                $cita->forceFill([
                    'trainer_id' => $destino,
                    'starts_at' => $inicio->copy()->utc(),
                    'ends_at' => $fin->copy()->utc(),
                    'duration_minutes' => $duracion,
                    'status' => ValuationAppointment::STATUS_SCHEDULED,
                    'confirmed_at' => null,
                    'version' => (int) $cita->version + 1,
                ])->save();

                $motivo = self::texto($datos['reason'] ?? null);
                $this->evento($cita, ValuationAppointmentEvent::ACTION_RESCHEDULED, $access->actor, [
                    'from_status' => $antes['status'],
                    'to_status' => $cita->status,
                    'from_starts_at' => $antes['starts_at'],
                    'to_starts_at' => $cita->starts_at,
                    'from_trainer_id' => $antes['trainer_id'],
                    'to_trainer_id' => $destino,
                    'reason' => $motivo !== null ? mb_substr($motivo, 0, 500) : null,
                    'metadata' => array_filter([
                        'from_duration_minutes' => $antes['duration_minutes'] !== $duracion ? $antes['duration_minutes'] : null,
                        'to_duration_minutes' => $antes['duration_minutes'] !== $duracion ? $duracion : null,
                    ]),
                ]);

                $this->reminders->cancelWaiting($cita);
                $this->reminders->plan($cita, $ajustes, ValuationReminder::KIND_RESCHEDULED);

                $this->audit->record($request, [
                    'action' => 'update', 'module' => 'Valoraciones', 'entity' => 'cita_valoracion',
                    'entity_id' => $cita->uuid, 'summary' => 'Reprogramó la valoración',
                    'changes' => array_values(array_filter([
                        ['field' => 'starts_at', 'from' => $antes['starts_at']->toIso8601String(), 'to' => $cita->starts_at->copy()->utc()->toIso8601String()],
                        $antes['trainer_id'] !== $destino ? ['field' => 'trainer_id', 'from' => $antes['trainer_id'], 'to' => $destino] : null,
                        $antes['duration_minutes'] !== $duracion ? ['field' => 'duration_minutes', 'from' => $antes['duration_minutes'], 'to' => $duracion] : null,
                        $antes['status'] !== $cita->status ? ['field' => 'status', 'from' => $antes['status'], 'to' => $cita->status] : null,
                    ])),
                ]);

                return ['appointment' => $cita, 'changed' => true];
            });
        } catch (QueryException $e) {
            throw $this->traducir($e);
        }

        if ($r['changed']) {
            $this->notifier->internalEvent($r['appointment'], 'rescheduled');
        }

        return $r;
    }

    // ── Cancelar ────────────────────────────────────────────────────────────

    /** @return array{appointment: ValuationAppointment, changed: bool} */
    public function cancel(ValuationAccess $access, string $uuid, int $expectedVersion, string $motivo, Request $request): array
    {
        $access->require(ValuationAccess::CANCEL);
        $this->cargar($access, $uuid);

        $r = DB::transaction(function () use ($access, $uuid, $expectedVersion, $motivo, $request): array {
            $cita = $this->bloquearCita($access, $uuid);
            if ($cita->status === ValuationAppointment::STATUS_CANCELLED) {
                return ['appointment' => $cita, 'changed' => false];
            }
            $this->exigirVersion($cita, $expectedVersion, $access);
            if (! in_array($cita->status, self::FROM[ValuationAppointment::STATUS_CANCELLED], true)) {
                throw ValuationException::invalidTransition(self::estado($cita->status), 'cancelar');
            }

            $desde = $cita->status;
            $cita->forceFill([
                'status' => ValuationAppointment::STATUS_CANCELLED,
                'cancelled_at' => Carbon::now()->utc(),
                'cancelled_by_admin_id' => $access->actor->id,
                'cancellation_reason' => mb_substr(trim($motivo), 0, 500),
                'version' => (int) $cita->version + 1,
            ])->save();

            $this->evento($cita, ValuationAppointmentEvent::ACTION_CANCELLED, $access->actor, [
                'from_status' => $desde,
                'to_status' => $cita->status,
                'reason' => $cita->cancellation_reason,
            ]);

            $this->reminders->cancelWaiting($cita);
            $this->reminders->planCancellation($cita);

            $this->audit->record($request, [
                'action' => 'status', 'module' => 'Valoraciones', 'entity' => 'cita_valoracion',
                'entity_id' => $cita->uuid, 'summary' => 'Canceló la valoración',
                'changes' => [['field' => 'status', 'from' => $desde, 'to' => $cita->status]],
            ]);

            return ['appointment' => $cita, 'changed' => true];
        });

        if ($r['changed']) {
            $this->notifier->internalEvent($r['appointment'], 'cancelled');
        }

        return $r;
    }

    // ── Confirmar, iniciar, completar, no asistió ───────────────────────────

    /**
     * @param  'confirm'|'start'|'complete'|'no_show'  $accion
     * @return array{appointment: ValuationAppointment, changed: bool}
     */
    public function transition(ValuationAccess $access, string $uuid, string $accion, int $expectedVersion, ?string $nota, Request $request): array
    {
        [$hacia, $tipoEvento, $columna, $resumen] = self::ACTIONS[$accion];
        $access->require(ValuationAccess::COMPLETE);
        $this->cargar($access, $uuid);

        $r = DB::transaction(function () use ($access, $uuid, $accion, $hacia, $tipoEvento, $columna, $resumen, $expectedVersion, $nota, $request): array {
            $cita = $this->bloquearCita($access, $uuid);
            if ($cita->status === $hacia) {
                return ['appointment' => $cita, 'changed' => false];
            }
            $this->exigirVersion($cita, $expectedVersion, $access);
            if (! in_array($cita->status, self::FROM[$hacia], true)) {
                throw ValuationException::invalidTransition(self::estado($cita->status), self::verbo($accion));
            }

            $ahora = Carbon::now()->utc();
            $inicio = $cita->starts_at->copy()->utc();
            match ($accion) {
                'confirm' => $ahora->lt($inicio) ?: throw ValuationException::tooLate('La valoración ya empezó: no se puede confirmar. Iníciala, complétala o marca que no asistió.'),
                'start' => $ahora->gte($inicio->copy()->subMinutes(self::START_EARLY_MINUTES)) ?: throw ValuationException::notDue('Todavía no: se puede iniciar desde 15 minutos antes de la hora.'),
                'complete' => $ahora->gte($inicio) ?: throw ValuationException::notDue('Todavía no: una valoración se completa a partir de su hora.'),
                'no_show' => $ahora->gte($inicio) ?: throw ValuationException::notDue('Todavía no: la ausencia se marca a partir de la hora de la cita.'),
            };

            $desde = $cita->status;
            $versionAnterior = (int) $cita->version;
            $cita->forceFill([
                'status' => $hacia,
                $columna => $ahora,
                'outcome_by_admin_id' => $access->actor->id,
                'version' => $versionAnterior + 1,
            ])->save();

            $nota = self::texto($nota);
            $this->evento($cita, $tipoEvento, $access->actor, [
                'from_status' => $desde,
                'to_status' => $hacia,
                'reason' => $nota !== null ? mb_substr($nota, 0, 500) : null,
            ]);

            if (in_array($hacia, ValuationAppointment::TERMINAL_STATUSES, true)) {
                // Cerrada: ningún recordatorio posterior tiene sentido.
                $this->reminders->cancelWaiting($cita);
            } else {
                // La hora no cambió: los avisos siguen valiendo con la versión nueva.
                $this->reminders->carryForward($cita, $versionAnterior);
            }

            $this->audit->record($request, [
                'action' => 'status', 'module' => 'Valoraciones', 'entity' => 'cita_valoracion',
                'entity_id' => $cita->uuid, 'summary' => $resumen,
                'changes' => [['field' => 'status', 'from' => $desde, 'to' => $hacia]],
            ]);

            return ['appointment' => $cita, 'changed' => true];
        });

        if ($r['changed'] && $accion === 'confirm') {
            $this->notifier->internalEvent($r['appointment'], 'confirmed');
        }

        return $r;
    }

    // ── Lectura con alcance ─────────────────────────────────────────────────

    /**
     * La cita por uuid, si está en el alcance de quien pregunta. Fuera del
     * alcance responde como si no existiera.
     *
     * @throws ValuationException 403|404
     */
    public function cargar(ValuationAccess $access, string $uuid): ValuationAppointment
    {
        $access->scopedTrainerId();
        $cita = ValuationAppointment::query()->where('uuid', strtolower($uuid))->first();
        if ($cita === null) {
            throw ValuationException::notFound();
        }
        $access->requireCovers($cita);

        return $cita;
    }

    // ── Internos ────────────────────────────────────────────────────────────

    /**
     * Reglas 3–5: pasado, antelación mínima y horizonte. `$pasadoPermitido`
     * solo lo es con `manage_settings` y motivo (lo validó el controlador).
     */
    private function reglasDeHora(Carbon $inicio, ValuationSetting $ajustes, bool $pasadoPermitido): void
    {
        $ahora = Carbon::now()->utc();
        $enElPasado = $inicio->lt($ahora);

        if ($enElPasado && ! $pasadoPermitido) {
            throw ValuationException::inPast();
        }
        if (! $enElPasado && $inicio->lt($ahora->copy()->addMinutes((int) $ajustes->min_notice_minutes))) {
            throw ValuationException::tooSoon((int) $ajustes->min_notice_minutes);
        }
        // El último día que se puede agendar es hoy (Bogotá) + N días, incluido.
        $ultimoDia = ValuationTime::today()->addDays((int) $ajustes->max_horizon_days)->toDateString();
        if (ValuationTime::local($inicio)->toDateString() > $ultimoDia) {
            throw ValuationException::beyondHorizon((int) $ajustes->max_horizon_days);
        }
    }

    private function exigirMinutoExacto(Carbon $inicio): void
    {
        if ($inicio->second !== 0 || $inicio->micro !== 0) {
            throw new ValuationException('La hora de inicio va en minutos exactos (sin segundos).', 'validation_failed', 422, [
                'errors' => ['starts_at' => ['La hora de inicio va en minutos exactos (sin segundos).']],
            ]);
        }
    }

    private function exigirEntrenadorActivo(int $trainerId): void
    {
        $entrenador = Trainer::query()->find($trainerId);
        if ($entrenador === null || ! $entrenador->isActive()) {
            throw ValuationException::trainerInactive();
        }
    }

    private function exigirVersion(ValuationAppointment $cita, int $esperada, ValuationAccess $access): void
    {
        if ((int) $cita->version !== $esperada) {
            throw ValuationException::changed($this->presenter->one($cita->fresh(), $access));
        }
    }

    /**
     * Entrenadores en orden de id (siempre el mismo orden: dos operaciones que
     * toman los mismos dos entrenadores no pueden esperarse en cruz).
     *
     * @param  list<int>  $ids
     */
    private function bloquearEntrenadores(array $ids): void
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);
        foreach ($ids as $id) {
            DB::table('trainers')->where('id', $id)->lock(self::rowMutex())->first(['id']);
        }
    }

    /** Por la tabla y no por el modelo: el alcance de entrenador no aplica a un cerrojo. */
    private function bloquearSocio(int $memberId): void
    {
        DB::table('members')->where('id', $memberId)->lock(self::rowMutex())->first(['id']);
    }

    /** `FOR NO KEY UPDATE` en PostgreSQL; en otro motor, el `FOR UPDATE` de siempre. */
    private static function rowMutex(): string|bool
    {
        return DB::connection()->getDriverName() === 'pgsql' ? self::ROW_MUTEX : true;
    }

    /**
     * La cita bloqueada, y OTRA VEZ en el alcance: entre la lectura previa y el
     * bloqueo alguien pudo pasarla a otro entrenador, y quien solo ve lo suyo no
     * puede operar lo que ya no es suyo.
     */
    private function bloquearCita(ValuationAccess $access, string $uuid): ValuationAppointment
    {
        $cita = ValuationAppointment::query()->where('uuid', strtolower($uuid))->lockForUpdate()->first();
        if ($cita === null) {
            throw ValuationException::notFound();
        }
        $access->requireCovers($cita);

        return $cita;
    }

    /**
     * ¿Es una repetición de una creación ya hecha? Misma cita (mismo actor y
     * datos) o conflicto.
     *
     * @param  array{member_id: int, trainer_id: int, type: string, starts_at: Carbon, duration_minutes: int}  $firma
     */
    private function repeticion(string $idSolicitud, Admin $actor, array $firma): ?ValuationAppointment
    {
        $cita = ValuationAppointment::query()->where('client_request_id', $idSolicitud)->first();
        if ($cita === null) {
            return null;
        }

        $igual = (int) $cita->created_by_admin_id === (int) $actor->id
            && (int) $cita->member_id === $firma['member_id']
            && (int) $cita->trainer_id === $firma['trainer_id']
            && $cita->type === $firma['type']
            && $cita->starts_at->copy()->utc()->equalTo($firma['starts_at'])
            && (int) $cita->duration_minutes === $firma['duration_minutes'];

        if (! $igual) {
            throw ValuationException::requestConflict();
        }

        return $cita;
    }

    /** @param  array<string,mixed>  $datos */
    private function evento(ValuationAppointment $cita, string $accion, ?Admin $actor, array $datos): void
    {
        ValuationAppointmentEvent::create(array_merge([
            'appointment_id' => $cita->id,
            'action' => $accion,
            'actor_admin_id' => $actor?->id,
            'actor_name' => $actor?->name,
            'created_at' => Carbon::now()->utc(),
        ], array_map(
            fn ($v) => $v instanceof Carbon ? $v->copy()->utc() : $v,
            $datos,
        )));
    }

    /**
     * El 23P01 de la restricción EXCLUDE (PostgreSQL) es una hora ocupada: la
     * misma respuesta que da la comprobación del servicio.
     */
    private function traducir(QueryException $e): \Throwable
    {
        $estado = $e->errorInfo[0] ?? $e->getCode();
        if ((string) $estado === '23P01') {
            return ValuationException::slotTaken();
        }

        return $e;
    }

    private static function texto(mixed $valor): ?string
    {
        if ($valor === null) {
            return null;
        }
        $limpio = trim((string) $valor);

        return $limpio === '' ? null : $limpio;
    }

    private static function estado(string $status): string
    {
        return mb_strtolower(ValuationAppointment::STATUS_LABELS[$status] ?? $status);
    }

    private static function verbo(string $accion): string
    {
        return match ($accion) {
            'confirm' => 'confirmar',
            'start' => 'iniciar',
            'complete' => 'completar',
            'no_show' => 'marcar que no asistió',
            default => $accion,
        };
    }

    /** Para pruebas y el importador: ejecuta con el mismo cerrojo que crear. */
    public function withTrainerAndMemberLocks(array $trainerIds, array $memberIds, Closure $fn): mixed
    {
        $this->bloquearEntrenadores($trainerIds);
        $memberIds = array_values(array_unique(array_map('intval', $memberIds)));
        sort($memberIds);
        foreach ($memberIds as $id) {
            $this->bloquearSocio($id);
        }

        return $fn();
    }
}
