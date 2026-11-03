<?php

namespace Tests\Unit\Voice;

use App\Services\UltronStudio\StudioContentHasher;
use App\Services\Voice\Dto\VoiceAgentSpec;
use App\Services\Voice\Dto\VoiceCanonicalJson;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Voice\Support\VoiceSpecSamples as M;

/**
 * La especificación neutra (contrato H8, D8-6): el lector estricto de lo que
 * compila S8. Pura: sin aplicación, sin red.
 *
 * LO QUE SE PROTEGE.
 *  - La huella es la del compilador: el mismo JSON canónico que
 *    `StudioContentHasher` (S8), byte a byte, y no depende del orden de claves.
 *  - Las invariantes que protegen al proveedor aunque el compilador fallara:
 *    `caller_authorized = no` por defecto, sobrescrituras cerradas, grabación y
 *    audio apagados, `end_call`, transferencia coherente y en conferencia, el
 *    prompt sin variables ajenas ni `secret__`, los textos sin invisibles.
 *  - Forma estricta: ni una clave de más ni de menos, a ningún nivel.
 *  - Ningún mensaje de error repite el valor que lo provocó.
 */
class VoiceAgentSpecTest extends TestCase
{
    private const CENTINELA = 'CENTINELA-VALOR-QUE-NO-SALE';

    public function test_lee_la_especificacion_y_su_huella_es_la_del_compilador(): void
    {
        $datos = M::spec();
        $spec = VoiceAgentSpec::fromArray($datos);

        $this->assertSame(StudioContentHasher::hash($datos), $spec->specHash, 'la huella de S8 (StudioContentHasher) y la de B8 coinciden');
        $this->assertSame(StudioContentHasher::canonicalJson($datos), VoiceCanonicalJson::encode($datos), 'los mismos bytes');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $spec->specHash);
        $this->assertSame($datos, $spec->toArray());

