<?php

namespace Tests\Unit\Outreach\Core;

use App\Services\Outreach\Core\CanonicalJson;
use App\Services\Outreach\Core\OutreachCatalog;
use App\Services\Outreach\Core\StrategyChainSignature;
use App\Services\Outreach\Core\StrategyContent;
use App\Services\UltronStudio\StudioContentHasher;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PARIDAD del lector estricto del contenido de una estrategia
 * (`strategy-content-cases.json`, D9-6) y de la firma de la cadena de
 * publicaciones (`strategy-chain-vectors.json`, D9-11.3; T1), caso a caso y
 * byte a byte.
 */
class StrategyContentAndChainParityTest extends TestCase
{
    use LoadsOutreachFixtures;

    /** @return iterable<string, array{array<string,mixed>}> */
    public static function content(): iterable
    {
        return self::cases('strategy-content-cases', ['valid', 'invalid']);
    }

    #[DataProvider('content')]
    public function test_contenido(array $caso): void
    {
        $r = StrategyContent::fromArray(self::input($caso), self::fixture('strategy-content-cases')['dynamicDomains']);
        $e = $caso['expected'];

        $this->assertSame($e['errors'], $r['errors']);
        if ($e['errors'] === []) {
            $this->assertCanonicalSame($e['canonical'], $r['canonical']);
            $this->assertSame($e['canonicalJson'], $r['json']);
            $this->assertSame($e['contentHash'], $r['contentHash']);
            $this->assertSame($e['canonicalBytes'], strlen($r['json']));
            $this->assertSame($e['contentHash'], StudioContentHasher::hash($r['canonical']));
            // Releer los bytes guardados da lo mismo; tocar un byte, no.
            $releida = StrategyContent::fromStored($r['json'], $r['contentHash'], self::fixture('strategy-content-cases')['dynamicDomains']);
            $this->assertNull($releida['reason']);
            $this->assertCanonicalSame($r['canonical'], $releida['canonical']);
            $this->assertSame('content_hash_mismatch', StrategyContent::fromStored($r['json'].' ', $r['contentHash'])['reason']);
        } else {
            $this->assertNull($r['canonical']);
        }
    }

    /** @return iterable<string, array{array<string,mixed>}> */
    public static function versions(): iterable
    {
        return self::cases('strategy-content-cases', ['versions']);
    }

    #[DataProvider('versions')]
    public function test_huellas_de_versiones(array $caso): void
    {
        $dominios = self::fixture('strategy-content-cases')['dynamicDomains'];
        $a = StrategyContent::fromArray($caso['a'], $dominios);
        $b = StrategyContent::fromArray($caso['b'], $dominios);

        $this->assertSame($caso['expected']['hashA'], $a['contentHash']);
        $this->assertSame($caso['expected']['hashB'], $b['contentHash']);
        $this->assertSame($caso['expected']['sameContentHash'], $a['contentHash'] === $b['contentHash']);
    }

    /**
     * Q1-2 (revisión de security de la ola 5): `complaint_created` tiene emisor y está DISPONIBLE, pero una queja nunca
     * dispara un contacto. Lo rechaza el lector del SERVIDOR, no solo el constructor del CRM, sea cual sea la
     * finalidad. Los casos del fixture cubren un evento sin emisor y uno que solo detiene (`human_requested`); este,
     * la clase nueva: disponible y no disparador.
     */
    public function test_una_queja_nunca_es_disparador_aunque_el_evento_tenga_emisor(): void
    {
        $f = self::fixture('strategy-content-cases');
        $evento = OutreachCatalog::event('complaint_created');
        $this->assertSame([true, false], [$evento['available'] ?? null, $evento['triggerable'] ?? null]);

        $vistas = [];
        foreach ($f['valid'] as $caso) {
            $entrada = self::input($caso);
            if (! is_array($entrada) || isset($vistas[$entrada['purpose']])) {
                continue;
            }
            $vistas[$entrada['purpose']] = true;
            // El caso, tal cual, es válido; con el disparador de la queja y nada más cambiado, deja de serlo.
            $this->assertSame([], StrategyContent::fromArray($entrada, $f['dynamicDomains'])['errors'], $caso['name']);
            $entrada['trigger'] = ['event' => 'complaint_created'];
            $r = StrategyContent::fromArray($entrada, $f['dynamicDomains']);
            $this->assertContains(['key' => 'content.trigger.event', 'code' => 'trigger_not_allowed'], $r['errors'], $caso['name']);
            $this->assertNull($r['canonical'], $caso['name']);
        }
        $this->assertEqualsCanonicalizing(['collections', 'commercial'], array_keys($vistas), 'las finalidades con casos válidos en el fixture');
    }

