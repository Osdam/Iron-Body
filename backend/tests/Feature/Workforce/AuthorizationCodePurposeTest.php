<?php

namespace Tests\Feature\Workforce;

use App\Models\TrainerRole;
use App\Models\ClassSession;
use App\Models\MyClass;
use App\Models\Trainer;
use App\Models\TrainerAuditLog;
use App\Models\WorkforceAuthorizationCode;
use App\Services\Trainer\TrainerSessionService;
use App\Support\Workforce\Secrets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Workforce\Concerns\WorkforceKit;
use Tests\TestCase;

/**
 * CÓDIGOS DE AUTORIZACIÓN: cada uno sirve para UNA operación de UNA persona.
 *
 * Incidente del 16-10-2026: para un entrenador sin rostro se generaron 3
 * códigos de «Registrar la jornada» (la opción marcada por defecto en el CRM)
 * y 3 de «Registrar el rostro», y los 6 se escribieron en el diálogo de
 * INICIAR CLASE. El servidor los rechazó bien —son de otra operación—, pero
 * con el mismo «El código no es válido, caducó o ya se usó» para todo, sin
 * decir para qué servía el código ni dónde se usa, y sin dejar rastro. Nadie
 * pudo saber qué pasaba.
 */
class AuthorizationCodePurposeTest extends TestCase
{
    use RefreshDatabase;
    use WorkforceKit;

    private const LUNES = '2026-10-12';

    private const DOC = '1075000077';

    private Trainer $coach;

    private string $token;

