<?php

namespace Tests\Unit\Marketing\Ultron;

use App\Services\Marketing\Ultron\Prompts\UltronPromptCompiler as C;
use App\Services\Marketing\Ultron\UltronDraftDefects as D;
use App\Services\Marketing\Ultron\UltronRegenerationNotes as N;
use App\Services\Marketing\Ultron\UltronReplyFitter as F;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PT-17b · LA REGLA «TOPE DE LISTA» (Enmienda A4-6 del H5; decisión B del
 * propietario, 23-11-2026; anexo de `plans/informes-motores/diseno-arq-pt17b-planes-c6.md`).
 *
 * La función pura {@see F::plansListCap()} y su par {@see F::plansListDraft()},
 * y lo que hace con ellas {@see D::of()} (la comprobación de `ai/check`) sin
 * base de datos. Las pruebas del endpoint, del commit y del laboratorio están en
 * `UltronPlansListCapFlowTest` y `StudioLabEnginePlansListCapTest`.
 *
 *  1. El tope de lista es max(tope publicado, 320): 160 → 320, 200 → 320,
 *     320 → 320, 480 → 480, con dos o tres marcadores de la lista del turno,
 *     también en una consulta combinada (la lista y otra respuesta). Cada fila
 *     usa el `sp` que firma `decide`: con 320, el de la línea base, SIN `n`
 *     (bloqueante B1 de la revisión del 23-11-2026).
 *  2. NO se aplica (null: el tope publicado, como hoy) sin lista
 *     (o en un turno de cobro, que llega sin lista), con un tope firmado que no
 *     vale, en Legacy, y sin el sello del compilador 6 (`sp.k` ausente, 5 o de
 *     otro tipo): el token de un compilador anterior se mide como hoy. Lo mismo
 *     sin `n` (la línea base) con un marcador, sin lista o con el sello del 5.
 *  3. Una respuesta con la lista no se recorta: cabe entera o null, mientras
 *     que la regla de siempre le quitaría la pregunta final.
 *  4. `of()`: con tope 200, una lista que mide 310 pasa; una de 330 da
 *     `length_over_cap` con la nota que nombra 320; sin lista, 210 falla con
 *     200; y con el sello del 5, la misma lista de 310 falla con 200, como hoy.
 *  5. `of()` con la línea base (320, sin `n`): la lista de 310 pasa, la de 450
 *     da `length_over_cap` con la nota de 320, y sin lista (o con el sello del
 *     5) 450 no se mide, como hoy.
 *
 * PT-17, puerta 1, L2 (diagnóstico del 24-11-2026): un solo tope por turno de lista: en el turno de lista vale
 * también con un marcador, sin marcadores, con uno repetido o de fuera de la lista
 * (`enElTurnoDeLista`), y un borrador de un solo plan recibe «falta la lista» con
 * el presupuesto de 320, sin la longitud de 200.
 */
class UltronPlansListCapTest extends TestCase
{
    /** La lista firmada de la prueba real: Plan Valera, Plan Mensual y Élite. */
    private const PO = [3, 4, 8];

    /** Lo que mide cada marcador ya expandido con el catálogo de la prueba real (25 + 26 + 20). */
    private const TEXTOS = [3 => 'Plan Valera ($65.000 COP)', 4 => 'Plan Mensual ($80.000 COP)', 8 => 'Élite ($180.000 COP)'];

    /** El catálogo mínimo para `of()` (sin beneficios: ninguno se atribuye a otro plan). */
    private const CATALOGO = [
        ['id' => 3, 'name' => 'Plan Valera', 'price' => 65000, 'duration_days' => 30, 'benefits' => []],
        ['id' => 4, 'name' => 'Plan Mensual', 'price' => 80000, 'duration_days' => 30, 'benefits' => []],
        ['id' => 8, 'name' => 'Élite', 'price' => 180000, 'duration_days' => 30, 'benefits' => []],
    ];

    private const DOS = 'Claro, le cuento: {{PLAN_1}} da acceso total y {{PLAN_2}} suma clases grupales. ¿Cuál le encaja mejor?';

    private const TRES = 'Claro, tenemos {{PLAN_1}}, {{PLAN_2}} y {{PLAN_3}}. ¿Cuál revisamos?';

