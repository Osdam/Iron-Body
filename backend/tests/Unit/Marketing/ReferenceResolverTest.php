<?php

namespace Tests\Unit\Marketing;

use App\Services\Marketing\Ultron\ConversationMemory;
use App\Services\Marketing\Ultron\ReferenceResolver as R;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A qué se refiere la persona cuando no lo dice. Todo determinista, sin modelo.
 */
class ReferenceResolverTest extends TestCase
{
    private const PLANS = [
        ['id' => 20, 'name' => 'Plan Mensual', 'benefits' => []],
        ['id' => 21, 'name' => 'Plan Trimestral', 'benefits' => []],
        ['id' => 22, 'name' => 'Plan Élite', 'benefits' => []],
    ];

    /** El catálogo real del gimnasio tiene un plan llamado «Plan Semana». */
    private const PLANES_CON_SEMANA = [
        ['id' => 20, 'name' => 'Plan Mensual', 'benefits' => []],
        ['id' => 23, 'name' => 'Plan Semana', 'benefits' => []],
    ];

    private R $r;

    protected function setUp(): void
    {
        parent::setUp();
        $this->r = new R;
    }

    private function memoryWithOffer(string $kind, ?int $plan = 20): ConversationMemory
    {
        return ConversationMemory::empty()
            ->recommend([20, 22], '2026-09-17T00:00:00Z')
            ->agentAsked('¿Quieres que te explique cómo empezar?', $kind, $plan, '2026-09-17T00:00:00Z', 1);
    }

    /** El caso histórico: «sí por favor» tras «¿quieres que te explique cómo empezar?». */
    public static function afirmaciones(): array
    {
        return [['sí por favor'], ['si por favor'], ['si'], ['Sí'], ['dale'], ['listo'], ['claro'], ['de una'], ['ok'], ['vale'], ['perfecto, si'], ['si claro']];
    }

    #[DataProvider('afirmaciones')]
    public function test_an_affirmation_accepts_the_pending_offer(string $texto): void
    {
        $d = $this->r->resolve($texto, $this->memoryWithOffer('explain_how_to_start'), self::PLANS);

        $this->assertSame(R::ACCEPT_OFFER, $d['type'], "«{$texto}» debería aceptar la oferta");
        $this->assertSame('explain_how_to_start', $d['offer_kind']);
        $this->assertSame(20, $d['plan_id']);
    }

    public function test_an_affirmation_without_any_offer_is_flagged_as_such(): void
    {
        $d = $this->r->resolve('si por favor', ConversationMemory::empty(), self::PLANS);

        $this->assertSame(R::AFFIRMATION_WITHOUT_OFFER, $d['type']);
    }

    public function test_a_negation_declines_the_pending_offer(): void
    {
        foreach (['no', 'no gracias', 'ahora no', 'luego'] as $t) {
            $d = $this->r->resolve($t, $this->memoryWithOffer('plan_details'), self::PLANS);
            $this->assertSame(R::DECLINE_OFFER, $d['type'], "«{$t}»");
        }
    }

    public static function masInformacion(): array
    {
        return [['cuéntame más'], ['uy que bueno cuentame mas'], ['dime más'], ['qué incluye?'], ['más detalles'], ['explícame']];
    }

    #[DataProvider('masInformacion')]
    public function test_more_info_points_at_the_plan_under_discussion(string $texto): void
    {
        $d = $this->r->resolve($texto, $this->memoryWithOffer('plan_details'), self::PLANS);

        $this->assertSame(R::MORE_INFO, $d['type'], "«{$texto}»");
        $this->assertSame(20, $d['plan_id']);
    }

    public function test_and_how_much_asks_the_price_of_the_pending_plan(): void
    {
        foreach (['y cuánto?', 'cuanto vale', 'precio', 'y el precio?', 'cuánto cuesta'] as $t) {
            $d = $this->r->resolve($t, $this->memoryWithOffer('plan_details'), self::PLANS);
            $this->assertSame(R::PRICE_OF_PENDING, $d['type'], "«{$t}»");
            $this->assertSame(20, $d['plan_id']);
        }
    }

