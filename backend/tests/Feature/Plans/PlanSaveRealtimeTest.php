<?php

namespace Tests\Feature\Plans;

use App\Models\Admin;
use App\Models\Member;
use App\Models\MemberRealtimeEvent;
use App\Models\MemberTrainerAssignment;
use App\Models\Plan;
use App\Models\RolePermission;
use App\Models\Trainer;
use App\Models\User;
use App\Services\Audit\AuditTrail;
use App\Services\RealtimeEvents;
use App\Support\Access\CrmPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * Guardar un plan desde el CRM: qué se conserva y a quién se avisa.
 *
 * El CRM guarda con un PATCH del plan y, a continuación, un PUT de sus
 * capacidades de IRON IA. Antes, cada uno avisaba a cada socio del plan uno a
 * uno, dentro de la transacción y SIEMPRE (el CRM manda `features` en cada
 * guardado): un plan de 4.000 socios tardaba ~10 s en guardarse y les hacía
 * pedir su estado dos veces aunque no hubiera cambiado nada.
 *
 * Y el modal reenviaba «Acceso a clases» y «Límite de reservas» con valores
 * fijos, pisando lo guardado. Ya no los manda: lo que no llega se conserva.
 */
class PlanSaveRealtimeTest extends TestCase
{
    use RefreshDatabase;

    private const NOW_UTC = '2026-11-11 15:00:00';

    private Plan $plan;

    private array $headers;

    /** @var list<int> los socios de app del plan, en orden */
    private array $socios;

    private int $otroSocio;

