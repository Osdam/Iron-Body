<?php

namespace Tests\Unit\Marketing;

use App\Services\Marketing\CommercialPhaseMachine as Fase;
use App\Services\Marketing\Ultron\ConversationMemory as CM;
use App\Services\Marketing\Ultron\UltronSalesPlaybook as PB;
use PHPUnit\Framework\TestCase;

/**
 * LA DOCTRINA COMERCIAL, MEDIDA COMO UN CONTRATO Y NO COMO UN TEXTO BONITO.
 *
 * El playbook es la única capa de este sistema que existe para que el agente
 * venda MEJOR, no para que no haga daño. Eso la vuelve peligrosa de otra
 * manera: una capa de estilo que pudiera colar una acción, un plan o una cifra
 * sería una puerta trasera a todo lo que los cerrojos defienden.
 *
 * Así que aquí se mide lo que no debe pasar nunca:
 *
 *   · que la doctrina nombre a una persona real a la que imitar;
 *   · que traiga un id de plan, un precio o una herramienta;
 *   · que el cierre se active donde la persona dijo que no, o dudó, o ya es
 *     miembro —la fase la calcula la intención del último mensaje, así que
 *     «me interesa pero está caro» puede aterrizar en BUYING_SIGNAL—;
 *   · que el piso ético dependa de la fase;
 *   · y que se venda a quien pidió un humano o pidió no ser contactado.
 */
class PlaybookDeVentaTest extends TestCase
{
    /** Las 16 combinaciones de señales. Una capa así se rompe en las esquinas. */
    private const TODAS_LAS_SEÑALES = [
        [],
        ['rejected_a_plan' => true], ['is_member' => true], ['has_barrier' => true], ['returning' => true],
        ['rejected_a_plan' => true, 'is_member' => true],
        ['rejected_a_plan' => true, 'has_barrier' => true],
        ['rejected_a_plan' => true, 'returning' => true],
        ['is_member' => true, 'has_barrier' => true],
        ['is_member' => true, 'returning' => true],
        ['has_barrier' => true, 'returning' => true],
        ['rejected_a_plan' => true, 'is_member' => true, 'has_barrier' => true],
        ['rejected_a_plan' => true, 'is_member' => true, 'returning' => true],
        ['rejected_a_plan' => true, 'has_barrier' => true, 'returning' => true],
        ['is_member' => true, 'has_barrier' => true, 'returning' => true],
        ['rejected_a_plan' => true, 'is_member' => true, 'has_barrier' => true, 'returning' => true],
    ];

    /** Todo el texto que el playbook puede llegar a mandar al modelo. */
    private function todoElTexto(): string
    {
        $t = '';
        foreach (Fase::PHASES as $fase) {
            foreach (self::TODAS_LAS_SEÑALES as $señales) {
                $p = PB::forPhase($fase, $señales);
                $t .= implode(' ', $p['techniques']).' '.implode(' ', $p['directives']).' '.implode(' ', $p['never']).' ';
            }
        }

        return $t;
    }

    public function test_no_manda_al_modelo_el_nombre_de_nadie_a_quien_imitar(): void
    {
        $texto = mb_strtolower($this->todoElTexto());

        /*
         * Los principios vienen de la literatura de ventas; las personas se
         * quedan en los comentarios del código. Si un apellido llegara al
         * prompt, el modelo intentaría SONAR como esa persona en vez de aplicar
         * la idea, y un vendedor imitado en WhatsApp suena a personaje.
         */
        foreach (['rackham', 'carnegie', 'girard', 'ziglar', 'cialdini', 'mary kay', 'cardone', 'spin selling'] as $nombre) {
            $this->assertStringNotContainsString($nombre, $texto, "El playbook nombra a {$nombre} en el texto que ve el modelo.");
        }
    }