    public function test_how_do_i_start_is_resolved_before_anything_else(): void
    {
        foreach (['cómo hago?', 'como empiezo', 'cómo me inscribo', 'qué tengo que hacer?'] as $t) {
            $this->assertSame(R::HOW_TO_START, $this->r->resolve($t, $this->memoryWithOffer('plan_details'), self::PLANS)['type'], "«{$t}»");
        }
    }

    public function test_send_it_means_the_pending_plan(): void
    {
        foreach (['mándamelo', 'mandame el link', 'envíamelo', 'pásame el enlace'] as $t) {
            $d = $this->r->resolve($t, $this->memoryWithOffer('plan_details'), self::PLANS);
            $this->assertSame(R::SEND_IT, $d['type'], "«{$t}»");
            $this->assertSame(20, $d['plan_id']);
        }
    }

    public function test_choosing_a_plan_by_name_ordinal_or_deictic(): void
    {
        $m = $this->memoryWithOffer('plan_details');

        $this->assertSame([R::CHOOSE_PLAN, 20], $this->pair($this->r->resolve('el mensual', $m, self::PLANS)));
        $this->assertSame([R::CHOOSE_PLAN, 20], $this->pair($this->r->resolve('si el Mensual', $m, self::PLANS)));
        $this->assertSame([R::CHOOSE_PLAN, 22], $this->pair($this->r->resolve('quiero el élite', $m, self::PLANS)));
        $this->assertSame([R::CHOOSE_PLAN, 20], $this->pair($this->r->resolve('el primero', $m, self::PLANS)));
        $this->assertSame([R::CHOOSE_PLAN, 22], $this->pair($this->r->resolve('el segundo', $m, self::PLANS)));
        $this->assertSame([R::CHOOSE_PLAN, 20], $this->pair($this->r->resolve('quiero ese', $m, self::PLANS)));
        $this->assertSame([R::CHOOSE_PLAN, 20], $this->pair($this->r->resolve('eso', $m, self::PLANS)));
    }

    /** Sin oferta pendiente pero con plan en la mesa, «sí» elige ese plan. */
    public function test_yes_with_a_pending_plan_but_no_offer_chooses_the_plan(): void
    {
        $m = ConversationMemory::empty()->recommend([20], 'x')->agentAsked('¿Te sirve el horario de la mañana?', null, 20, 'x', 1);
        $d = $this->r->resolve('si', $m, self::PLANS);

        $this->assertSame([R::CHOOSE_PLAN, 20], $this->pair($d));
    }

    /** El otro caso histórico: rechazar a la persona y exigir respuesta. */
    public function test_refusing_a_human_carries_the_unresolved_question(): void
    {
        $data = $this->memoryWithOffer('plan_details')->toArray();
        $data['unresolved_question'] = ['text' => '¿cuántos entrenadores tiene el gimnasio?', 'at' => 'x', 'message_id' => 9];
        $m = ConversationMemory::fromArray($data);

        foreach (['no quiero alguien del equipo, contesta tú', 'contesta', 'no quiero hablar con una persona', 'sigamos tu y yo', 'respóndeme tú'] as $t) {
            $d = $this->r->resolve($t, $m, self::PLANS);
            $this->assertSame(R::REFUSE_HUMAN, $d['type'], "«{$t}»");
            $this->assertSame(['source' => 'lead_verbatim', 'text' => '¿cuántos entrenadores tiene el gimnasio?'], $d['unresolved_question']);
        }
    }

    // ── Regresiones de la revisión independiente ─────────────────────────────

    /** WhatsApp colombiano viene con emoji, letras repetidas y muletillas. */
    public function test_noise_does_not_break_an_affirmation(): void
    {
        $m = $this->memoryWithOffer('explain_how_to_start');
        foreach (['si por favor 🙏', 'Sí, por favor 🙏', 'dale 💪', 'listo 👍', 'ok 👌', 'siii', 'sii dale', 'dale pues', 'si señor', 'está bien', 'si porfa', 'de una 🔥', 'claro que sí'] as $t) {
            $this->assertSame(R::ACCEPT_OFFER, $this->r->resolve($t, $m, self::PLANS)['type'], "«{$t}»");
        }
    }

