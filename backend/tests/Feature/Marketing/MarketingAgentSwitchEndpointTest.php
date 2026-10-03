<?php

namespace Tests\Feature\Marketing;

use App\Models\Admin;
use App\Models\AdminRole;
use App\Models\AuditLog;
use App\Models\MarketingAgentSetting;
use App\Services\Marketing\MarketingAgentSwitch;
use App\Support\Access\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Tests\TestCase;

/**
 * El interruptor del agente IA visto desde el CRM: quién lo lee, quién lo
 * cambia, qué deja escrito y qué pasa con el doble clic.
 *
 * La regla de permisos que se fija aquí es la que justifica la llave nueva:
 * `marketing.manage` lo tiene cualquiera que conteste en el Inbox, y pausar el
 * agente es una decisión sobre todo el negocio.
 */
class MarketingAgentSwitchEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/admin/marketing/agent';

    protected function setUp(): void
    {
        parent::setUp();
        foreach (Admin::ROLES as $rol) {
            AdminRole::firstOrCreate(['name' => $rol], ['is_system' => true]);
        }
    }

    private function fila(): MarketingAgentSetting
    {
        return MarketingAgentSetting::query()->findOrFail(1);
    }

    /** @return list<AuditLog> */
    private function trazas(): array
    {
        return AuditLog::query()->where('entity', 'agente_ia')->orderBy('id')->get()->all();
    }

    // ── Leer ─────────────────────────────────────────────────────────────────

    public function test_por_defecto_esta_activo_y_lo_lee_quien_ve_mercadeo(): void
    {
        $res = $this->getJson(self::URL, $this->adminWithPermissions(['marketing.view']))->assertOk();

        $res->assertJsonPath('data.paused', false)
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.version', 0)
            ->assertJsonPath('data.changed_by_name', null)
            ->assertJsonPath('data.can_manage', false);
        $this->assertIsBool($res->json('data.ultron.enabled'));
        $this->assertContains($res->json('data.ultron.scope'), ['all', 'canary']);
        $this->assertIsBool($res->json('data.ultron.brake_engaged'));
    }

    public function test_quien_tiene_la_llave_ve_que_puede_cambiarlo(): void
    {
        $this->getJson(self::URL, $this->actingAsAdmin())->assertOk()->assertJsonPath('data.can_manage', true);
        $this->getJson(self::URL, $this->adminWithPermissions(['marketing.view', 'marketing.agent.manage']))
            ->assertOk()->assertJsonPath('data.can_manage', true);
    }

    public function test_sin_ver_mercadeo_no_se_lee(): void
    {
        $this->getJson(self::URL, $this->actingAsAdmin(Admin::create([
            'name' => 'Rec', 'email' => 'rec-'.uniqid().'@ironbody.test', 'password' => 'secret-123',
            'role' => Admin::ROLE_RECEPCION, 'status' => 'active',
        ])))->assertStatus(403);
        $this->getJson(self::URL)->assertStatus(401);
    }

    // ── Cambiar: permisos ────────────────────────────────────────────────────

    public function test_marketing_manage_no_basta_para_pausar(): void
    {
        $asesor = $this->adminWithPermissions(['marketing.view', 'marketing.manage']);

        $this->putJson(self::URL, ['paused' => true], $asesor)->assertStatus(403);
        $this->putJson(self::URL, ['paused' => true], $this->sharedTokenHeaders())->assertStatus(403);
        $this->putJson(self::URL, ['paused' => true])->assertStatus(401);

        $this->assertFalse($this->fila()->paused, 'el agente se pausó sin la llave');
        $this->assertSame([], $this->trazas());
    }

    public function test_con_la_llave_propia_se_pausa_sin_ser_super_admin(): void
    {
        $this->putJson(self::URL, ['paused' => true], $this->adminWithPermissions(['marketing.view', 'marketing.agent.manage']))
            ->assertOk()
            ->assertJsonPath('changed', true)
            ->assertJsonPath('data.paused', true);
    }

    // ── Cambiar: estado, versión y traza ─────────────────────────────────────

    public function test_pausar_y_reactivar_deja_traza_con_antes_y_despues_y_sube_la_version(): void
    {
        $jefa = Admin::create([
            'name' => 'Jefa', 'email' => 'jefa-'.uniqid().'@ironbody.test', 'password' => 'secret-123',
            'role' => Admin::ROLE_SUPER_ADMIN, 'status' => 'active',
        ]);
        $cabeceras = $this->actingAsAdmin($jefa);

        $this->putJson(self::URL, ['paused' => true], $cabeceras)
            ->assertOk()
            ->assertJsonPath('changed', true)
            ->assertJsonPath('data.status', 'paused')
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.changed_by_name', 'Jefa');

        $fila = $this->fila();
        $this->assertTrue($fila->paused);
        $this->assertNotNull($fila->paused_at);
        $this->assertSame($jefa->id, (int) $fila->changed_by_admin_id);
        $pausadoEn = $fila->paused_at;

        $this->putJson(self::URL, ['paused' => false], $cabeceras)
            ->assertOk()
            ->assertJsonPath('changed', true)
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.version', 2);

        $fila = $this->fila();
        $this->assertFalse($fila->paused);
        $this->assertNotNull($fila->resumed_at);
        // La última pausa se conserva al reactivar: es lo que juzga los turnos en vuelo.
        $this->assertTrue($pausadoEn->equalTo($fila->paused_at));

        $trazas = $this->trazas();
        $this->assertCount(2, $trazas);
        [$pausa, $reactivacion] = $trazas;
        $this->assertSame('settings', $pausa->action);
        $this->assertSame('Mercadeo', $pausa->module);
        $this->assertSame('Pausó el agente IA', $pausa->summary);
        $this->assertSame([['field' => 'estado', 'from' => 'activo', 'to' => 'pausado']], $pausa->changes);
        $this->assertSame((string) $jefa->id, $pausa->actor_id);
        $this->assertSame('Jefa', $pausa->actor_name);
        $this->assertNotNull($pausa->created_at);
        $this->assertSame('Reactivó el agente IA', $reactivacion->summary);
        $this->assertSame([['field' => 'estado', 'from' => 'pausado', 'to' => 'activo']], $reactivacion->changes);
    }

    /**
     * Doble clic, reintento de red o dos personas pidiendo lo mismo: una sola
     * transición, una sola traza, una sola versión.
     */
    public function test_pedir_el_estado_que_ya_tiene_no_cambia_nada(): void
    {
        $cabeceras = $this->actingAsAdmin();

        $this->putJson(self::URL, ['paused' => true], $cabeceras)->assertOk()->assertJsonPath('changed', true);
        $this->putJson(self::URL, ['paused' => true], $cabeceras)->assertOk()->assertJsonPath('changed', false)->assertJsonPath('data.paused', true);
        $this->putJson(self::URL, ['paused' => true], $this->actingAsAdmin())->assertOk()->assertJsonPath('changed', false);

        $this->assertSame(1, $this->fila()->version);
        $this->assertCount(1, $this->trazas());

        // Reactivar estando activo, igual.
        $this->putJson(self::URL, ['paused' => false], $cabeceras)->assertOk()->assertJsonPath('changed', true);
        $this->putJson(self::URL, ['paused' => false], $cabeceras)->assertOk()->assertJsonPath('changed', false);
        $this->assertSame(2, $this->fila()->version);
        $this->assertCount(2, $this->trazas());
    }

    public function test_si_la_traza_no_se_escribe_el_estado_no_cambia(): void
    {
        $cabeceras = $this->actingAsAdmin();
        AuditLog::creating(function (): never {
            throw new RuntimeException('fallo simulado de la auditoría');
        });

        $this->putJson(self::URL, ['paused' => true], $cabeceras)->assertStatus(500);

        $fila = $this->fila();
        $this->assertFalse($fila->paused, 'el agente quedó pausado sin traza');
        $this->assertSame(0, $fila->version);
        $this->assertNull($fila->paused_at);
    }

    public function test_el_cuerpo_se_valida(): void
    {
        $cabeceras = $this->actingAsAdmin();

        $this->putJson(self::URL, [], $cabeceras)->assertStatus(422);
        $this->putJson(self::URL, ['paused' => 'quizá'], $cabeceras)->assertStatus(422);
        $this->assertSame(0, $this->fila()->version);
    }

    /** La fuente de verdad es la base: limpiar cachés no reactiva nada. */
    public function test_la_pausa_sobrevive_a_limpiar_cache(): void
    {
        $cabeceras = $this->actingAsAdmin();
        $this->putJson(self::URL, ['paused' => true], $cabeceras)->assertOk();

        Artisan::call('cache:clear');
        $this->app->forgetInstance(MarketingAgentSwitch::class);

        $this->getJson(self::URL, $cabeceras)->assertOk()->assertJsonPath('data.paused', true);
    }

    // ── Límite de peticiones ─────────────────────────────────────────────────

    /**
     * El interruptor de emergencia no comparte cubo con la IP: desde el Wi-Fi
     * del gimnasio salen el CRM entero y la app de los socios, y con el
     * limitador anónimo pausar devolvía 429 a la primera.
     */
    public function test_el_trafico_ajeno_de_la_misma_red_no_bloquea_la_pausa(): void
    {
        $cabeceras = $this->actingAsAdmin();

        for ($i = 0; $i < 7; $i++) {
            $this->postJson('/api/admin/auth/login', ['email' => 'nadie@ironbody.test', 'password' => 'mal-'.$i]);
        }
        for ($i = 0; $i < 9; $i++) {
            $this->getJson('/api/admin/marketing/appointments', $cabeceras);
        }

        $this->putJson(self::URL, ['paused' => true], $cabeceras)->assertOk()->assertJsonPath('changed', true);
    }

    /** Y al revés: leer el estado y escuchar el canal no gastan el cubo del login. */
    public function test_leer_y_escuchar_no_gastan_el_cubo_del_login(): void
    {
        $admin = Admin::create([
            'name' => 'Entra', 'email' => 'entra-'.uniqid().'@ironbody.test', 'password' => 'Clave-Entra-2026',
            'role' => Admin::ROLE_SUPER_ADMIN, 'status' => 'active',
        ]);
        $cabeceras = $this->actingAsAdmin($admin);

        for ($i = 0; $i < 20; $i++) {
            $this->getJson(self::URL, $cabeceras)->assertOk();
            $this->get(self::URL.'/stream', $cabeceras)->assertOk();
        }

        $this->postJson('/api/admin/auth/login', ['email' => $admin->email, 'password' => 'Clave-Entra-2026'])->assertOk();
    }

    public function test_el_limite_de_cambios_es_por_sesion(): void
    {
        $a = $this->actingAsAdmin();
        $b = $this->actingAsAdmin();

        for ($i = 0; $i < 10; $i++) {
            $this->putJson(self::URL, ['paused' => $i % 2 === 0], $a)->assertOk();
        }
        $this->putJson(self::URL, ['paused' => true], $a)
            ->assertStatus(429)
            ->assertJsonPath('code', 'too_many_agent_changes');

        // Otra persona no hereda el límite de la primera.
        $this->putJson(self::URL, ['paused' => true], $b)->assertOk();
    }

    // ── Tiempo real ──────────────────────────────────────────────────────────

    /**
     * El canal en sí se prueba por su respuesta, como los demás SSE del repo:
     * `SseStream` cierra los buffers de salida y el cuerpo no se puede leer
     * dentro de PHPUnit. Lo que viaja se fija en `streamEvent()`.
     */
    public function test_el_canal_responde_como_sse_y_su_evento_solo_lleva_el_estado(): void
    {
        $cabeceras = $this->actingAsAdmin();
        $switch = app(MarketingAgentSwitch::class);
        $this->assertSame(['version' => 0, 'paused' => false], $switch->streamEvent());

        $this->putJson(self::URL, ['paused' => true], $cabeceras)->assertOk();

        $res = $this->get(self::URL.'/stream', $cabeceras)->assertOk();
        $this->assertStringStartsWith('text/event-stream', (string) $res->headers->get('Content-Type'));
        // Solo el estado: ni nombres ni fechas de quien lo cambió.
        $this->assertSame(['version' => 1, 'paused' => true], $switch->streamEvent());
    }

    public function test_el_canal_pide_el_mismo_permiso_que_la_pantalla(): void
    {
        $this->get(self::URL.'/stream', $this->adminWithPermissions(['marketing.view']))->assertOk();
        $this->getJson(self::URL.'/stream', $this->actingAsAdmin(Admin::create([
            'name' => 'Rec2', 'email' => 'rec2-'.uniqid().'@ironbody.test', 'password' => 'secret-123',
            'role' => Admin::ROLE_RECEPCION, 'status' => 'active',
        ])))->assertStatus(403);
    }

    // ── Catálogo ─────────────────────────────────────────────────────────────

    public function test_la_llave_aparece_en_mercadeo_con_su_explicacion(): void
    {
        $fila = collect(PermissionCatalog::rows())->firstWhere('key', 'marketing.agent.manage');

        $this->assertNotNull($fila, 'la llave no está en el catálogo');
        $this->assertSame('marketing', $fila['domain']);
        $this->assertSame('Pausar o reactivar el agente IA', $fila['label']);
        $this->assertNotEmpty($fila['help']);
    }
}
