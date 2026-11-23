<?php

namespace Tests\Feature\Marketing;

use App\Services\Marketing\Ultron\Prompts\UltronPromptsN8nFlow as S1;
use App\Services\Marketing\Ultron\Prompts\UltronRegenN8nFlow as S2;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * A4 · «REGENERAR ANTES DE SUSTITUIR», OLA 5: EL FLUJO «S2» DE n8n, GENERADO
 * Y COMPROBADO SIN TOCAR EL n8n EN MARCHA (plan
 * `plan-arquitecto-a4-regenerar.md` §5 entero, §6 «Ola 5» y §13 L-6; encargo
 * `encargos-regenerar/encargo-be-r5.md`).
 *
 * S2 = los 25 nodos de S1 + 14 nuevos, desenrollado: entre el redactor y el
 * revisor se le pregunta al servidor (`ai/check`) si aceptaría el borrador; si
 * pide reescribir, se reescribe con su nota, y como mucho hay un tercer
 * borrador. El tope de tres redacciones está en la FORMA del grafo.
 *
 * Lo que se fija:
 *  1. `ultron:prompts-n8n --rev=S2` genera los ficheros de forma determinista,
 *     y lo que hay en `n8n/flujos/ultron-studio-s2/` es lo que genera hoy.
 *  2. S1 (la orden sin `--rev` y con `--rev=S1`) sale BYTE A BYTE igual que
 *     antes de esta ola: los sha256 de HEAD `ab64134a`, escritos aquí a mano.
 *  3. En PHP: los 25 de S1 idénticos salvo el código de Preparar Commit y la
 *     revisión del selector; los 14 nuevos con su forma (la comprobación, con
 *     la credencial de siempre por referencia, 3 s y sin reintentos).
 *  4. Ningún secreto en lo generado.
 *  5. El comprobador offline (`verificar-flujo.cjs`) pasa en los dos modos
 *     sobre lo generado, FALLA en modo S2 sobre S1 y FALLA con cada mutación
 *     (un redactor de más, un IF que salta la comprobación, el `values[1]` de
 *     un nodo de S1 tocado, una clave de más en el cuerpo de la comprobación,
 *     Preparar Commit sin el `try` del bloque, un bucle que cierra el grafo, y
 *     cuatro más). Los nodos Code nuevos se ejecutan allí, en `vm` de Node, con
 *     los fixtures (necesita Node en el PATH: sin él la prueba falla).
 *  6. Un S1 que no es el generado, o una revisión desconocida, no genera nada.
 */
class UltronPromptsN8nS2CommandTest extends TestCase
{
    private const RESPALDO = 'n8n/respaldos/20261111-ultron-29de5119/ultron-commercial-advisor.json';

    private const EN_EL_REPO = 'n8n/flujos/ultron-studio-s2';

    private const S1_EN_EL_REPO = 'n8n/flujos/ultron-studio-s1';

    private const COMPROBADOR = 'docs/ultron-studio/ops/motores/verificar-flujo.cjs';

    private const FICHEROS = [
        'LEEME.md', 'decidir-intento-3.js',
        'fixtures/check-404.json', 'fixtures/check-422.json', 'fixtures/check-error-de-red.json', 'fixtures/check-legacy.json',
        'fixtures/check-mal-formada.json', 'fixtures/check-pasa.json', 'fixtures/check-reescribe.json',
        'fixtures/decide-legacy.json', 'fixtures/decide-studio.json',
        'flujo-s2.json', 'huellas.json', 'leer-comprobacion-1.js', 'leer-comprobacion-2.js', 'nota-del-servidor.js', 'preparar-commit.js',
    ];

