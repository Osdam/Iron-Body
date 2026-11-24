<?php

namespace Tests\Unit\Marketing\Ultron;

use App\Models\Plan;
use App\Services\Marketing\SalesIntents;
use App\Services\Marketing\Ultron\Prompts\UltronPromptCompiler;
use App\Services\Marketing\Ultron\UltronDraftDefects as D;
use App\Services\Marketing\Ultron\UltronPlansOverview as O;
use App\Services\Marketing\Ultron\UltronRegenerationNotes as N;
use App\Services\Marketing\Ultron\UltronRegisterDetector;
use App\Services\Marketing\Ultron\UltronReplyFitter;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * A4 · TURNO 2 DE LA TERCERA PRUEBA REAL (22-11-2026) · CORRECCIÓN (1): LAS
 * NOTAS DE REESCRITURA CON EL ESQUELETO Y EL PRESUPUESTO DE LA LISTA
 * (`informe-debugger-a4-turno2.md` §1 y §7).
 *
 * Ante «¿Qué planes tienen y cuánto cuesta la mensualidad?», con la lista
 * ofrecida (`po` = [3, 4, 8]) y el tope 200, los tres borradores incumplieron
 * la lista y salió el respaldo: las notas no decían cómo caben los marcadores
 * ni cuánto ocupan expandidos (71 caracteres de los 160 que se piden).
 *
 * Lo que se fija, con las clases reales:
 *  1. El texto LITERAL de las tres variantes (N-P1, N-P2 y N-L con la lista y
 *     el tope) con el catálogo de la prueba: X = 25 + 26 + 20 = 71 (los
 *     `item()` de `Plan`), M = 160 y R = 89.
 *  2. `listBudget()`, el único sitio de la cifra: X, R y M; R nunca negativo;
 *     el plan que ya no se vende cuenta como su marcador literal.
 *  3. Sin tope o sin lista, las notas de HOY, byte a byte (la N-L sin lista es
 *     la del fixture de n8n S2).
 *  4. LA TRAMPA DE LOS 500: `merge()` corta por notas enteras y n8n vuelve a
 *     cortar a 500 (`leer-comprobacion-2.js`). Con las cifras más largas
 *     posibles: (a) la nota de la lista y la de la longitud caben ENTERAS en
 *     toda combinación de las dos; (b) en TODAS las combinaciones de códigos
 *     que pueden ir juntos (y alguna más: un superconjunto), ninguna nota de
 *     más prioridad que hoy entraba se pierde (comparado con los textos de
 *     antes, copiados aquí a mano). Si una falla, se acorta la redacción; el
 *     límite no se sube.
 *  5. ASCII imprimible y sin llaves; cada variante declara sus huecos.
 *  6. CON LA LISTA DEL SERVIDOR (PT-17, puerta 2, L1; las notas de §1.4 que
 *     aprobó el propietario): la nota de la lista y la de la longitud caben
 *     ENTERAS en toda combinación de las dos, también en el peor caso (P1 +
 *     longitud: 491 con un borrador de hasta 4 000 caracteres, el máximo del
 *     endpoint; 493 con las cifras de arriba); y en las demás combinaciones se
 *     cuenta lo que pasa con los textos aprobados, que son más largos.
 *
 * Pura: los `Plan` son instancias en memoria; sin base de datos ni config.
 */
class UltronRegenerationBudgetNotesTest extends TestCase
{
    // ── Los textos de ANTES (HEAD 320caa3d), copiados a mano: la referencia de 4 (b) ──

    private const ANTES_N_P1 = 'FALTA LA LISTA DE PLANES: la persona pidio los planes o los precios en general. Nombra al menos dos planes de commercial_turn_policy.plans_overview, en su orden, escribiendo solo sus marcadores PLAN_1, PLAN_2 o PLAN_3 entre dobles llaves; el backend pone el nombre y el precio. Sin PLAN_NAME ni PLAN_PRICE.';

    private const ANTES_N_P2 = 'MARCADORES MEZCLADOS: con la lista de planes usa solo PLAN_1, PLAN_2 y PLAN_3 entre dobles llaves, cada uno una sola vez, sin PLAN_NAME, PLAN_PRICE ni PLAN_DURATION.';

    private const ANTES_N_L = 'DEMASIADO LARGO: el borrador mide {L} caracteres y el limite publicado es {N}. Reescribelo en unos {M}: primero la respuesta a lo que pregunto la persona, sin relleno, y como mucho una pregunta corta al final. Cada marcador cuenta como el texto que lo sustituye.';

    // ── Los de AHORA, con el catálogo de la prueba real, copiados a mano ──────

    private const N_P1 = 'FALTA LA LISTA DE PLANES: una frase con PLAN_1 a PLAN_3 entre dobles llaves, cada uno una vez y, en su mencion, para quien es; sin PLAN_NAME ni PLAN_PRICE. Ya expandidos ocupan 71 caracteres: quedan 89 de 160. Si no caben, dos; una pregunta corta o ninguna.';

