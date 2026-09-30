<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\AdminRole;
use App\Models\AuditLog;
use App\Services\Audit\AuditTrail;
use App\Support\Access\AdminActor;
use App\Support\Access\CrmPermission;
use App\Support\Access\TrainerMemberScope;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use RuntimeException;

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
 * En la llave de cada usuario el CRM vuelve a llamar «temporal» a la generada,
 * por decisión de producto: es la que el sistema inventa para entregársela a
 * la persona, frente a la «personalizada» que escribe quien administra. Sigue
 * sin caducar y sin obligar a cambiarla: la palabra describe su propósito, no
 * un mecanismo, y la pantalla lo dice así.
 *
 * Al restablecer, la generada se entrega UNA sola vez y la personalizada no
 * vuelve en la respuesta, porque quien la escribió ya la conoce. (El alta,
 * `store()`, todavía devuelve la que se eligió, para su aviso de siempre.)
 * Todas se guardan hasheadas (cast `hashed`) y no se pueden volver a consultar.
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
     * POST /api/admin/users/{admin}/reset-password
     *
     *   {}                                    → contraseña TEMPORAL, generada por el sistema
     *   { password, password_confirmation }   → contraseña PERSONALIZADA, la que define quien administra
     *   operation_id (uuid, opcional)         → lo pone el CRM para reconocer su confirmación en el SSE
     *   un JSON que no se puede leer          → 400, sin cambiar nada (nunca se toma por «{}»)
     *
     * No se puede consultar la anterior —está hasheada—, así que fijar una nueva
     * es la única vía. La temporal se entrega UNA vez, para poder dársela a la
     * persona; la personalizada NO vuelve en la respuesta: quien la escribió ya
     * la conoce, y devolverla solo la dejaba en las herramientas del navegador.
     *
     * Contraseña y auditoría van en UNA transacción, y la fila de `audit_logs`
     * es a la vez la traza y el evento que emite
     * {@see AdminUserRealtimeController}. Si algo falla no queda ni la
     * contraseña nueva ni un evento que diga que se cambió; y si queda, el
     * evento existe, porque es la misma escritura.
     */
    public function resetPassword(Request $request, Admin $admin): JsonResponse
    {
        if ($bloqueo = $this->denyIfTouchingSuperAdmin($request, $admin)) {
            return $bloqueo;
        }

        // Un JSON que no se puede leer NO es un cuerpo vacío. Laravel lo trata
        // como vacío sin avisar, y aquí vacío significa «genera una»: una
        // personalizada pegada con medio emoji acababa sustituida por una
        // aleatoria que nadie vio, respondiendo éxito.
        if ($this->cuerpoIlegible($request)) {
            return response()->json([
                'ok' => false,
                'code' => 'unreadable_body',
                'message' => 'No se pudo leer la contraseña enviada y no se cambió nada. Vuelve a escribirla.',
            ], 400);
        }

        // Personalizada si la clave viene en el cuerpo, aunque venga vacía: una
        // personalizada en blanco es un error de quien la escribe, no una
        // petición de generar otra sin avisarle. Antes, ocho espacios generaban
        // una aleatoria y respondían «generated: true».
        $personalizada = $request->has('password');

        $reglas = ['operation_id' => ['nullable', 'uuid']];
        if ($personalizada) {
            $reglas['password'] = $this->reglasDeClaveElegida();
        }
        $data = $request->validate($reglas, self::MENSAJES_DE_CLAVE);

        // La personalizada se guarda TAL CUAL se escribió y se confirmó: sin
        // recortes que la dejen distinta de la que se teclea al entrar.
        $clave = $personalizada ? (string) $data['password'] : $this->resolvePassword(null)[0];
        $operacion = $data['operation_id'] ?? (string) Str::uuid();

        try {
            [$presentado, $eventoId] = $this->guardarClave($request, $admin, $clave, $personalizada, $operacion);
        } catch (QueryException $e) {
            // El mensaje de una QueryException incluye los valores enlazados, y
            // en el UPDATE uno es el hash nuevo: tal cual, acabaría en
            // laravel.log (y en la respuesta, con APP_DEBUG). Se registra sin
            // ellos, y sin la original como previa, porque el log también la
            // imprime. La transacción ya se revirtió.
            report(new RuntimeException(sprintf(
                'No se pudo guardar la contraseña de la cuenta %d (SQLSTATE %s). SQL: %s',
                $admin->id,
                $e->getCode(),
                $e->getSql(),
            )));

            return response()->json([
                'ok' => false,
                'code' => 'password_not_saved',
                'message' => 'No se pudo guardar la contraseña. Inténtalo de nuevo.',
            ], 500);
        }

        return response()->json([
            'ok' => true,
            'data' => $presentado,
            'password' => $personalizada ? null : $clave,
            'generated' => ! $personalizada,
            'operation_id' => $operacion,
            // Id del evento en el canal de usuarios: el CRM abre el stream desde
            // justo antes y espera ESTE evento, sin carreras con la reconexión.
            'event_id' => $eventoId,
        ]);
    }

    /**
     * Contraseña y traza, en una sola transacción.
     *
     * `$clave` es sensible: sin el atributo, cualquier excepción de aquí dentro
     * la dejaba como argumento en la traza del log.
     *
     * @return array{0: array<string, mixed>, 1: int|null} la cuenta presentada y el id del evento
     */
    private function guardarClave(Request $request, Admin $admin, #[\SensitiveParameter] string $clave, bool $personalizada, string $operacion): array
    {
        return DB::transaction(function () use ($request, $admin, $clave, $personalizada, $operacion): array {
            $admin->update(['password' => $clave]);

            app(AuditTrail::class)->record($request, [
                'action' => 'update',
                'module' => 'Usuarios',
                'entity' => 'usuario',
                'entity_id' => $admin->id,
                'target_name' => $admin->name,
                'summary' => $personalizada
                    ? "Contraseña personalizada definida para {$admin->name}"
                    : "Contraseña temporal generada para {$admin->name}",
                // Solo el NOMBRE del campo: ni la contraseña ni su hash salen de `admins`.
                'changes' => [['field' => 'password']],
                'metadata' => [
                    'event' => AdminUserRealtimeController::PASSWORD_UPDATED,
                    'mode' => $personalizada ? 'custom' : 'generated',
                    'operation_id' => $operacion,
                ],
            ]);

            // El id de la traza es el del evento en el canal. Se busca por la
            // operación, dentro de esta misma transacción: el máximo de la
            // tabla podría ser el de otra operación concurrente.
            $eventoId = AuditLog::query()
                ->where('module', 'Usuarios')
                ->where('entity', 'usuario')
                ->where('entity_id', (string) $admin->id)
                ->where('metadata->operation_id', $operacion)
                ->latest('id')
                ->value('id');

            // También dentro: si presentar la cuenta falla, la contraseña nueva
            // no queda guardada sin que nadie la haya visto.
            return [$this->present($admin->fresh()), $eventoId];
        });
    }

    /**
     * ¿Llegó como JSON algo que no es un objeto JSON legible?
     *
     * `Request::json()` convierte un cuerpo mal formado —un surrogate UTF-16
     * suelto, bytes que no son UTF-8, un JSON cortado— en una entrada vacía, y
     * una lista con elementos (`["x"]`) en una sin claves: las dos, «{}» a la
     * vista. La lista VACÍA sí vale: es como codifica PHP un cuerpo sin campos.
     */
    private function cuerpoIlegible(Request $request): bool
    {
        $crudo = $request->getContent();
        if (! $request->isJson() || trim($crudo) === '') {
            return false;
        }

        $cuerpo = json_decode($crudo);

        return ! ($cuerpo instanceof \stdClass || $cuerpo === []);
    }

    /** Mensajes en español: el backend no tiene traducciones y el CRM los muestra tal cual. */
    private const MENSAJES_DE_CLAVE = [
        'password.required' => 'Escribe la nueva contraseña.',
        'password.string' => 'Escribe la nueva contraseña.',
        'password.min' => 'La contraseña debe tener al menos :min caracteres.',
        'password.confirmed' => 'La confirmación no coincide con la contraseña.',
        'operation_id.uuid' => 'El identificador de la operación no es válido.',
    ];

    /**
     * Reglas de una contraseña ELEGIDA a mano al restablecer.
     *
     * Las mismas cotas que al crear (MIN_PASSWORD, MAX_PASSWORD) más cuatro cosas
     * que al crear todavía se escapan, medidas en la auditoría:
     *  - se valida lo que se GUARDA: recortar después dejaba pasar «   abc   »
     *    por el mínimo de 8 y guardaba «abc». Los espacios al borde se rechazan
     *    con su motivo en vez de quitarse en silencio;
     *  - 72 BYTES y no caracteres: bcrypt ignora el resto, y con tildes o ñ dos
     *    contraseñas distintas acababan valiendo lo mismo;
     *  - sin caracteres de control: con un byte nulo, password_hash lanza y la
     *    respuesta era un 500 en lugar de un 422 con su motivo;
     *  - un hash no es una contraseña: el cast `hashed` lo guarda tal cual y la
     *    cuenta quedaba con una clave que nadie conoce.
     * `confirmed` exige password_confirmation idéntica, que tampoco se recorta
     * (TrimStrings no toca ninguna de las dos).
     *
     * @return list<mixed>
     */
    private function reglasDeClaveElegida(): array
    {
        return [
            'bail',
            'required',
            'string',
            'min:'.self::MIN_PASSWORD,
            function (string $atributo, #[\SensitiveParameter] mixed $valor, Closure $falla): void {
                if (strlen((string) $valor) > self::MAX_PASSWORD) {
                    $falla('La contraseña no puede superar 72 bytes: las tildes y la ñ ocupan dos.');
                } elseif ($valor !== trim((string) $valor)) {
                    $falla('La contraseña no puede empezar ni terminar con espacios.');
                } elseif (preg_match('/[\x00-\x1F\x7F]/', (string) $valor) === 1) {
                    // bcrypt no admite el byte nulo: sin esto, un 500 en vez de
                    // su motivo, y la traza con el principio de la contraseña.
                    $falla('La contraseña no puede tener caracteres de control, como tabuladores o saltos de línea.');
                } elseif (Hash::isHashed((string) $valor)) {
                    $falla('Eso es un valor cifrado, no una contraseña: escribe la que usará la persona.');
                }
            },
            'confirmed',
        ];
    }

    /**
     * Solo un Super Admin fija la contraseña de un Super Admin.
     *
     * `users.manage` se puede conceder a otros roles desde Configuración →
     * Roles, y el restablecimiento no miraba a quién se aplicaba: quien lo
     * tuviera podía ponerle a un Super Admin una contraseña conocida —o
     * recibirla generada— y entrar como él. Es la misma regla que ya protege
     * crear o ascender a Super Admin ({@see denyIfEscalating()}).
     */
    private function denyIfTouchingSuperAdmin(Request $request, Admin $admin): ?JsonResponse
    {
        if (! $admin->hasRole(Admin::ROLE_SUPER_ADMIN)) {
            return null;
        }

        if (AdminActor::from($request)?->hasRole(Admin::ROLE_SUPER_ADMIN)) {
            return null;
        }

        return response()->json([
            'ok' => false,
            'code' => 'requires_super_admin',
            'message' => 'Solo un Super Admin puede cambiar la contraseña de otro Super Admin.',
        ], 403);
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
