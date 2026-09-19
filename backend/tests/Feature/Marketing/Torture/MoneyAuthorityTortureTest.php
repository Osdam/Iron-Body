<?php

namespace Tests\Feature\Marketing\Torture;

use App\Models\MarketingLead;
use App\Models\PaymentTransaction;
use App\Services\Marketing\CommercialPhaseMachine as P;
use App\Services\Marketing\OutboundContentGuard;
use App\Services\Marketing\SalesIntents;
use App\Services\Marketing\Ultron\MembershipFactGuard;
use App\Services\Marketing\Ultron\PaymentFactGuard;
use App\Services\Wompi\PaymentStateMachine as SM;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * TANDA 1 DEL LABORATORIO DE TORTURA: EL DINERO.
 *
 * La regla que se defiende aquí es una sola: **el modelo no toca el dinero**.
 * Ni la cifra que se dice, ni el plan que se cotiza, ni el monto que se cobra,
 * ni si llega a haber un cobro. n8n propone; el precio sale de `Plan::price`
 * leído en el momento de escribir, y el cobro lo decide Laravel.
 *
 * Cada escenario afirma DOS cosas, porque una sola no prueba nada:
 *
 *   1. qué contestó el endpoint (código HTTP y `code`/`blocked_reason`), y
 *   2. qué pasó de verdad al otro lado: cuántos mensajes salieron, qué decían,
 *      y qué quedó —o no quedó— en `payment_transactions`.
 *
 * Un 422 con un mensaje ya enviado sería un fallo igual de grave que un 200.
 *
 * ── HALLAZGOS ABIERTOS ───────────────────────────────────────────────────────
 * Varias pruebas de este archivo fijan comportamiento que NO es el deseable.
 * Están marcadas con `HALLAZGO Hn` y reportadas al lead; documentan lo que hoy
 * ocurre para que el día que se corrija la corrección se note aquí en rojo y el
 * escenario se invierta a propósito, en vez de pasar en silencio.
 */
class MoneyAuthorityTortureTest extends TortureCase
{
    /** Lo que Laravel escribe cuando sustituye {{PLAN_PRICE}} del Plan Mensual. */
    private const PRECIO_MENSUAL = '$80.000 COP';

    // ═══════════════════════════════════════════════════════════════════════
    // FAMILIA 1 · La cifra la escribe el modelo
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Un precio se escribe de muchas formas en Colombia, y todas valen igual
     * para quien lo lee. La cifra correcta del plan también cuenta: que
     * coincida con el catálogo hoy no la vuelve autoritativa, porque la escribió
     * el modelo y mañana el catálogo cambia.
     *
     * @return array<string, array{0:string}>
     */
    public static function preciosEscritosPorElModelo(): array
    {
        return [
            'cifra pelada' => ['El plan cuesta 80000 al mes.'],
            'miles con punto' => ['El plan cuesta 80.000 al mes.'],
            'miles con coma' => ['El plan cuesta 80,000 al mes.'],
            'con signo pegado' => ['El plan cuesta $80.000 al mes.'],
            'con signo separado' => ['El plan cuesta $ 80.000 al mes.'],
            'con codigo de moneda' => ['El plan cuesta COP 80000 al mes.'],
            'con la palabra pesos' => ['El plan cuesta 80.000 pesos al mes.'],
            'en letras con la palabra pesos' => ['El plan cuesta ochenta mil pesos al mes.'],
            'cifra inventada' => ['Te lo dejo en 65.000 este mes.'],
            'cifra de otro orden' => ['El anual sale en 1.500.000 y es la mejor opcion.'],
            'cifra escondida en una frase larga' => [
                'Te cuento: el acompanamiento es completo, incluye valoracion inicial, '
                .'rutina personalizada y seguimiento, y con todo eso la mensualidad queda en 80.000.',
            ],
        ];
    }

    #[DataProvider('preciosEscritosPorElModelo')]
    public function test_a_price_written_by_the_model_never_reaches_the_person(string $draft): void
    {
        $r = $this->commit($this->inbound('cuanto vale el gimnasio?'), ['reply_draft' => $draft]);

        $r->assertStatus(422);
        $this->assertSame(OutboundContentGuard::CODE_INVENTED_PRICE, $r->json('code'));
        $this->assertSame(0, $this->outbound()->count(), 'la cifra del modelo no puede llegar a nadie');
        $this->assertSame(0, PaymentTransaction::count(), 'un borrador rechazado no cobra nada');
    }

    /**
     * H1, CERRADO. El patrón de precios miraba dígitos, no dinero: un precio
     * dicho en palabras o abreviado salía tal cual. Ahora cuenta como precio.
     *
     * @return array<string, array{0:string}>
     */
    public static function preciosSinDigitosDeMiles(): array
    {
        return [
            'en letras' => ['El plan cuesta ochenta mil al mes.'],
            'cifra corta mas mil' => ['El plan cuesta 80 mil al mes.'],
            'abreviado con k' => ['El plan cuesta 80k al mes.'],
            'en letras e inventado' => ['Te lo puedo dejar en sesenta mil si entras esta semana.'],
        ];
    }

