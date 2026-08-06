<?php

namespace Tests\Feature\IronGuard;

use App\Models\Admin;
use App\Models\Incident;
use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Models\MarketingMessageAttachment;
use App\Models\MetaWebhookEvent;
use App\Services\IronGuard\ChannelHealthDetector;
use App\Services\Marketing\WhatsappOutboxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * IRON GUARD detecta a partir del ESTADO, no de los logs.
 *
 * Los logs son texto libre: una expresión regular sobre prosa se rompe en
 * cuanto alguien cambia una frase, y mandar cada línea a un modelo cuesta
 * dinero y alucina justo cuando más falta hace la exactitud. Aquí se consulta
 * lo que ya guardamos en tablas propias, que son hechos.
 *
 * Lo que se fija en estas pruebas es sobre todo el AGRUPAMIENTO: doscientos
 * fallos iguales tienen que ser un incidente con doscientas ocurrencias. Si
 * cada fallo abriera su propia alarma, un worker caído una hora llenaría el
 * panel y nadie volvería a mirarlo, que es como muere la observabilidad.
 */
class IncidentDetectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('observability.enabled', true);
        config()->set('observability.raw_events.stuck_after_minutes', 10);
        config()->set('observability.incidents.grouping_window_minutes', 60);
        Http::fake();
    }

    private function detector(): ChannelHealthDetector
    {
        return app(ChannelHealthDetector::class);
    }

    private function stuckEvent(int $messages = 1, int $minutesAgo = 30): MetaWebhookEvent
    {
        $event = MetaWebhookEvent::create([
            'correlation_id' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', Str::random(20)),
            'payload' => ['object' => 'whatsapp_business_account'],
            'messages_count' => $messages,
            'status' => MetaWebhookEvent::STATUS_PENDING,
        ]);
        $event->forceFill(['created_at' => now()->subMinutes($minutesAgo)])->save();

        return $event;
    }

    private function conversation(): MarketingConversation
    {
        $lead = MarketingLead::create([
            'channel' => 'whatsapp', 'source' => 'inbound', 'phone' => '3150536026',
            'name' => 'Lead', 'status' => MarketingLead::STATUS_NEW,
        ]);

        return MarketingConversation::create([
            'lead_id' => $lead->id, 'channel' => 'whatsapp', 'status' => 'open',
            'ai_enabled' => true, 'human_takeover' => false,
        ]);
    }

    /**
     * El peor fallo posible del canal: alguien escribió y su mensaje no llegó
     * al inbox. Ni la IA ni una persona pueden contestar lo que no ven.
     */
    public function test_unprocessed_events_with_waiting_people_are_critical(): void
    {
        $this->stuckEvent(messages: 2);
        $this->stuckEvent(messages: 1);

        $incidents = $this->detector()->scan();

        $incident = collect($incidents)->firstWhere('kind', 'events_stuck');
        $this->assertNotNull($incident);
        $this->assertSame(Incident::SEVERITY_CRITICAL, $incident->severity);
        $this->assertSame(3, $incident->affected_messages);
        $this->assertSame(2, data_get($incident->evidence, 'stuck_count'));
        // La evidencia dice qué mirar y qué se puede hacer sin riesgo.
        $this->assertSame('marketing:replay-webhooks', data_get($incident->evidence, 'safe_remediation'));
    }

    /** Un evento reciente todavía no está atascado: es la cola trabajando. */
    public function test_a_recent_event_is_not_an_incident(): void
    {
        $this->stuckEvent(minutesAgo: 1);

        $this->assertNull(collect($this->detector()->scan())->firstWhere('kind', 'events_stuck'));
    }

    /** El agrupamiento: correr el detector diez veces deja UN incidente. */
    public function test_scanning_repeatedly_groups_instead_of_flooding(): void
    {
        $this->stuckEvent();

        for ($i = 0; $i < 10; $i++) {
            $this->detector()->scan();
        }

        $incidents = Incident::where('kind', 'events_stuck')->get();
        $this->assertCount(1, $incidents);
        $this->assertSame(10, $incidents->first()->occurrences);
    }

    /** Lo que se repite mucho deja de ser anécdota y sube de gravedad. */
    public function test_persistent_repetition_escalates_the_severity(): void
    {
        config()->set('observability.incidents.escalate_after_occurrences', 5);

        $conversation = $this->conversation();
        for ($i = 0; $i < 3; $i++) {
            MarketingMessage::create([
                'conversation_id' => $conversation->id, 'direction' => 'outbound',
                'sender_type' => 'ai', 'body' => 'hola', 'status' => WhatsappOutboxService::STATUS_DEAD,
                'last_error_code' => 131026,
            ]);
        }

        for ($i = 0; $i < 6; $i++) {
            $this->detector()->scan();
        }

        $incident = Incident::where('kind', 'messages_dead')->sole();
        $this->assertSame(Incident::SEVERITY_HIGH, $incident->severity);
    }

    /**
     * Dos códigos de error distintos son dos problemas distintos: "ventana de
     * 24 h cerrada" y "número inválido" no se arreglan igual.
     */
    public function test_different_meta_error_codes_are_different_incidents(): void
    {
        $conversation = $this->conversation();

        MarketingMessage::create([
            'conversation_id' => $conversation->id, 'direction' => 'outbound',
            'sender_type' => 'ai', 'status' => WhatsappOutboxService::STATUS_DEAD,
            'last_error_code' => 131026,
        ]);
        $this->detector()->scan();

        MarketingMessage::where('last_error_code', 131026)->delete();
        MarketingMessage::create([
            'conversation_id' => $conversation->id, 'direction' => 'outbound',
            'sender_type' => 'ai', 'status' => WhatsappOutboxService::STATUS_DEAD,
            'last_error_code' => 131047,
        ]);
        $this->detector()->scan();

        $this->assertSame(2, Incident::where('kind', 'messages_dead')->count());
    }

    /** Un fallo de entrega trae la traducción del código, no solo el número. */
    public function test_a_dead_message_incident_explains_the_meta_code(): void
    {
        $conversation = $this->conversation();
        MarketingMessage::create([
            'conversation_id' => $conversation->id, 'direction' => 'outbound',
            'sender_type' => 'ai', 'status' => WhatsappOutboxService::STATUS_DEAD,
            'last_error_code' => 131047,
        ]);

        $this->detector()->scan();

        $incident = Incident::where('kind', 'messages_dead')->sole();
        $this->assertStringContainsString('24 h', (string) data_get($incident->evidence, 'hint'));
        $this->assertSame(1, $incident->affected_conversations);
    }

    /**
     * Rechazar un archivo con el tipo mentido es el sistema defendiéndose y
     * funcionando bien. Tratarlo como avería grave enseñaría a los operadores a
     * ignorar las alertas.
     */
    public function test_rejecting_disguised_files_is_low_severity_not_an_outage(): void
    {
        $conversation = $this->conversation();
        $message = MarketingMessage::create([
            'conversation_id' => $conversation->id, 'direction' => 'inbound', 'sender_type' => 'lead',
        ]);

        for ($i = 0; $i < 4; $i++) {
            MarketingMessageAttachment::create([
                'message_id' => $message->id, 'direction' => 'inbound', 'kind' => 'image',
                'status' => MarketingMessageAttachment::STATUS_REJECTED,
                'failure_reason' => 'mime_mismatch',
            ]);
        }

        $this->detector()->scan();

        $incident = Incident::where('kind', 'downloads_failing')->sole();
        $this->assertSame(Incident::SEVERITY_LOW, $incident->severity);
        $this->assertTrue((bool) data_get($incident->evidence, 'defensive'));
        // No se ofrece reintento: repetirlo repetiría el rechazo.
        $this->assertNull(data_get($incident->evidence, 'safe_remediation'));
    }

    /** No poder descargar de Meta sí es una avería nuestra. */
    public function test_failing_to_download_from_meta_is_a_real_outage(): void
    {
        $conversation = $this->conversation();
        $message = MarketingMessage::create([
            'conversation_id' => $conversation->id, 'direction' => 'inbound', 'sender_type' => 'lead',
        ]);

        for ($i = 0; $i < 4; $i++) {
            MarketingMessageAttachment::create([
                'message_id' => $message->id, 'direction' => 'inbound', 'kind' => 'audio',
                'status' => MarketingMessageAttachment::STATUS_FAILED,
                'failure_reason' => 'download_http_503',
            ]);
        }

        $this->detector()->scan();

        $incident = Incident::where('kind', 'downloads_failing')->sole();
        $this->assertSame(Incident::SEVERITY_HIGH, $incident->severity);
        $this->assertSame('retry_media_download', data_get($incident->evidence, 'safe_remediation'));
    }

    /**
     * Prospectos reales esperando atención humana. No es un fallo técnico, pero
     * es exactamente lo que el negocio necesita ver.
     */
    public function test_conversations_waiting_too_long_for_a_person_are_surfaced(): void
    {
        $conversation = $this->conversation();
        $conversation->forceFill([
            'staff_review_pending' => true,
            'last_inbound_at' => now()->subHours(6),
        ])->save();

        $this->detector()->scan();

        $incident = Incident::where('kind', 'unattended_escalations')->sole();
        $this->assertSame(1, $incident->affected_conversations);
    }

    /** Un canal sano no inventa incidentes. */
    public function test_a_healthy_channel_produces_no_incidents(): void
    {
        $this->assertSame([], $this->detector()->scan());
        $this->assertSame(0, Incident::count());
    }

    /**
     * Un incidente cerrado que vuelve a ocurrir se REABRE con su historia, no
     * se pierde ni choca contra el índice único.
     */
    public function test_a_resolved_incident_that_returns_is_reopened(): void
    {
        $this->stuckEvent();
        $this->detector()->scan();

        $incident = Incident::where('kind', 'events_stuck')->sole();
        $incident->forceFill(['status' => Incident::STATUS_RESOLVED, 'resolved_at' => now()])->save();

        $this->detector()->scan();

        $incident->refresh();
        $this->assertSame(Incident::STATUS_OPEN, $incident->status);
        $this->assertNull($incident->resolved_at);
        $this->assertSame(1, Incident::where('kind', 'events_stuck')->count());
    }

    /** El comando respeta el flag: apagado no toca nada. */
    public function test_the_command_does_nothing_while_the_flag_is_off(): void
    {
        config()->set('observability.enabled', false);
        $this->stuckEvent();

        $this->artisan('iron-guard:scan')->assertSuccessful();

        $this->assertSame(0, Incident::count());
    }

    public function test_the_command_can_be_forced_for_a_one_off_check(): void
    {
        config()->set('observability.enabled', false);
        $this->stuckEvent();

        $this->artisan('iron-guard:scan --force')->assertSuccessful();

        $this->assertGreaterThan(0, Incident::count());
    }

    /** El panel exige administrador pleno: no es información para un asesor. */
    public function test_the_panel_is_closed_to_non_admins(): void
    {
        $this->getJson('/api/admin/iron-guard/overview')->assertStatus(401);
    }

    public function test_an_admin_sees_metrics_and_open_incidents(): void
    {
        $admin = Admin::create([
            'name' => 'Super QA', 'email' => 'guard-qa@ironbody.test',
            'password' => 'x', 'role' => Admin::ROLE_SUPER_ADMIN, 'status' => 'active',
        ]);
        $headers = $this->actingAsAdmin($admin);

        $this->stuckEvent(messages: 3);
        $this->detector()->scan();

        $response = $this->getJson('/api/admin/iron-guard/overview', $headers)->assertOk();

        $response->assertJsonPath('ok', true);
        $this->assertGreaterThan(0, count($response->json('data.incidents')));
        $this->assertNotNull($response->json('data.metrics.ingest'));
        // El panel dice qué cerebro está decidiendo de verdad.
        $this->assertNotNull($response->json('data.metrics.brain.effective_driver'));
    }
}
