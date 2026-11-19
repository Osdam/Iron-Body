<?php

namespace Tests\Unit\Marketing\Ultron;

use App\Services\Marketing\Ultron\CriticContract;
use App\Services\Marketing\Ultron\UltronPlansOverview;
use App\Services\Marketing\Ultron\UltronRegenerationNotes as N;
use App\Services\Marketing\Ultron\UltronRegisterDetector;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * «REGENERAR ANTES DE SUSTITUIR» · OLA 1 · LAS NOTAS DE REESCRITURA (plan del
 * arquitecto `plan-arquitecto-a4-regenerar.md` §3 P-5, §4.1, §4.4 y §7.2).
 *
 * Lo que se fija:
 *  1. Los códigos y los detalles son listas cerradas, y cada uno da un `issue`
 *     del vocabulario del revisor (`CriticContract::DIMENSIONS`).
 *  2. Cada nota es el texto LITERAL de §7.2 (los aprobará el propietario en el
 *     gate 4-H: aquí se copian a mano para que un retoque no pase sin verse).
 *  3. Los parámetros: de vocabulario cerrado o números; el texto del catálogo
 *     sin llaves y a 60 caracteres. Ninguna nota lleva `{{` ni llaves: no
 *     pueden formar un marcador ni un hueco de plantilla.
 *  4. La mezcla: en orden, por notas enteras y a 500 caracteres
 *     (`CriticContract::MAX_NOTES`).
 */
class UltronRegenerationNotesTest extends TestCase
{
    // Los textos de §7.2, copiados a mano del plan.
    private const N_R = 'REESCRIBE DE USTED: la version publicada trata a la persona de usted y el borrador tutea (te, quieres). Pasa todo el mensaje a usted sin mezclar (le, su, usted, cuenteme, quiere) y conserva lo que decia.';

    private const N_L = 'DEMASIADO LARGO: el borrador mide 243 caracteres y el limite publicado es 200. Reescribelo en unos 160: primero la respuesta a lo que pregunto la persona, sin relleno, y como mucho una pregunta corta al final. Cada marcador cuenta como el texto que lo sustituye.';

    private const N_P1 = 'FALTA LA LISTA DE PLANES: la persona pidio los planes o los precios en general. Nombra al menos dos planes de commercial_turn_policy.plans_overview, en su orden, escribiendo solo sus marcadores PLAN_1, PLAN_2 o PLAN_3 entre dobles llaves; el backend pone el nombre y el precio. Sin PLAN_NAME ni PLAN_PRICE.';

    private const N_P2 = 'MARCADORES MEZCLADOS: con la lista de planes usa solo PLAN_1, PLAN_2 y PLAN_3 entre dobles llaves, cada uno una sola vez, sin PLAN_NAME, PLAN_PRICE ni PLAN_DURATION.';

    private const N_P3 = 'NOMBRE DUPLICADO: no escribas el nombre de un plan de la lista; su marcador ya pone el nombre y el precio.';

    private const N_P4 = 'MARCADOR INEXISTENTE: este turno solo tiene PLAN_1 a PLAN_3. Usa solo esos, entre dobles llaves.';

    private const N_P5 = 'SIN LISTA DE PLANES: en este turno no uses PLAN_1, PLAN_2 ni PLAN_3. Para un precio usa PLAN_PRICE con el plan de la estrategia.';

    private const N_P6 = 'SIN CIFRAS: no escribas precios ni cifras de dinero; el backend los pone con los marcadores.';

    private const N_B = 'BENEFICIO DE OTRO PLAN: el borrador atribuye a PLAN_2 un beneficio que en active_plans es de otro plan ("Clases grupales"). A cada plan, solo los benefits de su plan_id.';

    public function test_los_codigos_y_los_detalles_son_listas_cerradas(): void
    {
        $this->assertSame(['register_tuteo', 'length_over_cap', 'plans_overview', 'benefit_misattributed'], N::CODES);
        $this->assertSame(['missing', 'mixed', 'name_written', 'unknown_marker', 'no_list', 'price_written'], N::DETAILS);
        $this->assertSame(UltronPlansOverview::DETAILS, N::DETAILS);
        $this->assertSame(CriticContract::MAX_NOTES, N::MAX_NOTES);
    }

