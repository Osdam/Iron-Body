<?php

namespace App\Services\UltronStudio;

use App\Services\Marketing\Ultron\Prompts\UltronListTurnPrompts;
use App\Services\Marketing\Ultron\Prompts\UltronPromptsN8nFlow;
use App\Services\Marketing\Ultron\UltronListCriticGate;
use App\Services\Marketing\Ultron\UltronListTurnContract;
use InvalidArgumentException;

/** Local, inactive S3 artifact. It never contacts n8n or a model provider. */
final class StudioListN8nFlow
{
    public const VERSION = 'ultron-list-n8n/1';

    public const REV = 'S3';

    public const NAME = 'Iron Body - ULTRON - Commercial Advisor (Studio S3 · contrato inicial)';

    public const OPENAI_URL = 'https://api.openai.com/v1/responses';

    private const EXPORT_KEYS = ['id', 'name', 'active', 'versionId', 'updatedAt', 'createdAt', 'nodes', 'connections', 'settings', '_generado'];

    public static function referencePath(): string
    {
        return dirname(__DIR__, 4).'/n8n/flujos/ultron-studio-s2/flujo-s2.json';
    }

    /** Accept one workflow object, or the one-item export array from n8n. */
    public static function read(string $path): array
    {
        $raw = @file_get_contents($path);
        $value = is_string($raw) ? json_decode($raw, true) : null;
        if (is_array($value) && array_is_list($value) && count($value) === 1) {
            $value = $value[0];
        }
        if (! is_array($value) || ! is_array($value['nodes'] ?? null) || ! is_array($value['connections'] ?? null)) {
            throw new InvalidArgumentException('El archivo no contiene un único workflow de n8n.');
        }

        return $value;
    }

    /** Build only from a locally exported S2 that matches the committed reference. */
    public static function build(array $source): array
    {
        if (array_diff(array_keys($source), self::EXPORT_KEYS) !== []) {
            throw new InvalidArgumentException('El export S2 contiene metadatos no admitidos: no se copian datos fijados ni estado de ejecución.');
        }
        if (self::differences($source, self::read(self::referencePath())) !== []) {
            throw new InvalidArgumentException('El export S2 difiere de la referencia: no se genera S3.');
        }
        $flow = $source;
        unset($flow['id'], $flow['versionId'], $flow['updatedAt'], $flow['createdAt'], $flow['_generado']);
        $flow['name'] = self::NAME;
        $flow['active'] = false;
        $flow['settings']['availableInMCP'] = false;
        $nodes = [];
        $replacement = [];
        foreach ($flow['nodes'] as $node) {
            $name = $node['name'];
            if ($name === 'Elegir Prompts') {
                $node['parameters']['jsCode'] = UltronPromptsN8nFlow::selectorCode(self::REV);
            }
            if ($name === 'Pedir Contexto al CRM') {
                $node['parameters']['jsonBody'] = '={{ JSON.stringify({ conversation_id: $json.conversation_id, message_id: $json.message_id, prompt_contract: "ultron-prompts/1", generation_contract: "ultron-list-turn/1" }) }}';
            }
            if ($name === StudioLabEngineFlow::IF_CRITIC) {
                $node['parameters']['conditions']['conditions'][0]['leftValue'] = self::criticGateExpression();
            }
            if (! isset(StudioLabEngineFlow::NODES[$name])) {
                $nodes[] = $node;

                continue;
            }
            $prepare = self::prepareName($name);
            $http = self::httpName($name);
            $replacement[$name] = $prepare;
            $position = $node['position'] ?? [0, 0];
            $nodes[] = [
                'parameters' => ['jsCode' => self::prepareCode($name, $node)],
                'id' => self::nodeId('prepare', $name), 'name' => $prepare,
                'type' => 'n8n-nodes-base.code', 'typeVersion' => 2,
                'position' => [$position[0] - 320, $position[1]],
            ];
            $nodes[] = [
                'parameters' => [
                    'method' => 'POST', 'url' => self::OPENAI_URL,
                    'authentication' => 'predefinedCredentialType', 'nodeCredentialType' => 'openAiApi',
                    'sendBody' => true, 'specifyBody' => 'json', 'jsonBody' => '={{ JSON.stringify($json.ultron_request.body) }}',
                    'options' => [
                        'timeout' => StudioLabEngineCase::CALL_TIMEOUT_SECONDS * 1000,
                        'redirect' => ['redirect' => ['followRedirects' => false]],
                        'response' => ['response' => ['responseFormat' => 'json']],
                    ],
                ],
                'id' => self::nodeId('http', $name), 'name' => $http,
                'type' => 'n8n-nodes-base.httpRequest', 'typeVersion' => 4.4,
                'position' => [$position[0] - 160, $position[1]],
                'credentials' => $node['credentials'], 'retryOnFail' => false,
            ];
            // Preserve the original name for every downstream $ reference.
            $nodes[] = [
                'parameters' => ['jsCode' => self::adapterCode($prepare)],
                'id' => $node['id'], 'name' => $name,
                'type' => 'n8n-nodes-base.code', 'typeVersion' => 2, 'position' => $position,
            ];
        }
        $flow['nodes'] = $nodes;
        foreach ($flow['connections'] as &$ports) {
            if (! isset($ports['main'])) {
                continue;
            }
            foreach ($ports['main'] as &$edges) {
                foreach ($edges as &$edge) {
                    if (isset($replacement[$edge['node']])) {
                        $edge['node'] = $replacement[$edge['node']];
                    }
                }
                unset($edge);
            }
            unset($edges);
        }
        unset($ports);
        foreach ($replacement as $name => $prepare) {
            $flow['connections'][$prepare] = ['main' => [[['node' => self::httpName($name), 'type' => 'main', 'index' => 0]]]];
            $flow['connections'][self::httpName($name)] = ['main' => [[['node' => $name, 'type' => 'main', 'index' => 0]]]];
        }
        $flow['_generado'] = [
            'generator' => self::VERSION, 'flowRev' => self::REV, 'compiler' => 9,
            'generationContract' => UltronListTurnContract::CONTRACT,
            'transport' => StudioLabEngineTransport::VERSION,
            'sourceSemanticHash' => self::semanticHash($source),
            'note' => 'Artefacto local e inactivo. Requiere comparación con un export local antes de integrar; no certifica ningún runtime remoto.',
        ];

        return $flow;
    }

