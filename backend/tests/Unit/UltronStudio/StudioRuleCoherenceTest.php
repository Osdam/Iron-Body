<?php

namespace Tests\Unit\UltronStudio;

use App\Models\UltronStudio\StudioRule;
use App\Services\UltronStudio\StudioCatalog;
use App\Services\UltronStudio\StudioDraftNormalizer;
use App\Services\UltronStudio\StudioRuleCoherence as R;
use Illuminate\Validation\ValidationException;
use ReflectionClass;
use Tests\TestCase;

/**
 * La coherencia de las reglas del Studio (contrato G §A3), contra el catálogo
 * REAL salvo donde se simula un cambio de catálogo.
 *
 * LO QUE SE PROTEGE. Que una regla solo se guarde en su forma canónica y con
 * un 422 que lleve todas las claves (forma, valores, repeticiones, lo
 * imposible, lo que nunca se aplica, la autocontradicción, la contradicción
 * con un control del motor y las capas); que la contradicción entre reglas sea
 * la del contrato (por pares y por variable, con su limitación declarada); que
 * el análisis diga lo mismo que el CRM en `id`, `level`, `with` y `target`; y
 * que la evaluación de un contenido dé los hallazgos literales, en su orden y
 * como AVISO (decisión 1 del lead). Hereda de Tests\TestCase porque
 * ValidationException necesita el validador de Laravel.
 */
class StudioRuleCoherenceTest extends TestCase
{
    // ── Ayudas ──────────────────────────────────────────────────────────────

    private static function catalogo(): array
    {
        return StudioCatalog::toArray();
    }

    /** Un predicado o afirmación de configuración (`$kind` null = del «si»). */
    private static function cfg(string $capaClave, string $op, mixed $valor, ?string $kind = null): array
    {
        [$capa, $clave] = explode('.', $capaClave);
        $p = ['target' => 'config', 'layer' => $capa, 'key' => $clave, 'op' => $op];
        $p += in_array($op, ['in', 'not_in'], true) ? ['values' => $valor] : ['value' => $valor];

        return $kind === null ? $p : ['kind' => $kind] + $p;
    }

    private static function edge(string $id, string $op, ?string $kind = null): array
    {
        $p = ['target' => 'edge', 'edge' => $id, 'op' => $op];

        return $kind === null ? $p : ['kind' => $kind] + $p;
    }

    private static function text(string $kind, array $frases): array
    {
        return ['kind' => $kind, 'target' => 'text', 'op' => 'contains', 'phrases' => $frases];
    }

    private static function cond(array $then, array $when = []): array
    {
        return ['when' => $when, 'then' => $then];
    }

    /** Un RuleInput. */
    private static function regla(string $codigo, ?array $condiciones, array $extra = []): array
    {
        $codigoDelCodigo = str_starts_with($codigo, 'R-');

        return $extra + [
            'code' => $codigo,
            'source' => $codigoDelCodigo ? 'legacy_baseline' : 'studio',
            'enforcement' => $codigoDelCodigo ? 'code' : 'none',
            'status' => $codigoDelCodigo ? 'active' : 'draft',
            'description' => "Descripción de {$codigo}.",
            'layers' => StudioCatalog::layerIds(),
            'conditions' => $condiciones,
            'refs' => ['configs' => [], 'edges' => [], 'knowledgeKeys' => [], 'knowledgeCategories' => []],
        ];
    }

    /** Las doce reglas del código como RuleInput. */
    private static function codigo(): array
    {
        return array_map(static fn (array $r): array => self::regla($r['code'], $r['conditions'], [
            'description' => $r['description'], 'layers' => $r['layers'], 'refs' => $r['refs'],
        ]), StudioCatalog::legacyRules());
    }

    /** Las claves del 422, ordenadas (son un mapa). */
    private static function claves(mixed $condiciones, ?array $capas = null, ?array $catalogo = null): array
    {
        $claves = array_keys(R::check($condiciones, $catalogo ?? self::catalogo(), $capas)['errors']);
        sort($claves, SORT_STRING);

        return $claves;
    }

    private static function errores(mixed $condiciones, ?array $capas = null): array
    {
        return R::check($condiciones, self::catalogo(), $capas)['errors'];
    }

    /** analysis.issues de una regla dentro de un conjunto. */
    private static function problemas(string $codigo, array $reglas, ?array $catalogo = null): array
    {
        return R::analyzeAll($reglas, $catalogo ?? self::catalogo())[$codigo]['issues'];
    }

    private static function contenido(array $cambios = [], array $aristas = []): array
    {
        $base = StudioCatalog::baseline();
        $configs = $base['configs'];
        foreach ($cambios as $capaClave => $valor) {
            [$capa, $clave] = explode('.', $capaClave);
            $configs[$capa][$clave] = $valor;
        }

        return [['layers' => StudioCatalog::layerIds(), 'edgeIds' => [...$base['edgeIds'], ...$aristas]], $configs];
    }

    // ── Forma canónica ──────────────────────────────────────────────────────

    public function test_null_is_a_documentary_rule_and_the_canonical_form_is_returned(): void
    {
        $this->assertNull(R::canonicalize(null, self::catalogo()));

        $limpio = R::canonicalize([
            'then' => [
                ['value' => 300, 'op' => 'lte', 'key' => 'maxChars', 'layer' => 'persona', 'target' => 'config', 'kind' => 'limit'],
                ['phrases' => ['  Precio   ESPECIAL ', 'gratis', 'GRATIS', "2x1\u{00A0}\u{00A0}hoy"], 'op' => 'contains', 'target' => 'text', 'kind' => 'forbid'],
            ],
            'when' => [
                ['values' => ['entusiasta', 'cercano', 'entusiasta'], 'op' => 'in', 'key' => 'tone', 'layer' => 'persona', 'target' => 'config'],
                ['value' => "\u{3000} Plan insignia (is_recommended)\t", 'op' => 'contains', 'key' => 'offerPriority', 'layer' => 'strategy', 'target' => 'config'],
            ],
        ], self::catalogo());

        // Claves en orden fijo; `values` en el orden del catálogo y sin repetidos;
        // etiquetas recortadas; frases recortadas, colapsadas, en minúsculas y sin repetidas.
        $this->assertSame([
            'when' => [
                ['target' => 'config', 'layer' => 'persona', 'key' => 'tone', 'op' => 'in', 'values' => ['cercano', 'entusiasta']],
                ['target' => 'config', 'layer' => 'strategy', 'key' => 'offerPriority', 'op' => 'contains', 'value' => 'Plan insignia (is_recommended)'],
            ],
            'then' => [
                ['kind' => 'limit', 'target' => 'config', 'layer' => 'persona', 'key' => 'maxChars', 'op' => 'lte', 'value' => 300],
                ['kind' => 'forbid', 'target' => 'text', 'op' => 'contains', 'phrases' => ['precio especial', 'gratis', '2x1 hoy']],
            ],
        ], $limpio);
        $this->assertSame(['conditions' => $limpio, 'errors' => []], R::check($limpio, self::catalogo()), 'la forma canónica es un punto fijo');
    }

    public function test_phrases_lowercase_only_a_to_z_and_the_spanish_capitals(): void
    {
        $limpio = R::canonicalize(self::cond([self::text('forbid', ['ÁÉÍÓÚÜÑ ABC', 'ÇÀ Øk', 'ÉXITO'])]), self::catalogo());

        $this->assertSame(['áéíóúüñ abc', 'ÇÀ Øk', 'éxito'], $limpio['then'][0]['phrases']);
    }

