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
     * LA GUARDA. En TODA ruta que lleve la puerta del secreto interno, esa puerta corre antes que cualquier limitador,
     * en el orden EFECTIVO (el que Laravel aplica tras reordenar), no en el declarado. Una ruta interna nueva queda
     * cubierta sin tocar nada; si alguien quita la prioridad de `bootstrap/app.php`, esta prueba cae.
     */
    public function test_en_toda_ruta_interna_el_secreto_corre_antes_que_el_limitador(): void
    {
        // El núcleo HTTP es quien entrega al enrutador los alias, los grupos y la lista de prioridad: sin construirlo,
        // el orden «efectivo» sería el declarado y esta guarda no mediría nada.
        $this->app->make(HttpKernel::class);
        $router = app('router');
        $internas = array_values(array_filter(
            Route::getRoutes()->getRoutes(),
            static fn (RutaDeLaravel $r): bool => in_array('automation.internal', $r->gatherMiddleware(), true)
                || in_array(VerifyInternalAutomationSignature::class, $r->gatherMiddleware(), true),
        ));
        $this->assertGreaterThanOrEqual(15, count($internas), 'la guarda no vale: no encuentra las rutas internas');

        $malas = [];
        $conLimitador = 0;
        foreach ($internas as $ruta) {
            $efectivo = array_values(array_filter($router->gatherRouteMiddleware($ruta), 'is_string'));
            $clases = array_map(static fn (string $m): string => explode(':', $m, 2)[0], $efectivo);
            $puerta = array_search(VerifyInternalAutomationSignature::class, $clases, true);
            $limitadores = array_keys(array_filter($clases, static fn (string $c): bool => is_a($c, ThrottleRequests::class, true)));
            $this->assertNotFalse($puerta, 'ruta interna sin la puerta del secreto en su orden efectivo: '.$ruta->uri());
            if ($limitadores === []) {
                continue;
            }
            $conLimitador++;
            if ($puerta > min($limitadores)) {
                $malas[] = implode('|', $ruta->methods()).' '.$ruta->uri();
            }
        }

        $this->assertGreaterThanOrEqual(15, $conLimitador, 'la guarda no vale: casi ninguna ruta interna lleva limitador');
        $this->assertSame([], $malas, 'en estas rutas internas el limitador corre antes que el secreto (una petición sin credenciales gasta el cupo)');
    }
}
