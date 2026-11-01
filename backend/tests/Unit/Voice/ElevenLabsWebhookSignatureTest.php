<?php

namespace Tests\Unit\Voice;

use App\Services\Voice\ElevenLabs\ElevenLabsWebhookSignature as Firma;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * La firma del webhook post-llamada (contrato H6 §I6.4), con casos escritos a
 * mano desde el contrato.
 *
 * Las firmas esperadas NO salen de PHP: se derivaron por dos vías
 * independientes, `openssl dgst -sha256 -hmac` (OpenSSL 3.6.4) y `hmac` de
 * Python, que coinciden byte a byte; aquí van como literales.
 *
 * LO QUE SE PROTEGE. El orden del algoritmo (falta → mal formada → caducada o
 * futura → no coincide), los bordes de la ventana, que se firma el cuerpo
 * CRUDO con el `t` tal como viene, que vale cualquier `v0` que coincida, que
 * las mayúsculas del hex valen, y que un secreto vacío es un fallo de
 * configuración (excepción), no una firma mala.
 */
class ElevenLabsWebhookSignatureTest extends TestCase
{
    private const SECRETO = 'whsec_prueba_voz_0123456789';

    private const CUERPO = '{"type":"post_call_transcription","event_timestamp":1761955200,"data":{"agent_id":"agt_prueba","conversation_id":"conv_1"}}';

    private const T = 1761955200;

    /** HMAC-SHA256("1761955200." . CUERPO, SECRETO). */
    private const H1 = '4486b4395e109633214bd83dd64aefe1285d49975c209e488be53aa883ad78b6';

    /** HMAC-SHA256("1761955200." . CUERPO . "\n", SECRETO): el cuerpo con un salto de línea final. */
    private const H2 = '46a3d1d24b21f505a9fe96bf7eeb28a1057dac1496e47c5422e75213dc86afd0';

    /** HMAC-SHA256("001761955200." . CUERPO, SECRETO): el `t` con ceros a la izquierda, tal cual. */
    private const H4 = '2d6dfd4c3b009eebd20b7739e8836f5298b880882603fb7357c3e809a8851848';

    /** HMAC-SHA256("1761955200." . CUERPO, "otro_secreto"). */
    private const HX = '7b08e07697b83fc70e869f2918f6ee5387156ea6430811059124a44245fc95ed';

    private const AHORA = self::T + 60;

    /** @return array<string, array{0:string, 1:?string, 2:string, 3:int, 4:string}> nombre → [cuerpo, cabecera, secreto, ahora, esperado] */
    public static function casos(): array
    {
        $t = (string) self::T;
        $c = self::CUERPO;
        $s = self::SECRETO;
        $a = self::AHORA;

        return [
            'válida' => [$c, "t={$t},v0=".self::H1, $s, $a, 'ok'],
            'válida con espacios alrededor de las partes' => [$c, " t={$t} ,  v0=".self::H1.' ', $s, $a, 'ok'],
            'hex en mayúsculas' => [$c, "t={$t},v0=".strtoupper(self::H1), $s, $a, 'ok'],
            'hex mezclado' => [$c, "t={$t},v0=".substr(self::H1, 0, 32).strtoupper(substr(self::H1, 32)), $s, $a, 'ok'],
            'varios v0 y coincide el segundo' => [$c, "t={$t},v0=".self::HX.',v0='.self::H1, $s, $a, 'ok'],
            'varios v0 y no coincide ninguno' => [$c, "t={$t},v0=".self::HX.',v0='.self::H2, $s, $a, 'mismatch'],
            'v0 antes que t' => [$c, 'v0='.self::H1.",t={$t}", $s, $a, 'ok'],
            'partes vacías' => [$c, ",,t={$t},,v0=".self::H1.',,', $s, $a, 'ok'],
            'clave desconocida con valor raro: se ignora' => [$c, "t={$t},v1=zzz,v0=".self::H1, $s, $a, 'ok'],
            'clave en mayúsculas (T): desconocida, sin t' => [$c, "T={$t},v0=".self::H1, $s, $a, 'malformed_signature'],
            'cabecera null' => [$c, null, $s, $a, 'missing_signature'],
            'cabecera vacía' => [$c, '', $s, $a, 'missing_signature'],
            'cabecera de espacios' => [$c, "  \t ", $s, $a, 'missing_signature'],
            'solo comas' => [$c, ',,,', $s, $a, 'malformed_signature'],
            'sin t' => [$c, 'v0='.self::H1, $s, $a, 'malformed_signature'],
            'dos t iguales' => [$c, "t={$t},t={$t},v0=".self::H1, $s, $a, 'malformed_signature'],
            'dos t, uno mal formado' => [$c, "t=abc,t={$t},v0=".self::H1, $s, $a, 'malformed_signature'],
            't no numérico' => [$c, 't=abc,v0='.self::H1, $s, $a, 'malformed_signature'],
            't de 13 cifras' => [$c, 't=1761955200000,v0='.self::H1, $s, $a, 'malformed_signature'],
            't vacío' => [$c, 't=,v0='.self::H1, $s, $a, 'malformed_signature'],
            't negativo' => [$c, "t=-{$t},v0=".self::H1, $s, $a, 'malformed_signature'],
            't con espacio tras el igual' => [$c, "t= {$t},v0=".self::H1, $s, $a, 'malformed_signature'],
            'sin v0' => [$c, "t={$t}", $s, $a, 'malformed_signature'],
            'v0 de 63 hex' => [$c, "t={$t},v0=".substr(self::H1, 0, 63), $s, $a, 'malformed_signature'],
            'v0 de 65 hex' => [$c, "t={$t},v0=".self::H1.'a', $s, $a, 'malformed_signature'],
            'v0 que no es hex' => [$c, "t={$t},v0=".str_repeat('g', 64), $s, $a, 'malformed_signature'],
            'un v0 bueno y otro mal formado' => [$c, "t={$t},v0=".self::H1.',v0=xyz', $s, $a, 'malformed_signature'],
            'parte sin igual' => [$c, "t={$t},basura,v0=".self::H1, $s, $a, 'malformed_signature'],
            'v0 sin igual' => [$c, "t={$t},v0", $s, $a, 'malformed_signature'],
            'se parte por el primer igual (v0=<hex>=)' => [$c, "t={$t},v0=".self::H1.'=', $s, $a, 'malformed_signature'],
            'caducada (un segundo más allá de 30 min)' => [$c, "t={$t},v0=".self::H1, $s, self::T + 1801, 'expired'],
            'borde: exactamente 30 min vale' => [$c, "t={$t},v0=".self::H1, $s, self::T + 1800, 'ok'],
            'futura (un segundo más allá de 5 min)' => [$c, "t={$t},v0=".self::H1, $s, self::T - 301, 'from_future'],
            'borde: exactamente 5 min en el futuro vale' => [$c, "t={$t},v0=".self::H1, $s, self::T - 300, 'ok'],
            'caducada gana a una firma que no coincide' => [$c, "t={$t},v0=".self::HX, $s, self::T + 1801, 'expired'],
            'futura gana a una firma que no coincide' => [$c, "t={$t},v0=".self::HX, $s, self::T - 301, 'from_future'],
            'secreto ajeno' => [$c, "t={$t},v0=".self::H1, 'otro_secreto', $a, 'mismatch'],
            'la firma del secreto ajeno con ese secreto' => [$c, "t={$t},v0=".self::HX, 'otro_secreto', $a, 'ok'],
            'cuerpo alterado (salto de línea final)' => [$c."\n", "t={$t},v0=".self::H1, $s, $a, 'mismatch'],
            'el cuerpo alterado con su propia firma' => [$c."\n", "t={$t},v0=".self::H2, $s, $a, 'ok'],
            'JSON vuelto a serializar: no vale' => [json_encode(json_decode($c, true), JSON_PRETTY_PRINT), "t={$t},v0=".self::H1, $s, $a, 'mismatch'],
            't con ceros a la izquierda: se firma tal cual' => [$c, "t=00{$t},v0=".self::H4, $s, $a, 'ok'],
            't con ceros a la izquierda y la firma sin ellos' => [$c, "t=00{$t},v0=".self::H1, $s, $a, 'mismatch'],
            'firma de otro t' => [$c, 't='.(self::T + 1).',v0='.self::H1, $s, $a, 'mismatch'],
        ];
    }