    /**
     * S1 tal como estaba ANTES de esta ola (HEAD `ab64134a`, árbol limpio):
     * los nueve ficheros, por su sha256. Si una ola posterior cambia S1 a
     * propósito (por ejemplo, el compilador mueve los fixtures), esto cambia
     * con ella; esta ola no puede moverlo. Compilador 5 (Enmienda A4-5, PT-17):
     * regenerado con `php artisan ultron:prompts-n8n`, cambian `LEEME.md` (la
     * línea del compilador), los tres fixtures (lo compilado y `compiler`),
     * `flujo-s1.json` (solo `_generado.compiler`) y `huellas.json` (`compiler`);
     * el selector, las operaciones y «Preparar Commit», no.
     */
    private const S1_ANTES = [
        'LEEME.md' => '609f7e6a73af833c1fa5f4d1dffa0fac33565b526e03220edb7afa262fc8acfa',
        'elegir-prompts.js' => 'fec89513389e0272a9ab664eccea9ca7b514620ab3fc35a9183635dec9997d10',
        'fixtures/decide-legacy.json' => 'c7495e624035ad42fe2ec46764f7743063633b583ac4b984298ecf1a1294bb94',
        'fixtures/decide-studio-frases.json' => '2af9ac2b529c05f31d7e9a4b9c74eb61f01f8b064283833caac6495c49990266',
        'fixtures/decide-studio.json' => '8136437d94e6b27f5d3f65d8c92c454418e987bffb6c9e800cdc1739389ee3c5',
        'flujo-s1.json' => '73a0c1bfd6c9da045f305dbbd2900b3b5d3693a7fe1aa6bcfec46ce1a9e0340f',
        'huellas.json' => 'fb3f66e299e522d80a82e1d977e654c5efa1088e5ccaeee049bbcb46e1f901ca',
        'operaciones-mcp.json' => '1e1aca5a7523d17e2cd07192aa70415348aa5996cf9f952899ee4fd2bb491cdc',
        'preparar-commit.js' => '876a3f6a8c50447526a321c7d7ff391c447e09e3a22dc442f7c008e520ac8b45',
    ];

    /** Los 14 nodos nuevos de §5.2, con su tipo y versión. */
    private const NUEVOS = [
        '¿Comprobar 1?' => ['n8n-nodes-base.if', 2.3],
        'Comprobar Borrador 1' => ['n8n-nodes-base.httpRequest', 4.4],
        'Leer Comprobación 1' => ['n8n-nodes-base.code', 2],
        '¿Reescribir 1?' => ['n8n-nodes-base.if', 2.3],
        'Nota del Servidor 1' => ['n8n-nodes-base.code', 2],
        '¿Comprobar 2?' => ['n8n-nodes-base.if', 2.3],
        'Comprobar Borrador 2' => ['n8n-nodes-base.httpRequest', 4.4],
        'Leer Comprobación 2' => ['n8n-nodes-base.code', 2],
        '¿Reescribir 2?' => ['n8n-nodes-base.if', 2.3],
        'Nota del Servidor 2' => ['n8n-nodes-base.code', 2],
        'Decidir Intento 3' => ['n8n-nodes-base.code', 2],
        '¿Intento 3?' => ['n8n-nodes-base.if', 2.3],
        'Composer Reintento 2' => ['@n8n/n8n-nodes-langchain.openAi', 2.3],
        'Critic Comercial 3' => ['@n8n/n8n-nodes-langchain.openAi', 2.3],
    ];

    /** @var list<string> */
    private array $temporales = [];

    protected function tearDown(): void
    {
        foreach ($this->temporales as $dir) {
            $this->borra($dir);
        }
        parent::tearDown();
    }

    // ── 1. Generación ─────────────────────────────────────────────────────────

    public function test_genera_los_ficheros_de_s2_de_forma_determinista(): void
    {
        $uno = $this->genera('S2');
        $otro = $this->genera('S2');

        $this->assertSame(self::FICHEROS, $this->lista($uno));
        foreach (self::FICHEROS as $f) {
            $this->assertSame(hash_file('sha256', "{$uno}/{$f}"), hash_file('sha256', "{$otro}/{$f}"), "{$f} es determinista");
        }
    }

    public function test_lo_que_hay_en_el_repositorio_es_lo_que_genera_hoy(): void
    {
        $repo = dirname(base_path()).'/'.self::EN_EL_REPO;
        $nuevo = $this->genera('S2');

        $this->assertDirectoryExists($repo, 'el flujo S2 generado está en el repositorio');
        $this->assertSame(self::FICHEROS, $this->lista($repo), 'ni un fichero de más ni de menos');
        foreach (self::FICHEROS as $f) {
            $this->assertSame(hash_file('sha256', "{$nuevo}/{$f}"), hash_file('sha256', "{$repo}/{$f}"), "{$f}: el del repositorio es el generado (regenerar con `php artisan ultron:prompts-n8n --rev=S2`)");
        }
    }

    // ── 2. S1, byte a byte ────────────────────────────────────────────────────

