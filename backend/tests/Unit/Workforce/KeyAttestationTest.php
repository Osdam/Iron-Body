<?php

namespace Tests\Unit\Workforce;

use App\Support\Workforce\KeyAttestation;
use PHPUnit\Framework\TestCase;

/**
 * Verificación de la atestación de clave de Android con una cadena sintética
 * firmada de verdad por OpenSSL: raíz EC autofirmada → hoja con la extensión
 * KeyDescription (OID 1.3.6.1.4.1.11129.2.1.17). La raíz de Google no se puede
 * fabricar, así que la raíz de prueba se acepta pasándola como «confiable»; la
 * prueba de que una raíz ajena se rechaza usa la lista real de Google.
 */
class KeyAttestationTest extends TestCase
{
    private const CHALLENGE = 'K7QX4M2P';

    /** @return array{chain: list<string>, leafSpki: string, rootFingerprint: string} */
    private function cadena(string $challenge, int $nivel, ?string $leafExt = null): array
    {
        $rootKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $leafKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);

        $conf = tempnam(sys_get_temp_dir(), 'att').'.cnf';
        file_put_contents($conf, implode("\n", [
            '[ req ]', 'distinguished_name = dn', '[ dn ]',
            '[ root_ext ]', 'basicConstraints = critical,CA:true',
            '[ leaf_ext ]', $leafExt ?? '1.3.6.1.4.1.11129.2.1.17 = DER:'.bin2hex($this->keyDescription($challenge, $nivel)),
        ]));

        $rootCsr = openssl_csr_new(['commonName' => 'Raíz de prueba'], $rootKey, ['config' => $conf, 'digest_alg' => 'sha256']);
        $root = openssl_csr_sign($rootCsr, null, $rootKey, 30, ['config' => $conf, 'x509_extensions' => 'root_ext', 'digest_alg' => 'sha256']);
        $leafCsr = openssl_csr_new(['commonName' => 'Android Keystore Key'], $leafKey, ['config' => $conf, 'digest_alg' => 'sha256']);
        $leaf = openssl_csr_sign($leafCsr, $root, $rootKey, 30, ['config' => $conf, 'x509_extensions' => 'leaf_ext', 'digest_alg' => 'sha256']);
        @unlink($conf);

