<?php

namespace App\Services\Telephony;

use App\Models\Voice\VoiceCallEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Escribe los eventos de D8-13 (`voice_call_events`, solo inserción),
 * deduplicados por (`provider`, `kind`, `provider_event_key`): un duplicado
 * devuelve null y no escribe nada (D8-16: duplicado → 200). Sin clave del
 * proveedor no se deduplica (DF-S7).
 *
 * Lo que se guarda son CÓDIGOS: `codes` lleva códigos, números de turno y
 * cifras, nunca texto de una transcripción, un teléfono ni un cuerpo crudo.
 */
final class VoiceEvents
{
    public const KEY_PATTERN = '/^[A-Za-z0-9_.:-]{1,128}$/D';

    /**
     * @param  array{tool?: ?string, outcome?: ?string, codes?: ?array, actor_type?: string, actor_admin_id?: ?int, actor_name?: ?string, actor_role?: ?string, occurred_at?: mixed}  $extra
     */
    public static function record(string $kind, ?int $callId, string $provider, ?string $key, array $extra = []): ?VoiceCallEvent
    {
        if (! in_array($kind, VoiceCallEvent::KINDS, true)) {
            throw new InvalidArgumentException('Tipo de evento de voz fuera del conjunto cerrado.');
        }
        if ($key !== null && preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new InvalidArgumentException('La clave del evento no tiene forma.');
        }

        $evento = (new VoiceCallEvent)->forceFill([
            'call_id' => $callId,
            'provider' => $provider,
            'kind' => $kind,
            'provider_event_key' => $key,
            'tool' => $extra['tool'] ?? null,
            'outcome' => isset($extra['outcome']) ? self::code($extra['outcome']) : null,
            'codes' => $extra['codes'] ?? null,
            'actor_type' => $extra['actor_type'] ?? ($provider === VoiceCallEvent::PROVIDER_LARAVEL ? VoiceCallEvent::ACTOR_SYSTEM : VoiceCallEvent::ACTOR_PROVIDER),
            'actor_admin_id' => $extra['actor_admin_id'] ?? null,
            'actor_name' => $extra['actor_name'] ?? null,
            'actor_role' => $extra['actor_role'] ?? null,
            'occurred_at' => $extra['occurred_at'] ?? null,
        ]);

        try {
            // Un punto de guardado propio: en PostgreSQL, un único repetido no
            // aborta la transacción de quien llama.
            DB::transaction(static fn () => $evento->save());
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        return $evento;
    }

    /**
     * Un valor de un proveedor como CÓDIGO de la base (`^[a-z][a-z0-9_]{0,31}$`,
     * `vce_codes_chk`): `no-answer` → `no_answer`. Lo que no se puede escribir
     * así queda como `unknown`.
     */
    public static function code(?string $valor): string
    {
        $c = trim((string) preg_replace('/[^a-z0-9_]+/', '_', strtolower((string) $valor)), '_');

        return preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $c) === 1 ? $c : 'unknown';
    }

    public static function exists(string $kind, string $provider, string $key): bool
    {
        return VoiceCallEvent::query()->where('provider', $provider)->where('kind', $kind)->where('provider_event_key', $key)->exists();
    }
}
