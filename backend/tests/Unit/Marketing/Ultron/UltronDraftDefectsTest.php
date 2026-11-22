<?php

namespace Tests\Unit\Marketing\Ultron;

use App\Services\Marketing\SalesIntents;
use App\Services\Marketing\Ultron\CommercialTurnPolicy as P;
use App\Services\Marketing\Ultron\UltronDraftDefects as D;
use App\Services\Marketing\Ultron\UltronPlansOverview as O;
use App\Services\Marketing\Ultron\UltronRegenerationNotes as N;
use App\Services\Marketing\Ultron\UltronReplyFitter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * «REGENERAR ANTES DE SUSTITUIR» · OLA 1 · LOS DEFECTOS DE UN BORRADOR (plan
 * del arquitecto `plan-arquitecto-a4-regenerar.md` §4.1, paso 4, y §4.4).
 *
 * Dados el borrador sin expandir (con su intención y sus herramientas), el
 * expandido, el `sp` firmado, la lista `po`, el catálogo y el entrante: la
 * lista ORDENADA de defectos, uno por código, cada uno con su detalle, su
 * `issue` y su nota. El orden es el de §4.1: (a) la lista de planes, (b) el
 * tuteo, (c) la longitud y (d) el beneficio de otro plan. Sin versión del
 * Studio, ninguno. Pura: aún no la llama nadie (la usará la ola 4).
 *
 * OLA 4b (decisión L-3 del lead): `ofPolicy()` da, DESPUÉS de esos cuatro, las
 * seis reglas de política corregibles que `CommercialTurnPolicy::violations()`
 * marca sobre el texto que miraría el commit (con el recorte de la pregunta
 * final por el tope o por el registro con un bloque de Laravel, y el saludo
 * repetido recortado como hace el commit), en el orden de
 * `UltronRegenerationNotes::POLICY_CODES`. El saludo repetido no entra.
 */
class UltronDraftDefectsTest extends TestCase
{
    private const SP = ['m' => 'studio', 'r' => null, 'h' => 'x', 'v' => 'v', 'c' => 'c', 'e' => 'e', 's' => 1, 'n' => 200, 't' => 'profesional', 'o' => 1];

    /** @return list<array{id:int,name:string,duration_days:int,benefits:list<string>}> */
    private static function catalogo(): array
    {
        return [
            ['id' => 3, 'name' => 'Plan Valera', 'duration_days' => 30, 'benefits' => ['Acceso ilimitado al gimnasio']],
            ['id' => 4, 'name' => 'Plan Mensual', 'duration_days' => 30, 'benefits' => ['Clases grupales', 'Acceso ilimitado al gimnasio']],
            ['id' => 8, 'name' => 'Élite', 'duration_days' => 30, 'benefits' => ['Plan nutricional personalizado']],
        ];
    }

    private const TEXTOS = [3 => 'Plan Valera ($65.000 COP)', 4 => 'Plan Mensual ($80.000 COP)', 8 => 'Élite ($180.000 COP)'];

    private const PANORAMA = '¿Qué planes tienen y cuánto cuesta la mensualidad?';

    /** @return list<array{code:string,detail:?string,issue:string,note:string}> */
    private static function defectos(string $borrador, array $sp = self::SP, array $po = [3, 4, 8], string $entrante = self::PANORAMA, string $intent = SalesIntents::PRICING_QUESTION, array $tools = []): array
    {
        $expandido = O::expand($borrador, self::TEXTOS, $po)['text'];

        return D::of(['reply_draft' => $borrador, 'intent' => $intent, 'tools_requested' => $tools], $expandido, $sp, $po, self::catalogo(), $entrante);
    }

    /** @param list<array{code:string,detail:?string}> $d */
    private static function codigos(array $d): array
    {
        return array_map(fn (array $x) => $x['detail'] === null ? $x['code'] : $x['code'].'/'.$x['detail'], $d);
    }