    public function test_s1_sale_byte_a_byte_igual_que_antes(): void
    {
        $repo = dirname(base_path()).'/'.self::S1_EN_EL_REPO;
        $porDefecto = $this->temporal();
        $this->artisan('ultron:prompts-n8n', ['--out' => $porDefecto])->assertExitCode(0);
        $explicito = $this->genera('S1');

        foreach ([$porDefecto, $explicito, $repo] as $dir) {
            $this->assertSame(array_keys(self::S1_ANTES), $this->lista($dir));
            foreach (self::S1_ANTES as $f => $sha) {
                $this->assertSame($sha, hash_file('sha256', "{$dir}/{$f}"), "S1 {$f}: el mismo byte a byte que antes de la ola 5 ({$dir})");
            }
        }

        // El selector con la revisión como parámetro: por defecto, el de siempre.
        $this->assertSame(S1::selectorCode(), S1::selectorCode('S1'));
        $this->assertSame(file_get_contents("{$repo}/elegir-prompts.js"), S1::selectorCode()."\n");
        $s2 = S1::selectorCode('S2');
        $this->assertSame(1, substr_count(S1::selectorCode(), 'const CONTRACT = "ultron-prompts/1", FLOW_REV = "S1";'));
        $this->assertSame(S1::selectorCode(), str_replace('FLOW_REV = "S2";', 'FLOW_REV = "S1";', $s2), 'S2 solo cambia la constante de la revisión');
        $this->assertNotSame(S1::selectorCode(), $s2);
    }

    public function test_una_revision_del_selector_que_no_es_un_codigo_corto_no_se_genera(): void
    {
        foreach (['', 's 2', 'S2"; alert(1); "', str_repeat('S', 33)] as $malo) {
            $this->assertTrue($this->lanza(fn () => S1::selectorCode($malo)), "«{$malo}» no es una revisión válida");
        }
    }

    // ── 3. La forma de S2, en PHP ─────────────────────────────────────────────