    public function test_the_trim_set_is_the_draft_normalizers_and_the_constants_are_the_models(): void
    {
        $leer = static fn (string $clase): string => (string) (new ReflectionClass($clase))->getConstant('JS_TRIM');

        $this->assertSame($leer(StudioDraftNormalizer::class), $leer(R::class));
        $this->assertSame(StudioDraftNormalizer::MAX_TAG_LENGTH, R::MAX_TAG_LENGTH);
        $this->assertSame(StudioRule::SOURCE_STUDIO, R::SOURCE_STUDIO);
        $this->assertSame(StudioRule::STATUS_ARCHIVED, R::STATUS_ARCHIVED);
    }

    public function test_each_type_with_each_op_has_its_allowed_set(): void
    {
        $conjuntos = static fn (array $afirmacion): array => R::allowedSets(self::cond([$afirmacion]), self::catalogo());
        $rejilla = range(160, 480, 20);

        $this->assertSame([['target' => 'config:memory.useLeadProfile', 'allowed' => [false], 'domain' => [true, false]]], $conjuntos(self::cfg('memory.useLeadProfile', 'eq', false, 'require')));
        $this->assertSame(['profesional'], $conjuntos(self::cfg('persona.tone', 'eq', 'profesional', 'require'))[0]['allowed']);
        $this->assertSame(['cercano', 'entusiasta'], $conjuntos(self::cfg('persona.tone', 'in', ['entusiasta', 'cercano'], 'require'))[0]['allowed']);
        $this->assertSame(['profesional'], $conjuntos(self::cfg('persona.tone', 'not_in', ['entusiasta', 'cercano'], 'require'))[0]['allowed']);
        $this->assertSame([300], $conjuntos(self::cfg('persona.maxChars', 'eq', 300, 'require'))[0]['allowed']);
        $this->assertSame(range(160, 300, 20), $conjuntos(self::cfg('persona.maxChars', 'lte', 310, 'limit'))[0]['allowed']);
        $this->assertSame(range(320, 480, 20), $conjuntos(self::cfg('persona.maxChars', 'gte', 310, 'limit'))[0]['allowed']);
        $this->assertSame($rejilla, $conjuntos(self::cfg('persona.maxChars', 'gte', 160, 'require'))[0]['domain']);
        $this->assertSame([['target' => 'config:strategy.offerPriority#Mensualidad', 'allowed' => [true], 'domain' => [true, false]]], $conjuntos(self::cfg('strategy.offerPriority', 'contains', 'Mensualidad', 'require')));
        $this->assertSame([false], $conjuntos(self::cfg('strategy.offerPriority', 'not_contains', 'Mensualidad', 'require'))[0]['allowed']);
        $this->assertSame([['target' => 'edge:E14', 'allowed' => ['present'], 'domain' => ['present', 'absent']]], $conjuntos(self::edge('E14', 'present', 'require')));
        $this->assertSame(['absent'], $conjuntos(self::edge('E14', 'absent', 'require'))[0]['allowed']);
        $this->assertSame(['present'], $conjuntos(self::edge('E10', 'present', 'require'))[0]['domain'], 'una arista obligatoria solo puede estar');
        $this->assertSame(
            [['target' => 'text#hola', 'allowed' => [true], 'domain' => [true, false]], ['target' => 'text#adiós', 'allowed' => [true], 'domain' => [true, false]]],
            $conjuntos(self::text('require', ['hola', 'adiós'])),
        );
        // forbid = el dominio menos el conjunto; limit = require.
        $this->assertSame([false], $conjuntos(self::text('forbid', ['hola']))[0]['allowed']);
        $this->assertSame(['cercano', 'entusiasta'], $conjuntos(self::cfg('persona.tone', 'eq', 'profesional', 'forbid'))[0]['allowed']);
        $this->assertSame(range(320, 480, 20), $conjuntos(self::cfg('persona.maxChars', 'lte', 300, 'forbid'))[0]['allowed']);
        $this->assertSame(['absent'], $conjuntos(self::edge('E14', 'present', 'forbid'))[0]['allowed']);
        $this->assertSame([false], $conjuntos(self::cfg('strategy.offerPriority', 'contains', 'X', 'forbid'))[0]['allowed']);
    }

    // ── 422: forma y valores ────────────────────────────────────────────────

    public function test_shape_errors_land_on_their_key(): void
    {
        $max = self::cfg('persona.maxChars', 'lte', 300, 'limit');

        $this->assertSame(['conditions'], self::claves('texto'));
        $this->assertSame(['conditions'], self::claves([]));
        $this->assertSame(['conditions'], self::claves([['when' => [], 'then' => [$max]]]), 'una lista no es un objeto');
        $this->assertSame(['conditions'], self::claves(['when' => [], 'then' => [$max], 'otra' => 1]));
        $this->assertSame(['conditions.when'], self::claves(['then' => [$max]]), 'falta «when»: es obligatoria aunque esté vacía');
        $this->assertSame(['conditions.then'], self::claves(['when' => []]), 'falta «then»');
        $this->assertSame(['conditions.when'], self::claves(['when' => 'x', 'then' => [$max]]));
        $this->assertSame(['conditions.when'], self::claves(['when' => ['a' => self::cfg('persona.tone', 'eq', 'cercano')], 'then' => [$max]]));
        $this->assertSame(['conditions.when'], self::claves(self::cond([$max], [
            self::cfg('persona.tone', 'eq', 'cercano'), self::cfg('persona.emojis', 'eq', true), self::cfg('strategy.goal', 'eq', 'cita'), self::cfg('intent.fallback', 'eq', 'humano'),
        ])));
        $this->assertSame(['conditions.then'], self::claves(self::cond([])));
        $this->assertSame(['conditions.then'], self::claves(self::cond([
            $max, self::cfg('persona.tone', 'eq', 'cercano', 'require'), self::cfg('persona.emojis', 'eq', true, 'require'), self::cfg('strategy.goal', 'eq', 'cita', 'require'),
        ])));
        $this->assertSame(['conditions.then.0'], self::claves(self::cond(['config'])));
        $this->assertSame(['conditions.then.0'], self::claves(self::cond([[]])));
        $this->assertSame(['conditions.then.0.target'], self::claves(self::cond([['kind' => 'require', 'target' => 'prompt', 'op' => 'eq', 'value' => 1]])));
        $this->assertSame(['conditions.when.0.target'], self::claves(self::cond([$max], [['target' => 'text', 'op' => 'contains', 'phrases' => ['hola']]])), 'el texto solo va en «entonces»');
        $this->assertSame(['conditions.then.0.kind'], self::claves(self::cond([['kind' => 'obliga'] + self::cfg('persona.tone', 'eq', 'cercano')])));
        $this->assertSame(['conditions.then.0.kind'], self::claves(self::cond([['target' => 'config', 'layer' => 'persona', 'key' => 'tone', 'op' => 'eq', 'value' => 'cercano']])), 'falta kind: lo dice su clave, sin cascada');
        $this->assertSame(['conditions.then.0.op'], self::claves(self::cond([self::cfg('persona.maxChars', 'lt', 300, 'limit')])));
        // Claves exactas: la que sobra se señala en el objeto; la que falta, en su clave.
        $this->assertSame(['conditions.then.0'], self::claves(self::cond([$max + ['extra' => 1]])), 'sobra una clave');
        $this->assertSame(['conditions.then.0.value'], self::claves(self::cond([['kind' => 'limit', 'target' => 'config', 'layer' => 'persona', 'key' => 'maxChars', 'op' => 'lte']])), 'falta value');
        $this->assertSame(['conditions.then.0.layer'], self::claves(self::cond([['kind' => 'require', 'target' => 'config', 'key' => 'tone', 'op' => 'eq', 'value' => 'cercano']])), 'falta layer');
        $this->assertSame(['conditions.then.0.edge'], self::claves(self::cond([['kind' => 'require', 'target' => 'edge', 'op' => 'present']])), 'falta edge');
        $this->assertSame(['conditions.then.0.op'], self::claves(self::cond([['kind' => 'require', 'target' => 'edge', 'edge' => 'E14']])), 'falta op');
        $this->assertSame(['conditions.when.0'], self::claves(self::cond([$max], [['kind' => 'require'] + self::cfg('persona.tone', 'eq', 'cercano')])), 'kind sobra en el «si»');
        $this->assertSame(['conditions.then.0', 'conditions.then.0.values'], self::claves(self::cond([['kind' => 'require', 'target' => 'config', 'layer' => 'persona', 'key' => 'tone', 'op' => 'in', 'value' => 'cercano']])), 'in lleva values: value sobra y values falta');
    }

