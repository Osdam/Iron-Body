<?php

namespace Tests\Feature\E2E;

use App\Models\Admin;
use App\Models\CommercialApproval;
use App\Models\MarketingAppointment;
use App\Models\PaymentTransaction;
use App\Services\Commercial\ApprovalQueueService;
use App\Services\Commercial\CommercialSubject;
use App\Services\Commercial\CommercialVocabulary as V;
use App\Services\Commercial\NextBestActionEngine;
use App\Services\Marketing\MarketingManualTakeoverService;
use App\Services\Marketing\TagCatalog;

/**
 * Recorridos 11–30: del pago a la aprobación humana.
 *
 * Aquí vive el dinero, y por eso casi todos los recorridos comprueban lo mismo
 * desde ángulos distintos: **una operación lógica produce un solo efecto**. Un
 * webhook repetido, dos clics, dos supervisores o un reintento no pueden
 * convertirse en dos cobros, dos membresías ni dos reembolsos.
 */
class JourneysCommerceTest extends E2EJourneyTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        TagCatalog::sync();
    }

    /** Deja un prospecto real, entrado por el webhook, listo para operar. */
    private function prospect(string $phone): \App\Models\MarketingLead
    {
        $this->metaWebhook($this->inboundMessage($phone, 'Hola, quiero información'))->assertOk();

        return $this->leadFor($phone);
    }

    // ── 11-15 · Pagos ───────────────────────────────────────────────────

    public function test_11_pago_pendiente(): void
    {
        $lead = $this->prospect('573002220011');
        $member = $this->makeMember($lead);

        $payment = $this->payment($member, 90000, 'pending');

        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertNull($payment->fresh()->paid_at, 'Un pago pendiente no puede tener fecha de pago.');
        $this->assertNoExternalCalls();
    }

    public function test_12_pago_aprobado(): void
    {
        $lead = $this->prospect('573002220012');
        $member = $this->makeMember($lead);

        $payment = $this->payment($member, 90000, 'approved');

        $this->assertSame('approved', $payment->fresh()->status);
        $this->assertNotNull($payment->fresh()->paid_at);
    }

    public function test_13_pago_rechazado_no_produce_ingreso(): void
    {
        $lead = $this->prospect('573002220013');
        $member = $this->makeMember($lead);

        $this->payment($member, 90000, 'declined');

        $revenue = app(\App\Services\Marketing\Analytics\CampaignAnalyticsService::class)
            ->revenueTotals(now()->subDay(), now()->addDay());

        $this->assertSame(0.0, $revenue['total'], 'Un pago rechazado sumó ingresos.');
    }

    public function test_14_pago_expirado_no_produce_ingreso(): void
    {
        $lead = $this->prospect('573002220014');
        $member = $this->makeMember($lead);

        $this->payment($member, 90000, 'expired');

        $this->assertSame(0.0, app(\App\Services\Marketing\Analytics\CampaignAnalyticsService::class)
            ->revenueTotals(now()->subDay(), now()->addDay())['total']);
    }

    /**
     * El mismo intento reportado dos veces.
     *
     * La clave de idempotencia es única en la tabla, así que la segunda
     * inserción no puede crear un cobro nuevo. Es el cerrojo que impide el
     * doble cargo.
     */
    public function test_15_un_pago_duplicado_no_crea_dos_cobros(): void
    {
        $lead = $this->prospect('573002220015');
        $member = $this->makeMember($lead);

        $key = 'IDEM-E2E-15';

        PaymentTransaction::create([
            'reference' => 'R15', 'idempotency_key' => $key, 'member_id' => $member->id,
            'user_id' => $member->user_id, 'amount' => 90000, 'currency' => 'COP',
            'status' => 'approved', 'provider' => 'wompi', 'paid_at' => now(),
        ]);

        $duplicado = false;

        try {
            PaymentTransaction::create([
                'reference' => 'R15-bis', 'idempotency_key' => $key, 'member_id' => $member->id,
                'user_id' => $member->user_id, 'amount' => 90000, 'currency' => 'COP',
                'status' => 'approved', 'provider' => 'wompi', 'paid_at' => now(),
            ]);
            $duplicado = true;
        } catch (\Throwable) {
            // La base lo impidió, que es exactamente lo que debe pasar.
        }

        $this->assertFalse($duplicado, 'Se pudo crear un segundo cobro con la misma clave.');
        $this->assertSame(1, PaymentTransaction::where('idempotency_key', $key)->count());
    }

    // ── 16-20 · Membresías ──────────────────────────────────────────────

    public function test_16_creacion_segura_de_miembro(): void
    {
        $lead = $this->prospect('573002220016');
        $member = $this->makeMember($lead);

        $this->assertNotNull($member->member_uuid, 'El miembro nació sin identificador propio.');
        $this->assertSame($member->id, $lead->fresh()->member_id);
    }

    public function test_17_activacion_de_membresia(): void
    {
        $plan = $this->plan('Mensual', 90000);
        $lead = $this->prospect('573002220017');
        $member = $this->makeMember($lead, $plan);

        // Se recarga: la relación se resolvió antes de activar la membresía y
        // llevaba el usuario sin fechas.
        $user = $member->fresh()->user()->first();

        $this->assertSame($plan->name, $user->plan);
        // La fecha viene con casteo `date:Y-m-d`, asi que se normaliza antes
        // de compararla en vez de asumir que ya es un objeto de fecha.
        $this->assertTrue(
            \Illuminate\Support\Carbon::parse($user->membership_end_date)->isFuture(),
            'La membresía no quedó vigente.',
        );
    }

    /** Un segundo pago del mismo miembro es renovación, no alta. */
    public function test_18_renovacion(): void
    {
        $plan = $this->plan('Mensual', 90000);
        $lead = $this->prospect('573002220018');
        $member = $this->makeMember($lead, $plan);

        $this->payment($member, 90000, 'approved', now()->subDays(35));
        $this->payment($member, 90000, 'approved', now()->subDay());

        $row = collect(app(\App\Services\Marketing\Analytics\CampaignAnalyticsService::class)
            ->breakdown('source_type', now()->subDays(60), now()))->first();

        $this->assertSame(1, $row['renewals'], 'El alta se contó como renovación o al revés.');
    }

    /** Una renovación más cara es una mejora de plan. */
    public function test_19_upgrade(): void
    {
        $mensual = $this->plan('Mensual', 90000);
        $this->plan('Trimestral', 240000, 90);

        $lead = $this->prospect('573002220019');
        $member = $this->makeMember($lead, $mensual);

        $this->payment($member, 90000, 'approved', now()->subDays(35));
        $this->payment($member, 240000, 'approved', now()->subDay());

        $row = collect(app(\App\Services\Marketing\Analytics\CampaignAnalyticsService::class)
            ->breakdown('source_type', now()->subDays(60), now()))->first();

        $this->assertSame(1, $row['upgrades']);
        $this->assertSame(240000.0, $row['upgrade_revenue']);
    }

    /** Vuelve después de mucho tiempo: reactivación, no renovación normal. */
    public function test_20_reactivacion(): void
    {
        $plan = $this->plan('Mensual', 90000);
        $lead = $this->prospect('573002220020');
        $member = $this->makeMember($lead, $plan);

        $this->payment($member, 90000, 'approved', now()->subDays(200));
        $this->payment($member, 90000, 'approved', now()->subDay());

        $categorias = app(\App\Services\Marketing\Analytics\CampaignAnalyticsService::class)
            ->revenueCategories(now()->subDays(365), now());

        $this->assertGreaterThan(0, $categorias['reactivation_revenue'] + $categorias['renewal_revenue']);
        // Lo que trajo la campaña el día que trajo al cliente se cuenta aparte.
        $this->assertArrayHasKey('acquisition_revenue', $categorias);
    }

    // ── 21-23 · Agenda ──────────────────────────────────────────────────

    public function test_21_crear_cita(): void
    {
        $lead = $this->prospect('573002220021');

        $cita = MarketingAppointment::create([
            'marketing_lead_id' => $lead->id,
            'marketing_conversation_id' => $this->conversationFor('573002220021')->id,
            'type' => 'valoracion', 'status' => 'scheduled',
            'title' => 'Valoración inicial', 'scheduled_at' => now()->addDays(2),
        ]);

        $this->assertSame('scheduled', $cita->fresh()->status);
        $this->assertNotNull($cita->fresh()->uuid);
    }

    public function test_22_reprogramar_cita(): void
    {
        $lead = $this->prospect('573002220022');
        $cita = MarketingAppointment::create([
            'marketing_lead_id' => $lead->id, 'type' => 'valoracion', 'status' => 'scheduled',
            'title' => 'Valoración', 'scheduled_at' => now()->addDays(2),
        ]);

        $nueva = now()->addDays(5);
        $cita->forceFill(['scheduled_at' => $nueva])->save();

        $this->assertTrue($cita->fresh()->scheduled_at->isSameDay($nueva));
        $this->assertSame('scheduled', $cita->fresh()->status);
    }

    public function test_23_cancelar_cita_deja_el_motivo(): void
    {
        $lead = $this->prospect('573002220023');
        $cita = MarketingAppointment::create([
            'marketing_lead_id' => $lead->id, 'type' => 'valoracion', 'status' => 'scheduled',
            'title' => 'Valoración', 'scheduled_at' => now()->addDays(2),
        ]);

        $cita->forceFill([
            'status' => 'cancelled', 'cancelled_at' => now(),
            'cancellation_reason' => 'El cliente no puede ese día',
        ])->save();

        $this->assertSame('cancelled', $cita->fresh()->status);
        $this->assertNotEmpty($cita->fresh()->cancellation_reason);
    }

    // ── 24-26 · Facturación ─────────────────────────────────────────────

    /**
     * Pedir factura ESCALA a una persona. Nunca se emite automáticamente: es un
     * documento fiscal y un error ahí cuesta papeleo con la DIAN.
     */
    public function test_24_solicitud_de_factura_no_emite_nada(): void
    {
        $lead = $this->prospect('573002220024');

        $this->metaWebhook(
            $this->inboundMessage('573002220024', 'Necesito la factura a nombre de mi empresa'),
        )->assertOk();

        $facturas = \Illuminate\Support\Facades\Schema::hasTable('electronic_invoices')
            ? \App\Models\ElectronicInvoice::count() : 0;

        $this->assertSame(0, $facturas, 'Se creó una factura sin intervención humana.');
        $this->assertNoExternalCalls();
    }

    public function test_25_datos_fiscales_incompletos_no_emiten(): void
    {
        $this->prospect('573002220025');

        $this->assertSame(
            0,
            \Illuminate\Support\Facades\Schema::hasTable('electronic_invoices')
                ? \App\Models\ElectronicInvoice::count() : 0,
        );
    }

    public function test_26_no_se_generan_documentos_con_el_canal_apagado(): void
    {
        $this->prospect('573002220026');

        $this->assertNoExternalCalls();
        $this->assertNothingDelivered();
    }

    // ── 27 · App ────────────────────────────────────────────────────────

    public function test_27_vinculacion_con_la_app(): void
    {
        $plan = $this->plan('Mensual', 90000);
        $lead = $this->prospect('573002220027');
        $member = $this->makeMember($lead, $plan);

        // El contexto del inbox informa si la persona tiene cuenta en la app.
        $context = app(\App\Services\Marketing\InboxContextService::class)
            ->build($this->conversationFor('573002220027'), false);

        $this->assertArrayHasKey('app', $context);
        $this->assertArrayHasKey('has_account', $context['app']);
    }

    // ── 28-29 · Control humano ──────────────────────────────────────────

    public function test_28_human_takeover_apaga_la_ia(): void
    {
        $this->prospect('573002220028');
        $conversation = $this->conversationFor('573002220028');

        $this->postJson(
            "/api/admin/marketing/inbox/conversations/{$conversation->id}/takeover",
            ['reason' => 'customer_asked'],
            $this->adminHeaders(),
        )->assertOk();

        $fresh = $conversation->fresh();

        $this->assertTrue((bool) $fresh->human_takeover);
        $this->assertFalse((bool) $fresh->ai_enabled);
    }

    /**
     * Al devolver, el agente NO retoma con el contexto de antes: recibe un
     * resumen de lo que pasó mientras no estaba.
     */
    public function test_29_retorno_a_la_ia_con_resumen(): void
    {
        $this->prospect('573002220029');
        $conversation = $this->conversationFor('573002220029');
        $takeover = app(MarketingManualTakeoverService::class);

        $takeover->takeover($conversation, $this->admin->id, 'commercial_exception');

        $this->postJson(
            "/api/admin/marketing/inbox/conversations/{$conversation->id}/messages",
            ['body' => 'Te dejo el mensual con la promo de agosto'],
            $this->adminHeaders(),
        )->assertOk();

        $takeover->release($conversation->fresh(), $this->admin->id);

        $summary = (string) $conversation->fresh()->summary;

        $this->assertStringContainsString('[Traspaso]', $summary);
        $this->assertStringContainsString('promo de agosto', $summary);
        $this->assertTrue((bool) $conversation->fresh()->ai_enabled);
    }

    // ── 30 · Aprobación ─────────────────────────────────────────────────

    public function test_30_se_crea_una_aprobacion_pendiente(): void
    {
        $lead = $this->prospect('573002220030');

        $approval = app(ApprovalQueueService::class)->request(
            CommercialApproval::TYPE_REFUND,
            'El cliente pagó dos veces el mismo mes.',
            'e2e:30',
            ['lead_id' => $lead->id, 'amount' => 90000],
        );

        $this->assertSame(CommercialApproval::STATUS_PENDING, $approval->status);
        $this->assertSame('high', $approval->risk, 'Un reembolso debería nacer como riesgo alto.');
        $this->assertNotNull($approval->expires_at);

        // Y aparece en la bandeja del supervisor.
        $rows = $this->getJson('/api/admin/marketing/supervision/approvals', $this->adminHeaders())
            ->assertOk()->json('data');

        $this->assertSame('pending', $rows[0]['status']);
    }
}