    /** La negación manda: lo negado no es lo pedido. */
    public function test_a_negated_pattern_is_not_the_request(): void
    {
        $m = $this->memoryWithOffer('send_payment_link');
        foreach (['no me mandes el link por ahora', 'no quiero el link todavia', 'todavia no quiero el link', 'no me abre el link'] as $t) {
            $this->assertNotSame(R::SEND_IT, $this->r->resolve($t, $m, self::PLANS)['type'], "«{$t}»");
        }
        $this->assertNotSame(R::HOW_TO_START, $this->r->resolve('no quiero que me expliquen como empiezo', $m, self::PLANS)['type']);

        $d = $this->r->resolve('no me interesa el mensual', $m, self::PLANS);
        $this->assertSame(R::DECLINE_OFFER, $d['type']);
        $this->assertSame(20, $d['plan_id']);
    }

    /** Pedir una persona no es rechazarla. */
    public function test_asking_for_a_human_is_never_refusing_one(): void
    {
        $m = $this->memoryWithOffer('plan_details');
        foreach (['me responde un asesor?', 'nadie contesta el teléfono, quiero hablar con una persona', 'no me pases más info, quiero hablar con un asesor', 'respóndeme rápido, necesito un humano', 'pásame con alguien', 'quiero hablar con una persona'] as $t) {
            $this->assertNotSame(R::REFUSE_HUMAN, $this->r->resolve($t, $m, self::PLANS)['type'], "«{$t}»");
        }
    }

    /** Un deíctico sólo elige en una elección desnuda. */
    public function test_a_deictic_inside_an_objection_chooses_nothing(): void
    {
        $m = $this->memoryWithOffer('plan_details');
        foreach (['eso es muy caro', 'eso no me sirve', 'no quiero ese', 'ese no', 'este mes no', 'a qué hora abren este sábado?'] as $t) {
            $d = $this->r->resolve($t, $m, self::PLANS);
            $this->assertNotSame(R::CHOOSE_PLAN, $d['type'], "«{$t}» → {$d['type']}");
        }
        $this->assertSame(R::CHOOSE_PLAN, $this->r->resolve('quiero ese', $m, self::PLANS)['type']);
        $this->assertSame(R::CHOOSE_PLAN, $this->r->resolve('el mismo', $m, self::PLANS)['type']);
    }

    /** Planes con nombre de diccionario: «esta semana no puedo» no compra el Plan Semana. */
    public function test_common_noun_plan_names_need_a_choice_around_them(): void
    {
        $plans = [['id' => 1, 'name' => 'Plan Semana'], ['id' => 5, 'name' => 'Semestre'], ['id' => 8, 'name' => 'Pro'], ['id' => 24, 'name' => 'Plan Familia']];
        $m = ConversationMemory::empty();
        foreach (['esta semana no puedo', 'la otra semana voy', 'puedo ir la próxima semana?', 'este fin de semana están abiertos?', 'mejor el otro semestre', 'soy pro en pesas', 'mi familia va conmigo'] as $t) {
            $d = $this->r->resolve($t, $m, $plans);
            $this->assertNotSame(R::CHOOSE_PLAN, $d['type'], "«{$t}» → {$d['type']}");
        }
        $this->assertSame([R::CHOOSE_PLAN, 5], $this->pair($this->r->resolve('el semestre', $m, $plans)));
        $this->assertSame([R::CHOOSE_PLAN, 1], $this->pair($this->r->resolve('quiero el plan semana', $m, $plans)));
        $this->assertSame([R::CHOOSE_PLAN, 24], $this->pair($this->r->resolve('plan familia', $m, $plans)));
        $this->assertSame([R::CHOOSE_PLAN, 8], $this->pair($this->r->resolve('el pro', $m, $plans)));
    }

    /** «cuánto vale el mensual» pregunta, no compra. */
    public function test_a_price_question_naming_a_plan_is_a_price_question(): void
    {
        $m = $this->memoryWithOffer('plan_details');
        $this->assertSame([R::PRICE_OF_PENDING, 20], $this->pair($this->r->resolve('cuánto vale el mensual?', $m, self::PLANS)));
        $this->assertSame([R::PRICE_OF_PENDING, 22], $this->pair($this->r->resolve('y cuánto sale el élite', $m, self::PLANS)));
        $this->assertSame([R::PRICE_OF_PENDING, 21], $this->pair($this->r->resolve('precio del trimestral', $m, self::PLANS)));
        $this->assertNotSame(R::PRICE_OF_PENDING, $this->r->resolve('y cuánto tiempo dura?', $m, self::PLANS)['type']);
    }

