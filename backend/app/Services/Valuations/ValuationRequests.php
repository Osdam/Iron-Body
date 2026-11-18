<?php

namespace App\Services\Valuations;

use App\Exceptions\ValuationException;
use App\Models\Member;
use App\Models\MemberTrainerAssignment;
use App\Models\Notification;
use App\Models\ValuationAppointment;
use App\Models\ValuationRequest;
use App\Services\Audit\AuditTrail;
use App\Services\NotificationService;
use App\Support\Access\TrainerMemberScope;
use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

/**
 * «SOLICITAR VALORACIÓN» (CONTRATO_SOLICITUDES_VALORACION.md §1–§3). Todas las
 * escrituras de `valuation_requests` pasan por aquí.
 *
 * UNA SOLICITUD NO ES UNA CITA. El socio la pide desde la app; administración
 * crea la cita con el asistente de siempre de la agenda y la ENLAZA aquí
 * (`schedule`), o la da por atendida sin cita (`attend`), o la rechaza con un
 * motivo que lee el socio (`reject`).
 *
 * CONCURRENCIA AL PEDIRLA. La transacción bloquea la fila del SOCIO con el
 * mismo cerrojo que la agenda ({@see ValuationAgenda::ROW_MUTEX}, `FOR NO KEY
 * UPDATE` en PostgreSQL). La agenda toma entrenadores → socio → cita; aquí solo
 * el socio, que es un prefijo de ese orden, así que no puede cruzarse en un
 * interbloqueo con ella. Con el socio bloqueado se repiten la idempotencia y las
 * dos reglas (cita futura, pendiente): dos toques a la vez dan UNA solicitud.
 * El índice único parcial `(member_id) WHERE status='pending'` es la segunda
 * puerta; su 23505 (y el de `client_request_id`) se reinterpreta FUERA de la
 * transacción como la solicitud que ya existe.
 *
 * CONCURRENCIA EN EL CRM. `schedule`, `attend` y `reject` bloquean la
 * solicitud (`FOR UPDATE`) y, `schedule`, después la cita; ningún camino de la
 * agenda toma una solicitud, así que tampoco hay ciclo. Exigen
 * `expected_version` (409 `request_changed`) y que siga pendiente (409
 * `request_not_pending`). Repetir la acción que ya se hizo (misma cita, ya
 * atendida, ya rechazada) es 200 con `changed:false`: el doble clic no es un
 * error.
 *
 * AVISOS DESPUÉS DEL COMMIT ({@see DB::afterCommit()}), idempotentes por
 * `event_key`, y nunca rompen la operación ya confirmada. Sin medidas ni datos
 * de salud: solo nombre corto, uuid y, al rechazar, el motivo escrito por
 * administración para el socio. Al socio, SIN push (`should_popup=false`).
 *
 * TRAZA. Las acciones del CRM dejan su `AuditTrail` y la propia fila
 * (`handled_by_*`). La petición del socio no tiene actor administrativo: su
 * traza es la fila (origen, id de la petición, fecha).
 */
final class ValuationRequests
{
    public const BLOCKED_PENDING = 'pending_request';

    public const BLOCKED_FUTURE = 'future_appointment';

    /** Donde la app abre la valoración (contrato §2 y §5). */
    public const ACTION_ROUTE = '/evaluation';

    /** La bandeja del CRM: el mismo permiso que los avisos de la agenda. */
    public const REQUIRED_PERMISSION = ValuationAccess::VIEW_ALL;

    private const WEEKDAYS = [
        1 => 'lunes', 2 => 'martes', 3 => 'miércoles', 4 => 'jueves',
        5 => 'viernes', 6 => 'sábado', 7 => 'domingo',
    ];

    public function __construct(
        private readonly NotificationService $notifications,
        private readonly AuditTrail $audit,
    ) {}

    // ── App del socio ───────────────────────────────────────────────────────

