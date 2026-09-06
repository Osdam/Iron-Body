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
        $this->sm = new PaymentStateMachine;
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

    // ── `expired` es nuestro, el desenlace es de Wompi ──────────────────────

    /**
     * `expired` no es un hecho de la pasarela: es nuestra conclusión de que
     * llevábamos demasiado tiempo sin noticias. Cuando Wompi cuenta qué pasó de
     * verdad, esa conclusión cede. Si no lo hiciera, un APPROVED tardío quedaría
     * enterrado y el socio habría pagado sin recibir su membresía.
     */
    public function test_expired_yields_to_a_gateway_confirmed_outcome(): void
    {
        foreach ([
            PaymentStateMachine::APPROVED,
            PaymentStateMachine::DECLINED,
            PaymentStateMachine::VOIDED,
            PaymentStateMachine::ERROR,
        ] as $outcome) {
            $this->assertSame(
                $outcome,
                $this->sm->resolveNext(PaymentStateMachine::EXPIRED, $outcome, true),
                "expired debe ceder ante {$outcome} confirmado por Wompi"
            );
        }
    }

    /** Sin confirmación de la pasarela, `expired` no se mueve solo. */
    public function test_expired_holds_without_gateway_confirmation(): void
    {
        $this->assertSame(
            PaymentStateMachine::EXPIRED,
            $this->sm->resolveNext(PaymentStateMachine::EXPIRED, PaymentStateMachine::APPROVED)
        );
    }

    /**
     * Y ni siquiera con confirmación vuelve a estar en vuelo: que Wompi diga
     * PENDING no reabre lo que ya dimos por vencido, solo un desenlace lo hace.
     */
    public function test_expired_never_returns_to_in_flight(): void
    {
        foreach ([PaymentStateMachine::PENDING, PaymentStateMachine::REQUIRES_ACTION] as $inFlight) {
            $this->assertSame(
                PaymentStateMachine::EXPIRED,
                $this->sm->resolveNext(PaymentStateMachine::EXPIRED, $inFlight, true),
                "expired no debe volver a {$inFlight}"
            );
        }
    }

    /** Los demás terminales siguen cerrados, también ante Wompi. */
    public function test_other_terminals_stay_closed_even_when_gateway_confirmed(): void
    {
        $this->assertSame(
            PaymentStateMachine::APPROVED,
            $this->sm->resolveNext(PaymentStateMachine::APPROVED, PaymentStateMachine::DECLINED, true)
        );
        $this->assertSame(
            PaymentStateMachine::DECLINED,
            $this->sm->resolveNext(PaymentStateMachine::DECLINED, PaymentStateMachine::APPROVED, true)
        );
        $this->assertSame(
            PaymentStateMachine::VOIDED,
            $this->sm->resolveNext(PaymentStateMachine::VOIDED, PaymentStateMachine::APPROVED, true)
        );
        $this->assertSame(
            PaymentStateMachine::ERROR,
            $this->sm->resolveNext(PaymentStateMachine::ERROR, PaymentStateMachine::APPROVED, true)
        );
    }
}