    private const N_P2 = 'MARCADOR REPETIDO O MEZCLADO: una frase con PLAN_1 a PLAN_3 entre dobles llaves, cada uno una vez y, en su mencion, para quien es; sin PLAN_NAME, PLAN_PRICE ni PLAN_DURATION. Ya expandidos ocupan 71 caracteres: quedan 89 de 160.';

    private const N_L = 'DEMASIADO LARGO: mide 251 caracteres y el limite publicado es 200. Reescribelo en unos 160: primero la respuesta, sin relleno. Los marcadores de la lista ya ocupan 71: quedan 89. Si no caben, dos planes; una pregunta corta o ninguna.';

    /** El `sp` de la versión de la prueba: «profesional» y 200. */
    private const SP = ['m' => 'studio', 'r' => null, 'h' => 'x', 'v' => 'v', 'c' => 'c', 'e' => 'e', 's' => 1, 'n' => 200, 't' => 'profesional', 'o' => 1];

    private const PO = [3, 4, 8];

    private const PANORAMA = '¿Qué planes tienen y cuánto cuesta la mensualidad?';

    /** El borrador 3 de la prueba real, sin expandir (200 caracteres): repite `{{PLAN_3}}`. */
    private const BORRADOR_3 = 'Le comento que tenemos el {{PLAN_1}}, el {{PLAN_2}} y el {{PLAN_3}}. Por ejemplo, el {{PLAN_3}} incluye acceso ilimitado, asesoría y seguimiento con entrenador. ¿Qué busca lograr con su entrenamiento?';

    /**
     * Las cifras más largas posibles, por construcción:
     *  - L: el borrador tiene como mucho 4000 caracteres (la puerta), y cada
     *    marcador de 10 se expande a un texto de menos de 300 (abajo): menos
     *    de 120.000 → 6 cifras.
     *  - X: tres `item()`, cada uno con un nombre de 255 (la columna), la
     *    duración en palabras y el precio de `decimal(10,2)`: menos de 1000
     *    → 3 cifras.
     *  - N y M: el tope del compilador (480) y su objetivo (380); R ≤ M; K ≤ 3.
     */
    private const PEOR = ['K' => 3, 'X' => 999, 'R' => 380, 'M' => 380, 'L' => 999999, 'N' => 480];

    /** El catálogo de la prueba real (Z1): id → nombre, precio y días. */
    private const CATALOGO = [3 => ['Plan Valera', 65000, 30], 4 => ['Plan Mensual', 80000, 30], 8 => ['Élite', 180000, 30]];

    /** @return array<int,string> plan_id → su texto, con `item()` sobre un `Plan` en memoria */
    private static function textos(): array
    {
        $out = [];
        foreach (self::CATALOGO as $id => [$nombre, $precio, $dias]) {
            $out[$id] = O::item((new Plan)->forceFill(['name' => $nombre, 'price' => $precio, 'duration_days' => $dias]));
        }

        return $out;
    }

    /** @return list<array{id:int,name:string,duration_days:int,benefits:list<string>}> */
    private static function catalogo(): array
    {
        $out = [];
        foreach (self::CATALOGO as $id => [$nombre, , $dias]) {
            $out[] = ['id' => $id, 'name' => $nombre, 'duration_days' => $dias, 'benefits' => ['Acceso total al gimnasio']];
        }

        return $out;
    }

    /** @return list<array{code:string,detail:?string,issue:string,note:string}> */
    private static function defectos(string $borrador, array $sp = self::SP, array $po = self::PO, ?array $textos = null): array
    {
        $textos ??= self::textos();
        $expandido = O::expand($borrador, $textos, $po)['text'];

        return D::of(['reply_draft' => $borrador, 'intent' => SalesIntents::PRICING_QUESTION, 'tools_requested' => []], $expandido, $sp, $po, self::catalogo(), self::PANORAMA, array_intersect_key($textos, array_flip($po)));
    }

    // ── 1. El texto literal con el catálogo de la prueba ────────────────────

    public function test_con_el_catalogo_de_la_prueba_los_marcadores_ocupan_71_y_quedan_89(): void
    {
        $textos = self::textos();
        $this->assertSame([3 => 'Plan Valera ($65.000 COP)', 4 => 'Plan Mensual ($80.000 COP)', 8 => 'Élite ($180.000 COP)'], $textos);
        $this->assertSame([25, 26, 20], array_values(array_map(UltronReplyFitter::measure(...), $textos)));
        $this->assertSame(['X' => 71, 'R' => 89, 'M' => 160], D::listBudget(array_values($textos), 200));
        $this->assertSame(UltronPromptCompiler::composerTargetChars(200), 160);
    }

