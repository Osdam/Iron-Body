<?php

namespace Tests\Feature\Commercial;

use App\Models\Admin;
use App\Models\CommercialAlert;
use App\Models\Incident;
use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\Member;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Services\Commercial\CommercialAlertService;
use App\Services\IronGuard\ChannelHealthDetector;
use App\Services\IronGuard\IncidentRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Incidentes técnicos y alertas comerciales.
 *
 * Son dos bandejas distintas a propósito. Un incidente es una avería —un disco
 * lleno, un worker caído— y se cierra arreglando algo. Una alerta comercial es
 * **una persona esperando**: un pago a medias, alguien que escribió y nadie le
 * contestó. Se cierran distinto y las mira gente distinta.
 *
 * Lo que más se prueba aquí es la deduplicación, porque sin ella las dos
 * bandejas se vuelven ruido en un día: la evaluación corre cada pocos minutos y
 * un solo pago pendiente abriría noventa y seis alertas. Y una bandeja que se
 * ignora es peor que no tenerla.
 */
class CommercialAlertsTest extends TestCase
{
    use RefreshDatabase;

    private CommercialAlertService $alerts;

    private IncidentRecorder $incidents;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('meta.enabled', false);
        Http::fake();
        Carbon::setTestNow(Carbon::parse('2026-08-15 12:00:00', 'UTC'));

