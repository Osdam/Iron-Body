<?php

namespace Tests\Feature\Marketing\Torture;

use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Models\Member;
use App\Models\Plan;
use App\Models\User;
use App\Services\Marketing\CommercialPhaseMachine as P;
use App\Services\Marketing\SalesIntents;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Support\UltronTurnEvents;
use Tests\TestCase;

/**
 * Banco de pruebas del LABORATORIO DE TORTURA (PUNTO 13).
 *
 * El resto de la suite tortura el MENSAJE ENTRANTE: da por hecho que el texto
 * de un desconocido es hostil. Aquí se tortura lo otro, que hasta ahora se
 * daba por bueno: la PROPUESTA que llega de n8n. Un modelo de lenguaje puede
 * proponer cualquier cosa —por error, por deriva o porque alguien consiguió
 * moverlo—, y la promesa de esta arquitectura es que Laravel decide igual.
 *
 * Cada caso hace el recorrido REAL: `POST /ai/decide` para conseguir el token
 * firmado, y `POST /ai/commit` con la propuesta envenenada. Nada de dobles: se
 * mira lo que el sistema hace de verdad (qué responde, qué mensaje sale, qué
 * fila queda), porque el valor de estas pruebas es exactamente ese.
 *
 * Esta clase NO contiene casos: es el banco donde se montan. Si se rompe, se
 * rompen las cuatro tandas a la vez, así que {@see TortureHarnessTest} la
 * vigila.
 */
abstract class TortureCase extends TestCase
{
    use RefreshDatabase;
    use UltronTurnEvents;

    protected const SECRET = 'test-internal-secret';

    /** Plan vendible por defecto: el que el negocio cotiza. */
    protected Plan $plan;

    /** Plan vendible de otra duración, para «háblame de otro». */
    protected Plan $trimestral;

    /** Plan NO vendible (interno): existe en la BD y nunca puede cotizarse. */
    protected Plan $interno;

    protected MarketingLead $lead;

    protected MarketingConversation $conversation;

    /** Contador para que cada mensaje entrante tenga su propio id de Meta. */
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        /*
         * ULTRON, encendido. Estas pruebas describen un mundo en el que ULTRON
         * atiende; desde que `/ai/commit` exige el interruptor maestro, dejarlo
         * apagado no probaría el turno, probaría el apagado —y eso tiene su
         * propia prueba.
         */
        config()->set('marketing.ultron.enabled', true);

        config()->set('automation.internal_secret', self::SECRET);
        config()->set('meta.enabled', false);
        config()->set('marketing.ai.enabled', true);
        config()->set('marketing.ai.driver', 'fake');
        config()->set('marketing.inbound.auto_analyze', false);
        // Las dos banderas que gobiernan el dinero arrancan APAGADAS, como en
        // producción. El caso que necesite el motor lo enciende a propósito.
        config()->set('marketing.ultron.payment_links_enabled', false);
        Http::preventStrayRequests();
        Http::fake();

        $this->plan = Plan::create([
            'name' => 'Plan Mensual', 'price' => 80000, 'duration_days' => 30,
            'active' => true, 'sellable' => true, 'benefits' => json_encode(['Acceso ilimitado']),
        ]);
        $this->trimestral = Plan::create([
            'name' => 'Plan Trimestral', 'price' => 210000, 'duration_days' => 90,
            'active' => true, 'sellable' => true, 'benefits' => json_encode(['Acceso ilimitado']),
        ]);
        $this->interno = Plan::create([
            'name' => 'Convenio Interno', 'price' => 0, 'duration_days' => 30,
            'active' => true, 'sellable' => false,
        ]);

