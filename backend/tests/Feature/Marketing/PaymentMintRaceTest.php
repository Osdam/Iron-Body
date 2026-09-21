<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Services\Marketing\SalesPaymentGuardrailService;
use App\Services\Marketing\WompiPaymentLinkService;
use App\Services\Wompi\WompiSignatureService;
use App\Services\Wompi\WompiTransactionService;
use BadMethodCallException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery;
use RuntimeException;
use Tests\Feature\Payments\PaymentReferenceIdempotencyTest;
use Tests\TestCase;

/**
 * DOS PETICIONES A LA VEZ NO ACUÑAN DOS COBROS.
 *
 * La regla «un solo enlace vivo por (lead, plan)» se resolvía leyendo y luego
 * creando, sin nada que serializara las dos cosas. Dos peticiones simultáneas
 * —la misma persona tocando dos veces, un reintento de n8n, el panel y el bot a
 * la vez— leen las dos que no hay cobro en vuelo y crean cada una el suyo.
 *
 * No hay unicidad que lo detenga: la `idempotency_key` lleva un uuid por
 * intento, y la `reference` se regenera mientras choque. El inventario lo
 * dejó por escrito: «en la BASE, dos checkouts simultáneos para el mismo lead y
 * plan son dos filas legítimas».
 *
 * Y dos filas legítimas son dos URLs pagables al mismo tiempo, que es
 * exactamente el daño que las guardas de arriba existen para impedir: un
 * checkout de Wompi no se anula desde aquí.
 *
 * La carrera se reproduce como ya se reproduce en {@see PaymentReferenceIdempotencyTest}:
 * enganchando el «otro proceso» a la consulta del primero, dentro de su
 * ventana. Sin el cerrojo, esto deja dos filas.
 *
 * QUÉ PRUEBA Y QUÉ NO. El «otro proceso» corre en la pila del primero, en el
 * mismo proceso PHP: esto demuestra que la ventana existe, que el cerrojo se
 * toma antes de leer y que quien entra dentro no acuña. NO demuestra la
 * serialización entre procesos distintos, que depende del store de caché y no
 * se puede montar desde un test —por eso se comprobó aparte, en producción,
 * con dos adquisiciones seguidas de la misma clave—.
 */
class PaymentMintRaceTest extends TestCase
{
    use RefreshDatabase;

    private static bool $carreraDisparada = false;

    /** Consultas del par (lead, plan) vistas hasta ahora en el proceso de fuera. */
    private static int $consultasDelPar = 0;

    private Plan $plan;

    private MarketingLead $lead;

    private MarketingConversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        self::$carreraDisparada = false;
        self::$consultasDelPar = 0;

        config()->set('wompi.env', 'production');
        config()->set('wompi.public_key', 'pub_prod_test_key');
        config()->set('wompi.private_key', 'prv_prod_test_key');
        config()->set('wompi.integrity_secret', 'integrity_test_secret');
        config()->set('wompi.events_secret', 'events_test_secret');
        config()->set('wompi.checkout.base_url', 'https://checkout.wompi.co/p/');
        config()->set('wompi.checkout.redirect_url', 'https://web.ironbodyneiva.cloud/pago');
        config()->set('marketing.ultron.payment_links_enabled', true);

        // Quien llega segundo no espera: se rinde en el acto. En producción
        // espera y acaba reutilizando el cobro del primero; aquí lo que se mide
        // es que NO cree el suyo, y esperar sólo añadiría segundos al test.
        config()->set('wompi.checkout.mint_wait_seconds', 0);

