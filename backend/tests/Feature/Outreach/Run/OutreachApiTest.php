<?php

namespace Tests\Feature\Outreach\Run;

use App\Http\Controllers\Crm\UltronStudio\UltronStudioOutreachController;
use App\Http\Middleware\LimitRequestBodySize;
use App\Models\Admin;
use App\Models\RolePermission;
use App\Services\Outreach\Run\OutreachAccess;
use App\Services\Outreach\Run\OutreachDispatcher;
use App\Services\Outreach\Run\OutreachPlanner;
use App\Services\Outreach\Run\OutreachRuns;
use App\Support\Access\RolePermissionPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Feature\Outreach\Run\Support\RunWorld;
use Tests\TestCase;

/**
 * La API de la ejecución (contrato H9, §7 E1–E13, D9-13, D9-14; T6): las
 * cerraduras (una persona, la llave), BOLA por uuid y seudónimo, listas
 * paginadas y sin datos personales, la PARADA GLOBAL y soltar a cuatro ojos
 * por HTTP y la bandeja asistida. Las rutas se registran aquí con el mismo
 * bloque que el lead monta en `routes/ultron-studio.php`
 * (`OutreachRoutes::register()`); la primera cerradura (`AuthorizationMap`)
 * queda fuera hasta que el lead añada el bloque: las llaves se conceden por
 * la política persistida, como en las pruebas de S9.
 */
class OutreachApiTest extends TestCase
{
    use RefreshDatabase;
    use RunWorld;

    private const NOW = '2026-11-17 15:00:00';

    private const BASE = '/api/admin/ultron-studio/';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Carbon::setTestNow(Carbon::parse(self::NOW, 'UTC'));
        config(['commercial.events_enabled' => true]);
        Route::middleware('api')->prefix('api')->group(function (): void {
            Route::prefix('admin/ultron-studio')->middleware(LimitRequestBodySize::class.':65536')->group(static fn () => UltronStudioOutreachController::routes());
        });
        foreach (OutreachAccess::PERMISSIONS as $llave) {
            RolePermission::query()->updateOrCreate(['role' => Admin::ROLE_SUPER_ADMIN, 'permission' => $llave], ['granted' => true]);
        }
        Cache::forget('crm.role_permissions.v1');
        app(RolePermissionPolicy::class)->flush();
        $this->threePeople();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @var array<int, array<string, string>> */
    private array $sesiones = [];

    /** @return array<string, string> */
    private function as(Admin $admin): array
    {
        return $this->sesiones[$admin->id] ??= $this->actingAsAdmin($admin);
    }

    public function test_las_rutas_son_13_una_a_una_con_su_llave_y_el_mapa_para_authorization_map(): void
    {
        $this->assertCount(13, UltronStudioOutreachController::ROUTES);
        $mapa = UltronStudioOutreachController::authorizationMap();
        $this->assertCount(13, $mapa);
        $this->assertSame(OutreachAccess::VIEW, $mapa['GET api/admin/ultron-studio/outreach/status']);
        $this->assertSame(OutreachAccess::STOP, $mapa['POST api/admin/ultron-studio/outreach/brakes']);
        $this->assertSame(OutreachAccess::RELEASE, $mapa['POST api/admin/ultron-studio/outreach/brakes/{uuid}/release']);
        $this->assertSame(OutreachAccess::TIMELINE_VIEW, $mapa['GET api/admin/ultron-studio/outreach/people/{subjectRef}/timeline']);
        $this->assertSame(OutreachAccess::ASSISTED_WORK, $mapa['POST api/admin/ultron-studio/outreach/assisted/{uuid}/outcome']);
        // Cada ruta del mapa existe en el enrutador con ese método.
        $registradas = collect(Route::getRoutes()->getRoutes())->filter(static fn ($r) => str_starts_with($r->uri(), 'api/admin/ultron-studio/outreach/'))
            ->map(static fn ($r): string => $r->methods()[0].' '.$r->uri())->sort()->values()->all();
        $esperadas = array_keys($mapa);
        sort($esperadas);
        $this->assertSame($esperadas, $registradas);
    }