    /** La consulta combinada: la lista y, además, el horario que también preguntó. */
    private const COMBINADA = 'Abrimos de lunes a viernes de 5 a. m. a 10 p. m. y los sabados en la mañana. En planes tenemos {{PLAN_1}} y {{PLAN_2}}, los dos con acceso total. ¿Cuál le sirve más?';

    /** El sello de un turno del Studio con el compilador 6 y un tope. */
    private static function sp(int $n, array $mas = []): array
    {
        return array_replace(['m' => 'studio', 'r' => null, 'h' => str_repeat('a', 64), 'v' => null, 'c' => null, 'e' => null, 's' => null, 'n' => $n, 't' => 'profesional', 'o' => 1, 'k' => C::VERSION], $mas);
    }

    /**
     * El `sp` que firma `decide` con el tope de la línea base (320): sin `n`
     * (`UltronPromptOffer::offer()` y `StudioPromptBundle::rolesFor()` solo lo
     * firman si se aparta de ella). Lo fija la prueba de flujo y la del laboratorio.
     */
    private static function spDeLaLineaBase(array $mas = []): array
    {
        return array_diff_key(self::sp(C::BASELINE[C::MAX_CHARS], $mas), ['n' => 1]);
    }

    /** Un borrador con la lista que mide exactamente `$largo` caracteres ya expandido, con su pregunta final. */
    private static function listaDe(int $largo): string
    {
        $base = 'Claro, le cuento: {{PLAN_1}}, {{PLAN_2}} y {{PLAN_3}}. ¿Cuál le interesa revisar con más calma?';
        $mide = F::measure(self::expandir($base));
        $relleno = 'Los tres incluyen acceso total al gimnasio'.str_repeat(' y acompañamiento', 30);

        return str_replace('Claro, le cuento: ', 'Claro, le cuento: '.mb_substr($relleno, 0, $largo - $mide - 2).'. ', $base);
    }

    private static function expandir(string $borrador): string
    {
        return str_replace(['{{PLAN_1}}', '{{PLAN_2}}', '{{PLAN_3}}'], array_values(self::TEXTOS), $borrador);
    }

    /** @return list<array{code:string,detail:?string,issue:string,note:string}> */
    private static function defectos(string $borrador, array $sp, array $po = self::PO): array
    {
        $expandido = $po === [] ? $borrador : self::expandir($borrador);

        return D::of(['reply_draft' => $borrador, 'intent' => 'pricing_question', 'tools_requested' => []], $expandido, $sp, $po, self::CATALOGO, '¿Qué planes tienen y cuánto cuesta la mensualidad?', self::TEXTOS);
    }

    // ── 1 ─────────────────────────────────────────────────────────────────────

    /** @return array<string, array{array<string,mixed>, int, int}> el `sp` que firma `decide`, el tope publicado y el de lista */
    public static function topes(): array
    {
        return [
            '160 → 320' => [self::sp(160), 160, 320],
            '200 → 320' => [self::sp(200), 200, 320],
            '320 → 320 (la línea base: sin n)' => [self::spDeLaLineaBase(), 320, 320],
            '480 → 480' => [self::sp(480), 480, 480],
        ];
    }

    #[DataProvider('topes')]
    public function test_con_la_lista_el_tope_es_el_publicado_y_nunca_menos_de_320(array $sp, int $publicado, int $esperado): void
    {
        // PT-17, puerta 1, L2: el tope es del TURNO con lista, así que el borrador ya no entra en la función (con dos,
        // tres o ningún marcador, el tope es el mismo). Revisión de la puerta 1, menor 2: sin tercer argumento.
        $this->assertSame($esperado, F::plansListCap($sp, self::PO), 'una lista de tres');
        $this->assertSame($esperado, F::plansListCap($sp, [3, 4]), 'una lista de dos');
        // Es la cifra que el compilador le da al redactor.
        $this->assertSame(C::plansListChars($publicado), F::plansListCap($sp, self::PO));
    }

    // ── 2 ─────────────────────────────────────────────────────────────────────

