<?php

namespace Tests\Feature\UltronStudio;

use App\Models\Admin;
use App\Models\UltronStudio\StudioPublication;
use App\Models\UltronStudio\StudioRule;
use App\Models\UltronStudio\StudioVersion;
use App\Services\Marketing\Ultron\Prompts\UltronPromptBase;
use App\Services\Marketing\Ultron\Prompts\UltronPromptCompiler;
use App\Services\Marketing\Ultron\Prompts\UltronPromptOffer;
use App\Services\Marketing\Ultron\UltronPublishedConfig;
use App\Services\UltronStudio\StudioPromptBundle;
use App\Services\UltronStudio\StudioPublicationDeclaration as D;
use App\Services\UltronStudio\StudioPublications;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * LAS REGLAS S-NNN EN WHATSAPP, POR HTTP (compilador de prompts 2; opción A de
 * `plans/cierre/auditoria-a-studio-whatsapp.md` §3.2).
 *
 * Reglas creadas por la API, copiadas en una versión aprobada por otra
 * persona, y:
 *  - con la llave de prompts en `canary`: la vista previa enseña las frases que
 *    entran (bloque `phrases` y el cambio `rules.forbiddenPhrases` con su texto
 *    exacto), las S-NNN que las aportan dejan de pedir reconocimiento (clase
 *    `prompt`) y las demás lo siguen pidiendo; publicar firma `/2` con la
 *    quinta clave y el LECTOR y la OFERTA REALES del motor la ofrecen a la
 *    canaria con la huella de la vista previa;
 *  - con la llave en `off`: lo de siempre, todas las S-NNN sin efecto;
 *  - frases que no caben (juego cerrado, más de 12): la vista previa lo dice
 *    con `prompts_rule_phrases_invalid` y el detalle, y publicar es 409 sin
 *    escribir nada;
 *  - el ORDEN DE DESPLIEGUE: lo publicado con el compilador 1 cae a Legacy
 *    (`compiler_changed`) hasta revertirlo y publicar otra versión, y desde el
 *    compilador 3 (Enmienda A4-1) también lo publicado con el 2;
 *  - LA PARTE DEL RESTO QUE SE ARRASTRA (vuelta 2: B-1 de seguridad y
 *    hallazgo 1 del reviewer): una V0 publicada y promovida por la API con la
 *    llave apagada (cabeza `/1`) se compila ENTERA, frases de sus reglas
 *    incluidas, al publicar otra versión con la llave encendida. La vista
 *    previa enseña esas frases (`carried.phrases`); si no caben, bloquea con
 *    `prompts_rule_phrases_invalid`, `carried.problems` y un 409 con `part:
 *    carried` que da la salida («Volver a Legacy»); y, si caben, al revertir la
 *    versión publicada la canaria recibe las frases de V0 (el comportamiento
 *    que pidió documentar `security`).
 */
class UltronStudioPublicationRulePhrasesTest extends TestCase
{
    use PublicationFixtures;
    use RefreshDatabase;
    use StudioFixtures;

    /** «profesional + 200» con el compilador 3 (compile-cases.json, c074). */
    private const G8_HASH = 'af9e0d23de42368f1cda211b03dc3164ce1a341acc7e6f22abf3d71afbdb4d51';

    /** La misma con los compiladores 1 y 2 (la que quedó firmada en la cadena antes del 3). */
    private const G8_HASH_COMPILADORES_1_Y_2 = '702890f7d86a27fef027abd672a99903e64b6661c2d8f8a72a45ddea3d6d311c';

    /** Enmienda A4-1: lo que el mensaje del motor dice de la longitud, igual en `canary` y en `all`. */
    private const LONGITUD_MEDIDA = 'La longitud, además, se mide antes de enviar: a un mensaje que pasa del tope se le quita la pregunta final y, si aun así no cabe, se sustituye por un texto que cabe (con el tope de la línea base, 320, no se mide). «Si no entiende» sigue sin efecto en WhatsApp.';

    private const COMPOSER = 'FRASES PROHIBIDAS PUBLICADAS: nunca escribas ninguna de estas frases, tampoco con otras mayusculas; si la respuesta parece necesitar una, dilo con otras palabras. Las frases: ';

    private const CRITIC = 'FRASES PROHIBIDAS PUBLICADAS: si el borrador contiene alguna de estas frases, aunque cambien las mayusculas, verdict fail con naturalness. Las frases: ';

    private Admin $ana;

    private Admin $root;

