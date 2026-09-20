<?php

namespace Tests\Feature\Marketing;

use App\Models\Incident;
use App\Models\MarketingAiAction;
use App\Models\MarketingAutomationEvent;
use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Models\UltronAbort;
use App\Services\Marketing\Ultron\UltronAbortLatch;
use App\Services\Marketing\Ultron\UltronEventEmitter;
use App\Services\Marketing\Ultron\UltronSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * EL VIGÍA Y EL FRENO.
 *
 * El vigía es la única pieza capaz de ver un silencio, porque un silencio no
 * deja fila que revisar. Estas pruebas fijan las dos mitades: que encuentra al
 * que preguntó y no recibió nada, y que NO acusa a quien sí recibió respuesta
 * —un vigía que grita siempre se acaba apagando, y entonces no vigila nadie—.
 *
 * Y fijan la regla del freno: sólo empuja en un sentido. Soltarlo no enciende
 * ULTRON, y soltarlo exige que alguien firme.
 */
class UltronTurnWatchdogTest extends TestCase
{
    use RefreshDatabase;

    private MarketingLead $lead;

    private MarketingConversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('marketing.ultron.enabled', true);
        config()->set('meta.enabled', false);
        Http::fake();

        $this->lead = MarketingLead::create([
            'channel' => 'whatsapp', 'source' => 'inbound', 'phone' => '3150536026',
            'meta_user_id' => '573150536026', 'name' => 'Prospecto', 'status' => MarketingLead::STATUS_NEW,
        ]);

