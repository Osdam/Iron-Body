<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ClassReservation;
use App\Models\ClassSession;
use App\Models\Member;
use App\Models\MyClass;
use App\Services\Audit\AuditTrail;
use App\Services\Classes\ClassBookingResult;
use App\Services\Classes\ClassBookingService;
use App\Services\Classes\ClassesChannel;
use App\Services\Classes\ClassEventsFeed;
use App\Support\Access\CrmPermission;
use App\Support\SseStream;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Inscripción a clases desde el mostrador del CRM.
 *
 * NO ES OTRA LÓGICA DE RESERVAS. Todo lo que decide si una inscripción existe
 * —reglas, bloqueo, cupo, anti-doble, avisos— vive en
 * {@see ClassBookingService}, el mismo que atiende a la app. Este controlador
 * solo traduce HTTP: resuelve a quién se inscribe, deja la traza de quién lo
 * hizo y pone en palabras de recepción los motivos de rechazo.
 *
 * NUNCA inserta ni borra filas por su cuenta: si mañana cambia una regla, la
 * app y el mostrador cambian juntos.
 */
class ClassEnrollmentController extends Controller
{
    /**
     * Motivos de rechazo en palabras de quien atiende: el socio no está
     * delante de la pantalla, así que se habla de él en tercera persona.
     */
    private const MESSAGES = [
        ClassBookingService::NOT_IN_PLAN => 'El plan de este socio no incluye clases, o su membresía no está vigente.',
        ClassBookingService::CLASS_UNAVAILABLE => 'La clase no está activa.',
        ClassBookingService::ACCOUNT_BLOCKED => 'La cuenta de este socio está eliminada o suspendida.',
        ClassBookingService::PAYMENT_OVERDUE => 'El socio tiene un saldo vencido: su membresía está retenida hasta que pague.',
        ClassBookingService::NOT_AN_OCCURRENCE => 'La clase no se dicta en esa fecha.',
        ClassBookingService::OCCURRENCE_PAST => 'Esa fecha ya pasó.',
        ClassBookingService::OCCURRENCE_LIVE => 'La clase ya está en curso.',
        ClassBookingService::OCCURRENCE_CLOSED => 'La clase ya finalizó.',
        ClassBookingService::FULL => 'No hay cupos disponibles.',
    ];

    public function __construct(private readonly ClassBookingService $booking) {}

    /**
     * GET /api/admin/classes/{myClass}/enrollments?session_date=YYYY-MM-DD
     *
     * Quién está inscrito en UNA ocurrencia. Sin fecha, la operativa: la misma
     * que reserva la app y la que ve el entrenador en su lista.
     */
    public function index(Request $request, MyClass $myClass): JsonResponse
    {
        $data = $request->validate([
            'session_date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $date = $data['session_date'] ?? $this->booking->operationalDate($myClass);

        return response()->json(['data' => $this->snapshot($request, $myClass, $date)]);
    }

    /**
     * POST /api/admin/classes/{myClass}/enrollments  {user_id, session_date?}
     *
     * `user_id` es lo que emite el buscador de socios del CRM (la cuenta,
     * `users.id`). La reserva es del socio (`members.id`), y los dos números NO
     * coinciden siempre: se resuelve aquí por `members.user_id`, nunca
     * suponiendo que son el mismo.
     *
     * Idempotente: inscribir a quien ya estaba devuelve 200 con `outcome =
     * already`. Un doble clic o un reintento de red no son un error.
     */
    public function store(Request $request, MyClass $myClass): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'min:1'],
            'session_date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        // Con el alcance del entrenador ya aplicado: una cuenta de entrenador
        // solo encuentra a los socios que tiene asignados.
        $member = Member::query()->where('user_id', $data['user_id'])->first();
        if ($member === null) {
            return response()->json([
                'ok' => false,
                'code' => 'member_not_found',
                'message' => 'Esa persona no tiene cuenta de socio.',
            ], 422);
        }

        $result = $this->booking->enrollByStaff(
            $member,
            $myClass,
            $data['session_date'] ?? null,
            function (ClassReservation $reserva) use ($request, $myClass, $member): void {
                app(AuditTrail::class)->record($request, [
                    'action' => 'enroll',
                    'module' => 'Clases',
                    'entity' => 'clase',
                    'entity_id' => $myClass->id,
                    'target_name' => $myClass->name,
                    'summary' => "Inscribió a {$member->full_name} en {$myClass->name} ({$reserva->session_date?->toDateString()})",
                    'metadata' => [
                        'member_id' => $member->id,
                        'reservation_id' => $reserva->id,
                        'session_date' => $reserva->session_date?->toDateString(),
                        'channel' => ClassBookingService::CHANNEL_STAFF,
                    ],
                ]);
            },
        );

        return $this->respond($request, $myClass, $result);
    }

