<?php

namespace Tests\Unit\Marketing;

use App\Services\Marketing\Ultron\PromiseAuthority;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * El banco adversarial de promesas, medido entero.
 *
 * Lo que aquí se mide no es si una frase suena mal, sino si AFIRMA un efecto
 * que el sistema no produce. Las inocentes son la mitad importante y llevan
 * a propósito las mismas palabras —marcado, registro, equipo, mañana,
 * recuerdo, guardar— en frases que no prometen nada: un guard que las tumbe
 * deja a la gente sin respuesta para arreglar un problema de estilo.
 */
class PromiseAuthorityTest extends TestCase
{
    private PromiseAuthority $autoridad;

    protected function setUp(): void
    {
        parent::setUp();
        $this->autoridad = new PromiseAuthority;
    }

    private static function banco(): array
    {
        return require __DIR__.'/../../fixtures/promesas_ultron.php';
    }

    #[DataProvider('efectosDurables')]
    public function test_a_claim_of_a_durable_effect_is_detected(string $frase): void
    {
        $this->assertSame(PromiseAuthority::EFECTO_DURABLE, $this->autoridad->detectar($frase)['kind'], $frase);
    }

    #[DataProvider('accionesFuturas')]
    public function test_a_promise_of_a_future_action_is_detected(string $frase): void
    {
        $this->assertSame(PromiseAuthority::ACCION_FUTURA, $this->autoridad->detectar($frase)['kind'], $frase);
    }

    #[DataProvider('inocentes')]
    public function test_an_honest_sentence_is_left_alone(string $frase): void
    {
        $r = $this->autoridad->detectar($frase);
        $this->assertNull($r['kind'], $frase.'  →  '.var_export($r['quote'], true));
    }

    public static function efectosDurables(): array
    {
        return array_map(fn ($f) => [$f], self::banco()['efecto_durable']);
    }

    public static function accionesFuturas(): array
    {
        return array_map(fn ($f) => [$f], self::banco()['accion_futura']);
    }

    public static function inocentes(): array
    {
        return array_map(fn ($f) => [$f], self::banco()['inocentes']);
    }

    /**
     * Decir la VERDAD sobre lo que no se puede hacer es exactamente lo que
     * queremos, así que negarlo no puede costar el turno. Es el caso donde la
     * frase honesta y la prohibida se parecen más.
     */
    public function test_saying_it_cannot_be_done_is_never_a_promise(): void
    {
        foreach ([
            'No te puedo guardar el cupo, se toman al llegar.',
            'No te escribo despues porque no manejo recordatorios.',
            'No te agendo nada por aqui.',
            'No lo dejo marcado porque esto lo resuelvo yo.',
            'Nunca te llamo sin que me lo pidas.',
        ] as $frase) {
            $this->assertNull($this->autoridad->detectar($frase)['kind'], $frase);
        }
    }

    /** Una negación en la frase anterior no autoriza la promesa de la siguiente. */
    public function test_a_negation_in_a_previous_sentence_does_not_cover_a_later_promise(): void
    {
        $r = $this->autoridad->detectar('No tengo cupos confirmados. Te escribo manana con la respuesta.');

        $this->assertSame(PromiseAuthority::ACCION_FUTURA, $r['kind']);
    }

    /** La acción futura pesa más: nunca puede ser verdad, así que gana el empate. */
    public function test_the_future_action_wins_when_both_appear(): void
    {
        $r = $this->autoridad->detectar('Lo dejo marcado para el equipo y te escribo manana.');

        $this->assertSame(PromiseAuthority::ACCION_FUTURA, $r['kind']);
    }

    public function test_an_empty_text_promises_nothing(): void
    {
        foreach ([null, '', '   '] as $vacio) {
            $this->assertNull($this->autoridad->detectar($vacio)['kind']);
        }
    }
}
