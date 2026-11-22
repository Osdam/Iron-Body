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
 *    `AppServiceProvider` (una vez);
 *  - nada del H9 (`Outreach`, sus órdenes y sus trabajos) nombra el
 *    adaptador ni su espacio: solo el puerto;
 *  - el adaptador habla SOLO con `https://api.openai.com/v1/responses`, sin
 *    redirecciones ni reintentos: una llamada es una petición, pase lo que pase;
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
        $implementan = [];
        $nombran = [];
        foreach ($this->phpFiles(['app', 'routes', 'config', 'bootstrap', 'database']) as $relativa) {
            $codigo = $this->codeOnly($relativa);
            if (preg_match('/\bimplements\b[^{]*\bReplyClassifierTransport\b/', $codigo) === 1) {
                $implementan[] = $relativa;
            }
            if (str_contains($codigo, 'OpenAiReplyClassifierTransport') || str_contains($codigo, 'ReplyClassification\\')) {
                $nombran[] = $relativa;
            }
        }
        $this->assertSame([self::ADAPTER], $implementan, 'un solo implementador del puerto con red en el código que corre');
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
}
