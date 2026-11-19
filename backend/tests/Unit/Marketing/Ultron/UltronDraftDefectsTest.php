<?php

namespace Tests\Unit\Marketing\Ultron;

use App\Services\Marketing\SalesIntents;
use App\Services\Marketing\Ultron\UltronDraftDefects as D;
use App\Services\Marketing\Ultron\UltronPlansOverview as O;
use App\Services\Marketing\Ultron\UltronRegenerationNotes as N;
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
        $this->assertSame(['plans_overview/name_written'], self::codigos(self::defectos($aMano)), 'pidiendo el precio, sí');
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