    /**
     * El estado del socio (contrato §2): su cita abierta futura, su solicitud
     * pendiente y si puede pedir otra. Siempre de ESTE socio.
     *
     * @return array{request: array<string,mixed>|null, next_appointment: array<string,mixed>|null, can_request: bool, blocked_reason: string|null}
     */
    public function stateFor(Member $socio): array
    {
        $id = (int) $socio->getKey();
        $cita = $this->proximaCita($id);
        $pendiente = $this->pendienteDe($id);

        return [
            'request' => $pendiente === null ? null : [
                'uuid' => $pendiente->uuid,
                'status' => $pendiente->status,
                'created_at' => self::iso($pendiente->created_at),
            ],
            'next_appointment' => $cita === null ? null : [
                'uuid' => $cita->uuid,
                'starts_at' => self::iso($cita->starts_at),
                'date_label' => self::dateLabel($cita->starts_at),
                'trainer_name' => ValuationMessages::shortName($cita->trainer?->full_name),
                'status' => $cita->status,
            ],
            'can_request' => $cita === null && $pendiente === null,
            // La cita futura manda: es lo que el socio tiene que ver.
            'blocked_reason' => $cita !== null ? self::BLOCKED_FUTURE : ($pendiente !== null ? self::BLOCKED_PENDING : null),
        ];
    }

    /**
     * POST de la app. Idempotente por `client_request_id`.
     *
     * @return array{request: ValuationRequest, created: bool}
     *
     * @throws ValuationException 409 `valuation_future_appointment` | `valuation_request_pending` (con `data`), 403 `member_unavailable`, 422 `client_request_id_conflict`
     */
    public function submit(Member $socio, string $clientRequestId): array
    {
        $socioId = (int) $socio->getKey();
        $idPeticion = strtolower(trim($clientRequestId));

        // La repetición se reconoce antes de nada (el reintento tras perder la
        // red llega cuando ya existe).
        if ($repetida = $this->repeticion($idPeticion, $socioId)) {
            $this->avisarSiSigue($repetida, $socio);

            return ['request' => $repetida, 'created' => false];
        }

        if (! self::socioVigente($socio->anonymized_at, $socio->status)) {
            throw self::memberUnavailable();
        }

        try {
            return DB::transaction(function () use ($socio, $socioId, $idPeticion): array {
                $fila = DB::table('members')->where('id', $socioId)->lock(self::rowMutex())->first(['id', 'status', 'anonymized_at']);
                // Releído con la fila bloqueada: una baja que se confirmó
                // mientras esta petición esperaba ya se ve.
                if ($fila === null || ! self::socioVigente($fila->anonymized_at, $fila->status)) {
                    throw self::memberUnavailable();
                }

                // Con el socio bloqueado: el otro toque ya terminó o espera.
                if ($repetida = $this->repeticion($idPeticion, $socioId)) {
                    return ['request' => $repetida, 'created' => false];
                }
                if ($this->proximaCita($socioId) !== null) {
                    throw self::futureAppointment($this->stateFor($socio));
                }
                if ($this->pendienteDe($socioId) !== null) {
                    throw self::pending($this->stateFor($socio));
                }

                $solicitud = ValuationRequest::create([
                    'member_id' => $socioId,
                    'status' => ValuationRequest::STATUS_PENDING,
                    'source' => ValuationRequest::SOURCE_APP,
                    'client_request_id' => $idPeticion,
                    'version' => 1,
                ]);

                // Solo si la transacción se confirma; si no, el callback se descarta.
                DB::afterCommit(fn () => $this->avisarCreada($solicitud, $socio));

                return ['request' => $solicitud, 'created' => true];
            });
        } catch (UniqueConstraintViolationException $e) {
            // Dos toques que llegaron a insertar sin que el cerrojo los ordenara
            // (SQLite no tiene cerrojos de fila): el segundo choca con
            // `client_request_id` o con la pendiente única. Es la misma
            // solicitud, o la pendiente que ya tiene.
            if ($repetida = $this->repeticion($idPeticion, $socioId)) {
                return ['request' => $repetida, 'created' => false];
            }
            if ($this->pendienteDe($socioId) !== null) {
                throw self::pending($this->stateFor($socio));
            }
            throw $e;
        }
    }