    public function test_el_fixture_del_contenido_es_el_del_codigo(): void
    {
        $f = self::fixture('strategy-content-cases');
        $this->assertSame(StrategyContent::FORMAT, $f['contentFormat']);
        $this->assertSame(StrategyContent::REQUIRED, $f['requiredKeys']);
        $this->assertSame(StrategyContent::OPTIONAL, $f['optionalKeys']);
        $this->assertSame(StrategyContent::STEP_REQUIRED, $f['stepRequiredKeys']);
        $this->assertSame(StrategyContent::STEP_OPTIONAL, $f['stepOptionalKeys']);
        $this->assertSame(StrategyContent::MAX_BYTES, $f['limits']['maxContentBytes']);
        $this->assertSame(StrategyContent::MAX_STEPS, $f['limits']['maxSteps']);
        $this->assertSame(StrategyContent::MAX_CHANNELS, $f['limits']['maxChannelsPerStep']);
        $this->assertSame(StrategyContent::MAX_WAIT_DAYS, $f['limits']['maxWaitDays']);
        $this->assertSame(StrategyContent::MAX_DELAY_DAYS, $f['limits']['maxDelayDays']);
        $this->assertSame(StrategyContent::MAX_SCHEDULE_WINDOWS, $f['limits']['maxScheduleWindows']);
        $this->assertSame(array_map(static fn (array $r): array => $r, StrategyContent::LIMITS), $f['limits']['limits']);
        $this->assertSame(StrategyContent::LIMITS_OPTIONAL, $f['limits']['limitsOptional']);
        $this->assertSame([OutreachCatalog::VERSION, OutreachCatalog::hash()], self::ratifiedPin($f['catalogVersion'], $f['catalogHash']), 'L9-29');
    }

