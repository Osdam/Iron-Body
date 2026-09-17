<?php

namespace Tests\Unit\Marketing;

use App\Services\Marketing\HumanHandoffAuthority;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Cuándo una conversación puede pasar a una persona.
 *
 * El caso que obliga a que esto exista es el turno real en que el prospecto
 * escribió «no quiero alguien del equipo» y el asesor le contestó ofreciéndole
 * pasarle con alguien del equipo. La negación tiene que ganar.
 */
class HumanHandoffAuthorityTest extends TestCase
{
    private HumanHandoffAuthority $autoridad;

    protected function setUp(): void
    {
        parent::setUp();
        $this->autoridad = new HumanHandoffAuthority;
    }

    private function decide(?string $motivo, ?string $mensaje): array
    {
        return $this->autoridad->decide($motivo, $mensaje);
    }

    // ── Lo pidió de verdad ───────────────────────────────────────────────────

    public static function peticionesReales(): array
    {
        return [
            ['quiero hablar con una persona'],
            ['quiero hablar con un asesor por favor'],
            ['me pueden pasar con alguien'],
            ['pasame con un asesor'],
            ['páseme con recepción'],
            ['comunicame con alguien del gimnasio'],
            ['prefiero que me atienda una persona'],
            ['necesito un asesor'],
            ['quiero hablar con un humano'],
            ['hay alguna forma de hablar con alguien'],
        ];
    }

    #[DataProvider('peticionesReales')]
    public function test_a_real_request_for_a_human_is_honoured(string $mensaje): void
    {
        $d = $this->decide(HumanHandoffAuthority::EXPLICIT_HUMAN_REQUEST, $mensaje);

        $this->assertTrue($d['allowed'], "Debería permitirse: «{$mensaje}»");
        $this->assertNotNull($d['evidence'], 'Un escalado sin prueba citable no es auditable.');
    }

    // ── La negación gana ─────────────────────────────────────────────────────

    public static function negaciones(): array
    {
        return [
            ['no quiero alguien del equipo'],              // el caso real
            ['no quiero alguien del equipo, contesta'],
            ['no quiero hablar con un asesor'],
            ['no necesito una persona'],
            ['no me pases con nadie'],
            ['no quiero hablar con una persona, quiero que me expliques tú'],
            ['prefiero no hablar con un asesor'],
            ['sin hablar con un asesor, dime tú'],
            ['nunca quiero hablar con un humano'],
        ];
    }

    #[DataProvider('negaciones')]
    public function test_a_denial_is_never_read_as_a_request(string $mensaje): void
    {
        $d = $this->decide(HumanHandoffAuthority::EXPLICIT_HUMAN_REQUEST, $mensaje);

        $this->assertFalse($d['allowed'], "No debería derivar: «{$mensaje}»");
        $this->assertSame('handoff_not_corroborated', $d['refusal']);
    }

    // ── Lo que NUNCA es pedir un humano ──────────────────────────────────────

    public static function conversacionNormal(): array
    {
        return [
            ['hola quiero informacion de los planes'],
            ['uy que buneo cuentame mas'],
            ['si por favor'],
            ['tienes inforamacion de cuantos entrenadores tiene el gimnsaio?'],
            ['contesta'],
            ['como pago?'],
            ['quiero inscribirme ya'],
            ['80 mil esta caro'],
            ['me interesa el mensual'],
            ['no se, ayudame tu'],
            ['explicame como empiezo'],
            ['que me recomiendas'],
        ];
    }

    /**
     * Estas son las frases con las que el asesor derivaba. Ninguna pide un
     * humano, y por eso ninguna puede autorizar la derivación por mucho que el
     * modelo etiquete el turno como `explicit_human_request`.
     */
    #[DataProvider('conversacionNormal')]
    public function test_ordinary_conversation_never_authorises_a_handoff(string $mensaje): void
    {
        $d = $this->decide(HumanHandoffAuthority::EXPLICIT_HUMAN_REQUEST, $mensaje);

        $this->assertFalse($d['allowed'], "El modelo no puede escalar con: «{$mensaje}»");
    }

    // ── La lista de motivos ──────────────────────────────────────────────────

    public function test_a_reason_outside_the_list_is_refused(): void
    {
        foreach (['other', 'unknown', 'complex', 'payment', 'closing', 'user_interested', ''] as $inventado) {
            $d = $this->decide($inventado, 'quiero hablar con una persona');
            $this->assertFalse($d['allowed'], "Motivo inventado aceptado: «{$inventado}»");
        }
    }

    public function test_no_reason_at_all_is_refused(): void
    {
        $d = $this->decide(null, 'quiero hablar con una persona');

        $this->assertFalse($d['allowed']);
        $this->assertSame('handoff_reason_missing', $d['refusal']);
    }

    /**
     * Los motivos que describen un límite del sistema no se corroboran con el
     * mensaje: quien sabe que un pago no se puede resolver es el backend, no
     * la persona que escribe.
     */
    public function test_system_limit_reasons_need_no_corroboration(): void
    {
        foreach ([
            HumanHandoffAuthority::UNRESOLVABLE_PAYMENT_INCIDENT,
            HumanHandoffAuthority::UNRESOLVABLE_ACCOUNT_OPERATION,
            HumanHandoffAuthority::FORMAL_COMPLAINT_REQUIRING_HUMAN,
            HumanHandoffAuthority::POLICY_REQUIRED_ESCALATION,
            HumanHandoffAuthority::CRITICAL_CAPABILITY_NOT_AVAILABLE,
        ] as $motivo) {
            $d = $this->decide($motivo, 'ya pagué y no aparece');
            $this->assertTrue($d['allowed'], "Debería permitirse por {$motivo}");
            $this->assertSame($motivo, $d['reason']);
        }
    }

    /** Pero «lo pidió» sin mensaje que lo respalde, no. */
    public function test_an_explicit_request_without_a_message_is_refused(): void
    {
        $this->assertFalse($this->decide(HumanHandoffAuthority::EXPLICIT_HUMAN_REQUEST, null)['allowed']);
        $this->assertFalse($this->decide(HumanHandoffAuthority::EXPLICIT_HUMAN_REQUEST, '')['allowed']);
    }

    /** Una negación lejana no debe anular una petición posterior y clara. */
    public function test_an_unrelated_earlier_negation_does_not_cancel_the_request(): void
    {
        $d = $this->decide(
            HumanHandoffAuthority::EXPLICIT_HUMAN_REQUEST,
            'no me interesa el plan mensual la verdad, mejor quiero hablar con un asesor',
        );

        $this->assertTrue($d['allowed'], 'La negación era sobre el plan, no sobre hablar con alguien.');
    }
}
