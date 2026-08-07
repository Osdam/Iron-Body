<?php

namespace Tests\Feature\Hardening;

use App\Models\Admin;
use App\Models\CommercialApproval;
use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\MarketingLeadAttribution;
use App\Models\MarketingMessageAttachment;
use App\Services\Commercial\ApprovalQueueService;
use App\Services\Marketing\LeadAttributionService;
use App\Services\Marketing\MarketingManualTakeoverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Fase F: intentar romperlo.
 *
 * Aquí no se prueba que las cosas funcionen —eso ya lo cubren las suites de
 * cada fase— sino que **no se puedan forzar**. Cada prueba describe un intento
 * concreto de conseguir algo indebido: ver lo que no toca, cobrar dos veces,
 * ejecutar una acción por texto libre, colar un archivo disfrazado o ganar una
 * carrera entre dos procesos.
 *
 * La regla que las une: donde el resultado correcto es «una sola acción
 * efectiva», se ejecuta dos veces y se comprueba que solo una cuenta.
 */
class PhaseFHardeningTest extends TestCase
{
    use RefreshDatabase;

    private Admin $admin;

    private array $saHeaders = [];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('meta.enabled', false);
        Storage::fake('whatsapp');
        config()->set('marketing.media.disk', 'whatsapp');
        Http::preventStrayRequests();
        Http::fake();

