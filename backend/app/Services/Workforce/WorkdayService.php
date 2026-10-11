<?php

namespace App\Services\Workforce;

use App\Exceptions\WorkforceException;
use App\Models\AuthorizedDevice;
use App\Models\Identity;
use App\Models\StaffProfile;
use App\Models\Trainer;
use App\Models\Workday;
use App\Models\WorkdayEvent;
use App\Models\WorkforceKioskToken;
use App\Support\Workforce\Positions;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * La jornada laboral: entrar, salir, saber cómo está, corregir incidencias.
 *
 * Reglas (las decide el servidor, nunca la tablet):
 *  · La hora oficial es `now()` del servidor. La tablet no manda horas.
 *  · Una persona no puede tener dos jornadas abiertas: lo garantiza el índice
 *    único parcial de la base, no un SELECT previo.
 *  · El mismo `operation_id` no crea dos movimientos: un reintento recibe el
 *    resultado original (`replayed`).
 *  · `business_date` es el día del gimnasio (Bogotá) de la ENTRADA: una
 *    jornada que cruza la medianoche es del día en que empezó.
 *  · Salida olvidada: una jornada abierta más de `stale_after_hours` pasa a
 *    incidencia «salida no registrada» SIN inventar hora de salida, y la
 *    persona puede entrar de nuevo. La corrige un administrador.
 *  · Cerrar la pantalla o la sesión NO cierra la jornada. Nada la cierra salvo
 *    la persona (con su identidad verificada) o un administrador con motivo.
 */
class WorkdayService
{
    public const TZ = 'America/Bogota';

    public function __construct(
        private readonly WorkforceEvents $events,
        private readonly EmployeeDirectory $directory,
        private readonly KioskTokens $tokens,
    ) {}

    // ── Consulta ────────────────────────────────────────────────────────────

    public function businessDate(?\DateTimeInterface $at = null): string
    {
        return CarbonImmutable::instance($at ?? now())->setTimezone(self::TZ)->toDateString();
    }

    public function isStale(Workday $workday): bool
    {
        $hours = (int) config('workforce.workday.stale_after_hours', 16);

        return $workday->isOpen() && $workday->clock_in_at->lt(now()->subHours($hours));
    }

    /**
     * Cómo está la persona: jornada abierta vigente, la última terminada y, si
     * dejó una abierta de hace demasiado, esa como incidencia pendiente.
     *
     * @return array{state:string, current:?array, last:?array, pending_incident:?array}
     */
    public function state(Identity $identity): array
    {
        $open = Workday::query()->open()->where('identity_id', $identity->getKey())->with(['clockInDevice', 'clockOutDevice'])->first();
        $last = Workday::query()
            ->where('identity_id', $identity->getKey())
            ->where('status', '!=', Workday::STATUS_OPEN)
            ->with(['clockInDevice', 'clockOutDevice'])
            ->orderByDesc('clock_in_at')
            ->first();

        if ($open !== null && $this->isStale($open)) {
            return ['state' => 'none', 'current' => null, 'last' => $last ? $this->toArray($last) : null, 'pending_incident' => $this->toArray($open)];
        }

        return [
            'state' => $open !== null ? 'open' : 'none',
            'current' => $open ? $this->toArray($open) : null,
            'last' => $last ? $this->toArray($last) : null,
            'pending_incident' => null,
        ];
    }

    /** @return list<string> */
    public function allowedActions(EmployeeProfile $profile, array $state): array
    {
        if ($state['state'] === 'open') {
            return ['clock_out'];
        }

        return $profile->registersWorkday() ? ['clock_in'] : [];
    }

    // ── Entrada y salida ────────────────────────────────────────────────────

