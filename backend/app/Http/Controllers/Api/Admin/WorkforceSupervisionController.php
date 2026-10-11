<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuthorizedDevice;
use App\Models\ClassEvent;
use App\Models\Identity;
use App\Models\Workday;
use App\Models\WorkdayEvent;
use App\Services\Audit\AuditTrail;
use App\Services\Workforce\EmployeeDirectory;
use App\Services\Workforce\WorkdayService;
use App\Support\Access\AdminActor;
use App\Support\Access\CrmPermission;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Supervisión de jornadas laborales (CRM, pestaña «Empleados»). Contrato §7.1.
 * Leer: `workforce.view`. Corregir incidencias: `workforce.manage`.
 */
class WorkforceSupervisionController extends Controller
{
    public function __construct(
        private readonly WorkdayService $workdays,
        private readonly EmployeeDirectory $directory,
        private readonly AuditTrail $auditTrail,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'identity' => ['nullable', 'uuid'],
            'position' => ['nullable', 'string', 'max:40'],
            'status' => ['nullable', 'in:open,closed,incident'],
            'device' => ['nullable', 'uuid'],
            'method' => ['nullable', 'in:face,enrollment,admin_code,admin'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        [$from, $to] = $this->range($data);
        // El cursor se lee ANTES que los datos: un hecho que se confirme en medio
        // queda por encima del cursor y el CRM lo recibe; leído después, se
        // perdería hasta el siguiente.
        $cursor = (int) (ClassEvent::max('id') ?? 0);

        // whereDate y no where: Eloquent guarda la fecha como «Y-m-d H:i:s» en
        // SQLite (texto) y la comparación con «Y-m-d» dejaba fuera el último
        // día. En PostgreSQL la columna es `date`; whereDate vale en los dos.
        $query = Workday::query()
            ->whereDate('business_date', '>=', $from)
            ->whereDate('business_date', '<=', $to)
            ->with(['clockInDevice', 'clockOutDevice']);

        if (! empty($data['identity'])) {
            $query->where('identity_id', (int) Identity::query()->where('uuid', $data['identity'])->value('id'));
        }
        if (! empty($data['position'])) {
            $query->where('position', $data['position']);
        }
        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }
        if (! empty($data['device'])) {
            $deviceId = (int) AuthorizedDevice::query()->where('uuid', $data['device'])->value('id');
            $query->where(fn ($q) => $q->where('clock_in_device_id', $deviceId)->orWhere('clock_out_device_id', $deviceId));
        }
        if (! empty($data['method'])) {
            $query->where(fn ($q) => $q->where('clock_in_method', $data['method'])->orWhere('clock_out_method', $data['method']));
        }

        $page = $query->orderByDesc('business_date')->orderByDesc('clock_in_at')->orderByDesc('id')
            ->paginate((int) ($data['per_page'] ?? 25));
        $employees = $this->workdays->employeesFor(collect($page->items()));

        return response()->json([
            'ok' => true,
            'data' => collect($page->items())->map(fn (Workday $w) => $this->workdays->toArray($w) + [
                'employee' => $employees[(int) $w->identity_id] ?? null,
            ])->values(),
            'meta' => [
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
            'events_cursor' => $cursor,
        ]);
    }

    public function summary(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);
        [$from, $to] = $this->range($data);
        $inRange = fn () => Workday::query()->whereDate('business_date', '>=', $from)->whereDate('business_date', '<=', $to);

        return response()->json(['ok' => true, 'data' => [
            'eligible_employees' => $this->directory->everyone(onlyActive: true)->filter->registersWorkday()->count(),
            'employees_with_records' => $inRange()->distinct()->count('identity_id'),
            // Ahora mismo, no en el rango: una jornada abierta es de hoy.
            'currently_in' => Workday::query()->open()->count(),
            'finished' => $inRange()->where('status', Workday::STATUS_CLOSED)->count(),
            // Pendientes de cualquier día: una incidencia sin resolver no
            // desaparece porque se mire otra semana.
            'incidents_open' => Workday::query()->where('status', Workday::STATUS_INCIDENT)->whereNull('resolved_at')->count(),
            // Sin horarios laborales aprobados no hay puntualidad que calcular.
            'punctuality' => null,
        ]]);
    }

