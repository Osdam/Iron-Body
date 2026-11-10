<?php

namespace App\Services\Telephony;

use App\Services\Voice\VoiceProviders;
use App\Services\Voice\VoiceProviderStatus;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;

/**
 * El estado de ElevenLabs para la política de salientes (contrato H8, D8-12):
 * `VERIFIED` exige una verificación `ok` de menos de 30 días.
 *
 * LA EVIDENCIA de esa verificación la guarda el Studio
 * (`ultron-studio:integrations-snapshot --verify-voice`, en su tabla de
 * instantáneas), y la telefonía no puede leer esa tabla
 * (TelephonyIsolationTest: del Studio, solo los canarios y su cadena). Por eso
 * (E4-6, B8-g) el mismo comando, al guardar su evidencia, deja aquí una MARCA
 * en la caché ({@see recordStudioVerification()}), y esta clase es la única que
 * la lee:
 *  - una verificación `ok` escribe el instante de esa evidencia (UTC, ISO 8601),
 *    que caduca a los 30 días;
 *  - cualquier otra verificación guardada (clave mala, agente que no existe,
 *    sin conexión, error) BORRA la marca: como el Studio, manda la ÚLTIMA.
 *
 * Es la paridad con `StudioIntegrations::studioVoiceState()`: `VERIFIED` =
 * `UNVERIFIED` + la última verificación `ok` de hace menos de 30 días (estricto).
 *
 * FALLA CERRADO: sin marca, con una marca caducada, ilegible o del futuro (más
 * allá de {@see FUTURE_SKEW_SECONDS}), la telefonía dice `UNVERIFIED` y la
 * política, `elevenlabs_unverified` (ninguna saliente). Vaciar la caché de la
 * aplicación (`cache:clear`, `optimize:clear`) borra la marca: hay que repetir
 * `--verify-voice` (un GET) para volver a `VERIFIED`.
 */
final class VoiceProviderState
{
    /** La marca de la última verificación `ok` del Studio. No lleva secretos: solo un instante. */
    public const VERIFIED_CACHE_KEY = 'voice:elevenlabs:verified_at';

    /** 30 días, estricto: el mismo umbral que el Studio (`StudioIntegrationConfig::VOICE_VERIFIED_MAX_AGE_SECONDS`). */
    public const VERIFIED_MAX_AGE_SECONDS = 2592000;

    /** Holgura de relojes hacia delante: una marca más allá de esto en el futuro no vale. */
    public const FUTURE_SKEW_SECONDS = 300;

    /** El formato de la marca: ISO 8601 en UTC, al segundo. */
    public const MARK_FORMAT = 'Y-m-d\TH:i:s\Z';

    /** Su forma, antes de leerla (una fecha imposible la caza la ida y vuelta del formato). */
    public const MARK_PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D';

    public const MARK_VALID = 'valid';

    public const MARK_EXPIRED = 'expired';

    public const MARK_MISSING = 'missing';

    public const MARK_INVALID = 'invalid';

    public const MARK_STATES = [self::MARK_VALID, self::MARK_EXPIRED, self::MARK_MISSING, self::MARK_INVALID];

    /** Lo que hizo {@see recordStudioVerification()}. */
    public const RECORD_WRITTEN = 'written';

    public const RECORD_CLEARED = 'cleared';

    public const RECORD_NOT_WRITTEN = 'not_written';

    public static function current(?CarbonInterface $now = null): string
    {
        $estado = VoiceProviders::status()->state;

        return $estado === VoiceProviderStatus::STATE_UNVERIFIED && self::verifiedRecently($now) ? VoiceProviderStatus::STATE_VERIFIED : $estado;
    }

    /** ¿La última verificación del Studio salió `ok` hace menos de 30 días? Sin marca válida, no. */
    public static function verifiedRecently(?CarbonInterface $now = null): bool
    {
        return self::verification($now)['state'] === self::MARK_VALID;
    }

    /**
     * La marca, para `voice:doctor --telefonia` y el estado de la telefonía:
     * su estado, cuándo se verificó y cuándo caduca. Sin red y sin secretos.
     *
     * @return array{state: string, verifiedAt: ?string, expiresAt: ?string}
     */
    public static function verification(?CarbonInterface $now = null): array
    {
        $valor = Cache::get(self::VERIFIED_CACHE_KEY);
        if ($valor === null) {
            return ['state' => self::MARK_MISSING, 'verifiedAt' => null, 'expiresAt' => null];
        }
        // Solo con la forma exacta: `createFromFormat` lanza con otra, y esto falla cerrado, nunca lanza.
        $en = is_string($valor) && preg_match(self::MARK_PATTERN, $valor) === 1 ? CarbonImmutable::createFromFormat(self::MARK_FORMAT, $valor, 'UTC') : false;
        if (! $en instanceof CarbonImmutable || $en->format(self::MARK_FORMAT) !== $valor) {
            return ['state' => self::MARK_INVALID, 'verifiedAt' => null, 'expiresAt' => null];
        }
        $ahora = CarbonImmutable::instance($now ?? now())->utc();
        $edad = $ahora->getTimestamp() - $en->getTimestamp();
        $estado = match (true) {
            $edad < -self::FUTURE_SKEW_SECONDS => self::MARK_INVALID,
            $edad < self::VERIFIED_MAX_AGE_SECONDS => self::MARK_VALID,
            default => self::MARK_EXPIRED,
        };

        return ['state' => $estado, 'verifiedAt' => $valor, 'expiresAt' => $en->addSeconds(self::VERIFIED_MAX_AGE_SECONDS)->format(self::MARK_FORMAT)];
    }

    /**
     * La ÚNICA escritura de la marca (E4-6, B8-g). La llama
     * `ultron-studio:integrations-snapshot --verify-voice` después de guardar
     * su evidencia, con el resultado y el instante de esa evidencia:
     *  - `ok` → escribe el instante (UTC) con 30 días de vida, menos lo que ya
     *    tenga la evidencia;
     *  - otro resultado → la borra (manda la última verificación, como el Studio).
     *
     * @return string {@see RECORD_WRITTEN}, {@see RECORD_CLEARED} o {@see RECORD_NOT_WRITTEN} (la caché no la aceptó: sigue sin verificar)
     */
    public static function recordStudioVerification(bool $ok, CarbonInterface $verifiedAt): string
    {
        if (! $ok) {
            Cache::forget(self::VERIFIED_CACHE_KEY);

            return self::RECORD_CLEARED;
        }
        $en = CarbonImmutable::instance($verifiedAt)->utc();
        $resta = self::VERIFIED_MAX_AGE_SECONDS - (now()->getTimestamp() - $en->getTimestamp());
        if ($resta <= 0) {
            // Una evidencia ya caducada no verifica nada (y una marca anterior tampoco vale ya).
            Cache::forget(self::VERIFIED_CACHE_KEY);

            return self::RECORD_CLEARED;
        }

        return Cache::put(self::VERIFIED_CACHE_KEY, $en->format(self::MARK_FORMAT), min(self::VERIFIED_MAX_AGE_SECONDS + self::FUTURE_SKEW_SECONDS, $resta))
            ? self::RECORD_WRITTEN
            : self::RECORD_NOT_WRITTEN;
    }
}
