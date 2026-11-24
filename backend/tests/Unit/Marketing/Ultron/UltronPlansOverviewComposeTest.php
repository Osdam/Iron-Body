<?php

namespace Tests\Unit\Marketing\Ultron;

use App\Models\Plan;
use App\Services\Marketing\Ultron\UltronDraftPlaceholders;
use App\Services\Marketing\Ultron\UltronPlansOverview as O;
use App\Services\Marketing\Ultron\UltronReplyFitter;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PT-17 · PUERTA 2 · L1: LA LISTA LA COMPONE LARAVEL (especificación
 * `12-pt17-puerta-2-especificacion.md` §1.2, §1.3 y §4; decisiones P-2 A y
 * «Ranura A» del propietario, 24-11-2026).
 *
 * Lo que se fija, sin base de datos (los `Plan` son instancias sin guardar):
 *  1. `compose()` con las propiedades P1–P6, sobre un catálogo sintético
 *     (nombres con llaves, con comas, con «Plan …», con tildes y uno que no es
 *     de un mes) y TODAS las permutaciones de `po` de dos y de tres planes.
 *  2. `header()` en sus cuatro casos, con las cadenas de la lista fija sin
 *     «Claro. » (leídas del commit, no copiadas).
 *  3. `place()`: una ranura, dos, ninguna (antes de la pregunta final o al
 *     final); la lista sale una sola vez y quitando la frase insertada queda
 *     el borrador tal cual.
 *  4. `render()`, el único punto de `ai/check`, el commit y el laboratorio.
 *  5. `verify(..., true)` con la precedencia de §1.3 y `verify(..., false)`
 *     igual que hoy sobre los casos de `UltronPlansOverviewTest`.
 *  6. `serverList()`, `shape()` e `items()` sin marcadores.
 *  7. La lista blanca de marcadores y la red del commit con la ranura
 *     (comprobación 1 del propietario, la parte pura).
 *
 * Cómo cae sin el cambio: `compose()`, `place()`, `header()`, `render()`,
 * `serverList()` y `shape()` no existen (error), `verify()` acepta dos de
 * tres y no conoce la ranura, `allowed()` no la admite y la red no la ve.
 */
class UltronPlansOverviewComposeTest extends TestCase
{
    /**
     * El catálogo sintético: id → [nombre en el CRM, precio, días]. Llaves
     * (dobles y simples), una coma dentro del nombre, «Plan …», tildes y eñes,
     * y uno de siete días (el único que no es de un mes).
     */
    private const SINTETICO = [
        11 => ['Plan {Oro}', 65000, 30],
        12 => ['Mensual, con clases', 80000, 30],
        13 => ['Plan Élite Señor', 180000, 31],
        14 => ['{{Plan}} Azul', 99000, 28],
        15 => ['Semana Ágil', 45000, 7],
    ];

    /** El catálogo de producción de la prueba real (los textos de `po` [3, 4, 8]). */
    private const PRODUCCION = [3 => 'Plan Valera ($65.000 COP)', 4 => 'Plan Mensual ($80.000 COP)', 8 => 'Élite ($180.000 COP)'];

    private static function plan(string $nombre, float $precio, int $dias): Plan
    {
        return (new Plan)->forceFill(['name' => $nombre, 'price' => $precio, 'duration_days' => $dias]);
    }

    /** @return array<int,string> plan_id → su texto, con `item()` sobre un `Plan` sin guardar */
    private static function textos(): array
    {
        $t = [];
        foreach (self::SINTETICO as $id => [$nombre, $precio, $dias]) {
            $t[$id] = O::item(self::plan($nombre, $precio, $dias));
        }

        return $t;
    }

    /** @return list<array{id:int,name:string,duration_days:int}> el catálogo como `activePlans()` */
    private static function activos(): array
    {
        $a = [];
        foreach (self::SINTETICO as $id => [$nombre, , $dias]) {
            $a[] = ['id' => $id, 'name' => $nombre, 'duration_days' => $dias];
        }

        return $a;
    }

    /** @return list<array{id:int,name:string,duration_days:int,benefits:list<string>}> el de producción, para `verify()` */
    private static function catalogo(): array
    {
        return [
            ['id' => 3, 'name' => 'Plan Valera', 'duration_days' => 30, 'benefits' => ['Acceso ilimitado al gimnasio']],
            ['id' => 4, 'name' => 'Plan Mensual', 'duration_days' => 30, 'benefits' => ['Clases grupales']],
            ['id' => 8, 'name' => 'Élite', 'duration_days' => 30, 'benefits' => ['Plan nutricional personalizado']],
            ['id' => 9, 'name' => 'Plan Semana', 'duration_days' => 7, 'benefits' => []],
        ];
    }

    /** @return list<list<int>> todas las permutaciones de `$k` elementos de `$ids` */
    private static function permutaciones(array $ids, int $k): array
    {
        if ($k === 0) {
            return [[]];
        }
        $out = [];
        foreach ($ids as $i => $id) {
            $resto = $ids;
            unset($resto[$i]);
            foreach (self::permutaciones(array_values($resto), $k - 1) as $cola) {
                $out[] = [$id, ...$cola];
            }
        }

        return $out;
    }