    private Admin $pub;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->importBaseline();
        $this->openKeys();
        $this->ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana Pérez');
        $this->root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root');
        $this->pub = $this->personWith(['ultron.view', 'ultron.publish', 'ultron.rollback'], 'Pablo Publica');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function promptsOn(): void
    {
        config(['marketing.ultron.studio_prompts' => 'canary']);
    }

    /** @param list<string> $frases */
    private function reglaQueProhibe(array $frases, array $when = []): string
    {
        return (string) $this->newStudioRule($this->ana, ['layers' => ['persona'], 'conditions' => self::conditionsOf(
            [['kind' => 'forbid', 'target' => 'text', 'op' => 'contains', 'phrases' => $frases]],
            $when,
        )])->assertCreated()->json('data.code');
    }

    private static function cuandoTono(string $tono): array
    {
        return [['target' => 'config', 'layer' => 'persona', 'key' => 'tone', 'op' => 'eq', 'value' => $tono]];
    }

    /** Cuatro reglas: dos aportan frases a G8 (tono profesional) y dos no. @return array{0:string,1:string,2:string,3:string} */
    private function cuatroReglas(): array
    {
        return [
            $this->reglaQueProhibe(['te lo regalo', 'gratis']),
            $this->reglaQueProhibe(['Oiga'], self::cuandoTono('profesional')),
            $this->reglaQueProhibe(['parcero'], self::cuandoTono('entusiasta')),
            (string) $this->newStudioRule($this->ana, ['layers' => ['persona'], 'conditions' => self::conditionsOf([self::requireEq('persona', 'emojis', true)])])->assertCreated()->json('data.code'),
        ];
    }

    private function g8(string $label = 'Profesional, corto y sin regalos'): StudioVersion
    {
        return $this->approvedVersion($this->ana, $this->root, $label, ['persona' => ['tone' => 'profesional', 'maxChars' => 200]]);
    }

    /** @return array{contract:string,canary:array<string,mixed>|null,rest:array<string,mixed>|null,column:string} */
    private function headEntry(): array
    {
        $c = $this->chainHead();

        return D::entry((string) $c->effective_json, (string) $c->uuid, (int) $c->seq) + ['column' => $c->engine_contract];
    }

    /** La oferta real de `decide` para la canaria. */
    private function decide(): array
    {
        return app(UltronPromptOffer::class)->forConversation(self::CANARY_ID)['response'];
    }

    private function decideOffer(): array
    {
        $r = $this->decide();

        return ['mode' => $r['mode'], 'version' => $r['version'], 'bundleHash' => $r['bundle_hash'], 'reason' => $r['reason']];
    }

    private function statusCurrent(): array
    {
        return $this->studio('GET', 'status', $this->pub)->assertOk()->json('data.engine.prompts.current');
    }

    // ── Con la llave encendida ──────────────────────────────────────────────