    public function test_el_texto_literal_de_cada_nota_con_el_presupuesto(): void
    {
        $this->assertSame(self::N_P1, N::budgetNote(N::PLANS_OVERVIEW, O::MISSING, ['K' => 3, 'X' => 71, 'R' => 89, 'M' => 160]));
        $this->assertSame(self::N_P2, N::budgetNote(N::PLANS_OVERVIEW, O::MIXED, ['K' => 3, 'X' => 71, 'R' => 89, 'M' => 160]));
        $this->assertSame(self::N_L, N::budgetNote(N::LENGTH_OVER_CAP, null, ['L' => 251, 'N' => 200, 'M' => 160, 'X' => 71, 'R' => 89]));
        // El `issue` no cambia: es el de su nota de siempre.
        $this->assertSame(['relevance', 'relevance', 'progressive_disclosure'], [N::issue(N::PLANS_OVERVIEW, O::MISSING), N::issue(N::PLANS_OVERVIEW, O::MIXED), N::issue(N::LENGTH_OVER_CAP)]);
    }

    /** El borrador 3 de la prueba real: `mixed` y 251 expandido; las dos notas, con su presupuesto y unidas, enteras. */
    public function test_el_borrador_3_de_la_prueba_real_recibe_las_dos_notas_con_su_presupuesto(): void
    {
        $this->assertSame(200, mb_strlen(self::BORRADOR_3));
        $this->assertSame(251, UltronReplyFitter::measure(O::expand(self::BORRADOR_3, self::textos(), self::PO)['text']));

        $d = self::defectos(self::BORRADOR_3);
        $this->assertSame([['plans_overview', 'mixed'], ['length_over_cap', null]], array_map(fn (array $x) => [$x['code'], $x['detail']], $d));
        $this->assertSame([self::N_P2, self::N_L], array_column($d, 'note'));
        $this->assertSame(self::N_P2.' '.self::N_L, N::merge(array_column($d, 'note')), 'las dos, enteras');
        $this->assertSame(mb_strlen(self::N_P2) + 1 + mb_strlen(self::N_L), mb_strlen(N::merge(array_column($d, 'note'))));
    }

    public function test_un_borrador_de_un_solo_plan_recibe_la_falta_con_su_presupuesto(): void
    {
        $d = self::defectos('Claro. El {{PLAN_PRICE}} es la mensualidad del Plan Valera. ¿Le interesa?');
        $this->assertSame('plans_overview/missing', $d[0]['code'].'/'.$d[0]['detail']);
        $this->assertSame(self::N_P1, $d[0]['note']);

        // Con dos ofrecidos, K = 2 y la cifra de los dos.
        $d = self::defectos('Claro. Tenemos el {{PLAN_1}}. ¿Le interesa?', po: [3, 4]);
        $this->assertStringContainsString('una frase con PLAN_1 a PLAN_2 entre dobles llaves', $d[0]['note']);
        $this->assertStringContainsString('Ya expandidos ocupan 51 caracteres: quedan 109 de 160.', $d[0]['note']);
    }

    // ── 2. La cifra, en un solo sitio ───────────────────────────────────────

    public function test_la_cifra_del_presupuesto(): void
    {
        $this->assertSame(['X' => 30, 'R' => 130, 'M' => 160], D::listBudget(['{{PLAN_1}}', '{{PLAN_2}}', '{{PLAN_3}}'], 200), 'sin plan vendible, cada marcador cuenta como él mismo');
        $this->assertSame(['X' => 400, 'R' => 0, 'M' => 120], D::listBudget([str_repeat('a', 200), str_repeat('b', 200)], 160), 'R nunca negativo');
        $this->assertSame(['X' => 25, 'R' => 355, 'M' => 380], D::listBudget(['Plan Valera ($65.000 COP)'], 480));
        // Con la medida del tope: las URL no cuentan (no hay en un nombre de plan, pero es la misma medida).
        $this->assertSame(UltronReplyFitter::measure('Élite ($180.000 COP)'), D::listBudget(['Élite ($180.000 COP)'], 200)['X']);

        // Un plan de la lista que ya no se vende: la expansión deja su marcador literal, y así cuenta.
        $sinElite = array_diff_key(self::textos(), [8 => 1]);
        $d = self::defectos(self::BORRADOR_3, textos: $sinElite);
        $this->assertStringContainsString('Ya expandidos ocupan 61 caracteres: quedan 99 de 160.', $d[0]['note'], '25 + 26 + 10');
    }

    // ── 3. Sin tope o sin lista, las notas de hoy ───────────────────────────