    // ── CRM: lectura con el alcance de la agenda ────────────────────────────

    /**
     * Bandeja del CRM. `pending`: de la más antigua a la más nueva (es una
     * cola). `all`: de la más nueva a la más antigua.
     *
     * @return array{rows: list<array<string,mixed>>, page: LengthAwarePaginator}
     */
    public function list(ValuationAccess $access, string $estado, int $porPagina): array
    {
        $consulta = $this->acotar(ValuationRequest::query(), $access);
        if ($estado === 'pending') {
            $consulta->where('status', ValuationRequest::STATUS_PENDING)->orderBy('created_at')->orderBy('id');
        } else {
            $consulta->orderByDesc('created_at')->orderByDesc('id');
        }

        $pagina = $consulta->with(self::relations())->paginate($porPagina);
        $solicitudes = collect($pagina->items());
        $futuras = $this->proximasCitas($solicitudes->pluck('member_id')->map(fn ($id) => (int) $id)->unique()->values()->all());

        return [
            'rows' => $solicitudes->map(fn (ValuationRequest $r) => $this->present($r, $access, $futuras))->values()->all(),
            'page' => $pagina,
        ];
    }

    /**
     * La solicitud por uuid, si está en el alcance de quien pregunta. Fuera del
     * alcance responde como si no existiera.
     *
     * @throws ValuationException 403|404
     */
    public function load(ValuationAccess $access, string $uuid): ValuationRequest
    {
        $solicitud = $this->acotar(ValuationRequest::query(), $access)
            ->where('uuid', strtolower($uuid))
            ->first();
        if ($solicitud === null) {
            throw ValuationException::notFound('La solicitud');
        }

        return $solicitud;
    }

    /**
     * La fila del CRM (contrato §3).
     *
     * @param  Collection<int, ValuationAppointment>|null  $futuras  member_id → próxima cita abierta
     * @return array<string,mixed>
     */
    public function present(ValuationRequest $r, ValuationAccess $access, ?Collection $futuras = null): array
    {
        $r->loadMissing(self::relations());
        $futuras ??= $this->proximasCitas([(int) $r->member_id]);
        $futura = $futuras->get((int) $r->member_id);
        $enlazada = $r->appointment;

        return [
            'uuid' => $r->uuid,
            'status' => $r->status,
            'status_label' => ValuationRequest::STATUS_LABELS[$r->status] ?? $r->status,
            'source' => $r->source,
            'version' => (int) $r->version,
            'created_at' => self::iso($r->created_at),
            'member' => [
                'id' => (int) $r->member_id,
                'name' => (string) ($r->member?->full_name ?? ''),
                'document_tail' => ValuationPresenter::documentTail($r->member?->document_number),
            ],
            // Las citas, solo si quien mira ve la agenda de ese entrenador.
            'appointment' => $enlazada !== null && $access->covers($enlazada) ? self::citaCrm($enlazada) : null,
            'future_appointment' => $futura !== null && $access->covers($futura) ? self::citaCrm($futura) : null,
            'handled_by_name' => $r->handled_by_name,
            'handled_at' => self::iso($r->handled_at),
            'rejection_reason' => $r->rejection_reason,
            'allowed_actions' => $this->allowedActions($r, $access),
        ];
    }

    // ── CRM: acciones ───────────────────────────────────────────────────────