    public function test_no_decide_nada_que_no_sea_lenguaje(): void
    {
        $texto = mb_strtolower($this->todoElTexto());

        // Ni herramientas: las autoriza el endpoint, turno a turno.
        foreach (['payment_link_send', 'app_links_send', 'staff_review', 'mark_dnc', 'courtesy_request'] as $tool) {
            $this->assertStringNotContainsString($tool, $texto, "El playbook nombra la herramienta {$tool}.");
        }

        // Ni cifras: una doctrina con un número dentro es un precio esperando
        // a que alguien lo copie.
        $this->assertSame(0, preg_match('/\d{3,}/', $texto), 'El playbook contiene una cifra larga.');

        // Ni planes: el plan lo decide CommercialTurnPolicy.
        foreach (['total access', 'plan mensual', 'plan semana', 'elite'] as $plan) {
            $this->assertStringNotContainsString($plan, $texto, "El playbook nombra el plan {$plan}.");
        }

        // Y la forma que devuelve no puede pisar la de la política del turno.
        $claves = array_keys(PB::forPhase(Fase::RECOMMENDATION));
        foreach (['required_plan_id', 'allowed_plan_ids', 'forbidden_actions', 'allowed_next_actions', 'greet_required'] as $deLaPolitica) {
            $this->assertNotContains($deLaPolitica, $claves, "El playbook devuelve {$deLaPolitica}, que es de CommercialTurnPolicy.");
        }
    }

    public function test_el_piso_etico_viaja_en_las_dieciocho_fases(): void
    {
        foreach (Fase::PHASES as $fase) {
            $never = PB::forPhase($fase)['never'];
            $this->assertNotEmpty($never, "La fase {$fase} sale sin piso ético.");
            $texto = mb_strtolower(implode(' ', $never));
            $this->assertStringContainsString('no presiones después de un no', $texto, "La fase {$fase} no prohíbe presionar.");
            $this->assertStringContainsString('ni te declares el mejor', $texto, "La fase {$fase} no prohíbe el superlativo.");
        }
    }

    public function test_donde_no_se_vende_no_hay_tecnica_de_venta(): void
    {
        /*
         * CON TODAS LAS COMBINACIONES DE SEÑALES, y no solo sin ninguna.
         *
         * La primera versión solo miraba la fase desnuda y por eso no vio que
         * las ramas de señales se aplican DESPUÉS del match: `DO_NOT_CONTACT`
         * con `returning` —que es el estado normal, porque el resumen se
         * reescribe cada turno— devolvía «pregunta cómo va», y `HUMAN_HANDOFF`
         * con una barrera devolvía seis directrices de venta.
         */
        foreach ([Fase::HUMAN_HANDOFF, Fase::DO_NOT_CONTACT] as $fase) {
            foreach (self::TODAS_LAS_SEÑALES as $señales) {
                $p = PB::forPhase($fase, $señales);
                $q = json_encode($señales);
                $this->assertSame([], $p['techniques'], "Se vende en {$fase} con {$q}.");
                $this->assertSame([], $p['directives'], "Hay directrices de venta en {$fase} con {$q}.");
                // Pero el piso ético no se va con la venta.
                $this->assertNotEmpty($p['never'], "El piso ético desapareció en {$fase} con {$q}.");
            }
        }

        // Quien dijo que no tampoco recibe un cierre.
        $this->assertNotContains(PB::CIERRE_DECIDIDO, PB::forPhase(Fase::LOST)['techniques']);
    }

    public function test_pregunta_antes_de_proponer_en_descubrimiento(): void
    {
        foreach ([Fase::DISCOVERY, Fase::GOAL_DISCOVERY, Fase::BARRIER_DISCOVERY, Fase::QUALIFICATION] as $fase) {
            $this->assertContains(PB::DESCUBRIMIENTO_CONSULTIVO, PB::forPhase($fase)['techniques'], "No se descubre en {$fase}.");
            $this->assertNotContains(PB::CIERRE_DECIDIDO, PB::forPhase($fase)['techniques'], "Se cierra en {$fase}.");
        }
    }

