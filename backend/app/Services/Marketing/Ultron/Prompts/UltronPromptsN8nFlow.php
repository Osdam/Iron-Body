<?php

namespace App\Services\Marketing\Ultron\Prompts;

use RuntimeException;

/**
 * EL CAMBIO EN n8n «S1», GENERADO (Enmienda M-1 del H5; plan de motores §B.7).
 *
 * A partir del respaldo del flujo vivo (`Iron Body - ULTRON - Commercial
 * Advisor`, versión `29de5119-…`) y de la base del repositorio, construye todo
 * lo que hace falta para que n8n use los prompts que ofrece `decide`, sin que
 * nadie escriba a mano ni una línea:
 *
 *  1. El nodo nuevo «Elegir Prompts» (Code v2, JavaScript, una vez para todos
 *     los ítems), entre `Mensaje superado?` (salida falsa) y `Strategist`, con
 *     las constantes LEGACY = la base byte a byte y un SHA-256 en JavaScript
 *     puro. Verifica la INTEGRIDAD de lo ofrecido (forma, tamaños y huella) y,
 *     si algo no cuadra o lanza, usa Legacy. Devuelve la respuesta de `decide`
 *     tal cual (sin `studio_prompts`) más `ultron_prompts`.
 *  2. El contenido de sistema de los cinco nodos de OpenAI, que pasa a ser una
 *     referencia a `ultron_prompts.roles.<rol>` (`values[1]`, modelo, opciones
 *     y reintentos sin tocar).
 *  3. El cuerpo de `Pedir Contexto al CRM`, que negocia `prompt_contract`.
 *  4. `Preparar Commit`, que añade el bloque `prompts` SOLO si `decide`
 *     devolvió `studio_prompts.contract` (así sigue funcionando con un backend
 *     devuelto al código de hoy), dentro de un `try` (si falla, sin bloque).
 *
 * Nada más cambia: ni `Preparar Rendicion`, ni `Rendicion Minima`, ni los IF,
 * ni los `Responder:*`, ni el webhook, ni el flujo de errores.
 *
 * PURA: no lee ficheros (salvo la base, por {@see UltronPromptBase}), no usa
 * red ni reloj; las mismas entradas dan los mismos bytes. La orden
 * `ultron:prompts-n8n` hace la E/S; el comprobador offline
 * (`docs/ultron-studio/ops/motores/verificar-flujo.cjs`) lo verifica.
 */
final class UltronPromptsN8nFlow
{
    /** La revisión del flujo que se observa en el sello (`observed.flowRev`). */
    public const FLOW_REV = 'S1';

    /** El flujo de producción y la versión de la que parte S1. */
    public const WORKFLOW_ID = 'YspRwsXnqt33NouP';

    public const SOURCE_VERSION = '29de5119-2e5a-4bc8-a479-60f4fe37c5b3';

    /** Nodos que intervienen. */
    public const SELECTOR = 'Elegir Prompts';

    public const GATE = 'Mensaje superado?';

    public const DECIDE = 'Pedir Contexto al CRM';

    public const COMMIT = 'Preparar Commit';

    /** El id del nodo nuevo: fijo, para que el generador sea determinista. */
    public const SELECTOR_ID = '5e1ec7a0-0001-4c0d-9a5e-000000000051';

    /** Donde se pinta el nodo nuevo en el lienzo (entre la puerta y el Strategist). */
    public const SELECTOR_POSITION = [-560, 144];

    /** El cuerpo de `decide`, antes y después. */
    public const DECIDE_BODY_BEFORE = '={{ JSON.stringify({ conversation_id: $json.conversation_id, message_id: $json.message_id }) }}';

    public const DECIDE_BODY = '={{ JSON.stringify({ conversation_id: $json.conversation_id, message_id: $json.message_id, prompt_contract: "ultron-prompts/1" }) }}';

    /** Número de nodos del respaldo 29de5119. */
    public const SOURCE_NODES = 24;

