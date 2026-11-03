<?php

namespace App\Services\Voice\Dto;

use InvalidArgumentException;
use SensitiveParameterValue;

/**
 * La petición de UNA llamada saliente (contrato H8, D8-12 «Petición»): lo que
 * la puerta de salientes ya decidió, en forma neutra. La construye el job de la
 * telefonía justo antes de marcar y la consume `VoiceCallStarter`.
 *
 *  - `agentId`: el agente SELLADO en la fila de la llamada (nunca otro).
 *  - `phoneNumberRef`: el id del número importado en el proveedor
 *    (`ELEVENLABS_AGENT_PHONE_NUMBER_ID`).
 *  - `toNumber`: el E.164 del destino, DESCIFRADO EN MEMORIA en el instante de
 *    marcar (D8-17). Va envuelto en `SensitiveParameterValue`: `dump()`,
 *    `var_dump()` y `print_r()` no lo enseñan y `serialize()` se niega, así que
 *    esta petición tampoco puede viajar en una cola. {@see toArray()} solo
 *    lleva los cuatro últimos dígitos.
 *  - `callRef`: el uuid opaco de la fila (`user_id` y la variable `call_ref`).
 *  - `dynamicVariables`: exactamente las de {@see VoiceAgentSpec::VARIABLES},
 *    con `caller_authorized = si`, `call_direction = saliente` y
 *    `call_ref = callRef` (una saliente la autoriza la puerta; con la
 *    dirección, la plantilla elige el saludo de las salientes).
 *  - `ringingTimeoutSeconds` y `voicemailPolicy`: [P-10] (el buzón solo admite
 *    `hang_up`: Enmienda 1, E1-8).
 *
 * La grabación no es un campo: en el H8 va siempre apagada [P-7], en el
 * proveedor y en el operador.
 */
final class OutboundCallRequest
{
    /** [P-10]: los segundos de timbre (el proveedor usa 60 por defecto). */
    public const MIN_RINGING_SECONDS = 10;

    public const MAX_RINGING_SECONDS = 60;

    /** @var array<string, string> en el orden de VoiceAgentSpec::VARIABLES */
    public readonly array $dynamicVariables;

    /** @var SensitiveParameterValue<string> */
    private readonly SensitiveParameterValue $toNumber;

    /**
     * @param  array<string, string>  $dynamicVariables
     *
     * @throws InvalidArgumentException con el campo que falla, nunca con su valor
     */
    public function __construct(
        public readonly string $agentId,
        public readonly string $phoneNumberRef,
        #[\SensitiveParameter] string $toNumber,
        public readonly string $callRef,
        array $dynamicVariables,
        public readonly int $ringingTimeoutSeconds,
        public readonly string $voicemailPolicy,
    ) {
        if (! VoiceIds::isProviderId($agentId)) {
            throw new InvalidArgumentException('agentId no tiene la forma de un id del proveedor.');
        }
        if (! VoiceIds::isProviderId($phoneNumberRef)) {
            throw new InvalidArgumentException('phoneNumberRef no tiene la forma de un id del proveedor.');
        }
        if (preg_match(VoiceAgentSpec::E164_PATTERN, $toNumber) !== 1) {
            throw new InvalidArgumentException('toNumber no es un E.164 estricto.');
        }
        if (! VoiceIds::isUuid($callRef)) {
            throw new InvalidArgumentException('callRef tiene que ser un uuid en minúsculas.');
        }

        $claves = array_keys($dynamicVariables);
        sort($claves, SORT_STRING);
        if ($claves !== VoiceAgentSpec::VARIABLES) {
            throw new InvalidArgumentException('dynamicVariables tiene que llevar exactamente las variables del agente.');
        }
        foreach ($dynamicVariables as $valor) {
            if (! is_string($valor) || $valor === '' || mb_strlen($valor, 'UTF-8') > VoiceAgentSpec::ALIAS_MAX
                || preg_match('//u', $valor) !== 1 || preg_match('/[\p{Cc}\p{Cf}]/u', $valor) !== 0 || str_contains($valor, '{{')) {
                throw new InvalidArgumentException('dynamicVariables lleva un valor vacío, demasiado largo o con caracteres de control.');
            }
        }
        if ($dynamicVariables[VoiceAgentSpec::VAR_CALLER_AUTHORIZED] !== VoiceAgentSpec::CALLER_AUTHORIZED) {
            throw new InvalidArgumentException('Una saliente solo sale autorizada (caller_authorized = si).');
        }
        if ($dynamicVariables[VoiceAgentSpec::VAR_CALL_DIRECTION] !== VoiceAgentSpec::DIRECTION_OUTBOUND) {
            throw new InvalidArgumentException('Una saliente lleva call_direction = saliente.');
        }
        if ($dynamicVariables[VoiceAgentSpec::VAR_CALL_REF] !== $callRef) {
            throw new InvalidArgumentException('La variable call_ref tiene que ser el callRef de la petición.');
        }

        if ($ringingTimeoutSeconds < self::MIN_RINGING_SECONDS || $ringingTimeoutSeconds > self::MAX_RINGING_SECONDS) {
            throw new InvalidArgumentException('ringingTimeoutSeconds fuera de '.self::MIN_RINGING_SECONDS.'–'.self::MAX_RINGING_SECONDS.'.');
        }
        if (! in_array($voicemailPolicy, VoiceAgentSpec::VOICEMAIL_POLICIES, true)) {
            throw new InvalidArgumentException('voicemailPolicy fuera del conjunto cerrado.');
        }

        ksort($dynamicVariables, SORT_STRING);
        $this->dynamicVariables = $dynamicVariables;
        $this->toNumber = new SensitiveParameterValue($toNumber);
    }

    /** EN CLARO: solo para el cuerpo de la petición al proveedor. */
    public function toNumber(): string
    {
        return $this->toNumber->getValue();
    }

    /** @return array{agentId:string, phoneNumberRef:string, toLast4:string, callRef:string, ringingTimeoutSeconds:int, voicemailPolicy:string} sin el número ni los textos */
    public function toArray(): array
    {
        return [
            'agentId' => $this->agentId,
            'phoneNumberRef' => $this->phoneNumberRef,
            'toLast4' => substr($this->toNumber->getValue(), -4),
            'callRef' => $this->callRef,
            'ringingTimeoutSeconds' => $this->ringingTimeoutSeconds,
            'voicemailPolicy' => $this->voicemailPolicy,
        ];
    }
}
