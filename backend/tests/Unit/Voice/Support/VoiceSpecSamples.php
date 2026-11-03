<?php

namespace Tests\Unit\Voice\Support;

use App\Services\Voice\Dto\VoiceAgentSpec;
use App\Services\Voice\Dto\VoiceAgentTarget;

/**
 * Muestras SINTÉTICAS para las pruebas de B8-a: una especificación con la
 * forma exacta que produce el compilador de S8 (`StudioVoiceCompiler::compile()`
 * al 03-11-2026) y un destino de despliegue. Ningún dato real: ids, huellas y
 * teléfonos (+57 601 000 00xx) inventados.
 */
final class VoiceSpecSamples
{
    public const REF_A = 'a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1';

    public const REF_B = 'b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2';

    public const PHONE_A = '+576010000001';

    public const PHONE_B = '+576010000002';

    public const BASE = 'https://api.ironbody.test';

    /** @return array<string, mixed> */
    public static function spec(): array
    {
        return [
            'contract' => 'ultron-voice-spec/1',
            'compilerVersion' => 'ultron-voice-compiler/1',
            'template' => ['version' => 'ultron-voice-template/1', 'hash' => str_repeat('c3', 32)],
            'source' => [
                'studioVersionUuid' => '0f6c2a52-6c1e-4c3a-9a51-7d3b0e1c2a10',
                'studioContentHash' => str_repeat('d4', 32),
                'catalogVersion' => '2026-10-19.1',
                'voiceProfileUuid' => '5b8e0c3d-2f4a-4b6e-8c1d-9e7f6a5b4c3d',
                'voiceProfileHash' => str_repeat('e5', 32),
            ],
            'language' => 'es',
            'voiceId' => 'pNInz6obpgDQGcFmaJgB',
            'firstMessage' => 'Hola, te habla el asistente virtual de Iron Body Workout, un sistema de inteligencia artificial.',
            'systemPrompt' => "Eres el asistente virtual de voz.\n\nDATOS DE ESTA LLAMADA\n- Autorización: {{caller_authorized}}\n- Tipo de llamada: {{call_direction}}\n- Hora: {{system__time}}",
            'dynamicVariables' => [
                'call_direction' => 'entrante',
                'call_ref' => 'sin_referencia',
                'caller_authorized' => 'no',
                'version_label' => 'sin_etiqueta',
            ],
            'overridesAllowed' => false,
            'maxDurationSeconds' => 300,
            'tools' => [
                ['name' => 'consultar_horarios', 'description' => 'Devuelve la sede y el horario de Iron Body.'],
                ['name' => 'consultar_planes', 'description' => 'Devuelve los planes vigentes de Iron Body con sus condiciones.'],
                ['name' => 'no_volver_a_llamar', 'description' => 'Registra que la persona no quiere recibir más llamadas.'],
                ['name' => 'solicitar_humano', 'description' => 'Dice si ahora hay alguien del equipo disponible.'],
            ],
            'systemTools' => [
                ['name' => 'end_call', 'description' => 'Termina la llamada.'],
                ['name' => 'transfer_to_number', 'description' => 'Transfiere la llamada a un destino aprobado.'],
            ],
            'transfer' => [
                'mode' => 'conference',
                'destinations' => [
                    ['alias' => 'Gerencia', 'ref' => self::REF_B, 'condition' => 'Solo si solicitar_humano acaba de indicar el destino «Gerencia» como disponible.'],
                    ['alias' => 'Recepción', 'ref' => self::REF_A, 'condition' => 'Solo si solicitar_humano acaba de indicar el destino «Recepción» como disponible.'],
                ],
            ],
            'prices' => 'catalog_tool_only',
            'forbiddenPhrases' => ['garantizado'],
            'privacy' => ['retentionDays' => 30, 'storeAudio' => false, 'recording' => false],
            'voicemail' => 'hang_up',
        ];
    }

    /** La especificación sin transferencia (sin destinos ni `transfer_to_number` ni `solicitar_humano`). */
    public static function specWithoutTransfer(): array
    {
        $s = self::spec();
        $s['tools'] = array_values(array_filter($s['tools'], static fn (array $t): bool => $t['name'] !== 'solicitar_humano'));
        $s['systemTools'] = [['name' => 'end_call', 'description' => 'Termina la llamada.']];
        $s['transfer']['destinations'] = [];

        return $s;
    }

    public static function voiceSpec(): VoiceAgentSpec
    {
        return VoiceAgentSpec::fromArray(self::spec());
    }

    public static function target(string $purpose = VoiceAgentTarget::PURPOSE_PUBLICATION, ?string $postCall = 'wh_postcall_0001'): VoiceAgentTarget
    {
        return new VoiceAgentTarget($purpose, self::BASE, 'sec_tool_0001', 'sec_init_0001', $postCall, 3, 1, [self::REF_A => self::PHONE_A, self::REF_B => self::PHONE_B]);
    }

    /**
     * Una ruta con puntos de un array, cambiada (o borrada con $borrar).
     *
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    public static function with(array $datos, string $ruta, mixed $valor, bool $borrar = false): array
    {
        $ref = &$datos;
        $partes = explode('.', $ruta);
        $ultima = array_pop($partes);
        foreach ($partes as $p) {
            $ref = &$ref[ctype_digit($p) ? (int) $p : $p];
        }
        $clave = ctype_digit($ultima) ? (int) $ultima : $ultima;
        if ($borrar) {
            unset($ref[$clave]);
        } else {
            $ref[$clave] = $valor;
        }
        unset($ref);

        return $datos;
    }
}
