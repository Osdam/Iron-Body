<?php

namespace App\Services;

use App\Models\AutomationEvent;
use App\Models\Member;
use App\Support\ProactiveCoach\ProactiveCoachCatalog;
use Carbon\CarbonImmutable;

/**
 * Orquesta la emisión de eventos del Iron Body Proactive Coach (Fase 2).
 *
 * Responsabilidades:
 *  - Construir el bloque `notification` premium/personalizado (vía catálogo).
 *  - Aplicar el presupuesto anti-spam por miembro/día (fuertes/total) ANTES de
 *    emitir, además del gate final de AppNotificationService.
 *  - Generar la idempotency_key (día o semana) para no duplicar.
 *  - Respetar quiet hours.
 *  - Soportar dry-run (previsualiza sin escribir nada).
 *
 * NO envía a OpenAI, NO toca FCM directamente: solo emite el evento hacia la
 * cola/n8n reutilizando AutomationEventService (mismo pipeline ya probado).
 */
class ProactiveCoachService
{
    public function __construct(private readonly AutomationEventService $events)
    {
    }

    /**
     * Decide y (si procede) emite un evento proactivo para un miembro.
     *
     * @param array<string,mixed> $context  Datos seguros del contexto (sin PII sensible).
     * @return array{status:string, reason:?string, event_type:string, member_id:int,
     *               notification:?array, idempotency_key:string}
     *   status: would_emit | emitted | skipped | duplicate
     */
    public function consider(Member $member, string $eventType, array $context = [], bool $dryRun = false): array
    {
        $key = $this->idempotencyKey($eventType, $member->id);
        $notification = ProactiveCoachCatalog::buildNotification(
            $eventType,
            $member->full_name,
            $this->variantSeed(),
        );

        $base = [
            'event_type' => $eventType,
            'member_id' => $member->id,
            'notification' => $notification,
            'idempotency_key' => $key,
        ];

        if ($notification === null) {
            return $base + ['status' => 'skipped', 'reason' => 'no_catalog_entry'];
        }

        // Quiet hours (defensa extra; el scheduler ya corre en horas válidas).
        if ($this->inQuietHours()) {
            return $base + ['status' => 'skipped', 'reason' => 'quiet_hours'];
        }

        // Ya emitido (idempotencia por día/semana).
        if (AutomationEvent::query()->where('idempotency_key', $key)->exists()) {
            return $base + ['status' => 'duplicate', 'reason' => 'already_emitted'];
        }

        // Presupuesto diario (fuertes/total).
        $budget = $this->budgetCheck($member->id, $eventType);
        if ($budget !== null) {
            return $base + ['status' => 'skipped', 'reason' => $budget];
        }

        if ($dryRun) {
            return $base + ['status' => 'would_emit', 'reason' => null];
        }

        $payload = [
            'member' => ['id' => $member->id, 'name' => $member->full_name],
            'notification' => $notification,
            'context' => $context,
        ];

        $this->events->emit($eventType, $member->id, $payload, $key);

        return $base + ['status' => 'emitted', 'reason' => null];
    }

    /** Idempotency key: diaria o semanal según la cadencia del catálogo. */
    public function idempotencyKey(string $eventType, int $memberId): string
    {
        $now = $this->now();
        $tag = ProactiveCoachCatalog::cadence($eventType) === 'weekly'
            ? $now->format('o-\WW')          // año-semana ISO
            : $now->toDateString();          // fecha
        return "{$eventType}:{$memberId}:{$tag}";
    }

    /**
     * Aplica el presupuesto. Devuelve null si está permitido o el motivo del
     * skip ('budget_strong' | 'budget_total').
     */
    private function budgetCheck(int $memberId, string $eventType): ?string
    {
        $cfg = config('proactive_coach.budget');
        $maxStrong = (int) ($cfg['max_strong_per_day'] ?? 1);
        $maxTotal = (int) ($cfg['max_total_per_day'] ?? 2);

        $start = $this->now()->startOfDay();
        $todayTypes = AutomationEvent::query()
            ->where('member_id', $memberId)
            ->where('created_at', '>=', $start->toDateTimeString())
            ->pluck('event_type')
            ->filter(fn ($t) => ProactiveCoachCatalog::isProactive((string) $t));

        if ($todayTypes->count() >= $maxTotal) {
            return 'budget_total';
        }
        if (ProactiveCoachCatalog::intensity($eventType) === 'strong') {
            $strong = $todayTypes->filter(
                fn ($t) => ProactiveCoachCatalog::intensity((string) $t) === 'strong'
            )->count();
            if ($strong >= $maxStrong) {
                return 'budget_strong';
            }
        }
        return null;
    }

    private function inQuietHours(): bool
    {
        $q = config('proactive_coach.quiet_hours');
        $start = (int) ($q['start_hour'] ?? 21);
        $end = (int) ($q['end_hour'] ?? 8);
        $hour = (int) $this->now()->format('G');

        // Ventana que cruza medianoche (p. ej. 21→8).
        if ($start > $end) {
            return $hour >= $start || $hour < $end;
        }
        return $hour >= $start && $hour < $end;
    }

    /** Semilla para rotar variantes de copy (día del año). */
    private function variantSeed(): int
    {
        return (int) $this->now()->format('z');
    }

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::now(config('proactive_coach.timezone', 'America/Bogota'));
    }
}