    public function test_sin_tope_o_sin_lista_salen_las_notas_de_hoy_byte_a_byte(): void
    {
        $sinTope = array_diff_key(self::SP, ['n' => 1]);
        $d = self::defectos(self::BORRADOR_3, $sinTope);
        $this->assertSame(['plans_overview/mixed'], array_map(fn (array $x) => $x['code'].'/'.$x['detail'], $d), 'sin tope no se mide');
        $this->assertSame(self::ANTES_N_P2, $d[0]['note']);
        $this->assertSame(self::ANTES_N_P1, self::defectos('Claro. Tenemos el {{PLAN_1}}. ¿Le interesa?', $sinTope)[0]['note']);

        // Sin lista: la N-L de hoy (la del fixture de n8n S2), con la medida del texto.
        $largo = 'Claro. El Plan Valera cuesta {{PLAN_PRICE}} al mes. '.str_repeat('Le explico cada detalle con calma. ', 5).'¿Le interesa?';
        $expandido = str_replace('{{PLAN_PRICE}}', '$65.000 COP', $largo);
        $d = D::of(['reply_draft' => $largo, 'intent' => SalesIntents::PRICING_QUESTION, 'tools_requested' => []], $expandido, self::SP, [], self::catalogo(), self::PANORAMA, self::textos());
        $this->assertSame(['length_over_cap'], array_column($d, 'code'));
        $this->assertSame(strtr(self::ANTES_N_L, ['{L}' => (string) UltronReplyFitter::measure($expandido), '{N}' => '200', '{M}' => '160']), $d[0]['note']);
        $this->assertSame(self::ANTES_N_L, N::entries()[1][3], 'la plantilla de siempre, intacta');

        // Las demás notas de la lista no tienen variante.
        $this->assertSame('NOMBRE DUPLICADO: no escribas el nombre de un plan de la lista; su marcador ya pone el nombre y el precio.', self::defectos('Tenemos el Plan Valera {{PLAN_1}} y {{PLAN_2}}.')[0]['note']);
    }

    // ── 4. La trampa de los 500 ─────────────────────────────────────────────

    /**
     * (a) La nota de la lista y la de la longitud, con la lista y el tope y
     * las cifras más largas posibles: en cada combinación de las dos (con K = 2
     * y K = 3), todas las notas entran ENTERAS en `merge()`, y lo que queda
     * cabe en los 500 que corta n8n (ASCII: un carácter es una unidad de JS).
     */
    public function test_la_lista_y_la_longitud_caben_enteras_en_el_peor_caso(): void
    {
        $vistas = 0;
        foreach ([2, 3] as $k) {
            $peor = ['K' => $k] + self::PEOR;
            $lista = [
                null,
                N::budgetNote(N::PLANS_OVERVIEW, O::MISSING, array_intersect_key($peor, array_flip(['K', 'X', 'R', 'M']))),
                N::budgetNote(N::PLANS_OVERVIEW, O::MIXED, array_intersect_key($peor, array_flip(['K', 'X', 'R', 'M']))),
                N::note(N::PLANS_OVERVIEW, O::NAME_WRITTEN),
                N::note(N::PLANS_OVERVIEW, O::UNKNOWN_MARKER, ['K' => $k]),
                N::note(N::PLANS_OVERVIEW, O::PRICE_WRITTEN),
            ];
            $longitud = N::budgetNote(N::LENGTH_OVER_CAP, null, array_intersect_key($peor, array_flip(['L', 'N', 'M', 'X', 'R'])));
            foreach ($lista as $primera) {
                foreach ([null, $longitud] as $segunda) {
                    $notas = array_values(array_filter([$primera, $segunda], 'is_string'));
                    if ($notas === []) {
                        continue;
                    }
                    $mezcla = N::merge($notas);
                    $this->assertSame(implode(' ', $notas), $mezcla, 'ninguna se pierde: '.mb_substr($mezcla, 0, 40));
                    $this->assertLessThanOrEqual(N::MAX_NOTES, mb_strlen($mezcla));
                    $this->assertMatchesRegularExpression('/^[\x20-\x7E]+$/', $mezcla, 'ASCII: el corte de n8n (UTF-16) mide lo mismo');
                    $vistas++;
                }
            }
        }
        $this->assertSame(22, $vistas);
        // Las dos que pasaron en la prueba real, con su holgura medida en el peor caso.
        $p1 = N::budgetNote(N::PLANS_OVERVIEW, O::MISSING, array_intersect_key(self::PEOR, array_flip(['K', 'X', 'R', 'M'])));
        $p2 = N::budgetNote(N::PLANS_OVERVIEW, O::MIXED, array_intersect_key(self::PEOR, array_flip(['K', 'X', 'R', 'M'])));
        $l = N::budgetNote(N::LENGTH_OVER_CAP, null, array_intersect_key(self::PEOR, array_flip(['L', 'N', 'M', 'X', 'R'])));
        $this->assertSame([498, 469], [mb_strlen($p1.' '.$l), mb_strlen($p2.' '.$l)]);
    }