    public function test_cada_codigo_da_un_issue_del_vocabulario_del_revisor(): void
    {
        $this->assertSame('naturalness', N::issue(N::REGISTER_TUTEO));
        $this->assertSame('progressive_disclosure', N::issue(N::LENGTH_OVER_CAP));
        $this->assertSame('factuality', N::issue(N::BENEFIT_MISATTRIBUTED));
        foreach (N::DETAILS as $d) {
            $this->assertSame($d === UltronPlansOverview::PRICE_WRITTEN ? 'factuality' : 'relevance', N::issue(N::PLANS_OVERVIEW, $d), $d);
        }
        foreach (N::entries() as [$code, $detail]) {
            $this->assertContains(N::issue($code, $detail), CriticContract::DIMENSIONS, $code.'/'.$detail);
        }
        $this->assertCount(4 - 1 + 6, N::entries(), 'tres códigos sin detalle y los seis detalles del panorama');
    }

    public function test_cada_nota_es_la_literal_del_plan(): void
    {
        $this->assertSame(self::N_R, N::note(N::REGISTER_TUTEO, null, ['MARCAS' => ['te', 'quieres']]));
        $this->assertSame(self::N_L, N::note(N::LENGTH_OVER_CAP, null, ['L' => 243, 'N' => 200, 'M' => 160]));
        $this->assertSame(self::N_P1, N::note(N::PLANS_OVERVIEW, UltronPlansOverview::MISSING));
        $this->assertSame(self::N_P2, N::note(N::PLANS_OVERVIEW, UltronPlansOverview::MIXED));
        $this->assertSame(self::N_P3, N::note(N::PLANS_OVERVIEW, UltronPlansOverview::NAME_WRITTEN));
        $this->assertSame(self::N_P4, N::note(N::PLANS_OVERVIEW, UltronPlansOverview::UNKNOWN_MARKER, ['K' => 3]));
        $this->assertSame(self::N_P5, N::note(N::PLANS_OVERVIEW, UltronPlansOverview::NO_LIST));
        $this->assertSame(self::N_P6, N::note(N::PLANS_OVERVIEW, UltronPlansOverview::PRICE_WRITTEN));
        $this->assertSame(self::N_B, N::note(N::BENEFIT_MISATTRIBUTED, null, ['PLAN' => 'PLAN_2', 'BENEFICIO' => 'Clases grupales']));
    }

    public function test_las_marcas_son_de_la_lista_cerrada_y_como_mucho_cinco(): void
    {
        $nota = N::note(N::REGISTER_TUTEO, null, ['MARCAS' => ['tú', 'te', 'quieres', 'puedes', 'tienes', 'dime', 'cuéntame']]);
        $this->assertStringContainsString('el borrador tutea (tú, te, quieres, puedes, tienes). Pasa', $nota);
        $nota = N::note(N::REGISTER_TUTEO, null, ['MARCAS' => ['te', '{{PLAN_1}}', 'Juan', '3001234567', 'te']]);
        $this->assertStringContainsString('tutea (te). Pasa', $nota, 'lo que no es de la lista cerrada no entra, y sin repetir');
        $this->assertSame(UltronRegisterDetector::MAX_MARCAS, 5);

        $this->expectException(InvalidArgumentException::class);
        N::note(N::REGISTER_TUTEO, null, ['MARCAS' => ['Juan', 'hola']]);
    }

    public function test_el_texto_del_catalogo_sin_llaves_y_a_sesenta(): void
    {
        $nota = N::note(N::BENEFIT_MISATTRIBUTED, null, ['PLAN' => "Plan {{PLAN_1}}\nValera", 'BENEFICIO' => '{Acceso} {{ilimitado}} '.str_repeat('a', 80)]);
        $this->assertStringNotContainsString('{', $nota);
        $this->assertStringNotContainsString('}', $nota);
        $this->assertStringNotContainsString("\n", $nota);
        $this->assertStringContainsString('atribuye a Plan PLAN_1 Valera un beneficio', $nota);
        preg_match('/\("([^"]*)"\)/', $nota, $m);
        $this->assertSame(60, mb_strlen($m[1]), 'el beneficio, a 60 caracteres');
        $this->assertStringStartsWith('Acceso ilimitado aaaa', $m[1]);

        $nota = N::note(N::BENEFIT_MISATTRIBUTED, null, ['PLAN' => 'Élite', 'BENEFICIO' => 'Asesoría de entrenador']);
        $this->assertStringContainsString('atribuye a Élite un beneficio que en active_plans es de otro plan ("Asesoría de entrenador")', $nota);
    }

