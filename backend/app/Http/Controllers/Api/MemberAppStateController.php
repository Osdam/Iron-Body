<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\Payment;
use App\Models\Routine;
use App\Services\DeviceSessionService;
use App\Services\WeeklyStreakService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Snapshot consolidado y versionado del estado del miembro autenticado: la
 * ÚNICA fuente de verdad que la app usa para reflejar, sin reiniciar, los
 * cambios tras pagos, edición de perfil, racha, entrenamiento o dispositivos.
 *
 * Solo lectura. Pensado para llamarse tras cada evento importante y en el
 * resume de la app. No expone datos sensibles innecesarios (documento masked).
 */
class MemberAppStateController extends Controller
{
    public function show(
        Request $request,
        WeeklyStreakService $streakService,
        DeviceSessionService $sessions
    ): JsonResponse {
        /** @var Member $member */
        $member = $request->attributes->get('auth_member');
        $member->loadMissing('user');
        $user = $member->user;

        // ── Membresía (la verdad vive en el User: plan + fecha fin) ──────────
        $endsAt = $user && $user->membership_end_date
            ? Carbon::parse($user->membership_end_date)->endOfDay()
            : null;
        $hasPlan = (bool) ($user && $user->plan);
        $membershipActive = $hasPlan && (! $endsAt || $endsAt->isFuture());
        $daysRemaining = $endsAt
            ? max(0, (int) Carbon::now()->startOfDay()->diffInDays($endsAt->copy()->startOfDay(), false))
            : null;

        // ── Pago: último estado normalizado + aprobación ────────────────────
        $lastPayment = Payment::where('member_id', $member->id)->latest('id')->first();
        $hasApprovedPayment = Payment::where('member_id', $member->id)
            ->whereRaw('LOWER(status) IN (?, ?)', ['approved', 'paid'])
            ->exists();

        // ── Acceso: membresía activa O pago aprobado O member activo ────────
        $canAccessHome = $member->status === Member::STATUS_ACTIVE
            || $membershipActive
            || $hasApprovedPayment;

        // ── Features resueltas por plan (gating de IA/entrenamiento) ────────
        $features = $member->resolvedFeatures();

        // ── Entrenamiento de hoy (rotación determinista por día) ────────────
        $today = $this->todayWorkout($member);

        // ── Racha ───────────────────────────────────────────────────────────
        $streak = $streakService->summary($member);

        // ── Seguridad / dispositivos ────────────────────────────────────────
        $activeDevices = $sessions->activeSessions($member);
        $currentSession = $request->attributes->get('auth_device_session');

        // ── Completitud de perfil ───────────────────────────────────────────
        $photoUrl = $member->getAttribute('profile_photo_url'); // null hasta Bloque B
        $missing = [];
        if (empty($member->phone)) {
            $missing[] = 'phone';
        }
        if (empty($member->goal)) {
            $missing[] = 'goal';
        }
        $requiresPhoto = empty($photoUrl);
        if ($requiresPhoto) {
            $missing[] = 'profile_photo';
        }
        $totalFields = 3; // phone, goal, photo
        $percent = (int) round((($totalFields - count($missing)) / $totalFields) * 100);

        return response()->json([
            'ok' => true,
            'server_time' => Carbon::now()->toIso8601String(),
            'member' => [
                'id' => $member->id,
                'uuid' => $member->member_uuid,
                'full_name' => $member->full_name,
                'email' => $member->email ?: $user?->email,
                'document_number_masked' => $this->maskDocument($member->document_number),
                'phone' => $member->phone,
                'profile_photo_url' => $photoUrl,
                'status' => $member->status,
                'biometric_status' => $member->biometric_status,
            ],
            'membership' => [
                'status' => $membershipActive ? 'active' : ($hasPlan ? 'expired' : 'none'),
                'plan_name' => $hasPlan ? $user->plan : null,
                'starts_at' => $user?->membership_start_date,
                'ends_at' => $endsAt?->toDateString(),
                'days_remaining' => $daysRemaining,
                'is_active' => $membershipActive,
            ],
            'payment' => [
                'last_status' => $lastPayment ? Payment::normalizeStatus($lastPayment->status) : 'none',
                'last_payment_id' => $lastPayment?->id,
            ],
            'training' => [
                'has_today_workout' => $today !== null,
                'today_workout_id' => $today['id'] ?? null,
                'title' => $today['title'] ?? null,
                'estimated_minutes' => $today['estimated_minutes'] ?? null,
            ],
            'streak' => [
                'current' => (int) ($streak['current_streak_days'] ?? 0),
                'best' => (int) ($streak['longest_streak_days'] ?? 0),
                'active_days_this_week' => (int) ($streak['active_days_this_week'] ?? 0),
            ],
            'profile_completion' => [
                'requires_photo' => $requiresPhoto,
                'percent' => $percent,
                'missing' => $missing,
            ],
            'security' => [
                'active_devices_count' => $activeDevices->count(),
                'current_device_trusted' => $currentSession !== null,
            ],
            'features' => [
                'can_access_home' => $canAccessHome,
                'requires_activation' => ! $canAccessHome,
                'can_use_ai' => (bool) ($features['iron_ia'] ?? false) && $canAccessHome,
                'can_use_training' => (bool) ($features['workouts'] ?? false) && $canAccessHome,
            ],
            'versions' => [
                'profile' => $this->ver($member->updated_at),
                'membership' => $this->ver($user?->updated_at ?? $lastPayment?->updated_at),
                'training' => $this->ver($today['updated_at'] ?? null),
                'streak' => (int) ($streak['active_days_this_week'] ?? 0) * 100 + (int) ($streak['current_streak_days'] ?? 0),
                'security' => $this->ver($activeDevices->max('updated_at')),
            ],
        ]);
    }

    /**
     * Misma selección que AppRoutineController::today (rutina asignada del día).
     * @return array{id:int,title:string,estimated_minutes:int,updated_at:mixed}|null
     */
    private function todayWorkout(Member $member): ?array
    {
        $routines = Routine::whereHas('assignments', fn ($q) => $q->where('member_id', $member->id))
            ->get()
            ->merge(Routine::where('member_id', $member->id)->where('is_assigned', true)->get())
            ->unique('id')->sortBy('id')->values();

        if ($routines->isEmpty()) {
            return null;
        }
        $r = $routines[((int) Carbon::now()->dayOfYear) % $routines->count()];

        return [
            'id' => $r->id,
            'title' => (string) $r->name,
            'estimated_minutes' => (int) ($r->estimated_minutes ?? $r->duration_minutes ?? 0),
            'updated_at' => $r->updated_at,
        ];
    }

    private function maskDocument(?string $doc): ?string
    {
        $doc = (string) $doc;
        if (strlen($doc) <= 4) {
            return $doc === '' ? null : str_repeat('•', strlen($doc));
        }

        return str_repeat('•', strlen($doc) - 4).substr($doc, -4);
    }

    private function ver($timestamp): int
    {
        return $timestamp ? (int) Carbon::parse($timestamp)->timestamp : 0;
    }
}