    /**
     * DELETE /api/admin/classes/{myClass}/enrollments/{member}?session_date=YYYY-MM-DD
     *
     * `{member}` es `members.id`: el que devuelve el listado de inscritos. La
     * fecha es obligatoria: quitar a alguien de «la clase» sin decir de qué día
     * podría borrar una reserva de otra semana.
     *
     * Idempotente: quitar a quien ya no estaba devuelve 200 con `outcome =
     * already`.
     */
    public function destroy(Request $request, MyClass $myClass, Member $member): JsonResponse
    {
        $data = $request->validate([
            'session_date' => ['required', 'date_format:Y-m-d'],
        ]);

        $result = $this->booking->removeByStaff(
            $member,
            $myClass,
            $data['session_date'],
            function (int $filas) use ($request, $myClass, $member, $data): void {
                app(AuditTrail::class)->record($request, [
                    'action' => 'unenroll',
                    'module' => 'Clases',
                    'entity' => 'clase',
                    'entity_id' => $myClass->id,
                    'target_name' => $myClass->name,
                    'summary' => "Quitó a {$member->full_name} de {$myClass->name} ({$data['session_date']})",
                    'metadata' => [
                        'member_id' => $member->id,
                        'session_date' => $data['session_date'],
                        'rows' => $filas,
                        'channel' => ClassBookingService::CHANNEL_STAFF,
                    ],
                ]);
            },
        );

        return $this->respond($request, $myClass, $result);
    }

    /**
     * GET /api/admin/classes/stream
     *
     * Canal en tiempo real del módulo de clases del CRM, sobre la misma
     * infraestructura que los demás ({@see SseStream}, el vale `?ticket=` y el
     * permiso del mapa central). Es una SEÑAL: dice «algo cambió, vuelve a
     * preguntar»; los datos se leen por HTTP con los permisos de siempre.
     *
     * Por qué por huella y no por aviso: los cupos cambian desde cuatro sitios
     * —la app, el mostrador, el entrenador al iniciar o cerrar la sesión y la
     * renovación del ciclo— y la base es lo único que los ve a todos.
     */
    public function stream(Request $request): StreamedResponse
    {
        // Reanudación: el cliente manda el último id que vio. Sin él (primera
        // conexión) o si ya no hay continuidad, `resync` le pide releer todo.
        $after = $request->query('after_id');
        $apertura = ClassEventsFeed::open(is_numeric($after) ? (int) $after : null);

        // Qué recibe esta conexión en cada latido: los hechos tipados y la
        // huella de lo que no pasa por el registro. `proto=2` es el CRM que
        // entiende los hechos; las pestañas del CRM anterior no lo mandan y
        // siguen recibiendo la huella de todo. Ver ClassesChannel.
        $canal = new ClassesChannel(
            ClassEventsFeed::floorFor($apertura),
            $request->query('proto') === '2',
            fn (): string => self::classesSignature(),
        );

        return SseStream::response(function () use ($canal): void {
            foreach ($canal->tick() as $salida) {
                SseStream::emit($salida['event'], $salida['data'], $salida['id']);
            }
        }, 25, 2000, function () use ($apertura): void {
            SseStream::emit('ready', $apertura);
        }); // sondeo cada 2 s durante ~25 s; el cliente reconecta solo
    }

    /**
     * La huella del módulo de clases: cambia cuando cambia algo que el CRM
     * debe ver.
     *
     *   · Reservas: `SUM(id)` además del conteo. Cancelar es un DELETE, así
     *     que una baja y un alta en el mismo latido dejan el conteo igual; la
     *     suma de ids no, porque un id nuevo nunca repite uno borrado.
     *   · Clases: aforo, estado y reserva online, no solo la fecha de
     *     modificación, que se guarda al segundo y pierde dos cambios seguidos.
     *   · Sesiones: iniciar o cerrar una clase cambia si se puede inscribir.
     *
     * SALE HASHEADA: el cliente solo compara si cambió.
     */
    public static function classesSignature(): string
    {
        // Sesiones y asistencia, solo de la ventana que enseña el CRM (8 días
        // atrás en adelante): son tablas históricas que no se podan, y esta
        // huella se calcula cada 2 s por pestaña abierta.
        $desde = Carbon::parse(app(ClassBookingService::class)->todayDate())->subDays(8)->toDateString();

        $reservas = DB::table('class_reservations')
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(id), 0) AS s, MAX(updated_at) AS t')
            ->first();