    /** Tope de un rol en el selector, en caracteres JS (PHP limita en bytes: es más estricto). */
    public const MAX_ROLE_CHARS = 40960;

    // Las dos anclas del cambio en `Preparar Commit` (cada una aparece una vez en 29de5119).
    private const COMMIT_RETURN = "return [{ json: {\n";

    private const COMMIT_END = "\n} }];";

    /**
     * Decodifica un export de n8n SIN perder los objetos vacíos: `{}` sigue
     * siendo `{}` al volver a codificar (con `json_decode(…, true)` saldría
     * `[]`, y n8n y el comprobador lo verían como otro parámetro). Los demás
     * objetos pasan a arrays asociativos y las listas siguen siendo listas.
     *
     * @return array<string,mixed>
     *
     * @throws \JsonException
     */
    public static function decode(string $json): array
    {
        $convierte = static function (mixed $v) use (&$convierte): mixed {
            if ($v instanceof \stdClass) {
                $propiedades = get_object_vars($v);

                return $propiedades === [] ? $v : array_map($convierte, $propiedades);
            }

            return is_array($v) ? array_map($convierte, $v) : $v;
        };
        $valor = $convierte(json_decode($json, false, 512, JSON_THROW_ON_ERROR));
        if (! is_array($valor)) {
            throw new RuntimeException('El export no es un objeto JSON.');
        }

        return $valor;
    }

    /** El contenido de sistema de un nodo de OpenAI en S1: una referencia, no un literal. */
    public static function systemExpression(string $role): string
    {
        if (! in_array($role, UltronPromptBase::ROLES, true)) {
            throw new RuntimeException("Rol desconocido: {$role}");
        }

        return "={{ \$('".self::SELECTOR."').first().json.ultron_prompts.roles.{$role} }}";
    }

    /**
     * El código del nodo «Elegir Prompts», con la base embebida.
     *
     * @throws UltronPromptException si la base no se puede cargar
     */
    public static function selectorCode(): string
    {
        $roles = UltronPromptBase::roles();
        $legacy = [];
        foreach (UltronPromptBase::ROLES as $rol) {
            $legacy[] = '  '.$rol.': '.self::js($roles[$rol]);
        }

        return strtr(self::SELECTOR_TEMPLATE, [
            '%%CONTRACT%%' => self::js(UltronPromptBase::CONTRACT),
            '%%FLOW_REV%%' => self::js(self::FLOW_REV),
            '%%ROLES%%' => self::js(UltronPromptBase::ROLES),
            '%%LEGACY%%' => implode(",\n", $legacy),
            '%%LEGACY_HASH%%' => self::js(UltronPromptBase::LEGACY_BUNDLE_HASH),
            '%%MAX_ROLE%%' => (string) self::MAX_ROLE_CHARS,
            '%%BASE%%' => UltronPromptBase::ID,
        ]);
    }

    /**
     * `Preparar Commit` con el bloque `prompts`: se inserta antes del `return`
     * y el objeto devuelto gana la clave solo si el bloque existe. Nada más.
     *
     * @throws RuntimeException si el código de partida no tiene las anclas
     */
    public static function commitCode(string $before): string
    {
        if (substr_count($before, self::COMMIT_RETURN) !== 1 || substr_count($before, self::COMMIT_END) !== 1 || ! str_ends_with($before, self::COMMIT_END)) {
            throw new RuntimeException('Preparar Commit no es el de 29de5119: no se encuentran sus anclas.');
        }

        $bloque = <<<'JS'
/*
 * ULTRON STUDIO S1 (Enmienda M-1): lo que este flujo dice haber aplicado.
 * Solo si decide ofrecio prompts con el contrato que conocemos (un backend
 * sin la oferta no recibe un bloque que no entenderia). Es traza: si algo
 * falla aqui, el commit sale sin bloque, nunca sin respuesta.
 */
let promptsAplicados = null;
try {
  const sp = ctx.studio_prompts;
  if (sp && sp.contract === "ultron-prompts/1") {
    const up = $("Elegir Prompts").first().json.ultron_prompts;
    promptsAplicados = {
      mode: up.mode,
      bundle_hash: up.bundle_hash,
      reason: up.reason,
      flow_rev: up.flow_rev,
      execution_id: String($execution.id)
    };
  }
} catch (e) { promptsAplicados = null; }

JS;

        $despues = str_replace(self::COMMIT_RETURN, $bloque."\nreturn [{ json: Object.assign({\n", $before);

        return substr($despues, 0, -strlen(self::COMMIT_END))."\n}, promptsAplicados ? { prompts: promptsAplicados } : {}) }];";
    }

