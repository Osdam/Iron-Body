<?php

namespace Tests\Unit\Marketing\Ultron;

use App\Models\Plan;
use App\Services\Marketing\Ultron\UltronDraftPlaceholders as M;
use PHPUnit\Framework\TestCase;

/**
 * «REGENERAR ANTES DE SUSTITUIR» · CORRECCIONES TRAS LAS REVISIONES · C1
 * (encargo `encargo-be-r6.md`; R-1 de seguridad e I-1 del revisor): NINGÚN
 * MARCADOR PUEDE SALIR POR WHATSAPP.
 *
 * Lo que se fija, en las piezas puras de `UltronDraftPlaceholders`:
 *  1. La forma de marcador que la red del commit no deja salir: `{{` o `}}`,
 *     `{ PLAN_`, `PLAN_1`, `PLAN_2`… sueltos, y `PLAN_PRICE`, `PLAN_NAME`,
 *     `PLAN_DURATION` o `APP_LINKS` sueltos. Lo que un cliente lee de verdad
 *     (los nombres del catálogo, los precios, las llaves sueltas de un texto) no
 *     la tiene.
 *  2. El texto sin marcadores, para cuando no hay último recurso que mandar
 *     (hoy no se alcanza): ni un marcador ni una llave.
 *  3. El nombre del plan pierde las llaves al resolver `{{PLAN_NAME}}` SOLO si
 *     quien llama lo pide (un turno del Studio); sin pedirlo, lo de hoy.
 *
 * Pura: sin base de datos (los `Plan` son instancias en memoria), sin config,
 * sin reloj.
 */
class UltronDraftPlaceholdersTest extends TestCase
{
    private static function plan(string $nombre, float $precio = 65000, int $dias = 30): Plan
    {
        return (new Plan)->forceFill(['name' => $nombre, 'price' => $precio, 'duration_days' => $dias]);
    }

    public function test_lo_que_tiene_forma_de_marcador_no_sale(): void
    {
        foreach ([
            'las dos llaves que abren' => 'Tenemos {{PLAN_1}} y otro.',
            'solo las que cierran' => 'Tenemos el plan }} de un mes.',
            'solo las que abren' => 'Le dejo {{ la app.',
            'PLAN_1 sin llaves (I-1, sonda S4)' => 'Claro. Tenemos PLAN_1 y PLAN_2, los dos de un mes. Le dejo la app.',
            'con una llave (I-1, sonda S5)' => 'Claro. Tenemos {PLAN_1} y {PLAN_2}, los dos de un mes.',
            'con una llave y espacios' => 'Tenemos { PLAN_3 } hoy.',
            'el precio suelto' => 'El plan cuesta PLAN_PRICE al mes.',
            'el nombre suelto' => 'El PLAN_NAME es el más pedido.',
            'la duración suelta' => 'Dura PLAN_DURATION.',
            'los enlaces sueltos' => 'Aquí los tiene: APP_LINKS',
            'en minúsculas' => 'Tenemos plan_1 y plan_2.',
            'pegado a la puntuación' => '¿PLAN_2?',
            'en un nombre del catálogo' => 'Tenemos Plan PLAN_PRICE ($65.000 COP).',
            // PT-17, puerta 2 (revisión de seguridad, B1): sin `\b`, dentro de otra palabra también es un marcador (antes no lo era).
            'una palabra que empieza igual' => 'Le mando PLAN_PRICES_X y PLAN_10X por error de copia.',
        ] as $caso => $texto) {
            $this->assertTrue(M::looksLikeMarker($texto), $caso);
        }

        foreach ([
            'la lista expandida' => 'Claro. Tenemos Plan Valera ($65.000 COP), Plan Mensual ($80.000 COP) y Élite ($180.000 COP), todos de un mes. ¿Cuál le interesa más?',
            'el plan del día' => 'El PLAN DÍA cuesta $10.000 COP.',
            'un plan con número' => 'El Plan 1 y el Plan 2 son de un mes.',
            'una llave suelta' => 'Horario {lunes a viernes}: de 5:00 a. m. a 10:00 p. m.',
            'los enlaces de verdad' => "Android: https://play.google.com/store/apps/details?id=com.ironbody\niPhone: https://apps.apple.com/app/id1",
            'vacío' => '',
        ] as $caso => $texto) {
            $this->assertFalse(M::looksLikeMarker($texto), $caso);
        }
    }

    public function test_sin_marcadores_no_queda_ni_un_marcador_ni_una_llave(): void
    {
        foreach ([
            'Claro. Tenemos PLAN_1 y PLAN_2, los dos de un mes. Le dejo la app.',
            'Claro. Tenemos {PLAN_1} y {PLAN_2}, los dos de un mes.',
            'Tenemos {{PLAN_1}}, {{ PLAN_PRICE }} y APP_LINKS. }}',
        ] as $texto) {
            $limpio = M::withoutMarkers($texto);
            $this->assertFalse(M::looksLikeMarker($limpio), $texto);
            $this->assertDoesNotMatchRegularExpression('/[{}]/', $limpio, $texto);
            $this->assertStringNotContainsStringIgnoringCase('PLAN_', $limpio, $texto);
            $this->assertStringNotContainsString('  ', $limpio, 'sin espacios dobles');
            $this->assertSame(trim($limpio), $limpio);
        }
        $this->assertSame('Claro. Tenemos y , los dos de un mes. Le dejo la app.', M::withoutMarkers('Claro. Tenemos PLAN_1 y PLAN_2, los dos de un mes. Le dejo la app.'));
        $this->assertSame('Un texto sin nada.', M::withoutMarkers('Un texto sin nada.'), 'lo que no tiene forma de marcador, tal cual');
    }

    public function test_el_nombre_pierde_las_llaves_solo_si_se_pide(): void
    {
        $p = new M;
        $borrador = 'El {{PLAN_NAME}} cuesta {{PLAN_PRICE}} y dura {{PLAN_DURATION}}.';

        $this->assertSame('El Plan Oro cuesta $65.000 COP y dura un mes.', $p->resolve($borrador, self::plan('Plan {{Oro}}'), true));
        $this->assertSame('El Plan APP_LINKS cuesta $65.000 COP y dura un mes.', $p->resolve($borrador, self::plan('Plan {{APP_LINKS}}'), true));
        // Sin pedirlo (Legacy), lo de hoy, byte a byte: el nombre tal cual.
        $this->assertSame('El Plan {{Oro}} cuesta $65.000 COP y dura un mes.', $p->resolve($borrador, self::plan('Plan {{Oro}}')));
        $this->assertSame($p->resolve($borrador, self::plan('Plan {{Oro}}')), $p->resolve($borrador, self::plan('Plan {{Oro}}'), false));
        // Un nombre sin llaves sale igual de las dos formas.
        $this->assertSame($p->resolve($borrador, self::plan('Plan Valera')), $p->resolve($borrador, self::plan('Plan Valera'), true));

        $this->assertSame('Plan Oro', M::nameWithoutBraces(' Plan {{ Oro }} '));
        $this->assertSame('', M::nameWithoutBraces('{{}}'));
    }
}
