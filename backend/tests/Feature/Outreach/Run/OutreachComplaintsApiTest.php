<?php

namespace Tests\Feature\Outreach\Run;

use App\Http\Controllers\Crm\CommercialAutomation\OutreachComplaintController;
use App\Http\Middleware\LimitRequestBodySize;
use App\Models\Admin;
use App\Models\AdminRole;
use App\Models\RolePermission;
use App\Observers\Commercial\ComplaintCommercialEmitter;
use App\Services\Commercial\CommercialEventRecorder;
use App\Services\Outreach\Core\OutreachCatalog;
use App\Services\Outreach\Core\PolicyLadder;
use App\Services\Outreach\Facts\CoreProjection;
use App\Services\Outreach\Run\LadderInputBuilder;
use App\Services\Outreach\Run\OutreachAccess;
use App\Services\Outreach\Run\OutreachPlanner;
use App\Services\Outreach\Run\OutreachReplay;
use App\Support\Access\AuthorizationMap;
use App\Support\Access\CrmPermission;
use App\Support\Access\PermissionCatalog;
use App\Support\Access\RolePermissionPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Mockery;
use Tests\Feature\Outreach\Run\Support\RunWorld;
use Tests\TestCase;

/**
 * Q1-8 (a) · REGISTRAR UNA QUEJA presencial o por teléfono
 * (`POST /api/admin/commercial-automation/complaints`; Enmienda Q1-8 del
 * contrato H9), por HTTP y sobre las clases REALES: la puerta de
 * `AuthorizationMap`, una persona, la llave, la de VER a la persona (sin
 * oráculo), la validación sin texto libre, la idempotencia por
 * `clientRequestId`, una al día por persona, el registro de hechos apagado
 * (409) y un registro que no queda (503). De extremo a extremo: tras
 * registrarla, la escalera niega comercial y cobranza (N6 `complaint_recent`)
 * y el servicio no cambia.
 *
 * El martes 17-11-2026 a las 10:00 de Bogotá (15:00Z).
 */
class OutreachComplaintsApiTest extends TestCase
{
    use RefreshDatabase;
    use RunWorld;

    private const NOW = '2026-11-17 15:00:00';

    private const URL = '/api/admin/commercial-automation/complaints';

