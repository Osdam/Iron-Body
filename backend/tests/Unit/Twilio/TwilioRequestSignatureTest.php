<?php

namespace Tests\Unit\Twilio;

use App\Services\Twilio\TwilioRequestSignature as S;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * La firma de la subcuenta de voz (contrato H8, D8-4; auditoría OTP-3): los 6
 * vectores OFICIALES de twilio/twilio-python (los del fixture del H6), el
 * derivado T-1 y los casos inválidos. Sin red.
 */
class TwilioRequestSignatureTest extends TestCase
{
    private const URI = 'https://mycompany.com/myapp.php?foo=1&bar=2';

    private const PARAMS = [['CallSid', 'CA1234567890ABCDE'], ['Digits', '1234'], ['From', '+14158675309'], ['To', '+18005551212'], ['Caller', '+14158675309']];

    private const BODY = '{"property": "value", "boolean": true}';

    private const BODY_HASH = '0a1ff7634d9ab3b95db5c9a2dfe9416e41502b283a80c7cf19632632f96e6620';

    /** @return iterable<string, array{0:string, 1:list<array{0:string,1:string}>, 2:string}> */
    public static function oficialesDeFormulario(): iterable
    {
        yield 'oficial 1 · clásico' => [self::URI, self::PARAMS, 'RSOYDt4T1cUTdK1PDd93/VVr8B8='];
        yield 'oficial 3 · claves repetidas' => [self::URI, [['Sid', 'CA123'], ['SidAccount', 'AC123'], ['Digits', '5678'], ['Digits', '1234'], ['Digits', '1234']], 'IK+Dwps556ElfBT0I3Rgjkr1wJU='];
        yield 'oficial 4 · :443' => ['https://mycompany.com:443/myapp.php?foo=1&bar=2', self::PARAMS, 'kvajT1Ptam85bY51eRf/AJRuM3w='];
        yield 'oficial 5 · http :80' => ['http://mycompany.com:80/myapp.php?foo=1&bar=2', self::PARAMS, '0ZXoZLH/DfblKGATFgpif+LLRf4='];
        yield 'oficial 6 · http :1234' => ['http://mycompany.com:1234/myapp.php?foo=1&bar=2', self::PARAMS, 'Zmvh+3yNM1Phv2jhDCwEM3q5ebU='];
    }

    #[DataProvider('oficialesDeFormulario')]
    public function test_los_vectores_oficiales_de_formulario(string $url, array $pares, string $firma): void
    {
        $this->assertSame($firma, S::compute($url, $pares, '12345'), 'la firma publicada sale de la URL tal cual');
        $this->assertSame('ok', S::verifyPairs($url, $pares, $firma, '12345'));
        $this->assertSame('mismatch', S::verifyPairs($url, $pares, $firma, '12346'));
    }

    public function test_el_vector_oficial_json_con_body_sha256(): void
    {
        $url = self::URI.'&bodySHA256='.self::BODY_HASH;
        $this->assertSame(self::BODY_HASH, hash('sha256', self::BODY));
        $this->assertSame('a9nBmqA0ju/hNViExpshrM61xv4=', S::compute($url, [], '12345'));
        $this->assertSame('ok', S::verifyJson($url, self::BODY, 'a9nBmqA0ju/hNViExpshrM61xv4=', '12345'));
        $this->assertSame('body_hash_mismatch', S::verifyJson($url, self::BODY.' ', 'a9nBmqA0ju/hNViExpshrM61xv4=', '12345'));
        $this->assertSame('body_hash_mismatch', S::verifyJson($url.'&bodySHA256='.self::BODY_HASH, self::BODY, 'a9nBmqA0ju/hNViExpshrM61xv4=', '12345'), 'repetido');
        $this->assertSame('body_hash_mismatch', S::verifyJson(self::URI.'&bodySHA256='.strtoupper(self::BODY_HASH), self::BODY, 'a9nBmqA0ju/hNViExpshrM61xv4=', '12345'));
        $this->assertSame('body_hash_mismatch', S::verifyJson(self::URI, self::BODY, 'a9nBmqA0ju/hNViExpshrM61xv4=', '12345'), 'sin bodySHA256');
    }