    public function test_el_panel_exige_una_persona_con_su_llave_y_no_lleva_datos_personales(): void
    {
        $gente = $this->threePeople();
        // Con la entrada del mapa (L9-59) y el dominio commercial_automation excluido del token compartido (D9-13),
        // la puerta del mapa lo para con 403 `forbidden` antes del `person_required` del controlador (L9-63, m-1).
        $this->getJson(self::BASE.'outreach/status', $this->sharedTokenHeaders())->assertStatus(403)->assertJsonPath('code', 'forbidden');
        $this->getJson(self::BASE.'outreach/status', $this->adminWithPermissions([OutreachAccess::STOP]))->assertStatus(403)->assertJsonPath('code', 'forbidden')->assertJsonPath('required_permission', OutreachAccess::VIEW);

        $r = $this->getJson(self::BASE.'outreach/status', $this->as($gente['ana']))->assertOk();
        $r->assertJsonPath('ok', true)->assertJsonPath('data.config.enabled', false)->assertJsonPath('data.config.mode', 'off')
            ->assertJsonPath('data.config.sendsEnabledInCode', false)->assertJsonPath('data.brakes.own.global', false)->assertJsonPath('data.chain.head.verified', true)
            ->assertJsonPath('data.can.stop', true);
        $this->assertArrayHasKey('ultron_abort_latch', $r->json('data.brakes.foreign'));
        // L9-47 (L9-68): con la maestra apagada, toda ruta salvo `status` responde 409 `outreach_disabled`.
        $this->getJson(self::BASE.'outreach/metrics', $this->as($gente['ana']))->assertStatus(409)->assertJsonPath('code', 'outreach_disabled');
        config(['outreach.enabled' => true, 'outreach.mode' => 'shadow']);
        $this->getJson(self::BASE.'outreach/metrics', $this->as($gente['ana']))->assertOk()->assertJsonPath('data.mode', 'shadow');
    }