    public function test_un_borrador_bueno_no_tiene_defectos(): void
    {
        $this->assertSame([], self::defectos('Claro. Tenemos {{PLAN_1}} y {{PLAN_2}}. ¿Cuál le interesa?'));
    }

    public function test_sin_version_del_studio_ninguno(): void
    {
        $malo = 'Tienes {{PLAN_1}}. '.str_repeat('Te cuento mucho más. ', 20);
        $this->assertSame([], self::defectos($malo, ['m' => 'legacy', 'r' => 'prompts_off']));
        $this->assertSame([], self::defectos($malo, []));
        $this->assertNotSame([], self::defectos($malo));
    }

    public function test_el_orden_de_los_cuatro(): void
    {
        $borrador = 'Te cuento: {{PLAN_1}} incluye clases grupales. '.str_repeat('Es una gran opción para empezar a entrenar. ', 5);
        $d = self::defectos($borrador);
        $this->assertSame(['plans_overview/missing', 'register_tuteo', 'length_over_cap', 'benefit_misattributed'], self::codigos($d));
        foreach ($d as $x) {
            $this->assertSame(N::issue($x['code'], $x['detail']), $x['issue']);
            $this->assertNotSame('', $x['note']);
            $this->assertStringNotContainsString('{{', $x['note']);
        }
    }

    public function test_la_lista_solo_cuando_hay_lista_sin_herramientas_y_se_pide_el_precio_o_faltan_planes(): void
    {
        $uno = 'Tenemos {{PLAN_1}}. ¿Le interesa?';
        $this->assertSame(['plans_overview/missing'], self::codigos(self::defectos($uno)));
        $this->assertSame([], self::codigos(self::defectos('Tenemos varios planes.', po: [])), 'sin lista no se pide');
        $this->assertSame([], self::codigos(self::defectos('Tenemos varios planes.', tools: ['app_links_send'])), 'con herramientas no se pide');

        // Sin pedir el precio y con dos planes nombrados (a mano, sin marcadores), no se mira la lista: como en el commit.
        $aMano = 'Tenemos el Plan Valera y el Plan Mensual. ¿Cuál le interesa?';
        $this->assertSame([], self::codigos(self::defectos($aMano, entrante: '¿Qué planes tienen?', intent: SalesIntents::GENERAL_INFO)));
        // Correcciones, C2 (I-2): sin ningún marcador de la lista, lo que falta es la lista (antes, `name_written`).
        $this->assertSame(['plans_overview/missing'], self::codigos(self::defectos($aMano)), 'pidiendo el precio, sí: falta la lista');
        $this->assertSame(['plans_overview/missing'], self::codigos(self::defectos('Tenemos varios planes.', entrante: '¿Qué planes tienen?', intent: SalesIntents::GENERAL_INFO)), 'menos de dos nombrados');
    }

    public function test_cada_detalle_de_la_lista_con_su_nota(): void
    {
        $casos = [
            O::MISSING => 'Tenemos {{PLAN_2}}.',
            O::MIXED => 'Tenemos {{PLAN_1}} y {{PLAN_2}} desde {{PLAN_PRICE}}.',
            O::NAME_WRITTEN => 'Tenemos el Plan Valera {{PLAN_1}} y {{PLAN_2}}.',
            O::UNKNOWN_MARKER => 'Tenemos {{PLAN_1}} y {{PLAN_4}}.',
            O::PRICE_WRITTEN => 'Tenemos {{PLAN_1}} y {{PLAN_2}} desde $50.000.',
        ];
        foreach ($casos as $detalle => $borrador) {
            $d = self::defectos($borrador);
            $this->assertSame('plans_overview/'.$detalle, self::codigos($d)[0] ?? null, $detalle);
            $params = $detalle === O::UNKNOWN_MARKER ? ['K' => 3] : [];
            $this->assertSame(N::note(N::PLANS_OVERVIEW, $detalle, $params), $d[0]['note'], $detalle);
        }
        $this->assertStringContainsString('solo tiene PLAN_1 a PLAN_2.', self::defectos('{{PLAN_1}} y {{PLAN_3}}', po: [3, 4])[0]['note'], 'K = count(po)');
    }

