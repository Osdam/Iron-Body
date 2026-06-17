<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\MemberClassContext;
use App\Http\Controllers\Controller;
use App\Http\Resources\ClassResource;
use App\Models\ClassAttendance;
use App\Models\ClassReservation;
use App\Models\ClassSession;
use App\Models\Member;
use App\Models\MyClass;
use App\Services\NotificationService;
use App\Services\RealtimeEvents;
use App\Services\Trainer\TrainerRealtimeEvents;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AppClassController extends Controller
{
    use MemberClassContext;

    private function member(Request $request): Member
    {
        return $request->attributes->get('auth_member');
    }

    public function index(Request $request): JsonResponse
    {
        $member = $this->member($request);

        $classes = MyClass::query()
            ->where('status', 'active')
            ->where('allow_online_booking', true)
            ->with('trainer:id,full_name')
            ->withCount('reservations')
            ->get();

        $classIds = $classes->pluck('id');
        $today = Carbon::today();

        $reservedIds = ClassReservation::where('member_id', $member->id)
            ->whereIn('class_id', $classIds)
            ->pluck('class_id')
            ->flip();

        // Sesión vigente (en curso / recién finalizada / hoy) + asistencia de HOY
        // del miembro, en bloque (sin N+1).
        $sessions = $this->relevantSessionsFor($classIds);
        $attendance = ClassAttendance::where('member_id', $member->id)
            ->whereIn('class_id', $classIds)
            ->whereDate('session_date', $today)
            ->get()
            ->keyBy('class_id');

        return response()->json([
            'data' => $classes->map(function (MyClass $c) use ($reservedIds, $sessions, $attendance) {
                $reserved = $reservedIds->has($c->id);
                return new ClassResource(
                    $c,
                    $reserved,
                    $this->memberClassContext($reserved, $sessions->get($c->id), $attendance->get($c->id)),
                );
            }),
        ]);
    }

    public function reserve(Request $request, MyClass $myClass): JsonResponse
    {
        $member = $this->member($request);

        if ($myClass->status !== 'active' || ! $myClass->allow_online_booking) {
            return response()->json(['message' => 'Clase no disponible para reservas.'], 422);
        }

        $bookedSpots = $myClass->reservations()->count();
        if ($bookedSpots >= $myClass->max_capacity) {
            return response()->json(['message' => 'Clase completa.'], 422);
        }

        if (ClassReservation::where('class_id', $myClass->id)->where('member_id', $member->id)->exists()) {
            return response()->json(['message' => 'Ya tienes una reserva para esta clase.'], 422);
        }

        ClassReservation::create([
            'class_id'    => $myClass->id,
            'member_id'   => $member->id,
            'reserved_at' => now(),
        ]);

        // Notificación de clase reservada (ADITIVO; no afecta la reserva).
        $notifier = app(NotificationService::class);
        $notifier->notifyClassReserved($member, $myClass);

        // Si esta reserva agotó el cupo, avisa al CRM (idempotente por clase).
        if (($bookedSpots + 1) >= $myClass->max_capacity) {
            $notifier->notifyClassFull($myClass);
        }

        $myClass->loadCount('reservations')->load('trainer:id,full_name');

        // Cupo cambió → refresca el módulo de Clases para todos en vivo.
        RealtimeEvents::classesChanged();

        return response()->json(['data' => $this->resourceFor($myClass, $member, true)]);
    }

    public function cancel(Request $request, MyClass $myClass): JsonResponse
    {
        $member = $this->member($request);

        // No se puede cancelar una clase que ya inició o finalizó (sesión de hoy).
        $session = $this->currentClassSession($myClass);
        if ($session && $session->started_at) {
            return response()->json([
                'message' => $session->ended_at
                    ? 'La clase ya finalizó.'
                    : 'La clase ya está en curso.',
            ], 422);
        }

        $deleted = ClassReservation::where('class_id', $myClass->id)
            ->where('member_id', $member->id)
            ->delete();

        if (! $deleted) {
            return response()->json(['message' => 'No tienes reserva en esta clase.'], 422);
        }

        // Notificación de reserva cancelada (ADITIVO; no afecta la cancelación).
        app(NotificationService::class)->notifyClassReservationCancelled($member, $myClass);

        $myClass->loadCount('reservations')->load('trainer:id,full_name');

        // Cupo liberado → refresca el módulo de Clases para todos en vivo.
        RealtimeEvents::classesChanged();

        return response()->json(['data' => $this->resourceFor($myClass, $member, false)]);
    }

    /**
     * AUTO CHECK-IN: el miembro marca su propia asistencia (presente) cuando la
     * clase está EN CURSO. Requiere reserva y que el entrenador haya iniciado la
     * clase (sesión con `started_at` y sin `ended_at`).
     */
    public function checkIn(Request $request, MyClass $myClass): JsonResponse
    {
        $member = $this->member($request);
        $today = Carbon::today();

        $reserved = ClassReservation::where('class_id', $myClass->id)
            ->where('member_id', $member->id)
            ->exists();
        if (! $reserved) {
            return response()->json(['message' => 'No tienes reserva en esta clase.'], 422);
        }

        $session = $this->currentClassSession($myClass);
        if (! $session || ! $session->started_at) {
            return response()->json(['message' => 'La clase aún no ha iniciado.'], 422);
        }
        if ($session->ended_at) {
            return response()->json(['message' => 'La clase ya finalizó.'], 422);
        }

        $sessionDate = optional($session->session_date)->toDateString() ?? $today->toDateString();
        ClassAttendance::updateOrCreate(
            [
                'class_id' => $myClass->id,
                'member_id' => $member->id,
                'session_date' => $sessionDate,
            ],
            [
                'status' => ClassAttendance::STATUS_PRESENT,
                'marked_at' => now(),
            ],
        );

        $myClass->loadCount('reservations')->load('trainer:id,full_name');

        // Realtime: el entrenador ve la asistencia entrar en vivo en su portal.
        if ($myClass->trainer_id) {
            TrainerRealtimeEvents::emit((int) $myClass->trainer_id, TrainerRealtimeEvents::ATTENDANCE, ['classes']);
        }

        return response()->json([
            'message' => 'Asistencia registrada.',
            'data' => $this->resourceFor($myClass, $member, true),
        ]);
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    /** ClassResource con el contexto del miembro recalculado (reserva/cancela/etc.). */
    private function resourceFor(MyClass $class, Member $member, bool $reserved): ClassResource
    {
        $session = $this->currentClassSession($class);
        $sessionDate = optional($session?->session_date)->toDateString() ?? Carbon::today()->toDateString();
        $attendance = ClassAttendance::where('class_id', $class->id)
            ->where('member_id', $member->id)
            ->whereDate('session_date', $sessionDate)
            ->first();

        return new ClassResource($class, $reserved, $this->memberClassContext($reserved, $session, $attendance));
    }
}
