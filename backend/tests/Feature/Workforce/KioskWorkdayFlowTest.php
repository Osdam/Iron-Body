<?php

namespace Tests\Feature\Workforce;

use App\Models\ClassEvent;
use App\Models\Identity;
use App\Models\StaffProfile;
use App\Models\TrainerAuditLog;
use App\Models\TrainerFaceReference;
use App\Models\TrainerRole;
use App\Models\Workday;
use App\Models\WorkdayEvent;
use App\Models\WorkforceKioskToken;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Feature\Workforce\Concerns\WorkforceKit;
use Tests\TestCase;

/**
 * La jornada laboral de punta a punta por el kiosko, contra las clases reales.
 * Escenarios del encargo: 01-11, 14, 16, 18-21, 33-36, 48, 49.
 */
class KioskWorkdayFlowTest extends TestCase
{
    use RefreshDatabase;
    use WorkforceKit;

    private const ANA = '1075000001';

    protected function setUp(): void
    {
        parent::setUp();
        $this->reloj('2026-10-12 08:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function reloj(string $bogota): void
    {
        Carbon::setTestNow(Carbon::parse($bogota, 'America/Bogota')->utc());
    }

    // ── 01-03: sin biometría → enrolamiento autorizado → entrada en el mismo flujo

    public function test_recepcionista_sin_biometria_se_enrola_con_codigo_y_entra_en_el_mismo_flujo(): void
    {
        $ana = $this->receptionist(self::ANA);
        $tablet = $this->tablet();

        $this->identify(self::ANA, $tablet)->assertOk()
            ->assertJsonPath('employee.display_name', 'Ana R.')
            ->assertJsonPath('employee.biometric.status', 'not_enrolled')
            ->assertJsonPath('employee.capabilities.workday', true)
            ->assertJsonMissingPath('employee.workday');

        // Sin plantilla no hay verificación posible.
        $this->verifyFace(self::ANA, $this->face(1), $tablet)
            ->assertStatus(409)->assertJsonPath('code', 'biometric_not_enrolled');

        $enrolled = $this->enroll($ana, self::ANA, $this->face(1), $tablet)
            ->assertCreated()
            ->assertJsonPath('biometric.status', 'active')
            ->assertJsonPath('verification.method', 'enrollment')
            ->assertJsonPath('workday.state', 'none')
            ->assertJsonPath('allowed_actions', ['clock_in']);

        $this->clock('in', $enrolled->json('verification.token'), $tablet)
            ->assertCreated()
            ->assertJsonPath('result', 'clocked_in')
            ->assertJsonPath('workday.status', 'open')
            ->assertJsonPath('workday.position.key', 'receptionist')
            ->assertJsonPath('workday.clock_in.method', 'enrollment')
            ->assertJsonPath('workday.clock_in.device.name', 'Tablet Recepción 01')
            ->assertJsonPath('workday.business_date', '2026-10-12');

        // La plantilla queda cifrada, con consentimiento y quién lo autorizó.
        $ref = TrainerFaceReference::query()->where('identity_id', $ana->id)->firstOrFail();
        $this->assertNull($ref->getRawOriginal('embedding'));
        $this->assertStringNotContainsString((string) $this->face(1)[0], (string) $ref->getRawOriginal('template'));
        $this->assertSame('2026-10-v1', $ref->consent_version);
        $this->assertSame('kiosk', $ref->enrolled_via);
        $this->assertNotNull($ref->enrolled_by_name);
    }

    public function test_el_documento_solo_no_enrola_hace_falta_el_codigo_del_administrador(): void
    {
        $this->receptionist(self::ANA);
        $tablet = $this->tablet();

        // Sin código emitido no hay nada que adivinar: se dice que no hay
        // ninguno vigente (antes, el genérico `authorization_code_invalid`).
        $this->signed('POST', '/api/kiosk/enrollment/start', ['employee_ref' => $this->ref(self::ANA, $tablet), 'authorization_code' => '12345678'], $tablet)
            ->assertStatus(422)->assertJsonPath('code', 'authorization_code_not_issued');
        $this->assertSame(0, TrainerFaceReference::count());
    }

    public function test_el_codigo_de_enrolamiento_admite_pocos_intentos(): void
    {
        $ana = $this->receptionist(self::ANA);
        $tablet = $this->tablet();
        $code = $this->postJson("/api/admin/biometrics/identities/{$ana->uuid}/enrollment-code", ['reason' => 'Registro inicial'], $this->adminWithPermissions(['biometrics.manage']))->json('code');
        $wrong = $code === '00000000' ? '11111111' : '00000000';

        for ($i = 0; $i < 4; $i++) {
            $this->signed('POST', '/api/kiosk/enrollment/start', ['employee_ref' => $this->ref(self::ANA, $tablet), 'authorization_code' => $wrong], $tablet)
                ->assertStatus(422);
        }
        $this->signed('POST', '/api/kiosk/enrollment/start', ['employee_ref' => $this->ref(self::ANA, $tablet), 'authorization_code' => $wrong], $tablet)
            ->assertStatus(429)->assertJsonPath('code', 'authorization_code_attempts_exceeded');
        // Agotado: ni el código correcto sirve ya.
        $this->signed('POST', '/api/kiosk/enrollment/start', ['employee_ref' => $this->ref(self::ANA, $tablet), 'authorization_code' => $code], $tablet)
            ->assertStatus(429);
    }

    public function test_sin_consentimiento_no_se_guarda_ningun_rostro(): void
    {
        $ana = $this->receptionist(self::ANA);
        $tablet = $this->tablet();
        $code = $this->postJson("/api/admin/biometrics/identities/{$ana->uuid}/enrollment-code", ['reason' => 'Registro inicial'], $this->adminWithPermissions(['biometrics.manage']))->json('code');
        $start = $this->signed('POST', '/api/kiosk/enrollment/start', ['employee_ref' => $this->ref(self::ANA, $tablet), 'authorization_code' => $code], $tablet)->assertOk();

        $this->signed('POST', '/api/kiosk/enrollment/complete', [
            'enrollment_token' => $start->json('enrollment.token'),
            'consent_version' => $start->json('consent.version'),
            'consent_accepted' => false,
            'embeddings' => [$this->capture($this->face(1), 1), $this->capture($this->face(1), 2), $this->capture($this->face(1), 3)],
            'operation_id' => (string) Str::uuid(),
        ], $tablet)->assertStatus(422)->assertJsonPath('code', 'consent_required');

        $this->assertSame(0, TrainerFaceReference::count());
        $this->assertSame(0, Workday::count());
    }

    public function test_capturas_incoherentes_no_enrolan_pero_se_pueden_repetir_sin_otro_codigo(): void
    {
        $ana = $this->receptionist(self::ANA);
        $tablet = $this->tablet();
        $code = $this->postJson("/api/admin/biometrics/identities/{$ana->uuid}/enrollment-code", ['reason' => 'Registro inicial'], $this->adminWithPermissions(['biometrics.manage']))->json('code');
        $start = $this->signed('POST', '/api/kiosk/enrollment/start', ['employee_ref' => $this->ref(self::ANA, $tablet), 'authorization_code' => $code], $tablet)->assertOk();
        $body = fn (array $caps) => [
            'enrollment_token' => $start->json('enrollment.token'),
            'consent_version' => '2026-10-v1',
            'consent_accepted' => true,
            'embeddings' => $caps,
            'operation_id' => (string) Str::uuid(),
        ];

        // Tres caras distintas: no son la misma persona.
        $this->signed('POST', '/api/kiosk/enrollment/complete', $body([$this->face(1), $this->face(2), $this->face(3)]), $tablet)
            ->assertStatus(422)->assertJsonPath('code', 'embeddings_inconsistent');
        $this->assertSame(0, TrainerFaceReference::count());
        $this->assertSame(0, Workday::count(), 'Un enrolamiento fallido no abre jornada.');

        $this->signed('POST', '/api/kiosk/enrollment/complete', $body([$this->capture($this->face(1), 1), $this->capture($this->face(1), 2), $this->capture($this->face(1), 3)]), $tablet)
            ->assertCreated();
    }

    public function test_no_se_enrola_un_rostro_que_ya_es_de_otra_persona(): void
    {
        $tablet = $this->tablet();
        $ana = $this->receptionist(self::ANA);
        $this->enroll($ana, self::ANA, $this->face(1), $tablet)->assertCreated();

        // Otra identidad intenta registrar la cara de Ana (34).
        $pedro = $this->receptionist('1075000009', 'Pedro Gómez');
        $this->enroll($pedro, '1075000009', $this->face(1), $tablet)
            ->assertStatus(409)->assertJsonPath('code', 'face_already_linked');

        $this->assertNull(TrainerFaceReference::query()->where('identity_id', $pedro->id)->first());
        // Y el intento queda en la traza aunque la operación se rechace.
        $this->assertSame(1, TrainerAuditLog::query()->where('event', 'biometric.duplicate_rejected')->count());
    }

    // ── 04-09: entrada, salida, duplicados

    public function test_entrada_y_salida_con_rostro_registran_horas_del_servidor_y_duracion(): void
    {
        $ana = $this->receptionist(self::ANA);
        $tablet = $this->tablet();
        $this->enroll($ana, self::ANA, $this->face(1), $tablet)->assertCreated();

        $in = $this->verifyFace(self::ANA, $this->face(1), $tablet)->assertOk()
            ->assertJsonPath('verification.method', 'face')
            ->assertJsonPath('employee.display_name', 'Ana María Rojas')
            ->assertJsonPath('allowed_actions', ['clock_in']);
        $this->clock('in', $in->json('verification.token'), $tablet)->assertCreated();

        $this->reloj('2026-10-12 17:30');
        $out = $this->verifyFace(self::ANA, $this->face(1), $tablet)->assertOk()
            ->assertJsonPath('workday.state', 'open')
            ->assertJsonPath('allowed_actions', ['clock_out']);
        $this->clock('out', $out->json('verification.token'), $tablet)->assertOk()
            ->assertJsonPath('result', 'clocked_out')
            ->assertJsonPath('workday.status', 'closed')
            ->assertJsonPath('workday.duration_seconds', 9 * 3600 + 30 * 60)
            ->assertJsonPath('workday.clock_out_at', Carbon::parse('2026-10-12 17:30', 'America/Bogota')->utc()->toIso8601String());

        $this->assertSame([WorkdayEvent::CLOCK_IN, WorkdayEvent::CLOCK_OUT], WorkdayEvent::query()->orderBy('id')->pluck('type')->all());
    }

    public function test_una_entrada_repetida_no_crea_otra_jornada(): void
    {
        $ana = $this->receptionist(self::ANA);
        $tablet = $this->tablet();
        $first = $this->enroll($ana, self::ANA, $this->face(1), $tablet);
        $this->clock('in', $first->json('verification.token'), $tablet)->assertCreated();

        // Otra verificación y otra entrada (07).
        $again = $this->verifyFace(self::ANA, $this->face(1), $tablet)->assertOk();
        $this->clock('in', $again->json('verification.token'), $tablet)
            ->assertStatus(409)->assertJsonPath('code', 'workday_already_open')
            ->assertJsonPath('workday.status', 'open');

        $this->assertSame(1, Workday::count());
        // La verificación no se gastó: sirve para la salida.
        $this->clock('out', $again->json('verification.token'), $tablet)->assertOk();
    }

    public function test_una_salida_repetida_o_sin_entrada_se_rechaza(): void
    {
        $ana = $this->receptionist(self::ANA);
        $tablet = $this->tablet();
        $first = $this->enroll($ana, self::ANA, $this->face(1), $tablet);

        // Salida sin entrada (09).
        $this->clock('out', $first->json('verification.token'), $tablet)
            ->assertStatus(409)->assertJsonPath('code', 'no_open_workday');

        $in = $this->verifyFace(self::ANA, $this->face(1), $tablet);
        $this->clock('in', $in->json('verification.token'), $tablet)->assertCreated();
        $out = $this->verifyFace(self::ANA, $this->face(1), $tablet);
        $this->clock('out', $out->json('verification.token'), $tablet)->assertOk();

        // Salida repetida (08).
        $again = $this->verifyFace(self::ANA, $this->face(1), $tablet);
        $this->clock('out', $again->json('verification.token'), $tablet)
            ->assertStatus(409)->assertJsonPath('code', 'no_open_workday')
            ->assertJsonPath('last.status', 'closed');
        $this->assertSame(1, WorkdayEvent::query()->where('type', WorkdayEvent::CLOCK_OUT)->count());
    }

    // ── 19-21: reintentos y respuestas duplicadas

    public function test_un_reintento_con_el_mismo_operation_id_devuelve_lo_mismo_sin_duplicar(): void
    {
        $ana = $this->receptionist(self::ANA);
        $tablet = $this->tablet();
        $v = $this->enroll($ana, self::ANA, $this->face(1), $tablet)->json('verification.token');
        $op = (string) Str::uuid();

        $first = $this->clock('in', $v, $tablet, $op)->assertCreated();
        // La respuesta se perdió: la tablet reintenta igual (nonce nuevo).
        $retry = $this->clock('in', $v, $tablet, $op)->assertOk()->assertJsonPath('result', 'replayed');

        $this->assertSame($first->json('workday.id'), $retry->json('workday.id'));
        $this->assertSame(1, Workday::count());
        $this->assertSame(1, WorkdayEvent::count());
    }

    public function test_el_mismo_operation_id_no_vale_para_otra_persona(): void
    {
        $tablet = $this->tablet();
        $ana = $this->receptionist(self::ANA);
        $op = (string) Str::uuid();
        $this->clock('in', $this->enroll($ana, self::ANA, $this->face(1), $tablet)->json('verification.token'), $tablet, $op)->assertCreated();

        $pedro = $this->receptionist('1075000009', 'Pedro Gómez');
        $vPedro = $this->enroll($pedro, '1075000009', $this->face(2), $tablet)->json('verification.token');
        $this->clock('in', $vPedro, $tablet, $op)->assertStatus(409)->assertJsonPath('code', 'idempotency_conflict');
    }

    public function test_una_verificacion_sirve_una_sola_vez_y_solo_en_su_dispositivo(): void
    {
        $ana = $this->receptionist(self::ANA);
        $a = $this->tablet('a', 'Tablet A');
        $b = $this->tablet('b', 'Tablet B');
        $v = $this->enroll($ana, self::ANA, $this->face(1), $a)->json('verification.token');

        $this->clock('in', $v, $b)->assertStatus(401)->assertJsonPath('code', 'verification_invalid');
        $this->clock('in', $v, $a)->assertCreated();

        $this->reloj('2026-10-12 12:00');
        $this->clock('out', $v, $a)->assertStatus(401)->assertJsonPath('code', 'verification_invalid');
    }

    public function test_una_verificacion_caduca_a_los_dos_minutos(): void
    {
        $ana = $this->receptionist(self::ANA);
        $tablet = $this->tablet();
        $v = $this->enroll($ana, self::ANA, $this->face(1), $tablet)->json('verification.token');
        $this->reloj('2026-10-12 08:02:01');

        $this->clock('in', $v, $tablet)->assertStatus(401)->assertJsonPath('code', 'verification_invalid');
        $this->assertSame(0, Workday::count());
    }

    // ── 10-11: dos personas en la misma tablet

    public function test_dos_empleados_seguidos_en_la_misma_tablet_no_se_mezclan(): void
    {
        $tablet = $this->tablet();
        $ana = $this->receptionist(self::ANA);
        $pedro = $this->receptionist('1075000009', 'Pedro Gómez');
        $this->enroll($ana, self::ANA, $this->face(1), $tablet);
        $this->enroll($pedro, '1075000009', $this->face(2), $tablet);

        $vAna = $this->verifyFace(self::ANA, $this->face(1), $tablet)->json('verification.token');
        // Pedro, justo después, con su cara: su propia verificación.
        $vPedro = $this->verifyFace('1075000009', $this->face(2), $tablet)->assertOk()
            ->assertJsonPath('employee.display_name', 'Pedro Gómez')->json('verification.token');
        // Pedro NO puede verificarse con la cara de Ana.
        $this->verifyFace('1075000009', $this->face(1), $tablet)->assertStatus(422)->assertJsonPath('code', 'face_no_match');

        $this->clock('in', $vPedro, $tablet)->assertCreated();
        $this->clock('in', $vAna, $tablet)->assertCreated();

        $this->assertSame(1, Workday::query()->where('identity_id', $ana->id)->count());
        $this->assertSame(1, Workday::query()->where('identity_id', $pedro->id)->count());
    }

    public function test_la_referencia_de_empleado_no_viaja_a_otra_tablet(): void
    {
        $this->receptionist(self::ANA);
        $a = $this->tablet('a', 'Tablet A');
        $b = $this->tablet('b', 'Tablet B');
        $ref = $this->ref(self::ANA, $a);

        $this->signed('POST', '/api/kiosk/verify', ['employee_ref' => $ref, 'purpose' => 'workday', 'embedding' => $this->face(1)], $b)
            ->assertStatus(401)->assertJsonPath('code', 'employee_ref_invalid');
    }

    // ── 18: medianoche; salida olvidada → incidencia

    public function test_una_jornada_nocturna_es_del_dia_en_que_empezo(): void
    {
        $ana = $this->receptionist(self::ANA);
        $tablet = $this->tablet();
        $this->reloj('2026-10-12 22:00');
        $this->clock('in', $this->enroll($ana, self::ANA, $this->face(1), $tablet)->json('verification.token'), $tablet)->assertCreated();

        $this->reloj('2026-10-13 06:00');
        $out = $this->verifyFace(self::ANA, $this->face(1), $tablet)->assertJsonPath('workday.state', 'open');
        $this->clock('out', $out->json('verification.token'), $tablet)->assertOk()
            ->assertJsonPath('workday.business_date', '2026-10-12')
            ->assertJsonPath('workday.duration_seconds', 8 * 3600);
    }

    public function test_una_salida_olvidada_pasa_a_incidencia_sin_inventar_hora_y_deja_entrar(): void
    {
        $ana = $this->receptionist(self::ANA);
        $tablet = $this->tablet();
        $this->clock('in', $this->enroll($ana, self::ANA, $this->face(1), $tablet)->json('verification.token'), $tablet)->assertCreated();

        $this->reloj('2026-10-13 08:05');
        $next = $this->verifyFace(self::ANA, $this->face(1), $tablet)->assertOk()
            ->assertJsonPath('workday.state', 'none')
            ->assertJsonPath('workday.pending_incident.status', 'open')
            ->assertJsonPath('allowed_actions', ['clock_in']);
        $this->clock('in', $next->json('verification.token'), $tablet)->assertCreated()
            ->assertJsonPath('flagged_previous.status', 'incident')
            ->assertJsonPath('flagged_previous.incident.reason', 'missing_clock_out')
            ->assertJsonPath('flagged_previous.clock_out_at', null)
            ->assertJsonPath('flagged_previous.duration_seconds', null);

        $this->assertSame(1, Workday::query()->open()->count());
        $this->assertSame(1, Workday::query()->where('status', Workday::STATUS_INCIDENT)->count());
    }

    public function test_cerrar_una_jornada_de_hace_dia_y_medio_no_se_permite_queda_en_revision(): void
    {
        $ana = $this->receptionist(self::ANA);
        $tablet = $this->tablet();
        $this->clock('in', $this->enroll($ana, self::ANA, $this->face(1), $tablet)->json('verification.token'), $tablet)->assertCreated();

        // Al día siguiente olvidó entrar y marca salida.
        $this->reloj('2026-10-13 17:00');
        $v = $this->verifyFace(self::ANA, $this->face(1), $tablet)->json('verification.token');
        $this->clock('out', $v, $tablet)->assertStatus(409)
            ->assertJsonPath('code', 'no_open_workday')
            ->assertJsonPath('last.status', 'incident');
        $this->assertNull(Workday::query()->firstOrFail()->clock_out_at);
    }

    public function test_el_planificador_marca_las_salidas_olvidadas_sin_cerrarlas(): void
    {
        $ana = $this->receptionist(self::ANA);
        $tablet = $this->tablet();
        $this->clock('in', $this->enroll($ana, self::ANA, $this->face(1), $tablet)->json('verification.token'), $tablet)->assertCreated();

        $this->reloj('2026-10-12 23:00');
        $this->artisan('workforce:maintenance')->assertSuccessful();
        $this->assertSame(Workday::STATUS_OPEN, Workday::query()->firstOrFail()->status, '15 h: aún no.');

        $this->reloj('2026-10-13 00:30');
        $this->artisan('workforce:maintenance')->assertSuccessful();
        $w = Workday::query()->firstOrFail();
        $this->assertSame(Workday::STATUS_INCIDENT, $w->status);
        $this->assertNull($w->clock_out_at);
    }

    // ── Elegibilidad, multirol, revocación, respaldo

    public function test_entrenador_de_planta_registra_jornada(): void
    {
        $coach = $this->floorTrainer('1075000002');
        $tablet = $this->tablet();
        $v = $this->enroll($coach->identity, '1075000002', $this->face(5), $tablet)->assertCreated()->json('verification.token');

        $this->clock('in', $v, $tablet)->assertCreated()->assertJsonPath('workday.position.key', 'trainer_floor');
    }

    public function test_un_entrenador_solo_funcional_no_registra_jornada(): void
    {
        $coach = $this->trainerPerson('1075000003', [TrainerRole::FUNCTIONAL]);
        $tablet = $this->tablet();
        $enrolled = $this->enroll($coach->identity, '1075000003', $this->face(6), $tablet)->assertCreated()
            ->assertJsonPath('allowed_actions', []);

        $this->clock('in', $enrolled->json('verification.token'), $tablet)
            ->assertStatus(403)->assertJsonPath('code', 'workday_not_applicable');
    }

    public function test_una_persona_con_varios_cargos_es_una_sola_identidad_con_una_sola_jornada(): void
    {
        // Entrenadora de planta y funcional que además cubre recepción (16).
        $coach = $this->trainerPerson('1075000004', [TrainerRole::FLOOR, TrainerRole::FUNCTIONAL], 'Laura Méndez');
        StaffProfile::create(['identity_id' => $coach->identity_id, 'full_name' => 'Laura Méndez', 'positions' => ['receptionist'], 'status' => 'active']);
        $tablet = $this->tablet();

        $enrolled = $this->enroll($coach->identity, '1075000004', $this->face(7), $tablet)->assertCreated()
            ->assertJsonPath('employee.capabilities', ['workday' => true, 'classes' => true]);
        $this->assertSame(['trainer_floor', 'trainer_functional', 'receptionist'], array_column($enrolled->json('employee.positions'), 'key'));

        $this->clock('in', $enrolled->json('verification.token'), $tablet)->assertCreated()
            ->assertJsonPath('workday.position.key', 'trainer_floor');
        $this->assertSame(1, TrainerFaceReference::count(), 'Una plantilla por persona.');
    }

    public function test_una_biometria_revocada_no_verifica(): void
    {
        $ana = $this->receptionist(self::ANA);
        $tablet = $this->tablet();
        $this->enroll($ana, self::ANA, $this->face(1), $tablet)->assertCreated();
        $this->postJson("/api/admin/biometrics/identities/{$ana->uuid}/revoke", ['reason' => 'Renuncia voluntaria'], $this->adminWithPermissions(['biometrics.manage']))->assertOk();

        $this->identify(self::ANA, $tablet)->assertJsonPath('employee.biometric.status', 'revoked');
        $this->verifyFace(self::ANA, $this->face(1), $tablet)
            ->assertStatus(409)->assertJsonPath('code', 'biometric_not_enrolled')->assertJsonPath('biometric.status', 'revoked');
    }

    public function test_el_rostro_que_no_coincide_se_rechaza_y_bloquea_tras_cinco_intentos(): void
    {
        $ana = $this->receptionist(self::ANA);
        $tablet = $this->tablet();
        $this->enroll($ana, self::ANA, $this->face(1), $tablet)->assertCreated();

        for ($i = 0; $i < 5; $i++) {
            $this->verifyFace(self::ANA, $this->face(50 + $i), $tablet)->assertStatus(422)->assertJsonPath('code', 'face_no_match');
        }
        // Bloqueada: ni su propia cara pasa hasta que se cumpla el tiempo.
        $this->verifyFace(self::ANA, $this->face(1), $tablet)->assertStatus(429)->assertJsonPath('code', 'face_attempts_exceeded');
    }

    public function test_un_vector_que_no_es_un_rostro_se_rechaza(): void
    {
        $ana = $this->receptionist(self::ANA);
        $tablet = $this->tablet();
        $this->enroll($ana, self::ANA, $this->face(1), $tablet)->assertCreated();

        $this->signed('POST', '/api/kiosk/verify', ['employee_ref' => $this->ref(self::ANA, $tablet), 'purpose' => 'workday', 'embedding' => array_fill(0, 192, 0.0)], $tablet)
            ->assertStatus(422)->assertJsonPath('code', 'embedding_invalid');
    }

    public function test_el_respaldo_con_codigo_de_administrador_registra_una_operacion(): void
    {
        $ana = $this->receptionist(self::ANA);
        $tablet = $this->tablet();
        $code = $this->postJson("/api/admin/workforce/staff/{$ana->uuid}/fallback-code", ['purpose' => 'workday', 'reason' => 'La cámara de la tablet no enfoca'], $this->adminWithPermissions(['workforce.manage']))
            ->assertCreated()->json('code');

        $v = $this->signed('POST', '/api/kiosk/verify-code', ['employee_ref' => $this->ref(self::ANA, $tablet), 'purpose' => 'workday', 'code' => $code], $tablet)
            ->assertOk()->assertJsonPath('verification.method', 'admin_code')->json('verification.token');
        $this->clock('in', $v, $tablet)->assertCreated()->assertJsonPath('workday.clock_in.method', 'admin_code');

        // Un solo uso: el segundo intento se rechaza y dice que ya se usó.
        $this->signed('POST', '/api/kiosk/verify-code', ['employee_ref' => $this->ref(self::ANA, $tablet), 'purpose' => 'workday', 'code' => $code], $tablet)
            ->assertStatus(422)->assertJsonPath('code', 'authorization_code_used');
    }

    public function test_el_documento_identifica_pero_no_registra_nada(): void
    {
        // 35-36: con el documento se sabe A QUIÉN verificar; sin rostro o
        // código no hay verificación y sin verificación no hay jornada.
        $this->receptionist(self::ANA);
        $tablet = $this->tablet();
        $this->identify(self::ANA, $tablet)->assertOk();

        $this->clock('in', 'vt_inventado', $tablet)->assertStatus(401)->assertJsonPath('code', 'verification_invalid');
        $this->identify('9999999', $tablet)->assertStatus(404)->assertJsonPath('code', 'employee_not_found');
        $this->assertSame(0, Workday::count());
    }

    // ── 48-49: concurrencia e integridad en la base

    public function test_la_base_impide_dos_jornadas_abiertas_de_la_misma_persona(): void
    {
        $ana = $this->receptionist(self::ANA);
        $fila = ['identity_id' => $ana->id, 'position' => 'receptionist', 'status' => 'open', 'business_date' => '2026-10-12', 'clock_in_at' => now(), 'clock_in_method' => 'face'];
        Workday::create($fila);

        $this->expectException(UniqueConstraintViolationException::class);
        Workday::create($fila);
    }

    public function test_dos_entradas_simultaneas_dejan_una_jornada(): void
    {
        // Ya abierta ANTES de entrar: el SELECT … FOR UPDATE la ve.
        $ana = $this->receptionist(self::ANA);
        $tablet = $this->tablet();
        $v = $this->enroll($ana, self::ANA, $this->face(1), $tablet)->json('verification.token');
        Workday::create(['identity_id' => $ana->id, 'position' => 'receptionist', 'status' => 'open', 'business_date' => '2026-10-12', 'clock_in_at' => now(), 'clock_in_method' => 'face']);

        $this->clock('in', $v, $tablet)->assertStatus(409)->assertJsonPath('code', 'workday_already_open');
        $this->assertSame(1, Workday::count());
    }

    public function test_la_carrera_real_la_decide_el_indice_unico_y_responde_409(): void
    {
        // La otra tablet confirma su entrada DESPUÉS del SELECT … FOR UPDATE de
        // esta (que no ve nada) y ANTES de su INSERT: lo único que la detiene es
        // el índice único parcial. Se simula con la fila competidora insertada
        // justo antes del INSERT, y visible tras el rollback (la confirmó otra
        // conexión), que es lo que pasa en PostgreSQL.
        $ana = $this->receptionist(self::ANA);
        $tablet = $this->tablet();
        $v = $this->enroll($ana, self::ANA, $this->face(1), $tablet)->json('verification.token');
        $competidora = fn (): array => [
            'uuid' => '00000000-0000-4000-8000-00000000c0de', 'identity_id' => $ana->id, 'position' => 'receptionist',
            'status' => 'open', 'business_date' => '2026-10-12', 'clock_in_at' => now(), 'clock_in_method' => 'face',
            'created_at' => now(), 'updated_at' => now(),
        ];
        $insertada = false;
        Workday::creating(function () use (&$insertada, $competidora): void {
            if (! $insertada) {
                $insertada = true;
                DB::table('workdays')->insert($competidora());
            }
        });
        $confirmada = false;
        Event::listen(TransactionRolledBack::class, function () use (&$confirmada, $competidora): void {
            if (! $confirmada) {
                $confirmada = true;
                DB::table('workdays')->insert($competidora());
            }
        });

        $this->clock('in', $v, $tablet)->assertStatus(409)->assertJsonPath('code', 'workday_already_open')
            ->assertJsonPath('workday.id', '00000000-0000-4000-8000-00000000c0de');
        $this->assertTrue($insertada && $confirmada, 'La carrera no llegó al índice único.');
        $this->assertSame(1, Workday::count());
        $this->assertSame(0, WorkdayEvent::count(), 'Nada de esta entrada queda en el historial.');
        // La verificación no se gastó: la persona puede reintentar (p. ej. salir).
        $this->assertSame(0, WorkforceKioskToken::query()->where('kind', WorkforceKioskToken::KIND_VERIFICATION)->whereNotNull('consumed_at')->count());
    }

    public function test_la_base_exige_integridad_referencial(): void
    {
        // Lo garantiza el motor, no solo la aplicación: ni jornadas de nadie,
        // ni historial suelto, ni borrar a quien tiene registro laboral.
        $ana = $this->receptionist(self::ANA);
        $tablet = $this->tablet();
        $this->clock('in', $this->enroll($ana, self::ANA, $this->face(1), $tablet)->json('verification.token'), $tablet)->assertCreated();
        $fila = fn (array $cambios): array => $cambios + [
            'uuid' => (string) Str::uuid(), 'identity_id' => $ana->id, 'position' => 'receptionist', 'status' => 'closed',
            'business_date' => '2026-10-12', 'clock_in_at' => now(), 'clock_in_method' => 'face', 'created_at' => now(), 'updated_at' => now(),
        ];

        $rechaza = function (callable $escritura, string $que): void {
            try {
                DB::transaction($escritura);
                $this->fail("La base aceptó {$que}.");
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        };
        $rechaza(fn () => DB::table('workdays')->insert($fila(['identity_id' => 999_999])), 'una jornada de una identidad inexistente');
        $rechaza(fn () => DB::table('workdays')->insert($fila(['clock_in_device_id' => 999_999])), 'una jornada de un dispositivo inexistente');
        $rechaza(fn () => DB::table('workday_events')->insert([
            'uuid' => (string) Str::uuid(), 'workday_id' => 999_999, 'identity_id' => $ana->id, 'type' => 'clock_in',
            'occurred_at' => now(), 'actor_type' => 'employee', 'created_at' => now(),
        ]), 'historial de una jornada inexistente');
        $rechaza(fn () => DB::table('identities')->where('id', $ana->id)->delete(), 'borrar a una persona con registro laboral');
        $rechaza(fn () => DB::table('authorized_devices')->delete(), 'borrar un dispositivo con jornadas (se revoca, no se borra)');

        $this->assertSame(1, Workday::count());
        $this->assertSame(1, Identity::query()->whereKey($ana->id)->count());
    }

    public function test_el_historial_de_jornada_es_inmutable(): void
    {
        $ana = $this->receptionist(self::ANA);
        $tablet = $this->tablet();
        $this->clock('in', $this->enroll($ana, self::ANA, $this->face(1), $tablet)->json('verification.token'), $tablet)->assertCreated();
        $evento = WorkdayEvent::query()->firstOrFail();

        $this->expectException(\LogicException::class);
        $evento->update(['method' => 'admin']);
    }

    public function test_cada_movimiento_publica_su_hecho_sin_datos_personales(): void
    {
        $ana = $this->receptionist(self::ANA);
        $tablet = $this->tablet();
        $this->clock('in', $this->enroll($ana, self::ANA, $this->face(1), $tablet)->json('verification.token'), $tablet)->assertCreated();

        $hecho = ClassEvent::query()->where('type', 'workday.updated')->firstOrFail();
        $this->assertSame('clock_in', $hecho->reason);
        $this->assertSame('workforce.view', $hecho->audience);
        $this->assertSame(Workday::query()->firstOrFail()->uuid, $hecho->subject);
        $this->assertNull($hecho->class_id);
    }

    public function test_una_entrada_rechazada_no_publica_nada(): void
    {
        $ana = $this->receptionist(self::ANA);
        $tablet = $this->tablet();
        $first = $this->enroll($ana, self::ANA, $this->face(1), $tablet);
        $this->clock('in', $first->json('verification.token'), $tablet)->assertCreated();
        $antes = ClassEvent::query()->where('type', 'workday.updated')->count();

        $again = $this->verifyFace(self::ANA, $this->face(1), $tablet);
        $this->clock('in', $again->json('verification.token'), $tablet)->assertStatus(409);

        $this->assertSame($antes, ClassEvent::query()->where('type', 'workday.updated')->count());
    }

    public function test_fallar_el_login_facial_publico_no_deja_a_nadie_sin_rostro_en_la_tablet(): void
    {
        // El login facial es público y el trainer_id, secuencial: si compartiera
        // el contador del kiosko, cualquiera bloquearía la jornada y las clases
        // de todos los entrenadores fallando cinco veces por cada uno.
        config(['trainer.flags.trainer_auth_enabled' => true, 'trainer.face.login_enabled' => true]);
        $coach = $this->floorTrainer('1075000002');
        $tablet = $this->tablet();
        $this->enroll($coach->identity, '1075000002', $this->face(3), $tablet)->assertCreated();
        $ajena = $this->capture($this->face(9), 1);

        $intento = fn (string $ip, array $rostro) => $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson('/api/trainer/auth/face/login', ['trainer_id' => $coach->id, 'embedding' => $rostro]);
        // Cinco fallos repartidos entre IPs distintas: rotar direcciones no da
        // intentos de más.
        foreach (['203.0.113.7', '203.0.113.8', '2001:db8::1', '2001:db8::2', '198.51.100.4'] as $ip) {
            $intento($ip, $ajena)->assertStatus(422)->assertJsonPath('code', 'face_no_match');
        }
        $intento('2001:db8::3', $ajena)->assertStatus(429)->assertJsonPath('code', 'face_attempts_exceeded');
        // Bloqueado el login público, ni siquiera con su rostro entra por ahí…
        $intento('192.0.2.10', $this->face(3))->assertStatus(429);
        // …pero la tablet del gimnasio no se entera: jornada y clases siguen.
        $this->verifyFace('1075000002', $this->face(3), $tablet)->assertOk()->assertJsonPath('verification.method', 'face');
    }

    public function test_acertar_limpia_el_contador_de_intentos(): void
    {
        // F8: el intento se cuenta antes de comparar y se limpia al acertar.
        $ana = $this->receptionist(self::ANA);
        $tablet = $this->tablet();
        $this->enroll($ana, self::ANA, $this->face(1), $tablet)->assertCreated();

        for ($i = 0; $i < 4; $i++) {
            $this->verifyFace(self::ANA, $this->face(60 + $i), $tablet)->assertStatus(422);
        }
        $this->verifyFace(self::ANA, $this->face(1), $tablet)->assertOk();
        for ($i = 0; $i < 4; $i++) {
            $this->verifyFace(self::ANA, $this->face(70 + $i), $tablet)->assertStatus(422);
        }
        $this->verifyFace(self::ANA, $this->face(1), $tablet)->assertOk();
    }

    public function test_una_ruta_firmada_no_admite_query_string(): void
    {
        // I3: la query no se firma; no puede colarse en una petición legítima.
        $this->receptionist(self::ANA);
        $tablet = $this->tablet();

        $this->signed('POST', '/api/kiosk/employees/identify?document=999', ['document' => self::ANA], $tablet)
            ->assertStatus(401)->assertJsonPath('code', 'device_signature_invalid');
    }
}
