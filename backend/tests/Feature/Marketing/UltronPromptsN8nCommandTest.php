<?php

namespace Tests\Feature\Marketing;

use App\Services\Marketing\Ultron\Prompts\UltronPromptBase;
use App\Services\Marketing\Ultron\Prompts\UltronPromptsN8nFlow as S1;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * E10 · EL GENERADOR DEL CAMBIO «S1» DE n8n Y SU COMPROBADOR (Enmienda M-1 del
 * H5; plan de motores §B.7 y §B.8).
 *
 * Lo que se fija:
 *  1. `ultron:prompts-n8n` genera los ficheros, de forma determinista, y lo que
 *     hay en el repositorio (`n8n/flujos/ultron-studio-s1/`) es exactamente lo
 *     que genera hoy (nadie lo edita a mano).
 *  2. El selector lleva la base EXACTA (cada rol como literal, una vez) y la
 *     huella Legacy; sin `require`.
 *  3. Las operaciones del MCP tocan solo lo de §B.7, con `values[1]` intacto.
 *  4. El flujo generado solo difiere del respaldo en lo de §B.7 (en PHP).
 *  5. El comprobador offline PASA sobre el flujo generado y FALLA sobre el
 *     respaldo 29de5119 (necesita Node en el PATH: sin él la prueba falla).
 *  6. Un respaldo que no es 29de5119 con la base no genera nada.
 */
class UltronPromptsN8nCommandTest extends TestCase
{
    private const RESPALDO = 'n8n/respaldos/20261111-ultron-29de5119/ultron-commercial-advisor.json';

    private const EN_EL_REPO = 'n8n/flujos/ultron-studio-s1';

    private const COMPROBADOR = 'docs/ultron-studio/ops/motores/verificar-flujo.cjs';