    public static function prepareName(string $node): string
    {
        return 'Preparar Responses · '.$node;
    }

    public static function httpName(string $node): string
    {
        return 'Responses API · '.$node;
    }

    public static function criticGateExpression(): string
    {
        return '={{ (() => { const trusted = $("Pedir Contexto al CRM").first().json; '
            .'const listTurn = '.UltronListTurnContract::javascript().'(trusted.context || {}) !== null; '
            .'let check = null; try { check = $("Comprobar Borrador 1").first().json; } catch (_) {} '
            .'return '.UltronListCriticGate::javascript().'($json.output[0].content[0].text, listTurn, check) ? "pass" : "fail"; })() }}';
    }

    /** Safe diagnostics: names and categories, never values, prompts or credentials. */
    public static function differences(array $candidate, array $expected): array
    {
        $left = self::semantic($candidate);
        $right = self::semantic($expected);
        $differences = [];
        foreach (array_unique([...array_keys($left['nodes']), ...array_keys($right['nodes'])]) as $name) {
            if (! isset($left['nodes'][$name]) || ! isset($right['nodes'][$name]) || $left['nodes'][$name] !== $right['nodes'][$name]) {
                $differences[] = 'node:'.$name;
            }
        }
        if ($left['connections'] !== $right['connections']) {
            $differences[] = 'connections';
        }
        if ($left['settings'] !== $right['settings']) {
            $differences[] = 'settings';
        }

        return $differences;
    }

    /** A pre-integration export must remain inactive and unavailable to MCP. */
    public static function integrationDifferences(array $candidate, array $expected): array
    {
        return [
            ...self::differences($candidate, $expected),
            ...(($candidate['active'] ?? null) === false ? [] : ['active']),
            ...(($candidate['settings']['availableInMCP'] ?? null) === false ? [] : ['settings.availableInMCP']),
            ...(array_diff(array_keys($candidate), self::EXPORT_KEYS) === [] ? [] : ['export_metadata']),
        ];
    }

    public static function semanticHash(array $flow): string
    {
        return hash('sha256', self::json(self::semantic($flow)));
    }

    private static function prepareCode(string $node, array $source): string
    {
        $spec = StudioLabEngineFlow::NODES[$node];
        $expression = $source['parameters']['responses']['values'][1]['content'] ?? '';
        if (! is_string($expression) || ! str_starts_with($expression, '={{ ') || ! str_ends_with($expression, ' }}')) {
            throw new InvalidArgumentException('La expresión del contexto S2 no tiene la forma esperada.');
        }
        $expression = substr($expression, 4, -3);
        $values = array_replace($spec, ['schema' => StudioLabEngineFlow::schemaOf($node)]);
        $critic = in_array($node, StudioLabEngineFlow::CRITICS, true);
        $checkName = $node === StudioLabEngineFlow::CRITIC ? StudioLabEngineFlow::READ_1 : StudioLabEngineFlow::READ_2;
        $js = 'const $json = $input.first().json;'."\n"
            .'const payload = JSON.parse('.$expression.');'."\n"
            .'const trusted = $("Pedir Contexto al CRM").first().json;'."\n"
            .'const contract = '.UltronListTurnContract::javascript().'(trusted.context || {});'."\n"
            .'if (contract !== null) {'."\n"
            .'payload.commercial_turn_policy = trusted.context.commercial_turn_policy;'."\n";
        if ($critic) {
            $js .= 'let checked = null; try { checked = $('.self::json($checkName).').first().json.ultron_check; } catch (_) {}'."\n"
                .'payload.backend_list_check = checked && typeof checked === "object" ? {'
                .'checked: checked.error === false, codes: checked.codes || [], '
                .'reason: checked.may_rewrite === false && Array.isArray(checked.codes) && checked.codes.length === 0 ? "list_final" : null'
                .'} : {checked:false,codes:[],reason:null};'."\n";
        }
        $js .= '}'."\n"
            .'const packed = $("Elegir Prompts").first().json.ultron_prompts.roles.'.$spec['role'].';'."\n"
            .'const instructions = '.UltronListTurnPrompts::javascript().'('.self::json($spec['role']).', packed, contract !== null);'."\n"
            .'const values = '.self::json($values).';'."\n"
            .'values.system = instructions; values.user = JSON.stringify(payload);'."\n"
            .'const profile = contract !== null ? '.self::json(StudioLabEngineTransport::LIST_TURN).' : '.self::json(StudioLabEngineTransport::LEGACY).';'."\n";
        if (in_array($spec['role'], ['composer', 'composer_retry'], true)) {
            $js .= 'if (contract !== null) values.schema.properties.reply_draft.pattern = '.self::json(StudioLabEngineTransport::REPLY_PATTERN).';'."\n";
        }

        return $js.'const body = '.StudioLabEngineTransport::javascript().'(profile, values);'."\n"
            .'return [{json:{ultron_request:{profile,body}}}];';
    }

