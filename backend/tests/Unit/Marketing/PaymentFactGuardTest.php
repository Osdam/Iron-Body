<?php

namespace Tests\Unit\Marketing;

use App\Services\Marketing\Ultron\PaymentFactGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Quién dice que un pago entró. Lo dice Wompi; el modelo no.
 *
 * Los casos de bloqueo salen del laboratorio de tortura (PUNTO 13), que
 * demostró que estas frases llegaban íntegras al cliente con la caja vacía.
 * Los de paso son igual de importantes: un cerrojo que impida decir la verdad
 * («todavía no me aparece») deja a la persona sin respuesta y empuja a una
 * derivación que no hace falta.
 */
class PaymentFactGuardTest extends TestCase
{
    private PaymentFactGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guard = new PaymentFactGuard;
    }

    /** @return iterable<string, array{string, string}> */
    public static function afirmacionesFalsas(): iterable
    {
        yield 'recibimos tu pago' => ['Ya recibimos tu pago.', PaymentFactGuard::REASON_CLAIMS_PAID];
        yield 'pago confirmado' => ['Tu pago ya esta confirmado en el sistema.', PaymentFactGuard::REASON_CLAIMS_PAID];
        yield 'el pago entro' => ['Listo, el pago entro correctamente.', PaymentFactGuard::REASON_CLAIMS_PAID];
        yield 'pago aprobado suelto' => ['Pago aprobado, nos vemos en el gimnasio.', PaymentFactGuard::REASON_CLAIMS_PAID];
        yield 'transferencia recibida' => ['Tu transferencia ya llego, gracias.', PaymentFactGuard::REASON_CLAIMS_PAID];
    }

    #[DataProvider('afirmacionesFalsas')]
    public function test_a_payment_the_crm_cannot_see_is_never_announced(string $reply, string $reason): void
    {
        foreach (['none', 'pending', 'declined', 'expired'] as $estado) {
            $hallazgo = $this->guard->contradiction($reply, $estado);

            $this->assertNotNull($hallazgo, "$reply con estado $estado");
            $this->assertSame(PaymentFactGuard::CODE, $hallazgo['code']);
            $this->assertSame($reason, $hallazgo['reason'], $reply);
        }
    }

    #[DataProvider('afirmacionesFalsas')]
    public function test_with_the_payment_really_approved_the_same_sentence_is_fine(string $reply): void
    {
        $this->assertNull($this->guard->contradiction($reply, PaymentFactGuard::APPROVED), $reply);
    }

    /** @return iterable<string, array{string}> */
    public static function pruebasQueNoSonPrueba(): iterable
    {
        yield 'pide captura' => ['Mandame la captura del comprobante y te dejo todo listo.'];
        yield 'con el soporte basta' => ['Con el soporte de la transferencia por aqui basta.'];
        yield 'foto del pago' => ['Enviame una foto del pago para verificarlo.'];
        yield 'pantallazo' => ['Pasame un pantallazo del comprobante.'];
    }

    #[DataProvider('pruebasQueNoSonPrueba')]
    public function test_a_screenshot_is_never_proof_of_payment(string $reply): void
    {
        foreach (['none', 'pending', PaymentFactGuard::APPROVED] as $estado) {
            $hallazgo = $this->guard->contradiction($reply, $estado);

            $this->assertNotNull($hallazgo, "$reply con estado $estado");
            $this->assertSame(PaymentFactGuard::REASON_ACCEPTS_RECEIPT, $hallazgo['reason']);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function honestidad(): iterable
    {
        yield 'no aparece' => ['Todavia no me aparece confirmado tu pago.'];
        yield 'consultado en vivo' => ['Acabo de consultarlo con la pasarela y aun no aparece el pago.'];
        yield 'puede tardar' => ['El pago puede tardar unos minutos en confirmarse, no hace falta que mandes nada.'];
        yield 'sin registro' => ['Por ahora no tengo registro de un pago a tu nombre.'];
        yield 'explica el paso' => ['Cuando completes el pago en el link, el sistema lo confirma solo.'];
        yield 'habla de otra cosa' => ['Las clases grupales son de lunes a viernes por la manana.'];
        yield 'pregunta util' => ['Quieres que te explique como funciona el plan mensual?'];
        yield 'recibo del gimnasio' => ['Tu referencia de pago son los ultimos digitos del recibo.'];
    }

    #[DataProvider('honestidad')]
    public function test_saying_the_truth_is_never_blocked(string $reply): void
    {
        foreach (['none', 'pending', 'declined', 'expired', PaymentFactGuard::APPROVED] as $estado) {
            $this->assertNull($this->guard->contradiction($reply, $estado), "$reply con estado $estado");
        }
    }

    public function test_the_finding_never_carries_the_reply_text(): void
    {
        $hallazgo = $this->guard->contradiction('Hola Carla, ya recibimos tu pago de 80.000.', 'none');

        $this->assertNotNull($hallazgo);
        $json = json_encode($hallazgo);
        $this->assertStringNotContainsString('Carla', $json);
        $this->assertStringNotContainsString('80.000', $json);
    }

    public function test_the_regex_compiles_on_the_production_pcre(): void
    {
        $this->guard->contradiction('Ya recibimos tu pago.', 'none');

        $this->assertSame('No error', preg_last_error_msg());
        $codigo = file_get_contents(__DIR__.'/../../../app/Services/Marketing/Ultron/PaymentFactGuard.php');
        $this->assertDoesNotMatchRegularExpression('~\\\\p\{~', (string) $codigo, 'produccion corre PCRE 10.39: sin propiedades Unicode');
    }
}