    public function test_s2_son_los_25_de_s1_mas_los_14_con_su_forma(): void
    {
        $dir = $this->genera('S2');
        $s2 = $this->leeJson("{$dir}/flujo-s2.json");
        $s1 = $this->leeJson(dirname(base_path()).'/'.self::S1_EN_EL_REPO.'/flujo-s1.json');
        $nodos2 = array_column($s2['nodes'], null, 'name');

        $this->assertCount(39, $s2['nodes']);
        $this->assertCount(39, $nodos2, 'sin nombres repetidos');
        $this->assertSame([...array_column($s1['nodes'], 'name'), ...array_keys(self::NUEVOS)], array_column($s2['nodes'], 'name'), 'los de S1 en su orden y detrás los 14, en el de §5.2');
        foreach (self::NUEVOS as $nombre => [$tipo, $version]) {
            $this->assertSame([$tipo, $version], [$nodos2[$nombre]['type'], $nodos2[$nombre]['typeVersion']], $nombre);
        }
        $this->assertSame(12, count(array_filter($s2['nodes'], fn (array $n) => isset($n['credentials']))), '8 de S1 + 2 comprobaciones + 2 de OpenAI');

        foreach ($s1['nodes'] as $original) {
            $nuevo = $nodos2[$original['name']];
            if ($original['name'] === 'Preparar Commit') {
                $this->assertStringContainsString('regeneracion ? { regeneration: regeneracion } : {}', $nuevo['parameters']['jsCode']);
                $nuevo['parameters']['jsCode'] = $original['parameters']['jsCode'];
            } elseif ($original['name'] === 'Elegir Prompts') {
                $this->assertSame(S1::selectorCode('S2'), $nuevo['parameters']['jsCode']);
                $nuevo['parameters']['jsCode'] = $original['parameters']['jsCode'];
            }
            $this->assertSame($original, $nuevo, "«{$original['name']}» igual que en S1 (posición, credenciales, values[1] y opciones incluidos)");
        }

        // La comprobación: la URL y la credencial de `decide`, por referencia; 3 s, sin reintentos, sin cortar el flujo.
        $decide = array_column($s1['nodes'], null, 'name')['Pedir Contexto al CRM'];
        foreach ([1, 2] as $k) {
            $c = $nodos2["Comprobar Borrador {$k}"];
            $this->assertSame('https://api.ironbodyneiva.cloud/api/internal/marketing/ai/check', $c['parameters']['url']);
            $this->assertSame($decide['credentials'], $c['credentials'], 'la credencial de siempre, por referencia (id y nombre), sin secreto');
            $this->assertSame(['POST', 'genericCredentialType', 'httpHeaderAuth', true, 'json'], [$c['parameters']['method'], $c['parameters']['authentication'], $c['parameters']['genericAuthType'], $c['parameters']['sendBody'], $c['parameters']['specifyBody']]);
            $this->assertSame(['response' => ['response' => ['fullResponse' => true, 'neverError' => true]], 'timeout' => 3000], $c['parameters']['options']);
            $this->assertSame('continueRegularOutput', $c['onError']);
            $this->assertArrayNotHasKey('retryOnFail', $c, 'sin reintentos');
            $this->assertArrayNotHasKey('maxTries', $c);
            $this->assertSame(S2::checkBody($k), $c['parameters']['jsonBody']);
        }

        // Las dos copias de OpenAI, iguales a sus originales salvo nombre, id, posición y `rejected_draft`.
        $copias = ['Composer Reintento 2' => 'Composer Reintento', 'Critic Comercial 3' => 'Critic Comercial 2'];
        foreach ($copias as $copia => $origen) {
            $a = $nodos2[$copia];
            $b = $nodos2[$origen];
            $this->assertNotSame($a['id'], $b['id']);
            foreach (['name', 'id', 'position'] as $k) {
                unset($a[$k], $b[$k]);
            }
            if ($copia === 'Composer Reintento 2') {
                $this->assertSame(1, substr_count($a['parameters']['responses']['values'][1]['content'], 'rejected_draft: $("Composer Reintento").first().json.output[0].content[0].text.reply_draft'));
                $a['parameters']['responses']['values'][1]['content'] = str_replace('$("Composer Reintento").first()', '$("Composer").first()', $a['parameters']['responses']['values'][1]['content']);
            }
            $this->assertSame($b, $a, "{$copia} es la copia exacta de {$origen}");
        }
        $this->assertSame(S1::systemExpression('composer_retry'), $nodos2['Composer Reintento 2']['parameters']['responses']['values'][0]['content'], 'ningún rol nuevo: el paquete y su huella no cambian');
        $this->assertSame(S1::systemExpression('critic_retry'), $nodos2['Critic Comercial 3']['parameters']['responses']['values'][0]['content']);

        // Los objetos vacíos siguen siendo objetos (n8n distingue {} de []).
        $texto = (string) file_get_contents("{$dir}/flujo-s2.json");
        $this->assertSame(7, substr_count($texto, '"builtInTools": {}'), 'los siete nodos de OpenAI');
        $this->assertSame('S2', $s2['_generado']['flowRev']);
        $this->assertSame([null, false, null], [$s2['id'], $s2['active'], $s2['versionId']], 'sin el id del flujo vivo: importar el fichero tal cual no pisa ninguno');
    }

    /**
     * Los fixtures de `ai/check` no son una forma inventada: «pasa» y
     * «reescribe» son, byte a byte, lo que devolvió el endpoint real de la ola
     * 4 (volcado por la prueba del endpoint, evidencia 111), y los demás
     * fixtures del servidor tienen sus mismas claves en su orden.
     */
    public function test_los_fixtures_de_la_comprobacion_son_los_del_endpoint_real(): void
    {
        $dir = $this->genera('S2');
        $real = $this->leeJson(base_path('docs/ultron-studio/plans/informes-motores/a4-evidencia/111-regenerar-ola4-contrato-tres-casos.json'));

        $this->assertSame($real['pasa']['respuesta'], $this->leeJson("{$dir}/fixtures/check-pasa.json")['body']);
        $this->assertSame($real['reescribe']['respuesta'], $this->leeJson("{$dir}/fixtures/check-reescribe.json")['body']);
        $this->assertSame(array_keys($real['pasa']['respuesta']), array_keys($this->leeJson("{$dir}/fixtures/check-legacy.json")['body']));
        foreach (['check-pasa', 'check-reescribe', 'check-legacy', 'check-404', 'check-422', 'check-mal-formada'] as $f) {
            $this->assertSame(['body', 'headers', 'statusCode', 'statusMessage'], array_keys($this->leeJson("{$dir}/fixtures/{$f}.json")), "{$f}: la salida del nodo HTTP con fullResponse");
        }
        $this->assertSame(['contract' => 'ultron-regen/1', 'max_drafts' => 3], $this->leeJson("{$dir}/fixtures/decide-studio.json")['regeneration']);
        $this->assertArrayNotHasKey('regeneration', $this->leeJson("{$dir}/fixtures/decide-legacy.json"));
    }

