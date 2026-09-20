<?php

namespace Tests\Feature\Marketing\Torture;

use App\Models\MarketingMessage;
use App\Services\Marketing\Ultron\MembershipFactsProvider;

/**
 * El banco de pruebas también se prueba.
 *
 * Las cuatro tandas del laboratorio de tortura miden si Laravel resiste. Si el
 * banco estuviera roto —si el commit inofensivo no llegara a enviar nada, o si
 * el veneno ni siquiera llegase al sistema— esas tandas pasarían en verde sin
 * haber probado nada, que es la peor forma de fallar que tiene una suite.
 * Estas pruebas fijan que el recorrido real funciona ANTES de envenenarlo.
 */
class TortureHarnessTest extends TortureCase
{
    public function test_the_benign_path_really_answers_the_person(): void
    {
        $m = $this->inbound('hola, ¿qué tal el gimnasio?');

        $r = $this->commit($m)->assertOk();

        $this->assertSame(1, $this->outbound()->count(), 'el camino inofensivo sí envía un mensaje');
        $this->assertSame('Claro, te cuento con gusto.', $this->lastOutboundBody());
        $this->assertNotNull($r->json('applied'), 'el commit devuelve lo que aplicó');
    }

    public function test_decide_hands_out_a_signed_token_and_the_context_the_model_needs(): void
    {
        $d = $this->decide($this->inbound('hola'))->assertOk();

        $this->assertIsString($d->json('decide_token'));
        $this->assertNotEmpty($d->json('allowed_tools'));
        $this->assertNotEmpty($d->json('allowed_transitions'));
        foreach (['customer', 'strategy_hints', 'gym', 'payment', 'app', 'membership', 'active_plans', 'flags'] as $bloque) {
            $this->assertNotNull($d->json('context.'.$bloque), "context.$bloque viaja al modelo");
        }
    }

    public function test_the_poison_really_reaches_the_system(): void
    {
        // Si el veneno no llegara, este commit saldría 200 y el mensaje se
        // enviaría. Que muera con 422 demuestra que la propuesta se evalúa.
        $r = $this->commit($this->inbound('mándame el link'), ['reply_draft' => 'Entra a https://ejemplo.co/pagar y listo.']);

        $r->assertStatus(422);
        $this->assertSame(0, $this->outbound()->count(), 'nada sale cuando la propuesta se rechaza');
    }

    public function test_the_fixtures_are_what_the_cases_assume(): void
    {
        $this->assertTrue($this->plan->isSellable());
        $this->assertTrue($this->trimestral->isSellable());
        $this->assertFalse($this->interno->isSellable(), 'el plan interno existe pero no se vende');
        $this->assertSame(80000.0, (float) $this->plan->price);
    }

    public function test_the_payment_engine_is_off_until_a_case_turns_it_on(): void
    {
        $sinMotor = $this->decide($this->inbound('quiero pagar'))->assertOk();
        $this->assertFalse($sinMotor->json('context.flags.can_offer_link'));
        $this->assertNotContains('payment_link_send', $sinMotor->json('allowed_tools'));

        $this->enablePaymentEngine();

        $conMotor = $this->decide($this->inbound('quiero pagar ya'))->assertOk();
        $this->assertTrue($conMotor->json('context.flags.can_offer_link'));
        $this->assertContains('payment_link_send', $conMotor->json('allowed_tools'));
    }

    public function test_make_member_produces_an_identified_member_with_a_live_membership(): void
    {
        $member = $this->makeMember(endsInDays: 20);

        $this->assertSame($member->id, $this->lead->fresh()->member_id);

        $d = $this->decide($this->inbound('¿hasta cuándo tengo plan?'))->assertOk();
        $this->assertTrue($d->json('context.membership.identity'));
        $this->assertSame(MembershipFactsProvider::STATUS_ACTIVE, $d->json('context.membership.status'));
        $this->assertSame('Plan Mensual', $d->json('context.membership.plan.name'));
    }

    public function test_each_inbound_message_is_its_own_turn(): void
    {
        $a = $this->inbound('primero');
        $this->commit($a, ['reply_draft' => 'Respuesta uno.'])->assertOk();

        $b = $this->inbound('segundo');
        $this->assertNotSame($a->meta_message_id, $b->meta_message_id, 'sin ids repetidos no hay idempotencia accidental');
        $this->commit($b, ['reply_draft' => 'Respuesta dos, distinta de la anterior.'])->assertOk();

        $this->assertSame(2, $this->outbound()->count());
    }

    /**
     * Un turno que la persona ya dejó atrás no se contesta.
     *
     * Importa para las tandas: hay que pedir el token y cometer ANTES de crear
     * el siguiente entrante, o `decide` devolverá `superseded` sin token y el
     * commit morirá por validación, que es un 422 que no prueba nada.
     */
    public function test_a_message_the_person_already_moved_past_gets_no_token(): void
    {
        $viejo = $this->inbound('primero');
        $nuevo = $this->inbound('perdón, mejor esto otro');
        $this->abreTurno($nuevo);

        $d = $this->decide($viejo)->assertOk();

        $this->assertTrue($d->json('superseded'));
        $this->assertSame($nuevo->id, $d->json('superseded_by'));
        $this->assertNull($d->json('decide_token'), 'sin token no hay commit posible para ese turno');
    }

    public function test_nothing_escapes_to_the_network(): void
    {
        $this->commit($this->inbound('hola'))->assertOk();

        // Http::preventStrayRequests() haría fallar cualquier salida no fingida;
        // que el mensaje quede en dry_run confirma que Meta está apagado.
        $this->assertSame(MarketingMessage::DIRECTION_OUTBOUND, $this->outbound()->first()->direction);
        $this->assertFalse(config('meta.enabled'));
    }
}