    public function test_targets_ops_and_values_are_checked_against_the_catalog(): void
    {
        $this->assertSame(['conditions.then.0.layer'], self::claves(self::cond([self::cfg('voz.tone', 'eq', 'cercano', 'require')])));
        $this->assertSame(['conditions.then.0.key'], self::claves(self::cond([self::cfg('persona.color', 'eq', 'rojo', 'require')])));
        $this->assertSame(['conditions.then.0.edge'], self::claves(self::cond([self::edge('E99', 'present', 'require')])));
        $this->assertSame(['conditions.then.0.op'], self::claves(self::cond([self::cfg('persona.emojis', 'in', [true], 'require')])), 'un toggle solo admite eq');
        $this->assertSame(['conditions.then.0.op'], self::claves(self::cond([self::cfg('persona.tone', 'lte', 'cercano', 'require')])));
        $this->assertSame(['conditions.then.0.op'], self::claves(self::cond([self::cfg('strategy.offerPriority', 'eq', 'Mensualidad', 'require')])));
        $this->assertSame(['conditions.then.0.op'], self::claves(self::cond([self::edge('E14', 'contains', 'require')])));
        $this->assertSame(['conditions.then.0.op'], self::claves(self::cond([['kind' => 'require', 'target' => 'text', 'op' => 'eq', 'phrases' => ['hola']]])));
        $this->assertSame(['conditions.then.0.kind'], self::claves(self::cond([self::cfg('persona.maxChars', 'eq', 300, 'limit')])), 'limit solo con lte o gte');
        $this->assertSame(['conditions.then.0.kind'], self::claves(self::cond([self::cfg('persona.tone', 'eq', 'cercano', 'limit')])), 'limit solo sobre un rango');
        $this->assertSame(['conditions.then.0.kind'], self::claves(self::cond([self::edge('E14', 'present', 'limit')])));
        $this->assertSame(['conditions.then.0.kind'], self::claves(self::cond([self::text('limit', ['hola'])])));

        $this->assertSame(['conditions.then.0.value'], self::claves(self::cond([self::cfg('persona.emojis', 'eq', 'sí', 'require')])));
        $this->assertSame(['conditions.then.0.value'], self::claves(self::cond([self::cfg('persona.emojis', 'eq', 1, 'require')])), 'booleano estricto');
        $this->assertSame(['conditions.then.0.value'], self::claves(self::cond([self::cfg('persona.tone', 'eq', 'gritón', 'require')])));
        $this->assertSame(['conditions.then.0.values'], self::claves(self::cond([self::cfg('persona.tone', 'in', [], 'require')])));
        $this->assertSame(['conditions.then.0.values'], self::claves(self::cond([self::cfg('persona.tone', 'in', 'cercano', 'require')])));
        $this->assertSame(['conditions.then.0.values.1'], self::claves(self::cond([self::cfg('persona.tone', 'in', ['cercano', 'gritón'], 'require')])));
        $this->assertSame(['conditions.then.0.value'], self::claves(self::cond([self::cfg('persona.maxChars', 'eq', 310, 'require')])), 'fuera de la rejilla');
        $this->assertSame(['conditions.then.0.value'], self::claves(self::cond([self::cfg('persona.maxChars', 'eq', 300.0, 'require')])), 'un entero');
        $this->assertSame(['conditions.then.0.value'], self::claves(self::cond([self::cfg('persona.maxChars', 'gte', 500, 'limit')])), 'gte 500 con un máximo de 480');
        $this->assertSame(['conditions.then.0.value'], self::claves(self::cond([self::cfg('persona.maxChars', 'lte', 159, 'limit')])));
        $this->assertSame([], self::claves(self::cond([self::cfg('persona.maxChars', 'lte', 159 + 1, 'limit')])), 'lte admite cualquier entero del intervalo');
        $this->assertSame(['conditions.then.0.value'], self::claves(self::cond([self::cfg('strategy.offerPriority', 'contains', " \t ", 'require')])));
        $this->assertSame(['conditions.then.0.value'], self::claves(self::cond([self::cfg('strategy.offerPriority', 'contains', str_repeat('x', 61), 'require')])));
        $this->assertSame(['conditions.then.0.value'], self::claves(self::cond([self::cfg('strategy.offerPriority', 'contains', "a\u{200B}b", 'require')])), 'invisibles');
        $this->assertSame([], self::claves(self::cond([self::cfg('strategy.offerPriority', 'contains', str_repeat('ñ', 60), 'require')])), '60 caracteres, no bytes');
    }

    public function test_phrases_are_checked(): void
    {
        $this->assertSame(['conditions.then.0.phrases'], self::claves(self::cond([self::text('forbid', [])])));
        $this->assertSame(['conditions.then.0.phrases'], self::claves(self::cond([self::text('forbid', array_map(static fn (int $i): string => "frase {$i}", range(1, 11)))])));
        $this->assertSame([], self::claves(self::cond([self::text('forbid', [...array_map(static fn (int $i): string => "frase {$i}", range(1, 10)), 'FRASE 1'])])), 'el tope cuenta las frases distintas');
        $this->assertSame(['conditions.then.0.phrases.1'], self::claves(self::cond([self::text('forbid', ['hola', 'x'])])), 'de 2 a 60 caracteres');
        $this->assertSame(['conditions.then.0.phrases.0'], self::claves(self::cond([self::text('forbid', [str_repeat('a', 61)])])));
        $this->assertSame(['conditions.then.0.phrases.0'], self::claves(self::cond([self::text('forbid', ["dos\nlíneas"])])), 'una sola línea');
        $this->assertSame(['conditions.then.0.phrases.0'], self::claves(self::cond([self::text('forbid', [12])])));
        $this->assertSame([], self::claves(self::cond([self::text('forbid', ["  hola\n"])])), 'los extremos se recortan');
    }