    /** Sin lista (no se ofreció o es un turno de cobro: quien llama pasa `po` vacía), un PLAN_k siempre es defecto, también con herramientas. */
    public function test_no_list(): void
    {
        foreach ([[], ['payment_link_send']] as $tools) {
            $d = self::defectos('Tenemos {{PLAN_1}} y {{PLAN_2}}.', po: [], tools: $tools, intent: SalesIntents::PAYMENT_LINK_REQUEST);
            $this->assertSame(['plans_overview/no_list'], self::codigos($d));
            $this->assertSame(N::note(N::PLANS_OVERVIEW, O::NO_LIST), $d[0]['note']);
        }
    }

    /**
     * DECISIÓN DEL LEAD (encargo de la ola 4; laguna de §4.1, paso 4a): un
     * `PLAN_k` que no es de la lista hace que el commit rechace el turno (422
     * `unknown_placeholder` → rendición) se pida o no el panorama, con
     * herramientas o sin ellas y aunque haya otros marcadores válidos. Es justo
     * el defecto que una reescritura arregla: la comprobación lo señala SIEMPRE.
     */
    public function test_un_marcador_que_no_es_de_la_lista_se_senala_siempre(): void
    {
        $borrador = 'Tenemos {{PLAN_1}}, {{PLAN_2}} y {{PLAN_4}}. ¿Cuál le interesa?';

        // Sin pedir el precio y con dos planes nombrados: la lista no se mira, el marcador sí.
        $d = self::defectos($borrador, entrante: '¿Qué planes tienen?', intent: SalesIntents::GENERAL_INFO);
        $this->assertSame(['plans_overview/unknown_marker'], self::codigos($d));
        $this->assertSame(N::note(N::PLANS_OVERVIEW, O::UNKNOWN_MARKER, ['K' => 3]), $d[0]['note']);
        $this->assertSame('relevance', $d[0]['issue']);

        // Con herramientas en el turno, igual.
        $this->assertSame(['plans_overview/unknown_marker'], self::codigos(self::defectos($borrador, tools: ['app_links_send'])));
        $this->assertSame(['plans_overview/unknown_marker'], self::codigos(self::defectos($borrador, entrante: '¿Qué planes tienen?', intent: SalesIntents::GENERAL_INFO, tools: ['staff_review'])));

        // Cualquier forma que no sea exactamente PLAN_1..n.
        foreach (['{{PLAN_0}}', '{{PLAN_01}}', '{{plan_1}}', '{{ PLAN_3 }}'] as $marcador) {
            $d = self::defectos('Tenemos {{PLAN_1}} y '.$marcador.'.', po: [3, 4], entrante: '¿Qué planes tienen?', intent: SalesIntents::GENERAL_INFO, tools: ['app_links_send']);
            $this->assertSame(['plans_overview/unknown_marker'], self::codigos($d), $marcador);
            $this->assertStringContainsString('solo tiene PLAN_1 a PLAN_2.', $d[0]['note'], $marcador);
        }

        // Fuera de la condición del panorama, lo demás de la lista sigue sin mirarse (como en el commit).
        $this->assertSame([], self::codigos(self::defectos('Tenemos {{PLAN_1}}.', tools: ['app_links_send'])), 'falta un plan: no es de esta regla');
        $this->assertSame([], self::codigos(self::defectos('Tenemos {{PLAN_1}} y {{PLAN_1}} con {{PLAN_PRICE}}.', tools: ['app_links_send'])), 'mezcla y repetido: tampoco');
    }

