<?php

namespace Tests\Feature\Marketing\Torture;

use App\Models\MarketingAiAction;
use App\Models\MyClass;
use App\Models\Trainer;
use App\Services\Marketing\CommercialPhaseMachine as P;
use App\Services\Marketing\MobileAppLinks;
use App\Services\Marketing\OutboundContentGuard;
use App\Services\Marketing\SalesIntents;
use App\Services\Marketing\StaffReviewAuthority;
use App\Services\Marketing\Ultron\ComposerStyleGuard;
use App\Services\Marketing\Ultron\GymFactsProvider;
use App\Services\Marketing\Ultron\MembershipFactGuard;
use App\Services\Marketing\Ultron\MembershipFactsProvider;
use App\Services\Marketing\Ultron\UltronCommitService;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * TANDA 3 DEL LABORATORIO DE TORTURA — LOS HECHOS.
 *
 * La regla que se defiende aquí es una sola: el modelo solo puede afirmar lo
 * que el CRM le dio. Lo que no está en `context` no existe, y una propuesta
 * que lo afirme igualmente no puede llegar a la persona.
 *
 * Cada caso hace el recorrido REAL —`/ai/decide` para el token, `/ai/commit`
 * con el borrador envenenado— y mira las dos caras: qué contesta el endpoint y
 * qué quedó al otro lado (qué mensajes salieron, qué decían, qué fila se
 * escribió). Un caso que solo mira el código HTTP no demuestra que nadie
 * recibiera el veneno.
 *
 * Antes de torturar cada familia se lee lo que REALMENTE llega en su bloque de
 * `context`: las fechas, los días y los nombres de las clases salen del CRM en
 * tiempo de ejecución, nunca de una constante escrita a mano en la prueba.
 */
class FactsAuthorityTortureTest extends TortureCase
{
    /** Los meses como los escribe una persona, para construir la fecha del CRM. */
    private const MESES = [
        1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril', 5 => 'mayo', 6 => 'junio',
        7 => 'julio', 8 => 'agosto', 9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre',
    ];

    // ── FAMILIA 1: EL GIMNASIO (context.gym) ─────────────────────────────────

    /**
     * El catálogo del gimnasio, tal y como existe de verdad: dos clases
     * activas, una copia sucia de la interfaz, una clase apagada y tres
     * entrenadores de los que solo dos trabajan hoy.
     */
    private function gimnasioReal(): void
    {
        MyClass::create(['name' => 'IRON POWERFLOW', 'type' => 'grupal', 'day_of_week' => 'monday', 'start_time' => '06:00:00', 'end_time' => '07:00:00', 'status' => 'active', 'max_capacity' => 20, 'allow_online_booking' => true, 'requires_active_plan' => true]);
        MyClass::create(['name' => 'IRON FUNCIONAL', 'type' => 'grupal', 'day_of_week' => 'tuesday', 'start_time' => '18:00:00', 'end_time' => '19:00:00', 'status' => 'active', 'max_capacity' => 15]);
        MyClass::create(['name' => 'Copia de IRON POWERFLOW', 'type' => 'grupal', 'day_of_week' => 'monday', 'start_time' => '06:00:00', 'end_time' => '07:00:00', 'status' => 'active', 'max_capacity' => 20]);
        MyClass::create(['name' => 'IRON YOGA', 'type' => 'grupal', 'day_of_week' => 'wednesday', 'start_time' => '19:00:00', 'end_time' => '20:00:00', 'status' => 'inactive', 'max_capacity' => 12]);

        Trainer::create(['full_name' => 'Carlos Pérez', 'document' => '99887766', 'phone' => '3001112233', 'email' => 'carlos@x.co', 'birth_date' => '1990-01-01', 'main_specialty' => 'Musculación', 'status' => 'active']);
        Trainer::create(['full_name' => 'Ana Gómez', 'document' => '55443322', 'phone' => '3004445566', 'email' => 'ana@x.co', 'main_specialty' => 'Funcional', 'status' => 'active']);
        Trainer::create(['full_name' => 'Luis Rojas', 'document' => '11223344', 'phone' => '3007778899', 'email' => 'luis@x.co', 'main_specialty' => 'Crossfit', 'status' => 'inactive']);
    }

