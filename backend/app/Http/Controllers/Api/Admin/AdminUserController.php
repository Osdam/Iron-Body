<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\AdminRole;
use App\Support\Access\AdminActor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Cuentas administrativas del CRM: quién puede entrar y con qué rol.
 *
 * Usa la arquitectura de autenticación que ya existe —email y contraseña
 * verificados con `Hash` en AdminAuthController— y no inventa una paralela. La
 * contraseña se genera aquí y se entrega UNA sola vez al crear la cuenta, para
 * que quien la recibe la cambie; no se guarda en claro ni se puede volver a
 * consultar.
 *
 * INVARIANTES que este controlador protege, y que ninguna pantalla puede saltarse:
 *
 *  - Nunca cero Super Admin activos. Ni desactivando al último, ni cambiándole
 *    el rol. Un CRM sin Super Admin es un CRM del que nadie puede recuperar el
 *    control sin tocar la base de datos.
 *  - Nadie se asciende ni se degrada a sí mismo, ni se desactiva a sí mismo.
 *  - Crear o ascender a Super Admin exige serlo. `users.manage` reparte cuentas,
 *    no autoridad máxima.
 *  - No se borra a nadie: se desactiva. Un admin borrado deja huérfano su
 *    rastro de auditoría, que es justo lo que hay que poder consultar después.
 */
class AdminUserController extends Controller
{
    /** GET /api/admin/users */
    public function index(Request $request): JsonResponse
    {
        $admins = Admin::query()
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
            ->orderBy('name')
            ->get();

        return response()->json([
            'ok' => true,
            'data' => $admins->map(fn (Admin $a) => $this->present($a))->all(),
            'roles' => AdminRole::assignableNames(),
        ]);
    }