    public function test_el_tuteo_solo_con_profesional_y_sobre_el_expandido(): void
    {
        $tutea = 'Claro, te cuento: {{PLAN_1}} y {{PLAN_2}}. ¿Cuál quieres?';
        $d = self::defectos($tutea);
        $this->assertSame(['register_tuteo'], self::codigos($d));
        $this->assertSame(N::note(N::REGISTER_TUTEO, null, ['MARCAS' => ['te', 'quieres']]), $d[0]['note']);

        $this->assertSame([], self::defectos($tutea, ['t' => 'cercano'] + self::SP));
        $this->assertSame([], self::defectos($tutea, array_diff_key(self::SP, ['t' => 1])));
        $this->assertSame([], self::defectos($tutea, ['t' => 7] + self::SP), 'un tono que no es texto no se mira');
        $this->assertSame([], self::defectos('Usted escribió «¿tú qué me recomiendas?»: tenemos {{PLAN_1}} y {{PLAN_2}}. ¿Cuál le interesa?'), 'la cita de la persona no cuenta');
    }

    public function test_la_longitud_sobre_el_expandido_con_sus_numeros(): void
    {
        // 150 sin expandir; con los dos nombres y precios, más de 200.
        $borrador = 'Claro. Tenemos {{PLAN_1}} y {{PLAN_2}}. '.str_repeat('Le explico cada uno con calma. ', 4).'¿Cuál le interesa?';
        $expandido = O::expand($borrador, self::TEXTOS, [3, 4, 8])['text'];
        $this->assertLessThanOrEqual(200, mb_strlen($borrador));
        $this->assertGreaterThan(200, mb_strlen($expandido));

        $d = self::defectos($borrador);
        $this->assertSame(['length_over_cap'], self::codigos($d));
        $this->assertSame(N::note(N::LENGTH_OVER_CAP, null, ['L' => mb_strlen($expandido), 'N' => 200, 'M' => 160]), $d[0]['note']);

        $this->assertSame([], self::defectos($borrador, array_diff_key(self::SP, ['n' => 1])), 'sin tope firmado no se mide');
        $this->assertSame([], self::defectos($borrador, ['n' => 999] + self::SP), 'un tope fuera del rango del compilador no se mide');
        $this->assertSame(['length_over_cap'], self::codigos(self::defectos($borrador, ['n' => 160] + self::SP)));
    }

    public function test_el_beneficio_con_el_marcador_o_con_el_nombre(): void
    {
        $d = self::defectos('{{PLAN_1}} incluye clases grupales. También {{PLAN_2}}.');
        $this->assertSame(['benefit_misattributed'], self::codigos($d));
        $this->assertSame(N::note(N::BENEFIT_MISATTRIBUTED, null, ['PLAN' => 'PLAN_1', 'BENEFICIO' => 'Clases grupales']), $d[0]['note'], 'nombrado por su marcador: el marcador, sin llaves');

        $d = self::defectos('El Élite incluye clases grupales.', po: [], entrante: '¿El Élite tiene clases?', intent: SalesIntents::GENERAL_INFO);
        $this->assertSame(['benefit_misattributed'], self::codigos($d));
        $this->assertSame(N::note(N::BENEFIT_MISATTRIBUTED, null, ['PLAN' => 'Élite', 'BENEFICIO' => 'Clases grupales']), $d[0]['note'], 'nombrado a mano: el nombre del catálogo');
    }

