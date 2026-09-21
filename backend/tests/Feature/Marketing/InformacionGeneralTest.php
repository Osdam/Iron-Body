<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Models\Plan;
use App\Services\Marketing\CommercialPhaseMachine as P;
use App\Services\Marketing\SalesConversationReplyService;
use App\Services\Marketing\SalesIntents;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * PEDIR INFORMACIÓN DEL GIMNASIO TERMINA EN INFORMACIÓN DEL GIMNASIO.
 *
 * Nace de un incidente físico completo, con sus cuatro mensajes. Alguien pidió
 * información general tres veces y un «hola», y lo que pasó fue esto:
 *
 *   turno 1 → respondió preguntando el objetivo, sin contar nada.
 *   turno 2 → «El mensual te sirve para entrenar constante…»: un plan que
 *             nadie eligió (`recommended_plan_id` iba en null) porque ese
 *             texto estaba escrito a mano en el código.
 *   turnos 3, 4 y 5 → SILENCIO. Nada. Y el vigía en verde.
 *
 * La cadena, medida: el critic tumbaba el borrador —que era correcto— y el
 * respaldo era UN solo texto por intención; al repetirse, la guarda
 * antirrepetición lo anulaba y el turno salía mudo.
 *
 * Lo que se fija aquí es la regla, no las frases: un entrante atendible termina
 * en un saliente visible, y las excepciones son gobernadas y se cuentan con los
 * dedos.
 */
class InformacionGeneralTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-internal-secret';

    /** Los cuatro mensajes del incidente, con su ortografía real. */
    private const MENSAJES_REALES = [
        'Hola buenas tardes, quisiera mas informacion del gimnasio',
        'quiero informacion del gimnasio',
        'necesito infomracion sobre el gimnasio',
        'hola',
    ];

    private MarketingLead $lead;

    private MarketingConversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('marketing.ultron.enabled', true);
        config()->set('automation.internal_secret', self::SECRET);
        config()->set('meta.enabled', false);
        config()->set('marketing.ai.enabled', true);
        config()->set('marketing.ai.driver', 'fake');
        config()->set('marketing.inbound.auto_analyze', false);
        config()->set('marketing.ultron.payment_links_enabled', false);
        Http::fake();

        Plan::create(['name' => 'Plan Mensual', 'price' => 80000, 'duration_days' => 30, 'active' => true, 'sellable' => true, 'sort_order' => 3]);
        Plan::create(['name' => 'TOTAL ACCESS - ELITE', 'price' => 180000, 'duration_days' => 30, 'active' => true, 'sellable' => true, 'sort_order' => 108, 'is_recommended' => true]);

        $this->lead = MarketingLead::create([
            'channel' => 'whatsapp', 'source' => 'inbound', 'phone' => '3150536026',
            'meta_user_id' => '573150536026', 'name' => 'Prospecto', 'status' => MarketingLead::STATUS_NEW,
        ]);

        $this->conversation = MarketingConversation::create([
            'lead_id' => $this->lead->id, 'channel' => 'whatsapp', 'status' => 'open',
            'ai_enabled' => true, 'human_takeover' => false, 'commercial_phase' => P::NEW_LEAD,
        ]);
    }

    private function h(): array
    {
        return ['Authorization' => 'Bearer '.self::SECRET];
    }

    /** Un turno con el critic TUMBANDO el borrador, que es el caso del incidente. */
    private function turnoConCriticCaido(string $texto, string $wamid, array $overrides = []): TestResponse
    {
        $m = MarketingMessage::create([
            'conversation_id' => $this->conversation->id, 'direction' => MarketingMessage::DIRECTION_INBOUND,
            'sender_type' => MarketingMessage::SENDER_LEAD, 'body' => $texto,
            'meta_message_id' => $wamid, 'status' => 'received',
        ]);
        $token = (string) $this->postJson('/api/internal/marketing/ai/decide', [
            'conversation_id' => $this->conversation->id, 'message_id' => $m->id,
        ], $this->h())->json('decide_token');

        return $this->postJson('/api/internal/marketing/ai/commit', array_replace_recursive([
            'source_type' => 'inbound_message', 'source_event_id' => $m->id, 'idempotency_key' => $wamid,
            'conversation_id' => $this->conversation->id, 'decide_token' => $token,
            'proposal' => [
                'next_state' => P::DISCOVERY, 'intent' => SalesIntents::GENERAL_INFO,
                'reply_draft' => 'Un borrador correcto que el critic tumbó igualmente.',
                'confidence' => 0.8, 'tools_requested' => [],
            ],
            'critic' => ['verdict' => 'fail', 'attempt' => 2, 'score' => 0.3, 'issues' => [], 'hard_fail' => null],
        ], $overrides), $this->h());
    }

    /** @return string[] */
    private function salientes(): array
    {
        return MarketingMessage::where('conversation_id', $this->conversation->id)
            ->where('direction', MarketingMessage::DIRECTION_OUTBOUND)
            ->orderBy('id')->pluck('body')->map(fn ($b) => mb_strtolower((string) $b))->all();
    }

    /**
     * EL INCIDENTE ENTERO: los cuatro mensajes reciben respuesta.
     *
     * Con el critic cayendo en los cuatro, que es lo que pasó de verdad.
     */
    public function test_los_cuatro_mensajes_del_incidente_reciben_respuesta(): void
    {
        foreach (self::MENSAJES_REALES as $i => $texto) {
            $this->turnoConCriticCaido($texto, 'w.inc.'.$i)->assertOk();
        }

        $salientes = $this->salientes();

        $this->assertCount(4, $salientes, 'alguno de los cuatro mensajes se quedó sin respuesta');

        foreach ($salientes as $i => $s) {
            $this->assertNotSame('', trim($s), "el turno {$i} salió vacío");
            $this->assertStringNotContainsString('mensual', $s, "el turno {$i} nombró un plan que nadie eligió");
        }

        // Y que el agente se atragante no es trabajo para una persona.
        $this->assertFalse(
            (bool) $this->conversation->fresh()->staff_review_pending,
            'un fallo interno del agente acabó en la bandeja de una persona',
        );
    }

    /**
     * La antirrepetición no puede convertir el último recurso en silencio.
     *
     * Éste es el fallo exacto: el texto curado se gasta en el primer turno y a
     * partir del segundo la guarda de duplicados lo anulaba.
     */
    public function test_repetir_la_misma_intencion_no_acaba_en_silencio(): void
    {
        foreach (range(1, 5) as $i) {
            $this->turnoConCriticCaido('necesito informacion sobre el gimnasio', 'w.rep.'.$i)->assertOk();
        }

        $salientes = $this->salientes();

        $this->assertCount(5, $salientes, 'la guarda antirrepetición dejó turnos mudos');
        $this->assertGreaterThanOrEqual(
            2,
            count(array_unique($salientes)),
            'se repitió siempre el mismo texto: las variantes del último recurso no entran',
        );
    }

    /** El texto curado de información general no nombra ningún plan. */
    public function test_el_texto_curado_no_nombra_un_plan(): void
    {
        $curado = mb_strtolower((string) (new SalesConversationReplyService)->replyFor(SalesIntents::GENERAL_INFO));

        $this->assertNotSame('', trim($curado));
        foreach (['mensual', 'trimestre', 'semestre', 'anualidad', 'total access', '$'] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, $curado, "el texto curado nombra «{$prohibido}»");
        }
        $this->assertStringContainsString('?', $curado, 'el texto curado no deja una puerta abierta');
    }

    /** Y ninguna variante del último recurso nombra un plan tampoco. */
    public function test_ninguna_variante_del_ultimo_recurso_nombra_un_plan(): void
    {
        $servicio = new SalesConversationReplyService;

        foreach ([SalesIntents::GENERAL_INFO, SalesIntents::PRICING_QUESTION, SalesIntents::LOCATION_QUESTION, SalesIntents::UNKNOWN] as $intent) {
            $variantes = $servicio->lastResorts($intent);
            $this->assertNotEmpty($variantes, "sin último recurso para {$intent}");

            foreach ($variantes as $v) {
                $bajo = mb_strtolower($v);
                $this->assertNotSame('', trim($v));
                foreach (['mensual', 'total access', '$'] as $prohibido) {
                    $this->assertStringNotContainsString($prohibido, $bajo, "«{$intent}» nombra «{$prohibido}»");
                }
            }
        }
    }

    /**
     * Y la excepción gobernada SIGUE siendo muda.
     *
     * Quien pide que no le escriban no recibe un texto de respaldo: ésa es la
     * única puerta que la regla «entrante atendible → saliente visible» deja
     * abierta, y abrirla de más sería peor que el fallo que se arregla.
     */
    public function test_quien_pide_que_no_le_escriban_sigue_sin_recibir_respuesta(): void
    {
        $this->turnoConCriticCaido('no me escriban mas', 'w.dnc', [
            'proposal' => [
                'intent' => SalesIntents::DO_NOT_CONTACT_REQUEST,
                'next_state' => P::DO_NOT_CONTACT,
                'tools_requested' => [SalesIntents::TOOL_MARK_DNC],
            ],
        ])->assertOk()->assertJsonPath('fallback_mode', 'NO_REPLY_AND_HANDOFF');

        $this->assertSame([], $this->salientes());
        $this->assertTrue((bool) $this->lead->fresh()->do_not_contact);
    }

    /**
     * EL BORRADOR REAL QUE EL CRITIC TUMBÓ, COMO FIXTURE.
     *
     * Es literal: lo escribió el Composer en el turno de las 12:32 del
     * incidente y el Critic lo rechazó con `score 0.3` por «no hace la
     * pregunta necesaria para entender el objetivo». Era correcto —cuenta qué
     * es el gimnasio y deja una puerta abierta—, así que lo que estaba roto
     * era el juez, no el mensaje.
     *
     * Aquí se fija la mitad que SÍ se puede probar sin llamar al modelo: que
     * si el Critic lo aprueba, el backend lo deja salir entero. El criterio
     * del Critic vive en el prompt de n8n y sólo se comprueba con un turno
     * real; lo que no puede pasar es que además lo rompa el backend.
     */
    public function test_el_borrador_real_rechazado_sale_intacto_si_el_critic_lo_aprueba(): void
    {
        $real = 'Buenas tardes, Iron Body Neiva es un gimnasio en Neiva con zona de pesas, maquinaria y asesoría '
            .'semi personalizada. Tenemos entrenadores especializados en musculación, funcional, cardio y más. '
            .'¿Qué te gustaría conocer primero?';

        $this->turnoConCriticCaido('quiero informacion del gimnasio', 'w.fixture', [
            'proposal' => ['reply_draft' => $real],
            'critic' => ['verdict' => 'pass', 'attempt' => 1, 'score' => 0.9],
        ])->assertOk();

        $salientes = $this->salientes();

        $this->assertCount(1, $salientes);
        $this->assertStringContainsString('iron body neiva es un gimnasio', $salientes[0]);
        $this->assertStringNotContainsString('mensual', $salientes[0]);
        $this->assertFalse((bool) $this->conversation->fresh()->staff_review_pending);
    }
}
