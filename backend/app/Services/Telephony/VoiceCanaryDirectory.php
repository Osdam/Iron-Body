<?php

namespace App\Services\Telephony;

use App\Models\UltronStudio\StudioCanaryNumber;
use App\Services\UltronStudio\StudioCanaryPhone;
use App\Services\Voice\VoiceCallContext;
use App\Support\Privacy\PhoneFingerprint;
use SensitiveParameterValue;
use Throwable;

/**
 * El ÚNICO adaptador de la telefonía hacia el registro de canarios del Studio
 * (contrato H8, D8-2 y D8-19: «el T3 de voz»). SOLO LECTURA: no escribe, no
 * transiciona y no registra. Lee los números de canal `voice` del registro
 * del H6 por su huella del H6 (`PhoneFingerprint`, etiqueta
 * `ultron-canary-phone/1`) y, para marcar, descifra el número EN MEMORIA.
 *
 * Estados (los del H6, `VoiceCallContext::CANARY_STATES`, más el caller ID
 * oculto): `active_voice` si el número es un canario de voz `active`;
 * `not_active` si está registrado y vivo sin activar; `not_registered` en
 * cualquier otro caso (revocado o desconocido); `withheld` sin un E.164.
 */
final class VoiceCanaryDirectory
{
    /** @return array{state: string, uuid: ?string} */
    public static function lookup(#[\SensitiveParameter] ?string $e164): array
    {
        if (! VoicePhone::isE164($e164)) {
            return ['state' => InboundPolicy::CALLER_WITHHELD, 'uuid' => null];
        }
        $vivos = StudioCanaryNumber::query()
            ->where('channel', StudioCanaryPhone::CHANNEL_VOICE)
            ->where('phone_hash', PhoneFingerprint::of($e164))
            ->whereIn('status', StudioCanaryNumber::LIVE)
            ->get(['id', 'uuid', 'status']);
        $activo = $vivos->firstWhere('status', StudioCanaryNumber::STATUS_ACTIVE);
        if ($activo !== null) {
            return ['state' => VoiceCallContext::CANARY_ACTIVE_VOICE, 'uuid' => (string) $activo->uuid];
        }
        $vivo = $vivos->first();

        return $vivo === null
            ? ['state' => VoiceCallContext::CANARY_NOT_REGISTERED, 'uuid' => null]
            : ['state' => VoiceCallContext::CANARY_NOT_ACTIVE, 'uuid' => (string) $vivo->uuid];
    }

    /**
     * Un canario de voz por su uuid, para una saliente: su estado y, si está
     * activo, el número descifrado EN MEMORIA (envuelto: no se vuelca).
     *
     * @return array{state: string, uuid: string, number: ?SensitiveParameterValue}|null
     */
    public static function forDial(string $uuid): ?array
    {
        $numero = StudioCanaryNumber::query()->where('uuid', strtolower($uuid))->where('channel', StudioCanaryPhone::CHANNEL_VOICE)->first();
        if ($numero === null) {
            return null;
        }
        $estado = match (true) {
            $numero->status === StudioCanaryNumber::STATUS_ACTIVE => VoiceCallContext::CANARY_ACTIVE_VOICE,
            in_array($numero->status, StudioCanaryNumber::LIVE, true) => VoiceCallContext::CANARY_NOT_ACTIVE,
            default => VoiceCallContext::CANARY_NOT_REGISTERED,
        };
        $claro = null;
        if ($estado === VoiceCallContext::CANARY_ACTIVE_VOICE) {
            try {
                $valor = $numero->phone_encrypted;
                $claro = VoicePhone::isE164($valor) ? new SensitiveParameterValue($valor) : null;
            } catch (Throwable) {
                // Un cifrado ilegible (otra APP_KEY) no se marca: el número queda sin descifrar.
                $claro = null;
            }
        }

        return ['state' => $estado, 'uuid' => (string) $numero->uuid, 'number' => $claro];
    }
}
