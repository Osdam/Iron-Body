<?php

namespace Tests\Unit\Marketing;

use App\Services\Marketing\OutboundContentGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Una URL en el borrador del modelo se detecta aunque venga disfrazada; el texto normal no dispara. */
class OutboundUrlGuardTest extends TestCase
{
    /** @return array<string, array{0:string}> */
    public static function legitimo(): array
    {
        return [
            'precio' => ['El mensual vale $80.000 COP y el trimestral 3.000.000 de pesos.'],
            'horas' => ['Abrimos de 5 a.m. a 10 p.m.'],
            'direccion' => ['Estamos en la Cl. 24 Sur #33-53.'],
            'puntuacion pegada' => ['Gracias.Cordial saludo.'],
            'plan con punto' => ['El plan Iron.Fit incluye clases.'],
        ];
    }

    /** @return array<string, array{0:string}> */
    public static function url(): array
    {
        return [
            'https' => ['Paga aquí: https://checkout.wompi.co/p/?x=1'],
            'www' => ['Entra a www.ironbodyneiva.cloud y regístrate.'],
            'dominio' => ['Mira web.ironbodyneiva.cloud/pago'],
            'parentesis' => ['checkout(.)wompi(.)co/l/abc'],
            'corchetes' => ['checkout[.]wompi[.]co/l/abc'],
            'punto hablado' => ['checkout punto wompi punto co barra l barra abc'],
        ];
    }

    #[DataProvider('legitimo')]
    public function test_ordinary_text_is_not_a_url(string $texto): void
    {
        $this->assertFalse(OutboundContentGuard::containsUrl($texto), $texto);
    }

    #[DataProvider('url')]
    public function test_a_url_is_detected_even_when_disguised(string $texto): void
    {
        $this->assertTrue(OutboundContentGuard::containsUrl($texto), $texto);
    }
}