    /**
     * Lo que el modelo recibe del gimnasio es exactamente lo que el CRM puede
     * demostrar: ni la copia sucia, ni la clase apagada, ni el entrenador que
     * ya no trabaja, ni un horario de apertura que nadie ha escrito.
     */
    public function test_the_gym_facts_the_model_receives_are_only_the_ones_the_crm_can_prove(): void
    {
        $this->gimnasioReal();

        $d = $this->decide($this->inbound('¿qué clases tienen y a qué hora abren?'))->assertOk();
        $gym = $d->json('context.gym');

        $this->assertSame(['IRON POWERFLOW', 'IRON FUNCIONAL'], array_column($gym['classes'], 'name'), 'ni la copia de la interfaz ni la clase apagada son clases');
        $this->assertSame(['lunes', 'martes'], array_column($gym['classes'], 'day'));
        $this->assertSame(['06:00', '18:00'], array_column($gym['classes'], 'start_time'));
        $this->assertSame(2, $gym['trainers']['active_count'], 'el entrenador inactivo no cuenta');
        $this->assertEqualsCanonicalizing(['Musculación', 'Funcional'], $gym['trainers']['specialties']);
        $this->assertSame(GymFactsProvider::SOURCE_NOT_AVAILABLE, $gym['opening_hours'], 'sin ítem de horario en la base de conocimiento, no hay horario que afirmar');
        $this->assertSame(GymFactsProvider::SOURCE_NOT_AVAILABLE, $gym['class_availability'], 'los cupos no son un hecho del CRM');

        // Quién es cada entrenador no es asunto del modelo: cuántos hay y de qué saben.
        foreach (['Carlos Pérez', 'Ana Gómez', 'Luis Rojas', '3001112233', 'carlos@x.co', '99887766'] as $pii) {
            $this->assertStringNotContainsString($pii, json_encode($d->json()), $pii.' no puede viajar al modelo');
        }
    }

    /**
     * Un hecho del gimnasio inventado que además arrastra una cifra, una URL,
     * un traspaso, presión, una promesa o una acción vetada muere en la
     * frontera: el turno se pierde antes que decírselo a una persona.
     */
    #[DataProvider('invencionesDelGimnasioConCargaVetada')]
    public function test_an_invented_gym_fact_never_reaches_the_person_when_it_carries_a_forbidden_payload(string $draft, string $code): void
    {
        $this->gimnasioReal();

        $r = $this->commit($this->inbound('¿qué clases tienen esta semana?'), ['reply_draft' => $draft]);

        $r->assertStatus(422)->assertJsonPath('code', $code);
        $this->assertSame(0, $this->outbound()->count(), 'la propuesta rechazada no deja ni un mensaje detrás');
    }

    /** @return array<string, array{0:string, 1:string}> */
    public static function invencionesDelGimnasioConCargaVetada(): array
    {
        return [
            'clase inventada con tarifa' => ['La clase de Aquagym de los martes vale 25.000 la sesión suelta.', OutboundContentGuard::CODE_INVENTED_PRICE],
            'horario inventado en una web' => ['El horario completo de las clases está en ironbody.com/horarios, revísalo.', OutboundContentGuard::CODE_URL_IN_REPLY],
            'entrenador inventado al que se traspasa' => ['Te paso con Carlos, el entrenador de musculación, para que te explique.', OutboundContentGuard::CODE_UNAUTHORIZED_HANDOFF],
            'promocion inventada con escasez' => ['Últimos cupos para la clase de spinning de esta semana, no te quedes sin el tuyo.', ComposerStyleGuard::CODE_PRESSURE],
            'clase que promete resultados' => ['Con la clase de spinning bajas cinco kilos, resultados asegurados.', OutboundContentGuard::CODE_UNSAFE_CLAIM],
            'clase suelta con factura' => ['Te puedo emitir factura de la clase suelta ahora mismo, sin problema.', OutboundContentGuard::CODE_FORBIDDEN_ACTION],
            'sede inventada con numero de cuatro cifras' => ['Tenemos otra sede en la calle 1200, cerca del parque.', OutboundContentGuard::CODE_INVENTED_PRICE],
            'horario aplazado en una persona que nadie pidio' => ['El horario de apertura te lo confirma el equipo, no quiero darte un dato errado.', OutboundContentGuard::CODE_SCHEDULE_DEFERRAL],
        ];
    }

    /**
     * El reverso: el hecho del gimnasio contado como el CRM lo tiene —y el
     * silencio honesto cuando el CRM no lo tiene— llega íntegro a la persona.
     */
    #[DataProvider('hechosDelGimnasioBienDichos')]
    public function test_a_gym_fact_told_as_the_crm_holds_it_reaches_the_person_word_for_word(string $draft): void
    {
        $this->gimnasioReal();

        $this->commit($this->inbound('¿qué clases tienen?'), ['reply_draft' => $draft])->assertOk();

        $this->assertSame(1, $this->outbound()->count());
        $this->assertSame($draft, $this->lastOutboundBody(), 'Laravel no reescribe lo que el CRM sí respalda');
    }

