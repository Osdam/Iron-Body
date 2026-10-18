<?php

namespace Tests\Unit\UltronStudio;

use App\Services\UltronStudio\StudioCatalog;
use App\Services\UltronStudio\StudioContentHasher;
use App\Services\UltronStudio\StudioDraftNormalizer as N;
use Closure;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * El borrador que llega del CRM, contra el catálogo.
 *
 * LO QUE SE PROTEGE. Que nada que el catálogo no reconozca llegue a guardarse
 * (capas, parámetros, conexiones, valores fuera de tipo o de rango, texto con
 * controles), que el rechazo sea un 422 con la clave `content.*` del campo y
 * sin repetir lo que mandó el cliente, y que lo que falte se rellene con la
 * línea base. Hereda de Tests\TestCase porque ValidationException necesita el
 * validador de Laravel.
 */
class StudioDraftNormalizerTest extends TestCase
{
    private static function base(): array
    {
        $base = StudioCatalog::baseline();

        return [
            'definition' => ['layers' => StudioCatalog::layerIds(), 'edgeIds' => $base['edgeIds']],
            'configs' => $base['configs'],
            'layout' => ['positions' => $base['positions'], 'viewport' => null],
        ];
    }

    /** @return array<string,list<string>> */
    private function errores(array $content, ?array $catalog = null): array
    {
        try {
            N::normalize($content, $catalog);
        } catch (ValidationException $e) {
            return $e->errors();
        }
        $this->fail('El borrador debería rechazarse.');
    }

    /** Ordena las claves de los objetos, recursivamente. */
    private static function ordenar(mixed $v): mixed
    {
        if (! is_array($v)) {
            return $v;
        }
        $v = array_map(self::ordenar(...), $v);
        if (! array_is_list($v)) {
            ksort($v, SORT_STRING);
        }

        return $v;
    }

    public function test_the_baseline_comes_back_as_it_went_in(): void
    {
        $limpio = N::normalize(self::base());

        $this->assertSame(['definition', 'configs', 'layout'], array_keys($limpio));
        $this->assertSame(StudioCatalog::layerIds(), $limpio['definition']['layers']);
        $this->assertSame(StudioCatalog::baseline()['edgeIds'], $limpio['definition']['edgeIds']);
        $this->assertSame(StudioCatalog::baseline()['configs'], $limpio['configs']);
        $this->assertSame(StudioCatalog::layerIds(), array_keys($limpio['layout']['positions']), 'en el orden del catálogo');
        $this->assertSame(self::ordenar(StudioCatalog::baseline()['positions']), self::ordenar($limpio['layout']['positions']));
        $this->assertNull($limpio['layout']['viewport']);
    }

    public function test_what_is_missing_is_filled_from_the_baseline(): void
    {
        $limpio = N::normalize([
            'definition' => ['edgeIds' => ['E01']],
            'configs' => ['persona' => ['tone' => 'profesional']],
            'layout' => ['positions' => ['memory' => ['x' => -32, 'y' => 48]]],
        ]);
        $base = StudioCatalog::baseline();

        $this->assertSame(['tone' => 'profesional', 'emojis' => true, 'maxChars' => 320], $limpio['configs']['persona']);
        foreach (StudioCatalog::layerIds() as $capa) {
            if ($capa !== 'persona') {
                $this->assertSame($base['configs'][$capa], $limpio['configs'][$capa], $capa);
            }
            if ($capa !== 'memory') {
                $this->assertSame($base['positions'][$capa], $limpio['layout']['positions'][$capa], $capa);
            }
        }
        $this->assertSame(['x' => -32, 'y' => 48], $limpio['layout']['positions']['memory']);
        $this->assertNull($limpio['layout']['viewport']);

        $sinNada = N::normalize(['definition' => ['edgeIds' => []]]);
        $this->assertSame($base['configs'], $sinNada['configs']);
        $this->assertSame([], $sinNada['definition']['edgeIds']);
    }

    public function test_edges_come_back_in_catalog_order_and_incoming_layers_are_ignored(): void
    {
        $limpio = N::normalize(['definition' => ['layers' => ['knowledge'], 'edgeIds' => ['E19', 'E14', 'E01', 'E10']]]);

        $this->assertSame(['E01', 'E10', 'E14', 'E19'], $limpio['definition']['edgeIds']);
        $this->assertSame(StudioCatalog::layerIds(), $limpio['definition']['layers']);
    }

