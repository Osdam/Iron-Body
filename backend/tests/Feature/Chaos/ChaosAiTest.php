<?php

namespace Tests\Feature\Chaos;

use App\Models\CommercialToolInvocation;
use App\Models\MarketingAiAction;
use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Models\PaymentTransaction;
use App\Services\Commercial\Tools\ToolContext;
use App\Services\Commercial\Tools\ToolExecutor;
use Illuminate\Support\Facades\Http;

/**
 * F6.01 – F6.07 · El modelo falla, miente o pide algo que no debería.
 *
 * Todo lo que entra por aquí atraviesa el webhook firmado de Meta y llega al
 * cerebro comercial con el modelo roto de una forma distinta cada vez. Lo que
 * se comprueba no es que «no pete»: es que la conversación sobreviva intacta al
 * fallo y que el prospecto no se quede sin nadie que le conteste.
 *
 * La segunda mitad es más incómoda de escribir pero más importante: qué pasa
 * cuando el modelo NO falla, sino que pide algo que no le corresponde. Un
 * modelo que inventa una herramienta o que se fija el precio él mismo no está
 * roto —está haciendo exactamente lo que un modelo hace— y la defensa no puede
 * ser el prompt, tiene que ser código que le diga que no.
 */
class ChaosAiTest extends ChaosTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // El cerebro, encendido y apuntando a OpenAI. Es la única forma de
        // ejercitar el camino real de fallo: con el driver `fake` no hay red
        // que romper y la prueba no probaría nada.
        config()->set('marketing.ai.driver', 'openai');
        config()->set('marketing.ai.enabled', true);
        config()->set('marketing.ai.openai.enabled', true);
        config()->set('marketing.ai.openai.model', 'gpt-4.1-mini');
        config()->set('marketing.ai.openai.max_retries', 1);
        config()->set('services.openai.api_key', 'sk-chaos-no-es-real');
        config()->set('services.openai.base_url', 'https://api.openai.com');
        config()->set('marketing.inbound.auto_analyze', true);
    }

    /** Manda un mensaje real por el webhook con OpenAI roto de la forma dada. */
    private function inboundWithBrokenAi(\Closure|array $openAiStub, string $text = 'Hola, ¿cuánto cuesta el plan mensual?'): void
    {
        Http::fake(['api.openai.com/*' => $openAiStub]);

        $this->metaWebhook($this->inboundMessage('573001112233', $text))->assertOk();
    }

    private function conversation(): MarketingConversation
    {
        $lead = MarketingLead::query()
            ->where('phone', '573001112233')
            ->orWhere('meta_user_id', '573001112233')
            ->firstOrFail();

        return MarketingConversation::where('lead_id', $lead->id)->firstOrFail();
    }

    /**
     * El suelo común de F6.01–F6.04: pase lo que pase con el modelo, el mensaje
     * del cliente está guardado, la conversación existe, y la decisión quedó
     * anotada con constancia de que el cerebro no respondió.
     */
    private function assertConversationSurvived(string $expectedFlagFragment): void
    {
        $conversation = $this->conversation();

        $inbound = MarketingMessage::where('conversation_id', $conversation->id)
            ->where('direction', 'inbound')->get();

        $this->assertCount(1, $inbound, 'El mensaje del cliente se perdió cuando falló el modelo.');
        $this->assertNotEmpty($inbound->first()->correlation_id, 'El mensaje quedó sin hilo con el que investigar.');

        $action = MarketingAiAction::where('conversation_id', $conversation->id)
            ->whereNotNull('metadata')->latest('id')->first();

        $this->assertNotNull($action, 'El fallo del modelo no dejó ninguna decisión registrada.');

        $flags = json_encode($action->metadata);
        $this->assertStringContainsString($expectedFlagFragment, (string) $flags, sprintf(
            'La decisión no dice que el modelo falló (se esperaba «%s»). Sin esa marca, '
            .'un fallback silencioso es indistinguible de una respuesta buena.',
            $expectedFlagFragment,
        ));

        // Con el canal apagado nada salió; y nada duplicado tampoco.
        $this->assertNothingDelivered();
        $this->assertLessThanOrEqual(1, MarketingMessage::where('conversation_id', $conversation->id)
            ->where('direction', 'outbound')->count(), 'El fallo produjo más de una respuesta.');
    }

    // ── F6.01 ───────────────────────────────────────────────────────────

    /**
     * F6.01 — OpenAI no contesta nunca.
     *
     * El prospecto escribió y del otro lado hay un socket colgado. La
     * conversación tiene que quedar exactamente igual que si el modelo hubiera
     * contestado «no sé»: guardada, atribuible y con un humano pudiendo entrar.
     */
    public function test_f601_timeout_de_openai_preserva_la_conversacion_y_no_duplica(): void
    {
        $this->inboundWithBrokenAi($this->timeout());

        $this->assertConversationSurvived('openai_fallback_error');
    }

    /**
     * F6.01b — Y el reintento del modelo está acotado.
     *
     * `max_retries` existe para que un proveedor caído no se convierta en una
     * tormenta de peticiones nuestra. Aquí se cuenta que se respeta.
     */
    public function test_f601b_los_reintentos_a_openai_estan_acotados(): void
    {
        // El contador va DENTRO del stub, no en `Http::recorded()`: una petición
        // que muere por timeout no llega a tener respuesta y no queda registrada,
        // así que contarla desde fuera daría cero y la prueba pasaría sola.
        $calls = 0;
        $this->inboundWithBrokenAi(function () use (&$calls) {
            $calls++;
            throw new \Illuminate\Http\Client\ConnectionException('cURL error 28: Operation timed out');
        });

        // max_retries=1 → 2 intentos como mucho. Lo que importa es el techo.
        $this->assertGreaterThanOrEqual(1, $calls, 'No se llegó a llamar al modelo.');
        $this->assertLessThanOrEqual(2, $calls, 'El modelo caído provocó una tormenta de reintentos.');
    }

    // ── F6.02 / F6.03 ───────────────────────────────────────────────────

    /** F6.02 — 429: se degrada sin insistir y sin duplicar respuesta. */
    public function test_f602_openai_429_degrada_sin_tormenta(): void
    {
        $calls = 0;
        $this->inboundWithBrokenAi(function () use (&$calls) {
            $calls++;

            return Http::response([
                'error' => ['message' => 'Rate limit reached', 'type' => 'rate_limit_error'],
            ], 429, ['Retry-After' => '20']);
        });

        $this->assertConversationSurvived('openai_fallback_error');
        $this->assertLessThanOrEqual(2, $calls, 'Un 429 provocó más peticiones de las permitidas.');
    }

    /** F6.03 — 500: degradación controlada, idéntica en efectos observables. */
    public function test_f603_openai_500_degrada_de_forma_controlada(): void
    {
        $this->inboundWithBrokenAi($this->httpStatus(500, [
            'error' => ['message' => 'Internal server error'],
        ]));

        $this->assertConversationSurvived('openai_fallback_error');
    }

    // ── F6.04 ───────────────────────────────────────────────────────────

    /**
     * F6.04 — El modelo devuelve algo que no se puede leer.
     *
     * Responde 200, con contenido, y el contenido no es JSON. Es el fallo más
     * sucio de los cuatro porque parece un éxito hasta que se intenta usar: no
     * hay excepción de red, no hay código de error, solo texto que no encaja.
     */
    public function test_f604_json_invalido_no_ejecuta_nada_y_deja_evidencia(): void
    {
        $this->inboundWithBrokenAi($this->httpStatus(200, [
            'choices' => [['message' => ['content' => 'Claro que sí, te paso el link {roto']]],
        ]));

        $this->assertConversationSurvived('openai_fallback_error');

        // Y sobre todo: nada se ejecutó a partir de una respuesta ilegible.
        $this->assertSame(0, CommercialToolInvocation::count(),
            'Se ejecutó una herramienta a partir de una respuesta que no se pudo interpretar.');
        $this->assertSame(0, PaymentTransaction::count());
    }

    /**
     * F6.04b — JSON válido pero con una intención que no existe.
     *
     * El validador tiene que devolverlo al terreno conocido en vez de dejar que
     * una intención inventada se propague por el resto del sistema.
     */
    public function test_f604b_intencion_inventada_se_normaliza_a_conocida(): void
    {
        $this->inboundWithBrokenAi($this->httpStatus(200, [
            'choices' => [['message' => ['content' => json_encode([
                'intent' => 'regalar_membresia_gratis',
                'confidence' => 0.99,
                'reply' => 'Te la regalo',
            ])]]],
        ]));

        $conversation = $this->conversation();
        $action = MarketingAiAction::where('conversation_id', $conversation->id)->latest('id')->first();

        $this->assertNotNull($action);
        $this->assertNotSame('regalar_membresia_gratis', (string) data_get($action->metadata, 'intent'),
            'Una intención inventada por el modelo llegó intacta al sistema.');
        $this->assertSame(0, PaymentTransaction::count());
        $this->assertNothingDelivered();
    }

    // ── F6.05 / F6.06 / F6.07 ───────────────────────────────────────────

    /*
     * Los tres siguientes atacan el ejecutor de herramientas directamente, y es
     * a propósito. Con los flags de producción el motor ni siquiera llega a
     * pedir una herramienta, así que probarlo por el webhook comprobaría el
     * flag, no la barrera. Lo que interesa es qué pasa el día que el flag esté
     * encendido y el modelo pida una barbaridad: la defensa tiene que estar en
     * el ejecutor, que es el único camino por el que se ejecuta algo.
     */

    private function executor(): ToolExecutor
    {
        config()->set('commercial.tools.payments', true);
        config()->set('commercial.autonomy_enabled', true);

        return app(ToolExecutor::class);
    }

    private function context(): ToolContext
    {
        return new ToolContext(requestedBy: 'engine', correlationId: 'chaos-corr-1');
    }

    /**
     * F6.05 — El modelo pide una herramienta que no existe.
     *
     * El registro es cerrado: el nombre que propone el modelo no es una llamada
     * a un método, es una llave que se busca en una lista. Si no está, no pasa
     * nada más. Es la diferencia entre un agente y una consola remota.
     */
    public function test_f605_herramienta_inexistente_se_rechaza_sin_ejecutar_nada(): void
    {
        $result = $this->executor()->execute(
            'activar_membresia_gratis',
            ['member_id' => 1],
            $this->context(),
        );

        $this->assertFalse($result->successful());
        $this->assertSame('unknown_tool', $result->errorCode);
        $this->assertSame(0, CommercialToolInvocation::count());
        $this->assertSame(0, PaymentTransaction::count());
    }

    /**
     * F6.05b — Y el texto libre tampoco es una herramienta.
     *
     * Un nombre con forma de comando no encuentra nada que ejecutar, porque no
     * hay ningún punto en el que un nombre se convierta en código.
     */
    public function test_f605b_texto_libre_no_se_interpreta_como_ejecucion(): void
    {
        foreach ([
            'App\\Services\\MembershipService::activate',
            'create_payment_link; DROP TABLE payments',
            '../../etc/passwd',
        ] as $injection) {
            $result = $this->executor()->execute($injection, [], $this->context());

            $this->assertSame('unknown_tool', $result->errorCode, "Se aceptó «{$injection}» como herramienta.");
        }

        $this->assertSame(0, CommercialToolInvocation::count());
    }

    /**
     * F6.06 — JSON válido que viola el esquema.
     *
     * El rechazo tiene que ocurrir ANTES del servicio. Si `plan_id` llegara como
     * texto hasta la implementación, el fallo se descubriría a medio camino y
     * con efectos ya escritos.
     */
    public function test_f606_argumentos_invalidos_se_rechazan_antes_del_servicio(): void
    {
        $result = $this->executor()->execute(
            'create_payment_link',
            ['plan_id' => 'el mensual'],
            $this->context(),
        );

        $this->assertFalse($result->successful());
        $this->assertSame('invalid_arguments', $result->errorCode);
        $this->assertSame(0, PaymentTransaction::count(), 'Un argumento inválido llegó a crear un cobro.');

        // El rechazo se audita: hace falta para saber que el modelo se equivoca.
        $invocation = CommercialToolInvocation::latest('id')->first();
        $this->assertNotNull($invocation);
        $this->assertSame(CommercialToolInvocation::STATUS_REJECTED, $invocation->status);
    }

    /**
     * F6.07 — El modelo intenta poner el precio.
     *
     * El caso que más caro sale y el más fácil de pasar por alto, porque el
     * argumento de más no rompe nada: si nadie lo mira, se ignora en silencio y
     * el sistema parece funcionar. Aquí se exige lo contrario —rechazo explícito
     * y constancia— porque un modelo que intenta fijar importes es información
     * que alguien tiene que ver.
     */
    public function test_f607_el_modelo_no_puede_fijar_el_precio(): void
    {
        $plan = $this->plan('Mensual', 90000);

        foreach ([['price' => 1], ['amount' => 1], ['amount_in_cents' => 100]] as $intento) {
            $result = $this->executor()->execute(
                'create_payment_link',
                array_merge(['plan_id' => $plan->id], $intento),
                $this->context(),
            );

            $this->assertFalse($result->successful(), 'El ejecutor aceptó '.json_encode($intento));
            $this->assertSame('unexpected_arguments', $result->errorCode);
        }

        $this->assertSame(0, PaymentTransaction::count(), 'Se creó un cobro con un importe puesto por el modelo.');

        // Los tres intentos quedaron anotados, no solo el último.
        $this->assertSame(3, CommercialToolInvocation::where('status', CommercialToolInvocation::STATUS_REJECTED)->count());
    }

    /**
     * F6.07b — Y con el plan correcto, el importe sale del catálogo.
     *
     * Comprobar el rechazo sin comprobar esto dejaría a medias la afirmación:
     * lo que importa no es que el modelo no mande precio, es que el precio que
     * se cobra sea el del backend.
     */
    public function test_f607b_el_importe_sale_del_catalogo_no_del_modelo(): void
    {
        $plan = $this->plan('Mensual', 90000);
        $lead = MarketingLead::create([
            'channel' => 'whatsapp', 'meta_user_id' => '573001112233',
            'phone' => '573001112233', 'name' => 'Prospecto Chaos',
        ]);

        config()->set('wompi.env', 'production');

        $result = $this->executor()->execute(
            'create_payment_link',
            ['plan_id' => $plan->id],
            new ToolContext(lead: $lead, requestedBy: 'engine', correlationId: 'chaos-corr-2'),
        );

        // Sea cual sea el desenlace (Wompi no es productivo de verdad en la
        // prueba), lo que NO puede haber es un cobro por un importe inventado.
        $created = PaymentTransaction::first();

        if ($created !== null) {
            $this->assertEqualsWithDelta(90000.0, (float) $created->amount, 0.01,
                'El cobro se creó con un importe que no es el del catálogo.');
        }

        $this->assertNotSame('unexpected_arguments', $result->errorCode);
    }
}