    /**
     * Cierre del H9: el estado lleva el recuento de vínculos de persona
     * VERIFICADOS y vivos (para que el panel diga «hacen falta dos personas
     * vinculadas (hay N)» sin la lista, que exige un permiso alto). Solo un
     * entero: ni cuentas, ni identidades, ni nombres. Aditivo: lo demás no cambia.
     */
    public function test_el_estado_cuenta_los_vinculos_verificados_y_vivos_y_solo_eso(): void
    {
        $gente = $this->threePeople();
        $vivos = static fn (): int => DB::table('ultron_studio_person_bindings')->whereNotNull('verified_at')->whereNull('revoked_at')->count();
        $personas = static fn (): int => DB::table('ultron_studio_person_bindings')->whereNotNull('verified_at')->whereNull('revoked_at')->distinct()->count('identity_id');
        $base = $vivos();
        $this->assertGreaterThanOrEqual(3, $base, 'Ana, Beto y Caro');
        $this->assertSame(3, $personas(), 'tres personas, tengan las cuentas que tengan');

        $r = $this->getJson(self::BASE.'outreach/status', $this->as($gente['ana']))->assertOk();
        $this->assertSame(['verified' => $base, 'persons' => 3], $r->json('data.personBindings'));

        // Dani es una SEGUNDA cuenta de Ana (la misma identidad, L9-7): un vínculo más, la misma persona.
        $this->secondAccountOfAna();
        $this->assertSame(['verified' => $base + 1, 'persons' => 3], $this->getJson(self::BASE.'outreach/status', $this->as($gente['ana']))->assertOk()->json('data.personBindings'));
        $base++;
        $this->assertSame(['config', 'brakes', 'chain', 'sources', 'live', 'personBindings', 'can', 'at'], array_keys($r->json('data')), 'aditivo: las claves de siempre, en su sitio');

        // Uno pendiente de verificar no cuenta; uno revocado deja de contar.
        $nueva = $this->unboundAccount();
        $this->insertBinding((int) $nueva->id, $this->insertIdentity(), ['created_by_admin_id' => 1, 'created_by_name' => 'Ana']);
        DB::table('ultron_studio_person_bindings')->where('admin_id', 3)->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'revoked_by_admin_id' => 1, 'revoked_by_name' => 'Ana', 'revoke_reason' => 'Prueba del recuento', 'updated_at' => now()]);
        $this->assertSame($base - 1, $vivos());
        $caroSigue = DB::table('ultron_studio_person_bindings')->where('identity_id', $this->identityOf('caro'))->whereNotNull('verified_at')->whereNull('revoked_at')->exists();
        $this->assertSame(['verified' => $base - 1, 'persons' => $caroSigue ? 3 : 2], $this->getJson(self::BASE.'outreach/status', $this->as($gente['ana']))->assertOk()->json('data.personBindings'));

        // Lo ve quien puede leer el estado, y nadie más.
        $this->getJson(self::BASE.'outreach/status', $this->adminWithPermissions([OutreachAccess::STOP]))->assertStatus(403)->assertJsonPath('required_permission', OutreachAccess::VIEW);
    }

    /**
     * I-4: la posición del barrido (ids internos de personas, cartera, pagos o
     * seguimientos) se guarda en los recuentos de la pasada, pero el estado no
     * la enseña: el resto de los recuentos, sí.
     */
    public function test_el_estado_no_ensena_la_posicion_del_barrido(): void
    {
        $gente = $this->threePeople();
        $this->runFlags('shadow', ['outreach.sweep.batch' => 1]);
        $this->liveStrategy($this->validContent($this->assistedTemplate()));
        $this->expiringMember(self::utcAt(self::NOW), 3);
        $this->expiringMember(self::utcAt(self::NOW), 4);
        (new OutreachPlanner)->sweep(now(), true);
        $guardados = json_decode((string) DB::table('outreach_runs')->where('kind', 'sweep')->value('counts'), true);
        $this->assertArrayHasKey(OutreachRuns::SWEEP_CURSOR_NEXT, $guardados, 'en la base, sí');

        $r = $this->getJson(self::BASE.'outreach/status', $this->as($gente['ana']))->assertOk();
        $recuentos = $r->json('data.sources.latestRuns.sweep.counts');
        $this->assertSame(OutreachRuns::publicCounts($guardados), $recuentos);
        $this->assertSame([1, 1, true], [$recuentos['candidates'], $recuentos['enrolled'], $recuentos['more']]);
        $this->assertStringNotContainsString('cursorFrom', $r->getContent());
        $this->assertStringNotContainsString('cursorNext', $r->getContent());
    }

    public function test_la_parada_global_y_soltar_a_cuatro_ojos_por_http(): void
    {
        $gente = $this->threePeople();
        // L9-47 (L9-68): con la maestra apagada, el POST responde 409 `outreach_disabled` y no escribe nada (la PARADA GLOBAL por consola funciona siempre).
        $this->postJson(self::BASE.'outreach/brakes', ['clientRequestId' => (string) Str::uuid(), 'scope' => 'global', 'note' => 'Parada con la maestra apagada'], $this->as($gente['ana']))->assertStatus(409)->assertJsonPath('code', 'outreach_disabled');
        $this->assertSame(0, DB::table('outreach_brakes')->count());
        config(['outreach.enabled' => true, 'outreach.mode' => 'shadow']);
        $this->postJson(self::BASE.'outreach/brakes', ['clientRequestId' => (string) Str::uuid(), 'scope' => 'global', 'note' => 'ok'], $this->as($gente['ana']))->assertStatus(422);
        $peticion = (string) Str::uuid();
        $r = $this->postJson(self::BASE.'outreach/brakes', ['clientRequestId' => $peticion, 'scope' => 'global', 'note' => 'Parada por revisión del propietario'], $this->as($gente['ana']))
            ->assertCreated()->assertJsonPath('data.scope', 'global')->assertJsonPath('data.reason', 'manual')->assertJsonPath('meta.retainedJobs', 0)->assertJsonPath('meta.replayed', false);
        $uuid = $r->json('data.uuid');
        $this->postJson(self::BASE.'outreach/brakes', ['clientRequestId' => $peticion, 'scope' => 'global', 'note' => 'Parada por revisión del propietario'], $this->as($gente['ana']))->assertOk()->assertJsonPath('meta.replayed', true);
        $this->getJson(self::BASE.'outreach/brakes', $this->as($gente['beto']))->assertOk()->assertJsonPath('meta.open.global', true)->assertJsonCount(1, 'data');
        $this->getJson(self::BASE.'outreach/status', $this->as($gente['beto']))->assertOk()->assertJsonPath('data.brakes.own.global', true);
        // Sin la llave de soltar, 403; la misma persona, 403; otra persona con la llave, 200; otra vez, 409.
        $this->postJson(self::BASE."outreach/brakes/{$uuid}/release", ['clientRequestId' => (string) Str::uuid(), 'note' => 'Revisado y corregido'], $this->adminWithPermissions([OutreachAccess::VIEW]))->assertStatus(403)->assertJsonPath('code', 'forbidden');
        $this->postJson(self::BASE."outreach/brakes/{$uuid}/release", ['clientRequestId' => (string) Str::uuid(), 'note' => 'Revisado y corregido'], $this->as($gente['ana']))->assertStatus(403)->assertJsonPath('code', 'four_eyes_required');
        $this->postJson(self::BASE."outreach/brakes/{$uuid}/release", ['clientRequestId' => (string) Str::uuid(), 'note' => 'Revisado y corregido'], $this->as($gente['beto']))->assertOk()->assertJsonPath('data.released', true)->assertJsonPath('data.releasedBy', 'Beto');
        $this->postJson(self::BASE."outreach/brakes/{$uuid}/release", ['clientRequestId' => (string) Str::uuid(), 'note' => 'Otra vez'], $this->as($gente['caro']))->assertStatus(409)->assertJsonPath('code', 'brake_already_released');
        $this->postJson(self::BASE.'outreach/brakes/'.Str::uuid().'/release', ['clientRequestId' => (string) Str::uuid(), 'note' => 'Nada'], $this->as($gente['caro']))->assertStatus(404);
        // Un freno de canal y uno de estrategia, con su alcance; un alcance mal escrito, 422.
        $this->postJson(self::BASE.'outreach/brakes', ['clientRequestId' => (string) Str::uuid(), 'scope' => 'channel', 'scopeRef' => 'assisted', 'note' => 'Sin personal hoy'], $this->as($gente['ana']))->assertCreated();
        $this->postJson(self::BASE.'outreach/brakes', ['clientRequestId' => (string) Str::uuid(), 'scope' => 'channel', 'scopeRef' => 'simulated', 'note' => 'No se frena'], $this->as($gente['ana']))->assertStatus(422);
        $this->postJson(self::BASE.'outreach/brakes', ['clientRequestId' => (string) Str::uuid(), 'scope' => 'strategy', 'scopeRef' => 'no-es-uuid', 'note' => 'Mal escrito'], $this->as($gente['ana']))->assertStatus(422);
    }

    public function test_listas_y_detalles_por_uuid_y_seudonimo_paginados_y_sin_datos_personales(): void
    {
        $gente = $this->threePeople();
        $p = $this->plannedJobWorld(self::utcAt(self::NOW));
        (new OutreachDispatcher)->dispatch(now(), null, true);
        $ins = $this->getJson(self::BASE.'outreach/enrollments?per_page=10', $this->as($gente['ana']))->assertOk();
        $this->assertSame(1, $ins->json('data.total'));
        $fila = $ins->json('data.data.0');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $fila['subjectRef']);
        $this->assertArrayNotHasKey('subject_key', $fila);
        $this->assertArrayNotHasKey('member_id', $fila);
        $cuerpo = $ins->getContent();
        $this->assertStringNotContainsString('3001234567', $cuerpo);
        $this->assertStringNotContainsString('"m:', $cuerpo, 'la clave interna nunca sale');
        $this->getJson(self::BASE.'outreach/enrollments?per_page=500', $this->as($gente['ana']))->assertStatus(422);
        $this->getJson(self::BASE.'outreach/enrollments?status=flying', $this->as($gente['ana']))->assertStatus(422);

        $detalle = $this->getJson(self::BASE.'outreach/enrollments/'.$fila['uuid'], $this->as($gente['ana']))->assertOk();
        $this->assertCount(1, $detalle->json('data.jobs'));
        $this->assertCount(1, $detalle->json('data.outcomes'));
        $this->assertGreaterThanOrEqual(2, count($detalle->json('data.decisions')));
        $this->getJson(self::BASE.'outreach/enrollments/'.Str::uuid(), $this->as($gente['ana']))->assertStatus(404)->assertJsonPath('code', 'enrollment_not_found');

        $decision = $detalle->json('data.decisions.1');
        $d = $this->getJson(self::BASE.'outreach/decisions/'.$decision['uuid'], $this->as($gente['ana']))->assertOk();
        $this->assertSame('plan', $d->json('data.kind'));
        $this->assertNotEmpty($d->json('data.text'), 'la traza en frases');
        $this->assertSame($decision['traceHash'], $d->json('data.traceHash'));
        $this->assertStringNotContainsString('3001234567', $d->getContent());

        $trabajos = $this->getJson(self::BASE.'outreach/jobs?channel=simulated', $this->as($gente['ana']))->assertOk();
        $this->assertSame(1, $trabajos->json('data.total'));
        $this->assertArrayNotHasKey('phone_ref', $trabajos->json('data.data.0'));

        // El historial por seudónimo (no enumerable); uno mal escrito, 422; uno que no es de nadie, vacío.
        $h = $this->getJson(self::BASE.'outreach/people/'.$fila['subjectRef'].'/timeline', $this->adminWithPermissions([OutreachAccess::TIMELINE_VIEW]))->assertOk();
        $this->assertTrue($h->json('data.available'));
        $this->assertGreaterThanOrEqual(3, count($h->json('data.entries')));
        $this->getJson(self::BASE.'outreach/people/'.$fila['subjectRef'].'/timeline', $this->adminWithPermissions([OutreachAccess::VIEW]))->assertStatus(403);
        $this->getJson(self::BASE.'outreach/people/'.str_repeat('f', 64).'/timeline', $this->as($gente['ana']))->assertOk()->assertJsonPath('data.entries', []);
        $this->getJson(self::BASE.'outreach/people/nope/timeline', $this->as($gente['ana']))->assertStatus(404, 'la forma del seudónimo está en la ruta');
    }

    public function test_la_bandeja_asistida_por_http_reclama_una_vez_y_registra_con_su_persona(): void
    {
        $gente = $this->threePeople();
        $p = $this->plannedJobWorld(self::utcAt(self::NOW), 'live');
        (new OutreachDispatcher)->dispatch(now(), null, true);
        $uuid = (string) DB::table('outreach_jobs')->value('uuid');
        $cola = $this->getJson(self::BASE.'outreach/assisted/queue', $this->as($gente['ana']))->assertOk();
        $this->assertCount(1, $cola->json('data'));
        $this->assertSame($uuid, $cola->json('data.0.uuid'));
        $this->assertStringNotContainsString('3001234567', $cola->getContent());
        // I-1/I-4 (aditivo): cada fila lleva `contactWithheld` (null sin retención) y la página, `meta.withheld`.
        $this->assertSame(['withheld' => 0], $cola->json('meta'));
        $this->assertArrayHasKey('contactWithheld', $cola->json('data.0'));
        $this->assertNull($cola->json('data.0.contactWithheld'));
        $this->getJson(self::BASE.'outreach/assisted/queue', $this->adminWithPermissions([OutreachAccess::VIEW]))->assertStatus(403);

        $reclamo = $this->postJson(self::BASE."outreach/assisted/{$uuid}/claim", ['clientRequestId' => (string) Str::uuid()], $this->as($gente['ana']))->assertCreated()->assertJsonPath('data.claimedBy.name', 'Ana');
        $this->assertSame([['kind' => 'member', 'id' => $p['member']['member']], null], [$reclamo->json('data.person'), $reclamo->json('data.contactWithheld')]);
        // I-1: la queja que llega DESPUÉS de reclamar retiene el contacto en la bandeja y en la repetición; el resultado se registra igual.
        Carbon::setTestNow(Carbon::parse('2026-11-17 15:20:00', 'UTC'));
        $this->commercialEvent('complaint_created', '2026-11-17 15:16:00', $p['member']['member']);
        $mia = $this->getJson(self::BASE.'outreach/assisted/queue?filter=mine', $this->as($gente['ana']))->assertOk()->assertJsonPath('meta.withheld', 0);
        $this->assertSame([null, null, 'complaint_recent'], [$mia->json('data.0.person'), $mia->json('data.0.script'), $mia->json('data.0.contactWithheld')]);
        $otra = $this->postJson(self::BASE."outreach/assisted/{$uuid}/claim", ['clientRequestId' => (string) Str::uuid()], $this->as($gente['ana']))->assertOk()->assertJsonPath('meta.replayed', true);
        $this->assertSame([null, null, 'complaint_recent'], [$otra->json('data.person'), $otra->json('data.script'), $otra->json('data.contactWithheld')]);
        $this->postJson(self::BASE."outreach/assisted/{$uuid}/claim", ['clientRequestId' => (string) Str::uuid()], $this->as($gente['beto']))->assertStatus(409)->assertJsonPath('code', 'already_claimed');
        $this->postJson(self::BASE."outreach/assisted/{$uuid}/outcome", ['clientRequestId' => (string) Str::uuid(), 'outcome' => 'answered'], $this->as($gente['beto']))->assertStatus(403)->assertJsonPath('code', 'not_claimer');
        $this->postJson(self::BASE."outreach/assisted/{$uuid}/outcome", ['clientRequestId' => (string) Str::uuid(), 'outcome' => 'converted'], $this->as($gente['ana']))->assertStatus(422);
        $peticion = (string) Str::uuid();
        $this->postJson(self::BASE."outreach/assisted/{$uuid}/outcome", ['clientRequestId' => $peticion, 'outcome' => 'answered'], $this->as($gente['ana']))->assertCreated()->assertJsonPath('data.transition', 'end_success');
        $this->postJson(self::BASE."outreach/assisted/{$uuid}/outcome", ['clientRequestId' => $peticion, 'outcome' => 'answered'], $this->as($gente['ana']))->assertOk()->assertJsonPath('data.replayed', true);
        $this->assertSame(1, DB::table('outreach_outcomes')->where('source', 'staff')->count());
        $this->getJson(self::BASE.'outreach/assisted/queue', $this->as($gente['ana']))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(self::BASE.'outreach/status', $this->as($gente['ana']))->assertOk()->assertJsonPath('data.live.assistedQueue', 0);
    }
}