    public function test_con_la_llave_las_frases_se_ensenan_se_firman_y_el_motor_las_ofrece(): void
    {
        [$siempre, $conTono, $otroTono, $sinFrases] = $this->cuatroReglas();
        $this->promptsOn();
        $v = $this->g8();

        $vista = $this->previewOf($this->pub, $v->uuid);
        $this->assertTrue($vista['publishable'], json_encode($vista['blocking']));
        $this->assertSame('prompts', $vista['effect']);
        $esperadas = ['gratis', 'oiga', 'te lo regalo'];
        $reglas = [$siempre, $conTono];
        sort($reglas, SORT_STRING);
        $this->assertSame(['list' => $esperadas, 'rules' => $reglas, 'max' => 12, 'problems' => []], $vista['prompts']['phrases']);
        $sinEfecto = ['rule:'.$otroTono, 'rule:'.$sinFrases];
        sort($sinEfecto, SORT_STRING);
        $this->assertSame($sinEfecto, $vista['noEffect'], 'las S-NNN sin frases que entren se siguen reconociendo');
        $this->assertSame(['persona.tone', 'persona.maxChars'], array_map(static fn (array $p): string => $p['layer'].'.'.$p['key'], array_values(array_filter($vista['params'], static fn (array $p): bool => $p['class'] === 'prompt'))), 'las reglas no son filas de parámetros');

        // El cambio de las frases, el último, con su texto exacto en los cuatro roles.
        $p = $vista['prompts'];
        $this->assertSame(['persona.tone', 'persona.maxChars', 'rules.forbiddenPhrases'], array_column($p['changes'], 'param'));
        $frases = $p['changes'][2];
        $this->assertSame($esperadas, $frases['value']);
        $this->assertSame(['composer', 'critic', 'composer_retry', 'critic_retry'], $frases['roles']);
        $lista = '"gratis", "oiga", "te lo regalo".';
        $this->assertSame([
            ['role' => 'composer', 'operation' => 'insert_after_line', 'anchor' => 'FORMA:', 'text' => self::COMPOSER.$lista],
            ['role' => 'composer_retry', 'operation' => 'insert_after_line', 'anchor' => 'FORMA:', 'text' => self::COMPOSER.$lista],
            ['role' => 'critic', 'operation' => 'insert_after_line', 'anchor' => 'naturalness:', 'text' => self::CRITIC.$lista],
            ['role' => 'critic_retry', 'operation' => 'insert_after_line', 'anchor' => 'naturalness:', 'text' => self::CRITIC.$lista],
        ], $frases['patches']);
        $huella = UltronPromptCompiler::compile(array_replace(UltronPromptCompiler::BASELINE, ['persona.tone' => 'profesional', 'persona.maxChars' => 200, UltronPromptCompiler::PHRASES => $esperadas]))['bundleHash'];
        $this->assertSame([$huella, 3, null], [$p['bundleHash'], $p['compiler'], $p['error']]);
        $this->assertNotSame(self::G8_HASH, $huella);

        // Reconocer las reglas que ya entran en los prompts no vale.
        $this->studio('POST', "versions/{$v->uuid}/publish", $this->pub, [
            'clientRequestId' => (string) Str::uuid(),
            'previewHash' => $vista['previewHash'],
            'acknowledgedNoEffect' => [...$vista['noEffect'], 'rule:'.$siempre],
        ])->assertStatus(409)->assertJsonPath('code', 'no_effect_not_acknowledged');

        $this->publishAs($this->pub, $v->uuid)->assertCreated();

        // Lo firmado: `/2`, compilador 3, la quinta clave con las frases.
        $cabeza = $this->headEntry();
        $this->assertSame([D::CONTRACT_V2, D::CONTRACT_V2], [$cabeza['column'], $cabeza['contract']]);
        $this->assertSame(['persona.emojis' => true, 'persona.maxChars' => 200, 'persona.tone' => 'profesional', 'rules.forbiddenPhrases' => $esperadas, 'strategy.goal' => 'cita'], $cabeza['canary']['governed']);
        $this->assertSame(['base' => 'n8n-29de5119', 'bundleHash' => $huella, 'compiler' => 3], $cabeza['canary']['prompts']);

        // Escritor → lector → oferta de `decide`: la huella de la vista previa y las frases en los prompts.
        $this->assertSame(['applied', $v->uuid], [$this->engineStamp(self::CANARY_ID)['state'], $this->engineStamp(self::CANARY_ID)['version']]);
        $this->assertSame($esperadas, app(UltronPublishedConfig::class)->partFor(self::CANARY_ID)['governed']['rules.forbiddenPhrases']);
        $o = $this->decide();
        $this->assertSame(['studio', $huella], [$o['mode'], $o['bundle_hash']]);
        $this->assertStringContainsString("\n".self::COMPOSER.$lista."\n", $o['roles']['composer']);
        $this->assertStringContainsString("\n".self::CRITIC.$lista."\n", $o['roles']['critic']);
        $this->assertSame($this->decideOffer(), $this->statusCurrent());
        $this->assertSame(StudioPublications::ENGINE_MESSAGES_PROMPTS['canary'], $this->studio('GET', 'status', $this->pub)->json('data.engine.message'));
        $this->assertStringContainsString('reglas S-NNN', StudioPublications::ENGINE_MESSAGES_PROMPTS['canary']);
        foreach (['canary', 'all'] as $alcance) {
            $this->assertStringEndsWith(' '.self::LONGITUD_MEDIDA, StudioPublications::ENGINE_MESSAGES_PROMPTS[$alcance], "{$alcance}: la longitud medida, justo antes de «Si no entiende»");
            $this->assertSame(1, substr_count(StudioPublications::ENGINE_MESSAGES_PROMPTS[$alcance], 'se mide antes de enviar'), $alcance);
        }

        // Revertir: la canaria vuelve a Legacy.
        $this->rollbackAs($this->pub, $v->uuid)->assertCreated();
        $this->assertSame(['mode' => 'legacy', 'version' => null, 'bundleHash' => null, 'reason' => 'part_legacy'], $this->decideOffer());
    }