    public function test_el_valor_va_antes_que_la_lista_de_caracteristicas(): void
    {
        foreach ([Fase::VALUE_BUILDING, Fase::RECOMMENDATION] as $fase) {
            $p = PB::forPhase($fase);
            $this->assertContains(PB::TRADUCCION_DE_VALOR, $p['techniques'], "No se traduce valor en {$fase}.");
            $this->assertStringContainsString(
                'no enumeres características',
                mb_strtolower(implode(' ', $p['directives'])),
                "En {$fase} no se prohíbe el folleto.",
            );
        }
    }

    public function test_la_objecion_se_trabaja_preguntando_y_sin_cerrar(): void
    {
        $p = PB::forPhase(Fase::OBJECTION_HANDLING);

        $this->assertContains(PB::DESCUBRIMIENTO_CONSULTIVO, $p['techniques']);
        $this->assertContains(PB::TRADUCCION_DE_VALOR, $p['techniques']);
        $this->assertContains(PB::DIGNIDAD_DEL_CLIENTE, $p['techniques']);
        $this->assertNotContains(PB::CIERRE_DECIDIDO, $p['techniques'], 'Se cierra sobre una objeción.');
    }

    public function test_cuando_ya_decidio_se_cierra_y_se_deja_de_vender(): void
    {
        foreach ([Fase::BUYING_SIGNAL, Fase::CLOSING, Fase::PAYMENT_HANDOFF] as $fase) {
            $p = PB::forPhase($fase);
            $this->assertContains(PB::CIERRE_DECIDIDO, $p['techniques'], "No se cierra en {$fase}.");
            $this->assertStringContainsString(
                'deja de vender',
                mb_strtolower(implode(' ', $p['directives'])),
                "En {$fase} no se manda dejar de vender.",
            );
        }

        // Y el cierre no es presión: la propia doctrina lo dice.
        $this->assertStringContainsString(
            'si dice que lo piensa, se piensa',
            mb_strtolower(implode(' ', PB::forPhase(Fase::CLOSING)['directives'])),
        );
    }

    /**
     * EL CASO DEL INCIDENTE: la fase dice cierra, la persona dice «está caro».
     *
     * `commercial_phase` se calcula desde la intención del último mensaje, así
     * que «me interesa pero está caro» puede llegar aquí como señal de compra.
     * Cerrar ahí es exactamente lo que vuelve pesado a un vendedor.
     */
    public function test_una_barrera_o_un_rechazo_apagan_el_cierre(): void
    {
        foreach ([['has_barrier' => true], ['rejected_a_plan' => true]] as $señales) {
            $p = PB::forPhase(Fase::BUYING_SIGNAL, $señales);
            $this->assertNotContains(PB::CIERRE_DECIDIDO, $p['techniques'], 'Cierra sobre una duda: '.json_encode($señales));
            $this->assertContains(PB::DIGNIDAD_DEL_CLIENTE, $p['techniques']);
            $this->assertContains(PB::INTERES_GENUINO, $p['techniques']);
        }

        // Y sin señales sí cierra: el apagado no puede ser el comportamiento
        // por defecto, o el agente no vendería nunca.
        $this->assertContains(PB::CIERRE_DECIDIDO, PB::forPhase(Fase::BUYING_SIGNAL)['techniques']);
    }

    public function test_a_quien_ya_es_miembro_no_se_le_cierra_una_venta(): void
    {
        $p = PB::forPhase(Fase::CLOSING, ['is_member' => true]);

        $this->assertNotContains(PB::CIERRE_DECIDIDO, $p['techniques']);
        $this->assertContains(PB::MEMORIA_DE_RELACION, $p['techniques']);
    }

