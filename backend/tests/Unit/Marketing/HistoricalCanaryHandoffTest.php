<?php

namespace Tests\Unit\Marketing;

use App\Models\MarketingMessage;
use App\Services\Marketing\HumanHandoffAuthority;
use App\Services\Marketing\OutboundContentGuard;
use PHPUnit\Framework\TestCase;

/**
 * La conversación que falló, turno por turno.
 *
 * El 17 de septiembre el asesor derivó a una persona cuatro veces en cinco
 * turnos. Ninguno de esos turnos pedía un humano; el último decía literalmente
 * lo contrario. Esta prueba fija esa secuencia como regresión: no evalúa el
 * texto que se genere —eso cambia— sino la única pregunta que importaba,
 * «¿podía derivar aquí?», y la respuesta tiene que ser no en los cuatro casos.
 */
class HistoricalCanaryHandoffTest extends TestCase
{
    /** Los cinco turnos reales, en orden, tal y como llegaron (erratas incluidas). */
    private const CONVERSACION_REAL = [
        'hola quiero informacion de los planes',
        'uy que buneo cuentame mas',
        'si por favor',
        'tienes inforamacion de cuantos entrenadores tiene el gimnsaio?',
        'no quiero alguien del equipo',
    ];

    public function test_the_conversation_that_failed_now_produces_zero_handoffs(): void
    {
        $autoridad = new HumanHandoffAuthority;
        $derivaciones = [];

        foreach (self::CONVERSACION_REAL as $i => $mensaje) {
            // El peor caso: el modelo insiste en escalar en TODOS los turnos,
            // que es exactamente lo que hizo.
            $d = $autoridad->decide(HumanHandoffAuthority::EXPLICIT_HUMAN_REQUEST, $mensaje);

            if ($d['allowed']) {
                $derivaciones[] = ($i + 1).": «{$mensaje}»";
            }
        }

        $this->assertSame([], $derivaciones, 'Derivaciones no autorizadas: '.implode(' | ', $derivaciones));
    }

    /** Y el turno 5, que es el que dolía: se le dijo que no y volvió a ofrecerlo. */
    public function test_after_an_explicit_no_the_answer_is_still_no(): void
    {
        $autoridad = new HumanHandoffAuthority;

        foreach (['no quiero alguien del equipo', 'no quiero alguien del equipo, contesta', 'contesta'] as $m) {
            $this->assertFalse($autoridad->decide(HumanHandoffAuthority::EXPLICIT_HUMAN_REQUEST, $m)['allowed']);
        }
    }

    /**
     * Las respuestas que salieron aquel día tampoco habrían salido hoy: la
     * segunda barrera las para aunque el modelo las escriba.
     */
    public function test_the_replies_that_were_sent_would_be_blocked_today(): void
    {
        $guard = new OutboundContentGuard;

        $respuestasReales = [
            'Listo, Alejandro. Te conecto con alguien del equipo para que te explique cómo empezar con el Plan Mensual.',
            'Tranquilo, esa info la tiene el equipo humano. Te conecto con alguien para que te cuente bien todo.',
            'Te entiendo, pero soy un asistente automático del equipo de Iron Body y no tengo esa info exacta. ¿Quieres que te pase con alguien del equipo que te pueda contar?',
        ];

        foreach ($respuestasReales as $texto) {
            $r = $guard->inspect($texto, MarketingMessage::SENDER_AI, handoffAllowed: false);
            $this->assertFalse($r['safe'], "Habría salido igual: «{$texto}»");
            $this->assertSame(OutboundContentGuard::CODE_UNAUTHORIZED_HANDOFF, $r['code']);
        }
    }

    /** Pero si de verdad lo piden, sigue funcionando: no eliminamos el handoff. */
    public function test_a_genuine_request_still_reaches_a_human(): void
    {
        $d = (new HumanHandoffAuthority)->decide(
            HumanHandoffAuthority::EXPLICIT_HUMAN_REQUEST,
            'quiero hablar con una persona por favor',
        );

        $this->assertTrue($d['allowed']);
        $this->assertSame(HumanHandoffAuthority::EXPLICIT_HUMAN_REQUEST, $d['reason']);
        $this->assertNotNull($d['evidence']);
    }
}