    public function test_sin_secretos_en_lo_generado(): void
    {
        $dir = $this->genera('S2');
        foreach (self::FICHEROS as $f) {
            $texto = (string) file_get_contents("{$dir}/{$f}");
            foreach (['Bearer ', 'Authorization', 'X-Internal', 'sk-', 'password', 'apiKey', '$env', 'process.env', 'require('] as $prohibido) {
                $this->assertStringNotContainsString($prohibido, $texto, "{$f}: «{$prohibido}»");
            }
        }
        $s2 = $this->leeJson("{$dir}/flujo-s2.json");
        foreach ($s2['nodes'] as $n) {
            foreach ((array) ($n['credentials'] ?? []) as $tipo => $ref) {
                $this->assertSame(['id', 'name'], array_keys($ref), "{$n['name']}: la credencial es una referencia ({$tipo})");
            }
            $this->assertArrayNotHasKey('headerParameters', $n['parameters'] ?? [], "{$n['name']}: sin cabeceras escritas a mano");
            $this->assertArrayNotHasKey('sendHeaders', $n['parameters'] ?? [], $n['name']);
        }
        $this->assertSame('sintetico.no-firmado', $this->leeJson("{$dir}/fixtures/decide-studio.json")['decide_token'], 'los fixtures no llevan un token de verdad');
    }

    // ── 5. El comprobador ─────────────────────────────────────────────────────

    public function test_el_comprobador_pasa_sobre_lo_generado_en_los_dos_modos(): void
    {
        $this->necesitaNode();
        $s1 = $this->genera('S1');
        $s2 = $this->genera('S2');

        $r1 = $this->comprueba(null, "{$s1}/flujo-s1.json", '--generado='.$s1);
        $this->assertSame(0, $r1->exitCode(), "el comprobador S1 falla sobre S1:\n".$r1->output().$r1->errorOutput());
        $this->assertMatchesRegularExpression('/^OK \(\d+ comprobaciones\)$/m', $r1->output());

        $r2 = $this->comprueba('S2', "{$s2}/flujo-s2.json", '--generado='.$s2, '--s1='.$s1.'/flujo-s1.json');
        $this->assertSame(0, $r2->exitCode(), "el comprobador S2 falla sobre S2:\n".$r2->output().$r2->errorOutput());
        $this->assertMatchesRegularExpression('/^OK \(\d+ comprobaciones\)$/m', $r2->output());
        $this->assertStringNotContainsString('✗', $r2->output());
        foreach (['A. ', 'B. ', 'C. ', 'D. ', 'E. ', 'F. ', 'G. ', 'H. ', 'I. ', 'J. '] as $apartado) {
            $this->assertMatchesRegularExpression('/^'.preg_quote($apartado, '/').'/m', $r2->output(), "el apartado {$apartado}se ejecuta");
        }
        // Los seis recorridos del plan (§5.2) y los del servidor caído, simulados con los Code y los IF reales.
        foreach (['Legacy, pasa', 'Legacy, el revisor falla', 'Studio, pasa', 'Studio, el servidor pide 1', 'Studio, el revisor pide 1', 'Studio, dos reescrituras del revisor (peor caso)', 'Studio, check-404.json en la comprobación 1'] as $recorrido) {
            $this->assertStringContainsString("✓ recorrido «{$recorrido}»", $r2->output());
        }

        // El repositorio, con sus valores por defecto (S1 y los fixtures del repositorio).
        $repo = $this->comprueba('S2', dirname(base_path()).'/'.self::EN_EL_REPO.'/flujo-s2.json');
        $this->assertSame(0, $repo->exitCode(), $repo->output());
    }

    public function test_el_comprobador_en_modo_s2_falla_sobre_s1(): void
    {
        $this->necesitaNode();
        $s2 = $this->genera('S2');

        $r = $this->comprueba('S2', dirname(base_path()).'/'.self::S1_EN_EL_REPO.'/flujo-s1.json', '--generado='.$s2);
        $this->assertSame(1, $r->exitCode());
        $this->assertStringContainsString('✗ son los 25 de S1 + los 14 nuevos', $r->output());
        $this->assertMatchesRegularExpression('/^FALLO: \d+ de \d+ comprobaciones$/m', $r->output());
    }

