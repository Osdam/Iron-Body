<?php

namespace Tests\Unit\Marketing\Ultron;

use App\Services\Marketing\Ultron\UltronBenefitAttribution as B;
use PHPUnit\Framework\TestCase;

/**
 * «REGENERAR ANTES DE SUSTITUIR» · OLA 1 · P-4: EL BENEFICIO DE OTRO PLAN
 * (plan del arquitecto `plan-arquitecto-a4-regenerar.md` §3 P-4; decisión
 * L-5 del lead: la condición 3 se cumple solo en parte en los beneficios, y el
 * límite se declara, no se rebaja en silencio).
 *
 * La regla, literal y por frase: en una frase que nombra EXACTAMENTE un plan,
 * un beneficio del catálogo de OTRO plan, de al menos dos palabras, escrito
 * por palabras completas (sin tildes ni mayúsculas), que ningún beneficio del
 * plan nombrado contiene ni está contenido en él. Las frases con negación no
 * se miran. Los límites (paráfrasis, pronombre en la frase siguiente, frases
 * con dos planes) se fijan aquí como «no marca».
 *
 * Pura: el catálogo lo pasa quien llama (`activePlans()`: id, name, benefits).
 */
class UltronBenefitAttributionTest extends TestCase
{
    /** @return list<array{id:int,name:string,benefits:list<string>}> */
    private static function catalogo(): array
    {
        return [
            ['id' => 3, 'name' => 'Plan Valera', 'benefits' => ['Acceso ilimitado al gimnasio', 'Asesoría de entrenador']],
            ['id' => 8, 'name' => 'Élite', 'benefits' => ['Clases grupales', 'Acceso ilimitado al gimnasio', 'Plan nutricional personalizado', 'Sauna']],
            ['id' => 9, 'name' => 'Plan Semana', 'benefits' => ['Siete días de acceso al gimnasio y clases grupales']],
        ];
    }

    public function test_el_beneficio_propio_no_marca(): void
    {
        $this->assertSame([], B::misattributed('El Plan Valera incluye asesoría de entrenador.', self::catalogo()));
        $this->assertSame([], B::misattributed('El Élite trae clases grupales y plan nutricional personalizado.', self::catalogo()));
    }

    public function test_el_beneficio_de_otro_plan_marca_con_su_dueno_y_su_indice(): void
    {
        $this->assertSame(
            [['plan_id' => 3, 'benefit_plan_id' => 8, 'benefit_index' => 0, 'benefit' => 'Clases grupales']],
            B::misattributed('Claro. El Plan Valera incluye clases grupales. ¿Le interesa?', self::catalogo()),
        );
        $this->assertSame(
            [['plan_id' => 8, 'benefit_plan_id' => 3, 'benefit_index' => 1, 'benefit' => 'Asesoría de entrenador']],
            B::misattributed('El Élite le da asesoría de entrenador.', self::catalogo()),
        );
    }

    public function test_tildes_y_mayusculas_no_cuentan_pero_las_palabras_completas_si(): void
    {
        $this->assertSame(8, B::misattributed('EL ÉLITE INCLUYE ASESORIA DE ENTRENADOR.', self::catalogo())[0]['plan_id'] ?? null);
        $this->assertSame(3, B::misattributed('El plan valera trae PLAN NUTRICIONÁL personalizado.', self::catalogo())[0]['plan_id'] ?? null);
        $this->assertSame([], B::misattributed('El Plan Valera incluye clases grupalesxl.', self::catalogo()), 'palabra completa');
        $this->assertSame([], B::misattributed('El Plan Valera incluye subclases grupales.', self::catalogo()), 'palabra completa');
    }

