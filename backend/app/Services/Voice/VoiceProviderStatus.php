<?php

namespace App\Services\Voice;

use App\Services\Voice\ElevenLabs\ElevenLabsConfig;
use InvalidArgumentException;

/**
 * El estado de la voz, sin red y sin secretos (contrato H6 §I6.1 y §I6.3).
 *
 *  - `ELEVENLABS_NOT_CONFIGURED`: falta alguna pieza o no tiene forma válida
 *    (`missing` ≠ []). Es el estado de producción en este hito.
 *  - `ELEVENLABS_DISABLED`: piezas completas y bandera apagada.
 *  - `ELEVENLABS_UNVERIFIED`: bandera encendida y piezas completas.
 *
 * `ELEVENLABS_VERIFIED` NO sale de aquí: lo calcula quien guarda la evidencia
 * de una verificación (este espacio neutro no guarda nada). La constante
 * existe para el conjunto cerrado de {@see VoiceCallContext}.
 *
 * `provider` es el proveedor que devolvería {@see VoiceProviders::current()}:
 * `elevenlabs` si y solo si el estado es `ELEVENLABS_UNVERIFIED`; si no,
 * `none`. Así `status()->provider === current()->id()` siempre.
 *
 * El constructor rechaza lo que no es del contrato (conjuntos cerrados de
 * §I2 y la coherencia entre estado, bandera, piezas y región): un estado
 * imposible es un fallo de programación y no debe llegar a una respuesta.
 * Los mensajes no llevan valores.
 */
final class VoiceProviderStatus
{
    public const PROVIDER_NONE = 'none';

    public const PROVIDER_ELEVENLABS = 'elevenlabs';

    public const PROVIDERS = [self::PROVIDER_NONE, self::PROVIDER_ELEVENLABS];

    public const STATE_NOT_CONFIGURED = 'ELEVENLABS_NOT_CONFIGURED';

    public const STATE_DISABLED = 'ELEVENLABS_DISABLED';

    public const STATE_UNVERIFIED = 'ELEVENLABS_UNVERIFIED';

    /** Solo lo calcula quien guarda la evidencia; nunca lo lleva un VoiceProviderStatus. */
    public const STATE_VERIFIED = 'ELEVENLABS_VERIFIED';

    /** Los estados que puede llevar un VoiceProviderStatus. */
    public const STATES = [self::STATE_NOT_CONFIGURED, self::STATE_DISABLED, self::STATE_UNVERIFIED];

    /** Las piezas de `missing`, en su orden (§I2): «falta o no tiene forma válida». */
    public const PIECES = ['api_key', 'agent_id', 'webhook_secret', 'region'];

    /**
     * @param  list<string>  $missing  subconjunto ordenado de {@see PIECES}
     */
    public function __construct(
        public readonly string $provider,     // 'none' | 'elevenlabs'
        public readonly string $state,        // ELEVENLABS_NOT_CONFIGURED | ELEVENLABS_DISABLED | ELEVENLABS_UNVERIFIED
        public readonly bool $enabled,        // voice.elevenlabs.enabled
        /** @var list<string> */ public readonly array $missing,
        public readonly ?string $region,      // clave válida de ElevenLabsConfig::REGIONS o null
    ) {
        if (! in_array($provider, self::PROVIDERS, true)) {
            throw new InvalidArgumentException('Proveedor de voz desconocido.');
        }
        if (! in_array($state, self::STATES, true)) {
            throw new InvalidArgumentException('Estado de voz desconocido.');
        }
        if (! array_is_list($missing) || array_values(array_intersect(self::PIECES, $missing)) !== $missing) {
            throw new InvalidArgumentException('Las piezas que faltan tienen que ser un subconjunto ordenado de api_key, agent_id, webhook_secret y region.');
        }
        if ($region !== null && ! array_key_exists($region, ElevenLabsConfig::REGIONS)) {
            throw new InvalidArgumentException('Región de voz fuera de la lista cerrada.');
        }
        if (($region === null) !== in_array('region', $missing, true)) {
            throw new InvalidArgumentException('La región es nula si y solo si falta.');
        }
        $esperado = match (true) {
            $missing !== [] => self::STATE_NOT_CONFIGURED,
            ! $enabled => self::STATE_DISABLED,
            default => self::STATE_UNVERIFIED,
        };
        if ($state !== $esperado) {
            throw new InvalidArgumentException('El estado de voz no casa con la bandera y las piezas.');
        }
        if (($provider === self::PROVIDER_ELEVENLABS) !== ($state === self::STATE_UNVERIFIED)) {
            throw new InvalidArgumentException('El proveedor de voz es elevenlabs si y solo si el estado es ELEVENLABS_UNVERIFIED.');
        }
    }

    /** @return array{provider:string, state:string, enabled:bool, missing:list<string>, region:?string} */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'state' => $this->state,
            'enabled' => $this->enabled,
            'missing' => $this->missing,
            'region' => $this->region,
        ];
    }
}
