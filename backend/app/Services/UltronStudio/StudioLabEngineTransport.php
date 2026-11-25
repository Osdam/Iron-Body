<?php

namespace App\Services\UltronStudio;

use App\Services\Marketing\Ultron\UltronListTurnContract;
use InvalidArgumentException;

/**
 * Los dos cuerpos de Responses API, en una única definición declarativa.
 *
 * La forma histórica queda intacta. El perfil nuevo solo entra cuando el
 * prompt de confianza declara el contrato inicial y el contexto trae su
 * contrato validado. El JSON de una persona, por sí solo, no lo activa.
 * `javascript()` materializa las mismas plantillas para el artefacto n8n;
 * no se presupone que ningún nodo OpenAI instalado mapee roles o `strict`.
 */
final class StudioLabEngineTransport
{
    public const VERSION = 'ultron-engine-transport/1';

    public const LEGACY = 'n8n-response-values/1';

    public const LIST_TURN = 'ultron-list-instructions/1';

    public const PROMPT_FIRST_LINE = 'ULTRON_LIST_TURN/1';

    /**
     * Sin lookarounds: una ranura literal; APP_LINKS sigue siendo admisible.
     * La guía oficial admite `pattern` entre las propiedades string, pero no
     * enumera `maxLength`: el presupuesto sigue siendo una regla local del
     * contrato, no una garantía inventada del proveedor.
     * https://developers.openai.com/api/docs/guides/structured-outputs#supported-schemas
     */
    public const REPLY_PATTERN = '^(?:[^{}]*\{\{LISTA_DE_PLANES\}\}[^{}]*(?:\{\{APP_LINKS\}\}[^{}]*)?|[^{}]*\{\{APP_LINKS\}\}[^{}]*\{\{LISTA_DE_PLANES\}\}[^{}]*)$';

    /**
     * Una referencia ocupa el objeto entero; nunca se sustituye texto dentro
     * de una instrucción, del contexto ni de un esquema.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function templates(): array
    {
        $value = static fn (string $name): array => ['$ultron_request_value' => $name];
        $message = static fn (string $name): array => ['role' => 'user', 'content' => [['type' => 'input_text', 'text' => $value($name)]]];
        $tail = [
            'temperature' => $value('temperature'),
            'max_output_tokens' => $value('maxTokens'),
            'store' => $value('store'),
            'text' => ['format' => ['type' => 'json_schema', 'name' => $value('schemaName'), 'schema' => $value('schema')]],
        ];
        $legacy = ['model' => $value('model'), 'input' => [$message('system'), $message('user')]] + $tail;
        $list = ['model' => $value('model'), 'instructions' => $value('system'), 'input' => [$message('user')]] + $tail;
        $list['text']['format']['strict'] = true;

        return [self::LEGACY => $legacy, self::LIST_TURN => $list];
    }

    /** @return array<string,mixed> */
    public static function artifactDefinition(): array
    {
        return [
            'version' => self::VERSION,
            'profiles' => ['legacy' => self::LEGACY, 'listTurn' => self::LIST_TURN],
            'promptFirstLine' => self::PROMPT_FIRST_LINE,
            'replyPattern' => self::REPLY_PATTERN,
            'templates' => self::templates(),
        ];
    }

    /**
     * @return array<string,mixed>
     *
     * @throws InvalidArgumentException un prompt especializado sin contrato válido
     */
    public static function body(string $node, string $system, string $user): array
    {
        $spec = StudioLabEngineFlow::NODES[$node] ?? throw new InvalidArgumentException('Nodo de OpenAI desconocido.');
        $profile = self::profile($system, $user);
        $schema = StudioLabEngineFlow::schemaOf($node);
        if ($profile === self::LIST_TURN && in_array($spec['role'], ['composer', 'composer_retry'], true)) {
            $schema['properties']['reply_draft']['pattern'] = self::REPLY_PATTERN;
        }

        return self::materialize(self::templates()[$profile], array_replace($spec, ['system' => $system, 'user' => $user, 'schema' => $schema]));
    }

    public static function profile(string $system, string $user): string
    {
        if (explode("\n", $system, 2)[0] !== self::PROMPT_FIRST_LINE) {
            return self::LEGACY;
        }
        $payload = json_decode($user, true);
        if (! is_array($payload) || UltronListTurnContract::fromPayload($payload) === null) {
            throw new InvalidArgumentException('El prompt especializado requiere el contrato inicial de la lista.');
        }

        return self::LIST_TURN;
    }

    /**
     * JavaScript del materializador, con las plantillas PHP embebidas. Quien
     * genera el artefacto selecciona el perfil con el mismo contrato; esta
     * función solo materializa y no adivina el perfil por el mensaje entrante.
     */
    public static function javascript(): string
    {
        $templates = json_encode(self::templates(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return '(function materializeUltronEngineRequest(profile, values) {'
            .'const templates = '.$templates.';'
            .'if (!Object.prototype.hasOwnProperty.call(templates, profile)) throw new Error("Perfil de transporte desconocido.");'
            .'const resolve = value => {'
            .'if (value === null || typeof value !== "object") return value;'
            .'if (Array.isArray(value)) return value.map(resolve);'
            .'const keys = Object.keys(value);'
            .'if (keys.length === 1 && keys[0] === "$ultron_request_value") {'
            .'const name = value.$ultron_request_value;'
            .'if (!Object.prototype.hasOwnProperty.call(values, name)) throw new Error("Falta un valor del transporte.");'
            .'return values[name];}'
            .'return Object.fromEntries(keys.map(key => [key, resolve(value[key])]));};'
            .'return resolve(templates[profile]);})';
    }

    private static function materialize(mixed $template, array $values): mixed
    {
        if (! is_array($template)) {
            return $template;
        }
        if (array_keys($template) === ['$ultron_request_value']) {
            $name = $template['$ultron_request_value'];
            if (! array_key_exists($name, $values)) {
                throw new InvalidArgumentException('Falta un valor del transporte.');
            }

            return $values[$name];
        }

        return array_map(static fn (mixed $value): mixed => self::materialize($value, $values), $template);
    }
}
