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
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * UNA SOLA PUERTA ACUÑA DINERO.
 *
 * Al cobro automático se llega por dos sitios: el checkout va DENTRO de la
 * respuesta cuando la intención es de pago y está corroborada, o sale en un
 * mensaje propio cuando el modelo pide la herramienta con otra intención
 * encima. Durante un día fueron dos copias de las mismas seis guardas, escritas
 * a mano en dos métodos distintos.
 *
 * Eran equivalentes, así que no fallaba nada. Dejaron de serlo en cuanto una de
 * las dos cambió: el corroborador de intención entró sólo en el camino nuevo, y
 * ese mismo camino nuevo no miraba si había otro link vivo, de modo que generó
 * un segundo cobro para un plan distinto —dos enlaces pagables a la vez, y el
 * checkout de Wompi no se anula desde aquí—.
 *
 * Por eso aquí no se prueba que las guardas funcionen: eso ya lo cubren
 * {@see PaymentCanaryGateTest} y {@see UltronPaymentLinkTest}. Se prueba que
 * hay UNA, y que las dos entradas obtienen de ella exactamente la misma
 * respuesta. Lo primero se mide sobre el código, porque una duplicación es un
 * hecho del código y no del comportamiento; lo segundo, entrada por entrada.
 */
class PaymentMintAuthorityTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-internal-secret';

    /** El único sitio del servicio con permiso para crear una transacción. */
    private const AUTORIDAD = 'mintCheckout';

    /**
     * Lo que sólo puede aparecer dentro de la autoridad.
     *
     * `generateForLead` es el que de verdad acuña —crea o reutiliza la fila de
     * `payment_transactions`—; los demás son las decisiones que lo rodean.
     */
    private const ACUÑACION = [
        'generateForLead',
        'assertCanGeneratePaymentLink',
        'another_link_in_flight',
        'wompi_checkout_not_configured',
        'link_not_safe_to_send',
    ];

    private Plan $plan;

    private Plan $otroPlan;

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
        config()->set('marketing.ultron.payment_links_enabled', true);
        Http::fake();

        $this->plan = Plan::create(['name' => 'Plan Mensual', 'price' => 80000, 'duration_days' => 30, 'active' => true, 'sellable' => true, 'benefits' => json_encode(['Acceso ilimitado'])]);
        $this->otroPlan = Plan::create(['name' => 'Plan Trimestral', 'price' => 210000, 'duration_days' => 90, 'active' => true, 'sellable' => true, 'benefits' => json_encode(['Acceso ilimitado'])]);
        $this->lead = MarketingLead::create(['channel' => 'whatsapp', 'source' => 'inbound', 'phone' => '3150536026', 'meta_user_id' => '573150536026', 'name' => 'Prospecto', 'status' => MarketingLead::STATUS_HOT]);
        $this->conversation = MarketingConversation::create(['lead_id' => $this->lead->id, 'channel' => 'whatsapp', 'status' => 'open', 'ai_enabled' => true, 'human_takeover' => false, 'commercial_phase' => P::CLOSING]);
    }

    /**
     * Las dos entradas reales al cobro.
     *
     * Lo ÚNICO que cambia entre ellas es la intención validada, y es
     * precisamente eso lo que decide el camino: con una intención de pago el
     * checkout se inyecta en la respuesta; con cualquier otra, la petición
     * explícita de la herramienta lo saca en un mensaje aparte.
     *
     * @return array<string,array{0:string}>
     */
    public static function entradas(): array
    {
        return [
            'dentro de la respuesta' => [SalesIntents::PAYMENT_LINK_REQUEST],
            'mensaje aparte (herramienta)' => [SalesIntents::PRICING_QUESTION],
        ];
    }

    private function h(): array
    {
        return ['Authorization' => 'Bearer '.self::SECRET];
    }

    private function inbound(string $body, string $wamid): MarketingMessage
    {
        return MarketingMessage::create(['conversation_id' => $this->conversation->id, 'direction' => MarketingMessage::DIRECTION_INBOUND, 'sender_type' => MarketingMessage::SENDER_LEAD, 'body' => $body, 'meta_message_id' => $wamid, 'status' => 'received']);
    }

    /**
     * Un turno real: mensaje que entra, decisión propuesta, commit.
     *
     * El texto y el borrador van por parámetro y son DISTINTOS en cada turno a
     * propósito. Repetir el mismo borrador dispara la guarda de novedad, el
     * turno no se emite y la herramienta ya no se ejecuta: el turno se moriría
     * por una razón que no tiene nada que ver con lo que aquí se mide.
     */
    private function commit(string $intent, string $wamid, string $texto, string $borrador, array $overrides = []): TestResponse
    {
        $m = $this->inbound($texto, $wamid);
        $token = (string) $this->postJson('/api/internal/marketing/ai/decide', ['conversation_id' => $this->conversation->id, 'message_id' => $m->id], $this->h())->json('decide_token');

        return $this->postJson('/api/internal/marketing/ai/commit', [
            'source_type' => 'inbound_message', 'source_event_id' => $m->id, 'idempotency_key' => $wamid,
            'conversation_id' => $this->conversation->id, 'decide_token' => $token,
            'proposal' => array_merge([
                'strategy_goal' => 'collect_payment', 'next_state' => P::CLOSING, 'intent' => $intent,
                'reply_draft' => $borrador,
                'recommended_plan_id' => $this->plan->id, 'main_barrier' => null, 'next_best_action' => 'recommend_plan',
                'confidence' => 0.9, 'tools_requested' => [SalesIntents::TOOL_PAYMENT_LINK_SEND],
            ], $overrides),
            'critic' => ['verdict' => 'pass', 'attempt' => 1, 'score' => 0.9, 'issues' => [], 'hard_fail' => null],
        ], $this->h());
    }

    /** El veredicto del cobro, escriba quien lo escriba: las dos entradas anotan aquí. */
    private function motivo(): ?string
    {
        return MarketingAiAction::latest('id')->first()->metadata['payment_link']['reason'] ?? null;
    }

    /**
     * La prueba de la duplicación es del código, no del comportamiento.
     *
     * Dos copias equivalentes se comportan igual por definición: ningún test
     * funcional las distingue mientras sigan siendo equivalentes, y cuando
     * dejan de serlo ya es tarde. Lo que sí es medible es que el sitio donde se
     * acuña sea uno.
     *
     * Falla sobre el commit anterior: allí cada uno de estos cinco aparecía dos
     * veces, en `mintCheckout()` y en `execPaymentLink()`.
     */
    public function test_only_one_method_can_mint_money(): void
    {
        $metodos = $this->metodosDe(app_path('Services/Marketing/Ultron/UltronCommitService.php'));

        $this->assertArrayHasKey(self::AUTORIDAD, $metodos, 'la autoridad tiene que existir');

        foreach (self::ACUÑACION as $marca) {
            $dueños = array_keys(array_filter($metodos, fn (string $cuerpo) => str_contains($cuerpo, $marca)));

            $this->assertSame(
                [self::AUTORIDAD],
                $dueños,
                "«{$marca}» sólo puede vivir en ".self::AUTORIDAD.'(); está además en: '.implode(', ', array_diff($dueños, [self::AUTORIDAD])),
            );
        }
    }

    /**
     * Cuerpo de cada método del servicio, sin comentarios.
     *
     * Sin quitarlos, el docblock que explica por qué las guardas ya no se
     * copian contaría como una copia y el test se acusaría a sí mismo.
     *
     * @return array<string,string>
     */
    private function metodosDe(string $ruta): array
    {
        $tokens = array_values(array_filter(
            token_get_all(file_get_contents($ruta)),
            fn ($t) => ! is_array($t) || ! in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true),
        ));

        $metodos = [];
        for ($i = 0; $i < count($tokens); $i++) {
            if (! is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
                continue;
            }
            $nombre = null;
            for ($j = $i + 1; $j < count($tokens); $j++) {
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                    $nombre = $tokens[$j][1];
                    break;
                }
            }
            if ($nombre === null) {
                continue;
            }
            // Del primer `{` hasta el que lo cierra: el cuerpo, y sólo el cuerpo.
            $cuerpo = '';
            $nivel = 0;
            $dentro = false;
            for ($j = $i; $j < count($tokens); $j++) {
                $texto = is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
                if ($texto === '{') {
                    $nivel++;
                    $dentro = true;
                } elseif ($texto === '}') {
                    $nivel--;
                    if ($dentro && $nivel === 0) {
                        break;
                    }
                } elseif ($texto === ';' && ! $dentro) {
                    break; // método abstracto o de interfaz
                }
                if ($dentro) {
                    $cuerpo .= $texto;
                }
            }
            $metodos[$nombre] = $cuerpo;
        }

        return $metodos;
    }

    /**
     * Y las dos entradas son DE VERDAD dos.
     *
     * Sin esto, el resto de la clase se volvería mentira en silencio el día que
     * un cambio de rutas mandara las dos intenciones por el mismo sitio:
     * seguirían pasando en verde, midiendo una sola puerta dos veces. Ya pasó
     * algo así con unos tests que llamaban a `analyze()` directamente y no
     * podían ver un error de orden.
     *
     * Se mide por donde se nota: cuántos globos salen. Con intención de pago el
     * checkout viaja dentro de la respuesta y sale UN mensaje; con la
     * herramienta pedida desde otra intención sale la respuesta y DESPUÉS el
     * cobro, dos. En los dos casos el enlace aparece exactamente una vez.
     *
     * @return array<string,array{0:string,1:int}>
     */
    public static function entregas(): array
    {
        return [
            'dentro de la respuesta' => [SalesIntents::PAYMENT_LINK_REQUEST, 1],
            'mensaje aparte (herramienta)' => [SalesIntents::PRICING_QUESTION, 2],
        ];
    }

    #[DataProvider('entregas')]
    public function test_each_intent_uses_the_entry_it_is_supposed_to(string $intent, int $globos): void
    {
        $this->commit($intent, 'w.entrega.1', 'dale, mándame el link', 'Listo, te paso el link de pago del {{PLAN_NAME}}.')->assertOk();

        $salientes = MarketingMessage::where('conversation_id', $this->conversation->id)
            ->where('direction', MarketingMessage::DIRECTION_OUTBOUND)->orderBy('id')->get();

        $this->assertCount($globos, $salientes, 'el cobro no salió por la puerta que le toca');
        $this->assertSame(
            1,
            $salientes->filter(fn ($m) => str_contains((string) $m->body, 'checkout.wompi.co'))->count(),
            'el enlace, una sola vez',
        );
        $this->assertSame(1, PaymentTransaction::count());
    }

    /** Pedir dos veces el mismo plan devuelve el mismo cobro, se entre por donde se entre. */
    #[DataProvider('entradas')]
    public function test_asking_twice_reuses_the_same_checkout(string $intent): void
    {
        $this->commit($intent, 'w.reuse.1', 'dale, mándame el link', 'Listo, te paso el link de pago del {{PLAN_NAME}}.')->assertOk();
        $primera = PaymentTransaction::sole()->reference;

        $this->commit($intent, 'w.reuse.2', 'no me llegó, mándalo otra vez', 'Claro, te reenvío el mismo enlace del {{PLAN_NAME}}; sigue activo.')->assertOk();

        $this->assertSame(1, PaymentTransaction::count(), 'ningún cobro nuevo');
        $this->assertSame($primera, PaymentTransaction::sole()->reference);
    }

    /** Un segundo plan no consigue un segundo link vivo por ninguna de las dos puertas. */
    #[DataProvider('entradas')]
    public function test_a_second_plan_never_gets_a_second_live_link(string $intent): void
    {
        $this->commit($intent, 'w.flight.1', 'dale, mándame el link', 'Listo, te paso el link de pago del {{PLAN_NAME}}.')->assertOk();

        $this->commit($intent, 'w.flight.2', 'mejor el trimestral, mándame ese', 'Perfecto, cambiamos al {{PLAN_NAME}} y te lo dejo listo.', [
            'recommended_plan_id' => $this->otroPlan->id,
        ])->assertOk();

        $this->assertSame(1, PaymentTransaction::count(), 'ningún segundo cobro en vuelo');
        $this->assertSame('another_link_in_flight', $this->motivo());
        $this->assertTrue((bool) $this->conversation->fresh()->staff_review_pending, 'lo resuelve una persona');
    }

    /** Un pago aprobado no vuelve a cobrarse por ninguna de las dos puertas. */
    #[DataProvider('entradas')]
    public function test_an_approved_payment_is_never_charged_again(string $intent): void
    {
        $this->commit($intent, 'w.paid.1', 'dale, mándame el link', 'Listo, te paso el link de pago del {{PLAN_NAME}}.')->assertOk();
        PaymentTransaction::sole()->forceFill(['status' => PaymentStateMachine::APPROVED])->save();

        $this->commit($intent, 'w.paid.2', 'quiero pagarlo otra vez', 'Tu pago del {{PLAN_NAME}} ya quedó confirmado, no tienes que volver a pagarlo.', [
            'next_state' => P::WON,
        ])->assertOk();

        $this->assertSame(1, PaymentTransaction::count(), 'ninguna transacción nueva');
        $this->assertSame('already_paid', $this->motivo());
    }

    /** Sin plan no hay qué cobrar, y ninguna de las dos puertas lo inventa. */
    #[DataProvider('entradas')]
    public function test_without_a_plan_there_is_nothing_to_charge(string $intent): void
    {
        $this->commit($intent, 'w.noplan.1', 'mándame el link', 'Claro, dime qué plan te interesa y lo cerramos.', [
            'recommended_plan_id' => null,
            'next_best_action' => 'ask_question',
        ])->assertOk();

        $this->assertSame(0, PaymentTransaction::count());
        $this->assertSame('no_plan_to_charge', $this->motivo());
    }
}
