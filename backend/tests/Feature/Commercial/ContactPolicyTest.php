<?php

namespace Tests\Feature\Commercial;

use App\Models\CommercialOpportunity;
use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Services\Commercial\CommercialSubject;
use App\Services\Commercial\CommercialVocabulary as V;
use App\Services\Commercial\ContactPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Lo único que separa un sistema comercial de una máquina de molestar.
 *
 * El motor de oportunidades es bueno encontrando razones para escribir. Sin un
 * freno explícito, alguien con tres oportunidades abiertas —renovación, app sin
 * vincular y referidos— recibiría tres mensajes el mismo martes y bloquearía el
 * número del gimnasio.
 *
 * Se prueba que el límite cuenta sobre la PERSONA y no sobre la oportunidad,
 * que es justo el agujero por el que se colaría el acoso.
 */
class ContactPolicyTest extends TestCase
{
    use RefreshDatabase;

    private MarketingLead $lead;

    private MarketingConversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('commercial.contact_limits', [
            'max_proactive_per_week' => 2,
            'min_hours_between' => 48,
            'quiet_hours_start' => 21,
            'quiet_hours_end' => 8,
            'timezone' => 'America/Bogota',
        ]);

        // Media tarde en Neiva: fuera de horas de silencio, para que las
        // pruebas de frecuencia no dependan de cuándo se ejecuten.
        Carbon::setTestNow(Carbon::parse('2026-08-06 19:00:00', 'UTC')); // 14:00 en Neiva