    public function test_bytes_guardados_que_no_valen(): void
    {
        $this->assertSame('malformed', StrategyContent::fromStored('{no', hash('sha256', '{no'))['reason']);
        $viejo = CanonicalJson::encode(['format' => StrategyContent::FORMAT, 'goal' => 'renew']);
        $this->assertSame(StrategyContent::CATALOG_INCOMPATIBLE, StrategyContent::fromStored($viejo, hash('sha256', $viejo))['reason']);
        $valido = self::fixture('strategy-content-cases')['valid'][0]['expected']['canonicalJson'];
        $sangrado = json_encode(json_decode($valido, true), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertSame('not_canonical', StrategyContent::fromStored($sangrado, hash('sha256', $sangrado), self::fixture('strategy-content-cases')['dynamicDomains'])['reason']);
    }

    /** @return iterable<string, array{array<string,mixed>}> */
    public static function chain(): iterable
    {
        return self::cases('strategy-chain-vectors', ['chain', 'subkey', 'verify']);
    }

    #[DataProvider('chain')]
    public function test_cadena(array $caso): void
    {
        $f = self::fixture('strategy-chain-vectors');
        $clave = StrategyChainSignature::appKeyRaw($f['keys']['appKeyBase64']);
        if ($caso['_section'] === 'chain') {
            $mensaje = StrategyChainSignature::message($caso['entry']);
            $this->assertSame($caso['expected']['message'], $mensaje);
            $this->assertSame($caso['expected']['entryHash'], StrategyChainSignature::entryHash($mensaje));
            $this->assertSame($caso['expected']['signature'], StrategyChainSignature::sign($mensaje, $clave));
            $this->assertSame($caso['expected']['signature'], (StrategyChainSignature::signer($clave))($mensaje), 'el Closure del Studio firma lo mismo');

            return;
        }
        if ($caso['_section'] === 'subkey') {
            $cruda = StrategyChainSignature::appKeyRaw($caso['appKey']);
            $this->assertSame($caso['expected']['subkeyHex'], bin2hex(hash_hmac('sha256', $caso['label'], $cruda, true)));
            if ($caso['label'] === StrategyChainSignature::SUBKEY_LABEL) {
                $this->assertSame($caso['expected']['subkeyHex'], bin2hex(StrategyChainSignature::subkey($cruda)));
            } else {
                $this->assertNotSame($caso['expected']['subkeyHex'], bin2hex(StrategyChainSignature::subkey($cruda)));
            }

            return;
        }
        $this->assertSame($caso['expected'], StrategyChainSignature::verify($caso['message'], $caso['signature'], $caso['previous'], $clave));
    }

    public function test_la_cadena_entera_y_los_conjuntos(): void
    {
        $f = self::fixture('strategy-chain-vectors');
        $clave = StrategyChainSignature::appKeyRaw($f['keys']['appKeyBase64']);
        $entradas = array_map(static fn (array $c): array => ['message' => $c['expected']['message'], 'signature' => $c['expected']['signature']], $f['chain']);
        $this->assertSame(['result' => 'ok', 'seq' => null, 'verified' => 7], StrategyChainSignature::verifyChain($entradas, $clave));
        // T1: un carácter del contentHash cambiado (UPDATE crudo) rompe en esa entrada, con la firma original.
        $entradas[3]['message'] = str_replace('"contentHash":"9', '"contentHash":"8', $entradas[3]['message']);
        $this->assertSame(['result' => 'signature_mismatch', 'seq' => 4, 'verified' => 3], StrategyChainSignature::verifyChain($entradas, $clave));
        $this->assertSame(['result' => 'malformed', 'seq' => 1, 'verified' => 0], StrategyChainSignature::verifyChain([['nada']], $clave));

        $this->assertSame($f['closedSets']['keys'], StrategyChainSignature::MESSAGE_KEYS);
        $ordenadas = StrategyChainSignature::ACTIONS;
        sort($ordenadas);
        $this->assertSame($f['closedSets']['actions'], $ordenadas);
        $estados = StrategyChainSignature::STATUSES;
        sort($estados);
        $this->assertSame($f['closedSets']['statuses'], $estados);
        $this->assertSame($f['closedSets']['verifyResults'], StrategyChainSignature::RESULTS);
        $this->assertSame($f['keys']['chainLabel'], StrategyChainSignature::SUBKEY_LABEL);
        $this->assertSame(StrategyChainSignature::appKeyRaw($f['keys']['appKeyRaw']), $f['keys']['appKeyRaw'], 'sin base64: se usa tal cual, como Laravel');
    }

    public function test_un_mensaje_sin_forma_no_se_firma_y_una_app_key_mala_tampoco(): void
    {
        foreach ([['seq' => 1], ['action' => 'archive'] + self::fixture('strategy-chain-vectors')['chain'][0]['entry']] as $i => $entrada) {
            try {
                StrategyChainSignature::message($entrada);
                $this->fail("La entrada {$i} no lanzó.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        foreach (['', 'base64:', 'base64:%%%'] as $clave) {
            try {
                StrategyChainSignature::appKeyRaw($clave);
                $this->fail("La clave «{$clave}» no lanzó.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertNull(StrategyChainSignature::decode(str_repeat('x', StrategyChainSignature::MAX_MESSAGE + 1)));
        $this->assertNull(StrategyChainSignature::decode('[1,2]'));
    }

    /** DF-E7: una franja que solo toca el borde legal (termina a las 08:00 o empieza a las 19:00) no toca la ventana. */
    public function test_la_franja_que_solo_toca_el_borde_legal(): void
    {
        $f = self::fixture('strategy-content-cases');
        $base = self::input($f['valid'][0]);
        $errores = static fn (array $franja): array => StrategyContent::fromArray(['schedule' => ['days' => [1, 2, 3, 4, 5], 'windows' => [$franja]]] + $base, $f['dynamicDomains'])['errors'];
        $fuera = [['key' => 'content.schedule.windows.0', 'code' => 'schedule_outside_legal_window']];
        $this->assertSame($fuera, $errores(['from' => 420, 'to' => 480]));
        $this->assertSame($fuera, $errores(['from' => 1140, 'to' => 1200]));
        $this->assertSame([], $errores(['from' => 420, 'to' => 481]));
        $this->assertSame([], $errores(['from' => 1139, 'to' => 1200]));
    }

    /** DF-V3: `approvedByPerson` es una identidad en publish y promote, y null en pause, resume, rollback y retire. */
    public function test_la_aprobacion_solo_en_publish_y_promote(): void
    {
        $f = self::fixture('strategy-chain-vectors');
        $this->assertNotNull(StrategyChainSignature::decode($f['chain'][4]['expected']['message']), 'la pausa del fixture es válida');
        foreach ([[$f['chain'][4]['entry'], $f['chain'][0]['entry']['approvedByPerson']], [$f['chain'][0]['entry'], null]] as [$entrada, $aprobada]) {
            $entrada['approvedByPerson'] = $aprobada;
            $this->assertNull(StrategyChainSignature::decode(CanonicalJson::encode($entrada)), $entrada['action']);
            try {
                StrategyChainSignature::message($entrada);
                $this->fail("{$entrada['action']} con approvedByPerson ".json_encode($aprobada).' no lanzó.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** L9-20: `commercialBlockOverdueDays` va de 0 a 365 y solo en `commercial`. */
    public function test_el_umbral_de_cartera_de_la_estrategia(): void
    {
        $f = self::fixture('strategy-content-cases');
        $base = self::input($f['valid'][0]);
        $this->assertSame('commercial', $base['purpose']);
        $errores = static fn (array $contenido): array => StrategyContent::fromArray($contenido, $f['dynamicDomains'])['errors'];
        $con = static fn (int $dias): array => ['limits' => ['commercialBlockOverdueDays' => $dias] + $base['limits']] + $base;
        $this->assertSame([], $errores($con(0)));
        $this->assertSame([], $errores($con(365)));
        $this->assertSame([['key' => 'content.limits.commercialBlockOverdueDays', 'code' => 'value_out_of_range']], $errores($con(366)));
        $this->assertSame(0, StrategyContent::fromArray($con(0), $f['dynamicDomains'])['canonical']['limits']['commercialBlockOverdueDays']);
    }
}