    /**
     * Todo S1 a partir del respaldo 29de5119.
     *
     * @param  array<string,mixed>  $backup  el export del flujo (nodes, connections, …)
     * @return array{flow:array<string,mixed>,operations:list<array<string,mixed>>,hashes:array<string,mixed>,selector:string,commit:string}
     *
     * @throws RuntimeException si el respaldo no es el de 29de5119 con la base del repositorio
     * @throws UltronPromptException si la base no se puede cargar
     */
    public static function build(array $backup): array
    {
        $nodos = self::nodesByName($backup);
        self::assertSource($backup, $nodos);

        $selector = self::selectorCode();
        $commit = self::commitCode((string) $nodos[self::COMMIT]['parameters']['jsCode']);

        $nodoSelector = [
            'parameters' => ['jsCode' => $selector],
            'id' => self::SELECTOR_ID,
            'name' => self::SELECTOR,
            'type' => 'n8n-nodes-base.code',
            'typeVersion' => 2,
            'position' => self::SELECTOR_POSITION,
        ];

        $operaciones = [
            ['type' => 'addNode', 'node' => $nodoSelector],
            ['type' => 'removeConnection', 'source' => self::GATE, 'sourceOutput' => 1, 'target' => UltronPromptBase::NODES['strategist'], 'targetInput' => 0],
            ['type' => 'addConnection', 'source' => self::GATE, 'sourceOutput' => 1, 'target' => self::SELECTOR, 'targetInput' => 0],
            ['type' => 'addConnection', 'source' => self::SELECTOR, 'sourceOutput' => 0, 'target' => UltronPromptBase::NODES['strategist'], 'targetInput' => 0],
        ];

        $flujo = $backup;
        $huellasUser = [];
        foreach ($flujo['nodes'] as $i => $nodo) {
            $nombre = $nodo['name'];
            $rol = array_search($nombre, UltronPromptBase::NODES, true);
            if ($rol !== false) {
                $respuestas = $nodo['parameters']['responses'];
                $respuestas['values'][0]['content'] = self::systemExpression($rol);
                $flujo['nodes'][$i]['parameters']['responses'] = $respuestas;
                // `setNodeParameter` no indexa arrays: se reescribe `/responses` entero, con `values[1]` intacto.
                $operaciones[] = ['type' => 'setNodeParameter', 'nodeName' => $nombre, 'path' => '/responses', 'value' => $respuestas];
                $huellasUser[$nombre] = hash('sha256', (string) $respuestas['values'][1]['content']);
            } elseif ($nombre === self::DECIDE) {
                $flujo['nodes'][$i]['parameters']['jsonBody'] = self::DECIDE_BODY;
                $operaciones[] = ['type' => 'setNodeParameter', 'nodeName' => $nombre, 'path' => '/jsonBody', 'value' => self::DECIDE_BODY];
            } elseif ($nombre === self::COMMIT) {
                $flujo['nodes'][$i]['parameters']['jsCode'] = $commit;
                $operaciones[] = ['type' => 'setNodeParameter', 'nodeName' => $nombre, 'path' => '/jsCode', 'value' => $commit];
            }
        }
        $flujo['nodes'][] = $nodoSelector;

        $salidas = $flujo['connections'][self::GATE]['main'];
        $salidas[1] = [['node' => self::SELECTOR, 'type' => 'main', 'index' => 0]];
        $flujo['connections'][self::GATE]['main'] = $salidas;
        $flujo['connections'][self::SELECTOR] = ['main' => [[['node' => UltronPromptBase::NODES['strategist'], 'type' => 'main', 'index' => 0]]]];
        $flujo['versionId'] = null;
        $flujo['_generado'] = ['from' => self::SOURCE_VERSION, 'flowRev' => self::FLOW_REV, 'base' => UltronPromptBase::ID, 'compiler' => UltronPromptCompiler::VERSION, 'nota' => 'Solo para el comprobador. NUNCA se importa en n8n.'];

        $huellas = [
            'flowRev' => self::FLOW_REV,
            'workflowId' => self::WORKFLOW_ID,
            'sourceVersionId' => self::SOURCE_VERSION,
            'contract' => UltronPromptBase::CONTRACT,
            'base' => UltronPromptBase::ID,
            'compiler' => UltronPromptCompiler::VERSION,
            'legacyBundleHash' => UltronPromptBase::LEGACY_BUNDLE_HASH,
            'baseSha256' => UltronPromptBase::SHA256,
            'baseBytes' => UltronPromptBase::BYTES,
            'nodes' => UltronPromptBase::NODES,
            'selector' => ['name' => self::SELECTOR, 'id' => self::SELECTOR_ID, 'jsCodeSha256' => hash('sha256', $selector), 'jsCodeBytes' => strlen($selector)],
            'preparaCommitJsCodeSha256' => hash('sha256', $commit),
            'decideJsonBody' => self::DECIDE_BODY,
            'systemContent' => array_combine(array_values(UltronPromptBase::NODES), array_map(self::systemExpression(...), UltronPromptBase::ROLES)),
            'userContentSha256' => $huellasUser,
        ];

        return ['flow' => $flujo, 'operations' => $operaciones, 'hashes' => $huellas, 'selector' => $selector, 'commit' => $commit];
    }

