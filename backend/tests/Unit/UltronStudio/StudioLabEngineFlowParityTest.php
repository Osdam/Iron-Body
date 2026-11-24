<?php

namespace Tests\Unit\UltronStudio;

use App\Services\Marketing\Ultron\Prompts\UltronPromptBase;
use App\Services\Marketing\Ultron\Prompts\UltronPromptCompiler;
use App\Services\UltronStudio\StudioLabEngineFlow as F;
use App\Services\UltronStudio\StudioPromptBundle;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * T2 Y T3 DEL APARTADO 5 (diseño `plans/informes-cierre/diseno-arq-lab.md`
 * §3.4): EL PORT DEL RECORRIDO S2 ES EL FLUJO REAL.
 *
 * T2 · Los siete nodos de OpenAI de {@see F::NODES} (rol, modelo, temperatura,
 * `maxTokens`, `store`, esquema byte a byte, claves del segundo mensaje y el
 * subconjunto de la política de los revisores), las aristas del tramo
 * ({@see F::CONNECTIONS}), las condiciones de los IF que porta
 * {@see F::evaluateIf()}, las huellas de los Code portados y el `nodesHash`,
 * frente a `n8n/flujos/ultron-studio-s2/flujo-s2.json`.
 *
 * T3 · {@see F::walk()} frente al JavaScript REAL del flujo ejecutado con Node
 * (`tests/fixtures/ultron-studio/tools/recorrer-s2-lab.cjs`: el selector,
 * Validar Estrategia, los IF, Leer Comprobación, Nota del Servidor, Decidir
 * Intento 3 y Preparar Commit), con las mismas salidas fijadas del modelo y de
 * la comprobación: los recorridos del LEEME y los del servidor caído (sobre
 * `fixtures/check-*.json`), los veredictos del revisor y salidas del
 * estratega hostiles. Se compara el orden de los nodos, el texto EXACTO de
 * los dos mensajes de cada nodo de OpenAI, el cuerpo de cada comprobación, la
 * salida de cada Code y lo que Preparar Commit elige.
 *
 * Si el flujo cambia sin el laboratorio, esto se pone en rojo. Necesita Node
 * en el PATH, como `UltronPromptsN8nS2CommandTest`.
 */
class StudioLabEngineFlowParityTest extends TestCase
{
    private const FLOW = 'n8n/flujos/ultron-studio-s2/flujo-s2.json';

    private const FIXTURES = 'n8n/flujos/ultron-studio-s2/fixtures';

    private const HARNESS = 'tests/fixtures/ultron-studio/tools/recorrer-s2-lab.cjs';

    /** Las condiciones de los IF que porta `evaluateIf()`, literales. */
    private const IF_CONDITIONS = [
        F::IF_CHECK_1 => "={{ \$('Pedir Contexto al CRM').first().json.regeneration ? \$('Pedir Contexto al CRM').first().json.regeneration.contract === 'ultron-regen/1' : false }}",
        F::IF_CHECK_2 => "={{ \$('Pedir Contexto al CRM').first().json.regeneration ? \$('Pedir Contexto al CRM').first().json.regeneration.contract === 'ultron-regen/1' : false }}",
        F::IF_REWRITE_1 => '={{ $json.ultron_check ? $json.ultron_check.rewrite === true : false }}',
        F::IF_REWRITE_2 => '={{ $json.ultron_check ? $json.ultron_check.rewrite === true : false }}',
        F::IF_CRITIC => '={{ $json.output[0].content[0].text.verdict }}',
        F::IF_THIRD => '={{ $json.ultron_intento3 === true }}',
    ];

    private const B1 = 'Claro, le cuento con gusto. ¿Qué le gustaría lograr?';

    private const B2 = 'Segundo borrador, de usted y más corto.';

    private const B3 = 'Tercer borrador: {{PLAN_NAME}} por {{PLAN_PRICE}}.';

    private static ?array $flujo = null;

    /** La raíz del repositorio (los proveedores de datos corren sin la aplicación). */
    private static function root(): string
    {
        return dirname(__DIR__, 4);
    }

    // ── T2 ──────────────────────────────────────────────────────────────────

