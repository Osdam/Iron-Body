<?php

namespace Tests\Unit\UltronStudio;

use App\Models\MarketingKnowledgeItem;
use App\Models\Plan;
use App\Services\Marketing\Ultron\Prompts\UltronPromptCompiler as C;
use App\Services\Marketing\Ultron\UltronPlansOverview as O;
use App\Services\Marketing\Ultron\UltronRegenerationNotes as N;
use App\Services\Marketing\Ultron\UltronReplyFitter;
use App\Services\UltronStudio\StudioLabEngineCheck;
use App\Services\UltronStudio\StudioLabEngineContext;
use App\Services\UltronStudio\StudioPromptBundle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Tests\Unit\Marketing\Ultron\Prompts\PromptPublications;

/**
 * PT-17b · LA REGLA «TOPE DE LISTA» EN EL LABORATORIO FRENTE AL MOTOR, sin
 * modelo ni red (Enmienda A4-6 del H5; decisión B del propietario,
 * 23-11-2026). Sustituye a la prueba de la plantilla del diseño, que el
 * propietario descartó junto con la frase fija.
 *
 * El turno es el de E-001 a E-005 («¿Qué planes tienen y cuánto cuesta la
 * mensualidad?») con el catálogo de la prueba real (3 · Plan Valera, 4 · Plan
 * Mensual y 8 · Élite: 25 + 26 + 20 caracteres expandidos), y la comprobación,
 * la del servidor ({@see StudioLabEngineCheck::check()}, `ai/check`) y la del
 * commit ({@see StudioLabEngineCheck::predict()}): las MISMAS funciones del
 * motor, con la misma regla ({@see UltronReplyFitter::plansListCap()}).
 *
 *  L1. Los 24 terceros borradores reales de la medición de la v4
 *      (`tests/fixtures/ultron-studio/pt17b-terceros-borradores-v4.json`,
 *      todos ≤ 300): con el compilador 6 ninguno tiene `length_over_cap` ni
 *      en `ai/check` ni en el commit, ninguno se recorta, y los 23 que traen
 *      la lista se miden contra 320 (el que trae un solo marcador mide 200 y
 *      se mide contra el publicado). Con el sello del compilador 5, los 23 que
 *      pasan de 200 siguen fallando, como hoy.
 *  L2. Paridad con el commit: con tope 200, una lista de 310 con su pregunta
 *      sale del modelo entera; una de 330 no se recorta y es respaldo por la
 *      longitud, con la nota que nombra 320.
 *  L3. Alcance: en un turno SIN lista, el mismo texto no lleva tope de lista
 *      y sus marcadores son respaldo (`no_list`) aunque el revisor apruebe.
 *  L4. «profesional · 320», el tope de la línea base (bloqueante B1 de la
 *      revisión del 23-11-2026): `rolesFor()` no firma `sp.n`, como `decide`,
 *      y aun así la lista de 310 sale entera con tope 320 y la de 450 pide
 *      reescribir (nota de 320) y es respaldo sin recorte; sin lista, 450 no se
 *      mide (como hoy).
 */
class StudioLabEnginePlansListCapTest extends TestCase
{
    use PromptPublications;
    use RefreshDatabase;

    private const VERSION = 'c0000000-0000-4000-8000-0000000000c6';

    private const FIXTURE = __DIR__.'/../../fixtures/ultron-studio/pt17b-terceros-borradores-v4.json';

    /** El catálogo de la prueba real: id → nombre, precio, días y beneficios. */
    private const CATALOGO = [
        3 => ['Plan Valera', 65000, 30, ['Acceso total al gimnasio', 'Asesoría semi personalizada']],
        4 => ['Plan Mensual', 80000, 30, ['Acceso total al gimnasio', 'Clases grupales ilimitadas']],
        8 => ['Élite', 180000, 30, ['Acceso total al gimnasio', 'Entrenador personal dedicado']],
    ];

    private const V4 = ['persona.tone' => 'profesional', 'persona.maxChars' => 200];

