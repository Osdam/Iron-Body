<?php

namespace Tests\Feature\Workforce;

use App\Models\ClassSession;
use App\Models\MyClass;
use App\Models\Trainer;
use App\Models\TrainerDeviceSession;
use App\Models\TrainerFaceReference;
use App\Models\TrainerRole;
use App\Models\Workday;
use App\Models\WorkforceAuthorizationCode;
use App\Services\Trainer\TrainerSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Workforce\Concerns\WorkforceKit;
use Tests\TestCase;

/**
 * Iniciar y finalizar clase con identidad y dispositivo comprobados por el
 * servidor, sin romper la app publicada. Escenarios 12, 13, 15, 17 y la
 * protección de los endpoints antiguos.
 */
class TrainerClassIdentityTest extends TestCase
{
    use RefreshDatabase;
    use WorkforceKit;

    private const LUNES = '2026-10-12';

    private Trainer $coach;

    private string $token;

    private MyClass $clase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'trainer.flags.trainer_auth_enabled' => true,
            'trainer.flags.trainer_classes_enabled' => true,
            'trainer.face.login_enabled' => true,
        ]);
        Carbon::setTestNow(Carbon::parse(self::LUNES.' 08:55', 'America/Bogota')->utc());

        $this->coach = $this->trainerPerson('1075000010', [TrainerRole::FLOOR, TrainerRole::FUNCTIONAL], 'Coach Funcional');
        $this->token = app(TrainerSessionService::class)->issueSession($this->coach, ['device_id' => 'dev_publicada', 'device_name' => 'Android', 'platform' => 'android'])['token'];
        $this->clase = MyClass::create([
            'name' => 'Funcional 9am', 'type' => 'Funcional', 'trainer_id' => $this->coach->id, 'day_of_week' => 'Lunes',
            'start_time' => '09:00', 'end_time' => '10:00', 'max_capacity' => 20, 'status' => 'active',
            'allow_online_booking' => true, 'is_recurring' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function bearer(): array
    {
        return ['Authorization' => 'Bearer '.$this->token];
    }

    private function enrollCoach(): void
    {
        $this->enroll($this->coach->identity, '1075000010', $this->face(20), $this->tablet())->assertCreated();
    }

    // ── App publicada: sigue funcionando, pero se distingue

    public function test_la_app_publicada_sigue_iniciando_y_queda_como_rostro_declarado(): void
    {
        $this->postJson("/api/trainer/classes/{$this->clase->id}/start", ['session_date' => self::LUNES, 'face_verified' => true], $this->bearer())
            ->assertOk()->assertJsonPath('session.start_identity_method', 'legacy_claim');

        $s = ClassSession::query()->firstOrFail();
        $this->assertNull($s->start_device_id);
        $this->assertSame('Android', $s->start_declared_device);
        $this->assertTrue($s->start_face_verified);

        $this->getJson('/api/admin/class-sessions?from='.self::LUNES.'&to='.self::LUNES, $this->adminWithPermissions(['classes.view']))
            ->assertOk()
            ->assertJsonPath('data.0.start_device', ['id' => null, 'name' => 'Android', 'verified' => false])
            ->assertJsonPath('data.0.start_identity_method', 'legacy_claim')
            ->assertJsonPath('data.0.end_device', null);
    }

    public function test_cerrar_la_transicion_exige_prueba_real(): void
    {
        config(['workforce.legacy.face_claim' => false]);

        $this->postJson("/api/trainer/classes/{$this->clase->id}/start", ['session_date' => self::LUNES, 'face_verified' => true], $this->bearer())
            ->assertStatus(422)->assertJsonPath('code', 'face_required');
        $this->assertSame(0, ClassSession::count());
    }

    // ── Rostro comparado en el servidor + dispositivo firmado (12, 13)

    public function test_iniciar_y_finalizar_con_rostro_y_tablet_autorizada(): void
    {
        $this->enrollCoach();
        $tablet = $this->tablet();

        $this->signed('POST', "/api/trainer/classes/{$this->clase->id}/start", ['session_date' => self::LUNES, 'embedding' => $this->capture($this->face(20), 7), 'face_verified' => true], $tablet, ['Authorization' => 'Bearer '.$this->token])
            ->assertOk()->assertJsonPath('session.start_identity_method', 'face');

        Carbon::setTestNow(Carbon::parse(self::LUNES.' 10:02', 'America/Bogota')->utc());
        $otra = $this->tablet('principal', 'Tablet Principal 02');
        $this->signed('POST', "/api/trainer/classes/{$this->clase->id}/end", ['session_date' => self::LUNES, 'embedding' => $this->capture($this->face(20), 8), 'face_verified' => true], $otra, ['Authorization' => 'Bearer '.$this->token])
            ->assertOk()->assertJsonPath('session.end_identity_method', 'face');

        $fila = $this->getJson('/api/admin/class-sessions?from='.self::LUNES.'&to='.self::LUNES, $this->adminWithPermissions(['classes.view']))->assertOk();
        $this->assertSame('Tablet Recepción 01', $fila->json('data.0.start_device.name'));
        $this->assertTrue($fila->json('data.0.start_device.verified'));
        $this->assertSame('Tablet Principal 02', $fila->json('data.0.end_device.name'));
        $this->assertIsInt($fila->json('events_cursor'));
    }

    public function test_un_codigo_de_enrolamiento_vivo_no_bloquea_el_rostro_valido_en_clase(): void
    {
        // Caso real del 19-10-2026: un código de enrolamiento emitido «para la
        // clase» dejaba el rostro bueno en renovación y la clase solo entraba
        // con el código de respaldo.
        $this->enrollCoach();
        $tablet = $this->tablet();
        $this->postJson("/api/admin/biometrics/identities/{$this->coach->identity->uuid}/enrollment-code", ['reason' => 'Clase'], $this->adminWithPermissions(['biometrics.manage']))
            ->assertCreated();
        $this->identify('1075000010', $tablet)->assertJsonPath('employee.biometric.status', 'renewal_pending')
            ->assertJsonPath('employee.biometric.verifiable', true);

        $this->signed('POST', "/api/trainer/classes/{$this->clase->id}/start", ['session_date' => self::LUNES, 'embedding' => $this->capture($this->face(20), 3)], $tablet, ['Authorization' => 'Bearer '.$this->token])
            ->assertOk()->assertJsonPath('session.start_identity_method', 'face');
        Carbon::setTestNow(Carbon::parse(self::LUNES.' 10:02', 'America/Bogota')->utc());
        $this->signed('POST', "/api/trainer/classes/{$this->clase->id}/end", ['session_date' => self::LUNES, 'embedding' => $this->capture($this->face(20), 4)], $tablet, ['Authorization' => 'Bearer '.$this->token])
            ->assertOk()->assertJsonPath('session.end_identity_method', 'face');

        // Y un rostro ajeno sigue sin pasar aunque haya renovación autorizada.
        $this->signed('POST', "/api/trainer/classes/{$this->clase->id}/start", ['session_date' => self::LUNES, 'embedding' => $this->face(21)], $tablet, $this->bearer())
            ->assertStatus(422)->assertJsonPath('code', 'face_no_match');
    }

    // ── Solo la tablet opera clases, y solo con rostro (regla 2026-10-20)

    public function test_un_telefono_no_puede_presentarse_como_tablet_mandando_un_rostro(): void
    {
        $this->enrollCoach();

        // El vector del rostro correcto, pero sin la firma de un equipo autorizado.
        $this->postJson("/api/trainer/classes/{$this->clase->id}/start", ['session_date' => self::LUNES, 'embedding' => $this->capture($this->face(20), 3)], $this->bearer())
            ->assertStatus(403)->assertJsonPath('code', 'device_not_authorized');
        $this->assertSame(0, ClassSession::count());
    }

    public function test_por_defecto_ninguna_clase_se_opera_con_codigo(): void
    {
        $admin = $this->adminWithPermissions(['workforce.manage', 'trainers.manage']);
        $tablet = $this->tablet();

        // El CRM no emite un código que el servidor rechazaría…
        $this->postJson("/api/admin/workforce/staff/{$this->coach->identity->uuid}/fallback-code", ['purpose' => 'class', 'reason' => 'La cámara no enfoca hoy'], $admin)
            ->assertStatus(422)->assertJsonPath('code', 'class_fallback_disabled');
        // …ni la clase ni el portal de la tablet aceptan uno.
        $this->signed('POST', "/api/trainer/classes/{$this->clase->id}/start", ['session_date' => self::LUNES, 'fallback_code' => '12345678'], $tablet, $this->bearer())
            ->assertStatus(422)->assertJsonPath('code', 'class_fallback_disabled');
        $this->signed('POST', '/api/kiosk/verify-code', ['employee_ref' => $this->ref('1075000010', $tablet), 'purpose' => 'trainer_session', 'code' => '12345678'], $tablet)
            ->assertStatus(422)->assertJsonPath('code', 'class_fallback_disabled');
        $this->assertSame(0, ClassSession::count());
        $this->assertSame(0, WorkforceAuthorizationCode::query()->where('purpose', WorkforceAuthorizationCode::PURPOSE_FALLBACK_CLASS)->count());

        // La contingencia de la jornada sigue existiendo (supervisada y auditada).
        $this->postJson("/api/admin/workforce/staff/{$this->coach->identity->uuid}/fallback-code", ['purpose' => 'workday', 'reason' => 'La cámara no enfoca hoy'], $admin)
            ->assertCreated();
    }

    public function test_quien_ya_tiene_rostro_no_puede_declararlo_desde_el_telefono(): void
    {
        $this->enrollCoach();

        $this->postJson("/api/trainer/classes/{$this->clase->id}/start", ['session_date' => self::LUNES, 'face_verified' => true], $this->bearer())
            ->assertStatus(422)->assertJsonPath('code', 'face_required');
        $this->assertSame(0, ClassSession::count());
    }

    public function test_el_rostro_declarado_caduca_en_la_fecha_limite(): void
    {
        config(['workforce.legacy.face_claim_until' => '2026-10-12']);
        $this->postJson("/api/trainer/classes/{$this->clase->id}/start", ['session_date' => self::LUNES, 'face_verified' => true], $this->bearer())
            ->assertOk()->assertJsonPath('session.start_identity_method', 'legacy_claim');

        config(['workforce.legacy.face_claim_until' => '2026-10-11']);
        Carbon::setTestNow(Carbon::parse(self::LUNES.' 10:02', 'America/Bogota')->utc());
        $this->postJson("/api/trainer/classes/{$this->clase->id}/end", ['session_date' => self::LUNES, 'face_verified' => true], $this->bearer())
            ->assertStatus(422)->assertJsonPath('code', 'face_required');
        // El inicio declarado se conserva tal cual: no se convierte en verificado.
        $this->assertSame('legacy_claim', ClassSession::query()->firstOrFail()->start_identity_method);
    }

    public function test_el_documento_sin_otp_solo_entra_desde_un_equipo_que_ya_usaba(): void
    {
        config(['trainer.otp_dev_bypass' => true]);
        $login = fn (string $device) => $this->postJson('/api/trainer/auth/dev-login', ['document' => '1075000010', 'device_id' => $device]);

        // Quien sabe la cédula, en otro teléfono: no entra (ni se revela si existe).
        $login('telefono-ajeno')->assertStatus(403)->assertJsonPath('code', 'device_not_recognized');
        $this->postJson('/api/trainer/auth/dev-login', ['document' => '9999999999', 'device_id' => 'telefono-ajeno'])
            ->assertStatus(403)->assertJsonPath('code', 'device_not_recognized');

        // Su propio teléfono (ya tenía sesión, aunque la cerrara): entra.
        $propio = app(TrainerSessionService::class)->issueSession($this->coach, ['device_id' => 'telefono-propio'])['session'];
        app(TrainerSessionService::class)->revoke($propio, 'logout');
        $login('telefono-propio')->assertOk();

        // Un equipo que administración cortó no vuelve a entrar por documento.
        app(TrainerSessionService::class)->revokeAll($this->coach, 'revoked_by_admin');
        $login('telefono-propio')->assertStatus(403)->assertJsonPath('code', 'device_not_recognized');
    }

    public function test_la_confianza_anterior_al_corte_exige_confirmar_por_sms(): void
    {
        app(TrainerSessionService::class)->issueSession($this->coach, ['device_id' => 'telefono-propio']);
        $access = fn () => $this->postJson('/api/trainer/auth/access', ['document' => '1075000010', 'device_id' => 'telefono-propio']);

        $access()->assertOk()->assertJsonPath('trusted', true);

        config(['trainer.trusted_device.not_before' => '2026-10-13']);
        $r = $access()->assertOk();
        $this->assertNull($r->json('token'));
        $this->assertNotEmpty($r->json('challenge_id'));
    }

    public function test_un_rostro_que_no_es_el_suyo_no_inicia_la_clase(): void
    {
        $this->enrollCoach();

        $this->signed('POST', "/api/trainer/classes/{$this->clase->id}/start", ['session_date' => self::LUNES, 'embedding' => $this->face(21), 'face_verified' => true], $this->tablet(), $this->bearer())
            ->assertStatus(422)->assertJsonPath('code', 'face_no_match');
        $this->assertSame(0, ClassSession::count());
    }

    public function test_sin_plantilla_el_rostro_real_no_puede_comprobarse(): void
    {
        $this->signed('POST', "/api/trainer/classes/{$this->clase->id}/start", ['session_date' => self::LUNES, 'embedding' => $this->face(20)], $this->tablet(), $this->bearer())
            ->assertStatus(409)->assertJsonPath('code', 'biometric_not_enrolled');
    }

    public function test_respaldo_con_codigo_de_administrador(): void
    {
        // Solo si el propietario enciende la contingencia (apagada por defecto).
        config(['workforce.classes.fallback_code' => true]);
        $code = $this->postJson("/api/admin/workforce/staff/{$this->coach->identity->uuid}/fallback-code", ['purpose' => 'class', 'reason' => 'Cámara dañada en el salón'], $this->adminWithPermissions(['workforce.manage', 'trainers.manage']))
            ->assertCreated()->json('code');

        $this->postJson("/api/trainer/classes/{$this->clase->id}/start", ['session_date' => self::LUNES, 'fallback_code' => $code], $this->bearer())
            ->assertOk()->assertJsonPath('session.start_identity_method', 'admin_code')
            ->assertJsonPath('session.start_face_verified', false);
    }

    public function test_una_firma_presente_pero_falsa_se_rechaza(): void
    {
        $tablet = $this->tablet();

        $this->signed('POST', "/api/trainer/classes/{$this->clase->id}/start", ['session_date' => self::LUNES, 'face_verified' => true], $tablet, ['Authorization' => 'Bearer '.$this->token], signWith: $this->newKey())
            ->assertStatus(401)->assertJsonPath('code', 'device_signature_invalid');
        $this->assertSame(0, ClassSession::count());
    }

    public function test_con_la_politica_en_exigir_un_equipo_sin_firma_no_opera_clases(): void
    {
        config(['workforce.devices.classes_policy' => 'enforce']);

        $this->postJson("/api/trainer/classes/{$this->clase->id}/start", ['session_date' => self::LUNES, 'face_verified' => true], $this->bearer())
            ->assertStatus(403)->assertJsonPath('code', 'device_not_authorized');
    }

    public function test_una_sesion_historica_sin_dato_sale_como_no_registrada(): void
    {
        ClassSession::create(['class_id' => $this->clase->id, 'session_date' => '2026-10-05', 'started_at' => now()->subWeek(), 'started_by' => $this->coach->id, 'start_face_verified' => true]);

        $this->getJson('/api/admin/class-sessions?from=2026-10-05&to=2026-10-05', $this->adminWithPermissions(['classes.view']))
            ->assertOk()->assertJsonPath('data.0.start_device', null)->assertJsonPath('data.0.start_identity_method', null);
    }

    // ── 15, 17: clase y jornada no se pisan

    public function test_la_clase_y_la_jornada_no_comparten_estado(): void
    {
        $this->enrollCoach();
        $tablet = $this->tablet();

        $v = $this->verifyFace('1075000010', $this->face(20), $tablet)->assertOk()->json('verification.token');
        $this->clock('in', $v, $tablet)->assertCreated();

        $this->signed('POST', "/api/trainer/classes/{$this->clase->id}/start", ['session_date' => self::LUNES, 'embedding' => $this->capture($this->face(20), 3)], $tablet, ['Authorization' => 'Bearer '.$this->token])->assertOk();
        Carbon::setTestNow(Carbon::parse(self::LUNES.' 10:05', 'America/Bogota')->utc());
        $this->signed('POST', "/api/trainer/classes/{$this->clase->id}/end", ['session_date' => self::LUNES, 'embedding' => $this->capture($this->face(20), 4)], $tablet, ['Authorization' => 'Bearer '.$this->token])->assertOk();

        // Terminar la clase NO cierra la jornada.
        $this->assertSame(Workday::STATUS_OPEN, Workday::query()->firstOrFail()->status);
        $this->assertNotNull(ClassSession::query()->firstOrFail()->ended_at);
    }

    // ── Portal del entrenador: endpoints antiguos protegidos

    public function test_el_autoenrolamiento_ya_no_sobrescribe_un_rostro(): void
    {
        config(['workforce.legacy.self_enrollment' => 'first_only']);
        $this->postJson('/api/trainer/auth/face/enroll', ['embedding' => $this->face(20)], $this->bearer())->assertOk();
        // Quien obtuvo una sesión con el documento no puede cambiar el rostro por el suyo.
        $this->postJson('/api/trainer/auth/face/enroll', ['embedding' => $this->face(66)], $this->bearer())
            ->assertStatus(409)->assertJsonPath('code', 'face_already_enrolled');

        $ref = TrainerFaceReference::query()->firstOrFail();
        $this->assertSame($this->coach->identity_id, $ref->identity_id);
        $this->assertSame('trainer_portal_legacy', $ref->enrolled_via);
    }

    public function test_tras_revocar_hace_falta_la_autorizacion_de_un_administrador(): void
    {
        config(['workforce.legacy.self_enrollment' => 'first_only']);
        $this->postJson('/api/trainer/auth/face/enroll', ['embedding' => $this->face(20)], $this->bearer())->assertOk();
        $this->deleteJson("/api/admin/trainers/{$this->coach->id}/face", ['reason' => 'Rostro mal registrado'], $this->adminWithPermissions(['biometrics.manage']))->assertOk();

        $this->postJson('/api/trainer/auth/face/enroll', ['embedding' => $this->face(20)], $this->bearer())
            ->assertStatus(409)->assertJsonPath('code', 'enrollment_requires_authorization');
    }

    public function test_por_defecto_el_portal_no_enrola_hace_falta_el_codigo_de_un_administrador(): void
    {
        // Revisión de seguridad 2026-10-11 (F2): con el acceso por documento sin
        // segundo factor, `first_only` dejaba registrar la cara propia en la
        // ficha de un entrenador sin rostro y marcar su jornada.

        $this->postJson('/api/trainer/auth/face/enroll', ['embedding' => $this->face(20)], $this->bearer())
            ->assertStatus(409)->assertJsonPath('code', 'enrollment_requires_authorization');
    }

    public function test_el_autoenrolamiento_tampoco_acepta_un_rostro_ajeno(): void
    {
        config(['workforce.legacy.self_enrollment' => 'first_only']);
        $otro = $this->receptionist('1075000011', 'Recepción Ajena');
        $this->enroll($otro, '1075000011', $this->face(20), $this->tablet())->assertCreated();

        $this->postJson('/api/trainer/auth/face/enroll', ['embedding' => $this->face(20)], $this->bearer())
            ->assertStatus(409)->assertJsonPath('code', 'face_already_linked');
    }

    public function test_el_login_facial_antiguo_no_acepta_una_plantilla_revocada(): void
    {
        $this->enrollCoach();
        $this->postJson('/api/trainer/auth/face/login', ['trainer_id' => $this->coach->id, 'embedding' => $this->capture($this->face(20), 1)])->assertOk();

        $this->deleteJson("/api/admin/trainers/{$this->coach->id}/face", [], $this->adminWithPermissions(['biometrics.manage']))->assertOk();
        $this->postJson('/api/trainer/auth/face/login', ['trainer_id' => $this->coach->id, 'embedding' => $this->capture($this->face(20), 1)])
            ->assertStatus(422)->assertJsonPath('code', 'face_no_match');
        $this->getJson('/api/trainer/auth/face/roster')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_una_plantilla_heredada_sin_consentimiento_queda_pendiente_de_renovacion(): void
    {
        // Decisión 2026-10-11: las 4 plantillas de producción se registraron con
        // sesiones de solo cédula y sin consentimiento. Se conservan, pero no
        // verifican hasta un registro nuevo autorizado.
        config(['workforce.legacy.self_enrollment' => 'first_only']);
        $this->postJson('/api/trainer/auth/face/enroll', ['embedding' => $this->face(20)], $this->bearer())->assertOk();
        $tablet = $this->tablet();

        $this->identify('1075000010', $tablet)->assertJsonPath('employee.biometric.status', 'renewal_pending')
            ->assertJsonPath('employee.biometric.verifiable', false);
        $this->verifyFace('1075000010', $this->face(20), $tablet)
            ->assertStatus(409)->assertJsonPath('code', 'biometric_not_enrolled')->assertJsonPath('biometric.status', 'renewal_pending')
            ->assertJsonPath('biometric.verifiable', false);
        $this->signed('POST', "/api/trainer/classes/{$this->clase->id}/start", ['session_date' => self::LUNES, 'embedding' => $this->face(20)], $tablet, $this->bearer())
            ->assertStatus(409);
        $this->postJson('/api/trainer/auth/face/login', ['trainer_id' => $this->coach->id, 'embedding' => $this->face(20)])->assertStatus(422);
        $this->getJson('/api/trainer/auth/face/roster')->assertJsonCount(0, 'data');

        // Un administrador autoriza el registro nuevo: la MISMA fila se renueva.
        $this->enroll($this->coach->identity, '1075000010', $this->face(20), $tablet)->assertCreated();
        $this->assertSame(1, TrainerFaceReference::count());
        $this->verifyFace('1075000010', $this->face(20), $tablet)->assertOk();
    }

    public function test_borrar_rostro_desde_la_ficha_exige_permiso_de_biometria_y_deja_traza(): void
    {
        config(['workforce.legacy.self_enrollment' => 'first_only']);
        $this->postJson('/api/trainer/auth/face/enroll', ['embedding' => $this->face(20)], $this->bearer())->assertOk();

        $this->deleteJson("/api/admin/trainers/{$this->coach->id}/face", [], $this->adminWithPermissions(['trainers.manage']))->assertForbidden();
        $this->deleteJson("/api/admin/trainers/{$this->coach->id}/face", [], $this->adminWithPermissions(['biometrics.manage']))
            ->assertOk()->assertJsonPath('face_enrolled', false)->assertJsonPath('biometric.status', 'revoked');

        $ref = TrainerFaceReference::query()->firstOrFail();
        $this->assertNull($ref->getRawOriginal('template'), 'Revocar borra la plantilla.');
        $this->assertSame('Eliminado desde la ficha del entrenador', $ref->revoked_reason);
    }

    public function test_cerrar_sesion_la_revoca_en_el_servidor(): void
    {
        $this->postJson('/api/trainer/auth/logout', [], $this->bearer())->assertOk();
        $this->getJson('/api/trainer/auth/me', $this->bearer())->assertStatus(401)->assertJsonPath('code', 'session_revoked');
    }

    public function test_entrar_al_portal_desde_el_kiosko_con_rostro(): void
    {
        $this->enrollCoach();
        $tablet = $this->tablet();

        $token = $this->verifyFace('1075000010', $this->face(20), $tablet, 'trainer_session')->assertOk()
            ->assertJsonMissingPath('verification')
            ->json('trainer_session.token');
        $this->getJson('/api/trainer/auth/me', ['Authorization' => 'Bearer '.$token])->assertOk()
            ->assertJsonPath('trainer.full_name', 'Coach Funcional');
    }

    public function test_quien_no_es_entrenador_no_obtiene_sesion_del_portal(): void
    {
        $ana = $this->receptionist('1075000001');
        $tablet = $this->tablet();
        $this->enroll($ana, '1075000001', $this->face(1), $tablet)->assertCreated();

        $this->verifyFace('1075000001', $this->face(1), $tablet, 'trainer_session')
            ->assertStatus(403)->assertJsonPath('code', 'not_a_trainer');
    }

    public function test_una_sesion_del_kiosko_no_vuelve_la_tablet_de_confianza(): void
    {
        // F1: con la sesión del kiosko viva, declarar el device_id de la tablet
        // en `access` daba una sesión del portal sin segundo factor.
        $this->enrollCoach();
        $tablet = $this->tablet();
        $this->verifyFace('1075000010', $this->face(20), $tablet, 'trainer_session')->assertOk();

        $this->postJson('/api/trainer/auth/access', ['document' => '1075000010', 'device_id' => 'kiosk:'.$tablet['uuid']])
            ->assertOk()->assertJsonMissingPath('token')->assertJsonMissingPath('trusted');
    }

    public function test_un_id_declarado_no_puede_hacerse_pasar_por_la_sesion_de_una_tablet(): void
    {
        config(['trainer.otp_dev_bypass' => true]);
        // Equipo que ese entrenador ya usaba (transición: solo así entra por documento).
        app(TrainerSessionService::class)->issueSession($this->coach, ['device_id' => 'client:kiosk:cualquiera']);
        $this->postJson('/api/trainer/auth/dev-login', ['document' => '1075000010', 'device_id' => 'kiosk:cualquiera'])->assertOk();

        $this->assertSame(0, TrainerDeviceSession::query()->where('device_id', 'kiosk:cualquiera')->count());
        $this->assertSame(1, TrainerDeviceSession::query()->where('device_id', 'client:kiosk:cualquiera')->count());
    }

    public function test_la_sesion_del_portal_en_el_kiosko_caduca_por_inactividad_y_por_hora(): void
    {
        $this->enrollCoach();
        $tablet = $this->tablet();
        $token = $this->verifyFace('1075000010', $this->face(20), $tablet, 'trainer_session')->json('trainer_session.token');
        $auth = ['Authorization' => 'Bearer '.$token];

        $this->getJson('/api/trainer/auth/me', $auth)->assertOk();
        Carbon::setTestNow(now()->addMinutes(16));
        $this->getJson('/api/trainer/auth/me', $auth)->assertStatus(401)->assertJsonPath('code', 'session_expired');

        // Una nueva, usada cada pocos minutos, igual muere a las 12 h.
        $token = $this->verifyFace('1075000010', $this->face(20), $tablet, 'trainer_session')->json('trainer_session.token');
        for ($h = 0; $h <= 12 * 6; $h++) {
            Carbon::setTestNow(now()->addMinutes(10));
            $res = $this->getJson('/api/trainer/auth/me', ['Authorization' => 'Bearer '.$token]);
            if ($res->status() !== 200) {
                break;
            }
        }
        $res->assertStatus(401)->assertJsonPath('code', 'session_expired');
    }

    public function test_la_sesion_del_telefono_no_caduca_ni_pierde_su_confianza(): void
    {
        // Comportamiento de siempre para quien entra desde su teléfono.
        Carbon::setTestNow(now()->addDays(3));
        $this->getJson('/api/trainer/auth/me', $this->bearer())->assertOk();
    }

    public function test_el_interruptor_del_login_facial_apaga_tambien_el_rostro_al_portal_en_el_kiosko(): void
    {
        // I2: TRAINER_FACE_LOGIN_ENABLED=false (mitigación de incidente) no deja
        // abierta la vía nueva rostro → portal.
        $this->enrollCoach();
        config(['trainer.face.login_enabled' => false]);

        $this->verifyFace('1075000010', $this->face(20), $this->tablet(), 'trainer_session')
            ->assertStatus(403)->assertJsonPath('code', 'trainer_face_login_disabled');
        // La jornada no depende de ese interruptor.
        $this->verifyFace('1075000010', $this->face(20), $this->tablet())->assertOk();
    }
}
