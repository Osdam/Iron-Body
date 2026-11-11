<?php

namespace Tests\Unit\UltronStudio;

use App\Exceptions\UltronStudioException;
use App\Services\Marketing\Ultron\Prompts\UltronPromptCompiler;
use App\Services\Marketing\Ultron\UltronPublishedConfig;
use App\Services\UltronStudio\StudioCatalog;
use App\Services\UltronStudio\StudioContentHasher;
use App\Services\UltronStudio\StudioPromptBundle;
use App\Services\UltronStudio\StudioPublicationDeclaration as D;
use App\Services\UltronStudio\StudioRulePhrases as P;
use App\Services\UltronStudio\StudioVoiceCompiler;
use App\Services\UltronStudio\StudioVoiceTemplate;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClassConstant;

/**
 * LAS FRASES DE LAS REGLAS S-NNN: una sola semántica para la voz y para los
 * prompts de WhatsApp (compilador de prompts 2; opción A de la auditoría de
 * cierre §3.2).
 *
 * Lo que se fija:
 *  1. La voz no cambia con la extracción: el `specHash` de una versión con
 *     reglas es el que daba ANTES de extraer `StudioRulePhrases` (instantánea
 *     tomada con el código anterior, `e48be7ea`), y su lista de frases es la
 *     de la clase nueva.
 *  2. WhatsApp recibe EXACTAMENTE las frases de la voz (paridad sobre las
 *     mismas versiones) y las reglas que las aportan son las de la clase
 *     `prompt`.
 *  3. La semántica: solo S-NNN, vivas, con `forbid text contains`, cuyo «si»
 *     se cumple; canónicas, sin repetir y por orden de bytes; nada se descarta
 *     aquí (una frase vacía sale vacía y la rechaza quien compila).
 */
class StudioRulePhrasesTest extends TestCase
{
    /**
     * `specHash` de la voz para {@see self::versionConReglas()} con
     * {@see self::perfil()} y tope 600, calculado con `StudioVoiceCompiler` de
     * `e48be7ea`, ANTES de extraer `StudioRulePhrases`.
     */
    private const VOICE_SPEC_HASH_ANTES = '5300386942b6c41f7f04d0488c41c8b62df9d817a3e7775599489f917f587006';

    /**
     * `specHash` de la voz para la versión de
     * {@see self::test_las_frases_cortas_o_sin_texto_no_cambian_la_voz_y_whatsapp_las_rechaza()}
     * (frases de 2 caracteres y sin letras), calculado con el compilador de
     * prompts que aún admitía 2 caracteres y frases sin letras (antes de B-3).
     */
    private const VOICE_SPEC_HASH_CORTAS = '871753c69032fe61074c26ce5b1a4315dde831da491de9edf1d32911b1385927';

    // ── Datos (los del compilador de voz, StudioVoiceCompilerTest) ──────────

    /** @param list<array<string,mixed>> $studio */
    private static function reglas(array $studio = []): array
    {
        $codigo = array_map(static fn (array $r): array => [
            'code' => $r['code'], 'revision' => 2, 'source' => 'legacy_baseline', 'enforcement' => 'code', 'status' => $r['status'],
            'description' => $r['description'], 'type' => $r['type'], 'layers' => $r['layers'], 'priority' => $r['priority'],
            'refs' => $r['refs'], 'conditions' => $r['conditions'],
        ], StudioCatalog::legacyRules());

        return [...$codigo, ...$studio];
    }

    /** @param list<string> $frases */
    private static function regla(string $code, array $frases, array $when = [], string $status = 'draft', string $kind = 'forbid'): array
    {
        return [
            'code' => $code, 'revision' => 1, 'source' => 'studio', 'enforcement' => 'none', 'status' => $status,
            'description' => 'Regla del Studio de la prueba.', 'type' => 'style', 'layers' => ['persona'], 'priority' => 'normal',
            'refs' => ['configs' => [], 'edges' => [], 'knowledgeKeys' => [], 'knowledgeCategories' => []],
            'conditions' => ['when' => $when, 'then' => [['kind' => $kind, 'target' => 'text', 'op' => 'contains', 'phrases' => $frases]]],
        ];
    }

