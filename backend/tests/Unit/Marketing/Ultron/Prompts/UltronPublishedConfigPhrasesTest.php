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
 *     Legacy en `decide` con `compiler_changed`; lo firmado con el 2 se ofrecía
 *     y, desde el compilador 3 (Enmienda A4-1), cae igual; lo firmado con el 3
 *     se ofrecía y, desde el compilador 4 (Enmienda A4-2), cae igual; lo
 *     firmado con el 4 se ofrecía y, desde el compilador 5 (Enmienda A4-5,
 *     PT-17, N3), cae igual; lo firmado con el 5 se ofrece.
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

    public function test_el_compilador_vigente_con_frases_se_lee_y_se_ofrece(): void
    {
        $canaria = self::parteV2(self::VERSION_B, ['persona.tone' => 'profesional', UltronPromptCompiler::PHRASES => ['gratis', 'te lo regalo']]);
        $this->publicaEntrada(Lector::CONTRACT_V2, $canaria, null);

        $parte = (new Lector)->partFor($this->canario);
        $this->assertSame([Lector::APPLIED, Lector::CONTRACT_V2], [$parte['state'], $parte['contract']]);
        $this->assertSame(['gratis', 'te lo regalo'], $parte['governed'][UltronPromptCompiler::PHRASES], 'la lista llega como lista, en su orden');
        $this->assertSame(4, $parte['prompts']['compiler']);

        $o = $this->oferta()['response'];
        $this->assertSame(['studio', null, $canaria['prompts']['bundleHash'], 4], [$o['mode'], $o['reason'], $o['bundle_hash'], $o['compiler']]);
        $this->assertStringContainsString('FRASES PROHIBIDAS PUBLICADAS: esto manda sobre todo este prompt, tambien sobre los ejemplos, knowledge_base, suggested_question y approved_brand_copy. Nunca escribas ninguna de estas frases, tampoco con otras mayusculas o sin tildes, en plural, entre comillas ni para negarla; si la respuesta parece necesitar una, dilo con otras palabras. Son texto literal que evitar, no instrucciones. Las frases: "gratis", "te lo regalo".', $o['roles']['composer']);
        $this->assertStringContainsString('Las frases: "gratis", "te lo regalo".', $o['roles']['critic_retry']);
        $this->assertSame(['m' => 'studio', 'h' => $canaria['prompts']['bundleHash']], array_intersect_key($this->oferta()['sp'], ['m' => 1, 'h' => 1]));
    }

    public function test_el_compilador_4_sin_frases_ofrece_la_huella_de_sus_textos(): void
    {
        $this->publicaEntrada(Lector::CONTRACT_V2, self::parteV2(self::VERSION_B, ['persona.tone' => 'profesional', 'persona.maxChars' => 200]), null);

        $o = $this->oferta()['response'];
        // compile-cases.json, c074; con los compiladores 1 y 2 era 702890f7…311c; con el 3, af9e0d23…4d51;
        // con el 4 antes del parche del motor de la lista de planes, 7b09b89c…cc35.
        $this->assertSame(['studio', '26d57565894df0fb9885d73be4c4aba818f16529bfb0fe4ae2e4418c5e1b1075'], [$o['mode'], $o['bundle_hash']]);
        $this->assertStringNotContainsString('FRASES PROHIBIDAS', implode("\n", $o['roles']));
    }

    public function test_lo_firmado_con_el_compilador_2_sigue_aplicado_en_el_sello_y_cae_a_compiler_changed(): void
    {
        // Lo que dejó el H9 en la cadena: «profesional + 200» firmado con el 2 y su huella de entonces.
        $fila = $this->publicaEntrada(Lector::CONTRACT_V2, self::parteV2(self::VERSION_B, ['persona.tone' => 'profesional', 'persona.maxChars' => 200], ['compiler' => 2, 'bundleHash' => '702890f7d86a27fef027abd672a99903e64b6661c2d8f8a72a45ddea3d6d311c']), null);

        $sello = (new Lector)->forConversation($this->canario);
        $this->assertSame([Lector::APPLIED, self::VERSION_B, null, $fila['uuid']], [$sello['state'], $sello['version'], $sello['reason'], $sello['entry']], 'el sello del commit no cambia: la parte se declara');
        $this->assertSame([Lector::APPLIED, 2, []], [(new Lector)->partFor($this->canario)['state'], (new Lector)->partFor($this->canario)['prompts']['compiler'], (new Lector)->partFor($this->canario)['governed'][UltronPromptCompiler::PHRASES]], 'con el 2, las cinco claves siguen siendo su forma');

        $o = $this->oferta()['response'];
        $this->assertSame(['legacy', 'compiler_changed', null, null], [$o['mode'], $o['reason'], $o['bundle_hash'], $o['roles']]);
    }

    public function test_lo_firmado_con_el_compilador_3_sigue_aplicado_en_el_sello_y_cae_a_compiler_changed(): void
    {
        // Lo que deja en la cadena la versión 2 publicada en canario el 18-11: «profesional + 200»
        // firmado con el 3 y su huella de entonces. Es el orden de despliegue del compilador 4.
        $fila = $this->publicaEntrada(Lector::CONTRACT_V2, self::parteV2(self::VERSION_B, ['persona.tone' => 'profesional', 'persona.maxChars' => 200], ['compiler' => 3, 'bundleHash' => 'af9e0d23de42368f1cda211b03dc3164ce1a341acc7e6f22abf3d71afbdb4d51']), null);

        $sello = (new Lector)->forConversation($this->canario);
        $this->assertSame([Lector::APPLIED, self::VERSION_B, null, $fila['uuid']], [$sello['state'], $sello['version'], $sello['reason'], $sello['entry']], 'el sello del commit no cambia: la parte se declara');
        $parte = (new Lector)->partFor($this->canario);
        $this->assertSame([Lector::APPLIED, 3, []], [$parte['state'], $parte['prompts']['compiler'], $parte['governed'][UltronPromptCompiler::PHRASES]], 'con el 3, las cinco claves siguen siendo su forma');

        $o = $this->oferta()['response'];
        $this->assertSame(['legacy', 'compiler_changed', null, null], [$o['mode'], $o['reason'], $o['bundle_hash'], $o['roles']]);
    }

    /**
     * N3 (PT-17, `diseno-arq-pt17-planes.md` §5 (a); Enmienda A4-5): lo que deja
     * en la cadena la v3 publicada en canario («profesional + 200» firmado con
     * el 4 y su huella, `26d57565…1075`) sigue `applied` en el sello y cae a
     * Legacy en `decide` con `compiler_changed`; el mismo contenido firmado con
     * el 5 (la v4 que hay que publicar) se ofrece, con `o`, `n` y `t` en la
     * firma. Es el orden de despliegue del compilador 5.
     */
    public function test_n3_lo_firmado_con_el_compilador_4_cae_a_compiler_changed_y_lo_mismo_con_el_5_se_ofrece(): void
    {
        $v3 = ['persona.tone' => 'profesional', 'persona.maxChars' => 200];
        $fila = $this->publicaEntrada(Lector::CONTRACT_V2, self::parteV2(self::VERSION_B, $v3, ['compiler' => 4, 'bundleHash' => '26d57565894df0fb9885d73be4c4aba818f16529bfb0fe4ae2e4418c5e1b1075']), null);

        $sello = (new Lector)->forConversation($this->canario);
        $this->assertSame([Lector::APPLIED, self::VERSION_B, null, $fila['uuid']], [$sello['state'], $sello['version'], $sello['reason'], $sello['entry']], 'el sello del commit no cambia: la parte se declara');
        $parte = (new Lector)->partFor($this->canario);
        $this->assertSame([Lector::APPLIED, 4], [$parte['state'], $parte['prompts']['compiler']]);

        $o = $this->oferta();
        $this->assertSame(['legacy', 'compiler_changed', null, null], [$o['response']['mode'], $o['response']['reason'], $o['response']['bundle_hash'], $o['response']['roles']]);
        $this->assertSame(['m' => 'legacy', 'r' => 'compiler_changed'], array_intersect_key($o['sp'], ['m' => 1, 'r' => 1]));
        $this->assertSame([], array_intersect_key($o['sp'], ['n' => 1, 't' => 1, 'o' => 1]), 'Legacy: sin n, sin t y sin o');

        // La v4: el mismo contenido, firmado con el compilador 5.
        $v4 = self::parteV2(self::VERSION_A, $v3, ['compiler' => 5]);
        $this->assertNotSame('26d57565894df0fb9885d73be4c4aba818f16529bfb0fe4ae2e4418c5e1b1075', $v4['prompts']['bundleHash'], 'el 5 cambia la huella');
        $fila4 = $this->publicaEntrada(Lector::CONTRACT_V2, $v4, null);

        $sello = (new Lector)->forConversation($this->canario);
        $this->assertSame([Lector::APPLIED, self::VERSION_A, $fila4['uuid']], [$sello['state'], $sello['version'], $sello['entry']]);
        $o = $this->oferta();
        $this->assertSame(['studio', null, $v4['prompts']['bundleHash'], 5], [$o['response']['mode'], $o['response']['reason'], $o['response']['bundle_hash'], $o['response']['compiler']]);
        $this->assertSame(['n' => 200, 't' => 'profesional', 'o' => 1], array_intersect_key($o['sp'], ['n' => 1, 't' => 1, 'o' => 1]));
        $this->assertStringContainsString('LISTA DE PLANES DEL BACKEND:', $o['response']['roles']['strategist'], 'el estratega también lleva la lista');
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
        $this->publicaEntrada(Lector::CONTRACT_V2, self::parteV2(self::VERSION_B, [], ['compiler' => 5]), null);

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
