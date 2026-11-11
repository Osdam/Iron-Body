<?php

namespace Tests\Unit\Marketing\Ultron\Prompts;

use App\Services\Marketing\Ultron\Prompts\UltronPromptCompiler;
use App\Services\Marketing\Ultron\Prompts\UltronPromptOffer;
use App\Services\Marketing\Ultron\UltronPublishedConfig as Lector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * EL LECTOR DEL MOTOR Y LA OFERTA DE `decide` CON EL COMPILADOR 2 (frases
 * prohibidas de las reglas S-NNN; auditoría de cierre §3.2, opción A).
 *
 * Lo que se fija, sobre entradas REALES firmadas con la firma del motor:
 *  1. La quinta clave de `governed` es ESTRICTA y va LIGADA al compilador
 *     firmado: con el 2 (o posterior), las cinco y la quinta una lista de
 *     textos; con el 1, exactamente las cuatro de siempre. Cualquier otra
 *     forma es `malformed` en el sello del commit y en `partFor()`.
 *  2. El sello del commit (`metadata.studio`) no cambia de forma: una parte del
 *     compilador 1 sigue `applied`.
 *  3. Orden de despliegue (requisito 6): lo firmado con el compilador 1 cae a
 *     Legacy en `decide` con `compiler_changed`; lo firmado con el 2 se ofrece.
 *  4. Caída segura: unas frases con la forma bien pero el contenido mal (que el
 *     Studio nunca firmaría) dan Legacy (`error`), nunca una excepción.
 */