    private static function cuandoTono(string $tono): array
    {
        return [['target' => 'config', 'layer' => 'persona', 'key' => 'tone', 'op' => 'eq', 'value' => $tono]];
    }

    /**
     * @param  array<string,array<string,mixed>>  $configs
     * @param  list<array<string,mixed>>  $studio
     * @return array<string,mixed> el contenido de una versión de formato 2
     */
    private static function contenido(array $configs = [], array $studio = []): array
    {
        $base = StudioCatalog::baseline();
        $cfg = $base['configs'];
        foreach ($configs as $capa => $valores) {
            $cfg[$capa] = array_merge($cfg[$capa], $valores);
        }

        return [
            'catalogVersion' => StudioCatalog::VERSION,
            'format' => StudioContentHasher::FORMAT_2,
            'definition' => ['layers' => StudioCatalog::layerIds(), 'edgeIds' => $base['edgeIds']],
            'configs' => $cfg,
            'layout' => ['positions' => $base['positions'], 'viewport' => null],
            'rules' => self::reglas($studio),
        ];
    }

    /** Las reglas de la prueba de la voz: dos que aplican siempre, una con el «si» falso, una archivada, una con el «si» cierto y una por arista. */
    private static function variadas(): array
    {
        return [
            self::regla('S-002', ['te lo juro', '  Barato ']),
            self::regla('S-001', ['barato', 'gratis']),
            self::regla('S-003', ['oiga'], self::cuandoTono('profesional')),
            self::regla('S-004', ['jamás'], status: 'archived'),
            self::regla('S-005', ['parcero'], self::cuandoTono('cercano')),
            self::regla('S-006', ['de una'], [['target' => 'edge', 'edge' => 'E08', 'op' => 'present']]),
            self::regla('S-009', ['siempre esto'], kind: 'require'),
        ];
    }

    private static function versionConReglas(): array
    {
        $content = self::contenido([], self::variadas());

        return [
            'uuid' => '0f6c2a52-6c1e-4c3a-9a51-7d3b0e1c2a10',
            'status' => 'APPROVED',
            'contentHash' => StudioContentHasher::versionHash(StudioCatalog::VERSION, $content, $content['rules']),
            'content' => $content,
            'createdBy' => 11,
            'approvedBy' => 22,
        ];
    }

    private static function perfil(): array
    {
        $content = [
            'format' => StudioVoiceCompiler::PROFILE_FORMAT,
            'voiceId' => 'pNInz6obpgDQGcFmaJgB',
            'language' => 'es',
            'aiNotice' => StudioVoiceTemplate::AI_NOTICE,
            'greetings' => ['inbound' => '¿En qué te puedo ayudar hoy?', 'outbound' => 'Te llamo porque dejaste tus datos para conocer Iron Body. ¿Tienes un minuto?'],
            'holes' => ['presentation' => 'Te ayudo con planes, horarios y la sede.', 'closing' => 'Gracias por tu tiempo. ¡Que tengas un buen día!'],
            'maxDurationSeconds' => 300,
            'tools' => ['consultar_planes', 'consultar_horarios', 'no_volver_a_llamar'],
            'systemTools' => ['end_call'],
            'transfer' => ['destinations' => []],
            'privacy' => ['retentionDays' => 30, 'storeAudio' => false, 'recording' => false],
            'voicemail' => 'hang_up',
        ];

        return [
            'uuid' => '5b8e0c3d-2f4a-4b6e-8c1d-9e7f6a5b4c3d',
            'status' => 'approved',
            'contentHash' => StudioVoiceCompiler::profileHash($content),
            'content' => $content,
            'createdBy' => 11,
            'approvedBy' => 22,
        ];
    }

    // ── 1 · La voz no cambia ────────────────────────────────────────────────

