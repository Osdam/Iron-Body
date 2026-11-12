<?php

namespace Tests\Feature\Commercial;

use App\Jobs\Commercial\EvaluateCommercialSubject;
use App\Models\CommercialEvent;
use App\Models\MarketingAiAction;
use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\Member;
use App\Services\Commercial\CommercialSubjectResolver;
use App\Services\Commercial\CommercialVocabulary as V;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Q1-1 del plan de quejas: la queja que la IA marca en una conversación llega
 * al motor como `complaint_created`, por la puerta de siempre
 * (`CommercialEventRecorder`) y con el emisor real
 * (`MarketingAiActionCommercialObserver`, registrado en
 * `CommercialEventServiceProvider`).
 *
 * Lo que fija: qué cuenta como queja (el motivo o la intención del turno), una
 * queja al DÍA DE BOGOTÁ por conversación, ni texto ni teléfono en el evento,
 * el socio vinculado al prospecto, el interruptor `commercial.events_enabled`,
 * que con el motor NBA apagado no se encola nada, que un fallo del registro
 * no rompe la creación de la fila (ULTRON la crea dentro de su `try/catch`) y
 * que el registro corre DESPUÉS del commit de quien crea la fila: dentro de
 * su transacción, en PostgreSQL, un INSERT fallido (la carrera por la clave)
 * la dejaría abortada aunque el registrador se trague la excepción.
 */
class ComplaintEventWiringTest extends TestCase
{
    use RefreshDatabase;

    private MarketingLead $lead;

    private MarketingConversation $conversation;

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('commercial.events_enabled', true);
        config()->set('commercial.enabled', false);   // producción: NBA apagado
        config()->set('commercial.autonomy_enabled', false);
        Queue::fake([EvaluateCommercialSubject::class]);
        Carbon::setTestNow(Carbon::parse('2026-11-17 15:00:00', 'UTC'));   // martes, 10:00 de Bogotá