    /** @return list<list<int>> las 20 de dos y las 60 de tres */
    private static function todasLasListas(): array
    {
        $ids = array_keys(self::SINTETICO);

        return [...self::permutaciones($ids, 2), ...self::permutaciones($ids, 3)];
    }

    // ── 1. compose(): P1–P6 ─────────────────────────────────────────────────

    public function test_compose_cumple_p1_a_p6_en_todas_las_listas_de_dos_y_de_tres(): void
    {
        $textos = self::textos();
        $listas = self::todasLasListas();
        $this->assertCount(80, $listas, '20 de dos y 60 de tres');

        foreach ($listas as $po) {
            $caso = json_encode($po);
            $lista = O::compose($po, $textos);
            $this->assertIsString($lista, $caso);

            // P1 · orden y unicidad: cada texto exactamente una vez y en el orden de `po`.
            $donde = -1;
            foreach ($po as $id) {
                $this->assertSame(1, substr_count($lista, $textos[$id]), $caso.' '.$id);
                $this->assertGreaterThan($donde, $p = strpos($lista, $textos[$id]), $caso.' orden');
                $donde = $p;
            }

            // P2 · totalidad: sin el texto de cualquiera de `po`, null (nunca una lista parcial).
            foreach ($po as $id) {
                $this->assertNull(O::compose($po, array_diff_key($textos, [$id => true])), $caso.' sin '.$id);
            }

            // P3 · sin invención: nada que no venga de los textos de `po`, salvo el separador; ningún otro plan.
            $this->assertSame(implode(O::SEPARATOR, array_map(fn (int $id): string => $textos[$id], $po)), $lista, $caso);
            foreach (array_diff(array_keys($textos), $po) as $fuera) {
                $this->assertStringNotContainsString($textos[$fuera], $lista, $caso.' no nombra '.$fuera);
            }

            // P4 · pura y determinista: los mismos bytes con las claves en otro orden.
            $this->assertSame($lista, O::compose($po, array_reverse($textos, true)), $caso);
            $this->assertSame($lista, O::compose($po, $textos), $caso);

            // P5 · sin forma de marcador (aunque los nombres del CRM lleven llaves) y sin saltos de línea.
            $this->assertFalse(UltronDraftPlaceholders::looksLikeMarker($lista), $caso.': «'.$lista.'»');
            $this->assertDoesNotMatchRegularExpression('/[\r\n{}]/', $lista, $caso);

            // P6 · paridad A/B: la forma A con todos sus marcadores en orden da los mismos bytes.
            $marcadores = implode(O::SEPARATOR, array_map(fn (int $k): string => O::marker($k), range(1, count($po))));
            $this->assertSame($lista, O::expand($marcadores, $textos, $po)['text'], $caso);
        }
    }

    public function test_compose_con_el_catalogo_de_produccion_mide_75_y_con_encabezado_102(): void
    {
        $lista = O::compose([3, 4, 8], self::PRODUCCION);
        $this->assertSame('Plan Valera ($65.000 COP); Plan Mensual ($80.000 COP); Élite ($180.000 COP)', $lista);
        $this->assertSame(75, UltronReplyFitter::measure($lista));
        $this->assertSame(102, UltronReplyFitter::measure(O::HEADER_SOME_MONTHLY.$lista.'.'), '«Algunos planes de un mes: »: hay más de un mes en producción');
    }

    public function test_compose_nunca_devuelve_una_lista_con_forma_de_marcador_ni_con_saltos_de_linea(): void
    {
        $textos = self::textos();
        // Un nombre del CRM que, sin sus llaves, queda como un marcador: la lista no sale (null), la red no tiene que pararla.
        $conMarcador = [11 => O::item(self::plan('Plan {{PLAN_PRICE}}', 65000, 30))] + $textos;
        $this->assertSame('Plan PLAN_PRICE ($65.000 COP)', $conMarcador[11]);
        $this->assertNull(O::compose([11, 12], $conMarcador));
        $this->assertNull(O::compose([12, 11], [11 => 'Uno ($1 COP) LISTA_DE_PLANES'] + $textos));
        $this->assertNull(O::compose([11, 12], [11 => "Plan\nOro ($65.000 COP)"] + $textos), 'un salto de línea en el nombre');
        $this->assertNull(O::compose([11, 12], [11 => '   '] + $textos), 'un texto vacío cuenta como que falta');
    }

    /**
     * Revisión de seguridad de la puerta 2, M2: tampoco con los separadores
     * de línea de Unicode, NEL, el tabulador vertical, el salto de página ni
     * los controles de dirección (bidi), que dan la vuelta a lo que se lee. El
     * texto de `item()` los conserva (el nombre del CRM solo pierde las
     * llaves): los rechaza la composición.
     *
     * @return array<string, array{string}>
     */
    public static function controlesEnElNombre(): array
    {
        $casos = [];
        foreach ([0x0B, 0x0C, 0x85, 0x2028, 0x2029, 0x202A, 0x202B, 0x202C, 0x202D, 0x202E, 0x2066, 0x2067, 0x2068, 0x2069] as $c) {
            $casos[sprintf('U+%04X', $c)] = [mb_chr($c, 'UTF-8')];
        }

        return $casos;
    }