    public function test_t2_los_siete_nodos_de_openai_son_los_del_flujo(): void
    {
        $porNombre = self::nodos();
        $deOpenAi = array_keys(array_filter($porNombre, static fn (array $n): bool => $n['type'] === '@n8n/n8n-nodes-langchain.openAi'));
        sort($deOpenAi);
        $esperados = array_keys(F::NODES);
        sort($esperados);
        $this->assertSame($esperados, $deOpenAi, 'los mismos siete nodos de OpenAI');

        // Cada rol del motor, en el nodo que lo usa en S1 (el compilador lo sabe).
        foreach (UltronPromptBase::NODES as $rol => $nodo) {
            $this->assertSame($rol, F::NODES[$nodo]['role'] ?? null, "«{$nodo}» usa el prompt de {$rol}");
        }

        foreach (F::NODES as $nombre => $spec) {
            $p = $porNombre[$nombre]['parameters'];
            $o = $p['options'];
            $tf = $o['textFormat']['textOptions'];
            $v = $p['responses']['values'];
            $this->assertSame(['__rl' => true, 'mode' => 'id', 'value' => $spec['model']], $p['modelId'], "{$nombre}: el modelo");
            $this->assertSame($spec['temperature'], $o['temperature'], "{$nombre}: la temperatura");
            $this->assertSame($spec['maxTokens'], $o['maxTokens'], "{$nombre}: maxTokens");
            $this->assertSame($spec['store'], $o['store'], "{$nombre}: store");
            $this->assertSame(['maxTokens', 'store', 'textFormat', 'temperature'], array_keys($o), "{$nombre}: ninguna opción más (ni instrucciones ni otra cosa)");
            $this->assertSame('json_schema', $tf['type']);
            $this->assertSame($spec['schemaName'], $tf['name'], "{$nombre}: el nombre del esquema");
            $this->assertSame($spec['schema'], $tf['schema'], "{$nombre}: el esquema, byte a byte");
            $this->assertSame($spec['strict'], $tf['strict']);
            $this->assertSame([], $p['builtInTools']);
            $this->assertCount(2, $v, "{$nombre}: dos mensajes, sin rol");
            $this->assertSame([['content'], ['content']], [array_keys($v[0]), array_keys($v[1])], "{$nombre}: ningún mensaje lleva `role`");
            $this->assertSame("={{ \$('Elegir Prompts').first().json.ultron_prompts.roles.{$spec['role']} }}", $v[0]['content'], "{$nombre}: el prompt compilado de su rol");
            [$claves, $subconjunto] = self::keysOf($v[1]['content']);
            $this->assertSame($spec['keys'], $claves, "{$nombre}: las claves del segundo mensaje, en su orden");
            $this->assertSame($spec['policySubset'], $subconjunto, "{$nombre}: el subconjunto de la política");
        }
    }

    public function test_t2_las_aristas_y_los_if_del_tramo_son_los_del_flujo(): void
    {
        $f = self::flujo();
        $tramo = [...array_keys(F::CONNECTIONS), F::COMMIT];
        foreach (F::CONNECTIONS as $nodo => $salidas) {
            $reales = array_map(static fn (array $s): array => array_map(static fn (array $c): string => $c['node'], $s), $f['connections'][$nodo]['main'] ?? []);
            $this->assertSame($salidas, $reales, "las salidas de «{$nodo}»");
            foreach ($reales as $s) {
                foreach ($s as $destino) {
                    $this->assertContains($destino, $tramo, "«{$nodo}» lleva fuera del tramo");
                }
            }
        }
        // Al tramo solo se entra por el estratega (desde el selector).
        foreach ($f['connections'] as $origen => $c) {
            if (in_array($origen, $tramo, true)) {
                continue;
            }
            foreach ($c['main'] ?? [] as $s) {
                foreach ($s as $destino) {
                    $this->assertTrue(! in_array($destino['node'], $tramo, true) || ($origen === 'Elegir Prompts' && $destino['node'] === F::STRATEGIST), "«{$origen}» entra al tramo por «{$destino['node']}»");
                }
            }
        }
        $nodos = self::nodos();
        foreach (self::IF_CONDITIONS as $if => $condicion) {
            $c = $nodos[$if]['parameters']['conditions'];
            $this->assertSame('and', $c['combinator']);
            $this->assertCount(1, $c['conditions']);
            $this->assertSame($condicion, $c['conditions'][0]['leftValue'], "la condición de «{$if}»");
        }
        $this->assertSame(['type' => 'string', 'operation' => 'equals'], $nodos[F::IF_CRITIC]['parameters']['conditions']['conditions'][0]['operator']);
        $this->assertSame('pass', $nodos[F::IF_CRITIC]['parameters']['conditions']['conditions'][0]['rightValue']);
        foreach (F::PORTED_CODE_SHA256 as $code => $huella) {
            $this->assertSame($huella, hash('sha256', $nodos[$code]['parameters']['jsCode']), "«{$code}» cambió: hay que volver a portarlo y subir StudioLabEngineFlow::VERSION");
        }
    }