    /**
     * C6 · R-8 de seguridad: una nota no tumba la puerta. Si el nombre del plan
     * queda vacío al sanearlo (solo llaves), la nota lo llama por su marcador
     * si el plan es de la lista y, si no, «ese plan».
     */
    public function test_un_nombre_que_queda_vacio_se_nombra_por_su_marcador_o_ese_plan(): void
    {
        $catalogo = self::catalogo();
        $catalogo[0]['name'] = '{{}}';
        $borrador = 'El {{}} incluye clases grupales.';

        $d = D::of(['reply_draft' => $borrador, 'intent' => SalesIntents::GENERAL_INFO, 'tools_requested' => []], $borrador, self::SP, [3, 4, 8], $catalogo, '¿Qué incluye?');
        $this->assertContains('benefit_misattributed', self::codigos($d));
        $this->assertSame(N::note(N::BENEFIT_MISATTRIBUTED, null, ['PLAN' => 'PLAN_1', 'BENEFICIO' => 'Clases grupales']), $d[array_search('benefit_misattributed', self::codigos($d), true)]['note'], 'de la lista: su marcador');

        // El mismo plan, en un turno sin lista: «ese plan».
        $d = D::of(['reply_draft' => $borrador, 'intent' => SalesIntents::GENERAL_INFO, 'tools_requested' => []], $borrador, self::SP, [], $catalogo, '¿Qué incluye?');
        $this->assertSame(['benefit_misattributed'], self::codigos($d));
        $this->assertSame(N::note(N::BENEFIT_MISATTRIBUTED, null, ['PLAN' => 'ese plan', 'BENEFICIO' => 'Clases grupales']), $d[0]['note'], 'fuera de la lista: «ese plan»');
    }

    /** C6 · R-8: y si la nota lanza de todos modos, el defecto va sin nota (la puerta no responde 500). */
    public function test_si_la_nota_lanza_el_defecto_va_sin_nota(): void
    {
        $defecto = new \ReflectionMethod(D::class, 'defecto');

        $d = $defecto->invoke(null, N::BENEFIT_MISATTRIBUTED, null, ['PLAN' => '{{}}', 'BENEFICIO' => '}}']);

        $this->assertSame(['code' => N::BENEFIT_MISATTRIBUTED, 'detail' => null, 'issue' => 'factuality', 'note' => ''], $d);
        $this->assertSame('', N::merge([$d['note']]), 'y la mezcla no la cuenta');
    }

    // ── Ola 4b · las reglas de política corregibles ─────────────────────────

    /** La política mínima de un turno: lo que lee `violations()`. */
    private static function politica(array $cambios = []): array
    {
        return array_replace([
            'greet_required' => true, 'answer_first' => false, 'required_plan_id' => null, 'forbidden_plan_ids' => [],
            'required_facts' => [], 'goal_takes_turn' => false, 'reception_mode' => false, 'memory_mode' => false,
        ], $cambios);
    }

    private const HECHOS = ['Lunes a viernes de 5:00 a. m. a 10:00 p. m. Sabados de 8:00 a. m. a 2:00 p. m.'];

    /** @return array<string, array{string, array<string,mixed>, list<string>}> */
    public static function reglas(): array
    {
        $hecho = ['answer_first' => true, 'required_facts' => ['gym_identity']];

        return [
            'saludo que vende' => ['¡Hola! Bienvenido a Iron Body. El Plan Mensual es el más pedido.', ['reception_mode' => true], [P::VIOLACION_SALUDO_COMERCIAL]],
            'saludo que recibe' => ['¡Hola! Bienvenido a Iron Body. ¿En qué le puedo ayudar?', ['reception_mode' => true], []],
            'visita a medias que vende' => ['Perfecto. Mientras tanto, el Plan Mensual le sirve para empezar.', ['goal_takes_turn' => true], [P::VIOLACION_OBJETIVO_PERDIDO]],
            'visita a medias que la retoma' => ['Perfecto. ¿A qué hora le queda bien venir?', ['goal_takes_turn' => true], []],
            'otro plan primero' => ['El Plan Valera y el Plan Mensual son de un mes.', ['required_plan_id' => 4], [P::VIOLACION_PLAN_EQUIVOCADO]],
            'el obligatorio primero' => ['El Plan Mensual y el Plan Valera son de un mes.', ['required_plan_id' => 4], []],
            'un plan rechazado' => ['Con el Plan Valera puede venir cuando quiera.', ['forbidden_plan_ids' => [3]], [P::VIOLACION_PLAN_EQUIVOCADO]],
            'sin el dato' => ['Con gusto le cuento: somos un gimnasio para entrenar a su ritmo. ¿Qué le gustaría saber?', $hecho, [P::VIOLACION_SIN_HECHO]],
            'con el dato' => ['Abrimos de lunes a viernes de 5:00 a. m. a 10:00 p. m. ¿Le gustaría venir?', $hecho, []],
            'solo pregunta' => ['¿Para qué día le gustaría saber el horario?', ['answer_first' => true], [P::VIOLACION_SOLO_DESCUBRIMIENTO]],
            'pregunta con respuesta' => ['Abrimos a las 5:00 a. m. ¿Le sirve?', ['answer_first' => true], []],
            'memoria que vende' => ['No veo una visita registrada todavía. Si quiere, le cuento sobre los planes.', ['memory_mode' => true, 'answer_first' => true], [P::VIOLACION_MEMORIA_VENDE]],
            'memoria que contesta' => ['No veo una visita registrada todavía. ¿Qué día le gustaría venir?', ['memory_mode' => true, 'answer_first' => true], []],
        ];
    }