    /** POST /api/admin/users  { name, email, role, status? } */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:120'],
            'email' => ['required', 'email', 'max:160', 'unique:admins,email'],
            'role' => ['required', 'string', Rule::in(AdminRole::assignableNames())],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]);

        if ($bloqueo = $this->denyIfEscalating($request, $data['role'])) {
            return $bloqueo;
        }

        // Contraseña temporal robusta. Se devuelve una vez y no se guarda en
        // claro: el modelo la castea a `hashed` al asignarla.
        $temporal = Str::password(16, symbols: false);

        $admin = Admin::create([
            'uuid' => (string) Str::uuid(),
            'name' => trim($data['name']),
            'email' => strtolower(trim($data['email'])),
            'password' => $temporal,
            'role' => $data['role'],
            'status' => $data['status'] ?? 'active',
        ]);

        return response()->json([
            'ok' => true,
            'data' => $this->present($admin),
            // Única vez que se ve. La pantalla la muestra para copiarla y
            // advierte de que no volverá a estar disponible.
            'temporary_password' => $temporal,
        ], 201);
    }

    /** PATCH /api/admin/users/{admin}  { name?, email?, role?, status? } */
    public function update(Request $request, Admin $admin): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:3', 'max:120'],
            'email' => ['sometimes', 'email', 'max:160', Rule::unique('admins', 'email')->ignore($admin->id)],
            'role' => ['sometimes', 'string', Rule::in(AdminRole::assignableNames())],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ]);

        $actor = AdminActor::from($request);

        // Cambiarse el propio rol o el propio estado es la vía más corta a una
        // escalación —o a quedarse fuera— y no la ofrece nadie serio.
        if ($actor && $actor->id === $admin->id) {
            foreach (['role', 'status'] as $campo) {
                if (isset($data[$campo]) && $data[$campo] !== $admin->{$campo}) {
                    return response()->json([
                        'ok' => false,
                        'code' => 'cannot_modify_self',
                        'message' => 'No puedes cambiar tu propio rol ni tu propio estado. Pídeselo a otro Super Admin.',
                    ], 422);
                }
            }
        }

        if (isset($data['role']) && ($bloqueo = $this->denyIfEscalating($request, $data['role']))) {
            return $bloqueo;
        }

        // Al último Super Admin activo no se le puede quitar el rol ni apagar.
        $pierdeElRol = isset($data['role']) && $data['role'] !== Admin::ROLE_SUPER_ADMIN;
        $seDesactiva = ($data['status'] ?? null) === 'inactive';
        if (($pierdeElRol || $seDesactiva) && $this->isLastActiveSuperAdmin($admin)) {
            return response()->json([
                'ok' => false,
                'code' => 'last_super_admin',
                'message' => 'Es el único Super Admin activo. Nombra otro antes de cambiar este.',
            ], 422);
        }

        $admin->fill($data)->save();

        return response()->json(['ok' => true, 'data' => $this->present($admin->fresh())]);
    }

    /**
     * POST /api/admin/users/{admin}/status  { status }
     *
     * Activar o desactivar. NO existe borrado: un admin eliminado dejaría sin
     * dueño las entradas de auditoría que firmó.
     */
    public function setStatus(Request $request, Admin $admin): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['active', 'inactive'])]]);

        $actor = AdminActor::from($request);
        if ($actor && $actor->id === $admin->id && $data['status'] === 'inactive') {
            return response()->json([
                'ok' => false,
                'code' => 'cannot_modify_self',
                'message' => 'No puedes desactivarte a ti mismo.',
            ], 422);
        }

        if ($data['status'] === 'inactive' && $this->isLastActiveSuperAdmin($admin)) {
            return response()->json([
                'ok' => false,
                'code' => 'last_super_admin',
                'message' => 'Es el único Super Admin activo. Nombra otro antes de desactivarlo.',
            ], 422);
        }

        $admin->update(['status' => $data['status']]);

        return response()->json(['ok' => true, 'data' => $this->present($admin->fresh())]);
    }

    /**
     * POST /api/admin/users/{admin}/reset-password
     *
     * Genera una contraseña temporal nueva. No se puede consultar la anterior
     * —está hasheada— así que restablecer es la única vía, y queda a la vista
     * de quien la ejecuta.
     */
    public function resetPassword(Admin $admin): JsonResponse
    {
        $temporal = Str::password(16, symbols: false);
        $admin->update(['password' => $temporal]);

        return response()->json([
            'ok' => true,
            'data' => $this->present($admin->fresh()),
            'temporary_password' => $temporal,
        ]);
    }

    /**
     * Solo un Super Admin puede crear o ascender a Super Admin.
     *
     * `users.manage` permite repartir cuentas; no permite fabricar la autoridad
     * máxima del sistema, que es la vía clásica de escalación.
     */
    private function denyIfEscalating(Request $request, string $role): ?JsonResponse
    {
        if ($role !== Admin::ROLE_SUPER_ADMIN) {
            return null;
        }

        $actor = AdminActor::from($request);
        if ($actor?->hasRole(Admin::ROLE_SUPER_ADMIN)) {
            return null;
        }

        return response()->json([
            'ok' => false,
            'code' => 'requires_super_admin',
            'message' => 'Solo un Super Admin puede nombrar a otro Super Admin.',
        ], 403);
    }

    /** ¿Es el único Super Admin activo que queda? */
    private function isLastActiveSuperAdmin(Admin $admin): bool
    {
        if (! $admin->hasRole(Admin::ROLE_SUPER_ADMIN) || ! $admin->isActive()) {
            return false;
        }

        return DB::table('admins')
            ->where('role', Admin::ROLE_SUPER_ADMIN)
            ->where('status', 'active')
            ->where('id', '!=', $admin->id)
            ->doesntExist();
    }

    /** @return array<string,mixed> */
    private function present(Admin $admin): array
    {
        return [
            'id' => $admin->id,
            'name' => $admin->name,
            'email' => $admin->email,
            'role' => $admin->role,
            'status' => $admin->status,
            'active' => $admin->isActive(),
            'last_login_at' => optional($admin->last_login_at)->toIso8601String(),
            'created_at' => optional($admin->created_at)->toIso8601String(),
            'is_last_super_admin' => $this->isLastActiveSuperAdmin($admin),
        ];
    }
}