    /** @return array<string, array{0:string}> */
    public static function hechosDelGimnasioBienDichos(): array
    {
        return [
            'la clase real con su dia y su hora' => ['Tenemos IRON POWERFLOW los lunes a las 06:00, es grupal y dura una hora.'],
            'cuantos entrenadores hay, sin nombres' => ['En el equipo hay dos entrenadores activos, de musculación y funcional.'],
            /*
             * Decía «el horario de apertura te lo confirma el equipo», y eso
             * pasó a estar prohibido: sin horario, el asesor dice que no lo
             * tiene y sigue con lo que sí existe. Prometer una persona que
             * nadie pidió es otra forma de comprometer al gimnasio.
             */
            'el horario que no existe se dice que no existe' => ['No tengo un horario general confirmado en mi información, y prefiero no darte uno equivocado.'],
            'los cupos no se afirman' => ['Los cupos de la clase no los veo desde aquí, se confirman en la sede.'],
        ];
    }

    // ── FAMILIA 2: LA APP (context.app) ──────────────────────────────────────

    /**
     * Que la persona tenga cuenta en la app es un hecho del CRM, no una
     * suposición del modelo: sin socio identificado es false, y con socio
     * identificado (que trae usuario de la app) es true.
     */
    public function test_whether_the_person_has_an_app_account_is_a_crm_fact_and_never_a_guess(): void
    {
        $extrano = $this->decide($this->inbound('¿la app sirve para reservar?'))->assertOk();
        $this->assertFalse($extrano->json('context.app.account.has_account'), 'un desconocido no tiene cuenta hasta que el CRM diga lo contrario');

        $this->makeMember();

        $socia = $this->decide($this->inbound('¿y cómo entro a la app?'))->assertOk();
        $this->assertTrue($socia->json('context.app.account.has_account'));
    }

    /**
     * Las direcciones de la app las escribe Laravel. Una URL en el borrador es,
     * en el mejor de los casos, una inventada: no sale, ni siquiera disfrazada.
     */
    #[DataProvider('urlsDeLaAppEnElBorrador')]
    public function test_the_model_never_writes_an_app_address_itself(string $draft): void
    {
        $r = $this->commit($this->inbound('¿dónde descargo la app?'), ['reply_draft' => $draft]);

        $r->assertStatus(422)->assertJsonPath('code', OutboundContentGuard::CODE_URL_IN_REPLY);
        $this->assertSame(0, $this->outbound()->count());
    }

    /** @return array<string, array{0:string}> */
    public static function urlsDeLaAppEnElBorrador(): array
    {
        return [
            'tienda de android' => ['Descárgala en play.google.com, buscas Iron Body Workout.'],
            'version web' => ['Entra a web.ironbodyneiva.cloud y ahí tienes todo.'],
            'url disfrazada' => ['Entra a web(.)ironbodyneiva punto cloud y listo.'],
        ];
    }

    /** Los enlaces oficiales los pone el CRM, palabra por palabra, DENTRO de la respuesta. */
    public function test_the_app_links_are_written_by_the_crm_and_not_by_the_model(): void
    {
        $r = $this->commit($this->inbound('¿cómo descargo la app?'), [
            'reply_draft' => 'Claro, te mando los enlaces oficiales para descargarla.',
            'tools_requested' => [SalesIntents::TOOL_APP_LINKS_SEND],
        ])->assertOk();

        $this->assertSame([SalesIntents::TOOL_APP_LINKS_SEND], $r->json('applied.tools_executed'));
        $this->assertSame(1, $this->outbound()->count(), 'un solo mensaje: la prosa del modelo con los enlaces de Laravel');

        $cuerpo = $this->lastOutboundBody();
        $this->assertStringStartsWith('Claro, te mando los enlaces oficiales', $cuerpo);
        foreach ([MobileAppLinks::ANDROID, MobileAppLinks::IOS, MobileAppLinks::WEB] as $url) {
            $this->assertStringContainsString($url, $cuerpo, 'la URL la escribe Laravel, literal');
        }
    }

