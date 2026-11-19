<?php

namespace Tests\Unit\Marketing\Ultron;

use App\Services\Marketing\Ultron\CommercialTurnPolicy;
use App\Services\Marketing\Ultron\ReferenceResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A4 · H4-10 · ¿LA PERSONA PIDE EL PANORAMA DE PLANES? (plan del arquitecto
 * `plan-arquitecto-a4-resto.md` §3, decisión P-1 del lead). Función pura: el
 * texto, la política del turno y la resolución del referente. Cierra con
 * casos la lista de filtros que excluyen.
 */
class CommercialTurnPolicyPanoramaTest extends TestCase
{
    private const POLITICA = ['reception_mode' => false, 'memory_mode' => false, 'goal_takes_turn' => false];

    /** @return array<string, array{string}> */
    public static function panoramas(): array
    {
        return [
            'qué planes tienen' => ['¿Qué planes tienen?'],
            'cuáles planes manejan' => ['¿Cuáles planes manejan?'],
            'qué membresías hay' => ['¿Qué membresías hay?'],
            'qué opciones tienen' => ['Hola, ¿qué opciones tienen?'],
            'planes hay' => ['¿Y planes hay?'],
            'membresías ofrecen' => ['¿Membresías ofrecen?'],
            'qué ofrecen' => ['¿Qué ofrecen?'],
            'los planes' => ['Quisiera saber los planes'],
            'todos los planes' => ['Mándame todos los planes'],
            'el de la prueba real' => ['¿Qué planes tienen y cuánto cuesta la mensualidad?'],
            'con precio en plural' => ['¿Qué planes tienen y cuánto cuestan?'],
            'un plan nombrado y qué otros hay' => ['¿Cuánto cuesta el Mensual y qué otros hay?'],
            'qué otros planes tienen' => ['¿Qué otros planes tienen?'],
        ];
    }

    #[DataProvider('panoramas')]
    public function test_lo_que_es_panorama(string $texto): void
    {
        $this->assertTrue(CommercialTurnPolicy::pideElPanorama($texto, self::POLITICA, []), $texto);
    }

    /** @return array<string, array{string}> */
    public static function noPanoramas(): array
    {
        return [
            'un plan nombrado' => ['¿Cuánto cuesta el Mensual?'],
            'la mensualidad, en singular' => ['¿Cuánto cuesta la mensualidad?'],
            'el precio a secas' => ['¿Cuánto cuesta?'],
            'qué otros días (no son planes)' => ['¿Qué otros días abren?'],
            'el horario' => ['¿Cuál es el horario?'],
            // La lista de filtros que excluyen: la lista no contestaría lo que se pregunta.
            'para estudiantes' => ['¿Qué planes tienen para estudiantes?'],
            'para parejas' => ['¿Qué planes tienen para parejas?'],
            'para dos' => ['¿Qué planes hay para dos personas?'],
            'para niños' => ['¿Qué planes tienen para niños?'],
            'para adultos mayores' => ['¿Qué planes hay para adultos mayores?'],
            'para familias' => ['¿Qué planes tienen para familias?'],
            'para empresas' => ['¿Qué planes manejan para empresas?'],
            'para principiantes' => ['¿Qué planes tienen para principiantes?'],
            'por días' => ['¿Qué planes tienen por días?'],
            'por semana' => ['¿Qué planes hay por semana?'],
            'de un mes' => ['¿Qué planes tienen de un mes?'],
            'por meses' => ['¿Qué planes manejan por meses?'],
            'con descuento' => ['¿Qué planes tienen con descuento?'],
            'promociones' => ['¿Qué planes hay en promoción?'],
            'con entrenador personalizado' => ['¿Qué planes tienen con entrenador personalizado?'],
            'familiar' => ['¿Tienen planes familiares?'],
        ];
    }

    #[DataProvider('noPanoramas')]
    public function test_lo_que_no_es_panorama(string $texto): void
    {
        $this->assertFalse(CommercialTurnPolicy::pideElPanorama($texto, self::POLITICA, []), $texto);
    }

    public function test_elegir_entre_opciones_dadas_no_es_panorama(): void
    {
        $this->assertFalse(CommercialTurnPolicy::pideElPanorama('De los planes, el primero', self::POLITICA, ['type' => ReferenceResolver::CHOOSE_PLAN, 'plan_id' => 3]));
        $this->assertTrue(CommercialTurnPolicy::pideElPanorama('De los planes, el primero', self::POLITICA, ['type' => ReferenceResolver::NONE]));
    }

    /** @return array<string, array{string}> */
    public static function modos(): array
    {
        return ['recepción' => ['reception_mode'], 'memoria' => ['memory_mode'], 'visita a medias' => ['goal_takes_turn']];
    }

    #[DataProvider('modos')]
    public function test_recepcion_memoria_y_visita_a_medias_no_son_panorama(string $modo): void
    {
        $this->assertFalse(CommercialTurnPolicy::pideElPanorama('¿Qué planes tienen?', [$modo => true] + self::POLITICA, []));
    }

    public function test_la_pregunta_de_precio(): void
    {
        $this->assertTrue(CommercialTurnPolicy::preguntaPorPrecio('¿Qué planes tienen y cuánto cuestan?'));
        $this->assertTrue(CommercialTurnPolicy::preguntaPorPrecio('¿Qué planes tienen y cuánto cuesta la mensualidad?'));
        $this->assertTrue(CommercialTurnPolicy::preguntaPorPrecio('Los planes y sus precios'));
        $this->assertFalse(CommercialTurnPolicy::preguntaPorPrecio('¿Qué planes tienen?'));
    }
}
