<?php

namespace Tests\Unit\Marketing\Ultron;

use App\Services\Marketing\Ultron\CommercialTurnPolicy;
use App\Services\Marketing\Ultron\StrategyContract;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * H4-9 · LOS DETECTORES DE TEXTO ENTIENDEN EL USTED, SOLO CON «PROFESIONAL»
 * (añadido del lead del 19-11). Puras: `StrategyContract::asksDiscovery()` y
 * `CommercialTurnPolicy::violations()` en modo memoria. Sin tono (la llamada
 * de hoy), con `cercano` o con `entusiasta`, exactamente como antes. La oferta
 * de usted de la memoria (`offerKindOf()`, privada) la prueba el endpoint.
 */
class UltronUstedDetectoresTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function preguntasDeUsted(): array
    {
        return [
            'cuál es su objetivo' => ['Bienvenido. ¿Cuál es su objetivo al entrenar?'],
            'qué busca lograr' => ['Hola. ¿Qué busca lograr al empezar?'],
            'ha entrenado antes' => ['Hola, buenas tardes. ¿Ya ha entrenado antes?'],
            'tiene experiencia' => ['¿Tiene experiencia entrenando?'],
            'cuántos días puede' => ['¿Cuántos días puede venir?'],
            'qué horario le queda' => ['¿Qué horario le queda mejor?'],
        ];
    }

    #[DataProvider('preguntasDeUsted')]
    public function test_la_pregunta_de_descubrimiento_de_usted_solo_cuenta_con_profesional(string $texto): void
    {
        $this->assertTrue(StrategyContract::asksDiscovery($texto, 'profesional'));
        $this->assertFalse(StrategyContract::asksDiscovery($texto), 'sin tono: como siempre');
        $this->assertFalse(StrategyContract::asksDiscovery($texto, 'cercano'));
        $this->assertFalse(StrategyContract::asksDiscovery($texto, 'entusiasta'));
    }

    public function test_de_usted_solo_dentro_de_una_pregunta_y_la_de_tu_siempre(): void
    {
        $this->assertFalse(StrategyContract::asksDiscovery('Nuestro equipo tiene experiencia y le acompaña.', 'profesional'), 'tercera persona, sin pregunta');
        $this->assertFalse(StrategyContract::asksDiscovery('Le cuento lo que busca la gente que empieza. ¿En qué le ayudo?', 'profesional'));
        foreach ([null, 'cercano', 'entusiasta', 'profesional'] as $tono) {
            $this->assertTrue(StrategyContract::asksDiscovery('¿Ya has entrenado antes?', $tono), 'la de tú, con cualquier tono');
        }
    }

    public function test_el_turno_de_memoria_que_ofrece_los_planes_de_usted(): void
    {
        $politica = ['memory_mode' => true];
        foreach (['Si quiere, le cuento sobre los planes.', 'Con gusto le explico de las clases.', 'Así queda su plan.'] as $borrador) {
            $this->assertSame([CommercialTurnPolicy::VIOLACION_MEMORIA_VENDE], CommercialTurnPolicy::violations($borrador, $politica, [], [], 'profesional'), $borrador);
            $this->assertSame([], CommercialTurnPolicy::violations($borrador, $politica, []), "sin tono: {$borrador}");
            $this->assertSame([], CommercialTurnPolicy::violations($borrador, $politica, [], [], 'entusiasta'));
        }
        $this->assertSame([CommercialTurnPolicy::VIOLACION_MEMORIA_VENDE], CommercialTurnPolicy::violations('Si quieres, te cuento sobre los planes.', $politica, []), 'la de tú, como siempre');
    }
}
