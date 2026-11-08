<?php

namespace Tests\Unit\UltronStudio;

use App\Services\Telephony\VoicePhone;
use App\Services\Telephony\VoicePublishedConfig;
use App\Services\UltronStudio\StudioVoiceProfiles;
use App\Services\UltronStudio\StudioVoicePublications as P;
use Closure;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * PARIDAD DE LA CADENA DE VOZ con `tests/fixtures/voice/voice-chain-vectors.json`
 * (contrato H8, E1-4, E2-7, E3-2; DF-V1 a DF-V6): el mensaje, su huella y su
 * firma BYTE A BYTE, el encadenado por `prev_hash` (la huella del mensaje
 * anterior, nunca su firma) y los 27 casos de verificación con su resultado.
 *
 * La firma se prueba de dos formas: con una referencia LOCAL del algoritmo del
 * fixture (para fijar el mensaje y la verificación del Studio sin depender de
 * nadie) y con la ÚNICA implementación de producción, la que el Studio recibe
 * como `Closure` (`VoicePublishedConfig::signatureFor()`, B8), con la `APP_KEY`
 * del fixture: así un cambio en cualquiera de los dos lados lo caza esta prueba.
 * Y la huella `voice-phone` de los destinos de los perfiles, contra la sección
 * `phoneHash` de `policy-tables.json` y contra la de la telefonía.
 */
class StudioVoicePublicationsParityTest extends TestCase
{
    private const FIXTURE = 'tests/fixtures/voice/voice-chain-vectors.json';

    private const POLICY_FIXTURE = 'tests/fixtures/voice/policy-tables.json';

    /** @var array<string, mixed>|null */
    private static ?array $fixture = null;

    /** @return array<string, mixed> */
    private static function fixture(): array
    {
        return self::$fixture ??= json_decode((string) file_get_contents(dirname(__DIR__, 3).'/'.self::FIXTURE), true, 512, JSON_THROW_ON_ERROR);
    }

    /** La APP_KEY sintética del fixture, cruda (bytes 00..1f). */
    private static function rawKey(): string
    {
        return base64_decode(substr((string) self::fixture()['keys']['appKeyBase64'], 7), true);
    }

    /** La firma de referencia del fixture (E1-4 + E2-7), escrita aquí aparte de la de producción. */
    private static function referenceSigner(?string $label = null): Closure
    {
        $subclave = hash_hmac('sha256', $label ?? P::CHAIN_LABEL, self::rawKey(), true);

        return static fn (string $m): string => hash_hmac('sha256', $m, $subclave);
    }

    public static function chainCases(): iterable
    {
        foreach (self::fixture()['chain'] as $i => $caso) {
            yield $caso['name'] => [$i, $caso];
        }
    }

    public static function verifyCases(): iterable
    {
        foreach (self::fixture()['verify'] as $caso) {
            yield $caso['name'] => [$caso];
        }
    }

    public function test_la_forma_del_fixture_es_la_del_studio(): void
    {
        $f = self::fixture();
        $this->assertSame(P::MESSAGE_KEYS, $f['closedSets']['keys']);
        $this->assertSame(P::MESSAGE_SCOPES, $f['closedSets']['scopes']);
        $this->assertSame(P::VERIFY_RESULTS, $f['closedSets']['verifyResults']);
        $this->assertSame(P::CHAIN_LABEL, $f['keys']['chainLabel']);
        $this->assertSame(['chain' => 5, 'subkey' => 3, 'verify' => 27, 'total' => 35], $f['counts']);
        $this->assertSame(VoicePublishedConfig::MESSAGE_KEYS, P::MESSAGE_KEYS, 'el Studio y el lector firman las mismas claves');
        $this->assertSame(VoicePublishedConfig::GENESIS_PREV_HASH, P::GENESIS_HASH);
    }

    #[DataProvider('chainCases')]
    public function test_cada_entrada_de_la_cadena_da_su_mensaje_su_huella_y_su_firma(int $i, array $caso): void
    {
        $mensaje = P::message($caso['entry']);
        $this->assertSame($caso['expected']['message'], $mensaje, 'los bytes del mensaje');
        $this->assertSame($caso['expected']['entryHash'], P::entryHash($mensaje));
        $this->assertSame($caso['expected']['signature'], (self::referenceSigner())($mensaje));

        // prev_hash = la huella del mensaje anterior (DF-V1), 64 ceros en la génesis.
        $anterior = $i === 0 ? null : self::fixture()['chain'][$i - 1];
        $this->assertSame($anterior === null ? P::GENESIS_HASH : $anterior['expected']['entryHash'], $caso['entry']['prev_hash']);
        $this->assertNotSame($anterior['expected']['signature'] ?? null, $caso['entry']['prev_hash'], 'prev_hash nunca es la firma anterior');

        // Y verifica con la anterior de verdad.
        $previo = $anterior === null ? null : ['seq' => $anterior['entry']['seq'], 'entryHash' => $anterior['expected']['entryHash']];
        $this->assertSame(P::VERIFY_OK, P::verifyMessage($mensaje, $caso['expected']['signature'], $previo, self::referenceSigner()));
    }