    public function test_el_seguimiento_se_apoya_en_la_memoria_y_no_en_el_acoso(): void
    {
        foreach ([Fase::FOLLOW_UP, Fase::NURTURE] as $fase) {
            $p = PB::forPhase($fase);
            $this->assertContains(PB::MEMORIA_DE_RELACION, $p['techniques'], "No hay memoria en {$fase}.");
            $this->assertNotContains(PB::CIERRE_DECIDIDO, $p['techniques'], "Se cierra en frío en {$fase}.");
        }
    }

    public function test_al_llegar_alguien_nuevo_se_habla_de_la_persona(): void
    {
        foreach ([Fase::NEW_LEAD, Fase::RAPPORT] as $fase) {
            $p = PB::forPhase($fase);
            $this->assertContains(PB::INTERES_GENUINO, $p['techniques'], "No hay interés genuino en {$fase}.");
            $this->assertNotContains(PB::CIERRE_DECIDIDO, $p['techniques'], "Se cierra al saludar en {$fase}.");
            $this->assertStringContainsString('habla de la persona', mb_strtolower(implode(' ', $p['directives'])));
        }
    }

    public function test_una_fase_desconocida_no_deja_al_agente_sin_doctrina(): void
    {
        /*
         * Hay conversaciones anteriores a la máquina comercial, y la columna es
         * nullable. Quedarse sin doctrina ahí sería quedarse sin piso ético.
         */
        $p = PB::forPhase('FASE_QUE_NO_EXISTE');

        $this->assertNotEmpty($p['techniques']);
        $this->assertNotEmpty($p['never']);
        $this->assertNotContains(PB::CIERRE_DECIDIDO, $p['techniques'], 'Cierra sin saber dónde está la conversación.');
    }

    public function test_las_señales_salen_de_la_memoria_que_ya_existe(): void
    {
        $vacia = PB::signalsFrom(CM::fromArray(null));
        $this->assertSame(
            ['rejected_a_plan' => false, 'is_member' => false, 'has_barrier' => false, 'returning' => false],
            $vacia,
        );

        $conRechazo = PB::signalsFrom(CM::fromArray(null)->rejectPlan(4), true, ' ', 'Quiere bajar grasa.');
        $this->assertTrue($conRechazo['rejected_a_plan']);
        $this->assertTrue($conRechazo['is_member']);
        // Una barrera en blanco no es una barrera.
        $this->assertFalse($conRechazo['has_barrier']);
        $this->assertTrue($conRechazo['returning']);
    }

    /**
     * La FORMA de la conversación también es doctrina: el saludo no es un
     * menú, la recomendación se justifica con lo que la persona dijo, el
     * interés no salta al pago, y el vocabulario no calca «cliente» ni
     * «siguiente paso», que el modelo repite si lo lee.
     */
    public function test_la_forma_de_conversar_viaja_en_la_doctrina(): void
    {
        $de = fn (string $fase) => mb_strtolower(implode(' ', PB::forPhase($fase)['directives']));

        foreach ([Fase::NEW_LEAD, Fase::RAPPORT] as $fase) {
            $this->assertStringContainsString('saludo, bienvenida y una apertura humana', $de($fase), "En {$fase} el saludo puede ser un menú.");
        }
        foreach ([Fase::VALUE_BUILDING, Fase::RECOMMENDATION] as $fase) {
            $this->assertStringContainsString('justifica la recomendación', $de($fase), "En {$fase} se puede recomendar a secas.");
        }
        $this->assertStringContainsString('no saltes al pago', $de(Fase::BUYING_SIGNAL), 'El interés salta al pago.');

        foreach (Fase::PHASES as $fase) {
            $p = PB::forPhase($fase);
            $texto = mb_strtolower(implode(' ', [...$p['directives'], ...$p['never']]));
            foreach (['cliente', 'usuario', 'siguiente paso', 'puedes consultar'] as $calco) {
                $this->assertStringNotContainsString($calco, $texto, "La fase {$fase} lee «{$calco}» y lo va a repetir.");
            }
        }
    }
}