    #[DataProvider('preciosSinDigitosDeMiles')]
    public function test_a_price_the_model_spelled_out_is_still_a_price(string $draft): void
    {
        $r = $this->commit($this->inbound('cuanto vale?'), ['reply_draft' => $draft]);

        $r->assertStatus(422)->assertJsonPath('code', OutboundContentGuard::CODE_INVENTED_PRICE);
        $this->assertSame(0, $this->outbound()->count(), 'la cifra del modelo no llega a nadie');
        $this->assertSame(0, PaymentTransaction::count());
    }

    /**
     * H1 bis, CERRADO. El espacio como separador es ortografía normal, y los
     * dígitos de ancho completo o la O por cero son lo que escribe un modelo al
     * que le pidieron «disimular». Las tres formas cuentan como precio.
     *
     * @return array<string, array{0:string}>
     */
    public static function preciosConSeparadorRaro(): array
    {
        return [
            'espacio como separador' => ['El plan cuesta 80 000 al mes.'],
            'digitos de ancho completo' => ['El plan cuesta ８０.０００ al mes.'],
            'ceros escritos con o' => ['El plan cuesta 8O.OOO al mes.'],
        ];
    }

    #[DataProvider('preciosConSeparadorRaro')]
    public function test_a_price_with_an_unusual_thousands_separator_is_a_price_too(string $draft): void
    {
        $r = $this->commit($this->inbound('cuanto vale?'), ['reply_draft' => $draft]);

        $r->assertStatus(422)->assertJsonPath('code', OutboundContentGuard::CODE_INVENTED_PRICE);
        $this->assertSame(0, $this->outbound()->count());
        $this->assertSame(0, PaymentTransaction::count());
    }

    /**
     * H2, CERRADO. Un descuento es dinero aunque no lleve cifra: la rebaja la
     * declara el negocio, no el modelo.
     *
     * @return array<string, array{0:string}>
     */
    public static function descuentosInventados(): array
    {
        return [
            'porcentaje' => ['Te hago un descuento del 20% si entras hoy.'],
            'mitad del valor' => ['Te dejo la mitad del valor por ser tu primera vez.'],
            'mitad de precio' => ['Por hoy te sale a mitad de precio, sin compromiso.'],
        ];
    }

    #[DataProvider('descuentosInventados')]
    public function test_a_discount_nobody_authorised_never_reaches_the_person(string $draft): void
    {
        $r = $this->commit($this->inbound('me haces un descuento?'), ['reply_draft' => $draft]);

        $r->assertStatus(422)->assertJsonPath('code', OutboundContentGuard::CODE_INVENTED_DISCOUNT);
        $this->assertSame(0, $this->outbound()->count(), 'nadie autorizo rebajar el plan');
        $this->assertSame(0, PaymentTransaction::count());
    }

    // ═══════════════════════════════════════════════════════════════════════
    // FAMILIA 2 · Marcadores: la única forma en que una cifra puede salir
    // ═══════════════════════════════════════════════════════════════════════

    /** @return array<string, array{0:string}> */
    public static function marcadoresInventados(): array
    {
        return [
            'descuento' => ['Te hago {{DESCUENTO}} sobre el valor del plan.'],
            'precio en castellano' => ['El plan cuesta {{PRECIO}} al mes.'],
            'nombre bien escrito en minusculas' => ['El plan cuesta {{plan_price}} al mes.'],
            'nombre parecido al real' => ['El plan cuesta {{PLAN_PRICE_COP}} al mes.'],
        ];
    }

    #[DataProvider('marcadoresInventados')]
    public function test_a_placeholder_the_catalogue_does_not_know_is_refused(string $draft): void
    {
        $r = $this->commit($this->inbound('cuanto vale?'), [
            'reply_draft' => $draft, 'recommended_plan_id' => $this->plan->id,
        ]);

        $r->assertStatus(422);
        $this->assertSame('unknown_placeholder', $r->json('code'));
        $this->assertSame(0, $this->outbound()->count(), 'una plantilla inventada no se sustituye a ciegas ni se envia');
    }

    public function test_a_quote_without_a_plan_is_refused_instead_of_guessed(): void
    {
        $r = $this->commit($this->inbound('cuanto vale?'), [
            'reply_draft' => 'Queda en {{PLAN_PRICE}} al mes.', 'recommended_plan_id' => null,
        ]);

        $r->assertStatus(422);
        $this->assertSame('plan_required_for_placeholder', $r->json('code'));
        $this->assertSame(0, $this->outbound()->count(), 'sin plan no hay precio que poner: no se adivina');
    }

    public function test_the_placeholders_are_filled_from_the_catalogue_row(): void
    {
        $this->commit($this->inbound('que incluye el mensual?'), [
            'reply_draft' => 'El {{PLAN_NAME}} dura {{PLAN_DURATION}} y cuesta {{PLAN_PRICE}}.',
            'recommended_plan_id' => $this->plan->id,
        ])->assertOk();

        $this->assertSame(1, $this->outbound()->count());
        $this->assertSame('El Plan Mensual dura un mes y cuesta '.self::PRECIO_MENSUAL.'.', $this->lastOutboundBody());
    }

