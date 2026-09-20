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
}
