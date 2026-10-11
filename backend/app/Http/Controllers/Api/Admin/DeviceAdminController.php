<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuthorizedDevice;
use App\Models\DeviceSecurityEvent;
use App\Services\Audit\AuditTrail;
use App\Services\Workforce\DeviceRegistry;
use App\Support\Access\AdminActor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Dispositivos autorizados (CRM, pestaña «Dispositivos»). Contrato §7.4.
 * Consultar: `devices.view`. Vincular, renombrar, revocar: `devices.manage`.
 */
class DeviceAdminController extends Controller
{
    public function __construct(
        private readonly DeviceRegistry $devices,
        private readonly AuditTrail $auditTrail,
    ) {}

    public function index(): JsonResponse
    {
        $devices = AuthorizedDevice::query()
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
            ->orderBy('name')
            ->get()
            ->map(fn (AuthorizedDevice $d) => self::present($d));

        return response()->json(['ok' => true, 'data' => $devices]);
    }

    public function pairingCode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:120'],
            'location' => ['nullable', 'string', 'max:120'],
        ]);
        $issued = $this->devices->issuePairingCode($data['name'], $data['location'] ?? null, AdminActor::id($request), AdminActor::label($request));
        $this->auditTrail->record($request, [
            'action' => 'create',
            'module' => 'devices',
            'entity' => 'pairing_code',
            'target_name' => $data['name'],
            'summary' => 'Generó un código para vincular un dispositivo',
        ]);

        return response()->json(['ok' => true, 'code' => $issued['code'], 'expires_at' => $issued['expires_at']->toIso8601String()], 201);
    }

    public function update(Request $request, string $device): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:3', 'max:120'],
            'location' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);
        $model = AuthorizedDevice::query()->where('uuid', $device)->firstOrFail();
        $before = $model->getOriginal();
        $this->devices->rename($model, $data['name'] ?? null, $data['location'] ?? null);
        $this->auditTrail->record($request, [
            'action' => 'update',
            'module' => 'devices',
            'entity' => 'authorized_device',
            'entity_id' => $model->uuid,
            'target_name' => $model->name,
            'summary' => 'Editó un dispositivo autorizado',
            'changes' => $this->auditTrail->changesOf($model, $before, ['name', 'location']),
        ]);

        return response()->json(['ok' => true, 'data' => self::present($model->fresh())]);
    }

    public function revoke(Request $request, string $device): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        $model = AuthorizedDevice::query()->where('uuid', $device)->firstOrFail();
        $this->devices->revoke($model, $data['reason'], AdminActor::id($request), AdminActor::label($request));
        $this->auditTrail->record($request, [
            'action' => 'status',
            'module' => 'devices',
            'entity' => 'authorized_device',
            'entity_id' => $model->uuid,
            'target_name' => $model->name,
            'summary' => 'Revocó un dispositivo autorizado',
            'metadata' => ['reason' => $data['reason']],
        ]);

        return response()->json(['ok' => true, 'data' => self::present($model->fresh())]);
    }

    public function securityEvents(Request $request): JsonResponse
    {
        $data = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $page = DeviceSecurityEvent::query()->with('device')->orderByDesc('id')->paginate((int) ($data['per_page'] ?? 25));

        return response()->json([
            'ok' => true,
            'data' => collect($page->items())->map(fn (DeviceSecurityEvent $e) => [
                'id' => $e->id,
                'at' => $e->created_at?->toIso8601String(),
                'event' => $e->event,
                'label' => DeviceSecurityEvent::LABELS[$e->event] ?? $e->event,
                'device' => $e->device?->toBrief(),
                'claimed_device_id' => $e->claimed_device_id,
                'operation' => $e->operation,
                'ip_masked' => WorkforceSupervisionController::maskIp($e->ip_address),
            ])->values(),
            'meta' => [
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    public static function present(AuthorizedDevice $d): array
    {
        return [
            'id' => $d->uuid,
            'name' => $d->name,
            'location' => $d->location,
            'status' => $d->status,
            'platform' => $d->platform,
            'manufacturer' => $d->manufacturer,
            'model' => $d->model,
            'os_version' => $d->os_version,
            'app_version' => $d->app_version,
            'key' => [
                'algorithm' => $d->key_algorithm,
                'fingerprint' => substr((string) $d->key_fingerprint, 0, 16),
                'storage_declared' => $d->key_storage_declared,
                'attestation' => ['status' => $d->attestation_status, 'security_level' => $d->attestation_security_level],
            ],
            'registered_at' => $d->registered_at?->toIso8601String(),
            'registered_by' => $d->registered_by_name,
            'last_seen_at' => $d->last_seen_at?->toIso8601String(),
            'revoked' => $d->revoked_at ? ['at' => $d->revoked_at->toIso8601String(), 'by' => $d->revoked_by_name, 'reason' => $d->revoked_reason] : null,
        ];
    }
}
