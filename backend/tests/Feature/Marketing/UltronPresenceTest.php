<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingAiAction;
use App\Models\MarketingAutomationEvent;
use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Models\Payment;
use App\Services\Marketing\Ultron\UltronEventEmitter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * EL VISTO Y EL «ESCRIBIENDO…», que son señales, no respuestas.
 *
 * Laravel sigue siendo el único dueño de Meta: n8n no llama a Graph. Y estas
 * señales tienen dos reglas que estas pruebas fijan en los dos sentidos:
 *
 *  - Van DESPUÉS de las puertas. Enseñar «escribiendo…» a alguien a quien
 *    ULTRON no va a contestar —otra conversación, alguien que pidió que no le
 *    escriban, un hilo tomado por una persona— es prometer una respuesta que
 *    nadie mandará.
 *  - Son mejor-esfuerzo. Si Meta falla, el turno sigue. Una señal visual no
 *    puede convertirse en un turno mudo.
 *
 * Y no son mensajes: no crean saliente, ni acción, ni cobro.
 */
class UltronPresenceTest extends TestCase
{
    use RefreshDatabase;

    private const WAMID = 'wamid.HBgMNTczMTUwNTM2MDI2FQIAEhgUM0E0RjE4';

    private MarketingLead $lead;

    private MarketingConversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('marketing.ultron.enabled', true);

        // Meta CONFIGURADO de verdad: sin credenciales el cliente ni sale a la
        // red, y la prueba pasaría sin probar nada.
        config()->set('meta.enabled', true);
        config()->set('meta.app_secret', 'app-secret-de-prueba');
        config()->set('meta.access_token', 'token-de-prueba');
        config()->set('meta.whatsapp_phone_number_id', '111111111111111');
        config()->set('meta.graph_base', 'https://graph.facebook.com');
        config()->set('meta.graph_version', 'v21.0');
        app(\App\Services\Meta\WhatsappIntegrationRegistry::class)->forget();

        $this->lead = MarketingLead::create([
            'channel' => 'whatsapp', 'source' => 'inbound', 'phone' => '3150536026',
            'meta_user_id' => '573150536026', 'name' => 'Prospecto', 'status' => MarketingLead::STATUS_NEW,
        ]);

        $this->conversation = MarketingConversation::create([
            'lead_id' => $this->lead->id, 'channel' => 'whatsapp', 'status' => 'open',
            'ai_enabled' => true, 'human_takeover' => false,
        ]);