    /**
     * Las frases del fixture con reglas S-NNN (compilador 2): letras con tilde,
     * ñ, signos de apertura y un número, para que el SHA-256 en JS del selector
     * vea UTF-8 de 1, 2 y 3 bytes dentro del parche. Sintéticas, por orden de
     * bytes como las firma el Studio.
     */
    public const FIXTURE_PHRASES = ['2x1', 'gratis', 'te lo regalo', '¿qué esperas?', 'ñapa', 'último día'];

    /**
     * Los fixtures del comprobador: una respuesta de `decide` con `studio`
     * (compilada aquí, en PHP: el comprobador exige que el SHA-256 del selector
     * dé la misma huella, E3), otra `studio` con las frases de unas reglas
     * S-NNN (compilador 2; el selector tiene que aceptarla igual) y una con
     * `legacy`.
     *
     * @return array{studio:array<string,mixed>,studioPhrases:array<string,mixed>,legacy:array<string,mixed>}
     *
     * @throws UltronPromptException
     */
    public static function fixtures(): array
    {
        $paquete = UltronPromptCompiler::compile(array_replace(UltronPromptCompiler::BASELINE, [
            UltronPromptCompiler::TONE => 'profesional',
            UltronPromptCompiler::MAX_CHARS => 200,
        ]));
        $conFrases = UltronPromptCompiler::compile(array_replace(UltronPromptCompiler::BASELINE, [
            UltronPromptCompiler::TONE => 'profesional',
            UltronPromptCompiler::MAX_CHARS => 200,
            UltronPromptCompiler::PHRASES => self::FIXTURE_PHRASES,
        ]));
        $comun = [
            'ok' => true,
            'commercial_phase' => 'NEW_LEAD',
            'allowed_transitions' => ['NEW_LEAD', 'GOAL_DISCOVERY'],
            'allowed_tools' => ['staff_review'],
            'decide_token' => 'sintetico.no-firmado',
            'superseded' => false,
        ];

        return [
            'studio' => $comun + ['studio_prompts' => [
                'contract' => UltronPromptBase::CONTRACT, 'mode' => 'studio', 'reason' => null,
                'base' => UltronPromptBase::ID, 'compiler' => UltronPromptCompiler::VERSION,
                'bundle_hash' => $paquete['bundleHash'],
                'version' => 'f0000000-0000-4000-8000-0000000000f1', 'content_hash' => str_repeat('c', 64),
                'entry' => 'e0000000-0000-4000-8000-0000000000e1', 'seq' => 7,
                'roles' => $paquete['roles'],
            ]],
            'studioPhrases' => $comun + ['studio_prompts' => [
                'contract' => UltronPromptBase::CONTRACT, 'mode' => 'studio', 'reason' => null,
                'base' => UltronPromptBase::ID, 'compiler' => UltronPromptCompiler::VERSION,
                'bundle_hash' => $conFrases['bundleHash'],
                'version' => 'f0000000-0000-4000-8000-0000000000f2', 'content_hash' => str_repeat('d', 64),
                'entry' => 'e0000000-0000-4000-8000-0000000000e2', 'seq' => 8,
                'roles' => $conFrases['roles'],
            ]],
            'legacy' => $comun + ['studio_prompts' => [
                'contract' => UltronPromptBase::CONTRACT, 'mode' => 'legacy', 'reason' => 'prompts_off',
                'base' => UltronPromptBase::ID, 'compiler' => UltronPromptCompiler::VERSION,
                'bundle_hash' => null, 'version' => null, 'content_hash' => null, 'entry' => null, 'seq' => null, 'roles' => null,
            ]],
        ];
    }

