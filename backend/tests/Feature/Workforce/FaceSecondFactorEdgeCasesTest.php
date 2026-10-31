<?php

namespace Tests\Feature\Workforce;

use App\Models\AuditLog;
use App\Models\ClassSession;
use App\Models\FaceLookalike;
use App\Models\FaceLookalikeException;
use App\Models\Identity;
use App\Models\MyClass;
use App\Models\StaffProfile;
use App\Models\Trainer;
use App\Models\TrainerAuditLog;
use App\Models\TrainerFaceReference;
use App\Models\TrainerRole;
use App\Models\WorkdayEvent;
use App\Models\WorkforcePin;
use App\Services\Trainer\TrainerSessionService;
use App\Support\Workforce\FaceVector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Workforce\Concerns\WorkforceKit;
use Tests\TestCase;

/**
 * Casos límite del segundo factor biométrico (contrato §10) que
 * FaceSecondFactorTest no cubre: bordes del bloqueo del PIN y su ciclo de vida,
 * el contador facial frente al del PIN, plantillas heredadas en la regla de
 * ambigüedad, el login facial público, el estado del PIN en el kiosko, el
 * historial del CRM y lo que NUNCA debe salir (PIN, plantillas ajenas,
 * distancias ajenas).
 *
 * Mismas convenciones que FaceSecondFactorTest: geometría exacta (cada prueba
 * comprueba sus premisas de distancia), reloj en UTC, tablet con firma EC real.
 *
 * Lo que NO demuestran: concurrencia (RefreshDatabase = una conexión; las
 * carreras se probaron aparte contra PostgreSQL con procesos reales) ni cómo se
 * reparten las distancias de rostros reales de MobileFaceNet.
 */
class FaceSecondFactorEdgeCasesTest extends TestCase
{
    use RefreshDatabase;
    use WorkforceKit;

    private const LUNES = '2026-10-12';

    private const JUAN_DOC = '1075000005';

    private const CAMILO_DOC = '1075305903';

    private const JUAN_PIN = '482913';

    private const CAMILO_PIN = '739164';

    private const WRONG_PIN = '135792';

    private Trainer $juan;