    public function test_el_spec_hash_de_la_voz_con_reglas_es_el_de_antes_de_la_extraccion(): void
    {
        $r = StudioVoiceCompiler::compile(self::versionConReglas(), self::perfil(), 600);

        $this->assertSame(self::VOICE_SPEC_HASH_ANTES, $r['specHash']);
        $this->assertSame(['barato', 'de una', 'gratis', 'parcero', 'te lo juro'], $r['spec']['forbiddenPhrases']);
        $this->assertSame($r['spec']['forbiddenPhrases'], P::sorted(P::byRule(self::versionConReglas()['content'])));
    }

    public function test_las_frases_cortas_o_sin_texto_no_cambian_la_voz_y_whatsapp_las_rechaza(): void
    {
        // B-3 de seguridad: WhatsApp exige de 3 a 60 caracteres y al menos una
        // letra o una cifra; la voz conserva SU validación (las acepta) y su
        // `specHash` es el que daba ANTES de ese cambio.
        $content = self::contenido([], [self::regla('S-060', ['no', '..', 'gratis']), self::regla('S-061', ['¿?', '...', '- -'])]);
        $version = ['contentHash' => StudioContentHasher::versionHash(StudioCatalog::VERSION, $content, $content['rules']), 'content' => $content] + self::versionConReglas();

        $voz = StudioVoiceCompiler::compile($version, self::perfil(), 600);
        $this->assertSame(self::VOICE_SPEC_HASH_CORTAS, $voz['specHash']);
        $this->assertSame(['- -', '..', '...', 'gratis', 'no', '¿?'], $voz['spec']['forbiddenPhrases']);

        $this->assertSame([
            ['rule' => 'S-060', 'reason' => 'length', 'phrase' => 'no'],
            ['rule' => 'S-060', 'reason' => 'length', 'phrase' => '..'],
            ['rule' => 'S-061', 'reason' => 'length', 'phrase' => '¿?'],
            ['rule' => 'S-061', 'reason' => 'no_text', 'phrase' => '...'],
            ['rule' => 'S-061', 'reason' => 'no_text', 'phrase' => '- -'],
        ], StudioPromptBundle::phrases($content)['problems']);
        $this->expectException(InvalidArgumentException::class);
        StudioPromptBundle::compile(StudioPromptBundle::governedFrom($content));
    }

    public function test_la_voz_sigue_negandose_en_el_orden_de_las_reglas(): void
    {
        // La primera frase que no cabe en la voz, en el orden de las reglas, da el campo.
        $content = self::contenido([], [self::regla('S-010', ['barato', '80 mil']), self::regla('S-011', ['ironbody.co'])]);
        $v = ['contentHash' => StudioContentHasher::versionHash(StudioCatalog::VERSION, $content, $content['rules']), 'content' => $content] + self::versionConReglas();

        try {
            StudioVoiceCompiler::compile($v, self::perfil(), 600);
            $this->fail('compiló');
        } catch (UltronStudioException $e) {
            $this->assertSame(['reason' => 'rule_phrase_invalid', 'field' => 'version.content.rules.S-010'], $e->payload);
        }
    }

    // ── 2 · WhatsApp recibe exactamente las frases de la voz ────────────────

    /** @return iterable<string,array{array<string,array<string,mixed>>,list<array<string,mixed>>}> */
    public static function versiones(): iterable
    {
        yield 'sin reglas del Studio' => [[], []];
        yield 'las variadas, tono cercano' => [[], self::variadas()];
        yield 'las variadas, tono profesional' => [['persona' => ['tone' => 'profesional']], self::variadas()];
        yield 'solo archivadas y de requerir' => [[], [self::regla('S-004', ['jamás'], status: 'archived'), self::regla('S-009', ['siempre esto'], kind: 'require')]];
        yield 'repetidas entre reglas y con mayúsculas' => [[], [self::regla('S-020', ['Oiga', 'TE LO JURO']), self::regla('S-021', ['oiga', '  te  lo juro '])]];
    }