        return [
            'chain' => [$this->der($leaf), $this->der($root)],
            'leafSpki' => $this->spki($leafKey),
            'rootFingerprint' => hash('sha256', $this->spki($rootKey)),
        ];
    }

    /** KeyDescription mínima: versión, nivel, versión, nivel, desafío, uniqueId, dos AuthorizationList vacías. */
    private function keyDescription(string $challenge, int $nivel): string
    {
        $tlv = fn (int $tag, string $v): string => chr($tag).chr(strlen($v)).$v;
        $body = $tlv(0x02, "\x64").$tlv(0x0A, chr($nivel)).$tlv(0x02, "\x64").$tlv(0x0A, chr($nivel))
            .$tlv(0x04, $challenge).$tlv(0x04, '').$tlv(0x30, '').$tlv(0x30, '');

        return $tlv(0x30, $body);
    }

    private function der(\OpenSSLCertificate $cert): string
    {
        openssl_x509_export($cert, $pem);

        return base64_decode((string) preg_replace('/-----[^-]+-----|\s+/', '', $pem));
    }

    private function spki(\OpenSSLAsymmetricKey $key): string
    {
        return base64_decode((string) preg_replace('/-----[^-]+-----|\s+/', '', openssl_pkey_get_details($key)['key']));
    }

    public function test_una_cadena_valida_con_el_desafio_de_la_vinculacion_queda_verificada_en_strongbox(): void
    {
        $c = $this->cadena(self::CHALLENGE, 2);

        $this->assertSame(
            ['status' => 'verified', 'security_level' => 'strongbox', 'reason' => null],
            KeyAttestation::verify($c['chain'], self::CHALLENGE, $c['leafSpki'], [$c['rootFingerprint']]),
        );
    }

    public function test_el_nivel_tee_se_reconoce(): void
    {
        $c = $this->cadena(self::CHALLENGE, 1);

        $this->assertSame('tee', KeyAttestation::verify($c['chain'], self::CHALLENGE, $c['leafSpki'], [$c['rootFingerprint']])['security_level']);
    }

    public function test_una_cadena_de_otra_vinculacion_no_vale(): void
    {
        $c = $this->cadena('OTROCODE', 2);

        $this->assertSame('challenge_mismatch', KeyAttestation::verify($c['chain'], self::CHALLENGE, $c['leafSpki'], [$c['rootFingerprint']])['reason']);
    }

    public function test_una_raiz_que_no_es_de_google_no_verifica(): void
    {
        $c = $this->cadena(self::CHALLENGE, 2);

        $this->assertSame('root_not_trusted', KeyAttestation::verify($c['chain'], self::CHALLENGE, $c['leafSpki'])['reason']);
    }

    public function test_la_clave_atestada_debe_ser_la_que_se_registra(): void
    {
        $c = $this->cadena(self::CHALLENGE, 2);
        $otra = $this->spki(openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']));

        $this->assertSame('leaf_key_mismatch', KeyAttestation::verify($c['chain'], self::CHALLENGE, $otra, [$c['rootFingerprint']])['reason']);
    }

    public function test_una_cadena_rota_no_verifica(): void
    {
        $a = $this->cadena(self::CHALLENGE, 2);
        $b = $this->cadena(self::CHALLENGE, 2);

        // Hoja de una cadena con la raíz de otra: la firma no cuadra.
        $this->assertSame('chain_signature', KeyAttestation::verify([$a['chain'][0], $b['chain'][1]], self::CHALLENGE, $a['leafSpki'], [$b['rootFingerprint']])['reason']);
        $this->assertSame('chain_unreadable', KeyAttestation::verify(['basura', 'basura'], self::CHALLENGE, $a['leafSpki'])['reason']);
        $this->assertSame('chain_length', KeyAttestation::verify([$a['chain'][0]], self::CHALLENGE, $a['leafSpki'])['reason']);
    }

    public function test_un_keydescription_escondido_en_otra_extension_no_cuenta(): void
    {
        // F7: los bytes del OID y una descripción «strongbox» con el desafío
        // correcto, metidos en el valor de OTRA extensión. Buscar el OID en
        // bruto los tomaba por buenos.
        $oid = "\x06\x0A\x2B\x06\x01\x04\x01\xD6\x79\x02\x01\x11";
        $desc = $this->keyDescription(self::CHALLENGE, 2);
        $señuelo = $oid."\x04".chr(strlen($desc)).$desc;
        $c = $this->cadena(self::CHALLENGE, 2, '1.2.3.4.5 = DER:'.bin2hex($señuelo));

        $this->assertNull(KeyAttestation::keyDescription($c['chain'][0]));
        $this->assertSame('no_key_description', KeyAttestation::verify($c['chain'], self::CHALLENGE, $c['leafSpki'], [$c['rootFingerprint']])['reason']);
    }

    public function test_un_senuelo_antes_de_la_extension_real_no_la_suplanta(): void
    {
        // El señuelo dice «strongbox» y el desafío esperado; la extensión REAL
        // dice «software» y otro desafío. Manda la real.
        $oid = "\x06\x0A\x2B\x06\x01\x04\x01\xD6\x79\x02\x01\x11";
        $falsa = $this->keyDescription(self::CHALLENGE, 2);
        $señuelo = $oid."\x04".chr(strlen($falsa)).$falsa;
        $real = $this->keyDescription('OTROCODE', 0);
        $exts = '1.2.3.4.5 = DER:'.bin2hex($señuelo)."\n1.3.6.1.4.1.11129.2.1.17 = DER:".bin2hex($real);
        $c = $this->cadena(self::CHALLENGE, 2, $exts);

        $this->assertSame(['attestation_security_level' => 0, 'challenge' => 'OTROCODE'], KeyAttestation::keyDescription($c['chain'][0]));
        $this->assertSame('challenge_mismatch', KeyAttestation::verify($c['chain'], self::CHALLENGE, $c['leafSpki'], [$c['rootFingerprint']])['reason']);
    }
}
