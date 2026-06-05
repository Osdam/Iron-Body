<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\Payment;
use App\Models\Routine;
use App\Services\DeviceSessionService;
use App\Services\LiveKitService;
use App\Services\MembershipService;
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
        DeviceSessionService $sessions,
        MembershipService $memberships,
        LiveKitService $live
    ): JsonResponse {
        /** @var Member $member */
        $member = $request->attributes->get('auth_member');
        $member->loadMissing('user');
        $user = $member->user;

        // ── Membresía (la verdad vive en el User: plan + fecha fin) ──────────
        // Una sola forma de exponer el ciclo de vida (estado/renovación/cancelación).
        $membershipActive = $user ? $memberships->isActive($user) : false;
        $membershipSnapshot = $user
            ? $memberships->snapshot($user)
            : ['status' => MembershipService::STATUS_NONE, 'is_active' => false];

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
                'is_staff' => (bool) $member->is_staff,
            ],
            'membership' => $membershipSnapshot,
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
            // ── Story Live: permisos decididos por el backend (Flutter solo
            // renderiza). is_staff lo otorga el CRM; sin LiveKit todo queda en
            // false salvo nada (función no disponible).
            'live' => $this->buildLive($live, (bool) $member->is_staff),
            'features' => $this->buildFeatures($features, $canAccessHome, $membershipActive),
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
     * Contrato de permisos de Story Live (backend = fuente de verdad). El staff
     * (otorgado desde el CRM con `members.is_staff`) puede crear/iniciar/finalizar
     * sus lives; el resto solo mira. Sin LiveKit configurado, la función queda
     * deshabilitada (todo en false) y la app muestra "no disponible" — sin crash.
     */
    private function buildLive(LiveKitService $live, bool $isStaff): array
    {
        $enabled = $live->isConfigured();
        $staffCan = $enabled && $isStaff;

        return [
            'enabled' => $enabled,
            'provider' => (string) config('live.provider', 'livekit'),
            'is_staff' => $isStaff,
            'can_create' => $staffCan,
            'can_start' => $staffCan,
            'can_end_own_live' => $staffCan,
            'can_view' => $enabled,
        ];
    }

    /**
     * Contrato de features que la app obedece (backend = fuente de verdad, sin
     * hardcode en Flutter). Las features gateadas por plan (IA, entrenamiento,
     * progreso, nutrición, clases, rutinas) salen del mapa del plan; las secciones
     * base (perfil, seguridad, membresía, stories, biblioteca, store, notifs) se
     * habilitan con el acceso. `can_use_full_app` = acceso + plan premium completo
     * (todas las features núcleo del plan en true → p. ej. Plan Total).
     *
     * @param array<string,bool> $features  mapa de Member::resolvedFeatures()
     */
    private function buildFeatures(array $features, bool $canAccessHome, bool $membershipActive): array
    {
        $f = fn (string $k) => (bool) ($features[$k] ?? false);

        // Features núcleo que definen una experiencia premium completa. `ranking`
        // se excluye a propósito (Plan Total lo trae en false por diseño y no es
        // un módulo bloqueante de la app).
        $premiumCoreKeys = ['iron_ia', 'workouts', 'custom_routines', 'classes', 'progress', 'nutrition'];
        $hasFullPremium = $canAccessHome
            && collect($premiumCoreKeys)->every(fn ($k) => $f($k));

        return [
            'can_access_home'             => $canAccessHome,
            'requires_activation'         => ! $canAccessHome,
            // Gateadas por plan:
            'can_use_ai'                  => $f('iron_ia') && $canAccessHome,
            'can_use_training'            => $f('workouts') && $canAccessHome,
            'can_use_progress'            => $f('progress') && $canAccessHome,
            'can_use_nutrition'           => $f('nutrition') && $canAccessHome,
            'can_use_classes'             => $f('classes') && $canAccessHome,
            'can_use_custom_routines'     => $f('custom_routines') && $canAccessHome,
            'can_use_ranking'             => $f('ranking') && $canAccessHome,
            // Secciones base (disponibles con acceso a la app):
            'can_use_exercise_library'    => $canAccessHome,
            'can_use_stories'             => $canAccessHome,
            'can_use_reels'               => $canAccessHome,
            'can_use_profile'             => $canAccessHome,
            'can_use_security_devices'    => $canAccessHome,
            'can_use_membership_details'  => $canAccessHome,
            'can_use_store'               => $canAccessHome,
            'can_use_notifications_center'=> $canAccessHome,
            // Resumen:
            'can_use_full_app'            => $hasFullPremium,
            // Mapa crudo del plan para gating fino del cliente (no hardcode).
            'plan_features'               => $features,
        ];
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