    private const PASS = ['verdict' => 'pass', 'issues' => [], 'notes' => ''];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-23 14:05:00', 'UTC'));
        config()->set('marketing.ultron.enabled', true);
        config()->set('meta.enabled', false);
        config()->set('marketing.ai.enabled', true);
        config()->set('marketing.ai.driver', 'fake');
        config()->set('marketing.ultron.payment_links_enabled', false);
        Http::fake();
        $orden = 0;
        foreach (self::CATALOGO as $id => [$nombre, $precio, $dias, $beneficios]) {
            (new Plan)->forceFill([
                'id' => $id, 'name' => $nombre, 'price' => $precio, 'duration_days' => $dias,
                'active' => true, 'sellable' => true, 'sort_order' => ++$orden, 'is_recommended' => false,
                'benefits' => json_encode($beneficios),
            ])->save();
        }
        MarketingKnowledgeItem::create([
            'category' => 'schedule', 'key' => 'schedule.opening_hours', 'title' => 'Horario general de atencion',
            'content' => 'Lunes a viernes de 5:00 a. m. a 10:00 p. m. Sabados de 8:00 a. m. a 2:00 p. m.',
            'is_active' => true, 'origin' => MarketingKnowledgeItem::ORIGIN_HUMAN_PANEL, 'review_status' => MarketingKnowledgeItem::REVIEW_APPROVED,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array<string,mixed> el `sp` que el Studio firmaría para estos gobernados */
    private static function sp(array $gobernados): array
    {
        $parte = self::parteV2(self::VERSION, $gobernados);

        return StudioPromptBundle::rolesFor($parte['governed'], self::VERSION, $parte['contentHash'])['sp'];
    }

    /** @return array<string,mixed> el turno de E-001 para ese `sp` */
    private function turno(array $sp): array
    {
        return app(StudioLabEngineContext::class)->build(['turns' => [['role' => 'prospect', 'text' => '¿Qué planes tienen y cuánto cuesta la mensualidad?']], 'facts' => ['paymentState' => 'none', 'handoffRequested' => false]], $sp)['turn'];
    }

    /** @return array{0:array<string,mixed>,1:array<string,mixed>} [ai/check del borrador k, la predicción del commit con el revisor en pass] */
    private function pasa(array $turno, string $borrador, int $intento = 1): array
    {
        $lab = app(StudioLabEngineCheck::class);
        $draft = ['reply_draft' => $borrador, 'intent' => 'pricing_question', 'recommended_plan_id' => 3, 'tools_requested' => []];

        return [
            $lab->check($turno, $intento, $draft, 3),
            $lab->predict($turno, ['reply_draft' => $borrador, 'recommended_plan_id' => 3, 'critic' => self::PASS], ['intent' => 'pricing_question', 'tools_requested' => []]),
        ];
    }

    /** @return array<int,string> plan_id → su texto expandido */
    private static function textos(): array
    {
        $out = [];
        foreach (Plan::query()->whereIn('id', array_keys(self::CATALOGO))->get() as $plan) {
            $out[(int) $plan->id] = O::item($plan);
        }

        return $out;
    }

    private static function medida(string $borrador, array $po): int
    {
        return UltronReplyFitter::measure(O::expand($borrador, self::textos(), $po)['text']);
    }

    /** Una lista con su pregunta final que mide exactamente `$largo` ya expandida. */
    private static function listaDe(int $largo, array $po): string
    {
        $base = 'Claro, le cuento: {{PLAN_1}}, {{PLAN_2}} y {{PLAN_3}}. ¿Cuál le interesa revisar con más calma?';
        $relleno = 'Los tres incluyen acceso total al gimnasio'.str_repeat(' y acompañamiento', 30);

        return str_replace('Claro, le cuento: ', 'Claro, le cuento: '.mb_substr($relleno, 0, $largo - self::medida($base, $po) - 2).'. ', $base);
    }

    // ── L1 ──────────────────────────────────────────────────────────────────

    public function test_l1_los_24_terceros_borradores_reales_pasan_la_longitud_con_la_regla_y_no_se_recortan(): void
    {
        $f = json_decode((string) file_get_contents(self::FIXTURE), true, 16, JSON_THROW_ON_ERROR);
        $this->assertCount(24, $f['cases']);

        $sp = self::sp(self::V4);
        $this->assertSame([200, 'profesional', 1, C::VERSION], [$sp['n'], $sp['t'], $sp['o'], $sp['k']], 'el sello de la v4 con el compilador 6');
        $turno = $this->turno($sp);
        $this->assertSame($f['po'], $turno['po'], 'la lista de E-001 con este catálogo');

        $conLista = 0;
        foreach ($f['cases'] as $caso) {
            $nombre = $caso['case'];
            $mide = self::medida($caso['text'], $turno['po']);
            $this->assertSame($caso['labChars'], $mide, "{$nombre}: con este catálogo mide lo mismo que en el laboratorio");
            $this->assertLessThanOrEqual(300, $mide, $nombre);
            $lista = count(array_unique($caso['markers'])) >= O::MIN_MARKERS;
            $conLista += $lista ? 1 : 0;

            [$check, $commit] = $this->pasa($turno, $caso['text'], 3);
            $this->assertNotContains(N::LENGTH_OVER_CAP, array_column($check['defects'], 'code'), "{$nombre}: ai/check no pide reescribir por la longitud");
            $this->assertNotContains(N::LENGTH_OVER_CAP, $commit['codes'], "{$nombre}: el commit no lo descarta por la longitud");
            $this->assertFalse($commit['trimmed'], "{$nombre}: no se le quita la pregunta final");
            $this->assertSame([$mide, $mide, true], [$commit['measured'], $commit['chars'], $commit['withinCap']], "{$nombre}: sale entero");
            $this->assertSame($lista ? 320 : 200, $commit['cap'], "{$nombre}: el tope que se le aplica");
        }
        $this->assertSame(23, $conLista, '23 traen la lista; 36/161/E-003 trae un solo marcador (y mide 200)');

        // Con el sello del compilador 5 (sin `k`), como hoy: los que pasan de 200 piden reescribir y el commit los recorta o descarta.
        $turno5 = $this->turno(array_diff_key($sp, ['k' => 1]));
        $fallan = 0;
        foreach ($f['cases'] as $caso) {
            [$check, $commit] = $this->pasa($turno5, $caso['text'], 3);
            $this->assertSame(200, $commit['cap'], $caso['case']);
            if ($caso['labChars'] > 200) {
                $fallan++;
                $this->assertContains(N::LENGTH_OVER_CAP, array_column($check['defects'], 'code'), $caso['case']);
                $this->assertTrue($commit['trimmed'] || in_array(N::LENGTH_OVER_CAP, $commit['codes'], true), "{$caso['case']}: recortado o descartado, como hoy");
            }
        }
        $this->assertSame(23, $fallan);
    }

    // ── L2 ──────────────────────────────────────────────────────────────────

    public function test_l2_con_tope_200_la_lista_de_310_sale_entera_y_la_de_330_es_respaldo_sin_recorte(): void
    {
        $turno = $this->turno(self::sp(self::V4));
        $de310 = self::listaDe(310, $turno['po']);
        $de330 = self::listaDe(330, $turno['po']);
        $this->assertSame([310, 330], [self::medida($de310, $turno['po']), self::medida($de330, $turno['po'])]);

        [$check, $commit] = $this->pasa($turno, $de310);
        $this->assertSame([true, [], false], [$check['pass'], $check['defects'], $check['rewrite']], '310: ai/check');
        $this->assertSame(['origin' => StudioLabEngineCheck::ORIGIN_MODEL, 'cause' => null, 'codes' => [], 'measured' => 310, 'chars' => 310, 'cap' => 320, 'withinCap' => true, 'register' => 'ok', 'tuteo' => [], 'trimmed' => false], $commit, '310: sale entero, con su pregunta');

        [$check, $commit] = $this->pasa($turno, $de330);
        $this->assertSame([false, [N::LENGTH_OVER_CAP], true], [$check['pass'], array_column($check['defects'], 'code'), $check['rewrite']], '330: ai/check pide reescribir');
        $this->assertStringContainsString('mide 330 caracteres y el limite publicado es 320', $check['notes']);
        $this->assertSame([StudioLabEngineCheck::ORIGIN_FALLBACK, N::LENGTH_OVER_CAP, [N::LENGTH_OVER_CAP], false, 320], [$commit['origin'], $commit['cause'], $commit['codes'], $commit['trimmed'], $commit['cap']], '330: no se recorta y es respaldo por la longitud');

        // En el último intento, `ai/check` ya no deja reescribir: la misma longitud, sin reescritura (los intentos no cambian).
        [$check] = $this->pasa($turno, $de330, 3);
        $this->assertSame([false, false, StudioLabEngineCheck::REASON_LAST_ATTEMPT], [$check['pass'], $check['rewrite'], $check['budget']['reason']]);
    }

    // ── L3 ──────────────────────────────────────────────────────────────────

    public function test_l3_sin_lista_en_el_turno_no_hay_tope_de_lista_y_los_marcadores_son_respaldo(): void
    {
        $turno = $this->turno(self::sp(self::V4));
        $de310 = self::listaDe(310, $turno['po']);
        $sinLista = ['po' => []] + $turno;

        [$check, $commit] = $this->pasa($sinLista, $de310);
        $this->assertSame([N::PLANS_OVERVIEW, O::NO_LIST], [$check['defects'][0]['code'], $check['defects'][0]['detail']], 'ai/check');
        $this->assertSame([StudioLabEngineCheck::ORIGIN_FALLBACK, N::PLANS_OVERVIEW, 200], [$commit['origin'], $commit['cause'], $commit['cap']], 'el commit: respaldo, con el tope publicado');
        $this->assertNull(UltronReplyFitter::plansListCap($turno['sp'], [], $de310));
    }

    // ── L4 ──────────────────────────────────────────────────────────────────

    public function test_l4_con_320_sin_n_firmado_la_lista_se_mide_contra_320_y_lo_demas_no(): void
    {
        $sp = self::sp(['persona.tone' => 'profesional', 'persona.maxChars' => 320]);
        $this->assertArrayNotHasKey('n', $sp, 'el tope de la línea base no se firma, como en `decide`');
        $this->assertSame(['profesional', 1, C::VERSION], [$sp['t'], $sp['o'], $sp['k']]);
        $turno = $this->turno($sp);
        $de310 = self::listaDe(310, $turno['po']);
        $de450 = self::listaDe(450, $turno['po']);
        $this->assertSame([310, 450], [self::medida($de310, $turno['po']), self::medida($de450, $turno['po'])]);

        [$check, $commit] = $this->pasa($turno, $de310);
        $this->assertSame([true, [], false], [$check['pass'], $check['defects'], $check['rewrite']], '310: ai/check');
        $this->assertSame([StudioLabEngineCheck::ORIGIN_MODEL, 310, 320, true, false], [$commit['origin'], $commit['chars'], $commit['cap'], $commit['withinCap'], $commit['trimmed']], '310: sale entero, con su pregunta');

        [$check, $commit] = $this->pasa($turno, $de450);
        $this->assertSame([false, [N::LENGTH_OVER_CAP], true], [$check['pass'], array_column($check['defects'], 'code'), $check['rewrite']], '450: ai/check pide reescribir');
        $this->assertStringContainsString('mide 450 caracteres y el limite publicado es 320', $check['notes']);
        $this->assertSame([StudioLabEngineCheck::ORIGIN_FALLBACK, N::LENGTH_OVER_CAP, false, 320], [$commit['origin'], $commit['cause'], $commit['trimmed'], $commit['cap']], '450: respaldo por la longitud, sin recorte');

        // Sin lista en el turno, 450 sin marcadores no se mide (la línea base, como hoy).
        $sinLista = mb_substr(str_repeat('Abrimos de lunes a viernes desde muy temprano hasta la noche. ', 8), 0, 449).'.';
        [$check, $commit] = $this->pasa(['po' => []] + $turno, $sinLista);
        $this->assertNotContains(N::LENGTH_OVER_CAP, array_column($check['defects'], 'code'));
        $this->assertNotContains(N::LENGTH_OVER_CAP, $commit['codes']);
        $this->assertSame([null, false], [$commit['cap'], $commit['trimmed']]);
    }
}
