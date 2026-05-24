<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Member;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class AttendanceController extends Controller
{
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

            return response()->json([
                'ok' => true,
                'attendance' => $this->serialize($attendance->load('user:id,name,plan')),
            ], 201);
        } catch (Throwable $e) {
            return response()->json([
                'ok' => false,
                'message' => 'No se pudo registrar la asistencia.',
            ], 500);
        }
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

    /** Última acción registrada para un usuario, para deducir entry vs exit. */
    private function nextActionFor(int $userId): string
    {
        $last = Attendance::query()
            ->where('user_id', $userId)
            ->orderByDesc('captured_at')
            ->first();

        return $last && $last->action === 'entry' ? 'exit' : 'entry';
    }

    private function serialize(Attendance $a): array
    {
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
            'captured_at' => optional($a->captured_at)->toIso8601String(),
            'date' => optional($a->captured_at)->toDateString(),
            'time' => optional($a->captured_at)->format('H:i'),
        ];
    }
}
