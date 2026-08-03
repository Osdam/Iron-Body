<?php

namespace Tests\Unit\Marketing;

use App\Models\MarketingLead;
use PHPUnit\Framework\TestCase;

/**
 * Separación de responsabilidades del consentimiento:
 *
 *   isContactable()          → freno duro (do_not_contact). Semántica original.
 *   canReplyReactively()     → responder a quien nos escribió.
 *   canContactProactively()  → escribir nosotros primero (exige opt-in expreso).
 *
 * El caso que motivó la separación: un lead con consent_status='denied' y
 * do_not_contact=false era contactable, y eso es una infracción de Habeas Data.
 */
class MarketingLeadConsentTest extends TestCase
{
    private function lead(?string $consent, bool $dnc = false): MarketingLead
    {
        $lead = new MarketingLead;
        $lead->consent_status = $consent;
        $lead->do_not_contact = $dnc;

        return $lead;
    }

    // ---- isContactable: sin cambios respecto al comportamiento original ----

    public function test_is_contactable_only_looks_at_do_not_contact(): void
    {
        $this->assertTrue($this->lead(null)->isContactable());
        $this->assertTrue($this->lead(MarketingLead::CONSENT_DENIED)->isContactable());
        $this->assertFalse($this->lead(MarketingLead::CONSENT_GRANTED, true)->isContactable());
    }

    // ---- canReplyReactively ----

    public function test_reactive_reply_allowed_when_consent_is_absent(): void
    {
        // 18 de los 20 leads de producción están así: escribieron ellos y nunca
        // se les preguntó nada. No responderles sería el peor de los fallos.
        $this->assertTrue($this->lead(null)->canReplyReactively());
        $this->assertTrue($this->lead('')->canReplyReactively());
    }

    public function test_reactive_reply_allowed_for_granted_pending_and_unknown(): void
    {
        $this->assertTrue($this->lead(MarketingLead::CONSENT_GRANTED)->canReplyReactively());
        $this->assertTrue($this->lead(MarketingLead::CONSENT_PENDING)->canReplyReactively());
        $this->assertTrue($this->lead(MarketingLead::CONSENT_UNKNOWN)->canReplyReactively());
    }

    public function test_reactive_reply_blocked_when_consent_denied(): void
    {
        $this->assertFalse($this->lead(MarketingLead::CONSENT_DENIED)->canReplyReactively());
    }

    public function test_reactive_reply_blocked_for_unrecognised_value(): void
    {
        // Producción tiene un lead con consent_status='test'. La columna no tiene
        // CHECK, así que cualquier cadena entra: fail-closed.
        $this->assertFalse($this->lead('test')->canReplyReactively());
        $this->assertFalse($this->lead('GRANTED')->canReplyReactively());
        $this->assertFalse($this->lead('si')->canReplyReactively());
    }

    public function test_do_not_contact_beats_any_consent(): void
    {
        $this->assertFalse($this->lead(MarketingLead::CONSENT_GRANTED, true)->canReplyReactively());
    }

    // ---- canContactProactively ----

    public function test_proactive_contact_requires_explicit_grant(): void
    {
        $this->assertTrue($this->lead(MarketingLead::CONSENT_GRANTED)->canContactProactively());
    }

    public function test_proactive_contact_blocked_without_explicit_grant(): void
    {
        $this->assertFalse($this->lead(null)->canContactProactively());
        $this->assertFalse($this->lead(MarketingLead::CONSENT_PENDING)->canContactProactively());
        $this->assertFalse($this->lead(MarketingLead::CONSENT_UNKNOWN)->canContactProactively());
        $this->assertFalse($this->lead(MarketingLead::CONSENT_DENIED)->canContactProactively());
        $this->assertFalse($this->lead('test')->canContactProactively());
        $this->assertFalse($this->lead(MarketingLead::CONSENT_GRANTED, true)->canContactProactively());
    }

    public function test_proactive_is_never_broader_than_reactive(): void
    {
        foreach ([null, '', 'test', 'granted', 'denied', 'pending', 'unknown'] as $consent) {
            foreach ([true, false] as $dnc) {
                $lead = $this->lead($consent === '' ? '' : $consent, $dnc);
                if ($lead->canContactProactively()) {
                    $this->assertTrue(
                        $lead->canReplyReactively(),
                        sprintf('consent=%s dnc=%s: proactivo sin reactivo', var_export($consent, true), var_export($dnc, true)),
                    );
                }
            }
        }
    }

    public function test_consent_statuses_constant_matches_declared_values(): void
    {
        $this->assertSame(
            ['granted', 'denied', 'pending', 'unknown'],
            MarketingLead::CONSENT_STATUSES,
        );
    }
}
