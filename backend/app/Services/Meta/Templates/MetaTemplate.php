<?php

namespace App\Services\Meta\Templates;

/**
 * Una plantilla tal como la lee Meta, NORMALIZADA en el borde: lo que se
 * entiende pasa con su forma fija; lo que llegó con otra forma no se inventa
 * ni se descarta en silencio, se anota en {@see $unreadable}.
 *
 * QUÉ SE NORMALIZA Y QUÉ NO:
 *  - `id`: obligatorio, solo dígitos. Sin él no hay plantilla ({@see fromGraph()} da null).
 *  - `name` y `language`: tal cual, si tienen la forma de Meta.
 *  - Los estados y enumeraciones (`status`, `category`, `previous_category`,
 *    `correct_category`, `rejected_reason`, `parameter_format`, la calidad de
 *    `quality_score.score`): en mayúsculas y solo `[A-Z_]`, con un tope por
 *    campo (24, 16, 16, 16, 32, 16 y 8). Uno más largo o con otra forma es un
 *    valor que este código no conoce: null y anotado, para que quien lo lea
 *    lo trate como desconocido (falla cerrado).
 *  - `components`: cada componente con su `type` (y `format`) en mayúsculas,
 *    su `text` EXACTAMENTE como lo devuelve Meta y sus `buttons` (`type` en
 *    mayúsculas; `text`, `url` y `phone_number` exactos). Los ejemplos
 *    (`example`) no se guardan: no llegan al socio. Cualquier otra clave se
 *    nombra en `extraKeys` (ordenadas) sin su valor: así, una plantilla con
 *    algo que este código no sabe comparar no puede pasar por igual a otra.
 *    Si un componente o un botón no tiene la forma esperada, `components` es
 *    null y queda anotado.
 *
 * Los campos que no se pidieron son null (y no se anotan).
 */
final class MetaTemplate
{
    /** Topes de las enumeraciones (los de las columnas que las guardan). */
    public const STATUS_MAX = 24;

    public const CATEGORY_MAX = 16;

    public const REASON_MAX = 32;

    public const PARAMETER_FORMAT_MAX = 16;

    public const QUALITY_MAX = 8;

    /** Tope de un texto de un componente (el cuerpo de Meta llega a 1 024; esto es solo un techo de memoria). */
    public const TEXT_MAX_BYTES = 65536;

    /** Botones por componente y componentes por plantilla, como mucho (techos de memoria, muy por encima de Meta). */
    public const BUTTONS_MAX = 32;

    public const COMPONENTS_MAX = 32;

    /** Las claves de un componente que se leen; `example` se lee y se descarta. */
    private const COMPONENT_KEYS = ['type', 'format', 'text', 'buttons', 'example'];

    /** Las claves de un botón que se leen. */
    private const BUTTON_KEYS = ['type', 'text', 'url', 'phone_number'];

    /**
     * @param  list<array<string,mixed>>|null  $components
     * @param  list<string>  $unreadable
     */
    private function __construct(
        public readonly string $id,
        public readonly ?string $name,
        public readonly ?string $language,
        public readonly ?string $status,
        public readonly ?string $category,
        public readonly ?string $previousCategory,
        public readonly ?string $correctCategory,
        public readonly ?string $rejectedReason,
        public readonly ?string $qualityScore,
        public readonly ?string $parameterFormat,
        public readonly ?array $components,
        public readonly array $unreadable,
    ) {}

    /**
     * Un nodo WhatsAppMessageTemplate de Graph (o lo que haya llegado en su
     * lugar), normalizado; null si no es un objeto con un `id` de dígitos.
     */
    public static function fromGraph(mixed $node): ?self
    {
        if (! is_array($node) || array_is_list($node)) {
            return null;
        }

        $id = $node['id'] ?? null;
        if (is_int($id) && $id >= 0) {
            $id = (string) $id;
        }
        if (! is_string($id) || preg_match(TemplateRequestGuard::ID_PATTERN, $id) !== 1) {
            return null;
        }

        $unreadable = [];
        $read = static function (string $field, callable $parse) use ($node, &$unreadable): mixed {
            if (! array_key_exists($field, $node)) {
                return null;
            }
            $value = $parse($node[$field]);
            if ($value === null) {
                $unreadable[] = $field;
            }

            return $value;
        };

        $name = $read('name', static fn (mixed $v): ?string => is_string($v) && preg_match(TemplateRequestGuard::NAME_PATTERN, $v) === 1 ? $v : null);
        $language = $read('language', static fn (mixed $v): ?string => is_string($v) && preg_match('/^[A-Za-z]{2,3}(?:_[A-Za-z0-9]{2,4})?$/D', $v) === 1 ? $v : null);
        $status = $read('status', static fn (mixed $v): ?string => self::enum($v, self::STATUS_MAX));
        $category = $read('category', static fn (mixed $v): ?string => self::enum($v, self::CATEGORY_MAX));
        $previous = $read('previous_category', static fn (mixed $v): ?string => self::enum($v, self::CATEGORY_MAX));
        $correct = $read('correct_category', static fn (mixed $v): ?string => self::enum($v, self::CATEGORY_MAX));
        $reason = $read('rejected_reason', static fn (mixed $v): ?string => self::enum($v, self::REASON_MAX));
        $format = $read('parameter_format', static fn (mixed $v): ?string => self::enum($v, self::PARAMETER_FORMAT_MAX));
        $quality = $read('quality_score', static fn (mixed $v): ?string => is_array($v) ? self::enum($v['score'] ?? null, self::QUALITY_MAX) : null);
        $components = $read('components', static fn (mixed $v): ?array => self::components($v));

        return new self($id, $name, $language, $status, $category, $previous, $correct, $reason, $quality, $format, $components, $unreadable);
    }