    /**
     * (b) TODAS las combinaciones, en el orden en que las da la comprobación
     * (la lista, el trato, la longitud, el beneficio y hasta cuatro de la
     * política, en su orden): un superconjunto de las reales. Con las cifras y
     * los textos más largos posibles, comparadas con los textos de ANTES: la
     * primera nota que cambia de suerte nunca es una que antes entraba y ahora
     * no (orden lexicográfico por prioridad, que es como `merge()` elige).
     * Que hoy ya se pierden notas en muchas (con el trato, el beneficio o la
     * política) es de antes y se cuenta: no aumenta.
     */
    public function test_ninguna_combinacion_pierde_una_nota_de_mas_prioridad_que_hoy_entraba(): void
    {
        // Las cinco marcas más largas de la lista cerrada, y el plan y el beneficio a 60.
        $marcas = UltronRegisterDetector::MARCAS;
        usort($marcas, fn (string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));
        $comunes = [
            'R' => N::note(N::REGISTER_TUTEO, null, ['MARCAS' => array_slice($marcas, 0, UltronRegisterDetector::MAX_MARCAS)]),
            'B' => N::note(N::BENEFIT_MISATTRIBUTED, null, ['PLAN' => str_repeat('p', 60), 'BENEFICIO' => str_repeat('b', 60)]),
            O::NAME_WRITTEN => N::note(N::PLANS_OVERVIEW, O::NAME_WRITTEN),
            O::UNKNOWN_MARKER => N::note(N::PLANS_OVERVIEW, O::UNKNOWN_MARKER, ['K' => 3]),
            O::PRICE_WRITTEN => N::note(N::PLANS_OVERVIEW, O::PRICE_WRITTEN),
        ];
        foreach (N::POLICY_CODES as $c) {
            $comunes[$c] = N::note($c);
        }
        $antes = $comunes + [
            O::MISSING => self::ANTES_N_P1,
            O::MIXED => self::ANTES_N_P2,
            'L' => strtr(self::ANTES_N_L, ['{L}' => (string) self::PEOR['L'], '{N}' => (string) self::PEOR['N'], '{M}' => (string) self::PEOR['M']]),
        ];
        $ahora = $comunes + [
            O::MISSING => N::budgetNote(N::PLANS_OVERVIEW, O::MISSING, array_intersect_key(self::PEOR, array_flip(['K', 'X', 'R', 'M']))),
            O::MIXED => N::budgetNote(N::PLANS_OVERVIEW, O::MIXED, array_intersect_key(self::PEOR, array_flip(['K', 'X', 'R', 'M']))),
            'L' => N::budgetNote(N::LENGTH_OVER_CAP, null, array_intersect_key(self::PEOR, array_flip(['L', 'N', 'M', 'X', 'R']))),
        ];

        /*
         * Qué notas entran (1) y cuáles no (0), en el orden de la combinación:
         * la misma regla que `merge()` (por notas enteras, a 500). Que es la
         * suya lo comprueba, en cada combinación, la aserción de abajo contra
         * el `merge()` real.
         */
        $entran = function (array $codigos, array $textos): array {
            $out = [];
            $hasta = '';
            foreach ($codigos as $c) {
                $con = $hasta === '' ? $textos[$c] : $hasta.' '.$textos[$c];
                $cabe = mb_strlen($con) <= N::MAX_NOTES;
                $hasta = $cabe ? $con : $hasta;
                $out[] = $cabe ? 1 : 0;
            }

            return $out;
        };

        $politica = [[]];
        foreach (N::POLICY_CODES as $p) {
            foreach ($politica as $s) {
                if (count($s) < 4) {
                    $politica[] = [...$s, $p];
                }
            }
        }
        $combinaciones = 0;
        $pierdenAntes = 0;
        $pierdenAhora = 0;
        foreach ([null, O::MISSING, O::MIXED, O::NAME_WRITTEN, O::UNKNOWN_MARKER, O::PRICE_WRITTEN] as $a) {
            foreach ([false, true] as $tuteo) {
                foreach ([false, true] as $largo) {
                    foreach ([false, true] as $beneficio) {
                        foreach ($politica as $p) {
                            $codigos = [...array_filter([$a, $tuteo ? 'R' : null, $largo ? 'L' : null, $beneficio ? 'B' : null]), ...$p];
                            if ($codigos === []) {
                                continue;
                            }
                            $combinaciones++;
                            $vAntes = $entran($codigos, $antes);
                            $vAhora = $entran($codigos, $ahora);
                            $pierdenAntes += in_array(0, $vAntes, true) ? 1 : 0;
                            $pierdenAhora += in_array(0, $vAhora, true) ? 1 : 0;
                            $this->assertGreaterThanOrEqual($vAntes, $vAhora, 'pierde una nota que hoy entraba: '.implode(' + ', $codigos));
                            // Y lo que entra es exactamente lo que deja `merge()`.
                            $this->assertSame(N::merge(array_map(fn (string $c) => $ahora[$c], $codigos)), implode(' ', array_map(fn (string $c) => $ahora[$c], array_values(array_intersect_key($codigos, array_filter($vAhora))))));
                        }
                    }
                }
            }
        }
        $this->assertSame(2735, $combinaciones);
        $this->assertSame([2628, 2623], [$pierdenAntes, $pierdenAhora], 'las que ya perdían alguna nota (con el peor caso de todo) no aumentan');
    }

    // ── 5. La forma ─────────────────────────────────────────────────────────