    public function test_una_revision_desconocida_del_comprobador_no_comprueba_nada(): void
    {
        $this->necesitaNode();
        $r = $this->comprueba('S3', dirname(base_path()).'/'.self::EN_EL_REPO.'/flujo-s2.json');
        $this->assertSame(2, $r->exitCode());
        $this->assertStringContainsString('--rev', $r->errorOutput());
    }

    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function mutaciones(): array
    {
        return [
            'un redactor de más' => ['redactorDeMas', '✗ ningún camino de Composer a Preparar Commit pasa por más de 3 redactores ni de 3 revisores'],
            'un IF que salta la comprobación' => ['ifQueSaltaLaComprobacion', '✗ con la oferta, la salida «sí» de ¿Comprobar 1? pasa siempre por Comprobar Borrador 1'],
            'el values[1] de un nodo de S1 tocado' => ['values1DeS1Tocado', '✗ «Critic Comercial» igual a S1'],
            'una clave de más en el cuerpo de la comprobación' => ['claveDeMasEnElCuerpo', '✗ Comprobar Borrador 1: el cuerpo lleva solo las claves del contrato'],
            'Preparar Commit sin el try del bloque' => ['commitSinTry', '✗ una lectura que lanza dentro del bloque: sin bloque y la misma salida'],
            'un bucle que cierra el grafo' => ['bucle', '✗ sin bucles fuera del ciclo de la rendición'],
            'la comprobación con reintentos' => ['comprobacionConReintentos', '✗ Comprobar Borrador 2: sin reintentos'],
            'un 404 que pide reescribir' => ['un404QueReescribe', '✗ Leer Comprobación 1 con check-404.json: no reescribir (error)'],
            'el tercer borrador sin revisor' => ['tercerBorradorSinRevisor', '✗ todo borrador lo juzga un revisor antes de Preparar Commit'],
            'Legacy que pregunta al servidor' => ['legacyQuePregunta', '✗ recorrido «Legacy, pasa»'],
        ];
    }