    /**
     * Para registros, pruebas y quien prefiera arrays. Nunca lleva nada que no
     * esté en las propiedades.
     *
     * @return array{id: string, name: ?string, language: ?string, status: ?string, category: ?string, previousCategory: ?string,
     *               correctCategory: ?string, rejectedReason: ?string, qualityScore: ?string, parameterFormat: ?string,
     *               components: list<array<string,mixed>>|null, unreadable: list<string>}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'language' => $this->language,
            'status' => $this->status,
            'category' => $this->category,
            'previousCategory' => $this->previousCategory,
            'correctCategory' => $this->correctCategory,
            'rejectedReason' => $this->rejectedReason,
            'qualityScore' => $this->qualityScore,
            'parameterFormat' => $this->parameterFormat,
            'components' => $this->components,
            'unreadable' => $this->unreadable,
        ];
    }

    /** Un valor de enumeración de Meta en mayúsculas, o null si no tiene esa forma o pasa del tope. */
    public static function enum(mixed $value, int $max): ?string
    {
        if (! is_string($value) || preg_match('/^[A-Za-z_]{1,'.$max.'}$/D', $value) !== 1) {
            return null;
        }

        return strtoupper($value);
    }

    /** @return list<array<string,mixed>>|null */
    private static function components(mixed $value): ?array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > self::COMPONENTS_MAX) {
            return null;
        }

        $out = [];
        foreach ($value as $componente) {
            $normalizado = self::component($componente);
            if ($normalizado === null) {
                return null;
            }
            $out[] = $normalizado;
        }

        return $out;
    }

    /** @return array<string,mixed>|null */
    private static function component(mixed $c): ?array
    {
        if (! is_array($c) || array_is_list($c)) {
            return null;
        }

        $type = self::enum($c['type'] ?? null, 32);
        if ($type === null) {
            return null;
        }
        $out = ['type' => $type];

        if (array_key_exists('format', $c)) {
            $format = self::enum($c['format'], 32);
            if ($format === null) {
                return null;
            }
            $out['format'] = $format;
        }
        if (array_key_exists('text', $c)) {
            if (! self::text($c['text'])) {
                return null;
            }
            $out['text'] = $c['text'];
        }
        if (array_key_exists('buttons', $c)) {
            if (! is_array($c['buttons']) || ! array_is_list($c['buttons']) || count($c['buttons']) > self::BUTTONS_MAX) {
                return null;
            }
            $botones = [];
            foreach ($c['buttons'] as $boton) {
                $normalizado = self::button($boton);
                if ($normalizado === null) {
                    return null;
                }
                $botones[] = $normalizado;
            }
            $out['buttons'] = $botones;
        }

        $extra = self::extraKeys($c, self::COMPONENT_KEYS);
        if ($extra !== []) {
            $out['extraKeys'] = $extra;
        }

        return $out;
    }

    /** @return array<string,mixed>|null */
    private static function button(mixed $b): ?array
    {
        if (! is_array($b) || array_is_list($b)) {
            return null;
        }

        $type = self::enum($b['type'] ?? null, 32);
        if ($type === null) {
            return null;
        }
        $out = ['type' => $type];
        foreach (['text', 'url', 'phone_number'] as $clave) {
            if (! array_key_exists($clave, $b)) {
                continue;
            }
            if (! self::text($b[$clave])) {
                return null;
            }
            $out[$clave] = $b[$clave];
        }

        $extra = self::extraKeys($b, self::BUTTON_KEYS);
        if ($extra !== []) {
            $out['extraKeys'] = $extra;
        }

        return $out;
    }

    private static function text(mixed $value): bool
    {
        return is_string($value) && strlen($value) <= self::TEXT_MAX_BYTES;
    }

    /**
     * Las claves que este código no lee, ordenadas y solo si son nombres
     * sensatos (una clave rara se anota como «?»: su presencia cuenta, su texto no).
     *
     * @param  array<mixed>  $node
     * @param  list<string>  $known
     * @return list<string>
     */
    private static function extraKeys(array $node, array $known): array
    {
        $extra = [];
        foreach (array_keys($node) as $clave) {
            $clave = (string) $clave;
            if (in_array($clave, $known, true)) {
                continue;
            }
            $extra[] = preg_match('/^[a-z_]{1,40}$/D', $clave) === 1 ? $clave : '?';
        }
        $extra = array_values(array_unique($extra));
        sort($extra, SORT_STRING);

        return $extra;
    }
}