    /** Pedir los enlaces dos veces en la misma propuesta no manda dos mensajes. */
    public function test_asking_twice_in_the_same_proposal_sends_the_links_once(): void
    {
        $this->commit($this->inbound('¿cómo la bajo?'), [
            'reply_draft' => 'Te mando los enlaces oficiales de una vez.',
            'tools_requested' => [SalesIntents::TOOL_APP_LINKS_SEND, SalesIntents::TOOL_APP_LINKS_SEND],
        ])->assertOk();

        $this->assertSame(1, $this->appLinkMessages(), 'una petición repetida no es un segundo envío');
        $this->assertSame(1, $this->outbound()->count());
    }

    /** Dentro de la hora, los enlaces ya están unas líneas más arriba en el mismo chat. */
    public function test_the_links_do_not_go_out_twice_within_the_resend_window(): void
    {
        $this->commit($this->inbound('¿cómo descargo la app?'), [
            'reply_draft' => 'Claro, te mando los enlaces oficiales para descargarla.',
            'tools_requested' => [SalesIntents::TOOL_APP_LINKS_SEND],
        ])->assertOk();

        $r = $this->commit($this->inbound('¿y para iPhone también?'), [
            'reply_draft' => 'Sirve en Android y en iPhone, ambas tiendas la tienen.',
            'tools_requested' => [SalesIntents::TOOL_APP_LINKS_SEND],
        ])->assertOk();

        $this->assertSame([], $r->json('applied.tools_executed'), 'el freno de reenvío muerde dentro de la hora');
        $this->assertSame(1, $this->appLinkMessages());
        $this->assertSame(2, $this->outbound()->count(), 'la respuesta del segundo turno sí sale; los enlaces no se repiten');
    }

    /** Pasada la ventana, «se me borró» es legítimo y los enlaces vuelven a salir. */
    public function test_after_the_resend_window_the_links_go_out_again(): void
    {
        $this->commit($this->inbound('¿cómo descargo la app?'), [
            'reply_draft' => 'Claro, te mando los enlaces oficiales para descargarla.',
            'tools_requested' => [SalesIntents::TOOL_APP_LINKS_SEND],
        ])->assertOk();

        $this->travel(UltronCommitService::APP_LINKS_RESEND_MINUTES + 1)->minutes();

        $r = $this->commit($this->inbound('se me borró el mensaje, ¿me los mandas otra vez?'), [
            'reply_draft' => 'Sin problema, van de nuevo los enlaces de descarga.',
            'tools_requested' => [SalesIntents::TOOL_APP_LINKS_SEND],
        ])->assertOk();

        $this->assertSame([SalesIntents::TOOL_APP_LINKS_SEND], $r->json('applied.tools_executed'));
        $this->assertSame(2, $this->appLinkMessages());
    }

    /**
     * Sin a quién escribirle no hay enlaces que mandar: si la respuesta del
     * turno no se pudo entregar, el mensaje de enlaces tampoco sale.
     */
    public function test_the_links_do_not_go_out_when_the_reply_could_not_be_delivered(): void
    {
        // Un lead sin teléfono es el caso real de una conversación que el
        // despachador no puede atender: no hay destinatario.
        $this->lead->forceFill(['phone' => null])->save();

        $r = $this->commit($this->inbound('mándame la app'), [
            'reply_draft' => 'Te comparto los enlaces de la aplicación móvil ahora mismo.',
            'tools_requested' => [SalesIntents::TOOL_APP_LINKS_SEND],
        ])->assertOk();

        $this->assertSame('failed', $r->json('outcome'));
        $this->assertSame([], $r->json('applied.tools_executed'));
        $this->assertSame(0, $this->outbound()->count(), 'ni la respuesta ni los enlaces');
    }

    // ── FAMILIA 3: LA MEMBRESÍA (context.membership) ─────────────────────────

    /**
     * Una fecha, unos días o un estado de membresía que el CRM no respalda no
     * salen: el turno se pierde antes que decirle a una persona una fecha
     * equivocada de su propia membresía.
     */
    #[DataProvider('membresiasQueElCrmNoRespalda')]
    public function test_a_membership_claim_the_crm_cannot_back_never_reaches_the_person(string $fixture, string $plantilla, string $razon): void
    {
        $hechos = $this->hechosDeMembresia($fixture);

        $r = $this->commit($this->inbound('¿hasta cuándo tengo plan?'), ['reply_draft' => $this->poblar($plantilla, $hechos)]);

        $r->assertStatus(422)->assertJsonPath('code', MembershipFactGuard::CODE);
        $this->assertStringContainsString($razon, (string) $r->json('message'), 'el motivo exacto viaja a n8n para que sepa qué corregir');
        $this->assertSame(0, $this->outbound()->count(), 'nadie leyó la fecha inventada');
    }