    public function test_los_casos_del_contrato(): void
    {
        foreach (self::casos() as $nombre => [$cuerpo, $cabecera, $secreto, $ahora, $esperado]) {
            $this->assertSame($esperado, Firma::verify($cuerpo, $cabecera, $secreto, $ahora), $nombre);
        }
        $this->assertGreaterThanOrEqual(20, count(self::casos()), 'el fixture pide al menos 20');
    }

    public function test_el_resultado_siempre_es_del_conjunto_cerrado(): void
    {
        $this->assertSame(['ok', 'missing_signature', 'malformed_signature', 'expired', 'from_future', 'mismatch'], Firma::RESULTS);
        $vistos = [];
        foreach (self::casos() as [$cuerpo, $cabecera, $secreto, $ahora]) {
            $vistos[] = Firma::verify($cuerpo, $cabecera, $secreto, $ahora);
        }
        $vistos = array_values(array_unique($vistos));
        sort($vistos);
        $todos = Firma::RESULTS;
        sort($todos);
        $this->assertSame($todos, $vistos, 'los casos recorren los seis resultados');
    }

    public function test_un_secreto_vacio_es_un_fallo_de_configuracion(): void
    {
        foreach ([null, '', 't='.self::T.',v0='.self::H1] as $cabecera) {
            try {
                Firma::verify(self::CUERPO, $cabecera, '', self::AHORA);
                $this->fail('un secreto vacío tiene que lanzar');
            } catch (InvalidArgumentException $e) {
                $this->assertStringNotContainsString(self::H1, $e->getMessage());
            }
        }
    }

    public function test_constantes_del_contrato(): void
    {
        $this->assertSame(1800, Firma::TOLERANCE_SECONDS);
        $this->assertSame(300, Firma::FUTURE_SKEW_SECONDS);
    }

    public function test_compara_en_tiempo_constante_y_sobre_el_cuerpo_crudo(): void
    {
        $codigo = (string) file_get_contents(dirname(__DIR__, 3).'/app/Services/Voice/ElevenLabs/ElevenLabsWebhookSignature.php');
        $this->assertStringContainsString('hash_equals(', $codigo);
        $this->assertStringContainsString("hash_hmac('sha256', \$t.'.'.\$rawBody, \$secret)", $codigo);
        // Nunca se decodifica ni se vuelve a serializar el cuerpo para firmar.
        $this->assertStringNotContainsString('json_decode', $codigo);
        $this->assertStringNotContainsString('json_encode', $codigo);
    }
}