    /**
     * Enlaza una cita YA creada con el asistente: misma ficha, abierta y futura.
     *
     * @return array{request: ValuationRequest, changed: bool}
     */
    public function schedule(ValuationAccess $access, string $uuid, string $citaUuid, int $esperada, Request $request): array
    {
        $access->require(ValuationAccess::CREATE);
        $this->load($access, $uuid);
        $citaUuid = strtolower(trim($citaUuid));

        return DB::transaction(function () use ($access, $uuid, $citaUuid, $esperada, $request): array {
            $solicitud = $this->bloquear($access, $uuid);

            // Ya enlazada a ESA cita: es la repetición (doble clic, reintento).
            if ($solicitud->status === ValuationRequest::STATUS_SCHEDULED && $solicitud->appointment_id !== null
                && ValuationAppointment::query()->whereKey($solicitud->appointment_id)->value('uuid') === $citaUuid) {
                return ['request' => $solicitud, 'changed' => false];
            }
            $this->exigirVersion($solicitud, $esperada, $access);
            $this->exigirPendiente($solicitud, $access);

            // Solicitud → cita, siempre en ese orden. Bloqueada: mientras se
            // enlaza nadie la mueve, la cancela ni la cierra.
            $cita = ValuationAppointment::query()->where('uuid', $citaUuid)->lockForUpdate()->first();
            if ($cita === null || ! $access->covers($cita)) {
                throw self::appointmentNotFound();
            }
            if ((int) $cita->member_id !== (int) $solicitud->member_id) {
                throw self::appointmentOtherMember();
            }
            if (! $cita->isActive()) {
                throw self::appointmentNotOpen();
            }
            if (! $cita->starts_at->copy()->utc()->gt(Carbon::now()->utc())) {
                throw self::appointmentNotFuture();
            }

            $this->cerrar($solicitud, $access, ValuationRequest::STATUS_SCHEDULED, ['appointment_id' => $cita->id]);

            $this->audit->record($request, [
                'action' => 'status', 'module' => 'Valoraciones', 'entity' => 'solicitud_valoracion',
                'entity_id' => $solicitud->uuid, 'summary' => 'Agendó la solicitud de valoración',
                'changes' => [['field' => 'status', 'from' => ValuationRequest::STATUS_PENDING, 'to' => $solicitud->status]],
                'metadata' => ['member_id' => (int) $solicitud->member_id, 'appointment_uuid' => $cita->uuid],
            ]);

            return ['request' => $solicitud, 'changed' => true];
        });
    }

    /**
     * Atendida sin cita (p. ej. se valoró en el momento).
     *
     * @return array{request: ValuationRequest, changed: bool}
     */
    public function attend(ValuationAccess $access, string $uuid, int $esperada, Request $request): array
    {
        $access->require(ValuationAccess::UPDATE);
        $this->load($access, $uuid);

        return DB::transaction(function () use ($access, $uuid, $esperada, $request): array {
            $solicitud = $this->bloquear($access, $uuid);
            if ($solicitud->status === ValuationRequest::STATUS_ATTENDED) {
                return ['request' => $solicitud, 'changed' => false];
            }
            $this->exigirVersion($solicitud, $esperada, $access);
            $this->exigirPendiente($solicitud, $access);

            $this->cerrar($solicitud, $access, ValuationRequest::STATUS_ATTENDED);

            $this->audit->record($request, [
                'action' => 'status', 'module' => 'Valoraciones', 'entity' => 'solicitud_valoracion',
                'entity_id' => $solicitud->uuid, 'summary' => 'Marcó atendida la solicitud de valoración',
                'changes' => [['field' => 'status', 'from' => ValuationRequest::STATUS_PENDING, 'to' => $solicitud->status]],
                'metadata' => ['member_id' => (int) $solicitud->member_id],
            ]);

            return ['request' => $solicitud, 'changed' => true];
        });
    }

