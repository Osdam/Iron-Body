<?php

namespace Tests\Feature\Marketing;

use App\Http\Middleware\VerifyInternalAutomationSignature;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route as RutaDeLaravel;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * QUIEN NO TIENE EL SECRETO NO GASTA EL CUPO DE n8n (PT-19 del control de cierre de ULTRON STUDIO, 22-11-2026).
 *
 * Las rutas internas declaran primero la puerta del secreto (`automation.internal`) y después su limitador
 * (`throttle:internal-…`), pero Laravel reordena por su lista de prioridad y el limitador corría ANTES: una petición
 * SIN secreto recibía su 401 y aun así descontaba del cubo, que es global (todo el tráfico entra desde n8n). Con 120
 * peticiones por minuto sin credenciales, desde internet, `decide` y `commit` recibían 429 con su secreto válido: el
 * asesor de WhatsApp se quedaba sin responder; con 600, las notificaciones a los socios. Lo encontró la revisión de
 * seguridad de PT-15 (P-1) y se comprobó en producción en solo lectura
 * (`docs/ultron-studio/ops/evidencia-h9/2026-11-22/10-puertas-internas-cupo-sin-secreto.txt`).
 *
 * La corrección está en `bootstrap/app.php`: el secreto se comprueba ANTES de contar. Aquí se fija el efecto (lo que
 * recibe n8n no cambia por mucho que inunde quien no tiene el secreto), que el 401 no enseña el contador, que el tope
 * del tráfico legítimo sigue existiendo y, con una guarda sobre TODAS las rutas internas, que ninguna ruta futura
 * vuelve a contar antes de autenticar.
 */
class PuertasInternasSecretoAntesDelCupoTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'secreto-interno-de-la-prueba';

    /** Peticiones de la inundación: más que cualquier tope de estas pruebas. */
    private const INUNDACION = 12;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('automation.internal_secret', self::SECRET);
        // Topes pequeños, con sitio para las llamadas legítimas de cada prueba y no para la inundación.
        config()->set('automation.rate_limits.automation', 8);
        config()->set('automation.rate_limits.marketing_ai', 8);
        // El cubo propio de `ai/check` (Enmienda A4-6): la clave no existe en todas las ramas; fijarla es inocuo.
        config()->set('automation.rate_limits.marketing_ai_check', 8);
    }

    private function conSecreto(): array
    {
        return ['Authorization' => 'Bearer '.self::SECRET];
    }

    /** Las tres puertas del turno del asesor, con un cuerpo que el controlador rechaza por sí mismo. */
    private function turno(string $puerta, array $cabeceras): int
    {
        return $this->postJson('/api/internal/marketing/ai/'.$puerta, [
            'conversation_id' => 999999, 'message_id' => 999999,
        ], $cabeceras)->getStatusCode();
    }

    private function aviso(array $cabeceras): int
    {
        return $this->postJson('/api/internal/automation/notify-member', [
            'member_id' => 1, 'type' => 'daily.compliance_missing', 'title' => 'Aun puedes cumplir hoy', 'body' => 'Texto.',
        ], $cabeceras)->getStatusCode();
    }

    /** Lo que recibe n8n, con su secreto, en las tres puertas del turno. */
    private function loQueRecibeN8n(): array
    {
        $out = [];
        foreach (['decide', 'commit', 'check'] as $puerta) {
            $out[$puerta] = $this->turno($puerta, $this->conSecreto());
        }

        return $out;
    }

    public static function quienNoTieneElSecreto(): array
    {
        return [
            'sin cabecera' => [[]],
            'con un secreto equivocado' => [['Authorization' => 'Bearer no-es-el-secreto']],
            'con un esquema que no es Bearer' => [['Authorization' => 'Basic '.self::SECRET]],
        ];
    }

    #[DataProvider('quienNoTieneElSecreto')]
    public function test_una_inundacion_sin_secreto_no_cambia_lo_que_recibe_el_asesor(array $cabeceras): void
    {
        $sinInundar = $this->loQueRecibeN8n();
        $this->assertNotContains(429, $sinInundar, 'la prueba no vale: n8n ya recibe 429 sin que nadie inunde');
        $this->assertNotContains(401, $sinInundar, 'la prueba no vale: el secreto de la prueba no abre la puerta');
        $this->assertNotContains(404, $sinInundar, 'la prueba no vale: la petición no llega al controlador');

        foreach (['decide', 'commit', 'check'] as $puerta) {
            foreach (range(1, self::INUNDACION) as $i) {
                $this->assertSame(401, $this->turno($puerta, $cabeceras), "sin el secreto, {$puerta} responde siempre 401 (petición {$i})");
            }
        }

        $this->assertSame(
            $sinInundar,
            $this->loQueRecibeN8n(),
            'las peticiones sin secreto gastaron el cupo del asesor: n8n recibe otra cosa con su secreto válido',
        );
    }

    #[DataProvider('quienNoTieneElSecreto')]
    public function test_una_inundacion_sin_secreto_no_cambia_lo_que_reciben_las_notificaciones(array $cabeceras): void
    {
        $sinInundar = $this->aviso($this->conSecreto());
        $this->assertNotContains($sinInundar, [429, 401], 'la prueba no vale: la puerta de notificaciones no responde con el secreto');

        foreach (range(1, self::INUNDACION) as $i) {
            $this->assertSame(401, $this->aviso($cabeceras), "sin el secreto, la puerta responde siempre 401 (petición {$i})");
        }

        $this->assertSame($sinInundar, $this->aviso($this->conSecreto()), 'las peticiones sin secreto gastaron el cupo de las notificaciones');
    }

    /** Quien no tiene credenciales no ve cuántas peticiones lleva n8n en el minuto (NB-3 de seguridad). */
    public function test_el_401_no_ensena_el_contador_del_cupo(): void
    {
        $this->turno('decide', $this->conSecreto());

        foreach (['/api/internal/marketing/ai/doctor', '/api/internal/marketing/knowledge'] as $ruta) {
            $r = $this->getJson($ruta);
            $r->assertStatus(401);
            foreach (['X-RateLimit-Limit', 'X-RateLimit-Remaining', 'Retry-After', 'X-RateLimit-Reset'] as $cabecera) {
                $this->assertFalse($r->headers->has($cabecera), "el 401 de {$ruta} enseña {$cabecera}: ".$r->headers->get($cabecera));
            }
        }
    }

    /** El tráfico legítimo conserva su tope: esto no es abrir la mano (con secreto, de doce peticiones se rechazan cuatro). */
    public function test_con_el_secreto_el_tope_sigue_existiendo(): void
    {
        $asesor = 0;
        foreach (range(1, self::INUNDACION) as $i) {
            $asesor += $this->turno('decide', $this->conSecreto()) === 429 ? 1 : 0;
        }
        $this->assertSame(self::INUNDACION - 8, $asesor, 'con un tope de 8, de 12 peticiones del asesor se rechazan 4');

        $avisos = 0;
        foreach (range(1, self::INUNDACION) as $i) {
            $avisos += $this->aviso($this->conSecreto()) === 429 ? 1 : 0;
        }
        $this->assertSame(self::INUNDACION - 8, $avisos, 'con un tope de 8, de 12 notificaciones se rechazan 4');
    }

    /** Y quien SÍ tiene el secreto y agota su cupo recibe el 429 con sus cabeceras, como siempre. */
    public function test_el_429_del_trafico_legitimo_conserva_sus_cabeceras(): void
    {
        foreach (range(1, 8) as $i) {
            $this->turno('decide', $this->conSecreto());
        }

        $r = $this->postJson('/api/internal/marketing/ai/decide', ['conversation_id' => 999999, 'message_id' => 999999], $this->conSecreto());

        $r->assertStatus(429);
        $this->assertTrue($r->headers->has('Retry-After'), 'el 429 legítimo dejó de decir cuándo reintentar');
    }

    /**
     * Con el secreto válido y la firma HMAC opcional (`X-IronBody-Signature`) mala, el 403 tampoco cuenta ni enseña el
     * contador: quien llega ahí ya tiene el secreto y podría gastar el cupo con llamadas válidas, que sí cuentan.
     */
    public function test_una_firma_mala_con_el_secreto_valido_no_cuenta_ni_cambia_lo_que_recibe_el_asesor(): void
    {
        $sinInundar = $this->loQueRecibeN8n();
        $firmaMala = $this->conSecreto() + ['X-IronBody-Signature' => str_repeat('0', 64)];

        foreach (range(1, self::INUNDACION) as $i) {
            $r = $this->postJson('/api/internal/marketing/ai/decide', ['conversation_id' => 999999, 'message_id' => 999999], $firmaMala);
            $r->assertStatus(403);
            $this->assertFalse($r->headers->has('X-RateLimit-Limit'), "el 403 de la firma enseña el contador (petición {$i})");
        }

        $this->assertSame($sinInundar, $this->loQueRecibeN8n(), 'las peticiones con la firma mala gastaron el cupo del asesor');
    }

    /** Los cubos GLOBALES de n8n: gastarlos sin el secreto es lo que dejaba mudo al asesor. */
    private const CUBOS_DE_N8N = ['internal-automation', 'internal-marketing-ai', 'internal-marketing-ai-check'];

    /**
     * Lo que la guarda considera mal, sobre el orden EFECTIVO (el que Laravel aplica tras reordenar) y por la clase
     * RESUELTA de cada middleware, no por el alias con que se declaró:
     *  - una ruta bajo `api/internal/` sin la puerta del secreto;
     *  - una ruta que usa un cubo global de n8n sin la puerta (aunque no esté bajo `api/internal/`);
     *  - una ruta con la puerta y con un limitador que corre antes que ella.
     *
     * @return array{malas: list<string>, internas: int, conCuboDeN8n: int}
     */
    private function loQueVeLaGuarda(): array
    {
        // El núcleo HTTP es quien entrega al enrutador los alias, los grupos y la lista de prioridad: sin construirlo,
        // el orden «efectivo» sería el declarado y la guarda no mediría nada.
        $this->app->make(HttpKernel::class);
        $router = app('router');
        $malas = [];
        $internas = 0;
        $conCuboDeN8n = 0;

        foreach (Route::getRoutes()->getRoutes() as $ruta) {
            /** @var RutaDeLaravel $ruta */
            $efectivo = array_values(array_filter($router->gatherRouteMiddleware($ruta), 'is_string'));
            $puerta = null;
            $primerLimitador = null;
            $usaCuboDeN8n = false;
            foreach ($efectivo as $i => $m) {
                [$clase, $parametros] = array_pad(explode(':', $m, 2), 2, '');
                if ($puerta === null && is_a($clase, VerifyInternalAutomationSignature::class, true)) {
                    $puerta = $i;
                }
                if (is_a($clase, ThrottleRequests::class, true)) {
                    $primerLimitador ??= $i;
                    $usaCuboDeN8n = $usaCuboDeN8n || in_array(explode(',', $parametros)[0], self::CUBOS_DE_N8N, true);
                }
            }
            $nombre = implode('|', $ruta->methods()).' '.$ruta->uri();
            $esInterna = str_starts_with($ruta->uri(), 'api/internal/');
            $internas += $esInterna ? 1 : 0;
            $conCuboDeN8n += $usaCuboDeN8n ? 1 : 0;

            if ($puerta === null) {
                if ($esInterna) {
                    $malas[] = $nombre.' · ruta interna sin la puerta del secreto';
                } elseif ($usaCuboDeN8n) {
                    $malas[] = $nombre.' · gasta un cubo de n8n sin la puerta del secreto';
                }

                continue;
            }
            if ($primerLimitador !== null && $primerLimitador < $puerta) {
                $malas[] = $nombre.' · el limitador corre antes que el secreto';
            }
        }

        return ['malas' => $malas, 'internas' => $internas, 'conCuboDeN8n' => $conCuboDeN8n];
    }

    /**
     * LA GUARDA. Ninguna ruta gasta un cubo de n8n, ni vive bajo `api/internal/`, sin que la puerta del secreto corra
     * antes que su limitador. Una ruta interna nueva queda cubierta sin tocar nada; si alguien quita la prioridad de
     * `bootstrap/app.php`, esta prueba cae con las 21.
     */
    public function test_en_toda_ruta_interna_el_secreto_corre_antes_que_el_limitador(): void
    {
        $visto = $this->loQueVeLaGuarda();

        $this->assertGreaterThanOrEqual(15, $visto['internas'], 'la guarda no vale: no encuentra las rutas internas');
        $this->assertGreaterThanOrEqual(15, $visto['conCuboDeN8n'], 'la guarda no vale: casi ninguna ruta usa los cubos de n8n');
        $this->assertSame([], $visto['malas'], 'una petición sin credenciales gastaría el cupo de n8n en estas rutas');
    }

    /**
     * Y la guarda VE lo que tiene que ver (NB-1 de la revisión de seguridad de API): una ruta que comparta un cubo de
     * n8n sin la puerta reabre el defecto aunque no esté bajo `api/internal/`, y una ruta interna sin puerta es una
     * ruta sin autenticar. Se registran aquí, solo para esta prueba.
     */
    public function test_la_guarda_ve_una_ruta_que_gasta_el_cubo_de_n8n_sin_la_puerta_y_una_interna_sin_puerta(): void
    {
        Route::middleware(['api', 'throttle:internal-marketing-ai'])->get('api/sonda/comparte-el-cubo', static fn () => 'ok');
        Route::middleware(['api'])->get('api/internal/sonda/sin-puerta', static fn () => 'ok');
        // Declarada al revés, con la puerta: la prioridad la corrige y la guarda NO debe quejarse de ella.
        Route::middleware(['api', 'throttle:internal-marketing-ai', 'automation.internal'])->get('api/internal/sonda/al-reves', static fn () => 'ok');

        $malas = $this->loQueVeLaGuarda()['malas'];

        $this->assertSame([
            'GET|HEAD api/sonda/comparte-el-cubo · gasta un cubo de n8n sin la puerta del secreto',
            'GET|HEAD api/internal/sonda/sin-puerta · ruta interna sin la puerta del secreto',
        ], $malas);
    }
}
