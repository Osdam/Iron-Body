<?php

namespace Tests\Unit\Outreach;

use App\Services\UltronStudio\StudioContentHasher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * La CORDURA de los fixtures del Hito 9 (TEST9-a; contrato H9, §11 y §13):
 * `tests/fixtures/outreach/*.json` son el oráculo de B9-core, B9-run, S9 y FE9,
 * así que antes de que nadie pruebe su código contra ellos tienen que estar bien
 * formados, sin nombres repetidos y con sus huellas de verdad.
 *
 * Aquí NO hay código del motor (nada de `App\Services\Outreach`): solo la forma
 * de cada fichero, la unicidad de sus casos, que cada decisión usada esté
 * escrita, y que cada huella (catálogo, JSON canónico, traza, cadena) sea lo que
 * da `StudioContentHasher` del H5, la misma clase que el contrato manda usar.
 * Que un caso diga lo correcto lo decide el contrato y lo prueban los paquetes
 * dueños; que el fichero no se contradiga a sí mismo lo prueba esta clase.
 *
 * `OUTREACH_FIXTURES_DIR` permite pasarla sobre una copia (así se comprueba que
 * falla con un fixture estropeado); sin ella, lee el directorio del repo.
 */
class FixturesSanityTest extends TestCase
{
    /** Los ficheros que tiene que haber (contrato §11 y el encargo de TEST9-a). */
    private const FILES = [
        'calendar-cases.json',
        'catalog-snapshot.json',
        'channel-selection-cases.json',
        'conditions-cases.json',
        'contact-budget-cases.json',
        'followup-cases.json',
        'outcome-transitions.json',
        'policy-ladder-cases.json',
        'priority-cases.json',
        'strategy-chain-vectors.json',
        'strategy-content-cases.json',
    ];

    /** La APP_KEY sintética de los fixtures del H6, H8 y H9 (bytes 00..1f). */
    private const APP_KEY_B64 = 'base64:AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8=';

    private const ISO_Z = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/';

    private const HEADER = ['fixture', 'version', 'contract', 'contractSections', 'generatedBy', 'checkedBy', 'readme', 'basisLegend', 'sources', 'syntheticData', 'decisions', 'counts'];

    private const BASES = ['contrato', 'ley', 'codigo-existente', 'supuesto-lead', 'decision-fixture'];

    private const RESULTS = ['true', 'false', 'unknown'];

    /** @var array<string, array<string, mixed>> */
    private static array $cache = [];

    private static function dir(): string
    {
        $env = getenv('OUTREACH_FIXTURES_DIR');

        return is_string($env) && $env !== '' ? rtrim($env, '/') : dirname(__DIR__, 3).'/tests/fixtures/outreach';
    }