        Http::preventStrayRequests();
    }

    /**
     * Los stubs de `Http::fake()` se ACUMULAN y gana el primero que casa, así
     * que fijar la respuesta buena en el setUp haría imposible probar el fallo:
     * cada prueba decide, y si no decide se le da la buena al emitir.
     */
    private bool $metaFijado = false;

    private function metaResponde(int $estado, array $cuerpo = ['success' => true]): void
    {
        Http::fake(['*' => Http::response($cuerpo, $estado)]);
        $this->metaFijado = true;
    }

    private function metaRevienta(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout'));
        $this->metaFijado = true;
    }

    // ── Andamio ───────────────────────────────────────────────────────────────

    private function entrante(string $body = 'hola', ?string $wamid = self::WAMID): MarketingMessage
    {
        return MarketingMessage::create([
            'conversation_id' => $this->conversation->id,
            'direction' => MarketingMessage::DIRECTION_INBOUND,
            'sender_type' => MarketingMessage::SENDER_LEAD,
            'body' => $body, 'meta_message_id' => $wamid, 'status' => 'received',
            'metadata' => ['message_type' => 'text'],
        ]);
    }

    private function emitir(MarketingMessage $m): ?MarketingAutomationEvent
    {
        if (! $this->metaFijado) {
            $this->metaResponde(200);
        }

        return (new UltronEventEmitter)->emit($this->conversation->fresh(), $m, ['message_type' => 'text']);
    }

    /** Las peticiones de presencia que salieron hacia Graph. @return array<int,array<string,mixed>> */
    private function señales(): array
    {
        $out = [];
        foreach (Http::recorded() as [$request, $response]) {
            /** @var Request $request */
            $body = $request->data();
            if (($body['status'] ?? null) === 'read') {
                $out[] = $body;
            }
        }

        return $out;
    }

    // ── A · el camino bueno ───────────────────────────────────────────────────

    public function test_an_eligible_canary_inbound_is_marked_read_and_shows_typing(): void
    {
        config()->set('marketing.ultron.canary_conversation_id', $this->conversation->id);

        $m = $this->entrante('me interesa, cómo empiezo?');
        $this->assertNotNull($this->emitir($m), 'el turno nace');

        $señales = $this->señales();
        $this->assertCount(1, $señales, 'una sola petición: Cloud API marca leído y muestra typing a la vez');

        $this->assertSame('whatsapp', $señales[0]['messaging_product']);
        $this->assertSame('read', $señales[0]['status']);
        $this->assertSame(['type' => 'text'], $señales[0]['typing_indicator']);
    }

    // ── B · con el wamid REAL, nunca con un id nuestro ───────────────────────

    public function test_the_signal_uses_the_real_inbound_wamid(): void
    {
        config()->set('marketing.ultron.canary_conversation_id', $this->conversation->id);

        $m = $this->entrante();
        $this->emitir($m);

        $enviado = $this->señales()[0]['message_id'];

        $this->assertSame(self::WAMID, $enviado, 'Graph sólo reconoce el wamid del entrante');

        foreach ([$m->id, $this->conversation->id, $this->lead->id] as $idInterno) {
            $this->assertNotSame((string) $idInterno, (string) $enviado, 'un id nuestro no significa nada para Meta');
        }
    }

    /** Un entrante sin wamid (la simulación, la suite) no es un fallo: no hay nada que marcar. */
    public function test_an_inbound_without_a_wamid_signals_nothing_and_still_opens_its_turn(): void
    {
        config()->set('marketing.ultron.canary_conversation_id', $this->conversation->id);

        $m = $this->entrante('hola', null);

        $this->assertNotNull($this->emitir($m), 'el turno nace igual');
        $this->assertSame([], $this->señales());
        $this->assertFalse(data_get($m->fresh()->metadata, 'ultron_presence.read_attempted'));
    }

    // ── C, D, E · nadie ve «escribiendo…» si no le vamos a contestar ─────────

    public static function puertasQueNoDejanPasar(): array
    {
        return [
            'otra conversación (no es el canario)' => ['not_canary_conversation'],
            'pidió que no le escriban' => ['do_not_contact'],
            'una persona tomó el hilo' => ['human_takeover'],
            'la IA está apagada en ese hilo' => ['ai_disabled'],
            'ULTRON apagado' => ['ultron_disabled'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('puertasQueNoDejanPasar')]
    public function test_nobody_sees_typing_for_a_turn_that_will_not_be_answered(string $puerta): void
    {
        config()->set('marketing.ultron.canary_conversation_id', $this->conversation->id);

        match ($puerta) {
            'not_canary_conversation' => config()->set('marketing.ultron.canary_conversation_id', $this->conversation->id + 77),
            'do_not_contact' => $this->lead->forceFill(['do_not_contact' => true])->save(),
            'human_takeover' => $this->conversation->forceFill(['human_takeover' => true, 'human_takeover_source' => 'manual'])->save(),
            'ai_disabled' => $this->conversation->forceFill(['ai_enabled' => false])->save(),
            'ultron_disabled' => config()->set('marketing.ultron.enabled', false),
        };

        $this->assertNull($this->emitir($this->entrante()), 'no hay turno');
        $this->assertSame([], $this->señales(), "«{$puerta}» no puede enseñar «escribiendo…»");
    }

    /** Con el freno puesto tampoco: parar significa parar, también lo visible. */
    public function test_with_the_brake_on_there_is_no_typing(): void
    {
        config()->set('marketing.ultron.canary_conversation_id', $this->conversation->id);
        app(\App\Services\Marketing\Ultron\UltronAbortLatch::class)
            ->engage(\App\Models\UltronAbort::REASON_MANUAL, 'prueba');

        $this->assertNull($this->emitir($this->entrante()));
        $this->assertSame([], $this->señales());
    }

    // ── F · una reentrega de Meta no vuelve a señalar ────────────────────────

    public function test_a_redelivered_inbound_does_not_signal_twice(): void
    {
        config()->set('marketing.ultron.canary_conversation_id', $this->conversation->id);

        $m = $this->entrante();
        $this->emitir($m);
        $this->emitir($m);   // la misma wamid otra vez: reentrega

        $this->assertCount(1, $this->señales(), 'la deduplicación va antes que la señal');
        $this->assertSame(1, MarketingAutomationEvent::count());
    }

    // ── G · el relevo tiene su propia señal, y ninguna huérfana ──────────────

    public function test_each_inbound_signals_once_and_only_for_itself(): void
    {
        config()->set('marketing.ultron.canary_conversation_id', $this->conversation->id);

        $primero = $this->entrante('hola', 'wamid.UNO');
        $segundo = $this->entrante('mejor dime precios', 'wamid.DOS');

        $this->emitir($primero);
        $this->emitir($segundo);

        $ids = array_column($this->señales(), 'message_id');

        $this->assertSame(['wamid.UNO', 'wamid.DOS'], $ids, 'una señal por entrante, con su propio wamid');
    }

    // ── H · si Meta falla, el turno sigue ────────────────────────────────────

    public function test_a_failing_meta_never_silences_the_turn(): void
    {
        config()->set('marketing.ultron.canary_conversation_id', $this->conversation->id);
        $this->metaResponde(500, ['error' => ['code' => 131000, 'message' => 'internal']]);

        $m = $this->entrante();
        $evento = $this->emitir($m);

        $this->assertNotNull($evento, 'el turno nace igual: la respuesta vale más que el indicador');
        $this->assertSame(MarketingAutomationEvent::STATUS_PENDING, $evento->status);

        $ux = data_get($m->fresh()->metadata, 'ultron_presence');
        $this->assertTrue($ux['typing_attempted'], 'se intentó');
        $this->assertFalse($ux['typing_success'], 'y se anota que no salió');
        $this->assertNotEmpty($ux['provider_error'], 'con el motivo saneado de Meta');
        $this->assertArrayNotHasKey('typing_started_at', $ux, 'no se finge una señal que no llegó');
    }

    /** Ni una excepción de transporte: ni siquiera eso puede tumbar el turno. */
    public function test_a_transport_failure_never_silences_the_turn(): void
    {
        config()->set('marketing.ultron.canary_conversation_id', $this->conversation->id);
        $this->metaRevienta();

        $this->assertNotNull($this->emitir($this->entrante()));
    }

    // ── I · una señal no es un mensaje ───────────────────────────────────────

    public function test_the_signal_creates_no_outbound_no_action_and_no_payment(): void
    {
        config()->set('marketing.ultron.canary_conversation_id', $this->conversation->id);

        $this->emitir($this->entrante());

        $this->assertSame(0, MarketingMessage::where('direction', MarketingMessage::DIRECTION_OUTBOUND)->count());
        $this->assertSame(0, MarketingAiAction::count());
        $this->assertSame(0, Payment::count());
    }

    // ── J · sin retrasos fingidos ────────────────────────────────────────────

    /**
     * Nada de `sleep()` para simular que alguien escribe. La naturalidad sale
     * del visto, del indicador y de la respuesta; fingirla cuesta segundos
     * reales de la persona que está esperando.
     */
    public function test_there_is_no_artificial_delay(): void
    {
        config()->set('marketing.ultron.canary_conversation_id', $this->conversation->id);

        $desde = microtime(true);
        $this->emitir($this->entrante());
        $ms = (microtime(true) - $desde) * 1000;

        $this->assertLessThan(1000, $ms, 'abrir el turno no puede tardar segundos a propósito');

        foreach ([
            app_path('Services/Marketing/Ultron/TurnPresence.php'),
            app_path('Services/Marketing/Ultron/UltronEventEmitter.php'),
        ] as $fichero) {
            $this->assertDoesNotMatchRegularExpression(
                '~\b(sleep|usleep)\s*\(~',
                (string) file_get_contents($fichero),
                'nada de esperas fingidas en el camino del turno',
            );
        }
    }

    // ── Observabilidad ───────────────────────────────────────────────────────

    public function test_the_turn_records_what_was_attempted_and_when(): void
    {
        config()->set('marketing.ultron.canary_conversation_id', $this->conversation->id);

        $m = $this->entrante();
        $this->emitir($m);

        $ux = data_get($m->fresh()->metadata, 'ultron_presence');

        $this->assertTrue($ux['read_attempted']);
        $this->assertTrue($ux['read_success']);
        $this->assertTrue($ux['typing_attempted']);
        $this->assertTrue($ux['typing_success']);
        $this->assertNotEmpty($ux['typing_started_at']);
        $this->assertArrayNotHasKey('provider_error', $ux);

        $this->assertStringNotContainsString('Bearer', json_encode($m->fresh()->metadata) ?: '');
    }
}
