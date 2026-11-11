<?php

namespace Tests\Unit\Marketing\Ultron\Prompts;

use App\Services\Marketing\Ultron\UltronPublishedConfig as Lector;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * EL LECTOR DEL MOTOR CON `ultron-engine-config/2` (Enmienda M-1 del H5; plan
 * de motores §B.4 y §B.5, paso 4).
 *
 * Lo que se fija:
 *  1. `/2` se lee: el sello `metadata.studio` sale con la MISMA forma que con
 *     `/1` (nueve claves, mismos valores para las mismas partes).
 *  2. Decodificación estricta de lo nuevo: `governed` y `prompts` con sus
 *     claves y tipos exactos en TODA parte no nula; si no, `malformed`.
 *  3. La firma de `/2` es la de `/1`, con el mismo prefijo
 *     `"ultron-engine-config/1\n"` (§B.4: «firma … los de `/1`»): firmar con
 *     otro prefijo es `signature_invalid`.
 *  4. `partFor()`: la misma resolución que `forConversation()`, con el
 *     contrato y lo firmado de `/2`; null y ninguna consulta con la llave en
 *     `off`; nunca lanza; su aviso de log dice `via: decide`.
 *  5. `describe()` cuenta las partes de una cabeza `/2` verificada.
 *  6. La llave `MARKETING_ULTRON_STUDIO_PROMPTS` del fichero de configuración:
 *     solo `canary` (sin distinguir mayúsculas ni espacios) enciende.
 */
class UltronPublishedConfigV2Test extends TestCase
{
    use PromptPublications;
    use RefreshDatabase;

    private const VERSION_A = 'a0000000-0000-4000-8000-00000000000a';

    private const VERSION_B = 'b0000000-0000-4000-8000-00000000000b';