        $this->member = Member::create([
            'full_name' => 'Socio Queja', 'document_number' => '2020202020', 'phone' => '3150000001',
            'access_hash' => 'tok-'.uniqid(), 'status' => Member::STATUS_ACTIVE,
        ]);
        $this->lead = MarketingLead::create([
            'channel' => 'whatsapp', 'source' => 'inbound', 'meta_user_id' => '573150000001', 'phone' => '3150000001',
            'name' => 'Socio Queja', 'status' => MarketingLead::STATUS_NEW, 'member_id' => $this->member->id,
        ]);
        $this->conversation = MarketingConversation::create(['lead_id' => $this->lead->id, 'channel' => 'whatsapp', 'status' => 'open']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param  array<string, mixed>  $o */
    private function action(array $o = []): MarketingAiAction
    {
        return MarketingAiAction::create(array_merge([
            'lead_id' => $this->lead->id,
            'conversation_id' => $this->conversation->id,
            'action_type' => 'staff_review',
            'reason' => 'complaint',
            'status' => 'created',
            'metadata' => ['needs_staff_review' => true, 'staff_review_reason' => 'complaint', 'intent' => 'complaint'],
        ], $o));
    }

    /** @return list<CommercialEvent> */
    private function complaints(): array
    {
        return CommercialEvent::query()->where('event', V::EV_COMPLAINT_CREATED)->orderBy('id')->get()->all();
    }

    public function test_la_fila_de_la_ia_con_queja_registra_un_complaint_created_sin_texto_ni_telefono_con_el_socio_del_prospecto(): void
    {
        $fila = $this->action();

        $eventos = $this->complaints();
        $this->assertCount(1, $eventos);
        $e = $eventos[0];
        $this->assertSame($this->lead->id, (int) $e->marketing_lead_id);
        $this->assertSame($this->member->id, (int) $e->member_id, 'el socio vinculado al prospecto: su m: lo ve en el H9');
        $this->assertSame("complaint_created:conversation:{$this->conversation->id}:complaint:20261117", $e->dedupe_key);
        $carga = $e->payload;
        ksort($carga);   // PostgreSQL (jsonb) no conserva el orden de las claves
        $this->assertSame(['ai_action_id' => $fila->id, 'conversation_id' => $this->conversation->id, 'source' => 'sales_agent'], $carga);
        $this->assertSame('2026-11-17 15:00:00', $e->occurred_at->utc()->format('Y-m-d H:i:s'));
        $crudo = json_encode($e->getAttributes());
        $this->assertStringNotContainsString('3150000001', $crudo);
        $this->assertStringNotContainsString('Socio Queja', $crudo);
    }

    public function test_cuenta_como_queja_el_motivo_o_la_intencion_y_nada_mas(): void
    {
        $this->action(['reason' => 'complaint', 'metadata' => ['intent' => 'pricing']]);
        $this->assertCount(1, $this->complaints(), 'por el motivo');

        $otra = MarketingConversation::create(['lead_id' => $this->lead->id, 'channel' => 'whatsapp', 'status' => 'open']);
        $this->action(['conversation_id' => $otra->id, 'action_type' => 'reply', 'reason' => 'pricing', 'metadata' => ['intent' => 'complaint']]);
        $this->assertCount(2, $this->complaints(), 'por la intención del turno');

        $tercera = MarketingConversation::create(['lead_id' => $this->lead->id, 'channel' => 'whatsapp', 'status' => 'open']);
        foreach ([
            ['reason' => 'sensitive_topic', 'metadata' => ['intent' => 'pricing']],
            ['reason' => 'human_request', 'metadata' => ['intent' => 'human_request']],
            ['reason' => 'Conflicto o queja', 'metadata' => null],
            ['reason' => 'COMPLAINT', 'metadata' => ['intent' => 'Complaint']],
            ['reason' => null, 'metadata' => ['complaint' => true]],
        ] as $sinQueja) {
            $this->action(['conversation_id' => $tercera->id] + $sinQueja);
        }
        $this->assertCount(2, $this->complaints(), 'una fila sin queja no registra nada');
    }

    public function test_una_queja_al_dia_de_bogota_por_conversacion(): void
    {
        $this->action();
        Carbon::setTestNow(Carbon::parse('2026-11-17 23:59:00', 'UTC'));   // 18:59 de Bogotá
        $this->action();
        Carbon::setTestNow(Carbon::parse('2026-11-18 00:00:00', 'UTC'));   // 19:00 de Bogotá: en UTC ya es otro día
        $this->action();
        Carbon::setTestNow(Carbon::parse('2026-11-18 04:59:59', 'UTC'));   // 23:59:59 de Bogotá
        $this->action();
        $this->assertCount(1, $this->complaints(), 'el mismo día de Bogotá: una');

        Carbon::setTestNow(Carbon::parse('2026-11-18 05:00:00', 'UTC'));   // 00:00 de Bogotá del 18-11
        $this->action();
        $this->assertSame(
            ["complaint_created:conversation:{$this->conversation->id}:complaint:20261117", "complaint_created:conversation:{$this->conversation->id}:complaint:20261118"],
            array_map(static fn (CommercialEvent $e): string => $e->dedupe_key, $this->complaints()),
        );

        // Otra conversación de la misma persona, el mismo día: otra queja.
        $otra = MarketingConversation::create(['lead_id' => $this->lead->id, 'channel' => 'whatsapp', 'status' => 'open']);
        $this->action(['conversation_id' => $otra->id]);
        $this->assertCount(3, $this->complaints());
    }

    public function test_sin_conversacion_la_clave_es_la_del_prospecto(): void
    {
        // La ruta interna de n8n crea filas con `conversation_id` opcional (`lead_id` es NOT NULL en la base).
        $this->action(['conversation_id' => null]);
        $this->action(['conversation_id' => null]);
        $this->assertSame(["complaint_created:lead:{$this->lead->id}:complaint:20261117"], array_map(static fn (CommercialEvent $e): string => $e->dedupe_key, $this->complaints()));
        $this->assertNull($this->complaints()[0]->payload['conversation_id']);
    }

    public function test_la_fila_de_ultron_se_marca_como_ultron(): void
    {
        $this->action(['source_type' => 'inbound_message', 'source_event_id' => 77, 'idempotency_key' => 'ultron-q-1', 'action_type' => 'reply', 'status' => 'executed']);

        $this->assertSame('ultron', $this->complaints()[0]->payload['source']);
    }

    public function test_con_el_registro_de_hechos_apagado_no_se_registra_nada(): void
    {
        config()->set('commercial.events_enabled', false);
        $this->action();

        $this->assertCount(0, $this->complaints());
    }

    public function test_con_el_motor_nba_apagado_la_queja_no_encola_ninguna_evaluacion(): void
    {
        $this->action();

        $this->assertCount(1, $this->complaints());
        Queue::assertNotPushed(EvaluateCommercialSubject::class);
        $this->assertNull($this->complaints()[0]->evaluated_at, 'el hecho queda registrado; nadie lo evalúa con el motor apagado');

        // Con el NBA encendido (hoy no lo está): como cualquier hecho, encola la reevaluación del ESTADO de la persona
        // (`EvaluateCommercialSubject`); el motor no mira el tipo de evento y, sin autonomía, no ejecuta nada.
        config()->set('commercial.enabled', true);
        $otra = MarketingConversation::create(['lead_id' => $this->lead->id, 'channel' => 'whatsapp', 'status' => 'open']);
        $this->action(['conversation_id' => $otra->id]);
        Queue::assertPushed(EvaluateCommercialSubject::class, 1);
    }

    public function test_dentro_de_una_transaccion_la_queja_se_registra_al_confirmar_y_no_antes(): void
    {
        DB::transaction(function (): void {
            $this->action();
            $this->action();   // otra fila con queja en el mismo turno
            $this->assertCount(0, $this->complaints(), 'dentro de la transacción de quien crea la fila, todavía nada');
            // La transacción sigue sana para lo que el turno haga después.
            $this->assertSame(2, MarketingAiAction::query()->count());
        });

        $this->assertCount(1, $this->complaints(), 'al confirmar, una (la segunda fila del mismo día se deduplica)');
        $this->assertSame('2026-11-17 15:00:00', $this->complaints()[0]->occurred_at->utc()->format('Y-m-d H:i:s'), 'con la hora de la fila');
    }

    public function test_si_la_transaccion_se_revierte_no_queda_ninguna_queja(): void
    {
        DB::beginTransaction();
        $this->action();
        DB::rollBack();
        $this->assertSame(0, MarketingAiAction::query()->count(), 'la fila no existe');
        $this->assertCount(0, $this->complaints());

        try {
            DB::transaction(function (): void {
                $this->action();
                throw new \RuntimeException('el turno falla después de crear la fila');
            });
        } catch (\RuntimeException) {
            // Esperada: la transacción del turno se revierte.
        }
        $this->assertCount(0, $this->complaints());

        // Y lo que se confirma después sí se registra (no queda nada pendiente de la revertida).
        $this->action();
        $this->assertCount(1, $this->complaints());
    }

    public function test_fuera_de_una_transaccion_se_registra_en_el_acto(): void
    {
        $this->action();

        $this->assertCount(1, $this->complaints(), 'sin transacción de la aplicación, el callback corre en el acto');
    }

    public function test_un_fallo_al_registrar_no_rompe_la_creacion_de_la_fila(): void
    {
        $this->app->instance(CommercialSubjectResolver::class, new class extends CommercialSubjectResolver
        {
            public function fromLead(?int $leadId): array
            {
                throw new \RuntimeException('base caída');
            }
        });

        $fila = $this->action();

        $this->assertTrue($fila->exists);
        $this->assertSame(1, MarketingAiAction::query()->whereKey($fila->id)->count());
        $this->assertCount(0, $this->complaints());

        // Y después del commit: la excepción no le llega a quien llamó a DB::transaction.
        $otra = DB::transaction(fn (): MarketingAiAction => $this->action());
        $this->assertTrue($otra->exists);
        $this->assertCount(0, $this->complaints());
    }
}