        $this->plan = Plan::create(['name' => 'Plan Mensual', 'price' => 80000, 'duration_days' => 30, 'active' => true, 'sellable' => true]);
        $this->lead = MarketingLead::create(['channel' => 'whatsapp', 'source' => 'inbound', 'phone' => '3150536026', 'meta_user_id' => '573150536026', 'name' => 'Prospecto', 'status' => MarketingLead::STATUS_HOT]);
        $this->conversation = MarketingConversation::create(['lead_id' => $this->lead->id, 'channel' => 'whatsapp', 'status' => 'open', 'ai_enabled' => true, 'human_takeover' => false]);
    }

    private function generar(): array
    {
        return WompiPaymentLinkService::make()->generateForLead($this->lead, $this->plan, [
            'channel' => 'whatsapp',
            'conversation_id' => $this->conversation->id,
        ]);
    }

    /** ¿Es una de las consultas con las que se busca cobro para este par? */
    private function esLaBusquedaDelPar(string $sql): bool
    {
        return str_contains($sql, 'payment_transactions')
            && str_contains(strtolower($sql), 'select')
            && str_contains($sql, 'idempotency_key');
    }

    public function test_two_simultaneous_requests_mint_one_checkout(): void
    {
        $segundo = null;

        /*
         * La ventana exacta.
         *
         * El primero hace DOS consultas al par: «¿hay un pago aprobado?» y
         * «¿hay uno en vuelo?». El competidor entra DESPUÉS de la segunda, que
         * es cuando el primero ya ha leído que no hay nada y va a insertar. Si
         * entrara antes, el primero acabaría encontrando el cobro del otro y
         * reutilizándolo —una carrera que sale bien por casualidad, y que como
         * prueba no vale—.
         */
        DB::listen(function ($query) use (&$segundo) {
            if (self::$carreraDisparada || ! $this->esLaBusquedaDelPar($query->sql)) {
                return;
            }
            if (++self::$consultasDelPar < 2) {
                return;
            }
            self::$carreraDisparada = true;

            // El «otro proceso», dentro de la ventana del primero.
            $segundo = $this->generar();
        });

        $primero = $this->generar();

        $this->assertTrue(self::$carreraDisparada, 'la carrera no llegó a dispararse');

        $this->assertSame(
            1,
            PaymentTransaction::count(),
            'dos peticiones simultáneas acuñaron dos cobros para el mismo lead y plan',
        );

        // El primero cobra.
        $this->assertNotEmpty($primero['payment_url'] ?? null);

        // El segundo se va sin enlace y con su motivo, no con uno inventado.
        // (`?? ` no vale para comprobarlo: un `payment_url` nulo es justo lo que
        // se espera, y el operador no distingue eso de que no hubiera llamada.)
        $this->assertIsArray($segundo, 'el segundo proceso no llegó a ejecutarse');
        $this->assertArrayHasKey('payment_url', $segundo);
        $this->assertNull($segundo['payment_url']);
        $this->assertSame('mint_in_progress', $segundo['error'] ?? null);
    }

    /**
     * Y el camino de siempre no se entera del cerrojo.
     *
     * Un cerrojo que además cambiara la reutilización secuencial sería un
     * arreglo peor que el fallo: dos peticiones seguidas tienen que seguir
     * devolviendo el mismo cobro.
     */
    public function test_sequential_requests_still_reuse_the_same_checkout(): void
    {
        $a = $this->generar();
        $b = $this->generar();

        $this->assertSame(1, PaymentTransaction::count());
        $this->assertSame($a['reference'], $b['reference']);
        $this->assertSame($a['payment_url'], $b['payment_url']);
    }

    /**
     * Si el cerrojo no se puede ni pedir, se rechaza; no revienta el turno.
     *
     * La cabecera de `generateForLead()` promete que nunca lanza, y cuatro de
     * sus cinco llamadores sólo capturan `SalesGuardrailException`: cualquier
     * otra excepción saldría como 500 en un endpoint de dinero. `Cache::lock`
     * puede lanzar por su cuenta —store sin cerrojos, tabla `cache_locks`
     * ausente, base caída—, así que ese camino tiene que degradar.
     *
     * Y degrada RECHAZANDO: sin cerrojo no hay nada que impida dos cobros
     * vivos, y en el dinero se falla cerrado.
     */
    public function test_an_unavailable_lock_refuses_instead_of_blowing_up(): void
    {
        Cache::partialMock()
            ->shouldReceive('lock')
            ->andThrow(new BadMethodCallException('This cache store does not support locks.'));

        $r = $this->generar();

        $this->assertSame('mint_lock_unavailable', $r['error'] ?? null);
        $this->assertNull($r['payment_url']);
        $this->assertSame(0, PaymentTransaction::count(), 'acuñó sin cerrojo');
    }

    /**
     * Pero un fallo DEL COBRO sigue saliendo como fallo del cobro.
     *
     * Capturar todo alrededor del cerrojo era la forma fácil de cumplir el
     * contrato de arriba y, de paso, disfrazar de «ocupado» cualquier error del
     * dinero. Lo que distingue un caso del otro es si se llegó a entrar.
     */
    public function test_a_failure_inside_the_mint_is_not_disguised_as_busy(): void
    {
        $tx = Mockery::mock(WompiTransactionService::class);
        $tx->shouldReceive('createOrReuse')->andThrow(new RuntimeException('la base se cayó al crear el cobro'));

        $servicio = new WompiPaymentLinkService(
            $tx,
            WompiSignatureService::fromConfig(),
            (array) config('wompi'),
            app(SalesPaymentGuardrailService::class),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('la base se cayó al crear el cobro');

        $servicio->generateForLead($this->lead, $this->plan, [
            'channel' => 'whatsapp',
            'conversation_id' => $this->conversation->id,
        ]);
    }

    /**
     * Y el panel no puede leer «sin enlace» como éxito.
     *
     * El embudo contesta autorizado y configurado pero sin URL cuando el cobro
     * se está acuñando en otra petición o cuando no se pudo asegurar el
     * cerrojo. El endpoint del panel devolvía `ok: true` con `payment_url:
     * null` —justo lo que su propio comentario dice evitar—, y quien lo
     * consume no tiene forma de distinguir eso de un enlace que sí salió.
     */
    public function test_the_panel_never_reports_success_without_a_link(): void
    {
        Cache::partialMock()
            ->shouldReceive('lock')
            ->andThrow(new BadMethodCallException('This cache store does not support locks.'));

        $r = $this->postJson(
            "/api/admin/marketing/leads/{$this->lead->id}/payment-link",
            ['plan_id' => $this->plan->id],
            $this->adminHeaders(),
        );

        $r->assertStatus(409)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('code', 'mint_lock_unavailable');

        $this->assertSame(0, PaymentTransaction::count());
    }
}