    private static function raw(string $file): string
    {
        $path = self::dir().'/'.$file;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /** @return array<string, mixed> */
    private static function load(string $file): array
    {
        if (! isset(self::$cache[self::dir().$file])) {
            self::$cache[self::dir().$file] = json_decode(self::raw($file), true, 512, JSON_THROW_ON_ERROR);
        }

        return self::$cache[self::dir().$file];
    }

    /** @return list<string> */
    private static function sections(array $doc): array
    {
        return array_values(array_filter(array_keys($doc['counts']), fn ($k) => $k !== 'total'));
    }

    /** @return iterable<string, array{string}> */
    public static function files(): iterable
    {
        foreach (self::FILES as $f) {
            yield $f => [$f];
        }
    }

    public function test_estan_todos_los_ficheros_y_ninguno_de_mas(): void
    {
        $hay = array_map('basename', glob(self::dir().'/*.json') ?: []);
        sort($hay);
        $esperados = self::FILES;
        sort($esperados);
        $this->assertSame($esperados, $hay);
    }

    #[DataProvider('files')]
    public function test_la_cabecera_esta_completa(string $file): void
    {
        $doc = self::load($file);
        foreach (self::HEADER as $k) {
            $this->assertArrayHasKey($k, $doc, "$file: falta $k");
        }
        $this->assertSame($file, $doc['fixture']);
        $this->assertSame(1, $doc['version']);
        $this->assertSame(self::BASES, array_keys($doc['basisLegend']));
        $this->assertNotEmpty($doc['contractSections']);
        $this->assertNotEmpty($doc['sources']);
        foreach ($doc['sources'] as $i => $s) {
            foreach (['what', 'url', 'consulted', 'how'] as $k) {
                $this->assertIsString($s[$k] ?? null, "$file: sources[$i].$k");
            }
        }
    }

    #[DataProvider('files')]
    public function test_los_recuentos_cuadran(string $file): void
    {
        $doc = self::load($file);
        $total = 0;
        foreach (self::sections($doc) as $sec) {
            $this->assertIsList($doc[$sec], "$file: $sec no es una lista");
            $this->assertCount($doc['counts'][$sec], $doc[$sec], "$file: recuento de $sec");
            $total += count($doc[$sec]);
        }
        $this->assertSame($doc['counts']['total'], $total);
        $this->assertGreaterThan(0, $total);
    }

    #[DataProvider('files')]
    public function test_cada_caso_tiene_nombre_unico_seccion_kind_y_base(string $file): void
    {
        $doc = self::load($file);
        $nombres = [];
        foreach (self::sections($doc) as $sec) {
            foreach ($doc[$sec] as $i => $c) {
                foreach (['name', 'kind', 'contractRef', 'basis', 'expected'] as $k) {
                    $this->assertArrayHasKey($k, $c, "{$file}: {$sec}[{$i}] sin {$k}");
                }
                $this->assertStringStartsWith("{$sec} · {$c['kind']} · ", $c['name']);
                $this->assertArrayNotHasKey($c['name'], $nombres, "$file: nombre repetido «{$c['name']}»");
                $nombres[$c['name']] = true;
                $this->assertContains($c['basis'], self::BASES, $c['name']);
                if ($c['basis'] === 'decision-fixture') {
                    $this->assertIsString($c['decision'] ?? null, "{$c['name']}: decision-fixture sin decision");
                }
            }
        }
    }

    #[DataProvider('files')]
    public function test_cada_decision_usada_esta_escrita_y_ninguna_sobra(string $file): void
    {
        $doc = self::load($file);
        $usadas = [];
        $cuerpo = $doc;
        unset($cuerpo['decisions']);
        array_walk_recursive($cuerpo, function ($v, $k) use (&$usadas) {
            if ($k === 'decision' && is_string($v)) {
                $usadas[$v] = true;
            }
        });
        $usadas = array_keys($usadas);
        sort($usadas);
        $escritas = array_keys($doc['decisions']);
        sort($escritas);
        $this->assertSame($escritas, $usadas, "$file: decisiones usadas frente a escritas");
        foreach ($doc['decisions'] as $id => $texto) {
            $this->assertMatchesRegularExpression('/^DF-[A-Z]+\d+$/', $id);
            $this->assertNotSame('', trim($texto));
        }
    }

    #[DataProvider('files')]
    public function test_sin_invisibles_literales_y_con_salto_de_linea_final(string $file): void
    {
        $raw = self::raw($file);
        $this->assertStringEndsWith("}\n", $raw);
        $sinSaltos = str_replace("\n", '', $raw);
        $this->assertSame(0, preg_match('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}\p{Co}\p{Mn}\p{Me}]/u', $sinSaltos), "$file: un invisible literal (van como \\uXXXX)");
        $this->assertSame(0, preg_match('/(?! )\p{Zs}/u', $sinSaltos), "$file: un espacio raro literal");
    }

    // ── catalog-snapshot.json ────────────────────────────────────────────────

    public function test_la_huella_del_catalogo_es_la_de_su_json_canonico(): void
    {
        $doc = self::load('catalog-snapshot.json');
        $json = StudioContentHasher::canonicalJson($doc['catalog']);
        $this->assertSame($doc['catalogHash'], hash('sha256', $json));
        $this->assertSame($doc['catalogCanonicalBytes'], strlen($json));
        $this->assertSame($doc['catalogVersion'], $doc['catalog']['version']);
        $this->assertMatchesRegularExpression('#^outreach-catalog/\d{4}-\d{2}-\d{2}\.\d+$#', $doc['catalogVersion']);
    }

    public function test_el_catalogo_tiene_forma_uniforme_y_listas_ordenadas(): void
    {
        $cat = self::load('catalog-snapshot.json')['catalog'];
        $claves = fn (array $l) => array_map(fn ($e) => $e['key'], $l);
        foreach (['events', 'facts'] as $lista) {
            $k = $claves($cat[$lista]);
            $ordenadas = $k;
            sort($ordenadas, SORT_STRING);
            $this->assertSame($ordenadas, $k, "$lista ordenados por bytes");
            $this->assertSame(count($k), count(array_unique($k)), "$lista sin repetidos");
            $forma = array_keys($cat[$lista][0]);
            foreach ($cat[$lista] as $e) {
                $this->assertSame($forma, array_keys($e), "{$e['key']}: forma uniforme");
            }
        }
        $operadores = array_unique(array_merge(...array_values($cat['operators'])));
        foreach ($cat['facts'] as $f) {
            $this->assertContains($f['type'], $cat['types'], $f['key']);
            $this->assertArrayHasKey($f['type'], $cat['operators'], $f['key']);
            foreach ($f['purposes'] as $p) {
                $this->assertContains($p, $cat['purposes'], $f['key']);
            }
            if ($f['type'] === 'enum') {
                $this->assertNotNull($f['domain'], $f['key']);
                if (array_is_list($f['domain'])) {
                    $d = $f['domain'];
                    sort($d, SORT_STRING);
                    $this->assertSame($d, $f['domain'], "{$f['key']}: dominio ordenado");
                } else {
                    $this->assertContains($f['domain']['dynamic'], $cat['dynamicDomains'], $f['key']);
                }
            }
            if (in_array($f['type'], ['int', 'decimal', 'duration', 'date_relative'], true)) {
                $this->assertIsInt($f['min'], $f['key']);
                $this->assertIsInt($f['max'], $f['key']);
                $this->assertLessThanOrEqual($f['max'], $f['min'], $f['key']);
            }
            $this->assertSame($f['buckets'] === null ? 'value' : 'bucket', $f['trace'], $f['key']);
        }
        foreach ($cat['events'] as $e) {
            $this->assertContains($e['class'], $cat['eventClasses'], $e['key']);
            if ($e['triggerable']) {
                $this->assertTrue($e['available'], "{$e['key']}: un disparador tiene que estar disponible");
                $this->assertNotEmpty($e['purposes'], "{$e['key']}: un disparador declara su finalidad");
            }
        }
        $porClave = array_column($cat['events'], null, 'key');
        $this->assertFalse($porClave['human_requested']['triggerable'], 'human_requested solo detiene (D9-3)');
        $this->assertCount(27, array_filter($cat['events'], fn ($e) => $e['class'] === 'crm_fact'), 'los 27 del vocabulario');
        $this->assertCount(18, $cat['outcomes'], 'D9-10: 18 resultados');
        foreach (self::load('catalog-snapshot.json')['compatibility'] as $c) {
            $this->assertSame($c['expected']['result'] === 'catalog_incompatible' ? 'N3' : null, $c['expected']['level'], "{$c['name']}: catalog_incompatible es de N3 (L9-21)");
        }
        $this->assertCount(6, $cat['followUpTransitions'], 'D9-10: 6 transiciones');
        $this->assertSame(['is_known', 'is_unknown'], $cat['operators']['any']);
        $this->assertNotContains('regex', $operadores);
        $this->assertNotContains('matches', $operadores);
    }

    // ── conditions-cases.json ────────────────────────────────────────────────

    public function test_condiciones_y_catalogo_hablan_de_la_misma_version(): void
    {
        $doc = self::load('conditions-cases.json');
        $cat = self::load('catalog-snapshot.json');
        $this->assertSame($cat['catalogHash'], $doc['catalogHash']);
        $this->assertSame($cat['catalogVersion'], $doc['catalogVersion']);
        $this->assertSame($cat['catalog']['grammar'], $doc['grammar']);
        $dinamicos = array_keys($doc['dynamicDomains']);
        sort($dinamicos);
        $this->assertSame($cat['catalog']['dynamicDomains'], $dinamicos);
    }

    public function test_cada_forma_canonica_es_su_json_canonico_y_su_huella(): void
    {
        $doc = self::load('conditions-cases.json');
        foreach ($doc['canonicalize'] as $c) {
            $json = StudioContentHasher::canonicalJson($c['expected']['canonical']);
            $this->assertSame($c['expected']['canonicalJson'], $json, $c['name']);
            $this->assertSame($c['expected']['hash'], hash('sha256', $json), $c['name']);
            $this->assertSame($c['expected']['canonicalBytes'], strlen($json), $c['name']);
            $this->assertLessThanOrEqual(8192, strlen($json), $c['name']);
            $this->assertSame([], $c['expected']['errors'], $c['name']);
            $this->assertTrue(array_key_exists('input', $c) !== array_key_exists('inputText', $c), "{$c['name']}: input o inputText, uno solo");
        }
    }

    public function test_los_422_son_conjuntos_ordenados_de_codigos_cerrados(): void
    {
        $doc = self::load('conditions-cases.json');
        foreach ($doc['reject'] as $c) {
            $e = $c['expected'];
            $this->assertSame(422, $e['http'], $c['name']);
            $this->assertNull($e['canonical'], $c['name']);
            $this->assertNotEmpty($e['errors'], $c['name']);
            $pares = array_map(fn ($x) => $x['key']."\0".$x['code'], $e['errors']);
            $ordenados = $pares;
            sort($ordenados, SORT_STRING);
            $this->assertSame($ordenados, $pares, "{$c['name']}: errores ordenados por key y code");
            $this->assertSame(count($pares), count(array_unique($pares)), "{$c['name']}: errores sin repetir");
            foreach ($e['errors'] as $x) {
                $this->assertContains($x['code'], $doc['errorCodes'], $c['name']);
                $this->assertMatchesRegularExpression('/^conditions(\.[A-Za-z0-9_]+)*$/', $x['key'], $c['name']);
            }
            $claves = array_values(array_unique(array_map(fn ($x) => $x['key'], $e['errors'])));
            $this->assertSame($claves, $e['errorKeys'], $c['name']);
        }
    }

    public function test_cada_traza_tiene_su_traceHash_y_la_raiz_da_el_valor(): void
    {
        $doc = self::load('conditions-cases.json');
        foreach ($doc['evaluate'] as $c) {
            $e = $c['expected'];
            $this->assertContains($e['value'], self::RESULTS, $c['name']);
            $this->assertSame($e['traceHash'], hash('sha256', StudioContentHasher::canonicalJson($e['trace'])), $c['name']);
            $this->assertSame('conditions', $e['trace'][0]['path'], $c['name']);
            $this->assertSame($e['value'], $e['trace'][0]['result'], "{$c['name']}: la raíz es el valor");
            $hojas = [];
            foreach ($e['trace'] as $t) {
                $this->assertContains($t['result'], self::RESULTS, $c['name']);
                foreach (['path', 'kind', 'observed', 'op', 'expected', 'result'] as $k) {
                    $this->assertArrayHasKey($k, $t, "{$c['name']}: traza sin $k");
                }
                if (! in_array($t['kind'], ['all', 'any', 'not'], true)) {
                    $hojas[] = $t['result'];
                }
            }
            $this->assertSame($e['leafResults'], $hojas, $c['name']);
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $c['now'], $c['name']);
        }
    }