    private int $canario = 501;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('marketing.ultron.studio_config', Lector::CANARY);
        config()->set('marketing.ultron.canary_conversation_id', $this->canario);
    }

    public function test_el_sello_de_una_cabeza_v2_tiene_la_forma_de_siempre(): void
    {
        $v1 = $this->publicaEntrada(Lector::CONTRACT, self::parteV1(self::VERSION_B), self::parteV1(self::VERSION_A));
        $sello1 = (new Lector)->forConversation($this->canario);
        $v2 = $this->publicaEntrada(Lector::CONTRACT_V2, self::parteV2(self::VERSION_B, ['persona.tone' => 'profesional']), self::parteV2(self::VERSION_A));
        $sello2 = (new Lector)->forConversation($this->canario);

        $this->assertSame(['v', 'env', 'state', 'entry', 'seq', 'part', 'version', 'contentHash', 'reason'], array_keys($sello2));
        $this->assertSame(array_replace($sello1, ['entry' => $v2['uuid'], 'seq' => $v2['seq']]), $sello2, 'mismos valores que con /1, salvo la fila');
        $this->assertSame([Lector::APPLIED, 'canary', self::VERSION_B], [$sello2['state'], $sello2['part'], $sello2['version']]);
        $this->assertSame($v1['seq'] + 1, $sello2['seq']);
    }

    public function test_part_for_devuelve_lo_firmado_de_v2(): void
    {
        $canaria = self::parteV2(self::VERSION_B, ['persona.tone' => 'profesional', 'persona.maxChars' => 200]);
        $fila = $this->publicaEntrada(Lector::CONTRACT_V2, $canaria, null);

        $parte = (new Lector)->partFor($this->canario);

        $this->assertSame([
            'env' => 'canary', 'state' => 'applied', 'reason' => null, 'contract' => Lector::CONTRACT_V2,
            'entry' => $fila['uuid'], 'seq' => $fila['seq'], 'part' => 'canary', 'version' => self::VERSION_B,
            'contentHash' => hash('sha256', self::VERSION_B),
            'governed' => $canaria['governed'],
            'prompts' => $canaria['prompts'],
        ], $parte);
        $this->assertSame(320 - 120, $parte['governed']['persona.maxChars'], 'los enteros llegan como enteros');
    }

    public function test_part_for_con_v1_y_sin_parte(): void
    {
        $this->publicaEntrada(Lector::CONTRACT, self::parteV1(self::VERSION_B), null);
        $parte = (new Lector)->partFor($this->canario);
        $this->assertSame([Lector::APPLIED, Lector::CONTRACT, null, null], [$parte['state'], $parte['contract'], $parte['governed'], $parte['prompts']]);

        $legacy = $this->publicaEntrada(Lector::CONTRACT, null, null);
        $parte = (new Lector)->partFor($this->canario);
        $this->assertSame([Lector::LEGACY, $legacy['uuid'], null], [$parte['state'], $parte['entry'], $parte['governed']]);

        config()->set('marketing.ultron.canary_conversation_id', null);
        $this->assertSame(Lector::OUT_OF_SCOPE, (new Lector)->partFor($this->canario)['state']);
    }

    public function test_sin_cabeza_part_for_es_legacy_sin_entrada(): void
    {
        $parte = (new Lector)->partFor($this->canario);

        $this->assertSame([Lector::LEGACY, null, null, null], [$parte['state'], $parte['entry'], $parte['contract'], $parte['governed']]);
    }

    public function test_con_la_llave_en_off_part_for_es_null_y_no_consulta(): void
    {
        config()->set('marketing.ultron.studio_config', 'off');
        $this->publicaEntrada(Lector::CONTRACT_V2, self::parteV2(self::VERSION_B), null);
        $consultas = 0;
        DB::listen(function (QueryExecuted $q) use (&$consultas): void {
            $consultas += str_contains($q->sql, 'ultron_studio_') ? 1 : 0;
        });

        $this->assertNull((new Lector)->partFor($this->canario));
        $this->assertSame(0, $consultas);
    }

    /** @return array<string, array{\Closure(array<string,mixed>): array<string,mixed>}> */
    public static function partesMalas(): array
    {
        return [
            'sin governed' => [fn (array $p) => array_diff_key($p, ['governed' => 1])],
            'sin prompts' => [fn (array $p) => array_diff_key($p, ['prompts' => 1])],
            'una clave de más en la parte' => [fn (array $p) => $p + ['studio' => 1]],
            'una clave de más en governed' => [fn (array $p) => array_replace($p, ['governed' => $p['governed'] + ['intent.fallback' => 'humano']])],
            'una clave de menos en governed' => [fn (array $p) => array_replace($p, ['governed' => array_diff_key($p['governed'], ['strategy.goal' => 1])])],
            'maxChars como cadena' => [fn (array $p) => array_replace_recursive($p, ['governed' => ['persona.maxChars' => '320']])],
            'emojis como entero' => [fn (array $p) => array_replace_recursive($p, ['governed' => ['persona.emojis' => 1]])],
            'tono como lista' => [fn (array $p) => array_replace($p, ['governed' => array_replace($p['governed'], ['persona.tone' => ['cercano']])])],
            'compiler como cadena' => [fn (array $p) => array_replace_recursive($p, ['prompts' => ['compiler' => '1']])],
            'bundleHash null' => [fn (array $p) => array_replace_recursive($p, ['prompts' => ['bundleHash' => null]])],
            'una clave de más en prompts' => [fn (array $p) => array_replace($p, ['prompts' => $p['prompts'] + ['roles' => []]])],
            'governed como lista' => [fn (array $p) => array_replace($p, ['governed' => [true, 320, 'cercano', 'cita']])],
        ];
    }

    #[DataProvider('partesMalas')]
    public function test_una_parte_v2_fuera_de_forma_es_malformed(\Closure $estropea): void
    {
        $fila = $this->publicaEntrada(Lector::CONTRACT_V2, self::parteV2(self::VERSION_B), $estropea(self::parteV2(self::VERSION_A)));

        $sello = (new Lector)->forConversation($this->canario);
        $this->assertSame([Lector::FALLBACK, Lector::MALFORMED, $fila['uuid']], [$sello['state'], $sello['reason'], $sello['entry']], 'también si la parte mala no es la que rige');
        $this->assertSame([Lector::FALLBACK, Lector::MALFORMED], [(new Lector)->partFor($this->canario)['state'], (new Lector)->partFor($this->canario)['reason']]);
    }

    public function test_una_parte_v1_en_una_entrada_v2_y_al_reves_son_malformed(): void
    {
        $this->publicaEntrada(Lector::CONTRACT_V2, self::parteV1(self::VERSION_B), null);
        $this->assertSame(Lector::MALFORMED, (new Lector)->forConversation($this->canario)['reason']);

        $this->publicaEntrada(Lector::CONTRACT, self::parteV2(self::VERSION_B), null);
        $this->assertSame(Lector::MALFORMED, (new Lector)->forConversation($this->canario)['reason']);
    }

    public function test_la_firma_de_v2_es_la_de_v1_con_su_mismo_prefijo(): void
    {
        $fila = $this->publicaEntrada(Lector::CONTRACT_V2, self::parteV2(self::VERSION_B), null);
        $this->assertSame(Lector::APPLIED, (new Lector)->forConversation($this->canario)['state']);
        $this->assertSame(Lector::signatureFor($fila['effective_json']), $fila['signature']);

        // Firmada con «ultron-engine-config/2\n» delante (la misma subclave): no es la firma del contrato.
        $clave = config('app.key');
        $clave = is_string($clave) && str_starts_with($clave, 'base64:') ? base64_decode(substr($clave, 7)) : $clave;
        $subclave = hash_hmac('sha256', 'ultron-studio-publication', (string) $clave, true);
        $this->publicaEntrada(Lector::CONTRACT_V2, self::parteV2(self::VERSION_B), null, [
            'signature' => fn (string $json): string => hash_hmac('sha256', "ultron-engine-config/2\n".$json, $subclave),
        ]);

        $this->assertSame([Lector::FALLBACK, Lector::SIGNATURE_INVALID], array_values(array_intersect_key((new Lector)->forConversation($this->canario), array_flip(['state', 'reason']))));
    }

    public function test_part_for_nunca_lanza_y_avisa_con_via_decide(): void
    {
        $spy = Log::spy();
        Log::shouldReceive('channel')->andReturn($spy);
        Log::shouldReceive('getFacadeRoot')->andReturn($spy);
        $this->publicaEntrada(Lector::CONTRACT_V2, self::parteV2(self::VERSION_B), null, ['effective_hash' => str_repeat('0', 64)]);

        $parte = (new Lector)->partFor($this->canario);

        $this->assertSame([Lector::FALLBACK, Lector::HASH_MISMATCH, null, null], [$parte['state'], $parte['reason'], $parte['contract'], $parte['governed']]);
        $spy->shouldHaveReceived('warning')->withArgs(fn (string $e, array $c): bool => $e === 'ultron.published_config.fallback' && ($c['via'] ?? null) === 'decide' && ($c['reason'] ?? null) === 'hash_mismatch')->once();
    }

    public function test_el_aviso_del_commit_no_cambia(): void
    {
        $spy = Log::spy();
        Log::shouldReceive('channel')->andReturn($spy);
        Log::shouldReceive('getFacadeRoot')->andReturn($spy);
        $this->publicaEntrada(Lector::CONTRACT_V2, self::parteV2(self::VERSION_B), null, ['effective_hash' => str_repeat('0', 64)]);

        (new Lector)->forConversation($this->canario);

        $spy->shouldHaveReceived('warning')->withArgs(fn (string $e, array $c): bool => $e === 'ultron.published_config.fallback' && ! array_key_exists('via', $c))->once();
    }

    public function test_describe_cuenta_las_partes_de_una_cabeza_v2(): void
    {
        $fila = $this->publicaEntrada(Lector::CONTRACT_V2, self::parteV2(self::VERSION_B), null);

        $d = (new Lector)->describe();

        $this->assertSame(['canary' => true, 'rest' => false], $d['parts']);
        $this->assertSame(['entry' => $fila['uuid'], 'seq' => $fila['seq'], 'contract' => Lector::CONTRACT_V2, 'hashOk' => true, 'signatureOk' => true, 'parseOk' => true], $d['head']);
    }

    public function test_los_contratos_que_sabe_leer(): void
    {
        $this->assertSame('ultron-engine-config/1', Lector::CONTRACT, 'el del Studio sigue atado a /1');
        $this->assertSame('ultron-engine-config/2', Lector::CONTRACT_V2);
        $this->assertSame([Lector::CONTRACT, Lector::CONTRACT_V2], Lector::CONTRACTS);
    }

    // ── La llave del fichero de configuración ────────────────────────────────

    /** @return array<string, array{?string, string}> */
    public static function valoresDeLaLlave(): array
    {
        return [
            'sin variable' => [null, 'off'],
            'off' => ['off', 'off'],
            'canary' => ['canary', 'canary'],
            'CANARY con espacios' => [' CANARY ', 'canary'],
            'all' => ['all', 'off'],
            'true' => ['true', 'off'],
            'on' => ['on', 'off'],
            'vacío' => ['', 'off'],
        ];
    }

    #[DataProvider('valoresDeLaLlave')]
    public function test_la_llave_de_los_prompts_solo_enciende_con_canary(?string $valor, string $esperado): void
    {
        $nombre = 'MARKETING_ULTRON_STUDIO_PROMPTS';
        $antes = [$_SERVER[$nombre] ?? null, $_ENV[$nombre] ?? null, getenv($nombre)];
        try {
            unset($_SERVER[$nombre], $_ENV[$nombre]);
            putenv($nombre);
            if ($valor !== null) {
                $_SERVER[$nombre] = $_ENV[$nombre] = $valor;
                putenv("{$nombre}={$valor}");
            }
            $config = require base_path('config/marketing.php');
        } finally {
            unset($_SERVER[$nombre], $_ENV[$nombre]);
            putenv($nombre);
            [$servidor, $entorno, $proceso] = $antes;
            if ($servidor !== null) {
                $_SERVER[$nombre] = $servidor;
            }
            if ($entorno !== null) {
                $_ENV[$nombre] = $entorno;
            }
            if ($proceso !== false) {
                putenv("{$nombre}={$proceso}");
            }
        }

        $this->assertSame($esperado, $config['ultron']['studio_prompts']);
    }
}