    /** @return array<string, array{array<string,mixed>, list<int>}> */
    public static function fueraDeLaRegla(): array
    {
        return [
            'sin lista (no se ofreció, o un turno de cobro)' => [self::sp(200), []],
            'un tope que no vale' => [self::sp(200, ['n' => 150]), self::PO],
            'un tope en texto' => [self::sp(200, ['n' => '200']), self::PO],
            'un tope firmado nulo (no es la línea base)' => [self::sp(200, ['n' => null]), self::PO],
            'línea base: sin lista' => [self::spDeLaLineaBase(), []],
            'línea base: sello del compilador 5 (sin k)' => [array_diff_key(self::spDeLaLineaBase(), ['k' => 1]), self::PO],
            'línea base: Legacy con un sp de más' => [self::spDeLaLineaBase(['m' => 'legacy']), self::PO],
            'Legacy' => [['m' => 'legacy', 'r' => 'prompts_off', 'h' => null, 'v' => null, 'c' => null, 'e' => null, 's' => null], self::PO],
            'Legacy con un sp de más' => [self::sp(200, ['m' => 'legacy']), self::PO],
            'sin modo' => [array_diff_key(self::sp(200), ['m' => 1]), self::PO],
            'sello del compilador 5 (sin k: de antes del despliegue)' => [array_diff_key(self::sp(200), ['k' => 1]), self::PO],
            'sello del compilador 5 (k = 5)' => [self::sp(200, ['k' => 5]), self::PO],
            'k en texto' => [self::sp(200, ['k' => '6']), self::PO],
            'sin sp' => [[], self::PO],
        ];
    }

    #[DataProvider('fueraDeLaRegla')]
    public function test_fuera_de_la_regla_no_hay_tope_de_lista(array $sp, array $po): void
    {
        $this->assertNull(F::plansListCap($sp, $po));
    }

    /**
     * PT-17, puerta 1, L2 (diagnóstico del 24-11-2026): un solo tope por turno de lista. Estas filas estaban
     * «fuera de la regla» porque el borrador no traía dos marcadores de la lista;
     * ahora el tope es del turno en que el servidor ofreció la lista, lo traiga o
     * no el borrador (el contrato del redactor: en ese turno, «no pasa de 320»).
     *
     * El borrador ya no es una entrada de la función: las filas de antes (un marcador, ninguno, uno repetido, los de un
     * plan…) se reducen a las combinaciones de `sp` y lista que sí la cambian (revisión de la puerta 1, menor 2).
     *
     * @return array<string, array{array<string,mixed>, list<int>}>
     */
    public static function enElTurnoDeLista(): array
    {
        return [
            'tope 200 · lista de tres' => [self::sp(200), self::PO],
            'tope 200 · lista de dos' => [self::sp(200), [3, 4]],
            'línea base · lista de tres' => [self::spDeLaLineaBase(), self::PO],
        ];
    }

    #[DataProvider('enElTurnoDeLista')]
    public function test_en_el_turno_de_lista_el_tope_es_el_del_turno_sin_mirar_el_borrador(array $sp, array $po): void
    {
        $this->assertSame(320, F::plansListCap($sp, $po));
    }

    // ── 3 ─────────────────────────────────────────────────────────────────────

    public function test_a_la_respuesta_con_la_lista_no_se_le_quita_la_pregunta_final(): void
    {
        $de300 = self::expandir(self::listaDe(300));
        $this->assertSame(300, F::measure($de300));
        $this->assertStringEndsWith('?', $de300);

        // Con la lista: cabe en 320, sale entera, con su pregunta; en 280 no cabe y no se recorta.
        $this->assertSame(['text' => $de300, 'dropped' => 0], F::plansListDraft($de300, 320));
        $this->assertNull(F::plansListDraft($de300, 280));
        // La regla de siempre, en cambio, le quitaría la pregunta final para que cupiera.
        $recorte = F::draft($de300, 280);
        $this->assertNotNull($recorte);
        $this->assertSame(1, $recorte['dropped']);
        $this->assertStringNotContainsString('?', $recorte['text']);
    }

    // ── 4 ─────────────────────────────────────────────────────────────────────

