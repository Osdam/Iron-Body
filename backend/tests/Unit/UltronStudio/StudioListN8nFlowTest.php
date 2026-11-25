<?php

namespace Tests\Unit\UltronStudio;

use App\Services\Marketing\Ultron\Prompts\UltronListTurnPrompts;
use App\Services\Marketing\Ultron\Prompts\UltronPromptBase;
use App\Services\Marketing\Ultron\Prompts\UltronPromptCompiler;
use App\Services\Marketing\Ultron\UltronListTurnContract;
use App\Services\UltronStudio\StudioLabEngineFlow as Flow;
use App\Services\UltronStudio\StudioLabEngineTransport as Transport;
use App\Services\UltronStudio\StudioListN8nFlow as S3;
use InvalidArgumentException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Real generated Code, executed in Node; all inputs and model replies are synthetic. */
final class StudioListN8nFlowTest extends TestCase
{
    private static function nodes(array $flow): array
    {
        return array_column($flow['nodes'], null, 'name');
    }

    private static function execute(string $code, array $input, array $nodes): array
    {
        $process = new Process(['node', base_path('tests/fixtures/ultron-studio/tools/ejecutar-s3-node.cjs')]);
        $process->setInput(json_encode(compact('code', 'input', 'nodes'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $process->mustRun();

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }

    private static function model(array $value): array
    {
        return ['output' => [['content' => [['text' => $value]]]]];
    }

    public function test_s3_es_inactivo_preserva_s2_y_rutea_los_siete_nodos_por_http_explicito(): void
    {
        $before = hash_file('sha256', S3::referencePath());
        $source = S3::read(S3::referencePath());
        $flow = S3::build($source);
        $nodes = self::nodes($flow);
        $originals = self::nodes($source);
        $this->assertFalse($flow['active']);
        $this->assertTrue($source['settings']['availableInMCP']);
        $this->assertSame(array_replace($source['settings'], ['availableInMCP' => false]), $flow['settings']);
        $this->assertSame(S3::read(S3::referencePath()), $source, 'generar S3 no modifica el origen S2');
        $this->assertArrayNotHasKey('id', $flow);
        $this->assertArrayNotHasKey('versionId', $flow);
        $this->assertCount(count($source['nodes']) + 14, $nodes);
        $this->assertStringContainsString('generation_contract: "ultron-list-turn/1"', $nodes['Pedir Contexto al CRM']['parameters']['jsonBody']);
        $this->assertStringContainsString('FLOW_REV = "S3"', $nodes['Elegir Prompts']['parameters']['jsCode']);
        foreach (Flow::NODES as $name => $_) {
            $this->assertSame('n8n-nodes-base.code', $nodes[$name]['type']);
            $this->assertSame($originals[$name]['id'], $nodes[$name]['id']);
            $http = $nodes[S3::httpName($name)];
            $this->assertSame(['n8n-nodes-base.httpRequest', 4.4, false], [$http['type'], $http['typeVersion'], $http['retryOnFail']]);
            $this->assertSame($originals[$name]['credentials'], $http['credentials']);
            $this->assertSame(S3::OPENAI_URL, $http['parameters']['url']);
            $this->assertFalse($http['parameters']['options']['redirect']['redirect']['followRedirects']);
            $this->assertSame($name, $flow['connections'][S3::httpName($name)]['main'][0][0]['node']);
            $this->assertSame(S3::httpName($name), $flow['connections'][S3::prepareName($name)]['main'][0][0]['node']);
        }
        foreach ($flow['connections'] as $from => $ports) {
            foreach ($ports['main'] ?? [] as $edges) {
                foreach ($edges as $edge) {
                    if (isset(Flow::NODES[$edge['node']])) {
                        $this->assertSame(S3::httpName($edge['node']), $from, 'ninguna arista salta la preparación ni el HTTP');
                    }
                }
            }
        }
        $this->assertSame($before, hash_file('sha256', S3::referencePath()));
    }

    public function test_los_cuerpos_completos_de_los_siete_nodos_son_identicos_al_laboratorio(): void
    {
        $nodes = self::nodes(S3::build(S3::read(S3::referencePath())));
        foreach ([false, true] as $listTurn) {
            [$decide, $ran, $roles, $inputs, $lookup] = self::context($listTurn);
            foreach (Flow::NODES as $name => $spec) {
                $actual = self::execute($nodes[S3::prepareName($name)]['parameters']['jsCode'], $inputs[$name], $lookup);
                $this->assertTrue($actual['ok'], ($actual['error'] ?? '').' '.$name);
                $user = Flow::encode(Flow::userPayload($name, $decide, '¿Qué planes tienen?', $ran, $inputs[$name]));
                $instructions = UltronListTurnPrompts::select($spec['role'], $roles[$spec['role']], $listTurn);
                $this->assertSame(Transport::body($name, $instructions, $user), $actual['result'][0]['json']['ultron_request']['body'], $name.' list='.(int) $listTurn);
            }
        }
    }

    public function test_el_adaptador_no_repara_el_texto_y_rechaza_incompletas_negativas_y_schema_invalido(): void
    {
        $nodes = self::nodes(S3::build(S3::read(S3::referencePath())));
        [, , , $inputs, $lookup] = self::context(true);
        $prepared = self::execute($nodes[S3::prepareName(Flow::COMPOSER)]['parameters']['jsCode'], $inputs[Flow::COMPOSER], $lookup)['result'][0]['json'];
        $raw = '  Opciones: {{LISTA_DE_PLANES}}. ¿Sobre cuál amplío?  ';
        $output = ['reply_draft' => $raw, 'uses_price_placeholder' => true, 'questions_asked' => 1, 'plans_mentioned' => 2];
        $response = [
            'status' => 'completed', 'error' => null,
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($output, JSON_UNESCAPED_UNICODE)]]]],
            'usage' => ['input_tokens' => 100, 'output_tokens' => 30],
        ];
        $code = $nodes[Flow::COMPOSER]['parameters']['jsCode'];
        $lookup = [S3::prepareName(Flow::COMPOSER) => $prepared];
        $actual = self::execute($code, $response, $lookup);
        $this->assertTrue($actual['ok']);
        $this->assertSame($output, $actual['result'][0]['json']['output'][0]['content'][0]['text']);
        $this->assertSame($response['usage'], $actual['result'][0]['json']['usage']);
        $badResponses = [
            array_replace($response, ['status' => 'incomplete']),
            array_replace($response, ['status' => 'failed']),
            array_replace($response, ['output' => [['type' => 'message', 'content' => [['type' => 'refusal', 'refusal' => 'No.']]]]]),
            array_replace($response, ['output' => []]),
        ];
        foreach (['No contiene la ranura.', '{{LISTA_DE_PLANES}} {{LISTA_DE_PLANES}}', '{{PLAN_PRICE}} {{LISTA_DE_PLANES}}'] as $invalid) {
            $bad = $response;
            $bad['output'][0]['content'][0]['text'] = json_encode(array_replace($output, ['reply_draft' => $invalid]));
            $badResponses[] = $bad;
        }
        foreach ($badResponses as $bad) {
            $this->assertFalse(self::execute($code, $bad, $lookup)['ok']);
        }
    }

    public function test_el_comparador_rechaza_drift_de_nodos_aristas_body_y_credenciales_sin_mostrar_valores(): void
    {
        $source = S3::read(S3::referencePath());
        $flow = S3::build($source);
        $this->assertSame([], S3::differences($flow, $flow));
        $cosmetic = $flow;
        $cosmetic['nodes'][0]['position'] = [999, 999];
        $cosmetic['nodes'][0]['id'] = 'different-local-export-id';
        $this->assertSame([], S3::differences($cosmetic, $flow));
        $active = $flow;
        $active['active'] = true;
        $this->assertSame(['active'], S3::integrationDifferences($active, $flow));
        $pinned = $flow;
        $pinned['pinData'] = ['Composer' => ['private' => 'NEVER-COPY-OR-PRINT']];
        $this->assertSame(['export_metadata'], S3::integrationDifferences($pinned, $flow));
        $bad = $flow;
        $bad['nodes'][0]['parameters']['new_option'] = 'SECRET-NEVER-PRINT';
        $this->assertNotSame([], S3::differences($bad, $flow));
        $this->assertStringNotContainsString('SECRET-NEVER-PRINT', json_encode(S3::differences($bad, $flow)));
        $bad = $flow;
        $bad['connections']['Elegir Prompts']['main'][0][0]['node'] = 'Composer';
        $this->assertContains('connections', S3::differences($bad, $flow));
        $badSource = $source;
        $badSource['nodes'][0]['parameters']['new_option'] = true;
        $this->expectException(InvalidArgumentException::class);
        S3::build($badSource);
    }

    public function test_un_export_con_datos_fijados_o_estado_no_se_copia_al_artefacto(): void
    {
        foreach (['pinData', 'staticData', 'executionData', 'credentialData'] as $metadata) {
            $source = S3::read(S3::referencePath());
            $source[$metadata] = ['private' => 'NEVER-COPY-OR-PRINT'];
            try {
                S3::build($source);
                $this->fail('Se aceptaron datos fuera de la lista blanca.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringNotContainsString('NEVER-COPY-OR-PRINT', $exception->getMessage());
            }
        }
    }

    public function test_el_comparador_exige_false_explicito_en_active_y_disponibilidad_mcp(): void
    {
        $flow = S3::build(S3::read(S3::referencePath()));
        $flow['settings']['availableInMCP'] = false;
        $this->assertSame([], S3::integrationDifferences($flow, $flow));

        foreach (['active', 'settings.availableInMCP'] as $field) {
            foreach (['missing' => null, 'null' => null, 'zero' => 0, 'string_false' => 'false', 'true' => true] as $state => $value) {
                $candidate = $flow;
                if ($field === 'active') {
                    if ($state === 'missing') {
                        unset($candidate['active']);
                    } else {
                        $candidate['active'] = $value;
                    }
                } elseif ($state === 'missing') {
                    unset($candidate['settings']['availableInMCP']);
                } else {
                    $candidate['settings']['availableInMCP'] = $value;
                }
                $this->assertContains($field, S3::integrationDifferences($candidate, $flow), $field.': '.$state);
                $this->assertContains($field, S3::integrationDifferences($candidate, $candidate), 'una referencia insegura tampoco autoriza '.$field.': '.$state);
            }
        }
    }

    public function test_el_artefacto_exportado_permanece_inactivo_y_fuera_de_mcp(): void
    {
        $artifact = S3::read(base_path('../n8n/flujos/ultron-studio-s3/flujo-s3.json'));
        $this->assertFalse($artifact['active']);
        $this->assertFalse($artifact['settings']['availableInMCP']);
        $this->assertSame([], S3::integrationDifferences($artifact, S3::build(S3::read(S3::referencePath()))));
    }

    public function test_recorridos_s3_completos_coinciden_con_el_laboratorio_sin_llamadas_reales(): void
    {
        $flow = S3::build(S3::read(S3::referencePath()));
        $base = ['verdict' => 'pass', 'score' => 0.9, 'issues' => [], 'notes' => '', 'hard_fail' => null];
        $soft = array_replace($base, ['verdict' => 'fail', 'issues' => ['naturalness'], 'notes' => 'Mejora editorial.']);
        $hard = array_replace($soft, ['issues' => ['factuality'], 'hard_fail' => 'invented_class']);
        foreach ([
            'legacy sin comprobaciones' => [false, false, $base, false, false],
            'c9 general con comprobación' => [false, true, $base, false, false],
            'lista válida desde el primer borrador' => [true, true, $base, false, false],
            'lista limpia con rechazo editorial' => [true, true, $soft, false, false],
            'lista con fallo real del revisor' => [true, true, $hard, false, true],
            'lista con reescritura del servidor' => [true, true, $base, true, true],
            'lista con tercer borrador' => [true, true, $base, true, true],
            'lista con servidor caído' => [true, true, $soft, false, true],
        ] as $label => [$listTurn, $regeneration, $firstCritic, $serverRewrite, $expectsRetry]) {
            [$decide, , $roles] = self::context($listTurn);
            $legacy = str_starts_with($label, 'legacy');
            if ($legacy) {
                $roles = UltronPromptBase::roles();
            }
            $decide['source'] = ['source_type' => 'inbound_message', 'source_event_id' => null, 'idempotency_key' => 'synthetic-s3'];
            $decide['decide_token'] = 'synthetic-decide-token';
            $decide['studio_prompts'] = ['contract' => 'ultron-prompts/1', 'mode' => 'studio', 'reason' => null, 'bundle_hash' => UltronPromptBase::bundleHash($roles), 'roles' => $roles];
            if ($legacy) {
                $decide['studio_prompts'] = ['contract' => 'ultron-prompts/1', 'mode' => 'legacy', 'reason' => 'prompts_off', 'bundle_hash' => null, 'roles' => null];
            }
            if ($regeneration) {
                $decide['regeneration'] = ['contract' => Flow::CHECK_CONTRACT, 'max_drafts' => 3];
            }
            $strategy = [
                'strategy_goal' => 'responder', 'next_state' => 'NEW_LEAD', 'intent' => 'pricing_question',
                'recommended_plan_id' => 7, 'main_barrier' => null, 'next_best_action' => 'answer', 'confidence' => 0.9,
                'tools_requested' => [], 'staff_review_reason' => null, 'reason' => 'Consulta sintética.',
                'human_handoff_requested' => false, 'human_handoff_reason' => null, 'human_handoff_evidence' => null,
                'conversation_goal' => 'recommend', 'information_needed' => [], 'question_needed' => false,
                'response_goal' => 'Dar la lista.', 'buying_signal' => 'none', 'closing_opportunity' => false,
                'payment_opportunity' => false, 'app_support_opportunity' => false, 'recommendation_goal' => 'compare_plans',
            ];
            $decide['context']['active_plans'] = [['id' => 7], ['id' => 11]];
            $draft = ['reply_draft' => $listTurn ? '{{LISTA_DE_PLANES}}' : 'Respuesta general sintética.', 'uses_price_placeholder' => $listTurn, 'questions_asked' => 0, 'plans_mentioned' => $listTurn ? 2 : 0];
            $pins = [Flow::STRATEGIST => self::model($strategy), Flow::COMPOSER => self::model($draft), Flow::CRITIC => self::model($firstCritic), Flow::RETRY => self::model($draft), Flow::CRITIC_2 => self::model($base)];
            $check = static fn (int $attempt, bool $rewrite): array => ['statusCode' => 200, 'body' => [
                'ok' => true, 'contract' => Flow::CHECK_CONTRACT, 'mode' => 'studio', 'attempt' => $attempt,
                'pass' => ! $rewrite, 'defects' => $rewrite ? [['code' => 'length_over_cap', 'detail' => null]] : [],
                'issues' => $rewrite ? ['progressive_disclosure'] : [], 'notes' => $rewrite ? 'Más breve, conserve la ranura.' : '',
                'may_rewrite' => $rewrite, 'rewrite' => $rewrite,
                'budget' => ['max_drafts' => 3, 'elapsed_s' => 4, 'reason' => $rewrite ? null : 'list_final'],
            ]];
            if ($regeneration) {
                $pins[Flow::CHECK_1] = str_contains($label, 'caído') ? ['statusCode' => 503, 'body' => null] : $check(1, $serverRewrite);
                $pins[Flow::CHECK_2] = $check(2, false);
            }
            if (str_contains($label, 'tercer')) {
                $pins[Flow::CHECK_2] = $check(2, true);
                $pins[Flow::RETRY_2] = self::model($draft);
                $pins[Flow::CRITIC_3] = self::model($base);
            }
            $event = ['conversation_id' => 11, 'message_id' => 22, 'idempotency_key' => 'synthetic-s3', 'text' => '¿Qué planes tienen?'];
            $lab = Flow::walk($decide, $roles, $event['text'],
                static fn (string $name): array => ['ok' => true, 'json' => $pins[$name]],
                static fn (int $attempt): array => $pins[$attempt === 1 ? Flow::CHECK_1 : Flow::CHECK_2]);
            foreach (['lanza', 'undefined'] as $mode) {
                $process = new Process(['node', base_path('tests/fixtures/ultron-studio/tools/recorrer-s3-lab.cjs')]);
                $process->setInput(json_encode(['flowObject' => $flow, 'decide' => $decide, 'event' => $event, 'pins' => $pins, 'mode' => $mode], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                $process->mustRun();
                $actual = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
                $this->assertSame('done', $actual['status'], $label.': '.($actual['error'] ?? ''));
                $this->assertSame($lab['order'], $actual['order'], $label);
                $this->assertSame($lab['sent'], $actual['sent'], $label);
                foreach ($lab['sent'] as $name => $messages) {
                    $this->assertSame(Transport::body($name, $messages['system'], $messages['user']), $actual['requests'][$name], $label.' '.$name);
                }
                $this->assertSame(array_keys($lab['bodies']), array_keys($actual['bodies']), $label);
                foreach ($actual['bodies'] as $name => $body) {
                    $this->assertSame($lab['bodies'][$name], array_diff_key($body, array_flip(['conversation_id', 'message_id', 'decide_token'])), $label.' '.$name);
                }
                $this->assertSame($lab['commit']['reply_draft'], $actual['commit']['proposal']['reply_draft'], $label);
                $this->assertSame($lab['commit']['attempt'], $actual['commit']['critic']['attempt'], $label);
                $this->assertSame($lab['commit']['critic'], $actual['commit']['critic'], $label);
                $this->assertSame($expectsRetry, in_array(Flow::RETRY, $actual['order'], true), $label);
                $this->assertSame($firstCritic, $actual['ran'][Flow::CRITIC]['output'][0]['content'][0]['text'] ?? $firstCritic, 'el arbitraje no reescribe el veredicto del revisor');
            }
        }
    }

    private static function context(bool $listTurn): array
    {
        $policy = ['active_goal' => null, 'resume_goal_after_answer' => false, 'price_verification' => false, 'reception_mode' => false, 'memory_mode' => false, 'greet_required' => true];
        if ($listTurn) {
            $policy['plans_overview'] = [
                'plans' => [['plan_id' => 7], ['plan_id' => 11]],
                'render_contract' => UltronListTurnContract::build(['m' => 'studio', 'o' => 1, 'k' => 9, 'n' => 200], [7, 11], [
                    ['id' => 7, 'name' => 'Inicial', 'price' => 65000, 'duration_days' => 30],
                    ['id' => 11, 'name' => 'Completo', 'price' => 80000, 'duration_days' => 30],
                ], 'pricing_question'),
            ];
        }
        $ctx = [
            'memory' => [], 'resolved_reference' => [], 'novelty' => [], 'customer' => [], 'strategy_hints' => ['suggested_question' => null],
            'lead' => [], 'recent_messages' => [], 'knowledge_base' => [], 'gym' => [], 'business_time' => [], 'payment' => [], 'app' => [],
            'membership' => [], 'active_plans' => [], 'default_plan_id' => 7, 'flags' => [], 'sales_playbook' => [], 'approved_brand_copy' => [],
            'commercial_turn_policy' => $policy,
        ];
        $decide = ['commercial_phase' => 'NEW_LEAD', 'allowed_transitions' => ['NEW_LEAD'], 'allowed_tools' => [], 'allowed_staff_review_reasons' => [], 'context' => $ctx, 'decision' => []];
        $validated = ['strategy' => ['intent' => 'pricing_question', 'recommended_plan_id' => 7], 'human_handoff_allowed' => false, 'context' => $decide];
        $reply = self::model(['reply_draft' => '{{LISTA_DE_PLANES}}', 'uses_price_placeholder' => true, 'questions_asked' => 0, 'plans_mentioned' => 2]);
        $critic = self::model(['verdict' => 'fail', 'issues' => ['naturalness'], 'notes' => 'Más breve.', 'hard_fail' => null]);
        $check = ['ultron_check' => ['error' => false, 'codes' => [], 'may_rewrite' => false]];
        $ran = [Flow::VALIDATE => $validated, Flow::COMPOSER => $reply, Flow::RETRY => $reply, Flow::READ_1 => $check, Flow::READ_2 => $check];
        $roles = [];
        foreach (['strategist', 'composer', 'critic', 'composer_retry', 'critic_retry'] as $role) {
            $roles[$role] = UltronListTurnPrompts::pack($role, 'Prompt general de '.$role, UltronPromptCompiler::BASELINE);
        }
        $inputs = [];
        foreach (Flow::NODES as $name => $spec) {
            $inputs[$name] = match ($name) {
                Flow::STRATEGIST => $decide,
                Flow::COMPOSER => $validated,
                Flow::RETRY, Flow::RETRY_2 => $critic,
                default => $reply,
            };
        }
        $lookup = [
            'Pedir Contexto al CRM' => $decide, 'Normalizar Evento' => ['text' => '¿Qué planes tienen?'],
            'Elegir Prompts' => ['ultron_prompts' => ['roles' => $roles]],
            Flow::VALIDATE => $validated, Flow::COMPOSER => $reply, Flow::RETRY => $reply,
            Flow::READ_1 => $check, Flow::READ_2 => $check,
        ];

        return [$decide, $ran, $roles, $inputs, $lookup];
    }
}
