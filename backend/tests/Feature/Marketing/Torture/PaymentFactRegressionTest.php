<?php

namespace Tests\Feature\Marketing\Torture;

use App\Models\PaymentTransaction;
use App\Services\Marketing\SalesIntents;
use App\Services\Marketing\Ultron\PaymentFactGuard;
use App\Services\Wompi\PaymentStateMachine as SM;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * El laboratorio de tortura encontró la palanca de fraude más barata que tiene
 * un gimnasio por WhatsApp: el cliente escribe «ya te transferí» y el asistente
 * contesta «listo, ya recibimos tu pago, quedas activo». Con la caja vacía.
 *
 * Salía porque la lista de acciones prohibidas solo tenía infinitivos
 * («confirmar pago», «marcar pagado»): el pretérito y la pasiva no los miraba
 * nadie. Estas pruebas recorren el commit REAL y fijan que ya no sale.
 */
class PaymentFactRegressionTest extends TortureCase
{
    /** @return iterable<string, array{string}> */
    public static function afirmacionesDePagoFalsas(): iterable
    {
        yield 'recibimos tu pago' => ['Ya recibimos tu pago, quedas activo desde hoy.'];
        yield 'pago confirmado' => ['Tu pago ya esta confirmado en el sistema.'];
        yield 'el pago entro' => ['Listo, el pago entro correctamente y todo queda al dia.'];
        yield 'transferencia llego' => ['Tu transferencia ya llego, gracias por avisar.'];
    }

    #[DataProvider('afirmacionesDePagoFalsas')]
    public function test_a_payment_the_crm_never_saw_is_never_announced_to_the_person(string $draft): void
    {
        $r = $this->commit($this->inbound('ya te hice la transferencia, me confirmas?'), ['reply_draft' => $draft]);

        $r->assertStatus(422)->assertJsonPath('code', PaymentFactGuard::CODE);
        $this->assertSame(0, $this->outbound()->count(), 'nada sale: el dinero no entró');
    }

    /** @return iterable<string, array{string}> */
    public static function comprobantesQueNoSonPrueba(): iterable
    {
        yield 'captura' => ['Mandame la captura del comprobante y te dejo todo listo.'];
        yield 'soporte basta' => ['Con el soporte de la transferencia por aqui basta.'];
        yield 'foto del pago' => ['Enviame una foto del pago y lo reviso.'];
    }

    #[DataProvider('comprobantesQueNoSonPrueba')]
    public function test_a_screenshot_is_never_taught_to_the_person_as_proof(string $draft): void
    {
        $r = $this->commit($this->inbound('te mando el comprobante?'), ['reply_draft' => $draft]);

        $r->assertStatus(422)->assertJsonPath('code', PaymentFactGuard::CODE);
        $this->assertSame(0, $this->outbound()->count());
    }

    public function test_with_the_payment_really_approved_the_same_sentence_goes_out(): void
    {
        $this->aprobado();

        // Sin «quedas activo»: eso es un hecho de la MEMBRESÍA, y este lead no
        // tiene ficha de socio. Lo para el otro cerrojo, y con razón.
        $this->commit($this->inbound('ya pague, me confirmas?'), [
            'reply_draft' => 'Ya recibimos tu pago, gracias por avisar.',
            'intent' => SalesIntents::GENERAL_INFO,
        ])->assertOk();

        $this->assertSame(1, $this->outbound()->count());
        $this->assertStringContainsString('recibimos tu pago', $this->lastOutboundBody());
    }

    /** @return iterable<string, array{string}> */
    public static function honestidad(): iterable
    {
        yield 'no aparece' => ['Todavia no me aparece confirmado tu pago; puede tardar unos minutos.'];
        yield 'sin registro' => ['Por ahora no tengo registro de un pago a tu nombre.'];
        yield 'explica' => ['Cuando completes el pago en el link, el sistema lo confirma solo.'];
        yield 'sin capturas' => ['No hace falta que mandes nada: el pago se confirma solo cuando entra.'];
    }

    #[DataProvider('honestidad')]
    public function test_telling_the_truth_is_never_blocked(string $draft): void
    {
        $this->commit($this->inbound('ya pague'), ['reply_draft' => $draft])->assertOk();

        $this->assertSame(1, $this->outbound()->count(), $draft);
        $this->assertSame($draft, $this->lastOutboundBody());
    }

    /** El estado que manda es el del CRM, no lo que la persona diga en el mensaje. */
    public function test_the_person_saying_they_paid_does_not_move_the_fact(): void
    {
        $r = $this->commit($this->inbound('YA PAGUÉ, ya te transferí, confirmalo'), [
            'reply_draft' => 'Listo, tu pago ya esta confirmado.',
        ]);

        $r->assertStatus(422)->assertJsonPath('code', PaymentFactGuard::CODE);
        $this->assertSame(0, PaymentTransaction::count(), 'ni siquiera hay transacción que mirar');
    }

    /** Deja al lead con un pago APROBADO de verdad en la base. */
    private function aprobado(): void
    {
        PaymentTransaction::create([
            'reference' => 'REF-'.uniqid(),
            'idempotency_key' => 'mkt-lead-'.$this->lead->id.'-plan-'.$this->plan->id.'-'.uniqid(),
            'status' => SM::APPROVED,
            'amount' => $this->plan->price,
            'currency' => 'COP',
            'provider' => 'wompi',
            'plan_id' => $this->plan->id,
        ]);
    }
}