    public function test_t2_la_revision_la_base_el_compilador_y_la_huella_de_los_nodos(): void
    {
        $f = self::flujo();
        $this->assertSame([F::FLOW_REV, F::BASE, F::COMPILER, F::CHECK_CONTRACT], [$f['_generado']['flowRev'], $f['_generado']['base'], $f['_generado']['compiler'], $f['_generado']['contract']]);
        $this->assertSame([UltronPromptCompiler::BASE, UltronPromptCompiler::VERSION], [F::BASE, F::COMPILER], 'el flujo es el del compilador de hoy');
        $this->assertSame('motor-S2-c8-'.F::VERSION, F::promptVersion());
        $this->assertLessThanOrEqual(32, strlen(F::promptVersion()), 'cabe en la columna');
        $this->assertTrue(F::isEnginePromptVersion(F::promptVersion()));
        $this->assertFalse(F::isEnginePromptVersion('2026-11-12.6'));
        // La huella de los nodos, recalculada desde el flujo.
        $nodos = self::nodos();
        $desdeElFlujo = [];
        foreach (array_keys(F::NODES) as $nombre) {
            $p = $nodos[$nombre]['parameters'];
            $tf = $p['options']['textFormat']['textOptions'];
            [$claves, $sub] = self::keysOf($p['responses']['values'][1]['content']);
            $desdeElFlujo[$nombre] = [
                'role' => substr($p['responses']['values'][0]['content'], strlen("={{ \$('Elegir Prompts').first().json.ultron_prompts.roles."), -3),
                'model' => $p['modelId']['value'], 'temperature' => $p['options']['temperature'], 'maxTokens' => $p['options']['maxTokens'], 'store' => $p['options']['store'],
                'schemaName' => $tf['name'], 'schema' => $tf['schema'], 'strict' => $tf['strict'],
                'keys' => $claves,
                'policySubset' => $sub,
            ];
        }
        $aristas = [];
        foreach (array_keys(F::CONNECTIONS) as $nodo) {
            $aristas[$nodo] = array_map(static fn (array $s): array => array_map(static fn (array $c): string => $c['node'], $s), $f['connections'][$nodo]['main']);
        }
        $codigo = array_map(static fn (string $n): string => hash('sha256', $nodos[$n]['parameters']['jsCode']), array_combine(array_keys(F::PORTED_CODE_SHA256), array_keys(F::PORTED_CODE_SHA256)));
        $this->assertSame(hash('sha256', F::encode(['flowRev' => $f['_generado']['flowRev'], 'nodes' => $desdeElFlujo, 'connections' => $aristas, 'code' => $codigo])), F::nodesHash());
    }

    // ── T3 ──────────────────────────────────────────────────────────────────

