<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\AdminRole;
use App\Support\Access\AdminActor;
use App\Support\Access\CrmPermission;
use App\Support\Access\TrainerMemberScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Cuentas administrativas del CRM: quién puede entrar y con qué rol.
 *
 * Usa la arquitectura de autenticación que ya existe —email y contraseña
 * verificados con `Hash` en AdminAuthController— y no inventa una paralela.
 *
 * La contraseña la ELIGE quien crea la cuenta y es definitiva: no caduca ni
 * obliga a cambiarla al entrar. Antes se llamaba «temporal» y se generaba
 * siempre, pero no había nada detrás de ese nombre —ni marca de caducidad ni
 * `must_change_password`—, así que la que salía en pantalla ya era la de
 * siempre y el aviso de «cámbiala al entrar» no lo respaldaba ningún mecanismo.
 * Si no se envía ninguna, se genera una fuerte, que es el atajo cómodo.
 *
 * En cualquiera de los dos casos se entrega UNA sola vez: se guarda hasheada
 * (cast `hashed`) y no se puede volver a consultar.
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
    /** Longitud mínima de una contraseña elegida a mano. */
    private const MIN_PASSWORD = 8;

    /** Tope defensivo: bcrypt solo mira los primeros 72 bytes. */
    private const MAX_PASSWORD = 72;

    /** Longitud de las que genera el sistema cuando no se elige ninguna. */
    private const GENERATED_LENGTH = 16;

    /**
     * La que envíe quien crea la cuenta, o una fuerte si no envía ninguna.
     * Devuelve también si fue generada, para que la pantalla lo diga.
     *
     * @return array{0: string, 1: bool}
     */
    private function resolvePassword(?string $elegida): array
    {
        $limpia = trim((string) $elegida);
        if ($limpia !== '') {
            return [$limpia, false];
        }

        return [Str::password(self::GENERATED_LENGTH, symbols: false), true];
    }

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

    /** POST /api/admin/users  { name, email, role, status?, password? } */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:120'],
            'email' => ['required', 'email', 'max:160', 'unique:admins,email'],
            'role' => ['required', 'string', Rule::in(AdminRole::assignableNames())],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'password' => ['nullable', 'string', 'min:'.self::MIN_PASSWORD, 'max:'.self::MAX_PASSWORD],
            'trainer_id' => $this->trainerLinkRules($request->input('role')),
        ]);

        if ($bloqueo = $this->denyIfEscalating($request, $data['role'])) {
            return $bloqueo;
        }

        [$clave, $generada] = $this->resolvePassword($data['password'] ?? null);

        $admin = Admin::create([
            'uuid' => (string) Str::uuid(),
            'name' => trim($data['name']),
            'email' => strtolower(trim($data['email'])),
            'password' => $clave,
            'role' => $data['role'],
            'trainer_id' => $this->trainerLinkFor($data['role'], $data['trainer_id'] ?? null),
            'status' => $data['status'] ?? 'active',
        ]);

        return response()->json([
            'ok' => true,
            'data' => $this->present($admin),
            // Única vez que se ve en claro: a partir de aquí solo existe hasheada.
            'password' => $clave,
            'generated' => $generada,
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
            // El rol que va a QUEDAR, no el que se envía: quitarle el rol de
            // entrenador a una cuenta no puede exigirle un vínculo, y ponérselo
            // sin enviar `role` —ya lo tenía— sí.
            'trainer_id' => $this->trainerLinkRules($request->input('role', $admin->role)),
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

        $rolFinal = $data['role'] ?? $admin->role;
        $data['trainer_id'] = $this->trainerLinkFor(
            $rolFinal,
            array_key_exists('trainer_id', $data) ? $data['trainer_id'] : $admin->trainer_id,
        );

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
     * POST /api/admin/users/{admin}/reset-password  { password? }
     *
     * Fija una contraseña nueva y definitiva. No se puede consultar la anterior
     * —está hasheada— así que restablecer es la única vía, y queda a la vista
     * de quien la ejecuta. Mismo criterio que al crear: la que se envíe, o una
     * generada si no se envía ninguna.
     */
    public function resetPassword(Request $request, Admin $admin): JsonResponse
    {
        $data = $request->validate([
            'password' => ['nullable', 'string', 'min:'.self::MIN_PASSWORD, 'max:'.self::MAX_PASSWORD],
        ]);

        [$clave, $generada] = $this->resolvePassword($data['password'] ?? null);
        $admin->update(['password' => $clave]);

        return response()->json([
            'ok' => true,
            'data' => $this->present($admin->fresh()),
            'password' => $clave,
            'generated' => $generada,
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
            'trainer_id' => $admin->trainer_id,
            'trainer_name' => $admin->trainer_id ? $admin->trainer?->full_name : null,
        ];
    }

    /**
     * Reglas del vínculo con un entrenador, según el rol que va a quedar.
     *
     * Una cuenta de entrenador SIN vincular no sabe a qué socios alcanza, y
     * {@see TrainerMemberScope} —correctamente— no le da ninguno. Sería una
     * cuenta que entra al CRM y no ve nada, sin explicación. Mejor no dejar
     * crearla.
     *
     * `unique` porque un entrenador tiene UNA cuenta: dos serían dos sesiones
     * con el mismo alcance y sin forma de saber cuál usó cada quien.
     *
     * @return list<mixed>
     */
    private function trainerLinkRules(?string $rol): array
    {
        if ($rol !== CrmPermission::ROLE_ENTRENADOR) {
            // Para el resto de roles el campo no significa nada. Se acepta que
            // llegue —el formulario es el mismo— y se descarta en
            // `trainerLinkFor()`, en vez de responder un error que obligaría al
            // CRM a limpiarlo antes de enviar.
            return ['nullable'];
        }

        return [
            'required',
            'integer',
            Rule::exists('trainers', 'id'),
            Rule::unique('admins', 'trainer_id')->ignore(request()->route('admin')),
        ];
    }

    /**
     * El vínculo que se guarda. Solo las cuentas de entrenador lo conservan:
     * dejarlo puesto tras cambiar de rol crearía una asociación huérfana que
     * volvería a activarse sola si algún día se le devuelve el rol.
     */
    private function trainerLinkFor(string $rol, mixed $trainerId): ?int
    {
        if ($rol !== CrmPermission::ROLE_ENTRENADOR) {
            return null;
        }

        return $trainerId === null ? null : (int) $trainerId;
    }
}
