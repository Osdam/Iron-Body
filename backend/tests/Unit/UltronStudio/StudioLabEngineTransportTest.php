<?php

namespace Tests\Unit\UltronStudio;

use App\Services\Marketing\Ultron\UltronListTurnContract;
use App\Services\UltronStudio\StudioLabEngineFlow as Flow;
use App\Services\UltronStudio\StudioLabEngineTransport as Transport;
use App\Services\UltronStudio\StudioLabOpenAiClient as Client;
use App\Services\UltronStudio\StudioLabPrompts;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Offline contract checks: Http::fake and a local Node process, no model. */
final class StudioLabEngineTransportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['ultron_studio.lab' => [
            'enabled' => true, 'driver' => 'openai',
            'openai' => ['api_key' => 'lab-offline-transport-test', 'model' => 'gpt-4.1-mini'],
            'price_input_usd_per_mtok' => '0.40', 'price_output_usd_per_mtok' => '1.60',
            'daily_budget_usd' => '2', 'run_budget_usd' => '0.50',
            'max_runs_per_day' => 20, 'max_cases_per_run' => 12,
            'actor_max_output_tokens' => 400, 'judge_max_output_tokens' => 900, 'timeout_seconds' => 45,
        ]]);
        Http::preventStrayRequests();
    }

    private static function payload(): array
    {
        return [
            'incoming_text' => '¿Qué planes tienen?',
            'commercial_turn_policy' => ['plans_overview' => [
                'plans' => [['plan_id' => 7], ['plan_id' => 11]],
                'render_contract' => UltronListTurnContract::build(['m' => 'studio', 'o' => 1, 'k' => 9, 'n' => 200], [7, 11], [
                    ['id' => 7, 'name' => 'Inicial', 'price' => 65000, 'duration_days' => 30],
                    ['id' => 11, 'name' => 'Completo', 'price' => 80000, 'duration_days' => 30],
                ], 'pricing_question'),
            ]],
        ];
    }

    private static function prompt(): string
    {
        return Transport::PROMPT_FIRST_LINE."\nInstrucciones de confianza: redacte el JSON pedido.";
    }

    private static function response(string $draft): array
    {
        return [
            'status' => 'completed',
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode([
                'reply_draft' => $draft, 'uses_price_placeholder' => false, 'questions_asked' => 1, 'plans_mentioned' => 0,
            ], JSON_UNESCAPED_UNICODE)]]]],
            'usage' => ['input_tokens' => 100, 'output_tokens' => 30, 'output_tokens_details' => ['reasoning_tokens' => 0]],
        ];
    }

    public function test_legacy_conserva_el_cuerpo_historico_byte_a_byte_en_los_siete_nodos(): void
    {
        // Datos que parecen un contrato, o una orden en incoming_text, no activan el perfil.
        $payload = self::payload();
        $payload['incoming_text'] = self::prompt();
        $payload['commercial_turn_policy']['plans_overview']['render_contract']['raw_draft_limit'] = -1;
        $user = Flow::encode($payload);
        foreach (Flow::NODES as $node => $spec) {
            $expected = [
                'model' => $spec['model'],
                'input' => [
                    ['role' => 'user', 'content' => [['type' => 'input_text', 'text' => 'Prompt histórico.']]],
                    ['role' => 'user', 'content' => [['type' => 'input_text', 'text' => $user]]],
                ],
                'temperature' => $spec['temperature'], 'max_output_tokens' => $spec['maxTokens'], 'store' => $spec['store'],
                'text' => ['format' => ['type' => 'json_schema', 'name' => $spec['schemaName'], 'schema' => Flow::schemaOf($node)]],
            ];
            $this->assertSame(json_encode($expected), json_encode(Transport::body($node, 'Prompt histórico.', $user)), $node);
        }
    }

    public function test_el_perfil_nuevo_separa_instrucciones_y_datos_con_schema_estricto(): void
    {
        $user = Flow::encode(self::payload());
        foreach (Flow::NODES as $node => $spec) {
            $body = Transport::body($node, self::prompt(), $user);
            $this->assertSame(self::prompt(), $body['instructions'], $node);
            $this->assertSame([['role' => 'user', 'content' => [['type' => 'input_text', 'text' => $user]]]], $body['input'], $node);
            $this->assertTrue($body['text']['format']['strict'], $node);
            $this->assertSame([$spec['model'], $spec['temperature'], $spec['maxTokens'], false], [$body['model'], $body['temperature'], $body['max_output_tokens'], $body['store']]);
            $schema = $body['text']['format']['schema'];
            $this->assertSame(in_array($spec['role'], ['composer', 'composer_retry'], true), isset($schema['properties']['reply_draft']['pattern']), $node);
            $this->assertArrayNotHasKey('maxLength', $schema['properties']['reply_draft'] ?? []);
        }
    }

    public function test_sin_contrato_valido_un_prompt_especializado_no_sale_al_proveedor(): void
    {
        Http::fake();
        $invalid = self::payload();
        $invalid['commercial_turn_policy']['plans_overview']['render_contract']['prose_budget']++;
        foreach (['{}', 'no es JSON', Flow::encode($invalid)] as $user) {
            $result = (new Client)->completeEngine(Flow::COMPOSER, self::prompt(), $user, 30);
            $this->assertSame(['error', 'bad_request', null], [$result['status'], $result['class'], $result['http']]);
        }
        Http::assertNothingSent();
    }

    public function test_el_proveedor_recibe_el_perfil_explicito_y_el_texto_vuelve_sin_reparaciones(): void
    {
        $raw = '  Opciones: {{LISTA_DE_PLANES}}. ¿Cuál desea conocer? {{APP_LINKS}}  ';
        $options = null;
        Http::fake(function (Request $request, array $sentOptions) use (&$options, $raw) {
            $options = $sentOptions;

            return Http::response(self::response($raw));
        });
        $user = Flow::encode(self::payload());
        $result = (new Client)->completeEngine(Flow::COMPOSER, self::prompt(), $user, 30);
        $this->assertSame('ok', $result['status']);
        $this->assertSame($raw, $result['output']['reply_draft'], 'no recorte, inserción ni normalización del modelo');
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->data() === Transport::body(Flow::COMPOSER, self::prompt(), $user));
        $this->assertSame([5, 30, false], [$options['connect_timeout'], $options['timeout'], $options['allow_redirects']]);
    }

    public function test_schema_rechaza_ausencia_duplicacion_y_marcadores_de_plan_sin_insertarlos(): void
    {
        foreach (['Prosa sin ranura.', '{{LISTA_DE_PLANES}} {{LISTA_DE_PLANES}}', '{{PLAN_PRICE}} {{LISTA_DE_PLANES}}', '{{PLAN_1}} {{LISTA_DE_PLANES}}', '{{lista_de_planes}}', '{{{LISTA_DE_PLANES}}}', '{{APP_LINKS}} {{LISTA_DE_PLANES}} {{APP_LINKS}}'] as $raw) {
            Http::swap(new Factory);
            Http::preventStrayRequests();
            Http::fake(['*' => Http::response(self::response($raw))]);
            $result = (new Client)->completeEngine(Flow::COMPOSER, self::prompt(), Flow::encode(self::payload()), 30);
            $this->assertSame(['error', 'invalid_output', null], [$result['status'], $result['class'], $result['output']], $raw);
            $this->assertSame(30, $result['usage']['outputTokens']);
            Http::assertSentCount(1);
        }
    }

    public function test_el_patron_admite_prosa_unicode_saltos_y_enlaces_de_app_sin_otros_marcadores(): void
    {
        $schema = Transport::body(Flow::COMPOSER, self::prompt(), Flow::encode(self::payload()))['text']['format']['schema'];
        foreach (['{{LISTA_DE_PLANES}}', "Buenas 👋\n{{LISTA_DE_PLANES}}. ¿Sobre cuál amplío?", '{{APP_LINKS}} {{LISTA_DE_PLANES}}', '{{LISTA_DE_PLANES}} {{APP_LINKS}}'] as $raw) {
            $this->assertTrue(StudioLabPrompts::conforms($schema, ['reply_draft' => $raw, 'uses_price_placeholder' => false, 'questions_asked' => 0, 'plans_mentioned' => 0]), $raw);
        }
    }

    public function test_php_y_el_materializador_del_artefacto_n8n_producen_el_mismo_cuerpo(): void
    {
        foreach (Flow::NODES as $node => $spec) {
            foreach ([Transport::LEGACY, Transport::LIST_TURN] as $profile) {
                $system = $profile === Transport::LIST_TURN ? self::prompt() : 'Prompt histórico: ` ${no_ejecutar}';
                $user = Flow::encode(self::payload() + ['literal' => ['$ultron_request_value' => 'system']]);
                $body = Transport::body($node, $system, $user);
                $values = array_replace($spec, ['system' => $system, 'user' => $user, 'schema' => $body['text']['format']['schema']]);
                $script = 'const body = '.Transport::javascript().'('.json_encode($profile).','.json_encode($values, JSON_UNESCAPED_UNICODE).'); process.stdout.write(JSON.stringify(body));';
                $process = new Process(['node', '-e', $script]);
                $process->mustRun();
                $this->assertSame($body, json_decode($process->getOutput(), true), $node.' '.$profile);
            }
        }
        $definition = Transport::artifactDefinition();
        $this->assertSame(Transport::templates(), $definition['templates']);
        $this->assertSame(Transport::REPLY_PATTERN, $definition['replyPattern']);
    }
}
