<?php

namespace Tests\Unit\Marketing;

use App\Services\Marketing\Ultron\ConversationMemory;
use App\Services\Marketing\Ultron\NoveltyGuard;
use PHPUnit\Framework\TestCase;

class NoveltyGuardTest extends TestCase
{
    private const PRECIO = 'El mensual está en $80.000 COP e incluye Acceso al gimnasio durante la vigencia del plan, Reserva de clases grupales disponibles, y Acceso a rutinas asignadas en la app móvil.';

    /** Las seis respuestas idénticas de junio: la segunda ya no sale. */
    public function test_an_identical_reply_is_a_near_duplicate(): void
    {
        $g = new NoveltyGuard;

        $this->assertSame(0, $g->nearDuplicateOf(self::PRECIO, [self::PRECIO]));
        $this->assertSame(1, $g->nearDuplicateOf(self::PRECIO, ['Hola, ¿en qué te ayudo?', self::PRECIO]));
    }

    /** Cambiar el saludo o el nombre no lo convierte en respuesta nueva. */
    public function test_a_cosmetic_variation_is_still_a_duplicate(): void
    {
        $g = new NoveltyGuard;
        $variante = 'Claro, Alejandro. '.self::PRECIO;

        $this->assertNotNull($g->nearDuplicateOf($variante, [self::PRECIO]));
    }

    /** Información nueva sobre el mismo plan sí es una respuesta nueva. */
    public function test_new_information_is_not_a_duplicate(): void
    {
        $g = new NoveltyGuard;
        $nuevo = 'Además del acceso, el mensual trae seguimiento de un entrenador en la ejecución y valoración inicial cuando llegas. ¿Quieres que te explique cómo empezar?';

        $this->assertNull($g->nearDuplicateOf($nuevo, [self::PRECIO]));
        $this->assertLessThan(0.3, $g->similarity($nuevo, self::PRECIO));
    }

    public function test_an_explicit_repeat_request_is_recognised(): void
    {
        $g = new NoveltyGuard;
        foreach (['me repites la dirección porfa? la borré', 'mándamelo otra vez', 'no me llegó, lo puedes volver a enviar?', 'repíteme el precio'] as $t) {
            $this->assertTrue($g->isExplicitRepeatRequest($t), "«{$t}»");
        }
        foreach (['quiero informacion de los planes', 'cuéntame más', 'si por favor'] as $t) {
            $this->assertFalse($g->isExplicitRepeatRequest($t), "«{$t}»");
        }
    }

    public function test_guidance_lists_what_was_delivered_and_what_is_left(): void
    {
        $g = new NoveltyGuard;
        $m = ConversationMemory::empty()
            ->deliverPrice(20, 'x', 1)
            ->deliverBenefit(20, 'Acceso ilimitado al gimnasio', 'x')
            ->agentAsked('¿Quieres que te cuente más detalles?', 'plan_details', 20, 'x', 1);
        $plans = [['id' => 20, 'name' => 'Plan Mensual', 'benefits' => ['Acceso ilimitado al gimnasio', 'Asesoría semi personalizada', 'Clases grupales']]];

        $guia = $g->guidance($m, $plans, ['type' => 'more_info', 'plan_id' => 20]);

        $this->assertSame([20], $guia['must_not_repeat']['price_of_plans']);
        $this->assertSame(['Acceso ilimitado al gimnasio'], $guia['must_not_repeat']['benefits']);
        $this->assertSame('¿Quieres que te cuente más detalles?', $guia['must_not_repeat']['last_agent_question']);
        $this->assertContains('beneficio no contado del plan 20: Asesoría semi personalizada', $guia['fresh_candidates']);
        $this->assertContains('beneficio no contado del plan 20: Clases grupales', $guia['fresh_candidates']);
        $this->assertContains('como empezar (pasos concretos)', $guia['fresh_candidates']);
        $this->assertNotContains('beneficio no contado del plan 20: Acceso ilimitado al gimnasio', $guia['fresh_candidates']);
    }

    /**
     * SIN PLAN EN FOCO, LO FRESCO SALE DEL PRIMERO DEL CATÁLOGO, NO DEL MÁS CORTO.
     *
     * Prueba física: nadie había hablado de planes y la guía traía «beneficio
     * no contado del plan 2» siete veces, porque el catálogo llegaba por
     * duración y el más corto era el Plan Semana. El redactor lo nombró. El
     * catálogo llega ahora en el orden del negocio, y sólo el primero aporta.
     */
    public function test_without_a_focus_plan_only_the_first_of_the_catalogue_contributes(): void
    {
        $g = new NoveltyGuard;
        $plans = [
            ['id' => 22, 'name' => 'TOTAL ACCESS - ELITE', 'benefits' => ['Acceso completo', 'Acompañamiento']],
            ['id' => 2, 'name' => 'Plan Semana', 'benefits' => ['Siete días', 'Zona de pesas']],
            ['id' => 4, 'name' => 'Plan Mensual', 'benefits' => ['Acceso ilimitado']],
        ];

        $guia = $g->guidance(ConversationMemory::empty(), $plans, ['type' => 'none', 'plan_id' => null]);

        $delPreferido = array_filter($guia['fresh_candidates'], fn ($c) => str_starts_with($c, 'beneficio no contado del plan 22'));
        $deOtros = array_filter($guia['fresh_candidates'], fn ($c) => str_starts_with($c, 'beneficio no contado del plan 2:') || str_starts_with($c, 'beneficio no contado del plan 4'));
        $this->assertCount(2, $delPreferido, 'el preferido del negocio no aporta lo fresco');
        $this->assertSame([], array_values($deOtros), 'un plan del que nadie habló entró como «lo fresco que contar»');

        // Con foco, el foco manda, sea cual sea su posición.
        $conFoco = $g->guidance(ConversationMemory::empty(), $plans, ['type' => 'more_info', 'plan_id' => 4]);
        $this->assertContains('beneficio no contado del plan 4: Acceso ilimitado', $conFoco['fresh_candidates']);
        $this->assertNotContains('beneficio no contado del plan 22: Acceso completo', $conFoco['fresh_candidates']);
    }
}