    /** @return array{result:string, workday:array, flagged_previous:?array} */
    public function clockIn(string $verificationToken, string $operationId, AuthorizedDevice $device, Request $request): array
    {
        if ($prior = WorkdayEvent::query()->where('operation_id', $operationId)->first()) {
            return $this->replay($prior, WorkdayEvent::CLOCK_IN, $verificationToken, $device);
        }

        try {
            return DB::transaction(function () use ($verificationToken, $operationId, $device, $request): array {
                $token = $this->tokens->consume($verificationToken, WorkforceKioskToken::KIND_VERIFICATION, 'workday', $device);
                $identity = Identity::query()->findOrFail($token->identity_id);
                $profile = $this->directory->profile($identity);
                $position = Positions::workdayPosition($profile->positions);
                if (! $profile->isActive() || $position === null) {
                    throw WorkforceException::workdayNotApplicable();
                }

                $open = Workday::query()->open()->where('identity_id', $identity->getKey())->lockForUpdate()->first();
                $flagged = null;
                if ($open !== null) {
                    if (! $this->isStale($open)) {
                        throw WorkforceException::workdayAlreadyOpen($this->toArray($open));
                    }
                    $flagged = $this->flagIncident($open);
                }

                $now = now();
                $workday = Workday::create([
                    'identity_id' => $identity->getKey(),
                    'position' => $position,
                    'status' => Workday::STATUS_OPEN,
                    'business_date' => $this->businessDate($now),
                    'clock_in_at' => $now,
                    'clock_in_method' => (string) $token->method,
                    'clock_in_device_id' => $device->getKey(),
                ]);
                $this->recordEvent($workday, WorkdayEvent::CLOCK_IN, $token, $device, $operationId, $request);
                $this->events->publish(WorkforceEvents::WORKDAY_UPDATED, 'clock_in', $workday->uuid, WorkforceEvents::SOURCE_KIOSK, $workday->business_date->toDateString());

                return [
                    'result' => 'clocked_in',
                    'workday' => $this->toArray($workday->fresh(['clockInDevice', 'clockOutDevice'])),
                    'flagged_previous' => $flagged ? $this->toArray($flagged) : null,
                ];
            });
        } catch (UniqueConstraintViolationException) {
            // Dos entradas a la vez: la base dejó pasar una. Si fue este mismo
            // operation_id, es un reintento; si no, ya hay una jornada abierta.
            if ($prior = WorkdayEvent::query()->where('operation_id', $operationId)->first()) {
                return $this->replay($prior, WorkdayEvent::CLOCK_IN, $verificationToken, $device);
            }
            $identityId = $this->tokens->find($verificationToken, $device)?->identity_id;
            $open = $identityId ? Workday::query()->open()->where('identity_id', $identityId)->first() : null;

            throw $open ? WorkforceException::workdayAlreadyOpen($this->toArray($open)) : WorkforceException::verificationInvalid();
        }
    }

