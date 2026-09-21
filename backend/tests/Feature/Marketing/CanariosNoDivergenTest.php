<?php

namespace Tests\Feature\Marketing;

use Tests\TestCase;

/**
 * LOS DOS CANARIOS APUNTAN A LA MISMA CONVERSACIÓN, O ALGUIEN SE ENTERA.
 *
 * Hay dos variables: una decide a quién atiende ULTRON y otra a quién se le
 * puede cobrar. Nada en el código las contrastaba, y ya divergieron una vez —el
 * permiso de cobro se movió y el del cerebro se quedó atrás—, dejando el
 * canario inerte: la única conversación que podía cobrar era la única a la que
 * ULTRON no contestaba.
 *
 * Y la divergencia no se ve: el turno del pago muere con
 * `automatic_links_disabled`, que es el motivo legítimo de todo el tráfico
 * fuera del canario. El doctor es el sitio donde se nota, porque el smoke de
 * producción lo ejecuta en cada pasada.
 */
class CanariosNoDivergenTest extends TestCase
{
    public function test_el_doctor_falla_cuando_los_canarios_divergen(): void
    {
        config()->set('marketing.ultron.canary_conversation_id', 225);
        config()->set('marketing.ultron.payment_canary_conversation_id', 223);

        $this->artisan('marketing:ai-doctor')
            ->expectsOutputToContain('CANARIOS DIVERGENTES')
            ->assertExitCode(1);
    }

    public function test_el_doctor_pasa_cuando_apuntan_a_la_misma(): void
    {
        config()->set('marketing.ultron.canary_conversation_id', 225);
        config()->set('marketing.ultron.payment_canary_conversation_id', 225);

        $this->artisan('marketing:ai-doctor')->assertExitCode(0);
    }

    /**
     * Sin canario de cobro no hay nada que contrastar.
     *
     * Es el estado normal fuera de una prueba —el cobro apagado y ULTRON
     * acotado o no—, y no puede dar rojo: un doctor que grita cuando todo está
     * bien deja de leerse.
     */
    public function test_sin_canario_de_cobro_no_hay_divergencia(): void
    {
        config()->set('marketing.ultron.canary_conversation_id', 225);
        config()->set('marketing.ultron.payment_canary_conversation_id', 0);

        $this->artisan('marketing:ai-doctor')->assertExitCode(0);
    }

    /** Y con ULTRON sin acotar tampoco: el cobro puede estar acotado solo. */
    public function test_con_ultron_sin_acotar_no_hay_divergencia(): void
    {
        config()->set('marketing.ultron.canary_conversation_id', null);
        config()->set('marketing.ultron.payment_canary_conversation_id', 225);

        $this->artisan('marketing:ai-doctor')->assertExitCode(0);
    }
}