    public function test_dos_versiones_con_otras_frases_tienen_otra_huella_de_vista_previa(): void
    {
        $this->promptsOn();
        $sin = $this->g8('Sin reglas');
        $vistaSin = $this->previewOf($this->pub, $sin->uuid);
        $this->assertSame(self::G8_HASH, $vistaSin['prompts']['bundleHash'], 'sin reglas, la huella de siempre');

        $this->reglaQueProhibe(['gratis']);
        $con = $this->g8('Con una regla');
        $vistaCon = $this->previewOf($this->pub, $con->uuid);
        $this->assertNotSame(self::G8_HASH, $vistaCon['prompts']['bundleHash']);
        $this->assertSame(['gratis'], $vistaCon['prompts']['phrases']['list']);
        $this->assertSame([], $vistaCon['noEffect']);
    }

    // ── Con la llave apagada ────────────────────────────────────────────────

    public function test_con_la_llave_apagada_las_reglas_siguen_sin_efecto(): void
    {
        $codigos = $this->cuatroReglas();
        $v = $this->g8();

        $vista = $this->previewOf($this->pub, $v->uuid);
        $this->assertSame('declaration', $vista['effect']);
        $this->assertNull($vista['prompts']);
        $todas = array_map(static fn (string $c): string => 'rule:'.$c, $codigos);
        sort($todas, SORT_STRING);
        $this->assertSame(['persona.tone', 'persona.maxChars', ...$todas], $vista['noEffect']);

        $this->publishAs($this->pub, $v->uuid)->assertCreated();
        $cabeza = $this->headEntry();
        $this->assertSame(D::CONTRACT, $cabeza['column']);
        $this->assertFalse(D::isPartV2($cabeza['canary']));
    }

    // ── Lo que no cabe no se publica ────────────────────────────────────────

    public function test_unas_frases_que_no_caben_bloquean_con_su_codigo_y_no_se_escribe_nada(): void
    {
        $mala = $this->reglaQueProhibe(['di "gratis"', 'promo: hoy']);
        $buena = $this->reglaQueProhibe(['oiga']);
        $this->promptsOn();
        $v = $this->g8();

        $vista = $this->previewOf($this->pub, $v->uuid);
        $this->assertFalse($vista['publishable']);
        $this->assertSame([StudioPromptBundle::CODE_PHRASES_INVALID], $vista['blocking']);
        $this->assertSame(['bundleHash' => null, 'changes' => [], 'error' => 'prompts_rule_phrases_invalid'], array_intersect_key($vista['prompts'], array_flip(['error', 'bundleHash', 'changes'])));
        $problemas = [
            ['rule' => $mala, 'reason' => 'charset', 'phrase' => 'di "gratis"'],
            ['rule' => $mala, 'reason' => 'charset', 'phrase' => 'promo: hoy'],
        ];
        $this->assertSame($problemas, $vista['prompts']['phrases']['problems']);
        $reglas = [$mala, $buena];
        sort($reglas, SORT_STRING);
        $this->assertSame($reglas, $vista['prompts']['phrases']['rules']);

        $this->publishAs($this->pub, $v->uuid)->assertStatus(409)
            ->assertJsonPath('code', 'prompts_rule_phrases_invalid')
            ->assertJsonPath('problems', $problemas)
            ->assertJsonPath('part', 'version');
        $this->assertNull($vista['prompts']['carried'], 'sin cabeza no se arrastra nada');
        // Es una puerta, en su sitio: con una huella vieja dice lo mismo (va antes de `preview_stale`).
        $this->publishAs($this->pub, $v->uuid, null, ['previewHash' => str_repeat('f', 64)])->assertStatus(409)->assertJsonPath('code', 'prompts_rule_phrases_invalid');
        $this->assertNull($this->chainHead());
        $this->assertSame('APPROVED', $v->fresh()->status);

        // Con la llave apagada es solo una declaración: se publica como siempre.
        config(['marketing.ultron.studio_prompts' => 'off']);
        $this->publishAs($this->pub, $v->uuid)->assertCreated();
        $this->assertSame(D::CONTRACT, $this->chainHead()->engine_contract);
    }

    public function test_mas_de_doce_frases_no_se_recortan(): void
    {
        $this->reglaQueProhibe(array_map(static fn (int $i): string => sprintf('frase %02d', $i), range(1, 10)));
        $this->reglaQueProhibe(['otra', 'y otra', 'una más']);
        $this->promptsOn();
        $v = $this->g8();

        $vista = $this->previewOf($this->pub, $v->uuid);
        $this->assertSame([StudioPromptBundle::CODE_PHRASES_INVALID], $vista['blocking']);
        $this->assertCount(13, $vista['prompts']['phrases']['list']);
        $this->assertSame([['rule' => null, 'reason' => 'too_many', 'phrase' => null]], $vista['prompts']['phrases']['problems']);
        $this->publishAs($this->pub, $v->uuid)->assertStatus(409)->assertJsonPath('code', 'prompts_rule_phrases_invalid');
        $this->assertSame(0, StudioPublication::query()->count());
    }