    public function test_tags_are_trimmed_empty_ones_dropped_and_order_kept(): void
    {
        $contenido = self::base();
        $contenido['configs']['knowledge']['sources'] = ['  b ', "\u{00A0}a\u{3000}", '', '   ', "\tc\n", "\u{FEFF}d"];
        $contenido['configs']['strategy']['offerPriority'] = [...array_fill(0, 20, 'x'), ' ', ''];

        $limpio = N::normalize($contenido);

        $this->assertSame(['b', 'a', 'c', 'd'], $limpio['configs']['knowledge']['sources']);
        $this->assertCount(20, $limpio['configs']['strategy']['offerPriority'], 'los vacíos no cuentan para el tope');
        $this->assertSame(str_repeat('é', 60), N::normalize(array_replace_recursive(self::base(), ['configs' => ['knowledge' => ['sources' => [str_repeat('é', 60)]]]]))['configs']['knowledge']['sources'][0], '60 caracteres, no 60 bytes');
    }

    public function test_the_viewport_takes_finite_numbers_with_a_bounded_scale(): void
    {
        foreach ([['x' => -120.5, 'y' => 40, 'scale' => 0.8], ['x' => 0, 'y' => 0, 'scale' => 0.3], ['x' => 1, 'y' => 2, 'scale' => 2.2], ['x' => 1, 'y' => 2, 'scale' => 1]] as $vista) {
            $contenido = self::base();
            $contenido['layout']['viewport'] = $vista + ['rotacion' => 90];

            $this->assertSame($vista, N::normalize($contenido)['layout']['viewport']);
        }
    }

    public function test_other_keys_are_dropped(): void
    {
        $contenido = self::base();
        $contenido['extra'] = 'x';
        $contenido['definition']['notas'] = 'x';
        $contenido['layout']['zoom'] = 3;
        $contenido['layout']['positions']['knowledge']['z'] = 7;

        $limpio = N::normalize($contenido);

        $this->assertSame(['definition', 'configs', 'layout'], array_keys($limpio));
        $this->assertSame(['layers', 'edgeIds'], array_keys($limpio['definition']));
        $this->assertSame(['positions', 'viewport'], array_keys($limpio['layout']));
        $this->assertSame(['x' => 672, 'y' => 0], $limpio['layout']['positions']['knowledge']);
    }