    public function test_cada_variante_declara_sus_huecos_y_no_lleva_llaves(): void
    {
        $this->assertSame([['length_over_cap', null], ['plans_overview', 'missing'], ['plans_overview', 'mixed']], array_map(fn (array $e) => [$e[0], $e[1]], N::budgetEntries()));
        foreach (N::budgetEntries() as [$code, $detail, $params, $plantilla]) {
            preg_match_all('/\{([A-Z]+)\}/', $plantilla, $m);
            $huecos = array_values(array_unique($m[1]));
            sort($huecos);
            sort($params);
            $this->assertSame($params, $huecos, $code.'/'.$detail);
            $nota = N::budgetNote($code, $detail, array_intersect_key(['K' => 3, 'X' => PHP_INT_MAX, 'R' => 0, 'M' => 380, 'L' => PHP_INT_MAX, 'N' => 480], array_flip($params)));
            $this->assertDoesNotMatchRegularExpression('/[{}]/', $nota);
            $this->assertMatchesRegularExpression('/^[\x20-\x7E]+$/', $nota, 'ASCII imprimible, sin tildes: cruza al modelo');
            $this->assertLessThanOrEqual(N::MAX_NOTES, mb_strlen($nota), 'cada una cabe sola');
        }
    }

    // ── 6. Con la lista del servidor (PT-17, puerta 2, L1) ──────────────────

    /**
     * (a) La nota de la lista y la de la longitud con la lista del servidor,
     * con las cifras más largas posibles: X = 999, N = 480 (el tope máximo),
     * R = 480 y L con 4 cifras (el borrador llega con 4 000 caracteres como
     * mucho, `UltronController::check()`) o con las 6 de arriba. En cada
     * combinación de las dos, todas entran ENTERAS en `merge()` y caben en los
     * 500 que corta n8n; la peor, P1 + longitud, mide 491 (la cifra de la
     * especificación, §1.4) y 493 con L de 6 cifras. Sin lista (`no_list`), la
     * longitud es la nota de siempre: no hay lista que medir.
     */
    public function test_con_la_lista_del_servidor_la_lista_y_la_longitud_caben_enteras_en_el_peor_caso(): void
    {
        $servidor = ['X' => 999, 'N' => 480, 'R' => 480];
        foreach ([9999 => 491, self::PEOR['L'] => 493] as $l => $peor) {
            $longitud = N::serverNote(N::LENGTH_OVER_CAP, null, ['L' => $l] + $servidor);
            $lista = [
                null,
                N::serverNote(N::PLANS_OVERVIEW, O::MISSING, $servidor),
                N::serverNote(N::PLANS_OVERVIEW, O::MIXED, $servidor),
                N::serverNote(N::PLANS_OVERVIEW, O::NAME_WRITTEN),
                N::serverNote(N::PLANS_OVERVIEW, O::UNKNOWN_MARKER),
                N::serverNote(N::PLANS_OVERVIEW, O::PRICE_WRITTEN),
            ];
            $vistas = 0;
            foreach ($lista as $primera) {
                foreach ([null, $longitud] as $segunda) {
                    $notas = array_values(array_filter([$primera, $segunda], 'is_string'));
                    if ($notas === []) {
                        continue;
                    }
                    $mezcla = N::merge($notas);
                    $this->assertSame(implode(' ', $notas), $mezcla, 'ninguna se pierde: '.mb_substr($mezcla, 0, 40));
                    $this->assertLessThanOrEqual(N::MAX_NOTES, mb_strlen($mezcla));
                    $this->assertMatchesRegularExpression('/^[\x20-\x7E]+$/', $mezcla, 'ASCII: el corte de n8n (UTF-16) mide lo mismo');
                    $vistas++;
                }
            }
            $this->assertSame(11, $vistas);
            $this->assertSame($peor, mb_strlen(N::merge([$lista[1], $longitud])), "P1 + longitud con L = {$l}");
            $this->assertLessThan($peor, mb_strlen(N::merge([$lista[2], $longitud])), 'P2 + longitud, menos');
        }
        // Sin lista: la del servidor sola, y con la longitud de siempre.
        $sinLista = N::serverNote(N::PLANS_OVERVIEW, O::NO_LIST);
        $deSiempre = N::note(N::LENGTH_OVER_CAP, null, ['L' => self::PEOR['L'], 'N' => self::PEOR['N'], 'M' => self::PEOR['M']]);
        $this->assertSame($sinLista.' '.$deSiempre, N::merge([$sinLista, $deSiempre]));
        $this->assertLessThanOrEqual(N::MAX_NOTES, mb_strlen($sinLista.' '.$deSiempre));
    }