        $this->assertSame('ultron-voice-compiler/1', $spec->compilerVersion());
        $this->assertSame('ultron-voice-template/1', $spec->templateVersion());
        $this->assertSame(str_repeat('c3', 32), $spec->templateHash());
        $this->assertSame('0f6c2a52-6c1e-4c3a-9a51-7d3b0e1c2a10', $spec->studioVersionUuid());
        $this->assertSame(str_repeat('d4', 32), $spec->studioContentHash());
        $this->assertSame('2026-10-19.1', $spec->catalogVersion());
        $this->assertSame('5b8e0c3d-2f4a-4b6e-8c1d-9e7f6a5b4c3d', $spec->voiceProfileUuid());
        $this->assertSame(str_repeat('e5', 32), $spec->voiceProfileHash());
        $this->assertSame('es', $spec->language());
        $this->assertSame('pNInz6obpgDQGcFmaJgB', $spec->voiceId());
        $this->assertSame(300, $spec->maxDurationSeconds());
        $this->assertSame(['consultar_horarios', 'consultar_planes', 'no_volver_a_llamar', 'solicitar_humano'], $spec->toolNames());
        $this->assertSame(['end_call', 'transfer_to_number'], $spec->systemToolNames());
        $this->assertSame('conference', $spec->transferMode());
        $this->assertCount(2, $spec->transferDestinations());
        $this->assertSame('catalog_tool_only', $spec->pricePolicy());
        $this->assertSame(['garantizado'], $spec->forbiddenPhrases());
        $this->assertSame(30, $spec->retentionDays());
        $this->assertSame('hang_up', $spec->voicemailPolicy());
        $this->assertSame(['call_direction' => 'entrante', 'call_ref' => 'sin_referencia', 'caller_authorized' => 'no', 'version_label' => 'sin_etiqueta'], $spec->dynamicVariableDefaults());
    }

    public function test_reordenar_las_claves_no_cambia_la_huella_y_cambiar_un_valor_si(): void
    {
        $datos = M::spec();
        $alReves = self::reverseKeys($datos);
        $this->assertSame(VoiceAgentSpec::fromArray($datos)->specHash, VoiceAgentSpec::fromArray($alReves)->specHash);

        $otra = M::with($datos, 'maxDurationSeconds', 299);
        $this->assertNotSame(VoiceAgentSpec::fromArray($datos)->specHash, VoiceAgentSpec::fromArray($otra)->specHash);
    }

    public function test_from_compiled_exige_que_la_huella_cuadre(): void
    {
        $datos = M::spec();
        $bien = VoiceAgentSpec::fromCompiled(['spec' => $datos, 'specHash' => StudioContentHasher::hash($datos)]);
        $this->assertSame(StudioContentHasher::hash($datos), $bien->specHash);

        $this->expectException(InvalidArgumentException::class);
        VoiceAgentSpec::fromCompiled(['spec' => $datos, 'specHash' => str_repeat('0', 64)]);
    }

    public function test_sin_transferencia_tambien_es_una_especificacion(): void
    {
        $spec = VoiceAgentSpec::fromArray(M::specWithoutTransfer());
        $this->assertSame(['end_call'], $spec->systemToolNames());
        $this->assertSame([], $spec->transferDestinations());
    }

    /** @return iterable<string, array{0: array<string, mixed>}> */
    public static function rechazos(): iterable
    {
        $s = M::spec();
        $invisible = "Termina la llamada.\u{200B}";

        yield 'una clave de más' => [$s + ['extra' => true]];
        yield 'una clave de menos' => [M::with($s, 'prices', null, true)];
        yield 'otro contrato' => [M::with($s, 'contract', 'ultron-voice-spec/2')];
        yield 'versión del compilador sin forma' => [M::with($s, 'compilerVersion', 'Compiler 1')];
        yield 'una clave de más en source' => [M::with($s, 'source.extra', 'x')];
        yield 'uuid en mayúsculas' => [M::with($s, 'source.studioVersionUuid', '0F6C2A52-6C1E-4C3A-9A51-7D3B0E1C2A10')];
        yield 'huella del perfil corta' => [M::with($s, 'source.voiceProfileHash', 'abc')];
        yield 'catálogo sin forma' => [M::with($s, 'source.catalogVersion', '2026-10-19')];
        yield 'otro idioma' => [M::with($s, 'language', 'en')];
        yield 'voz sin forma' => [M::with($s, 'voiceId', 'voz-1')];
        yield 'el aviso con una variable' => [M::with($s, 'firstMessage', 'Hola {{caller_authorized}}')];
        yield 'el aviso con un salto de línea' => [M::with($s, 'firstMessage', "Hola.\nAdiós.")];
        yield 'el aviso con espacios alrededor' => [M::with($s, 'firstMessage', ' Hola.')];
        yield 'caller_authorized por defecto si' => [M::with($s, 'dynamicVariables.caller_authorized', 'si')];
        yield 'call_ref por defecto un uuid' => [M::with($s, 'dynamicVariables.call_ref', '0f6c2a52-6c1e-4c3a-9a51-7d3b0e1c2a10')];
        yield 'una variable de más' => [M::with($s, 'dynamicVariables.greeting', 'Hola')];
        yield 'una variable de menos' => [M::with($s, 'dynamicVariables.version_label', null, true)];
        yield 'dirección por defecto desconocida' => [M::with($s, 'dynamicVariables.call_direction', 'inbound')];
        yield 'el prompt con una variable ajena' => [M::with($s, 'systemPrompt', 'Hola {{nombre_cliente}}')];
        yield 'el prompt con un secret__' => [M::with($s, 'systemPrompt', 'Hola {{secret__token}}')];
        yield 'el prompt con llaves sueltas' => [M::with($s, 'systemPrompt', 'Hola {{ caller authorized }}')];
        yield 'el prompt con un retorno de carro' => [M::with($s, 'systemPrompt', "Hola\r\nadiós")];
        yield 'el prompt con un invisible' => [M::with($s, 'systemPrompt', "Hola\u{202E}adiós")];
        yield 'sobrescrituras abiertas' => [M::with($s, 'overridesAllowed', true)];
        yield 'duración de 59 s' => [M::with($s, 'maxDurationSeconds', 59)];
        yield 'duración de 601 s' => [M::with($s, 'maxDurationSeconds', 601)];
        yield 'duración como texto' => [M::with($s, 'maxDurationSeconds', '300')];
        yield 'una herramienta fuera del catálogo' => [M::with($s, 'tools.0.name', 'cobrar')];
        yield 'una herramienta repetida' => [M::with($s, 'tools.1.name', 'consultar_horarios')];
        yield 'una herramienta con una clave de más' => [M::with($s, 'tools.0.url', 'https://otro.test')];
        yield 'una descripción con un invisible' => [M::with($s, 'systemTools.0.description', $invisible)];
        yield 'una herramienta de sistema ajena' => [M::with($s, 'systemTools.0.name', 'transfer_to_agent')];
        yield 'sin end_call' => [M::with($s, 'systemTools', [['name' => 'transfer_to_number', 'description' => 'Transfiere.']])];
        yield 'transfer_to_number sin destinos' => [M::with($s, 'transfer.destinations', [])];
        yield 'destinos sin transfer_to_number' => [M::with($s, 'systemTools', [['name' => 'end_call', 'description' => 'Termina la llamada.']])];
        yield 'transferencia ciega' => [M::with($s, 'transfer.mode', 'blind')];
        yield 'cuatro destinos' => [M::with($s, 'transfer.destinations', [...$s['transfer']['destinations'], ...$s['transfer']['destinations']])];
        yield 'alias repetido sin mirar mayúsculas' => [M::with($s, 'transfer.destinations.1.alias', 'GERENCIA')];
        yield 'destino repetido' => [M::with($s, 'transfer.destinations.1.ref', M::REF_B)];
        yield 'un destino con su teléfono' => [M::with($s, 'transfer.destinations.0.ref', M::PHONE_A)];
        yield 'un destino con una clave de más' => [M::with($s, 'transfer.destinations.0.phone', M::PHONE_A)];
        yield 'un alias con arroba' => [M::with($s, 'transfer.destinations.0.alias', 'ventas@gym')];
        yield 'otra política de precios' => [M::with($s, 'prices', 'free')];
        yield 'frases que no son lista' => [M::with($s, 'forbiddenPhrases', ['a' => 'b'])];
        yield 'una frase vacía' => [M::with($s, 'forbiddenPhrases', [''])];
        yield 'retención de 0 días' => [M::with($s, 'privacy.retentionDays', 0)];
        yield 'retención ilimitada' => [M::with($s, 'privacy.retentionDays', -1)];
        yield 'retención de 366 días' => [M::with($s, 'privacy.retentionDays', 366)];
        yield 'grabación encendida' => [M::with($s, 'privacy.recording', true)];
        yield 'audio guardado' => [M::with($s, 'privacy.storeAudio', true)];
        yield 'grabación como 0' => [M::with($s, 'privacy.recording', 0)];
        yield 'otro buzón' => [M::with($s, 'voicemail', 'transcribe')];
        yield 'dejar un mensaje en el buzón (E1-8: no existe)' => [M::with($s, 'voicemail', 'leave_message')];
    }

    /** @param  array<string, mixed>  $datos */
    #[DataProvider('rechazos')]
    public function test_rechaza(array $datos): void
    {
        $this->expectException(InvalidArgumentException::class);
        VoiceAgentSpec::fromArray($datos);
    }

    public function test_ningun_mensaje_repite_el_valor_que_falla(): void
    {
        $casos = [
            M::with(M::spec(), 'voiceId', self::CENTINELA),
            M::with(M::spec(), 'compilerVersion', self::CENTINELA),
            M::with(M::spec(), 'firstMessage', self::CENTINELA."\n"),
            M::with(M::spec(), 'systemPrompt', '{{'.self::CENTINELA.'}}'),
            M::with(M::spec(), 'transfer.destinations.0.ref', self::CENTINELA),
            M::with(M::spec(), 'dynamicVariables.caller_authorized', self::CENTINELA),
            M::with(M::spec(), 'voicemail', self::CENTINELA),
        ];
        foreach ($casos as $i => $datos) {
            try {
                VoiceAgentSpec::fromArray($datos);
                $this->fail("el caso {$i} tenía que fallar");
            } catch (InvalidArgumentException $e) {
                $this->assertStringNotContainsString(self::CENTINELA, $e->getMessage(), "caso {$i}");
            }
        }
    }

    public function test_el_json_canonico_rechaza_decimales_y_objetos(): void
    {
        $this->assertSame('{"a":1,"b":[2,1],"ñ":"/"}', VoiceCanonicalJson::encode(['ñ' => '/', 'b' => [2, 1], 'a' => 1]));
        $this->assertSame('[]', VoiceCanonicalJson::encode([]));

        foreach ([['x' => 0.5], ['x' => new \stdClass]] as $malo) {
            try {
                VoiceCanonicalJson::encode($malo);
                $this->fail('tenía que rechazarse');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $hondo = 'x';
        for ($i = 0; $i < VoiceCanonicalJson::MAX_DEPTH + 2; $i++) {
            $hondo = ['n' => $hondo];
        }
        $this->expectException(InvalidArgumentException::class);
        VoiceCanonicalJson::encode($hondo);
    }

    /** @param  array<mixed>  $valor */
    private static function reverseKeys(array $valor): array
    {
        if (array_is_list($valor)) {
            return array_map(static fn (mixed $v): mixed => is_array($v) ? self::reverseKeys($v) : $v, $valor);
        }
        $out = [];
        foreach (array_reverse($valor, true) as $k => $v) {
            $out[$k] = is_array($v) ? self::reverseKeys($v) : $v;
        }

        return $out;
    }
}