    /** @param list<string> $esperadas */
    #[DataProvider('reglas')]
    public function test_cada_regla_de_la_politica_con_su_issue_y_su_nota(string $texto, array $politica, array $esperadas): void
    {
        $d = D::ofPolicy($texto, self::SP, self::politica($politica), self::catalogo(), self::HECHOS);

        $this->assertSame($esperadas, array_column($d, 'code'));
        $this->assertSame($esperadas, array_values(array_intersect(P::violations($texto, self::politica($politica), self::catalogo(), self::HECHOS, 'profesional'), $esperadas)), 'lo mismo que violations()');
        foreach ($d as $x) {
            $this->assertNull($x['detail']);
            $this->assertSame(N::issue($x['code']), $x['issue']);
            $this->assertSame(N::note($x['code']), $x['note']);
        }
    }

    public function test_la_politica_sin_version_del_studio_ninguna(): void
    {
        $vende = '¡Hola! Bienvenido. El Plan Mensual es el más pedido.';
        $this->assertNotSame([], D::ofPolicy($vende, self::SP, self::politica(['reception_mode' => true]), self::catalogo(), []));
        $this->assertSame([], D::ofPolicy($vende, ['m' => 'legacy', 'r' => 'prompts_off'], self::politica(['reception_mode' => true]), self::catalogo(), []));
        $this->assertSame([], D::ofPolicy($vende, [], self::politica(['reception_mode' => true]), self::catalogo(), []));
    }

    /** En el orden de `POLICY_CODES`, no en el de `violations()` (que pone la memoria antes que la visita). */
    public function test_el_orden_de_la_politica(): void
    {
        $politica = self::politica(['memory_mode' => true, 'answer_first' => true, 'goal_takes_turn' => true, 'forbidden_plan_ids' => [3]]);
        $d = D::ofPolicy('¿Le cuento del Plan Valera?', self::SP, $politica, self::catalogo(), []);

        $this->assertSame([P::VIOLACION_OBJETIVO_PERDIDO, P::VIOLACION_PLAN_EQUIVOCADO, P::VIOLACION_SOLO_DESCUBRIMIENTO, P::VIOLACION_MEMORIA_VENDE], array_column($d, 'code'));
        $this->assertSame(array_column($d, 'code'), array_values(array_intersect(N::POLICY_CODES, array_column($d, 'code'))), 'un subconjunto, en el orden de POLICY_CODES');
        $this->assertLessThanOrEqual(D::MAX_DEFECTS - 4, count($d), 'como mucho cuatro de política a la vez');
    }

