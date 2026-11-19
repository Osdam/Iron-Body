<?php

namespace Tests\Unit\Marketing\Ultron;

use App\Models\Plan;
use App\Services\Marketing\Ultron\UltronDraftPlaceholders;
use App\Services\Marketing\Ultron\UltronPlansOverview as O;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClassConstant;

/**
 * «REGENERAR ANTES DE SUSTITUIR» · OLA 1 · LA LISTA DE PLANES QUE REDACTA EL
 * MODELO (plan del arquitecto `plan-arquitecto-a4-regenerar.md` §3 P-3, §4.4,
 * §6 «Ola 1» y §7.3; decisión L-2 del lead).
 *
 * Lo que se fija:
 *  1. PARIDAD: `select()` es la selección de `respaldoDePrecios()` tal como
 *     estaba en `97b59195` (copiada aquí, congelada, como oráculo), sobre una
 *     matriz de catálogos: el nombrado, los rechazados, sin planes de un mes,
 *     más de tres, ids como texto y políticas vacías. Lo mismo con
 *     `candidates()`, el catálogo del que sale.
 *  2. Los marcadores `{{PLAN_1}}`…`{{PLAN_3}}`: posicionales y casables por el
 *     patrón de `UltronDraftPlaceholders` (los de dos puntos no lo son).
 *  3. La expansión: «Nombre ($65.000 COP)», con la duración si el plan no es
 *     de 28 a 31 días y «1 día» en singular, el precio con `formatCop()`.
 *  4. `verify()`: cada uno de los seis detalles de §4.4 y su precedencia.
 *
 * Pura: sin base de datos (los `Plan` son instancias en memoria), sin config,
 * sin reloj.
 */
class UltronPlansOverviewTest extends TestCase
{
    /** @return list<array{id:int,name:string,duration_days:int,benefits:list<string>}> */
    private static function catalogo(): array
    {
        return [
            ['id' => 3, 'name' => 'Plan Valera', 'duration_days' => 30, 'benefits' => ['Acceso ilimitado al gimnasio']],
            ['id' => 4, 'name' => 'Plan Mensual', 'duration_days' => 30, 'benefits' => ['Clases grupales']],
            ['id' => 8, 'name' => 'Élite', 'duration_days' => 30, 'benefits' => ['Plan nutricional personalizado']],
            ['id' => 9, 'name' => 'Plan Semana', 'duration_days' => 7, 'benefits' => []],
        ];
    }

    /**
     * EL ORÁCULO: la selección de `UltronCommitService::respaldoDePrecios()`
     * en `97b59195` (líneas 5449-5466), copiada letra a letra. No se toca: es
     * lo que `select()` tiene que seguir dando.
     *
     * @return array{0:list<int>,1:list<array<string,mixed>>} [elegidos, catálogo]
     */
    private static function seleccionDeHoy(array $policy, array $activePlans): array
    {
        $nombrado = ($policy['hard_required_plan'] ?? null) === null ? null : (int) $policy['hard_required_plan'];
        $rechazados = array_map('intval', (array) ($policy['forbidden_plan_ids'] ?? []));
        $catalogo = array_values(array_filter(
            $activePlans,
            fn (array $p) => (int) $p['id'] === $nombrado || ! in_array((int) $p['id'], $rechazados, true),
        ));
        $ids = array_map(fn (array $p) => (int) $p['id'], $catalogo);
        $deUnMes = fn (array $p) => (int) $p['duration_days'] >= 28 && (int) $p['duration_days'] <= 31;
        $hayDeUnMes = array_filter($catalogo, $deUnMes) !== [];

        $elegidos = $nombrado !== null && in_array($nombrado, $ids, true) ? [$nombrado] : [];
        foreach ($catalogo as $p) {
            if (count($elegidos) < 3 && ($deUnMes($p) || ! $hayDeUnMes) && ! in_array((int) $p['id'], $elegidos, true)) {
                $elegidos[] = (int) $p['id'];
            }
        }

        return [$elegidos, $catalogo];
    }

