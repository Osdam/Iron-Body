<?php

namespace App\Services\UltronStudio;

use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\UltronStudio\StudioCanaryNumber;
use App\Support\Privacy\PhoneFingerprint;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ¿Coincide el registro de números canarios con el canario del MOTOR?
 * (contrato H6 §I4.5). SOLO LECTURA: el motor sigue atendiendo solo la
 * conversación canaria configurada en el servidor
 * (`marketing.ultron.canary_conversation_id`), y esto solo lo compara.
 *
 * EL TELÉFONO DEL LEAD CANARIO SOLO EXISTE EN MEMORIA, el tiempo justo para
 * calcular su huella: se lee con dos consultas puntuales (la conversación y su
 * lead), se normaliza, se convierte en huella y se hace `unset`. Nunca se
 * registra, se devuelve ni se guarda. La huella (`engineHash`) es de uso
 * INTERNO y no sale en ningún DTO.
 *
 * Normalización (§I4.5): solo los dígitos; con 10 que empiezan por `3` se
 * antepone `57`; se antepone `+`. Si no casa con E.164, el teléfono no sirve.
 *
 * Veredicto, por precedencia: `no_engine_canary` → `unreadable` →
 * `engine_canary_phone_unusable` → `engine_canary_not_registered` (ninguna fila
 * `whatsapp` viva con esa huella) → `engine_canary_registered_inactive` (la fila
 * está pendiente o autorizada) → `registry_has_extra_active` (la fila está
 * activa y hay OTRAS `whatsapp` activas) → `in_sync`. NUNCA `in_sync` por
 * defecto: cualquier fallo es `unreadable`, con una línea de log que lleva solo
 * la clase del error.
 *
 * `activeWhatsapp` son las filas WhatsApp activas del registro, contadas tal
 * cual: también con `no_engine_canary` (CO-4) y con `unreadable` si lo que no
 * se pudo leer fue el teléfono del lead canario y no el registro (Q-6 de la
 * Enmienda 2, decidido en informe-api.md). Solo es null si el propio recuento falló.
 */
final class StudioCanaryCoherence
{
    public const IN_SYNC = 'in_sync';

    public const NOT_REGISTERED = 'engine_canary_not_registered';

    public const REGISTERED_INACTIVE = 'engine_canary_registered_inactive';

    public const EXTRA_ACTIVE = 'registry_has_extra_active';

    public const PHONE_UNUSABLE = 'engine_canary_phone_unusable';

    public const NO_ENGINE_CANARY = 'no_engine_canary';

    public const UNREADABLE = 'unreadable';

    /** Los veredictos, en el orden de §I2. */
    public const VERDICTS = [self::IN_SYNC, self::NOT_REGISTERED, self::REGISTERED_INACTIVE, self::EXTRA_ACTIVE, self::PHONE_UNUSABLE, self::NO_ENGINE_CANARY, self::UNREADABLE];

    /**
     * Nunca lanza: cualquier Throwable → `unreadable` (al log, solo la clase).
     * `activeWhatsapp` es null solo si no se pudo contar.
     *
     * @return array{verdict:string,engineCanaryConfigured:bool,engineHash:?string,activeWhatsapp:?int}
     */
    public function read(): array
    {
        $id = self::engineCanaryConversation();

        $activos = null;
        $fallo = null;
        try {
            $activos = StudioCanaryNumber::query()
                ->where('channel', StudioCanaryPhone::CHANNEL_WHATSAPP)
                ->where('status', StudioCanaryNumber::STATUS_ACTIVE)
                ->count();
        } catch (Throwable $e) {
            $fallo = $e;
        }

        if ($id === null) {
            return self::result(self::NO_ENGINE_CANARY, false, null, $activos);
        }
        if ($fallo !== null) {
            return self::unreadable($fallo);
        }

        try {
            $leadId = MarketingConversation::query()->whereKey($id)->value('lead_id');
            $telefono = $leadId === null ? null : MarketingLead::query()->whereKey($leadId)->value('phone');
            $e164 = self::normalize($telefono);
            unset($telefono);
            if ($e164 === null) {
                return self::result(self::PHONE_UNUSABLE, true, null, $activos);
            }
            $huella = PhoneFingerprint::of($e164);
            unset($e164);

            $fila = StudioCanaryNumber::query()
                ->where('channel', StudioCanaryPhone::CHANNEL_WHATSAPP)
                ->where('phone_hash', $huella)
                ->whereIn('status', StudioCanaryNumber::LIVE)
                ->first(['id', 'status']);
            if ($fila === null) {
                return self::result(self::NOT_REGISTERED, true, $huella, $activos);
            }
            if ($fila->status !== StudioCanaryNumber::STATUS_ACTIVE) {
                return self::result(self::REGISTERED_INACTIVE, true, $huella, $activos);
            }
            $otros = StudioCanaryNumber::query()
                ->where('channel', StudioCanaryPhone::CHANNEL_WHATSAPP)
                ->where('status', StudioCanaryNumber::STATUS_ACTIVE)
                ->whereKeyNot($fila->id)
                ->exists();

            return self::result($otros ? self::EXTRA_ACTIVE : self::IN_SYNC, true, $huella, $activos);
        } catch (Throwable $e) {
            // El recuento ya se leyó: el veredicto dice que el resto no (Q-6).
            return self::unreadable($e, $activos);
        }
    }