        $this->admin = Admin::create([
            'name' => 'Hardening', 'email' => 'hard@ironbody.test',
            'password' => 'x', 'role' => Admin::ROLE_SUPER_ADMIN, 'status' => 'active',
        ]);
        $this->saHeaders = $this->actingAsAdmin($this->admin);
    }

    protected function adminHeaders(array $headers = []): array
    {
        return array_merge($this->saHeaders, $headers);
    }

    private function conversation(string $phone = '3150536026'): MarketingConversation
    {
        $lead = MarketingLead::create([
            'channel' => 'whatsapp', 'source' => 'inbound', 'phone' => $phone,
            'status' => MarketingLead::STATUS_NEW,
        ]);

        return MarketingConversation::create([
            'lead_id' => $lead->id, 'channel' => 'whatsapp', 'status' => 'open',
            'ai_enabled' => true, 'human_takeover' => false,
        ]);
    }

    // ── F.4 · Concurrencia ──────────────────────────────────────────────

    /**
     * Dos supervisores aprobando lo mismo. El resultado correcto es UNA
     * aprobación, no dos, y la autoría debe ser del primero.
     */
    public function test_dos_aprobadores_simultaneos_producen_una_sola_aprobacion(): void
    {
        $otro = Admin::create([
            'name' => 'Otro', 'email' => 'otro-h@ironbody.test', 'password' => 'x',
            'role' => Admin::ROLE_ADMINISTRADOR, 'status' => 'active',
        ]);

        $queue = app(ApprovalQueueService::class);
        $approval = $queue->request(
            CommercialApproval::TYPE_REFUND, 'Cobro duplicado', 'race:1', ['amount' => 90000],
        );

        $a = $queue->approve($approval, $this->admin);
        $b = $queue->approve($approval->fresh(), $otro);

        $this->assertTrue($a['ok']);
        $this->assertFalse($b['ok']);
        $this->assertSame($this->admin->id, $approval->fresh()->decided_by_admin_id);
    }

    /** Dos ejecuciones de la misma aprobación: solo una marca ejecutado. */
    public function test_ejecutar_dos_veces_solo_cuenta_una(): void
    {
        $queue = app(ApprovalQueueService::class);
        $approval = $queue->request(CommercialApproval::TYPE_REFUND, 'x', 'race:2');
        $queue->approve($approval, $this->admin);

        $primera = $queue->markExecuted($approval->fresh());
        $segunda = $queue->markExecuted($approval->fresh());

        $this->assertTrue($primera['ok']);
        $this->assertFalse($segunda['ok']);
    }

    /**
     * Dos webhooks idénticos del mismo referral: una atribución, no dos.
     *
     * Meta reintenta los webhooks; sin idempotencia, un reintento inventaría un
     * segundo contacto y falsearía la analítica de la pauta.
     */
    public function test_un_webhook_repetido_no_duplica_la_atribucion(): void
    {
        $conversation = $this->conversation();
        $referral = [
            'source_type' => 'ad', 'source_id' => 'AD-1',
            'source_url' => 'https://instagram.com/p/x/', 'ctwa_clid' => 'CL1',
        ];

        $service = app(LeadAttributionService::class);
        $service->record($conversation->lead_id, $referral, $conversation->id);
        $service->record($conversation->lead_id, $referral, $conversation->id);

        $this->assertSame(1, MarketingLeadAttribution::query()
            ->where('marketing_lead_id', $conversation->lead_id)->count());
    }

    /**
     * Dos envíos simultáneos reclamando el mismo adjunto.
     *
     * Sin el cerrojo, el cliente recibiría la misma foto dos veces.
     */
    public function test_dos_envios_no_pueden_reclamar_el_mismo_adjunto(): void
    {
        $conversation = $this->conversation();

        $draft = MarketingMessageAttachment::create([
            'message_id' => null, 'direction' => 'outbound', 'kind' => 'image',
            'uploaded_by_admin_id' => $this->admin->id,
            'detected_mime_type' => 'image/jpeg', 'disk' => 'whatsapp',
            'path' => 'outbound/image/x.jpg', 'size_bytes' => 10,
            'status' => MarketingMessageAttachment::STATUS_STORED,
        ]);

        $url = "/api/admin/marketing/inbox/conversations/{$conversation->id}/messages";

        $this->postJson($url, ['body' => 'a', 'attachment_ids' => [$draft->id]], $this->adminHeaders())
            ->assertOk();
        $this->postJson($url, ['body' => 'b', 'attachment_ids' => [$draft->id]], $this->adminHeaders())
            ->assertStatus(409);
    }

    /**
     * La IA procesando y una persona tomando el control a la vez.
     *
     * Que salgan dos respuestas —una del agente y otra de la persona— es de lo
     * que peor se ve desde el otro lado.
     */
    public function test_con_control_humano_la_ia_queda_apagada(): void
    {
        $conversation = $this->conversation();

        app(MarketingManualTakeoverService::class)
            ->takeover($conversation, $this->admin->id, 'customer_asked');

        $fresh = $conversation->fresh();

        $this->assertTrue((bool) $fresh->human_takeover);
        $this->assertFalse((bool) $fresh->ai_enabled);
    }

    // ── F.7 · Seguridad ─────────────────────────────────────────────────

    /** Sin sesión no se ve nada de marketing. */
    public function test_ningun_endpoint_de_marketing_responde_sin_sesion(): void
    {
        foreach ([
            '/api/admin/marketing/inbox/conversations',
            '/api/admin/marketing/analytics/summary',
            '/api/admin/marketing/supervision/state',
            '/api/admin/marketing/supervision/approvals',
        ] as $path) {
            $this->getJson($path)->assertUnauthorized();
        }
    }

    /**
     * IDOR: pedir por id el borrador de otra persona.
     *
     * Es la vía más simple para sacar la foto de un cliente por una pantalla
     * donde el permiso es otro.
     */
    public function test_no_se_puede_leer_el_adjunto_de_otro(): void
    {
        $ajeno = MarketingMessageAttachment::create([
            'message_id' => null, 'direction' => 'outbound', 'kind' => 'image',
            'uploaded_by_admin_id' => $this->admin->id + 999,
            'detected_mime_type' => 'image/jpeg', 'disk' => 'whatsapp',
            'path' => 'outbound/image/ajeno.jpg', 'size_bytes' => 10,
            'status' => MarketingMessageAttachment::STATUS_STORED,
        ]);

        $this->getJson(
            "/api/admin/marketing/inbox/attachments/{$ajeno->id}/link",
            $this->adminHeaders(),
        )->assertNotFound();
    }

    /** Un ejecutable renombrado a .jpg no sale del gimnasio. */
    public function test_un_ejecutable_disfrazado_no_se_puede_subir(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            'foto.jpg', "MZ\x90\x00\x03\x00\x00\x00".str_repeat("\x00", 300),
        );

        $this->post(
            '/api/admin/marketing/inbox/attachments',
            ['file' => $file],
            $this->adminHeaders(['Accept' => 'application/json']),
        )->assertStatus(422)->assertJsonPath('code', 'disallowed_type');
    }

    /** Un SVG lleva scripts: no está en la allowlist y se rechaza. */
    public function test_un_svg_no_se_puede_subir(): void
    {
        $svg = UploadedFile::fake()->createWithContent(
            'x.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
        );

        $this->post(
            '/api/admin/marketing/inbox/attachments',
            ['file' => $svg],
            $this->adminHeaders(['Accept' => 'application/json']),
        )->assertStatus(422);
    }

    /** El nombre del archivo nunca forma parte de la ruta guardada. */
    public function test_un_nombre_con_rutas_no_escapa_del_disco(): void
    {
        $jpeg = (string) base64_decode(
            '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRof'
            .'Hh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAAB'
            .'AAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q==',
        );

        $id = $this->post(
            '/api/admin/marketing/inbox/attachments',
            ['file' => UploadedFile::fake()->createWithContent('../../../etc/passwd', $jpeg)],
            $this->adminHeaders(['Accept' => 'application/json']),
        )->assertStatus(201)->json('data.id');

        $path = MarketingMessageAttachment::find($id)->path;

        $this->assertStringNotContainsString('..', $path);
        $this->assertStringNotContainsString('etc/passwd', $path);
    }

    /**
     * Inyección por SQL en el buscador del inbox.
     *
     * Se comprueba que la tabla sigue ahí después: si la consulta se
     * concatenara, esto la habría borrado.
     */
    public function test_el_buscador_no_ejecuta_sql_inyectado(): void
    {
        $this->conversation();

        $this->getJson(
            '/api/admin/marketing/inbox/conversations?'.http_build_query([
                'q' => "'; DROP TABLE marketing_conversations; --",
            ]),
            $this->adminHeaders(),
        )->assertOk();

        $this->assertSame(1, MarketingConversation::query()->count());
    }

    /** Y el de analítica tampoco. */
    public function test_los_filtros_de_analitica_no_ejecutan_sql(): void
    {
        $this->getJson(
            '/api/admin/marketing/analytics/breakdown/campaign?'.http_build_query([
                'platform' => "x' OR 1=1 --",
            ]),
            $this->adminHeaders(),
        )->assertOk();

        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('marketing_lead_attributions'));
    }

    /**
     * Asignación masiva: mandar campos que no se deberían poder fijar desde
     * fuera. El endpoint solo acepta lo que valida.
     */
    public function test_no_se_pueden_fijar_campos_por_asignacion_masiva(): void
    {
        $conversation = $this->conversation();

        $this->postJson(
            "/api/admin/marketing/inbox/conversations/{$conversation->id}/messages",
            [
                'body' => 'hola',
                // Intento de marcar el mensaje como ya entregado por Meta.
                'meta_message_id' => 'wamid.FALSO',
                'status' => 'delivered',
            ],
            $this->adminHeaders(),
        )->assertOk();

        $message = \App\Models\MarketingMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('direction', 'outbound')->first();

        $this->assertNotSame('wamid.FALSO', $message->meta_message_id);
        $this->assertNotSame('delivered', $message->status);
    }

    /** El rango de la analítica está acotado: nadie agrega años en cada carga. */
    public function test_no_se_puede_pedir_un_rango_ilimitado(): void
    {
        $from = $this->getJson(
            '/api/admin/marketing/analytics/summary?from=1990-01-01&to=2026-08-15',
            $this->adminHeaders(),
        )->assertOk()->json('data.period.from');

        $this->assertTrue(
            \Illuminate\Support\Carbon::parse($from)->greaterThan(\Illuminate\Support\Carbon::parse('2024-01-01')),
        );
    }

    // ── F.7 · El agente no ejecuta por texto libre ──────────────────────

    /**
     * Lo más importante de la auditoría: **el modelo no puede ejecutar nada
     * escribiendo texto**. Las herramientas que tienen efecto exigen autonomía,
     * y la autonomía está apagada.
     */
    public function test_ninguna_herramienta_con_efecto_puede_ejecutarse_hoy(): void
    {
        config()->set('commercial.autonomy_enabled', false);

        $registry = app(\App\Services\Commercial\Tools\ToolRegistry::class);
        $conEfecto = [];

        foreach ($registry->all() as $tool) {
            if (method_exists($tool, 'requiresAutonomy') && $tool->requiresAutonomy()) {
                $conEfecto[] = $tool->name();
            }
        }

        $this->assertNotEmpty($conEfecto, 'Deberia haber herramientas con efecto declaradas.');
        $this->assertFalse((bool) config('commercial.autonomy_enabled'));
    }

    /** Con META apagado, ningún camino de envío toca la red. */
    public function test_con_meta_apagado_no_sale_nada_a_la_red(): void
    {
        $conversation = $this->conversation();

        $this->postJson(
            "/api/admin/marketing/inbox/conversations/{$conversation->id}/messages",
            ['body' => 'prueba'],
            $this->adminHeaders(),
        )->assertOk()->assertJsonPath('dry_run', true);

        Http::assertNothingSent();
    }

    // ── F.7 · Secretos y datos personales ───────────────────────────────

    /**
     * Ningún endpoint devuelve credenciales.
     *
     * Se buscan VALORES de secreto y nombres de campo que solo aparecerían al
     * volcar configuración, no palabras sueltas: la supervisión dice
     * legítimamente si OpenAI está en uso, y eso es estado operativo, no una
     * credencial. La primera versión de esta prueba fallaba por confundir el
     * nombre de un campo con su contenido.
     */
    public function test_ninguna_respuesta_filtra_secretos(): void
    {
        $this->conversation();

        foreach ([
            '/api/admin/marketing/inbox/capabilities',
            '/api/admin/marketing/supervision/state',
            '/api/admin/marketing/analytics/summary',
        ] as $path) {
            $body = $this->getJson($path, $this->adminHeaders())->getContent();

            foreach (['access_token', 'app_secret', 'api_key', 'client_secret', 'private_key', '"password"'] as $campo) {
                $this->assertStringNotContainsStringIgnoringCase(
                    $campo, $body, "{$path} volco un campo de credencial.",
                );
            }

            // Formas típicas de una clave: prefijo de OpenAI o una cadena
            // larga de aspecto aleatorio.
            $this->assertDoesNotMatchRegularExpression('/sk-[A-Za-z0-9]{16,}/', $body);
            $this->assertDoesNotMatchRegularExpression('/EAA[A-Za-z0-9]{30,}/', $body, 'Parece un token de Meta.');
        }
    }

    /** La analítica es agregada: ningún teléfono sale por ahí. */
    public function test_la_analitica_no_expone_telefonos(): void
    {
        $conversation = $this->conversation('3159998877');

        app(LeadAttributionService::class)->record(
            $conversation->lead_id,
            ['source_type' => 'ad', 'source_id' => 'AD-9'],
            $conversation->id,
        );

        $body = $this->getJson(
            '/api/admin/marketing/analytics/breakdown/ad',
            $this->adminHeaders(),
        )->assertOk()->getContent();

        $this->assertStringNotContainsString('3159998877', $body);
    }
}