    public function test_a_repeated_price_placeholder_is_filled_with_the_same_catalogue_price(): void
    {
        $this->commit($this->inbound('me lo repites?'), [
            'reply_draft' => 'El {{PLAN_NAME}} cuesta {{PLAN_PRICE}}; si, {{PLAN_PRICE}} exactos.',
            'recommended_plan_id' => $this->plan->id,
        ])->assertOk();

        $this->assertSame(
            'El Plan Mensual cuesta '.self::PRECIO_MENSUAL.'; si, '.self::PRECIO_MENSUAL.' exactos.',
            $this->lastOutboundBody(),
            'dos marcadores no son dos precios distintos',
        );
    }

    public function test_a_placeholder_written_with_spaces_is_still_the_catalogue_price(): void
    {
        $this->commit($this->inbound('cuanto vale?'), [
            'reply_draft' => 'El plan cuesta {{ PLAN_PRICE }} al mes.',
            'recommended_plan_id' => $this->plan->id,
        ])->assertOk();

        $this->assertSame('El plan cuesta '.self::PRECIO_MENSUAL.' al mes.', $this->lastOutboundBody());
    }

    /**
     * HALLAZGO H3. Un marcador a medias no es ningún marcador: no lo sustituye
     * nadie, no lo rechaza nadie, y el cliente lee la plantilla rota.
     */
    public function test_a_half_written_placeholder_travels_to_the_person_as_it_is(): void
    {
        $this->commit($this->inbound('cuanto vale?'), [
            'reply_draft' => 'El plan cuesta {{PLAN_PRICE y ya quedas adentro.',
            'recommended_plan_id' => $this->plan->id,
        ])->assertOk();

        $this->assertSame(1, $this->outbound()->count());
        $this->assertSame('El plan cuesta {{PLAN_PRICE y ya quedas adentro.', $this->lastOutboundBody());
    }

    public function test_the_price_is_read_at_commit_time_and_not_at_decide_time(): void
    {
        $m = $this->inbound('cuanto vale?');
        $token = $this->decide($m)->json('decide_token');

        // La tarifa cambia mientras el modelo escribe: sale la de ahora.
        $this->plan->forceFill(['price' => 95000])->save();

        $this->commit($m, [
            'reply_draft' => 'Queda en {{PLAN_PRICE}} al mes.', 'recommended_plan_id' => $this->plan->id,
        ], [], ['decide_token' => $token])->assertOk();

        $this->assertSame('Queda en $95.000 COP al mes.', $this->lastOutboundBody());
    }

    public function test_a_plan_deactivated_after_the_decision_is_not_quoted(): void
    {
        $m = $this->inbound('cuanto vale?');
        $token = $this->decide($m)->json('decide_token');

        $this->plan->forceFill(['active' => false])->save();

        $r = $this->commit($m, [
            'reply_draft' => 'Queda en {{PLAN_PRICE}} al mes.', 'recommended_plan_id' => $this->plan->id,
        ], [], ['decide_token' => $token]);

        $r->assertStatus(422);
        $this->assertSame('plan_inactive', $r->json('code'));
        $this->assertSame(0, $this->outbound()->count(), 'no se recalcula a otro plan en silencio: no se contesta');
    }

    // ═══════════════════════════════════════════════════════════════════════
    // FAMILIA 3 · Qué plan se cotiza
    // ═══════════════════════════════════════════════════════════════════════

    /** @return array<string, array{0:string}> */
    public static function borradoresConYSinCifra(): array
    {
        return [
            'con marcador de precio' => ['El convenio queda en {{PLAN_PRICE}} al mes.'],
            'sin marcador de precio' => ['Con gusto te cuento que incluye ese convenio.'],
        ];
    }

    #[DataProvider('borradoresConYSinCifra')]
    public function test_a_plan_that_is_not_for_sale_is_never_quoted(string $draft): void
    {
        $r = $this->commit($this->inbound('y el convenio interno?'), [
            'reply_draft' => $draft, 'recommended_plan_id' => $this->interno->id,
        ]);

        $r->assertStatus(422);
        $this->assertSame('plan_not_sellable', $r->json('code'));
        $this->assertSame(0, $this->outbound()->count(), 'el plan interno no se cotiza ni aunque el texto no lleve cifra');
        $this->assertSame(0, PaymentTransaction::count());
    }

    public function test_a_plan_id_that_does_not_exist_is_refused(): void
    {
        $r = $this->commit($this->inbound('cuanto vale?'), [
            'reply_draft' => 'Queda en {{PLAN_PRICE}} al mes.', 'recommended_plan_id' => 99999,
        ]);

        $r->assertStatus(422);
        $this->assertSame('plan_not_found', $r->json('code'));
        $this->assertSame(0, $this->outbound()->count());
    }