    /** @return array{result:string, workday:array} */
    public function clockOut(string $verificationToken, string $operationId, AuthorizedDevice $device, Request $request): array
    {
        if ($prior = WorkdayEvent::query()->where('operation_id', $operationId)->first()) {
            return $this->replay($prior, WorkdayEvent::CLOCK_OUT, $verificationToken, $device);
        }

        try {
            $outcome = DB::transaction(function () use ($verificationToken, $operationId, $device, $request): array {
                $token = $this->tokens->consume($verificationToken, WorkforceKioskToken::KIND_VERIFICATION, 'workday', $device);
                $identity = Identity::query()->findOrFail($token->identity_id);

                $open = Workday::query()->open()->where('identity_id', $identity->getKey())->lockForUpdate()->first();
                if ($open === null) {
                    return ['none', $this->lastFor($identity)];
                }
                if ($this->isStale($open)) {
                    // Cerrarla ahora daría una jornada de un día y medio: se
                    // marca como incidencia y la revisa un administrador. El
                    // marcado SÍ se confirma; el rechazo sale fuera.
                    return ['stale', $this->toArray($this->flagIncident($open))];
                }

                $now = now();
                $open->forceFill([
                    'status' => Workday::STATUS_CLOSED,
                    'clock_out_at' => $now,
                    'clock_out_method' => (string) $token->method,
                    'clock_out_device_id' => $device->getKey(),
                    'clock_out_source' => 'employee',
                    'duration_seconds' => (int) $open->clock_in_at->diffInSeconds($now, true),
                ])->save();
                $this->recordEvent($open, WorkdayEvent::CLOCK_OUT, $token, $device, $operationId, $request);
                $this->events->publish(WorkforceEvents::WORKDAY_UPDATED, 'clock_out', $open->uuid, WorkforceEvents::SOURCE_KIOSK, $open->business_date->toDateString());

                return ['closed', $this->toArray($open->fresh(['clockInDevice', 'clockOutDevice']))];
            });
        } catch (UniqueConstraintViolationException) {
            if ($prior = WorkdayEvent::query()->where('operation_id', $operationId)->first()) {
                return $this->replay($prior, WorkdayEvent::CLOCK_OUT, $verificationToken, $device);
            }
            throw WorkforceException::verificationInvalid();
        }

        [$kind, $workday] = $outcome;
        if ($kind === 'none') {
            throw WorkforceException::noOpenWorkday($workday);
        }
        if ($kind === 'stale') {
            throw new WorkforceException(
                'Tu jornada anterior quedó abierta demasiado tiempo y pasó a revisión. Un administrador registrará la hora de salida.',
                'no_open_workday',
                409,
                ['last' => $workday],
            );
        }

        return ['result' => 'clocked_out', 'workday' => $workday];
    }

    /**
     * Marca como incidencia las jornadas abiertas de más de `stale_after_hours`.
     * No cierra nada ni inventa horas. Lo usa el planificador para que «en
     * jornada» no cuente a quien se fue sin marcar.
     */
    public function flagStale(): int
    {
        $limit = now()->subHours((int) config('workforce.workday.stale_after_hours', 16));
        $count = 0;
        Workday::query()->open()->where('clock_in_at', '<', $limit)->orderBy('id')->each(function (Workday $w) use (&$count): void {
            DB::transaction(function () use ($w, &$count): void {
                $fresh = Workday::query()->whereKey($w->getKey())->lockForUpdate()->first();
                if ($fresh !== null && $this->isStale($fresh)) {
                    $this->flagIncident($fresh);
                    $count++;
                }
            });
        });

        return $count;
    }

    /**
     * Quien deja de poder registrar jornada (desactivado) con una abierta ya no
     * puede marcar la salida. No se inventa la hora: queda como incidencia para
     * que la resuelva un administrador, igual que una salida olvidada, en vez de
     * figurar «en jornada» hasta que el planificador la vea obsoleta.
     */
    public function afterProfileDeactivated(Identity $identity): ?Workday
    {
        $profile = $this->directory->profile($identity);
        if ($profile->isActive() && Positions::workdayPosition($profile->positions) !== null) {
            return null;
        }

        return DB::transaction(function () use ($identity): ?Workday {
            $open = Workday::query()->open()->where('identity_id', $identity->getKey())->lockForUpdate()->first();

            return $open ? $this->flagIncident($open, Workday::INCIDENT_PROFILE_DEACTIVATED) : null;
        });
    }

    // ── Incidencias ─────────────────────────────────────────────────────────