    public function test_el_derivado_t1_y_los_cambios_no_valen(): void
    {
        $this->assertSame('mismatch', S::verifyPairs('http://mycompany.com/myapp.php?foo=1&bar=2', self::PARAMS, 'RSOYDt4T1cUTdK1PDd93/VVr8B8=', '12345'), 'T-1: http tras un proxy');
        $this->assertSame('mismatch', S::verifyPairs('https://mycompany.com/myapp.php?bar=2&foo=1', self::PARAMS, 'RSOYDt4T1cUTdK1PDd93/VVr8B8=', '12345'), 'la consulta reordenada (fullUrl)');
        $otros = self::PARAMS;
        $otros[1] = ['Digits', '9999'];
        $this->assertSame('mismatch', S::verifyPairs(self::URI, $otros, 'RSOYDt4T1cUTdK1PDd93/VVr8B8=', '12345'));
        $this->assertSame('missing_signature', S::verifyPairs(self::URI, self::PARAMS, null, '12345'));
        $this->assertSame('missing_signature', S::verifyPairs(self::URI, self::PARAMS, '  ', '12345'));
        foreach (['abc', 'RSOYDt4T1cUTdK1PDd93/VVr8B8', 'RSOYDt4T1cUTdK1PDd93/VVr8B8==', 'RSOYDt4T1cUTdK1PDd93-VVr8B8='] as $mala) {
            $this->assertSame('malformed_signature', S::verifyPairs(self::URI, self::PARAMS, $mala, '12345'), $mala);
        }
        $this->expectException(InvalidArgumentException::class);
        S::verifyPairs(self::URI, self::PARAMS, 'RSOYDt4T1cUTdK1PDd93/VVr8B8=', '');
    }

    public function test_el_formulario_crudo_conserva_las_claves_repetidas(): void
    {
        $crudo = 'Sid=CA123&SidAccount=AC123&Digits=5678&Digits=1234&Digits=1234&';
        $this->assertSame([['Sid', 'CA123'], ['SidAccount', 'AC123'], ['Digits', '5678'], ['Digits', '1234'], ['Digits', '1234']], S::formPairs($crudo));
        $this->assertSame('ok', S::verifyForm(self::URI, $crudo, 'IK+Dwps556ElfBT0I3Rgjkr1wJU=', '12345'));
        $this->assertSame([['From', '+14158675309'], ['A b', 'c d'], ['Vacia', '']], S::formPairs('From=%2B14158675309&A+b=c+d&Vacia'));
    }

    public function test_paridad_con_el_fixture_del_h6(): void
    {
        $fixture = json_decode((string) file_get_contents(base_path('tests/fixtures/ultron-studio/integrations-cases.json')), true);
        $casos = $fixture['twilioSignatureOfficial'] ?? null;
        $this->assertIsArray($casos);
        $this->assertCount(7, $casos);
        foreach ($casos as $c) {
            $r = isset($c['body']) ? S::verifyJson($c['url'], $c['body'], $c['expected']['signature'], $c['authToken'])
                : S::verifyPairs($c['url'], $c['params'], $c['expected']['signature'], $c['authToken']);
            $this->assertSame($c['expected']['valid'], $r === 'ok', $c['name']);
        }
    }

    public function test_la_url_canonica_y_la_lectura_del_token(): void
    {
        $this->assertSame('https://api.ironbody.test/api/x?b=2&a=1', S::canonicalUrl('https://api.ironbody.test/', '/api/x?b=2&a=1'));
        foreach ([['https://api.ironbody.test/backend', '/x'], ['https://api.ironbody.test?q=1', '/x'], ['ftp://a.test', '/x'], ['https://u:p@a.test', '/x'],
            ['https://a.test', 'x'], ['https://a.test', '//evil.test/x'], ['https://a.test', '/x#f'], ['https://a.test', "/x\n"], ['https://a.test', '/x y']] as [$base, $uri]) {
            $this->assertNull(S::canonicalUrl($base, $uri), "{$base} + {$uri}");
        }

        config(['voice.twilio.signing_token' => null, 'voice.webhook_base_url' => 'https://mycompany.com']);
        $this->assertSame('not_configured', S::verifyRequest('/myapp.php?foo=1&bar=2', 'a=b', 'RSOYDt4T1cUTdK1PDd93/VVr8B8='));
        config(['voice.twilio.signing_token' => '12345', 'voice.webhook_base_url' => 'https://mycompany.com/con-ruta']);
        $this->assertSame('not_configured', S::verifyRequest('/myapp.php', '', 'RSOYDt4T1cUTdK1PDd93/VVr8B8='));
        config(['voice.webhook_base_url' => 'https://mycompany.com']);
        $crudo = 'CallSid=CA1234567890ABCDE&Digits=1234&From=%2B14158675309&To=%2B18005551212&Caller=%2B14158675309';
        $this->assertSame('ok', S::verifyRequest('/myapp.php?foo=1&bar=2', $crudo, 'RSOYDt4T1cUTdK1PDd93/VVr8B8='));
        $this->assertSame('ok', S::verifyRequest('/myapp.php?foo=1&bar=2&bodySHA256='.self::BODY_HASH, self::BODY, 'a9nBmqA0ju/hNViExpshrM61xv4='));
        $this->assertSame('invalid_url', S::verifyRequest('myapp.php', $crudo, 'RSOYDt4T1cUTdK1PDd93/VVr8B8='));
        $this->assertSame(['signing_token' => substr(hash_hmac('sha256', '12345', 'k'), 0, 8)], S::secretFingerprints('k'));
    }
}