    /** @var array<string, array{0: Admin, 1: array<string, string>}> */
    private array $sesiones = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Carbon::setTestNow(Carbon::parse(self::NOW, 'UTC'));
        config(['commercial.events_enabled' => true, 'commercial.enabled' => false]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Una cuenta del personal con EXACTAMENTE esas llaves (un rol propio). @param  list<string>  $llaves @return array<string, string> */
    private function sesion(string $nombre, array $llaves): array
    {
        $clave = $nombre.'|'.implode(',', $llaves);
        if (isset($this->sesiones[$clave])) {
            return $this->sesiones[$clave][1];
        }
        $rol = 'q18-'.substr(md5(implode(',', $llaves)), 0, 8);
        AdminRole::firstOrCreate(['name' => $rol], ['is_system' => false]);
        foreach ($llaves as $llave) {
            RolePermission::query()->updateOrCreate(['role' => $rol, 'permission' => $llave], ['granted' => true]);
        }
        Cache::forget('crm.role_permissions.v1');
        app(RolePermissionPolicy::class)->flush();
        $admin = Admin::create(['name' => $nombre, 'email' => strtolower($nombre).'-'.uniqid().'@q18.test', 'password' => 'secret-password', 'role' => $rol, 'status' => 'active']);

        $this->sesiones[$clave] = [$admin, $this->actingAsAdmin($admin)];

        return $this->sesiones[$clave][1];
    }

    /** Mueve el reloj y abre sesiones nuevas para las MISMAS cuentas (las de antes caducan con el reloj). */
    private function reloj(string $utc): void
    {
        Carbon::setTestNow(Carbon::parse($utc, 'UTC'));
        foreach ($this->sesiones as $clave => [$admin]) {
            $this->sesiones[$clave] = [$admin, $this->actingAsAdmin($admin)];
        }
    }

    /** @return array<string, string> */
    private function recepcion(): array
    {
        return $this->sesion('Recepcion', [OutreachAccess::COMPLAINTS_RECORD, 'members.view', 'marketing.view']);
    }

    /** @return array<string, mixed> */
    private static function queja(string $tipo, int $id, array $cambios = []): array
    {
        return array_replace(['clientRequestId' => (string) Str::uuid(), 'personType' => $tipo, 'personId' => $id, 'channel' => 'in_person', 'category' => 'billing'], $cambios);
    }

    /** @return list<object> */
    private static function quejas(): array
    {
        return DB::table('commercial_events')->where('event', 'complaint_created')->orderBy('id')->get()->all();
    }

    // ── La ruta, la llave y los roles ───────────────────────────────────────

    public function test_la_ruta_con_su_llave_su_tope_y_su_cupo_y_la_llave_solo_para_super_admin(): void
    {
        $this->assertCount(OutreachComplaintController::ROUTE_COUNT, OutreachComplaintController::ROUTES);
        $ruta = null;
        foreach (Route::getRoutes() as $r) {
            if ($r->uri() === 'api/admin/commercial-automation/complaints') {
                $ruta = $r;
            }
        }
        $this->assertNotNull($ruta);
        $this->assertSame(['POST'], $ruta->methods());
        $this->assertSame(OutreachComplaintController::authorizationMap(), ['POST api/admin/commercial-automation/complaints' => AuthorizationMap::resolve($ruta)]);
        $this->assertSame('commercial_automation.complaints_record', AuthorizationMap::resolve($ruta));
        $this->assertContains(LimitRequestBodySize::class.':16384', $ruta->gatherMiddleware());
        $this->assertContains('throttle:ultron-studio-write', $ruta->gatherMiddleware());

        $llave = OutreachAccess::COMPLAINTS_RECORD;
        $this->assertContains($llave, OutreachAccess::PERMISSIONS);
        $this->assertContains($llave, PermissionCatalog::all());
        $this->assertContains($llave, CrmPermission::defaultsFor(Admin::ROLE_SUPER_ADMIN));
        foreach ([Admin::ROLE_ADMINISTRADOR, Admin::ROLE_RECEPCION, Admin::ROLE_ADMINISTRATIVO, CrmPermission::ROLE_ENTRENADOR] as $rol) {
            $this->assertNotContains($llave, CrmPermission::defaultsFor($rol), "{$llave} en {$rol}");
        }
        $this->assertStringContainsString('No llega por defecto al Administrador', (string) array_column(PermissionCatalog::rows(), null, 'key')[$llave]['help']);
        // El token compartido no entra (el dominio está excluido).
        $s = $this->socio();
        $this->postJson(self::URL, self::queja('member', $s['member']), $this->sharedTokenHeaders())->assertStatus(403);
        $this->assertSame([], self::quejas());
    }

    public function test_sin_la_llave_o_sin_ver_a_la_persona_403_sin_oraculo_y_sin_escribir(): void
    {
        $s = $this->socio();
        $lead = $this->lead(['member_id' => $s['member']]);
        $suelto = $this->lead();
        // Sin la llave: la puerta, exista o no la persona.
        foreach ([$s['member'], 999999] as $id) {
            $this->postJson(self::URL, self::queja('member', $id), $this->sesion('SinLlave', ['members.view', 'marketing.view']))
                ->assertStatus(403)->assertJsonPath('code', 'forbidden');
        }
        // Con la llave pero sin ver socios: 403 ANTES de leer la persona, igual exista o no.
        foreach ([$s['member'], 999999] as $id) {
            $this->postJson(self::URL, self::queja('member', $id), $this->sesion('Ciega', [OutreachAccess::COMPLAINTS_RECORD]))
                ->assertStatus(403)->assertJsonPath('code', 'forbidden')->assertJsonPath('required_permission', 'members.view');
        }
        // Un prospecto de un socio es el socio: ver prospectos no basta. Uno suelto, sí.
        $mercadeo = $this->sesion('Mercadeo', [OutreachAccess::COMPLAINTS_RECORD, 'marketing.view']);
        $this->postJson(self::URL, self::queja('lead', $lead), $mercadeo)->assertStatus(403)->assertJsonPath('required_permission', 'members.view');
        $this->assertSame([], self::quejas(), 'ninguna petición rechazada escribe');
        $this->postJson(self::URL, self::queja('lead', $suelto), $mercadeo)->assertCreated();
        // Con todo: la que no existe, 404.
        $this->postJson(self::URL, self::queja('member', 999999), $this->recepcion())->assertStatus(404)->assertJsonPath('code', 'person_not_found');
        $this->assertCount(1, self::quejas());
    }

    public function test_sin_texto_libre_y_con_listas_cerradas(): void
    {
        $s = $this->socio();
        foreach ([
            'sin medio' => [['channel' => null], 'channel'], 'medio fuera de la lista' => [['channel' => 'whatsapp'], 'channel'],
            'categoría fuera de la lista' => [['category' => 'Me cobraron doble'], 'category'], 'sin categoría' => [['category' => null], 'category'],
            'sin identificador' => [['clientRequestId' => null], 'clientRequestId'], 'identificador que no es uuid' => [['clientRequestId' => 'x'], 'clientRequestId'],
            'tipo de persona' => [['personType' => 'user'], 'personType'], 'id 0' => [['personId' => 0], 'personId'],
        ] as $nombre => [$cambio, $campo]) {
            $r = $this->postJson(self::URL, array_filter(self::queja('member', $s['member'], $cambio), static fn ($v) => $v !== null), $this->recepcion())
                ->assertStatus(422)->assertJsonPath('code', 'validation_failed');
            $this->assertSame(['invalid'], $r->json("errors.{$campo}"), $nombre);
            $this->assertStringNotContainsString('cobraron', (string) $r->getContent(), 'el 422 no repite el valor');
        }
        $this->assertSame([], self::quejas());

        // Un campo de más (un texto) se ignora: no se guarda.
        $this->postJson(self::URL, self::queja('member', $s['member'], ['note' => 'Se quejó a gritos de Ana', 'text' => 'x']), $this->recepcion())->assertCreated();
        $q = self::quejas()[0];
        $this->assertSame(['category', 'channel', 'client_request_id', 'recorded_by', 'source'], array_keys(self::sorted(json_decode((string) $q->payload, true))));
        $this->assertStringNotContainsString('gritos', (string) $q->payload);
    }

    // ── Lo que escribe ─────────────────────────────────────────────────────

    public function test_registra_el_mismo_hecho_que_la_ia_con_source_staff_sin_texto_ni_telefono(): void
    {
        $s = $this->socio();
        $lead = $this->lead(['member_id' => $s['member'], 'phone' => '3009990001']);
        $sesion = $this->recepcion();
        $crid = (string) Str::uuid();
        $r = $this->postJson(self::URL, self::queja('lead', $lead, ['clientRequestId' => strtoupper($crid), 'channel' => 'phone', 'category' => 'attention']), $sesion)->assertCreated();
        $this->assertSame([
            'personType' => 'lead', 'personId' => $lead, 'recordsAs' => ['type' => 'member', 'id' => $s['member']], 'channel' => 'phone', 'category' => 'attention',
            'source' => 'staff', 'day' => '2026-11-17', 'brake' => ['days' => 30, 'purposes' => ['collections', 'commercial']],
        ], $r->json('data'));
        $this->assertFalse($r->json('meta.replayed'));

        [$q] = self::quejas();
        $admin = Admin::query()->where('name', 'Recepcion')->value('id');
        $this->assertSame([$s['member'], 'complaint_created:member:'.$s['member'].':complaint:staff:20261117', '2026-11-17 15:00:00'], [(int) $q->member_id, $q->dedupe_key, (string) $q->occurred_at]);
        $this->assertSame(['category' => 'attention', 'channel' => 'phone', 'client_request_id' => $crid, 'recorded_by' => (int) $admin, 'source' => 'staff'], self::sorted(json_decode((string) $q->payload, true)));
        foreach (['3009990001', 'Socio', '@facts.test'] as $dato) {
            $this->assertStringNotContainsString($dato, json_encode((array) $q), 'ni teléfono ni nombre en la fila');
            $this->assertStringNotContainsString($dato, (string) $r->getContent(), 'ni en la respuesta');
        }
    }

    public function test_dos_el_mismo_dia_una_sola_y_la_respuesta_no_dice_si_ya_habia_otra(): void
    {
        $s = $this->socio();
        $a = $this->postJson(self::URL, self::queja('member', $s['member']), $this->recepcion())->assertCreated();
        $b = $this->postJson(self::URL, self::queja('member', $s['member'], ['channel' => 'phone']), $this->sesion('Otra', [OutreachAccess::COMPLAINTS_RECORD, 'members.view']))->assertCreated();
        $this->assertCount(1, self::quejas(), 'una al día de Bogotá por persona');
        $this->assertSame(array_keys($a->json()), array_keys($b->json()), 'la misma forma: no es un oráculo de «ya se quejó»');
        $this->assertSame([false, false], [$a->json('meta.replayed'), $b->json('meta.replayed')]);

        // 23:59:59 de Bogotá: el mismo día. 00:00 del 18: otro.
        $this->reloj('2026-11-18 04:59:59');
        $this->postJson(self::URL, self::queja('member', $s['member']), $this->recepcion())->assertCreated();
        $this->assertCount(1, self::quejas());
        $this->reloj('2026-11-18 05:00:00');
        $this->postJson(self::URL, self::queja('member', $s['member']), $this->recepcion())->assertCreated()->assertJsonPath('data.day', '2026-11-18');
        $this->assertSame(['member:'.$s['member'].':complaint:staff:20261117', 'member:'.$s['member'].':complaint:staff:20261118'],
            array_map(static fn (object $q): string => substr((string) $q->dedupe_key, strlen('complaint_created:')), self::quejas()));
    }

    public function test_idempotente_por_client_request_id(): void
    {
        $s = $this->socio();
        $otro = $this->socio();
        $peticion = self::queja('member', $s['member']);
        $this->postJson(self::URL, $peticion, $this->recepcion())->assertCreated();
        // Lo mismo: 200 `replayed`, sin fila nueva (también al día siguiente).
        $r = $this->postJson(self::URL, $peticion, $this->recepcion())->assertOk();
        $this->assertSame([true, '2026-11-17'], [$r->json('meta.replayed'), $r->json('data.day')]);
        $this->reloj('2026-11-19 15:00:00');
        $this->postJson(self::URL, $peticion, $this->recepcion())->assertOk()->assertJsonPath('meta.replayed', true)->assertJsonPath('data.day', '2026-11-17');
        $this->assertCount(1, self::quejas());
        // Otro contenido, otra persona u otra cuenta con el mismo identificador: 409.
        foreach ([['channel' => 'phone'], ['category' => 'other'], ['personId' => $otro['member']]] as $cambio) {
            $this->postJson(self::URL, array_replace($peticion, $cambio), $this->recepcion())->assertStatus(409)->assertJsonPath('code', 'client_request_reused');
        }
        $this->postJson(self::URL, $peticion, $this->sesion('Otra', [OutreachAccess::COMPLAINTS_RECORD, 'members.view']))->assertStatus(409)->assertJsonPath('code', 'client_request_reused');
        $this->assertCount(1, self::quejas());
    }

    public function test_con_el_registro_de_hechos_apagado_409_y_no_escribe(): void
    {
        $s = $this->socio();
        config(['commercial.events_enabled' => false]);
        $this->postJson(self::URL, [], $this->recepcion())->assertStatus(409)->assertJsonPath('code', 'commercial_events_disabled');
        $this->postJson(self::URL, self::queja('member', $s['member']), $this->recepcion())->assertStatus(409)->assertJsonPath('code', 'commercial_events_disabled');
        $this->assertSame(0, DB::table('commercial_events')->count());
    }

    public function test_si_el_registro_no_queda_es_503_y_no_dice_que_lo_hizo(): void
    {
        $s = $this->socio();
        // La frontera: el registrador contiene el fallo y devuelve null (lo que hace ante un INSERT que falla).
        $this->app->instance(CommercialEventRecorder::class, Mockery::mock(CommercialEventRecorder::class, ['record' => null]));
        $this->postJson(self::URL, self::queja('member', $s['member']), $this->recepcion())->assertStatus(503)->assertJsonPath('code', 'complaint_not_recorded');
        $this->assertSame([], self::quejas());
    }

    // ── De extremo a extremo: la escalera ──────────────────────────────────

    public function test_tras_registrarla_la_escalera_niega_comercial_y_cobranza_y_el_servicio_no_cambia(): void
    {
        $this->runFlags('shadow');
        $this->liveStrategy($this->validContent($this->assistedTemplate(), ['exclusions' => null]));
        $socio = $this->qualifyingMember(self::utcAt(self::NOW), 5, '3001234567');
        $limpio = $this->qualifyingMember(self::utcAt(self::NOW), 5, '3001234568');
        $this->postJson(self::URL, self::queja('member', $socio['member'], ['channel' => 'in_person']), $this->recepcion())->assertCreated();

        // Comercial, por el planificador real: la inscripción sale por N6 y no hay trabajo para ella.
        $planner = new OutreachPlanner;
        $planner->sweep(now(), true);
        $planner->planDue(now());
        $estado = DB::table('outreach_enrollments')->pluck('exit_reason', 'subject_key')->all();
        $this->assertSame(['complaint_recent', null], [$estado[$socio['key']], $estado[$limpio['key']]]);
        $this->assertSame(1, DB::table('outreach_jobs')->count(), 'solo la persona sin queja');
        $salida = DB::table('outreach_decisions')->where('kind', 'exit')->first();
        $this->assertSame(['N6', ['complaint_recent']], [$salida->level, json_decode((string) $salida->reasons, true)]);
        foreach (DB::table('outreach_decisions')->orderBy('id')->get() as $d) {
            $r = (new OutreachReplay)->replay((string) $d->uuid);
            $this->assertSame(OutreachReplay::SAME, $r['result'], json_encode($r));
        }

        // Las tres finalidades sobre la foto REAL de la persona (BatchSnapshotBuilder → EngineFacts) y la escalera real:
        // comercial y cobranza niegan en N6; el servicio decide lo mismo que sin la queja.
        $eventos = CoreProjection::snapshot($this->snap([$socio['key']], self::NOW, CoreProjection::selectionFor(['events' => ['complaint_created']]))->subject($socio['key']))->events;
        $this->assertSame(['complaint_created'], array_column($eventos, 'key'));
        $base = json_decode((string) file_get_contents(base_path('tests/fixtures/outreach/policy-ladder-cases.json')), true)['baseInput'];
        foreach (['commercial' => true, 'collections' => true, 'service' => false] as $finalidad => $frena) {
            $queja = LadderInputBuilder::complaintRecently($eventos, self::utcAt(self::NOW), $finalidad);
            $this->assertSame($frena, $queja, $finalidad);
            $con = PolicyLadder::decide(array_replace_recursive($base, ['purpose' => $finalidad, 'context' => ['complaintRecently' => $queja]]));
            $sin = PolicyLadder::decide(array_replace_recursive($base, ['purpose' => $finalidad, 'context' => ['complaintRecently' => false]]));
            if ($frena) {
                $this->assertSame(['deny', 'N6', ['complaint_recent'], 'exit_complaint'], [$con['verdict'], $con['level'], $con['reasons'], $con['effect']], $finalidad);
            } else {
                $this->assertSame($sin, $con, 'el servicio no cambia');
                $this->assertNotContains('complaint_recent', $con['reasons']);
            }
        }

        // Pasada la ventana (día 31 de Bogotá), ya no frena.
        $this->assertFalse(LadderInputBuilder::complaintRecently($eventos, self::utcAt('2026-12-18 05:00:00'), 'commercial'));
        $this->assertTrue(LadderInputBuilder::complaintRecently($eventos, self::utcAt('2026-12-18 04:59:59'), 'commercial'), 'el día 30 está dentro');
        $this->assertSame(OutreachCatalog::MAX_WINDOW_DAYS, ComplaintCommercialEmitter::DISMISS_WINDOW_DAYS, 'el emisor descarta hasta donde lee el H9');
    }

    /** @param  array<string, mixed>  $a @return array<string, mixed> */
    private static function sorted(array $a): array
    {
        ksort($a);

        return $a;
    }
}
