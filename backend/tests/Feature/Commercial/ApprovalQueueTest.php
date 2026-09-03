<?php

namespace Tests\Feature\Commercial;

use App\Models\Admin;
use App\Models\CommercialApproval;
use App\Services\Commercial\ApprovalQueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * La bandeja donde muere la autonomía.
 *
 * Lo que se prueba aquí no es que se puedan aprobar cosas, sino que **no se
 * puedan aprobar dos veces**. Devolver dinero dos veces, emitir dos notas
 * crédito o fusionar dos identidades por partida doble son errores que no se
 * arreglan con un `undo`: se arreglan con papeleo, dinero y una llamada
 * incómoda a un cliente.
 *
 * Por eso casi todas las pruebas de este archivo describen un intento de hacer
 * algo dos veces, o de hacerlo cuando ya no se puede.
 */
class ApprovalQueueTest extends TestCase
{
    use RefreshDatabase;

    private ApprovalQueueService $queue;

    private Admin $supervisor;

    private Admin $otro;

    private array $adminHeaders = [];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('meta.enabled', false);
        Http::fake();
        Carbon::setTestNow(Carbon::parse('2026-08-15 10:00:00', 'UTC'));

        $this->queue = app(ApprovalQueueService::class);

        $this->supervisor = Admin::create([
            'name' => 'Supervisora', 'email' => 'sup@ironbody.test',
            'password' => 'x', 'role' => Admin::ROLE_SUPER_ADMIN, 'status' => 'active',
        ]);
        $this->otro = Admin::create([
            'name' => 'Otro', 'email' => 'otro@ironbody.test',
            'password' => 'x', 'role' => Admin::ROLE_ADMINISTRADOR, 'status' => 'active',
        ]);

        $this->adminHeaders = $this->actingAsAdmin($this->supervisor);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function adminHeaders(array $headers = []): array
    {
        return array_merge($this->adminHeaders, $headers);
    }

    private function pedirReembolso(array $context = []): CommercialApproval
    {
        return $this->queue->request(
            CommercialApproval::TYPE_REFUND,
            'El cliente pagó dos veces el mismo mes.',
            $context['key'] ?? 'approval:refund:test:'.uniqid(),
            array_merge(['amount' => 90000, 'risk' => 'high'], $context),
        );
    }

    // ── Solicitar ───────────────────────────────────────────────────────

    public function test_una_solicitud_nace_pendiente_y_con_caducidad(): void
    {
        $approval = $this->pedirReembolso();

        $this->assertSame(CommercialApproval::STATUS_PENDING, $approval->status);
        $this->assertNotNull($approval->expires_at);
        $this->assertTrue($approval->isOpen());
    }

    /** Devolver dinero es de riesgo alto aunque nadie lo diga. */
    public function test_el_riesgo_por_defecto_depende_de_lo_que_se_pide(): void
    {
        $reembolso = $this->queue->request(
            CommercialApproval::TYPE_REFUND, 'x', 'k1', [],
        );
        $descuento = $this->queue->request(
            CommercialApproval::TYPE_DISCOUNT, 'x', 'k2', [],
        );

        $this->assertSame('high', $reembolso->risk);
        $this->assertSame('medium', $descuento->risk);
    }

    /**
     * El mismo hecho pedido dos veces —un reintento del agente, un webhook
     * repetido— no puede abrir dos solicitudes que después alguien aprobaría
     * por separado.
     */
    public function test_pedir_lo_mismo_dos_veces_no_abre_dos_solicitudes(): void
    {
        $primera = $this->pedirReembolso(['key' => 'approval:refund:member:7']);
        $segunda = $this->pedirReembolso(['key' => 'approval:refund:member:7']);

        $this->assertSame($primera->id, $segunda->id);
        $this->assertSame(1, CommercialApproval::query()->count());
    }

    public function test_un_tipo_inventado_se_rechaza(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->queue->request('regalar_gimnasio', 'x', 'k3');
    }

    /** Sin importe, la columna queda NULA. Un reembolso de cero no existe. */
    public function test_una_operacion_sin_dinero_no_inventa_un_importe(): void
    {
        $merge = $this->queue->request(
            CommercialApproval::TYPE_IDENTITY_MERGE,
            'Dos fichas con el mismo documento.',
            'k4',
        );

        $this->assertNull($merge->amount);
        $this->assertNull($merge->currency);
    }

    // ── Decidir ─────────────────────────────────────────────────────────