    #[DataProvider('controlesEnElNombre')]
    public function test_compose_no_admite_controles_de_linea_ni_de_direccion_en_un_nombre(string $control): void
    {
        $textos = self::textos();
        $item = O::item(self::plan('Plan '.$control.'Oro', 65000, 30));
        $this->assertStringContainsString($control, $item, 'item() no lo quita');
        $this->assertNull(O::compose([11, 12], [11 => $item] + $textos));
        $this->assertNull(O::compose([12, 11], [11 => $item] + $textos), 'en cualquier lugar de la lista');
        $this->assertTrue(O::unsafeListText($item));
        $this->assertFalse(O::unsafeListText($textos[11]), 'un nombre normal (con llaves en el CRM) sí vale');
    }

    public function test_compose_solo_acepta_la_lista_firmada(): void
    {
        foreach (['uno' => [11], 'cuatro' => [11, 12, 13, 14], 'repetido' => [11, 11], 'vacía' => [], 'ids como texto' => ['11', '12'], 'objeto' => ['a' => 11, 'b' => 12]] as $caso => $po) {
            try {
                O::compose($po, self::textos());
                $this->fail($caso.': tenía que lanzar');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // ── 2. header() ─────────────────────────────────────────────────────────

    public function test_header_en_sus_cuatro_casos(): void
    {
        $activos = self::activos();
        // Todos de un mes y queda otro de un mes fuera (14).
        $this->assertSame(O::HEADER_SOME_MONTHLY, O::header([11, 12, 13], $activos));
        // Todos de un mes y fuera solo queda uno que no es de un mes: los de un mes están todos.
        $this->assertSame(O::HEADER_ALL_MONTHLY, O::header([11, 12, 13, 14], $activos));
        $this->assertSame(O::HEADER_ALL_MONTHLY, O::header([11, 13], array_values(array_filter($activos, fn (array $p): bool => in_array($p['id'], [11, 13, 15], true)))));
        // Con uno que no es de un mes y otros vendibles fuera.
        $this->assertSame(O::HEADER_SOME, O::header([15, 11], $activos));
        // Con uno que no es de un mes y ninguno fuera.
        $this->assertSame(O::HEADER_ALL, O::header([15, 11], array_values(array_filter($activos, fn (array $p): bool => in_array($p['id'], [11, 15], true)))));
    }

    /** Las cadenas son las de la lista fija sin «Claro. »: las lee del commit (si cambian allí, esto cae). */
    public function test_los_encabezados_son_los_de_la_lista_fija_sin_claro(): void
    {
        $commit = (string) file_get_contents(dirname(__DIR__, 4).'/app/Services/Marketing/Ultron/UltronCommitService.php');
        foreach ([O::HEADER_ALL_MONTHLY, O::HEADER_SOME_MONTHLY, O::HEADER_ALL, O::HEADER_SOME] as $encabezado) {
            $this->assertStringContainsString("'Claro. ".$encabezado."'", $commit, $encabezado);
        }
        $this->assertSame(['Planes de un mes: ', 'Algunos planes de un mes: ', 'Nuestros planes: ', 'Algunos de nuestros planes: '], [O::HEADER_ALL_MONTHLY, O::HEADER_SOME_MONTHLY, O::HEADER_ALL, O::HEADER_SOME]);
    }

    // ── 3. place() ──────────────────────────────────────────────────────────

    public function test_place_con_una_ranura_la_sustituye_exactamente(): void
    {
        $lista = O::compose([3, 4, 8], self::PRODUCCION);
        foreach (['{{LISTA_DE_PLANES}}', '{{ LISTA_DE_PLANES }}'] as $ranura) {
            $raw = 'Con gusto le cuento. Estos son: '.$ranura.'. ¿Cuál le interesa más?';
            $r = O::place($raw, $lista, O::HEADER_SOME_MONTHLY);
            // Un precio («$65.000») no se lee como una referencia del reemplazo.
            $this->assertSame(['text' => str_replace($ranura, $lista, $raw), 'slot' => true], $r, $ranura);
            $this->assertSame(1, substr_count($r['text'], $lista));
            $this->assertStringNotContainsString(O::HEADER_SOME_MONTHLY, $r['text'], 'con ranura no se pone el encabezado');
        }
    }

    public function test_place_con_dos_ranuras_no_elige(): void
    {
        $lista = O::compose([3, 4], self::PRODUCCION);
        $this->assertNull(O::place('{{LISTA_DE_PLANES}} y {{LISTA_DE_PLANES}}', $lista, O::HEADER_ALL));
        $this->assertNull(O::place('Tenemos {{ LISTA_DE_PLANES }}. Repito: {{LISTA_DE_PLANES}}.', $lista, O::HEADER_ALL));
    }

    public function test_place_sin_ranura_inserta_antes_de_la_pregunta_final_o_al_final(): void
    {
        $lista = O::compose([3, 4, 8], self::PRODUCCION);
        $frase = O::HEADER_SOME_MONTHLY.$lista.'.';
        $casos = [
            // [borrador, texto esperado]
            'antes de la pregunta final' => ['Claro, con gusto. ¿Cuál le interesa más?', 'Claro, con gusto. '.$frase.' ¿Cuál le interesa más?'],
            'antes de la última pregunta, no de la primera' => ['¿Busca algo para empezar? Le cuento. ¿Cuál le interesa?', '¿Busca algo para empezar? Le cuento. '.$frase.' ¿Cuál le interesa?'],
            'solo una pregunta' => ['¿Cuál le interesa?', $frase.' ¿Cuál le interesa?'],
            'sin pregunta final: al final' => ['Claro, con gusto. Todos incluyen acceso total.', 'Claro, con gusto. Todos incluyen acceso total. '.$frase],
            'sin puntuación final: al final' => ['Claro, con gusto', 'Claro, con gusto '.$frase],
            'con salto de línea antes de la pregunta' => ["Claro, con gusto.\n¿Cuál le interesa?", "Claro, con gusto.\n".$frase.' ¿Cuál le interesa?'],
            'con espacios al final' => ['Claro, con gusto.  ', 'Claro, con gusto. '.$frase.'  '],
            'vacío' => ['', $frase],
        ];
        foreach ($casos as $caso => [$raw, $esperado]) {
            $r = O::place($raw, $lista, O::HEADER_SOME_MONTHLY);
            $this->assertSame(['text' => $esperado, 'slot' => false], $r, $caso);
            $this->assertSame(1, substr_count($r['text'], $lista), $caso.': la lista, una sola vez');
            // Quitando la frase insertada (y el espacio que la separa) queda el borrador tal cual: las frases del modelo no cambian.
            $sin = match (true) {
                $raw === '' => str_replace($frase, '', $r['text']),
                str_contains($r['text'], $frase.' ') => str_replace($frase.' ', '', $r['text']),
                default => str_replace(' '.$frase, '', $r['text']),
            };
            $this->assertSame($raw, $sin, $caso);
        }
    }

    // ── 4. render(): el único punto ─────────────────────────────────────────

    public function test_render_en_cada_forma(): void
    {
        $po = [3, 4, 8];
        $t = self::PRODUCCION;
        $c = self::catalogo();
        $lista = O::compose($po, $t);

        // Sin lista en el turno: el borrador tal cual.
        $this->assertSame(['text' => 'Hola {{LISTA_DE_PLANES}}', 'shape' => O::SHAPE_SERVER, 'slot' => false, 'used' => [], 'placed' => false], O::render('Hola {{LISTA_DE_PLANES}}', [], $t, $c, true));

        // Sin la lista del servidor (Legacy o k ≤ 7): la expansión de siempre, también con la ranura (queda literal y la para el 422).
        $this->assertSame(['text' => 'Tenemos Plan Valera ($65.000 COP) y Plan Mensual ($80.000 COP).', 'shape' => O::SHAPE_MARKERS, 'slot' => false, 'used' => [3, 4], 'placed' => true], O::render('Tenemos {{PLAN_1}} y {{PLAN_2}}.', $po, $t, $c, false));
        $this->assertFalse(O::render('Tenemos {{PLAN_1}}.', $po, $t, $c, false)['placed'], 'con un solo plan no está puesta');
        $this->assertSame('Tenemos {{LISTA_DE_PLANES}}.', O::render('Tenemos {{LISTA_DE_PLANES}}.', $po, $t, $c, false)['text']);

        // Con la lista del servidor y forma A: la misma expansión.
        $this->assertSame('Tenemos '.str_replace('; ', ', ', $lista).'.', O::render('Tenemos {{PLAN_1}}, {{PLAN_2}}, {{PLAN_3}}.', $po, $t, $c, true)['text']);
        $this->assertSame(O::SHAPE_MARKERS, O::render('{{plan_1}}', $po, $t, $c, true)['shape'], 'en minúsculas también pretende ser de la lista');

        // Con la lista del servidor y forma B: la lista entera, con `used` = po.
        $this->assertSame(['text' => 'Estos son: '.$lista.'. ¿Cuál?', 'shape' => O::SHAPE_SERVER, 'slot' => true, 'used' => $po, 'placed' => true], O::render('Estos son: {{LISTA_DE_PLANES}}. ¿Cuál?', $po, $t, $c, true));
        // Sin ranura, antes de la pregunta final, con el encabezado (aquí fuera solo queda el Plan Semana, que no es de un mes).
        $this->assertSame(['text' => 'Claro. Planes de un mes: '.$lista.'. ¿Cuál?', 'shape' => O::SHAPE_SERVER, 'slot' => false, 'used' => $po, 'placed' => true], O::render('Claro. ¿Cuál?', $po, $t, $c, true));

        // Si no se puede componer (falta un plan) o colocar (dos ranuras): el borrador tal cual y sin colocar.
        $this->assertSame(['text' => 'Claro. ¿Cuál?', 'shape' => O::SHAPE_SERVER, 'slot' => false, 'used' => $po, 'placed' => false], O::render('Claro. ¿Cuál?', $po, [3 => $t[3], 4 => $t[4]], $c, true));
        $this->assertSame(['text' => '{{LISTA_DE_PLANES}} {{LISTA_DE_PLANES}}', 'shape' => O::SHAPE_SERVER, 'slot' => true, 'used' => $po, 'placed' => false], O::render('{{LISTA_DE_PLANES}} {{LISTA_DE_PLANES}}', $po, $t, $c, true));
    }

    // ── 5. verify() ─────────────────────────────────────────────────────────

    /**
     * La tabla de §1.3 con `serverList`, en su orden de precedencia. Con
     * `po` [3, 4, 8] salvo donde se dice.
     *
     * @return array<string, array{string, list<int>, ?string}>
     */
    public static function conLaListaDelServidor(): array
    {
        $po = [3, 4, 8];

        return [
            // 1. Sin lista: la ranura, una variante suya o un PLAN_k.
            '1 · sin lista, la ranura' => ['Tenemos {{LISTA_DE_PLANES}}.', [], O::NO_LIST],
            '1 · sin lista, otra caja' => ['Tenemos {{lista_de_planes}}.', [], O::NO_LIST],
            '1 · sin lista, suelta' => ['Tenemos LISTA_DE_PLANES.', [], O::NO_LIST],
            '1 · sin lista, PLAN_1' => ['Tenemos {{PLAN_1}}.', [], O::NO_LIST],
            '1 · sin lista y sin marcas' => ['Abrimos de lunes a viernes. ¿Algo más?', [], null],
            '1 · sin lista, un PLAN_PRICE vale' => ['El {{PLAN_NAME}} cuesta {{PLAN_PRICE}}.', [], null],
            // 2. Un PLAN_k que no existe, o la ranura con otra caja.
            '2 · PLAN_4 con tres' => ['{{PLAN_1}}, {{PLAN_2}} y {{PLAN_4}}', $po, O::UNKNOWN_MARKER],
            '2 · PLAN_0' => ['{{PLAN_0}} y {{LISTA_DE_PLANES}}', $po, O::UNKNOWN_MARKER],
            '2 · la ranura en minúsculas' => ['Estos son: {{lista_de_planes}}.', $po, O::UNKNOWN_MARKER],
            '2 · la ranura en otra caja, antes que la cifra' => ['{{Lista_De_Planes}} desde $50.000', $po, O::UNKNOWN_MARKER],
            '2 · PLAN_4 antes que la cifra' => ['{{PLAN_4}} desde $50.000', $po, O::UNKNOWN_MARKER],
            // 3. Una cifra.
            '3 · la ranura y una cifra' => ['{{LISTA_DE_PLANES}} desde $50.000', $po, O::PRICE_WRITTEN],
            '3 · la cifra antes que la mezcla' => ['{{LISTA_DE_PLANES}} y {{PLAN_1}} por ochenta mil pesos', $po, O::PRICE_WRITTEN],
            // 4. A y B a la vez, la ranura repetida, con los de un plan, o suelta.
            '4 · A y B a la vez' => ['{{LISTA_DE_PLANES}} y además {{PLAN_1}}', $po, O::MIXED],
            '4 · A entera y B' => ['{{PLAN_1}}, {{PLAN_2}} y {{PLAN_3}}: {{LISTA_DE_PLANES}}', $po, O::MIXED],
            '4 · la ranura dos veces' => ['{{LISTA_DE_PLANES}}. Repito: {{ LISTA_DE_PLANES }}', $po, O::MIXED],
            '4 · la ranura y PLAN_PRICE' => ['{{LISTA_DE_PLANES}} desde {{PLAN_PRICE}}', $po, O::MIXED],
            '4 · la ranura y PLAN_NAME' => ['{{LISTA_DE_PLANES}}; el {{PLAN_NAME}} es el más pedido', $po, O::MIXED],
            '4 · la ranura y PLAN_DURATION' => ['{{LISTA_DE_PLANES}} ({{PLAN_DURATION}})', $po, O::MIXED],
            '4 · suelta, sin llaves' => ['Tenemos LISTA_DE_PLANES. ¿Cuál?', $po, O::MIXED],
            '4 · con una sola llave' => ['Tenemos {LISTA_DE_PLANES}. ¿Cuál?', $po, O::MIXED],
            '4 · suelta junto a la buena' => ['{{LISTA_DE_PLANES}} (LISTA_DE_PLANES)', $po, O::MIXED],
            // Revisión de seguridad de la puerta 2, M1: una llave pegada a la ranura (saldría entre llaves sueltas).
            '4 · la ranura con triple llave' => ['Estos son: {{{LISTA_DE_PLANES}}}. ¿Cuál?', $po, O::MIXED],
            '4 · una llave pegada delante' => ['Estos son: { {{LISTA_DE_PLANES}}. ¿Cuál?', $po, O::MIXED],
            '4 · una llave pegada detrás' => ['Estos son: {{LISTA_DE_PLANES}}}. ¿Cuál?', $po, O::MIXED],
            // 5. Forma A, estricta: todos y en orden.
            '5 · A entera y en orden' => ['Tenemos {{PLAN_1}}, {{PLAN_2}} y {{PLAN_3}}. ¿Cuál?', $po, null],
            '5 · A de dos con dos' => ['{{PLAN_1}} y {{PLAN_2}}', [3, 4], null],
            '5 · A con dos de tres' => ['Tenemos {{PLAN_1}} y {{PLAN_2}}. ¿Cuál?', $po, O::MISSING],
            '5 · A con uno (puerta 1: falta la lista)' => ['Le recomiendo {{PLAN_1}}.', $po, O::MISSING],
            // Decisión del lead (24-11): la regla L2 de la puerta 1 sigue con la lista del servidor: en forma A, menos de dos planes
            // de la lista (por su marcador o a mano) es `missing`, ANTES que `name_written` y `mixed` (los borradores reales de L2).
            '5 · L2: un plan a mano y su marcador' => ['Le cuento que el Plan Valera incluye acceso ilimitado al gimnasio, zona de pesas, asesoría semi personalizada y valoración inicial, todo por {{PLAN_1}}. Para orientarle mejor, ¿ha entrenado antes o es principiante?', $po, O::MISSING],
            '5 · L2: un marcador con PLAN_PRICE' => ['Por lo que pregunta, tenemos el {{PLAN_1}} con acceso ilimitado y asesoría semi personalizada por {{PLAN_PRICE}}. ¿Ha entrenado antes o es su primera vez para orientarle mejor?', $po, O::MISSING],
            '5 · L2: un marcador repetido' => ['Tenemos {{PLAN_1}} y otra vez {{PLAN_1}}. ¿Le interesa?', $po, O::MISSING],
            '5 · L2: con dos planes, el nombre a mano sigue siendo name_written' => ['Tenemos {{PLAN_1}} y el Plan Mensual. ¿Cuál?', $po, O::NAME_WRITTEN],
            '5 · A fuera de orden' => ['{{PLAN_2}}, {{PLAN_1}} y {{PLAN_3}}', $po, O::MIXED],
            '5 · A de dos fuera de orden' => ['{{PLAN_2}} y {{PLAN_1}}', [3, 4], O::MIXED],
            '5 · A repetida' => ['{{PLAN_1}}, {{PLAN_2}}, {{PLAN_3}} y otra vez {{PLAN_1}}', $po, O::MIXED],
            '5 · A con PLAN_PRICE' => ['{{PLAN_1}}, {{PLAN_2}} y {{PLAN_3}} desde {{PLAN_PRICE}}', $po, O::MIXED],
            '5 · A con un nombre a mano' => ['El Plan Valera {{PLAN_1}}, {{PLAN_2}} y {{PLAN_3}}', $po, O::NAME_WRITTEN],
            // 6. Forma B.
            '6 · la ranura' => ['Le comparto los planes: {{LISTA_DE_PLANES}}. ¿Cuál le interesa?', $po, null],
            '6 · la ranura con espacios' => ['{{ LISTA_DE_PLANES }}', $po, null],
            '6 · sin ninguna marca' => ['Claro, con gusto. ¿Cuál le interesa?', $po, null],
            '6 · cotiza un plan en vez de la lista' => ['El {{PLAN_NAME}} cuesta {{PLAN_PRICE}}. ¿Le interesa?', $po, O::MISSING],
            '6 · un nombre de la lista a mano' => ['El Plan Valera es el más pedido. {{LISTA_DE_PLANES}}', $po, O::NAME_WRITTEN],
            '6 · un nombre a mano sin ranura' => ['El Élite es el completo. ¿Cuál le interesa?', $po, O::NAME_WRITTEN],
            '6 · un plan de fuera de la lista no cuenta' => ['También hay un Plan Semana. {{LISTA_DE_PLANES}}', $po, null],
            '6 · por su lugar, sin nombre' => ['{{LISTA_DE_PLANES}}. El tercero es el más completo.', $po, null],
            // 7. La que por lo demás vale pero deja restos con forma de marcador (las sondas S4 y S5): mixed, no el último recurso.
            '7 · B con PLAN_1 sin llaves (S4)' => ['Claro. Tenemos PLAN_1 y PLAN_2, los dos de un mes. Le dejo la app.', $po, O::MIXED],
            '7 · B con una llave (S5)' => ['Claro. Tenemos {PLAN_1} y {PLAN_2}, los dos de un mes. Le dejo la app.', $po, O::MIXED],
            '7 · B con un espacio dentro' => ['Tenemos {{PLAN 1}}. ¿Cuál?', $po, O::MIXED],
            '7 · la ranura y llaves sueltas' => ['{{LISTA_DE_PLANES}} }}', $po, O::MIXED],
            '7 · A entera y un PLAN_4 suelto' => ['{{PLAN_1}}, {{PLAN_2}} y {{PLAN_3}}; también PLAN_4.', $po, O::MIXED],
            '7 · lo demás manda: un nombre a mano y un resto' => ['El Élite y PLAN_2. {{LISTA_DE_PLANES}}', $po, O::NAME_WRITTEN],
            // M1, lo mismo en la forma A: los marcadores entre triples llaves.
            '7 · A entera con triple llave' => ['{{{PLAN_1}}}, {{PLAN_2}} y {{PLAN_3}}', $po, O::MIXED],
            // B1: dentro de otra palabra también es un resto.
            '7 · B con _PLAN_1_ en cursiva' => ['Tenemos _PLAN_1_ y _PLAN_2_. ¿Cuál?', $po, O::MIXED],
        ];
    }

    #[DataProvider('conLaListaDelServidor')]
    public function test_verify_con_la_lista_del_servidor_sigue_la_tabla_de_1_3(string $raw, array $po, ?string $esperado): void
    {
        $this->assertSame($esperado, O::verify($raw, $po, self::catalogo(), true));
    }

    /**
     * Sin `serverList`, `verify()` da lo de hoy: los casos de
     * `UltronPlansOverviewTest`, con su valor (dos de tres valen, el orden no
     * importa, la ranura es un marcador más que falta).
     */
    public function test_verify_sin_la_lista_del_servidor_da_lo_de_hoy(): void
    {
        $c = self::catalogo();
        $po = [3, 4, 8];
        $casos = [
            ['Claro. Tenemos {{PLAN_1}} y {{PLAN_2}}. ¿Cuál le interesa?', [3, 4], null],
            ['{{PLAN_1}}, {{PLAN_2}} y {{PLAN_3}}: el primero es para empezar.', $po, null],
            ['{{PLAN_2}} y {{PLAN_1}}', $po, null],
            ['Le cuento: {{PLAN_1}} y {{PLAN_2}}. También hay un plan semanal.', [3, 4], null],
            ['Sin marcadores ni lista.', [], null],
            ['Tenemos {{PLAN_1}}. ¿Le interesa?', $po, O::MISSING],
            ['Tenemos varios planes. ¿Cuál le interesa?', $po, O::MISSING],
            ['El {{PLAN_NAME}} cuesta {{PLAN_PRICE}}.', $po, O::MISSING],
            ['{{PLAN_1}} y {{PLAN_2}} desde {{PLAN_PRICE}}', $po, O::MIXED],
            ['{{PLAN_1}}, {{PLAN_2}} y el {{PLAN_NAME}}', $po, O::MIXED],
            ['{{PLAN_1}} y {{PLAN_2}} ({{PLAN_DURATION}})', $po, O::MIXED],
            ['{{PLAN_1}}, {{PLAN_2}} y otra vez {{PLAN_1}}', $po, O::MIXED],
            ['{{PLAN_1}}, {{PLAN_2}} y {{ PLAN_1 }}', $po, O::MIXED],
            ['El Plan Valera {{PLAN_1}} y {{PLAN_2}}', $po, O::NAME_WRITTEN],
            ['{{PLAN_1}} y {{PLAN_3}}; el Élite es el completo.', $po, O::NAME_WRITTEN],
            ['{{PLAN_1}}, {{PLAN_2}} y {{PLAN_4}}', $po, O::UNKNOWN_MARKER],
            ['{{PLAN_1}}, {{PLAN_2}} y {{PLAN_3}}', [3, 4], O::UNKNOWN_MARKER],
            ['{{PLAN_0}} y {{PLAN_1}}', $po, O::UNKNOWN_MARKER],
            ['{{PLAN_01}} y {{PLAN_2}}', $po, O::UNKNOWN_MARKER],
            ['{{plan_1}} y {{PLAN_2}}', $po, O::UNKNOWN_MARKER],
            ['Tenemos {{PLAN_1}} y {{PLAN_2}}.', [], O::NO_LIST],
            ['El {{PLAN_NAME}} y {{PLAN_3}}', [], O::NO_LIST],
            ['{{PLAN_1}} y {{PLAN_2}}, desde $50.000.', $po, O::PRICE_WRITTEN],
            ['{{PLAN_1}} y {{PLAN_2}} por ochenta mil pesos', $po, O::PRICE_WRITTEN],
            ['{{PLAN_4}} desde $50.000', [3, 4], O::UNKNOWN_MARKER],
            ['{{PLAN_1}} {{PLAN_1}} {{PLAN_PRICE}} Plan Valera $50.000', [3, 4], O::PRICE_WRITTEN],
            ['{{PLAN_1}} {{PLAN_1}} Plan Valera', [3, 4], O::MISSING],
            ['{{PLAN_1}} {{PLAN_2}} {{PLAN_1}} Plan Valera', [3, 4], O::MIXED],
            ['{{PLAN_1}} y el Plan Mensual', [3, 4], O::NAME_WRITTEN],
            ['Claro. El Plan Valera cuesta {{PLAN_PRICE}} al mes e incluye acceso total al gimnasio. ¿Le gustaría conocer más?', $po, O::MISSING],
            ['Tenemos el Plan Valera y el Plan Mensual. ¿Cuál le interesa?', $po, O::MISSING],
            ['El Plan Valera cuesta $65.000 al mes.', $po, O::PRICE_WRITTEN],
            ['El Plan Valera y {{PLAN_4}}', $po, O::UNKNOWN_MARKER],
            // La ranura, sin la lista del servidor, no es nada: falta la lista (y el commit la para con el 422).
            ['Le comparto los planes: {{LISTA_DE_PLANES}}.', $po, O::MISSING],
        ];
        foreach ($casos as $i => [$raw, $lista, $esperado]) {
            $this->assertSame($esperado, O::verify($raw, $lista, $c, false), $i.': '.$raw);
            $this->assertSame($esperado, O::verify($raw, $lista, $c), $i.': por defecto, sin la lista del servidor');
        }
    }

    // ── 6. serverList(), shape(), items() ───────────────────────────────────

    public function test_server_list_solo_con_studio_y_compilador_8_o_posterior(): void
    {
        $sp = ['m' => 'studio', 'n' => 200, 't' => 'profesional', 'o' => 1, 'k' => 8];
        $this->assertTrue(O::serverList($sp));
        $this->assertTrue(O::serverList(['k' => 9] + $sp));
        $this->assertSame(8, O::SERVER_LIST_SINCE_COMPILER);
        foreach ([
            'compilador 7' => ['k' => 7] + $sp,
            'compilador 6' => ['k' => 6] + $sp,
            'k en texto' => ['k' => '8'] + $sp,
            'k decimal' => ['k' => 8.0] + $sp,
            'sin k' => array_diff_key($sp, ['k' => 1]),
            'Legacy' => ['m' => 'legacy'] + $sp,
            'sin modo' => array_diff_key($sp, ['m' => 1]),
            'vacío' => [],
        ] as $caso => $s) {
            $this->assertFalse(O::serverList($s), $caso);
        }
    }

    public function test_shape(): void
    {
        $this->assertSame(O::SHAPE_MARKERS, O::shape('Tenemos {{PLAN_1}} y {{PLAN_2}}.'));
        $this->assertSame(O::SHAPE_MARKERS, O::shape('{{ plan_3 }}'));
        $this->assertSame(O::SHAPE_MARKERS, O::shape('{{PLAN_9}} y {{LISTA_DE_PLANES}}'), 'A y B a la vez: pretende ser A (y verify da mixed)');
        $this->assertSame(O::SHAPE_SERVER, O::shape('{{LISTA_DE_PLANES}}'));
        $this->assertSame(O::SHAPE_SERVER, O::shape('El {{PLAN_NAME}} cuesta {{PLAN_PRICE}}.'));
        $this->assertSame(O::SHAPE_SERVER, O::shape('Claro. ¿Cuál le interesa?'));
        $this->assertSame(O::SHAPE_SERVER, O::shape(''));
    }

    public function test_items_sin_marcadores_con_la_lista_del_servidor(): void
    {
        $c = self::catalogo();
        $this->assertSame([
            ['plan_id' => 9, 'name' => 'Plan Semana', 'duration_days' => 7],
            ['plan_id' => 3, 'name' => 'Plan Valera', 'duration_days' => 30, 'benefits' => ['Acceso ilimitado al gimnasio']],
        ], O::items([9, 3], $c, false), 'lo mismo que hoy sin la clave `marker`: el modelo ve un solo sistema, la ranura');
        $this->assertSame(['{{PLAN_1}}', '{{PLAN_2}}'], array_column(O::items([9, 3], $c), 'marker'), 'por defecto, con marcadores (k ≤ 7)');
    }

    // ── 7. Comprobación 1 del propietario, la parte pura ────────────────────

    public function test_la_ranura_solo_vale_en_la_lista_blanca_con_lista_y_con_su_caja(): void
    {
        $p = new UltronDraftPlaceholders;
        $this->assertSame([], $p->unknown('Estos son: {{LISTA_DE_PLANES}}.', 3, true));
        $this->assertSame([], $p->unknown('Estos son: {{ LISTA_DE_PLANES }}.', 2, true));
        $this->assertContains(O::SLOT, $p->allowed(3, true));
        $this->assertSame(['LISTA_DE_PLANES'], $p->unknown('Estos son: {{LISTA_DE_PLANES}}.', 3), 'sin la lista del servidor (k ≤ 7)');
        $this->assertSame(['LISTA_DE_PLANES'], $p->unknown('Estos son: {{LISTA_DE_PLANES}}.', 0, true), 'sin lista en el turno (o de cobro)');
        $this->assertSame(UltronDraftPlaceholders::ALLOWED, $p->allowed(0, true), 'sin lista, la lista blanca de siempre');
        $this->assertSame(['lista_de_planes'], $p->unknown('Estos son: {{lista_de_planes}}.', 3, true), 'con otra caja');
        $this->assertSame(['Lista_De_Planes'], $p->unknown('Estos son: {{Lista_De_Planes}}.', 3, true));
        $this->assertSame(['LISTA_DE_PLANES2'], $p->unknown('{{LISTA_DE_PLANES2}}', 3, true));
        $this->assertNotContains(O::SLOT, $p->allowed(3), 'por defecto, sin ranura');
    }

    public function test_la_red_del_commit_ve_la_ranura_con_y_sin_llaves(): void
    {
        foreach ([
            'Estos son: LISTA_DE_PLANES.', 'Estos son: lista_de_planes.', 'Estos son: {LISTA_DE_PLANES}.', 'Estos: {{LISTA_DE_PLANES}}',
            // Revisión de seguridad de la puerta 2, B1: dentro de otra palabra (el `_` es de palabra), también.
            'Le comparto _LISTA_DE_PLANES_ hoy.', 'Le comparto laLISTA_DE_PLANES hoy.', 'Le comparto LISTA_DE_PLANES2 hoy.',
            'Tenemos _PLAN_1_ y _PLAN_2_.', 'Tenemos PLAN_1x.', 'Aquí la app: _APP_LINKS_', 'Aquí la app: xAPP_LINKS.', 'Cuesta _PLAN_PRICE_ al mes.',
        ] as $texto) {
            $this->assertTrue(UltronDraftPlaceholders::looksLikeMarker($texto), $texto);
            $limpio = UltronDraftPlaceholders::withoutMarkers($texto);
            $this->assertStringNotContainsStringIgnoringCase('LISTA_DE_PLANES', $limpio, $texto);
            $this->assertDoesNotMatchRegularExpression('/[{}]/', $limpio, $texto);
            $this->assertFalse(UltronDraftPlaceholders::looksLikeMarker($limpio), $texto.' → «'.$limpio.'»');
        }
        // Una frase normal sobre la lista no es un marcador.
        $this->assertFalse(UltronDraftPlaceholders::looksLikeMarker('Le paso la lista de planes y sus precios.'));
    }
}