    public function resolve(Workday $workday, string $action, ?string $clockOutAt, string $note, ?int $adminId, string $adminName, Request $request): Workday
    {
        return DB::transaction(function () use ($workday, $action, $clockOutAt, $note, $adminId, $adminName, $request): Workday {
            $w = Workday::query()->whereKey($workday->getKey())->lockForUpdate()->firstOrFail();
            if ($w->status !== Workday::STATUS_INCIDENT || $w->resolved_at !== null) {
                throw WorkforceException::workdayNotIncident();
            }

            $now = now();
            $changes = [
                'resolved_at' => $now,
                'resolved_by_admin_id' => $adminId,
                'resolved_by_name' => mb_substr($adminName, 0, 120),
                'resolution_note' => mb_substr($note, 0, 500),
            ];

            $out = null;
            if ($action === Workday::RESOLUTION_SET_CLOCK_OUT) {
                $out = $clockOutAt !== null ? Carbon::parse($clockOutAt)->utc() : null;
                if ($out === null || $out->lte($w->clock_in_at) || $out->gt($now)) {
                    throw WorkforceException::clockOutInvalid('La hora de salida debe ser posterior a la entrada y no puede estar en el futuro.');
                }
                if ($w->clock_in_at->diffInHours($out, true) > 24) {
                    throw WorkforceException::clockOutInvalid('Una jornada no puede durar más de 24 horas.');
                }
                $changes += [
                    'status' => Workday::STATUS_CLOSED,
                    'resolution' => Workday::RESOLUTION_SET_CLOCK_OUT,
                    'clock_out_at' => $out,
                    'clock_out_method' => 'admin',
                    'clock_out_source' => 'admin_correction',
                    'duration_seconds' => (int) $w->clock_in_at->diffInSeconds($out, true),
                ];
            } else {
                $changes += ['resolution' => Workday::RESOLUTION_DISMISSED];
            }
            $w->forceFill($changes)->save();

            WorkdayEvent::create([
                'workday_id' => $w->id,
                'identity_id' => $w->identity_id,
                'type' => WorkdayEvent::INCIDENT_RESOLVED,
                'occurred_at' => $now,
                'actor_type' => WorkdayEvent::ACTOR_ADMIN,
                'actor_id' => $adminId,
                'actor_name' => mb_substr($adminName, 0, 120),
                'ip_address' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
                'metadata' => ['action' => $action, 'clock_out_at' => $out?->toIso8601String(), 'note' => $note],
                'created_at' => $now,
            ]);
            $this->events->publish(WorkforceEvents::WORKDAY_UPDATED, 'resolved', $w->uuid, WorkforceEvents::SOURCE_CRM, $w->business_date->toDateString());

            return $w->fresh(['clockInDevice', 'clockOutDevice']);
        });
    }

    // ── Presentación ────────────────────────────────────────────────────────

    /** El objeto `Workday` del contrato. */
    public function toArray(Workday $w): array
    {
        // Las rutas del kiosko exigen firma: el dispositivo de una jornada
        // siempre está verificado.
        $device = fn (?AuthorizedDevice $d): ?array => $d ? $d->toBrief() + ['verified' => true] : null;

        return [
            'id' => $w->uuid,
            'status' => $w->status,
            'business_date' => $w->business_date?->toDateString(),
            'clock_in_at' => $w->clock_in_at?->toIso8601String(),
            'clock_out_at' => $w->clock_out_at?->toIso8601String(),
            'duration_seconds' => $w->duration_seconds,
            'position' => ['key' => $w->position, 'label' => Positions::label((string) $w->position)],
            'clock_in' => ['method' => $w->clock_in_method, 'device' => $device($w->clockInDevice)],
            'clock_out' => $w->clock_out_at !== null
                ? ['method' => $w->clock_out_method, 'device' => $device($w->clockOutDevice), 'source' => $w->clock_out_source]
                : null,
            'incident' => $w->incident_reason !== null ? [
                'reason' => $w->incident_reason,
                'label' => Workday::INCIDENT_LABELS[$w->incident_reason] ?? $w->incident_reason,
                'flagged_at' => $w->incident_at?->toIso8601String(),
                'resolved_at' => $w->resolved_at?->toIso8601String(),
                'resolution' => $w->resolution,
                'resolved_by' => $w->resolved_by_name,
                'note' => $w->resolution_note,
            ] : null,
        ];
    }