        $this->conversation = MarketingConversation::create([
            'lead_id' => $this->lead->id, 'channel' => 'whatsapp', 'status' => 'open',
            'ai_enabled' => true, 'human_takeover' => false,
        ]);
    }

    // ── Andamio ───────────────────────────────────────────────────────────────

    private function entrante(string $body = 'hola'): MarketingMessage
    {
        return MarketingMessage::create([
            'conversation_id' => $this->conversation->id,
            'direction' => MarketingMessage::DIRECTION_INBOUND,
            'sender_type' => MarketingMessage::SENDER_LEAD,
            'body' => $body, 'meta_message_id' => 'wamid.'.uniqid(), 'status' => 'received',
        ]);
    }

    private function evento(MarketingMessage $m, string $status = MarketingAutomationEvent::STATUS_SENT, int $hace = 60): MarketingAutomationEvent
    {
        $e = MarketingAutomationEvent::create([
            'event_type' => MarketingAutomationEvent::TYPE_MESSAGE_RECEIVED,
            'lead_id' => $this->lead->id,
            'conversation_id' => $this->conversation->id,
            'message_id' => $m->id,
            'payload_json' => ['message_id' => $m->id],
            'status' => $status,
            'idempotency_key' => 'evt:'.$m->id,
        ]);

        // El vigía sólo mira turnos ya vencidos: se envejece el evento a mano.
        $e->forceFill(['created_at' => now()->subMinutes($hace)])->save();

        return $e;
    }

    private function desenlace(MarketingMessage $m): MarketingAiAction
    {
        return MarketingAiAction::create([
            'lead_id' => $this->lead->id,
            'conversation_id' => $this->conversation->id,
            'action_type' => 'reply',
            'status' => 'executed',
            'source_type' => UltronSource::INBOUND_MESSAGE,
            'source_event_id' => $m->id,
            'idempotency_key' => 'act:'.$m->id,
            'metadata' => ['origin' => 'ultron'],
        ]);
    }

    private function saliente(string $quien = MarketingMessage::SENDER_AI): MarketingMessage
    {
        return MarketingMessage::create([
            'conversation_id' => $this->conversation->id,
            'direction' => MarketingMessage::DIRECTION_OUTBOUND,
            'sender_type' => $quien,
            'body' => 'respuesta', 'status' => 'dry_run',
        ]);
    }

    private function vigia(array $opciones = []): int
    {
        return $this->artisan('ultron:turn-watchdog', $opciones)->run();
    }

    // ── 1 · Qué es y qué no es un huérfano ────────────────────────────────────

    public function test_a_turn_that_ended_in_something_is_not_an_orphan(): void
    {
        $m = $this->entrante();
        $this->evento($m);
        $this->desenlace($m);

        $this->assertSame(0, $this->vigia());
        $this->assertSame(0, Incident::count());
    }

    public function test_an_eligible_inbound_without_an_outcome_is_an_orphan(): void
    {
        $m = $this->entrante('me interesa, cómo empiezo?');
        $this->evento($m);

        $this->assertSame(1, $this->vigia(), 'el vigía tiene que fallar cuando hay silencio');

        $incidente = Incident::first();
        $this->assertNotNull($incidente);
        $this->assertSame('ultron.turn.orphaned', $incidente->kind);
        $this->assertSame(Incident::SEVERITY_HIGH, $incidente->severity);
        $this->assertSame([$m->id], $incidente->evidence['message_ids']);
        $this->assertSame(1, $incidente->affected_messages);
    }

    /** Un turno recién nacido todavía puede estar en vuelo: no se le acusa. */
    public function test_a_turn_still_within_the_grace_window_is_not_an_orphan(): void
    {
        $m = $this->entrante();
        $this->evento($m, hace: 2);

        $this->assertSame(0, $this->vigia(['--minutes' => 10]));
        $this->assertSame(0, Incident::count());
    }

    /**
     * EL FALSO POSITIVO QUE HABRÍA HECHO INÚTIL AL VIGÍA. Tres mensajes
     * seguidos: los dos primeros se relevan y sólo el tercero contesta. Esa
     * persona SÍ recibió respuesta.
     */
    public function test_a_superseded_turn_answered_by_a_later_one_is_not_an_orphan(): void
    {
        $primero = $this->entrante('hola');
        $this->evento($primero);

        $ultimo = $this->entrante('mejor dime precios');
        $this->evento($ultimo);
        $this->desenlace($ultimo);
        $this->saliente();

        $this->assertSame(0, $this->vigia());
        $this->assertSame(0, Incident::count());
    }

    /** Un evento `skipped` no prometió turno: no puede haberlo perdido. */
    public function test_a_skipped_event_is_not_an_orphan(): void
    {
        $m = $this->entrante();
        $this->evento($m, MarketingAutomationEvent::STATUS_SKIPPED);

        $this->assertSame(0, $this->vigia());
    }

    /** Y un evento que murió sin llegar a n8n SÍ es un silencio: nadie lo contestó. */
    public function test_an_event_that_failed_to_reach_n8n_is_an_orphan(): void
    {
        $m = $this->entrante();
        $this->evento($m, MarketingAutomationEvent::STATUS_FAILED);

        $this->assertSame(1, $this->vigia());
        $this->assertSame('ultron.turn.orphaned', Incident::first()->kind);
    }

    /** Correrlo dos veces no abre dos alarmas: es la misma clase de problema. */
    public function test_running_twice_leaves_one_incident_with_two_occurrences(): void
    {
        $m = $this->entrante();
        $this->evento($m);

        $this->vigia();
        $this->vigia();

        $this->assertSame(1, Incident::count());
        $this->assertSame(2, Incident::first()->occurrences);
    }

    // ── 2 · El freno ──────────────────────────────────────────────────────────

    public function test_an_orphan_in_the_canary_conversation_stops_ultron(): void
    {
        config()->set('marketing.ultron.canary_conversation_id', $this->conversation->id);

        $m = $this->entrante();
        $this->evento($m);

        $this->vigia();

        $freno = UltronAbort::open()->first();
        $this->assertNotNull($freno, 'un canario mudo tiene que pararse solo');
        $this->assertSame(UltronAbort::REASON_ORPHANED_TURN, $freno->reason);
        $this->assertSame('ultron:turn-watchdog', $freno->engaged_by);
        $this->assertSame([$m->id], $freno->evidence['message_ids']);
    }

    public function test_an_orphan_outside_the_canary_does_not_touch_the_brake(): void
    {
        config()->set('marketing.ultron.canary_conversation_id', $this->conversation->id + 500);

        $this->evento($this->entrante());
        $this->vigia();

        $this->assertSame(1, Incident::count(), 'el incidente sí se abre');
        $this->assertSame(0, UltronAbort::open()->count(), 'pero no se para un canario que no es');
    }

    public function test_the_brake_can_be_held_back_explicitly(): void
    {
        config()->set('marketing.ultron.canary_conversation_id', $this->conversation->id);
        $this->evento($this->entrante());

        $this->vigia(['--no-abort' => true]);

        $this->assertSame(1, Incident::count());
        $this->assertSame(0, UltronAbort::open()->count());
    }

    public function test_two_runs_engage_a_single_brake(): void
    {
        config()->set('marketing.ultron.canary_conversation_id', $this->conversation->id);
        $this->evento($this->entrante());

        $this->vigia();
        $this->vigia();

        $this->assertSame(1, UltronAbort::count());
    }

    /** Con el freno puesto no nace ni un evento más. */
    public function test_with_the_brake_on_no_new_event_is_born(): void
    {
        app(UltronAbortLatch::class)->engage(UltronAbort::REASON_MANUAL, 'prueba');

        $emisor = new UltronEventEmitter;
        $m = $this->entrante('cuánto vale?');

        $this->assertSame('ultron_aborted', $emisor->ineligibleReason($this->conversation, $m, ['message_type' => 'text']));
        $this->assertNull($emisor->emit($this->conversation, $m, ['message_type' => 'text']));
        $this->assertSame(0, MarketingAutomationEvent::count());
    }

    /** Y un commit en vuelo tampoco pasa: el freno se comprueba en los dos extremos. */
    public function test_with_the_brake_on_a_late_commit_is_refused(): void
    {
        config()->set('automation.internal_secret', 'test-internal-secret');
        $m = $this->entrante('hola');

        $contexto = $this->postJson('/api/internal/marketing/ai/decide', [
            'conversation_id' => $this->conversation->id, 'message_id' => $m->id,
        ], ['Authorization' => 'Bearer test-internal-secret'])->assertOk()->json();

        app(UltronAbortLatch::class)->engage(UltronAbort::REASON_ORPHANED_TURN, 'ultron:turn-watchdog');

        $this->postJson('/api/internal/marketing/ai/commit', [
            'source_type' => 'inbound_message',
            'source_event_id' => $m->id,
            'idempotency_key' => 'k-freno',
            'conversation_id' => $this->conversation->id,
            'decide_token' => $contexto['decide_token'],
            'proposal' => [
                'next_state' => 'GOAL_DISCOVERY',
                'intent' => 'general_info',
                'reply_draft' => 'Claro, te cuento.',
            ],
        ], ['Authorization' => 'Bearer test-internal-secret'])
            ->assertStatus(403)
            ->assertJsonPath('code', 'ultron_aborted');

        $this->assertSame(0, MarketingMessage::where('direction', 'outbound')->count());
    }

    // ── 3 · Soltarlo es un acto humano ────────────────────────────────────────

    public function test_releasing_the_brake_needs_a_signature(): void
    {
        app(UltronAbortLatch::class)->engage(UltronAbort::REASON_ORPHANED_TURN, 'ultron:turn-watchdog');

        $this->artisan('ultron:abort', ['--release' => true])->assertExitCode(1);
        $this->assertSame(1, UltronAbort::open()->count(), 'sin firma no se suelta');

        $this->artisan('ultron:abort', ['--release' => true, '--by' => 'Alejandro'])->assertExitCode(0);
        $this->assertSame(0, UltronAbort::open()->count());
        $this->assertSame('Alejandro', UltronAbort::first()->released_by);
    }

    public function test_the_latch_refuses_an_anonymous_release_in_code_too(): void
    {
        $latch = app(UltronAbortLatch::class);
        $latch->engage(UltronAbort::REASON_MANUAL, 'prueba');

        $this->expectException(\InvalidArgumentException::class);
        $latch->release('   ');
    }

    /** La regla que lo hace seguro: soltar el freno NO enciende ULTRON. */
    public function test_releasing_the_brake_does_not_turn_ultron_on(): void
    {
        config()->set('marketing.ultron.enabled', false);

        $latch = app(UltronAbortLatch::class);
        $latch->engage(UltronAbort::REASON_MANUAL, 'prueba');
        $latch->release('Alejandro');

        $m = $this->entrante();

        $this->assertSame(
            'ultron_disabled',
            (new UltronEventEmitter)->ineligibleReason($this->conversation, $m, ['message_type' => 'text']),
        );
    }

    // ── 4 · Lo que dejó de ser atendible no es un silencio ────────────────────

    public static function razonesLegitimas(): array
    {
        return [
            'alguien tomó la conversación' => ['human_takeover'],
            'le apagaron la IA a ese hilo' => ['ai_disabled'],
            'la persona pidió que no le escriban' => ['do_not_contact'],
            'la conversación se cerró' => ['closed'],
        ];
    }

    /**
     * Entre el evento y el commit pasan segundos, y en esos segundos el mundo
     * cambia. No contestar entonces es lo correcto: contarlo como silencio
     * pararía el canario por hacer bien las cosas.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('razonesLegitimas')]
    public function test_a_turn_that_stopped_being_eligible_is_not_an_orphan(string $motivo): void
    {
        config()->set('marketing.ultron.canary_conversation_id', $this->conversation->id);

        $m = $this->entrante('hola');
        $this->evento($m);

        match ($motivo) {
            'human_takeover' => $this->conversation->forceFill(['human_takeover' => true, 'human_takeover_source' => 'manual'])->save(),
            'ai_disabled' => $this->conversation->forceFill(['ai_enabled' => false])->save(),
            'do_not_contact' => $this->lead->forceFill(['do_not_contact' => true])->save(),
            'closed' => $this->conversation->forceFill(['status' => 'closed'])->save(),
        };

        $this->assertSame(0, $this->vigia(), "«{$motivo}» no es un turno mudo");
        $this->assertSame(0, Incident::count());
        $this->assertSame(0, UltronAbort::open()->count(), 'y desde luego no puede parar el canario');
    }

    // ── 5 · Las rendiciones se ven, aunque nadie se quedara sin respuesta ─────

    /**
     * Una rendición no es un silencio —la persona recibió el texto curado— pero
     * sí es la senal de que el borrador del modelo se cae contra un guard. Y esa
     * senal no la manda nadie mas: el workflow responde 200 y el turno queda
     * bien. Sin esto, un fallo sistematico del Composer solo se veria leyendo
     * los chats a mano, que es como se encontro la primera vez.
     */
    public function test_a_surrender_opens_its_own_incident_without_stopping_the_canary(): void
    {
        config()->set('marketing.ultron.canary_conversation_id', $this->conversation->id);

        $m = $this->entrante('cuanto vale?');
        $this->evento($m);
        $accion = $this->desenlace($m);
        $accion->forceFill(['metadata' => array_merge((array) $accion->metadata, [
            'recovery' => ['reason' => 'machine_reply_payment_fact', 'attempt' => 2],
        ])])->save();
        $this->saliente();

        $this->assertSame(0, $this->vigia(), 'no es un silencio: no puede fallar');

        $incidente = Incident::where('kind', 'ultron.turn.recovered')->first();
        $this->assertNotNull($incidente, 'la rendición tiene que dejar aviso');
        $this->assertSame(Incident::SEVERITY_MEDIUM, $incidente->severity);
        $this->assertSame(1, $incidente->evidence['recoveries']);
        $this->assertSame(['machine_reply_payment_fact' => 1], $incidente->evidence['reasons']);

        $this->assertSame(0, UltronAbort::open()->count(), 'y NO para el canario: hubo respuesta');
    }

    /** Un turno normal no se cuenta como rendición. */
    public function test_a_normal_turn_is_not_reported_as_a_surrender(): void
    {
        $m = $this->entrante();
        $this->evento($m);
        $this->desenlace($m);

        $this->vigia();

        $this->assertSame(0, Incident::where('kind', 'ultron.turn.recovered')->count());
    }

    // ── 6 · La ventana hacia atrás ───────────────────────────────────────────

    /**
     * EL PIE QUE CASI SE PISA. Sin ventana, la primera corrida en produccion
     * desenterraria los silencios de la ronda anterior del canario —ya
     * investigados a mano— y pararia el canario nada mas encenderlo.
     */
    public function test_an_old_silence_is_not_resurrected(): void
    {
        config()->set('marketing.ultron.canary_conversation_id', $this->conversation->id);

        $m = $this->entrante('esto quedo mudo ayer');
        $this->evento($m, hace: 60 * 30);   // treinta horas

        $this->assertSame(0, $this->vigia(), 'fuera de la ventana no se cuenta');
        $this->assertSame(0, Incident::count());
        $this->assertSame(0, UltronAbort::open()->count());
    }

    /** Y ampliando la ventana a mano sí se ve: el dato no desaparece, se acota. */
    public function test_the_window_can_be_widened_by_hand(): void
    {
        $m = $this->entrante('esto quedo mudo ayer');
        $this->evento($m, hace: 60 * 30);

        $this->assertSame(1, $this->vigia(['--window' => 60 * 48, '--no-abort' => true]));
        $this->assertSame(1, Incident::count());
    }

    /**
     * Y si contestó una PERSONA desde el Inbox, tampoco hubo silencio.
     *
     * Durante un canario es lo normal: el dueño ve el chat y escribe él. Contar
     * eso como turno mudo pararía el canario por haberlo atendido bien.
     */
    public function test_a_human_reply_from_the_inbox_also_counts_as_answered(): void
    {
        config()->set('marketing.ultron.canary_conversation_id', $this->conversation->id);

        $m = $this->entrante('cuanto vale?');
        $this->evento($m);
        $this->saliente(MarketingMessage::SENDER_HUMAN);

        $this->assertSame(0, $this->vigia());
        $this->assertSame(0, Incident::count());
        $this->assertSame(0, UltronAbort::open()->count());
    }

    // ── 7 · Un atasco de cola no es un turno mudo ────────────────────────────

    /**
     * EL FALSO POSITIVO QUE CONVERTÍA UN RETRASO EN SILENCIO.
     *
     * Lo encontró la revisión: el vigía corta por `event.created_at`, no por el
     * intento de entrega. En un despliegue se reinician los doce workers; si un
     * job tarda más que la gracia, el evento sigue `pending` —no ha salido de
     * la cola— y el vigía lo leía como mudo, paraba el canario, y al volver el
     * worker el commit moría con `ultron_aborted`. El retraso se volvía
     * silencio de verdad, y encima había que soltar el freno a mano.
     *
     * Ahora abre incidente (se ve) pero NO frena: el freno lo accionan sólo los
     * que llegaron a n8n o murieron intentándolo.
     */
    public function test_an_event_stuck_in_the_queue_opens_an_incident_but_does_not_stop_the_canary(): void
    {
        config()->set('marketing.ultron.canary_conversation_id', $this->conversation->id);

        $m = $this->entrante('me interesa');
        $this->evento($m, MarketingAutomationEvent::STATUS_PENDING);

        $this->assertSame(1, $this->vigia(), 'se ve');

        $incidente = Incident::first();
        $this->assertNotNull($incidente);
        $this->assertSame(1, $incidente->evidence['pending'], 'y se distingue lo atascado de lo mudo');

        $this->assertSame(0, UltronAbort::open()->count(), 'pero no para el canario por un atasco de cola');
    }

    /** El que SÍ llegó a n8n y se quedó mudo sigue parando el canario. */
    public function test_an_event_that_reached_n8n_still_stops_the_canary(): void
    {
        config()->set('marketing.ultron.canary_conversation_id', $this->conversation->id);

        $this->evento($this->entrante('me interesa'), MarketingAutomationEvent::STATUS_SENT);

        $this->vigia();

        $this->assertSame(1, UltronAbort::open()->count());
    }

    /** Y si el freno no se puede accionar, el vigía no se cae: el incidente ya avisa. */
    public function test_the_watchdog_survives_a_brake_that_cannot_be_engaged(): void
    {
        config()->set('marketing.ultron.canary_conversation_id', $this->conversation->id);
        $this->evento($this->entrante('me interesa'), MarketingAutomationEvent::STATUS_SENT);

        $this->app->bind(UltronAbortLatch::class, fn () => new class extends UltronAbortLatch
        {
            public function engage(string $reason, string $by, array $evidence = []): UltronAbort
            {
                throw new \RuntimeException('la tabla no existe');
            }
        });

        $this->assertSame(1, $this->vigia(), 'falla, pero termina');
        $this->assertSame(1, Incident::count(), 'y el incidente queda abierto, que es lo que hace mirar');
    }

    /**
     * Una rendición vieja no se vuelve a contar en cada pasada.
     *
     * Con la ventana larga, la misma rendición sumaba una ocurrencia cada cinco
     * minutos —setenta y dos en seis horas por un solo turno—, y un incidente
     * que se infla solo deja de medir nada.
     */
    public function test_an_old_surrender_is_not_counted_again_on_every_pass(): void
    {
        $m = $this->entrante('cuanto vale?');
        $this->evento($m);
        $accion = $this->desenlace($m);
        $accion->forceFill([
            'metadata' => array_merge((array) $accion->metadata, [
                'recovery' => ['reason' => 'machine_reply_payment_fact', 'attempt' => 2],
            ]),
        ])->save();
        // La rendición fue hace dos horas; la ventana corta ya no la alcanza.
        $accion->forceFill(['created_at' => now()->subHours(2)])->save();
        $this->saliente();

        $this->vigia();

        $this->assertSame(0, Incident::where('kind', 'ultron.turn.recovered')->count());
    }
}
