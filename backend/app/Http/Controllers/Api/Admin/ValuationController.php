<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\ValuationException;
use App\Http\Controllers\Concerns\ValuationResponses;
use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\MemberTrainerAssignment;
use App\Models\Trainer;
use App\Models\ValuationAppointment;
use App\Models\ValuationAvailabilityRule;
use App\Rules\PlainText;
use App\Services\Valuations\ValuationAccess;
use App\Services\Valuations\ValuationAgenda;
use App\Services\Valuations\ValuationAvailability;
use App\Services\Valuations\ValuationPresenter;
use App\Services\Valuations\ValuationSettings;
use App\Services\Valuations\ValuationTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * AGENDA DE VALORACIONES (Entrenadores › Calendario de citas). Contrato:
 * CONTRATO_AGENDA_VALORACIONES.md §4.
 *
 * ENTRADA. Los textos libres (notas, motivos, búsqueda) pasan por PlainText:
 * UTF-8 válido y sin NUL ni controles (las notas y los motivos admiten saltos
 * de línea). Con bytes inválidos, PostgreSQL devolvía 500 y SQLite guardaba una
 * fila que rompía la lista entera (revisión de seguridad V-2). Las fechas, en
 * ISO 8601 estricto ({@see ValuationTime::parseInstant()}).
 *
 * AUTORIZACIÓN EN DOS PUERTAS. El mapa central (AuthorizationMap::OVERRIDES)
 * exige el permiso de cada ruta; este controlador NO está en CONTROLLERS, así
 * que una ruta nueva sin clasificar se deniega. Aquí se exige además una
 * PERSONA y el ALCANCE de la cita ({@see ValuationAccess}): un entrenador
 * vinculado sin `view_all` solo ve y opera las citas de su entrenador.
 *
 * Sin route-model binding: el uuid llega como texto y lo resuelve el servicio.
 * Con `{appointment}` enlazado, EnforceTrainerMemberScope devolvería 404 a un
 * entrenador sobre su propia cita con un socio que no tiene asignado.
 */
class ValuationController extends Controller
{
    use ValuationResponses;

    /** Rango máximo de la lista en modo calendario. */
    private const MAX_RANGE_DAYS = 62;

    public function __construct(
        private readonly ValuationAgenda $agenda,
        private readonly ValuationAvailability $availability,
        private readonly ValuationPresenter $presenter,
        private readonly ValuationSettings $settings,
    ) {}

    /** GET /api/admin/valuations/capabilities */
    public function capabilities(Request $request): JsonResponse
    {
        return $this->conPersona($request, function (ValuationAccess $access): JsonResponse {
            $s = $this->settings->current();

            return response()->json(['ok' => true, 'data' => [
                'can' => $access->capabilities(),
                'own_trainer_id' => $access->ownTrainerId(),
                'settings' => [
                    'default_duration_minutes' => (int) $s->default_duration_minutes,
                    'slot_interval_minutes' => (int) $s->slot_interval_minutes,
                    'max_horizon_days' => (int) $s->max_horizon_days,
                    'min_notice_minutes' => (int) $s->min_notice_minutes,
                ],
                'types' => array_map(
                    fn (string $t) => ['value' => $t, 'label' => ValuationAppointment::TYPE_LABELS[$t]],
                    ValuationAppointment::TYPES,
                ),
                'statuses' => array_map(
                    fn (string $e) => ['value' => $e, 'label' => ValuationAppointment::STATUS_LABELS[$e]],
                    ValuationAppointment::STATUSES,
                ),
                'channels' => [
                    'member_app' => ['available' => true, 'enabled' => (bool) $s->member_app_enabled],
                    'whatsapp' => ['available' => false, 'reason' => 'Sin plantilla aprobada en Meta'],
                ],
            ]]);
        });
    }

