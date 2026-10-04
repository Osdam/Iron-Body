<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\AppointmentException;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\MarketingAppointment;
use App\Services\Marketing\MarketingAgendaVersion;
use App\Services\Marketing\MarketingAppointmentAuthorizationService;
use App\Services\Marketing\MarketingAppointmentService;
use App\Support\SseStream;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Agenda comercial (Fase 4B). Citas con leads de marketing para convertir.
 * Protegido por el blindaje global de /api/admin/* (ProtectAdminPaths). La
 * autorización fina (rol/estado + ownership) vive en el servicio de auth.
 */
class MarketingAppointmentController extends Controller
{
    public function __construct(
        private readonly MarketingAppointmentService $service,
        private readonly MarketingAppointmentAuthorizationService $authz,
    ) {}

    private function admin(Request $request): ?Admin
    {
        $admin = $request->attributes->get('auth_admin');

        return $admin instanceof Admin ? $admin : null;
    }

    /** Verifica una capacidad base. Devuelve respuesta de rechazo o null. */
    private function guard(Request $request, string $capability): ?JsonResponse
    {
        $deny = $this->authz->deny($this->admin($request), $capability);
        if ($deny !== null) {
            return response()->json(['ok' => false, 'code' => $deny['code'], 'message' => $deny['message']], $deny['status']);
        }

        return null;
    }

    /** Carga una cita y valida ownership para operar; devuelve [appt, errorResponse]. */
    private function findOwned(Request $request, int $id): array
    {
        $appointment = MarketingAppointment::find($id);
        if (! $appointment) {
            return [null, response()->json(['ok' => false, 'code' => 'not_found', 'message' => 'Cita no encontrada.'], 404)];
        }
        if (! $this->authz->ownsOrUnassigned($this->admin($request), $appointment)) {
            return [null, response()->json(['ok' => false, 'code' => 'appointments_forbidden', 'message' => 'No puedes operar una cita de otro asesor.'], 403)];
        }

        return [$appointment, null];
    }