    /**
     * @param  array<string,array<string,mixed>>  $configs
     * @param  list<array<string,mixed>>  $studio
     */
    #[DataProvider('versiones')]
    public function test_whatsapp_recibe_exactamente_las_frases_de_la_voz(array $configs, array $studio): void
    {
        $content = self::contenido($configs, $studio);
        $version = ['contentHash' => StudioContentHasher::versionHash(StudioCatalog::VERSION, $content, $content['rules']), 'content' => $content] + self::versionConReglas();
        $voz = StudioVoiceCompiler::compile($version, self::perfil(), 600)['spec']['forbiddenPhrases'];

        $gobernado = StudioPromptBundle::governedFrom($content);
        $this->assertSame($voz, $gobernado[UltronPromptCompiler::PHRASES]);
        $this->assertSame($voz, StudioPromptBundle::phrases($content)['list']);
        $this->assertSame([], StudioPromptBundle::phrases($content)['problems']);
        // Y el compilador de prompts las acepta tal cual (ordenadas y sin repetir).
        $this->assertSame($voz, UltronPromptCompiler::compile($gobernado)['governed'][UltronPromptCompiler::PHRASES]);
    }

    public function test_las_reglas_que_aportan_frases(): void
    {
        $porRegla = P::byRule(self::contenido([], self::variadas()));

        // En el orden del contenido; canónicas y con sus repetidas (quien llama decide).
        $this->assertSame([
            ['code' => 'S-002', 'phrases' => ['te lo juro', 'barato']],
            ['code' => 'S-001', 'phrases' => ['barato', 'gratis']],
            ['code' => 'S-005', 'phrases' => ['parcero']],
            ['code' => 'S-006', 'phrases' => ['de una']],
        ], $porRegla);
        $this->assertSame(['S-001', 'S-002', 'S-005', 'S-006'], P::codes($porRegla));
        $this->assertSame(['S-001', 'S-002', 'S-005', 'S-006'], StudioPromptBundle::phrases(self::contenido([], self::variadas()))['rules']);
        $this->assertSame([], P::byRule(['rules' => 'no es una lista']));
        $this->assertSame([], P::byRule([]));
    }

    public function test_una_frase_vacia_no_se_descarta_aqui_y_el_compilador_la_rechaza(): void
    {
        // Una frase que G nunca deja (solo espacios) en una versión escrita a mano.
        $content = self::contenido([], [self::regla('S-030', ['   ', 'gratis'])]);

        $this->assertSame([['code' => 'S-030', 'phrases' => ['', 'gratis']]], P::byRule($content));
        $this->assertSame(['', 'gratis'], StudioPromptBundle::governedFrom($content)[UltronPromptCompiler::PHRASES]);
        $this->assertSame([['rule' => 'S-030', 'reason' => 'length', 'phrase' => '']], StudioPromptBundle::phrases($content)['problems']);
        $this->expectException(InvalidArgumentException::class);
        StudioPromptBundle::compile(StudioPromptBundle::governedFrom($content));
    }

    public function test_lo_que_no_cabe_en_whatsapp_se_dice_regla_a_regla_y_frase_a_frase(): void
    {
        $content = self::contenido([], [
            self::regla('S-040', ['gratis', 'di "gratis"', 'promo: hoy']),
            self::regla('S-041', ['di "gratis"', 'hola@ironbody.co']),
            // Su «si» no se cumple: no entra, aunque no cupiera.
            self::regla('S-042', ['{{PLAN_PRICE}}'], self::cuandoTono('entusiasta')),
        ]);

        $f = StudioPromptBundle::phrases($content);
        $this->assertSame([
            ['rule' => 'S-040', 'reason' => 'charset', 'phrase' => 'di "gratis"'],
            ['rule' => 'S-040', 'reason' => 'charset', 'phrase' => 'promo: hoy'],
            ['rule' => 'S-041', 'reason' => 'charset', 'phrase' => 'di "gratis"'],
            ['rule' => 'S-041', 'reason' => 'charset', 'phrase' => 'hola@ironbody.co'],
        ], $f['problems']);
        $this->assertSame(['S-040', 'S-041'], $f['rules']);
        $this->assertSame(12, $f['max']);
    }