    // ── La parte del resto que se arrastra (vuelta 2: B-1 y reviewer 1) ────

    public function test_una_parte_del_resto_v1_cuyas_frases_no_caben_bloquea_con_su_codigo_y_part_carried(): void
    {
        // V0 copia una S-NNN con «100% gratis» (G la admite; el juego cerrado de
        // WhatsApp no) y se publica y promueve con la llave de prompts en `off`.
        $regla = $this->reglaQueProhibe(['100% gratis']);
        $v0 = $this->publicadaYPromovidaSinLaLlave('V0 con cien por cien gratis');
        $this->archivar($regla);
        $antes = $this->chainHead();

        // Se enciende la llave; V1 no trae reglas (la S-NNN ya está archivada) y compila sola.
        $this->promptsOn();
        $v1 = $this->g8('V1 sin reglas');
        $vista = $this->previewOf($this->pub, $v1->uuid);
        $this->assertFalse($vista['publishable']);
        $this->assertSame([StudioPromptBundle::CODE_PHRASES_INVALID], $vista['blocking'], 'el código propio, no el genérico');
        $p = $vista['prompts'];
        $this->assertSame(['prompts_rule_phrases_invalid', self::G8_HASH], [$p['error'], $p['bundleHash']], 'la versión que se publica compila');
        $this->assertSame(['list' => [], 'rules' => [], 'max' => 12, 'problems' => []], $p['phrases'], 'las frases de V1: ninguna');
        $problemas = [['rule' => $regla, 'reason' => 'charset', 'phrase' => '100% gratis']];
        $this->assertSame([
            'versionUuid' => $v0->uuid,
            'label' => 'V0 con cien por cien gratis',
            'bundleHash' => null,
            'shortHash' => null,
            'baseline' => null,
            'phrases' => ['100% gratis'],
            'problems' => $problemas,
        ], $p['carried'], 'la vista previa dice que la causa es la versión arrastrada');

        // Publicar: 409 con el código nuevo, `part: carried` y los problemas de V0.
        $r = $this->publishAs($this->pub, $v1->uuid)->assertStatus(409)
            ->assertJsonPath('code', 'prompts_rule_phrases_invalid')
            ->assertJsonPath('part', 'carried')
            ->assertJsonPath('problems', $problemas);
        $this->assertStringContainsString('la causa no está en esta versión: está en la versión que hoy rige el resto de las conversaciones', $r->json('message'));
        $this->assertStringContainsString('La salida: «Volver a Legacy» antes de publicar; si quieres conservar esas reglas, corrígelas y publica otra versión.', $r->json('message'));
        $this->assertStringContainsString('de 3 a 60 caracteres, con al menos una letra o una cifra', $r->json('message'));
        $this->assertSame($antes->uuid, $this->chainHead()->uuid, 'no se escribe nada');
        $this->assertSame(['APPROVED', 'PUBLISHED'], [$v1->fresh()->status, $v0->fresh()->status]);

        // La salida que da el mensaje: «Volver a Legacy» y publicar.
        $this->legacyAs($this->pub)->assertCreated();
        $vista = $this->previewOf($this->pub, $v1->uuid);
        $this->assertTrue($vista['publishable'], json_encode($vista['blocking']));
        $this->assertNull($vista['prompts']['carried']);
        $this->publishAs($this->pub, $v1->uuid)->assertCreated();
        $this->assertSame(['mode' => 'studio', 'version' => $v1->uuid, 'bundleHash' => self::G8_HASH, 'reason' => null], $this->decideOffer());
    }