    public function test_las_equivalencias_son_coherentes_con_sus_huellas(): void
    {
        $doc = self::load('conditions-cases.json');
        foreach ($doc['equivalence'] as $c) {
            $e = $c['expected'];
            $this->assertSame($e['sameHash'], $e['hashA'] === $e['hashB'], $c['name']);
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $e['hashA']);
        }
        // Una equivalencia «la misma huella» con un caso de canonicalize de la misma entrada comparte la huella
        $porEntrada = [];
        foreach ($doc['canonicalize'] as $c) {
            if (array_key_exists('input', $c)) {
                $porEntrada[json_encode($c['input'])] = $c['expected']['hash'];
            }
        }
        $cruzadas = 0;
        foreach ($doc['equivalence'] as $c) {
            foreach (['a' => 'hashA', 'b' => 'hashB'] as $lado => $h) {
                if (array_key_exists($lado, $c) && isset($porEntrada[json_encode($c[$lado])])) {
                    $this->assertSame($porEntrada[json_encode($c[$lado])], $c['expected'][$h], $c['name']);
                    $cruzadas++;
                }
            }
        }
        $this->assertGreaterThan(0, $cruzadas);
    }

    public function test_las_condiciones_solo_nombran_claves_del_catalogo(): void
    {
        $doc = self::load('conditions-cases.json');
        $cat = self::load('catalog-snapshot.json')['catalog'];
        $hechos = array_column($cat['facts'], null, 'key');
        $eventos = array_column(array_filter($cat['events'], fn ($e) => $e['conditionUsable']), null, 'key');
        $arboles = array_merge(
            array_map(fn ($c) => $c['expected']['canonical'], $doc['canonicalize']),
            array_map(fn ($c) => $c['expected']['canonical'], $doc['evaluate']),
        );
        $vistos = 0;
        $recorre = function ($n) use (&$recorre, $hechos, $eventos, &$vistos) {
            foreach (['all', 'any'] as $g) {
                if (isset($n[$g])) {
                    $this->assertGreaterThan(1, count($n[$g]), 'un grupo canónico tiene ≥ 2 hijos');
                    array_map($recorre, $n[$g]);

                    return;
                }
            }
            if (isset($n['not'])) {
                $recorre($n['not']);

                return;
            }
            $vistos++;
            if (isset($n['fact'])) {
                $this->assertArrayHasKey($n['fact'], $hechos);
            } elseif (isset($n['event'])) {
                $this->assertArrayHasKey($n['event'], $eventos);
            } elseif (isset($n['count'])) {
                $of = $n['count']['of'];
                $this->assertTrue($of === 'contact' || isset($eventos[substr($of, 6)]), $of);
            } else {
                $this->assertArrayHasKey('transition', $n);
            }
        };
        array_map($recorre, $arboles);
        $this->assertGreaterThan(100, $vistos);
    }

    // ── README ───────────────────────────────────────────────────────────────

    public function test_el_readme_nombra_cada_fichero_con_su_recuento(): void
    {
        $readme = (string) file_get_contents(self::dir().'/README.md');
        $total = 0;
        foreach (self::FILES as $f) {
            $this->assertStringContainsString("`{$f}`", $readme, "el README no nombra $f");
            $n = self::load($f)['counts']['total'];
            $total += $n;
            $this->assertMatchesRegularExpression('/`'.preg_quote($f, '/').'`[^\n]*\|\s*'.$n.'\s*\|/u', $readme, "$f: el recuento del índice ($n)");
        }
        $this->assertStringContainsString("**Total: {$total} casos.**", $readme);
    }

    // ── huellas compartidas ──────────────────────────────────────────────────

    public function test_condiciones_y_estrategias_citan_la_huella_del_catalogo(): void
    {
        $cat = self::load('catalog-snapshot.json');
        foreach (['conditions-cases.json', 'strategy-content-cases.json'] as $f) {
            $this->assertSame($cat['catalogHash'], self::load($f)['catalogHash'], $f);
            $this->assertSame($cat['catalogVersion'], self::load($f)['catalogVersion'], $f);
        }
    }

    // ── calendar-cases.json ──────────────────────────────────────────────────

    public function test_los_festivos_son_los_del_fixture_del_h8(): void
    {
        $h8 = json_decode((string) file_get_contents(dirname(__DIR__, 3).'/tests/fixtures/voice/holidays-co-2026-2028.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach (self::load('calendar-cases.json')['holidays'] as $c) {
            $esperadas = array_map(fn ($x) => $x['date'], $h8['years'][(string) $c['year']]);
            sort($esperadas);
            $this->assertSame($esperadas, $c['expected']['dates'], $c['name']);
            $this->assertCount(19, $c['expected']['dates']);
        }
    }

    public function test_cada_hora_de_bogota_del_calendario_es_la_de_su_instante(): void
    {
        $doc = self::load('calendar-cases.json');
        $dias = [1 => 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo'];
        $zona = new \DateTimeZone('America/Bogota');
        foreach ($doc['window'] as $c) {
            $l = (new \DateTimeImmutable($c['now']))->setTimezone($zona);
            $this->assertSame($l->format('Y-m-d H:i:s'), $c['expected']['bogota'], $c['name']);
            $this->assertSame($dias[(int) $l->format('N')], $c['expected']['weekday'], $c['name']);
            $this->assertSame($c['expected']['inside'], $c['expected']['n1'] === [] && $c['expected']['n5'] === [], $c['name']);
            if ($dias[(int) $l->format('N')] === 'domingo') {
                $this->assertContains('calendar_sunday', $c['expected']['n1'], $c['name']);
            }
            // L9-15: el horario propio es N5, solo con horario propio y solo cuando la ley ya se cumple
            if ($c['expected']['n5'] !== []) {
                $this->assertSame(['calendar_outside_own_hours'], $c['expected']['n5'], $c['name']);
                $this->assertSame([], $c['expected']['n1'], "{$c['name']}: N5 solo se mira si N1 pasa");
                $this->assertNotNull($c['ownHours'], $c['name']);
            }
            $this->assertNotContains('calendar_outside_own_hours', $c['expected']['n1'], $c['name']);
        }
        foreach ($doc['nextSlot'] as $c) {
            $this->assertMatchesRegularExpression(self::ISO_Z, $c['expected']['at'], $c['name']);
            $this->assertGreaterThanOrEqual(strtotime($c['from']), strtotime($c['expected']['at']), "{$c['name']}: nunca antes");
            $l = (new \DateTimeImmutable($c['expected']['at']))->setTimezone($zona);
            $this->assertSame($l->format('Y-m-d H:i:s'), $c['expected']['bogota'], $c['name']);
            $this->assertNotSame('7', $l->format('N'), "{$c['name']}: nunca en domingo");
            $this->assertNotContains($l->format('Y-m-d'), self::load('calendar-cases.json')['holidays'][$l->format('Y') - 2026]['expected']['dates'] ?? [], "{$c['name']}: nunca en festivo");
        }
    }

    // ── contact-budget-cases.json ────────────────────────────────────────────

    public function test_el_presupuesto_ordena_sus_motivos_y_solo_aplaza(): void
    {
        $doc = self::load('contact-budget-cases.json');
        $orden = $doc['reasonOrder'];
        foreach ($doc['check'] as $c) {
            $e = $c['expected'];
            $this->assertSame($e['reasons'] === [], $e['allowed'], $c['name']);
            $this->assertSame($e['allowed'], $e['retryAt'] === null, "{$c['name']}: retryAt solo si niega");
            $pos = array_map(fn ($r) => array_search($r, $orden, true), $e['reasons']);
            $this->assertNotContains(false, $pos, $c['name']);
            $ordenadas = $pos;
            sort($ordenadas);
            $this->assertSame($ordenadas, $pos, "{$c['name']}: en reasonOrder");
            foreach ($e['releaseAt'] as $r => $t) {
                $this->assertContains($r, $e['reasons'], $c['name']);
                $this->assertMatchesRegularExpression(self::ISO_Z, $t);
                $this->assertGreaterThanOrEqual(strtotime($t), strtotime($e['retryAt']), "{$c['name']}: retryAt no antes de soltarse $r");
            }
        }
    }

    public function test_paridad_lo_que_niega_contact_policy_lo_niega_el_presupuesto(): void
    {
        $niega = 0;
        foreach (self::load('contact-budget-cases.json')['parity'] as $c) {
            $e = $c['expected'];
            foreach ($c['history'] as $h) {
                $this->assertSame('whatsapp', $h['medium'], "{$c['name']}: la paridad es solo de WhatsApp");
            }
            if ($e['contactPolicy']['denied']) {
                $niega++;
                $this->assertContains($e['contactPolicy']['reason'], ['too_soon_since_last_contact', 'weekly_contact_limit_reached', 'quiet_hours'], $c['name']);
                $this->assertFalse($e['contactBudget']['allowed'], "PARIDAD ROTA: {$c['name']}");
            }
        }
        $this->assertGreaterThanOrEqual(20, $niega, 'la paridad tiene que probar negativas de verdad');
    }

    public function test_los_motivos_del_presupuesto_son_de_n1_y_en_su_orden(): void
    {
        $n1 = self::load('policy-ladder-cases.json')['reasonOrder']['N1'];
        $pos = array_map(fn ($r) => array_search($r, $n1, true), self::load('contact-budget-cases.json')['reasonOrder']);
        $this->assertNotContains(false, $pos);
        $ordenadas = $pos;
        sort($ordenadas);
        $this->assertSame($ordenadas, $pos);
    }

    // ── policy-ladder-cases.json ─────────────────────────────────────────────

    /** @return array<string, mixed> */
    private static function merge(array $a, array $b): array
    {
        foreach ($b as $k => $v) {
            $a[$k] = is_array($v) && $v !== [] && ! array_is_list($v) && isset($a[$k]) && is_array($a[$k]) && ! array_is_list($a[$k]) ? self::merge($a[$k], $v) : $v;
        }

        return $a;
    }

    public function test_la_escalera_es_coherente_caso_a_caso(): void
    {
        $doc = self::load('policy-ladder-cases.json');
        $niveles = ['N1', 'N2', 'N3', 'N4', 'N5', 'N6', 'N7'];
        foreach (self::sections($doc) as $sec) {
            foreach ($doc[$sec] as $c) {
                $e = $c['expected'];
                $this->assertEquals(self::merge($doc['baseInput'], $c['patch']), $c['input'], "{$c['name']}: input = baseInput + patch");
                $this->assertContains($e['verdict'], ['allow', 'deny', 'defer'], $c['name']);
                $this->assertSame($e['verdict'] === 'allow', $e['allowed'], $c['name']);
                $this->assertSame($e['verdict'] === 'defer', $e['retryAt'] !== null, "{$c['name']}: retryAt solo con defer");
                $this->assertSame(array_column($e['levels'], 'level'), $niveles, $c['name']);
                $this->assertSame($e['level'] === 'N2', $e['waitsForRelease'], $c['name']);
                if ($e['effect'] !== null) {
                    $this->assertSame('N6', $e['level'], $c['name']);
                }
                if ($e['level'] === null) {
                    $this->assertSame([], $e['reasons'], $c['name']);
                    $this->assertSame(array_fill(0, 7, 'pass'), array_column($e['levels'], 'status'), $c['name']);
                    continue;
                }
                $i = array_search($e['level'], $niveles, true);
                $esperado = array_merge(array_fill(0, $i, 'pass'), [$e['verdict']], array_fill(0, 6 - $i, 'not_evaluated'));
                $this->assertSame($esperado, array_column($e['levels'], 'status'), "{$c['name']}: el primer nivel que niega decide");
                $orden = $doc['reasonOrder'][$e['level']];
                $pos = array_map(fn ($r) => str_starts_with($r, 'voice_policy:') ? array_search('voice_policy:*', $orden, true) : array_search($r, $orden, true), $e['reasons']);
                $this->assertNotContains(false, $pos, $c['name']);
                $ordenadas = $pos;
                sort($ordenadas);
                $this->assertSame($ordenadas, $pos, "{$c['name']}: motivos en el orden del contrato");
                $temporales = array_intersect($e['reasons'], $doc['temporalReasons']);
                // Única excepción (DF-L11): con el horario propio mal configurado no hay hueco posible, así que niega
                $sinHueco = $c['input']['ownHours'] === 'invalid' && $e['level'] === 'N5';
                $this->assertSame($e['verdict'] === 'defer', count($temporales) === count($e['reasons']) && ! $sinHueco, "{$c['name']}: defer ⇔ todos temporales");
                // L9-15, L9-20 y L9-21: cada motivo nuevo en su nivel y con su condición
                if (in_array('calendar_outside_own_hours', $e['reasons'], true)) {
                    $this->assertSame('N5', $e['level'], $c['name']);
                    $this->assertNotNull($c['input']['ownHours'], $c['name']);
                }
                if (in_array('collections_overdue_blocks_commercial', $e['reasons'], true)) {
                    $this->assertSame('N6', $e['level'], $c['name']);
                    $this->assertSame('commercial', $c['input']['purpose'], "{$c['name']}: el bloqueo por cartera es solo de lo comercial");
                }
                if (in_array('catalog_incompatible', $e['reasons'], true)) {
                    $this->assertSame('N3', $e['level'], $c['name']);
                }
                if ($e['retryAt'] !== null) {
                    $this->assertMatchesRegularExpression(self::ISO_Z, $e['retryAt']);
                    $this->assertGreaterThanOrEqual(strtotime($c['input']['now']), strtotime($e['retryAt']), $c['name']);
                    $this->assertNotSame('7', (new \DateTimeImmutable($e['retryAt']))->setTimezone(new \DateTimeZone('America/Bogota'))->format('N'), "{$c['name']}: retryAt nunca en domingo");
                }
            }
        }
        foreach ($doc['shadow'] as $c) {
            $this->assertSame('shadow', $c['input']['flags']['mode'], $c['name']);
            foreach ($c['expected']['warnings'] as $w) {
                $this->assertContains($w['reason'], $doc['shadowWarnings'], $c['name']);
            }
        }
    }

    // ── channel-selection-cases.json ─────────────────────────────────────────

    public function test_el_selector_elige_el_primer_permitido_del_orden(): void
    {
        foreach (self::load('channel-selection-cases.json')['choose'] as $c) {
            $e = $c['expected'];
            $order = $e['order'];
            $canales = $c['channels'];
            sort($order);
            sort($canales);
            $this->assertSame($canales, $order, "{$c['name']}: el orden efectivo es una permutación");
            $porCanal = array_column($e['perChannel'], null, 'channel');
            $this->assertSame($c['channels'], array_keys($porCanal), $c['name']);
            $permitidos = array_values(array_filter($e['order'], fn ($k) => $porCanal[$k]['verdict'] === 'allow'));
            $aplazados = array_values(array_filter($e['order'], fn ($k) => $porCanal[$k]['verdict'] === 'defer'));
            if ($e['verdict'] === 'selected') {
                $this->assertSame($permitidos[0], $e['channel'], $c['name']);
            } elseif ($e['verdict'] === 'defer') {
                $this->assertSame([], $permitidos, $c['name']);
                $this->assertSame(min(array_map(fn ($k) => strtotime($porCanal[$k]['retryAt']), $aplazados)), strtotime($e['retryAt']), $c['name']);
            } else {
                $this->assertSame([], $permitidos, $c['name']);
                $this->assertSame([], $aplazados, $c['name']);
            }
        }
    }

    // ── priority-cases.json ──────────────────────────────────────────────────

    public function test_la_prioridad_deja_un_ganador_y_aplaza_a_las_demas(): void
    {
        foreach (self::load('priority-cases.json')['arbitrate'] as $c) {
            $e = $c['expected'];
            $todas = array_column($c['candidates'], 'uuid');
            $salen = array_merge($e['winner'] === null ? [] : [$e['winner']], array_column($e['deferred'], 'uuid'));
            sort($todas);
            sort($salen);
            $this->assertSame($todas, $salen, "{$c['name']}: cada candidata gana o se aplaza");
            $this->assertSame($e['winner'], $e['ranking'][0] ?? null, $c['name']);
            foreach ($e['deferred'] as $d) {
                $this->assertContains($d['reason'], ['lower_priority', 'purpose_conflict_today'], $c['name']);
            }
        }
    }

    // ── outcome-transitions.json ─────────────────────────────────────────────

    public function test_la_tabla_de_resultados_la_matriz_y_las_resoluciones_cuadran(): void
    {
        $doc = self::load('outcome-transitions.json');
        $cat = self::load('catalog-snapshot.json')['catalog'];
        $tabla = $doc['table'];
        $this->assertSame(array_column($cat['outcomes'], 'code'), array_keys($tabla));
        foreach ($cat['outcomes'] as $o) {
            $this->assertSame($o['group'], $tabla[$o['code']]['group']);
        }
        $this->assertCount(18 * 6, $doc['matrix']);
        foreach ($doc['matrix'] as $c) {
            $t = $tabla[$c['outcome']];
            $this->assertSame(in_array($c['transition'], $t['allowed'], true), $c['expected']['allowed'], $c['name']);
            $this->assertSame($t['default'] === $c['transition'], $c['expected']['isDefault'], $c['name']);
        }
        foreach ($doc['resolve'] as $c) {
            $e = $c['expected'];
            if ($e['verdict'] === 'ignore') {
                $this->assertNull($e['transition'], $c['name']);
                $this->assertTrue($e['incident'], $c['name']);
            } elseif ($e['verdict'] === 'needs_review') {
                $this->assertSame('handoff_human', $e['transition'], $c['name']);
                $this->assertSame('classifier', $c['source'], $c['name']);
            } else {
                $this->assertSame($c['outcome'], $e['outcome'], $c['name']);
                $t = $tabla[$c['outcome']];
                $this->assertTrue($e['transition'] === null ? $t['allowed'] === [] : in_array($e['transition'], $t['allowed'], true), $c['name']);
                if ($c['source'] === 'classifier') {
                    $this->assertContains($e['transition'], $doc['classifierSafeTransitions'], "{$c['name']}: la IA solo frena o deriva");
                }
                if ($c['outcome'] === 'opted_out') {
                    $this->assertSame('end_exit', $e['transition'], "{$c['name']}: opted_out siempre end_exit");
                }
            }
        }
    }

    public function test_la_maquina_de_seguimientos_nunca_planifica_hacia_atras(): void
    {
        $estados = self::load('outcome-transitions.json')['enrollmentStatuses'];
        foreach (self::load('followup-cases.json')['apply'] as $c) {
            $e = $c['expected'];
            $this->assertContains($e['status'], $estados, $c['name']);
            if ($e['nextJob'] !== null) {
                $this->assertLessThan(count($c['steps']), $e['nextJob']['stepIndex'], $c['name']);
                $this->assertGreaterThanOrEqual(1, $e['nextJob']['attempt'], $c['name']);
                $this->assertGreaterThanOrEqual(strtotime($c['now']), strtotime($e['nextJob']['notBefore']), $c['name']);
                $this->assertLessThan(strtotime($c['enrollment']['windowEndsAt']), strtotime($e['nextJob']['notBefore']), $c['name']);
                $this->assertContains($e['status'], ['active', 'waiting'], $c['name']);
            }
        }
    }

    // ── strategy-content-cases.json ──────────────────────────────────────────

    public function test_cada_contenido_valido_tiene_su_huella_y_cada_invalido_sus_claves(): void
    {
        $doc = self::load('strategy-content-cases.json');
        foreach ($doc['valid'] as $c) {
            $json = StudioContentHasher::canonicalJson($c['expected']['canonical']);
            $this->assertSame($c['expected']['canonicalJson'], $json, $c['name']);
            $this->assertSame($c['expected']['contentHash'], hash('sha256', $json), $c['name']);
            $this->assertLessThanOrEqual(32768, strlen($json), $c['name']);
            $this->assertSame('strategy-content/1', $c['expected']['canonical']['format'], $c['name']);
        }
        foreach ($doc['invalid'] as $c) {
            $pares = array_map(fn ($x) => $x['key']."\0".$x['code'], $c['expected']['errors']);
            $ordenados = $pares;
            sort($ordenados, SORT_STRING);
            $this->assertSame($ordenados, $pares, $c['name']);
            $this->assertSame(count($pares), count(array_unique($pares)), $c['name']);
            foreach ($c['expected']['errors'] as $x) {
                $this->assertContains($x['code'], $doc['errorCodes'], $c['name']);
                $this->assertStringStartsWith('content', $x['key'], $c['name']);
            }
        }
        foreach ($doc['versions'] as $c) {
            $this->assertSame($c['expected']['sameContentHash'], $c['expected']['hashA'] === $c['expected']['hashB'], $c['name']);
        }
    }

    // ── strategy-chain-vectors.json ──────────────────────────────────────────

    private static function subkey(string $label, string $appKey = self::APP_KEY_B64): string
    {
        // La misma expresión que UltronPublishedConfig::subkey() (app/Services/Marketing/Ultron/UltronPublishedConfig.php:462-472)
        $clave = str_starts_with($appKey, 'base64:') ? base64_decode(substr($appKey, 7)) : $appKey;

        return hash_hmac('sha256', $label, $clave, true);
    }

    public function test_la_cadena_de_estrategias_firma_y_encadena_con_su_propia_subclave(): void
    {
        $doc = self::load('strategy-chain-vectors.json');
        $k = $doc['keys'];
        $this->assertSame($k['chainSubkeyHex'], bin2hex(self::subkey('ultron-studio-strategy-chain')));
        $this->assertSame($k['chainSubkeyHexFromRawAppKey'], bin2hex(self::subkey('ultron-studio-strategy-chain', $k['appKeyRaw'])));
        $this->assertSame($k['h5SubkeyHex'], bin2hex(self::subkey('ultron-studio-publication')));
        $this->assertSame($k['voiceSubkeyHex'], bin2hex(self::subkey('ultron-studio-voice-chain')));
        $this->assertCount(3, array_unique([$k['chainSubkeyHex'], $k['h5SubkeyHex'], $k['voiceSubkeyHex']]));
        foreach ($doc['subkey'] as $c) {
            $this->assertSame($c['expected']['subkeyHex'], bin2hex(self::subkey($c['label'], $c['appKey'])), $c['name']);
        }
        $anterior = str_repeat('0', 64);
        foreach ($doc['chain'] as $i => $c) {
            $msg = StudioContentHasher::canonicalJson($c['entry']);
            $this->assertSame($c['expected']['message'], $msg, $c['name']);
            $this->assertSame($c['expected']['entryHash'], hash('sha256', $msg), $c['name']);
            $this->assertSame($c['expected']['signature'], hash_hmac('sha256', $msg, self::subkey('ultron-studio-strategy-chain')), $c['name']);
            $this->assertSame($i + 1, $c['entry']['seq'], $c['name']);
            $this->assertSame($anterior, $c['entry']['prevHash'], "{$c['name']}: prevHash = huella de la anterior (global)");
            $anterior = $c['expected']['entryHash'];
        }
        foreach ($doc['verify'] as $c) {
            $firmaBuena = hash_hmac('sha256', $c['message'], self::subkey('ultron-studio-strategy-chain'));
            if ($c['expected']['result'] === 'ok') {
                $this->assertSame($firmaBuena, $c['signature'], $c['name']);
                $this->assertSame($c['message'], StudioContentHasher::canonicalJson(json_decode($c['message'], true)), $c['name']);
            }
            if ($c['expected']['result'] === 'signature_mismatch') {
                $this->assertNotSame($firmaBuena, $c['signature'], $c['name']);
            }
            $this->assertSame($c['expected']['result'] !== 'ok', $c['expected']['denies'], $c['name']);
        }
    }
}