    /**
     * `isEngineCanary` de una fila (§I4.5, paso 5): `true|false` en WhatsApp;
     * `null` en voz y con la coherencia `unreadable` o `no_engine_canary`. Es
     * el canario del motor la fila VIVA con su huella.
     *
     * @param  array{verdict:string,engineHash:?string}  $coherence
     */
    public static function isEngineCanary(array $coherence, string $channel, string $status, string $phoneHash): ?bool
    {
        if ($channel !== StudioCanaryPhone::CHANNEL_WHATSAPP || in_array($coherence['verdict'], [self::UNREADABLE, self::NO_ENGINE_CANARY], true)) {
            return null;
        }

        return $coherence['engineHash'] !== null
            && $status !== StudioCanaryNumber::STATUS_REVOKED
            && hash_equals($coherence['engineHash'], $phoneHash);
    }

    /** El bloque `coherence` de `GET integrations/canaries`: sin la huella. */
    public static function publicBlock(array $coherence): array
    {
        return [
            'engineCanaryConfigured' => $coherence['engineCanaryConfigured'],
            'verdict' => $coherence['verdict'],
            'activeWhatsapp' => $coherence['activeWhatsapp'],
        ];
    }

    /**
     * Solo los dígitos; con 10 que empiezan por `3` se antepone `57`; se
     * antepone `+`. Null si el resultado no es un E.164. Pura.
     */
    public static function normalize(#[\SensitiveParameter] mixed $phone): ?string
    {
        if (! is_string($phone) && ! is_int($phone)) {
            return null;
        }
        $digitos = (string) preg_replace('/[^0-9]/', '', (string) $phone);
        if (strlen($digitos) === 10 && str_starts_with($digitos, '3')) {
            $digitos = '57'.$digitos;
        }
        $e164 = '+'.$digitos;

        return preg_match(PhoneFingerprint::E164_PATTERN, $e164) === 1 ? $e164 : null;
    }

    /** La misma lectura que `StudioCanaryMeasures`: un entero o una cadena de dígitos; si no, sin canario. */
    public static function engineCanaryConversation(): ?int
    {
        $id = config('marketing.ultron.canary_conversation_id');

        return is_int($id) || (is_string($id) && ctype_digit($id)) ? (int) $id : null;
    }

    /** @return array{verdict:string,engineCanaryConfigured:bool,engineHash:?string,activeWhatsapp:?int} */
    private static function result(string $verdict, bool $configured, ?string $hash, ?int $active): array
    {
        return ['verdict' => $verdict, 'engineCanaryConfigured' => $configured, 'engineHash' => $hash, 'activeWhatsapp' => $active];
    }

    /** @return array{verdict:string,engineCanaryConfigured:bool,engineHash:?string,activeWhatsapp:?int} */
    private static function unreadable(Throwable $e, ?int $active = null): array
    {
        // Tragado a propósito: la coherencia se pinta en la lista de números y en
        // el estado, y no puede tumbarlos. Sin el mensaje: podría llevar datos.
        Log::warning('ultron_studio.canary_coherence_unreadable', ['error_class' => class_basename($e)]);

        return self::result(self::UNREADABLE, true, null, $active);
    }
}