    private const FICHEROS = [
        'LEEME.md', 'elegir-prompts.js', 'fixtures/decide-legacy.json', 'fixtures/decide-studio.json',
        'flujo-s1.json', 'huellas.json', 'operaciones-mcp.json', 'preparar-commit.js',
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

    public function test_genera_los_ficheros_de_forma_determinista(): void
    {
        $uno = $this->genera();
        $otro = $this->genera();

        $this->assertSame(self::FICHEROS, $this->lista($uno));
        foreach (self::FICHEROS as $f) {
            $this->assertSame(hash_file('sha256', "{$uno}/{$f}"), hash_file('sha256', "{$otro}/{$f}"), "{$f} es determinista");
        }
    }

    public function test_lo_que_hay_en_el_repositorio_es_lo_que_genera_hoy(): void
    {
        $repo = dirname(base_path()).'/'.self::EN_EL_REPO;
        $nuevo = $this->genera();

        $this->assertDirectoryExists($repo, 'el cambio S1 generado está en el repositorio');
        $this->assertSame(self::FICHEROS, $this->lista($repo), 'ni un fichero de más ni de menos');
        foreach (self::FICHEROS as $f) {
            $this->assertSame(hash_file('sha256', "{$nuevo}/{$f}"), hash_file('sha256', "{$repo}/{$f}"), "{$f}: el del repositorio es el generado (regenerar con `php artisan ultron:prompts-n8n`)");
        }
    }

    public function test_el_selector_lleva_la_base_exacta_y_la_huella_legacy(): void
    {
        $selector = (string) file_get_contents($this->genera().'/elegir-prompts.js');

        foreach (UltronPromptBase::roles() as $rol => $texto) {
            $literal = json_encode($texto, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $this->assertSame(1, substr_count($selector, '  '.$rol.': '.$literal), "{$rol}: la base exacta, una vez");
        }
        $this->assertStringContainsString('const LEGACY_HASH = "'.UltronPromptBase::LEGACY_BUNDLE_HASH.'"', $selector);
        $this->assertStringContainsString('const CONTRACT = "ultron-prompts/1", FLOW_REV = "S1";', $selector);
        $this->assertStringNotContainsString('require(', $selector, 'sin require: puede no estar permitido en el servidor de n8n');
        $this->assertStringNotContainsString('$env', $selector);
        $this->assertSame(S1::selectorCode()."\n", $selector);
    }

    public function test_las_operaciones_tocan_solo_lo_de_b7(): void
    {
        $dir = $this->genera();
        $ops = json_decode((string) file_get_contents("{$dir}/operaciones-mcp.json"), true, 512, JSON_THROW_ON_ERROR);
        $respaldo = $this->respaldo();
        $nodos = array_column($respaldo['nodes'], null, 'name');

        $this->assertSame(['YspRwsXnqt33NouP', '29de5119-2e5a-4bc8-a479-60f4fe37c5b3', 'S1'], [$ops['workflowId'], $ops['fromVersionId'], $ops['flowRev']]);
        $o = $ops['operations'];
        $this->assertCount(11, $o);
        $this->assertSame(['addNode', 'removeConnection', 'addConnection', 'addConnection'], array_column(array_slice($o, 0, 4), 'type'));

        $this->assertSame(['Elegir Prompts', 'n8n-nodes-base.code', 2], [$o[0]['node']['name'], $o[0]['node']['type'], $o[0]['node']['typeVersion']]);
        $this->assertSame(['jsCode'], array_keys($o[0]['node']['parameters']));
        $this->assertSame(S1::selectorCode(), $o[0]['node']['parameters']['jsCode']);
        $this->assertSame(['type' => 'removeConnection', 'source' => 'Mensaje superado?', 'sourceOutput' => 1, 'target' => 'Strategist', 'targetInput' => 0], $o[1]);
        $this->assertSame(['type' => 'addConnection', 'source' => 'Mensaje superado?', 'sourceOutput' => 1, 'target' => 'Elegir Prompts', 'targetInput' => 0], $o[2]);
        $this->assertSame(['type' => 'addConnection', 'source' => 'Elegir Prompts', 'sourceOutput' => 0, 'target' => 'Strategist', 'targetInput' => 0], $o[3]);

        $tocados = [];
        foreach (array_slice($o, 4) as $op) {
            $this->assertSame('setNodeParameter', $op['type']);
            $tocados[$op['nodeName']] = $op['path'];
            $rol = array_search($op['nodeName'], UltronPromptBase::NODES, true);
            if ($rol !== false) {
                $this->assertSame('/responses', $op['path']);
                $this->assertCount(2, $op['value']['values']);
                $this->assertSame(S1::systemExpression($rol), $op['value']['values'][0]['content']);
                $this->assertSame($nodos[$op['nodeName']]['parameters']['responses']['values'][1], $op['value']['values'][1], "{$op['nodeName']}: values[1] intacto");
            }
        }
        $this->assertSame([
            'Pedir Contexto al CRM' => '/jsonBody', 'Strategist' => '/responses', 'Composer' => '/responses', 'Critic Comercial' => '/responses',
            'Preparar Commit' => '/jsCode', 'Composer Reintento' => '/responses', 'Critic Comercial 2' => '/responses',
        ], $tocados, 'los siete nodos y las rutas de §B.7, y ninguno más (en el orden de los nodos del respaldo)');
    }

    public function test_el_flujo_generado_solo_difiere_del_respaldo_en_lo_de_b7(): void
    {
        $flujo = json_decode((string) file_get_contents($this->genera().'/flujo-s1.json'), true, 512, JSON_THROW_ON_ERROR);
        $respaldo = $this->respaldo();
        $generados = array_column($flujo['nodes'], null, 'name');

        $this->assertCount(25, $flujo['nodes']);
        foreach ($respaldo['nodes'] as $original) {
            $nuevo = $generados[$original['name']];
            $rol = array_search($original['name'], UltronPromptBase::NODES, true);
            if ($rol !== false) {
                $nuevo['parameters']['responses']['values'][0]['content'] = $original['parameters']['responses']['values'][0]['content'];
            } elseif ($original['name'] === 'Pedir Contexto al CRM') {
                $this->assertSame(S1::DECIDE_BODY, $nuevo['parameters']['jsonBody']);
                $nuevo['parameters']['jsonBody'] = $original['parameters']['jsonBody'];
            } elseif ($original['name'] === 'Preparar Commit') {
                $this->assertStringContainsString('promptsAplicados ? { prompts: promptsAplicados } : {}', $nuevo['parameters']['jsCode']);
                $nuevo['parameters']['jsCode'] = $original['parameters']['jsCode'];
            }
            $this->assertSame($original, $nuevo, "«{$original['name']}» solo cambia en lo de §B.7");
        }
        // Los objetos vacíos siguen siendo objetos (n8n distingue {} de []).
        $this->assertStringContainsString('"builtInTools": {}', (string) file_get_contents($this->genera().'/flujo-s1.json'));
    }

    public function test_el_comprobador_pasa_sobre_el_generado_y_falla_sobre_el_respaldo(): void
    {
        $node = Process::run(['node', '--version']);
        $this->assertTrue($node->successful(), 'E10 necesita Node en el PATH para ejecutar el comprobador offline.');
        $dir = $this->genera();

        $pasa = Process::timeout(120)->run(['node', base_path(self::COMPROBADOR), "{$dir}/flujo-s1.json", '--generado='.$dir]);
        $this->assertSame(0, $pasa->exitCode(), "el comprobador falla sobre el flujo generado:\n".$pasa->output().$pasa->errorOutput());
        $this->assertMatchesRegularExpression('/^OK \(\d+ comprobaciones\)$/m', $pasa->output());
        $this->assertStringNotContainsString('✗', $pasa->output());

        $falla = Process::timeout(120)->run(['node', base_path(self::COMPROBADOR), dirname(base_path()).'/'.self::RESPALDO, '--generado='.$dir]);
        $this->assertSame(1, $falla->exitCode(), 'el comprobador tiene que fallar sobre el respaldo 29de5119');
        $this->assertStringContainsString('✗ son los 24 del respaldo + «Elegir Prompts»', $falla->output());
        $this->assertMatchesRegularExpression('/^FALLO: \d+ de \d+ comprobaciones$/m', $falla->output());
    }

    public function test_el_comprobador_falla_si_alguien_edita_el_flujo(): void
    {
        $dir = $this->genera();
        $flujo = json_decode((string) file_get_contents("{$dir}/flujo-s1.json"), false, 512, JSON_THROW_ON_ERROR);
        foreach ($flujo->nodes as $nodo) {
            if ($nodo->name === 'Critic Comercial') {
                $nodo->parameters->options->temperature = 0.9;   // un cambio fuera de §B.7
            }
            if ($nodo->name === 'Elegir Prompts') {
                $nodo->parameters->jsCode = str_replace('Emoji opcional y raro', 'Emoji frecuente', $nodo->parameters->jsCode);   // Legacy editado a mano
            }
        }
        $editado = "{$dir}/editado.json";
        file_put_contents($editado, json_encode($flujo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $r = Process::timeout(120)->run(['node', base_path(self::COMPROBADOR), $editado, '--generado='.$dir]);
        $this->assertSame(1, $r->exitCode());
        $this->assertStringContainsString('✗ «Critic Comercial» igual al respaldo', $r->output());
        $this->assertStringContainsString('✗ sin oferta: roles = la base byte a byte', $r->output());
    }

    public function test_un_respaldo_que_no_es_29de5119_no_genera_nada(): void
    {
        $respaldo = $this->respaldo();
        foreach ($respaldo['nodes'] as $i => $nodo) {
            if ($nodo['name'] === 'Composer') {
                $respaldo['nodes'][$i]['parameters']['responses']['values'][0]['content'] .= ' ';
            }
        }
        $falso = $this->temporal().'/falso.json';
        file_put_contents($falso, json_encode($respaldo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $salida = $this->temporal().'/salida';

        $this->artisan('ultron:prompts-n8n', ['--from' => $falso, '--out' => $salida])
            ->expectsOutputToContain('No se puede generar S1')
            ->assertExitCode(1);
        $this->assertDirectoryDoesNotExist($salida);
    }

    // ── Andamio ───────────────────────────────────────────────────────────────

    private function genera(): string
    {
        $dir = $this->temporal();
        $this->artisan('ultron:prompts-n8n', ['--out' => $dir])->assertExitCode(0);

        return $dir;
    }

    /** @return array<string,mixed> */
    private function respaldo(): array
    {
        return json_decode((string) file_get_contents(dirname(base_path()).'/'.self::RESPALDO), true, 512, JSON_THROW_ON_ERROR);
    }

    private function temporal(): string
    {
        $dir = sys_get_temp_dir().'/ultron-s1-'.bin2hex(random_bytes(6));
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