    public function test_mas_de_doce_frases_entre_varias_reglas_es_too_many(): void
    {
        $diez = array_map(static fn (int $i): string => sprintf('frase %02d', $i), range(1, 10));
        $content = self::contenido([], [self::regla('S-050', $diez), self::regla('S-051', ['frase 01', 'otra', 'y otra', 'una más'])]);

        $f = StudioPromptBundle::phrases($content);
        $this->assertCount(13, $f['list'], 'las repetidas entre reglas cuentan una vez');
        $this->assertSame([['rule' => null, 'reason' => 'too_many', 'phrase' => null]], $f['problems']);

        $doce = self::contenido([], [self::regla('S-050', $diez), self::regla('S-051', ['frase 01', 'otra', 'y otra'])]);
        $this->assertSame([], StudioPromptBundle::phrases($doce)['problems']);
        $this->assertCount(12, StudioPromptBundle::compile(StudioPromptBundle::governedFrom($doce))['governed'][UltronPromptCompiler::PHRASES]);
    }

    // ── 3 · La clasificación y la declaración ───────────────────────────────

    /** @return array<string,mixed> */
    private static function clasifica(array $content, bool $conPrompts, array $promptRules): array
    {
        $lecturas = [];
        foreach (array_keys(D::MUST_MATCH) as $id) {
            [$capa, $clave] = explode('.', $id, 2);
            $lecturas[$id] = ['available' => true, 'value' => $content['configs'][$capa][$clave]];
        }

        return D::classify($content['configs'], $content['rules'], StudioCatalog::baseline()['configs'], $lecturas, StudioCatalog::toArray(), $conPrompts, $promptRules);
    }

    public function test_con_la_llave_las_s_nnn_con_frases_pasan_a_prompt_y_las_demas_siguen_en_no_effect(): void
    {
        $content = self::contenido([], self::variadas());
        $reglas = StudioPromptBundle::phrases($content)['rules'];

        $con = self::clasifica($content, true, $reglas);
        $this->assertSame(['rule:S-001', 'rule:S-002', 'rule:S-005', 'rule:S-006'], $con['prompt']);
        $this->assertSame(['rule:S-003', 'rule:S-004', 'rule:S-009'], $con['noEffect'], 'el «si» falso, la archivada y la de requerir');
        $this->assertSame(['rule:S-003', 'rule:S-004', 'rule:S-009'], D::requiredAcknowledgements($con));

        // Sin la llave, todas a `noEffect` (la de siempre, C-1) y sin la sexta lista.
        $sin = self::clasifica($content, false, $reglas);
        $this->assertArrayNotHasKey('prompt', $sin);
        $this->assertSame(['rule:S-001', 'rule:S-002', 'rule:S-003', 'rule:S-004', 'rule:S-005', 'rule:S-006', 'rule:S-009'], $sin['noEffect']);
        $this->assertSame($sin, self::clasifica($content, false, []));
    }