    private static function plan(string $nombre, float $precio, int $dias): Plan
    {
        return (new Plan)->forceFill(['name' => $nombre, 'price' => $precio, 'duration_days' => $dias]);
    }

    // ── 1. Paridad de la selección ──────────────────────────────────────────

    public function test_select_es_la_seleccion_de_hoy_en_los_casos_con_nombre(): void
    {
        $c = self::catalogo();
        $casos = [
            'sin política' => [[], [3, 4, 8]],
            'nombrado el de una semana' => [['hard_required_plan' => 9], [9, 3, 4]],
            'nombrado uno de un mes' => [['hard_required_plan' => 8], [8, 3, 4]],
            'rechazado el primero' => [['forbidden_plan_ids' => [3]], [4, 8]],
            'nombrado y rechazado a la vez: nombrarlo lo reabre' => [['hard_required_plan' => 3, 'forbidden_plan_ids' => [3, 4]], [3, 8]],
            'nombrado uno que no está' => [['hard_required_plan' => 99], [3, 4, 8]],
            'ids como texto' => [['hard_required_plan' => '9', 'forbidden_plan_ids' => ['3']], [9, 4, 8]],
            'todos rechazados' => [['forbidden_plan_ids' => [3, 4, 8, 9]], []],
            'sin planes de un mes: los tres primeros' => [['forbidden_plan_ids' => [3, 4, 8]], [9]],
        ];
        foreach ($casos as $nombre => [$policy, $esperado]) {
            $this->assertSame($esperado, O::select($policy, $c), $nombre);
            $this->assertSame(self::seleccionDeHoy($policy, $c)[0], O::select($policy, $c), $nombre.' (oráculo)');
        }
        $this->assertSame([], O::select([], []), 'sin catálogo');
    }

    /**
     * La matriz: 4 000 catálogos de 0 a 7 planes con duraciones de 1 a 365
     * días (también ninguno de un mes y más de tres de un mes), con el
     * nombrado (ninguno, uno del catálogo, uno que no está, uno rechazado, 0)
     * y los rechazados (ninguno, sueltos, como texto, como escalar). Semilla
     * fija: determinista.
     */
    public function test_select_y_candidates_son_los_de_hoy_sobre_una_matriz_de_catalogos(): void
    {
        mt_srand(20261119);
        $duraciones = [1, 5, 7, 9, 15, 21, 27, 28, 29, 30, 30, 31, 32, 60, 90, 180, 365];
        $vistos = ['sin_mes' => 0, 'mas_de_tres_de_mes' => 0, 'nombrado_rechazado' => 0, 'vacio' => 0];
        for ($i = 0; $i < 4000; $i++) {
            $n = mt_rand(0, 7);
            $ids = [];
            while (count($ids) < $n) {
                $ids[mt_rand(1, 40)] = true;
            }
            $catalogo = [];
            foreach (array_keys($ids) as $id) {
                $catalogo[] = ['id' => $id, 'name' => 'P'.$id, 'duration_days' => $duraciones[mt_rand(0, count($duraciones) - 1)], 'benefits' => []];
            }
            $policy = [];
            $tipo = mt_rand(0, 4);
            if ($tipo === 1 && $catalogo !== []) {
                $policy['hard_required_plan'] = $catalogo[mt_rand(0, count($catalogo) - 1)]['id'];
            } elseif ($tipo === 2) {
                $policy['hard_required_plan'] = 99;
            } elseif ($tipo === 3) {
                $policy['hard_required_plan'] = mt_rand(0, 1) === 0 ? 0 : (string) mt_rand(1, 40);
            } elseif ($tipo === 4) {
                $policy['hard_required_plan'] = null;
            }
            $r = mt_rand(0, 3);
            if ($r === 1) {
                $policy['forbidden_plan_ids'] = array_map(fn () => mt_rand(1, 40), range(1, mt_rand(1, 4)));
            } elseif ($r === 2) {
                $policy['forbidden_plan_ids'] = array_map(fn () => (string) mt_rand(1, 40), range(1, mt_rand(1, 3)));
            } elseif ($r === 3) {
                $policy['forbidden_plan_ids'] = mt_rand(1, 40);
            }
            if (isset($policy['hard_required_plan']) && mt_rand(0, 2) === 0) {
                $policy['forbidden_plan_ids'] = array_merge((array) ($policy['forbidden_plan_ids'] ?? []), [$policy['hard_required_plan']]);
                $vistos['nombrado_rechazado']++;
            }

            [$elegidos, $candidatos] = self::seleccionDeHoy($policy, $catalogo);
            $this->assertSame($elegidos, O::select($policy, $catalogo), 'caso '.$i.': '.json_encode([$policy, $catalogo]));
            $this->assertSame($candidatos, O::candidates($policy, $catalogo), 'caso '.$i.' (candidatos)');

            $deMes = count(array_filter($catalogo, fn (array $p) => $p['duration_days'] >= 28 && $p['duration_days'] <= 31));
            $vistos['sin_mes'] += (int) ($catalogo !== [] && $deMes === 0);
            $vistos['mas_de_tres_de_mes'] += (int) ($deMes > 3);
            $vistos['vacio'] += (int) ($elegidos === []);
        }
        // La matriz cubre lo que dice cubrir.
        foreach ($vistos as $que => $veces) {
            $this->assertGreaterThan(20, $veces, $que);
        }
    }