        $this->lead = MarketingLead::create([
            'channel' => 'whatsapp', 'source' => 'inbound', 'phone' => '3150536026',
            'meta_user_id' => '573150536026', 'name' => 'Prospecto', 'status' => MarketingLead::STATUS_NEW,
        ]);
        $this->conversation = MarketingConversation::create([
            'lead_id' => $this->lead->id, 'channel' => 'whatsapp', 'status' => 'open',
            'ai_enabled' => true, 'human_takeover' => false, 'commercial_phase' => P::RECOMMENDATION,
        ]);
    }

    // ── El recorrido real ────────────────────────────────────────────────────

    /** @return array<string,string> */
    protected function h(): array
    {
        return ['Authorization' => 'Bearer '.self::SECRET];
    }

    /** Un mensaje entrante de la persona, con su id de Meta único. */
    protected function inbound(string $body): MarketingMessage
    {
        $this->seq++;

        return MarketingMessage::create([
            'conversation_id' => $this->conversation->id,
            'direction' => MarketingMessage::DIRECTION_INBOUND,
            'sender_type' => MarketingMessage::SENDER_LEAD,
            'body' => $body,
            'meta_message_id' => 'torture.'.$this->seq.'.'.uniqid(),
            'status' => 'received',
        ]);
    }

    protected function decide(MarketingMessage $m): TestResponse
    {
        return $this->postJson('/api/internal/marketing/ai/decide', [
            'conversation_id' => $this->conversation->id, 'message_id' => $m->id,
        ], $this->h());
    }

    /**
     * El commit REAL con una propuesta envenenada.
     *
     * `$proposal` se funde sobre una propuesta inofensiva, así que cada caso
     * escribe SOLO el veneno que quiere probar. `$envelope` permite torturar el
     * sobre (token de otra conversación, idempotency_key repetida, campos
     * inesperados); `$critic` permite torturar el veredicto.
     *
     * @param  array<string,mixed>  $proposal
     * @param  array<string,mixed>  $critic
     * @param  array<string,mixed>  $envelope
     */
    protected function commit(MarketingMessage $m, array $proposal = [], array $critic = [], array $envelope = []): TestResponse
    {
        $token = $envelope['decide_token'] ?? $this->decide($m)->json('decide_token');

        $payload = array_merge([
            'source_type' => 'inbound_message',
            'source_event_id' => $m->id,
            'idempotency_key' => $m->meta_message_id,
            'conversation_id' => $this->conversation->id,
            'decide_token' => $token,
            'proposal' => array_merge($this->benignProposal(), $proposal),
            'critic' => array_merge(['verdict' => 'pass', 'attempt' => 1, 'score' => 0.9, 'issues' => [], 'hard_fail' => null], $critic),
        ], $envelope);

        return $this->postJson('/api/internal/marketing/ai/commit', $payload, $this->h());
    }

    /** La propuesta que un modelo sano enviaría: el punto de partida de cada caso. */
    protected function benignProposal(): array
    {
        return [
            'strategy_goal' => 'collect_data',
            'next_state' => P::RECOMMENDATION,
            'intent' => SalesIntents::GENERAL_INFO,
            'reply_draft' => 'Claro, te cuento con gusto.',
            'recommended_plan_id' => null,
            'main_barrier' => null,
            'next_best_action' => 'recommend_plan',
            'confidence' => 0.8,
            'tools_requested' => [],
        ];
    }

    // ── Lo que quedó al otro lado ────────────────────────────────────────────

    /** Todo lo que el CRM mandó por este chat, en orden. */
    protected function outbound(): Collection
    {
        return MarketingMessage::where('conversation_id', $this->conversation->id)
            ->where('direction', MarketingMessage::DIRECTION_OUTBOUND)
            ->orderBy('id')->get();
    }

    /** El texto que leería la persona en el último mensaje, o cadena vacía. */
    protected function lastOutboundBody(): string
    {
        return (string) ($this->outbound()->last()->body ?? '');
    }

    /** Mensajes que llevan un enlace de pago (los escribe Laravel, nunca el modelo). */
    protected function paymentLinkMessages(): Collection
    {
        return $this->outbound()->filter(fn (MarketingMessage $m) => str_contains((string) $m->body, 'checkout.wompi.co'));
    }

    // ── Escenarios que un caso puede necesitar ───────────────────────────────

    /**
     * Enciende el motor de pagos con credenciales de prueba.
     *
     * Son valores de test, no secretos: el checkout se firma localmente y
     * `Http::fake()` impide cualquier salida real.
     */
    protected function enablePaymentEngine(): void
    {
        config()->set('wompi.env', 'production');
        config()->set('wompi.public_key', 'pub_prod_test_key');
        config()->set('wompi.private_key', 'prv_prod_test_key');
        config()->set('wompi.integrity_secret', 'integrity_test_secret');
        config()->set('wompi.events_secret', 'events_test_secret');
        config()->set('wompi.checkout.base_url', 'https://checkout.wompi.co/p/');
        config()->set('wompi.checkout.redirect_url', 'https://web.ironbodyneiva.cloud/pago');
        config()->set('marketing.ultron.payment_links_enabled', true);
    }

    /**
     * Convierte al lead en socio IDENTIFICADO con membresía viva.
     *
     * Es de pago único, que es como está la mayoría: el plan vive como NOMBRE
     * en `users.plan` y no hay fila en `membership_subscriptions`.
     */
    protected function makeMember(int $endsInDays = 20, int $startedDaysAgo = 10, ?Plan $plan = null): Member
    {
        $plan ??= $this->plan;
        $user = User::create([
            'name' => 'Socia', 'email' => 'socia-'.uniqid().'@ironbody.test', 'password' => bcrypt('secret-password'),
            'document' => '10'.random_int(10000000, 99999999), 'status' => 'active', 'plan' => $plan->name,
            'membership_start_date' => now()->subDays($startedDaysAgo)->toDateString(),
            'membership_end_date' => now()->addDays($endsInDays)->toDateString(),
        ]);
        $member = Member::create([
            'user_id' => $user->id, 'full_name' => 'Socia', 'email' => $user->email,
            'document_number' => $user->document, 'phone' => '3150536026', 'status' => Member::STATUS_ACTIVE,
        ]);
        $this->lead->forceFill(['member_id' => $member->id])->save();

        return $member;
    }
}