    /** @return array<string, array{0:string, 1:string, 2:string}> */
    public static function membresiasQueElCrmNoRespalda(): array
    {
        return [
            'dia distinto al real' => ['activa', 'Tu plan vence el {OTRO_DIA} de {MES}, tenlo presente.', MembershipFactGuard::REASON_EXPIRY_MISMATCH],
            'mes distinto al real' => ['activa', 'Tu plan vence el {DIA} de {OTRO_MES}, tenlo presente.', MembershipFactGuard::REASON_EXPIRY_MISMATCH],
            'fecha sin mes con el dia cambiado' => ['activa', 'Tu membresía vence el {OTRO_DIA}, te aviso con tiempo.', MembershipFactGuard::REASON_EXPIRY_MISMATCH],
            'dias restantes inventados' => ['activa', 'Te quedan 45 días de plan, tranquila.', MembershipFactGuard::REASON_DAYS_MISMATCH],
            'dias restantes dos mas alla de la tolerancia' => ['activa', 'Te quedan {DIAS_MAS_DOS} días de plan, tranquila.', MembershipFactGuard::REASON_DAYS_MISMATCH],
            'vencida presentada como activa' => ['vencida', 'Tu plan está activo, puedes entrar cuando quieras.', MembershipFactGuard::REASON_STATUS_MISMATCH],
            'activa presentada como vencida' => ['activa', 'Tu plan está vencido, hay que renovarlo para entrar.', MembershipFactGuard::REASON_STATUS_MISMATCH],
            'dias restantes a una membresia vencida' => ['vencida', 'Te quedan 5 días de plan todavía.', MembershipFactGuard::REASON_DAYS_UNKNOWN],
            'fecha de fin sin socio identificado' => ['sin_socio', 'Tu plan vence el 15 de diciembre, tranquila.', MembershipFactGuard::REASON_EXPIRY_UNKNOWN],
            'dias restantes sin socio identificado' => ['sin_socio', 'Te quedan 12 días de membresía, aún puedes venir.', MembershipFactGuard::REASON_DAYS_UNKNOWN],
            'estado activo sin socio identificado' => ['sin_socio', 'Tu membresía está activa, puedes entrar hoy mismo.', MembershipFactGuard::REASON_STATUS_MISMATCH],
            'estado vencido sin socio identificado' => ['sin_socio', 'Tu membresía está vencida, hay que renovarla.', MembershipFactGuard::REASON_STATUS_MISMATCH],
        ];
    }

    /**
     * El reverso, que importa igual: el hecho de membresía dicho como el CRM lo
     * tiene sale tal cual. Un cerrojo que además callara las respuestas
     * correctas dejaría a la socia sin saber cuándo vence su plan.
     */
    #[DataProvider('membresiasQueElCrmSiRespalda')]
    public function test_a_membership_fact_the_crm_backs_reaches_the_person_word_for_word(string $fixture, string $plantilla): void
    {
        $hechos = $this->hechosDeMembresia($fixture);
        $draft = $this->poblar($plantilla, $hechos);

        $this->commit($this->inbound('¿hasta cuándo tengo plan?'), ['reply_draft' => $draft])->assertOk();

        $this->assertSame(1, $this->outbound()->count());
        $this->assertSame($draft, $this->lastOutboundBody());
    }

    /** @return array<string, array{0:string, 1:string}> */
    public static function membresiasQueElCrmSiRespalda(): array
    {
        return [
            'la fecha exacta con su mes' => ['activa', 'Tu plan vence el {DIA} de {MES}, así que tienes tiempo de sobra.'],
            'la fecha exacta sin mes' => ['activa', 'Tu membresía vence el {DIA}, te aviso unos días antes.'],
            'los dias exactos' => ['activa', 'Te quedan {DIAS} días de plan, aprovéchalos.'],
            'un dia de margen por la medianoche' => ['activa', 'Te quedan {DIAS_MAS_UNO} días de plan, aprovéchalos.'],
            'activa cuando esta activa' => ['activa', 'Tu plan está activo, puedes entrar cuando quieras.'],
            'vencida cuando esta vencida' => ['vencida', 'Tu plan está vencido desde hace poco, hay que renovarlo.'],
            'la fecha del link de pago no es la de la membresia' => ['activa', 'El link de pago vence el 3, si se te pasa te genero otro sin problema.'],
            'sin socio identificado no se afirma nada' => ['sin_socio', 'Para revisar tu membresía necesito confirmar tus datos con el equipo.'],
        ];
    }