    #[DataProvider('mutaciones')]
    public function test_el_comprobador_mata_cada_mutacion(string $mutacion, string $esperado): void
    {
        $this->necesitaNode();
        $dir = $this->genera('S2');
        $flujo = json_decode((string) file_get_contents("{$dir}/flujo-s2.json"), false, 512, JSON_THROW_ON_ERROR);
        $this->{$mutacion}($flujo);
        $mutado = "{$dir}/mutado.json";
        file_put_contents($mutado, json_encode($flujo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $r = $this->comprueba('S2', $mutado, '--generado='.$dir);
        $this->assertSame(1, $r->exitCode(), "la mutación «{$mutacion}» sobrevive:\n".$r->output());
        $this->assertStringContainsString($esperado, $r->output());
        $this->assertMatchesRegularExpression('/^FALLO: \d+ de \d+ comprobaciones$/m', $r->output());
    }

    // ── 6. Lo que no genera nada ──────────────────────────────────────────────

    public function test_un_s1_que_no_es_el_generado_no_genera_s2(): void
    {
        $s1 = S1::build(S1::decode((string) file_get_contents(dirname(base_path()).'/'.self::RESPALDO)))['flow'];
        $this->assertSame(39, count(S2::build($s1)['flow']['nodes']), 'el S1 generado, sí');

        $casos = [
            'sin el selector' => fn (array $f) => ['nodes' => array_values(array_filter($f['nodes'], fn ($n) => $n['name'] !== 'Elegir Prompts'))] + $f,
            'el selector ya es S2' => fn (array $f) => self::conNodo($f, 'Elegir Prompts', fn ($n) => self::conCodigo($n, S1::selectorCode('S2'))),
            'Preparar Commit sin el bloque prompts' => fn (array $f) => self::conNodo($f, 'Preparar Commit', fn ($n) => self::conCodigo($n, str_replace('promptsAplicados ? { prompts: promptsAplicados } : {}', '{}', $n['parameters']['jsCode']))),
            'Preparar Commit sin el ancla del borrador' => fn (array $f) => self::conNodo($f, 'Preparar Commit', fn ($n) => self::conCodigo($n, str_replace('} catch (e) { intento = 1; }', '} catch (e) { intento = 1 }', $n['parameters']['jsCode']))),
            'ya tiene un nodo de S2' => fn (array $f) => ['nodes' => [...$f['nodes'], ['name' => 'Decidir Intento 3', 'type' => 'n8n-nodes-base.code']]] + $f,
            'el Composer no va al revisor' => function (array $f) {
                $f['connections']['Composer']['main'][0] = [['node' => 'Preparar Commit', 'type' => 'main', 'index' => 0]];

                return $f;
            },
            'la comprobación de decide sin credencial' => fn (array $f) => self::conNodo($f, 'Pedir Contexto al CRM', function ($n) {
                unset($n['credentials']);

                return $n;
            }),
            'la URL de decide no es la de siempre' => fn (array $f) => self::conNodo($f, 'Pedir Contexto al CRM', function ($n) {
                $n['parameters']['url'] = 'https://otro.example/api/internal/marketing/ai/decide';

                return $n;
            }),
        ];
        foreach ($casos as $caso => $muta) {
            $this->assertTrue($this->lanza(fn () => S2::build($muta($s1))), "«{$caso}»: S2 no se puede generar desde ahí");
        }
    }

    public function test_un_respaldo_que_no_es_29de5119_no_genera_s2(): void
    {
        $respaldo = $this->leeJson(dirname(base_path()).'/'.self::RESPALDO);
        foreach ($respaldo['nodes'] as $i => $nodo) {
            if ($nodo['name'] === 'Critic Comercial') {
                $respaldo['nodes'][$i]['parameters']['responses']['values'][0]['content'] .= ' ';
            }
        }
        $falso = $this->temporal().'/falso.json';
        file_put_contents($falso, json_encode($respaldo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $salida = $this->temporal().'/salida';

        $this->artisan('ultron:prompts-n8n', ['--rev' => 'S2', '--from' => $falso, '--out' => $salida])
            ->expectsOutputToContain('No se puede generar S2')
            ->assertExitCode(1);
        $this->assertDirectoryDoesNotExist($salida);
    }

    public function test_una_revision_desconocida_no_genera_nada(): void
    {
        $salida = $this->temporal().'/salida';
        $this->artisan('ultron:prompts-n8n', ['--rev' => 'S3', '--out' => $salida])
            ->expectsOutputToContain('--rev')
            ->assertExitCode(1);
        $this->assertDirectoryDoesNotExist($salida);
    }

    // ── Mutaciones (sobre el JSON decodificado como objetos) ──────────────────

    private function redactorDeMas(\stdClass $f): void
    {
        $copia = clone $this->nodo($f, 'Composer Reintento 2');
        $copia->name = 'Composer Reintento 3';
        $copia->id = '5e1ec7a0-0002-4c0d-9a5e-0000000001ff';
        $f->nodes[] = $copia;
        $si = $f->connections->{'¿Intento 3?'}->main[0];
        $f->connections->{'¿Intento 3?'}->main[0] = [(object) ['node' => 'Composer Reintento 3', 'type' => 'main', 'index' => 0]];
        $f->connections->{'Composer Reintento 3'} = (object) ['main' => [$si]];
    }

    private function ifQueSaltaLaComprobacion(\stdClass $f): void
    {
        $f->connections->{'¿Comprobar 1?'}->main[0] = [(object) ['node' => 'Critic Comercial', 'type' => 'main', 'index' => 0]];
    }

    private function values1DeS1Tocado(\stdClass $f): void
    {
        $n = $this->nodo($f, 'Critic Comercial');
        $n->parameters->responses->values[1]->content = str_replace('incoming_text:', 'incoming_text_x:', $n->parameters->responses->values[1]->content);
    }

    private function claveDeMasEnElCuerpo(\stdClass $f): void
    {
        $n = $this->nodo($f, 'Comprobar Borrador 1');
        $n->parameters->jsonBody = str_replace('attempt: 1,', 'attempt: 1, phone: $("Normalizar Evento").first().json.text,', $n->parameters->jsonBody);
    }

    private function commitSinTry(\stdClass $f): void
    {
        $n = $this->nodo($f, 'Preparar Commit');
        $codigo = $n->parameters->jsCode;
        $this->assertSame(1, substr_count($codigo, "let regeneracion = null;\ntry {"));
        $this->assertSame(1, substr_count($codigo, '} catch (e) { regeneracion = null; }'));
        $n->parameters->jsCode = str_replace(["let regeneracion = null;\ntry {", '} catch (e) { regeneracion = null; }'], ["let regeneracion = null;\n{", '}'], $codigo);
    }

    private function bucle(\stdClass $f): void
    {
        $f->connections->{'Critic Comercial 3'}->main[0][] = (object) ['node' => 'Composer Reintento 2', 'type' => 'main', 'index' => 0];
    }

    private function comprobacionConReintentos(\stdClass $f): void
    {
        $n = $this->nodo($f, 'Comprobar Borrador 2');
        $n->retryOnFail = true;
        $n->maxTries = 3;
    }

    /** El «fallback silencioso» que prohíbe el contrato: lo que no es un 200 bien formado pide reescribir igual. */
    private function un404QueReescribe(\stdClass $f): void
    {
        $n = $this->nodo($f, 'Leer Comprobación 1');
        $linea = 'r.statusCode !== 200) return noReescribir();';
        $this->assertSame(1, substr_count($n->parameters->jsCode, $linea));
        $n->parameters->jsCode = str_replace($linea, 'r.statusCode !== 200) return { rewrite: true, may_rewrite: true, error: false, codes: [], issues: [], notes: "Reescribe." };', $n->parameters->jsCode);
    }

    private function tercerBorradorSinRevisor(\stdClass $f): void
    {
        $f->connections->{'Composer Reintento 2'}->main[0] = [(object) ['node' => 'Preparar Commit', 'type' => 'main', 'index' => 0]];
    }

    private function legacyQuePregunta(\stdClass $f): void
    {
        $n = $this->nodo($f, '¿Comprobar 1?');
        $n->parameters->conditions->conditions[0]->leftValue = '={{ true }}';
    }

    // ── Andamio ───────────────────────────────────────────────────────────────

    /**
     * ¿Lanza el generador? Sin `fail()` dentro del `try`: la excepción de
     * PHPUnit también es una `RuntimeException` y se tragaría a sí misma.
     */
    private function lanza(\Closure $f): bool
    {
        try {
            $f();
        } catch (RuntimeException) {
            return true;
        }

        return false;
    }

    private function nodo(\stdClass $f, string $nombre): \stdClass
    {
        foreach ($f->nodes as $n) {
            if ($n->name === $nombre) {
                return $n;
            }
        }
        $this->fail("no está el nodo «{$nombre}»");
    }

    /**
     * @param  array<string,mixed>  $f
     * @return array<string,mixed>
     */
    private static function conNodo(array $f, string $nombre, \Closure $cambia): array
    {
        foreach ($f['nodes'] as $i => $n) {
            if ($n['name'] === $nombre) {
                $f['nodes'][$i] = $cambia($n);
            }
        }

        return $f;
    }

    /**
     * @param  array<string,mixed>  $n
     * @return array<string,mixed>
     */
    private static function conCodigo(array $n, string $codigo): array
    {
        $n['parameters']['jsCode'] = $codigo;

        return $n;
    }

    private function necesitaNode(): void
    {
        $this->assertTrue(Process::run(['node', '--version'])->successful(), 'La ola 5 necesita Node en el PATH para ejecutar el comprobador offline.');
    }

    private function comprueba(?string $rev, string $flujo, string ...$opciones): ProcessResult
    {
        $orden = ['node', base_path(self::COMPROBADOR)];
        if ($rev !== null) {
            $orden[] = '--rev='.$rev;
        }

        return Process::timeout(180)->run([...$orden, $flujo, ...$opciones]);
    }

    private function genera(string $rev): string
    {
        $dir = $this->temporal();
        $this->artisan('ultron:prompts-n8n', ['--rev' => $rev, '--out' => $dir])->assertExitCode(0);

        return $dir;
    }

    /** @return array<string,mixed> */
    private function leeJson(string $ruta): array
    {
        return json_decode((string) file_get_contents($ruta), true, 512, JSON_THROW_ON_ERROR);
    }

    private function temporal(): string
    {
        $dir = sys_get_temp_dir().'/ultron-s2-'.bin2hex(random_bytes(6));
        mkdir($dir, 0700, true);
        $this->temporales[] = $dir;

        return $dir;
    }

    /** @return list<string> los ficheros de un directorio, relativos y ordenados */
    private function lista(string $dir): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile()) {
                $out[] = substr($f->getPathname(), strlen($dir) + 1);
            }
        }
        sort($out);

        return $out;
    }

    private function borra(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }
}
