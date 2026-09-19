<?php

namespace Tests\Unit\Marketing;

use App\Services\Marketing\OutboundContentGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Lo que decide no es el vocabulario: es si la frase PIDE o AFIRMA.
 *
 * Dos intentos anteriores se rompieron por el mismo sitio, cada uno en un
 * sentido. El primero neutralizaba el vocabulario del gimnasio con
 * `preg_replace` ANTES de buscar: borraba «pin de acceso» y «tarjeta de
 * acceso», así que «necesito el pin de acceso de tu tarjeta» se quedaba sin el
 * término sensible y salía limpio —una excepción que borra texto desarma la
 * regla que la contiene—. El segundo lo cambió por anclas y contexto por
 * frase, y volvió a abrir cinco peticiones reales, porque una lista blanca de
 * PALABRAS no puede distinguir «la fecha de vencimiento de tu membresía es el
 * 30» de «dime la fecha de vencimiento de tu plan»: comparten todo el
 * vocabulario y son cosas opuestas.
 *
 * Por eso este archivo prueba POLARIDAD, no términos. Cada frase de abajo está
 * emparejada con su gemela de signo contrario —mismo léxico, intención
 * inversa—, y esa es la prueba: el guard tiene que separar el par. Si alguien
 * vuelve a resolverlo con una lista de palabras, los dos miembros del par
 * caerán del mismo lado y esta prueba lo dirá.
 *
 * @see OutboundContentGuard::containsCardDataRequest() para el criterio.
 */
class OutboundCardDataGuardTest extends TestCase
{
    /**
     * Petición de credenciales de pago. Ninguna puede salir al cliente.
     *
     * @return array<string, array{0:string}>
     */
    public static function pideCredenciales(): array
    {
        return [
            // Imperativo y «me + verbo» con la tarjeta nombrada.
            'numero de tarjeta y fecha' => ['Me confirmas tu número de tarjeta y la fecha?'],
            'dieciseis digitos' => ['Mándame los 16 dígitos por aquí.'],
            'digitos del reverso' => ['Escríbeme el código de tres dígitos del reverso.'],
            'clave dinamica' => ['Necesito tu clave dinámica para procesar el pago.'],
            'dictame la tarjeta' => ['Dictame la tarjeta para cobrarte.'],
            'token de la app' => ['Necesito el token de tu app bancaria.'],
            'cvv' => ['Pásame el CVV de la tarjeta.'],
            'otp' => ['Dime el OTP que te llegó por SMS.'],
            'clave del banco' => ['Necesito la clave del banco para entrar.'],
            'pin de la tarjeta' => ['Dame el PIN de tu tarjeta débito.'],
            'codigo que llego' => ['Escríbeme el código que te llegó al celular.'],
            'tarjeta completa' => ['Mándame el número de tu tarjeta, la fecha de vencimiento y el CVV.'],

            // El término del gimnasio metido DENTRO de la petición real. Es el
            // caso que el ciclo 1 desarmaba borrando «pin de acceso»: la
            // excepción tiene que ir pegada al SINTAGMA («pin del torniquete»),
            // no al hecho de que la frase diga «acceso» en alguna parte.
            'pin de acceso de la tarjeta debito' => ['Necesito el pin de acceso de tu tarjeta débito para cobrarte.'],
            'pin de acceso de la tarjeta' => ['Necesito el pin de acceso de tu tarjeta.'],
            'pin de ingreso de la tarjeta' => ['Requiero el pin de ingreso de la tarjeta para procesar el cobro.'],
            'codigo de seguridad de acceso de la tarjeta' => ['Me das el código de seguridad de acceso de tu tarjeta?'],
            'datos de la tarjeta de socio del banco' => ['Dime los datos de tu tarjeta de socio del banco.'],

            // Cosecha sin nombrar la tarjeta: el vencimiento pedido es media
            // tarjeta y la otra mitad la pide el mensaje siguiente. Hablar de
            // la membresía NO lo salva: el CRM ya sabe cuándo vence el plan, así
            // que preguntarlo no es informar, es cosechar.
            'vencimiento y ultimos digitos' => ['Me confirmas la fecha de vencimiento y los últimos dígitos?'],
            'vencimiento a secas' => ['Me confirmas la fecha de vencimiento por favor?'],
            'vencimiento suelto' => ['Y la fecha de vencimiento cuál es?'],
            'vencimiento con coartada de renovacion' => ['Para renovar tu membresía, dime la fecha de vencimiento.'],
            'vencimiento del plan preguntado' => ['¿Me confirmas la fecha de vencimiento de tu plan?'],

            // «Tarjeta de X» del gimnasio usada de coartada para cobrar. Solo
            // «tarjeta de acceso» es un objeto del gimnasio; «de membresía»,
            // «de socio» y «de ingreso» son el disfraz por el que se coló el
            // ciclo 2.
            'tarjeta de membresia para cobrar' => ['Necesito los datos de tu tarjeta de membresía para cobrarte.'],
            'tarjeta de ingreso' => ['Dame el número de tu tarjeta de ingreso.'],
        ];
    }