    /** Una oferta deja de valer en cuanto el agente pregunta otra cosa. */
    public function test_an_offer_expires_with_the_next_agent_question(): void
    {
        $m = ConversationMemory::empty()->recommend([20], 'x')
            ->agentAsked('¿Quieres que te mande el link de pago?', 'send_payment_link', 20, 'x', 1)
            ->agentAsked('¿Te sirve el horario de la mañana?', null, 20, 'x', 2);

        $this->assertNull($m->get('last_agent_offer'));
        $this->assertNotSame(R::ACCEPT_OFFER, $this->r->resolve('dale', $m, self::PLANS)['type']);

        $m->agentAsked(null, null, 20, 'x', 3);
        $this->assertNotSame(R::ACCEPT_OFFER, $this->r->resolve('si', $m, self::PLANS)['type']);
    }

    /** Un plan recordado que dejó de ser vendible no se resuelve. */
    public function test_a_remembered_plan_that_is_no_longer_sellable_is_dropped(): void
    {
        $m = ConversationMemory::empty()->recommend([99], 'x')->agentAsked('¿Quieres que te cuente más detalles?', 'plan_details', 99, 'x', 1);

        $this->assertNull($this->r->resolve('y cuánto?', $m, self::PLANS)['plan_id']);
        $this->assertNull($this->r->resolve('cuéntame más', $m, self::PLANS)['plan_id']);
        $this->assertNull($this->r->resolve('quiero ese', $m, self::PLANS)['plan_id'] ?? null);
    }

    public function test_ordinary_sentences_resolve_to_nothing(): void
    {
        foreach (['quiero informacion de los planes', 'tienes informacion de cuantos entrenadores tiene el gimnasio?', 'hola', 'estoy interesado en bajar de peso'] as $t) {
            $this->assertSame(R::NONE, $this->r->resolve($t, $this->memoryWithOffer('plan_details'), self::PLANS)['type'], "«{$t}»");
        }
    }

    /** @return array{0:string,1:?int} */
    private function pair(array $d): array
    {
        return [$d['type'], $d['plan_id']];
    }

    // ── Una FRECUENCIA no es una elección de plan ────────────────────────────

    /**
     * Lo encontró el canario físico en el séptimo mensaje: la persona escribió
     * «6 dias a la semana» —cuántas veces entrena— y el resolutor lo leyó como
     * elegir el «Plan Semana». La propuesta salió cotizando el plan semanal a
     * alguien que entrena seis días y que venía mirando el mensual.
     *
     * El nombre de un plan que además es una palabra de tiempo («Semana»,
     * «Mensual») no puede elegirse desde una expresión de ritmo.
     */
    #[DataProvider('frecuencias')]
    public function test_a_frequency_never_chooses_a_plan(string $texto): void
    {
        $r = $this->r->resolve($texto, ConversationMemory::empty(), self::PLANES_CON_SEMANA);

        $this->assertSame(R::NONE, $r['type'], $texto.' no elige ningún plan');
        $this->assertNull($r['plan_id']);
    }

    public static function frecuencias(): array
    {
        return [
            ['6 dias a la semana'],
            ['6 veces a la semana'],
            ['entreno 5 dias por semana'],
            ['vengo cada semana'],
            ['esta semana no puedo'],
            ['voy 3 veces al mes'],
        ];
    }

    /** Y elegirlo de verdad sigue funcionando, que es la otra mitad. */
    #[DataProvider('eleccionesDeVerdad')]
    public function test_choosing_that_plan_still_works(string $texto, int $plan): void
    {
        $r = $this->r->resolve($texto, ConversationMemory::empty(), self::PLANES_CON_SEMANA);

        $this->assertSame(R::CHOOSE_PLAN, $r['type'], $texto.' sí elige');
        $this->assertSame($plan, $r['plan_id']);
    }

    public static function eleccionesDeVerdad(): array
    {
        return [
            ['quiero el plan semana', 23],
            ['me quedo con la semana', 23],
            ['semana', 23],
            ['quiero el mensual', 20],
            ['el plan mensual', 20],
        ];
    }
}
