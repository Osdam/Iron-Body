<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingAiAction;
use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Services\Marketing\CommercialPhaseMachine as P;
use App\Services\Marketing\SalesIntents;
use App\Services\Wompi\PaymentStateMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Querer pagar no se resuelve mandando la app.
 *
 * El fallo que obliga a que esto exista se midió en el canario físico. La
 * persona escribió «Listo, quiero pagarlo. ¿Me puedes mandar el enlace para
 * hacer el pago?» y recibió instrucciones para pagar en el gimnasio o desde la
 * app, más los tres enlaces de descarga. El checkout real ya existía, la
 * autoridad lo permitía y la herramienta estaba en el menú del turno.
 *
 * El modelo no la pidió, y no por capricho: los prompts —escritos cuando el
 * cobro automático estaba apagado para siempre— le enseñaban que pagar es la
 * app o el gimnasio, y que no pedir los enlaces de la app es «el error más
 * caro». Hizo lo que le enseñamos.
 *
 * Por eso lo que se fija aquí NO es que el modelo acierte. Es que da igual lo
 * que pida: cuando la intención validada es de pago, hay plan vendible y la
 * autoridad permite cobrar, el checkout lo pone Laravel.
 *
 * ENLACE DE LA APP != ENLACE DE PAGO.
 */
class PaymentIntentRoutingTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-internal-secret';

    private Plan $plan;

    private MarketingLead $lead;

    private MarketingConversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('marketing.ultron.enabled', true);
        config()->set('automation.internal_secret', self::SECRET);
        config()->set('meta.enabled', false);
        config()->set('marketing.ai.enabled', true);
        config()->set('marketing.ai.driver', 'fake');
        config()->set('marketing.inbound.auto_analyze', false);
        config()->set('wompi.env', 'production');
        config()->set('wompi.public_key', 'pub_prod_test_key');
        config()->set('wompi.private_key', 'prv_prod_test_key');
        config()->set('wompi.integrity_secret', 'integrity_test_secret');
        config()->set('wompi.events_secret', 'events_test_secret');
        config()->set('wompi.checkout.base_url', 'https://checkout.wompi.co/p/');
        config()->set('wompi.checkout.redirect_url', 'https://web.ironbodyneiva.cloud/pago');
        Http::fake();

        $this->plan = Plan::create(['name' => 'Plan Mensual', 'price' => 80000, 'duration_days' => 30, 'active' => true, 'sellable' => true, 'benefits' => json_encode(['Acceso ilimitado'])]);
        $this->lead = MarketingLead::create(['channel' => 'whatsapp', 'source' => 'inbound', 'phone' => '3150536026', 'meta_user_id' => '573150536026', 'name' => 'Prospecto', 'status' => MarketingLead::STATUS_HOT]);
        $this->conversation = MarketingConversation::create(['lead_id' => $this->lead->id, 'channel' => 'whatsapp', 'status' => 'open', 'ai_enabled' => true, 'human_takeover' => false, 'commercial_phase' => P::CLOSING]);

        // El canario, acotado a ESTA conversación. El grifo general, cerrado.
        config()->set('marketing.ultron.payment_links_enabled', false);
        config()->set('marketing.ultron.payment_canary_conversation_id', $this->conversation->id);
    }

    private function h(): array
    {
        return ['Authorization' => 'Bearer '.self::SECRET];
    }

    private function inbound(string $body, string $wamid): MarketingMessage
    {
        return MarketingMessage::create(['conversation_id' => $this->conversation->id, 'direction' => MarketingMessage::DIRECTION_INBOUND, 'sender_type' => MarketingMessage::SENDER_LEAD, 'body' => $body, 'meta_message_id' => $wamid, 'status' => 'received']);
    }

    private function decideToken(MarketingMessage $m): string
    {
        return (string) $this->postJson('/api/internal/marketing/ai/decide', ['conversation_id' => $this->conversation->id, 'message_id' => $m->id], $this->h())->json('decide_token');
    }

    private function commitLink(MarketingMessage $m, array $overrides = []): TestResponse
    {
        return $this->postJson('/api/internal/marketing/ai/commit', [
            'source_type' => 'inbound_message', 'source_event_id' => $m->id, 'idempotency_key' => $m->meta_message_id,
            'conversation_id' => $this->conversation->id, 'decide_token' => $this->decideToken($m),
            'proposal' => array_merge([
                'strategy_goal' => 'collect_payment', 'next_state' => P::CLOSING, 'intent' => SalesIntents::PAYMENT_LINK_REQUEST,
                'reply_draft' => 'Listo, te paso el link de pago del {{PLAN_NAME}} para que lo hagas desde el celular.',
                'recommended_plan_id' => $this->plan->id, 'main_barrier' => null, 'next_best_action' => 'recommend_plan',
                'confidence' => 0.9, 'tools_requested' => [SalesIntents::TOOL_PAYMENT_LINK_SEND],
            ], $overrides),
            'critic' => ['verdict' => 'pass', 'attempt' => 1, 'score' => 0.9, 'issues' => [], 'hard_fail' => null],
        ], $this->h());
    }

    private function salientes(): Collection
    {
        return MarketingMessage::where('conversation_id', $this->conversation->id)
            ->where('direction', MarketingMessage::DIRECTION_OUTBOUND)
            ->orderBy('id')->get();
    }

    private function todoElSaliente(): string
    {
        return $this->salientes()->pluck('body')->implode("\n");
    }

    /**
     * EL TURNO DEL CANARIO, reproducido tal cual.
     *
     * Intención de pago, plan resuelto, y el modelo pidiendo los enlaces de la
     * app en vez del cobro. Antes salía la app; ahora sale el cobro.
     */
    public function test_the_canary_turn_now_delivers_the_checkout(): void
    {
        $r = $this->commitLink($this->inbound('Listo, quiero pagarlo. ¿Me puedes mandar el enlace para hacer el pago?', 'w.rt1'), [
            'intent' => SalesIntents::PAYMENT_LINK_REQUEST,
            'tools_requested' => [SalesIntents::TOOL_APP_LINKS_SEND],
            'reply_draft' => 'Para pagar el {{PLAN_NAME}} puedes hacerlo en el gimnasio o desde la app.',
        ])->assertOk();

        $tx = PaymentTransaction::sole();
        $this->assertStringContainsString($tx->checkout_url, $this->todoElSaliente(), 'el cobro tiene que llegar');
        $this->assertContains(SalesIntents::TOOL_PAYMENT_LINK_SEND, $r->json('applied.tools_executed'));
    }

    /**
     * PRIMER TESTIGO: el resolver de Laravel.
     *
     * Sin que el modelo pida nada. Es el caso del canario —pidió los enlaces
     * de la app— y el que demuestra que el cobro no depende de que el modelo
     * acierte con la herramienta.
     */
    #[DataProvider('mensajesQueLaravelCorrobora')]
    public function test_a_message_laravel_corroborates_gets_the_checkout(string $texto): void
    {
        $this->commitLink($this->inbound($texto, 'w.'.md5($texto)), [
            'intent' => SalesIntents::PAYMENT_LINK_REQUEST,
            'tools_requested' => [SalesIntents::TOOL_APP_LINKS_SEND],
            'reply_draft' => 'Para pagar el {{PLAN_NAME}} puedes hacerlo en el gimnasio o desde la app.',
        ])->assertOk();

        $tx = PaymentTransaction::sole();
        $this->assertStringContainsString($tx->checkout_url, $this->todoElSaliente(), $texto);
    }

    public static function mensajesQueLaravelCorrobora(): array
    {
        return array_map(fn ($f) => [$f], [
            'pásame el link',
            'dame el enlace para pagar',
            'cómo pago ahora',
            'mándamelo otra vez',
        ]);
    }

    /**
     * SEGUNDO TESTIGO: el modelo pide cobrar explícitamente.
     *
     * «Quiero pagarlo» a secas no lo corrobora el resolver —lo medí: da
     * `none`— y aun así es una petición de pago legítima. Ahí vale que el
     * modelo, además de clasificar, PIDA la herramienta: no es un testigo
     * independiente, pero exige dos errores suyos a la vez en lugar de uno.
     */
    #[DataProvider('mensajesConHerramientaPedida')]
    public function test_an_explicit_tool_request_gets_the_checkout(string $texto, string $intent): void
    {
        $this->commitLink($this->inbound($texto, 'w.'.md5($texto)), [
            'intent' => $intent,
            'tools_requested' => [SalesIntents::TOOL_PAYMENT_LINK_SEND],
            'reply_draft' => 'Claro, el {{PLAN_NAME}} cuesta {{PLAN_PRICE}}.',
        ])->assertOk();
        $tx = PaymentTransaction::sole();
        $this->assertStringContainsString($tx->checkout_url, $this->todoElSaliente(), $texto);
    }

    public static function mensajesConHerramientaPedida(): array
    {
        return [
            'quiero pagarlo' => ['quiero pagarlo', SalesIntents::PAYMENT_LINK_REQUEST],
            'quiero hacer el pago' => ['quiero hacer el pago', SalesIntents::PAYMENT_LINK_REQUEST],
            'quiero inscribirme ya' => ['quiero inscribirme ya', SalesIntents::HIGH_INTENT_CLOSE],
        ];
    }

    /**
     * Y SIN NINGUNO DE LOS DOS, NO SE ACUÑA. Éste es el hallazgo de la
     * revisión, y es el que de verdad protege a alguien.
     *
     * Cinco mensajes que NO piden pagar, etiquetados como intención de pago
     * por el modelo. Antes los cinco acuñaban un checkout real y entregaban la
     * URL; el peor, «no me interesa por ahora». El daño no es un cobro —hace
     * falta teclear la tarjeta— sino un enlace pagable por quien lo tenga en
     * manos de alguien que acaba de decir que no.
     */
    #[DataProvider('mensajesQueNoPidenPagar')]
    public function test_a_mislabelled_turn_never_mints_a_checkout(string $texto): void
    {
        $this->commitLink($this->inbound($texto, 'w.'.md5($texto)), [
            'intent' => SalesIntents::PAYMENT_LINK_REQUEST,
            'tools_requested' => [],
            'reply_draft' => 'Claro, el {{PLAN_NAME}} cuesta {{PLAN_PRICE}}.',
        ])->assertOk();

        $this->assertSame(0, PaymentTransaction::count(), $texto.' → no se acuña nada');
        $this->assertStringNotContainsString('checkout.wompi.co', $this->todoElSaliente(), $texto);
        $this->assertSame(
            'payment_intent_not_corroborated',
            MarketingAiAction::latest('id')->first()->metadata['payment_link']['reason'] ?? null,
            $texto,
        );
    }

    public static function mensajesQueNoPidenPagar(): array
    {
        return array_map(fn ($f) => [$f], [
            'hola, quiero informacion de los planes',
            'cuanto cuesta el mensual?',
            'gracias, lo voy a pensar',
            'a que hora abren los sabados?',
            'no me interesa por ahora',
        ]);
    }

    /** «Mándamelo otra vez» es el MISMO cobro: ni referencia nueva ni fila nueva. */
    public function test_asking_again_returns_the_same_reference(): void
    {
        $this->commitLink($this->inbound('mándame el link para pagar', 'w.rt2'))->assertOk();
        $primera = PaymentTransaction::sole()->reference;

        $this->commitLink($this->inbound('mándamelo otra vez', 'w.rt3'))->assertOk();

        $this->assertSame(1, PaymentTransaction::count(), 'ninguna segunda referencia');
        $this->assertSame($primera, PaymentTransaction::sole()->reference);
    }

    // ── Y lo que NO es una petición de pago ──────────────────────────────────

    /**
     * Preguntar por la app sigue siendo la app.
     *
     * La mitad que importa proteger: el arreglo no puede convertir cualquier
     * turno en un cobro. Un cobro que aparece sin que nadie lo pida es peor
     * que un enlace de descarga de más.
     */
    #[DataProvider('formasDePedirLaApp')]
    public function test_an_app_request_never_gets_a_checkout(string $texto): void
    {
        $this->commitLink($this->inbound($texto, 'w.'.md5($texto)), [
            'intent' => SalesIntents::GENERAL_INFO,
            'tools_requested' => [SalesIntents::TOOL_APP_LINKS_SEND],
            'reply_draft' => 'Te paso los enlaces para descargarla.',
            'recommended_plan_id' => null,
        ])->assertOk();

        $this->assertSame(0, PaymentTransaction::count(), $texto.' → no se acuña ningún cobro');
        $this->assertStringNotContainsString('checkout.wompi.co', $this->todoElSaliente(), $texto);
    }

    public static function formasDePedirLaApp(): array
    {
        return array_map(fn ($f) => [$f], [
            'cómo descargo la app',
            'quiero el link de la app',
            'pásame la app',
            'dónde bajo la aplicación',
        ]);
    }

    // ── Las barreras siguen donde estaban ────────────────────────────────────

    /** Fuera del canario no hay cobro, por mucha intención de pago que haya. */
    public function test_outside_the_canary_there_is_no_checkout(): void
    {
        config()->set('marketing.ultron.payment_links_enabled', false);
        config()->set('marketing.ultron.payment_canary_conversation_id', $this->conversation->id + 1000);

        $this->commitLink($this->inbound('quiero pagarlo ya', 'w.rt4'), [
            'tools_requested' => [SalesIntents::TOOL_PAYMENT_LINK_SEND],
        ])->assertOk();

        $this->assertSame(0, PaymentTransaction::count());
        $this->assertStringNotContainsString('checkout.wompi.co', $this->todoElSaliente());
    }

    /** Un pago ya aprobado no vuelve a cobrarse, diga lo que diga la persona. */
    public function test_an_approved_payment_is_never_charged_again_by_intent(): void
    {
        $this->commitLink($this->inbound('mándame el link', 'w.rt5'))->assertOk();
        PaymentTransaction::sole()->forceFill(['status' => PaymentStateMachine::APPROVED])->save();

        // La fase ya está en WON tras el pago: proponer CLOSING sería una
        // transición ilegal, y eso lo juzga la máquina de fases, no esto.
        $this->commitLink($this->inbound('quiero pagarlo otra vez', 'w.rt6'), [
            'next_state' => P::WON,
            'tools_requested' => [SalesIntents::TOOL_PAYMENT_LINK_SEND],
        ])->assertOk();

        $this->assertSame(1, PaymentTransaction::count(), 'ninguna transacción nueva');
        $this->assertSame(
            'already_paid',
            MarketingAiAction::latest('id')->first()->metadata['payment_link']['reason'] ?? null,
        );
    }

    /**
     * Un plan que dejó de venderse no llega ni a cobrarse ni a contestarse.
     *
     * El turno entero muere en 422 antes de mi camino: lo tumba la resolución
     * del plan, que es anterior y más dura. Se fija así y no con un 200 porque
     * el contrato real es ése, y un test que afirmara lo contrario estaría
     * describiendo un sistema que no existe.
     */
    public function test_a_plan_that_stopped_being_sellable_is_not_charged(): void
    {
        $this->plan->forceFill(['sellable' => false])->save();

        $this->commitLink($this->inbound('quiero pagarlo', 'w.rt7'))
            ->assertStatus(422)
            ->assertJsonPath('code', 'plan_not_sellable');

        $this->assertSame(0, PaymentTransaction::count());
        $this->assertSame(0, $this->salientes()->count(), 'y no sale ningún mensaje');
    }

    // ── El turno real que falló en el canario ────────────────────────────────

    /**
     * «y tienes un link de pago mas directo de casualidad?»
     *
     * El turno está copiado del canario, con sus tres datos reales: el modelo
     * clasificó bien —`payment_link_request`—, el resolver dio `none` y el
     * modelo pidió los enlaces de la APP. Con dos testigos, ninguno de los dos
     * se cumplía, así que el cobro se saltó y salió «No contamos con un link de
     * pago directo por ahora».
     *
     * El enlace existía. La frase era falsa.
     */
    public function test_the_measured_canary_failure_now_gets_the_checkout(): void
    {
        $r = $this->commitLink($this->inbound('y tienes un link de pago mas directo de casulidad?', 'w.real1'), [
            'intent' => SalesIntents::PAYMENT_LINK_REQUEST,
            'tools_requested' => [SalesIntents::TOOL_APP_LINKS_SEND],
            'reply_draft' => 'No contamos con un link de pago directo por ahora, pero puedes hacer el pago seguro desde la app Iron Body Workout o directamente en el gimnasio cuando vengas.',
        ])->assertOk();

        $saliente = $this->todoElSaliente();

        $this->assertStringContainsString('checkout.wompi.co', $saliente, 'el cobro existía y no salió');
        $this->assertSame(1, PaymentTransaction::count());

        // Y la frase falsa NO sale: el borrador que la llevaba se tira entero.
        $this->assertStringNotContainsString('No contamos con un link de pago', $saliente);
        $this->assertStringNotContainsString('no contamos con', mb_strtolower($saliente));

        // Una sola respuesta visible, no el cobro debajo de la negación.
        $this->assertCount(1, $this->salientes());

        // Y los enlaces de la app no sustituyen al cobro.
        $this->assertStringNotContainsString('play.google.com', $saliente);
        $this->assertStringNotContainsString('apps.apple.com', $saliente);
    }

    /**
     * La invariante, por debajo del enrutado: cae el BORRADOR, no el turno.
     *
     * Aquí la intención NO es de pago, así que el enrutado ni se plantea
     * acuñar; pero la respuesta niega un enlace que sí está disponible. Esa
     * frase no sale.
     *
     * Y el turno tampoco muere. Esto llegó a lanzar un 422 y era peor que el
     * problema: la fila de la acción ya se había escrito como ejecutada dos
     * pasos antes, así que el vigía de turnos sin desenlace no habría visto
     * nada que abrir. Cambiar una mentira por un silencio invisible no es
     * arreglarlo.
     */
    public function test_denying_an_available_checkout_drops_the_sentence_not_the_turn(): void
    {
        $this->commitLink($this->inbound('y como hago para pagar?', 'w.real2'), [
            'intent' => SalesIntents::GENERAL_INFO,
            'tools_requested' => [],
            'reply_draft' => 'Por ahora no tenemos enlace de pago. Puedes venir al gimnasio y te ayudamos con el registro.',
        ])->assertOk();

        $saliente = $this->todoElSaliente();

        $this->assertSame(1, $this->salientes()->count(), 'el turno se quedó mudo');
        $this->assertStringNotContainsString('no tenemos enlace de pago', mb_strtolower($saliente));
        $this->assertStringContainsString('gimnasio', $saliente, 'se llevó por delante el resto del mensaje');
        $this->assertSame(0, PaymentTransaction::count(), 'la invariante no acuña: corrige el texto');

        /*
         * Y la corrección deja expediente.
         *
         * Sin esto, el CRM reescribiría al modelo en silencio para siempre: el
         * log no llega a ningún panel y la causa raíz sigue en el prompt. Que
         * pase en un turno o en todos tiene que poder distinguirse.
         */
        $meta = MarketingAiAction::latest('id')->first()->metadata ?? [];
        $this->assertSame(
            'machine_reply_denies_available_checkout',
            $meta['checkout_denial_dropped']['code'] ?? null,
        );
        $this->assertSame(1, $meta['checkout_denial_dropped']['frases_retiradas'] ?? 0);
    }

    /**
     * Y si TODA la respuesta era la frase que mentía, sale texto curado.
     *
     * El caso límite del anterior: quitar la única frase dejaría el mensaje
     * vacío, y un mensaje vacío es un turno mudo con otro nombre.
     */
    public function test_when_the_lie_was_the_whole_reply_a_curated_text_goes_out(): void
    {
        $this->commitLink($this->inbound('tienen link de pago?', 'w.real2b'), [
            'intent' => SalesIntents::GENERAL_INFO,
            'tools_requested' => [],
            'reply_draft' => 'No contamos con un link de pago por ahora.',
        ])->assertOk();

        $saliente = $this->todoElSaliente();

        $this->assertSame(1, $this->salientes()->count());
        $this->assertNotSame('', trim($saliente));
        $this->assertStringNotContainsString('no contamos con', mb_strtolower($saliente));
    }

    /**
     * Y la misma frase, cuando es VERDAD, sale sin problema.
     *
     * Es la otra mitad de la invariante y la que impide que se convierta en una
     * mordaza: fuera del canario no hay cobro que ofrecer, así que decir que no
     * hay enlace es exacto.
     */
    public function test_the_same_denial_is_allowed_when_there_is_no_checkout(): void
    {
        config()->set('marketing.ultron.payment_canary_conversation_id', $this->conversation->id + 500);

        $this->commitLink($this->inbound('y como hago para pagar?', 'w.real3'), [
            'intent' => SalesIntents::GENERAL_INFO,
            'tools_requested' => [],
            'reply_draft' => 'Por ahora no tenemos enlace de pago, el pago se hace en el gimnasio cuando vengas.',
        ])->assertOk();

        $this->assertSame(1, $this->salientes()->count());
        $this->assertSame(0, PaymentTransaction::count());
    }

    /**
     * Preguntar POR EL MEDIO de pago no es pedir que te cobren.
     *
     * Informar es correcto; acuñar no. La respuesta enumera y no niega nada,
     * así que ni el testigo nuevo ni la invariante tienen nada que hacer.
     *
     * @param  string  $texto  lo que escribe la persona
     * @param  string  $intent  cómo lo clasifica el modelo
     * @param  string  $borrador  lo que responde
     */
    #[DataProvider('preguntasQueNoCobran')]
    public function test_asking_about_payment_does_not_mint(string $texto, string $intent, string $borrador): void
    {
        $this->commitLink($this->inbound($texto, 'w.nomint.'.md5($texto)), [
            'intent' => $intent,
            'tools_requested' => [],
            'reply_draft' => $borrador,
        ])->assertOk();

        $this->assertSame(0, PaymentTransaction::count(), 'acuñó un cobro que nadie pidió');
        $this->assertStringNotContainsString('checkout.wompi.co', $this->todoElSaliente());
    }

    /** @return array<string,array{0:string,1:string,2:string}> */
    public static function preguntasQueNoCobran(): array
    {
        return [
            'que metodos de pago tienen' => [
                'que metodos de pago tienen?',
                SalesIntents::GENERAL_INFO,
                'Puedes pagar con tarjeta, Nequi o en efectivo directamente en el gimnasio.',
            ],
            'cuanto cuesta' => [
                'cuanto cuesta el plan mensual?',
                SalesIntents::PRICING_QUESTION,
                'El {{PLAN_NAME}} está en {{PLAN_PRICE}} e incluye acceso ilimitado al gimnasio.',
            ],
            'no me interesa' => [
                'no me interesa por ahora',
                SalesIntents::NOT_INTERESTED,
                'Sin problema, aquí quedo por si más adelante quieres retomarlo.',
            ],
        ];
    }

    /**
     * La cortesía no puede acuñar un cobro. Reproducción de la revisión.
     *
     * La sonda fue exacta: «no me interesa por ahora», mal etiquetado como
     * intención de pago, sin herramientas pedidas y con el resolver en `none`.
     * La ÚNICA variable que cambiaba respecto al control era una frase de
     * cortesía en el borrador —«no hay afán con el link de pago»— y con ella
     * salía un checkout pagable hacia quien acababa de decir que no.
     *
     * Es el daño que el enrutado existe para impedir, entrando por la puerta
     * que abrí yo al ensanchar el detector.
     */
    public function test_courtesy_never_mints_for_someone_who_said_no(): void
    {
        $this->commitLink($this->inbound('no me interesa por ahora', 'w.cortesia'), [
            'intent' => SalesIntents::HIGH_INTENT_CLOSE,
            'tools_requested' => [],
            'reply_draft' => 'Sin problema, no hay afán con el link de pago, cuando quieras me escribes.',
        ])->assertOk();

        $this->assertSame(0, PaymentTransaction::count(), 'se acuñó un cobro por una frase de cortesía');
        $this->assertStringNotContainsString('checkout.wompi.co', $this->todoElSaliente());
    }
}