    /** @return array<string, array{0:string,1:array<string,mixed>}> */
    public static function recorridos(): array
    {
        $pasa = self::revisor('pass');
        $falla = self::revisor('fail', ['naturalness', 'relevance'], 'NOTA DEL REVISOR 1: tutea y no responde.');
        $falla2 = self::revisor('fail', ['next_step'], 'NOTA DEL REVISOR 2: sin paso siguiente.');
        $duro = self::revisor('pass', [], 'aprueba con un fallo duro', 'pressure');
        $c1 = F::CHECK_1;
        $c2 = F::CHECK_2;

        return [
            'legacy, pasa' => ['legacy', [F::COMPOSER => self::borrador(self::B1), F::CRITIC => $pasa]],
            'legacy, el revisor falla' => ['legacy', [F::COMPOSER => self::borrador(self::B1), F::CRITIC => $falla, F::RETRY => self::borrador(self::B2), F::CRITIC_2 => $pasa]],
            'legacy, el revisor falla dos veces' => ['legacy', [F::COMPOSER => self::borrador(self::B1), F::CRITIC => $falla, F::RETRY => self::borrador(self::B2), F::CRITIC_2 => $falla2]],
            'studio, pasa' => ['studio', [F::COMPOSER => self::borrador(self::B1), $c1 => self::check('pasa', 1), F::CRITIC => $pasa]],
            'studio, el servidor pide 1' => ['studio', [F::COMPOSER => self::borrador(self::B1), $c1 => self::check('reescribe', 1), F::RETRY => self::borrador(self::B2), $c2 => self::check('pasa', 2), F::CRITIC_2 => $pasa]],
            'studio, el revisor pide 1' => ['studio', [F::COMPOSER => self::borrador(self::B1), $c1 => self::check('pasa', 1), F::CRITIC => $falla, F::RETRY => self::borrador(self::B2), $c2 => self::check('pasa', 2), F::CRITIC_2 => $pasa]],
            'studio, dos reescrituras del servidor' => ['studio', [F::COMPOSER => self::borrador(self::B1), $c1 => self::check('reescribe', 1), F::RETRY => self::borrador(self::B2), $c2 => self::check('reescribe', 2), F::RETRY_2 => self::borrador(self::B3), F::CRITIC_3 => $pasa]],
            'studio, el revisor y después el servidor' => ['studio', [F::COMPOSER => self::borrador(self::B1), $c1 => self::check('pasa', 1), F::CRITIC => $falla, F::RETRY => self::borrador(self::B2), $c2 => self::check('reescribe', 2), F::RETRY_2 => self::borrador(self::B3), F::CRITIC_3 => $pasa]],
            'studio, el servidor y después el revisor' => ['studio', [F::COMPOSER => self::borrador(self::B1), $c1 => self::check('reescribe', 1), F::RETRY => self::borrador(self::B2), $c2 => self::check('pasa', 2), F::CRITIC_2 => $falla2, F::RETRY_2 => self::borrador(self::B3), F::CRITIC_3 => $pasa]],
            'studio, dos del revisor (peor caso)' => ['studio', [F::COMPOSER => self::borrador(self::B1), $c1 => self::check('pasa', 1), F::CRITIC => $falla, F::RETRY => self::borrador(self::B2), $c2 => self::check('pasa', 2), F::CRITIC_2 => $falla2, F::RETRY_2 => self::borrador(self::B3), F::CRITIC_3 => $pasa]],
            'studio, el tercero también rechazado' => ['studio', [F::COMPOSER => self::borrador(self::B1), $c1 => self::check('pasa', 1), F::CRITIC => $falla, F::RETRY => self::borrador(self::B2), $c2 => self::check('pasa', 2), F::CRITIC_2 => $falla2, F::RETRY_2 => self::borrador(self::B3), F::CRITIC_3 => $falla]],
            'studio, 404 en la 1' => ['studio', [F::COMPOSER => self::borrador(self::B1), $c1 => self::check('404', null), F::CRITIC => $pasa]],
            'studio, 422 en la 1' => ['studio', [F::COMPOSER => self::borrador(self::B1), $c1 => self::check('422', null), F::CRITIC => $pasa]],
            'studio, red en la 1' => ['studio', [F::COMPOSER => self::borrador(self::B1), $c1 => self::check('error-de-red', null), F::CRITIC => $pasa]],
            'studio, mal formada en la 1' => ['studio', [F::COMPOSER => self::borrador(self::B1), $c1 => self::check('mal-formada', null), F::CRITIC => $pasa]],
            'studio, otro intento en la respuesta' => ['studio', [F::COMPOSER => self::borrador(self::B1), $c1 => self::check('reescribe', 2), F::CRITIC => $pasa]],
            'studio, servidor caído en las dos' => ['studio', [F::COMPOSER => self::borrador(self::B1), $c1 => self::check('404', null), F::CRITIC => $falla, F::RETRY => self::borrador(self::B2), $c2 => self::check('error-de-red', null), F::CRITIC_2 => $falla2]],
            'studio, la comprobación contesta legacy' => ['studio', [F::COMPOSER => self::borrador(self::B1), $c1 => self::check('legacy', 1), F::CRITIC => $falla, F::RETRY => self::borrador(self::B2), $c2 => self::check('legacy', 2), F::CRITIC_2 => $falla2]],
            'studio, sin presupuesto en la 2' => ['studio', [F::COMPOSER => self::borrador(self::B1), $c1 => self::check('pasa', 1), F::CRITIC => $falla, F::RETRY => self::borrador(self::B2), $c2 => self::check('sin-presupuesto', 2), F::CRITIC_2 => $falla2]],
            'studio, el servidor pide 1 y la 2 cae' => ['studio', [F::COMPOSER => self::borrador(self::B1), $c1 => self::check('reescribe', 1), F::RETRY => self::borrador(self::B2), $c2 => self::check('error-de-red', null), F::CRITIC_2 => $falla2]],
            'studio, aprueba con un fallo duro' => ['studio', [F::COMPOSER => self::borrador(self::B1), $c1 => self::check('pasa', 1), F::CRITIC => $duro]],
            'studio, el revisor 2 con fallo duro da tercer borrador' => ['studio', [F::COMPOSER => self::borrador(self::B1), $c1 => self::check('pasa', 1), F::CRITIC => $falla, F::RETRY => self::borrador(self::B2), $c2 => self::check('pasa', 2), F::CRITIC_2 => $duro, F::RETRY_2 => self::borrador(self::B3), F::CRITIC_3 => $pasa]],
            'studio, el tercer borrador vacío se queda el segundo' => ['studio', [F::COMPOSER => self::borrador(self::B1), $c1 => self::check('pasa', 1), F::CRITIC => $falla, F::RETRY => self::borrador(self::B3), $c2 => self::check('pasa', 2), F::CRITIC_2 => $falla2, F::RETRY_2 => self::borrador(''), F::CRITIC_3 => $pasa]],
            'studio, sin borrador: Preparar Commit lanza' => ['studio', [F::COMPOSER => self::borrador('   '), $c1 => self::check('pasa', 1), F::CRITIC => $pasa]],
        ];
    }