    public function show(Request $request, string $workday): JsonResponse
    {
        $w = Workday::query()->where('uuid', $workday)->with(['clockInDevice', 'clockOutDevice'])->firstOrFail();
        $admin = AdminActor::from($request);
        $technical = CrmPermission::allows($admin, 'devices.view');
        // La distancia sale del rostro: es dato biométrico, no técnico (F6).
        $biometric = CrmPermission::allows($admin, 'biometrics.view');
        $employee = $this->workdays->employeesFor(collect([$w]))[(int) $w->identity_id] ?? null;

        $events = WorkdayEvent::query()->where('workday_id', $w->id)->with('device')->orderBy('occurred_at')->orderBy('id')->get()
            ->map(fn (WorkdayEvent $e) => [
                'id' => $e->uuid,
                'type' => $e->type,
                'at' => $e->occurred_at?->toIso8601String(),
                'method' => $e->method,
                'device' => $e->device ? $e->device->toBrief() + ['verified' => (bool) $e->device_verified] : null,
                'actor' => ['type' => $e->actor_type, 'name' => $e->actor_type === WorkdayEvent::ACTOR_EMPLOYEE ? ($employee['full_name'] ?? null) : $e->actor_name],
                'note' => $e->metadata['note'] ?? null,
                'technical' => $technical ? [
                    'ip_masked' => self::maskIp($e->ip_address),
                    'app_version' => $e->metadata['app_version'] ?? null,
                    'device_platform' => $e->metadata['platform'] ?? null,
                    'face_distance' => $biometric ? $e->face_distance : null,
                ] : null,
            ]);

        return response()->json(['ok' => true, 'data' => $this->workdays->toArray($w) + [
            'employee' => $employee,
            'events' => $events,
        ]]);
    }

    public function resolve(Request $request, string $workday): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:set_clock_out,dismiss'],
            // ISO 8601 CON desfase: sin él la hora se leería en UTC y no en la
            // del gimnasio (revisión de seguridad 2026-10-11, I6).
            'clock_out_at' => ['required_if:action,set_clock_out', 'nullable', 'date', 'regex:/(Z|[+-]\d{2}:?\d{2})$/'],
            'note' => ['required', 'string', 'min:5', 'max:500'],
        ]);
        $w = Workday::query()->where('uuid', $workday)->firstOrFail();

        $resolved = $this->workdays->resolve(
            $w,
            $data['action'],
            $data['clock_out_at'] ?? null,
            $data['note'],
            AdminActor::id($request),
            AdminActor::label($request),
            $request,
        );

        $this->auditTrail->record($request, [
            'action' => 'update',
            'module' => 'workforce',
            'entity' => 'workday',
            'entity_id' => $resolved->uuid,
            'summary' => $data['action'] === 'set_clock_out' ? 'Corrigió la salida de una jornada con incidencia' : 'Descartó una incidencia de jornada',
            'metadata' => ['action' => $data['action'], 'clock_out_at' => $resolved->clock_out_at?->toIso8601String()],
        ]);

        $employee = $this->workdays->employeesFor(collect([$resolved]))[(int) $resolved->identity_id] ?? null;

        return response()->json(['ok' => true, 'data' => $this->workdays->toArray($resolved) + ['employee' => $employee]]);
    }

    /**
     * Días del gimnasio. Por defecto, la última semana.
     *
     * @param  array<string, mixed>  $data
     * @return array{0:string, 1:string}
     */
    private function range(array $data): array
    {
        $today = CarbonImmutable::now(WorkdayService::TZ);
        $from = $data['from'] ?? $today->subDays(7)->toDateString();
        $to = $data['to'] ?? $today->toDateString();
        if ($from > $to) {
            abort(response()->json(['message' => 'La fecha inicial no puede ser posterior a la final.', 'errors' => ['from' => ['La fecha inicial no puede ser posterior a la final.']]], 422));
        }
        $max = (int) config('workforce.workday.max_range_days', 93);
        if (CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) > $max) {
            abort(response()->json(['message' => "Consulta como mucho {$max} días seguidos.", 'errors' => ['to' => ["Consulta como mucho {$max} días seguidos."]]], 422));
        }

        return [$from, $to];
    }

    public static function maskIp(?string $ip): ?string
    {
        if ($ip === null || $ip === '') {
            return null;
        }
        if (str_contains($ip, '.')) {
            $parts = explode('.', $ip);

            return $parts[0].'.'.($parts[1] ?? '•').'.•.•';
        }

        return substr($ip, 0, 9).'…';
    }
}
