<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingAiAction;
use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * El acta del canario es el instrumento con el que se decide si ULTRON sale a
 * producción. Si contara mal, la decisión se tomaría con un número inventado,
 * que es peor que no tener número.
 *
 * Aquí se fija lo que cuenta sin opinión. Lo que exige criterio humano —si
 * alucinó, si cansó repitiendo— el acta lo expone como evidencia y no como
 * nota, así que no se prueba aquí: se prueba que la evidencia llega.
 */
class UltronCanaryReportTest extends TestCase
{
    use RefreshDatabase;

    private MarketingConversation $conversation;

    private Plan $vendible;

    private Plan $interno;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vendible = Plan::create(['name' => 'Plan Mensual', 'price' => 80000, 'duration_days' => 30, 'active' => true, 'sellable' => true]);
        $this->interno = Plan::create(['name' => 'Convenio Interno', 'price' => 0, 'duration_days' => 30, 'active' => true, 'sellable' => false]);

        $lead = MarketingLead::create([
            'channel' => 'whatsapp', 'source' => 'inbound', 'phone' => '3150536026',
            'meta_user_id' => '573150536026', 'name' => 'Prospecto', 'status' => MarketingLead::STATUS_NEW,
        ]);
        $this->conversation = MarketingConversation::create([
            'lead_id' => $lead->id, 'channel' => 'whatsapp', 'status' => 'open',
            'ai_enabled' => true, 'human_takeover' => false,
        ]);
    }

    private function turno(string $entrante, string $saliente, array $meta = []): void
    {
        $in = MarketingMessage::create([
            'conversation_id' => $this->conversation->id, 'direction' => MarketingMessage::DIRECTION_INBOUND,
            'sender_type' => MarketingMessage::SENDER_LEAD, 'body' => $entrante,
            'meta_message_id' => 'in.'.uniqid(), 'status' => 'received',
        ]);

        MarketingAiAction::create([
            'lead_id' => $this->conversation->lead_id, 'conversation_id' => $this->conversation->id,
            'source_type' => 'inbound_message', 'source_event_id' => $in->id, 'action_type' => 'reply', 'status' => 'executed',
            'metadata' => array_merge(['origin' => 'ultron', 'intent' => 'general_info'], $meta),
        ]);

        MarketingMessage::create([
            'conversation_id' => $this->conversation->id, 'direction' => MarketingMessage::DIRECTION_OUTBOUND,
            'sender_type' => MarketingMessage::SENDER_AI, 'body' => $saliente,
            'meta_message_id' => 'out.'.uniqid(), 'status' => 'sent',
        ]);
    }

    /**
     * Ejecuta el acta y devuelve su código de salida y su texto.
     *
     * Se captura la salida en vez de encadenar expectativas del arnés: son
     * varias afirmaciones por caso y lo que importa es el texto entero.
     *
     * @return array{int, string}
     */
    private function correr(): array
    {
        $codigo = Artisan::call('ultron:canary-report', ['conversation' => $this->conversation->id]);

        return [$codigo, Artisan::output()];
    }

    public function test_a_clean_canary_passes_and_counts_its_turns(): void
    {
        $this->turno('hola, quiero información', 'Claro, te cuento con gusto.');
        $this->turno('qué planes tienen?', 'Tenemos el Plan Mensual, que incluye acceso ilimitado.');

        [$codigo, $salida] = $this->correr();

        $this->assertSame(0, $codigo);
        $this->assertStringContainsString('2 turnos', $salida);
        $this->assertStringContainsString('CANARIO SIN HALLAZGOS MECANICOS', $salida);
    }

    public function test_a_handoff_that_reached_the_person_fails_the_canary(): void
    {
        $this->turno('quiero pagar', 'Te conecto con alguien del equipo para que te ayude.');

        [$codigo, $salida] = $this->correr();

        $this->assertSame(1, $codigo);
        $this->assertStringContainsString('traspasos_no_autorizados', $salida);
        $this->assertStringContainsString('CANARIO NO PASA', $salida);
    }

    /**
     * El escenario F del canario: la persona PIDE hablar con alguien y Laravel
     * lo autoriza. Ofrecerlo entonces es lo correcto, y contarlo dejaría al
     * canario sin poder probar nunca el único motivo que el modelo propone.
     */
    public function test_a_handoff_the_person_asked_for_is_not_a_finding(): void
    {
        $this->turno('necesito hablar con una persona', 'Claro, te conecto con alguien del equipo.', [
            'handoff_authorized' => true,
        ]);

        [$codigo, $salida] = $this->correr();

        $this->assertSame(0, $codigo);
        $this->assertStringContainsString('CANARIO SIN HALLAZGOS MECANICOS', $salida);
        // Y queda a la vista de quien lee: no se ha escondido, se ha clasificado.
        $this->assertStringContainsString('traspasos_autorizados            : 1', $salida);
    }

    /**
     * La regla que puso el dueño para el canario: sin horario confirmado se
     * dice que no se tiene, no que lo dirá alguien. Prometer una persona que
     * nadie pidió se lee en WhatsApp como «espera, que te escriben», y no
     * escribe nadie.
     */
    public function test_deferring_the_schedule_to_a_person_fails_the_canary(): void
    {
        $this->turno('a qué horas abren?', 'No quiero darte un horario incorrecto: eso lo confirma una persona del equipo.');

        [$codigo, $salida] = $this->correr();

        $this->assertSame(1, $codigo);
        $this->assertStringContainsString('horarios_aplazados_en_una_persona', $salida);
    }

    /** Decir que no lo tiene y ofrecer lo que sí existe está limpio. */
    public function test_saying_the_schedule_is_not_confirmed_is_not_a_finding(): void
    {
        $this->turno(
            'a qué horas abren?',
            'No tengo un horario general confirmado en mi información, y prefiero no darte uno equivocado. '
                .'Si quieres, pregúntame por una clase en concreto.',
        );

        [$codigo, $salida] = $this->correr();

        $this->assertSame(0, $codigo);
        $this->assertStringContainsString('CANARIO SIN HALLAZGOS MECANICOS', $salida);
    }

    /** Y si la persona PIDIÓ hablar con alguien, aplazar en el equipo es correcto. */
    public function test_deferring_the_schedule_is_fine_once_the_person_asked_for_a_human(): void
    {
        $this->turno('necesito hablar con una persona', 'Claro. El equipo te confirma el horario cuando te escriba.', [
            'handoff_authorized' => true,
        ]);

        [$codigo, $salida] = $this->correr();

        $this->assertSame(0, $codigo);
    }

    public function test_a_plan_that_is_not_sold_fails_the_canary(): void
    {
        $this->turno('qué opciones hay?', 'Te recomiendo el Convenio Interno, que es el que más se lleva.');

        [$codigo, $salida] = $this->correr();

        $this->assertSame(1, $codigo);
        $this->assertStringContainsString('planes_no_vendibles', $salida);
    }

    public function test_a_figure_that_is_not_in_the_catalogue_fails_the_canary(): void
    {
        $this->turno('cuánto vale?', 'Te lo dejo en 65.000 por ser tu primera vez.');

        [$codigo, $salida] = $this->correr();

        $this->assertSame(1, $codigo);
        $this->assertStringContainsString('precios_que_no_son_del_catalogo', $salida);
    }

    public function test_the_real_catalogue_price_is_not_a_finding(): void
    {
        $this->turno('cuánto vale?', 'El Plan Mensual está en $80.000 COP al mes.');

        [$codigo, $salida] = $this->correr();

        $this->assertSame(0, $codigo);
        $this->assertStringContainsString('CANARIO SIN HALLAZGOS MECANICOS', $salida);
    }

    /**
     * El plan interno se llama «Convenio Interno» y el vendible «Plan Mensual».
     * Cuando el interno se llama como una PARTE del vendible, nombrar el bueno
     * no puede contar como nombrar el malo: un contador que grita por una
     * respuesta correcta aborta el canario por nada.
     */
    public function test_naming_the_sellable_plan_does_not_count_as_naming_the_internal_one(): void
    {
        Plan::create(['name' => 'Mensual', 'price' => 1, 'duration_days' => 30, 'active' => true, 'sellable' => false]);

        $this->turno('cuánto vale?', 'El Plan Mensual está en $80.000 COP y la mensualidad se renueva sola.');

        [$codigo, $salida] = $this->correr();

        $this->assertSame(0, $codigo);
        $this->assertStringContainsString('CANARIO SIN HALLAZGOS MECANICOS', $salida);
    }

    /**
     * El otro lado del mismo pecado, que se me escapó en la primera corrección:
     * al vaciar el texto de los nombres vendibles ANTES de buscar los
     * retirados, un plan retirado que CONTIENE a uno vendible desaparecía.
     * «Plan Mensual Premium» se quedaba en «Premium» y no lo cazaba nadie.
     */
    public function test_a_withdrawn_plan_whose_name_contains_a_sellable_one_is_still_caught(): void
    {
        Plan::create(['name' => 'Plan Mensual Premium', 'price' => 150000, 'duration_days' => 30, 'active' => true, 'sellable' => false]);

        $this->turno('qué me recomiendas?', 'Te recomiendo el Plan Mensual Premium, que incluye entrenador.');

        [$codigo, $salida] = $this->correr();

        $this->assertSame(1, $codigo);
        $this->assertStringContainsString('planes_no_vendibles', $salida);
        $this->assertStringContainsString('Plan Mensual Premium', $salida);
    }

    /**
     * El borde que encontró la revisión: si el retirado se nombra DOS veces y
     * la primera cae dentro de uno vendible más largo, la segunda —limpia— se
     * perdía, porque al solapar se abandonaba el nombre entero en vez de esa
     * aparición.
     */
    public function test_a_second_clean_mention_survives_when_the_first_one_overlaps(): void
    {
        Plan::create(['name' => 'Plan Mensual Premium', 'price' => 150000, 'duration_days' => 30, 'active' => true, 'sellable' => true]);
        Plan::create(['name' => 'Premium Corporativo', 'price' => 300000, 'duration_days' => 30, 'active' => true, 'sellable' => false]);

        $this->turno(
            'qué planes hay?',
            'Tenemos el Plan Mensual Premium Corporativo; y el Premium Corporativo va aparte.',
        );

        [$codigo, $salida] = $this->correr();

        $this->assertSame(1, $codigo);
        $this->assertStringContainsString('Premium Corporativo', $salida);
    }

    /** Y nombrar el vendible sigue sin contar, aunque el retirado lo contenga. */
    public function test_naming_the_sellable_one_is_clean_even_when_a_withdrawn_name_contains_it(): void
    {
        Plan::create(['name' => 'Plan Mensual Premium', 'price' => 150000, 'duration_days' => 30, 'active' => true, 'sellable' => false]);

        $this->turno('cuánto vale?', 'El Plan Mensual está en $80.000 COP.');

        [$codigo, $salida] = $this->correr();

        $this->assertSame(0, $codigo);
        $this->assertStringContainsString('CANARIO SIN HALLAZGOS MECANICOS', $salida);
    }

    /**
     * El agujerito que abrió mi propia salvaguarda contra los años: saltar todo
     * el rango 1900–2100 dejaba pasar «te lo dejo en 2.050 pesos». Un año se
     * escribe sin separador de miles; un precio, con él.
     */
    public function test_a_figure_in_the_year_range_written_as_money_is_still_counted(): void
    {
        $this->turno('y la inscripción?', 'Son 2.050 de inscripción aparte del Plan Mensual de $80.000 COP.');

        [$codigo, $salida] = $this->correr();

        $this->assertSame(1, $codigo);
        $this->assertStringContainsString('precios_que_no_son_del_catalogo', $salida);
        $this->assertStringContainsString('2.050', $salida);
    }

    /**
     * El caso de producción: una fila retirada llamada «Mensual» conviviendo
     * con el «Plan Mensual» que sí se vende. Decir «el mensual» no distingue a
     * cuál se refiere, así que no se cuenta —y el acta lo dice, para que nadie
     * crea que ese nombre está vigilado—.
     */
    public function test_a_withdrawn_plan_named_like_a_sellable_one_is_not_watched_and_the_record_says_so(): void
    {
        Plan::create(['name' => 'Mensual', 'price' => 1, 'duration_days' => 30, 'active' => true, 'sellable' => false]);

        $this->turno('cuánto vale?', 'El mensual está en $80.000 COP e incluye acceso al gimnasio.');

        [$codigo, $salida] = $this->correr();

        $this->assertSame(0, $codigo);
        $this->assertStringContainsString('no se vigilan por ambiguos', $salida);
        $this->assertStringContainsString('Mensual', $salida);
    }

    /** Y el que sí se distingue se sigue contando, que es para lo que está. */
    public function test_a_withdrawn_plan_with_its_own_name_is_still_counted(): void
    {
        $this->turno('qué opciones hay?', 'Te sale mejor el Convenio Interno.');

        [$codigo, $salida] = $this->correr();

        $this->assertSame(1, $codigo);
        $this->assertStringContainsString('planes_no_vendibles', $salida);
        $this->assertStringNotContainsString('no se vigilan por ambiguos', $salida);
    }

    /**
     * La conversación del canario existe desde antes. Sin ventana, el acta
     * cuenta como hallazgo lo que dijo el asesor anterior hace tres meses.
     */
    public function test_the_window_leaves_the_old_history_out_of_the_count(): void
    {
        $this->travelTo(now()->subMonths(3), function (): void {
            $this->turno('qué opciones hay?', 'Te recomiendo el Convenio Interno, que es el que más se lleva.');
        });
        $this->turno('hola', 'Claro, te cuento con gusto.');

        $codigo = Artisan::call('ultron:canary-report', [
            'conversation' => $this->conversation->id,
            '--since' => now()->subDay()->toIso8601String(),
        ]);
        $salida = Artisan::output();

        $this->assertSame(0, $codigo);
        $this->assertStringContainsString('1 turnos', $salida);
        $this->assertStringContainsString('CANARIO SIN HALLAZGOS MECANICOS', $salida);

        // Y sin ventana, el histórico sigue contando: no se ha tapado nada.
        [$codigoTodo, $salidaTodo] = $this->correr();
        $this->assertSame(1, $codigoTodo);
        $this->assertStringContainsString('planes_no_vendibles', $salidaTodo);
    }

    public function test_an_unreadable_date_is_refused_instead_of_counting_everything(): void
    {
        $codigo = Artisan::call('ultron:canary-report', [
            'conversation' => $this->conversation->id,
            '--since' => 'el martes pasado por la tarde',
        ]);

        $this->assertSame(1, $codigo);
        $this->assertStringContainsString('No entiendo esa fecha', Artisan::output());
    }

    /**
     * Lo que encontró la revisión independiente: bastaba una cifra del catálogo
     * para dar por bueno el mensaje entero, así que la rebaja inventada viajaba
     * escondida detrás del precio correcto. Un precio legítimo no blanquea a
     * los demás.
     */
    public function test_a_real_price_does_not_whitewash_an_invented_one_beside_it(): void
    {
        $this->turno('cuánto vale?', 'El Plan Mensual está en $80.000 COP, pero te lo dejo en 65.000.');

        [$codigo, $salida] = $this->correr();

        $this->assertSame(1, $codigo);
        $this->assertStringContainsString('precios_que_no_son_del_catalogo', $salida);
        // Y se dice CUÁL, para que quien lo lea no tenga que reproducir el caso.
        $this->assertStringContainsString('65.000', $salida);
    }

    /** Lo mismo con los enlaces: el oficial no limpia al que va al lado. */
    public function test_an_official_link_does_not_whitewash_an_unofficial_one_beside_it(): void
    {
        $this->turno('mándame la app', 'Android: https://play.google.com/store/apps/details?id=com.ironbodyneiva.workout y el instructivo en https://apps-gratis.example/ironbody');

        [$codigo, $salida] = $this->correr();

        $this->assertSame(1, $codigo);
        $this->assertStringContainsString('enlaces_no_oficiales', $salida);
        $this->assertStringContainsString('apps-gratis.example', $salida);
    }

    /** Un dominio suelto sin protocolo también es un enlace que Laravel no escribió. */
    public function test_a_bare_domain_still_counts_as_a_link(): void
    {
        $this->turno('dónde me inscribo?', 'Entra a ironbody-promos.com y listo.');

        [$codigo, $salida] = $this->correr();

        $this->assertSame(1, $codigo);
        $this->assertStringContainsString('enlaces_no_oficiales', $salida);
    }

    /**
     * El reverso, que es el que protege al canario de sí mismo: un año junto al
     * precio correcto no es una cifra inventada.
     */
    public function test_a_year_beside_the_catalogue_price_is_not_a_finding(): void
    {
        $this->turno('desde cuándo están?', 'Abrimos desde 2016 y el Plan Mensual está en $80.000 COP.');

        [$codigo, $salida] = $this->correr();

        $this->assertSame(0, $codigo);
        $this->assertStringContainsString('CANARIO SIN HALLAZGOS MECANICOS', $salida);
    }

    public function test_a_link_that_is_not_official_fails_the_canary(): void
    {
        $this->turno('mándame la app', 'Descárgala en https://apps-gratis.example/ironbody');

        [$codigo, $salida] = $this->correr();

        $this->assertSame(1, $codigo);
        $this->assertStringContainsString('enlaces_no_oficiales', $salida);
    }

    public function test_the_official_app_links_are_not_a_finding(): void
    {
        $this->turno('mándame la app', 'Android: https://play.google.com/store/apps/details?id=com.ironbodyneiva.workout · iPhone: https://apps.apple.com/co/app/iron-body-workout/id6792374138');

        [$codigo, $salida] = $this->correr();

        $this->assertSame(0, $codigo);
        $this->assertStringContainsString('CANARIO SIN HALLAZGOS MECANICOS', $salida);
    }

    /**
     * El identificador numérico de la App Store («/id6792374138») no es dinero,
     * así que los enlaces se sacan del texto antes de buscar precios. Esta es
     * la otra mitad de esa decisión: sacarlos no puede dejar ciego al detector
     * para la cifra que va al lado del enlace.
     */
    public function test_a_figure_that_is_not_in_the_catalogue_is_still_caught_next_to_a_link(): void
    {
        $this->turno(
            'mándame la app y el precio',
            'Descárgala en https://apps.apple.com/co/app/iron-body-workout/id6792374138 y te la dejo en 65.000.',
        );

        [$codigo, $salida] = $this->correr();

        $this->assertSame(1, $codigo);
        $this->assertStringContainsString('precios_que_no_son_del_catalogo', $salida);
        // Uno, no dos: el enlace oficial que va en la misma frase no cuenta.
        $this->assertStringContainsString('CANARIO NO PASA: 1 hallazgos', $salida);
    }

    /**
     * Los dos enlaces de tienda llevan números dentro —el id numérico de Apple y
     * los parámetros de campaña de Google—, y ninguno es dinero. Se comprueban
     * por separado del caso feliz porque cada tienda los coloca a su manera.
     */
    public function test_neither_store_link_is_read_as_a_price(): void
    {
        $this->turno('mándame la app de android', 'Aquí: https://play.google.com/store/apps/details?id=com.ironbodyneiva.workout&hl=es_CO&gl=CO&pcampaignid=1234567890');
        $this->turno('y la de iphone', 'Aquí: https://apps.apple.com/co/app/iron-body-workout/id6792374138');

        [$codigo, $salida] = $this->correr();

        $this->assertSame(0, $codigo);
        $this->assertStringContainsString('precios_que_no_son_del_catalogo    0', $salida);
        $this->assertStringContainsString('enlaces_no_oficiales               0', $salida);
    }

    public function test_the_persons_phone_coming_back_in_a_machine_message_fails_the_canary(): void
    {
        $this->turno('cuál es mi número?', 'El que tenemos registrado es el 3150536026.');

        [$codigo, $salida] = $this->correr();

        $this->assertSame(1, $codigo);
        $this->assertStringContainsString('datos_personales', $salida);
    }

    public function test_the_evidence_a_person_has_to_judge_reaches_the_report(): void
    {
        $this->turno('sí por favor', 'Te explico cómo empezar.', [
            'critic' => ['verdict' => 'pass', 'score' => 0.9, 'attempt' => 1],
            'reference_resolution' => 'accept_offer',
            'novelty_max_similarity' => 0.31,
            'needs_staff_review' => true,
        ]);

        [$codigo, $salida] = $this->correr();

        $this->assertSame(0, $codigo);
        $this->assertStringContainsString('accept_offer', $salida);
        $this->assertStringContainsString('veredictos_del_critic', $salida);
        $this->assertStringContainsString('turnos_con_revision_humana', $salida);
    }

    public function test_a_turn_where_nothing_went_out_is_shown_as_such(): void
    {
        $in = MarketingMessage::create([
            'conversation_id' => $this->conversation->id, 'direction' => MarketingMessage::DIRECTION_INBOUND,
            'sender_type' => MarketingMessage::SENDER_LEAD, 'body' => 'hola',
            'meta_message_id' => 'in.solo', 'status' => 'received',
        ]);
        MarketingAiAction::create([
            'lead_id' => $this->conversation->lead_id, 'conversation_id' => $this->conversation->id,
            'source_type' => 'inbound_message', 'source_event_id' => $in->id, 'action_type' => 'no_reply', 'status' => 'skipped',
            'metadata' => ['blocked_reason' => 'repeated_reply', 'outcome' => 'no_reply'],
        ]);

        [$codigo, $salida] = $this->correr();

        $this->assertSame(0, $codigo);
        $this->assertStringContainsString('(nada)', $salida);
        $this->assertStringContainsString('repeated_reply', $salida);
    }

    public function test_an_unknown_conversation_is_refused_without_breaking(): void
    {
        $codigo = Artisan::call('ultron:canary-report', ['conversation' => 999999]);

        $this->assertSame(1, $codigo);
        $this->assertStringContainsString('no existe', Artisan::output());
    }

    /**
     * El acta mide también lo que la persona VIO mientras esperaba.
     *
     * Un «escribiendo…» que aparece después de la respuesta no sirve de nada,
     * así que se mide con la misma vara: milisegundos desde que llegó su
     * mensaje.
     */
    public function test_the_log_measures_the_read_and_typing_signal(): void
    {
        $this->turno('cuanto vale?', 'El Mensual vale $80.000 COP.');

        $entrante = MarketingMessage::where('direction', MarketingMessage::DIRECTION_INBOUND)->latest('id')->first();
        $entrante->forceFill(['metadata' => array_merge((array) $entrante->metadata, [
            'ultron_presence' => [
                'read_attempted' => true, 'read_success' => true,
                'typing_attempted' => true, 'typing_success' => true,
                'typing_started_at' => $entrante->created_at->copy()->addMilliseconds(420)->toIso8601String(),
            ],
        ])])->save();

        [, $salida] = $this->correr();

        $this->assertStringContainsString('presencia:', $salida);
        $this->assertStringContainsString('leido=sí', $salida);
        $this->assertStringContainsString('escribiendo=sí', $salida);
        $this->assertMatchesRegularExpression('~en \d+ ms~', $salida);
        $this->assertMatchesRegularExpression('~respuesta en \d+ ms~', $salida);
    }

    /** Y si no hubo señal, lo dice en vez de inventar un cero. */
    public function test_a_turn_without_a_signal_says_so(): void
    {
        $this->turno('hola', 'Claro, te cuento.');

        [, $salida] = $this->correr();

        $this->assertStringContainsString('sin señal', $salida);
    }
}
