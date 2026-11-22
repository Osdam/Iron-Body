<?php

namespace Tests\Feature\Outreach\Guards;

use App\Services\Outreach\Run\Classifier\ReplyClassifier;
use App\Services\Outreach\Run\Classifier\ReplyClassifierPrompt;
use App\Services\Outreach\Run\Classifier\ReplyClassifierTransport;
use App\Services\ReplyClassification\OpenAiReplyClassifierTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * AI9 · LA GUARDA DEL TRANSPORTE DEL CLASIFICADOR (contrato H9, «Enmienda
 * AI9»; §3 protección 4). Es el primer componente del H9 que habla con la red
 * sin pasar por una puerta nombrada en `OutreachStaticGuardsTest`, así que
 * tiene su guarda propia, sin ampliar ninguna lista de las de siempre:
 *
 *  - `App\Services\ReplyClassification` contiene SOLO el adaptador;
 *  - el adaptador es el ÚNICO implementador del puerto en `app/`, y el único
 *    sitio que lo nombra, aparte de él mismo, es la línea de enlace de
 *    `AppServiceProvider` (una vez). Los nombres se resuelven como los
 *    resuelve PHP (el espacio del fichero y sus `use`, con alias y en grupo),
 *    así que un `implements` con alias, con el nombre completo, desde una
 *    clase anónima o a través de una SUBINTERFAZ del puerto se ve igual que el
 *    directo; y el puerto no tiene subinterfaces (M3 de security);
 *  - nada del H9 (`Outreach`, sus órdenes y sus trabajos) nombra el
 *    adaptador ni su espacio: solo el puerto;
 *  - el adaptador habla SOLO con `https://api.openai.com/v1/responses`, sin
 *    redirecciones ni reintentos: una llamada es una petición, pase lo que pase;
 *  - el adaptador no tiene OTRO camino de red (M3): sus importaciones, sus
 *    funciones, sus métodos y sus llamadas estáticas son listas cerradas; ni
 *    `new`, ni nombres completos fuera de los `use`, ni `curl_*`,
 *    `file_get_contents`, `fopen`, `fsockopen`, `stream_socket_client` o
 *    parecidos (tampoco llamados por su nombre en un texto), y la URL no se
 *    arma por partes: es un único literal, sin concatenar ni interpolar, que
 *    solo usa `->post(self::URL, …)`;
 *  - sin su clave PROPIA (o con la compartida) no sale ninguna petición, ni
 *    por el adaptador ni por el clasificador entero.
 */
class ReplyClassifierStaticGuardsTest extends TestCase
{
    use RefreshDatabase;

    private const ADAPTER = 'app/Services/ReplyClassification/OpenAiReplyClassifierTransport.php';

    private const PROVIDER = 'app/Providers/AppServiceProvider.php';

    private const BIND_LINE = '$this->app->bind(ReplyClassifierTransport::class, OpenAiReplyClassifierTransport::class);';

    private const URL = 'https://api.openai.com/v1/responses';

    private const H9_ROOTS = ['app/Services/Outreach', 'app/Console/Commands/Outreach', 'app/Jobs/Outreach'];

    /** El PHP que corre (donde no puede haber otro implementador del puerto). */
    private const CODE_ROOTS = ['app', 'routes', 'config', 'bootstrap', 'database'];

    /** Lo único que importa el adaptador (lista cerrada, en orden): ningún otro cliente de red. */
    private const ADAPTER_IMPORTS = [
        'App\\Services\\Outreach\\Run\\Classifier\\ReplyClassifierPrompt',
        'App\\Services\\Outreach\\Run\\Classifier\\ReplyClassifierTransport',
        'GuzzleHttp\\Exception\\ConnectException',
        'GuzzleHttp\\Exception\\RequestException',
        'Illuminate\\Http\\Client\\ConnectionException',
        'Illuminate\\Http\\Client\\StrayRequestException',
        'Illuminate\\Support\\Facades\\Http',
        'Throwable',
    ];

    /** Las funciones que llama el adaptador (lista cerrada): ninguna abre red, ficheros ni procesos. */
    private const ADAPTER_FUNCTIONS = ['config', 'hash', 'hash_equals', 'hrtime', 'implode', 'in_array', 'intdiv', 'is_array', 'is_int', 'is_string', 'json_decode', 'max', 'min', 'preg_match', 'trim'];