    /**
     * Nombre de cada persona de una página de jornadas, en tres consultas.
     *
     * @param  Collection<int, Workday>  $workdays
     * @return array<int, array{identity_id:string, full_name:string}>
     */
    public function employeesFor(Collection $workdays): array
    {
        $ids = $workdays->pluck('identity_id')->unique()->values();
        $uuids = Identity::query()->whereIn('id', $ids)->pluck('uuid', 'id');
        $staff = StaffProfile::query()->whereIn('identity_id', $ids)->pluck('full_name', 'identity_id');
        $trainers = Trainer::query()->whereIn('identity_id', $ids)->orderBy('id')->get(['identity_id', 'full_name'])
            ->unique('identity_id')->pluck('full_name', 'identity_id');

        $out = [];
        foreach ($ids as $id) {
            $out[(int) $id] = [
                'identity_id' => (string) ($uuids[$id] ?? ''),
                'full_name' => (string) ($staff[$id] ?? $trainers[$id] ?? ''),
            ];
        }

        return $out;
    }

    // ── Interno ─────────────────────────────────────────────────────────────

    private function flagIncident(Workday $open, string $reason = Workday::INCIDENT_MISSING_CLOCK_OUT): Workday
    {
        $now = now();
        $open->forceFill([
            'status' => Workday::STATUS_INCIDENT,
            'incident_reason' => $reason,
            'incident_at' => $now,
        ])->save();
        WorkdayEvent::create([
            'workday_id' => $open->id,
            'identity_id' => $open->identity_id,
            'type' => WorkdayEvent::INCIDENT_FLAGGED,
            'occurred_at' => $now,
            'actor_type' => WorkdayEvent::ACTOR_SYSTEM,
            'metadata' => ['reason' => $reason],
            'created_at' => $now,
        ]);
        $this->events->publish(WorkforceEvents::WORKDAY_UPDATED, 'incident', $open->uuid, WorkforceEvents::SOURCE_SYSTEM, $open->business_date->toDateString());

        return $open->fresh(['clockInDevice', 'clockOutDevice']);
    }

    private function recordEvent(Workday $workday, string $type, WorkforceKioskToken $token, AuthorizedDevice $device, string $operationId, Request $request): void
    {
        WorkdayEvent::create([
            'workday_id' => $workday->id,
            'identity_id' => $workday->identity_id,
            'type' => $type,
            'occurred_at' => $type === WorkdayEvent::CLOCK_IN ? $workday->clock_in_at : $workday->clock_out_at,
            'method' => $token->method,
            'face_distance' => $token->face_distance,
            'authorized_device_id' => $device->getKey(),
            'device_verified' => true,
            'actor_type' => WorkdayEvent::ACTOR_EMPLOYEE,
            'actor_id' => $workday->identity_id,
            'operation_id' => $operationId,
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
            'metadata' => ['app_version' => $device->app_version, 'platform' => $device->platform],
            'created_at' => now(),
        ]);
    }

    /** Un reintento con el mismo operation_id: el resultado original, sin repetir nada. */
    private function replay(WorkdayEvent $prior, string $expectedType, string $verificationToken, AuthorizedDevice $device): array
    {
        $token = $this->tokens->find($verificationToken, $device);
        if ($prior->type !== $expectedType || $token === null || (int) $token->identity_id !== (int) $prior->identity_id) {
            throw WorkforceException::idempotencyConflict();
        }
        $workday = Workday::query()->with(['clockInDevice', 'clockOutDevice'])->findOrFail($prior->workday_id);

        return ['result' => 'replayed', 'workday' => $this->toArray($workday), 'flagged_previous' => null];
    }

    private function lastFor(Identity $identity): ?array
    {
        $last = Workday::query()->where('identity_id', $identity->getKey())
            ->with(['clockInDevice', 'clockOutDevice'])->orderByDesc('clock_in_at')->first();

        return $last ? $this->toArray($last) : null;
    }
}