    /** @return array<string, array{0:mixed}> */
    public static function idsImposibles(): array
    {
        return [
            'cero' => [0],
            'negativo' => [-3],
            'cadena' => ['abc'],
            'decimal' => [1.5],
        ];
    }

    #[DataProvider('idsImposibles')]
    public function test_a_plan_id_outside_the_contract_dies_in_validation(mixed $planId): void
    {
        $r = $this->commit($this->inbound('cuanto vale?'), [
            'reply_draft' => 'Queda en {{PLAN_PRICE}} al mes.', 'recommended_plan_id' => $planId,
        ]);

        $r->assertStatus(422)->assertJsonValidationErrors('proposal.recommended_plan_id');
        $this->assertSame(0, $this->outbound()->count());
    }

    public function test_a_plan_id_that_arrives_as_a_numeric_string_still_quotes_that_plan(): void
    {
        // n8n manda enteros como cadena con más frecuencia de la que parece.
        $this->commit($this->inbound('cuanto vale?'), [
            'reply_draft' => 'Queda en {{PLAN_PRICE}} al mes.',
            'recommended_plan_id' => (string) $this->plan->id,
        ])->assertOk();

        $this->assertSame('Queda en '.self::PRECIO_MENSUAL.' al mes.', $this->lastOutboundBody());
    }

    /**
     * HALLAZGO H4. El precio sale del catálogo, pero el NOMBRE del plan lo
     * escribe el modelo en prosa: nadie comprueba que hablen del mismo plan, y
     * la persona recibe el precio del trimestral atribuido al mensual.
     */
    public function test_the_quoted_price_belongs_to_the_id_and_not_to_the_plan_the_draft_names(): void
    {
        $this->commit($this->inbound('cuanto vale el mensual?'), [
            'reply_draft' => 'El Plan Mensual te queda en {{PLAN_PRICE}} y ya.',
            'recommended_plan_id' => $this->trimestral->id,
        ])->assertOk();

        $this->assertSame('El Plan Mensual te queda en $210.000 COP y ya.', $this->lastOutboundBody());
    }

    public function test_a_turn_without_a_quoted_plan_answers_without_any_price(): void
    {
        $r = $this->commit($this->inbound('que planes tienen?'), [
            'reply_draft' => 'Tenemos varias opciones de plan; te cuento cual te sirve mejor.',
            'recommended_plan_id' => null,
        ])->assertOk();

        $this->assertNull($r->json('price_source'), 'sin plan no hay fuente de precio que reportar');
        $this->assertSame(1, $this->outbound()->count());
        $this->assertStringNotContainsString('$', $this->lastOutboundBody());
    }

    // ═══════════════════════════════════════════════════════════════════════
    // FAMILIA 4 · El link de pago: quién cobra, cuánto y cuántas veces
    // ═══════════════════════════════════════════════════════════════════════

    public function test_with_the_payment_engine_off_the_requested_link_is_dropped(): void
    {
        $r = $this->commit($this->inbound('mandame el link'), [
            'reply_draft' => 'Con gusto, ya te cuento como se paga.',
            'recommended_plan_id' => $this->plan->id,
            'tools_requested' => [SalesIntents::TOOL_PAYMENT_LINK_SEND],
        ])->assertOk();

        $this->assertSame([SalesIntents::TOOL_PAYMENT_LINK_SEND], $r->json('applied.tools_rejected'));
        $this->assertSame([], $r->json('applied.tools_executed'));
        $this->assertSame(0, PaymentTransaction::count(), 'sin motor no se crea un cobro');
        $this->assertSame(0, $this->paymentLinkMessages()->count());
        $this->assertSame(1, $this->outbound()->count(), 'la respuesta sale; el link no');
    }

    /**
     * HALLAZGO H5. Con el motor apagado la herramienta se cae, pero el texto que
     * PROMETE el link sale igual: la persona queda esperando un enlace que nunca
     * se generó.
     */
    public function test_a_link_promised_in_the_draft_goes_out_even_when_no_link_can_exist(): void
    {
        $this->commit($this->inbound('mandame el link'), [
            'reply_draft' => 'Listo, ahora mismo te mando el link de pago.',
            'recommended_plan_id' => $this->plan->id,
            'tools_requested' => [SalesIntents::TOOL_PAYMENT_LINK_SEND],
        ])->assertOk();

        $this->assertSame(1, $this->outbound()->count());
        $this->assertSame('Listo, ahora mismo te mando el link de pago.', $this->lastOutboundBody());
        $this->assertSame(0, $this->paymentLinkMessages()->count(), 'la promesa sale, el link no existe');
    }

    public function test_a_link_for_a_plan_that_is_not_for_sale_never_creates_a_charge(): void
    {
        $this->enablePaymentEngine();

        $r = $this->commit($this->inbound('quiero pagar el convenio'), [
            'reply_draft' => 'Claro, te dejo el medio de pago del convenio.',
            'recommended_plan_id' => $this->interno->id,
            'tools_requested' => [SalesIntents::TOOL_PAYMENT_LINK_SEND],
        ]);

        $r->assertStatus(422);
        $this->assertSame('plan_not_sellable', $r->json('code'));
        $this->assertSame(0, PaymentTransaction::count());
        $this->assertSame(0, $this->outbound()->count());
    }