    /** @param  array<string,mixed>  $pins */
    #[DataProvider('recorridos')]
    public function test_t3_el_recorrido_es_el_del_javascript_real(string $modo, array $pins): void
    {
        $this->compara(self::decide($modo), self::estrategia(), $pins);
    }

    /** @return array<string, array{0:array<string,mixed>,1:array<string,mixed>}> */
    public static function estrategiasHostiles(): array
    {
        return [
            'fuera de la lista blanca' => [[], ['next_state' => 'CLOSED_WON', 'tools_requested' => ['payment_link_send', 'staff_review', 7], 'recommended_plan_id' => 999, 'staff_review_reason' => 'Lo Que Sea ']],
            'cotiza sin plan: el por defecto' => [[], ['intent' => 'pricing_question', 'recommended_plan_id' => null]],
            'cotiza sin plan y sin por defecto' => [['default_plan_id' => null], ['intent' => 'price_objection', 'recommended_plan_id' => null]],
            'la decisión del CRM cotiza' => [['decision_intent' => 'payment_link_request'], ['intent' => 'general_info']],
            'derivación sin permiso' => [[], ['human_handoff_requested' => true, 'next_state' => 'HUMAN_HANDOFF', 'human_handoff_reason' => 'explicit_human_request']],
            'derivación con permiso y prueba larga' => [['handoff' => true], ['human_handoff_requested' => true, 'next_state' => 'HUMAN_HANDOFF', 'human_handoff_reason' => '  EXPLICIT_HUMAN_REQUEST ', 'human_handoff_evidence' => '  '.str_repeat('quiero hablar con alguien ', 20)]],
            'derivación con otro motivo' => [['handoff' => true], ['human_handoff_requested' => true, 'next_state' => 'HUMAN_HANDOFF', 'human_handoff_reason' => 'complaint']],
            'HUMAN_HANDOFF sin pedirla' => [['handoff' => true], ['human_handoff_requested' => false, 'next_state' => 'HUMAN_HANDOFF']],
            'revisión con motivo válido' => [[], ['tools_requested' => ['staff_review'], 'staff_review_reason' => ' Customer_Requested_Review ']],
            'enumerados inválidos y preguntas' => [[], ['conversation_goal' => 'dominate', 'buying_signal' => 'max', 'recommendation_goal' => 7, 'information_needed' => ['goal', 'budget', 3, 'schedule', 'goal', 'a', 'b', 'c'], 'response_goal' => str_repeat('ñ', 200)]],
            'hot lead y sin vender' => [['hints' => ['hot_lead_fast_path' => true, 'do_not_sell' => false, 'may_ask' => ['goal']]], ['next_best_action' => 'ask_discovery', 'information_needed' => ['goal'], 'closing_opportunity' => true, 'payment_opportunity' => true]],
            'cliente: no se le recomienda' => [['hints' => ['do_not_sell' => true, 'payment_possible' => true, 'question_budget' => 0, 'may_ask' => ['goal']]], ['next_best_action' => 'recommend_plan', 'recommendation_goal' => 'single_plan', 'closing_opportunity' => true, 'payment_opportunity' => true, 'information_needed' => ['goal']]],
            'barrera desconocida y confianza fuera de rango' => [[], ['main_barrier' => 'aliens', 'confidence' => 7]],
            'confianza negativa' => [[], ['confidence' => -0.25]],
            'confianza en texto' => [[], ['confidence' => '0.65']],
            'confianza ilegible' => [[], ['confidence' => 'mucha']],
            'confianza nula y barrera nula' => [[], ['confidence' => null, 'main_barrier' => null]],
            'sin cambios' => [[], []],
        ];
    }

    /**
     * @param  array<string,mixed>  $mundo
     * @param  array<string,mixed>  $cambios
     */
    #[DataProvider('estrategiasHostiles')]
    public function test_t3_validar_estrategia_es_la_del_javascript_real(array $mundo, array $cambios): void
    {
        $decide = self::decide('studio', $mundo);
        $estrategia = array_replace(self::estrategia(), $cambios);
        $pins = [F::COMPOSER => self::borrador(self::B3), F::CHECK_1 => self::check('pasa', 1), F::CRITIC => self::revisor('pass')];
        $this->compara($decide, $estrategia, $pins);
    }

    public function test_t3_una_estrategia_ilegible_para_el_recorrido_como_en_n8n(): void
    {
        $decide = self::decide('studio');
        foreach (['"no es un objeto"', '[1, 2]', '"{\"a\": 1"'] as $texto) {
            $pins = [F::STRATEGIST => ['output' => [['content' => [['text' => json_decode($texto, true)]]]]]];
            $real = $this->ejecuta($decide, $pins, 'lanza');
            $lab = F::walk($decide, self::roles(), 'hola', fn (string $n) => ['ok' => true, 'json' => $pins[$n] ?? $this->fail("llamada no prevista: {$n}")], fn () => $this->fail('sin comprobación'));
            $this->assertSame(['failed', F::VALIDATE, $real['error']], [$lab['status'], $lab['stoppedAt'], $lab['error']], $texto);
            $this->assertSame($real['order'], $lab['order']);
        }
    }

