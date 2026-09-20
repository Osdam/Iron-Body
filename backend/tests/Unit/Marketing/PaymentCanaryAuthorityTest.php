<?php

namespace Tests\Unit\Marketing;

use App\Services\Marketing\PaymentCanaryAuthority;
use PHPUnit\Framework\TestCase;

/**
 * El permiso de acuñar un cobro deja de ser «sí o no» y pasa a ser
 * «sí, y para quién».
 *
 * La alternativa era encender la bandera global para ver UN cobro real, y eso
 * no es un canario: es el despliegue entero con otro nombre.
 */
class PaymentCanaryAuthorityTest extends TestCase
{
    private PaymentCanaryAuthority $autoridad;

    protected function setUp(): void
    {
        parent::setUp();
        $this->autoridad = new PaymentCanaryAuthority;
    }

    /** A · la conversación del canario, con el global apagado. */
    public function test_the_canary_conversation_is_allowed(): void
    {
        $v = $this->autoridad->decide(false, 19, 19);

        $this->assertTrue($v['allowed']);
        $this->assertSame(PaymentCanaryAuthority::CANARY_CONVERSATION, $v['via']);
    }

    /** B · cualquier otra conversación, no. Es lo único que hace falta que sea cierto. */
    public function test_any_other_conversation_is_denied(): void
    {
        foreach ([1, 18, 20, 190, 999] as $otra) {
            $v = $this->autoridad->decide(false, 19, $otra);

            $this->assertFalse($v['allowed'], 'conversación '.$otra);
            $this->assertSame(PaymentCanaryAuthority::OUTSIDE_CANARY, $v['via']);
        }
    }

    /**
     * C · sin conversación, tampoco.
     *
     * Es el caso de la llamada directa —un comando, un endpoint interno— que
     * llega sin decir para quién. Antes heredaba el permiso global; ahora, con
     * un canario abierto, heredaría el del canario sin ser el canario.
     */
    public function test_without_a_conversation_it_is_denied(): void
    {
        foreach ([null, 0, -1] as $sin) {
            $v = $this->autoridad->decide(false, 19, $sin);

            $this->assertFalse($v['allowed'], var_export($sin, true));
            $this->assertSame(PaymentCanaryAuthority::NO_CONVERSATION, $v['via']);
        }
    }

    /** Sin canario y sin global, no hay puerta para nadie. */
    public function test_with_no_canary_and_no_global_nobody_passes(): void
    {
        foreach ([null, 0] as $sinCanario) {
            foreach ([19, null, 3] as $quien) {
                $v = $this->autoridad->decide(false, $sinCanario, $quien);

                $this->assertFalse($v['allowed']);
                $this->assertSame(PaymentCanaryAuthority::DISABLED, $v['via']);
            }
        }
    }

    /** Y el día que se abra el grifo, vale para todos: ése es su significado. */
    public function test_the_global_flag_opens_it_for_everyone(): void
    {
        foreach ([19, 20, null] as $quien) {
            $v = $this->autoridad->decide(true, 19, $quien);

            $this->assertTrue($v['allowed'], var_export($quien, true));
            $this->assertSame(PaymentCanaryAuthority::GLOBAL_ENABLED, $v['via']);
        }
    }
}
