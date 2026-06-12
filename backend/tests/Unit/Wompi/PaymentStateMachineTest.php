<?php

namespace Tests\Unit\Wompi;

use App\Services\Wompi\PaymentStateMachine;
use PHPUnit\Framework\TestCase;

class PaymentStateMachineTest extends TestCase
{
    private PaymentStateMachine $sm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sm = new PaymentStateMachine();
    }

    public function test_only_approved_activates_membership(): void
    {
        $this->assertTrue($this->sm->activatesMembership(PaymentStateMachine::APPROVED));

        foreach ([
            PaymentStateMachine::PENDING,
            PaymentStateMachine::REQUIRES_ACTION,
            PaymentStateMachine::DECLINED,
            PaymentStateMachine::VOIDED,
            PaymentStateMachine::ERROR,
            PaymentStateMachine::EXPIRED,
            PaymentStateMachine::CREATED,
        ] as $state) {
            $this->assertFalse(
                $this->sm->activatesMembership($state),
                "El estado {$state} NO debe activar membresía"
            );
        }
    }

    public function test_wompi_status_mapping(): void
    {
        $this->assertSame(PaymentStateMachine::APPROVED, $this->sm->mapWompiStatus('APPROVED'));
        $this->assertSame(PaymentStateMachine::DECLINED, $this->sm->mapWompiStatus('DECLINED'));
        $this->assertSame(PaymentStateMachine::VOIDED, $this->sm->mapWompiStatus('VOIDED'));
        $this->assertSame(PaymentStateMachine::ERROR, $this->sm->mapWompiStatus('ERROR'));
        $this->assertSame(PaymentStateMachine::PENDING, $this->sm->mapWompiStatus('PENDING'));
        // Desconocido → pending (jamás aprueba por defecto).
        $this->assertSame(PaymentStateMachine::PENDING, $this->sm->mapWompiStatus('SOMETHING_NEW'));
    }

    public function test_approved_is_absorbing(): void
    {
        // approved no se sale jamás, ni siquiera ante otro estado de Wompi.
        $this->assertSame(
            PaymentStateMachine::APPROVED,
            $this->sm->resolveNext(PaymentStateMachine::APPROVED, PaymentStateMachine::PENDING)
        );
        $this->assertSame(
            PaymentStateMachine::APPROVED,
            $this->sm->resolveNext(PaymentStateMachine::APPROVED, PaymentStateMachine::DECLINED)
        );
    }

    public function test_terminal_does_not_downgrade_to_in_flight(): void
    {
        $this->assertSame(
            PaymentStateMachine::DECLINED,
            $this->sm->resolveNext(PaymentStateMachine::DECLINED, PaymentStateMachine::PENDING)
        );
        $this->assertSame(
            PaymentStateMachine::EXPIRED,
            $this->sm->resolveNext(PaymentStateMachine::EXPIRED, PaymentStateMachine::REQUIRES_ACTION)
        );
    }

    public function test_pending_can_advance_to_approved_or_requires_action(): void
    {
        $this->assertSame(
            PaymentStateMachine::APPROVED,
            $this->sm->resolveNext(PaymentStateMachine::PENDING, PaymentStateMachine::APPROVED)
        );
        $this->assertSame(
            PaymentStateMachine::REQUIRES_ACTION,
            $this->sm->resolveNext(PaymentStateMachine::PENDING, PaymentStateMachine::REQUIRES_ACTION)
        );
    }

    public function test_timestamp_columns(): void
    {
        $this->assertSame('approved_at', $this->sm->timestampColumnFor(PaymentStateMachine::APPROVED));
        $this->assertSame('declined_at', $this->sm->timestampColumnFor(PaymentStateMachine::DECLINED));
        $this->assertSame('voided_at', $this->sm->timestampColumnFor(PaymentStateMachine::VOIDED));
        $this->assertSame('failed_at', $this->sm->timestampColumnFor(PaymentStateMachine::ERROR));
        $this->assertSame('expires_at', $this->sm->timestampColumnFor(PaymentStateMachine::EXPIRED));
        $this->assertNull($this->sm->timestampColumnFor(PaymentStateMachine::PENDING));
    }

    public function test_terminal_and_in_flight_classification(): void
    {
        $this->assertTrue($this->sm->isTerminal(PaymentStateMachine::APPROVED));
        $this->assertTrue($this->sm->isInFlight(PaymentStateMachine::PENDING));
        $this->assertFalse($this->sm->isTerminal(PaymentStateMachine::REQUIRES_ACTION));
        $this->assertFalse($this->sm->isInFlight(PaymentStateMachine::VOIDED));
    }
}