    private Trainer $camilo;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'trainer.flags.trainer_auth_enabled' => true,
            'trainer.flags.trainer_classes_enabled' => true,
        ]);
        Carbon::setTestNow(Carbon::parse(self::LUNES.' 08:55', 'America/Bogota')->utc());
        $this->juan = $this->trainerPerson(self::JUAN_DOC, [TrainerRole::FLOOR, TrainerRole::FUNCTIONAL], 'Juan Entrenador');
        $this->camilo = $this->trainerPerson(self::CAMILO_DOC, [TrainerRole::FLOOR, TrainerRole::FUNCTIONAL], 'Camilo López');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── Geometría ───────────────────────────────────────────────────────────

    /** Vector unitario de 192 con las primeras componentes dadas. */
    private function v(float ...$lead): array
    {
        $out = array_fill(0, 192, 0.0);
        foreach ($lead as $i => $x) {
            $out[$i] = $x;
        }
        $n = sqrt(array_sum(array_map(fn ($x) => $x * $x, $out)));

        return array_map(fn ($x) => $x / $n, $out);
    }

    /** Un vector a distancia $d de Juan (el eje 0), en el plano de los ejes 0 y 1. */
    private function atDistanceFromJuan(float $d): array
    {
        $c = 1 - ($d ** 2) / 2;

        return $this->v($c, sqrt(1 - $c * $c));
    }

    private function juanFace(): array
    {
        return $this->v(1.0);
    }

    private function camiloFace(): array
    {
        return $this->atDistanceFromJuan(0.91);
    }

    private function d(array $a, array $b): float
    {
        return (float) FaceVector::distance($a, $b);
    }

    // ── Flujos ──────────────────────────────────────────────────────────────

    /** @var list<string> cuerpos de TODAS las respuestas de los flujos (para buscar fugas) */
    private array $bodies = [];

    private function keep(TestResponse $res): TestResponse
    {
        $this->bodies[] = (string) $res->getContent();

        return $res;
    }

    /** @param list<array> $captures */
    private function enrollWith(Identity $identity, string $document, array $captures, array $tablet): TestResponse
    {
        $code = $this->keep($this->postJson("/api/admin/biometrics/identities/{$identity->uuid}/enrollment-code", ['reason' => 'Registro en recepción'], $this->adminWithPermissions(['biometrics.manage'])))
            ->assertCreated()->json('code');
        $start = $this->keep($this->signed('POST', '/api/kiosk/enrollment/start', ['employee_ref' => $this->ref($document, $tablet), 'authorization_code' => $code], $tablet))->assertOk();

        return $this->keep($this->signed('POST', '/api/kiosk/enrollment/complete', [
            'enrollment_token' => $start->json('enrollment.token'),
            'consent_version' => $start->json('consent.version'),
            'consent_accepted' => true,
            'embeddings' => $captures,
            'operation_id' => (string) Str::uuid(),
        ], $tablet));
    }

    private function enrollFace(Identity $who, string $document, array $face, array $tablet): TestResponse
    {
        return $this->enrollWith($who, $document, [$face, $face, $face], $tablet);
    }

    private function verifyAs(string $document, array $probe, array $tablet, ?string $pin = null, string $purpose = 'workday'): TestResponse
    {
        return $this->keep($this->signed('POST', '/api/kiosk/verify', array_filter([
            'employee_ref' => $this->ref($document, $tablet),
            'purpose' => $purpose,
            'embedding' => $probe,
            'pin' => $pin,
        ], fn ($v) => $v !== null), $tablet));
    }

    private function pinCode(Identity $identity): string
    {
        return $this->keep($this->postJson("/api/admin/biometrics/identities/{$identity->uuid}/pin-code", ['reason' => 'Crear su PIN en recepción'], $this->adminWithPermissions(['biometrics.manage'])))
            ->assertCreated()->json('code');
    }

    private function setPin(Identity $who, string $document, array $face, string $pin, array $tablet, ?string $code = null): TestResponse
    {
        return $this->keep($this->signed('POST', '/api/kiosk/pin/setup', [
            'employee_ref' => $this->ref($document, $tablet),
            'authorization_code' => $code ?? $this->pinCode($who),
            'embedding' => $face,
            'pin' => $pin,
        ], $tablet));
    }

    private function grantException(Trainer $subject, Trainer $other): TestResponse
    {
        return $this->keep($this->postJson("/api/admin/biometrics/identities/{$subject->identity->uuid}/lookalike-exception", [
            'other_identity' => $other->identity->uuid,
            'subject_verified_in_person' => true,
            'other_verified_in_person' => true,
            'reason' => 'Comprobados los dos en recepción con su cédula',
        ], $this->adminWithPermissions(['biometrics.manage'])));
    }

    private function revokePin(Identity $who): TestResponse
    {
        return $this->keep($this->postJson("/api/admin/biometrics/identities/{$who->uuid}/pin/revoke", ['reason' => 'Lo olvidó'], $this->adminWithPermissions(['biometrics.manage'])));
    }

    private function crmShow(Identity $who, array $permissions = ['biometrics.view']): TestResponse
    {
        return $this->keep($this->getJson("/api/admin/biometrics/identities/{$who->uuid}", $this->adminWithPermissions($permissions)))->assertOk();
    }

    /** Juan registrado y Camilo rechazado por parecido: el estado real de producción. */
    private function pairLikeProduction(array $tablet): void
    {
        $this->enrollFace($this->juan->identity, self::JUAN_DOC, $this->juanFace(), $tablet)->assertCreated();
        $this->enrollFace($this->camilo->identity, self::CAMILO_DOC, $this->camiloFace(), $tablet)
            ->assertStatus(409)->assertJsonPath('code', 'face_already_linked');
    }

    /** Aísla la regla del PIN del contador facial (5 por vía y persona), como FaceSecondFactorTest. */
    private function clearFaceLimiter(int $identityId, string $via = 'kiosk'): void
    {
        RateLimiter::clear("workforce-face:{$via}:identity:{$identityId}");
    }

    /** @return TestResponse la del último intento */
    private function wrongPinTimes(int $times, array $tablet): TestResponse
    {
        $res = null;
        for ($i = 0; $i < $times; $i++) {
            $res = $this->verifyAs(self::JUAN_DOC, $this->juanFace(), $tablet, self::WRONG_PIN);
            $this->clearFaceLimiter($this->juan->identity_id);
        }

        return $res;
    }

    private function assertSecondFactorRequired(TestResponse $res, string $pinStatus, string $message = ''): void
    {
        $this->assertSame(422, $res->status(), $message.' — respuesta: '.$res->getContent());
        $res->assertJsonPath('code', 'face_no_match')
            ->assertJsonPath('second_factor.required', true)
            ->assertJsonPath('second_factor.type', 'pin')
            ->assertJsonPath('second_factor.status', $pinStatus)
            ->assertJsonMissingPath('verification')
            ->assertJsonMissingPath('trainer_session');
    }

    private function classOf(Trainer $trainer): MyClass
    {
        return MyClass::create([
            'name' => 'Funcional 9am', 'type' => 'Funcional', 'trainer_id' => $trainer->id, 'day_of_week' => 'Lunes',
            'start_time' => '09:00', 'end_time' => '10:00', 'max_capacity' => 20, 'status' => 'active',
            'allow_online_booking' => true, 'is_recurring' => true,
        ]);
    }

    /** @return array<string, string> */
    private function bearerOf(Trainer $trainer): array
    {
        return ['Authorization' => 'Bearer '.app(TrainerSessionService::class)->issueSession($trainer, ['device_id' => 'kiosk:test', 'device_name' => 'Tablet', 'platform' => 'android'])['token']];
    }

    // ── Bordes del bloqueo del PIN (contrato §10.1.3) ──────────────────────

    public function test_el_primer_bloqueo_dura_15_minutos_justos(): void
    {
        $tablet = $this->tablet();
        $this->pairLikeProduction($tablet);
        $this->setPin($this->juan->identity, self::JUAN_DOC, $this->juanFace(), self::JUAN_PIN, $tablet)->assertCreated();

        $lockedAt = now()->copy();
        $this->wrongPinTimes(5, $tablet)->assertStatus(429)->assertJsonPath('code', 'pin_locked')
            ->assertJsonPath('second_factor.locked_until', $lockedAt->copy()->addMinutes(15)->format(DATE_ATOM));

        // Un segundo antes del fin: sigue bloqueado aunque el PIN sea el bueno.
        Carbon::setTestNow($lockedAt->copy()->addMinutes(15)->subSecond());
        $this->clearFaceLimiter($this->juan->identity_id);
        $this->identify(self::JUAN_DOC, $tablet)->assertJsonPath('employee.pin.status', 'locked');
        $this->verifyAs(self::JUAN_DOC, $this->juanFace(), $tablet, self::JUAN_PIN)->assertStatus(429)->assertJsonPath('code', 'pin_locked');

        // En el segundo exacto: vuelve a valer.
        Carbon::setTestNow($lockedAt->copy()->addMinutes(15));
        $this->clearFaceLimiter($this->juan->identity_id);
        $this->identify(self::JUAN_DOC, $tablet)->assertJsonPath('employee.pin.status', 'active');
        $this->verifyAs(self::JUAN_DOC, $this->juanFace(), $tablet, self::JUAN_PIN)->assertOk()->assertJsonPath('verification.method', 'face_pin');
    }

    public function test_los_bloqueos_escalan_15_60_240_1440_minutos_y_se_quedan_en_24_horas(): void
    {
        $tablet = $this->tablet();
        $this->pairLikeProduction($tablet);
        $this->setPin($this->juan->identity, self::JUAN_DOC, $this->juanFace(), self::JUAN_PIN, $tablet)->assertCreated();

        $seen = [];
        foreach ([15, 60, 240, 1440, 1440] as $expected) {
            $at = now()->copy();
            $until = Carbon::parse($this->wrongPinTimes(5, $tablet)->assertStatus(429)->json('second_factor.locked_until'));
            $seen[] = (int) $at->diffInMinutes($until);
            // Justo al terminar el bloqueo, el siguiente ciclo.
            Carbon::setTestNow($until);
        }
        $this->assertSame([15, 60, 240, 1440, 1440], $seen);
    }

    public function test_a_las_24_horas_menos_un_segundo_los_fallos_de_la_ventana_aun_cuentan(): void
    {
        $tablet = $this->tablet();
        $this->pairLikeProduction($tablet);
        $this->setPin($this->juan->identity, self::JUAN_DOC, $this->juanFace(), self::JUAN_PIN, $tablet)->assertCreated();
        $start = now()->copy();
        $this->wrongPinTimes(4, $tablet)->assertStatus(422)->assertJsonPath('code', 'pin_invalid');

        Carbon::setTestNow($start->copy()->addHours(24)->subSecond());
        $this->wrongPinTimes(1, $tablet)->assertStatus(429)->assertJsonPath('code', 'pin_locked');
    }

    public function test_a_las_24_horas_justas_la_ventana_de_fallos_caduca(): void
    {
        $tablet = $this->tablet();
        $this->pairLikeProduction($tablet);
        $this->setPin($this->juan->identity, self::JUAN_DOC, $this->juanFace(), self::JUAN_PIN, $tablet)->assertCreated();
        $start = now()->copy();
        $this->wrongPinTimes(4, $tablet)->assertStatus(422);

        Carbon::setTestNow($start->copy()->addHours(24));
        // Los 4 viejos ya no cuentan: hacen falta 5 nuevos.
        $this->wrongPinTimes(4, $tablet)->assertStatus(422)->assertJsonPath('code', 'pin_invalid');
        $this->wrongPinTimes(1, $tablet)->assertStatus(429)->assertJsonPath('code', 'pin_locked');
    }

    public function test_un_bloqueo_a_7_dias_menos_un_segundo_del_anterior_escala(): void
    {
        $tablet = $this->tablet();
        $this->pairLikeProduction($tablet);
        $this->setPin($this->juan->identity, self::JUAN_DOC, $this->juanFace(), self::JUAN_PIN, $tablet)->assertCreated();
        $first = now()->copy();
        $this->wrongPinTimes(5, $tablet)->assertStatus(429);

        Carbon::setTestNow($first->copy()->addDays(7)->subSecond());
        $this->wrongPinTimes(5, $tablet)->assertStatus(429)
            ->assertJsonPath('second_factor.locked_until', now()->addMinutes(60)->format(DATE_ATOM));
    }

    public function test_a_los_7_dias_justos_sin_bloqueo_el_escalado_vuelve_a_15_minutos(): void
    {
        $tablet = $this->tablet();
        $this->pairLikeProduction($tablet);
        $this->setPin($this->juan->identity, self::JUAN_DOC, $this->juanFace(), self::JUAN_PIN, $tablet)->assertCreated();
        $first = now()->copy();
        $this->wrongPinTimes(5, $tablet)->assertStatus(429);

        Carbon::setTestNow($first->copy()->addDays(7));
        $this->wrongPinTimes(5, $tablet)->assertStatus(429)
            ->assertJsonPath('second_factor.locked_until', now()->addMinutes(15)->format(DATE_ATOM));
    }

    public function test_sin_pin_y_con_el_pin_bloqueado_la_respuesta_lo_dice_con_su_hora(): void
    {
        $tablet = $this->tablet();
        $this->pairLikeProduction($tablet);
        $this->setPin($this->juan->identity, self::JUAN_DOC, $this->juanFace(), self::JUAN_PIN, $tablet)->assertCreated();
        $this->wrongPinTimes(5, $tablet)->assertStatus(429);

        $res = $this->verifyAs(self::JUAN_DOC, $this->juanFace(), $tablet);
        $this->assertSecondFactorRequired($res, 'locked');
        $res->assertJsonPath('second_factor.locked_until', now()->addMinutes(15)->format(DATE_ATOM));
    }

    public function test_pin_invalid_nunca_dice_cuantos_intentos_quedan(): void
    {
        $tablet = $this->tablet();
        $this->pairLikeProduction($tablet);
        $this->setPin($this->juan->identity, self::JUAN_DOC, $this->juanFace(), self::JUAN_PIN, $tablet)->assertCreated();

        for ($i = 1; $i <= 4; $i++) {
            $res = $this->wrongPinTimes(1, $tablet)->assertStatus(422);
            $this->assertSame(['ok', 'code', 'message'], array_keys($res->json()), "fallo {$i}: sin campos extra");
        }
    }

    // ── Ciclo de vida del PIN ───────────────────────────────────────────────

    public function test_anular_un_pin_bloqueado_y_crear_otro_deja_el_nuevo_limpio_y_el_viejo_sin_valor(): void
    {
        $tablet = $this->tablet();
        $this->pairLikeProduction($tablet);
        $this->setPin($this->juan->identity, self::JUAN_DOC, $this->juanFace(), self::JUAN_PIN, $tablet)->assertCreated();
        $this->wrongPinTimes(5, $tablet)->assertStatus(429);

        $this->revokePin($this->juan->identity)->assertOk();
        $this->identify(self::JUAN_DOC, $tablet)->assertJsonPath('employee.pin', ['status' => 'revoked']);
        $this->crmShow($this->juan->identity)->assertJsonPath('data.pin.status', 'revoked')->assertJsonPath('data.pin.locked_until', null);
        $this->verifyAs(self::JUAN_DOC, $this->juanFace(), $tablet, self::JUAN_PIN)->assertStatus(409)->assertJsonPath('code', 'pin_not_set');
        $this->clearFaceLimiter($this->juan->identity_id);

        Carbon::setTestNow(now()->addMinutes(2));
        $nuevo = '604817';
        $this->setPin($this->juan->identity, self::JUAN_DOC, $this->juanFace(), $nuevo, $tablet)->assertCreated()->assertJsonPath('pin.status', 'active');

        $pin = WorkforcePin::query()->sole();
        $this->assertSame([0, null, null, 0], [$pin->failed_attempts, $pin->locked_until, $pin->revoked_at, $pin->lock_level], 'El PIN nuevo nace sin fallos, sin bloqueo, sin anulación y sin escalado.');
        $this->crmShow($this->juan->identity)->assertJsonPath('data.pin.status', 'active')->assertJsonPath('data.pin.set_at', now()->toIso8601String());
        $this->verifyAs(self::JUAN_DOC, $this->juanFace(), $tablet, self::JUAN_PIN)->assertStatus(422)->assertJsonPath('code', 'pin_invalid');
        $this->verifyAs(self::JUAN_DOC, $this->juanFace(), $tablet, $nuevo)->assertOk()->assertJsonPath('verification.method', 'face_pin');
    }

    public function test_crear_el_pin_exige_una_plantilla_validada_y_sin_ella_no_gasta_el_codigo(): void
    {
        $tablet = $this->tablet();
        $ana = $this->receptionist('1075000001');
        $face = $this->juanFace();
        $code = $this->pinCode($ana);

        // Sin rostro.
        $this->setPin($ana, '1075000001', $face, self::JUAN_PIN, $tablet, $code)
            ->assertStatus(409)->assertJsonPath('code', 'biometric_not_enrolled')->assertJsonPath('biometric.status', 'not_enrolled');

        // Plantilla heredada sin consentimiento: no verifica.
        $legacy = new TrainerFaceReference(['identity_id' => $ana->id, 'status' => TrainerFaceReference::STATUS_ACTIVE, 'enrolled_at' => now(), 'enrolled_via' => TrainerFaceReference::VIA_TRAINER_PORTAL]);
        $legacy->storeVector($face);
        $legacy->save();
        $this->setPin($ana, '1075000001', $face, self::JUAN_PIN, $tablet, $code)
            ->assertStatus(409)->assertJsonPath('code', 'biometric_not_enrolled')->assertJsonPath('biometric.status', 'renewal_pending');

        // Plantilla revocada.
        $this->enrollFace($ana, '1075000001', $face, $tablet)->assertCreated();
        $this->postJson("/api/admin/biometrics/identities/{$ana->uuid}/revoke", ['reason' => 'Prueba de revocación'], $this->adminWithPermissions(['biometrics.manage']))->assertOk();
        $this->setPin($ana, '1075000001', $face, self::JUAN_PIN, $tablet, $code)
            ->assertStatus(409)->assertJsonPath('code', 'biometric_not_enrolled')->assertJsonPath('biometric.status', 'revoked');

        $this->assertSame(0, WorkforcePin::count(), 'Ningún PIN sin plantilla validada.');

        // El MISMO código sigue vivo: ninguno de los rechazos lo gastó.
        $this->enrollFace($ana, '1075000001', $face, $tablet)->assertCreated();
        $this->setPin($ana, '1075000001', $face, self::JUAN_PIN, $tablet, $code)->assertCreated();
    }

    public function test_el_borde_de_la_regla_de_fechas_31_12_si_32_12_no(): void
    {
        $tablet = $this->tablet();
        $this->enrollFace($this->juan->identity, self::JUAN_DOC, $this->juanFace(), $tablet)->assertCreated();
        $code = $this->pinCode($this->juan->identity);

        $this->setPin($this->juan->identity, self::JUAN_DOC, $this->juanFace(), '311299', $tablet, $code)->assertStatus(422)->assertJsonPath('code', 'pin_weak');
        // Día 32: no es una fecha, ni serie, ni patrón, ni documento.
        $this->setPin($this->juan->identity, self::JUAN_DOC, $this->juanFace(), '321299', $tablet, $code)->assertCreated();
    }

    public function test_sin_codigo_de_pin_vivo_la_cara_ni_se_compara(): void
    {
        $tablet = $this->tablet();
        $this->enrollFace($this->juan->identity, self::JUAN_DOC, $this->juanFace(), $tablet)->assertCreated();
        $lejos = $this->atDistanceFromJuan(1.3);

        // Seis caras ajenas sin código vivo: rechazo del código, nunca face_no_match ni bloqueo facial.
        for ($i = 0; $i < 6; $i++) {
            $res = $this->setPin($this->juan->identity, self::JUAN_DOC, $lejos, self::JUAN_PIN, $tablet, '12345678');
            $this->assertStringStartsWith('authorization_code_', (string) $res->json('code'), "intento {$i}: ".$res->getContent());
        }
        $this->assertSame(0, TrainerAuditLog::query()->where('event', 'biometric.verify_failed')->count(), 'La cara no se comparó.');

        // Con el código ya emitido, la cara cuenta desde cero.
        $code = $this->pinCode($this->juan->identity);
        $this->setPin($this->juan->identity, self::JUAN_DOC, $this->juanFace(), self::JUAN_PIN, $tablet, $code)->assertCreated();
    }

    /**
     * Tester 2026-10-27 (D-2, corregido): `assertStrong()` corría ANTES de
     * `assertLive()` y miraba el documento de quien se le parece. Sin ningún
     * código, desde la tablet, un PIN = 6 dígitos del documento de otra
     * persona decía si las dos forman un par (`pin_weak`) o no (rechazo del
     * código). Ahora el código vivo se comprueba primero (contrato §10.2).
     */
    public function test_sin_codigo_la_respuesta_no_revela_con_quien_forma_par(): void
    {
        $tablet = $this->tablet();
        $this->pairLikeProduction($tablet);
        $ana = $this->receptionist('1075000001');
        $this->enrollFace($ana, '1075000001', $this->v(0.0, 0.0, 1.0), $tablet)->assertCreated();
        $camiloTail = substr(self::CAMILO_DOC, -6);

        // Ana (sin par con Camilo): el rechazo del código.
        $control = $this->setPin($ana, '1075000001', $this->v(0.0, 0.0, 1.0), $camiloTail, $tablet, '12345678');
        $this->assertStringStartsWith('authorization_code_', (string) $control->json('code'));

        // Juan (en par con Camilo): debería ser la MISMA respuesta.
        $juan = $this->setPin($this->juan->identity, self::JUAN_DOC, $this->juanFace(), $camiloTail, $tablet, '12345678');
        $this->assertSame($control->json('code'), $juan->json('code'), 'Sin código, la respuesta delata el par: '.$juan->getContent());
    }

    public function test_un_pin_con_formato_invalido_es_un_422_de_validacion_que_no_gasta_intentos(): void
    {
        $tablet = $this->tablet();
        $this->pairLikeProduction($tablet);
        $this->setPin($this->juan->identity, self::JUAN_DOC, $this->juanFace(), self::JUAN_PIN, $tablet)->assertCreated();

        // Cinco dígitos, siete, letras, dígitos no ASCII (árabe-índicos), número JSON.
        foreach (['48291', '4829130', '48a913', '٤٨٢٩١٣', 482913] as $bad) {
            $this->keep($this->signed('POST', '/api/kiosk/verify', [
                'employee_ref' => $this->ref(self::JUAN_DOC, $tablet), 'purpose' => 'workday', 'embedding' => $this->juanFace(), 'pin' => $bad,
            ], $tablet))->assertStatus(422)->assertJsonValidationErrors('pin');
        }

        $this->assertSame(0, WorkforcePin::query()->sole()->failed_attempts, 'Un formato inválido no es un intento de PIN.');
        // Tampoco gastó intentos de rostro: el 6.º intento real pasa.
        $this->verifyAs(self::JUAN_DOC, $this->juanFace(), $tablet, self::JUAN_PIN)->assertOk()->assertJsonPath('verification.method', 'face_pin');
    }

    // ── Contador facial frente al PIN (contrato §10.1.5) ─────────────────────

    public function test_cinco_intentos_ambiguos_sin_pin_agotan_el_rostro_aunque_despues_llegue_el_pin_correcto(): void
    {
        $tablet = $this->tablet();
        $this->pairLikeProduction($tablet);
        $this->setPin($this->juan->identity, self::JUAN_DOC, $this->juanFace(), self::JUAN_PIN, $tablet)->assertCreated();

        for ($i = 0; $i < 5; $i++) {
            $this->assertSecondFactorRequired($this->verifyAs(self::JUAN_DOC, $this->juanFace(), $tablet), 'active', "intento {$i}");
        }
        $this->verifyAs(self::JUAN_DOC, $this->juanFace(), $tablet, self::JUAN_PIN)->assertStatus(429)->assertJsonPath('code', 'face_attempts_exceeded');
        $this->assertSame(0, WorkforcePin::query()->sole()->failed_attempts, 'El PIN ni se llegó a comprobar.');
    }

    public function test_el_exito_con_pin_limpia_el_contador_del_rostro(): void
    {
        $tablet = $this->tablet();
        $this->pairLikeProduction($tablet);
        $this->setPin($this->juan->identity, self::JUAN_DOC, $this->juanFace(), self::JUAN_PIN, $tablet)->assertCreated();

        for ($i = 0; $i < 4; $i++) {
            $this->assertSecondFactorRequired($this->verifyAs(self::JUAN_DOC, $this->juanFace(), $tablet), 'active');
        }
        $this->verifyAs(self::JUAN_DOC, $this->juanFace(), $tablet, self::JUAN_PIN)->assertOk();
        // Sin la limpieza, el 6.º intento acumulado daría 429.
        for ($i = 0; $i < 5; $i++) {
            $this->assertSecondFactorRequired($this->verifyAs(self::JUAN_DOC, $this->juanFace(), $tablet), 'active', "intento {$i} tras el éxito");
        }
    }

    public function test_un_rostro_que_no_coincide_nunca_llega_a_gastar_intentos_del_pin(): void
    {
        $tablet = $this->tablet();
        $this->pairLikeProduction($tablet);
        $this->setPin($this->juan->identity, self::JUAN_DOC, $this->juanFace(), self::JUAN_PIN, $tablet)->assertCreated();
        $lejos = $this->atDistanceFromJuan(1.3);
        $this->assertGreaterThan(1.0, $this->d($lejos, $this->juanFace()), 'Premisa: fuera del umbral.');

        for ($i = 0; $i < 5; $i++) {
            $this->verifyAs(self::JUAN_DOC, $lejos, $tablet, self::WRONG_PIN)
                ->assertStatus(422)->assertJsonPath('code', 'face_no_match')->assertJsonMissingPath('second_factor');
        }
        $this->verifyAs(self::JUAN_DOC, $lejos, $tablet, self::WRONG_PIN)->assertStatus(429)->assertJsonPath('code', 'face_attempts_exceeded');

        $pin = WorkforcePin::query()->sole();
        $this->assertSame([0, 'active'], [$pin->failed_attempts, $pin->status()], 'Sin rostro no se adivina el PIN: ni se compara.');
        $this->assertSame(0, TrainerAuditLog::query()->where('event', 'biometric.pin_failed')->count());
    }

    // ── Plantillas heredadas (solo trainer_id) en la regla de ambigüedad ────

    /**
     * La regla compara contra TODAS las plantillas activas de otras personas,
     * también las heredadas sin consentimiento (el lado seguro; contrato
     * §10.1, alineado el 2026-10-27).
     */
    public function test_una_plantilla_heredada_sin_identidad_de_otra_persona_tambien_vuelve_ambigua_la_captura(): void
    {
        $tablet = $this->tablet();
        $ana = $this->receptionist('1075000001');
        $this->enrollFace($ana, '1075000001', $this->juanFace(), $tablet)->assertCreated();
        // Pedro: entrenador sin identidad, con plantilla heredada (solo trainer_id), a 0,91 de Ana.
        $pedro = Trainer::create(['full_name' => 'Pedro Heredado', 'document' => '1075000099', 'status' => 'active']);
        $legacy = new TrainerFaceReference(['trainer_id' => $pedro->id, 'status' => TrainerFaceReference::STATUS_ACTIVE, 'enrolled_at' => now(), 'enrolled_via' => TrainerFaceReference::VIA_TRAINER_PORTAL]);
        $legacy->storeVector($this->camiloFace());
        $legacy->save();
        $this->assertNull($legacy->identity_id);
        $this->assertFalse($legacy->isValidated(), 'Premisa: heredada sin consentimiento.');

        $this->assertSecondFactorRequired($this->verifyAs('1075000001', $this->juanFace(), $tablet), 'not_set');
        $this->assertSame('coincidence', TrainerAuditLog::query()->where('event', 'biometric.verify_ambiguous')->sole()->metadata['cause']);

        // Revocada, deja de contar.
        $legacy->forceFill(['status' => TrainerFaceReference::STATUS_REVOKED])->save();
        $this->verifyAs('1075000001', $this->juanFace(), $tablet)->assertOk()->assertJsonPath('verification.method', 'face');
    }

    /**
     * Tester 2026-10-27 (D-1, corregido): si la plantilla con la que choca el
     * registro es heredada y su fila no tiene identity_id (solo trainer_id),
     * el dueño se resuelve por su ficha de entrenador y el par se registra. Sin
     * eso, cuando esa persona renovara su rostro, la otra entraría como ella
     * con el rostro solo. La carga de la migración resuelve igual.
     */
    public function test_un_rechazo_contra_una_plantilla_heredada_sin_identity_id_tambien_deja_el_par(): void
    {
        $tablet = $this->tablet();
        // Juan: plantilla heredada del portal (fila sin identity_id, solo trainer_id), sin consentimiento.
        $legacy = new TrainerFaceReference(['trainer_id' => $this->juan->id, 'status' => TrainerFaceReference::STATUS_ACTIVE, 'enrolled_at' => now(), 'enrolled_via' => TrainerFaceReference::VIA_TRAINER_PORTAL]);
        $legacy->storeVector($this->juanFace());
        $legacy->save();
        $this->assertNull($legacy->identity_id);

        // Camilo choca con ella (0,91 < 1,0): rechazado, como en producción.
        $this->enrollFace($this->camilo->identity, self::CAMILO_DOC, $this->camiloFace(), $tablet)
            ->assertStatus(409)->assertJsonPath('code', 'face_already_linked');
        $pairsAfterRejection = FaceLookalike::count();

        // Juan renueva su rostro en el kiosko (reutiliza la fila heredada).
        $this->enrollFace($this->juan->identity, self::JUAN_DOC, $this->juanFace(), $tablet)->assertCreated();
        $this->assertSame($legacy->id, TrainerFaceReference::query()->where('identity_id', $this->juan->identity_id)->value('id'));

        // Camilo se presenta como Juan con su propia cara.
        $this->assertSecondFactorRequired(
            $this->verifyAs(self::JUAN_DOC, $this->camiloFace(), $tablet),
            'not_set',
            "El rechazo contra la plantilla heredada debía dejar el par Juan–Camilo (pares tras el rechazo: {$pairsAfterRejection}); sin él, Camilo entra como Juan con el rostro solo.",
        );
    }

    // ── Login facial público (apagado en producción) ────────────────────────

    public function test_el_login_facial_publico_ante_un_rostro_ambiguo_da_el_mismo_rechazo_generico_que_un_rostro_ajeno(): void
    {
        config(['trainer.face.login_enabled' => true]);
        $tablet = $this->tablet();
        $this->pairLikeProduction($tablet);
        $this->setPin($this->juan->identity, self::JUAN_DOC, $this->juanFace(), self::JUAN_PIN, $tablet)->assertCreated();
        $login = fn (array $body) => $this->keep($this->postJson('/api/trainer/auth/face/login', $body + ['trainer_id' => $this->juan->id, 'device_id' => 'tel-1']));

        $ajeno = $login(['embedding' => $this->atDistanceFromJuan(1.3)])->assertStatus(422);
        $ambiguo = $login(['embedding' => $this->camiloFace()])->assertStatus(422);
        // Juan con su cara y su PIN: esta vía no admite PIN y él está en un par.
        $conPin = $login(['embedding' => $this->juanFace(), 'pin' => self::JUAN_PIN])->assertStatus(422);

        $this->assertSame($ajeno->json(), $ambiguo->json(), 'Un rostro ambiguo no se distingue de uno ajeno.');
        $this->assertSame($ajeno->json(), $conPin->json());
        $ambiguo->assertJsonPath('code', 'face_no_match')->assertJsonMissingPath('second_factor')->assertJsonMissingPath('token');
        $this->assertSame(2, TrainerAuditLog::query()->where('event', 'biometric.verify_ambiguous')->count(), 'El servidor sí sabe por qué (traza interna).');
        $this->assertSame(0, WorkforcePin::query()->sole()->failed_attempts);
    }

    // ── Estado del PIN en el kiosko ─────────────────────────────────────────

    public function test_identify_y_roster_dicen_el_estado_del_pin_y_nada_mas(): void
    {
        $tablet = $this->tablet();
        $this->pairLikeProduction($tablet);
        $estado = function (string $expected) use ($tablet): void {
            $this->identify(self::JUAN_DOC, $tablet)->assertOk()->assertJsonPath('employee.pin', ['status' => $expected]);
            $fila = collect($this->signed('GET', '/api/kiosk/employees/roster', null, $tablet)->assertOk()->json('data'))
                ->first(fn ($e) => str_starts_with($e['display_name'], 'Juan'));
            $this->assertSame(['status' => $expected], $fila['pin'] ?? null, "roster: {$expected}");
        };

        $estado('not_set');
        $this->setPin($this->juan->identity, self::JUAN_DOC, $this->juanFace(), self::JUAN_PIN, $tablet)->assertCreated();
        $estado('active');
        $this->wrongPinTimes(5, $tablet)->assertStatus(429);
        $estado('locked');
        $this->revokePin($this->juan->identity)->assertOk();
        $estado('revoked');
    }

    // ── Historial del CRM ───────────────────────────────────────────────────

    public function test_el_historial_del_crm_cuenta_el_segundo_factor_sin_decir_con_quien(): void
    {
        $tablet = $this->tablet();
        $this->pairLikeProduction($tablet);
        $this->grantException($this->camilo, $this->juan)->assertCreated();
        $this->setPin($this->juan->identity, self::JUAN_DOC, $this->juanFace(), self::JUAN_PIN, $tablet)->assertCreated();
        $this->assertSecondFactorRequired($this->verifyAs(self::JUAN_DOC, $this->juanFace(), $tablet), 'active');
        $this->clearFaceLimiter($this->juan->identity_id);
        $this->wrongPinTimes(5, $tablet)->assertStatus(429);
        $this->revokePin($this->juan->identity)->assertOk();

        $res = $this->crmShow($this->juan->identity);
        $history = collect($res->json('data.history'));
        $labels = $history->pluck('label', 'event')->all();
        ksort($labels);
        $expected = [
            'biometric.pin_set' => 'PIN creado',
            'biometric.verify_ambiguous' => 'Rostro ambiguo: pidió PIN',
            'biometric.pin_failed' => 'PIN equivocado',
            'biometric.pin_locked' => 'PIN bloqueado',
            'biometric.pin_revoked' => 'PIN anulado',
        ];
        ksort($expected);
        $this->assertSame($expected, array_intersect_key($labels, $expected));
        $this->assertSame(4, $history->where('event', 'biometric.pin_failed')->count());
        $this->assertSame('Tiene un parecido registrado', $history->firstWhere('event', 'biometric.verify_ambiguous')['detail']);
        // Con solo biometrics.view, ni los eventos de parecido: su hora, igual en
        // las dos personas, delataría la pareja (revisión 2026-10-27).
        $this->assertArrayNotHasKey('biometric.lookalike_detected', $labels);
        $this->assertArrayNotHasKey('biometric.lookalike_exception_granted', $labels);

        // Con biometrics.view: ni con quién (nombre, documento, uuid) ni distancias ni PIN.
        $json = (string) $res->getContent();
        foreach (['Camilo', self::CAMILO_DOC, $this->camilo->identity->uuid, 'distance', self::JUAN_PIN, self::WRONG_PIN, 'pin_hash'] as $needle) {
            $this->assertStringNotContainsString($needle, $json, "El historial no debe contener «{$needle}».");
        }
    }

    // ── Método face_pin en jornada y clases ─────────────────────────────────

    public function test_entrar_con_rostro_y_pin_deja_la_jornada_y_su_evento_como_face_pin(): void
    {
        $tablet = $this->tablet();
        $this->pairLikeProduction($tablet);
        $this->setPin($this->juan->identity, self::JUAN_DOC, $this->juanFace(), self::JUAN_PIN, $tablet)->assertCreated();

        $token = $this->verifyAs(self::JUAN_DOC, $this->juanFace(), $tablet, self::JUAN_PIN)->assertOk()->json('verification.token');
        $this->clock('in', $token, $tablet)->assertCreated()->assertJsonPath('workday.clock_in.method', 'face_pin');
        $this->assertSame('face_pin', WorkdayEvent::query()->sole()->method);

        $this->getJson('/api/admin/workforce/workdays?'.http_build_query(['from' => self::LUNES, 'to' => self::LUNES, 'method' => 'face_pin']), $this->adminWithPermissions(['workforce.view']))
            ->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_finalizar_la_clase_tambien_exige_el_pin_y_queda_como_face_pin(): void
    {
        $tablet = $this->tablet();
        $this->pairLikeProduction($tablet);
        $this->setPin($this->juan->identity, self::JUAN_DOC, $this->juanFace(), self::JUAN_PIN, $tablet)->assertCreated();
        $clase = $this->classOf($this->juan);
        $bearer = $this->bearerOf($this->juan);
        $proof = fn (?string $pin) => array_filter(['session_date' => self::LUNES, 'embedding' => $this->juanFace(), 'pin' => $pin], fn ($v) => $v !== null);

        $this->keep($this->signed('POST', "/api/trainer/classes/{$clase->id}/start", $proof(self::JUAN_PIN), $tablet, $bearer))->assertOk();
        $this->assertSecondFactorRequired($this->keep($this->signed('POST', "/api/trainer/classes/{$clase->id}/end", $proof(null), $tablet, $bearer)), 'active');
        $this->assertNull(ClassSession::query()->sole()->ended_at, 'Sin PIN no se finaliza.');

        $this->keep($this->signed('POST', "/api/trainer/classes/{$clase->id}/end", $proof(self::JUAN_PIN), $tablet, $bearer))
            ->assertOk()->assertJsonPath('session.end_identity_method', 'face_pin')->assertJsonPath('session.start_identity_method', 'face_pin');
    }

    public function test_el_acceso_de_entrenador_en_la_tablet_con_pin_queda_en_la_traza_como_face_pin(): void
    {
        $tablet = $this->tablet();
        $this->pairLikeProduction($tablet);
        $this->setPin($this->juan->identity, self::JUAN_DOC, $this->juanFace(), self::JUAN_PIN, $tablet)->assertCreated();

        $this->verifyAs(self::JUAN_DOC, $this->juanFace(), $tablet, self::JUAN_PIN, 'trainer_session')->assertOk()->assertJsonStructure(['trainer_session' => ['token']]);
        $login = TrainerAuditLog::query()->where('event', TrainerAuditLog::EVENT_LOGIN)->sole();
        $this->assertSame('face_pin', $login->metadata['method']);
    }

    // ── Excepción de enrolamiento: dirección, sustitución y caducidad ───────

    public function test_la_excepcion_solo_sirve_a_quien_se_registra_no_a_la_otra_persona_del_par(): void
    {
        $tablet = $this->tablet();
        $this->pairLikeProduction($tablet);
        $this->grantException($this->camilo, $this->juan)->assertCreated();
        $this->enrollFace($this->camilo->identity, self::CAMILO_DOC, $this->camiloFace(), $tablet)->assertCreated();

        // Excepción viva Camilo→Juan: no deja a JUAN renovar contra la plantilla de Camilo.
        $this->grantException($this->camilo, $this->juan)->assertCreated();
        $paraCamilo = FaceLookalikeException::query()->latest('id')->first();
        $this->enrollFace($this->juan->identity, self::JUAN_DOC, $this->juanFace(), $tablet)->assertStatus(409)->assertJsonPath('code', 'face_already_linked');
        $this->assertNull($paraCamilo->fresh()->consumed_at);

        // Con la suya (Juan→Camilo), sí; la de Camilo sigue sin usar.
        $this->grantException($this->juan, $this->camilo)->assertCreated();
        $this->enrollFace($this->juan->identity, self::JUAN_DOC, $this->juanFace(), $tablet)->assertCreated();
        $this->assertNull($paraCamilo->fresh()->consumed_at);
        $this->assertNotNull(FaceLookalikeException::query()->where('identity_id', $this->juan->identity_id)->sole()->consumed_at);
    }

    public function test_las_confirmaciones_presenciales_no_aceptan_cadenas_que_parecen_verdaderas(): void
    {
        // Lo que la regla `accepted` de Laravel sí aceptaría (FaceSecondFactorTest ya cubre «yes» y 1).
        $tablet = $this->tablet();
        $this->pairLikeProduction($tablet);
        foreach (['true', '1', 'on'] as $truthy) {
            foreach (['subject_verified_in_person', 'other_verified_in_person'] as $field) {
                $this->postJson("/api/admin/biometrics/identities/{$this->camilo->identity->uuid}/lookalike-exception", [
                    'other_identity' => $this->juan->identity->uuid,
                    'subject_verified_in_person' => true,
                    'other_verified_in_person' => true,
                    'reason' => 'Comprobados los dos en recepción con su cédula',
                    $field => $truthy,
                ], $this->adminWithPermissions(['biometrics.manage']))->assertStatus(422)->assertJsonValidationErrors($field);
            }
        }
        $this->assertSame(0, FaceLookalikeException::count());
    }

    public function test_conceder_otra_excepcion_al_mismo_par_anula_la_anterior(): void
    {
        $tablet = $this->tablet();
        $this->pairLikeProduction($tablet);
        $this->grantException($this->camilo, $this->juan)->assertCreated();
        $primera = FaceLookalikeException::query()->sole();
        Carbon::setTestNow(now()->addMinutes(5));
        $expires = $this->grantException($this->camilo, $this->juan)->assertCreated()->json('exception.expires_at');

        $this->assertNotNull($primera->fresh()->revoked_at);
        $this->crmShow($this->camilo->identity, ['biometrics.view', 'biometrics.manage'])->assertJsonPath('data.lookalikes.0.exception_expires_at', $expires);
        $this->enrollFace($this->camilo->identity, self::CAMILO_DOC, $this->camiloFace(), $tablet)->assertCreated();
        $this->assertNull($primera->fresh()->consumed_at, 'Se gasta la viva, no la anulada.');
        $this->assertNotNull(FaceLookalikeException::query()->latest('id')->first()->consumed_at);
    }

    public function test_la_excepcion_vale_hasta_un_segundo_antes_de_los_30_minutos(): void
    {
        $tablet = $this->tablet();
        $this->pairLikeProduction($tablet);
        $at = now()->copy();
        $this->grantException($this->camilo, $this->juan)->assertCreated();

        Carbon::setTestNow($at->copy()->addMinutes(30)->subSecond());
        $this->enrollFace($this->camilo->identity, self::CAMILO_DOC, $this->camiloFace(), $tablet)->assertCreated();
    }

    public function test_a_los_30_minutos_exactos_la_excepcion_ya_no_vale(): void
    {
        $tablet = $this->tablet();
        $this->pairLikeProduction($tablet);
        $at = now()->copy();
        $this->grantException($this->camilo, $this->juan)->assertCreated();

        Carbon::setTestNow($at->copy()->addMinutes(30));
        $this->enrollFace($this->camilo->identity, self::CAMILO_DOC, $this->camiloFace(), $tablet)->assertStatus(409)->assertJsonPath('code', 'face_already_linked');
    }

    public function test_el_suelo_de_la_excepcion_entre_069_y_071(): void
    {
        $tablet = $this->tablet();
        $this->pairLikeProduction($tablet);
        $this->grantException($this->camilo, $this->juan)->assertCreated();
        $a069 = $this->atDistanceFromJuan(0.69);
        $a071 = $this->atDistanceFromJuan(0.71);
        $this->assertEqualsWithDelta(0.69, $this->d($a069, $this->juanFace()), 0.0001);
        $this->assertEqualsWithDelta(0.71, $this->d($a071, $this->juanFace()), 0.0001);

        $this->enrollFace($this->camilo->identity, self::CAMILO_DOC, $a069, $tablet)->assertStatus(409);
        $this->enrollFace($this->camilo->identity, self::CAMILO_DOC, $a071, $tablet)->assertCreated();
    }

    // ── Retención ───────────────────────────────────────────────────────────

    public function test_desactivar_el_perfil_de_personal_anula_su_pin(): void
    {
        $tablet = $this->tablet();
        $ana = $this->receptionist('1075000001');
        $this->enrollFace($ana, '1075000001', $this->juanFace(), $tablet)->assertCreated();
        $this->setPin($ana, '1075000001', $this->juanFace(), self::JUAN_PIN, $tablet)->assertCreated();

        StaffProfile::query()->where('identity_id', $ana->id)->sole()->update(['status' => StaffProfile::STATUS_INACTIVE]);

        $pin = WorkforcePin::query()->sole();
        $this->assertSame(WorkforcePin::STATUS_REVOKED, $pin->status());
        $this->assertNull($pin->pin_hash);
        $this->assertSame(1, TrainerAuditLog::query()->where('event', 'biometric.pin_revoked')->where('identity_id', $ana->id)->count());
    }

    // ── Nada sale: PIN, plantillas ajenas, distancias ajenas ────────────────

    public function test_ningun_pin_ni_dato_de_otra_plantilla_sale_en_respuestas_trazas_ni_logs(): void
    {
        config(['trainer.face.login_enabled' => true]);
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged): void {
            $logged[] = $e->level.' '.$e->message.' '.json_encode($e->context);
        });
        $tablet = $this->tablet();

        // Recorrido completo: par, excepción, registro, PIN de los dos, ambigüedad,
        // PIN equivocado, PIN del otro, éxito, sesión de entrenador, clase, login público, CRM.
        $this->pairLikeProduction($tablet);
        $this->grantException($this->camilo, $this->juan)->assertCreated();
        $this->enrollFace($this->camilo->identity, self::CAMILO_DOC, $this->camiloFace(), $tablet)->assertCreated();
        $this->setPin($this->juan->identity, self::JUAN_DOC, $this->juanFace(), self::JUAN_PIN, $tablet)->assertCreated();
        $this->setPin($this->camilo->identity, self::CAMILO_DOC, $this->camiloFace(), self::CAMILO_PIN, $tablet)->assertCreated();

        $this->assertSecondFactorRequired($this->verifyAs(self::JUAN_DOC, $this->juanFace(), $tablet), 'active');
        $genuino = TrainerAuditLog::query()->where('event', 'biometric.verify_ambiguous')->latest('id')->first();
        $this->verifyAs(self::JUAN_DOC, $this->juanFace(), $tablet, self::WRONG_PIN)->assertStatus(422);
        $this->verifyAs(self::JUAN_DOC, $this->camiloFace(), $tablet, self::CAMILO_PIN)->assertStatus(422)->assertJsonPath('code', 'pin_invalid');
        $this->clearFaceLimiter($this->juan->identity_id);
        $this->verifyAs(self::JUAN_DOC, $this->juanFace(), $tablet, self::JUAN_PIN)->assertOk();
        $this->verifyAs(self::CAMILO_DOC, $this->camiloFace(), $tablet, self::CAMILO_PIN, 'trainer_session')->assertOk();
        $clase = $this->classOf($this->juan);
        $this->keep($this->signed('POST', "/api/trainer/classes/{$clase->id}/start", ['session_date' => self::LUNES, 'embedding' => $this->juanFace(), 'pin' => self::JUAN_PIN], $tablet, $this->bearerOf($this->juan)))->assertOk();
        $this->keep($this->postJson('/api/trainer/auth/face/login', ['trainer_id' => $this->juan->id, 'embedding' => $this->camiloFace(), 'device_id' => 'tel-1']))->assertStatus(422);
        foreach ([$this->juan->identity, $this->camilo->identity] as $who) {
            $this->crmShow($who);
            $this->crmShow($who, ['biometrics.view', 'biometrics.manage']);
        }
        $this->revokePin($this->camilo->identity)->assertOk();

        // La traza de un intento genuino de Juan lleva SU distancia (0), nunca la de Camilo (0,91).
        $this->assertSame(['distance', 'cause'], array_keys($genuino->metadata));
        $this->assertEqualsWithDelta(0.0, $genuino->metadata['distance'], 0.0001);
        $this->assertSame('pair', $genuino->metadata['cause']);

        $audits = TrainerAuditLog::query()->orderBy('id')->get();
        $forbiddenKeys = ['face_reference_id', 'reference_id', 'template', 'embedding', 'other_identity', 'other_identity_id', 'matches', 'nearest', 'pin', 'pin_hash', 'code'];
        foreach ($audits as $log) {
            $this->assertSame([], array_values(array_intersect($forbiddenKeys, array_keys((array) $log->metadata))), "Traza {$log->event} con claves prohibidas.");
        }
        foreach (TrainerAuditLog::query()->where('event', 'biometric.verify_ambiguous')->get() as $log) {
            $this->assertSame(['distance', 'cause'], array_keys($log->metadata), 'verify_ambiguous: solo la distancia propia y el motivo.');
        }

        $haystacks = [
            'respuestas' => implode("\n", $this->bodies),
            'trazas' => $audits->map(fn ($l) => json_encode($l->metadata))->implode("\n"),
            'auditoría del CRM' => AuditLog::query()->get()->map(fn ($l) => json_encode($l->toArray()))->implode("\n"),
            'logs' => implode("\n", $logged),
        ];
        foreach ($haystacks as $where => $text) {
            foreach ([self::JUAN_PIN, self::CAMILO_PIN, self::WRONG_PIN] as $pin) {
                $this->assertStringNotContainsString($pin, $text, "Un PIN aparece en {$where}.");
            }
        }
        // Respuestas: ni plantillas, ni vectores, ni distancias.
        $keys = [];
        foreach ($this->bodies as $body) {
            $this->collectKeys(json_decode($body, true) ?? [], $keys);
        }
        $this->assertSame([], array_values(array_intersect(['distance', 'template', 'embedding', 'face_reference_id', 'pin_hash', 'matches'], array_keys($keys))), 'Claves prohibidas en las respuestas.');
    }

    /** @param array<string, true> $keys */
    private function collectKeys(mixed $data, array &$keys): void
    {
        if (! is_array($data)) {
            return;
        }
        foreach ($data as $k => $v) {
            if (is_string($k)) {
                $keys[$k] = true;
            }
            $this->collectKeys($v, $keys);
        }
    }
}