    public function test_the_encoded_size_is_capped_at_4_kb(): void
    {
        // Tres afirmaciones de diez frases de 59 caracteres de tres bytes: forma válida, pero más de 4 KB.
        $frases = static fn (string $prefijo): array => array_map(static fn (int $i): string => str_repeat('€', 58).$prefijo.$i, range(0, 9));
        $grande = self::cond([self::text('forbid', $frases('a')), self::text('forbid', $frases('b')), self::text('forbid', $frases('c'))]);

        $this->assertGreaterThan(R::MAX_BYTES, strlen((string) json_encode($grande, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
        $this->assertSame(['conditions'], self::claves($grande));
    }

    public function test_a_variable_cannot_repeat_within_when_or_within_then(): void
    {
        $this->assertSame(['conditions.then.1'], self::claves(self::cond([
            self::cfg('persona.maxChars', 'lte', 400, 'limit'), self::cfg('persona.maxChars', 'gte', 200, 'limit'),
        ])));
        $this->assertSame(['conditions.when.1'], self::claves(self::cond([self::cfg('persona.emojis', 'eq', false, 'require')], [
            self::cfg('persona.tone', 'eq', 'cercano'), self::cfg('persona.tone', 'in', ['cercano', 'profesional']),
        ])));
        $this->assertSame(['conditions.then.1'], self::claves(self::cond([self::text('forbid', ['gratis', 'oferta']), self::text('require', ['bienvenido', 'Oferta'])])), 'en el texto, la variable es cada frase');
        $this->assertSame([], self::claves(self::cond([self::text('forbid', ['oferta']), self::text('require', ['bienvenido'])])));
        $this->assertSame([], self::claves(self::cond([
            self::cfg('strategy.offerPriority', 'contains', 'A', 'require'), self::cfg('strategy.offerPriority', 'not_contains', 'B', 'require'),
        ])), 'cada etiqueta es una variable');
        $this->assertSame(['conditions.then.1'], self::claves(self::cond([
            self::cfg('strategy.offerPriority', 'contains', 'A', 'require'), self::cfg('strategy.offerPriority', 'not_contains', ' A ', 'require'),
        ])));
        // La misma variable en el «si» y en el «entonces» sí puede estar (si no se contradice).
        $this->assertSame([], self::claves(self::cond([self::cfg('persona.tone', 'eq', 'cercano', 'require')], [self::cfg('persona.tone', 'in', ['cercano', 'profesional'])])));
    }

    // ── 422: semántica de una regla ─────────────────────────────────────────

    public function test_impossible_never_applying_and_self_contradicting_rules_are_rejected(): void
    {
        // Imposible: ningún valor cumple la afirmación.
        $this->assertSame(['conditions.then.0'], self::claves(self::cond([self::cfg('persona.tone', 'not_in', ['cercano', 'profesional', 'entusiasta'], 'require')])));
        $this->assertSame(['conditions.then.0'], self::claves(self::cond([self::cfg('integrations.channel', 'eq', 'whatsapp', 'forbid')])), 'un select de una sola opción');
        $this->assertSame(['conditions.then.0'], self::claves(self::cond([self::cfg('persona.maxChars', 'gte', 160, 'forbid')])));
        $this->assertSame(['conditions.then.0'], self::claves(self::cond([self::edge('E10', 'absent', 'require')])));
        $this->assertSame(['conditions.then.0'], self::claves(self::cond([self::edge('E10', 'present', 'forbid')])));
        $this->assertSame(
            ['conditions.then.0' => ['«borrador, veredicto y token → commit» es obligatoria en el motor: no se puede exigir que falte.']],
            self::errores(self::cond([self::edge('E10', 'absent', 'require')])),
        );

        // Nunca se aplicaría: ningún valor cumple el «si».
        $this->assertSame(['conditions.when.0'], self::claves(self::cond([self::cfg('persona.emojis', 'eq', false, 'require')], [self::cfg('persona.tone', 'not_in', ['cercano', 'profesional', 'entusiasta'])])));
        $this->assertSame(['conditions.when.0'], self::claves(self::cond([self::cfg('persona.emojis', 'eq', false, 'require')], [self::edge('E11', 'absent')])));

        // Se contradice a sí misma.
        $this->assertSame(['conditions.then.0'], self::claves(self::cond([self::cfg('strategy.goal', 'eq', 'cita', 'require')], [self::cfg('strategy.goal', 'eq', 'venta')])));
        $this->assertSame(['conditions.then.0'], self::claves(self::cond([self::cfg('persona.maxChars', 'lte', 300, 'limit')], [self::cfg('persona.maxChars', 'gte', 320)])));
        $this->assertSame([], self::claves(self::cond([self::cfg('persona.maxChars', 'lte', 300, 'limit')], [self::cfg('persona.maxChars', 'gte', 300)])), 'se cruzan en 300');
    }

    public function test_an_empty_assertion_is_saved_and_flagged_as_empty(): void
    {
        foreach ([
            self::cfg('persona.maxChars', 'lte', 480, 'limit'),
            self::cfg('persona.tone', 'in', ['cercano', 'profesional', 'entusiasta'], 'require'),
            self::cfg('persona.tone', 'not_in', ['cercano', 'profesional', 'entusiasta'], 'forbid'),
            self::cfg('integrations.channel', 'eq', 'whatsapp', 'require'),
            self::edge('E10', 'present', 'require'),
        ] as $i => $afirmacion) {
            $condiciones = self::cond([$afirmacion]);
            $this->assertSame([], self::claves($condiciones), "caso {$i}: se guarda");
            $objetivo = R::allowedSets($condiciones, self::catalogo())[0]['target'];
            $this->assertSame(
                [['id' => 'regla-vacia', 'level' => 'warning', 'with' => [], 'target' => $objetivo]],
                self::problemas('S-001', [self::regla('S-001', $condiciones)]),
                "caso {$i}",
            );
        }
    }

    // ── 422: controles del motor ────────────────────────────────────────────

    public function test_each_blocking_control_is_enforced_with_its_message(): void
    {
        $control = static fn (string $titulo): string => "Contradice un control del motor («{$titulo}»).";

        $this->assertSame(['conditions.then.0' => [
            $control('Hechos sin verificar'),
            'Contradice R-01 (la aplica el código): «Solo hechos verificados» tiene que ser «sí».',
        ]], self::errores(self::cond([self::cfg('knowledge.strictFacts', 'eq', false, 'require')])));
        $this->assertSame(['conditions.then.0' => [
            $control('Descuentos permitidos'),
            'Contradice R-02 (la aplica el código): «Puede ofrecer descuentos» tiene que ser «no».',
        ]], self::errores(self::cond([self::cfg('strategy.discountsAllowed', 'eq', true, 'require')])));
        $this->assertSame(['conditions.then.0' => [$control('Sin veredicto del Critic')]], self::errores(self::cond([self::cfg('composer.critic', 'eq', true, 'forbid')])));
        $this->assertSame(['conditions.then.0' => [
            $control('Derivación sin corroborar'),
            'Contradice R-07 (la aplica el código): «Derivar solo si está corroborado» tiene que ser «sí».',
        ]], self::errores(self::cond([self::cfg('rules.handoffOnlyCorroborated', 'eq', false, 'require')])));
        $this->assertSame(['conditions.then.0' => [$control('Hermes está inactivo')]], self::errores(self::cond([self::cfg('intent.driver', 'in', ['hermes'], 'require')])));
        $this->assertSame(['conditions.then.0' => [
            $control('Tráfico general'),
            'Contradice R-12 (la aplica el código): «Solo la conversación canaria» tiene que ser «sí».',
        ]], self::errores(self::cond([self::cfg('governance.canaryOnly', 'eq', false, 'require')])));
        $this->assertSame(['conditions.then.0' => [$control('Enlaces de pago sin su camino')]], self::errores(self::cond(
            [self::edge('E16', 'absent', 'require')],
            [self::cfg('rules.paymentLinksEnabled', 'eq', true)],
        )));
        $this->assertSame(['conditions.then.0' => ['Contradice R-02 (la aplica el código): la frase «gratis» no puede aparecer.']], self::errores(self::cond([self::text('require', ['bienvenido', '  GRATIS'])])));

        // Lo que respeta el control pasa: el «si» incompatible lo evita, y el mismo sentido no choca.
        $this->assertSame([], self::claves(self::cond([self::edge('E16', 'absent', 'require')], [self::cfg('rules.paymentLinksEnabled', 'eq', false)])));
        $this->assertSame([], self::claves(self::cond([self::text('forbid', ['gratis', 'promo'])])));
        $this->assertSame([], self::claves(self::cond([self::cfg('intent.driver', 'not_in', ['hermes'], 'require')])));
    }

    public function test_warning_level_constraints_are_not_controls(): void
    {
        // Decisión 3 del lead: una regla puede proponer encender el cobro (cobro-aprobacion es aviso).
        $this->assertSame([], self::claves(self::cond([self::cfg('rules.paymentLinksEnabled', 'eq', true, 'require')])));
        // `apagado` y `cobro-sin-bandera` tampoco son controles.
        $this->assertSame([], self::claves(self::cond([self::cfg('governance.enabled', 'eq', false, 'require')])));
        $this->assertSame([], self::claves(self::cond([self::edge('E14', 'present', 'require')], [self::cfg('rules.paymentLinksEnabled', 'eq', false)])));
    }

    public function test_layers_must_include_each_config_target_and_one_side_of_each_edge(): void
    {
        $condiciones = self::cond([self::cfg('persona.maxChars', 'lte', 300, 'limit'), self::edge('E14', 'present', 'require'), self::text('forbid', ['oferta'])], [self::cfg('strategy.goal', 'eq', 'venta')]);

        $this->assertSame([], self::claves($condiciones, ['persona', 'strategy', 'rules']));
        $this->assertSame([], self::claves($condiciones, ['persona', 'strategy', 'integrations']), 'basta una capa de la arista');
        $this->assertSame(['layers'], self::claves($condiciones, ['persona', 'rules']), 'falta la del «si»');
        $this->assertSame(['layers'], self::claves($condiciones, ['strategy', 'persona']), 'falta una de las de E14');
        $this->assertSame(['layers'], self::claves($condiciones, []));
        $this->assertSame([], self::claves(self::cond([self::text('forbid', ['oferta'])]), []), 'el texto no exige capa');
        $this->assertSame(['Las capas de la regla tienen que incluir «Reglas del negocio» o «Integraciones», por «enlace de pago como mensaje aparte».'], self::errores($condiciones, ['persona', 'strategy'])['layers']);
    }

    public function test_the_422_carries_every_key_at_once(): void
    {
        $condiciones = self::cond([
            self::cfg('persona.maxChars', 'eq', 301, 'require'),
            self::cfg('strategy.discountsAllowed', 'eq', true, 'require'),
            self::cfg('persona.tone', 'in', ['gritón'], 'require'),
        ], [self::cfg('strategy.goal', 'eq', 'venta')]);

        $this->assertSame(['conditions.then.0.value', 'conditions.then.1', 'conditions.then.2.values.0', 'layers'], self::claves($condiciones, ['persona']));

        try {
            R::canonicalize($condiciones, self::catalogo(), ['persona']);
            $this->fail('Debería rechazarse.');
        } catch (ValidationException $e) {
            $claves = array_keys($e->errors());
            sort($claves, SORT_STRING);
            $this->assertSame(['conditions.then.0.value', 'conditions.then.1', 'conditions.then.2.values.0', 'layers'], $claves);
        }
    }

    // ── Contradicciones entre reglas ────────────────────────────────────────

    public function test_rules_contradict_each_other_per_pair_and_per_variable(): void
    {
        foreach ([
            'toggle' => [self::cfg('persona.emojis', 'eq', true, 'require'), self::cfg('persona.emojis', 'eq', false, 'require'), 'config:persona.emojis'],
            'select' => [self::cfg('persona.tone', 'in', ['cercano', 'entusiasta'], 'require'), self::cfg('persona.tone', 'eq', 'profesional', 'require'), 'config:persona.tone'],
            'rango (rejilla)' => [self::cfg('persona.maxChars', 'lte', 310, 'limit'), self::cfg('persona.maxChars', 'gte', 311, 'limit'), 'config:persona.maxChars'],
            'etiqueta' => [self::cfg('strategy.offerPriority', 'contains', 'Mensualidad', 'require'), self::cfg('strategy.offerPriority', 'not_contains', 'Mensualidad', 'require'), 'config:strategy.offerPriority#Mensualidad'],
            // Con el cobro apagado en el «si», «E14 tiene que faltar» no choca con `cobro-sin-camino`.
            'arista' => [self::edge('E14', 'present', 'require'), self::edge('E14', 'present', 'forbid'), 'edge:E14', [self::cfg('rules.paymentLinksEnabled', 'eq', false)]],
            'frase' => [self::text('require', ['bienvenido']), self::text('forbid', ['bienvenido']), 'text#bienvenido'],
        ] as $caso => $c) {
            [$a, $b, $objetivo] = $c;
            $cuando = $c[3] ?? [];
            $reglas = [self::regla('S-001', self::cond([$a], $cuando)), self::regla('S-002', self::cond([$b], $cuando))];

            $this->assertSame([['id' => 'regla-contradice-regla', 'level' => 'blocking', 'with' => ['S-002'], 'target' => $objetivo]], self::problemas('S-001', $reglas), $caso);
            $this->assertSame([['id' => 'regla-contradice-regla', 'level' => 'blocking', 'with' => ['S-001'], 'target' => $objetivo]], self::problemas('S-002', $reglas), $caso);
            $this->assertSame(['controls' => [], 'rules' => ['S-001']], R::conflicts($reglas[1], [$reglas[0]], self::catalogo()), $caso);
        }

        // En la rejilla de 20 en 20, «≤ 310» y «≥ 300» se cruzan en 300: no hay contradicción.
        $this->assertSame([], R::conflicts(
            self::regla('S-002', self::cond([self::cfg('persona.maxChars', 'gte', 300, 'limit')])),
            [self::regla('S-001', self::cond([self::cfg('persona.maxChars', 'lte', 310, 'limit')]))],
            self::catalogo(),
        )['rules']);
    }

    public function test_incompatible_whens_avoid_the_contradiction(): void
    {
        $venta = self::regla('S-001', self::cond([self::cfg('persona.maxChars', 'lte', 300, 'limit')], [self::cfg('strategy.goal', 'eq', 'venta')]));
        $cita = self::regla('S-002', self::cond([self::cfg('persona.maxChars', 'gte', 320, 'limit')], [self::cfg('strategy.goal', 'eq', 'cita')]));
        $siempre = self::regla('S-003', self::cond([self::cfg('persona.maxChars', 'gte', 320, 'limit')]));

        $this->assertSame([], self::problemas('S-001', [$venta, $cita]));
        $this->assertSame(['controls' => [], 'rules' => []], R::conflicts($cita, [$venta], self::catalogo()));
        // Sin «si», la otra sí se aplica cuando objetivo = venta.
        $this->assertSame(['controls' => [], 'rules' => ['S-001']], R::conflicts($siempre, [$venta, $cita], self::catalogo()));
    }

    public function test_declared_limitation_contradictions_are_not_chained(): void
    {
        // venta ⇒ tono cercano; tono cercano ⇒ sin emojis; sin emojis ⇒ objetivo cita.
        // Juntas, «objetivo = venta» acaba exigiendo «objetivo = cita». Por pares y
        // por variable no se detecta: ningún par comparte una variable afirmada.
        $reglas = [
            self::regla('S-001', self::cond([self::cfg('persona.tone', 'eq', 'cercano', 'require')], [self::cfg('strategy.goal', 'eq', 'venta')])),
            self::regla('S-002', self::cond([self::cfg('persona.emojis', 'eq', false, 'require')], [self::cfg('persona.tone', 'eq', 'cercano')])),
            self::regla('S-003', self::cond([self::cfg('strategy.goal', 'eq', 'cita', 'require')], [self::cfg('persona.emojis', 'eq', false)])),
        ];
        foreach (R::analyzeAll($reglas, self::catalogo()) as $codigo => $a) {
            $this->assertSame([], $a['issues'], $codigo);
        }

        // Tampoco cruza el «si» de una con el «entonces» de otra.
        $this->assertSame(['controls' => [], 'rules' => []], R::conflicts(
            self::regla('S-005', self::cond([self::cfg('rules.paymentLinksEnabled', 'eq', true, 'require')], [self::edge('E14', 'absent')])),
            [],
            self::catalogo(),
        ));
    }

    // ── Análisis ────────────────────────────────────────────────────────────

    public function test_verification_says_what_can_be_checked(): void
    {
        $analisis = R::analyzeAll([
            self::regla('S-001', self::cond([self::cfg('persona.emojis', 'eq', false, 'require')])),
            self::regla('S-002', self::cond([self::text('forbid', ['oferta'])])),
            self::regla('S-003', null),
            self::regla('S-004', self::cond([self::text('forbid', ['oferta2']), self::edge('E14', 'absent', 'require')])),
        ], self::catalogo());

        $this->assertSame(['S-001' => 'studio', 'S-002' => 'lab', 'S-003' => 'none', 'S-004' => 'studio'], array_map(static fn (array $a): string => $a['verification'], $analisis));
        $this->assertSame([], $analisis['S-003']['issues']);
    }

    public function test_the_analysis_orders_issues_and_lists_counterparts(): void
    {
        // S-009 llegó (p. ej., antes de un cambio del código) con una afirmación vacía,
        // una que contradice a dos controles y otra que choca con S-001 y S-002.
        $reglas = [
            self::regla('S-002', self::cond([self::cfg('persona.maxChars', 'gte', 400, 'limit')])),
            self::regla('S-001', self::cond([self::cfg('persona.maxChars', 'gte', 320, 'limit')])),
            self::regla('S-009', self::cond([
                self::cfg('integrations.channel', 'eq', 'whatsapp', 'require'),
                self::cfg('strategy.discountsAllowed', 'eq', true, 'require'),
                self::cfg('persona.maxChars', 'lte', 300, 'limit'),
            ]), ['refs' => ['configs' => [['layer' => 'persona', 'key' => 'color']], 'edges' => ['E77'], 'knowledgeKeys' => [], 'knowledgeCategories' => []]]),
            self::regla('S-003', self::cond([self::cfg('persona.maxChars', 'gte', 460, 'limit')]), ['status' => 'archived']),
            ...self::codigo(),
        ];

        $this->assertSame([
            ['id' => 'regla-incompatible', 'level' => 'blocking', 'with' => [], 'target' => 'config:persona.color'],
            ['id' => 'regla-incompatible', 'level' => 'blocking', 'with' => [], 'target' => 'edge:E77'],
            ['id' => 'regla-contradice-control', 'level' => 'blocking', 'with' => ['control:descuentos', 'R-02'], 'target' => 'config:strategy.discountsAllowed'],
            ['id' => 'regla-contradice-regla', 'level' => 'blocking', 'with' => ['S-002', 'S-001'], 'target' => 'config:persona.maxChars'],
            ['id' => 'regla-vacia', 'level' => 'warning', 'with' => [], 'target' => 'config:integrations.channel'],
        ], self::problemas('S-009', $reglas), 'archivadas y reglas del código no cuentan como reglas del Studio; las demás, en el orden de llegada');
    }

    public function test_a_legacy_rule_never_gets_contradiction_issues_only_incompatibility(): void
    {
        // R-02 tal como estaría en la base con unas condiciones viejas que dicen lo contrario.
        $vieja = self::regla('R-02', self::cond([self::cfg('strategy.discountsAllowed', 'eq', true, 'require')]));
        $studio = self::regla('S-001', self::cond([self::cfg('strategy.discountsAllowed', 'eq', true, 'require')]));

        // Sus condiciones son controles: la que choca con ellas es la del Studio.
        $this->assertSame([], self::problemas('R-02', [$vieja, $studio]));
        $this->assertSame(
            [['id' => 'regla-contradice-control', 'level' => 'blocking', 'with' => ['control:descuentos', 'R-02'], 'target' => 'config:strategy.discountsAllowed']],
            self::problemas('S-001', [$vieja, $studio]),
            'R-02 sale de legacyConditions (el catálogo), no de la fila vieja',
        );

        // Lo único que puede tener una regla del código es una incompatibilidad.
        $rota = self::regla('R-01', self::cond([self::cfg('knowledge.facts', 'eq', true, 'require')]), ['refs' => ['configs' => [], 'edges' => ['E77'], 'knowledgeKeys' => [], 'knowledgeCategories' => []]]);
        $this->assertSame([
            ['id' => 'regla-incompatible', 'level' => 'blocking', 'with' => [], 'target' => 'config:knowledge.facts'],
            ['id' => 'regla-incompatible', 'level' => 'blocking', 'with' => [], 'target' => 'edge:E77'],
        ], self::problemas('R-01', [$rota]));
    }

    public function test_a_catalog_change_makes_rules_incompatible_or_contradictory(): void
    {
        $regla = self::regla('S-001', self::cond([
            self::cfg('persona.maxChars', 'lte', 300, 'limit'),
            self::cfg('persona.tone', 'in', ['cercano', 'entusiasta'], 'require'),
            self::edge('E14', 'absent', 'require'),
        ]));

        // Sin maxChars, sin «entusiasta» y con E14 obligatoria.
        $catalogo = self::catalogo();
        $catalogo['parameters']['persona'] = array_values(array_filter($catalogo['parameters']['persona'], static fn (array $p): bool => $p['key'] !== 'maxChars'));
        foreach ($catalogo['parameters']['persona'] as &$p) {
            if ($p['key'] === 'tone') {
                $p['options'] = array_values(array_filter($p['options'], static fn (array $o): bool => $o['value'] !== 'entusiasta'));
            }
        }
        unset($p);

        $this->assertSame([
            ['id' => 'regla-incompatible', 'level' => 'blocking', 'with' => [], 'target' => 'config:persona.maxChars'],
            ['id' => 'regla-incompatible', 'level' => 'blocking', 'with' => [], 'target' => 'config:persona.tone'],
        ], self::problemas('S-001', [$regla], $catalogo), 'parámetro y opción que ya no existen');

        $otro = self::catalogo();
        foreach ($otro['edges'] as &$e) {
            if ($e['id'] === 'E14') {
                $e['mandatory'] = true;
            }
        }
        unset($e);
        $this->assertSame([
            ['id' => 'regla-contradice-control', 'level' => 'blocking', 'with' => ['edge:E14'], 'target' => 'edge:E14'],
        ], self::problemas('S-001', [$regla], $otro), 'una arista que pasa a obligatoria');

        $sinArista = self::catalogo();
        $sinArista['edges'] = array_values(array_filter($sinArista['edges'], static fn (array $e): bool => $e['id'] !== 'E14'));
        $this->assertSame([['id' => 'regla-incompatible', 'level' => 'blocking', 'with' => [], 'target' => 'edge:E14']], self::problemas('S-001', [$regla], $sinArista));
    }

    public function test_a_when_that_can_never_hold_is_flagged_as_inapplicable(): void
    {
        $catalogo = self::catalogo();
        foreach ($catalogo['edges'] as &$e) {
            if ($e['id'] === 'E16') {
                $e['mandatory'] = true;
            }
        }
        unset($e);
        $regla = self::regla('S-001', self::cond([self::cfg('persona.emojis', 'eq', false, 'require')], [self::edge('E16', 'absent')]));

        $this->assertSame([], self::problemas('S-001', [$regla]));
        $this->assertSame([['id' => 'regla-inaplicable', 'level' => 'warning', 'with' => [], 'target' => 'edge:E16']], self::problemas('S-001', [$regla], $catalogo));
    }

    public function test_contradictions_give_the_detail_for_the_409(): void
    {
        $candidata = self::regla('S-004', self::cond([self::cfg('persona.maxChars', 'gte', 320, 'limit'), self::edge('E14', 'present', 'require')]));
        $vivas = [
            self::regla('S-001', self::cond([self::cfg('persona.maxChars', 'lte', 300, 'limit')])),
            self::regla('S-002', self::cond([self::edge('E14', 'absent', 'require')])),
            self::regla('S-003', self::cond([self::edge('E14', 'absent', 'require')]), ['status' => 'archived']),
            self::regla('S-004', self::cond([self::cfg('persona.maxChars', 'lte', 200, 'limit')])),
        ];

        // Ella misma (al editarla) y las archivadas no cuentan.
        $this->assertSame([
            ['kind' => 'rule', 'with' => 'S-001', 'target' => 'config:persona.maxChars', 'label' => 'Longitud máxima'],
            ['kind' => 'rule', 'with' => 'S-002', 'target' => 'edge:E14', 'label' => 'enlace de pago como mensaje aparte'],
        ], R::contradictions($candidata, $vivas, self::catalogo()));
        $this->assertSame(['controls' => [], 'rules' => ['S-001', 'S-002']], R::conflicts($candidata, $vivas, self::catalogo()));

        // Los controles, en su orden (restricciones, aristas obligatorias, reglas del código), no en el de las afirmaciones.
        $mala = self::regla('S-005', self::cond([self::text('require', ['2X1']), self::edge('E10', 'absent', 'require'), self::cfg('knowledge.strictFacts', 'eq', false, 'require')]));
        $this->assertSame(['controls' => ['control:hechos', 'edge:E10', 'R-01', 'R-02'], 'rules' => []], R::conflicts($mala, [], self::catalogo()));
        // Las reglas, en el orden de `live`.
        $this->assertSame(['S-002', 'S-001'], R::conflicts($candidata, array_reverse($vivas), self::catalogo())['rules']);
        // Unas condiciones que no se pueden leer no chocan con nada: eso es el 422.
        $this->assertSame(['controls' => [], 'rules' => []], R::conflicts(self::regla('S-006', ['when' => []]), $vivas, self::catalogo()));
    }

    // ── Evaluación contra un contenido ──────────────────────────────────────

    public function test_evaluation_warns_about_unmet_rules_with_literal_texts(): void
    {
        [$def, $configs] = self::contenido(['persona.maxChars' => 400, 'persona.tone' => 'entusiasta', 'strategy.offerPriority' => []], ['E14']);
        $reglas = [
            ...self::codigo(),
            self::regla('S-001', self::cond([self::cfg('persona.maxChars', 'lte', 300, 'limit')]), ['description' => 'Mensajes cortos.']),
            self::regla('S-002', self::cond([
                self::cfg('persona.tone', 'eq', 'profesional', 'require'),
                self::cfg('strategy.offerPriority', 'contains', 'Mensualidad', 'require'),
                self::text('forbid', ['urgente']),
            ]), ['description' => 'Tono sobrio.']),
            self::regla('S-003', self::cond([self::cfg('persona.emojis', 'eq', false, 'require')], [self::cfg('strategy.goal', 'eq', 'venta')]), ['description' => 'Sin emojis al vender.']),
            self::regla('S-004', null, ['description' => 'Documental.']),
            // Con el «si» del cobro apagado no choca con `cobro-sin-camino`.
            self::regla('S-005', self::cond([self::edge('E14', 'absent', 'require'), self::cfg('persona.emojis', 'eq', false, 'require')], [self::cfg('rules.paymentLinksEnabled', 'eq', false)]), ['description' => 'Sin cobro, sin enlace.']),
        ];

        $this->assertSame([
            ['id' => 'regla-S-001', 'level' => 'warning', 'title' => 'S-001 no se cumple', 'detail' => 'Mensajes cortos. (Longitud máxima: 400)', 'layer' => 'persona'],
            ['id' => 'regla-S-002', 'level' => 'warning', 'title' => 'S-002 no se cumple', 'detail' => 'Tono sobrio. (Tono: Entusiasta; Ofertas priorizadas: (vacío))', 'layer' => 'persona'],
            ['id' => 'regla-S-005', 'level' => 'warning', 'title' => 'S-005 no se cumple', 'detail' => 'Sin cobro, sin enlace. («enlace de pago como mensaje aparte»: presente; Usa emojis: sí)', 'layer' => 'integrations'],
            ['id' => 'reglas-sin-aplicacion', 'level' => 'warning', 'title' => 'Reglas que el motor no aplica', 'detail' => 'S-001, S-002, S-003, S-004, S-005: son propuestas del Studio; aprobar no las hace cumplir.'],
        ], R::evaluate($reglas, $def, $configs, self::catalogo()), 'S-003 no se aplica (objetivo = cita); el texto no se evalúa; las R-xx no dan regla-<code>');

        // Sin «si», «E14 tiene que faltar» choca con el control del cobro: hay una
        // configuración (bandera encendida) en la que se aplican las dos.
        $this->assertSame(['controls' => ['control:cobro-sin-camino'], 'rules' => []], R::conflicts(self::regla('S-006', self::cond([self::edge('E14', 'absent', 'require')])), [], self::catalogo()));
    }

    public function test_evaluation_formats_tags_edges_and_layers(): void
    {
        [$def, $configs] = self::contenido(['strategy.offerPriority' => ['Plan insignia', 'Trimestral']]);
        $reglas = [self::regla('S-001', self::cond([self::edge('E16', 'present', 'require'), self::cfg('strategy.offerPriority', 'not_contains', 'Trimestral', 'require')]), ['description' => 'Cobro completo.'])];

        $this->assertSame(
            ['id' => 'regla-S-001', 'level' => 'warning', 'title' => 'S-001 no se cumple', 'detail' => 'Cobro completo. («resultado del pago (Wompi) → memoria y fase»: ausente; Ofertas priorizadas: Plan insignia, Trimestral)', 'layer' => 'memory'],
            R::evaluate($reglas, $def, $configs, self::catalogo())[0],
            'la capa de una arista es la de su puerto destino',
        );
    }

    public function test_when_every_rule_is_met_the_ok_finding_lists_the_checked_ones(): void
    {
        [$def, $configs] = self::contenido();
        $reglas = [
            self::regla('S-002', self::cond([self::cfg('persona.maxChars', 'lte', 320, 'limit')])),
            self::regla('S-001', self::cond([self::cfg('persona.emojis', 'eq', false, 'require')], [self::cfg('strategy.goal', 'eq', 'venta')])),
            self::regla('S-003', self::cond([self::text('forbid', ['oferta'])])),
            self::regla('S-004', self::cond([self::cfg('persona.tone', 'eq', 'cercano', 'require')]), ['status' => 'archived']),
        ];

        $this->assertSame([
            ['id' => 'reglas', 'level' => 'ok', 'title' => 'Las reglas del Studio se cumplen', 'detail' => 'Comprobadas: S-002.'],
            ['id' => 'reglas-sin-aplicacion', 'level' => 'warning', 'title' => 'Reglas que el motor no aplica', 'detail' => 'S-002, S-001, S-003: son propuestas del Studio; aprobar no las hace cumplir.'],
        ], R::evaluate($reglas, $def, $configs, self::catalogo()), 'S-001 no se aplica (objetivo = cita) y S-003 solo tiene texto: ninguna cuenta como comprobada; las archivadas no cuentan; el orden es el de llegada');

        // Solo texto o sin condiciones: no hay `reglas`, solo el aviso.
        $this->assertSame(['reglas-sin-aplicacion'], array_column(R::evaluate([self::regla('S-003', self::cond([self::text('forbid', ['oferta'])])), self::regla('S-004', null)], $def, $configs, self::catalogo()), 'id'));
        // Sin reglas del Studio, nada.
        $this->assertSame([], R::evaluate(self::codigo(), $def, $configs, self::catalogo()));
    }

    public function test_a_variable_the_content_lacks_is_not_evaluated(): void
    {
        [$def, $configs] = self::contenido(['persona.maxChars' => 480]);
        unset($configs['persona']['maxChars'], $configs['strategy']['goal']);
        $reglas = [
            self::regla('S-001', self::cond([self::cfg('persona.maxChars', 'lte', 300, 'limit')])),
            self::regla('S-002', self::cond([self::cfg('persona.emojis', 'eq', false, 'require')], [self::cfg('strategy.goal', 'eq', 'cita')])),
        ];

        $this->assertSame(['reglas-sin-aplicacion'], array_column(R::evaluate($reglas, $def, $configs, self::catalogo()), 'id'));
        $this->assertSame(['reglas-sin-aplicacion'], array_column(R::evaluate($reglas, $def, null, self::catalogo()), 'id'));
    }

    public function test_incompatible_and_contradictory_rules_block_in_this_order(): void
    {
        [$def, $configs] = self::contenido(['persona.maxChars' => 480]);
        $reglas = [
            self::regla('S-003', self::cond([self::cfg('persona.maxChars', 'lte', 300, 'limit')]), ['description' => 'Corto.']),
            self::regla('S-001', self::cond([self::cfg('persona.color', 'eq', 'rojo', 'require'), self::edge('E99', 'present', 'require')])),
            self::regla('S-002', self::cond([self::cfg('persona.maxChars', 'gte', 320, 'limit'), self::cfg('strategy.discountsAllowed', 'eq', true, 'require'), self::text('require', ['gratis'])])),
            self::regla('S-004', self::cond([self::cfg('strategy.offerPriority', 'contains', 'A', 'require')]), ['refs' => ['configs' => [], 'edges' => ['E98'], 'knowledgeKeys' => [], 'knowledgeCategories' => []]]),
        ];

        $this->assertSame([
            ['id' => 'regla-incompatible-S-001', 'level' => 'blocking', 'title' => 'La regla S-001 no encaja con el catálogo', 'detail' => 'Cita lo que el catálogo 2026-10-19.1 no tiene: persona.color, E99.'],
            ['id' => 'regla-incompatible-S-004', 'level' => 'blocking', 'title' => 'La regla S-004 no encaja con el catálogo', 'detail' => 'Cita lo que el catálogo 2026-10-19.1 no tiene: E98.'],
            ['id' => 'reglas-contradictorias', 'level' => 'blocking', 'title' => 'Reglas que se contradicen', 'detail' => 'S-003 ↔ S-002 (Longitud máxima) · S-002 ↔ control «Descuentos permitidos» (Puede ofrecer descuentos) · S-002 ↔ R-02 (Puede ofrecer descuentos) · S-002 ↔ R-02 («gratis»)'],
            ['id' => 'regla-S-003', 'level' => 'warning', 'title' => 'S-003 no se cumple', 'detail' => 'Corto. (Longitud máxima: 480)', 'layer' => 'persona'],
            ['id' => 'regla-S-002', 'level' => 'warning', 'title' => 'S-002 no se cumple', 'detail' => 'Descripción de S-002. (Puede ofrecer descuentos: no)', 'layer' => 'strategy'],
            ['id' => 'reglas-sin-aplicacion', 'level' => 'warning', 'title' => 'Reglas que el motor no aplica', 'detail' => 'S-003, S-001, S-002, S-004: son propuestas del Studio; aprobar no las hace cumplir.'],
        ], R::evaluate($reglas, $def, $configs, self::catalogo()), 'en el orden de llegada; cada par entre reglas del Studio sale una vez, desde la primera; la contradictoria se evalúa igual');
    }

    public function test_format_1_contents_get_no_rule_findings_and_unknown_formats_are_errors(): void
    {
        [$def, $configs] = self::contenido(['persona.maxChars' => 480]);
        $reglas = [self::regla('S-001', self::cond([self::cfg('persona.maxChars', 'lte', 300, 'limit')]))];

        $this->assertSame([], R::evaluate($reglas, $def, $configs, self::catalogo(), 1), 'una versión de F se valida como en F');
        $this->assertSame(['regla-S-001', 'reglas-sin-aplicacion'], array_column(R::evaluate($reglas, $def, $configs, self::catalogo(), 2), 'id'));
        $this->assertSame(R::evaluate($reglas, $def, $configs, self::catalogo(), 2), R::evaluate($reglas, $def, $configs, self::catalogo()), 'un borrador es del último formato');

        $this->expectException(\InvalidArgumentException::class);
        R::evaluate($reglas, $def, $configs, self::catalogo(), 3);
    }

    public function test_a_malformed_definition_is_a_caller_error(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        R::evaluate([self::regla('S-001', null)], ['layers' => [], 'edgeIds' => 'E01'], [], self::catalogo());
    }
}