    /**
     * (b) Las demás combinaciones (la lista, el trato, la longitud, el
     * beneficio y hasta cuatro de la política, como en 4 b), con las notas del
     * servidor frente a las de los marcadores. La de la lista va primero y
     * nunca se pierde, ni la de la longitud cuando va con ella sin el trato.
     * Los textos aprobados son más largos (NOMBRE DUPLICADO: 196 frente a
     * 106; MARCADOR INEXISTENTE: 142 frente a 96), así que en 69 combinaciones
     * (34 con NOMBRE DUPLICADO y 35 con MARCADOR INEXISTENTE, todas con dos o
     * más de la política y sin el trato, la longitud ni el beneficio) se queda
     * fuera una nota de la POLÍTICA que con los marcadores entraba: la de menos
     * prioridad, que vuelve a pedir la comprobación siguiente. Se cuenta aquí
     * para que no crezca sin verse.
     */
    public function test_con_la_lista_del_servidor_las_demas_combinaciones_se_cuentan(): void
    {
        $marcas = UltronRegisterDetector::MARCAS;
        usort($marcas, fn (string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));
        $comunes = [
            'R' => N::note(N::REGISTER_TUTEO, null, ['MARCAS' => array_slice($marcas, 0, UltronRegisterDetector::MAX_MARCAS)]),
            'B' => N::note(N::BENEFIT_MISATTRIBUTED, null, ['PLAN' => str_repeat('p', 60), 'BENEFICIO' => str_repeat('b', 60)]),
        ];
        foreach (N::POLICY_CODES as $c) {
            $comunes[$c] = N::note($c);
        }
        $marcadores = $comunes + [
            O::NAME_WRITTEN => N::note(N::PLANS_OVERVIEW, O::NAME_WRITTEN),
            O::UNKNOWN_MARKER => N::note(N::PLANS_OVERVIEW, O::UNKNOWN_MARKER, ['K' => 3]),
            O::PRICE_WRITTEN => N::note(N::PLANS_OVERVIEW, O::PRICE_WRITTEN),
            O::NO_LIST => N::note(N::PLANS_OVERVIEW, O::NO_LIST),
            O::MISSING => N::budgetNote(N::PLANS_OVERVIEW, O::MISSING, array_intersect_key(self::PEOR, array_flip(['K', 'X', 'R', 'M']))),
            O::MIXED => N::budgetNote(N::PLANS_OVERVIEW, O::MIXED, array_intersect_key(self::PEOR, array_flip(['K', 'X', 'R', 'M']))),
            'L' => N::budgetNote(N::LENGTH_OVER_CAP, null, array_intersect_key(self::PEOR, array_flip(['L', 'N', 'M', 'X', 'R']))),
        ];
        $servidor = ['X' => 999, 'N' => 480, 'R' => 480];
        $delServidor = $comunes + [
            O::NAME_WRITTEN => N::serverNote(N::PLANS_OVERVIEW, O::NAME_WRITTEN),
            O::UNKNOWN_MARKER => N::serverNote(N::PLANS_OVERVIEW, O::UNKNOWN_MARKER),
            O::PRICE_WRITTEN => N::serverNote(N::PLANS_OVERVIEW, O::PRICE_WRITTEN),
            O::NO_LIST => N::serverNote(N::PLANS_OVERVIEW, O::NO_LIST),
            O::MISSING => N::serverNote(N::PLANS_OVERVIEW, O::MISSING, $servidor),
            O::MIXED => N::serverNote(N::PLANS_OVERVIEW, O::MIXED, $servidor),
            'L' => N::serverNote(N::LENGTH_OVER_CAP, null, ['L' => self::PEOR['L']] + $servidor),
        ];
        $entran = function (array $codigos, array $textos): array {
            $out = [];
            $hasta = '';
            foreach ($codigos as $c) {
                $con = $hasta === '' ? $textos[$c] : $hasta.' '.$textos[$c];
                $cabe = mb_strlen($con) <= N::MAX_NOTES;
                $hasta = $cabe ? $con : $hasta;
                $out[] = $cabe ? 1 : 0;
            }

            return $out;
        };
        $politica = [[]];
        foreach (N::POLICY_CODES as $p) {
            foreach ($politica as $s) {
                if (count($s) < 4) {
                    $politica[] = [...$s, $p];
                }
            }
        }
        $combinaciones = 0;
        $pierdenConMarcadores = 0;
        $pierdenConElServidor = 0;
        $peores = [];
        foreach ([null, O::MISSING, O::MIXED, O::NAME_WRITTEN, O::UNKNOWN_MARKER, O::PRICE_WRITTEN, O::NO_LIST] as $a) {
            foreach ([false, true] as $tuteo) {
                foreach ([false, true] as $largo) {
                    foreach ([false, true] as $beneficio) {
                        foreach ($politica as $p) {
                            $codigos = [...array_filter([$a, $tuteo ? 'R' : null, $largo ? 'L' : null, $beneficio ? 'B' : null]), ...$p];
                            if ($codigos === []) {
                                continue;
                            }
                            $combinaciones++;
                            $vMarcadores = $entran($codigos, $marcadores);
                            $vServidor = $entran($codigos, $delServidor);
                            $pierdenConMarcadores += in_array(0, $vMarcadores, true) ? 1 : 0;
                            $pierdenConElServidor += in_array(0, $vServidor, true) ? 1 : 0;
                            if ($vServidor < $vMarcadores) {
                                // La primera nota que cambia de suerte: una de la política.
                                $i = 0;
                                while ($vServidor[$i] === $vMarcadores[$i]) {
                                    $i++;
                                }
                                $this->assertContains($codigos[$i], N::POLICY_CODES, 'lo que se pierde es de la política: '.implode(' + ', $codigos));
                                $peores[] = $codigos;
                            }
                            if ($a !== null) {
                                $this->assertSame(1, $vServidor[0], 'la de la lista nunca se pierde: '.implode(' + ', $codigos));
                            }
                            if ($a !== null && $a !== O::NO_LIST && $largo && ! $tuteo) {
                                $this->assertSame(1, $vServidor[1], 'ni la longitud que va con ella: '.implode(' + ', $codigos));
                            }
                            // Lo que entra es exactamente lo que deja `merge()`.
                            $this->assertSame(N::merge(array_map(fn (string $c) => $delServidor[$c], $codigos)), implode(' ', array_map(fn (string $c) => $delServidor[$c], array_values(array_intersect_key($codigos, array_filter($vServidor))))));
                        }
                    }
                }
            }
        }
        $this->assertSame(3191, $combinaciones);
        $this->assertSame([3066, 3074], [$pierdenConMarcadores, $pierdenConElServidor]);
        $this->assertCount(69, $peores);
        $this->assertSame([O::NAME_WRITTEN => 34, O::UNKNOWN_MARKER => 35], array_count_values(array_column($peores, 0)), 'con NOMBRE DUPLICADO o MARCADOR INEXISTENTE');
        foreach ($peores as $codigos) {
            $this->assertGreaterThanOrEqual(2, count(array_intersect($codigos, N::POLICY_CODES)), 'y dos o más de la política: '.implode(' + ', $codigos));
            $this->assertSame([], array_intersect($codigos, ['R', 'L', 'B']), 'sin el trato, la longitud ni el beneficio: '.implode(' + ', $codigos));
        }
    }

