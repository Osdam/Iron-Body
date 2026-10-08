<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmployeeAccess;
use App\Models\User;
use App\Services\Audit\AuditTrail;
use App\Services\Membership\MembershipAccess;
use App\Services\Membership\PlanAccessRules;
use App\Support\Access\AdminActor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * EL ACCESO DE UN EMPLEADO: entrar a entrenar sin comprarse un plan.
 *
 * Hasta ahora, para que un empleado pudiera usar el gimnasio había que
 * venderle una membresía. Eso es un cobro que nadie cobró —entra en las cifras
 * como si alguien hubiera pagado— y no se acaba cuando se acaba el trabajo: el
 * día que renuncia sigue con su plan vigente hasta que alguien se acuerda de
 * quitárselo.
 *
 * Aquí el permiso es lo que es: laboral. Se enciende, se apaga, y tiene las
 * mismas horas y días que las horas valle de los planes, porque lo normal es
 * que el empleado entrene fuera de su turno.
 *
 * TIENE SU PROPIO PERMISO (`members.staff`). Conceder entrada gratuita no es
 * editar una ficha: es regalar lo que el gimnasio vende, igual que
 * `members.adjust`, y por eso no lo trae recepción de fábrica.
 */
class EmployeeAccessController extends Controller
{
    /** GET /api/users/{user}/employee-access */
    public function show(User $user): JsonResponse
    {
        return response()->json($this->payload($user));
    }

    /**
     * PUT /api/users/{user}/employee-access — lo crea o lo actualiza.
     *
     * Un solo verbo para las dos cosas: desde el mostrador no se distingue
     * «darle acceso» de «cambiarle la franja», y partirlo en dos rutas solo
     * obligaría al CRM a saber si la fila ya existía.
     */
    public function store(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'position' => ['nullable', 'string', 'max:60'],
            'active' => ['nullable', 'boolean'],
            'starts_on' => ['nullable', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'note' => ['nullable', 'string', 'max:255'],
            // Mismo formato que las reglas de un plan: los normaliza
            // PlanAccessRules, que es quien después las va a aplicar.
            'access_days' => ['nullable', 'array', 'max:7'],
            'access_days.*' => ['integer', 'between:0,7'],
            'access_windows' => ['nullable', 'array', 'max:'.PlanAccessRules::MAX_WINDOWS],
            'access_windows.*.from' => ['required', 'string', 'max:5'],
            'access_windows.*.to' => ['required', 'string', 'max:5'],
            'access_windows.*.label' => ['nullable', 'string', 'max:40'],
        ]);

        $actor = AdminActor::from($request);
        $existia = EmployeeAccess::where('user_id', $user->id)->first();
        $activo = (bool) ($data['active'] ?? true);

        $dias = PlanAccessRules::normalizeDays($data['access_days'] ?? null);
        $franjas = PlanAccessRules::normalizeWindows($data['access_windows'] ?? null);

        $empleo = EmployeeAccess::updateOrCreate(['user_id' => $user->id], [
            'position' => $data['position'] ?? null,
            'active' => $activo,
            'starts_on' => $data['starts_on'] ?? null,
            'ends_on' => $data['ends_on'] ?? null,
            // Vacío se guarda como null y no como []: así la columna dice «sin
            // restricción» y no «una lista vacía de días permitidos», que es la
            // forma de dejar a alguien encerrado fuera por un formulario.
            'access_days' => $dias === [] ? null : $dias,
            'access_windows' => $franjas === [] ? null : $franjas,
            'note' => $data['note'] ?? null,
            // Quién lo dio se escribe la primera vez y no se pisa después: la
            // pregunta que importa es quién abrió esta puerta.
            'granted_by' => $existia?->granted_by ?? $actor?->id,
            'granted_by_name' => $existia?->granted_by_name ?? $actor?->name,
            // Reactivar limpia el corte anterior; apagarlo lo firma.
            'revoked_at' => $activo ? null : ($existia?->revoked_at ?? now()),
            'revoked_by_name' => $activo ? null : ($existia?->revoked_by_name ?? $actor?->name),
        ]);

        $this->audit($request, $user, $empleo, $existia === null ? 'create' : 'update');

        return response()->json($this->payload($user->refresh()), $existia === null ? 201 : 200);
    }

    /**
     * DELETE /api/users/{user}/employee-access — renunció.
     *
     * NO borra la fila: la apaga y la firma. «¿Desde cuándo y hasta cuándo
     * entró esta persona gratis?» es una pregunta que alguien va a hacer, y
     * borrar el registro es quedarse sin respuesta.
     */
    public function destroy(Request $request, User $user): JsonResponse
    {
        $empleo = EmployeeAccess::where('user_id', $user->id)->first();

        if (! $empleo) {
            return response()->json(['ok' => false, 'message' => 'Esta persona no tiene acceso de empleado.'], 404);
        }

        $actor = AdminActor::from($request);
        $empleo->update([
            'active' => false,
            'revoked_at' => now(),
            'revoked_by_name' => $actor?->name,
        ]);

        $this->audit($request, $user, $empleo, 'delete');

        return response()->json($this->payload($user->refresh()));
    }

    /** @return array<string, mixed> */
    private function payload(User $user): array
    {
        return [
            'member' => ['id' => $user->id, 'name' => $user->name, 'plan' => $user->plan],
            'access' => app(MembershipAccess::class)->state($user),
            'weekdays' => collect(PlanAccessRules::DAY_NAMES)
                ->map(fn (string $nombre, int $iso) => ['iso' => $iso, 'name' => $nombre])
                ->values(),
        ];
    }

    private function audit(Request $request, User $user, EmployeeAccess $empleo, string $action): void
    {
        $resumen = match ($action) {
            'create' => 'Dio acceso de empleado',
            'delete' => 'Retiró el acceso de empleado',
            default => $empleo->active ? 'Cambió el acceso de empleado' : 'Desactivó el acceso de empleado',
        };

        app(AuditTrail::class)->record($request, [
            'action' => $action === 'create' ? 'create' : 'update',
            'module' => 'Miembros',
            'entity' => 'acceso de empleado',
            'entity_id' => (string) $user->id,
            'target_name' => $user->name,
            'summary' => $resumen,
            'metadata' => array_filter([
                'position' => $empleo->position,
                'active' => $empleo->active,
                'starts_on' => $empleo->starts_on?->toDateString(),
                'ends_on' => $empleo->ends_on?->toDateString(),
                'days' => $empleo->access_days,
                'windows' => $empleo->access_windows,
                'note' => $empleo->note,
            ], fn ($v) => $v !== null && $v !== []),
        ]);
    }
}