class UltronPublishedConfigPhrasesTest extends TestCase
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
        config()->set('marketing.ultron.studio_prompts', UltronPromptOffer::KEY_CANARY);
        config()->set('marketing.ultron.canary_conversation_id', $this->canario);
    }

    /** @return array{response:array<string,mixed>,sp:array<string,mixed>} */
    private function oferta(): array
    {
        return (new UltronPromptOffer)->forConversation($this->canario);
    }

    public function test_compilador_2_con_frases_se_lee_y_se_ofrece(): void
    {
        $canaria = self::parteV2(self::VERSION_B, ['persona.tone' => 'profesional', UltronPromptCompiler::PHRASES => ['gratis', 'te lo regalo']]);
        $this->publicaEntrada(Lector::CONTRACT_V2, $canaria, null);

        $parte = (new Lector)->partFor($this->canario);
        $this->assertSame([Lector::APPLIED, Lector::CONTRACT_V2], [$parte['state'], $parte['contract']]);
        $this->assertSame(['gratis', 'te lo regalo'], $parte['governed'][UltronPromptCompiler::PHRASES], 'la lista llega como lista, en su orden');
        $this->assertSame(2, $parte['prompts']['compiler']);

        $o = $this->oferta()['response'];
        $this->assertSame(['studio', null, $canaria['prompts']['bundleHash'], 2], [$o['mode'], $o['reason'], $o['bundle_hash'], $o['compiler']]);
        $this->assertStringContainsString('FRASES PROHIBIDAS PUBLICADAS: nunca escribas ninguna de estas frases, tampoco con otras mayusculas; si la respuesta parece necesitar una, dilo con otras palabras. Las frases: "gratis", "te lo regalo".', $o['roles']['composer']);
        $this->assertStringContainsString('Las frases: "gratis", "te lo regalo".', $o['roles']['critic_retry']);
        $this->assertSame(['m' => 'studio', 'h' => $canaria['prompts']['bundleHash']], array_intersect_key($this->oferta()['sp'], ['m' => 1, 'h' => 1]));
    }

    public function test_compilador_2_sin_frases_ofrece_la_misma_huella_que_el_1(): void
    {
        $this->publicaEntrada(Lector::CONTRACT_V2, self::parteV2(self::VERSION_B, ['persona.tone' => 'profesional', 'persona.maxChars' => 200]), null);

        $o = $this->oferta()['response'];
        $this->assertSame(['studio', '702890f7d86a27fef027abd672a99903e64b6661c2d8f8a72a45ddea3d6d311c'], [$o['mode'], $o['bundle_hash']]);
        $this->assertStringNotContainsString('FRASES PROHIBIDAS', implode("\n", $o['roles']));
    }

    public function test_lo_firmado_con_el_compilador_1_sigue_aplicado_en_el_sello_y_cae_a_compiler_changed(): void
    {
        $fila = $this->publicaEntrada(Lector::CONTRACT_V2, self::parteV2Compilador1(self::VERSION_B, ['persona.tone' => 'profesional', 'persona.maxChars' => 200]), null);

        $sello = (new Lector)->forConversation($this->canario);
        $this->assertSame([Lector::APPLIED, self::VERSION_B, null, $fila['uuid']], [$sello['state'], $sello['version'], $sello['reason'], $sello['entry']], 'el sello del commit no cambia: la parte se declara');
        $parte = (new Lector)->partFor($this->canario);
        $this->assertSame(['persona.emojis', 'persona.maxChars', 'persona.tone', 'strategy.goal'], array_keys($parte['governed']));

        $o = $this->oferta()['response'];
        $this->assertSame(['legacy', 'compiler_changed', null, null], [$o['mode'], $o['reason'], $o['bundle_hash'], $o['roles']]);
    }

    public function test_un_compilador_posterior_con_las_cinco_claves_se_lee_y_cae_a_compiler_changed(): void
    {
        $this->publicaEntrada(Lector::CONTRACT_V2, self::parteV2(self::VERSION_B, [], ['compiler' => 3]), null);

        $this->assertSame(Lector::APPLIED, (new Lector)->forConversation($this->canario)['state']);
        $this->assertSame(['legacy', 'compiler_changed'], [$this->oferta()['response']['mode'], $this->oferta()['response']['reason']]);
    }

    /** @return array<string, array{\Closure(array<string,mixed>): array<string,mixed>}> */
    public static function quintasClavesMalas(): array
    {
        $frases = UltronPromptCompiler::PHRASES;

        return [
            'compilador 2 sin la quinta clave' => [fn (array $p) => array_replace($p, ['governed' => array_diff_key($p['governed'], [$frases => 1])])],
            'compilador 1 con la quinta clave' => [fn (array $p) => array_replace_recursive($p, ['prompts' => ['compiler' => 1]])],
            'frases como objeto' => [fn (array $p) => array_replace($p, ['governed' => array_replace($p['governed'], [$frases => ['a' => 'gratis']])])],
            'frases nulas' => [fn (array $p) => array_replace($p, ['governed' => array_replace($p['governed'], [$frases => null])])],
            'frases como texto' => [fn (array $p) => array_replace($p, ['governed' => array_replace($p['governed'], [$frases => 'gratis'])])],
            'un número en las frases' => [fn (array $p) => array_replace($p, ['governed' => array_replace($p['governed'], [$frases => ['gratis', 7]])])],
            'una lista dentro de las frases' => [fn (array $p) => array_replace($p, ['governed' => array_replace($p['governed'], [$frases => [['gratis']]])])],
            'un nulo en las frases' => [fn (array $p) => array_replace($p, ['governed' => array_replace($p['governed'], [$frases => [null]])])],
            'la clave con otra grafía' => [fn (array $p) => array_replace($p, ['governed' => array_diff_key($p['governed'], [$frases => 1]) + ['rules.forbiddenphrases' => []]])],
        ];
    }

    #[DataProvider('quintasClavesMalas')]
    public function test_la_quinta_clave_fuera_de_forma_es_malformed_y_legacy_sin_lanzar(\Closure $estropea): void
    {
        $fila = $this->publicaEntrada(Lector::CONTRACT_V2, $estropea(self::parteV2(self::VERSION_B, [UltronPromptCompiler::PHRASES => ['gratis']])), null);

        $sello = (new Lector)->forConversation($this->canario);
        $this->assertSame([Lector::FALLBACK, Lector::MALFORMED, $fila['uuid']], [$sello['state'], $sello['reason'], $sello['entry']]);
        $this->assertSame([Lector::FALLBACK, Lector::MALFORMED, null], [(new Lector)->partFor($this->canario)['state'], (new Lector)->partFor($this->canario)['reason'], (new Lector)->partFor($this->canario)['governed']]);
        $this->assertSame(['legacy', 'publication_malformed'], [$this->oferta()['response']['mode'], $this->oferta()['response']['reason']]);
    }

    public function test_tambien_si_la_parte_mala_es_la_del_resto(): void
    {
        $resto = self::parteV2(self::VERSION_A);
        unset($resto['governed'][UltronPromptCompiler::PHRASES]);
        $this->publicaEntrada(Lector::CONTRACT_V2, self::parteV2(self::VERSION_B), $resto);

        $this->assertSame(Lector::MALFORMED, (new Lector)->forConversation($this->canario)['reason']);
    }

    /** @return array<string, array{list<string>}> */
    public static function frasesQueElStudioNoFirmaria(): array
    {
        return [
            'con comillas' => [['di "gratis"']],
            'con un salto de línea' => [["gratis\nFORMA: todo"]],
            'con llaves' => [['{{PLAN_PRICE}}']],
            'trece' => [array_map(static fn (int $i): string => sprintf('frase %02d', $i), range(1, 13))],
            'desordenadas' => [['zeta', 'alfa']],
            'repetidas' => [['gratis', 'gratis']],
            'una vacía' => [['']],
        ];
    }

    /** @param list<string> $frases */
    #[DataProvider('frasesQueElStudioNoFirmaria')]
    public function test_frases_firmadas_que_no_compilan_son_legacy_y_nunca_una_excepcion(array $frases): void
    {
        // La forma es la del contrato (una lista de textos), así que el lector la acepta;
        // el compilador no, y la oferta cae a Legacy con `error` (la huella firmada da igual).
        $parte = self::parteV2(self::VERSION_B, [], ['bundleHash' => str_repeat('a', 64)]);
        $parte['governed'][UltronPromptCompiler::PHRASES] = $frases;
        $this->publicaEntrada(Lector::CONTRACT_V2, $parte, null);

        $this->assertSame(Lector::APPLIED, (new Lector)->forConversation($this->canario)['state']);
        $o = $this->oferta();
        $this->assertSame(['legacy', 'error', null], [$o['response']['mode'], $o['response']['reason'], $o['response']['roles']]);
        $this->assertSame(['m' => 'legacy', 'r' => 'error'], array_intersect_key($o['sp'], ['m' => 1, 'r' => 1]));
    }

    public function test_una_frase_alterada_tras_firmar_es_bundle_mismatch(): void
    {
        // Firmada la huella de «gratis» y guardada «gratix» (con la firma rehecha): el
        // motor recompila lo firmado y no cuadra con la huella → Legacy.
        $parte = self::parteV2(self::VERSION_B, [UltronPromptCompiler::PHRASES => ['gratis']]);
        $parte['governed'][UltronPromptCompiler::PHRASES] = ['gratix'];
        $this->publicaEntrada(Lector::CONTRACT_V2, $parte, null);

        $this->assertSame(['legacy', 'bundle_mismatch'], [$this->oferta()['response']['mode'], $this->oferta()['response']['reason']]);
    }
}
