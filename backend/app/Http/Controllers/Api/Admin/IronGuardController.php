<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\Incident;
use App\Models\IncidentEvent;
use App\Services\IronGuard\ChannelHealthDetector;
use App\Services\IronGuard\ChannelMetricsService;
use App\Services\IronGuard\IncidentRecorder;
use App\Services\IronGuard\SafeRemediationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Panel de IRON GUARD dentro del CRM.
 *
 * Solo administradores con acceso total: aquí se ven ids internos, códigos de
 * error e infraestructura, que no es información para un asesor comercial.
 *
 * El panel responde a las preguntas que alguien se hace cuando algo va mal: qué
 * falló, desde cuándo, cuántas veces, a cuánta gente afectó, qué evidencia hay y
 * qué se puede hacer sin riesgo.
 */
class IronGuardController extends Controller
{
    /** Roles con acceso. Deliberadamente restrictivo. */
    private const ALLOWED_ROLES = ['super admin', 'administrador', 'admin'];

    public function __construct(
        private readonly ChannelMetricsService $metrics,
        private readonly IncidentRecorder $recorder,
    ) {}

    /** Estado general: cifras del canal + incidentes abiertos. */
    public function overview(Request $request): JsonResponse
    {
        if ($denial = $this->deny($request)) {
            return $denial;
        }

        $open = Incident::query()
            ->whereIn('status', [Incident::STATUS_OPEN, Incident::STATUS_ACKNOWLEDGED])
            ->orderByRaw($this->severityOrderSql())
            ->orderByDesc('last_seen_at')
            ->limit(50)
            ->get();

        return response()->json([
            'ok' => true,
            'data' => [
                'metrics' => $this->metrics->snapshot(),
                'incidents' => $open->map(fn (Incident $i) => $this->summarize($i))->all(),
                'guard_enabled' => (bool) config('observability.enabled', false),
                'auto_remediation_enabled' => (bool) config('observability.remediation.enabled', false),
            ],
        ]);
    }

