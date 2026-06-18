<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Member;
use App\Models\MemberBiometric;
use App\Models\TurnstileSetting;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\TurnstileService;
use App\Support\SseStream;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class AttendanceController extends Controller
{
    public function __construct(private readonly TurnstileService $turnstile)
    {
    }

    /**
     * Listado paginado de asistencias para el CRM. Filtros opcionales:
     * - user_id: solo asistencias de un miembro.
     * - from / to: rango de fechas (YYYY-MM-DD), inclusivo.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Attendance::query()
            ->with('user:id,name,plan')
            ->orderByDesc('captured_at');

        if ($userId = $request->integer('user_id')) {
            $query->where('user_id', $userId);
        }
        if ($from = $request->input('from')) {
            $query->where('captured_at', '>=', Carbon::parse($from)->startOfDay());
        }
        if ($to = $request->input('to')) {
            $query->where('captured_at', '<=', Carbon::parse($to)->endOfDay());
        }

        $perPage = (int) $request->input('per_page', 50);
        $perPage = max(1, min($perPage, 200));

        $page = $query->paginate($perPage)->through(fn (Attendance $a) => $this->serialize($a));

        return response()->json($page);
    }

    /**
     * Registra una asistencia (manual o facial). Si no se indica `action`,
     * la deduce a partir de la última asistencia del usuario en el día.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'action' => ['nullable', 'in:entry,exit'],
            'source' => ['nullable', 'in:facial,manual'],
            'confidence' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $user = User::query()->findOrFail($data['user_id']);
            $member = Member::query()->where('user_id', $user->id)->first();

            $action = $data['action'] ?? $this->nextActionFor($user->id);
            $source = $data['source'] ?? 'manual';

            // Anti-doble-marcado para lectura facial: evita re-registrar la
            // misma acción si ocurrió hace menos de 60 segundos.
            if ($source === 'facial') {
                $recent = Attendance::query()
                    ->where('user_id', $user->id)
                    ->where('captured_at', '>=', now()->subSeconds(60))
                    ->orderByDesc('captured_at')
                    ->first();

                if ($recent && $recent->action === $action) {
                    return response()->json([
                        'ok' => true,
                        'deduplicated' => true,
                        'attendance' => $this->serialize($recent),
                    ]);
                }
            }

            $attendance = Attendance::query()->create([
                'user_id' => $user->id,
                'member_id' => $member?->id,
                'action' => $action,
                'source' => $source,
                'confidence' => $data['confidence'] ?? null,
                'note' => $data['note'] ?? null,
                'captured_at' => now(),
            ]);

            $turnstileResult = $this->maybeOpenTurnstile($attendance, $user);

            return response()->json([
                'ok' => true,
                'attendance' => $this->serialize($attendance->load('user:id,name,plan')),
                'turnstile' => $turnstileResult,
            ], 201);
        } catch (Throwable $e) {
            return response()->json([
                'ok' => false,
                'message' => 'No se pudo registrar la asistencia.',
            ], 500);
        }
    }

    /**
     * Tiempo real (SSE): empuja cada asistencia nueva en cuanto se registra,
     * venga de la lectura facial, del registro manual o de otra estación. El
     * CRM la inserta al instante en el feed sin recargar. EventSource reconecta
     * solo; el polling/recarga queda como fallback.
     */
    public function stream(Request $request): StreamedResponse
    {
        $cursor = $request->filled('after_id')
            ? (int) $request->query('after_id')
            : (int) (Attendance::max('id') ?? 0);

        return SseStream::response(function () use (&$cursor): void {
            $items = Attendance::query()
                ->with('user:id,name,plan')
                ->where('id', '>', $cursor)
                ->orderBy('id')
                ->limit(50)
                ->get();

            foreach ($items as $attendance) {
                SseStream::emit('attendance', $this->serialize($attendance), $attendance->id);
                $cursor = $attendance->id;
            }
        }, 20, 1500);
    }

    /**
     * Catálogo de rostros de referencia para que el CRM precompute los
     * descriptores con face-api.js. Solo incluye miembros activos con
     * imagen biométrica guardada.
     */
    public function faceReferences(): JsonResponse
    {
        $members = Member::query()
            ->with(['user:id,name,plan,status', 'biometric:id,member_id,face_path,captured_at'])
            ->whereHas('biometric')
            ->whereHas('user')
            ->get();

        $references = $members
            ->map(function (Member $member) {
                $user = $member->user;
                $biometric = $member->biometric;
                if (! $user || ! $biometric) {
                    return null;
                }

                return [
                    'user_id'     => $user->id,
                    'member_id'   => $member->id,
                    'member_uuid' => $member->member_uuid,
                    'name'        => $user->name ?: $member->full_name,
                    'plan'        => $user->plan,
                    'face_url'    => url("/api/attendances/face-image/{$user->id}"),
                    'captured_at' => optional($biometric->captured_at)->toIso8601String(),
                ];
            })
            ->filter()
            ->values();

        return response()->json([
            'ok' => true,
            'count' => $references->count(),
            'data' => $references,
        ]);
    }

    /**
     * Sirve la imagen biométrica de un usuario para que el navegador del CRM
     * pueda computar el descriptor. Solo accesible desde el CRM (mismo origin
     * en producción) — en una iteración posterior, proteger con auth de staff.
     */
    public function faceImage(int $userId): Response|StreamedResponse
    {
        $member = Member::query()
            ->with('biometric')
            ->where('user_id', $userId)
            ->first();

        $biometric = $member?->biometric;
        if (! $biometric || ! $biometric->face_path) {
            return response('Not found', 404);
        }

        $disk = Storage::disk('local');
        if (! $disk->exists($biometric->face_path)) {
            return response('Not found', 404);
        }

        return $disk->response($biometric->face_path, null, [
            'Content-Type' => $biometric->face_mime ?: 'image/jpeg',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    /**
     * Miembros REGISTRADOS que aún NO tienen rostro biométrico. Es la lista de
     * trabajo del punto físico: el staff los selecciona y les asigna el rostro
     * con la cámara del CRM. Excluye registros incompletos (sin usuario CRM).
     */
    public function faceEnrollmentPending(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));

        $members = Member::query()
            ->with('user:id,name,plan,status')
            ->whereHas('user')
            ->whereDoesntHave('biometric')
            ->whereIn('status', [
                Member::STATUS_ACTIVE,
                Member::STATUS_PENDING_REGISTRATION,
                Member::STATUS_INCOMPLETE,
            ])
            ->when($search !== '', function ($q) use ($search): void {
                $like = '%' . $search . '%';
                $q->where(function ($sub) use ($like): void {
                    $sub->where('full_name', 'like', $like)
                        ->orWhere('document_number', 'like', $like)
                        ->orWhere('email', 'like', $like);
                });
            })
            ->orderByDesc('created_at')
            ->limit(500)
            ->get();

        $data = $members->map(function (Member $member): array {
            $user = $member->user;

            return [
                'member_id'     => $member->id,
                'user_id'       => $user?->id,
                'member_uuid'   => $member->member_uuid,
                'name'          => $user?->name ?: $member->full_name,
                'document'      => $member->document_number,
                'plan'          => $user?->plan,
                'status'        => $member->status,
                'biometric_status' => $member->biometric_status,
                'created_at'    => optional($member->created_at)->toIso8601String(),
            ];
        })->values();

        return response()->json([
            'ok' => true,
            'count' => $data->count(),
            'data' => $data,
        ]);
    }

    /**
     * Asigna (enrola) el rostro de un miembro desde el CRM. Guarda la imagen
     * biométrica, marca el estado y avisa en tiempo real (SSE) al CRM y al
     * miembro reutilizando el mismo flujo de notificación que la app.
     */
    public function enrollFace(Request $request, Member $member): JsonResponse
    {
        $request->validate([
            'face' => ['required', 'image', 'mimes:jpeg,jpg,png', 'max:8192'],
        ]);

        try {
            $old = $member->biometric;
            $file = $request->file('face');
            $path = $file->store("members/{$member->member_uuid}/biometrics/faces", 'local');

            $member->biometric()->updateOrCreate(
                ['member_id' => $member->id],
                [
                    'face_path' => $path,
                    'face_mime' => $file->getMimeType(),
                    'face_size' => $file->getSize(),
                    'captured_at' => now(),
                    'bytes_length' => $file->getSize(),
                    'normalizer_version' => null,
                    'enrolled_platform' => 'crm',
                    'biometric_reference_status' => MemberBiometric::STATUS_ACTIVE,
                    'last_biometric_enrolled_at' => now(),
                ]
            );

            $member->biometric_status = Member::BIOMETRIC_REGISTERED;
            $member->save();

            if ($old?->face_path && $old->face_path !== $path) {
                Storage::disk('local')->delete($old->face_path);
            }

            // Aviso real-time: CRM (re-index del terminal facial) + miembro (app).
            app(NotificationService::class)->notifyFaceEnrolled($member->fresh());

            return response()->json([
                'ok' => true,
                'message' => 'Rostro registrado correctamente.',
                'member_id' => $member->id,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'ok' => false,
                'message' => 'No se pudo registrar el rostro.',
            ], 500);
        }
    }

    /**
     * Si la configuración lo permite, dispara el torniquete tras un check-in.
     * Devuelve siempre algo serializable (incluso en error/desactivado) para
     * que el frontend pueda mostrar el estado al operador.
     */
    private function maybeOpenTurnstile(Attendance $attendance, User $user): array
    {
        $settings = TurnstileSetting::current();

        $shouldFire = $settings->enabled
            && (($attendance->action === 'entry' && $settings->fire_on_entry)
                || ($attendance->action === 'exit' && $settings->fire_on_exit));

        if (! $shouldFire) {
            return ['fired' => false, 'reason' => $settings->enabled ? 'action_disabled' : 'disabled'];
        }

        $result = $this->turnstile->trigger($settings, [
            'member_name' => $user->name ?: 'Miembro',
            'user_id' => $user->id,
            'action' => $attendance->action,
        ]);

        return array_merge(['fired' => true], $result);
    }

    /** Última acción registrada para un usuario, para deducir entry vs exit. */
    private function nextActionFor(int $userId): string
    {
        $last = Attendance::query()
            ->where('user_id', $userId)
            ->orderByDesc('captured_at')
            ->first();

        return $last && $last->action === 'entry' ? 'exit' : 'entry';
    }

    /** Zona horaria de negocio: la BD guarda en UTC, se muestra en hora local. */
    private const DISPLAY_TZ = 'America/Bogota';

    private function serialize(Attendance $a): array
    {
        // captured_at se persiste en UTC; para mostrar hora/fecha al operador se
        // convierte a la zona del gimnasio (si no, sale corrido ~5h).
        $local = $a->captured_at?->copy()->setTimezone(self::DISPLAY_TZ);

        return [
            'id' => $a->id,
            'user_id' => $a->user_id,
            'member_id' => $a->member_id,
            'member_name' => $a->user?->name,
            'plan' => $a->user?->plan,
            'action' => $a->action,
            'source' => $a->source,
            'confidence' => $a->confidence,
            'note' => $a->note,
            'captured_at' => $local?->toIso8601String(),
            'date' => $local?->toDateString(),
            'time' => $local?->format('H:i'),
        ];
    }
}