    public function test_prompt_rules_solo_nombra_reglas_s_nnn_de_la_version(): void
    {
        $content = self::contenido([], self::variadas());

        foreach ([['S-999'], ['R-01'], [7]] as $malas) {
            try {
                self::clasifica($content, true, $malas);
                $this->fail('clasificó con '.json_encode($malas));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private static function parte(array $governed, int $compiler): array
    {
        $p = D::part('6f1c2a9e-1d2b-4c3d-8e4f-5a6b7c8d9e0f', str_repeat('ab', 32), '2026-10-19.1', 12, 7, ['governance.canaryOnly' => true, 'governance.enabled' => true, 'intent.driver' => 'entorno', 'rules.paymentLinksEnabled' => false]);

        return D::partV2($p, $governed, ['base' => 'n8n-29de5119', 'bundleHash' => str_repeat('c', 64), 'compiler' => $compiler]);
    }

    public function test_la_parte_v2_del_compilador_2_lleva_la_quinta_clave_y_se_lee_igual(): void
    {
        $g = ['persona.emojis' => true, 'persona.maxChars' => 200, 'persona.tone' => 'profesional', 'rules.forbiddenPhrases' => ['gratis', '¿qué esperas?'], 'strategy.goal' => 'cita'];
        $parte = self::parte(array_reverse($g, true), 2);
        $this->assertSame(['persona.emojis', 'persona.maxChars', 'persona.tone', 'rules.forbiddenPhrases', 'strategy.goal'], array_keys($parte['governed']), 'por orden de bytes');

        $json = D::effectiveJson('0b7e8f10-2c3d-4e5f-9a0b-1c2d3e4f5a6b', 1, new DateTimeImmutable('2026-11-12T15:04:05Z'), $parte, null, D::CONTRACT_V2);
        $this->assertStringContainsString('"rules.forbiddenPhrases":["gratis","¿qué esperas?"]', $json, 'la lista en su orden y sin escapar');
        $this->assertSame($parte, D::entry($json, '0b7e8f10-2c3d-4e5f-9a0b-1c2d3e4f5a6b', 1)['canary']);

        // Sin frases, la lista vacía sigue siendo una lista para el lector del motor.
        $vacia = self::parte(array_replace($g, ['rules.forbiddenPhrases' => []]), 2);
        $this->assertStringContainsString('"rules.forbiddenPhrases":[]', D::effectiveJson('0b7e8f10-2c3d-4e5f-9a0b-1c2d3e4f5a6b', 1, new DateTimeImmutable('2026-11-12T15:04:05Z'), $vacia, null, D::CONTRACT_V2));
    }

    /** @return iterable<string,array{\Closure(): mixed}> */
    public static function partesQueNoSeEscriben(): iterable
    {
        $cuatro = ['persona.emojis' => true, 'persona.maxChars' => 200, 'persona.tone' => 'profesional', 'strategy.goal' => 'cita'];
        $cinco = $cuatro + ['rules.forbiddenPhrases' => ['gratis']];
        yield 'compilador 2 con cuatro claves' => [fn () => self::parte($cuatro, 2)];
        yield 'compilador 1 con cinco claves' => [fn () => self::parte($cinco, 1)];
        yield 'frases como objeto' => [fn () => self::parte(array_replace($cinco, ['rules.forbiddenPhrases' => ['a' => 'gratis']]), 2)];
        yield 'frases como texto' => [fn () => self::parte(array_replace($cinco, ['rules.forbiddenPhrases' => 'gratis']), 2)];
        yield 'un número en las frases' => [fn () => self::parte(array_replace($cinco, ['rules.forbiddenPhrases' => ['gratis', 3]]), 2)];
    }

    #[DataProvider('partesQueNoSeEscriben')]
    public function test_una_quinta_clave_fuera_de_forma_no_se_escribe(\Closure $parte): void
    {
        $this->expectException(InvalidArgumentException::class);
        $parte();
    }

    public function test_la_parte_del_compilador_1_sigue_siendo_valida_para_arrastrarla(): void
    {
        $cuatro = ['persona.emojis' => true, 'persona.maxChars' => 200, 'persona.tone' => 'profesional', 'strategy.goal' => 'cita'];
        $parte = self::parte($cuatro, 1);

        $json = D::effectiveJson('0b7e8f10-2c3d-4e5f-9a0b-1c2d3e4f5a6b', 2, new DateTimeImmutable('2026-11-12T15:04:05Z'), self::parte($cuatro + ['rules.forbiddenPhrases' => []], 2), $parte, D::CONTRACT_V2);
        $this->assertSame($parte, D::entry($json, '0b7e8f10-2c3d-4e5f-9a0b-1c2d3e4f5a6b', 2)['rest']);
    }

    public function test_el_studio_y_el_lector_del_motor_dicen_lo_mismo_de_la_quinta_clave(): void
    {
        $this->assertSame(D::GOVERNED_PHRASES, (new ReflectionClassConstant(UltronPublishedConfig::class, 'GOVERNED_PHRASES'))->getValue());
        $this->assertSame(D::PHRASES_SINCE_COMPILER, (new ReflectionClassConstant(UltronPublishedConfig::class, 'PHRASES_SINCE_COMPILER'))->getValue());
        $this->assertSame(D::GOVERNED, (new ReflectionClassConstant(UltronPublishedConfig::class, 'GOVERNED'))->getValue());
        $this->assertSame(UltronPromptCompiler::PHRASES, D::GOVERNED_PHRASES);
    }
}