    public function test_la_vista_previa_ensena_las_frases_de_la_parte_que_se_arrastra(): void
    {
        $regla = $this->reglaQueProhibe(['te lo regalo', 'gratis']);
        $v0 = $this->publicadaYPromovidaSinLaLlave('V0 con dos frases');
        $this->archivar($regla);

        $this->promptsOn();
        $v1 = $this->g8('V1 sin reglas');
        $vista = $this->previewOf($this->pub, $v1->uuid);
        $this->assertTrue($vista['publishable'], json_encode($vista['blocking']));
        $this->assertSame([[], null], [$vista['prompts']['phrases']['list'], $vista['prompts']['error']]);

        // La huella de lo arrastrado es la de la línea base CON esas dos frases
        // (el ejemplo del informe): las frases entran en `bundleHash` y, con
        // ella, en `previewHash`.
        $conFrases = UltronPromptCompiler::compile(array_replace(UltronPromptCompiler::BASELINE, [UltronPromptCompiler::PHRASES => ['gratis', 'te lo regalo']]))['bundleHash'];
        $this->assertSame('fbd3c91cb9f283519fe7d2ac4daf60323d1151e2599a9cbee39195090e4d8916', $conFrases);
        $this->assertNotSame(UltronPromptBase::LEGACY_BUNDLE_HASH, $conFrases);
        $this->assertSame([
            'versionUuid' => $v0->uuid,
            'label' => 'V0 con dos frases',
            'bundleHash' => $conFrases,
            'shortHash' => substr($conFrases, 0, 8),
            'baseline' => false,
            'phrases' => ['gratis', 'te lo regalo'],
        ], $vista['prompts']['carried'], 'sin `problems` cuando compila');
    }

    public function test_publicar_con_la_llave_y_revertir_lleva_a_la_canaria_las_frases_de_la_parte_arrastrada(): void
    {
        // El caso que pidió `security` (B-1): resto `/1` con frases válidas →
        // publicar W con la llave encendida → revertir W → la canaria recibe
        // las frases de V0. Es el comportamiento acordado: se arrastra entera
        // (nada a medias) y la vista previa de W lo enseñó en `carried.phrases`.
        $regla = $this->reglaQueProhibe(['te lo regalo', 'gratis']);
        $v0 = $this->publicadaYPromovidaSinLaLlave('V0 con dos frases');
        $this->archivar($regla);
        $this->promptsOn();
        $this->assertSame(['mode' => 'legacy', 'version' => null, 'bundleHash' => null, 'reason' => 'declaration_v1'], $this->decideOffer(), 'antes, la cabeza `/1` no da prompts a nadie');

        $w = $this->g8('W sin reglas');
        $this->assertSame(['gratis', 'te lo regalo'], $this->previewOf($this->pub, $w->uuid)['prompts']['carried']['phrases']);
        $this->publishAs($this->pub, $w->uuid)->assertCreated();
        $conFrases = UltronPromptCompiler::compile(array_replace(UltronPromptCompiler::BASELINE, [UltronPromptCompiler::PHRASES => ['gratis', 'te lo regalo']]))['bundleHash'];
        $cabeza = $this->headEntry();
        $this->assertSame(D::CONTRACT_V2, $cabeza['column']);
        $this->assertSame([$v0->uuid, ['gratis', 'te lo regalo'], $conFrases], [$cabeza['rest']['version'], $cabeza['rest']['governed']['rules.forbiddenPhrases'], $cabeza['rest']['prompts']['bundleHash']]);
        $this->assertSame(['mode' => 'studio', 'version' => $w->uuid, 'bundleHash' => self::G8_HASH, 'reason' => null], $this->decideOffer(), 'mientras W está en canario, la canaria tiene los prompts de W');

        // Revertir W: la canaria pasa a la parte del resto, ya `/2` con las frases de V0.
        $this->rollbackAs($this->pub, $w->uuid)->assertCreated();
        $cabeza = $this->headEntry();
        $this->assertSame(D::CONTRACT_V2, $cabeza['column']);
        $this->assertSame($cabeza['canary'], $cabeza['rest']);
        $this->assertSame(['mode' => 'studio', 'version' => $v0->uuid, 'bundleHash' => $conFrases, 'reason' => null], $this->decideOffer());
        $o = $this->decide();
        $lista = '"gratis", "te lo regalo".';
        $this->assertStringContainsString("\n".self::COMPOSER.$lista."\n", $o['roles']['composer']);
        $this->assertStringContainsString("\n".self::COMPOSER.$lista."\n", $o['roles']['composer_retry']);
        $this->assertStringContainsString("\n".self::CRITIC.$lista."\n", $o['roles']['critic']);
        $this->assertStringContainsString("\n".self::CRITIC.$lista."\n", $o['roles']['critic_retry']);
        $this->assertSame(['gratis', 'te lo regalo'], app(UltronPublishedConfig::class)->partFor(self::CANARY_ID)['governed']['rules.forbiddenPhrases']);
    }