    /**
     * Rechazo con motivo; el socio lo lee en la app (sin push).
     *
     * @return array{request: ValuationRequest, changed: bool}
     */
    public function reject(ValuationAccess $access, string $uuid, string $motivo, int $esperada, Request $request): array
    {
        $access->require(ValuationAccess::UPDATE);
        $this->load($access, $uuid);
        $motivo = mb_substr(trim($motivo), 0, 500);

        return DB::transaction(function () use ($access, $uuid, $motivo, $esperada, $request): array {
            $solicitud = $this->bloquear($access, $uuid);
            if ($solicitud->status === ValuationRequest::STATUS_REJECTED) {
                return ['request' => $solicitud, 'changed' => false];
            }
            $this->exigirVersion($solicitud, $esperada, $access);
            $this->exigirPendiente($solicitud, $access);

            $this->cerrar($solicitud, $access, ValuationRequest::STATUS_REJECTED, ['rejection_reason' => $motivo]);

            // El motivo NO va a `audit_logs`: queda en la fila y lo lee el socio.
            $this->audit->record($request, [
                'action' => 'status', 'module' => 'Valoraciones', 'entity' => 'solicitud_valoracion',
                'entity_id' => $solicitud->uuid, 'summary' => 'Rechazó la solicitud de valoración',
                'changes' => [['field' => 'status', 'from' => ValuationRequest::STATUS_PENDING, 'to' => $solicitud->status]],
                'metadata' => ['member_id' => (int) $solicitud->member_id],
            ]);

            DB::afterCommit(fn () => $this->avisarRechazo($solicitud));

            return ['request' => $solicitud, 'changed' => true];
        });
    }

    // ── Avisos (después del commit) ─────────────────────────────────────────

    public static function crmEventKey(ValuationRequest $r): string
    {
        return 'valuation:request:'.$r->uuid.':created';
    }

    public static function receivedEventKey(ValuationRequest $r): string
    {
        return 'valuation:request:'.$r->uuid.':received';
    }

    public static function rejectedEventKey(ValuationRequest $r): string
    {
        return 'valuation:request:'.$r->uuid.':rejected';
    }

    /** Aviso al CRM y acuse al socio de una solicitud nueva. */
    private function avisarCreada(ValuationRequest $r, Member $socio): void
    {
        $this->sinRomper($r, 'created', function () use ($r, $socio): ?Notification {
            return $this->notifications->createAdminNotification([
                'type' => ValuationNotifier::TYPE,
                'title' => 'Solicitud de valoración',
                'message' => ValuationMessages::shortName($socio->full_name).' solicitó una valoración desde la app.',
                'priority' => 'medium',
                'required_permission' => self::REQUIRED_PERMISSION,
                'action_type' => 'valuation_request',
                'action_payload' => ['request_uuid' => $r->uuid, 'member_id' => (int) $r->member_id],
                'metadata' => ['source_event' => 'valuation_request_created', 'request_uuid' => $r->uuid, 'member_id' => (int) $r->member_id],
                'event_key' => self::crmEventKey($r),
            ]);
        });

        $this->sinRomper($r, 'received', fn (): ?Notification => $this->alSocio($socio, $r, [
            'title' => 'Solicitud de valoración recibida',
            // Nunca dice que ya hay una cita.
            'message' => 'Recibimos tu solicitud de valoración. Te contactaremos para agendarla.',
            'source_event' => 'valuation_request_received',
            'event_key' => self::receivedEventKey($r),
        ]));
    }

    /**
     * Reintento de la app sobre una solicitud ya creada: si el proceso murió
     * tras el commit y antes de avisar, el reintento completa los avisos
     * (idempotentes por `event_key`). Solo mientras sigue pendiente: un acuse
     * tardío de algo ya agendado o rechazado confundiría al socio.
     */
    private function avisarSiSigue(ValuationRequest $r, Member $socio): void
    {
        if ($r->isPending()) {
            $this->avisarCreada($r, $socio);
        }
    }