    public function test_a_link_without_a_plan_charges_nobody(): void
    {
        $this->enablePaymentEngine();

        $r = $this->commit($this->inbound('quiero pagar ya'), [
            'reply_draft' => 'Perfecto, cuentame cual plan quieres y seguimos.',
            'recommended_plan_id' => null,
            'tools_requested' => [SalesIntents::TOOL_PAYMENT_LINK_SEND],
        ])->assertOk();

        $this->assertSame([], $r->json('applied.tools_executed'), 'sin plan no hay nada que cobrar');
        $this->assertSame(0, PaymentTransaction::count());
        $this->assertSame(1, $this->outbound()->count());
        $this->assertSame(0, $this->paymentLinkMessages()->count());
    }

    public function test_the_charged_amount_comes_from_the_catalogue_row(): void
    {
        $this->enablePaymentEngine();

        $r = $this->commit($this->inbound('mandame el link'), [
            'reply_draft' => 'Listo, el {{PLAN_NAME}} queda en {{PLAN_PRICE}}; aqui va tu enlace.',
            'recommended_plan_id' => $this->plan->id,
            'tools_requested' => [SalesIntents::TOOL_PAYMENT_LINK_SEND],
        ])->assertOk();

        $this->assertSame([SalesIntents::TOOL_PAYMENT_LINK_SEND], $r->json('applied.tools_executed'));

        $this->assertSame(1, PaymentTransaction::count());
        $tx = PaymentTransaction::first();
        $this->assertSame(80000.0, $tx->amount, 'el monto sale de Plan::price, nunca de la propuesta');
        $this->assertSame($this->plan->id, (int) $tx->plan_id);
        $this->assertSame('COP', $tx->currency);

        $this->assertSame(2, $this->outbound()->count(), 'la respuesta primero, el link despues');
        $this->assertSame(1, $this->paymentLinkMessages()->count());
        $this->assertStringContainsString(self::PRECIO_MENSUAL, $this->lastOutboundBody());
    }

    public function test_asking_for_the_link_twice_never_opens_a_second_charge(): void
    {
        $this->enablePaymentEngine();

        $this->commit($this->inbound('mandame el link'), [
            'reply_draft' => 'Listo, aqui va tu enlace de pago.',
            'recommended_plan_id' => $this->plan->id,
            'tools_requested' => [SalesIntents::TOOL_PAYMENT_LINK_SEND],
        ])->assertOk();

        $this->commit($this->inbound('no me llego, reenvialo por favor'), [
            'reply_draft' => 'Claro, te lo vuelvo a dejar enseguida por este chat.',
            'recommended_plan_id' => $this->plan->id,
            'tools_requested' => [SalesIntents::TOOL_PAYMENT_LINK_SEND],
        ])->assertOk();

        $this->assertSame(1, PaymentTransaction::count(), 'dos peticiones no son dos cobros');
        $links = $this->paymentLinkMessages()->values();
        $this->assertSame(2, $links->count());
        $this->assertSame($links[0]->body, $links[1]->body, 'se reenvia el MISMO link, no uno nuevo');
    }

    public function test_a_link_for_another_plan_while_one_is_in_flight_is_handed_to_a_person(): void
    {
        $this->enablePaymentEngine();

        $this->commit($this->inbound('mandame el link del mensual'), [
            'reply_draft' => 'Listo, aqui va tu enlace de pago.',
            'recommended_plan_id' => $this->plan->id,
            'tools_requested' => [SalesIntents::TOOL_PAYMENT_LINK_SEND],
        ])->assertOk();

        $r = $this->commit($this->inbound('mejor el trimestral, mandamelo'), [
            'reply_draft' => 'Entiendo, miremos la opcion de tres meses entonces.',
            'recommended_plan_id' => $this->trimestral->id,
            'tools_requested' => [SalesIntents::TOOL_PAYMENT_LINK_SEND],
        ])->assertOk();

        $this->assertSame([], $r->json('applied.tools_executed'), 'dos links vivos serian dos cobros posibles');
        $this->assertSame(1, PaymentTransaction::count());
        $this->assertSame($this->plan->id, (int) PaymentTransaction::first()->plan_id);
        $this->assertSame(1, $this->paymentLinkMessages()->count());
        $this->assertSame('payment_link_plan_change', $this->conversation->fresh()->staff_review_reason);
    }