    /** Lista filtrable de incidentes. */
    public function index(Request $request): JsonResponse
    {
        if ($denial = $this->deny($request)) {
            return $denial;
        }

        $data = $request->validate([
            'status' => ['nullable', 'string', 'in:open,acknowledged,resolved,ignored,all'],
            'severity' => ['nullable', 'string', 'in:low,medium,high,critical'],
            'source' => ['nullable', 'string', 'max:40'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Incident::query();

        $status = $data['status'] ?? 'open';
        if ($status === 'open') {
            $query->whereIn('status', [Incident::STATUS_OPEN, Incident::STATUS_ACKNOWLEDGED]);
        } elseif ($status !== 'all') {
            $query->where('status', $status);
        }

        if (! empty($data['severity'])) {
            $query->where('severity', $data['severity']);
        }
        if (! empty($data['source'])) {
            $query->where('source', $data['source']);
        }

        $incidents = $query
            ->orderByRaw($this->severityOrderSql())
            ->orderByDesc('last_seen_at')
            ->paginate($data['per_page'] ?? 25);

        return response()->json([
            'ok' => true,
            'data' => collect($incidents->items())->map(fn (Incident $i) => $this->summarize($i))->all(),
            'meta' => [
                'current_page' => $incidents->currentPage(),
                'last_page' => $incidents->lastPage(),
                'total' => $incidents->total(),
            ],
        ]);
    }

    /** Un incidente con su evidencia y su historia completa. */
    public function show(Request $request, int $id): JsonResponse
    {
        if ($denial = $this->deny($request)) {
            return $denial;
        }

        $incident = Incident::with(['events' => fn ($q) => $q->orderBy('id')])->find($id);

        if ($incident === null) {
            return response()->json(['ok' => false, 'code' => 'incident_not_found'], 404);
        }

        return response()->json([
            'ok' => true,
            'data' => array_merge($this->summarize($incident), [
                'evidence' => $incident->evidence,
                'correlation_ids' => $incident->correlation_ids,
                'root_cause' => $incident->root_cause,
                'confidence' => $incident->confidence,
                'recommended_action' => $incident->recommended_action,
                'prevention' => $incident->prevention,
                'analyzed_by' => $incident->analyzed_by,
                'resolution' => $incident->resolution,
                // Qué se puede ejecutar sin riesgo sobre ESTE incidente.
                'available_remediations' => $this->availableRemediations($incident),
                'timeline' => $incident->events->map(fn (IncidentEvent $e) => [
                    'kind' => $e->kind,
                    'actor' => $e->actor,
                    'summary' => $e->summary,
                    'payload' => $e->payload,
                    'at' => $e->created_at?->toIso8601String(),
                ])->all(),
            ]),
        ]);
    }

    /** Pasada manual del detector desde el panel. */
    public function scan(Request $request, ChannelHealthDetector $detector): JsonResponse
    {
        if ($denial = $this->deny($request)) {
            return $denial;
        }

        $incidents = $detector->scan();

        return response()->json([
            'ok' => true,
            'data' => [
                'detected' => count($incidents),
                'incidents' => array_map(fn (Incident $i) => $this->summarize($i), $incidents),
            ],
        ]);
    }

    /** Reconocer, resolver o ignorar. */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        if ($denial = $this->deny($request)) {
            return $denial;
        }

        $data = $request->validate([
            'status' => ['required', 'string', 'in:acknowledged,resolved,ignored,open'],
            'resolution' => ['nullable', 'string', 'max:2000'],
        ]);

        $incident = Incident::find($id);
        if ($incident === null) {
            return response()->json(['ok' => false, 'code' => 'incident_not_found'], 404);
        }

        $admin = $this->admin($request);

        $changes = ['status' => $data['status']];
        if ($data['status'] === Incident::STATUS_ACKNOWLEDGED) {
            $changes['acknowledged_at'] = now();
            $changes['assigned_to_admin_id'] = $admin?->id;
        }
        if ($data['status'] === Incident::STATUS_RESOLVED) {
            $changes['resolved_at'] = now();
            $changes['resolution'] = $data['resolution'] ?? null;
        }

        $incident->forceFill($changes)->save();

        $this->recorder->addEvent(
            $incident,
            IncidentEvent::KIND_STATUS,
            'Estado cambiado a '.$data['status'].($data['resolution'] ? ': '.$data['resolution'] : ''),
            ['status' => $data['status']],
            'admin:'.($admin?->id ?? '?'),
        );

        return response()->json(['ok' => true, 'data' => $this->summarize($incident->fresh())]);
    }

    /**
     * Ejecuta una acción de la allowlist. Requiere que un humano la pida desde
     * el panel: aquí no hay nada automático.
     */
    public function remediate(Request $request, int $id, SafeRemediationService $remediation): JsonResponse
    {
        if ($denial = $this->deny($request)) {
            return $denial;
        }

        $data = $request->validate(['action' => ['required', 'string', 'max:60']]);

        $incident = Incident::find($id);
        if ($incident === null) {
            return response()->json(['ok' => false, 'code' => 'incident_not_found'], 404);
        }

        $admin = $this->admin($request);
        $result = $remediation->run($incident, $data['action'], 'admin:'.($admin?->id ?? '?'));

        return response()->json([
            'ok' => $result['ok'],
            'data' => $result,
        ], $result['ok'] ? 200 : 422);
    }

    /** @return array<string,mixed> */
    private function summarize(Incident $incident): array
    {
        return [
            'id' => $incident->id,
            'source' => $incident->source,
            'kind' => $incident->kind,
            'title' => $incident->title,
            'severity' => $incident->severity,
            'status' => $incident->status,
            'occurrences' => $incident->occurrences,
            'affected_conversations' => $incident->affected_conversations,
            'affected_messages' => $incident->affected_messages,
            'first_seen_at' => $incident->first_seen_at?->toIso8601String(),
            'last_seen_at' => $incident->last_seen_at?->toIso8601String(),
            'release' => $incident->release,
            'assigned_to_admin_id' => $incident->assigned_to_admin_id,
        ];
    }

    /**
     * Qué acciones tienen sentido para este incidente concreto. La evidencia del
     * detector ya propone una; si no está en la allowlist vigente, no se ofrece.
     *
     * @return array<int,string>
     */
    private function availableRemediations(Incident $incident): array
    {
        $suggested = data_get($incident->evidence, 'safe_remediation');
        $allowlist = (array) config('observability.remediation.allowlist', []);

        $candidates = match ($incident->kind) {
            'events_stuck', 'events_dead' => ['replay_webhook_event'],
            'downloads_failing' => ['retry_media_download'],
            'failed_jobs' => ['retry_failed_job'],
            default => [],
        };

        if (is_string($suggested) && $suggested !== '') {
            $candidates[] = $suggested;
        }

        return array_values(array_intersect(array_unique($candidates), $allowlist));
    }

    /**
     * Orden por gravedad en SQL. Se escribe a mano porque el valor es texto y
     * un ORDER BY alfabético pondría 'critical' después de 'low'.
     */
    private function severityOrderSql(): string
    {
        return "CASE severity
            WHEN 'critical' THEN 1
            WHEN 'high' THEN 2
            WHEN 'medium' THEN 3
            ELSE 4 END";
    }

    private function admin(Request $request): ?Admin
    {
        $admin = $request->attributes->get('auth_admin');

        return $admin instanceof Admin ? $admin : null;
    }

    /** Solo administradores plenos y activos. */
    private function deny(Request $request): ?JsonResponse
    {
        $admin = $this->admin($request);

        if (! $admin instanceof Admin) {
            return response()->json([
                'ok' => false, 'code' => 'guard_requires_admin',
                'message' => 'IRON GUARD requiere una sesión de administrador.',
            ], 401);
        }

        if (! $admin->isActive()) {
            return response()->json([
                'ok' => false, 'code' => 'guard_admin_inactive',
                'message' => 'Tu cuenta no está activa.',
            ], 403);
        }

        if (! in_array(mb_strtolower(trim((string) $admin->role)), self::ALLOWED_ROLES, true)) {
            return response()->json([
                'ok' => false, 'code' => 'guard_forbidden',
                'message' => 'IRON GUARD es solo para administradores.',
            ], 403);
        }

        return null;
    }
}
