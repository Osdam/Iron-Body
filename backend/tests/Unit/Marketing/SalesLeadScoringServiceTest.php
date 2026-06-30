<?php

namespace Tests\Unit\Marketing;

use App\Services\Marketing\SalesIntents;
use App\Services\Marketing\SalesLeadScoringService;
use PHPUnit\Framework\TestCase;

/**
 * Scoring comercial PURO (sin BD): score 0-100, etapa del lead y temperatura
 * simplificada. Vectores fijos y deterministas.
 */
class SalesLeadScoringServiceTest extends TestCase
{
    private SalesLeadScoringService $scoring;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scoring = new SalesLeadScoringService();
    }

    public function test_score_is_bounded_0_100(): void
    {
        foreach (\App\Services\Marketing\SalesAgentDecisionSchema::INTENTS as $intent) {
            $score = $this->scoring->score($intent);
            $this->assertGreaterThanOrEqual(0, $score);
            $this->assertLessThanOrEqual(100, $score);
        }
    }

    public function test_payment_intent_scores_higher_than_general_info(): void
    {
        $this->assertGreaterThan(
            $this->scoring->score(SalesIntents::GENERAL_INFO),
            $this->scoring->score(SalesIntents::PAYMENT_LINK_REQUEST),
        );
    }

    public function test_declared_objective_adds_bonus(): void
    {
        $base  = $this->scoring->score(SalesIntents::PRICING_QUESTION);
        $bonus = $this->scoring->score(SalesIntents::PRICING_QUESTION, [
            'extracted_fields' => ['objective' => 'fat_loss'],
        ]);
        $this->assertGreaterThan($base, $bonus);
    }

    public function test_lead_stage_taxonomy(): void
    {
        $this->assertSame(SalesIntents::LEAD_STAGE_READY_TO_PAY, $this->scoring->leadStage(SalesIntents::PAYMENT_LINK_REQUEST, false));
        $this->assertSame(SalesIntents::LEAD_STAGE_INTERESTED, $this->scoring->leadStage(SalesIntents::PRICE_OBJECTION, false));
        $this->assertSame(SalesIntents::LEAD_STAGE_INFORMED, $this->scoring->leadStage(SalesIntents::GOAL_FAT_LOSS, false));
        $this->assertSame(SalesIntents::LEAD_STAGE_NEW, $this->scoring->leadStage(SalesIntents::UNKNOWN, false));
        $this->assertSame(SalesIntents::LEAD_STAGE_LOST, $this->scoring->leadStage(SalesIntents::SPAM_LOW_QUALITY, false));
        // Pago: sigue ready_to_pay aunque se escale (un asesor comparte el medio de pago).
        $this->assertSame(SalesIntents::LEAD_STAGE_READY_TO_PAY, $this->scoring->leadStage(SalesIntents::PAYMENT_LINK_REQUEST, true));
        // Escalado NO de pago (médico/humano) → needs_human.
        $this->assertSame(SalesIntents::LEAD_STAGE_NEEDS_HUMAN, $this->scoring->leadStage(SalesIntents::MEDICAL_RISK_ESCALATION, true));
    }

    public function test_crm_temperature_is_three_levels(): void
    {
        $this->assertSame('hot', $this->scoring->crmTemperature(SalesIntents::PAYMENT_LINK_REQUEST));
        $this->assertSame('warm', $this->scoring->crmTemperature(SalesIntents::PRICING_QUESTION));
        $this->assertSame('cold', $this->scoring->crmTemperature(SalesIntents::GENERAL_INFO));
    }
}