    public function test_a_lead_who_already_paid_is_not_charged_again(): void
    {
        $this->enablePaymentEngine();
        PaymentTransaction::create([
            'provider' => 'wompi', 'method' => 'web_checkout', 'status' => SM::APPROVED,
            'idempotency_key' => 'mkt-lead-'.$this->lead->id.'-plan-'.$this->plan->id.'-ya-pagado',
            'plan_id' => $this->plan->id, 'amount' => 80000, 'currency' => 'COP',
            'reference' => 'IRON-TORTURA-PAGADO', 'approved_at' => now(),
        ]);

        $r = $this->commit($this->inbound('mandame el link otra vez'), [
            'reply_draft' => 'Con gusto, reviso tu caso y te cuento.',
            'recommended_plan_id' => $this->plan->id,
            'tools_requested' => [SalesIntents::TOOL_PAYMENT_LINK_SEND],
        ])->assertOk();

        $this->assertSame([], $r->json('applied.tools_executed'));
        $this->assertSame(1, PaymentTransaction::count(), 'no se abre un cobro nuevo sobre uno aprobado');
        $this->assertSame(0, $this->paymentLinkMessages()->count());
        $this->assertSame(1, $this->outbound()->count());
    }

    public function test_a_lead_who_asked_not_to_be_written_gets_neither_reply_nor_link(): void
    {
        $this->enablePaymentEngine();
        $m = $this->inbound('mandame el link');
        $token = $this->decide($m)->json('decide_token');

        // El opt-out entra DESPUÉS de decidir: es justo el hueco que el commit
        // tiene que volver a mirar.
        $this->lead->forceFill([
            'do_not_contact' => true, 'consent_status' => MarketingLead::CONSENT_DENIED,
        ])->save();

        $r = $this->commit($m, [
            'reply_draft' => 'Listo, te dejo el enlace de pago.',
            'recommended_plan_id' => $this->plan->id,
            'tools_requested' => [SalesIntents::TOOL_PAYMENT_LINK_SEND],
        ], [], ['decide_token' => $token])->assertOk();

        $this->assertSame('blocked', $r->json('outcome'));
        $this->assertSame('do_not_contact', $r->json('blocked_reason'));
        $this->assertSame(0, $this->outbound()->count());
        $this->assertSame(0, PaymentTransaction::count());
    }

    public function test_the_opt_out_turn_marks_the_lead_and_drops_the_link(): void
    {
        $this->enablePaymentEngine();

        $r = $this->commit($this->inbound('no me escriban mas por favor'), [
            'reply_draft' => 'Entiendo, te dejo el enlace por si cambias de idea.',
            'intent' => SalesIntents::DO_NOT_CONTACT_REQUEST,
            'recommended_plan_id' => $this->plan->id,
            'tools_requested' => [SalesIntents::TOOL_PAYMENT_LINK_SEND],
        ])->assertOk();

        $this->assertSame('no_reply', $r->json('outcome'));
        $this->assertSame(SalesIntents::ACTION_MARK_DNC, $r->json('blocked_reason'));
        $this->assertSame([SalesIntents::TOOL_MARK_DNC], $r->json('applied.tools_executed'));
        $this->assertTrue((bool) $this->lead->fresh()->do_not_contact, 'lo que pidio la persona si se ejecuta');
        $this->assertSame(0, $this->outbound()->count());
        $this->assertSame(0, PaymentTransaction::count());
    }

    /**
     * HALLAZGO H6. El link en vuelo se reutiliza con el monto con el que se
     * creó, pero la respuesta cotiza el precio de AHORA: en el mismo turno salen
     * dos cifras distintas, y se cobra la vieja.
     */
    public function test_a_reused_link_keeps_the_old_amount_while_the_reply_quotes_the_new_price(): void
    {
        $this->enablePaymentEngine();

        $this->commit($this->inbound('mandame el link'), [
            'reply_draft' => 'Listo, quedan {{PLAN_PRICE}}; aqui va tu enlace.',
            'recommended_plan_id' => $this->plan->id,
            'tools_requested' => [SalesIntents::TOOL_PAYMENT_LINK_SEND],
        ])->assertOk();

        $this->plan->forceFill(['price' => 120000])->save();

        $this->commit($this->inbound('se me borro, reenvialo por favor'), [
            'reply_draft' => 'Claro, son {{PLAN_PRICE}} y te lo dejo otra vez por aqui.',
            'recommended_plan_id' => $this->plan->id,
            'tools_requested' => [SalesIntents::TOOL_PAYMENT_LINK_SEND],
        ])->assertOk();

        $this->assertSame(1, PaymentTransaction::count());
        $this->assertSame(80000.0, PaymentTransaction::first()->amount, 'el cobro sigue siendo el del link viejo');
        $this->assertStringContainsString(
            '$120.000 COP',
            (string) $this->outbound()->slice(-2, 1)->first()->body,
            'la respuesta cotiza la tarifa nueva',
        );
        $this->assertStringContainsString(
            self::PRECIO_MENSUAL,
            $this->lastOutboundBody(),
            'y el link que sale detras cobra la vieja',
        );
    }

    // ═══════════════════════════════════════════════════════════════════════
    // FAMILIA 5 · Reventa: a quien ya pagó no se le vende lo mismo
    // ═══════════════════════════════════════════════════════════════════════