    private function avisarRechazo(ValuationRequest $r): void
    {
        $this->sinRomper($r, 'rejected', function () use ($r): ?Notification {
            $socio = Member::query()->withoutGlobalScope(TrainerMemberScope::NAME)->find($r->member_id);
            if (! $socio instanceof Member || $socio->anonymized_at !== null) {
                // Un socio dado de baja no tiene a quién avisar.
                return null;
            }

            return $this->alSocio($socio, $r, [
                'title' => 'Solicitud de valoración',
                'message' => 'No pudimos agendar tu valoración: '.$r->rejection_reason,
                'source_event' => 'valuation_request_rejected',
                'event_key' => self::rejectedEventKey($r),
            ]);
        });
    }

    /**
     * Aviso al socio dentro de la app, SIN push. La tabla `notifications` no
     * tiene `action_route`: el destino va en `action_type=route` +
     * `action_url`, el patrón que ya usa la valoración profesional
     * (`/assessment`) y el de los avisos de cita (ValuationNotifier).
     *
     * @param  array{title: string, message: string, source_event: string, event_key: string}  $texto
     */
    private function alSocio(Member $socio, ValuationRequest $r, array $texto): ?Notification
    {
        // JAMÁS con un socio nulo: con `member_id` nulo la fila sería de todos.
        if (! $socio->getKey()) {
            throw new LogicException('Aviso de solicitud de valoración sin socio: no se envía.');
        }

        return $this->notifications->createMemberNotification($socio, [
            'type' => ValuationNotifier::TYPE,
            'title' => $texto['title'],
            'message' => $texto['message'],
            'priority' => 'medium',
            'should_popup' => false,
            'action_type' => 'route',
            'action_url' => self::ACTION_ROUTE,
            'action_payload' => ['request_uuid' => $r->uuid],
            'metadata' => ['source_event' => $texto['source_event'], 'request_uuid' => $r->uuid],
            'event_key' => $texto['event_key'],
        ]);
    }

    /**
     * Un aviso nunca rompe la operación que lo origina: ya está confirmada
     * cuando se llama, y un 500 aquí haría reintentar a la app algo que ya se
     * hizo. El fallo queda en el log sin el contenido (ni nombre ni motivo).
     *
     * @param  Closure(): ?Notification  $aviso
     */
    private function sinRomper(ValuationRequest $r, string $tipo, Closure $aviso): void
    {
        try {
            $aviso();
        } catch (Throwable $e) {
            Log::warning('valuations.request_notice_failed', [
                'request_uuid' => $r->uuid,
                'notice' => $tipo,
                'error' => $e::class,
            ]);
        }
    }

    // ── Internos ────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private static function relations(): array
    {
        return [
            // Sin el alcance de entrenador: el acceso ya lo decidió acotar().
            'member' => fn ($q) => $q->withoutGlobalScope(TrainerMemberScope::NAME)
                ->select(['id', 'full_name', 'document_number']),
            'appointment:id,uuid,member_id,trainer_id,status,starts_at',
            'appointment.trainer:id,full_name',
        ];
    }

    /**
     * Quien ve todo, todo. Quien ve solo lo suyo (`view` + entrenador
     * vinculado): las solicitudes de los socios que tiene asignados y las
     * enlazadas a una cita de su entrenador. Sin alcance, 403.
     *
     * @param  Builder<ValuationRequest>  $q
     * @return Builder<ValuationRequest>
     */
    private function acotar(Builder $q, ValuationAccess $access): Builder
    {
        $entrenador = $access->scopedTrainerId();
        if ($entrenador === null) {
            return $q;
        }

        return $q->where(fn (Builder $w) => $w
            ->whereIn('valuation_requests.member_id', DB::table('member_trainer_assignments')
                ->select('member_id')
                ->where('trainer_id', $entrenador)
                ->where('status', MemberTrainerAssignment::STATUS_ACTIVE))
            ->orWhereIn('valuation_requests.appointment_id', DB::table('valuation_appointments')
                ->select('id')
                ->where('trainer_id', $entrenador)));
    }

