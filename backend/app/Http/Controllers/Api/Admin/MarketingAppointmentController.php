<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\MarketingAppointment;
use App\Services\Marketing\MarketingAppointmentAuthorizationService;
use App\Services\Marketing\MarketingAppointmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
    ) {
    }

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
            'status'   => ['nullable', Rule::in(MarketingAppointment::STATUSES)],
            'type'     => ['nullable', Rule::in(MarketingAppointment::TYPES)],
            'date_from' => ['nullable', 'date'],
            'date_to'  => ['nullable', 'date'],
            'q'        => ['nullable', 'string', 'max:80'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = $this->service->list($request, $this->admin($request));

        return response()->json([
            'ok'   => true,
            'data' => collect($page->items())->map(fn ($a) => $this->service->present($a))->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page'    => $page->lastPage(),
                'per_page'     => $page->perPage(),
                'total'        => $page->total(),
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

        $appointment = $this->service->create($data, $this->admin($request)?->id);

        return response()->json(['ok' => true, 'data' => $this->service->present($appointment)], 201);
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

        return response()->json(['ok' => true, 'data' => $this->service->present($appointment)]);
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

        $this->service->update($appointment, $data);

        return response()->json(['ok' => true, 'data' => $this->service->present($appointment->fresh())]);
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
        $this->service->complete($appointment, $data['note'] ?? null);

        return response()->json(['ok' => true, 'data' => $this->service->present($appointment->fresh())]);
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
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $this->service->cancel($appointment, $data['reason'] ?? null);

        return response()->json(['ok' => true, 'data' => $this->service->present($appointment->fresh())]);
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
            'scheduled_at'     => ['required', 'date'],
            'duration_minutes' => ['nullable', 'integer', 'min:5', 'max:600'],
        ]);
        $this->service->reschedule($appointment, $data['scheduled_at'], $data['duration_minutes'] ?? null);

        return response()->json(['ok' => true, 'data' => $this->service->present($appointment->fresh())]);
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
            ->map(fn ($a) => $this->service->present($a))
            ->all();

        return response()->json(['ok' => true, 'data' => $appointments]);
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

    /** @return array<string,mixed> */
    private function validatePayload(Request $request, bool $creating): array
    {
        return $request->validate([
            'type'                      => [$creating ? 'required' : 'sometimes', Rule::in(MarketingAppointment::TYPES)],
            'title'                     => [$creating ? 'required' : 'sometimes', 'string', 'max:160'],
            'scheduled_at'              => [$creating ? 'required' : 'sometimes', 'date'],
            'duration_minutes'          => ['nullable', 'integer', 'min:5', 'max:600'],
            'notes'                     => ['nullable', 'string', 'max:2000'],
            'location'                  => ['nullable', 'string', 'max:200'],
            'contact_name'              => ['nullable', 'string', 'max:160'],
            'contact_phone'             => ['nullable', 'string', 'max:40'],
            'reminder_at'               => ['nullable', 'date'],
            'status'                    => ['nullable', Rule::in(MarketingAppointment::STATUSES)],
            'marketing_lead_id'         => ['nullable', 'integer', 'exists:marketing_leads,id'],
            'marketing_conversation_id' => ['nullable', 'integer', 'exists:marketing_conversations,id'],
            'assigned_to_admin_id'      => ['nullable', 'integer', 'exists:admins,id'],
        ]);
    }
}