    private MyClass $clase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'trainer.flags.trainer_auth_enabled' => true,
            'trainer.flags.trainer_classes_enabled' => true,
            // Estas pruebas fijan la mecánica de los códigos de clase para
            // cuando el propietario encienda la contingencia; por defecto está
            // apagada (TrainerClassIdentityTest lo prueba).
            'workforce.classes.fallback_code' => true,
        ]);
        Carbon::setTestNow(Carbon::parse(self::LUNES.' 08:55', 'America/Bogota')->utc());
        $this->coach = $this->trainerPerson(self::DOC, [TrainerRole::FLOOR, TrainerRole::FUNCTIONAL], 'Entrenador Sintético');
        $this->token = app(TrainerSessionService::class)->issueSession($this->coach, ['device_id' => 'tablet', 'device_name' => 'Tablet', 'platform' => 'android'])['token'];
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

    // ── Ayudantes ───────────────────────────────────────────────────────────

    private function bearer(): array
    {
        return ['Authorization' => 'Bearer '.$this->token];
    }

    private function enrollmentCode(): string
    {
        return (string) $this->postJson("/api/admin/biometrics/identities/{$this->coach->identity->uuid}/enrollment-code", ['reason' => 'Primer registro del entrenador'], $this->adminWithPermissions(['biometrics.manage']))
            ->assertCreated()->json('code');
    }

    private function fallbackCode(string $purpose): string
    {
        return (string) $this->postJson("/api/admin/workforce/staff/{$this->coach->identity->uuid}/fallback-code", ['purpose' => $purpose, 'reason' => 'La cámara no enfoca hoy'], $this->adminWithPermissions(['workforce.manage', 'trainers.manage']))
            ->assertCreated()->json('code');
    }

    // Desde la tablet autorizada (firmado): el único equipo que opera clases.
    private function start(array $body)
    {
        return $this->signed('POST', "/api/trainer/classes/{$this->clase->id}/start", ['session_date' => self::LUNES] + $body, $this->tablet(), $this->bearer());
    }

    private function end(array $body)
    {
        return $this->signed('POST', "/api/trainer/classes/{$this->clase->id}/end", ['session_date' => self::LUNES] + $body, $this->tablet(), $this->bearer());
    }

    /** @return array<string,int> propósito => intentos del código vivo */
    private function attempts(): array
    {
        return WorkforceAuthorizationCode::query()->where('identity_id', $this->coach->identity_id)
            ->whereNull('consumed_at')->whereNull('revoked_at')->pluck('attempts', 'purpose')->all();
    }

    // ── 1. La secuencia real del incidente ──────────────────────────────────

    public function test_reproduce_el_incidente_codigos_de_otra_operacion_en_iniciar_clase(): void
    {
        $rostro = $this->enrollmentCode();
        $jornada = $this->fallbackCode('workday');

        // Sin rostro: el servidor pide el respaldo (409), como en la tablet.
        $this->start(['embedding' => $this->face(30)])->assertStatus(409)->assertJsonPath('code', 'biometric_not_enrolled');

        foreach ([$rostro, $jornada] as $codigo) {
            $r = $this->start(['fallback_code' => $codigo])->assertStatus(422);
            // Ya no es el genérico: dice que no hay código de CLASES y para qué
            // sirve el que sí hay, y dónde se escribe.
            $r->assertJsonPath('code', 'authorization_code_not_issued');
            $this->assertStringContainsString('iniciar o finalizar clases', $r->json('message'));
            $this->assertStringContainsString('registrar el rostro', $r->json('message'));
            $this->assertStringContainsString('Registrar mi rostro', $r->json('message'));
            $this->assertStringContainsString('registrar la jornada', $r->json('message'));
        }

        // Ningún código se gastó ni sumó intentos: siguen valiendo para lo suyo.
        $this->assertSame(['enrollment' => 0, 'fallback_workday' => 0], $this->attempts());
        $this->assertSame(0, ClassSession::count());

        // Y queda rastro en el historial de biometría que ve el CRM.
        $historial = $this->getJson("/api/admin/biometrics/identities/{$this->coach->identity->uuid}", $this->adminWithPermissions(['biometrics.view']))
            ->assertOk()->json('data.history');
        $rechazos = array_values(array_filter($historial, fn ($h) => $h['event'] === 'biometric.code_rejected'));
        $this->assertCount(2, $rechazos);
        $this->assertSame('Código rechazado', $rechazos[0]['label']);
        $this->assertStringContainsString('iniciar o finalizar clases', $rechazos[0]['detail']);
    }

    public function test_el_rechazo_no_guarda_ni_el_codigo_ni_su_huella(): void
    {
        $codigo = $this->enrollmentCode();
        $this->start(['fallback_code' => $codigo])->assertStatus(422);

        $log = TrainerAuditLog::query()->where('event', 'biometric.code_rejected')->sole();
        $guardado = json_encode($log->metadata);
        $this->assertStringNotContainsString($codigo, $guardado);
        $this->assertStringNotContainsString(WorkforceAuthorizationCode::query()->value('code_hash'), $guardado);
    }

    /**
     * Revisión de seguridad: cada rechazo escribía una fila sin límite, y el
     * historial del CRM enseña las 50 últimas. Repetir un código falso desde
     * una sesión de entrenador (o una tablet) tapaba «Código generado» o
     * «Rostro registrado». Como los fallos de rostro: 5 por persona, operación
     * y motivo cada 15 minutos. La respuesta a la tablet no cambia.
     */
    public function test_los_rechazos_repetidos_no_tapan_el_historial(): void
    {
        $this->enrollmentCode();
        for ($i = 0; $i < 8; $i++) {
            $this->start(['fallback_code' => '12345678'])->assertStatus(422)->assertJsonPath('code', 'authorization_code_not_issued');
        }

        $this->assertSame(5, TrainerAuditLog::query()->where('event', 'biometric.code_rejected')->count());
        $historial = collect($this->getJson("/api/admin/biometrics/identities/{$this->coach->identity->uuid}", $this->adminWithPermissions(['biometrics.view']))
            ->assertOk()->json('data.history'));
        $this->assertTrue($historial->contains('event', 'biometric.code_issued'));

        // Pasada la ventana, vuelve a anotarse.
        Carbon::setTestNow(Carbon::now()->addMinutes(16));
        $this->start(['fallback_code' => '12345678'])->assertStatus(422);
        $this->assertSame(6, TrainerAuditLog::query()->where('event', 'biometric.code_rejected')->count());
    }

    // ── 2. El camino correcto: rostro en el kiosko y clase sin código ──────

    public function test_registrar_el_rostro_y_despues_iniciar_y_finalizar_la_clase_sin_codigo(): void
    {
        $this->enroll($this->coach->identity, self::DOC, $this->face(30), $this->tablet())->assertCreated();

        $this->start(['embedding' => $this->capture($this->face(30), 5)])->assertOk()->assertJsonPath('session.start_identity_method', 'face');
        Carbon::setTestNow(Carbon::parse(self::LUNES.' 10:01', 'America/Bogota')->utc());
        $this->end(['embedding' => $this->capture($this->face(30), 6)])->assertOk()->assertJsonPath('session.end_identity_method', 'face');
        $this->assertSame(0, WorkforceAuthorizationCode::query()->where('purpose', 'fallback_class')->count());
    }

    public function test_renovar_el_rostro_con_un_codigo_nuevo(): void
    {
        $this->enroll($this->coach->identity, self::DOC, $this->face(30), $this->tablet())->assertCreated();
        // Un código de rostro vivo pone la plantilla en renovación…
        $this->enroll($this->coach->identity, self::DOC, $this->face(31), $this->tablet())->assertCreated();
        // …y la nueva es la que vale.
        $this->start(['embedding' => $this->capture($this->face(31), 5)])->assertOk();
    }

    public function test_el_codigo_de_rostro_se_gasta_al_empezar_el_registro(): void
    {
        $codigo = $this->enrollmentCode();
        $tablet = $this->tablet();
        $this->signed('POST', '/api/kiosk/enrollment/start', ['employee_ref' => $this->ref(self::DOC, $tablet), 'authorization_code' => $codigo], $tablet)->assertOk();

        $r = $this->signed('POST', '/api/kiosk/enrollment/start', ['employee_ref' => $this->ref(self::DOC, $tablet), 'authorization_code' => $codigo], $tablet)->assertStatus(422);
        $r->assertJsonPath('code', 'authorization_code_used');
        $this->assertStringContainsString('ya se usó', $r->json('message'));
    }

    // ── 3. Código de clase ──────────────────────────────────────────────────

    public function test_codigo_de_clase_inicia_y_otro_finaliza(): void
    {
        $this->start(['fallback_code' => $this->fallbackCode('class')])->assertOk()->assertJsonPath('session.start_identity_method', 'admin_code');
        Carbon::setTestNow(Carbon::parse(self::LUNES.' 10:01', 'America/Bogota')->utc());
        $this->end(['fallback_code' => $this->fallbackCode('class')])->assertOk()->assertJsonPath('session.end_identity_method', 'admin_code');
    }

    public function test_un_codigo_de_clase_no_sirve_dos_veces(): void
    {
        $codigo = $this->fallbackCode('class');
        $this->start(['fallback_code' => $codigo])->assertOk();
        Carbon::setTestNow(Carbon::parse(self::LUNES.' 10:01', 'America/Bogota')->utc());

        $this->end(['fallback_code' => $codigo])->assertStatus(422)->assertJsonPath('code', 'authorization_code_used');
        $this->assertNull(ClassSession::query()->sole()->ended_at);
    }

    public function test_codigo_de_clase_caducado(): void
    {
        $codigo = $this->fallbackCode('class');
        Carbon::setTestNow(Carbon::now()->addMinutes(31));

        $r = $this->start(['fallback_code' => $codigo])->assertStatus(422)->assertJsonPath('code', 'authorization_code_expired');
        $this->assertStringContainsString('caducó', $r->json('message'));
        $this->assertSame(0, ClassSession::count());
    }

    public function test_numero_equivocado_con_un_codigo_de_clase_vigente_cuenta_intento(): void
    {
        $codigo = $this->fallbackCode('class');
        $otro = str_pad((string) (((int) $codigo + 1) % 100_000_000), 8, '0', STR_PAD_LEFT);

        $this->start(['fallback_code' => $otro])->assertStatus(422)->assertJsonPath('code', 'authorization_code_invalid');
        $this->assertSame(['fallback_class' => 1], $this->attempts());
        // El bueno sigue sirviendo.
        $this->start(['fallback_code' => $codigo])->assertOk();
    }

    public function test_cinco_intentos_fallidos_matan_el_codigo(): void
    {
        $codigo = $this->fallbackCode('class');
        $otro = str_pad((string) (((int) $codigo + 1) % 100_000_000), 8, '0', STR_PAD_LEFT);
        for ($i = 0; $i < 5; $i++) {
            $this->start(['fallback_code' => $otro]);
        }

        $this->start(['fallback_code' => $codigo])->assertStatus(429)->assertJsonPath('code', 'authorization_code_attempts_exceeded');
        $this->assertSame(0, ClassSession::count());
    }

    public function test_el_codigo_de_otra_persona_no_sirve(): void
    {
        $otro = $this->trainerPerson('1075000088', [TrainerRole::FUNCTIONAL], 'Otro Entrenador');
        $codigoDelOtro = (string) $this->postJson("/api/admin/workforce/staff/{$otro->identity->uuid}/fallback-code", ['purpose' => 'class', 'reason' => 'Prueba de otra persona'], $this->adminWithPermissions(['workforce.manage', 'trainers.manage']))
            ->assertCreated()->json('code');

        $this->start(['fallback_code' => $codigoDelOtro])->assertStatus(422)->assertJsonPath('code', 'authorization_code_not_issued');
        $this->assertSame(0, ClassSession::count());
        // Y al otro le sigue sirviendo, sin intentos gastados.
        $this->assertSame(0, WorkforceAuthorizationCode::query()->where('identity_id', $otro->identity_id)->value('attempts'));
    }

    public function test_un_codigo_con_ceros_a_la_izquierda(): void
    {
        $codigo = '00412345';
        WorkforceAuthorizationCode::create([
            'identity_id' => $this->coach->identity_id,
            'purpose' => WorkforceAuthorizationCode::PURPOSE_FALLBACK_CLASS,
            'code_hash' => Secrets::hash(WorkforceAuthorizationCode::PURPOSE_FALLBACK_CLASS.'|'.$this->coach->identity_id.'|'.$codigo),
            'created_by_name' => 'Admin QA',
            'expires_at' => now()->addMinutes(30),
        ]);

        // Como lo escribe la tablet (solo dígitos) y como lo enseña el CRM (agrupado).
        $this->start(['fallback_code' => '0041 2345'])->assertOk();
    }

    public function test_el_codigo_se_gasta_una_sola_vez_aunque_se_envie_dos_veces(): void
    {
        $codigo = $this->fallbackCode('class');
        $this->start(['fallback_code' => $codigo])->assertOk();
        $this->start(['fallback_code' => $codigo])->assertStatus(422)->assertJsonPath('code', 'authorization_code_used');
        $this->assertSame(1, WorkforceAuthorizationCode::query()->whereNotNull('consumed_at')->count());
        $this->assertSame(1, ClassSession::count());
    }

    // ── 4. Jornada (entrada y salida) ───────────────────────────────────────

    public function test_codigo_de_jornada_marca_entrada_y_otro_la_salida(): void
    {
        $tablet = $this->tablet();
        foreach (['in', 'out'] as $accion) {
            $codigo = $this->fallbackCode('workday');
            $v = $this->signed('POST', '/api/kiosk/verify-code', ['employee_ref' => $this->ref(self::DOC, $tablet), 'purpose' => 'workday', 'code' => $codigo], $tablet)->assertOk();
            $this->clock($accion, (string) $v->json('verification.token'), $tablet)->assertSuccessful();
        }
    }

    public function test_codigo_de_rostro_en_el_respaldo_de_la_jornada_explica_donde_va(): void
    {
        $tablet = $this->tablet();
        $codigo = $this->enrollmentCode();

        $r = $this->signed('POST', '/api/kiosk/verify-code', ['employee_ref' => $this->ref(self::DOC, $tablet), 'purpose' => 'workday', 'code' => $codigo], $tablet)
            ->assertStatus(422)->assertJsonPath('code', 'authorization_code_not_issued');
        $this->assertStringContainsString('registrar la jornada', $r->json('message'));
        $this->assertStringContainsString('Registrar mi rostro', $r->json('message'));
        $this->assertSame(['enrollment' => 0], $this->attempts());
    }

    public function test_codigo_de_clase_no_registra_la_jornada(): void
    {
        $tablet = $this->tablet();
        $this->signed('POST', '/api/kiosk/verify-code', ['employee_ref' => $this->ref(self::DOC, $tablet), 'purpose' => 'workday', 'code' => $this->fallbackCode('class')], $tablet)
            ->assertStatus(422)->assertJsonPath('code', 'authorization_code_not_issued');
    }

    // ── 5. Permisos para generar ────────────────────────────────────────────

    public function test_generar_cada_codigo_exige_su_permiso(): void
    {
        $uuid = $this->coach->identity->uuid;
        $this->postJson("/api/admin/biometrics/identities/{$uuid}/enrollment-code", ['reason' => 'Sin permiso de biometría'], $this->adminWithPermissions(['workforce.manage']))->assertForbidden();
        $this->postJson("/api/admin/workforce/staff/{$uuid}/fallback-code", ['purpose' => 'class', 'reason' => 'Sin permiso de entrenadores'], $this->adminWithPermissions(['workforce.manage']))->assertForbidden();
        $this->postJson("/api/admin/workforce/staff/{$uuid}/fallback-code", ['purpose' => 'workday', 'reason' => 'Sin permiso de personal'], $this->adminWithPermissions(['classes.view']))->assertForbidden();
        $this->assertSame(0, WorkforceAuthorizationCode::count());
    }
}