    /**
     * La solicitud bloqueada, y OTRA VEZ en el alcance (entre la lectura previa
     * y el bloqueo pudo dejar de serlo).
     */
    private function bloquear(ValuationAccess $access, string $uuid): ValuationRequest
    {
        $solicitud = $this->acotar(ValuationRequest::query(), $access)
            ->where('uuid', strtolower($uuid))
            ->lockForUpdate()
            ->first();
        if ($solicitud === null) {
            throw ValuationException::notFound('La solicitud');
        }

        return $solicitud;
    }

    /** @param  array<string, mixed>  $extra */
    private function cerrar(ValuationRequest $solicitud, ValuationAccess $access, string $estado, array $extra = []): void
    {
        $solicitud->forceFill(array_merge([
            'status' => $estado,
            'handled_by_admin_id' => $access->actor->id,
            'handled_by_name' => $access->actor->name,
            'handled_at' => Carbon::now()->utc(),
            'version' => (int) $solicitud->version + 1,
        ], $extra))->save();
    }

    private function exigirVersion(ValuationRequest $solicitud, int $esperada, ValuationAccess $access): void
    {
        if ((int) $solicitud->version !== $esperada) {
            throw new ValuationException(
                'La solicitud cambió mientras la mirabas. Revisa cómo quedó antes de seguir.',
                'request_changed',
                409,
                ['data' => $this->present($solicitud, $access)],
            );
        }
    }

    private function exigirPendiente(ValuationRequest $solicitud, ValuationAccess $access): void
    {
        if (! $solicitud->isPending()) {
            throw new ValuationException(
                'La solicitud ya se gestionó: '.mb_strtolower(ValuationRequest::STATUS_LABELS[$solicitud->status] ?? $solicitud->status).'.',
                'request_not_pending',
                409,
                ['data' => $this->present($solicitud, $access)],
            );
        }
    }

    /** @return list<string> */
    private function allowedActions(ValuationRequest $r, ValuationAccess $access): array
    {
        if (! $r->isPending()) {
            return [];
        }
        $acciones = [];
        if ($access->can(ValuationAccess::CREATE)) {
            $acciones[] = 'schedule';
        }
        if ($access->can(ValuationAccess::UPDATE)) {
            $acciones[] = 'attend';
            $acciones[] = 'reject';
        }

        return $acciones;
    }

    /**
     * ¿Es la repetición de una petición ya hecha? Solo del MISMO socio: el id de
     * otro no confirma nada de esa solicitud ni de su dueño.
     *
     * @throws ValuationException 422 `client_request_id_conflict`
     */
    private function repeticion(string $idPeticion, int $socioId): ?ValuationRequest
    {
        $solicitud = ValuationRequest::query()->where('client_request_id', $idPeticion)->first();
        if ($solicitud === null) {
            return null;
        }
        if ((int) $solicitud->member_id !== $socioId) {
            throw new ValuationException(
                'Este identificador de solicitud ya se usó. Vuelve a intentarlo desde la app.',
                'client_request_id_conflict',
                422,
                ['errors' => ['client_request_id' => ['Este identificador de solicitud ya se usó.']]],
            );
        }

        return $solicitud;
    }

    private function pendienteDe(int $socioId): ?ValuationRequest
    {
        return ValuationRequest::query()
            ->where('member_id', $socioId)
            ->where('status', ValuationRequest::STATUS_PENDING)
            ->orderByDesc('id')
            ->first();
    }

    /** La cita abierta FUTURA más cercana del socio (contrato §2). */
    private function proximaCita(int $socioId): ?ValuationAppointment
    {
        return $this->proximasCitas([$socioId])->get($socioId);
    }