    public function test_aprobar_deja_quien_cuando_y_por_que(): void
    {
        $approval = $this->pedirReembolso();

        $result = $this->queue->approve($approval, $this->supervisor, 'Verificado con el extracto.');

        $this->assertTrue($result['ok']);
        $this->assertSame(CommercialApproval::STATUS_APPROVED, $result['approval']->status);
        $this->assertSame($this->supervisor->id, $result['approval']->decided_by_admin_id);
        $this->assertNotNull($result['approval']->decided_at);
        $this->assertSame('Verificado con el extracto.', $result['approval']->decision_comment);
    }

    public function test_rechazar_tambien_queda_registrado(): void
    {
        $approval = $this->pedirReembolso();

        $result = $this->queue->reject($approval, $this->supervisor, 'No procede.');

        $this->assertTrue($result['ok']);
        $this->assertSame(CommercialApproval::STATUS_REJECTED, $result['approval']->status);
    }

    /** Pedir cambios NO cierra: sigue abierta para quien la pidió. */
    public function test_pedir_cambios_deja_la_solicitud_viva(): void
    {
        $approval = $this->pedirReembolso();

        $result = $this->queue->requestChanges($approval, $this->supervisor, 'Falta el comprobante.');

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['approval']->isOpen());
        $this->assertSame(CommercialApproval::STATUS_CHANGES_REQUESTED, $result['approval']->status);
    }

    // ── Lo que NO se puede hacer dos veces ──────────────────────────────

    /**
     * La prueba central del archivo.
     *
     * Dos supervisores mirando la misma bandeja, o el mismo pulsando dos veces
     * porque no vio el spinner. Solo la primera decisión vale.
     */
    public function test_dos_supervisores_no_pueden_aprobar_la_misma_solicitud(): void
    {
        $approval = $this->pedirReembolso();

        $primera = $this->queue->approve($approval, $this->supervisor);
        $segunda = $this->queue->approve($approval->fresh(), $this->otro);

        $this->assertTrue($primera['ok']);
        $this->assertFalse($segunda['ok'], 'La segunda aprobacion paso.');
        $this->assertSame('already_decided', $segunda['code']);
        $this->assertSame($this->supervisor->id, $segunda['approval']->decided_by_admin_id);
    }

    public function test_no_se_puede_rechazar_lo_ya_aprobado(): void
    {
        $approval = $this->pedirReembolso();
        $this->queue->approve($approval, $this->supervisor);

        $result = $this->queue->reject($approval->fresh(), $this->otro);

        $this->assertFalse($result['ok']);
        $this->assertSame(CommercialApproval::STATUS_APPROVED, $result['approval']->status);
    }

    /** Lo que ya ocurrió no se desautoriza cambiando una fila. */
    public function test_una_operacion_ejecutada_es_intocable(): void
    {
        $approval = $this->pedirReembolso();
        $this->queue->approve($approval, $this->supervisor);
        $this->queue->markExecuted($approval->fresh(), 'Reembolso enviado.');

        $result = $this->queue->reject($approval->fresh(), $this->supervisor);

        $this->assertFalse($result['ok']);
        $this->assertSame('already_executed', $result['code']);
        $this->assertSame(CommercialApproval::STATUS_EXECUTED, $result['approval']->status);
    }

    /** Y no se ejecuta dos veces. Ese es el error que devuelve dinero doble. */
    public function test_no_se_ejecuta_dos_veces(): void
    {
        $approval = $this->pedirReembolso();
        $this->queue->approve($approval, $this->supervisor);

        $primera = $this->queue->markExecuted($approval->fresh());
        $segunda = $this->queue->markExecuted($approval->fresh());

        $this->assertTrue($primera['ok']);
        $this->assertFalse($segunda['ok']);
        $this->assertSame('already_executed', $segunda['code']);
    }

    public function test_no_se_ejecuta_lo_que_nadie_aprobo(): void
    {
        $approval = $this->pedirReembolso();

        $result = $this->queue->markExecuted($approval);

        $this->assertFalse($result['ok']);
        $this->assertSame('not_approved', $result['code']);
    }

    // ── Caducidad ───────────────────────────────────────────────────────

    /**
     * Una autorización vencida no se puede aprobar, aunque el proceso que
     * marca las caducadas no haya pasado.
     */
    public function test_una_solicitud_vencida_ya_no_se_aprueba(): void
    {
        $approval = $this->pedirReembolso(['ttl_hours' => 1]);

        $this->travel(3)->hours();

        $result = $this->queue->approve($approval->fresh(), $this->supervisor);

        $this->assertFalse($result['ok']);
        $this->assertSame('expired', $result['code']);
        $this->assertSame(CommercialApproval::STATUS_EXPIRED, $result['approval']->status);
    }

    /**
     * La caducidad se calcula, no se cree.
     *
     * Si el job que marca las vencidas se para una noche, una solicitud
     * caducada NO puede volverse aprobable solo porque la columna siga
     * diciendo «pendiente».
     */
    public function test_la_caducidad_no_depende_de_que_un_job_la_marque(): void
    {
        $approval = $this->pedirReembolso(['ttl_hours' => 1]);

        $this->travel(5)->hours();

        $sinTocar = CommercialApproval::find($approval->id);

        $this->assertSame(CommercialApproval::STATUS_PENDING, $sinTocar->status, 'La columna deberia seguir intacta.');
        $this->assertTrue($sinTocar->hasExpired());
        $this->assertFalse($sinTocar->isOpen());
        $this->assertSame(CommercialApproval::STATUS_EXPIRED, $sinTocar->effectiveStatus());
    }

    public function test_el_barrido_cierra_las_vencidas(): void
    {
        $this->pedirReembolso(['ttl_hours' => 1, 'key' => 'a']);
        $this->pedirReembolso(['ttl_hours' => 200, 'key' => 'b']);

        $this->travel(5)->hours();

        $cerradas = $this->queue->expireStale();

        $this->assertSame(1, $cerradas);
        $this->assertSame(1, CommercialApproval::query()
            ->where('status', CommercialApproval::STATUS_PENDING)->count());
    }

    // ── Endpoints y permisos ────────────────────────────────────────────

    public function test_la_bandeja_pone_lo_pendiente_primero(): void
    {
        $vieja = $this->pedirReembolso(['key' => 'v']);
        $this->queue->approve($vieja, $this->supervisor);
        $this->pedirReembolso(['key' => 'n']);

        $rows = $this->getJson(
            '/api/admin/marketing/supervision/approvals',
            $this->adminHeaders(),
        )->assertOk()->json('data');

        $this->assertSame('pending', $rows[0]['status']);
    }

    public function test_recepcion_ve_la_pantalla_pero_no_puede_aprobar(): void
    {
        $recepcion = Admin::create([
            'name' => 'Recepción', 'email' => 'rec@ironbody.test',
            'password' => 'x', 'role' => Admin::ROLE_RECEPCION, 'status' => 'active',
        ]);
        // Recepción no entra en mercadeo por defecto; esta prueba fija las
        // reglas de la cola de aprobaciones, no quién accede al módulo.
        \App\Models\AdminRole::firstOrCreate(['name' => $recepcion->role], ['is_system' => true]);
        \App\Models\RolePermission::updateOrCreate(
            ['role' => $recepcion->role, 'permission' => 'marketing.view'], ['granted' => true],
        );
        app(\App\Support\Access\RolePermissionPolicy::class)->flush();

        $headers = $this->actingAsAdmin($recepcion);
        $approval = $this->pedirReembolso();

        // Ve el estado: es la pantalla desde la que trabaja.
        $this->getJson('/api/admin/marketing/supervision/state', $headers)->assertOk();

        // Pero no autoriza un reembolso.
        $this->postJson(
            "/api/admin/marketing/supervision/approvals/{$approval->id}/decide",
            ['decision' => 'approve'],
            $headers,
        )->assertStatus(403);

        $this->assertSame(CommercialApproval::STATUS_PENDING, $approval->fresh()->status);
    }

    public function test_pedir_cambios_sin_decir_cuales_se_rechaza(): void
    {
        $approval = $this->pedirReembolso();

        $this->postJson(
            "/api/admin/marketing/supervision/approvals/{$approval->id}/decide",
            ['decision' => 'request_changes'],
            $this->adminHeaders(),
        )->assertStatus(422);
    }

    /** Aprobar por segunda vez desde el endpoint devuelve conflicto, no 200. */
    public function test_el_endpoint_devuelve_conflicto_al_reaprobar(): void
    {
        $approval = $this->pedirReembolso();

        $this->postJson(
            "/api/admin/marketing/supervision/approvals/{$approval->id}/decide",
            ['decision' => 'approve'],
            $this->adminHeaders(),
        )->assertOk();

        $this->postJson(
            "/api/admin/marketing/supervision/approvals/{$approval->id}/decide",
            ['decision' => 'approve'],
            $this->adminHeaders(),
        )->assertStatus(409)->assertJsonPath('code', 'already_decided');
    }

    public function test_sin_sesion_no_se_ve_la_supervision(): void
    {
        $this->getJson('/api/admin/marketing/supervision/state')->assertUnauthorized();
    }
}