    public function test_la_firma_de_produccion_que_recibe_el_studio_es_la_del_fixture(): void
    {
        config(['app.key' => self::fixture()['keys']['appKeyBase64']]);
        $firma = P::signer();
        foreach (self::fixture()['chain'] as $caso) {
            $this->assertSame($caso['expected']['signature'], $firma($caso['expected']['message']), $caso['name']);
        }

        // Una APP_KEY sin el prefijo base64: se usa tal cual, como Laravel.
        foreach (self::fixture()['subkey'] as $caso) {
            $crudo = str_starts_with($caso['appKey'], 'base64:') ? base64_decode(substr($caso['appKey'], 7), true) : $caso['appKey'];
            $this->assertSame($caso['expected']['subkeyHex'], bin2hex(hash_hmac('sha256', $caso['label'], $crudo, true)), $caso['name']);
            if ($caso['label'] === P::CHAIN_LABEL) {
                $this->assertSame($caso['expected']['subkeyHex'], bin2hex(VoicePublishedConfig::subkey($crudo)), $caso['name']);
            }
        }
    }

    #[DataProvider('verifyCases')]
    public function test_cada_caso_de_verificacion_da_su_resultado_en_el_orden_del_fixture(array $caso): void
    {
        $this->assertSame($caso['expected']['result'], P::verifyMessage($caso['message'], $caso['signature'], $caso['previous'], self::referenceSigner()));
        $this->assertSame($caso['expected']['denies'], $caso['expected']['result'] !== P::VERIFY_OK);
    }

    public function test_una_firma_del_h5_o_de_otra_clave_no_vale_y_firmar_que_falla_no_verifica(): void
    {
        $caso = self::fixture()['chain'][0];
        $h5 = self::referenceSigner('ultron-studio-publication');
        $this->assertSame(P::VERIFY_SIGNATURE_MISMATCH, P::verifyMessage($caso['expected']['message'], $h5($caso['expected']['message']), null, self::referenceSigner()));
        // Un firmante que lanza (sin APP_KEY) no hace lanzar al verificador: deniega.
        $this->assertSame(P::VERIFY_SIGNATURE_MISMATCH, P::verifyMessage($caso['expected']['message'], $caso['expected']['signature'], null, static fn (): never => throw new \RuntimeException('sin clave')));
    }

    public function test_el_studio_no_firma_lo_que_no_tiene_la_forma_del_mensaje(): void
    {
        $base = self::fixture()['chain'][0]['entry'];
        foreach ([
            'una clave de más' => $base + ['contract' => 'x'],
            'off con agente' => ['scope' => 'off'] + $base,
            'canary sin spec' => ['spec_hash' => null] + $base,
            'fecha con desfase' => ['published_at' => '2026-11-04T10:00:00-05:00'] + $base,
            'seq 0' => ['seq' => 0] + $base,
            'actor como texto' => ['actor_id' => '7'] + $base,
        ] as $nombre => $entrada) {
            try {
                P::message($entrada);
                $this->fail("{$nombre}: se firmó");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame('2026-11-04T15:00:00Z', P::publishedAt(new \DateTimeImmutable('2026-11-04 10:00:00', new \DateTimeZone('America/Bogota'))));
    }

    public function test_la_huella_voice_phone_de_los_destinos_es_la_de_la_telefonia(): void
    {
        $f = json_decode((string) file_get_contents(dirname(__DIR__, 3).'/'.self::POLICY_FIXTURE), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(StudioVoiceProfiles::VOICE_PHONE_LABEL, $f['keys']['voicePhoneLabel']);
        config(['app.key' => $f['keys']['appKeyBase64']]);
        $vistos = 0;
        foreach ($f['phoneHash'] as $caso) {
            if (isset($caso['expected']['throws'])) {
                try {
                    StudioVoiceProfiles::phoneHash($caso['e164']);
                    $this->fail("{$caso['name']}: no lanzó");
                } catch (InvalidArgumentException) {
                    $this->addToAssertionCount(1);
                }

                continue;
            }
            $this->assertSame($caso['expected']['remoteHash'], StudioVoiceProfiles::phoneHash($caso['e164']), $caso['name']);
            $this->assertSame(VoicePhone::hash($caso['e164']), StudioVoiceProfiles::phoneHash($caso['e164']), 'la misma huella que la telefonía');
            $vistos++;
        }
        $this->assertGreaterThanOrEqual(3, $vistos);
    }
}
