<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Support\Access\AdminActor;
use App\Support\Access\CrmPermission;
use App\Support\Access\RolePermissionPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Configuración → Roles: la política de permisos, de verdad.
 *
 * Antes esta pantalla guardaba en `localStorage` y no llamaba al servidor:
 * quien creía estar revocando un permiso para la organización no lo revocaba en
 * ninguna parte. Ahora lo que se guarda aquí es lo que autoriza el backend.
 *
 * Solo Super Admin. No es `settings.roles` a secas: quien puede repartir
 * permisos puede darse cualquiera, así que la puerta es el rol más alto y no un
 * permiso que a su vez podría concederse desde esta misma pantalla.
 */
class RolePermissionController extends Controller
{
    public function __construct(private readonly RolePermissionPolicy $policy) {}

    /** GET /api/admin/roles/permissions — matriz rol × permiso. */
    public function index(Request $request): JsonResponse
    {
        if ($deny = $this->denyIfNotSuperAdmin($request)) {
            return $deny;
        }

        return response()->json([
            'roles' => array_values(Admin::ROLES),
            'permissions' => $this->catalog(),
            'matrix' => $this->policy->matrix(),
        ]);
    }

    /**
     * PUT /api/admin/roles/permissions  { role, permission, granted }
     *
     * Un permiso por llamada a propósito: hace el registro de auditoría exacto
     * y evita que un envío parcial deje la política a medias.
     */
    public function update(Request $request): JsonResponse
    {
        if ($deny = $this->denyIfNotSuperAdmin($request)) {
            return $deny;
        }

        $data = $request->validate([
            // Vocabulario cerrado en ambos campos: sin esto se podrían insertar
            // roles o permisos inventados que después nadie sabría interpretar.
            'role' => ['required', Rule::in(Admin::ROLES)],
            'permission' => ['required', Rule::in(CrmPermission::all())],
            'granted' => ['required', 'boolean'],
        ]);

        $actor = AdminActor::from($request);

        // Super Admin no se toca: es la cuenta que puede recuperar el sistema si
        // alguien se equivoca repartiendo permisos. Dejar que se revoque a sí
        // mismo el acceso permitiría cerrar la puerta desde dentro.
        if ($data['role'] === Admin::ROLE_SUPER_ADMIN) {
            return response()->json([
                'ok' => false,
                'code' => 'role_locked',
                'message' => 'Los permisos de Super Admin no se pueden modificar.',
            ], 422);
        }

        $this->policy->set($data['role'], $data['permission'], (bool) $data['granted'], $actor);
        $this->audit($request, $data, $actor);

        return response()->json([
            'ok' => true,
            'matrix' => $this->policy->matrix(),
        ]);
    }

    /**
     * Solo Super Admin reparte permisos.
     *
     * Si esto se controlara con un permiso normal, ese permiso podría
     * concederse desde esta misma pantalla y cualquiera podría escalar.
     */
    private function denyIfNotSuperAdmin(Request $request): ?JsonResponse
    {
        $admin = AdminActor::from($request);

        if ($admin instanceof Admin && $admin->hasRole(Admin::ROLE_SUPER_ADMIN) && $admin->isActive()) {
            return null;
        }

        Log::warning('auth:admin:roles_denied', [
            'admin_id' => $admin?->id,
            'admin_role' => $admin?->role ?? 'shared_token',
            'method' => $request->method(),
            'ip' => $request->ip(),
        ]);

        return response()->json([
            'ok' => false,
            'code' => 'forbidden',
            'message' => 'Solo un Super Admin puede consultar o modificar la política de permisos.',
        ], 403);
    }

    /** Catálogo legible para que el CRM no tenga que inventar las etiquetas. */
    private function catalog(): array
    {
        $labels = [
            CrmPermission::CAJA_VIEW => ['Caja', 'Ver caja y ventas'],
            CrmPermission::CAJA_SELL => ['Caja', 'Abrir turno y cobrar'],
            CrmPermission::CAJA_MANAGE => ['Caja', 'Anular ventas y cierre forzado'],
            CrmPermission::BILLING_VIEW => ['Facturación', 'Ver comprobantes'],
            CrmPermission::BILLING_MANAGE => ['Facturación', 'Emitir, anular y cambiar tarifas'],
            CrmPermission::INVENTORY_VIEW => ['Inventario', 'Ver productos y movimientos'],
            CrmPermission::INVENTORY_CREATE => ['Inventario', 'Crear productos'],
            CrmPermission::INVENTORY_EDIT => ['Inventario', 'Editar y mover existencias'],
            CrmPermission::INVENTORY_DELETE => ['Inventario', 'Archivar productos'],
        ];

        return array_map(
            static fn (string $p) => [
                'key' => $p,
                'group' => $labels[$p][0] ?? 'Otros',
                'label' => $labels[$p][1] ?? $p,
            ],
            CrmPermission::all(),
        );
    }

    /** Un cambio de permisos es un hecho de seguridad: queda en la traza. */
    private function audit(Request $request, array $data, ?Admin $actor): void
    {
        try {
            AuditLog::create([
                'action' => 'settings',
                'module' => 'Roles',
                'entity' => 'permiso',
                'entity_id' => $data['role'].':'.$data['permission'],
                'target_name' => $data['role'],
                'actor_id' => (string) $actor?->id,
                'actor_name' => $actor?->name ?? 'Sistema',
                'actor_role' => $actor?->role,
                'summary' => ($data['granted'] ? 'Concedido ' : 'Revocado ')
                    .$data['permission'].' a '.$data['role'],
                'metadata' => $data,
                'ip_address' => $request->ip(),
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            // La auditoría es best-effort: no debe impedir el cambio. Pero que
            // haya fallado tiene que constar en algún sitio.
            Log::warning('No se pudo auditar el cambio de permisos', ['error' => $e->getMessage()]);
        }
    }
}