    /** @return iterable<string,array{Closure(array):array,string}> */
    public static function rechazos(): iterable
    {
        $cambia = static fn (array $parche): Closure => static fn (array $c): array => array_replace_recursive($c, $parche);
        $configs = static fn (string $capa, string $clave, mixed $valor): Closure => static function (array $c) use ($capa, $clave, $valor): array {
            $c['configs'][$capa][$clave] = $valor;

            return $c;
        };
        $aristas = static fn (mixed $ids): Closure => static function (array $c) use ($ids): array {
            $c['definition']['edgeIds'] = $ids;

            return $c;
        };
        $posicion = static fn (string $capa, mixed $valor): Closure => static function (array $c) use ($capa, $valor): array {
            $c['layout']['positions'][$capa] = $valor;

            return $c;
        };
        $vista = static fn (mixed $valor): Closure => static function (array $c) use ($valor): array {
            $c['layout']['viewport'] = $valor;

            return $c;
        };
        $quita = static fn (string $clave): Closure => static function (array $c) use ($clave): array {
            unset($c[$clave]);

            return $c;
        };

        yield 'más de 64 KB, aunque sea en una clave que se descarta' => [$cambia(['relleno' => str_repeat('a', 65536)]), 'content'];
        yield 'sin definición' => [$quita('definition'), 'content.definition'];
        yield 'definición que no es objeto' => [$cambia(['definition' => 'E01']), 'content.definition'];
        yield 'conexiones que no son lista' => [$aristas(['a' => 'E01']), 'content.definition.edgeIds'];
        yield 'conexiones como texto' => [$aristas('E01,E02'), 'content.definition.edgeIds'];
        yield 'conexión desconocida' => [$aristas(['E01', 'E99']), 'content.definition.edgeIds.1'];
        yield 'conexión repetida' => [$aristas(['E01', 'E02', 'E01']), 'content.definition.edgeIds.2'];
        yield 'conexión que no es texto' => [$aristas(['E01', 14]), 'content.definition.edgeIds.1'];
        yield 'configuración que no es objeto' => [$cambia(['configs' => 'todo']), 'content.configs'];
        yield 'capa desconocida' => [$configs('ventas', 'x', true), 'content.configs'];
        yield 'capa que no es objeto' => [static function (array $c): array {
            $c['configs']['persona'] = 'cercano';

            return $c;
        }, 'content.configs.persona'];
        yield 'parámetro desconocido' => [$configs('persona', 'color', 'rojo'), 'content.configs.persona'];
        yield 'interruptor como texto' => [$configs('persona', 'emojis', 'true'), 'content.configs.persona.emojis'];
        yield 'interruptor como número' => [$configs('governance', 'enabled', 1), 'content.configs.governance.enabled'];
        yield 'interruptor nulo' => [$configs('governance', 'enabled', null), 'content.configs.governance.enabled'];
        yield 'rango por encima' => [$configs('persona', 'maxChars', 481), 'content.configs.persona.maxChars'];
        yield 'rango por debajo' => [$configs('context', 'windowTurns', 3), 'content.configs.context.windowTurns'];
        yield 'rango decimal' => [$configs('persona', 'maxChars', 320.5), 'content.configs.persona.maxChars'];
        yield 'rango decimal con valor entero' => [$configs('persona', 'maxChars', 320.0), 'content.configs.persona.maxChars'];
        yield 'rango como texto' => [$configs('persona', 'maxChars', '320'), 'content.configs.persona.maxChars'];
        yield 'rango booleano' => [$configs('persona', 'maxChars', true), 'content.configs.persona.maxChars'];
        yield 'selección fuera de las opciones' => [$configs('persona', 'tone', 'agresivo'), 'content.configs.persona.tone'];
        yield 'selección con otra capitalización' => [$configs('intent', 'driver', 'Hermes'), 'content.configs.intent.driver'];
        yield 'selección que no es texto' => [$configs('persona', 'tone', ['cercano']), 'content.configs.persona.tone'];
        yield 'etiquetas como texto' => [$configs('knowledge', 'sources', 'a, b'), 'content.configs.knowledge.sources'];
        yield 'etiquetas como objeto' => [$configs('knowledge', 'sources', ['a' => 'b']), 'content.configs.knowledge.sources'];
        yield 'etiqueta que no es texto' => [$configs('knowledge', 'sources', ['ok', 7]), 'content.configs.knowledge.sources.1'];
        yield 'etiqueta de 61 caracteres' => [$configs('knowledge', 'sources', [str_repeat('x', 61)]), 'content.configs.knowledge.sources.0'];
        yield 'más de 20 etiquetas' => [$configs('strategy', 'offerPriority', array_fill(0, 21, 'x')), 'content.configs.strategy.offerPriority'];
        yield 'etiqueta con un control' => [$configs('knowledge', 'sources', ["a\u{0007}b"]), 'content.configs.knowledge.sources.0'];
        yield 'etiqueta con un salto de línea dentro' => [$configs('knowledge', 'sources', ["a\nb"]), 'content.configs.knowledge.sources.0'];
        yield 'etiqueta con un invisible' => [$configs('knowledge', 'sources', ["a\u{202E}b"]), 'content.configs.knowledge.sources.0'];
        yield 'etiqueta con UTF-8 inválido' => [$configs('knowledge', 'sources', ["a\xC3"]), 'content'];
        yield 'disposición que no es objeto' => [$cambia(['layout' => 'grande']), 'content.layout'];
        yield 'posiciones que no son objeto' => [static function (array $c): array {
            $c['layout']['positions'] = 'arriba';

            return $c;
        }, 'content.layout.positions'];
        yield 'posición de una capa desconocida' => [$posicion('ventas', ['x' => 0, 'y' => 0]), 'content.layout.positions'];
        yield 'posición decimal' => [$posicion('knowledge', ['x' => 1.5, 'y' => 0]), 'content.layout.positions.knowledge'];
        yield 'posición demasiado lejos' => [$posicion('knowledge', ['x' => 0, 'y' => -100001]), 'content.layout.positions.knowledge'];
        yield 'posición sin y' => [$posicion('knowledge', ['x' => 0]), 'content.layout.positions.knowledge'];
        yield 'posición como texto' => [$posicion('knowledge', '0,0'), 'content.layout.positions.knowledge'];
        yield 'vista que no es objeto' => [$vista('auto'), 'content.layout.viewport'];
        yield 'vista con escala pequeña' => [$vista(['x' => 0, 'y' => 0, 'scale' => 0.29]), 'content.layout.viewport.scale'];
        yield 'vista con escala grande' => [$vista(['x' => 0, 'y' => 0, 'scale' => 2.21]), 'content.layout.viewport.scale'];
        yield 'vista sin escala' => [$vista(['x' => 0, 'y' => 0]), 'content.layout.viewport.scale'];
        yield 'vista con x como texto' => [$vista(['x' => '0', 'y' => 0, 'scale' => 1]), 'content.layout.viewport.x'];
        yield 'vista con y infinita' => [$vista(['x' => 0, 'y' => INF, 'scale' => 1]), 'content'];
    }