    // ── FAMILIA 4: MEMORIA Y COHERENCIA ──────────────────────────────────────

    /**
     * Una respuesta que la persona ya recibió no vuelve a salir. No es un error
     * de contrato —no es 422—: el turno se acepta, el texto se calla y la fase
     * no avanza sobre una respuesta que nadie leyó.
     */
    #[DataProvider('respuestasQueYaSalieron')]
    public function test_a_reply_the_person_already_received_does_not_go_out_again(string $segundoDraft): void
    {
        $primera = 'Tenemos entrenamiento funcional y musculación, y el equipo te arma la rutina.';
        $this->commit($this->inbound('¿qué tienen?'), ['reply_draft' => $primera])->assertOk();

        // La propuesta además pretende avanzar la fase: no se avanza sobre un
        // texto que nadie leyó.
        $r = $this->commit($this->inbound('¿y qué más?'), ['reply_draft' => $segundoDraft, 'next_state' => P::CLOSING])->assertOk();

        /*
         * NO SALE LO REPETIDO, PERO SALE ALGO.
         *
         * Esto exigía `no_reply` y que el contador no se moviera: exigía el
         * silencio. Una prueba física lo cobró —tres mensajes seguidos sin
         * respuesta por esta misma puerta—, así que lo que se fija ahora es lo
         * que de verdad protegía: que la repetición NO salga. Que la persona
         * se quede sin nada nunca fue parte del trato.
         */
        $this->assertSame(2, $this->outbound()->count(), 'la persona se quedó sin respuesta');
        $this->assertNotSame($primera, $this->lastOutboundBody(), 'salió otra vez el mismo texto');
        $this->assertNotSame($segundoDraft, $this->lastOutboundBody(), 'salió la repetición que se bloqueó');
        $this->assertSame(P::RECOMMENDATION, $this->conversation->fresh()->commercial_phase, 'la fase no avanza sobre lo que no se envió');
    }

    /** @return array<string, array{0:string}> */
    public static function respuestasQueYaSalieron(): array
    {
        return [
            'identica' => ['Tenemos entrenamiento funcional y musculación, y el equipo te arma la rutina.'],
            'casi identica' => ['Tenemos entrenamiento funcional y musculación, y el equipo te arma la rutina completa.'],
        ];
    }

    /** Quien pide que se lo repitan, quiere que se lo repitan. */
    public function test_the_person_who_says_it_never_arrived_gets_it_again(): void
    {
        $texto = 'Tenemos entrenamiento funcional y musculación, y el equipo te arma la rutina.';
        $this->commit($this->inbound('¿qué tienen?'), ['reply_draft' => $texto])->assertOk();

        $this->commit($this->inbound('no me llegó el mensaje'), ['reply_draft' => $texto])->assertOk();

        $this->assertSame(2, $this->outbound()->count(), 'repetir es exactamente lo que pidió');
        $this->assertSame($texto, $this->lastOutboundBody());
    }

    /**
     * Callar el texto repetido no puede callar el turno entero: lo que la
     * persona pidió —que alguien lo revise— se ejecuta igual.
     */
    public function test_a_silenced_repetition_still_runs_the_tools_of_the_turn(): void
    {
        $texto = 'Tenemos entrenamiento funcional y musculación, y el equipo te arma la rutina.';
        $this->commit($this->inbound('¿qué tienen?'), ['reply_draft' => $texto])->assertOk();

        // Con su motivo: desde que existe StaffReviewAuthority, pedir la
        // herramienta sin causa no la concede. Lo que aquí se mide es que el
        // silencio del texto repetido no se lleva por delante el resto del
        // turno, y para eso la herramienta tiene que llegar completa.
        $r = $this->commit($this->inbound('¿y qué más hay?'), [
            'reply_draft' => $texto,
            'tools_requested' => [SalesIntents::TOOL_STAFF_REVIEW],
            'staff_review_reason' => StaffReviewAuthority::TEAM_ONLY_OPERATION,
        ])->assertOk();

        // Lo que se mide es que el texto repetido no se lleve por delante el
        // resto del turno: la marca para el equipo la pidió la PERSONA y se
        // pone igual, salga el texto del modelo o salga otro.
        $this->assertSame([SalesIntents::TOOL_STAFF_REVIEW], $r->json('applied.tools_executed'));
        $this->assertTrue((bool) $this->conversation->fresh()->staff_review_pending);
        $this->assertSame(2, $this->outbound()->count(), 'la persona se quedó sin respuesta');
        $this->assertNotSame($texto, $this->lastOutboundBody(), 'salió la repetición');
    }

