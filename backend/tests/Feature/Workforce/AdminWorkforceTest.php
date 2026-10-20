<?php

namespace Tests\Feature\Workforce;

use App\Models\AuditLog;
use App\Models\Identity;
use App\Models\StaffProfile;
use App\Models\TrainerAuditLog;
use App\Models\TrainerFaceReference;
use App\Models\TrainerRole;
use App\Models\Workday;
use App\Services\Identity\IdentityLinkService;
use App\Support\Access\CrmPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Feature\Workforce\Concerns\WorkforceKit;
use Tests\TestCase;

/**
 * El CRM: supervisión de jornadas, personal, biometría y permisos.
 * Escenarios 25 (sin permiso), 32 (revocación desde el CRM), 41 (historiales)
 * y la decisión de retención (desactivar borra la plantilla).
 */
class AdminWorkforceTest extends TestCase
{
    use RefreshDatabase;
    use WorkforceKit;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-12 08:00', 'America/Bogota')->utc());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── Permisos

    public function test_los_permisos_nuevos_no_los_tiene_recepcion_ni_el_token_compartido(): void
    {
        foreach (['/api/admin/workforce/workdays', '/api/admin/workforce/staff', '/api/admin/devices'] as $uri) {
            $this->getJson($uri, $this->adminAs('Recepción'))->assertForbidden();
            $this->getJson($uri, $this->sharedTokenHeaders())->assertForbidden();
        }
        $this->assertNotContains('workforce.view', CrmPermission::readOnly());
        $this->assertNotContains('biometrics.view', CrmPermission::readOnly());
        $this->assertContains('workforce.manage', CrmPermission::defaultsFor('Administrador'));
        $this->assertSame([], array_values(array_intersect(
            ['workforce.view', 'workforce.manage', 'biometrics.view', 'biometrics.manage', 'devices.view', 'devices.manage'],
            CrmPermission::defaultsFor('Recepción'),
        )));
    }

    public function test_ninguna_ruta_nueva_de_administracion_responde_sin_sesion(): void
    {
        // Barrido sobre la tabla de rutas real: una ruta nueva que quedara fuera
        // de la protección por prefijo (/api/admin/*) saldría aquí.
        $rutas = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => preg_match('#^api/admin/(workforce|biometrics|devices)(/|$)#', $r->uri()) === 1);
        $this->assertGreaterThanOrEqual(17, $rutas->count());

