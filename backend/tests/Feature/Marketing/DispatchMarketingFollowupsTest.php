<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingCall;
use App\Models\MarketingFollowup;
use App\Models\MarketingLead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Despacho de seguimientos vencidos. El seguimiento es contacto PROACTIVO: lo
 * iniciamos nosotros, así que exige opt-in expreso (Ley 1581).
 *
 * La distinción que fija este test es entre los dos motivos por los que un
 * seguimiento no sale, porque tienen consecuencias opuestas:
 *
 *   - negativa expresa (do_not_contact / denied) → se CANCELA, no volverá
 *   - falta de opt-in (null / pending / unknown)  → queda PENDIENTE, el
 *     consentimiento puede llegar después
 *
 * Sin esa distinción, la primera corrida cancelaría todos los seguimientos
 * pendientes de la base por el mero hecho de que nadie tiene opt-in todavía.
 */
class DispatchMarketingFollowupsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('meta.enabled', false);
        Http::fake();
    }

    private function leadWith(?string $consent, bool $dnc = false): MarketingLead
    {
        return MarketingLead::create([
            'channel' => 'whatsapp',
            'source' => 'inbound',
            'phone' => '3001234567',
            'name' => 'Lead '.uniqid(),
            'status' => MarketingLead::STATUS_NEW,
            'consent_status' => $consent,
            'do_not_contact' => $dnc,
        ]);
    }

    private function dueFollowup(MarketingLead $lead, string $type = 'call'): MarketingFollowup
    {
        return MarketingFollowup::create([
            'lead_id' => $lead->id,
            'due_at' => now()->subHour(),
            'type' => $type,
            'status' => MarketingFollowup::STATUS_PENDING,
        ]);
    }

    private function dispatch(): void
    {
        $this->artisan('marketing:dispatch-followups', ['--force' => true])->assertSuccessful();
    }

    public function test_granted_lead_is_dispatched(): void
    {
        $followup = $this->dueFollowup($this->leadWith(MarketingLead::CONSENT_GRANTED));

        $this->dispatch();

        $this->assertSame(MarketingFollowup::STATUS_DONE, $followup->fresh()->status);
        $this->assertDatabaseHas('marketing_calls', ['marketing_followup_id' => $followup->id]);
    }

    public function test_lead_without_optin_keeps_followup_pending(): void
    {
        $followup = $this->dueFollowup($this->leadWith(null));

        $this->dispatch();

        $this->assertSame(
            MarketingFollowup::STATUS_PENDING,
            $followup->fresh()->status,
            'Sin opt-in el seguimiento debe conservarse, no cancelarse.',
        );
        $this->assertDatabaseCount('marketing_calls', 0);
    }

    public function test_pending_and_unknown_consent_also_keep_followup(): void
    {
        $a = $this->dueFollowup($this->leadWith(MarketingLead::CONSENT_PENDING));
        $b = $this->dueFollowup($this->leadWith(MarketingLead::CONSENT_UNKNOWN));

        $this->dispatch();

        $this->assertSame(MarketingFollowup::STATUS_PENDING, $a->fresh()->status);
        $this->assertSame(MarketingFollowup::STATUS_PENDING, $b->fresh()->status);
    }

    public function test_denied_consent_cancels_followup(): void
    {
        $followup = $this->dueFollowup($this->leadWith(MarketingLead::CONSENT_DENIED));

        $this->dispatch();

        $this->assertSame(MarketingFollowup::STATUS_CANCELLED, $followup->fresh()->status);
        $this->assertDatabaseCount('marketing_calls', 0);
    }

    public function test_do_not_contact_cancels_followup(): void
    {
        $followup = $this->dueFollowup($this->leadWith(MarketingLead::CONSENT_GRANTED, true));

        $this->dispatch();

        $this->assertSame(MarketingFollowup::STATUS_CANCELLED, $followup->fresh()->status);
    }

    public function test_unrecognised_consent_value_keeps_followup_but_never_calls(): void
    {
        // 'test' existe en producción (lead#1, consent_source='manual_vps'). No es
        // negativa expresa sino dato corrupto: no autoriza a llamar, pero tampoco
        // justifica destruir el seguimiento.
        $followup = $this->dueFollowup($this->leadWith('test'));

        $this->dispatch();

        $this->assertSame(MarketingFollowup::STATUS_PENDING, $followup->fresh()->status);
        $this->assertDatabaseCount('marketing_calls', 0);
    }

    public function test_dispatch_is_inert_when_flags_disabled(): void
    {
        config()->set('marketing.agent_enabled', false);
        config()->set('marketing.followups.dispatch_enabled', false);
        $followup = $this->dueFollowup($this->leadWith(MarketingLead::CONSENT_GRANTED));

        $this->artisan('marketing:dispatch-followups')->assertSuccessful();

        $this->assertSame(MarketingFollowup::STATUS_PENDING, $followup->fresh()->status);
        $this->assertDatabaseCount('marketing_calls', 0);
    }

    public function test_future_followup_is_not_dispatched(): void
    {
        $lead = $this->leadWith(MarketingLead::CONSENT_GRANTED);
        $followup = MarketingFollowup::create([
            'lead_id' => $lead->id,
            'due_at' => now()->addDay(),
            'type' => 'call',
            'status' => MarketingFollowup::STATUS_PENDING,
        ]);

        $this->dispatch();

        $this->assertSame(MarketingFollowup::STATUS_PENDING, $followup->fresh()->status);
    }

    public function test_dispatch_is_idempotent_for_calls(): void
    {
        $followup = $this->dueFollowup($this->leadWith(MarketingLead::CONSENT_GRANTED));

        $this->dispatch();
        $this->dispatch();

        $this->assertDatabaseCount('marketing_calls', 1);
        $this->assertSame(MarketingCall::STATUS_PENDING, MarketingCall::first()->status);
    }
}