    public function test_los_parametros_se_exigen_exactos(): void
    {
        $malos = [
            'falta un parámetro' => [N::LENGTH_OVER_CAP, null, ['L' => 243, 'N' => 200]],
            'sobra un parámetro' => [N::PLANS_OVERVIEW, UltronPlansOverview::MISSING, ['K' => 3]],
            'número como texto' => [N::LENGTH_OVER_CAP, null, ['L' => '243', 'N' => 200, 'M' => 160]],
            'número negativo' => [N::PLANS_OVERVIEW, UltronPlansOverview::UNKNOWN_MARKER, ['K' => -1]],
            'texto vacío' => [N::BENEFIT_MISATTRIBUTED, null, ['PLAN' => '  ', 'BENEFICIO' => 'Clases grupales']],
            'texto que no es texto' => [N::BENEFIT_MISATTRIBUTED, null, ['PLAN' => 2, 'BENEFICIO' => 'Clases grupales']],
            'código desconocido' => ['policy_greeting_sells', null, []],
            'panorama sin detalle' => [N::PLANS_OVERVIEW, null, []],
            'detalle desconocido' => [N::PLANS_OVERVIEW, 'otro', []],
            'detalle en otro código' => [N::LENGTH_OVER_CAP, UltronPlansOverview::MISSING, ['L' => 243, 'N' => 200, 'M' => 160]],
        ];
        foreach ($malos as $nombre => [$code, $detail, $params]) {
            try {
                N::note($code, $detail, $params);
                $this->fail("{$nombre}: no lanzó");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(InvalidArgumentException::class);
        N::issue('policy_greeting_sells');
    }

    /** Ninguna nota, con los parámetros más hostiles, lleva `{{` ni una llave suelta. */
    public function test_ninguna_nota_contiene_llaves(): void
    {
        $hostil = ['MARCAS' => ['te', 'tu'], 'L' => PHP_INT_MAX, 'N' => 480, 'M' => 380, 'K' => 3, 'PLAN' => '{{PLAN_PRICE}}}', 'BENEFICIO' => '{{APP_LINKS}} {x}'];
        $vistas = 0;
        foreach (N::entries() as [$code, $detail, $params]) {
            $nota = N::note($code, $detail, array_intersect_key($hostil, array_flip($params)));
            $this->assertStringNotContainsString('{{', $nota, $code.'/'.$detail);
            $this->assertDoesNotMatchRegularExpression('/[{}]/', $nota, $code.'/'.$detail);
            $this->assertLessThanOrEqual(N::MAX_NOTES, mb_strlen($nota), 'cada nota cabe sola');
            $vistas++;
        }
        $this->assertSame(9, $vistas);
    }

    public function test_la_mezcla_va_en_orden_por_notas_enteras_y_a_quinientos(): void
    {
        $this->assertSame('', N::merge([]));
        $this->assertSame(self::N_R.' '.self::N_L, N::merge([self::N_R, self::N_L]));
        $this->assertSame(mb_strlen(self::N_R) + 1 + mb_strlen(self::N_L), mb_strlen(N::merge([self::N_R, self::N_L])));

        // R + L + P1 no caben en 500: P1 se queda fuera entera y entra la siguiente que cabe.
        $mezcla = N::merge([self::N_R, self::N_L, self::N_P1, self::N_P3]);
        $this->assertGreaterThan(500, mb_strlen(self::N_R.' '.self::N_L.' '.self::N_P1));
        $this->assertSame(self::N_R.' '.self::N_L, $mezcla, 'P3 tampoco cabe tras R y L');
        $this->assertLessThanOrEqual(500, mb_strlen($mezcla));

        $this->assertSame(self::N_P3.' '.self::N_P6, N::merge([self::N_P3, '', '   ', self::N_P6]), 'las vacías no cuentan');
        $this->assertSame(self::N_P2, N::merge([str_repeat('x', 501), self::N_P2]), 'una que no cabe sola se salta');
        $this->assertSame(500, mb_strlen(N::merge([str_repeat('é', 500)])), 'caracteres, no bytes');
    }

    /** El mecanismo es genérico: cada entrada declara sus parámetros y su texto los usa todos, y nada más. */
    public function test_cada_entrada_declara_los_huecos_de_su_texto(): void
    {
        foreach (N::entries() as [$code, $detail, $params, $plantilla]) {
            preg_match_all('/\{([A-Z]+)\}/', $plantilla, $m);
            $huecos = array_values(array_unique($m[1]));
            sort($huecos);
            $declarados = $params;
            sort($declarados);
            $this->assertSame($declarados, $huecos, $code.'/'.$detail);
            $this->assertStringNotContainsString('{{', $plantilla);
        }
    }
}
