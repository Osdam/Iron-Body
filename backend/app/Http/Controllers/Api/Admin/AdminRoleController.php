<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\AdminRole;
use App\Models\RolePermission;
use App\Support\Access\AdminActor;
use App\Support\Access\CrmPermission;
use App\Support\Access\RolePermissionPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Administración de ROLES del CRM: crear, renombrar, describir y archivar.
 *
 * Los permisos de cada rol se siguen editando en RolePermissionController; aquí
 * solo vive el catálogo. Separarlos es deliberado: crear un rol y concederle
 * capacidades son dos decisiones distintas, y un rol nuevo debe nacer SIN nada.
 *
 * Todas las rutas exigen `roles.manage`. Quien administra roles puede, de
 * hecho, concederse permisos: es el privilegio más alto del CRM y no debe
 * derivarse de ningún otro.
 */
class AdminRoleController extends Controller
{
    /** GET /api/admin/roles */
    public function index(): JsonResponse
    {
        $roles = AdminRole::query()->orderByDesc('is_system')->orderBy('name')->get();

        return response()->json([
            'ok' => true,
            'data' => $roles->map(fn (AdminRole $r) => $r->toCrmArray())->all(),
        ]);
    }

    /** POST /api/admin/roles  { name, description? } */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:60', 'unique:admin_roles,name'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $actor = AdminActor::from($request);

        // Nace sin permisos. Que un rol nuevo heredara algo por parecido de
        // nombre sería conceder capacidades que nadie pidió.
        $role = AdminRole::create([
            'name' => trim($data['name']),
            'description' => $data['description'] ?? null,
            'is_system' => false,
            'created_by' => $actor?->id,
            'created_by_name' => $actor?->name,
        ]);

        return response()->json(['ok' => true, 'data' => $role->toCrmArray()], 201);
    }

    /** PATCH /api/admin/roles/{role}  { name?, description? } */
    public function update(Request $request, AdminRole $role): JsonResponse
    {
        if ($role->is_system && $request->filled('name') && $request->input('name') !== $role->name) {
            // El código nombra estos roles por constante (Admin::ROLE_*).
            // Renombrarlos rompería la autorización de forma silenciosa.
            return response()->json([
                'ok' => false,
                'code' => 'system_role_immutable',
                'message' => 'Los roles del sistema no se pueden renombrar. Sí puedes cambiar sus permisos.',
            ], 422);
        }

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:3', 'max:60', Rule::unique('admin_roles', 'name')->ignore($role->id)],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        // Renombrar arrastra las dos referencias por valor. En transacción: a
        // medias dejaría admins apuntando a un rol que ya no existe.
        if (isset($data['name']) && $data['name'] !== $role->name) {
            $anterior = $role->name;
            $nuevo = trim($data['name']);

            DB::transaction(function () use ($role, $anterior, $nuevo, $data) {
                $role->update(['name' => $nuevo] + array_intersect_key($data, ['description' => null]));
                Admin::where('role', $anterior)->update(['role' => $nuevo]);
                RolePermission::where('role', $anterior)->update(['role' => $nuevo]);
            });

            app(RolePermissionPolicy::class)->flush();

            return response()->json(['ok' => true, 'data' => $role->fresh()->toCrmArray()]);
        }

        $role->update(array_intersect_key($data, ['description' => null]));

        return response()->json(['ok' => true, 'data' => $role->fresh()->toCrmArray()]);
    }

    /**
     * POST /api/admin/roles/{role}/archive — retira el rol de la lista de
     * asignables SIN borrarlo.
     *
     * No se borra nunca: los admins que lo tengan conservarían un rol
     * inexistente y se perdería la traza de qué permisos tuvieron. Y si aún hay
     * gente asignada, ni siquiera se archiva: primero hay que reasignarla.
     */
    public function archive(AdminRole $role): JsonResponse
    {
        if ($role->is_system) {
            return response()->json([
                'ok' => false,
                'code' => 'system_role_immutable',
                'message' => 'Los roles del sistema no se pueden archivar.',
            ], 422);
        }

        $enUso = $role->adminsCount();
        if ($enUso > 0) {
            return response()->json([
                'ok' => false,
                'code' => 'role_in_use',
                'message' => "No se puede archivar: {$enUso} administrador(es) tienen este rol. Reasígnalos primero.",
                'admins_count' => $enUso,
            ], 409);
        }

        $role->update(['archived_at' => now()]);

        return response()->json(['ok' => true, 'data' => $role->fresh()->toCrmArray()]);
    }

    /** POST /api/admin/roles/{role}/restore */
    public function restore(AdminRole $role): JsonResponse
    {
        $role->update(['archived_at' => null]);

        return response()->json(['ok' => true, 'data' => $role->fresh()->toCrmArray()]);
    }

    /**
     * GET /api/admin/roles/assignable — nombres que el CRM puede ofrecer al
     * editar un administrador. Excluye los archivados.
     */
    public function assignable(): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'data' => AdminRole::assignableNames(),
            'permissions' => CrmPermission::all(),
        ]);
    }
}
