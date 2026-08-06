<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingMessage;
use App\Services\Marketing\HermesCircuitBreaker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Si Hermes se cae, el canal sigue atendiendo.
 *
 * Hermes es un motor de razonamiento opcional, no una dependencia del negocio.
 * Un prospecto que escribe un sábado no puede quedarse sin respuesta porque un
 * contenedor se reinició. Estas pruebas fijan las tres capas de esa garantía:
 *
 *  1. Ante un fallo puntual, se degrada a OpenAI directo en esa misma petición.
 *  2. Ante fallos repetidos, el cortacircuitos deja de intentarlo — para que la
 *     persona número quince no pague otra vez el timeout completo.
 *  3. Pase lo que pase, el mensaje del prospecto queda guardado y visible.
 */
class HermesFallbackTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE_ID = '123456';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('meta.enabled', false);
        config()->set('meta.webhook_secret', 'wsecret');
        config()->set('meta.whatsapp_phone_number_id', self::PHONE_ID);

        // Hermes como cerebro, con OpenAI listo por detrás.
        config()->set('marketing.ai.driver', 'hermes');
        config()->set('marketing.ai.enabled', true);
        config()->set('marketing.ai.hermes.enabled', true);
        config()->set('marketing.ai.hermes.base_url', 'http://127.0.0.1:8642');
        config()->set('marketing.ai.hermes.api_key', 'clave-de-prueba');
        config()->set('marketing.ai.hermes.timeout', 2);
        config()->set('marketing.ai.openai.enabled', true);
        config()->set('services.openai.api_key', 'sk-de-prueba');
        config()->set('marketing.inbound.auto_analyze', true);

        Cache::flush(); // el circuito vive en caché: cada prueba empieza limpio
    }

    private function breaker(): HermesCircuitBreaker
    {
        return app(HermesCircuitBreaker::class);
    }

    private function say(string $text): TestResponse
    {
        $payload = ['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['field' => 'messages', 'value' => [
            'metadata' => ['phone_number_id' => self::PHONE_ID],
            'contacts' => [['profile' => ['name' => 'Prospecto'], 'wa_id' => '573150536026']],
            'messages' => [['from' => '573150536026', 'id' => 'wamid.'.uniqid(),
                'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => $text]]],
        ]]]]]];

        $raw = json_encode($payload) ?: '{}';

        return $this->call('POST', '/api/webhooks/meta', [], [], [], [
            'HTTP_X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $raw, 'wsecret'),
            'CONTENT_TYPE' => 'application/json',
        ], $raw);
    }

    /** Respuesta válida de OpenAI para que el fallback tenga con qué contestar. */
    private function openAiAnswers(): array
    {
        return ['api.openai.com/*' => Http::response([
            'choices' => [['message' => ['content' => json_encode([
                'intent' => 'pricing',
                'reply' => 'Con gusto te cuento sobre los planes.',
                'tools_requested' => [],
            ])]]],
        ], 200)];
    }

    /** Con Hermes caído, el prospecto igualmente recibe atención. */
    public function test_the_channel_keeps_answering_when_hermes_is_down(): void
    {
        Http::fake(array_merge(
            ['127.0.0.1:8642/*' => Http::response('', 503)],
            $this->openAiAnswers(),
        ));

        $this->say('cuánto vale la mensualidad?')->assertOk();

        // El mensaje entró y se procesó pese al fallo del motor.
        $this->assertDatabaseHas('marketing_messages', [
            'body' => 'cuánto vale la mensualidad?',
            'direction' => 'inbound',
        ]);
        $this->assertSame(0, MarketingMessage::where('status', 'dead')->count());
    }

    /** Un timeout de Hermes tampoco deja al prospecto sin nada. */
    public function test_a_hermes_timeout_does_not_lose_the_message(): void
    {
        Http::fake(array_merge(
            ['127.0.0.1:8642/*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout')],
            $this->openAiAnswers(),
        ));

        $this->say('precio por favor')->assertOk();

        $this->assertDatabaseHas('marketing_messages', ['body' => 'precio por favor']);
    }

    /**
     * El cortacircuitos. Sin él, con Hermes caído cada prospecto paga el timeout
     * entero: quince personas escribiendo son quince esperas inútiles y quince
     * workers ocupados esperando a algo que ya sabemos que no responde.
     */
    public function test_after_repeated_failures_hermes_stops_being_called(): void
    {
        config()->set('marketing.ai.hermes.circuit_breaker.failure_threshold', 3);

        $this->assertTrue($this->breaker()->allows());

        $this->breaker()->recordFailure('timeout');
        $this->breaker()->recordFailure('timeout');
        $this->assertTrue($this->breaker()->allows(), 'No debe abrirse antes del umbral.');

        $this->breaker()->recordFailure('timeout');

        $this->assertFalse($this->breaker()->allows(), 'Debe abrirse al alcanzar el umbral.');
        $this->assertSame('open', $this->breaker()->state()['state']);
    }

    /** Con el circuito abierto no se toca la red: se degrada al instante. */
    public function test_an_open_circuit_skips_the_network_entirely(): void
    {
        config()->set('marketing.ai.hermes.circuit_breaker.failure_threshold', 1);
        $this->breaker()->recordFailure('caido');

        Http::fake($this->openAiAnswers());

        $this->say('hola')->assertOk();

        // Ni un solo intento contra Hermes.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '8642'));
    }

    /** Pasado el enfriamiento se permite UNA llamada de prueba. */
    public function test_after_the_cooldown_one_probe_is_allowed(): void
    {
        config()->set('marketing.ai.hermes.circuit_breaker.failure_threshold', 1);
        config()->set('marketing.ai.hermes.circuit_breaker.cooldown_seconds', 60);

        $this->breaker()->recordFailure('caido');
        $this->assertFalse($this->breaker()->allows());

        $this->travel(61)->seconds();

        $this->assertTrue($this->breaker()->allows(), 'Tras el enfriamiento debe dejar probar.');
    }

    /** Una llamada correcta cierra el circuito y olvida el historial. */
    public function test_a_success_closes_the_circuit(): void
    {
        config()->set('marketing.ai.hermes.circuit_breaker.failure_threshold', 2);

        $this->breaker()->recordFailure('x');
        $this->breaker()->recordSuccess();
        $this->breaker()->recordFailure('x');

        // El fallo previo se olvidó, así que uno nuevo no debe abrirlo.
        $this->assertTrue($this->breaker()->allows());
    }

    /**
     * Techo de gasto. Hermes cuesta ~12 400 tokens de entrada por llamada
     * (medido en el servidor); sin un tope, una avalancha de mensajes agota el
     * límite por minuto de la cuenta y tumba también a OpenAI.
     */
    public function test_the_budget_stops_calling_hermes_once_exhausted(): void
    {
        config()->set('marketing.ai.hermes.budget.max_calls_per_hour', 2);
        Cache::put('hermes:budget:'.now()->format('YmdH'), 2, now()->addHour());

        Http::fake($this->openAiAnswers());

        $this->say('hola')->assertOk();

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '8642'));
    }

    /** El interruptor devuelve el comportamiento anterior sin desplegar. */
    public function test_the_kill_switch_restores_openai_without_a_deploy(): void
    {
        config()->set('marketing.ai.hermes.enabled', false);

        Http::fake($this->openAiAnswers());

        $this->say('precio')->assertOk();

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '8642'));
        $this->assertDatabaseHas('marketing_messages', ['body' => 'precio']);
    }

    /**
     * El peor escenario: Hermes caído Y OpenAI caído. El canal no puede
     * inventarse una respuesta; tiene que guardar el mensaje para que lo vea
     * una persona.
     */
    public function test_with_both_engines_down_the_message_waits_for_a_human(): void
    {
        Http::fake([
            '127.0.0.1:8642/*' => Http::response('', 503),
            'api.openai.com/*' => Http::response(['error' => 'down'], 500),
        ]);

        $this->say('quiero inscribirme hoy mismo')->assertOk();

        // Lo esencial: el mensaje existe y alguien puede atenderlo.
        $this->assertDatabaseHas('marketing_messages', [
            'body' => 'quiero inscribirme hoy mismo',
            'direction' => 'inbound',
        ]);
    }
}
