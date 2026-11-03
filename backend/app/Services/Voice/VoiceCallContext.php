<?php

namespace App\Services\Voice;

use InvalidArgumentException;

/**
 * Lo que {@see VoiceCallPolicy::decide()} necesita saber, ya calculado por
 * quien llama (contrato H6 §I6.5 y §I6.6): la política es pura y no lee la
 * configuración, la base ni el reloj.
 *
 * El constructor rechaza cualquier valor fuera de sus conjuntos cerrados: un
 * texto desconocido no puede colarse como «otro» y abrir nada.
 */
final class VoiceCallContext
{
    /** Los cuatro estados de §I2 (`ELEVENLABS_VERIFIED` incluido: lo calcula quien guarda la evidencia). */
    public const PROVIDER_STATES = [
        VoiceProviderStatus::STATE_NOT_CONFIGURED,
        VoiceProviderStatus::STATE_DISABLED,
        VoiceProviderStatus::STATE_UNVERIFIED,
        VoiceProviderStatus::STATE_VERIFIED,
    ];

    public const CANARY_ACTIVE_VOICE = 'active_voice';

    public const CANARY_NOT_ACTIVE = 'not_active';

    public const CANARY_NOT_REGISTERED = 'not_registered';

    public const CANARY_STATES = [self::CANARY_ACTIVE_VOICE, self::CANARY_NOT_ACTIVE, self::CANARY_NOT_REGISTERED];

    /** El origen de las salientes a canarios (alcance `canary`): el registro de canarios. */
    public const SOURCE_CANARY_REGISTRY = 'canary_registry';

    /** Las filas pendientes de llamadas que crea el motor (T-7): nunca alimentan un marcador. */
    public const SOURCE_MARKETING_CALLS = 'marketing_calls';

    public const SOURCE_OTHER = 'other';

    /**
     * Contrato H8, D8-12: los contactos con consentimiento de voz (alcance
     * `contacts`, fase 4b). Un cambio DELIBERADO del conjunto cerrado del H6.
     * Esta clase solo lo admite como valor: {@see VoiceCallPolicy::decide()} no
     * cambia (sigue diciendo `source_not_allowed` para todo lo que no es el
     * registro de canarios) y las puertas del destino de este origen son de
     * `Telephony\OutboundPolicy`, que de la política del H6 compone solo las
     * del código y del proveedor. `marketing_calls` sigue prohibido.
     */
    public const SOURCE_VOICE_CONTACTS = 'voice_contacts';

    public const SOURCES = [self::SOURCE_CANARY_REGISTRY, self::SOURCE_MARKETING_CALLS, self::SOURCE_OTHER, self::SOURCE_VOICE_CONTACTS];

    public function __construct(
        public readonly bool $twilioVoiceEnabled,
        public readonly bool $twilioVoiceFromConfigured,
        public readonly string $providerState,   // ELEVENLABS_NOT_CONFIGURED | _DISABLED | _UNVERIFIED | _VERIFIED
        public readonly string $canary,          // active_voice | not_active | not_registered
        public readonly string $source,          // canary_registry | marketing_calls | other | voice_contacts
        public readonly int $dailyCap,
    ) {
        if (! in_array($providerState, self::PROVIDER_STATES, true)) {
            throw new InvalidArgumentException('Estado del proveedor de voz desconocido.');
        }
        if (! in_array($canary, self::CANARY_STATES, true)) {
            throw new InvalidArgumentException('Estado de canario de voz desconocido.');
        }
        if (! in_array($source, self::SOURCES, true)) {
            throw new InvalidArgumentException('Origen de llamada desconocido.');
        }
    }
}