        $this->alerts = app(CommercialAlertService::class);
        $this->incidents = app(IncidentRecorder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── Montaje ─────────────────────────────────────────────────────────

    private function conversation(bool $answered = false, int $hoursAgo = 6): MarketingConversation
    {
        $lead = MarketingLead::create([
            'channel' => 'whatsapp', 'source' => 'inbound',
            'phone' => '3'.str_pad((string) random_int(1, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 'new',
        ]);

        return MarketingConversation::create([
            'lead_id' => $lead->id, 'channel' => 'whatsapp', 'status' => 'open',
            'ai_enabled' => true, 'human_takeover' => false,
            'last_inbound_at' => now()->subHours($hoursAgo),
            'last_outbound_at' => $answered ? now()->subHours($hoursAgo - 1) : null,
        ]);
    }

    private function pendingPayment(int $hoursAgo = 5): PaymentTransaction
    {
        $user = User::create([
            'name' => 'P'.uniqid(), 'email' => uniqid().'@ib.test', 'password' => 'x',
        ]);
        $member = Member::create([
            'user_id' => $user->id, 'full_name' => 'P', 'document_number' => (string) random_int(10000000, 99999999),
            'phone' => '3001112233',
        ]);

        $payment = PaymentTransaction::create([
            'reference' => 'R'.uniqid(), 'idempotency_key' => 'I'.uniqid('', true),
            'member_id' => $member->id, 'user_id' => $user->id,
            'amount' => 90000, 'currency' => 'COP', 'status' => 'pending', 'provider' => 'wompi',
        ]);

        $payment->forceFill(['created_at' => now()->subHours($hoursAgo)])->save();

        return $payment;
    }

    // ── E.8 · Incidentes ────────────────────────────────────────────────

    public function test_1_un_error_unico_abre_un_incidente(): void
    {
        $this->incidents->record([
            'source' => 'wompi', 'kind' => 'timeout',
            'title' => 'La pasarela no respondió',
            'severity' => Incident::SEVERITY_HIGH,
        ]);

        $this->assertSame(1, Incident::query()->count());
    }

    /**
     * La prueba que justifica el fingerprint.
     *
     * Cien fallos del mismo defecto son UN incidente con cien ocurrencias, no
     * cien filas. Con cien filas, la pantalla se vuelve ilegible y se deja de
     * mirar, que es la forma habitual en que muere la observabilidad.
     */
    public function test_2_cien_veces_el_mismo_error_no_abren_cien_incidentes(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $this->incidents->record([
                'source' => 'openai', 'kind' => 'rate_limit',
                'title' => 'OpenAI respondió 429',
                'severity' => Incident::SEVERITY_MEDIUM,
            ]);
        }

        $this->assertSame(1, Incident::query()->count());
        $this->assertSame(100, (int) Incident::query()->first()->occurrences);
    }

    public function test_3_errores_distintos_abren_incidentes_distintos(): void
    {
        $this->incidents->record(['source' => 'wompi', 'kind' => 'timeout', 'title' => 'A']);
        $this->incidents->record(['source' => 'factus', 'kind' => 'timeout', 'title' => 'B']);

        $this->assertSame(2, Incident::query()->count());
    }

    public function test_4_un_incidente_critico_se_registra_como_tal(): void
    {
        $this->incidents->record([
            'source' => 'storage', 'kind' => 'disk_unavailable',
            'title' => 'No se puede escribir en el disco',
            'severity' => Incident::SEVERITY_CRITICAL,
        ]);

        $this->assertSame(Incident::SEVERITY_CRITICAL, Incident::query()->first()->severity);
    }

    public function test_6_el_correlation_id_viaja_con_el_incidente(): void
    {
        $this->incidents->record([
            'source' => 'meta_api', 'kind' => 'error_code_spike', 'title' => 'X',
            'correlation_ids' => ['corr-abc-123'],
        ]);

        $this->assertStringContainsString('corr-abc-123', json_encode(Incident::query()->first()->correlation_ids));
    }

    public function test_7_y_8_un_incidente_se_resuelve_y_se_puede_reabrir(): void
    {
        $incident = $this->incidents->record(['source' => 'queue', 'kind' => 'failed_jobs', 'title' => 'X']);

        $incident->forceFill([
            'status' => Incident::STATUS_RESOLVED, 'resolved_at' => now(),
        ])->save();

        $this->travel(2)->days();

        // El mismo defecto vuelve: se reabre en su sitio, no crea otra fila.
        $again = $this->incidents->record(['source' => 'queue', 'kind' => 'failed_jobs', 'title' => 'X']);

        $this->assertSame($incident->id, $again->id);
        $this->assertSame(Incident::STATUS_OPEN, $again->fresh()->status);
        $this->assertSame(1, Incident::query()->count());
    }

    /** La evidencia no puede llevar credenciales ni datos de tarjeta. */
    public function test_9_la_evidencia_no_arrastra_secretos(): void
    {
        $incident = $this->incidents->record([
            'source' => 'wompi', 'kind' => 'signature_error', 'title' => 'Firma inválida',
            'evidence' => ['reference' => 'REF-123', 'status' => 'declined'],
        ]);

        $evidence = json_encode($incident->evidence);

        foreach (['token', 'password', 'secret', 'api_key', 'authorization'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, $evidence);
        }
    }

    /** El detector corre entero aunque no haya nada que reportar. */
    public function test_el_escaneo_no_inventa_incidentes(): void
    {
        $found = app(ChannelHealthDetector::class)->scan();

        $this->assertIsArray($found);
        // Con una base limpia, ningún servicio está roto.
        $this->assertSame(0, Incident::query()->where('source', 'storage')->count());
    }

    // ── E.9 · Alertas comerciales ───────────────────────────────────────

    public function test_un_pago_a_medias_abre_una_alerta(): void
    {
        $this->pendingPayment();

        $this->alerts->evaluate();

        $alert = CommercialAlert::query()->where('type', CommercialAlert::TYPE_PAYMENT_PENDING)->first();

        $this->assertNotNull($alert, 'No se abrio la alerta del pago pendiente.');
        $this->assertSame(CommercialAlert::SEVERITY_HIGH, $alert->severity);
        $this->assertSame(90000.0, (float) $alert->opportunity_value);
    }

    public function test_quien_escribio_y_nadie_contesto_abre_una_alerta(): void
    {
        $this->conversation(answered: false);

        $this->alerts->evaluate();

        $this->assertSame(1, CommercialAlert::query()
            ->where('type', CommercialAlert::TYPE_NO_REPLY)->count());
    }

    public function test_una_conversacion_contestada_no_abre_alerta(): void
    {
        $this->conversation(answered: true);

        $this->alerts->evaluate();

        $this->assertSame(0, CommercialAlert::query()
            ->where('type', CommercialAlert::TYPE_NO_REPLY)->count());
    }

    /**
     * La prueba central de E.9.
     *
     * La evaluación corre cada pocos minutos. Sin huella, un pago pendiente
     * durante un día abriría noventa y seis alertas y la bandeja sería ruido.
     */
    public function test_evaluar_diez_veces_no_abre_diez_alertas(): void
    {
        $this->pendingPayment();

        for ($i = 0; $i < 10; $i++) {
            $this->alerts->evaluate();
        }

        $this->assertSame(1, CommercialAlert::query()
            ->where('type', CommercialAlert::TYPE_PAYMENT_PENDING)->count());
    }

    /** Cuando el pago entra, la alerta se cierra sola y dice por qué. */
    public function test_una_alerta_se_cierra_sola_cuando_deja_de_aplicar(): void
    {
        $payment = $this->pendingPayment();
        $this->alerts->evaluate();

        $payment->forceFill(['status' => 'approved', 'paid_at' => now()])->save();
        $this->alerts->evaluate();

        $alert = CommercialAlert::query()->where('type', CommercialAlert::TYPE_PAYMENT_PENDING)->first();

        $this->assertSame(CommercialAlert::STATUS_AUTO_CLOSED, $alert->status);
        $this->assertNotNull($alert->resolution_note, 'Se cerro sin decir por que.');
        $this->assertStringContainsString('pago', $alert->resolution_note);
    }

    /**
     * Lo que una persona decidió ignorar NO se reabre solo.
     *
     * Reabrirla en la siguiente evaluación convierte la decisión en ruido y
     * entrena a no usar el botón.
     */
    public function test_una_alerta_ignorada_no_vuelve_sola(): void
    {
        $this->pendingPayment();
        $this->alerts->evaluate();

        $alert = CommercialAlert::query()->first();
        $admin = Admin::create([
            'name' => 'S', 'email' => 's@ironbody.test', 'password' => 'x',
            'role' => Admin::ROLE_SUPER_ADMIN, 'status' => 'active',
        ]);
        $this->alerts->resolve($alert, $admin->id, 'ignored', 'Ya se habló con el cliente.');

        $this->alerts->evaluate();

        $this->assertSame(CommercialAlert::STATUS_IGNORED, $alert->fresh()->status);
        $this->assertSame(1, CommercialAlert::query()->count());
    }

    public function test_una_alerta_se_puede_asignar(): void
    {
        $this->conversation(answered: false);
        $this->alerts->evaluate();

        $admin = Admin::create([
            'name' => 'S', 'email' => 's2@ironbody.test', 'password' => 'x',
            'role' => Admin::ROLE_SUPER_ADMIN, 'status' => 'active',
        ]);

        $assigned = $this->alerts->assign(CommercialAlert::query()->first(), $admin->id);

        $this->assertSame(CommercialAlert::STATUS_ASSIGNED, $assigned->status);
        $this->assertSame($admin->id, $assigned->owner_admin_id);
    }

    /** Una alerta pasada de plazo se puede distinguir de una reciente. */
    public function test_una_alerta_vencida_se_reconoce(): void
    {
        $this->conversation(answered: false);
        $this->alerts->evaluate();

        $alert = CommercialAlert::query()->first();

        $this->assertFalse($alert->isOverdue());

        $this->travel(5)->hours();

        $this->assertTrue($alert->fresh()->isOverdue());
    }

    /** Detectar no es actuar: ninguna alerta manda un mensaje. */
    public function test_abrir_una_alerta_no_escribe_a_nadie(): void
    {
        $this->conversation(answered: false);
        $this->pendingPayment();

        $this->alerts->evaluate();

        Http::assertNothingSent();
        $this->assertSame(0, \App\Models\MarketingMessage::query()
            ->where('direction', 'outbound')->count());
    }

    /** Las dos bandejas no se mezclan. */
    public function test_un_incidente_tecnico_no_aparece_como_alerta_comercial(): void
    {
        $this->incidents->record([
            'source' => 'storage', 'kind' => 'disk_unavailable', 'title' => 'Disco lleno',
            'severity' => Incident::SEVERITY_CRITICAL,
        ]);

        $this->alerts->evaluate();

        $this->assertSame(1, Incident::query()->count());
        $this->assertSame(0, CommercialAlert::query()->count());
    }
}
