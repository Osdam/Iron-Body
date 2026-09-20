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

        /*
         * Condicionales: describen el proceso, no afirman que el dinero entró.
         * Los puso un turno perdido del canario físico — el borrador decía «una
         * vez confirmado el pago, se activa tu membresía», este guard lo leyó
         * como «el pago llegó», el commit devolvió 422 y la persona se quedó
         * esperando en silencio.
         */
        yield 'una vez' => ['Una vez confirmado el pago, se activa tu membresia.'];
        yield 'cuando' => ['Cuando se confirme el pago activamos tu membresia.'];
        yield 'apenas' => ['Apenas recibamos el pago te aviso por aqui.'];
        yield 'si condicional' => ['Si el pago se confirma hoy, quedas activo hoy mismo.'];
        yield 'despues de que' => ['Despues de que el pago se confirme, te llega el acceso.'];
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

    /**
     * La trampa que me metí yo al añadir la salida condicional: normalizado,
     * el «sí» de afirmar y el «si» de condicionar son la misma palabra. Una
     * afirmación de que el dinero entró sigue bloqueada, la anteceda lo que la
     * anteceda.
     */
    #[DataProvider('afirmacionesConSi')]
    public function test_a_yes_in_front_does_not_turn_a_lie_into_a_condition(string $draft): void
    {
        $this->assertNotNull(
            (new PaymentFactGuard)->contradiction($draft, 'none'),
            $draft,
        );
    }

    public static function afirmacionesConSi(): iterable
    {
        yield 'si con coma' => ['Si, ya recibimos tu pago.'];
        yield 'si sin coma' => ['Si ya recibimos tu pago, quedas activo.'];
        yield 'si claro' => ['Si claro, tu pago fue confirmado.'];
    }

    // ── La coordinada que desarmaba el guard ─────────────────────────────────

    /**
     * EL AGUJERO QUE ENCONTRÓ LA REVISIÓN.
     *
     * La exención condicional valía para la frase ENTERA, y la frase sólo se
     * partía por `.!?\n`. Así que bastaba rematar con una coordinada temporal
     * para que el guard dejara pasar lo que iba delante: una afirmación de que
     * el dinero entró, o la enseñanza de que una captura vale como prueba.
     */
    public static function coordinadasQueDesarmaban(): array
    {
        return [
            'afirma y luego condiciona' => ['Ya recibimos tu pago, cuando vengas te damos el carnet.', PaymentFactGuard::REASON_CLAIMS_PAID],
            'afirma en pasiva' => ['Tu pago fue confirmado, cuando quieras pasas por recepcion.', PaymentFactGuard::REASON_CLAIMS_PAID],
            'sin coma, con y' => ['Ya recibimos tu pago y cuando vengas te damos el carnet.', PaymentFactGuard::REASON_CLAIMS_PAID],
            'condiciona y luego afirma' => ['Cuando vengas te explico, ya recibimos tu pago.', PaymentFactGuard::REASON_CLAIMS_PAID],
            'captura con cuando detrás' => ['Mandame la captura del pago cuando puedas.', PaymentFactGuard::REASON_ACCEPTS_RECEIPT],
            'comprobante con apenas delante' => ['Apenas me envies el comprobante del pago te activo el plan.', PaymentFactGuard::REASON_ACCEPTS_RECEIPT],
        ];
    }

    #[DataProvider('coordinadasQueDesarmaban')]
    public function test_a_subordinate_clause_does_not_disarm_what_comes_before_it(string $texto, string $motivo): void
    {
        $r = $this->guard->contradiction($texto, 'none');

        $this->assertNotNull($r, 'una subordinada no puede eximir la afirmación entera');
        $this->assertSame($motivo, $r['reason']);
    }

    /** Y lo legítimo sigue pasando: la subordinada sola habla del futuro. */
    public static function condicionalesLegitimas(): array
    {
        return [
            'el turno perdido del canario' => ['Debes hacer el pago de forma manual; una vez confirmado el pago, se activa tu membresia.'],
            'protasis y apodosis' => ['Cuando se confirme el pago, tu membresia queda activa.'],
            'si con sujeto' => ['Si el pago se confirma hoy, entrenas manana mismo.'],
            'apenas en futuro' => ['Apenas el pago quede confirmado en el sistema te aviso.'],
            'la copia de pago real' => ['El pago lo haces tu mismo desde la app Iron Body Workout.'],
        ];
    }

    #[DataProvider('condicionalesLegitimas')]
    public function test_a_real_conditional_still_goes_through(string $texto): void
    {
        $this->assertNull($this->guard->contradiction($texto, 'none'), 'esto describe el proceso, no inventa un pago');
    }

    /** Con el pago aprobado de verdad, afirmarlo es decir la verdad. */
    public function test_with_the_payment_approved_the_claim_is_true(): void
    {
        $this->assertNull(
            $this->guard->contradiction('Ya recibimos tu pago, cuando vengas te damos el carnet.', PaymentFactGuard::APPROVED),
        );
    }

    // ── La misma mentira, con las cláusulas al revés ─────────────────────────

    /**
     * LA PUERTA DE ATRÁS DEL ARREGLO ANTERIOR.
     *
     * Juzgar sólo lo que va DELANTE de la conjunción cerró el agujero de la
     * coordinada, y abrió el simétrico: lo que va DETRÁS no lo miraba nadie.
     * «Cuando vengas te damos el carnet, tu pago fue confirmado» es exactamente
     * la misma mentira con las cláusulas cambiadas de orden.
     *
     * La regla no es de posición, es de GOBIERNO: una afirmación sólo es
     * hipotética si cuelga de la conjunción —pegada a ella, sin coma de por
     * medio—. Y «mientras tanto» no es una subordinada, es un conector de
     * discurso: no gobierna nada.
     */
    public static function afirmacionesEnLaCola(): array
    {
        return [
            'mientras tanto con coma' => ['Mientras tanto, tu pago quedo registrado.'],
            'mientras tanto sin coma' => ['Mientras tanto tu pago quedo registrado.'],
            'cuando delante, afirma detrás' => ['Cuando vengas te damos el carnet, tu pago fue confirmado.'],
            'en cuanto delante' => ['En cuanto llegues pregunta por recepcion, pago confirmado.'],
            'apenas delante' => ['Apenas abras la app veras tu plan, recibimos tu pago.'],
            'apenas delante sin coma' => ['Apenas abras la app veras tu plan recibimos tu pago.'],
            'una vez que delante' => ['Una vez que entres al gimnasio preguntas por mi, tu pago esta confirmado.'],
        ];
    }

    #[DataProvider('afirmacionesEnLaCola')]
    public function test_a_claim_that_does_not_hang_from_the_conjunction_is_still_a_claim(string $texto): void
    {
        $r = $this->guard->contradiction($texto, 'none');

        $this->assertNotNull($r, 'la conjunción de delante no vuelve hipotético lo que viene detrás');
        $this->assertSame(PaymentFactGuard::REASON_CLAIMS_PAID, $r['reason']);
    }

    /**
     * Y una afirmación no cruza una cláusula.
     *
     * Sin acotar las ventanas de los patrones, «debes hacer el pago de forma
     * manual; una vez confirmado el pago…» casaba de «el pago» de la PRIMERA
     * cláusula a «confirmado» de la SEGUNDA: el guard veía una afirmación que
     * nadie escribió y mataba el turno honrado del canario.
     */
    public function test_a_claim_does_not_span_two_clauses(): void
    {
        $this->assertNull($this->guard->contradiction(
            'Debes hacer el pago de forma manual; una vez confirmado el pago, se activa tu membresia.',
            'none',
        ));
    }

    // ── La tercera puerta: la negación que no niega nada ─────────────────────

    /**
     * «No te preocupes» es frase de catálogo de cualquier modelo comercial, y
     * bastaba con que apareciera para eximir la frase entera. Era la misma
     * forma del agujero que ya se cerró dos veces —mirar la frase y no la
     * afirmación— pero en la salida de al lado, y la más probable de las tres.
     *
     * La negación en español gobierna su cláusula: tiene que ir DELANTE de lo
     * que niega y sin una coma en medio.
     */
    public static function negacionesQueNoNiegan(): array
    {
        return [
            'no te preocupes detrás' => ['Ya recibimos tu pago, no te preocupes.', PaymentFactGuard::REASON_CLAIMS_PAID],
            'no hay problema detrás' => ['Tu pago fue confirmado, no hay problema.', PaymentFactGuard::REASON_CLAIMS_PAID],
            'coordinada con no' => ['Ya recibimos tu pago y no debes nada.', PaymentFactGuard::REASON_CLAIMS_PAID],
            'captura con no detrás' => ['Mandame la captura del pago, no hay problema.', PaymentFactGuard::REASON_ACCEPTS_RECEIPT],
        ];
    }

    #[DataProvider('negacionesQueNoNiegan')]
    public function test_a_negation_that_does_not_govern_the_claim_does_not_excuse_it(string $texto, string $motivo): void
    {
        $r = $this->guard->contradiction($texto, 'none');

        $this->assertNotNull($r, 'un «no» detrás no desmiente lo que ya se afirmó');
        $this->assertSame($motivo, $r['reason']);
    }

    /** Y la honestidad, que es lo que el sistema quiere poder decir, sigue pasando. */
    public static function negacionesDeVerdad(): array
    {
        return [
            ['Todavia no me aparece tu pago confirmado.'],
            ['Aun no hemos recibido tu pago.'],
            ['No me aparece tu pago, todavia no lo veo confirmado.'],
            ['No hace falta que me mandes la captura del pago.'],
            ['Hasta que no llegue tu pago no puedo activarte el plan.'],
        ];
    }

    #[DataProvider('negacionesDeVerdad')]
    public function test_an_honest_negation_still_goes_through(string $texto): void
    {
        $this->assertNull($this->guard->contradiction($texto, 'none'));
    }

    // ── El inciso entre comas no parte la afirmación ─────────────────────────

    /**
     * Excluir la coma de las ventanas de los patrones —para que una afirmación
     * no cruzara dos cláusulas— dejó pasar la misma mentira con una aclaración
     * en medio. El punto y coma basta para lo que motivó aquello.
     */
    public static function afirmacionesConInciso(): array
    {
        return [
            ['Ya recibimos, efectivamente, tu pago.'],
            ['Tu pago, como te decia, ya fue confirmado.'],
            ['Tu pago, el de ayer, quedo registrado.'],
            ['Tu transferencia, la de Nequi, entro bien.'],
        ];
    }

    #[DataProvider('afirmacionesConInciso')]
    public function test_an_aside_between_commas_does_not_split_the_claim(string $texto): void
    {
        $this->assertNotNull($this->guard->contradiction($texto, 'none'));
    }

    // ── Colgar de la conjunción no basta si el verbo va en pasado ────────────

    public static function subordinadasEnPasado(): array
    {
        return [
            ['Cuando tu pago fue confirmado te enviamos el carnet.'],
            ['Cuando tu pago quedo registrado ayer te llego el correo.'],
        ];
    }

    #[DataProvider('subordinadasEnPasado')]
    public function test_hanging_from_a_conjunction_in_past_indicative_is_still_a_claim(string $texto): void
    {
        $this->assertNotNull(
            $this->guard->contradiction($texto, 'none'),
            'el pasado de indicativo afirma, cuelgue de donde cuelgue',
        );
    }
}