        $this->lead = MarketingLead::create([
            'channel' => 'whatsapp', 'source' => 'inbound', 'meta_user_id' => '573150536026',
            'phone' => '3150536026', 'name' => 'Prospecto', 'status' => MarketingLead::STATUS_NEW,
        ]);
        $this->conversation = MarketingConversation::create([
            'lead_id' => $this->lead->id, 'channel' => 'whatsapp', 'status' => 'open',
            'ai_enabled' => true, 'human_takeover' => false,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function policy(): ContactPolicy
    {
        return app(ContactPolicy::class);
    }

    private function opportunity(array $attributes = []): CommercialOpportunity
    {
        return CommercialOpportunity::create(array_merge([
            'marketing_lead_id' => $this->lead->id,
            'goal' => V::GOAL_RENEW,
            'status' => V::STATUS_OPEN,
            'next_action' => V::ACTION_OFFER_RENEWAL,
            'reason' => 'prueba',
            'max_attempts' => 3,
        ], $attributes));
    }

    private function subject(array $facts = []): CommercialSubject
    {
        return new CommercialSubject(...array_merge([
            'lead' => $this->lead,
            'lastInboundAt' => now()->subHours(2),
        ], $facts));
    }

    /** Un mensaje proactivo de la IA, del tipo que sí consume cuota. */
    private function proactiveMessage(Carbon $at): void
    {
        MarketingMessage::create([
            'conversation_id' => $this->conversation->id,
            'direction' => 'outbound', 'sender_type' => 'ai',
            'body' => 'seguimiento', 'metadata' => ['kind' => 'followup'],
        ])->forceFill(['created_at' => $at])->save();
    }

    public function test_a_clean_conversation_allows_contact(): void
    {
        $result = $this->policy()->check($this->opportunity(), $this->subject());

        $this->assertTrue($result['allowed']);
        $this->assertNull($result['reason']);
    }

    /** El opt-out gana a cualquier oportunidad, por valiosa que sea. */
    public function test_opt_out_beats_everything(): void
    {
        $result = $this->policy()->check(
            $this->opportunity(['goal' => V::GOAL_RECOVER_PAYMENT_LINK, 'priority' => 100]),
            $this->subject(['doNotContact' => true]),
        );

        $this->assertFalse($result['allowed']);
        $this->assertSame('do_not_contact', $result['reason']);
    }

    /** Si un asesor lleva el caso, la automatización se calla. */
    public function test_nothing_automatic_while_a_person_is_in_control(): void
    {
        $result = $this->policy()->check($this->opportunity(), $this->subject(['needsHuman' => true]));

        $this->assertFalse($result['allowed']);
        $this->assertSame('human_in_control', $result['reason']);
    }

    public function test_two_messages_too_close_together_are_refused(): void
    {
        $this->proactiveMessage(now()->subHours(5));

        $result = $this->policy()->check($this->opportunity(), $this->subject());

        $this->assertFalse($result['allowed']);
        $this->assertSame('too_soon_since_last_contact', $result['reason']);
        // Y dice cuándo se podrá: un rechazo sin fecha no es accionable.
        $this->assertTrue($result['retry_at']->isFuture());
    }

    public function test_the_weekly_cap_stops_the_third_message(): void
    {
        $this->proactiveMessage(now()->subDays(5));
        $this->proactiveMessage(now()->subDays(3));

        $result = $this->policy()->check($this->opportunity(), $this->subject());

        $this->assertFalse($result['allowed']);
        $this->assertSame('weekly_contact_limit_reached', $result['reason']);
    }

    /**
     * El agujero que había que cerrar: el límite es por PERSONA. Tres
     * oportunidades distintas no dan derecho a tres mensajes el mismo día.
     */
    public function test_the_cap_counts_the_person_not_the_opportunity(): void
    {
        $this->proactiveMessage(now()->subDays(5));
        $this->proactiveMessage(now()->subDays(3));

        foreach ([V::GOAL_RENEW, V::GOAL_LINK_APP, V::GOAL_REQUEST_REFERRAL] as $goal) {
            $result = $this->policy()->check($this->opportunity(['goal' => $goal]), $this->subject());
            $this->assertFalse($result['allowed'], "La oportunidad {$goal} se saltó el límite semanal.");
        }
    }

    /** Responder no es perseguir: una respuesta no consume cuota proactiva. */
    public function test_replies_do_not_consume_the_proactive_quota(): void
    {
        foreach ([5, 4, 3] as $daysAgo) {
            MarketingMessage::create([
                'conversation_id' => $this->conversation->id,
                'direction' => 'outbound', 'sender_type' => 'ai',
                'body' => 'respuesta', 'metadata' => ['kind' => 'reply'],
            ])->forceFill(['created_at' => now()->subDays($daysAgo)])->save();
        }

        $this->assertTrue($this->policy()->check($this->opportunity(), $this->subject())['allowed']);
    }

    /** Un asesor humano escribiendo mucho tampoco bloquea al motor. */
    public function test_human_messages_do_not_consume_the_quota(): void
    {
        foreach ([5, 4, 3] as $daysAgo) {
            MarketingMessage::create([
                'conversation_id' => $this->conversation->id,
                'direction' => 'outbound', 'sender_type' => 'human',
                'body' => 'te atiendo yo',
            ])->forceFill(['created_at' => now()->subDays($daysAgo)])->save();
        }

        $this->assertTrue($this->policy()->check($this->opportunity(), $this->subject())['allowed']);
    }

    /** Nadie quiere una oferta del gimnasio a las 23:40. */
    public function test_no_messages_during_quiet_hours(): void
    {
        // 03:00 UTC = 22:00 en Neiva.
        Carbon::setTestNow(Carbon::parse('2026-08-07 03:00:00', 'UTC'));

        $result = $this->policy()->check($this->opportunity(), $this->subject());

        $this->assertFalse($result['allowed']);
        $this->assertSame('quiet_hours', $result['reason']);
    }

    /** La franja cruza la medianoche: las 6 de la mañana siguen siendo silencio. */
    public function test_early_morning_is_still_quiet(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-07 11:00:00', 'UTC')); // 06:00 en Neiva

        $result = $this->policy()->check($this->opportunity(), $this->subject());

        $this->assertFalse($result['allowed']);
        $this->assertSame('quiet_hours', $result['reason']);
    }

    /** El horario se mide en Neiva, no en el reloj del servidor. */
    public function test_quiet_hours_follow_neiva_not_the_server(): void
    {
        // 13:00 UTC es de madrugada en algunos husos, pero las 08:00 en Neiva:
        // hora perfectamente válida para escribir.
        Carbon::setTestNow(Carbon::parse('2026-08-07 13:00:00', 'UTC'));

        $this->assertTrue($this->policy()->check($this->opportunity(), $this->subject())['allowed']);
    }

    /** Pasadas 24 h del último mensaje del cliente, Meta exige plantilla. */
    public function test_after_24h_a_template_is_required(): void
    {
        $result = $this->policy()->check(
            $this->opportunity(),
            $this->subject(['lastInboundAt' => now()->subHours(30)]),
        );

        $this->assertTrue($result['allowed']);
        $this->assertTrue($result['requires_template']);
    }

    public function test_inside_the_window_free_text_is_allowed(): void
    {
        $result = $this->policy()->check(
            $this->opportunity(),
            $this->subject(['lastInboundAt' => now()->subHours(3)]),
        );

        $this->assertFalse($result['requires_template']);
    }

    /** Quien nunca escribió solo puede recibir plantilla. */
    public function test_someone_who_never_wrote_can_only_receive_a_template(): void
    {
        $result = $this->policy()->check($this->opportunity(), $this->subject(['lastInboundAt' => null]));

        $this->assertTrue($result['requires_template']);
    }

    /** Una oportunidad sin intentos disponibles no habilita nada. */
    public function test_an_exhausted_opportunity_is_refused(): void
    {
        $opportunity = $this->opportunity(['max_attempts' => 1]);
        $opportunity->recordAttempt();

        $result = $this->policy()->check($opportunity->fresh(), $this->subject());

        $this->assertFalse($result['allowed']);
        $this->assertSame('opportunity_not_actionable', $result['reason']);
    }
}
