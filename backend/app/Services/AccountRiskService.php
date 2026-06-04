<?php

namespace App\Services;

use App\Models\Member;
use App\Models\MemberRiskLock;
use App\Models\MemberSecurityEvent;

/**
 * Puntaje de riesgo y suspensión de cuentas (Fase 10).
 *
 * Calcula un puntaje a partir de los eventos de seguridad recientes y, según los
 * umbrales de config/security.php, avisa o suspende. La suspensión AUTOMÁTICA
 * está apagada por defecto (`security.autosuspend`): hoy solo avisa, para no
 * castigar errores normales. La suspensión MANUAL del CRM aplica siempre.
 */
class AccountRiskService
{
    public function __construct(
        private SecurityEventService $security,
        private NotificationService $notifications,
        private DeviceSessionService $sessions,
    ) {
    }

    /** Bloqueo de seguridad vivo del miembro, si existe. */
    public function liveLock(Member $member): ?MemberRiskLock
    {
        return $member->activeRiskLock();
    }

    /** Puntaje de riesgo (0..∞) según eventos recientes y pesos configurados. */
    public function score(Member $member): int
    {
        $window  = (int) config('security.window', 3600);
        $weights = (array) config('security.weights', []);

        $since  = now()->subSeconds($window);
        $counts = MemberSecurityEvent::query()
            ->where('member_id', $member->id)
            ->where('created_at', '>=', $since)
            ->selectRaw('type, COUNT(*) as c')
            ->groupBy('type')
            ->pluck('c', 'type');

        $map = [
            MemberSecurityEvent::TYPE_LOGIN_FAILED       => 'login_failed',
            MemberSecurityEvent::TYPE_OTP_BLOCKED        => 'otp_blocked',
            MemberSecurityEvent::TYPE_NEW_DEVICE         => 'new_device',
            MemberSecurityEvent::TYPE_FACE_FAILED        => 'face_failed',
            MemberSecurityEvent::TYPE_CONCURRENT         => 'concurrent',
            MemberSecurityEvent::TYPE_CONCURRENT_BLOCKED => 'concurrent',
            MemberSecurityEvent::TYPE_DEVICE_MISMATCH    => 'device_mismatch',
            MemberSecurityEvent::TYPE_SUSPICIOUS         => 'suspicious',
        ];

        $score = 0;
        foreach ($map as $type => $weightKey) {
            $count = (int) ($counts[$type] ?? 0);
            $score += $count * (int) ($weights[$weightKey] ?? 0);
        }

        return $score;
    }

    /**
     * Evalúa el riesgo y actúa: avisa al cruzar `warn_threshold` (con cooldown)
     * y suspende al cruzar `suspend_threshold` SOLO si `autosuspend` está activo.
     * Es no-op barato si el puntaje es bajo. Nunca lanza (defensivo).
     */
    public function assess(Member $member, array $context = []): void
    {
        try {
            if ($member->isSuspended()) {
                return; // ya está suspendido
            }

            $score      = $this->score($member);
            $warnAt     = (int) config('security.warn_threshold', 40);
            $suspendAt  = (int) config('security.suspend_threshold', 80);

            if ($score < $warnAt) {
                return;
            }

            if ($score >= $suspendAt && (bool) config('security.autosuspend', false)) {
                $this->suspend(
                    $member,
                    'Actividad sospechosa detectada automáticamente.',
                    (int) config('security.suspend_days', 3),
                    MemberRiskLock::BY_SYSTEM,
                    context: $context,
                    metadata: ['risk_score' => $score],
                );

                return;
            }

            // Solo aviso (con cooldown para no spamear).
            $cooldown = (int) config('security.warn_cooldown', 1800);
            $recent = MemberSecurityEvent::query()
                ->where('member_id', $member->id)
                ->where('type', MemberSecurityEvent::TYPE_SUSPICIOUS)
                ->where('created_at', '>=', now()->subSeconds($cooldown))
                ->exists();

            if (! $recent) {
                $this->security->record($member, MemberSecurityEvent::TYPE_SUSPICIOUS, $context, [
                    'risk_score'     => $score,
                    'autosuspend_on' => (bool) config('security.autosuspend', false),
                ], 'Puntaje de riesgo elevado.');
                $this->notifications->notifySuspiciousLogin($member, 'Detectamos varios intentos inusuales.');
            }
        } catch (\Throwable $e) {
            // El scoring nunca debe tumbar el flujo de auth.
            report($e);
        }
    }

    /**
     * Suspende la cuenta: crea un bloqueo vivo, marca el estado del miembro,
     * revoca todas las sesiones y notifica. `days` null = bloqueo sin caducidad.
     */
    public function suspend(
        Member $member,
        string $reason,
        ?int $days = null,
        string $by = MemberRiskLock::BY_ADMIN,
        ?int $resolvedBy = null,
        array $context = [],
        array $metadata = [],
    ): MemberRiskLock {
        $lock = MemberRiskLock::create([
            'member_id'    => $member->id,
            'reason'       => $reason,
            'status'       => MemberRiskLock::STATUS_ACTIVE,
            'locked_until' => $days !== null ? now()->addDays($days) : null,
            'created_by'   => $by,
            'metadata'     => $metadata ?: null,
        ]);

        // El estado del miembro refleja la suspensión (bloquea login + sesiones).
        if ($member->status !== Member::STATUS_SUSPENDED) {
            $member->forceFill(['status' => Member::STATUS_SUSPENDED])->save();
        }

        foreach ($this->sessions->activeSessions($member) as $session) {
            $this->sessions->revoke($session, 'account_suspended');
        }

        $this->security->record($member, MemberSecurityEvent::TYPE_ACCOUNT_SUSPENDED, $context, [
            'reason'    => $reason,
            'until'     => $lock->locked_until?->toIso8601String(),
            'by'        => $by,
            'lock_id'   => $lock->id,
        ]);
        $this->notifications->notifyAccountSuspended($member, $reason, $lock->locked_until?->toIso8601String());

        return $lock;
    }

    /**
     * Levanta la suspensión: resuelve los bloqueos vivos y restaura el estado del
     * miembro a activo (solo si estaba suspendido).
     */
    public function unlock(Member $member, ?string $note = null, ?int $resolvedBy = null, array $context = []): void
    {
        $member->riskLocks()->live()->get()->each(function (MemberRiskLock $lock) use ($note, $resolvedBy): void {
            $lock->forceFill([
                'status'          => MemberRiskLock::STATUS_RESOLVED,
                'resolved_by'     => $resolvedBy,
                'resolution_note' => $note,
            ])->save();
        });

        if ($member->status === Member::STATUS_SUSPENDED) {
            $member->forceFill(['status' => Member::STATUS_ACTIVE])->save();
        }

        $this->security->record($member, MemberSecurityEvent::TYPE_ACCOUNT_UNLOCKED, $context, [
            'note' => $note,
            'by'   => $resolvedBy ? 'admin' : 'system',
        ]);
    }
}