    /**
     * El saludo repetido no es un código (lo arregla el commit recortando), y
     * las demás se miran sobre el texto recortado, como en el commit. Hoy el
     * recorte no cambia ninguna de las seis (ninguna mira la fórmula del
     * principio): quitarlo es una mutación equivalente (M1), y se hace igual
     * para que la paridad no dependa de eso si mañana una regla la mira.
     */
    public function test_el_saludo_repetido_se_recorta_como_en_el_commit(): void
    {
        $politica = self::politica(['greet_required' => false, 'answer_first' => true]);
        $texto = 'Buenas tardes. Abrimos de lunes a viernes de 5:00 a. m. a 10:00 p. m. ¿Le sirve?';
        $this->assertContains(P::VIOLACION_SALUDO_REPETIDO, P::violations($texto, $politica, self::catalogo(), [], 'profesional'));

        $this->assertSame([], D::ofPolicy($texto, self::SP, $politica, self::catalogo(), []));
        $this->assertSame([P::VIOLACION_SOLO_DESCUBRIMIENTO], array_column(D::ofPolicy('Buenas tardes. ¿Qué le gustaría saber?', self::SP, $politica, self::catalogo(), []), 'code'));
    }

    /**
     * El commit mira la política DESPUÉS de quitar la pregunta final a un
     * borrador que se pasa del tope (F1) o que tutea solo en ella con un bloque
     * de Laravel: la comprobación mira ese mismo texto.
     */
    public function test_mira_el_texto_que_miraria_el_commit_tras_sus_recortes(): void
    {
        $recepcion = self::politica(['reception_mode' => true]);
        $largo = 'Hola, muy buenas tardes. Bienvenido a Iron Body, qué gusto atenderle por aquí. Estamos para resolver cualquier duda que tenga sobre el gimnasio y para acompañarle desde el primer día, con calma. ¿Cuál es su objetivo?';
        $this->assertGreaterThan(200, UltronReplyFitter::measure($largo));
        $this->assertNotNull(UltronReplyFitter::draft($largo, 200), 'el commit lo recorta (F1)');

        $this->assertSame([], D::ofPolicy($largo, self::SP, $recepcion, self::catalogo(), []), 'con tope 200: la pregunta del objetivo no llega a la política');
        $this->assertSame([P::VIOLACION_SALUDO_COMERCIAL], array_column(D::ofPolicy($largo, ['n' => 480] + self::SP, $recepcion, self::catalogo(), []), 'code'), 'con 480 cabe entero y vende');

        // El registro: el tuteo SOLO en la pregunta final y un bloque de Laravel (los enlaces de la app) → el commit la quita.
        $corto = 'Hola, bienvenido a Iron Body, qué gusto atenderle. ¿Cuál es tu objetivo?';
        $this->assertSame([], D::ofPolicy($corto, self::SP, $recepcion, self::catalogo(), [], true), 'con el bloque: recortada');
        $this->assertSame([P::VIOLACION_SALUDO_COMERCIAL], array_column(D::ofPolicy($corto, self::SP, $recepcion, self::catalogo(), [], false), 'code'), 'sin bloque: el commit la descarta entera y la política la ve');
        $this->assertSame([P::VIOLACION_SALUDO_COMERCIAL], array_column(D::ofPolicy($corto, ['t' => 'cercano'] + self::SP, $recepcion, self::catalogo(), [], true), 'code'), 'sin «profesional» no hay recorte por registro');
    }

    public function test_el_entrante_con_datos_personales_no_sale_en_las_notas(): void
    {
        $entrante = 'Soy Ramiro Pérez, mi celular es 3001234567. ¿Qué planes tienen y cuánto valen?';
        $d = self::defectos('Hola Ramiro, te cuento: {{PLAN_1}}. '.str_repeat('Muy completo. ', 20), entrante: $entrante);
        $this->assertNotSame([], $d);
        foreach ($d as $x) {
            $this->assertStringNotContainsString('3001234567', $x['note']);
            $this->assertStringNotContainsString('Ramiro', $x['note']);
        }
    }
}