    // ── Lista ────────────────────────────────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        if ($r = $this->guard($request, MarketingAppointmentAuthorizationService::CAP_VIEW)) {
            return $r;
        }
        $request->validate([
            'status' => ['nullable', Rule::in(MarketingAppointment::STATUSES)],
            'type' => ['nullable', Rule::in(MarketingAppointment::TYPES)],
            'source' => ['nullable', Rule::in([MarketingAppointment::SOURCE_ULTRON, MarketingAppointment::SOURCE_CRM, MarketingAppointment::SOURCE_COMMERCIAL_TOOL])],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'q' => ['nullable', 'string', 'max:80'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $viewer = $this->admin($request);
        try {
            $page = $this->service->list($request, $viewer);
            $counts = $this->service->countsByStatus($request, $viewer);
        } catch (AppointmentException $e) {
            return $this->falla($e);
        }

        return response()->json([
            'ok' => true,
            'data' => collect($page->items())->map(fn ($a) => $this->service->present($a, $viewer))->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                // Por estado, con los mismos filtros salvo el de estado y sin paginar.
                'counts' => $counts,
            ],
        ]);
    }

    // ── Crear ────────────────────────────────────────────────────────────────
    public function store(Request $request): JsonResponse
    {
        if ($r = $this->guard($request, MarketingAppointmentAuthorizationService::CAP_CREATE)) {
            return $r;
        }
        $data = $this->validatePayload($request, creating: true);

        // Solo FULL puede asignar a otro asesor; comercial se autoasigna.
        if (! empty($data['assigned_to_admin_id'])
            && ! $this->authz->isFull($this->admin($request))
            && (int) $data['assigned_to_admin_id'] !== (int) ($this->admin($request)?->id)) {
            return response()->json(['ok' => false, 'code' => 'appointments_forbidden', 'message' => 'No puedes asignar citas a otro asesor.'], 403);
        }

        try {
            $data['scheduled_at'] = MarketingAppointmentService::fromClientTime((string) $data['scheduled_at']);
        } catch (AppointmentException $e) {
            return $this->falla($e);
        }
        $appointment = $this->service->create($data, $this->admin($request)?->id, MarketingAppointment::SOURCE_CRM, $request);

        return response()->json(['ok' => true, 'data' => $this->service->present($appointment->fresh(), $this->admin($request))], 201);
    }

    // ── Detalle ──────────────────────────────────────────────────────────────
    public function show(Request $request, int $id): JsonResponse
    {
        if ($r = $this->guard($request, MarketingAppointmentAuthorizationService::CAP_VIEW)) {
            return $r;
        }
        [$appointment, $err] = $this->findOwned($request, $id);
        if ($err) {
            return $err;
        }

        return response()->json(['ok' => true, 'data' => $this->service->present($appointment, $this->admin($request))]);
    }

    // ── Editar ───────────────────────────────────────────────────────────────
    public function update(Request $request, int $id): JsonResponse
    {
        if ($r = $this->guard($request, MarketingAppointmentAuthorizationService::CAP_UPDATE)) {
            return $r;
        }
        [$appointment, $err] = $this->findOwned($request, $id);
        if ($err) {
            return $err;
        }
        $data = $this->validatePayload($request, creating: false);

        if (! empty($data['assigned_to_admin_id'])
            && ! $this->authz->isFull($this->admin($request))
            && (int) $data['assigned_to_admin_id'] !== (int) ($this->admin($request)?->id)) {
            return response()->json(['ok' => false, 'code' => 'appointments_forbidden', 'message' => 'No puedes asignar citas a otro asesor.'], 403);
        }

        // Cambiar la fecha o confirmar por la puerta genérica exige decir qué fecha
        // se vio: un formulario desactualizado no puede deshacer, sin aviso, el día
        // que la persona pidió por WhatsApp ni confirmar uno que nadie revisó.
        $request->validate(['expected_scheduled_at' => ['nullable', 'date']]);
        $tocaLaFecha = ! empty($data['scheduled_at']) || ($data['status'] ?? null) === MarketingAppointment::STATUS_SCHEDULED;
        if ($tocaLaFecha && ! $request->filled('expected_scheduled_at')) {
            return response()->json([
                'ok' => false, 'code' => 'expected_scheduled_at_required',
                'message' => 'Para cambiar la fecha o confirmar una cita, envía la fecha que estás viendo (expected_scheduled_at).',
            ], 422);
        }

        return $this->aplicar($request, fn () => $this->service->update($appointment, $data, $request, $this->fechaVista($request)));
    }

    // ── Confirmar una solicitud ──────────────────────────────────────────────
    public function confirm(Request $request, int $id): JsonResponse
    {
        if ($r = $this->guard($request, MarketingAppointmentAuthorizationService::CAP_CONFIRM)) {
            return $r;
        }
        [$appointment, $err] = $this->findOwned($request, $id);
        if ($err) {
            return $err;
        }
        // Opcional, para no romper clientes viejos: la fecha que vio quien confirma.
        $request->validate(['expected_scheduled_at' => ['nullable', 'date']]);

        return $this->aplicar($request, fn () => $this->service->confirm($appointment, $request, $this->fechaVista($request)));
    }

    // ── Completar ────────────────────────────────────────────────────────────
    public function complete(Request $request, int $id): JsonResponse
    {
        if ($r = $this->guard($request, MarketingAppointmentAuthorizationService::CAP_COMPLETE)) {
            return $r;
        }
        [$appointment, $err] = $this->findOwned($request, $id);
        if ($err) {
            return $err;
        }
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        return $this->aplicar($request, fn () => $this->service->complete($appointment, $data['note'] ?? null, $request));
    }

    // ── Cancelar ─────────────────────────────────────────────────────────────
    public function cancel(Request $request, int $id): JsonResponse
    {
        if ($r = $this->guard($request, MarketingAppointmentAuthorizationService::CAP_CANCEL)) {
            return $r;
        }
        [$appointment, $err] = $this->findOwned($request, $id);
        if ($err) {
            return $err;
        }
        // 255: lo que cabe en `cancellation_reason`; con 500, PostgreSQL rechazaba al guardar.
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        return $this->aplicar($request, fn () => $this->service->cancel($appointment, $data['reason'] ?? null, $request));
    }

    // ── Reprogramar ──────────────────────────────────────────────────────────
    public function reschedule(Request $request, int $id): JsonResponse
    {
        if ($r = $this->guard($request, MarketingAppointmentAuthorizationService::CAP_RESCHEDULE)) {
            return $r;
        }
        [$appointment, $err] = $this->findOwned($request, $id);
        if ($err) {
            return $err;
        }
        $data = $request->validate([
            'scheduled_at' => ['required', 'date'],
            'duration_minutes' => ['nullable', 'integer', 'min:5', 'max:600'],
            // Opcional, para no romper clientes viejos: la fecha que tenía la cita al abrir el panel.
            'expected_scheduled_at' => ['nullable', 'date'],
        ]);

        return $this->aplicar($request, fn () => $this->service->reschedule(
            $appointment, (string) $data['scheduled_at'], $data['duration_minutes'] ?? null, $request, vista: $this->fechaVista($request),
        ));
    }

    // ── Citas de una conversación ────────────────────────────────────────────
    public function forConversation(Request $request, int $id): JsonResponse
    {
        if ($r = $this->guard($request, MarketingAppointmentAuthorizationService::CAP_VIEW)) {
            return $r;
        }
        $appointments = MarketingAppointment::query()
            ->with(['assignedAdmin:id,name', 'lead:id,name,phone'])
            ->where('marketing_conversation_id', $id)
            ->orderByDesc('scheduled_at')
            ->limit(20)
            ->get()
            ->map(fn ($a) => $this->service->present($a, $this->admin($request)))
            ->all();

        return response()->json(['ok' => true, 'data' => $appointments]);
    }

    // ── Tiempo real ──────────────────────────────────────────────────────────
    /**
     * SSE: avisa cuando cambia cualquier cita —la crea ULTRON, la confirma otra
     * sesión, la mueve una herramienta—. Solo viaja la versión de la agenda:
     * quien escucha relee la lista por el GET, con sus filtros y su alcance.
     */
    public function stream(Request $request): Response
    {
        if ($r = $this->guard($request, MarketingAppointmentAuthorizationService::CAP_VIEW)) {
            return $r;
        }

        $ultima = null;
        $avisa = function () use (&$ultima): void {
            $version = MarketingAgendaVersion::current();
            if ($ultima !== $version) {
                $ultima = $version;
                SseStream::emit('agenda', ['version' => $version], $version);
            }
        };

        return SseStream::response($avisa, 25, 2000, $avisa);
    }

    /**
     * Ejecuta una transición y responde siempre igual: la cita como queda y si
     * cambió algo. Lo que el dominio no permite vuelve como 409/422 con un
     * código estable y el motivo en español, para que el CRM lo enseñe tal cual.
     *
     * @param  Closure(): array{appointment:MarketingAppointment, changed:bool}  $accion
     */
    private function aplicar(Request $request, Closure $accion): JsonResponse
    {
        try {
            $r = $accion();
        } catch (AppointmentException $e) {
            return $this->falla($e);
        }

        return response()->json([
            'ok' => true,
            'changed' => $r['changed'],
            'data' => $this->service->present($r['appointment']->fresh(), $this->admin($request)),
        ]);
    }

    // ── Capacidades para el frontend ─────────────────────────────────────────
    public function capabilities(Request $request): JsonResponse
    {
        $admin = $this->admin($request);
        if (! $admin instanceof Admin) {
            return response()->json(['ok' => false, 'code' => 'appointments_requires_admin', 'message' => 'Requiere sesión de administrador.'], 401);
        }
        if (! $admin->isActive()) {
            return response()->json(['ok' => false, 'code' => 'appointments_admin_inactive', 'message' => 'Tu cuenta no está activa.'], 403);
        }

        return response()->json(['ok' => true, 'data' => $this->authz->frontendCapabilities($admin)]);
    }

    /** La fecha que vio quien escribe, si la manda (en hora de Bogotá si llega sin desfase). */
    private function fechaVista(Request $request): ?CarbonInterface
    {
        return $request->filled('expected_scheduled_at')
            ? MarketingAppointmentService::fromClientTime((string) $request->input('expected_scheduled_at'))
            : null;
    }

    private function falla(AppointmentException $e): JsonResponse
    {
        return response()->json(['ok' => false, 'code' => $e->errorCode, 'message' => $e->getMessage()], $e->status);
    }

    /** @return array<string,mixed> */
    private function validatePayload(Request $request, bool $creating): array
    {
        return $request->validate([
            'type' => [$creating ? 'required' : 'sometimes', Rule::in(MarketingAppointment::TYPES)],
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'max:160'],
            'scheduled_at' => [$creating ? 'required' : 'sometimes', 'date'],
            'duration_minutes' => ['nullable', 'integer', 'min:5', 'max:600'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'location' => ['nullable', 'string', 'max:200'],
            'contact_name' => ['nullable', 'string', 'max:160'],
            'contact_phone' => ['nullable', 'string', 'max:40'],
            'reminder_at' => ['nullable', 'date'],
            'status' => ['nullable', Rule::in(MarketingAppointment::STATUSES)],
            'marketing_lead_id' => ['nullable', 'integer', 'exists:marketing_leads,id'],
            'marketing_conversation_id' => ['nullable', 'integer', 'exists:marketing_conversations,id'],
            'assigned_to_admin_id' => ['nullable', 'integer', 'exists:admins,id'],
        ]);
    }
}