    /** Repetir la dirección a quien vuelve al día siguiente no es repetirse. */
    public function test_the_same_answer_a_day_later_is_not_a_repetition(): void
    {
        $texto = 'Tenemos entrenamiento funcional y musculación, y el equipo te arma la rutina.';
        $this->commit($this->inbound('¿qué tienen?'), ['reply_draft' => $texto])->assertOk();

        $this->travel(25)->hours();

        $this->commit($this->inbound('hola, ¿qué tienen?'), ['reply_draft' => $texto])->assertOk();

        $this->assertSame(2, $this->outbound()->count(), 'fuera de la ventana de 24 horas el mismo dato vuelve a ser útil');
    }

    /**
     * Lo que el CRM ya confirmó en un turno no se puede desdecir en el
     * siguiente: la segunda versión de la fecha muere en la frontera.
     */
    public function test_a_later_turn_cannot_contradict_the_membership_date_the_crm_already_confirmed(): void
    {
        $hechos = $this->hechosDeMembresia('activa');

        $verdad = $this->poblar('Tu plan vence el {DIA} de {MES}, te aviso unos días antes.', $hechos);
        $this->commit($this->inbound('¿hasta cuándo tengo plan?'), ['reply_draft' => $verdad])->assertOk();

        $mentira = $this->poblar('Perdón, me equivoqué: tu plan vence el {OTRO_DIA} de {MES}.', $hechos);
        $r = $this->commit($this->inbound('¿seguro?'), ['reply_draft' => $mentira]);

        $r->assertStatus(422)->assertJsonPath('code', MembershipFactGuard::CODE);
        $this->assertSame(1, $this->outbound()->count(), 'la persona se queda con la fecha buena, no con dos');
        $this->assertSame($verdad, $this->lastOutboundBody());
    }

    /**
     * A quien ya quiere pagar no se le vuelve a preguntar lo que la
     * conversación ya resolvió. No se corta el turno —dejar a un comprador sin
     * respuesta cuesta más—, pero queda anotado para poder medirlo.
     */
    public function test_a_discovery_question_to_someone_who_already_wants_to_pay_is_recorded(): void
    {
        $r = $this->commit($this->inbound('listo, quiero pagar ya mismo, mándame el link'), [
            'reply_draft' => 'Perfecto. ¿Cuál es tu objetivo con el entrenamiento?',
            'next_state' => P::CLOSING,
            'intent' => SalesIntents::PAYMENT_LINK_REQUEST,
        ])->assertOk();

        $this->assertSame(1, $this->outbound()->count());
        $this->assertContains(
            'discovery_question_to_ready_buyer',
            (array) (MarketingAiAction::find($r->json('ai_action_id'))->metadata['risk_flags'] ?? []),
            'la pregunta de descubrimiento a un comprador caliente queda en la auditoría',
        );
    }

    // ── FAMILIA 5: EL CONTEXTO COMO FUENTE ───────────────────────────────────

    /** El modelo elige plan; el precio lo pone Laravel. Por eso no ve ninguna cifra. */
    public function test_the_catalogue_the_model_receives_never_carries_a_price(): void
    {
        $planes = $this->decide($this->inbound('¿cuánto vale?'))->assertOk()->json('context.active_plans');

        $this->assertNotSame([], $planes, 'hay catálogo que mirar');
        foreach ($planes as $plan) {
            foreach (['price', 'amount', 'valor', 'original_price', 'monthly_price'] as $clave) {
                $this->assertArrayNotHasKey($clave, $plan, 'el catálogo del modelo no lleva '.$clave);
            }
        }
        foreach (['80000', '210000', '80.000', '210.000'] as $cifra) {
            $this->assertStringNotContainsString($cifra, json_encode($planes), 'ninguna cifra del catálogo viaja al modelo');
        }
    }

    /** La membresía viaja como fechas, días, plan y estado. Nunca como persona. */
    public function test_the_membership_facts_travel_without_a_single_personal_datum(): void
    {
        $socia = $this->makeMember(endsInDays: 20);

        $membership = $this->decide($this->inbound('¿cómo va mi plan?'))->assertOk()->json('context.membership');

        $this->assertTrue($membership['identity'], 'hay socia identificada, que es lo que habilita los hechos');
        $this->assertSame(MembershipFactsProvider::STATUS_ACTIVE, $membership['status']);
        $json = json_encode($membership);
        foreach ([$socia->full_name, $socia->email, $socia->document_number, $socia->phone] as $pii) {
            $this->assertStringNotContainsString((string) $pii, $json, 'el dato personal de la socia no es un hecho que el modelo necesite');
        }
    }