    // ── Piezas ──────────────────────────────────────────────────────────────

    /**
     * @param  array<string,mixed>  $flujo
     * @return array<string,array<string,mixed>>
     */
    private static function nodesByName(array $flujo): array
    {
        if (! isset($flujo['nodes'], $flujo['connections']) || ! is_array($flujo['nodes']) || ! is_array($flujo['connections'])) {
            throw new RuntimeException('El respaldo no tiene nodes y connections en el primer nivel.');
        }
        $out = [];
        foreach ($flujo['nodes'] as $nodo) {
            $out[(string) ($nodo['name'] ?? '')] = $nodo;
        }

        return $out;
    }

    /**
     * El respaldo tiene que ser 29de5119 y llevar la base del repositorio en
     * sus cinco nodos; si no, S1 no se puede generar desde él.
     *
     * @param  array<string,mixed>  $backup
     * @param  array<string,array<string,mixed>>  $nodos
     */
    private static function assertSource(array $backup, array $nodos): void
    {
        if (($backup['versionId'] ?? null) !== self::SOURCE_VERSION) {
            throw new RuntimeException('El respaldo no es la versión '.self::SOURCE_VERSION.'.');
        }
        if (count($backup['nodes']) !== self::SOURCE_NODES || count($nodos) !== self::SOURCE_NODES) {
            throw new RuntimeException('El respaldo no tiene los '.self::SOURCE_NODES.' nodos de 29de5119.');
        }
        if (isset($nodos[self::SELECTOR])) {
            throw new RuntimeException('El respaldo ya tiene «'.self::SELECTOR.'».');
        }
        $base = UltronPromptBase::roles();
        foreach (UltronPromptBase::NODES as $rol => $nombre) {
            $valores = $nodos[$nombre]['parameters']['responses']['values'] ?? null;
            if (! is_array($valores) || count($valores) !== 2 || ($valores[0]['content'] ?? null) !== $base[$rol]) {
                throw new RuntimeException("El prompt de sistema de {$nombre} no es la base {$rol} byte a byte.");
            }
        }
        if (($nodos[self::DECIDE]['parameters']['jsonBody'] ?? null) !== self::DECIDE_BODY_BEFORE) {
            throw new RuntimeException('El cuerpo de '.self::DECIDE.' no es el de 29de5119.');
        }
        $salida = $backup['connections'][self::GATE]['main'][1] ?? null;
        if ($salida !== [['node' => UltronPromptBase::NODES['strategist'], 'type' => 'main', 'index' => 0]]) {
            throw new RuntimeException('La salida falsa de '.self::GATE.' no va al Strategist.');
        }
    }