    /**
     * Los huecos que el ciclo 2 dejó abiertos: la petición dicha como se dice
     * en Colombia —pregunta sin signo de apertura y «me + verbo en presente»—
     * y el dato nombrado con un sinónimo («el dato», «la numeración»).
     *
     * @return array<string, array{0:string}>
     */
    public static function huecosDelCicloAnterior(): array
    {
        return [
            'el dato de la tarjeta' => ['Me das el dato de tu tarjeta para cobrarte?'],
            'la numeracion de la tarjeta' => ['Necesito la numeración de tu tarjeta para el cobro.'],
            'me dictas la tarjeta' => ['¿Me dictas la tarjeta para cobrarte?'],
            'me lees la tarjeta sin signos' => ['Me lees la tarjeta por favor'],
            'necesito que me digas la tarjeta' => ['Necesito que me digas la tarjeta para cobrarte.'],
            'vencimiento de la tarjeta' => ['Me confirmas el vencimiento de la tarjeta?'],
            'tarjeta de socio para registrar' => ['Pásame el número de la tarjeta de socio para registrarte.'],
        ];
    }

    /**
     * El gimnasio hablando de lo suyo. Mismo léxico, polaridad contraria: aquí
     * se AFIRMA (o se pide un objeto físico de la sede), y eso tiene que poder
     * decirse. Bloquearlas fue el fallo del ciclo 0.
     *
     * @return array<string, array{0:string}>
     */
    public static function afirmacionesDelGimnasio(): array
    {
        return [
            'vencimiento del plan' => ['Tu plan tiene fecha de vencimiento el 15 de octubre.'],
            'vencimiento de la membresia' => ['La fecha de vencimiento de tu membresía es el 30.'],
            'pin del torniquete' => ['Te doy el PIN del torniquete para entrar.'],
            'tarjeta de acceso' => ['Trae los datos de tu tarjeta de acceso para activarla.'],
            'codigo de la puerta' => ['El código de seguridad de la puerta lo da recepción.'],
            'pin del torniquete con su numero' => ['Tu PIN es 4821 y sirve para el torniquete.'],
            'documento de diez digitos' => ['Tu documento son 10 dígitos.'],
            'documento tiene diez digitos' => ['El documento tiene 10 dígitos.'],
            'codigo de la puerta con sus digitos' => ['El código de la puerta tiene 4 dígitos y lo cambian cada mes.'],
            'clave del casillero' => ['La clave del casillero son 3 dígitos.'],
            'pin al correo' => ['Tu PIN llegará al correo que registraste.'],
            'sesiones y no digitos' => ['El plan tiene 16 sesiones, no 16 dígitos.'],
            'referencia de pago' => ['Tu referencia de pago son los últimos dígitos del recibo.'],
        ];
    }

    #[DataProvider('pideCredenciales')]
    #[DataProvider('huecosDelCicloAnterior')]
    public function test_asking_the_person_for_a_credential_is_blocked(string $texto): void
    {
        $this->assertTrue(
            OutboundContentGuard::containsCardDataRequest($texto),
            "Sale al cliente pidiéndole una credencial: «{$texto}»",
        );
    }

    #[DataProvider('afirmacionesDelGimnasio')]
    public function test_the_gym_stating_its_own_facts_is_not_a_request(string $texto): void
    {
        $this->assertFalse(
            OutboundContentGuard::containsCardDataRequest($texto),
            "El gimnasio no puede decir lo suyo: «{$texto}»",
        );
    }

    /**
     * Una frase legítima no le abre la puerta a la petición que viene detrás.
     *
     * El análisis es por FRASE justamente por esto: si el contexto del gimnasio
     * valiera para todo el mensaje, bastaría con saludar hablando del
     * torniquete para poder pedir la tarjeta en el punto siguiente.
     */
    public function test_a_gym_sentence_does_not_whitewash_the_request_that_follows(): void
    {
        $this->assertTrue(OutboundContentGuard::containsCardDataRequest(
            'Tu PIN del torniquete es 4821. Ahora mándame el número de tu tarjeta para el cobro.'
        ));
    }

    /**
     * Y al revés: una petición bloqueada no contamina las frases legítimas que
     * la rodean. Se comprueba aquí porque el corpus va frase a frase y no diría
     * nada del mensaje entero.
     */
    public function test_the_verdict_is_per_sentence_in_both_directions(): void
    {
        $this->assertFalse(OutboundContentGuard::containsCardDataRequest(
            'Tu plan vence el 30. El PIN del torniquete es 4821. Te espero en recepción.'
        ));
    }

    /**
     * La excepción del gimnasio se ata al sintagma y CAE si la frase cobra.
     *
     * «Tarjeta de acceso» es un objeto de la sede y se pide de verdad; la misma
     * tarjeta invocada para cobrar ya no es el torniquete. Este par es el que
     * separa la excepción legítima de la coartada.
     */
    public function test_the_physical_gym_object_stops_being_an_excuse_when_the_sentence_charges(): void
    {
        $this->assertFalse(OutboundContentGuard::containsCardDataRequest('Tráeme tu tarjeta de acceso para activarla.'));
        $this->assertTrue(OutboundContentGuard::containsCardDataRequest('Tráeme tu tarjeta de acceso para cobrarte.'));
    }
}
