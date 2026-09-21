<?php

namespace Tests\Unit\Marketing;

use App\Services\Marketing\OutboundContentGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * NEGAR NO ES AFIRMAR, Y LA DIFERENCIA CUESTA DINERO.
 *
 * Este detector decide dos cosas caras: si una respuesta que dice «no tenemos
 * link de pago» prueba que a la persona le interesaba pagar —y entonces Laravel
 * acuña un cobro real— y si esa frase puede salir. Equivocarse en cualquiera de
 * las dos direcciones se paga.
 *
 * La primera versión usaba un hueco de cuarenta caracteres entre el verbo
 * negado y el enlace, y con eso «no hay problema, te paso el link de pago» —la
 * cortesía más corriente que existe— contaba como negación. Se midió: un turno
 * de «no me interesa por ahora» acuñaba un checkout pagable, con la sola
 * diferencia de llevar esa frase de cortesía. El control sin ella no acuñaba
 * nada.
 *
 * Por eso las frases de cortesía son casos de prueba de primera clase aquí, y
 * no una nota al pie.
 */
class CheckoutDenialDetectorTest extends TestCase
{
    private function guard(): OutboundContentGuard
    {
        return new OutboundContentGuard;
    }

    /** @return array<string,array{0:string}> */
    public static function frasesQueNiegan(): array
    {
        return [
            'la del canario' => ['No contamos con un link de pago directo por ahora, pero puedes hacer el pago seguro desde la app Iron Body Workout.'],
            'sin determinante' => ['Por ahora no tenemos enlace de pago, se paga en recepción.'],
            'pagos en línea' => ['No manejamos pagos en línea todavía.'],
            'no puedo enviarlo' => ['No puedo enviarte un link de pago desde aquí.'],
            'no está disponible' => ['El link de pago no está disponible en este momento.'],
            'no hay, a secas' => ['No hay link de pago por ahora.'],
            'orden inverso' => ['No contamos con un link directo de pago.'],
            'demora antes de la negación' => ['El enlace de pago todavía no está disponible.'],
        ];
    }

    /** @return array<string,array{0:string}> */
    public static function frasesQueNoNiegan(): array
    {
        return [
            // Las cuatro que reprodujo la revisión, una a una.
            'cortesía · no hay problema' => ['No hay problema, te paso el link de pago.'],
            'cortesía · no hay afán' => ['No hay afán para el pago en línea, cuando quieras.'],
            'cortesía · no hay prisa' => ['Tranquilo, no hay prisa con el pago online.'],
            'cortesía · sin restricción' => ['No tenemos restricción para el link de pago.'],
            'cortesía · con el link' => ['No hay problema con el link, te lo mando ya.'],

            'enumera medios' => ['Puedes pagar en la app Iron Body Workout o directamente en el gimnasio.'],
            'entrega el enlace' => ['Claro, aquí tienes tu link de pago seguro por $210.000.'],
            'promete el enlace' => ['El pago lo confirmamos en el momento; te paso el enlace de pago enseguida.'],
            'niega otra cosa' => ['No tenemos parqueadero, pero el gimnasio está en la 8a.'],
            'la negación correcta de la tarjeta' => ['No puedo pedirte datos de tarjeta por aquí; el cobro lo hace la pasarela.'],

            // La otra dirección del mismo error: lo que no está disponible es
            // el parqueadero, no el enlace, y entre los dos hay un punto y coma
            // que el hueco ancho se saltaba.
            'lo no disponible es otra cosa' => ['El link de pago te llega ya; el parqueadero no está disponible.'],
            'no puedo enviarte OTRA cosa' => ['No puedo enviarte datos de tarjeta, pero aquí tienes el link de pago.'],
        ];
    }

    #[DataProvider('frasesQueNiegan')]
    public function test_a_real_denial_is_caught(string $frase): void
    {
        $this->assertNotNull(
            $this->guard()->checkoutDenialIn($frase),
            'una negación real del cobro pasó sin detectarse',
        );
    }

    #[DataProvider('frasesQueNoNiegan')]
    public function test_courtesy_and_affirmations_are_not_denials(string $frase): void
    {
        $this->assertNull(
            $this->guard()->checkoutDenialIn($frase),
            'se tomó por negación algo que no lo es: con eso se acuña un cobro a quien no lo pidió',
        );
    }

    /**
     * Y devuelve la frase, no un booleano.
     *
     * El motivo se registra y se lee en el acta del canario: saber QUÉ se
     * detectó es la diferencia entre corregir el patrón y adivinar.
     */
    public function test_it_returns_the_offending_phrase(): void
    {
        $frase = $this->guard()->checkoutDenialIn('No contamos con un link de pago directo por ahora.');

        $this->assertIsString($frase);
        $this->assertStringContainsString('link', $frase);
    }
}