    private static function adapterCode(string $prepare): string
    {
        return 'const response = $input.first().json;'."\n"
            .'if (!response || response.status !== "completed" || response.error) throw new Error("Responses API no completó una salida válida.");'."\n"
            .'const texts = []; for (const item of response.output || []) { if (item.type !== "message") continue; for (const part of item.content || []) {'
            .'if (part.type === "refusal") throw new Error("Responses API rechazó la salida."); '
            .'if (part.type === "output_text" && typeof part.text === "string") texts.push(part.text); }}'."\n"
            .'if (texts.length === 0) throw new Error("Responses API no entregó texto JSON.");'."\n"
            .'let output; try { output = JSON.parse(texts.join("")); } catch (_) { throw new Error("Responses API no entregó JSON válido."); }'."\n"
            .'const schema = $('.self::json($prepare).').first().json.ultron_request.body.text.format.schema;'."\n"
            .self::schemaValidatorJavascript()."\n"
            .'if (!conforms(schema,output)) throw new Error("Responses API no cumplió el esquema.");'."\n"
            .'const result = {output:[{content:[{text:output}]}]}; if (response.usage) result.usage=response.usage;'."\n"
            .'return [{json:result}];';
    }

    private static function schemaValidatorJavascript(): string
    {
        return <<<'JS'
function conforms(schema,value) {
  if (schema.enum && !schema.enum.some(item => item === value)) return false;
  const types=Array.isArray(schema.type)?schema.type:[schema.type];
  const object=value!==null&&typeof value==='object'&&!Array.isArray(value);
  if (!types.some(type=>({object:object,array:Array.isArray(value),string:typeof value==='string',integer:Number.isSafeInteger(value),number:typeof value==='number'&&Number.isFinite(value),boolean:typeof value==='boolean',null:value===null})[type])) return false;
  if (typeof value==='string'&&schema.pattern&&!new RegExp(schema.pattern,'u').test(value)) return false;
  if (object&&types.includes('object')) {
    if ((schema.required||[]).some(key=>!Object.prototype.hasOwnProperty.call(value,key))) return false;
    if (schema.additionalProperties===false&&Object.keys(value).some(key=>!Object.prototype.hasOwnProperty.call(schema.properties||{},key))) return false;
    for (const [key,sub] of Object.entries(schema.properties||{})) if (Object.prototype.hasOwnProperty.call(value,key)&&!conforms(sub,value[key])) return false;
  }
  return !Array.isArray(value)||!schema.items||value.every(item=>conforms(schema.items,item));
}
JS;
    }

    private static function semantic(array $flow): array
    {
        $nodes = [];
        foreach ($flow['nodes'] ?? [] as $node) {
            if (! is_array($node) || ! is_string($node['name'] ?? null) || isset($nodes[$node['name']])) {
                throw new InvalidArgumentException('El workflow contiene nodos inválidos o repetidos.');
            }
            unset($node['id'], $node['position']);
            $nodes[$node['name']] = self::canonical($node);
        }
        ksort($nodes);

        return ['nodes' => $nodes, 'connections' => self::canonical($flow['connections'] ?? []), 'settings' => self::canonical($flow['settings'] ?? [])];
    }

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::canonical(...), $value);
    }

    private static function nodeId(string $kind, string $name): string
    {
        $hash = hash('sha256', self::VERSION.':'.$kind.':'.$name);

        return substr($hash, 0, 8).'-'.substr($hash, 8, 4).'-4'.substr($hash, 13, 3).'-a'.substr($hash, 17, 3).'-'.substr($hash, 20, 12);
    }

    private static function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