    /** Un literal de JavaScript (JSON es un subconjunto válido; U+2028/2029 van escapados por defecto). */
    private static function js(mixed $valor): string
    {
        return json_encode($valor, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * El selector (plan §B.7). SHA-256 en JavaScript puro sobre UTF-8, sin
     * `require('crypto')` (puede no estar permitido en el servidor de n8n).
     * Cualquier excepción → Legacy con `selector_error` y sin huella.
     */
    private const SELECTOR_TEMPLATE = <<<'JS'
/*
 * ELEGIR PROMPTS — ULTRON STUDIO S1 (Enmienda M-1 del H5; plan de motores §B.7).
 * GENERADO por `php artisan ultron:prompts-n8n` desde la base %%BASE%% del
 * repositorio. NO SE EDITA A MANO: un cambio aqui se ve como legacy_drift.
 *
 * Usa los cinco prompts que ofrece decide (studio_prompts) solo si la forma,
 * los tamanos y la huella cuadran; si no, los LEGACY (la base byte a byte).
 * Devuelve la respuesta de decide tal cual, sin studio_prompts y con
 * ultron_prompts = {mode, reason, bundle_hash, flow_rev, roles}.
 */
const CONTRACT = %%CONTRACT%%, FLOW_REV = %%FLOW_REV%%;
const ROLES = %%ROLES%%;
const LEGACY = {
%%LEGACY%%
};
const LEGACY_HASH = %%LEGACY_HASH%%; // informativo: la huella observada se CALCULA
const MAX_ROLE = %%MAX_ROLE%%;

function utf8(s) {
  const out = [];
  for (let i = 0; i < s.length; i++) {
    let c = s.charCodeAt(i);
    if (c >= 0xd800 && c <= 0xdbff && i + 1 < s.length) {
      const d = s.charCodeAt(i + 1);
      if (d >= 0xdc00 && d <= 0xdfff) { c = 0x10000 + ((c - 0xd800) << 10) + (d - 0xdc00); i++; }
      else c = 0xfffd;
    } else if (c >= 0xd800 && c <= 0xdfff) c = 0xfffd;
    if (c < 0x80) out.push(c);
    else if (c < 0x800) out.push(0xc0 | (c >> 6), 0x80 | (c & 63));
    else if (c < 0x10000) out.push(0xe0 | (c >> 12), 0x80 | ((c >> 6) & 63), 0x80 | (c & 63));
    else out.push(0xf0 | (c >> 18), 0x80 | ((c >> 12) & 63), 0x80 | ((c >> 6) & 63), 0x80 | (c & 63));
  }
  return out;
}

function sha256hex(s) {
  const K = [
    0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1, 0x923f82a4, 0xab1c5ed5,
    0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3, 0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174,
    0xe49b69c1, 0xefbe4786, 0x0fc19dc6, 0x240ca1cc, 0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da,
    0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7, 0xc6e00bf3, 0xd5a79147, 0x06ca6351, 0x14292967,
    0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13, 0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85,
    0xa2bfe8a1, 0xa81a664b, 0xc24b8b70, 0xc76c51a3, 0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070,
    0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a, 0x5b9cca4f, 0x682e6ff3,
    0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208, 0x90befffa, 0xa4506ceb, 0xbef9a3f7, 0xc67178f2
  ];
  const b = utf8(s);
  const n = b.length;
  const blocks = Math.ceil((n + 9) / 64);
  const w = new Array(blocks * 16).fill(0);
  for (let i = 0; i < n; i++) w[i >> 2] |= b[i] << (24 - (i % 4) * 8);
  w[n >> 2] |= 0x80 << (24 - (n % 4) * 8);
  w[blocks * 16 - 1] = (n * 8) >>> 0;
  w[blocks * 16 - 2] = Math.floor((n * 8) / 0x100000000);
  const H = [0x6a09e667, 0xbb67ae85, 0x3c6ef372, 0xa54ff53a, 0x510e527f, 0x9b05688c, 0x1f83d9ab, 0x5be0cd19];
  const W = new Array(64);
  const rot = (x, r) => (x >>> r) | (x << (32 - r));
  for (let k = 0; k < blocks; k++) {
    for (let t = 0; t < 64; t++) {
      if (t < 16) W[t] = w[k * 16 + t] | 0;
      else {
        const s0 = rot(W[t - 15], 7) ^ rot(W[t - 15], 18) ^ (W[t - 15] >>> 3);
        const s1 = rot(W[t - 2], 17) ^ rot(W[t - 2], 19) ^ (W[t - 2] >>> 10);
        W[t] = (W[t - 16] + s0 + W[t - 7] + s1) | 0;
      }
    }
    let a = H[0], c1 = H[1], c2 = H[2], d = H[3], e = H[4], f = H[5], g = H[6], h = H[7];
    for (let t = 0; t < 64; t++) {
      const t1 = (h + (rot(e, 6) ^ rot(e, 11) ^ rot(e, 25)) + ((e & f) ^ (~e & g)) + K[t] + W[t]) | 0;
      const t2 = ((rot(a, 2) ^ rot(a, 13) ^ rot(a, 22)) + ((a & c1) ^ (a & c2) ^ (c1 & c2))) | 0;
      h = g; g = f; f = e; e = (d + t1) | 0; d = c2; c2 = c1; c1 = a; a = (t1 + t2) | 0;
    }
    H[0] = (H[0] + a) | 0; H[1] = (H[1] + c1) | 0; H[2] = (H[2] + c2) | 0; H[3] = (H[3] + d) | 0;
    H[4] = (H[4] + e) | 0; H[5] = (H[5] + f) | 0; H[6] = (H[6] + g) | 0; H[7] = (H[7] + h) | 0;
  }
  return H.map(v => (v >>> 0).toString(16).padStart(8, "0")).join("");
}

const material = r => [CONTRACT].concat(...ROLES.map(k => [k, r[k]])).join("\n");

let ctx = {};
try { ctx = ($input.first() && $input.first().json) || {}; } catch (e) { ctx = {}; }

let mode = "legacy", reason = "absent", roles = LEGACY, observed = null;
try {
  const sp = ctx.studio_prompts;
  if (sp && sp.contract === CONTRACT) {
    if (sp.mode !== "studio") reason = "offered_" + String(sp.reason || "legacy").replace(/[^A-Za-z0-9_.:-]/g, "_").slice(0, 48);
    else if (!sp.roles || typeof sp.roles !== "object" || Array.isArray(sp.roles) || Object.keys(sp.roles).sort().join() !== [...ROLES].sort().join()) reason = "shape";
    else if (ROLES.some(k => typeof sp.roles[k] !== "string" || sp.roles[k].length < 1 || sp.roles[k].length > MAX_ROLE)) reason = "size";
    else {
      const h = sha256hex(material(sp.roles));
      if (h !== sp.bundle_hash) reason = "hash_mismatch";
      else { mode = "studio"; reason = null; roles = sp.roles; observed = h; }
    }
  } else if (sp) reason = "contract_unknown";
  if (mode === "legacy") observed = sha256hex(material(LEGACY));
} catch (e) { mode = "legacy"; roles = LEGACY; reason = "selector_error"; observed = null; }

const out = Object.assign({}, ctx);
delete out.studio_prompts;
out.ultron_prompts = { mode, reason, bundle_hash: observed, flow_rev: FLOW_REV, roles };
return [{ json: out }];
JS;
}
