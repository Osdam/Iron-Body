<?php

namespace App\Services;

use App\Models\IronAiUserEvent;
use App\Models\IronAiUserProfile;
use App\Models\Member;
use Illuminate\Support\Facades\Log;

/**
 * Memoria controlada de IRON IA (vive en PostgreSQL, NO en n8n).
 *
 * Registra hitos importantes del miembro y mantiene un perfil resumido para dar
 * continuidad al coach sin reenviar todo el historial. Nunca guarda datos
 * prohibidos (documento, biometría, firma, pagos): el payload se sanea.
 */
class IronAiMemoryService
{
    /** Importancia por tipo de evento (1 = bajo, 5 = alto). */
    private const IMPORTANCE = [
        'evaluation.created' => 4,
        'nutrition.goal_updated' => 3,
        'meal.logged' => 1,
        'workout.completed' => 2,
        'streak.completed' => 3,
        'weight.changed' => 4,
        'injury.reported' => 5,
        'goal.changed' => 5,
        'membership.renewed' => 2,
    ];

    /** Mismas claves prohibidas que la capa de automatización. */
    private function forbiddenKeys(): array
    {
        return array_map('strtolower', (array) config('automation.forbidden_keys', []));
    }

    /**
     * Registra un evento de memoria. Idempotente si se pasa idempotencyKey.
     */
    public function recordEvent(
        Member $member,
        string $eventType,
        array $payload = [],
        ?string $idempotencyKey = null,
    ): ?IronAiUserEvent {
        if ($idempotencyKey !== null) {
            $existing = IronAiUserEvent::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                return $existing;
            }
        }

        $event = IronAiUserEvent::create([
            'member_id' => $member->id,
            'event_type' => $eventType,
            'payload_json' => $this->sanitize($payload),
            'importance' => self::IMPORTANCE[$eventType] ?? 1,
            'occurred_at' => now(),
            'idempotency_key' => $idempotencyKey,
        ]);

        Log::info('iron-ai.memory.event', [
            'member_id' => $member->id,
            'event_type' => $eventType,
            'importance' => $event->importance,
        ]);

        return $event;
    }

    /**
     * Asegura/actualiza el perfil IA del miembro (datos estables + resumen).
     */
    public function updateProfile(Member $member, array $attributes): IronAiUserProfile
    {
        $allowed = [
            'primary_goal', 'secondary_goal', 'training_level', 'nutrition_style',
            'preferences_summary', 'injuries_summary', 'ai_memory_summary',
        ];
        $clean = array_intersect_key($this->sanitize($attributes), array_flip($allowed));
        $clean['last_context_refresh_at'] = now();

        return IronAiUserProfile::updateOrCreate(
            ['member_id' => $member->id],
            $clean,
        );
    }

    /** Perfil IA del miembro (o null si aún no existe). */
    public function profileFor(Member $member): ?IronAiUserProfile
    {
        return IronAiUserProfile::query()->where('member_id', $member->id)->first();
    }

    /**
     * Eventos recientes más importantes (para inyectar continuidad al coach).
     *
     * @return array<int,array{event_type:string,importance:int,occurred_at:?string,data:array}>
     */
    public function recentImportantEvents(Member $member, int $limit = 8): array
    {
        return IronAiUserEvent::query()
            ->where('member_id', $member->id)
            ->orderByDesc('importance')
            ->orderByDesc('occurred_at')
            ->limit($limit)
            ->get()
            ->map(fn (IronAiUserEvent $e) => [
                'event_type' => $e->event_type,
                'importance' => $e->importance,
                'occurred_at' => $e->occurred_at?->toIso8601String(),
                'data' => $e->payload_json ?? [],
            ])
            ->all();
    }

    /** Saneo recursivo: elimina claves sensibles del payload. */
    private function sanitize(array $payload): array
    {
        $forbidden = $this->forbiddenKeys();

        $clean = function (array $data) use (&$clean, $forbidden): array {
            $out = [];
            foreach ($data as $key => $value) {
                $lower = is_string($key) ? strtolower($key) : $key;
                $isForbidden = false;
                if (is_string($lower)) {
                    foreach ($forbidden as $bad) {
                        if (str_contains($lower, $bad)) {
                            $isForbidden = true;
                            break;
                        }
                    }
                }
                if ($isForbidden) {
                    continue;
                }
                $out[$key] = is_array($value) ? $clean($value) : $value;
            }
            return $out;
        };

        return $clean($payload);
    }
}