    /**
     * member_id → su próxima cita abierta futura. Una consulta para toda la
     * página (índice `(member_id, starts_at)`).
     *
     * @param  list<int>  $socioIds
     * @return Collection<int, ValuationAppointment>
     */
    private function proximasCitas(array $socioIds): Collection
    {
        if ($socioIds === []) {
            return collect();
        }

        return ValuationAppointment::query()
            ->whereIn('member_id', $socioIds)
            ->whereIn('status', ValuationAppointment::ACTIVE_STATUSES)
            ->where('starts_at', '>', ValuationAvailability::db(Carbon::now()))
            ->with('trainer:id,full_name')
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get(['id', 'uuid', 'member_id', 'trainer_id', 'status', 'starts_at'])
            ->groupBy(fn (ValuationAppointment $c) => (int) $c->member_id)
            ->map(fn (Collection $citas) => $citas->first());
    }

    /** @return array{uuid: string, starts_at: string|null, trainer_name: string, status: string} */
    private static function citaCrm(ValuationAppointment $c): array
    {
        return [
            'uuid' => $c->uuid,
            'starts_at' => self::iso($c->starts_at),
            'trainer_name' => (string) ($c->trainer?->full_name ?? ''),
            'status' => $c->status,
        ];
    }

    private static function socioVigente(mixed $anonimizadoEn, ?string $estado): bool
    {
        return $anonimizadoEn === null && $estado !== Member::STATUS_DELETED;
    }

    /** ISO 8601 con el desfase de Bogotá (`…-05:00`). */
    private static function iso(mixed $instante): ?string
    {
        if ($instante === null) {
            return null;
        }

        return ValuationTime::local(Carbon::parse($instante))->toIso8601String();
    }

    /** «jueves 20 de noviembre, 10:00 a. m.» (sin `ext-intl`). */
    private static function dateLabel(Carbon $instante): string
    {
        $local = ValuationTime::local($instante);

        return self::WEEKDAYS[$local->isoWeekday()].' '.ValuationTime::dayMonth($instante).', '.ValuationTime::clock($instante);
    }

    /** `FOR NO KEY UPDATE` en PostgreSQL (el de la agenda); en otro motor, `FOR UPDATE`. */
    private static function rowMutex(): string|bool
    {
        return DB::connection()->getDriverName() === 'pgsql' ? ValuationAgenda::ROW_MUTEX : true;
    }

    private static function memberUnavailable(): ValuationException
    {
        return new ValuationException('Esta cuenta no puede solicitar valoraciones.', 'member_unavailable', 403);
    }

    /** @param  array<string,mixed>  $estado */
    private static function futureAppointment(array $estado): ValuationException
    {
        return new ValuationException('Ya tienes una valoración agendada.', 'valuation_future_appointment', 409, ['data' => $estado]);
    }

    /** @param  array<string,mixed>  $estado */
    private static function pending(array $estado): ValuationException
    {
        return new ValuationException('Ya recibimos tu solicitud. Te contactaremos para agendarla.', 'valuation_request_pending', 409, ['data' => $estado]);
    }

    private static function appointmentNotFound(): ValuationException
    {
        return new ValuationException('La cita no existe o no está a tu alcance.', 'appointment_not_found', 422, [
            'errors' => ['appointment_uuid' => ['La cita no existe o no está a tu alcance.']],
        ]);
    }

    private static function appointmentOtherMember(): ValuationException
    {
        return new ValuationException('La cita es de otro socio: solo se enlaza una cita del mismo socio.', 'appointment_member_mismatch', 422, [
            'errors' => ['appointment_uuid' => ['La cita es de otro socio.']],
        ]);
    }

    private static function appointmentNotOpen(): ValuationException
    {
        return new ValuationException('La cita ya está cerrada (completada, cancelada o no asistió): enlaza una cita abierta.', 'appointment_not_open', 409);
    }

    private static function appointmentNotFuture(): ValuationException
    {
        return new ValuationException('La cita ya pasó: enlaza una cita futura.', 'appointment_not_future', 409);
    }
}