    public function test_si_la_cabeza_se_mueve_dentro_de_la_transaccion_a_un_resto_cuyas_frases_no_caben_es_409_carried(): void
    {
        // La vista previa y las puertas ven una cadena vacía (nada que arrastrar);
        // justo antes de que la transacción relea la cabeza, «otra petición»
        // deja en el resto una versión `/1` con «100% gratis». No se firma la
        // entrada sin esa parte ni con ella a medias: 409 con `part: carried`.
        $v = $this->g8('V sin reglas');
        $regla = $this->reglaQueProhibe(['100% gratis']);
        $mala = $this->approvedVersion($this->ana, $this->root, 'La del resto con cien por cien gratis');
        $this->promptsOn();
        $vista = $this->previewOf($this->pub, $v->uuid);
        $this->assertTrue($vista['publishable'], json_encode($vista['blocking']));
        $this->assertNull($vista['prompts']['carried']);

        $this->beforeNthHeadRead(2, function () use ($mala): void {
            $this->assertTrue(StudioVersion::transition($mala->id, ['APPROVED'], ['status' => 'PUBLISHED', 'status_changed_at' => now()]));
            $this->seedEntry(D::ACTION_PROMOTE, $mala, $this->partOf($mala), $this->partOf($mala));
        });
        $this->studio('POST', "versions/{$v->uuid}/publish", $this->pub, [
            'clientRequestId' => (string) Str::uuid(),
            'previewHash' => $vista['previewHash'],
            'acknowledgedNoEffect' => $vista['noEffect'],
        ])->assertStatus(409)
            ->assertJsonPath('code', 'prompts_rule_phrases_invalid')
            ->assertJsonPath('part', 'carried')
            ->assertJsonPath('problems', [['rule' => $regla, 'reason' => 'charset', 'phrase' => '100% gratis']]);
        // La transacción se deshizo entera, también lo sembrado dentro de ella: eso
        // prueba que el rechazo salió de la transacción y no de las puertas.
        $this->assertNull($this->chainHead());
        $this->assertSame(['APPROVED', 'APPROVED'], [$v->fresh()->status, $mala->fresh()->status]);
    }

    // ── El orden de despliegue (requisito 6) ────────────────────────────────

    /** @return array<string, array{int, array<string,mixed>}> */
    public static function compiladoresAnteriores(): array
    {
        return [
            'compilador 1 (los cuatro gobernados)' => [1, ['persona.emojis' => true, 'persona.maxChars' => 200, 'persona.tone' => 'profesional', 'strategy.goal' => 'cita']],
            'compilador 2 (los cinco, sin frases)' => [2, ['persona.emojis' => true, 'persona.maxChars' => 200, 'persona.tone' => 'profesional', 'rules.forbiddenPhrases' => [], 'strategy.goal' => 'cita']],
        ];
    }

    /** @param array<string,mixed> $gobernados */
    #[DataProvider('compiladoresAnteriores')]
    public function test_lo_publicado_con_un_compilador_anterior_cae_a_legacy_hasta_revertirlo_y_publicar_otra_version(int $compilador, array $gobernados): void
    {
        $this->promptsOn();
        // V1 en canario, publicada ANTES de subir el compilador: su parte `/2` lleva
        // los gobernados de entonces, aquel compilador y su huella G8 de entonces.
        $v1 = $this->seededVersion($this->ana, $this->root, $this->contentWith(['persona' => ['tone' => 'profesional', 'maxChars' => 200]]), 'CANARY');
        $parte = D::partV2($this->partOf($v1), $gobernados, ['base' => 'n8n-29de5119', 'bundleHash' => self::G8_HASH_COMPILADORES_1_Y_2, 'compiler' => $compilador]);
        $this->seedEntryV2(D::ACTION_PUBLISH, $v1, $parte, null);

        // El commit la sigue declarando; `decide` ya no la ofrece.
        $this->assertSame(['applied', $v1->uuid], [$this->engineStamp(self::CANARY_ID)['state'], $this->engineStamp(self::CANARY_ID)['version']]);
        $this->assertSame(['mode' => 'legacy', 'version' => null, 'bundleHash' => null, 'reason' => 'compiler_changed'], $this->decideOffer());
        $this->assertSame($this->decideOffer(), $this->statusCurrent(), 'el Studio dice lo mismo que decide');

        // Revertirla (ROLLED_BACK es terminal) y publicar una versión nueva con el mismo contenido.
        $this->rollbackAs($this->pub, $v1->uuid)->assertCreated();
        $this->assertSame('part_legacy', $this->decideOffer()['reason']);
        $v2 = $this->g8('La misma, publicada con el compilador vigente');
        $this->publishAs($this->pub, $v2->uuid)->assertCreated();
        $this->assertSame(['mode' => 'studio', 'version' => $v2->uuid, 'bundleHash' => self::G8_HASH, 'reason' => null], $this->decideOffer());
    }