        $clases = DB::table('classes')
            ->selectRaw('COUNT(*) AS n, MAX(updated_at) AS t, COALESCE(SUM(max_capacity), 0) AS cupo')
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END), 0) AS activas")
            ->selectRaw('COALESCE(SUM(CASE WHEN allow_online_booking THEN 1 ELSE 0 END), 0) AS online')
            ->first();

        $sesiones = DB::table('class_sessions')
            ->whereDate('session_date', '>=', $desde)
            ->selectRaw('COUNT(*) AS n, MAX(updated_at) AS t')
            ->selectRaw('COUNT(started_at) AS iniciadas, COUNT(ended_at) AS cerradas, COUNT(renewed_at) AS renovadas')
            ->first();

        // La asistencia también se ve en el CRM: marcar o corregir mueve la huella.
        $asistencia = DB::table('class_attendances')
            ->whereDate('session_date', '>=', $desde)
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(id), 0) AS s, MAX(updated_at) AS t')
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'present' THEN 1 WHEN status = 'late' THEN 2 ELSE 3 END), 0) AS estados")
            ->first();

        return hash('xxh128', implode('|', [
            "{$reservas->n}:{$reservas->s}:{$reservas->t}",
            "{$clases->n}:{$clases->t}:{$clases->cupo}:{$clases->activas}:{$clases->online}",
            "{$sesiones->n}:{$sesiones->t}:{$sesiones->iniciadas}:{$sesiones->cerradas}:{$sesiones->renovadas}",
            "{$asistencia->n}:{$asistencia->s}:{$asistencia->t}:{$asistencia->estados}",
        ]));
    }

    // ── Respuestas ────────────────────────────────────────────────────────

    /**
     * Éxito: la foto actualizada de la ocurrencia, para que el CRM pinte el
     * cupo real sin otra petición. Rechazo: 422 con un código estable y el
     * texto para recepción.
     */
    private function respond(Request $request, MyClass $myClass, ClassBookingResult $result): JsonResponse
    {
        if (! $result->ok) {
            return response()->json([
                'ok' => false,
                'code' => $result->outcome,
                'message' => self::MESSAGES[$result->outcome] ?? 'No se pudo completar la inscripción.',
                'session_date' => $result->date,
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'outcome' => $result->outcome,
            'data' => $this->snapshot($request, $myClass->refresh(), $result->date),
        ]);
    }

    /**
     * El estado de una ocurrencia tal y como lo ve el mostrador.
     *
     * Datos del socio al MÍNIMO: nombre para reconocerlo y documento solo si
     * quien pregunta ya puede ver fichas de socios. Ver la lista de una clase
     * no es permiso para leer documentos.
     *
     * Y la lista, solo con `classes.view`. Inscribir y quitar responden con
     * esta misma foto, y esas rutas se abren con `classes.enroll`: sin este
     * filtro, un rol con permiso de inscribir pero sin el de ver leería la
     * lista quitando a alguien que no estaba. Los números sí van siempre: el
     * cupo ya es público en el horario de clases.
     *
     * @return array<string, mixed>
     */
    private function snapshot(Request $request, MyClass $myClass, string $date): array
    {
        $reservas = $this->booking->occurrenceReservations($myClass, $date);
        $capacidad = (int) $myClass->max_capacity;
        $ocupados = $reservas->count();

        $session = ClassSession::where('class_id', $myClass->id)->whereDate('session_date', $date)->first();
        $admin = $request->attributes->get('auth_admin');
        $verLista = CrmPermission::allows($admin, 'classes.view');
        $verDocumento = CrmPermission::allows($admin, 'members.view');

        // El primer motivo que impide inscribir en esta fecha, si hay alguno,
        // con las mismas reglas que aplicará la inscripción.
        $bloqueo = match (true) {
            ! $this->booking->isOccurrence($myClass, $date) => ClassBookingService::NOT_AN_OCCURRENCE,
            ! $this->booking->isOpenFor($myClass, ClassBookingService::CHANNEL_STAFF) => ClassBookingService::CLASS_UNAVAILABLE,
            default => $this->booking->occurrenceBlock($myClass, $date),
        };

        return [
            'class_id' => (int) $myClass->id,
            'class_name' => $myClass->name,
            'start_time' => $myClass->start_time,
            'session_date' => $date,
            'upcoming_dates' => $this->booking->upcomingOccurrences($myClass),
            'session_status' => $this->booking->sessionStatus($session),
            'capacity' => $capacidad,
            'booked' => $ocupados,
            'available' => max(0, $capacidad - $ocupados),
            'can_enroll' => $bloqueo === null && $ocupados < $capacidad,
            'blocked_reason' => $bloqueo,
            'blocked_message' => $bloqueo !== null ? (self::MESSAGES[$bloqueo] ?? null) : null,
            'enrollments' => ! $verLista ? [] : $reservas->map(fn (ClassReservation $r) => [
                'reservation_id' => (int) $r->id,
                // Con el alcance del entrenador aplicado, un socio que no le
                // corresponde llega sin datos: ocupa cupo, pero no se ve quién es.
                'member_id' => $r->member ? (int) $r->member->id : null,
                'user_id' => $r->member?->user_id !== null ? (int) $r->member->user_id : null,
                'full_name' => $r->member?->full_name,
                'document' => $verDocumento ? $r->member?->document_number : null,
                'reserved_at' => $r->reserved_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }
}
