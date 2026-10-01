<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\MemberClassContext;
use App\Http\Controllers\Concerns\ResolvesPagination;
use App\Http\Controllers\Controller;
use App\Http\Resources\ClassResource;
use App\Models\Admin;
use App\Models\ClassAttendance;
use App\Models\ClassEvent;
use App\Models\ClassReservation;
use App\Models\Member;
use App\Models\MyClass;
use App\Models\Trainer;
use App\Models\TrainerRole;
use App\Services\Admin\AdminSessionService;
use App\Services\Audit\AuditTrail;
use App\Services\Classes\ClassBookingService;
use App\Services\Classes\ClassEventBus;
use App\Services\NotificationService;
use App\Services\Trainer\ClassAttendanceService;
use App\Support\Access\CrmPermission;
use App\Support\Access\TrainerMemberScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ClassController extends Controller
{
    use MemberClassContext;
    use ResolvesPagination;

    public function index(Request $request)
    {
        // Optional member context: app sends access_hash as Bearer token.
        $member = $this->resolveMember($request);
        $memberId = $member?->id;

        $esAdmin = $memberId === null && $this->isAdminSession($request);

        // Para el CRM: el último hecho del registro ANTES de leer las clases.
        // Todo hecho hasta aquí ya está en estos datos; si el canal en vivo
        // abre con un cursor mayor, lo que hubo entre medias no llegará como
        // hecho y el CRM sabe que tiene que releer. Solo a una sesión de
        // administrador: esta lectura es pública.
        // Sin la tabla (entre subir el código y migrar) no hay cursor: el CRM
        // vuelve a su regla de antes en vez de recibir un 500.
        $eventsCursor = $esAdmin ? rescue(fn () => (int) (ClassEvent::max('id') ?? 0), null, false) : null;

        $query = MyClass::query()->with('trainer:id,full_name');

        // Fuera del CRM (la app, o sin sesión: la lectura es pública), solo el
        // catálogo reservable. Sin esto el listado devolvía también los
        // borradores —una clase duplicada nace `inactive`— y la app los pintaba
        // como "Disponible" hasta que la reserva respondía 422. El CRM entra con
        // sesión de administrador y ve el catálogo completo para gestionarlo.
        if (! $esAdmin) {
            $query->bookableByMembers();
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('day_of_week')) {
            $query->where('day_of_week', $request->input('day_of_week'));
        }
        if ($request->filled('trainer_id')) {
            $query->where('trainer_id', $request->input('trainer_id'));
        }
        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")
                ->orWhere('location', 'like', "%{$search}%"));
        }

        // Orden estable: el CRM pide las páginas en paralelo y, sin orden, una
        // clase podía salir en dos páginas o en ninguna. La app no pagina (lee
        // la primera página): para ella la página por defecto trae hasta 100.
        $query->orderBy('id');
        $paginated = $query->paginate($this->resolvePerPage($request, $esAdmin ? 20 : 100));

        $classIds = $paginated->pluck('id');
        $booking = app(ClassBookingService::class);

        // Cupo y reserva propia de la PRÓXIMA ocurrencia de cada clase, con la
        // MISMA condición que cuenta el cupo al reservar (servicio único), en
        // bloque. Y las próximas sesiones con su cupo, para el calendario.
        $filas = $booking->operationalRowsFor($paginated->getCollection());
        $proximas = $booking->upcomingSessionsFor($paginated->getCollection());

        // Sesión vigente de cada clase (en curso / recién finalizada / hoy) +
        // asistencia de HOY del miembro. Sin N+1.
        $sessions = $this->relevantSessionsFor($classIds);
        $attendance = $memberId
            ? ClassAttendance::where('member_id', $memberId)
                ->whereIn('class_id', $classIds)
                ->whereDate('session_date', $this->operationalToday())
                ->get()
                ->keyBy('class_id')
            : collect();

        $paginated->getCollection()->transform(function (MyClass $c) use ($memberId, $filas, $proximas, $sessions, $attendance, $esAdmin) {
            $rows = $filas[$c->id] ?? collect();
            $c->reservations_count = $rows->count();
            $c->setAttribute('upcoming_sessions', $proximas[$c->id] ?? []);

            $myRow = $memberId !== null
                ? $rows->first(fn ($r) => (int) $r->member_id === (int) $memberId)
                : null;
            $reserved = $myRow !== null;
            $context = $memberId !== null
                ? $this->memberClassContext($reserved, $sessions->get($c->id), $attendance->get($c->id))
                : [];

            return new ClassResource($c, $reserved, $context, $myRow?->id, $esAdmin);
        });

        if ($eventsCursor === null) {
            return $paginated;
        }

        return response()->json($paginated->toArray() + ['events_cursor' => $eventsCursor]);
    }

    /**
     * ¿La petición trae una sesión de administrador activa que puede ver el
     * módulo de clases? (Sin tocar la sesión.) Sin `classes.view`, una cuenta
     * del CRM ve lo mismo que el público: nada de borradores ni notas internas.
     */
    private function isAdminSession(Request $request): bool
    {
        $admin = $request->attributes->get('auth_admin');
        if (! $admin instanceof Admin) {
            $token = $request->bearerToken();
            $admin = $token ? app(AdminSessionService::class)->resolveByToken($token)?->admin : null;
        }

        return $admin instanceof Admin && $admin->isActive() && CrmPermission::allows($admin, 'classes.view');
    }

    public function show(MyClass $myClass, Request $request)
    {
        $member = $this->resolveMember($request);
        $myClass->load('trainer:id,full_name');

        // Cupo/estado referidos a la próxima ocurrencia (coherente con index()).
        $date = optional($myClass->operationalOccurrence())->toDateString() ?? $this->operationalToday()->toDateString();
        $myClass->reservations_count = $this->occurrenceBookedCount($myClass, $date);

        $reservation = $member
            ? ClassReservation::where('class_id', $myClass->id)
                ->where('member_id', $member->id)
                ->where(function ($q) use ($date): void {
                    $q->whereNull('session_date')->orWhereDate('session_date', $date);
                })
                ->first()
            : null;
        $reserved = $reservation !== null;

        // Contexto del miembro (estado resuelto: sesión + asistencia mandan sobre
        // "reservada"). Vacío en CRM (sin miembro) → solo cupo/estado base.
        $session = $member ? $this->currentClassSession($myClass) : null;
        $attendance = ($member && $session)
            ? ClassAttendance::where('class_id', $myClass->id)
                ->where('member_id', $member->id)
                ->whereDate('session_date', optional($session->session_date)->toDateString() ?? $date)
                ->first()
            : null;
        $context = $member ? $this->memberClassContext($reserved, $session, $attendance) : [];

        return new ClassResource($myClass, $reserved, $context, $reservation?->id, $member === null && $this->isAdminSession($request));
    }

    public function store(Request $request)
    {
        $this->normalizeClassInput($request);
        // Clase ÚNICA (is_recurring=false): exige fecha y deriva el día desde ella.
        $this->normalizeRecurrenceInput($request, requireDateWhenSingle: true);
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|string|max:100',
            'day_of_week' => 'required|string|in:Lunes,Martes,Miércoles,Jueves,Viernes,Sábado,Domingo',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time',
            'duration_minutes' => 'nullable|integer|min:15',
            'max_capacity' => 'required|integer|min:1',
            'location' => 'nullable|string|max:255',
            'status' => 'nullable|string|in:active,inactive,finished',
            'trainer_id' => 'nullable|exists:trainers,id',
            'instructor' => 'nullable|string|max:255',
            'date_time' => 'nullable|date',
            'description' => 'nullable|string',
            'notes' => 'nullable|string',
            'is_recurring' => 'nullable|boolean',
            'renewal_hours' => 'nullable|integer|in:0,8,12,24,48,168',
            'allow_online_booking' => 'nullable|boolean',
            'requires_active_plan' => 'nullable|boolean',
        ]);

        if (empty($validated['duration_minutes'])) {
            $start = \DateTime::createFromFormat('H:i', $validated['start_time']);
            $end = \DateTime::createFromFormat('H:i', $validated['end_time']);
            $validated['duration_minutes'] = $end->diff($start)->i + ($end->diff($start)->h * 60);
        }

        $class = MyClass::create($validated);

        // Asignar una clase implica que ese entrenador la gestione en su portal:
        // le garantizamos el rol que habilita el portal de clases.
        $this->ensureClassTrainerRole($validated['trainer_id'] ?? null);

        // Notificación de clase creada (ADITIVO; no afecta la creación).
        app(NotificationService::class)->notifyClassCreated($class);

        // Refresco en vivo: socios, su entrenador y el CRM.
        app(ClassEventBus::class)->classChanged($class, 'class.created', ClassEventBus::SOURCE_CRM);

        app(AuditTrail::class)->record($request, [
            'action' => 'create', 'module' => 'Clases', 'entity' => 'clase',
            'entity_id' => $class->id, 'target_name' => $class->name,
            'summary' => "Creó la clase {$class->name}",
        ]);

        return (new ClassResource($class->load('trainer:id,full_name'), internal: true))->response()->setStatusCode(201);
    }

    public function update(Request $request, MyClass $myClass)
    {
        $this->normalizeClassInput($request);
        // En edición no exigimos fecha (parcial), pero si llega fecha y la clase es
        // única, el día se deriva de ella para mantener coherencia.
        $this->normalizeRecurrenceInput($request, requireDateWhenSingle: false);
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'type' => 'sometimes|string|max:100',
            'day_of_week' => 'sometimes|string|in:Lunes,Martes,Miércoles,Jueves,Viernes,Sábado,Domingo',
            'start_time' => 'sometimes|date_format:H:i',
            'end_time' => 'sometimes|date_format:H:i|after:start_time',
            'duration_minutes' => 'nullable|integer|min:15',
            'max_capacity' => 'sometimes|integer|min:1',
            // El número de inscritos NO se escribe: se cuenta de las reservas.
            // Era la vía del contador «Agregar/Quitar inscrito» del CRM, que
            // movía un número sin inscribir a nadie —ni la app ni el
            // entrenador lo veían— y avisaba «Clase actualizada» a todos los
            // inscritos en cada clic. Se rechaza en vez de ignorarse para que
            // una pestaña antigua falle sin efectos, no con ellos. `missing` y
            // no `prohibited`: esta deja pasar null y vacío, que acababan en un
            // 500 contra la columna NOT NULL.
            'enrolled_count' => 'missing',
            'location' => 'nullable|string|max:255',
            'status' => 'sometimes|string|in:active,inactive,finished',
            'trainer_id' => 'nullable|exists:trainers,id',
            'instructor' => 'nullable|string|max:255',
            'date_time' => 'nullable|date',
            'description' => 'nullable|string',
            'notes' => 'nullable|string',
            'is_recurring' => 'nullable|boolean',
            'renewal_hours' => 'nullable|integer|in:0,8,12,24,48,168',
            'allow_online_booking' => 'nullable|boolean',
            'requires_active_plan' => 'nullable|boolean',
        ], [
            'enrolled_count.missing' => 'El número de inscritos se calcula con las reservas. Inscribe o quita socios desde «Inscritos».',
        ]);

        if (
            empty($validated['duration_minutes']) &&
            ! empty($validated['start_time']) &&
            ! empty($validated['end_time'])
        ) {
            $start = \DateTime::createFromFormat('H:i', $validated['start_time']);
            $end = \DateTime::createFromFormat('H:i', $validated['end_time']);
            if ($start && $end && $end > $start) {
                $validated['duration_minutes'] = $end->diff($start)->i + ($end->diff($start)->h * 60);
            }
        }

        // Una clase de FECHA ÚNICA solo cambia de FECHA si se pide (`reschedule`,
        // que manda el CRM al editar la fecha). El CRM anterior reenviaba en cada
        // edición una fecha recalculada en el navegador —con toISOString, el día
        // siguiente desde las 19:00—: movía la clase sin que nadie lo pidiera y,
        // ahora que mover la fecha reprograma las reservas, las cancelaría. El día
        // de la semana que se derivó de esa fecha tampoco cuenta. La HORA sí se
        // sigue: la sesión de una clase única sale de su `date_time`.
        if (! $myClass->is_recurring && $myClass->date_time !== null && ! ($validated['is_recurring'] ?? false)) {
            $guardada = Carbon::parse($myClass->date_time);
            $pedida = ! empty($validated['date_time']) ? Carbon::parse($validated['date_time']) : null;
            if ($pedida !== null && $pedida->toDateString() !== $guardada->toDateString() && ! $request->boolean('reschedule')) {
                unset($validated['day_of_week']);
                $pedida = null;
            }
            if ($pedida === null && (array_key_exists('date_time', $validated) || isset($validated['start_time']))) {
                $hora = $validated['start_time'] ?? $guardada->format('H:i');
                $validated['date_time'] = $guardada->toDateString().' '.$hora.':00';
            }
        }

        $previoClase = $myClass->getOriginal();

        // Bajar el aforo por debajo de lo ya inscrito dejaría una sesión
        // sobrevendida (22/20). Se comprueba bajo el mismo bloqueo de la clase
        // que toma cada reserva: ninguna puede colarse entre la cuenta y el cambio.
        $rechazo = DB::transaction(function () use ($myClass, $validated): ?string {
            if (isset($validated['max_capacity']) && (int) $validated['max_capacity'] < (int) $myClass->max_capacity) {
                MyClass::whereKey($myClass->getKey())->lockForUpdate()->first();
                if ($motivo = app(ClassBookingService::class)->capacityConflict($myClass, (int) $validated['max_capacity'])) {
                    return $motivo;
                }
            }
            $myClass->update($validated);

            return null;
        });
        if ($rechazo !== null) {
            return response()->json(['message' => $rechazo, 'errors' => ['max_capacity' => [$rechazo]]], 422);
        }

        // Si se (re)asignó a un entrenador, garantízale el rol del portal de clases.
        $this->ensureClassTrainerRole($myClass->trainer_id);

        $booking = app(ClassBookingService::class);
        $bus = app(ClassEventBus::class);
        $entrenadorPrevio = isset($previoClase['trainer_id']) ? (int) $previoClase['trainer_id'] : null;

        // Si cambió el DÍA o la fecha, las reservas futuras que quedaron fuera de
        // un día real de la clase se mueven a su nueva fecha de la misma semana
        // (o se cancelan si no cabe). Todo sale en UN aviso a socios y
        // entrenadores, incluido el entrenador anterior si se reasignó.
        $resultado = $bus->batch(function () use ($myClass, $booking, $bus, $entrenadorPrevio): array {
            $r = $myClass->wasChanged(['day_of_week', 'date_time', 'is_recurring'])
                ? $booking->reconcileFutureReservations($myClass)
                : ['moved' => [], 'dropped' => []];

            $otros = ($entrenadorPrevio !== null && $entrenadorPrevio !== (int) $myClass->trainer_id) ? [$entrenadorPrevio] : [];
            $bus->classChanged($myClass, 'class.updated', ClassEventBus::SOURCE_CRM, null, $otros);

            return $r;
        });

        // Avisos a quien le afecta: reservas de hoy en adelante, no el histórico.
        $notifier = app(NotificationService::class);
        foreach ($resultado['dropped'] as $caida) {
            // El socio sin el alcance del entrenador: si edita una cuenta del
            // CRM con rol Entrenador, un socio que no tiene asignado salía null
            // y el aviso se publicaba para TODOS los socios. El aviso va a la
            // bandeja del socio; al entrenador no le enseña nada.
            $socio = Member::withoutGlobalScope(TrainerMemberScope::NAME)->find($caida['reservation']->member_id);
            if ($socio !== null) {
                $notifier->notifyReservationDroppedBySchedule($socio, $myClass, $caida['date']);
            }
        }
        $notifier->notifyClassUpdated($myClass, $booking->membersWithUpcomingReservations($myClass));

        app(AuditTrail::class)->record($request, [
            'action' => $request->has('status') ? 'status' : 'update',
            'module' => 'Clases', 'entity' => 'clase',
            'entity_id' => $myClass->id, 'target_name' => $myClass->name,
            'summary' => "Modificó la clase {$myClass->name}",
            'changes' => app(AuditTrail::class)->changesOf(
                $myClass,
                $previoClase,
                // `capacity` no existe: el aforo es `max_capacity`, y sin él
                // la auditoría no guardaba de cuánto a cuánto cambió.
                ['status', 'max_capacity', 'date_time', 'day_of_week', 'start_time', 'end_time', 'trainer_id'],
            ),
        ]);

        // Sin `loadCount('reservations')`, que sumaba TODAS las semanas: el
        // recurso cuenta la ocurrencia operativa, como el listado.
        return new ClassResource($myClass->load('trainer:id,full_name'), internal: true);
    }

    /**
     * Normaliza las entradas de clase para que ediciones con datos en otro
     * formato (horas con segundos, día/estado en inglés o con mayúsculas/acentos)
     * no fallen la validación `in:`/`date_format`. Deja valores canónicos.
     */
    private function normalizeClassInput(Request $request): void
    {
        $merge = [];

        // Horas "H:i:s" → "H:i" (la validación pide H:i).
        foreach (['start_time', 'end_time'] as $field) {
            $value = $request->input($field);
            if (is_string($value) && preg_match('/^(\d{1,2}:\d{2}):\d{2}$/', $value, $m)) {
                $merge[$field] = $m[1];
            }
        }

        // Estado → active|inactive|finished (acepta español/mayúsculas).
        if ($request->has('status')) {
            $statusMap = [
                'active' => 'active', 'activa' => 'active', 'activo' => 'active',
                'inactive' => 'inactive', 'inactiva' => 'inactive', 'inactivo' => 'inactive',
                'finished' => 'finished', 'finalizada' => 'finished', 'finalizado' => 'finished',
            ];
            $key = $this->stripAccentsLower((string) $request->input('status'));
            if (isset($statusMap[$key])) {
                $merge['status'] = $statusMap[$key];
            } elseif ($request->isMethod('POST')) {
                // Alta sin estado reconocible (CRM viejo): nace activa.
                $merge['status'] = 'active';
            } else {
                // Edición: el CRM mandaba en `status` la DISPONIBILIDAD que le da
                // el recurso ('available', 'full', 'unavailable'…), no el estado
                // de la clase. Traducirla a 'active' reactivaba en silencio una
                // clase pausada al cambiarle el nombre. Sin un estado reconocible,
                // el estado no cambia.
                $request->offsetUnset('status');
            }
        }

        // Frecuencia de renovación: "" o no-numérico → null (no renovar), así un
        // valor vacío del CRM no falla la validación `integer|in:`.
        if ($request->has('renewal_hours')) {
            $raw = $request->input('renewal_hours');
            $merge['renewal_hours'] = ($raw === '' || $raw === null || ! is_numeric($raw))
                ? null
                : (int) $raw;
        }

        // Día → Lunes..Domingo (acepta inglés, minúsculas, sin acentos).
        if ($request->filled('day_of_week')) {
            $dayMap = [
                'lunes' => 'Lunes', 'monday' => 'Lunes',
                'martes' => 'Martes', 'tuesday' => 'Martes',
                'miercoles' => 'Miércoles', 'wednesday' => 'Miércoles',
                'jueves' => 'Jueves', 'thursday' => 'Jueves',
                'viernes' => 'Viernes', 'friday' => 'Viernes',
                'sabado' => 'Sábado', 'saturday' => 'Sábado',
                'domingo' => 'Domingo', 'sunday' => 'Domingo',
            ];
            $key = $this->stripAccentsLower((string) $request->input('day_of_week'));
            if (isset($dayMap[$key])) {
                $merge['day_of_week'] = $dayMap[$key];
            }
        }

        if ($merge !== []) {
            $request->merge($merge);
        }
    }

    /**
     * Modelo recurrente vs único. Recurrente (por defecto): manda `day_of_week`;
     * `date_time` —si llega— es la fecha de inicio de VIGENCIA, no una sesión
     * única. Única (`is_recurring=false`): la fecha es obligatoria y el día se
     * DERIVA de ella (coherencia garantizada; no se confía en el día del cliente).
     * Validación controlada (422), nunca 500.
     */
    private function normalizeRecurrenceInput(Request $request, bool $requireDateWhenSingle): void
    {
        // Si is_recurring no llega, se asume recurrente (default del negocio).
        if ($request->boolean('is_recurring', true)) {
            return;
        }

        if ($requireDateWhenSingle && ! $request->filled('date_time')) {
            $request->validate(
                ['date_time' => ['required', 'date']],
                ['date_time.required' => 'Una clase no recurrente requiere la fecha de la clase.'],
            );
        }

        if ($request->filled('date_time')) {
            $request->merge([
                'day_of_week' => $this->spanishWeekday(Carbon::parse((string) $request->input('date_time'))),
            ]);
        }
    }

    /** Día de la semana en español (coincide con el enum aceptado por la validación). */
    private function spanishWeekday(Carbon $date): string
    {
        return [
            Carbon::MONDAY => 'Lunes', Carbon::TUESDAY => 'Martes', Carbon::WEDNESDAY => 'Miércoles',
            Carbon::THURSDAY => 'Jueves', Carbon::FRIDAY => 'Viernes', Carbon::SATURDAY => 'Sábado',
            Carbon::SUNDAY => 'Domingo',
        ][$date->dayOfWeek];
    }

    private function stripAccentsLower(string $value): string
    {
        $value = mb_strtolower(trim($value));

        return strtr($value, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u']);
    }

    /**
     * Al asignar una clase a un entrenador, le garantizamos el rol que habilita
     * el portal de clases (`trainer_functional` → classes.view/manage/attendance),
     * sin quitarle los que ya tenga. Si no, la clase no le aparecería en su portal.
     */
    private function ensureClassTrainerRole(?int $trainerId): void
    {
        if (! $trainerId) {
            return;
        }
        $trainer = Trainer::find($trainerId);
        if ($trainer === null || $trainer->hasPermission('classes.view')) {
            return;
        }
        $trainer->syncRoles(array_values(array_unique([
            ...$trainer->roleNames(),
            TrainerRole::FUNCTIONAL,
        ])));
    }

    public function destroy(Request $request, MyClass $myClass)
    {
        $nombre = $myClass->name;
        $id = $myClass->id;

        // Captura inscritos ANTES de eliminar para poder avisarles: los que
        // tienen reserva de hoy en adelante, no los que fueron en agosto.
        $members = app(ClassBookingService::class)->membersWithUpcomingReservations($myClass);

        // El hecho y el borrado en la misma transacción: el aviso (tras el
        // commit) nunca llega antes de que la clase haya desaparecido.
        DB::transaction(function () use ($myClass): void {
            app(ClassEventBus::class)->classChanged($myClass, 'class.deleted', ClassEventBus::SOURCE_CRM);
            $myClass->delete();
        });

        // «Tu clase fue cancelada», solo si de verdad se borró.
        app(NotificationService::class)->notifyClassCancelled($myClass, $members);

        app(AuditTrail::class)->record($request, [
            'action' => 'delete', 'module' => 'Clases', 'entity' => 'clase',
            'entity_id' => $id, 'target_name' => $nombre,
            'summary' => "Eliminó la clase {$nombre}",
        ]);

        return response()->json(['message' => 'Clase eliminada correctamente'], 200);
    }

    /**
     * GET /api/classes/{myClass}/reservations?session_date= — reservas de UNA
     * ocurrencia (por defecto la operativa).
     *
     * Antes devolvía TODAS las reservas de la clase, de cualquier semana, con
     * `booked_spots` sumándolas: no cuadraba con ningún otro número. Con una
     * cuenta de entrenador, un inscrito fuera de su alcance llegaba sin socio y
     * la respuesta daba 500. Email y teléfono solo con permiso de ver fichas.
     */
    public function reservations(Request $request, MyClass $myClass): JsonResponse
    {
        $data = $request->validate(['session_date' => ['nullable', 'date_format:Y-m-d']]);
        $booking = app(ClassBookingService::class);
        $date = $data['session_date'] ?? $booking->operationalDate($myClass);
        // Como en la inscripción: un día en que la clase no se dicta no tiene
        // lista (antes devolvía las heredadas como si fueran de ese día).
        if (isset($data['session_date']) && ! $booking->isOccurrence($myClass, $date)) {
            return response()->json(['message' => 'Esta clase no se dicta ese día.', 'code' => 'not_an_occurrence'], 422);
        }
        $verContacto = CrmPermission::allows($request->attributes->get('auth_admin'), 'members.view');

        $ids = $booking->occurrenceReservations($myClass, $date)->pluck('id');
        $reservations = ClassReservation::query()
            ->whereIn('id', $ids)
            ->with('member:id,member_uuid,full_name,email,phone')
            ->orderBy('reserved_at')
            ->orderBy('id')
            ->get()
            ->map(fn (ClassReservation $r) => [
                'reservation_id' => $r->id,
                'session_date' => $r->session_date?->toDateString(),
                'reserved_at' => $r->reserved_at?->toIso8601String(),
                'member' => $r->member ? [
                    'id' => $r->member->id,
                    'uuid' => $r->member->member_uuid,
                    'full_name' => $r->member->full_name,
                    'email' => $verContacto ? $r->member->email : null,
                    'phone' => $verContacto ? $r->member->phone : null,
                ] : null,
            ]);

        return response()->json([
            'class_id' => $myClass->id,
            'class_name' => $myClass->name,
            'max_capacity' => $myClass->max_capacity,
            'session_date' => $date,
            'booked_spots' => $reservations->count(),
            'reservations' => $reservations,
        ]);
    }

    /** POST /api/classes/{myClass}/reserve — reservar (requiere auth.member) */
    public function reserve(Request $request, MyClass $myClass): JsonResponse
    {
        $member = $request->attributes->get('auth_member');
        $booking = app(ClassBookingService::class);

        // Reglas, bloqueo, cupo y aviso: el dominio de reservas (el mismo que el
        // CRM). Aquí solo se traducen a los textos que la app publicada enseña.
        $resultado = $booking->reserveForMember($member, $myClass);

        if (! $resultado->ok) {
            if ($resultado->outcome === ClassBookingService::NOT_IN_PLAN) {
                return $this->classesNotInPlanResponse();
            }

            return response()->json(['message' => match ($resultado->outcome) {
                ClassBookingService::ALREADY => 'Ya tienes esta clase reservada.',
                ClassBookingService::FULL => 'No hay cupos disponibles.',
                ClassBookingService::OCCURRENCE_LIVE => 'La clase ya está en curso.',
                ClassBookingService::OCCURRENCE_CLOSED => 'La clase ya finalizó.',
                ClassBookingService::OCCURRENCE_PAST => 'Esta clase ya pasó.',
                default => 'Clase no disponible para reservas.',
            }], 422);
        }

        $myClass->reservations_count = $booking->bookedCount($myClass, $resultado->date);
        $myClass->load('trainer:id,full_name');

        return response()->json(['data' => $this->memberResource($myClass, $member, true)]);
    }

    /** POST /api/classes/{myClass}/cancel — cancelar reserva (requiere auth.member) */
    public function cancel(Request $request, MyClass $myClass): JsonResponse
    {
        $member = $request->attributes->get('auth_member');

        // "Organizar mi semana" puede cancelar una OCURRENCIA concreta (futura)
        // enviando `session_date`. Sin él = comportamiento histórico de "Clases":
        // la próxima ocurrencia del ciclo (+ legacy sin fecha). Aditivo: no rompe
        // la cancelación individual ni toca otras reservas futuras.
        $data = $request->validate(['session_date' => ['nullable', 'date']]);
        $explicit = isset($data['session_date'])
            ? Carbon::parse($data['session_date'])->toDateString()
            : null;

        $booking = app(ClassBookingService::class);
        $resultado = $booking->cancelForMember($member, $myClass, $explicit);

        if (! $resultado->ok) {
            return response()->json(['message' => match ($resultado->outcome) {
                ClassBookingService::OCCURRENCE_CLOSED => 'La clase ya finalizó.',
                ClassBookingService::OCCURRENCE_LIVE => 'La clase ya está en curso.',
                default => 'No tienes reserva en esta clase.',
            }], 422);
        }

        $myClass->reservations_count = $booking->bookedCount($myClass, $resultado->date);
        $myClass->load('trainer:id,full_name');

        return response()->json(['data' => $this->memberResource($myClass, $member, false)]);
    }

    /**
     * POST /api/classes/{myClass}/check-in — AUTO check-in del miembro (presente).
     * Requiere reserva de ESA sesión y que la clase esté EN CURSO (el entrenador
     * la inició). Si el entrenador ya marcó la asistencia, se respeta.
     */
    public function checkIn(Request $request, MyClass $myClass): JsonResponse
    {
        $member = $request->attributes->get('auth_member');
        $resultado = app(ClassAttendanceService::class)->selfCheckIn($member, $myClass);

        [$codigo, $mensaje] = ClassAttendanceService::checkInReply($resultado);
        if ($codigo !== 200) {
            return response()->json(['message' => $mensaje], $codigo);
        }

        // Cupo de ESA sesión, no la suma de todas las semanas.
        $myClass->reservations_count = app(ClassBookingService::class)->bookedCount($myClass, $resultado['date']);
        $myClass->load('trainer:id,full_name');

        return response()->json([
            'message' => $mensaje,
            'data' => $this->memberResource($myClass, $member, true),
        ]);
    }

    /** ClassResource con el contexto de la sesión vigente del miembro. */
    private function memberResource(MyClass $class, Member $member, bool $reserved): ClassResource
    {
        $session = $this->currentClassSession($class);
        $sessionDate = optional($session?->session_date)->toDateString() ?? $this->operationalToday()->toDateString();
        $attendance = ClassAttendance::where('class_id', $class->id)
            ->where('member_id', $member->id)
            ->whereDate('session_date', $sessionDate)
            ->first();

        return new ClassResource($class, $reserved, $this->memberClassContext($reserved, $session, $attendance));
    }

    private function resolveMember(Request $request): ?Member
    {
        $token = $request->bearerToken();
        if (! $token) {
            return null;
        }

        return Member::resolveByToken($token);
    }
}