    public function test_respaldo_de_precios_llama_a_select_y_no_tiene_otra_seleccion(): void
    {
        $fuente = (string) file_get_contents(dirname(__DIR__, 4).'/app/Services/Marketing/Ultron/UltronCommitService.php');
        $ini = strpos($fuente, 'private function respaldoDePrecios(');
        $this->assertNotFalse($ini);
        $cuerpo = substr($fuente, $ini, (int) strpos($fuente, "\n    }\n", $ini) - $ini);
        $this->assertStringContainsString('UltronPlansOverview::select($policy, $activos)', $cuerpo);
        $this->assertStringContainsString('UltronPlansOverview::candidates($policy, $activos)', $cuerpo);
        $this->assertStringNotContainsString('$hayDeUnMes', $cuerpo, 'la selección vive en un solo sitio');
        $this->assertSame(1, substr_count($cuerpo, '->activePlans()'), 'el catálogo se lee una vez, como antes');
    }

    // ── 2. Los marcadores ───────────────────────────────────────────────────

    public function test_los_marcadores_son_posicionales_y_casan_con_el_patron_de_los_marcadores(): void
    {
        $this->assertSame(['{{PLAN_1}}', '{{PLAN_2}}', '{{PLAN_3}}'], O::markers(3));
        $this->assertSame(['{{PLAN_1}}', '{{PLAN_2}}'], O::markers(2));
        $this->assertSame([], O::markers(0));
        $this->assertSame('{{PLAN_2}}', O::marker(2));
        $this->assertSame(3, O::MAX_PLANS);

        $p = new UltronDraftPlaceholders;
        $this->assertSame(['PLAN_1', 'PLAN_2', 'PLAN_3'], $p->found(implode(' ', O::markers(3))), 'casan: el validador de hoy los ve');
        $this->assertSame([], $p->found('{{PLAN:12}}'), 'el de dos puntos no casa (H10): saldría literal');
        $this->assertSame(
            (new ReflectionClassConstant(UltronDraftPlaceholders::class, 'PATTERN'))->getValue(),
            O::PATTERN,
            'el mismo patrón que el validador de marcadores',
        );

        foreach ([-1, 4, 9] as $malo) {
            try {
                O::markers($malo);
                $this->fail("markers({$malo}) no lanzó");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(InvalidArgumentException::class);
        O::marker(0);
    }

    public function test_items_lleva_marcador_id_nombre_y_duracion_sin_precio(): void
    {
        $items = O::items([9, 3], self::catalogo());
        $this->assertSame([
            ['marker' => '{{PLAN_1}}', 'plan_id' => 9, 'name' => 'Plan Semana', 'duration_days' => 7],
            ['marker' => '{{PLAN_2}}', 'plan_id' => 3, 'name' => 'Plan Valera', 'duration_days' => 30],
        ], $items, 'en el orden de po y sin precio: el modelo nunca ve una cifra');

        $this->expectException(InvalidArgumentException::class);
        O::items([3, 77], self::catalogo());
    }

    // ── 3. La expansión ─────────────────────────────────────────────────────

    public function test_el_texto_de_cada_plan(): void
    {
        $this->assertSame('Plan Valera ($65.000 COP)', O::item(self::plan('Plan Valera', 65000, 30)));
        $this->assertSame('Mensual ($80.000 COP)', O::item(self::plan('  Mensual ', 80000, 28)), 'nombre sin espacios; de 28 a 31 días, sin duración');
        $this->assertSame('M ($80.000 COP)', O::item(self::plan('M', 80000, 31)));
        $this->assertSame('Plan Semana (7 días, $45.000 COP)', O::item(self::plan('Plan Semana', 45000, 7)));
        $this->assertSame('Plan Día (1 día, $10.000 COP)', O::item(self::plan('Plan Día', 10000, 1)), '«1 día» en singular (F5)');
        $this->assertSame('Q (27 días, $1 COP)', O::item(self::plan('Q', 1, 27)));
        $this->assertSame('Q (32 días, $1.234.567 COP)', O::item(self::plan('Q', 1234567, 32)));
        // Las mismas palabras que la lista fija de `respaldoDePrecios()` (`UltronDraftPlaceholders`).
        $this->assertSame('Trimestre (tres meses, $210.000 COP)', O::item(self::plan('Trimestre', 210000, 90)));
        $this->assertSame('Semestre (seis meses, $390.000 COP)', O::item(self::plan('Semestre', 390000, 180)));
        $this->assertSame('Anual (un año, $624.000 COP)', O::item(self::plan('Anual', 624000, 365)));
    }

    public function test_expand_sustituye_solo_los_marcadores_de_la_lista(): void
    {
        $po = [3, 4, 8];
        $textos = [3 => 'Plan Valera ($65.000 COP)', 4 => 'Plan Mensual ($80.000 COP)', 8 => 'Élite ($180.000 COP)'];

        $r = O::expand('Claro. Tenemos {{PLAN_1}} y {{ PLAN_2 }}. ¿Cuál le interesa?', $textos, $po);
        $this->assertSame('Claro. Tenemos Plan Valera ($65.000 COP) y Plan Mensual ($80.000 COP). ¿Cuál le interesa?', $r['text']);
        $this->assertSame([3, 4], $r['used']);

        $r = O::expand('{{PLAN_3}}, {{PLAN_1}} y otra vez {{PLAN_3}}', $textos, $po);
        $this->assertSame('Élite ($180.000 COP), Plan Valera ($65.000 COP) y otra vez Élite ($180.000 COP)', $r['text']);
        $this->assertSame([8, 3], $r['used'], 'por orden de aparición y sin repetir');

        $r = O::expand('{{PLAN_1}} {{PLAN_4}} {{PLAN_0}} {{PLAN_01}} {{plan_2}} {{PLAN_PRICE}} {{APP_LINKS}}', $textos, $po);
        $this->assertSame('Plan Valera ($65.000 COP) {{PLAN_4}} {{PLAN_0}} {{PLAN_01}} {{plan_2}} {{PLAN_PRICE}} {{APP_LINKS}}', $r['text'], 'lo demás, literal: lo juzgan el validador y verify()');
        $this->assertSame([3], $r['used']);

        $r = O::expand('{{PLAN_1}} y {{PLAN_2}}', $textos, []);
        $this->assertSame('{{PLAN_1}} y {{PLAN_2}}', $r['text'], 'sin lista no se expande nada');
        $this->assertSame([], $r['used']);

        $r = O::expand('{{PLAN_1}} y {{PLAN_2}}', [3 => 'Plan Valera ($65.000 COP)'], [3, 4]);
        $this->assertSame('Plan Valera ($65.000 COP) y {{PLAN_2}}', $r['text'], 'sin texto para un plan, el marcador queda');
        $this->assertSame([3], $r['used']);

        $r = O::expand('Sin marcadores.', $textos, $po);
        $this->assertSame(['text' => 'Sin marcadores.', 'used' => []], $r);
    }

    // ── 4. verify(): los seis detalles de §4.4 ──────────────────────────────

    public function test_una_lista_bien_escrita_vale(): void
    {
        $c = self::catalogo();
        $this->assertNull(O::verify('Claro. Tenemos {{PLAN_1}} y {{PLAN_2}}. ¿Cuál le interesa?', [3, 4], $c));
        $this->assertNull(O::verify('{{PLAN_1}}, {{PLAN_2}} y {{PLAN_3}}: el primero es para empezar.', [3, 4, 8], $c));
        $this->assertNull(O::verify('{{PLAN_2}} y {{PLAN_1}}', [3, 4, 8], $c), 'dos distintos bastan; el orden lo pide la instrucción, no la validez');
        $this->assertNull(O::verify('Le cuento: {{PLAN_1}} y {{PLAN_2}}. También hay un plan semanal.', [3, 4], $c), 'el nombre de un plan que no es de la lista no cuenta');
        $this->assertNull(O::verify('Sin marcadores ni lista.', [], $c), 'sin lista y sin marcadores: nada que decir');
    }

    public function test_cada_detalle(): void
    {
        $c = self::catalogo();
        $po = [3, 4, 8];

        $this->assertSame(O::MISSING, O::verify('Tenemos {{PLAN_1}}. ¿Le interesa?', $po, $c));
        $this->assertSame(O::MISSING, O::verify('Tenemos varios planes. ¿Cuál le interesa?', $po, $c));
        $this->assertSame(O::MISSING, O::verify('El {{PLAN_NAME}} cuesta {{PLAN_PRICE}}.', $po, $c), 'sin marcadores de la lista: falta la lista');

        $this->assertSame(O::MIXED, O::verify('{{PLAN_1}} y {{PLAN_2}} desde {{PLAN_PRICE}}', $po, $c));
        $this->assertSame(O::MIXED, O::verify('{{PLAN_1}}, {{PLAN_2}} y el {{PLAN_NAME}}', $po, $c));
        $this->assertSame(O::MIXED, O::verify('{{PLAN_1}} y {{PLAN_2}} ({{PLAN_DURATION}})', $po, $c));
        $this->assertSame(O::MIXED, O::verify('{{PLAN_1}}, {{PLAN_2}} y otra vez {{PLAN_1}}', $po, $c), 'repetido');
        $this->assertSame(O::MIXED, O::verify('{{PLAN_1}}, {{PLAN_2}} y {{ PLAN_1 }}', $po, $c), 'repetido con espacios');

        $this->assertSame(O::NAME_WRITTEN, O::verify('El Plan Valera {{PLAN_1}} y {{PLAN_2}}', $po, $c));
        $this->assertSame(O::NAME_WRITTEN, O::verify('{{PLAN_1}} y {{PLAN_3}}; el Élite es el completo.', $po, $c));

        $this->assertSame(O::UNKNOWN_MARKER, O::verify('{{PLAN_1}}, {{PLAN_2}} y {{PLAN_4}}', $po, $c));
        $this->assertSame(O::UNKNOWN_MARKER, O::verify('{{PLAN_1}}, {{PLAN_2}} y {{PLAN_3}}', [3, 4], $c), 'k > n');
        $this->assertSame(O::UNKNOWN_MARKER, O::verify('{{PLAN_0}} y {{PLAN_1}}', $po, $c));
        $this->assertSame(O::UNKNOWN_MARKER, O::verify('{{PLAN_01}} y {{PLAN_2}}', $po, $c), 'con cero delante no es PLAN_1');
        $this->assertSame(O::UNKNOWN_MARKER, O::verify('{{plan_1}} y {{PLAN_2}}', $po, $c), 'en minúsculas no es un marcador de la lista');

        $this->assertSame(O::NO_LIST, O::verify('Tenemos {{PLAN_1}} y {{PLAN_2}}.', [], $c));
        $this->assertSame(O::NO_LIST, O::verify('El {{PLAN_NAME}} y {{PLAN_3}}', [], $c));

        $this->assertSame(O::PRICE_WRITTEN, O::verify('{{PLAN_1}} y {{PLAN_2}}, desde $50.000.', $po, $c));
        $this->assertSame(O::PRICE_WRITTEN, O::verify('{{PLAN_1}} y {{PLAN_2}} por ochenta mil pesos', $po, $c));
    }

    /**
     * Si hay varios, uno: primero los que en el commit acaban en un 422 y en
     * la rendición (el marcador inexistente y la cifra), después los de la
     * estructura de la lista en el orden de §4.4.
     */
    public function test_la_precedencia_de_los_detalles(): void
    {
        $c = self::catalogo();
        $po = [3, 4];
        $this->assertSame(O::UNKNOWN_MARKER, O::verify('{{PLAN_4}} desde $50.000', $po, $c));
        $this->assertSame(O::PRICE_WRITTEN, O::verify('{{PLAN_1}} {{PLAN_1}} {{PLAN_PRICE}} Plan Valera $50.000', $po, $c));
        $this->assertSame(O::MIXED, O::verify('{{PLAN_1}} {{PLAN_1}} Plan Valera', $po, $c));
        $this->assertSame(O::NAME_WRITTEN, O::verify('{{PLAN_1}} y el Plan Mensual', $po, $c));
        $this->assertSame(O::DETAILS, [O::MISSING, O::MIXED, O::NAME_WRITTEN, O::UNKNOWN_MARKER, O::NO_LIST, O::PRICE_WRITTEN], 'los seis de §4.4, en su orden');
    }

    /**
     * OLA 4: la forma de la lista firmada (`po`) y el turno de cobro, extraídos
     * del commit (`listaFirmada()` y el paso 8.ter) para que la comprobación
     * (`ai/check`) y el commit lean lo mismo. Tal cual estaban: solo vale la
     * forma que emite `decide` (de 2 a 3 id enteros positivos y distintos, en
     * lista); el cobro es el enlace de pago pedido o una intención de cobro.
     */
    public function test_la_lista_firmada_y_el_turno_de_cobro(): void
    {
        $this->assertSame([3, 4, 8], O::signedList([3, 4, 8]));
        $this->assertSame([8, 3], O::signedList([8, 3]));
        foreach ([
            'nula' => null, 'uno solo' => [3], 'cuatro' => [3, 4, 8, 9], 'vacía' => [], 'texto' => '3,4',
            'ids como texto' => ['3', '4'], 'cero' => [0, 4], 'negativo' => [-3, 4], 'repetido' => [3, 3],
            'objeto' => ['a' => 3, 'b' => 4], 'desordenada' => [1 => 3, 0 => 4], 'decimal' => [3.0, 4],
        ] as $caso => $po) {
            $this->assertNull(O::signedList($po), $caso);
        }

        $this->assertTrue(O::isMoneyTurn('pricing_question', ['payment_link_send']));
        $this->assertTrue(O::isMoneyTurn('high_intent_close', []));
        $this->assertTrue(O::isMoneyTurn('payment_link_request', ['app_links_send']));
        $this->assertFalse(O::isMoneyTurn('pricing_question', ['app_links_send', 'staff_review']));
        $this->assertFalse(O::isMoneyTurn('general_info', []));
    }
}