    private int $doc = 810100100;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse(self::NOW_UTC, 'UTC'));

        $this->headers = $this->actingAsAdmin();
        $this->plan = Plan::create([
            'name' => 'Élite Auditoría', 'tier' => 'premium', 'price' => 180000, 'duration_days' => 30,
            'active' => true, 'visible_in_app' => true, 'benefits' => 'Acceso al gimnasio',
            'features' => array_merge(Plan::defaultFeatures(), ['classes' => true]),
            // Los dos campos retirados, con un valor que NO es el que el modal
            // viejo reenviaba (true / null): así se ve si alguien los pisa.
            'access_classes' => false,
            'reservations_limit' => 5,
        ]);

        // Los destinatarios de siempre: todo socio de app cuyo usuario tiene
        // este plan, sin mirar su estado (también el vencido y el inactivo).
        $this->socios = [
            $this->socio($this->plan->name),
            $this->socio($this->plan->name, ['membership_end_date' => '2026-01-31']),
            $this->socio($this->plan->name, ['status' => 'inactive']),
        ];
        // No lo son: el socio de otro plan y el usuario del plan sin cuenta de app.
        $this->otroSocio = $this->socio('Otro plan');
        User::create(['name' => 'Sin app', 'email' => 'sin-app@audit.test', 'password' => bcrypt('x'),
            'document' => '810199999', 'status' => 'active', 'plan' => $this->plan->name]);

        // Lo que hayan emitido los observadores al crear los socios no cuenta.
        MemberRealtimeEvent::query()->delete();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function socio(string $plan, array $usuario = []): int
    {
        $doc = (string) $this->doc++;
        $user = User::create(array_merge([
            'name' => 'Socio '.$doc, 'email' => "socio-{$doc}@audit.test", 'password' => bcrypt('x'),
            'document' => $doc, 'status' => 'active', 'plan' => $plan, 'membership_end_date' => '2026-12-31',
        ], $usuario));

        return (int) Member::create([
            'full_name' => 'Socio '.$doc, 'document_number' => $doc, 'phone' => '+57300'.substr($doc, -7),
            'access_hash' => 'tok-'.$doc, 'status' => Member::STATUS_ACTIVE, 'user_id' => $user->id,
        ])->id;
    }

    /** Exactamente lo que manda hoy el CRM al guardar (sin los dos campos retirados). */
    private function cuerpoDelCrm(array $funciones = [], array $extra = []): array
    {
        return $this->cuerpoPara($this->plan, $funciones, $extra);
    }

    private function cuerpoPara(Plan $plan, array $funciones = [], array $extra = []): array
    {
        $guardado = $plan->fresh();

        return array_merge([
            'name' => $guardado->name, 'tier' => $guardado->tier, 'benefits' => 'Acceso al gimnasio',
            'price' => (int) $guardado->price, 'duration_days' => 30, 'active' => true, 'visible_in_app' => true,
            'benefit_only' => false, 'access_mode' => 'unlimited', 'entry_credits' => null,
            'access_days' => null, 'access_windows' => null,
            'features' => array_merge($guardado->resolvedFeatures(), $funciones),
        ], $extra);
    }

    private function guardarPlan(array $cuerpo)
    {
        return $this->patchJson('/api/plans/'.$this->plan->id, $cuerpo, $this->headers);
    }

    private function guardarIa(array $capacidades)
    {
        return $this->putJson('/api/plans/'.$this->plan->id.'/ai-capabilities', $capacidades, $this->headers);
    }

    /** ¿El módulo «Clases» quedó encendido en la base? */
    private function clasesGuardadas(): bool
    {
        return (bool) ($this->plan->fresh()->resolvedFeatures()['classes'] ?? false);
    }

    /** @return list<int> a quién le llegó `app_state.updated`, una entrada por señal */
    private function señales(): array
    {
        return MemberRealtimeEvent::where('type', RealtimeEvents::APP_STATE)
            ->orderBy('member_id')->orderBy('id')->pluck('member_id')->map(fn ($id) => (int) $id)->all();
    }

    // ── Los dos campos retirados ────────────────────────────────────────────

    public function test_guardar_sin_los_campos_retirados_conserva_su_valor_historico(): void
    {
        $this->guardarPlan($this->cuerpoDelCrm(['nutrition' => true], ['price' => 190000]))
            ->assertOk()
            ->assertJsonPath('price', 190000)
            ->assertJsonPath('access_classes', false)
            ->assertJsonPath('reservations_limit', 5);

        $fila = DB::table('plans')->where('id', $this->plan->id)->first(['access_classes', 'reservations_limit']);
        $this->assertFalse((bool) $fila->access_classes);
        $this->assertSame(5, (int) $fila->reservations_limit);
    }

    public function test_un_crm_anterior_que_aun_los_envia_sigue_pudiendo_guardar(): void
    {
        // Una pestaña abierta con el CRM de antes del despliegue.
        $this->guardarPlan($this->cuerpoDelCrm([], ['access_classes' => true, 'reservations_limit' => null]))
            ->assertOk()
            ->assertJsonPath('access_classes', true)
            ->assertJsonPath('reservations_limit', null);
    }

    // ── A quién se avisa, y cuándo ──────────────────────────────────────────

    public function test_guardar_sin_cambios_no_avisa_a_ningun_socio(): void
    {
        $capacidades = ['ai_enabled' => true, 'ai_chat_enabled' => true, 'ai_monthly_messages_limit' => 20];
        $this->guardarIa($capacidades)->assertOk(); // crea la fila: eso sí es un cambio
        MemberRealtimeEvent::query()->delete();
        Carbon::setTestNow(now()->addMinute()); // fuera de cualquier ventana

        // Guardar el modal sin tocar nada: PATCH y PUT con lo que ya hay.
        $this->guardarPlan($this->cuerpoDelCrm())->assertOk();
        $this->guardarIa($capacidades)->assertOk();

        $this->assertSame([], $this->señales());
    }

    public function test_cambiar_un_modulo_avisa_una_vez_a_cada_socio_del_plan_y_a_nadie_mas(): void
    {
        $this->guardarPlan($this->cuerpoDelCrm(['classes' => false]))->assertOk();

        $this->assertSame($this->socios, $this->señales());
        $this->assertNotContains($this->otroSocio, $this->señales());
        // La misma señal de siempre: la app ya sabe qué hacer con ella.
        $señal = MemberRealtimeEvent::where('member_id', $this->socios[0])->sole();
        $this->assertSame(RealtimeEvents::APP_STATE, $señal->type);
        $this->assertSame(['features', 'membership'], $señal->changed);
        $this->assertFalse($this->clasesGuardadas());
    }

    public function test_el_patch_y_el_put_del_mismo_guardado_avisan_una_sola_vez(): void
    {
        $this->guardarPlan($this->cuerpoDelCrm(['classes' => false]))->assertOk();
        $this->guardarIa(['ai_chat_enabled' => false])->assertOk();

        $this->assertSame($this->socios, $this->señales());
    }

    public function test_un_cambio_solo_de_ia_avisa_y_no_se_repite_dentro_de_la_ventana(): void
    {
        $this->guardarIa(['ai_chat_enabled' => false])->assertOk();
        $this->assertSame($this->socios, $this->señales());

        Carbon::setTestNow(now()->addSeconds(5));
        $this->guardarIa(['ai_chat_enabled' => true])->assertOk();
        $this->assertCount(3, $this->señales(), 'la pista de IA no se repite a quien acaba de recibirla');

        Carbon::setTestNow(now()->addSeconds(30));
        $this->guardarIa(['ai_chat_enabled' => false])->assertOk();
        $this->assertCount(6, $this->señales());
    }

    public function test_un_cambio_de_modulos_se_avisa_siempre_aunque_haya_otra_señal_reciente(): void
    {
        // Las funciones sí viajan en app-state: quien refrescó con el primer
        // cambio tiene que enterarse también del segundo.
        $this->guardarPlan($this->cuerpoDelCrm(['classes' => false]))->assertOk();
        Carbon::setTestNow(now()->addSeconds(2));
        $this->guardarPlan($this->cuerpoDelCrm(['classes' => true]))->assertOk();

        $this->assertCount(6, $this->señales());
    }

    public function test_repetir_el_mismo_guardado_no_vuelve_a_avisar(): void
    {
        $cuerpo = $this->cuerpoDelCrm(['classes' => false]);
        $this->guardarPlan($cuerpo)->assertOk();
        Carbon::setTestNow(now()->addMinute());
        $this->guardarPlan($cuerpo)->assertOk(); // un reintento de red, un doble clic

        $this->assertSame($this->socios, $this->señales());
    }

    public function test_el_endpoint_de_funciones_sigue_la_misma_regla(): void
    {
        $url = '/api/plans/'.$this->plan->id.'/features';

        $this->putJson($url, ['features' => ['classes' => true]], $this->headers)->assertOk();
        $this->assertSame([], $this->señales(), 'ya estaba así');

        $this->putJson($url, ['features' => ['classes' => false]], $this->headers)->assertOk()
            ->assertJsonPath('features.classes', false);
        $this->assertSame($this->socios, $this->señales());
    }

    public function test_las_señales_salen_despues_del_commit_y_no_si_se_revierte(): void
    {
        $this->enTransaccion(function (): void {
            $this->guardarPlan($this->cuerpoDelCrm(['classes' => false]))->assertOk();
            $this->assertSame([], $this->señales(), 'nada antes de confirmar');
        }, confirmar: true);
        $this->assertSame($this->socios, $this->señales());

        MemberRealtimeEvent::query()->delete();
        $this->enTransaccion(function (): void {
            $this->guardarPlan($this->cuerpoDelCrm(['classes' => true]))->assertOk();
        }, confirmar: false);
        $this->assertSame([], $this->señales(), 'un cambio revertido no se anuncia');
        $this->assertFalse((bool) $this->plan->fresh()->resolvedFeatures()['classes']);
    }

    /** Una transacción externa que se confirma o se revierte; si algo falla dentro, se revierte. */
    private function enTransaccion(callable $paso, bool $confirmar): void
    {
        DB::beginTransaction();
        try {
            $paso();
        } catch (\Throwable $e) {
            DB::rollBack();

            throw $e;
        }
        $confirmar ? DB::commit() : DB::rollBack();
    }

    public function test_si_falla_la_auditoria_el_plan_no_cambia_y_nadie_recibe_señal(): void
    {
        $audit = Mockery::mock(AuditTrail::class);
        $audit->shouldReceive('changesOf')->andReturn([]);
        $audit->shouldReceive('record')->andThrow(new RuntimeException('auditoría fallida'));
        $this->app->instance(AuditTrail::class, $audit);

        $this->guardarPlan($this->cuerpoDelCrm(['classes' => false]))->assertStatus(500);

        $this->assertTrue($this->clasesGuardadas());
        $this->assertSame([], $this->señales());
    }

    public function test_si_el_aviso_falla_el_guardado_queda_confirmado_y_el_siguiente_aviso_sale(): void
    {
        // El mecanismo de señales cae (la tabla no está).
        Schema::rename('member_realtime_events', 'member_realtime_events_caida');

        $this->guardarPlan($this->cuerpoDelCrm(['classes' => false]))->assertOk();
        $this->assertFalse($this->clasesGuardadas(), 'el cambio del plan se confirmó igual');

        // Vuelve, y el siguiente cambio se avisa con normalidad.
        Schema::rename('member_realtime_events_caida', 'member_realtime_events');
        $this->guardarPlan($this->cuerpoDelCrm(['classes' => true]))->assertOk();
        $this->assertSame($this->socios, $this->señales());
    }

    public function test_los_destinatarios_no_dependen_de_quien_guarda(): void
    {
        // Una cuenta de rol Entrenador con `plans.manage` concedido desde
        // Configuración › Roles, que solo tiene asignado al PRIMER socio del plan.
        $entrenador = Trainer::create(['full_name' => 'Coach Auditoría', 'email' => 'coach-'.uniqid().'@ironbody.test', 'status' => 'active']);
        MemberTrainerAssignment::create([
            'member_id' => $this->socios[0], 'trainer_id' => $entrenador->id,
            'status' => MemberTrainerAssignment::STATUS_ACTIVE, 'assigned_at' => now(),
        ]);
        RolePermission::create(['role' => CrmPermission::ROLE_ENTRENADOR, 'permission' => 'plans.manage', 'granted' => true]);
        $cabeceras = $this->actingAsAdmin(Admin::create([
            'name' => 'Cuenta Entrenador', 'email' => 'tr-'.uniqid().'@ironbody.test', 'password' => 'secret-password',
            'role' => CrmPermission::ROLE_ENTRENADOR, 'trainer_id' => $entrenador->id, 'status' => 'active',
        ]));

        // El cambio del plan es de todos sus socios: a todos les llega.
        $this->patchJson('/api/plans/'.$this->plan->id, $this->cuerpoDelCrm(['classes' => false]), $cabeceras)->assertOk();
        $this->assertSame($this->socios, $this->señales());

        MemberRealtimeEvent::query()->delete();
        $this->putJson('/api/plans/'.$this->plan->id.'/features', ['features' => ['classes' => true]], $cabeceras)->assertOk();
        $this->assertSame($this->socios, $this->señales());

        MemberRealtimeEvent::query()->delete();
        Carbon::setTestNow(now()->addMinute());
        $this->putJson('/api/plans/'.$this->plan->id.'/ai-capabilities', ['ai_chat_enabled' => false], $cabeceras)->assertOk();
        $this->assertSame($this->socios, $this->señales());
    }

    public function test_las_consultas_no_crecen_con_el_numero_de_socios(): void
    {
        $grande = Plan::create([
            'name' => 'Plan grande', 'tier' => 'lite', 'price' => 90000, 'duration_days' => 30,
            'active' => true, 'visible_in_app' => true, 'benefits' => 'Acceso al gimnasio',
            'features' => Plan::defaultFeatures(),
        ]);
        for ($i = 0; $i < 40; $i++) {
            $this->socio($grande->name);
        }
        MemberRealtimeEvent::query()->delete();

        $consultas = function (Plan $plan, array $cuerpo): array {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->patchJson('/api/plans/'.$plan->id, $cuerpo, $this->headers)->assertOk();
            $log = DB::getQueryLog();
            DB::disableQueryLog();

            return [count($log), count(array_filter($log, fn ($q) => str_starts_with(strtolower($q['query']), 'insert into "member_realtime_events"')))];
        };

        // Calentamiento: la primera petición llena la caché de permisos de rol
        // (una consulta a `role_permissions`) y eso no depende de los socios.
        $this->guardarPlan($this->cuerpoDelCrm())->assertOk();

        [$pequeño, $insercionesPequeño] = $consultas($this->plan, $this->cuerpoDelCrm(['classes' => false]));
        [$conCuarenta, $insercionesGrande] = $consultas($grande, $this->cuerpoPara($grande, ['classes' => true]));

        $this->assertSame(1, $insercionesPequeño);
        $this->assertSame(1, $insercionesGrande, '40 socios, una sola inserción');
        $this->assertSame($pequeño, $conCuarenta, '3 o 40 socios: las mismas consultas');
        $this->assertCount(3 + 40, $this->señales());
    }
}