    public function test_las_notas_del_servidor_declaran_sus_huecos_y_no_llevan_llaves(): void
    {
        $this->assertSame([
            ['plans_overview', 'missing'], ['plans_overview', 'mixed'], ['plans_overview', 'name_written'], ['plans_overview', 'unknown_marker'],
            ['plans_overview', 'no_list'], ['plans_overview', 'price_written'], ['length_over_cap', null],
        ], array_map(fn (array $e) => [$e[0], $e[1]], N::serverEntries()), 'las siete de §1.4, en su orden');
        foreach (N::serverEntries() as [$code, $detail, $params, $plantilla]) {
            preg_match_all('/\{([A-Z]+)\}/', $plantilla, $m);
            $huecos = array_values(array_unique($m[1]));
            sort($huecos);
            sort($params);
            $this->assertSame($params, $huecos, $code.'/'.$detail);
            $nota = N::serverNote($code, $detail, array_intersect_key(['X' => PHP_INT_MAX, 'N' => 480, 'R' => 0, 'L' => PHP_INT_MAX], array_flip($params)));
            $this->assertDoesNotMatchRegularExpression('/[{}]/', $nota);
            $this->assertMatchesRegularExpression('/^[\x20-\x7E]+$/', $nota, 'ASCII imprimible, sin tildes: cruza al modelo');
            $this->assertStringNotContainsString('PLAN_1', $nota, 'con la lista del servidor el modelo no ve marcadores de la lista');
        }
        foreach ([
            'sin variante' => [N::REGISTER_TUTEO, null, ['MARCAS' => ['te']]],
            'falta R' => [N::PLANS_OVERVIEW, O::MISSING, ['X' => 75, 'N' => 320]],
            'los de la nota de siempre' => [N::LENGTH_OVER_CAP, null, ['L' => 340, 'N' => 320, 'M' => 250]],
            'X como texto' => [N::PLANS_OVERVIEW, O::MIXED, ['X' => '75', 'N' => 320, 'R' => 245]],
            'con huecos que no tiene' => [N::PLANS_OVERVIEW, O::NAME_WRITTEN, ['X' => 75]],
        ] as $nombre => [$code, $detail, $params]) {
            try {
                N::serverNote($code, $detail, $params);
                $this->fail("{$nombre}: no lanzó");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_los_parametros_de_la_variante_se_exigen_exactos(): void
    {
        $malos = [
            'nota sin variante' => [N::REGISTER_TUTEO, null, ['MARCAS' => ['te']]],
            'detalle sin variante' => [N::PLANS_OVERVIEW, O::NAME_WRITTEN, []],
            'falta X' => [N::PLANS_OVERVIEW, O::MISSING, ['K' => 3, 'R' => 89, 'M' => 160]],
            'los de la nota de siempre' => [N::LENGTH_OVER_CAP, null, ['L' => 251, 'N' => 200, 'M' => 160]],
            'R negativo' => [N::LENGTH_OVER_CAP, null, ['L' => 251, 'N' => 200, 'M' => 160, 'X' => 171, 'R' => -11]],
            'X como texto' => [N::PLANS_OVERVIEW, O::MIXED, ['K' => 3, 'X' => '71', 'R' => 89, 'M' => 160]],
            'código desconocido' => ['otro', null, []],
        ];
        foreach ($malos as $nombre => [$code, $detail, $params]) {
            try {
                N::budgetNote($code, $detail, $params);
                $this->fail("{$nombre}: no lanzó");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