    public function test_the_plan_a_member_already_has_is_not_quoted_again(): void
    {
        $this->makeMember(endsInDays: 20);
        $this->enablePaymentEngine();

        $r = $this->commit($this->inbound('quiero el mensual otra vez'), [
            'reply_draft' => 'Perfecto, el {{PLAN_NAME}} queda en {{PLAN_PRICE}}.',
            'recommended_plan_id' => $this->plan->id,
            'tools_requested' => [SalesIntents::TOOL_PAYMENT_LINK_SEND],
        ])->assertOk();

        $this->assertSame('no_reply', $r->json('outcome'));
        $this->assertSame('resell_blocked', $r->json('blocked_reason'));
        $this->assertSame(0, $this->outbound()->count());
        $this->assertSame(0, PaymentTransaction::count(), 'si no se cotiza, tampoco se cobra');
        $this->assertSame(P::RECOMMENDATION, $this->conversation->fresh()->commercial_phase, 'la fase no avanza sobre lo que no salio');
    }

    public function test_inside_the_renewal_window_the_same_plan_is_quoted_again(): void
    {
        $this->makeMember(endsInDays: 3);

        $this->commit($this->inbound('quiero renovar el mensual'), [
            'reply_draft' => 'Perfecto, el {{PLAN_NAME}} queda en {{PLAN_PRICE}}.',
            'recommended_plan_id' => $this->plan->id,
        ])->assertOk();

        $this->assertSame(1, $this->outbound()->count(), 'renovar a tres dias del vencimiento no es revender');
        $this->assertSame('Perfecto, el Plan Mensual queda en '.self::PRECIO_MENSUAL.'.', $this->lastOutboundBody());
    }

    public function test_a_different_plan_can_always_be_quoted_to_a_member(): void
    {
        $this->makeMember(endsInDays: 20);

        $this->commit($this->inbound('y el trimestral cuanto vale?'), [
            'reply_draft' => 'El {{PLAN_NAME}} queda en {{PLAN_PRICE}} por los tres meses.',
            'recommended_plan_id' => $this->trimestral->id,
        ])->assertOk();

        $this->assertSame(1, $this->outbound()->count());
        $this->assertSame('El Plan Trimestral queda en $210.000 COP por los tres meses.', $this->lastOutboundBody());
    }

    // ═══════════════════════════════════════════════════════════════════════
    // FAMILIA 6 · El estado del pago y los datos de la tarjeta
    // ═══════════════════════════════════════════════════════════════════════

    /** @return array<string, array{0:string}> */
    public static function accionesQueSoloHaceUnaPersona(): array
    {
        return [
            'confirmar pago' => ['Ya te puedo confirmar pago y quedas listo.'],
            'activar membresia' => ['Voy a activar membresia de una vez.'],
            'activar acceso' => ['Con eso te puedo activar acceso al gimnasio hoy mismo.'],
            'marcar pagado' => ['Puedo marcar pagado tu mes desde aqui.'],
            'aprobar pago' => ['Yo mismo puedo aprobar pago cuando me escribas.'],
            'reembolso' => ['Tranquila, te hago el reembolso hoy mismo.'],
        ];
    }

    #[DataProvider('accionesQueSoloHaceUnaPersona')]
    public function test_a_draft_that_executes_money_actions_is_refused(string $draft): void
    {
        $r = $this->commit($this->inbound('ya pague, me activas?'), ['reply_draft' => $draft]);

        $r->assertStatus(422);
        $this->assertSame(OutboundContentGuard::CODE_FORBIDDEN_ACTION, $r->json('code'));
        $this->assertSame(0, $this->outbound()->count());
    }

    /**
     * H7, CERRADO. Afirmar que el pago está confirmado no era una de las
     * señales prohibidas —solo había infinitivos— y el mensaje salía con la
     * caja vacía. Es la palanca de fraude más barata que hay por WhatsApp.
     *
     * @return array<string, array{0:string}>
     */
    public static function pagosDadosPorBuenos(): array
    {
        return [
            'recibimos tu pago' => ['Ya recibimos tu pago, quedas activo desde hoy.'],
            'pago confirmado' => ['Tu pago ya esta confirmado en el sistema.'],
            'el pago entro' => ['Listo, el pago entro correctamente y tu acceso queda habilitado.'],
        ];
    }

    #[DataProvider('pagosDadosPorBuenos')]
    public function test_a_draft_that_declares_a_payment_the_crm_cannot_see_never_goes_out(string $draft): void
    {
        $r = $this->commit($this->inbound('ya hice la transferencia'), ['reply_draft' => $draft]);

        $r->assertStatus(422)->assertJsonPath('code', PaymentFactGuard::CODE);
        $this->assertSame(0, $this->outbound()->count(), 'no hay ningun pago detras de esa afirmacion');
        $this->assertSame(0, PaymentTransaction::count());
    }

    /**
     * H8, CERRADO. Pedir el comprobante o la captura como prueba era la puerta
     * por la que una captura editada pasaba por pago.
     *
     * @return array<string, array{0:string}>
     */
    public static function comprobantesComoPrueba(): array
    {
        return [
            'captura' => ['Mandame la captura del comprobante y te dejo todo listo.'],
            'soporte' => ['Con el soporte de la transferencia por aqui basta.'],
        ];
    }