    /**
     * Una versión (con las reglas vivas de ahora) publicada y PROMOVIDA por la
     * API con la llave de prompts en `off`: la cabeza queda `/1` {V0, V0}. Con
     * la llave del motor en `all` (promover la exige), 3 turnos sellados y 25 h
     * de canario.
     */
    private function publicadaYPromovidaSinLaLlave(string $label): StudioVersion
    {
        self::at('2026-11-03T15:00:00Z');
        $this->openKeys(['marketing.ultron.studio_config' => 'all', 'marketing.ultron.studio_prompts' => 'off']);
        $v = $this->approvedVersion($this->ana, $this->root, $label);
        $this->publishAs($this->pub, $v->uuid)->assertCreated();
        $this->stampTurns(3, $v->uuid, now()->addHour());
        self::at('2026-11-04T16:00:00Z');
        $this->freshSessions();
        $evidencia = $this->evidenceOf($this->pub, $v->uuid);
        $this->assertTrue($evidencia['passes'], json_encode($evidencia['failing']));
        $this->promoteAs($this->pub, $v->uuid, null, $evidencia['evidenceHash'])->assertCreated();

        $cabeza = $this->headEntry();
        $this->assertSame([D::CONTRACT, $v->uuid, $v->uuid], [$cabeza['column'], $cabeza['canary']['version'], $cabeza['rest']['version']]);
        $this->assertFalse(D::isPartV2($cabeza['rest']));
        $this->assertSame('PUBLISHED', $v->fresh()->status);

        return $v;
    }

    /** Archiva una regla por la API: las versiones que ya la copiaron la conservan; las nuevas, no. */
    private function archivar(string $codigo): void
    {
        $uuid = (string) StudioRule::query()->where('code', $codigo)->value('uuid');
        $this->studio('POST', "rules/{$uuid}/archive", $this->ana, ['baseRevision' => 1, 'reason' => 'Ya no rige en las versiones nuevas'])->assertOk();
    }

    /**
     * Ejecuta `$alAntes` UNA vez, justo antes de la `$n`-ésima lectura de la
     * cabeza de la cadena (la de `StudioPublications::head()`), en la misma
     * conexión. Al publicar con la llave encendida, la primera es la de las
     * puertas (`facts()`) y la segunda, la de la transacción.
     */
    private function beforeNthHeadRead(int $n, Closure $alAntes): void
    {
        $vistas = 0;
        $hecho = false;
        DB::connection()->beforeExecuting(function (string $query) use (&$vistas, &$hecho, $n, $alAntes): void {
            if ($hecho || ! str_contains($query, 'as effective_json from "ultron_studio_publications" order by "seq" desc limit 1') || ++$vistas < $n) {
                return;
            }
            $hecho = true;
            $alAntes();
        });
    }

    private static function at(string $iso): void
    {
        Carbon::setTestNow(Carbon::parse($iso)->utc());
        CarbonImmutable::setTestNow(CarbonImmutable::parse($iso)->utc());
    }

    /**
     * Como `seedEntry()`, en `/2`: una entrada sembrada y firmada con la firma
     * del motor.
     */
    private function seedEntryV2(string $action, ?StudioVersion $version, ?array $canary, ?array $rest): StudioPublication
    {
        $cabeza = StudioPublication::query()->orderByDesc('seq')->first();
        $seq = ($cabeza !== null ? (int) $cabeza->seq : 0) + 1;
        $uuid = strtolower((string) Str::uuid());
        $d = D::declaration($uuid, $seq, now(), $canary, $rest, UltronPublishedConfig::signatureFor(...), D::CONTRACT_V2);

        return StudioPublication::query()->forceCreate([
            'uuid' => $uuid,
            'seq' => $seq,
            'client_request_id' => (string) Str::uuid(),
            'action' => $action,
            'version_id' => $version?->id,
            'restores_publication_id' => null,
            'previous_publication_id' => $cabeza?->id,
            'engine_contract' => $d['contract'],
            'effective_json' => $d['effectiveJson'],
            'effective_hash' => $d['effectiveHash'],
            'signature' => $d['signature'],
            'catalog_version' => (string) ($version?->catalog_version ?? '2026-10-19.1'),
            'lab_gate' => ['mode' => 'waived'],
            'acknowledged' => null,
            'evidence' => null,
            'reason' => null,
            'actor_admin_id' => 1,
            'actor_name' => 'Siembra',
            'actor_role' => null,
            'created_at' => now(),
        ]);
    }
}