    public function test_por_la_expansion_de_su_marcador(): void
    {
        $r = B::misattributed('Tenemos Plan Valera ($65.000 COP), con clases grupales, y Élite ($180.000 COP).', self::catalogo());
        $this->assertSame([], $r, 'la frase nombra dos planes: límite declarado');

        $r = B::misattributed('Tenemos Plan Valera ($65.000 COP), con clases grupales. También Élite ($180.000 COP).', self::catalogo());
        $this->assertSame([['plan_id' => 3, 'benefit_plan_id' => 8, 'benefit_index' => 0, 'benefit' => 'Clases grupales']], $r);
    }

    public function test_un_beneficio_compartido_o_contenido_no_marca(): void
    {
        $this->assertSame([], B::misattributed('El Plan Valera incluye acceso ilimitado al gimnasio.', self::catalogo()), 'los dos lo tienen');
        // «Clases grupales» (del Élite) está contenido en un beneficio del Plan Semana: no marca.
        $this->assertSame([], B::misattributed('El Plan Semana trae clases grupales.', self::catalogo()));
        // Y al revés: el del Plan Semana contiene «clases grupales», pero «Sauna» es de una sola palabra.
        $this->assertSame([], B::misattributed('El Plan Valera tiene sauna.', self::catalogo()), 'de una sola palabra no se mira');
    }

    public function test_las_frases_con_negacion_no_se_miran(): void
    {
        foreach ([
            'El Plan Valera no incluye clases grupales.',
            'El Plan Valera viene sin clases grupales.',
            'El Plan Valera, ni clases grupales ni sauna.',
            'El Plan Valera lo trae todo excepto clases grupales.',
            'El Plan Valera lo trae todo salvo clases grupales.',
            'El Plan Valera NO trae clases grupales.',
        ] as $texto) {
            $this->assertSame([], B::misattributed($texto, self::catalogo()), $texto);
        }
        // «nota», «sino» o «nivel» no son negaciones: por palabra completa.
        $this->assertCount(1, B::misattributed('El Plan Valera, a nivel de clases grupales, cumple.', self::catalogo()));
    }

    public function test_los_limites_declarados_no_marcan(): void
    {
        $c = self::catalogo();
        $this->assertSame([], B::misattributed('El Plan Valera trae clases en grupo.', $c), 'paráfrasis');
        $this->assertSame([], B::misattributed('El Plan Valera es el más completo. Además incluye clases grupales.', $c), 'pronombre o elipsis en la frase siguiente');
        $this->assertSame([], B::misattributed('El Plan Valera y el Élite traen clases grupales.', $c), 'dos planes en la frase');
        $this->assertSame([], B::misattributed('El Plan Valera incluye piscina olímpica.', $c), 'un beneficio que no es de ningún plan: eso es de otra guarda');
        $this->assertSame([], B::misattributed('Tenemos clases grupales.', $c), 'una frase sin plan');
    }

    public function test_varias_frases_y_sin_repetir(): void
    {
        $r = B::misattributed('El Plan Valera incluye clases grupales. El Élite tiene asesoría de entrenador. El Plan Valera, otra vez clases grupales.', self::catalogo());
        $this->assertSame([
            ['plan_id' => 3, 'benefit_plan_id' => 8, 'benefit_index' => 0, 'benefit' => 'Clases grupales'],
            ['plan_id' => 8, 'benefit_plan_id' => 3, 'benefit_index' => 1, 'benefit' => 'Asesoría de entrenador'],
        ], $r);
    }

    public function test_un_catalogo_raro_no_rompe(): void
    {
        $raro = [
            ['id' => 1, 'name' => 'Uno', 'benefits' => null],
            ['id' => 2, 'name' => 'Dos', 'benefits' => ['', '   ', 7, ['x'], 'Clases grupales']],
            ['id' => 3, 'name' => '', 'benefits' => ['Acceso total']],
        ];
        $this->assertSame([['plan_id' => 1, 'benefit_plan_id' => 2, 'benefit_index' => 4, 'benefit' => 'Clases grupales']], B::misattributed('El plan Uno trae clases grupales.', $raro));
        $this->assertSame([], B::misattributed('', $raro));
        $this->assertSame([], B::misattributed('El plan Uno trae clases grupales.', []));
    }
}