    // ── Andamio ─────────────────────────────────────────────────────────────

    /**
     * @param  array<string,mixed>  $decide
     * @param  array<string,mixed>  $estrategia
     * @param  array<string,mixed>  $pins
     */
    private function compara(array $decide, array $estrategia, array $pins): void
    {
        $pins = [F::STRATEGIST => ['output' => [['content' => [['text' => $estrategia]]]], 'usage' => ['input_tokens' => 7000, 'output_tokens' => 300]]] + $pins;
        $llamadas = [];
        // Los prompts que elegiría el selector: los de la oferta o, sin ella, los Legacy (la base).
        $roles = ($decide['studio_prompts']['mode'] ?? null) === 'studio' ? self::roles() : UltronPromptBase::roles();
        $lab = F::walk($decide, $roles, self::evento()['text'],
            function (string $nodo, string $sistema, string $usuario) use ($pins, &$llamadas): array {
                $llamadas[] = $nodo;
                $this->assertArrayHasKey($nodo, $pins, "el laboratorio llama a «{$nodo}», que el guion no prevé");

                return ['ok' => true, 'json' => $pins[$nodo]];
            },
            function (int $k, array $cuerpo) use ($pins): array {
                $nodo = $k === 1 ? F::CHECK_1 : F::CHECK_2;
                $this->assertArrayHasKey($nodo, $pins, "el laboratorio comprueba el borrador {$k}, que el guion no prevé");

                return $pins[$nodo];
            });

        foreach (['lanza', 'undefined'] as $modo) {
            $real = $this->ejecuta($decide, $pins, $modo);
            $this->assertSame($real['order'], $lab['order'], "[{$modo}] el orden de los nodos");
            $this->assertSame($real['status'], $lab['status'], "[{$modo}] cómo termina");
            $this->assertSame(array_keys($real['sent']), array_keys($lab['sent']), "[{$modo}] los nodos de OpenAI que corren");
            foreach ($real['sent'] as $nodo => $m) {
                $this->assertSame($m['system'], $lab['sent'][$nodo]['system'], "[{$modo}] «{$nodo}»: el prompt de sistema");
                $this->assertSame($m['user'], $lab['sent'][$nodo]['user'], "[{$modo}] «{$nodo}»: el segundo mensaje, byte a byte");
            }
            $this->assertSame(array_keys($real['bodies']), array_keys($lab['bodies']), "[{$modo}] las comprobaciones");
            foreach ($real['bodies'] as $nodo => $cuerpo) {
                $this->assertSame([$decide['decide_token'], 11, 22], [$cuerpo['decide_token'], $cuerpo['conversation_id'], $cuerpo['message_id']]);
                $this->assertSame(array_diff_key($cuerpo, array_flip(['conversation_id', 'message_id', 'decide_token'])), $lab['bodies'][$nodo], "[{$modo}] «{$nodo}»: el cuerpo");
            }
            foreach ($real['ran'] as $nodo => $json) {
                if (in_array($nodo, [F::VALIDATE, F::READ_1, F::READ_2, F::NOTE_1, F::NOTE_2, F::DECIDE_3], true)) {
                    $this->assertSame($json, $lab['ran'][$nodo], "[{$modo}] la salida de «{$nodo}»");
                }
            }
            if ($real['status'] === 'failed') {
                $this->assertSame([$real['at'], $real['error']], [$lab['stoppedAt'], $lab['error']], "[{$modo}] el Code que lanza y su mensaje");

                continue;
            }
            $c = $real['commit'];
            $this->assertSame([$c['proposal']['reply_draft'], $c['critic']['attempt'], $c['proposal']['recommended_plan_id']], [$lab['commit']['reply_draft'], $lab['commit']['attempt'], $lab['commit']['recommended_plan_id']], "[{$modo}] el borrador, el intento y el plan");
            $this->assertSame($c['critic'], $lab['commit']['critic'], "[{$modo}] el veredicto que llega al commit");
            $bloque = $c['regeneration'] ?? null;
            if ($bloque !== null) {
                unset($bloque['tokens']);
            }
            $this->assertSame($bloque, $lab['commit']['regeneration'], "[{$modo}] el bloque regeneration (sin los tokens)");
        }
        $this->assertSame(array_keys($lab['sent']), $llamadas);
    }