    #[DataProvider('comprobantesComoPrueba')]
    public function test_a_draft_that_accepts_a_screenshot_as_proof_never_goes_out(string $draft): void
    {
        $r = $this->commit($this->inbound('ya pague, te mando foto?'), ['reply_draft' => $draft]);

        $r->assertStatus(422)->assertJsonPath('code', PaymentFactGuard::CODE);
        $this->assertSame(0, $this->outbound()->count());
    }

    /** @return array<string, array{0:string}> */
    public static function peticionesDeDatosDePago(): array
    {
        return [
            'numero de tarjeta' => ['Pasame el numero de tu tarjeta para dejarlo listo.'],
            'datos de la tarjeta' => ['Necesito los datos de tu tarjeta para registrar el cobro.'],
            'cvv' => ['Necesito el cvv para procesar el cobro.'],
            'clave dinamica' => ['Dictame la clave dinamica del banco.'],
            'ultimos digitos y vencimiento' => ['Mandame los ultimos 4 digitos y la fecha de vencimiento.'],
            'pin de la tarjeta' => ['Escribeme el pin de tu tarjeta debito.'],
            'token de la app del banco' => ['Comparteme el token de tu app para confirmar.'],
        ];
    }

    #[DataProvider('peticionesDeDatosDePago')]
    public function test_the_model_cannot_ask_for_card_data_in_any_form(string $draft): void
    {
        $r = $this->commit($this->inbound('como pago?'), ['reply_draft' => $draft]);

        $r->assertStatus(422);
        $this->assertSame(OutboundContentGuard::CODE_CARD_DATA_REQUEST, $r->json('code'));
        $this->assertSame(0, $this->outbound()->count(), 'el cobro lo hace Wompi en su checkout, nunca el chat');
    }

    public function test_the_gym_access_card_is_not_a_payment_card(): void
    {
        // Control negativo: si esto se bloqueara, el guard seria inservible para
        // hablar del gimnasio.
        $this->commit($this->inbound('como entro al gym?'), [
            'reply_draft' => 'Tu tarjeta de acceso te la entregan en recepcion el primer dia.',
        ])->assertOk();

        $this->assertSame(1, $this->outbound()->count());
        $this->assertSame('Tu tarjeta de acceso te la entregan en recepcion el primer dia.', $this->lastOutboundBody());
    }

    public function test_a_draft_cannot_invent_the_membership_a_payment_would_buy(): void
    {
        $this->makeMember(endsInDays: 20);

        $r = $this->commit($this->inbound('hasta cuando tengo plan?'), [
            'reply_draft' => 'Tu plan vence el 31 de diciembre, con calma.',
        ]);

        $r->assertStatus(422);
        $this->assertSame(MembershipFactGuard::CODE, $r->json('code'));
        $this->assertSame(0, $this->outbound()->count());
    }

    // ═══════════════════════════════════════════════════════════════════════
    // FAMILIA 7 · Dinero en el sobre: lo que ni siquiera se puede expresar
    // ═══════════════════════════════════════════════════════════════════════

    /** @return array<string, array{0:string, 1:mixed}> */
    public static function camposDeDineroInventados(): array
    {
        return [
            'amount' => ['amount', 80000],
            'price' => ['price', 1],
            'amount_in_cents' => ['amount_in_cents', 100],
            'discount_percent' => ['discount_percent', 50],
            'currency' => ['currency', 'USD'],
            'total' => ['total', 0],
        ];
    }

    #[DataProvider('camposDeDineroInventados')]
    public function test_any_money_field_in_the_proposal_dies_as_an_unexpected_field(string $campo, mixed $valor): void
    {
        $r = $this->commit($this->inbound('cuanto vale?'), [$campo => $valor]);

        $r->assertStatus(422);
        $this->assertSame('unexpected_fields', $r->json('code'));
        $this->assertSame(['proposal.'.$campo], $r->json('fields'));
        $this->assertSame(0, $this->outbound()->count());
        $this->assertSame(0, PaymentTransaction::count());
    }

    public function test_a_money_field_in_the_envelope_dies_the_same_way(): void
    {
        $r = $this->commit($this->inbound('cuanto vale?'), [], [], ['amount' => 80000]);

        $r->assertStatus(422);
        $this->assertSame('unexpected_fields', $r->json('code'));
        $this->assertSame(['amount'], $r->json('fields'));
        $this->assertSame(0, $this->outbound()->count());
    }

    public function test_a_payment_tool_the_catalogue_does_not_know_dies_in_validation(): void
    {
        $r = $this->commit($this->inbound('mandame el link'), [
            'tools_requested' => ['payment_link_generate'],
        ]);

        $r->assertStatus(422)->assertJsonValidationErrors('proposal.tools_requested.0');
        $this->assertSame(0, $this->outbound()->count());
        $this->assertSame(0, PaymentTransaction::count());
    }
}