    /** Los métodos que encadena (lista cerrada): la petición es esta cadena y nada la reescribe (ni middleware, ni opciones, ni otra URL). */
    private const ADAPTER_METHODS = ['acceptJson', 'asJson', 'configured', 'connectTimeout', 'getHandlerContext', 'getMessage', 'getPrevious', 'json', 'post', 'status', 'timeout', 'withoutRedirecting'];

    /** Las clases a las que llama con `::` (lista cerrada): la fachada, una vez y solo `withToken`, y las constantes del prompt. */
    private const ADAPTER_STATIC_CLASSES = ['Http', 'ReplyClassifierPrompt', 'self'];

    /** Los caminos de red (o de ficheros que aceptan URL) de PHP: ni llamados ni nombrados en un texto. */
    private const NETWORK_FUNCTIONS = '/^(curl_\w+|file_get_contents|file_put_contents|fopen|file|readfile|fsockopen|pfsockopen|stream_socket_client|stream_context_create|stream_get_contents|socket_\w+|get_headers|copy|simplexml_load_file|exec|shell_exec|system|passthru|proc_open|popen|call_user_func|call_user_func_array)$/i';

    /** Trozos de URL que solo puede llevar el literal de la URL oficial. */
    private const URL_FRAGMENTS = ['http', '://', '//', 'www.', '.com', '/v1', 'api.openai'];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['outreach.classifier.api_key' => 'sk-clasificador-propio-de-prueba', 'services.openai.api_key' => 'sk-compartida-de-prueba']);
    }

    public function test_el_espacio_del_transporte_contiene_solo_el_adaptador(): void
    {
        $ficheros = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Services/ReplyClassification'), RecursiveDirectoryIterator::SKIP_DOTS)) as $f) {
            $ficheros[] = substr($f->getPathname(), strlen(base_path()) + 1);
        }
        $this->assertSame([self::ADAPTER], $ficheros);
    }

    public function test_el_adaptador_es_el_unico_implementador_y_solo_lo_nombra_su_linea_de_enlace(): void
    {
        $declaraciones = [];
        $nombran = [];
        $alias = [];
        foreach ($this->phpFiles(self::CODE_ROOTS) as $relativa) {
            $codigo = $this->codeOnly($relativa);
            foreach ($this->declarations($relativa) as $d) {
                $declaraciones[] = $d + ['file' => $relativa];
            }
            if (str_contains($codigo, 'OpenAiReplyClassifierTransport') || str_contains($codigo, 'ReplyClassification\\')) {
                $nombran[] = $relativa;
            }
            if (str_contains($codigo, 'class_alias') && str_contains($codigo, 'ReplyClassifierTransport')) {
                $alias[] = $relativa;
            }
        }
        $this->assertGreaterThan(500, count($declaraciones), 'el rastreo ve las clases de app/');

        // El puerto y lo que lo amplía (una interfaz que extiende a otra de la lista), hasta que no cambie.
        $puerto = strtolower(ReplyClassifierTransport::class);
        $interfaces = [$puerto => true];
        do {
            $antes = count($interfaces);
            foreach ($declaraciones as $d) {
                if ($d['kind'] === 'interface' && array_intersect($d['extends'], array_keys($interfaces)) !== []) {
                    $interfaces[$d['name']] = true;
                }
            }
        } while (count($interfaces) !== $antes);
        $this->assertSame([$puerto], array_keys($interfaces), 'el puerto no tiene subinterfaces en el código que corre');

        // Quien implementa el puerto o una subinterfaz, o hereda de quien lo implementa (con o sin alias, también anónimas).
        $implementan = [];
        do {
            $antes = count($implementan);
            foreach ($declaraciones as $d) {
                if ($d['kind'] !== 'interface' && (array_intersect($d['implements'], array_keys($interfaces)) !== [] || array_intersect($d['extends'], array_keys($implementan)) !== [])) {
                    $implementan[$d['name']] = $d['file'];
                }
            }
        } while (count($implementan) !== $antes);
        $this->assertSame([strtolower(OpenAiReplyClassifierTransport::class) => self::ADAPTER], $implementan, 'un solo implementador del puerto con red en el código que corre');
        $this->assertSame([], $alias, 'ningún class_alias del puerto');
        $this->assertSame([self::PROVIDER, self::ADAPTER], $nombran, 'el adaptador solo lo nombra su enlace');
        $proveedor = $this->codeOnly(self::PROVIDER);
        $this->assertSame(1, substr_count($proveedor, self::BIND_LINE), 'una línea de enlace, con esa forma');
        $this->assertSame(1, substr_count($proveedor, 'OpenAiReplyClassifierTransport::class'), 'y ningún otro uso');
        $this->assertInstanceOf(OpenAiReplyClassifierTransport::class, app(ReplyClassifierTransport::class), 'el contenedor lo enlaza');
    }

    public function test_el_h9_solo_conoce_el_puerto(): void
    {
        $vistos = 0;
        foreach ($this->phpFiles(self::H9_ROOTS) as $relativa) {
            $vistos++;
            $codigo = $this->codeOnly($relativa);
            $this->assertStringNotContainsString('ReplyClassification\\', $codigo, "{$relativa} nombra el espacio del transporte");
            $this->assertStringNotContainsString('OpenAiReplyClassifierTransport', $codigo, "{$relativa} nombra el adaptador");
        }
        $this->assertGreaterThan(60, $vistos, 'el rastreo ve el H9');
    }

    public function test_el_adaptador_habla_solo_con_la_url_oficial_sin_redirecciones_ni_reintentos(): void
    {
        $codigo = $this->codeOnly(self::ADAPTER);
        preg_match_all('#https?://[^\'"\s]+#', $codigo, $urls);
        $this->assertSame([self::URL], array_values(array_unique($urls[0])), 'ninguna otra URL ni host');
        $this->assertSame(1, substr_count($codigo, 'Http::'), 'una sola petición posible');
        $this->assertSame(1, substr_count($codigo, 'Http::withToken('), 'que empieza con su clave');
        $this->assertSame(1, substr_count($codigo, '->post('), 'una sola petición');
        $this->assertSame(1, substr_count($codigo, '->post(self::URL, '), 'y va a la URL fija');
        $this->assertStringContainsString('->withoutRedirecting()', $codigo);
        $this->assertStringContainsString('->connectTimeout(', $codigo);
        $this->assertStringContainsString('->timeout(', $codigo);
        foreach (['->retry(', 'base_url', 'baseUrl(', 'withOptions(', 'Http::pool', 'Http::async'] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, $codigo, "nunca {$prohibido}");
        }

        // En ejecución: una redirección no se sigue (ni a otro host) y un 5xx, un 429 o un corte no se reintentan.
        Http::fake([
            'api.openai.com/*' => Http::sequence()
                ->push('', 302, ['Location' => 'https://otro-host.example/v1/responses'])
                ->push(['error' => ['message' => 'caído']], 500)
                ->push(['error' => ['code' => 'rate_limit_exceeded']], 429)
                ->pushFailedConnection(),
            'otro-host.example/*' => Http::response(['status' => 'completed'], 200),
        ]);
        $t = new OpenAiReplyClassifierTransport;
        foreach (['bad_request', 'server', 'rate_limited', 'connection'] as $i => $clase) {
            $this->assertSame($clase, $t->classify('gpt-4.1-mini', ReplyClassifierPrompt::INSTRUCTIONS, 'No me interesa', ReplyClassifierPrompt::schema(), 64, 5)['error']);
            Http::assertSentCount($i + 1);
        }
        Http::assertNotSent(static fn (Request $r): bool => ! str_starts_with($r->url(), self::URL));
    }

    /**
     * M3 de security: la red del adaptador es SOLO la cadena de `Http::` de
     * arriba. Todo lo demás está en listas cerradas o prohibido, con el
     * nombre resuelto por los tokens (los comentarios no cuentan).
     */
    public function test_el_adaptador_no_tiene_otro_camino_de_red_ni_arma_la_url_por_partes(): void
    {
        $t = $this->tokens(self::ADAPTER);
        $this->assertSame(self::ADAPTER_IMPORTS, array_values($this->imports($t)), 'sus importaciones, ni una más (ningún otro cliente)');

        $funciones = [];
        $metodos = [];
        $estaticas = [];
        $literales = [];
        $saltar = false;
        $nivel = 0;
        foreach ($t as $i => $x) {
            $id = is_array($x) ? $x[0] : $x;
            if ($id === '{' || $id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
                $nivel++;
            } elseif ($id === '}') {
                $nivel--;
            }
            // El `namespace` y los `use` de arriba ya están vistos: su nombre completo es legítimo ahí y solo ahí.
            $antes = $t[$i - 1] ?? null;
            $despues = $t[$i + 1] ?? null;
            if ($id === T_NAMESPACE || ($id === T_USE && $nivel === 0 && $despues !== '(')) {
                $saltar = true;
            }
            if ($saltar) {
                $saltar = $id !== ';';

                continue;
            }
            $idAntes = is_array($antes) ? $antes[0] : $antes;
            $idDespues = is_array($despues) ? $despues[0] : $despues;
            $this->assertNotContains($id, [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], 'ningún nombre completo fuera de los `use`: «'.(is_array($x) ? $x[1] : $x).'»');
            $this->assertNotContains($id, [T_NEW, T_EVAL, T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE, '`', T_START_HEREDOC, T_ENCAPSED_AND_WHITESPACE, '"', '.', T_CONCAT_EQUAL],
                'ni `new`, ni `eval`/`include`, ni la consola, ni textos armados (concatenados, interpolados o heredoc): «'.(is_array($x) ? $x[1] : $x).'»');
            if (($id === T_OBJECT_OPERATOR || $id === T_NULLSAFE_OBJECT_OPERATOR || $id === T_DOUBLE_COLON) && $idDespues !== T_STRING && $idDespues !== T_CLASS) {
                $this->fail('ninguna llamada ni propiedad con nombre dinámico (`->{$x}`, `::$x`)');
            }
            if ($id === T_STRING && $idDespues === '(') {
                match ($idAntes) {
                    T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR => $metodos[$x[1]] = true,
                    T_DOUBLE_COLON, T_FUNCTION, T_CONST => null,
                    default => $funciones[$x[1]] = true,
                };
            }
            if (($id === T_STRING || $id === T_STATIC) && $idDespues === T_DOUBLE_COLON) {
                $estaticas[is_array($x) ? $x[1] : $x] = true;
            }
            if ($id === T_CONSTANT_ENCAPSED_STRING) {
                $literales[] = substr($x[1], 1, -1);
            }
        }

        foreach (array_keys($funciones) as $f) {
            $this->assertDoesNotMatchRegularExpression(self::NETWORK_FUNCTIONS, $f, "nunca {$f}(): es otro camino de red");
        }
        $this->assertSame([], array_values(array_diff(array_keys($funciones), self::ADAPTER_FUNCTIONS)), 'solo sus funciones (lista cerrada)');
        $this->assertSame([], array_values(array_diff(array_keys($metodos), self::ADAPTER_METHODS)), 'solo sus métodos: nada reescribe la petición');
        $this->assertSame([], array_values(array_diff(array_keys($estaticas), self::ADAPTER_STATIC_CLASSES)), 'solo esas clases con `::`');

        // Ningún texto nombra un camino de red (una llamada por su nombre) ni lleva un trozo de URL, salvo la URL oficial, una vez.
        $conUrl = [];
        foreach ($literales as $l) {
            $this->assertDoesNotMatchRegularExpression(self::NETWORK_FUNCTIONS, $l, "nunca «{$l}» como texto");
            foreach (self::URL_FRAGMENTS as $trozo) {
                if (stripos($l, $trozo) !== false) {
                    $conUrl[] = $l;

                    break;
                }
            }
        }
        $this->assertSame([self::URL], $conUrl, 'ningún trozo de URL fuera de la URL oficial');
        $codigo = $this->codeOnly(self::ADAPTER);
        $this->assertSame(1, preg_match_all('/\bconst\s+URL\s*=\s*\'([^\']*)\'\s*;/', $codigo, $m), 'la URL es UNA constante de UN literal');
        $this->assertSame(self::URL, $m[1][0]);
        $this->assertSame(1, substr_count($codigo, '::URL'), 'y solo la usa la petición');
        $this->assertSame(1, substr_count($codigo, '->post(self::URL, '));
    }

    public function test_sin_clave_propia_no_sale_ninguna_peticion_ni_por_el_clasificador(): void
    {
        Http::fake();
        foreach (['', '   ', 'sk-compartida-de-prueba'] as $clave) {
            config(['outreach.classifier.api_key' => $clave]);
            $this->assertFalse(app(ReplyClassifierTransport::class)->configured(), "clave «{$clave}»");
            $this->assertSame('not_configured', app(ReplyClassifierTransport::class)->classify('gpt-4.1-mini', 'x', 'No me interesa', [], 64, 5)['error']);
            // El clasificador entero, encendido y con cupo, techo, modelo y precios: sin clave, no llega a pedir el texto ni a reservar.
            config(['outreach.enabled' => true, 'outreach.mode' => 'live', 'outreach.classifier.enabled' => true, 'outreach.classifier.daily_cap' => 10,
                'outreach.classifier.daily_budget_usd' => '1', 'outreach.classifier.model' => 'gpt-4.1-mini',
                'outreach.classifier.price_input_usd_per_mtok' => '0.40', 'outreach.classifier.price_output_usd_per_mtok' => '1.60']);
            $r = (new ReplyClassifier)->classifyReply((object) ['uuid' => '0b3a6c1e-7f4d-4a2b-9c8d-1e2f3a4b5c6d'], 1, 1, [1], now()->toDateTimeImmutable(), now());
            $this->assertSame(['skipped', 'not_configured', 0], [$r['state'], $r['reason'], $r['calls']]);
        }
        Http::assertNothingSent();
        $this->assertSame(0, DB::table('outreach_budget_counters')->count(), 'ni una reserva');
    }

    public function test_un_corte_de_conexion_antes_de_salir_tampoco_se_reintenta(): void
    {
        $intentos = 0;
        Http::fake(['api.openai.com/*' => static function () use (&$intentos): never {
            $intentos++;
            throw new ConnectionException('cURL error 7: Failed to connect');
        }]);
        $this->assertSame('connection', (new OpenAiReplyClassifierTransport)->classify('gpt-4.1-mini', 'x', 'hola', [], 64, 5)['error']);
        $this->assertSame(1, $intentos);
    }

    // ────────────────────────────────────────────────────────────────────────

    /** @param  list<string>  $raices @return list<string> */
    private function phpFiles(array $raices): array
    {
        $out = [];
        foreach ($raices as $raiz) {
            $absoluta = base_path($raiz);
            if (! is_dir($absoluta)) {
                continue;
            }
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($absoluta, RecursiveDirectoryIterator::SKIP_DOTS)) as $f) {
                if ($f->isFile() && $f->getExtension() === 'php' && ! str_contains($f->getPathname(), '/bootstrap/cache/')) {
                    $out[] = substr($f->getPathname(), strlen(base_path()) + 1);
                }
            }
        }
        sort($out);

        return $out;
    }

    private function codeOnly(string $relativa): string
    {
        $out = '';
        foreach (token_get_all((string) file_get_contents(base_path($relativa))) as $t) {
            if (! is_array($t) || ! in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $out .= is_array($t) ? $t[1] : $t;
            }
        }

        return $out;
    }

    /** Los tokens de un fichero sin espacios ni comentarios. @return list<array{0: int, 1: string, 2: int}|string> */
    private function tokens(string $relativa): array
    {
        return array_values(array_filter(token_get_all((string) file_get_contents(base_path($relativa))),
            static fn (mixed $t): bool => ! is_array($t) || ! in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
    }

    /**
     * Los `use` de clase de un fichero (los de arriba, no los de `trait` ni
     * los de una función anónima): alias en minúsculas → nombre completo. Con
     * alias (`as`) y en grupo (`use A\{B, C as D}`); `use function` y
     * `use const` no son clases.
     *
     * @param  list<array{0: int, 1: string, 2: int}|string>  $t
     * @return array<string, string>
     */
    private function imports(array $t): array
    {
        $alias = [];
        $nivel = 0;
        $n = count($t);
        for ($i = 0; $i < $n; $i++) {
            $id = is_array($t[$i]) ? $t[$i][0] : $t[$i];
            if ($id === '{' || $id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
                $nivel++;
            } elseif ($id === '}') {
                $nivel--;
            }
            if ($id !== T_USE || $nivel !== 0) {
                continue;
            }
            $siguiente = $t[$i + 1] ?? null;
            if ($siguiente === '(' || (is_array($siguiente) && in_array($siguiente[0], [T_FUNCTION, T_CONST], true))) {
                continue; // `function () use (…)` arriba del todo (rutas, configuración), `use function` y `use const`
            }
            $prefijo = '';
            $nombre = null;
            $esAlias = false;
            for ($i++; $i < $n && $t[$i] !== ';'; $i++) {
                $x = $t[$i];
                $idx = is_array($x) ? $x[0] : $x;
                if (in_array($idx, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                    if ($esAlias && $nombre !== null) {
                        $alias[strtolower($x[1])] = $nombre;
                        $nombre = null;
                        $esAlias = false;
                    } else {
                        $nombre = ltrim($prefijo.$x[1], '\\');
                    }
                } elseif ($idx === T_AS) {
                    $esAlias = true;
                } elseif ($idx === T_NS_SEPARATOR && $nombre !== null) {
                    // `use A\B\{…}`: lo leído es el prefijo del grupo.
                    $prefijo = $nombre.'\\';
                    $nombre = null;
                } elseif (in_array($idx, [',', '}'], true)) {
                    if ($nombre !== null) {
                        $alias[strtolower(substr((string) strrchr('\\'.$nombre, '\\'), 1))] = $nombre;
                    }
                    $nombre = null;
                    $esAlias = false;
                }
            }
            if ($nombre !== null) {
                $alias[strtolower(substr((string) strrchr('\\'.$nombre, '\\'), 1))] = $nombre;
            }
        }

        return $alias;
    }

    /**
     * Las clases, interfaces y enums de un fichero (también las clases
     * anónimas), con los nombres de `extends` e `implements` resueltos como
     * los resuelve PHP: completo con `\`, relativo con `namespace\`, por el
     * alias de un `use`, o en el espacio del fichero. Todo en minúsculas
     * (PHP no distingue).
     *
     * @return list<array{kind: string, name: string, extends: list<string>, implements: list<string>}>
     */
    private function declarations(string $relativa): array
    {
        $t = $this->tokens($relativa);
        $alias = $this->imports($t);
        $espacio = '';
        $salida = [];
        $n = count($t);
        $resolver = static function (string $nombre) use (&$espacio, $alias): string {
            if (str_starts_with($nombre, '\\')) {
                return strtolower(ltrim($nombre, '\\'));
            }
            if (str_starts_with(strtolower($nombre), 'namespace\\')) {
                return strtolower(($espacio !== '' ? $espacio.'\\' : '').substr($nombre, 10));
            }
            $partes = explode('\\', $nombre, 2);
            $importado = $alias[strtolower($partes[0])] ?? null;
            if ($importado !== null) {
                return strtolower(isset($partes[1]) ? $importado.'\\'.$partes[1] : $importado);
            }

            return strtolower(($espacio !== '' ? $espacio.'\\' : '').$nombre);
        };
        for ($i = 0; $i < $n; $i++) {
            $x = $t[$i];
            $id = is_array($x) ? $x[0] : $x;
            if ($id === T_NAMESPACE && is_array($t[$i + 1] ?? null) && in_array($t[$i + 1][0], [T_STRING, T_NAME_QUALIFIED], true)) {
                $espacio = $t[$i + 1][1];

                continue;
            }
            if (! in_array($id, [T_CLASS, T_INTERFACE, T_ENUM], true)) {
                continue;
            }
            $antes = $t[$i - 1] ?? null;
            if (is_array($antes) && $antes[0] === T_DOUBLE_COLON) {
                continue; // `X::class`
            }
            $anonima = is_array($antes) && $antes[0] === T_NEW;
            $tipo = match ($id) {
                T_INTERFACE => 'interface',
                T_ENUM => 'enum',
                default => 'class',
            };
            $nombre = $anonima ? 'class@anonymous:'.$relativa.':'.$x[2] : null;
            $lista = null;
            $extends = [];
            $implements = [];
            $parentesis = 0;
            for ($j = $i + 1; $j < $n; $j++) {
                $y = $t[$j];
                $idy = is_array($y) ? $y[0] : $y;
                if ($idy === '(') {
                    $parentesis++;
                } elseif ($idy === ')') {
                    $parentesis--;
                }
                if ($parentesis > 0 || $idy === ')') {
                    continue; // los argumentos de una clase anónima
                }
                if ($idy === '{') {
                    break;
                }
                if ($idy === T_EXTENDS) {
                    $lista = 'extends';
                } elseif ($idy === T_IMPLEMENTS) {
                    $lista = 'implements';
                } elseif (in_array($idy, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)) {
                    if ($nombre === null) {
                        $nombre = strtolower(($espacio !== '' ? $espacio.'\\' : '').$y[1]);
                    } elseif ($lista === 'extends') {
                        $extends[] = $resolver($y[1]);
                    } elseif ($lista === 'implements') {
                        $implements[] = $resolver($y[1]);
                    }
                }
            }
            if ($nombre !== null) {
                $salida[] = ['kind' => $tipo, 'name' => $nombre, 'extends' => $extends, 'implements' => $implements];
            }
            $i = $j;
        }

        return $salida;
    }
}
