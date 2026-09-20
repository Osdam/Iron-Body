<?php

namespace Tests\Unit\Marketing;

use App\Services\Marketing\StaffReviewAuthority;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Cuándo una conversación queda marcada para el equipo.
 *
 * El caso que obliga a que esto exista es un turno real del canario: una
 * pregunta normal, contestada bien, sin queja ni lesión ni nadie pidiendo una
 * persona, que acabó con la bandera levantada porque el modelo escribió
 * `staff_review` en `tools_requested`. Nadie miraba si había causa.
 *
 * La regla es la misma que la de la derivación: una intención no autoriza
 * nada. O la causa la afirma el backend, o es la única que el modelo puede
 * alegar por su cuenta.
 */
class StaffReviewAuthorityTest extends TestCase
{
    private StaffReviewAuthority $autoridad;

    protected function setUp(): void
    {
        parent::setUp();
        $this->autoridad = new StaffReviewAuthority;
    }

    // ── Lo que afirma el backend manda ────────────────────────────────────────

    public function test_a_backend_cause_marks_the_conversation_with_its_own_reason(): void
    {
        $v = $this->autoridad->decide(true, 'complaint', null);

        $this->assertTrue($v['allowed']);
        $this->assertSame('complaint', $v['reason']);
        $this->assertSame('backend', $v['source']);
        $this->assertNull($v['refusal']);
    }

    /**
     * Una escalada real no se pierde por una discrepancia de vocabulario.
     *
     * Si el backend dice que hace falta revisión pero no nombra el motivo, la
     * bandera se levanta igual con la etiqueta genérica. Equivocarse de nombre
     * es barato; perder una queja no.
     *
     * OJO: hoy este camino es INALCANZABLE desde el validador —`force_escalate`
     * sólo se enciende por acción prohibida, afirmación insegura o intención
     * sensible, y las tres escriben su `escalation_reason`—. Es una red por si
     * mañana aparece una cuarta causa sin nombre, no un camino vivo. Que sea
     * laxo es deliberado: la alternativa era fallar ruidosamente y perder la
     * escalada, que es justo el lado caro del error.
     */
    public function test_a_backend_cause_without_a_reason_still_marks_it(): void
    {
        foreach ([null, '', '   '] as $vacio) {
            $v = $this->autoridad->decide(true, $vacio, null);

            $this->assertTrue($v['allowed']);
            $this->assertSame(StaffReviewAuthority::MOTIVO_GENERICO, $v['reason']);
        }
    }

    /** El motivo del backend gana al que proponga el modelo: es un hecho, no una etiqueta. */
    public function test_the_backend_reason_wins_over_the_proposed_one(): void
    {
        $v = $this->autoridad->decide(true, 'medical_case', StaffReviewAuthority::TEAM_ONLY_OPERATION);

        $this->assertSame('medical_case', $v['reason']);
        $this->assertSame('backend', $v['source']);
    }

    // ── Lo que propone el modelo se comprueba ─────────────────────────────────

    public function test_the_only_reason_the_model_may_propose_is_granted(): void
    {
        $v = $this->autoridad->decide(false, null, StaffReviewAuthority::TEAM_ONLY_OPERATION);

        $this->assertTrue($v['allowed']);
        $this->assertSame(StaffReviewAuthority::TEAM_ONLY_OPERATION, $v['reason']);
        $this->assertSame('model', $v['source']);
    }

    public function test_the_proposed_reason_is_read_without_case_or_spaces(): void
    {
        foreach (['TEAM_ONLY_OPERATION', ' team_only_operation ', 'Team_Only_Operation'] as $variante) {
            $this->assertTrue($this->autoridad->decide(false, null, $variante)['allowed'], $variante);
        }
    }

    /** Un turno limpio: ni causa del backend, ni motivo. No se marca. */
    public function test_a_clean_turn_is_not_marked(): void
    {
        $v = $this->autoridad->decide(false, null, null);

        $this->assertFalse($v['allowed']);
        $this->assertNull($v['reason']);
        $this->assertSame('staff_review_reason_missing', $v['refusal']);
    }

    /**
     * Los motivos que describen a la PERSONA no los alega el modelo.
     *
     * Todos éstos son reales y todos marcan la conversación —cuando los afirma
     * el backend leyendo el mensaje—. Aceptarlos de boca del modelo sería el
     * cheque en blanco de antes con otro nombre: bastaría escribir la cadena.
     */
    #[DataProvider('motivosQueNoProponeElModelo')]
    public function test_a_reason_the_model_may_not_propose_is_refused(string $motivo): void
    {
        $v = $this->autoridad->decide(false, null, $motivo);

        $this->assertFalse($v['allowed'], $motivo);
        $this->assertSame('staff_review_reason_not_proposable_by_model', $v['refusal']);
    }

    public static function motivosQueNoProponeElModelo(): array
    {
        return array_map(fn ($m) => [$m], [
            'complaint',
            'medical_case',
            'human_requested',
            'payment_or_fraud_claim',
            'invoice_request',
            'forbidden_action_attempt',
            'unsafe_claim',
            'escalation',
            'staff_review',
            'policy_required_escalation',
            'no_lo_se',
            'falta_un_dato',
        ]);
    }

    /** La lista de lo proponible es de uno, y que siga siéndolo es parte del contrato. */
    public function test_the_model_proposable_list_has_exactly_one_reason(): void
    {
        $this->assertSame([StaffReviewAuthority::TEAM_ONLY_OPERATION], StaffReviewAuthority::MODEL_PROPOSABLE);
    }
}
