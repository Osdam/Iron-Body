<?php

namespace Tests\Feature\Notifications;

use App\Http\Controllers\Crm\NotificationController;
use App\Models\Admin;
use App\Models\Notification;
use App\Services\NotificationService;
use App\Support\Access\CrmPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Valuations\ValuationFixtures;
use Tests\TestCase;

/**
 * La campanita del CRM y las alertas sonoras generales.
 *
 * P-1. Un aviso de un módulo solo existe para quien puede ver ese módulo: no
 * aparece, no cuenta, no viaja por el stream y no se marca. Antes la bandeja
 * era global y un pago lo veía cualquier sesión; ahora además SONARÍA.
 *
 * P-2. Crear una notificación a mano exige `content.manage` (lo mismo que un
 * push a los socios). Antes bastaba cualquier sesión: se podía fabricar un aviso
 * «alta» que suena en todo el CRM, o difundir a la app de todos los socios.
 */
class AdminNotificationVisibilityTest extends TestCase
{
    use RefreshDatabase;
    use ValuationFixtures;

    /** @var array<string, Notification> */
    private array $avisos = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock('2026-10-19 08:00');
        $servicio = app(NotificationService::class);
        foreach (['payment', 'membership', 'class', 'routine', 'trainer', 'system', 'security', 'promotion'] as $tipo) {
            $this->avisos[$tipo] = $servicio->createAdminNotification([
                'type' => $tipo, 'title' => "Aviso {$tipo}", 'message' => 'x', 'priority' => 'high', 'event_key' => "prueba-{$tipo}",
            ]);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return list<string> */
    private function tiposQueVe(array $h): array
    {
        $tipos = array_column($this->getJson('/api/admin/notifications?per_page=100', $h)->assertOk()->json('data'), 'type');
        sort($tipos);

        return $tipos;
    }

    public function test_cada_rol_ve_solo_los_avisos_de_lo_que_puede_ver(): void
    {
        $todos = ['class', 'membership', 'payment', 'promotion', 'routine', 'security', 'system', 'trainer'];
        $this->assertSame($todos, $this->tiposQueVe($this->h($this->superAdmin())));
        $this->assertSame($todos, $this->tiposQueVe($this->h($this->staff(Admin::ROLE_ADMINISTRADOR))));
        // Recepción: cobra, inscribe y ve socios; no ve rutinas ni entrenadores.
        $this->assertSame(['class', 'membership', 'payment', 'promotion', 'security', 'system'], $this->tiposQueVe($this->reception()));
        // Entrenador: socios, clases, rutinas y entrenadores; los pagos no.
        $this->assertSame(['class', 'membership', 'promotion', 'routine', 'security', 'system', 'trainer'], $this->tiposQueVe($this->h($this->staff(CrmPermission::ROLE_ENTRENADOR, $this->trainer()))));
        // Administrativo (moderación): nada de pagos, membresías, clases, rutinas ni entrenadores.
        $this->assertSame(['promotion', 'security', 'system'], $this->tiposQueVe($this->h($this->staff(Admin::ROLE_ADMINISTRATIVO))));
    }

    public function test_el_contador_la_busqueda_y_la_categoria_cuentan_lo_mismo_que_la_lista(): void
    {
        $administrativo = $this->h($this->staff(Admin::ROLE_ADMINISTRATIVO));
        $this->getJson('/api/admin/notifications/unread-count', $administrativo)->assertJsonPath('unread_count', 3);
        $this->assertSame([], $this->getJson('/api/admin/notifications?category=payment', $administrativo)->json('data'));
        $this->assertSame([], $this->getJson('/api/admin/notifications?search=Aviso%20payment', $administrativo)->json('data'));
        $this->getJson('/api/admin/notifications/unread-count', $this->reception())->assertJsonPath('unread_count', 6);
    }

    public function test_lo_que_no_ve_no_lo_marca_ni_solo_ni_con_marcar_todas(): void
    {
        $administrativo = $this->h($this->staff(Admin::ROLE_ADMINISTRATIVO));
        $this->postJson("/api/admin/notifications/{$this->avisos['payment']->uuid}/read", [], $administrativo)->assertNotFound();
        $this->postJson('/api/admin/notifications/read-all', [], $administrativo)->assertOk()->assertJsonPath('updated', 3);
        $this->assertSame('unread', $this->avisos['payment']->fresh()->status, 'marcar todas no toca el pago que no ve');
        $this->assertSame('read', $this->avisos['system']->fresh()->status);
    }

    public function test_el_stream_no_empuja_lo_que_la_sesion_no_puede_ver(): void
    {
        $controlador = app(NotificationController::class);
        $lote = new \ReflectionMethod($controlador, 'streamBatch');
        $tipos = fn (string $rol): array => $lote->invoke($controlador, CrmPermission::defaultsFor($rol), 0)->pluck('type')->sort()->values()->all();

        $this->assertSame(['promotion', 'security', 'system'], $tipos(Admin::ROLE_ADMINISTRATIVO));
        $this->assertSame(['class', 'membership', 'payment', 'promotion', 'security', 'system'], $tipos(Admin::ROLE_RECEPCION));
    }

    public function test_el_cursor_que_anuncia_el_stream_no_cuenta_lo_que_la_sesion_no_ve(): void
    {
        // Lo último creado es un pago: el Administrativo no lo ve, así que su cursor es su última fila visible.
        $controlador = app(NotificationController::class);
        $cursor = new \ReflectionMethod($controlador, 'initialCursor');
        $pedir = fn (array $query = []): \Illuminate\Http\Request => \Illuminate\Http\Request::create('/api/admin/notifications/stream', 'GET', $query);
        $ultimoPago = app(NotificationService::class)->createAdminNotification(['type' => 'payment', 'title' => 'Pago', 'message' => 'x', 'event_key' => 'prueba-ultimo-pago']);

        $administrativo = CrmPermission::defaultsFor(Admin::ROLE_ADMINISTRATIVO);
        $visibleMax = Notification::whereIn('type', ['system', 'security', 'promotion'])->max('id');
        $this->assertSame($visibleMax, $cursor->invoke($controlador, $pedir(), $administrativo));
        $this->assertLessThan($ultimoPago->id, $cursor->invoke($controlador, $pedir(), $administrativo));
        $this->assertSame($ultimoPago->id, $cursor->invoke($controlador, $pedir(), CrmPermission::defaultsFor(Admin::ROLE_RECEPCION)));
        // El `after_id` del cliente manda (y el filtro de visibilidad se aplica después, en cada lote).
        $this->assertSame(3, $cursor->invoke($controlador, $pedir(['after_id' => '3']), $administrativo));
    }

    public function test_el_token_compartido_de_automatizaciones_no_entra_en_la_campanita_ni_escribe(): void
    {
        $antes = Notification::count();
        $t = $this->sharedTokenHeaders();
        $this->getJson('/api/admin/notifications', $t)->assertForbidden();
        $this->getJson('/api/admin/notifications/unread-count', $t)->assertForbidden();
        $this->getJson('/api/admin/notifications/stream', $t)->assertForbidden();
        $this->postJson('/api/admin/notifications/read-all', [], $t)->assertForbidden();
        $this->postJson("/api/admin/notifications/{$this->avisos['system']->uuid}/read", [], $t)->assertForbidden();
        $this->postJson('/api/admin/notifications', ['title' => 'x', 'message' => 'y', 'audience' => 'admin', 'type' => 'system'], $t)->assertForbidden();
        $this->assertSame($antes, Notification::count());
        $this->assertSame('unread', $this->avisos['system']->fresh()->status);
    }

    public function test_un_permiso_quitado_en_roles_deja_de_mostrar_sus_avisos(): void
    {
        // Lo decide el permiso efectivo, no el nombre del rol: una cuenta solo con `payments.view` ve los pagos.
        $soloPagos = $this->h($this->staffWith(['payments.view']));
        $this->assertSame(['payment', 'promotion', 'security', 'system'], $this->tiposQueVe($soloPagos));
    }

    // ── P-2 ───────────────────────────────────────────────────────────────

    public function test_crear_a_mano_exige_content_manage_y_sin_el_no_escribe_nada(): void
    {
        $antes = Notification::count();
        $cuerpo = ['title' => 'Aviso del turno', 'message' => 'Falso', 'audience' => 'admin', 'type' => 'system', 'priority' => 'high'];
        foreach ([$this->reception(), $this->h($this->staff(CrmPermission::ROLE_ENTRENADOR, $this->trainer())), $this->h($this->staff(Admin::ROLE_ADMINISTRATIVO))] as $h) {
            $this->postJson('/api/admin/notifications', $cuerpo, $h)->assertForbidden();
            // Ni difundir a la app de todos los socios.
            $this->postJson('/api/admin/notifications', ['title' => 'Promo', 'message' => 'A todos'], $h)->assertForbidden();
        }
        $this->assertSame($antes, Notification::count(), 'un 403 no escribe nada');

        $this->postJson('/api/admin/notifications', $cuerpo, $this->h($this->staff(Admin::ROLE_ADMINISTRADOR)))->assertCreated();
        $this->postJson('/api/admin/notifications', $cuerpo, $this->h($this->superAdmin()))->assertCreated();
        // Y a quien se le concede expresamente desde Roles, también.
        $this->postJson('/api/admin/notifications', $cuerpo, $this->h($this->staffWith(['content.manage'])))->assertCreated();
    }

    public function test_la_manual_lleva_su_origen_y_quien_la_escribio_solo_en_el_crm(): void
    {
        $root = $this->superAdmin();
        $uuid = $this->postJson('/api/admin/notifications', ['title' => 'Nota interna', 'message' => 'x', 'audience' => 'admin'], $this->h($root))
            ->assertCreated()->json('data.uuid');
        $this->assertSame(['source' => 'manual', 'created_by_admin_id' => $root->id], Notification::where('uuid', $uuid)->value('metadata'));

        // A la app del socio no viaja el id de quien la escribió.
        $uuidSocio = $this->postJson('/api/admin/notifications', ['title' => 'Promo', 'message' => 'x'], $this->h($root))->assertCreated()->json('data.uuid');
        $this->assertArrayNotHasKey('created_by_admin_id', (array) Notification::where('uuid', $uuidSocio)->value('metadata'));
    }

    public function test_a_mano_solo_avisos_del_sistema_o_promociones_aunque_se_tenga_el_permiso(): void
    {
        $root = $this->h($this->superAdmin());
        $antes = Notification::count();
        // Un pago, una alerta de seguridad o una valoración hechos a mano sonarían como reales.
        foreach (['security', ' Security ', 'payment', 'Payment', 'membership', 'valuation', 'valuations_report'] as $tipo) {
            $this->postJson('/api/admin/notifications', ['title' => 'Falso', 'message' => 'x', 'audience' => 'admin', 'type' => $tipo, 'priority' => 'high'], $root)
                ->assertStatus(422)->assertJsonValidationErrors('type');
        }
        $this->assertSame($antes, Notification::count());
        $this->postJson('/api/admin/notifications', ['title' => 'Aviso', 'message' => 'x', 'audience' => 'admin', 'type' => ' SYSTEM '], $root)
            ->assertCreated()->assertJsonPath('data.type', 'system');
        $this->postJson('/api/admin/notifications', ['title' => 'Promo', 'message' => 'x'], $root)
            ->assertCreated()->assertJsonPath('data.type', 'promotion');
    }

    public function test_los_avisos_del_formulario_publico_del_login_van_marcados_como_publicos(): void
    {
        // Sin sesión: cualquiera puede enviarlo. El CRM no les da urgencia sonora por ese dato.
        $this->postJson('/api/security/support-report', [
            'report_type' => \App\Models\SupportSecurityReport::TYPE_OTHER, 'name' => 'Persona Cualquiera', 'phone' => '3000000000', 'description' => 'Perdí el teléfono',
        ])->assertOk();
        $publicos = Notification::where('audience', 'admin')->where('created_at', '>=', now()->subMinute())->whereIn('type', ['security', 'system'])->get();
        $this->assertNotEmpty($publicos);
        foreach ($publicos->whereNotIn('event_key', ['prueba-system', 'prueba-security']) as $n) {
            $this->assertSame('public', $n->metadata['origin'] ?? null, "{$n->type}: {$n->title}");
        }
        // Los creados por el propio sistema con sesión no llevan esa marca.
        $this->assertArrayNotHasKey('origin', (array) $this->avisos['security']->fresh()->metadata);
    }
}