    /** GET /api/admin/valuations — calendario (rango) o historial (por socio o entrenador). */
    public function index(Request $request): JsonResponse
    {
        return $this->conPersona($request, function (ValuationAccess $access) use ($request): JsonResponse {
            $datos = $this->validar($request, [
                'from' => ['nullable', 'string', 'max:40'],
                'to' => ['nullable', 'string', 'max:40'],
                'trainer_id' => ['nullable', 'integer', 'min:1'],
                'member_id' => ['nullable', 'integer', 'min:1'],
                'status' => ['nullable', 'string', 'max:120', new PlainText],
                'type' => ['nullable', Rule::in(ValuationAppointment::TYPES)],
                'page' => ['nullable', 'integer', 'min:1'],
                'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            ]);

            $alcance = $access->scopedTrainerId();
            $historial = ! empty($datos['member_id']) || ! empty($datos['trainer_id']);

            if (! $historial && (empty($datos['from']) || empty($datos['to']))) {
                return $this->invalido(array_filter([
                    'from' => empty($datos['from']) ? ['Indica el inicio del rango (o filtra por socio o entrenador).'] : null,
                    'to' => empty($datos['to']) ? ['Indica el final del rango (o filtra por socio o entrenador).'] : null,
                ]));
            }

            $desde = ! empty($datos['from']) ? ValuationTime::parseInstant((string) $datos['from'], 'from') : null;
            $hasta = ! empty($datos['to']) ? ValuationTime::parseInstant((string) $datos['to'], 'to') : null;
            if ($desde && $hasta) {
                if (! $hasta->gt($desde)) {
                    return $this->invalido(['to' => ['El final del rango tiene que ser posterior al inicio.']]);
                }
                if ($desde->diffInSeconds($hasta) > self::MAX_RANGE_DAYS * 86400) {
                    throw ValuationException::rangeTooWide(self::MAX_RANGE_DAYS);
                }
            }

            $estados = null;
            if (! empty($datos['status'])) {
                $estados = array_values(array_unique(array_filter(array_map('trim', explode(',', (string) $datos['status'])))));
                $desconocidos = array_diff($estados, ValuationAppointment::STATUSES);
                if ($desconocidos !== []) {
                    return $this->invalido(['status' => ['Estado desconocido: '.implode(', ', $desconocidos).'.']]);
                }
            }

            if (! empty($datos['trainer_id']) && ! $access->coversTrainer((int) $datos['trainer_id'])) {
                throw ValuationException::forbidden('Solo puedes ver la agenda de tu propio entrenador.');
            }

            $consulta = ValuationAppointment::query()
                ->when($alcance !== null, fn ($q) => $q->where('trainer_id', $alcance))
                ->when(! empty($datos['trainer_id']), fn ($q) => $q->where('trainer_id', (int) $datos['trainer_id']))
                ->when(! empty($datos['member_id']), fn ($q) => $q->where('member_id', (int) $datos['member_id']))
                ->when($estados !== null, fn ($q) => $q->whereIn('status', $estados))
                ->when(! empty($datos['type']), fn ($q) => $q->where('type', $datos['type']))
                // Lo que se SOLAPA con el rango: una cita que empieza antes y
                // termina dentro también se pinta.
                ->when($hasta !== null, fn ($q) => $q->where('starts_at', '<', ValuationAvailability::db($hasta)))
                ->when($desde !== null, fn ($q) => $q->where('ends_at', '>', ValuationAvailability::db($desde)))
                ->orderBy('starts_at')
                ->orderBy('id');

            $pagina = $consulta->paginate((int) ($datos['per_page'] ?? 50));

            return response()->json([
                'ok' => true,
                'data' => $this->presenter->many(collect($pagina->items()), $access),
                'meta' => [
                    'current_page' => $pagina->currentPage(),
                    'last_page' => $pagina->lastPage(),
                    'per_page' => $pagina->perPage(),
                    'total' => $pagina->total(),
                ],
            ]);
        });
    }

    /** GET /api/admin/valuations/{uuid} — la cita, su historial y el estado de sus avisos. */
    public function show(Request $request, string $uuid): JsonResponse
    {
        return $this->conPersona($request, function (ValuationAccess $access) use ($uuid): JsonResponse {
            $cita = $this->agenda->cargar($access, $uuid);

            return response()->json(['ok' => true, 'data' => $this->presenter->detail($cita, $access)]);
        });
    }

    /** POST /api/admin/valuations — 201 nueva; 200 si es la repetición de la misma. */
    public function store(Request $request): JsonResponse
    {
        return $this->conPersona($request, function (ValuationAccess $access) use ($request): JsonResponse {
            $datos = $this->validar($request, [
                'client_request_id' => ['required', 'uuid'],
                'member_id' => ['required', 'integer', 'exists:members,id'],
                'trainer_id' => ['required', 'integer', 'exists:trainers,id'],
                'type' => ['required', Rule::in(ValuationAppointment::TYPES)],
                'starts_at' => ['required', 'string', 'max:40'],
                'duration_minutes' => ['nullable', 'integer', 'min:15', 'max:240', 'multiple_of:5'],
                'notes' => ['nullable', 'string', 'max:1000', new PlainText(multiline: true)],
                'allow_past' => ['nullable', 'boolean'],
                'past_reason' => ['nullable', 'required_if_accepted:allow_past', 'string', 'min:5', 'max:500', new PlainText(multiline: true)],
            ]);

            $r = $this->agenda->create($access, $datos, $request);

            return response()->json([
                'ok' => true,
                'data' => $this->presenter->one($r['appointment']->fresh(), $access),
            ], $r['created'] ? 201 : 200);
        });
    }

    /** PATCH /api/admin/valuations/{uuid} — tipo y notas (sin hora). */
    public function update(Request $request, string $uuid): JsonResponse
    {
        return $this->conPersona($request, function (ValuationAccess $access) use ($request, $uuid): JsonResponse {
            $datos = $this->validar($request, [
                'expected_version' => ['required', 'integer', 'min:1'],
                'type' => ['sometimes', 'required', Rule::in(ValuationAppointment::TYPES)],
                'notes' => ['sometimes', 'nullable', 'string', 'max:1000', new PlainText(multiline: true)],
            ]);

            return $this->resultado($access, $this->agenda->update($access, $uuid, $datos, $request));
        });
    }

    /** POST /api/admin/valuations/{uuid}/reschedule */
    public function reschedule(Request $request, string $uuid): JsonResponse
    {
        return $this->conPersona($request, function (ValuationAccess $access) use ($request, $uuid): JsonResponse {
            $datos = $this->validar($request, [
                'expected_version' => ['required', 'integer', 'min:1'],
                'starts_at' => ['required', 'string', 'max:40'],
                'duration_minutes' => ['nullable', 'integer', 'min:15', 'max:240', 'multiple_of:5'],
                'trainer_id' => ['nullable', 'integer', 'exists:trainers,id'],
                'reason' => ['nullable', 'string', 'max:500', new PlainText(multiline: true)],
            ]);

            return $this->resultado($access, $this->agenda->reschedule($access, $uuid, $datos, $request));
        });
    }

    /** POST /api/admin/valuations/{uuid}/cancel */
    public function cancel(Request $request, string $uuid): JsonResponse
    {
        return $this->conPersona($request, function (ValuationAccess $access) use ($request, $uuid): JsonResponse {
            $datos = $this->validar($request, [
                'expected_version' => ['required', 'integer', 'min:1'],
                'reason' => ['required', 'string', 'min:5', 'max:500', new PlainText(multiline: true)],
            ]);

            return $this->resultado($access, $this->agenda->cancel($access, $uuid, (int) $datos['expected_version'], (string) $datos['reason'], $request));
        });
    }

    public function confirm(Request $request, string $uuid): JsonResponse
    {
        return $this->transicion($request, $uuid, 'confirm');
    }

    public function start(Request $request, string $uuid): JsonResponse
    {
        return $this->transicion($request, $uuid, 'start');
    }

    public function complete(Request $request, string $uuid): JsonResponse
    {
        return $this->transicion($request, $uuid, 'complete');
    }

    public function noShow(Request $request, string $uuid): JsonResponse
    {
        return $this->transicion($request, $uuid, 'no_show');
    }

    /** GET /api/admin/valuations/slots?trainer_id&date&duration_minutes&exclude */
    public function slots(Request $request): JsonResponse
    {
        return $this->conPersona($request, function (ValuationAccess $access) use ($request): JsonResponse {
            $datos = $this->validar($request, [
                'trainer_id' => ['required', 'integer', 'exists:trainers,id'],
                'date' => ['required', 'date_format:Y-m-d'],
                'duration_minutes' => ['nullable', 'integer', 'min:15', 'max:240', 'multiple_of:5'],
                'exclude' => ['nullable', 'uuid'],
            ]);
            $access->requireCoversTrainer((int) $datos['trainer_id']);
            $fecha = ValuationTime::plainDate((string) $datos['date']);
            if ($fecha === null) {
                return $this->invalido(['date' => ['Fecha no válida.']]);
            }

            // La cita que se está moviendo no se estorba a sí misma.
            $excluir = ! empty($datos['exclude']) ? (int) $this->agenda->cargar($access, (string) $datos['exclude'])->id : null;
            $ajustes = $this->settings->current();
            $duracion = (int) ($datos['duration_minutes'] ?? $ajustes->default_duration_minutes);

            return response()->json([
                'ok' => true,
                'data' => $this->availability->slots((int) $datos['trainer_id'], $fecha, $duracion, $ajustes, $excluir),
            ]);
        });
    }

    /** GET /api/admin/valuations/trainers — entrenadores activos del alcance. */
    public function trainers(Request $request): JsonResponse
    {
        return $this->conPersona($request, function (ValuationAccess $access): JsonResponse {
            $alcance = $access->scopedTrainerId();
            $hoy = ValuationTime::today()->toDateString();

            $conReglas = ValuationAvailabilityRule::query()
                ->where('active', true)
                ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', $hoy))
                ->distinct()
                ->pluck('trainer_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $lista = Trainer::query()
                ->whereRaw("LOWER(COALESCE(NULLIF(status, ''), 'active')) IN ('active', 'activo')")
                ->when($alcance !== null, fn ($q) => $q->whereKey($alcance))
                ->orderBy('full_name')
                ->get(['id', 'full_name', 'location'])
                ->map(fn (Trainer $t) => [
                    'id' => (int) $t->id,
                    'name' => (string) $t->full_name,
                    'has_availability' => in_array((int) $t->id, $conReglas, true),
                    'location' => $t->location,
                ])
                ->values()
                ->all();

            return response()->json(['ok' => true, 'data' => $lista]);
        });
    }

    /**
     * GET /api/admin/valuations/members/search?q= — socios para agendar.
     *
     * Por nombre o documento con `LOWER(...) LIKE` (no `ilike`: SQLite no lo
     * tiene). Sin teléfono ni correo, y del documento solo los 4 últimos.
     */
    public function searchMembers(Request $request): JsonResponse
    {
        return $this->conPersona($request, function (ValuationAccess $access) use ($request): JsonResponse {
            $access->require(ValuationAccess::CREATE);
            $access->scopedTrainerId();
            $datos = $this->validar($request, ['q' => ['required', 'string', 'min:2', 'max:80', new PlainText]]);

            $q = mb_strtolower(trim((string) $datos['q']));
            if (mb_strlen($q) < 2) {
                return $this->invalido(['q' => ['Escribe al menos 2 caracteres.']]);
            }
            // Comodines escapados con `!` y no con `\`: SQLite no tiene carácter
            // de escape por defecto, y `ESCAPE '\'` rompe el análisis de
            // parámetros de PDO en PostgreSQL (lee `\'` como comilla escapada).
            $escapar = fn (string $t) => str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $t);
            $like = '%'.$escapar($q).'%';
            $docLike = '%'.$escapar((string) preg_replace('/[\s.\-]+/', '', $q)).'%';

            $socios = Member::query()
                ->whereNull('anonymized_at')
                ->where(fn ($w) => $w->whereRaw("LOWER(full_name) LIKE ? ESCAPE '!'", [$like])
                    ->orWhereRaw("LOWER(document_number) LIKE ? ESCAPE '!'", [$docLike]))
                ->with(['user:id,membership_end_date'])
                ->orderBy('full_name')
                ->orderBy('id')
                ->limit(20)
                ->get(['id', 'user_id', 'full_name', 'document_number']);

            $asignados = MemberTrainerAssignment::query()
                ->whereIn('member_id', $socios->pluck('id')->all())
                ->where('status', MemberTrainerAssignment::STATUS_ACTIVE)
                ->with('trainer:id,full_name')
                ->orderByDesc('id')
                ->get(['id', 'member_id', 'trainer_id'])
                ->unique('member_id')
                ->keyBy('member_id');

            return response()->json(['ok' => true, 'data' => $socios->map(function (Member $m) use ($asignados) {
                [$estado, $etiqueta] = ValuationPresenter::membership($m);
                $asignacion = $asignados->get($m->id);

                return [
                    'id' => (int) $m->id,
                    'name' => (string) $m->full_name,
                    'document_tail' => ValuationPresenter::documentTail($m->document_number),
                    'membership_status' => $estado,
                    'membership_label' => $etiqueta,
                    'assigned_trainer' => $asignacion?->trainer
                        ? ['id' => (int) $asignacion->trainer->id, 'name' => (string) $asignacion->trainer->full_name]
                        : null,
                ];
            })->values()->all()]);
        });
    }

    /** GET /api/admin/valuations/members/by-user/{userId} → {member_id} o 404. */
    public function memberByUser(Request $request, int $userId): JsonResponse
    {
        return $this->conPersona($request, function (ValuationAccess $access) use ($userId): JsonResponse {
            $access->scopedTrainerId();
            $id = Member::query()->where('user_id', $userId)->orderBy('id')->value('id');
            if ($id === null) {
                throw ValuationException::notFound('El socio');
            }

            return response()->json(['ok' => true, 'data' => ['member_id' => (int) $id]]);
        });
    }

    private function transicion(Request $request, string $uuid, string $accion): JsonResponse
    {
        return $this->conPersona($request, function (ValuationAccess $access) use ($request, $uuid, $accion): JsonResponse {
            $datos = $this->validar($request, [
                'expected_version' => ['required', 'integer', 'min:1'],
                'note' => ['nullable', 'string', 'max:500', new PlainText(multiline: true)],
            ]);

            return $this->resultado($access, $this->agenda->transition($access, $uuid, $accion, (int) $datos['expected_version'], $datos['note'] ?? null, $request));
        });
    }

    /** @param  array{appointment: ValuationAppointment, changed: bool}  $r */
    private function resultado(ValuationAccess $access, array $r): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'changed' => $r['changed'],
            'data' => $this->presenter->one($r['appointment']->fresh(), $access),
        ]);
    }
}