        foreach ($rutas as $ruta) {
            $uri = '/'.preg_replace('/\{[^}]+\}/', (string) Str::uuid(), $ruta->uri());
            foreach (array_diff($ruta->methods(), ['HEAD']) as $metodo) {
                $this->json($metodo, $uri)->assertStatus(401);
            }
        }
    }

    public function test_ver_jornadas_no_permite_corregirlas(): void
    {
        $w = $this->workdayIncident();
        $this->postJson("/api/admin/workforce/workdays/{$w->uuid}/resolve", ['action' => 'dismiss', 'note' => 'Revisado con la persona'], $this->adminWithPermissions(['workforce.view']))
            ->assertForbidden();
    }

    // ── Personal

    public function test_dar_de_alta_personal_enlaza_la_identidad_del_documento_sin_duplicar(): void
    {
        // Ya es socia del gimnasio: misma persona, misma identidad.
        $identity = app(IdentityLinkService::class)->ensureIdentity('1075000777');

        $this->postJson('/api/admin/workforce/staff', [
            'full_name' => 'Marta Recepción', 'document' => '1.075.000.777', 'positions' => ['receptionist'],
        ], $this->adminWithPermissions(['workforce.manage']))
            ->assertCreated()
            ->assertJsonPath('data.identity_id', $identity->uuid)
            ->assertJsonPath('data.positions.0.key', 'receptionist')
            ->assertJsonPath('data.capabilities.workday', true)
            ->assertJsonPath('data.biometric.status', 'not_enrolled');

        $this->assertSame(1, Identity::count());
        $this->postJson('/api/admin/workforce/staff', [
            'full_name' => 'Marta Otra Vez', 'document' => '1075000777', 'positions' => ['receptionist'],
        ], $this->adminWithPermissions(['workforce.manage']))->assertStatus(409)->assertJsonPath('code', 'staff_profile_exists');
    }

    public function test_la_identidad_del_alta_se_resuelve_fuera_de_la_transaccion(): void
    {
        // En PostgreSQL, la relectura de `ensureIdentity` ante una carrera falla
        // dentro de una transacción abortada: el INSERT de la identidad tiene
        // que ir al nivel de transacción de fuera (el de la prueba).
        $base = DB::transactionLevel();
        $niveles = [];
        DB::listen(function ($q) use (&$niveles): void {
            if (str_starts_with(strtolower($q->sql), 'insert into "identities"')) {
                $niveles[] = DB::transactionLevel();
            }
        });

        $this->postJson('/api/admin/workforce/staff', [
            'full_name' => 'Nora Nueva', 'document' => '1075000888', 'positions' => ['receptionist'],
        ], $this->adminWithPermissions(['workforce.manage']))->assertCreated();

        $this->assertSame([$base], $niveles);
    }

    public function test_dos_altas_simultaneas_de_la_misma_persona_dan_409_y_no_500(): void
    {
        // La otra alta confirmó su perfil después de la comprobación de esta.
        $identity = app(IdentityLinkService::class)->ensureIdentity('1075000999');
        $insertado = false;
        StaffProfile::creating(function () use (&$insertado, $identity): void {
            if (! $insertado) {
                $insertado = true;
                DB::table('staff_profiles')->insert([
                    'uuid' => (string) Str::uuid(), 'identity_id' => $identity->id, 'full_name' => 'La otra alta',
                    'positions' => '["receptionist"]', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });

        $this->postJson('/api/admin/workforce/staff', [
            'full_name' => 'Olga Otra', 'document' => '1075000999', 'positions' => ['receptionist'],
        ], $this->adminWithPermissions(['workforce.manage']))->assertStatus(409)->assertJsonPath('code', 'staff_profile_exists');
        $this->assertTrue($insertado);
    }

    public function test_un_cargo_de_entrenador_no_se_asigna_desde_personal(): void
    {
        $this->postJson('/api/admin/workforce/staff', [
            'full_name' => 'Alguien', 'document' => '1075000778', 'positions' => ['trainer_floor'],
        ], $this->adminWithPermissions(['workforce.manage']))->assertStatus(422);
    }

    public function test_desactivar_a_quien_deja_de_trabajar_borra_su_plantilla(): void
    {
        $ana = $this->receptionist('1075000001');
        $this->enroll($ana, '1075000001', $this->face(1), $this->tablet())->assertCreated();

        $this->patchJson("/api/admin/workforce/staff/{$ana->uuid}", ['status' => 'inactive'], $this->adminWithPermissions(['workforce.manage']))
            ->assertOk()->assertJsonPath('data.status', 'inactive')->assertJsonPath('data.biometric.status', 'revoked');

        $ref = TrainerFaceReference::query()->firstOrFail();
        $this->assertNull($ref->getRawOriginal('template'));
        $this->assertStringContainsString('retención', (string) $ref->revoked_reason);
    }

    public function test_quien_conserva_otro_perfil_activo_conserva_su_plantilla(): void
    {
        $coach = $this->trainerPerson('1075000004', [TrainerRole::FLOOR], 'Laura Méndez');
        StaffProfile::create(['identity_id' => $coach->identity_id, 'full_name' => 'Laura Méndez', 'positions' => ['receptionist'], 'status' => 'active']);
        $this->enroll($coach->identity, '1075000004', $this->face(7), $this->tablet())->assertCreated();

        // Deja recepción, sigue siendo entrenadora de planta.
        $this->patchJson("/api/admin/workforce/staff/{$coach->identity->uuid}", ['status' => 'inactive'], $this->adminWithPermissions(['workforce.manage']))->assertOk();
        $this->assertTrue(TrainerFaceReference::query()->firstOrFail()->isActive());

        // Y se desactiva también como entrenadora: ahora sí se borra.
        $coach->update(['status' => 'inactive']);
        $this->assertFalse(TrainerFaceReference::query()->firstOrFail()->isActive());
    }

    public function test_desactivar_con_la_jornada_abierta_la_deja_como_incidencia(): void
    {
        // Ya no puede identificarse para marcar la salida: no se inventa la hora
        // ni se deja «en jornada» hasta que el planificador la vea obsoleta.
        $ana = $this->receptionist('1075000001');
        $tablet = $this->tablet();
        $this->clock('in', $this->enroll($ana, '1075000001', $this->face(1), $tablet)->json('verification.token'), $tablet)->assertCreated();
        $admin = $this->adminWithPermissions(['workforce.manage', 'workforce.view']);
        $this->travel(8)->hours();

        $this->patchJson("/api/admin/workforce/staff/{$ana->uuid}", ['status' => 'inactive'], $admin)->assertOk();

        $w = Workday::query()->firstOrFail();
        $this->assertSame(Workday::STATUS_INCIDENT, $w->status);
        $this->assertSame(Workday::INCIDENT_PROFILE_DEACTIVATED, $w->incident_reason);
        $this->assertNull($w->clock_out_at);
        $this->assertSame(0, Workday::query()->open()->count());
        $this->getJson("/api/admin/workforce/workdays/{$w->uuid}", $admin)
            ->assertOk()->assertJsonPath('data.incident.label', 'Desactivado con la jornada abierta');

        // El administrador la cierra con la hora real.
        $this->postJson("/api/admin/workforce/workdays/{$w->uuid}/resolve", ['action' => 'set_clock_out', 'clock_out_at' => now()->subMinutes(10)->toIso8601String(), 'note' => 'Salió al terminar el turno'], $admin)
            ->assertOk()->assertJsonPath('data.status', 'closed');
    }

    public function test_quien_conserva_un_cargo_con_jornada_conserva_su_jornada_abierta(): void
    {
        $coach = $this->trainerPerson('1075000004', [TrainerRole::FLOOR], 'Laura Méndez');
        StaffProfile::create(['identity_id' => $coach->identity_id, 'full_name' => 'Laura Méndez', 'positions' => ['receptionist'], 'status' => 'active']);
        $tablet = $this->tablet();
        $this->clock('in', $this->enroll($coach->identity, '1075000004', $this->face(7), $tablet)->json('verification.token'), $tablet)->assertCreated();

        // Deja recepción y sigue en planta: su jornada sigue abierta.
        $this->patchJson("/api/admin/workforce/staff/{$coach->identity->uuid}", ['status' => 'inactive'], $this->adminWithPermissions(['workforce.manage']))->assertOk();
        $this->assertSame(1, Workday::query()->open()->count());

        // Se desactiva también como entrenadora (otro camino): ahora sí.
        $coach->update(['status' => 'inactive']);
        $this->assertSame(0, Workday::query()->open()->count());
        $this->assertSame(Workday::INCIDENT_PROFILE_DEACTIVATED, Workday::query()->firstOrFail()->incident_reason);
    }

    // ── Biometría

    public function test_la_ficha_biometrica_nunca_trae_la_plantilla(): void
    {
        $ana = $this->receptionist('1075000001');
        $this->enroll($ana, '1075000001', $this->face(1), $this->tablet())->assertCreated();

        $res = $this->getJson("/api/admin/biometrics/identities/{$ana->uuid}", $this->adminWithPermissions(['biometrics.view']))
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.enrolled_via', 'kiosk')
            ->assertJsonPath('data.enrolled_device.name', 'Tablet Recepción 01')
            ->assertJsonPath('data.consent_version', '2026-10-v1');
        $json = $res->getContent();
        // Ni la columna cifrada ni el vector: ninguna clave `template`/`embedding`
        // y ningún número de la cara registrada.
        $this->assertStringNotContainsString('"template":', $json);
        $this->assertStringNotContainsString('embedding', $json);
        $this->assertStringNotContainsString((string) round($this->capture($this->face(1), 1)[0], 6), $json);
        $this->assertSame('Rostro registrado', collect($res->json('data.history'))->firstWhere('event', 'biometric.enrolled')['label']);
    }

    public function test_revocar_exige_motivo_borra_la_plantilla_y_deja_traza_doble(): void
    {
        $ana = $this->receptionist('1075000001');
        $this->enroll($ana, '1075000001', $this->face(1), $this->tablet())->assertCreated();
        $admin = $this->adminWithPermissions(['biometrics.manage', 'biometrics.view']);

        $this->postJson("/api/admin/biometrics/identities/{$ana->uuid}/revoke", [], $admin)->assertStatus(422);
        $this->postJson("/api/admin/biometrics/identities/{$ana->uuid}/revoke", ['reason' => 'Solicitud de la titular'], $admin)
            ->assertOk()->assertJsonPath('data.status', 'revoked');

        $this->assertNull(TrainerFaceReference::query()->firstOrFail()->getRawOriginal('template'));
        $this->assertSame(1, AuditLog::query()->where('module', 'biometrics')->where('action', 'delete')->count());
        $this->assertSame(1, TrainerAuditLog::query()->where('event', 'biometric.revoked')->count());
        $this->getJson("/api/admin/biometrics/identities/{$ana->uuid}", $admin)
            ->assertJsonPath('data.revoked.reason', 'Solicitud de la titular');

        $this->postJson("/api/admin/biometrics/identities/{$ana->uuid}/revoke", ['reason' => 'Otra vez'], $admin)
            ->assertStatus(409)->assertJsonPath('code', 'biometric_not_active');
    }

    public function test_autorizar_un_registro_con_plantilla_activa_ofrece_renovarla_sin_invalidarla(): void
    {
        $ana = $this->receptionist('1075000001');
        $tablet = $this->tablet();
        $this->enroll($ana, '1075000001', $this->face(1), $tablet)->assertCreated();

        $this->postJson("/api/admin/biometrics/identities/{$ana->uuid}/enrollment-code", ['reason' => 'Cambio de aspecto'], $this->adminWithPermissions(['biometrics.manage']))->assertCreated();
        // La tablet ofrece «Registrar mi rostro»…
        $this->identify('1075000001', $tablet)->assertJsonPath('employee.biometric.status', 'renewal_pending');
        // …pero la plantilla válida sigue verificando la jornada hasta que la
        // nueva la sustituya: la renovación no deja a la persona sin rostro.
        $this->verifyFace('1075000001', $this->face(1), $tablet)->assertOk();
        $this->verifyFace('1075000001', $this->face(2), $tablet)
            ->assertStatus(422)->assertJsonPath('code', 'face_no_match');
        $this->enroll($ana, '1075000001', $this->face(3), $tablet)->assertCreated();
        // Sustituida: verifica la nueva, ya no la anterior.
        $this->verifyFace('1075000001', $this->face(3), $tablet)->assertOk();
        $this->verifyFace('1075000001', $this->face(1), $tablet)
            ->assertStatus(422)->assertJsonPath('code', 'face_no_match');
        $this->identify('1075000001', $tablet)->assertJsonPath('employee.biometric.status', 'active');
    }

    // ── Supervisión de jornadas

    public function test_el_listado_trae_empleado_cargo_dispositivo_paginacion_y_cursor(): void
    {
        $ana = $this->receptionist('1075000001', 'Ana María Rojas');
        $tablet = $this->tablet();
        $this->clock('in', $this->enroll($ana, '1075000001', $this->face(1), $tablet)->json('verification.token'), $tablet)->assertCreated();

        $res = $this->getJson('/api/admin/workforce/workdays?from=2026-10-12&to=2026-10-12&per_page=10', $this->adminWithPermissions(['workforce.view']))
            ->assertOk()
            ->assertJsonPath('data.0.employee.full_name', 'Ana María Rojas')
            ->assertJsonPath('data.0.employee.identity_id', $ana->uuid)
            ->assertJsonPath('data.0.position.label', 'Recepción')
            ->assertJsonPath('data.0.clock_in.device.name', 'Tablet Recepción 01')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.per_page', 10);
        $this->assertGreaterThan(0, $res->json('events_cursor'));

        $this->getJson('/api/admin/workforce/workdays?status=closed', $this->adminWithPermissions(['workforce.view']))->assertJsonPath('meta.total', 0);
        $this->getJson("/api/admin/workforce/workdays?device={$tablet['uuid']}&method=enrollment", $this->adminWithPermissions(['workforce.view']))->assertJsonPath('meta.total', 1);
    }

    public function test_los_indicadores_cuentan_lo_que_pasa(): void
    {
        $ana = $this->receptionist('1075000001');
        $this->floorTrainer('1075000002');
        $tablet = $this->tablet();
        $this->clock('in', $this->enroll($ana, '1075000001', $this->face(1), $tablet)->json('verification.token'), $tablet)->assertCreated();

        $this->getJson('/api/admin/workforce/workdays/summary?from=2026-10-12&to=2026-10-12', $this->adminWithPermissions(['workforce.view']))
            ->assertOk()
            ->assertJsonPath('data.eligible_employees', 2)
            ->assertJsonPath('data.employees_with_records', 1)
            ->assertJsonPath('data.currently_in', 1)
            ->assertJsonPath('data.finished', 0)
            ->assertJsonPath('data.incidents_open', 0)
            ->assertJsonPath('data.punctuality', null);
    }

    public function test_el_detalle_muestra_la_linea_de_tiempo_y_lo_tecnico_solo_con_permiso_de_dispositivos(): void
    {
        $ana = $this->receptionist('1075000001');
        $tablet = $this->tablet();
        $w = $this->clock('in', $this->enroll($ana, '1075000001', $this->face(1), $tablet)->json('verification.token'), $tablet)->json('workday.id');

        $this->getJson("/api/admin/workforce/workdays/{$w}", $this->adminWithPermissions(['workforce.view']))
            ->assertOk()->assertJsonPath('data.events.0.type', 'clock_in')->assertJsonPath('data.events.0.technical', null);
        $this->getJson("/api/admin/workforce/workdays/{$w}", $this->adminWithPermissions(['workforce.view', 'devices.view']))
            ->assertOk()->assertJsonPath('data.events.0.technical.app_version', '2.1.0+16');
    }

    public function test_corregir_una_incidencia_conserva_lo_real_y_deja_traza(): void
    {
        $w = $this->workdayIncident();
        $admin = $this->adminWithPermissions(['workforce.manage', 'workforce.view']);

        $this->postJson("/api/admin/workforce/workdays/{$w->uuid}/resolve", ['action' => 'set_clock_out', 'clock_out_at' => now()->addHour()->toIso8601String(), 'note' => 'Futuro'], $admin)
            ->assertStatus(422)->assertJsonPath('code', 'clock_out_invalid');

        $salida = Carbon::parse('2026-10-11 17:00', 'America/Bogota')->utc()->toIso8601String();
        $this->postJson("/api/admin/workforce/workdays/{$w->uuid}/resolve", ['action' => 'set_clock_out', 'clock_out_at' => $salida, 'note' => 'Confirmado con la planilla física'], $admin)
            ->assertOk()
            ->assertJsonPath('data.status', 'closed')
            ->assertJsonPath('data.clock_out.source', 'admin_correction')
            ->assertJsonPath('data.duration_seconds', 9 * 3600)
            ->assertJsonPath('data.incident.resolution', 'set_clock_out');

        $this->postJson("/api/admin/workforce/workdays/{$w->uuid}/resolve", ['action' => 'dismiss', 'note' => 'Doble corrección'], $admin)
            ->assertStatus(409)->assertJsonPath('code', 'workday_not_incident');
        $this->assertSame(1, AuditLog::query()->where('module', 'workforce')->where('entity', 'workday')->count());
    }

    private function workdayIncident(): Workday
    {
        $ana = $this->receptionist('1075000001');

        return Workday::create([
            'identity_id' => $ana->id, 'position' => 'receptionist', 'status' => Workday::STATUS_INCIDENT,
            'business_date' => '2026-10-11', 'clock_in_at' => Carbon::parse('2026-10-11 08:00', 'America/Bogota')->utc(),
            'clock_in_method' => 'face', 'incident_reason' => Workday::INCIDENT_MISSING_CLOCK_OUT, 'incident_at' => now(),
        ]);
    }

    public function test_un_codigo_de_clases_exige_tambien_administrar_entrenadores(): void
    {
        // F5: el código de clases abre el portal del entrenador.
        $coach = $this->trainerPerson('1075000010', [TrainerRole::FUNCTIONAL]);
        $uuid = $coach->identity->uuid;

        $this->postJson("/api/admin/workforce/staff/{$uuid}/fallback-code", ['purpose' => 'class', 'reason' => 'Cámara dañada'], $this->adminWithPermissions(['workforce.manage']))
            ->assertForbidden()->assertJsonPath('required_permission', 'trainers.manage');
        $this->postJson("/api/admin/workforce/staff/{$uuid}/fallback-code", ['purpose' => 'class', 'reason' => 'Cámara dañada'], $this->adminWithPermissions(['workforce.manage', 'trainers.manage']))
            ->assertCreated();
        $this->postJson("/api/admin/workforce/staff/{$uuid}/fallback-code", ['purpose' => 'workday', 'reason' => 'Cámara dañada'], $this->adminWithPermissions(['workforce.manage']))
            ->assertCreated();
    }

    public function test_la_distancia_facial_es_dato_biometrico_no_tecnico(): void
    {
        // F6.
        $ana = $this->receptionist('1075000001');
        $tablet = $this->tablet();
        $this->clock('in', $this->enroll($ana, '1075000001', $this->face(1), $tablet)->json('verification.token'), $tablet)->assertCreated();
        $this->reloj('2026-10-12 12:00');
        $out = $this->verifyFace('1075000001', $this->face(1), $tablet)->json('verification.token');
        $w = $this->clock('out', $out, $tablet)->json('workday.id');

        $this->getJson("/api/admin/workforce/workdays/{$w}", $this->adminWithPermissions(['workforce.view', 'devices.view']))
            ->assertJsonPath('data.events.1.technical.face_distance', null);
        $con = $this->getJson("/api/admin/workforce/workdays/{$w}", $this->adminWithPermissions(['workforce.view', 'devices.view', 'biometrics.view']))
            ->json('data.events.1.technical.face_distance');
        $this->assertIsFloat($con);
    }

    private function reloj(string $bogota): void
    {
        Carbon::setTestNow(Carbon::parse($bogota, 'America/Bogota')->utc());
    }

    public function test_corregir_la_salida_exige_hora_con_zona(): void
    {
        // I6: «2026-10-11 17:00» sin desfase se leería en UTC (12:00 en Bogotá).
        $w = $this->workdayIncident();
        $this->postJson("/api/admin/workforce/workdays/{$w->uuid}/resolve", ['action' => 'set_clock_out', 'clock_out_at' => '2026-10-11 17:00:00', 'note' => 'Sin zona horaria'], $this->adminWithPermissions(['workforce.manage']))
            ->assertStatus(422)->assertJsonValidationErrors('clock_out_at');
        $this->postJson("/api/admin/workforce/workdays/{$w->uuid}/resolve", ['action' => 'set_clock_out', 'clock_out_at' => '2026-10-11T17:00:00-05:00', 'note' => 'Con zona horaria'], $this->adminWithPermissions(['workforce.manage']))
            ->assertOk()->assertJsonPath('data.duration_seconds', 9 * 3600);
    }

    public function test_el_cursor_de_eventos_se_lee_antes_que_los_datos(): void
    {
        // Leído después, un hecho confirmado entre las dos lecturas quedaba bajo
        // el cursor sin estar en los datos. No se puede provocar la carrera en
        // una prueba: se fija el ORDEN de las consultas.
        $orden = function (string $uri, string $tabla, array $headers): void {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson($uri, $headers)->assertOk();
            $sql = array_column(DB::getQueryLog(), 'query');
            DB::disableQueryLog();
            $cursor = collect($sql)->search(fn ($q) => str_contains($q, 'max(') && str_contains($q, 'class_events'));
            $datos = collect($sql)->search(fn ($q) => str_starts_with($q, 'select') && str_contains($q, 'from "'.$tabla.'"'));
            $this->assertNotFalse($cursor);
            $this->assertNotFalse($datos);
            $this->assertLessThan($datos, $cursor, "El cursor de {$uri} se lee después de los datos.");
        };
        $admin = $this->adminWithPermissions(['workforce.view', 'classes.view']);
        $orden('/api/admin/workforce/workdays', 'workdays', $admin);
        $orden('/api/admin/class-sessions', 'class_sessions', $admin);
    }

    public function test_el_telefono_solo_lo_ve_quien_edita_y_los_dispositivos_de_jornada_van_verificados(): void
    {
        $ana = $this->receptionist('1075000001');
        StaffProfile::query()->where('identity_id', $ana->id)->update(['phone' => '+573001112233']);
        $tablet = $this->tablet();
        $this->clock('in', $this->enroll($ana, '1075000001', $this->face(1), $tablet)->json('verification.token'), $tablet)
            ->assertJsonPath('workday.clock_in.device.verified', true);

        $this->getJson('/api/admin/workforce/staff', $this->adminWithPermissions(['workforce.view']))->assertJsonPath('data.0.phone', null);
        $this->getJson('/api/admin/workforce/staff', $this->adminWithPermissions(['workforce.view', 'workforce.manage']))->assertJsonPath('data.0.phone', '+573001112233');
    }
}