    /**
     * @param  array<string,mixed>  $decide
     * @param  array<string,mixed>  $pins
     * @return array<string,mixed>
     */
    private function ejecuta(array $decide, array $pins, string $modo): array
    {
        $this->assertTrue(Process::run(['node', '--version'])->successful(), 'T3 necesita Node en el PATH para ejecutar el flujo real.');
        $spec = tempnam(sys_get_temp_dir(), 'ultron-s2-lab-');
        file_put_contents($spec, json_encode(['flow' => self::root().'/'.self::FLOW, 'decide' => $decide, 'event' => self::evento(), 'pins' => $pins, 'mode' => $modo], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        try {
            $r = Process::timeout(60)->run(['node', self::root().'/backend/'.self::HARNESS, $spec]);
        } finally {
            unlink($spec);
        }
        $this->assertTrue($r->successful(), 'el arnés falló: '.$r->errorOutput());

        return json_decode($r->output(), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string,string> los cinco prompts de una versión «profesional, 200» */
    private static function roles(): array
    {
        return StudioPromptBundle::rolesFor(array_replace(UltronPromptCompiler::BASELINE, ['persona.tone' => 'profesional', 'persona.maxChars' => 200]))['roles'];
    }

    /**
     * Un `decide` sintético con la oferta de esos prompts (`studio`) o sin
     * ella (`legacy`: sin `regeneration`), a partir de `fixtures/decide-studio.json`.
     *
     * @param  array<string,mixed>  $mundo
     * @return array<string,mixed>
     */
    private static function decide(string $modo, array $mundo = []): array
    {
        $d = json_decode((string) file_get_contents(self::root().'/'.self::FIXTURES.'/decide-studio.json'), true, 512, JSON_THROW_ON_ERROR);
        $paquete = StudioPromptBundle::rolesFor(array_replace(UltronPromptCompiler::BASELINE, ['persona.tone' => 'profesional', 'persona.maxChars' => 200]));
        $d['studio_prompts'] = array_replace($d['studio_prompts'], ['mode' => 'studio', 'reason' => null, 'bundle_hash' => $paquete['bundleHash'], 'roles' => $paquete['roles']]);
        $d['allowed_staff_review_reasons'] = ['customer_requested_review'];
        $d['decision'] = ['intent' => $mundo['decision_intent'] ?? 'general_info', 'confidence' => 0.8];
        if ($mundo['handoff'] ?? false) {
            $d['allowed_transitions'][] = 'HUMAN_HANDOFF';
        }
        if (array_key_exists('default_plan_id', $mundo)) {
            $d['context']['default_plan_id'] = $mundo['default_plan_id'];
        }
        $d['context']['strategy_hints'] = ($mundo['hints'] ?? []) + ['may_ask' => ['goal', 'schedule'], 'suggested_question' => '¿Qué le gustaría lograr?'] + (is_array($d['context']['strategy_hints'] ?? null) ? $d['context']['strategy_hints'] : []);
        $d['context'] += [
            'memory' => ['summary' => null, 'commercial_phase' => 'NEW_LEAD', 'structured' => ['delivered' => []]],
            'recent_messages' => [['role' => 'lead', 'body' => 'hola', 'at' => '2026-11-22T15:00:00+00:00']],
            'knowledge_base' => ['horarios' => [['title' => 'Horario', 'content' => 'Lunes a viernes, 5:00 a 21:00.']]],
            'gym' => ['classes' => [], 'opening_hours' => 'SOURCE_NOT_AVAILABLE'],
            'approved_brand_copy' => [],
            'flags' => ['can_offer_link' => false],
        ];
        if ($modo === 'legacy') {
            unset($d['regeneration']);
            $d['studio_prompts'] = array_replace($d['studio_prompts'], ['mode' => 'legacy', 'reason' => 'prompts_off', 'bundle_hash' => null, 'roles' => null]);
        }

        return $d;
    }

    /** @return array<string,mixed> */
    private static function evento(): array
    {
        return ['conversation_id' => 11, 'message_id' => 22, 'idempotency_key' => 'wamid.X', 'text' => 'hola, ¿qué planes tienen?', 'message_type' => 'text', 'channel' => 'whatsapp', 'event_id' => ''];
    }

    /** @return array<string,mixed> */
    private static function estrategia(): array
    {
        return [
            'strategy_goal' => 'collect_data', 'next_state' => 'GOAL_DISCOVERY', 'intent' => 'general_info', 'recommended_plan_id' => null, 'main_barrier' => null,
            'next_best_action' => 'ask_discovery', 'confidence' => 0.8, 'tools_requested' => [], 'staff_review_reason' => null, 'reason' => 'saludo',
            'human_handoff_requested' => false, 'human_handoff_reason' => null, 'human_handoff_evidence' => null, 'conversation_goal' => 'understand',
            'information_needed' => ['goal'], 'question_needed' => true, 'response_goal' => 'entender', 'buying_signal' => 'none', 'closing_opportunity' => false,
            'payment_opportunity' => false, 'app_support_opportunity' => false, 'recommendation_goal' => 'none',
        ];
    }

    /** @return array<string,mixed> */
    private static function borrador(string $texto): array
    {
        return ['output' => [['content' => [['text' => ['reply_draft' => $texto, 'uses_price_placeholder' => str_contains($texto, 'PLAN_PRICE'), 'questions_asked' => 1, 'plans_mentioned' => 0]]]]], 'usage' => ['input_tokens' => 9000, 'output_tokens' => 120]];
    }

    /** @return array<string,mixed> */
    private static function revisor(string $verdict, array $issues = [], string $notes = '', ?string $hardFail = null): array
    {
        return ['output' => [['content' => [['text' => ['verdict' => $verdict, 'score' => $verdict === 'pass' ? 0.9 : 0.3, 'issues' => $issues, 'notes' => $notes, 'hard_fail' => $hardFail]]]]], 'usage' => ['input_tokens' => 8000, 'output_tokens' => 60]];
    }

    /** @return array<string,mixed> la salida del nodo HTTP desde `fixtures/check-<nombre>.json`, con su intento */
    private static function check(string $nombre, ?int $intento): array
    {
        $fichero = $nombre === 'sin-presupuesto' ? 'pasa' : $nombre;
        $f = json_decode((string) file_get_contents(self::root().'/'.self::FIXTURES."/check-{$fichero}.json"), true, 512, JSON_THROW_ON_ERROR);
        if ($intento !== null && is_array($f['body'] ?? null)) {
            $f['body']['attempt'] = $intento;
        }
        if ($nombre === 'sin-presupuesto') {
            $f['body']['may_rewrite'] = false;
            $f['body']['budget'] = ['max_drafts' => 3, 'elapsed_s' => 31, 'reason' => 'time_budget'];
        }

        return $f;
    }

    /** @return array<string,mixed> */
    private static function flujo(): array
    {
        return self::$flujo ??= json_decode((string) file_get_contents(self::root().'/'.self::FLOW), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, array<string,mixed>> */
    private static function nodos(): array
    {
        $out = [];
        foreach (self::flujo()['nodes'] as $n) {
            $out[$n['name']] = $n;
        }

        return $out;
    }

    /**
     * Las claves de primer nivel de `={{ JSON.stringify({ … }) }}`, en su
     * orden, y las del único objeto anidado literal (la política de los
     * revisores), si lo hay.
     *
     * @return array{0:list<string>,1:list<string>|null}
     */
    private static function keysOf(string $expresion): array
    {
        $prefijo = '={{ JSON.stringify({';
        self::assertStringStartsWith($prefijo, $expresion);
        $s = substr($expresion, strlen($prefijo));
        $claves = [];
        $sub = null;
        $pila = 0;
        $comilla = null;
        $esperaClave = true;
        $actual = '';
        $n = strlen($s);
        for ($i = 0; $i < $n; $i++) {
            $c = $s[$i];
            if ($comilla !== null) {
                if ($c === '\\') {
                    $i++;
                } elseif ($c === $comilla) {
                    $comilla = null;
                }

                continue;
            }
            if ($c === '"' || $c === "'") {
                $comilla = $c;

                continue;
            }
            if (in_array($c, ['(', '[', '{'], true)) {
                if ($pila === 0 && $c === '{' && ! $esperaClave) {
                    $cierre = self::closing($s, $i);
                    [$sub] = self::keysOf($prefijo.substr($s, $i + 1, $cierre - $i - 1).'})');
                    $i = $cierre;

                    continue;
                }
                $pila++;
            } elseif (in_array($c, [')', ']', '}'], true)) {
                if ($pila === 0) {
                    break;
                }
                $pila--;
            } elseif ($pila === 0 && $c === ':' && $esperaClave) {
                $claves[] = trim($actual);
                $esperaClave = false;
                $actual = '';

                continue;
            } elseif ($pila === 0 && $c === ',') {
                $esperaClave = true;
                $actual = '';

                continue;
            }
            if ($esperaClave && $pila === 0) {
                $actual .= $c;
            }
        }

        return [$claves, $sub];
    }

    private static function closing(string $s, int $abre): int
    {
        $pila = 0;
        $comilla = null;
        for ($i = $abre; $i < strlen($s); $i++) {
            $c = $s[$i];
            if ($comilla !== null) {
                if ($c === '\\') {
                    $i++;
                } elseif ($c === $comilla) {
                    $comilla = null;
                }

                continue;
            }
            if ($c === '"' || $c === "'") {
                $comilla = $c;
            } elseif (in_array($c, ['(', '[', '{'], true)) {
                $pila++;
            } elseif (in_array($c, [')', ']', '}'], true)) {
                $pila--;
                if ($pila === 0) {
                    return $i;
                }
            }
        }
        self::fail('objeto sin cerrar');
    }
}