    public function test_ai_check_con_la_lista_mide_contra_320_y_sin_lista_contra_200(): void
    {
        // 310 con la lista y tope 200: pasa.
        $de310 = self::listaDe(310);
        $this->assertSame(310, F::measure(self::expandir($de310)));
        $this->assertSame([], self::defectos($de310, self::sp(200)));

        // 330 con la lista: `length_over_cap` con la nota del tope de la lista (320, y el margen de 320).
        $de330 = self::listaDe(330);
        $this->assertSame(330, F::measure(self::expandir($de330)));
        $d = self::defectos($de330, self::sp(200));
        $this->assertSame([N::LENGTH_OVER_CAP], array_column($d, 'code'));
        $this->assertStringContainsString('mide 330 caracteres y el limite publicado es 320', $d[0]['note']);
        $this->assertStringContainsString('Reescribelo en unos '.C::composerTargetChars(320), $d[0]['note']);
        $this->assertStringContainsString('Los marcadores de la lista ya ocupan 71', $d[0]['note']);
        $this->assertStringNotContainsString(' 200', $d[0]['note']);

        // Sin lista: 210 falla contra 200, como hoy (la nota de siempre, sin presupuesto de lista).
        $sinLista = 'Abrimos de lunes a viernes desde muy temprano hasta la noche y los fines de semana en la mañana; '.str_repeat('con entrenadores en sala ', 5);
        $sinLista = mb_substr($sinLista, 0, 209).'.';
        $this->assertSame(210, F::measure($sinLista));
        $d = self::defectos($sinLista, self::sp(200), []);
        $this->assertSame([N::LENGTH_OVER_CAP], array_column($d, 'code'));
        $this->assertStringContainsString('mide 210 caracteres y el limite publicado es 200', $d[0]['note']);

        // PT-17, puerta 1, L2 (diagnóstico del 24-11-2026): un solo tope por turno de lista: con un solo marcador, en el MISMO turno, el mismo tope (320): 229 cabe; lo que le falta es la lista, con el presupuesto de 320.
        $uno = 'Le recomiendo {{PLAN_1}}, con acceso total al gimnasio y asesoría semi personalizada desde el primer día, ideal para empezar con buena guía y a su ritmo, sin prisa y con el acompañamiento del equipo. ¿Le sirve así?';
        $d = self::defectos($uno, self::sp(200));
        $this->assertSame([[N::PLANS_OVERVIEW, 'missing']], array_map(static fn (array $x): array => [$x['code'], $x['detail']], $d));
        $this->assertStringContainsString('Ya expandidos ocupan 71 caracteres: quedan 179 de 250.', $d[0]['note']);

        // El sello del compilador 5 (sin `k`): la misma lista de 310 falla contra 200, como hoy.
        $d = self::defectos($de310, array_diff_key(self::sp(200), ['k' => 1]));
        $this->assertSame([N::LENGTH_OVER_CAP], array_column($d, 'code'));
        $this->assertStringContainsString('mide 310 caracteres y el limite publicado es 200', $d[0]['note']);

        // Legacy: ningún defecto, se mida lo que se mida (como hoy).
        $this->assertSame([], self::defectos($de330, ['m' => 'legacy', 'r' => 'prompts_off']));
    }

    // ── 5 ─────────────────────────────────────────────────────────────────────

    public function test_ai_check_con_la_linea_base_mide_la_lista_contra_320_y_lo_demas_como_hoy(): void
    {
        $sp = self::spDeLaLineaBase();

        // 310 con la lista: pasa.
        $this->assertSame([], self::defectos(self::listaDe(310), $sp));

        // 450 con la lista: `length_over_cap` con la nota del tope de la lista, como con 160 o 200.
        $de450 = self::listaDe(450);
        $this->assertSame(450, F::measure(self::expandir($de450)));
        $d = self::defectos($de450, $sp);
        $this->assertSame([N::LENGTH_OVER_CAP], array_column($d, 'code'));
        $this->assertStringContainsString('mide 450 caracteres y el limite publicado es 320', $d[0]['note']);
        $this->assertStringContainsString('Reescribelo en unos '.C::composerTargetChars(320), $d[0]['note']);
        $this->assertStringContainsString('Los marcadores de la lista ya ocupan 71', $d[0]['note']);

        // Sin lista en el turno, 450 sin marcadores no se mide (la línea base, como hoy).
        $sinLista = mb_substr(str_repeat('Abrimos de lunes a viernes desde muy temprano hasta la noche. ', 8), 0, 449).'.';
        $this->assertSame(450, F::measure($sinLista));
        $this->assertSame([], self::defectos($sinLista, $sp, []));

        // Con el sello del compilador 5, la misma lista de 450 no se mide (como hoy).
        $this->assertSame([], self::defectos($de450, array_diff_key($sp, ['k' => 1])));
    }
}