    /** Un enlace de pago ya enviado es pagable por quien lo tenga: no vuelve al modelo. */
    public function test_a_payment_link_already_sent_never_comes_back_to_the_model(): void
    {
        $this->enablePaymentEngine();

        $this->commit($this->inbound('dale, mándame el link de pago'), [
            'next_state' => P::CLOSING,
            'intent' => SalesIntents::PAYMENT_LINK_REQUEST,
            'reply_draft' => 'Listo, te paso el link del {{PLAN_NAME}} para que pagues desde el celular.',
            'recommended_plan_id' => $this->plan->id,
            'tools_requested' => [SalesIntents::TOOL_PAYMENT_LINK_SEND],
        ])->assertOk();

        $this->assertSame(1, $this->paymentLinkMessages()->count(), 'el link salió de verdad');

        $recientes = $this->decide($this->inbound('ya lo abrí'))->assertOk()->json('context.recent_messages');
        $json = json_encode($recientes);

        /*
         * El sujeto no ha cambiado y es éste: la URL viva no vuelve al modelo.
         * Ni la firma, ni la llave, ni el dominio.
         */
        $this->assertStringNotContainsString('checkout.wompi.co', $json, 'la URL de cobro no vuelve al modelo');
        $this->assertStringNotContainsString('signature', $json);
        $this->assertStringNotContainsString('public-key', $json);

        /*
         * Lo que cambió es el MARCADOR, porque cambió el mecanismo: desde que
         * el cobro viaja dentro de la respuesta —un solo mensaje visible, no
         * dos— lo que se tapa es la URL dentro del texto, y no el mensaje
         * entero. El hecho sigue viajando: el modelo ve que mandó un enlace.
         */
        $cuerpos = implode("\n", array_column($recientes, 'body'));
        $this->assertStringContainsString('[enlace]', $cuerpos, 'el hecho sí viaja; la URL no');
    }

    // ── Piezas de estas pruebas ──────────────────────────────────────────────

    /** Mensajes de enlaces de la app que salieron por este chat. */
    private function appLinkMessages(): int
    {
        return $this->outbound()->filter(fn ($m) => str_contains((string) $m->body, 'play.google.com'))->count();
    }

    /**
     * Monta el escenario de membresía y devuelve el HECHO tal y como le llega
     * al modelo. Se lee de `context.membership` a propósito: la tortura va
     * contra la fecha que el CRM entrega hoy, no contra una escrita a mano.
     *
     * @return array<string,mixed>
     */
    private function hechosDeMembresia(string $fixture): array
    {
        if ($fixture === 'activa') {
            $this->makeMember(endsInDays: 20, startedDaysAgo: 10);
        } elseif ($fixture === 'vencida') {
            $this->makeMember(endsInDays: -5, startedDaysAgo: 40);
        }

        return (array) $this->decide($this->inbound('¿cómo va mi plan?'))->assertOk()->json('context.membership');
    }

    /** Sustituye en la plantilla los datos reales de la membresía de este escenario. */
    private function poblar(string $plantilla, array $hechos): string
    {
        $reemplazos = [];

        if (is_string($hechos['ends_on'] ?? null)) {
            $fin = CarbonImmutable::parse($hechos['ends_on']);
            $reemplazos['{DIA}'] = (string) $fin->day;
            $reemplazos['{OTRO_DIA}'] = (string) ($fin->day === 1 ? 2 : $fin->day - 1);
            $reemplazos['{MES}'] = self::MESES[$fin->month];
            $reemplazos['{OTRO_MES}'] = self::MESES[$fin->month === 12 ? 1 : $fin->month + 1];
        }

        if (($hechos['days_to_expiry'] ?? null) !== null) {
            $dias = (int) $hechos['days_to_expiry'];
            $reemplazos['{DIAS}'] = (string) $dias;
            // La tolerancia del cerrojo es de un día (el turno puede cruzar la
            // medianoche); dos días ya es otra cifra.
            $reemplazos['{DIAS_MAS_UNO}'] = (string) ($dias + 1);
            $reemplazos['{DIAS_MAS_DOS}'] = (string) ($dias + 2);
        }

        return strtr($plantilla, $reemplazos);
    }
}