    #[DataProvider('rechazos')]
    public function test_each_rejection_points_at_its_field(Closure $estropear, string $clave): void
    {
        $errores = $this->errores($estropear(self::base()));

        $this->assertArrayHasKey($clave, $errores, json_encode($errores, JSON_UNESCAPED_UNICODE));
        foreach (array_keys($errores) as $k) {
            $this->assertStringStartsWith('content', $k);
        }
    }

    public function test_text_and_textarea_use_plain_text_and_their_limits(): void
    {
        $catalogo = StudioCatalog::toArray();
        $catalogo['parameters']['persona'][] = ['key' => 'firma', 'label' => 'Firma', 'hint' => '', 'type' => 'text'];
        $catalogo['parameters']['persona'][] = ['key' => 'notas', 'label' => 'Notas', 'hint' => '', 'type' => 'textarea'];
        $catalogo['baseline']['configs']['persona'] += ['firma' => 'Iron Body', 'notas' => ''];
        $con = static fn (array $valores): array => array_replace_recursive(self::base(), ['configs' => ['persona' => $valores]]);

        $limpio = N::normalize($con(['firma' => str_repeat('a', 160), 'notas' => "Línea 1\n\tLínea 2\r\n".str_repeat('b', 580)]), $catalogo);
        $this->assertSame(str_repeat('a', 160), $limpio['configs']['persona']['firma']);
        $this->assertStringStartsWith("Línea 1\n\tLínea 2", $limpio['configs']['persona']['notas']);
        $this->assertSame('', N::normalize($con([]), $catalogo)['configs']['persona']['notas'], 'lo que falta, de la línea base');

        foreach ([
            ['firma', str_repeat('a', 161)],
            ['firma', "dos\nlíneas"],
            ['firma', "al\u{202E}revés"],
            ['firma', 12],
            ['notas', str_repeat('b', 601)],
            ['notas', "nul\u{0000}"],
            ['notas', "ancho\u{200B}cero"],
        ] as [$clave, $valor]) {
            $this->assertArrayHasKey("content.configs.persona.{$clave}", $this->errores($con([$clave => $valor]), $catalogo), json_encode($valor));
        }
    }

    public function test_messages_do_not_echo_what_the_client_sent(): void
    {
        $contenido = self::base();
        $contenido['configs']['<script>x</script>'] = ['a' => 1];
        $contenido['configs']['persona']['<img src=x>'] = 1;
        $contenido['layout']['positions']['<b>'] = ['x' => 0, 'y' => 0];
        $contenido['definition']['edgeIds'][] = '<i>E99</i>';

        $todo = json_encode($this->errores($contenido), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->assertStringNotContainsString('<', $todo);
        $this->assertStringNotContainsString('E99', $todo);
    }

    public function test_every_error_is_reported_at_once(): void
    {
        $contenido = self::base();
        $contenido['definition']['edgeIds'][] = 'E99';
        $contenido['configs']['persona']['maxChars'] = 9999;
        $contenido['layout']['viewport'] = ['x' => 0, 'y' => 0, 'scale' => 5];

        $this->assertEqualsCanonicalizing(
            ['content.definition.edgeIds.17', 'content.configs.persona.maxChars', 'content.layout.viewport.scale'],
            array_keys($this->errores($contenido)),
        );
    }

    public function test_the_normalized_draft_can_be_hashed(): void
    {
        $contenido = self::base();
        $contenido['layout']['viewport'] = ['x' => -120.5, 'y' => 40.25, 'scale' => 0.8];

        $limpio = N::normalize($contenido);

        $this->assertSame(
            StudioContentHasher::draftHash(StudioCatalog::VERSION, self::base()),
            StudioContentHasher::draftHash(StudioCatalog::VERSION, $limpio),
            'los decimales de la vista no entran en la huella',
        );
    }
}
