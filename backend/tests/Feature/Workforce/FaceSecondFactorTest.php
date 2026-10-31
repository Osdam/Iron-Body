<?php

namespace Tests\Feature\Workforce;

use App\Exceptions\WorkforceException;
use App\Models\ClassSession;
use App\Models\FaceDuplicateMatch;
use App\Models\FaceLookalike;
use App\Models\FaceLookalikeException;
use App\Models\Identity;
use App\Models\MyClass;
use App\Models\Trainer;
use App\Models\TrainerAuditLog;
use App\Models\TrainerFaceReference;
use App\Models\TrainerRole;
use App\Models\WorkforcePin;
use App\Services\Trainer\TrainerSessionService;
use App\Services\Workforce\LookalikeRegistry;
use App\Support\Workforce\FaceVector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Workforce\Concerns\WorkforceKit;
use Tests\TestCase;

/**
 * Rostros que el modelo no separa (contrato §10). Caso real 2026-10-26: un
 * entrenador («Camilo») no podía registrar su rostro porque quedaba a 0,91 de
 * la plantilla de otro («Juan», el entrenador 5), con el umbral en 1,0.
 *
 * Geometría EXACTA en vez de rostros aleatorios: Juan es un eje, Camilo está a
 * 0,91 de él, y cada prueba comprueba sus distancias antes de afirmar nada.
 * Lo que estas pruebas NO pueden demostrar: cómo se reparten las distancias
 * de rostros reales (eso lo mide la calibración sobre producción, no esto).
 */
class FaceSecondFactorTest extends TestCase
{
    use RefreshDatabase;
    use WorkforceKit;

    private const LUNES = '2026-10-12';

    private const JUAN_DOC = '1075000005';

    private const CAMILO_DOC = '1075305903';

    private const JUAN_PIN = '482913';

    private const CAMILO_PIN = '739164';

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
        // Planta + funcional, como los dos entrenadores reales (el funcional opera clases).
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

    /** Juan: el eje 0. */
    private function juanFace(): array
    {
        return $this->v(1.0);
    }

    /** Camilo: a 0,91 de Juan en el plano de los ejes 0 y 1. */
    private function camiloFace(): array
    {
        $c = 1 - (0.91 ** 2) / 2;

        return $this->v($c, sqrt(1 - $c * $c));
    }

    private function d(array $a, array $b): float
    {
        return (float) FaceVector::distance($a, $b);
    }

    // ── Flujos ──────────────────────────────────────────────────────────────

    /** @param list<array> $captures */
    private function enrollWith(Identity $identity, string $document, array $captures, array $tablet): TestResponse
    {
        $code = $this->postJson("/api/admin/biometrics/identities/{$identity->uuid}/enrollment-code", ['reason' => 'Registro en recepción'], $this->adminWithPermissions(['biometrics.manage']))
            ->assertCreated()->json('code');
        $start = $this->signed('POST', '/api/kiosk/enrollment/start', ['employee_ref' => $this->ref($document, $tablet), 'authorization_code' => $code], $tablet)->assertOk();

        return $this->signed('POST', '/api/kiosk/enrollment/complete', [
            'enrollment_token' => $start->json('enrollment.token'),
            'consent_version' => $start->json('consent.version'),
            'consent_accepted' => true,
            'embeddings' => $captures,
            'operation_id' => (string) Str::uuid(),
        ], $tablet);
    }

    private function enrollFace(Trainer $who, string $document, array $face, array $tablet): TestResponse
    {
        return $this->enrollWith($who->identity, $document, [$face, $face, $face], $tablet);
    }

    private function verifyAs(string $document, array $probe, array $tablet, ?string $pin = null, string $purpose = 'workday'): TestResponse
    {
        return $this->signed('POST', '/api/kiosk/verify', array_filter([
            'employee_ref' => $this->ref($document, $tablet),
            'purpose' => $purpose,
            'embedding' => $probe,
            'pin' => $pin,
        ], fn ($v) => $v !== null), $tablet);
    }

    private function pinCode(Identity $identity): string
    {
        return $this->postJson("/api/admin/biometrics/identities/{$identity->uuid}/pin-code", ['reason' => 'Crear su PIN en recepción'], $this->adminWithPermissions(['biometrics.manage']))
            ->assertCreated()->json('code');
    }

    private function setPin(Trainer $who, string $document, array $face, string $pin, array $tablet): TestResponse
    {
        return $this->signed('POST', '/api/kiosk/pin/setup', [
            'employee_ref' => $this->ref($document, $tablet),
            'authorization_code' => $this->pinCode($who->identity),
            'embedding' => $face,
            'pin' => $pin,
        ], $tablet);
    }

    private function grantException(Trainer $subject, Trainer $other, array $overrides = [], array $permissions = ['biometrics.manage']): TestResponse
    {
        return $this->postJson("/api/admin/biometrics/identities/{$subject->identity->uuid}/lookalike-exception", array_merge([
            'other_identity' => $other->identity->uuid,
            'subject_verified_in_person' => true,
            'other_verified_in_person' => true,
            'reason' => 'Comprobados los dos en recepción con su cédula',
        ], $overrides), $this->adminWithPermissions($permissions));
    }

    /** Juan registrado y Camilo rechazado por parecido: el estado real de producción. */
    private function pairLikeProduction(array $tablet): void
    {
        $this->enrollFace($this->juan, self::JUAN_DOC, $this->juanFace(), $tablet)->assertCreated();
        $this->enrollFace($this->camilo, self::CAMILO_DOC, $this->camiloFace(), $tablet)
            ->assertStatus(409)->assertJsonPath('code', 'face_already_linked');
    }

    private function assertSecondFactorRequired(TestResponse $res, string $pinStatus): void
    {
        $res->assertStatus(422)
            ->assertJsonPath('code', 'face_no_match')
            ->assertJsonPath('second_factor.required', true)
            ->assertJsonPath('second_factor.type', 'pin')
            ->assertJsonPath('second_factor.status', $pinStatus)
            ->assertJsonMissingPath('verification')
            ->assertJsonMissingPath('trainer_session');
    }

    // ── Requisito 7: antes de registrar a Camilo ─────────────────────────────

    public function test_antes_de_registrar_a_camilo_su_rostro_no_entra_como_el_entrenador_5(): void
    {
        $tablet = $this->tablet();
        $this->pairLikeProduction($tablet);
        $this->assertEqualsWithDelta(0.91, $this->d($this->juanFace(), $this->camiloFace()), 0.0001, 'Premisa: dentro del umbral 1,0.');

        // El rechazo dejó el par registrado (y su traza en las dos personas).
        $this->assertTrue(FaceLookalike::query()->where('identity_low_id', min($this->juan->identity_id, $this->camilo->identity_id))->exists());
        $this->assertSame(2, TrainerAuditLog::query()->where('event', 'biometric.lookalike_detected')->count());

        // Camilo se presenta como Juan, con su propia cara: el rostro solo no basta.
        $this->assertSecondFactorRequired($this->verifyAs(self::JUAN_DOC, $this->camiloFace(), $tablet), 'not_set');
        $this->assertSecondFactorRequired($this->verifyAs(self::JUAN_DOC, $this->camiloFace(), $tablet, purpose: 'trainer_session'), 'not_set');

        // Con el PIN de Juan creado, un PIN adivinado no pasa…
        $this->setPin($this->juan, self::JUAN_DOC, $this->juanFace(), self::JUAN_PIN, $tablet)->assertCreated();
        $this->assertSecondFactorRequired($this->verifyAs(self::JUAN_DOC, $this->camiloFace(), $tablet), 'active');
        $this->verifyAs(self::JUAN_DOC, $this->camiloFace(), $tablet, '135792')->assertStatus(422)->assertJsonPath('code', 'pin_invalid');
        // …y Juan, con su cara y su PIN, sí.
        $this->verifyAs(self::JUAN_DOC, $this->juanFace(), $tablet, self::JUAN_PIN)->assertOk()->assertJsonPath('verification.method', 'face_pin');

        // Contraste: sin el par, ESTE MISMO rostro de Camilo entraría como Juan
        // (0,91 < 1,0 y nadie más cerca). El par es lo que lo impide.
        FaceLookalike::query()->delete();
        $this->verifyAs(self::JUAN_DOC, $this->camiloFace(), $tablet)->assertOk()->assertJsonPath('verification.method', 'face');
    }

    public function test_antes_de_registrar_a_camilo_tampoco_opera_las_clases_del_entrenador_5(): void
    {
        $tablet = $this->tablet();
        $this->pairLikeProduction($tablet);
        $this->setPin($this->juan, self::JUAN_DOC, $this->juanFace(), self::JUAN_PIN, $tablet)->assertCreated();
        $clase = $this->classOf($this->juan);
        $bearer = ['Authorization' => 'Bearer '.app(TrainerSessionService::class)->issueSession($this->juan, ['device_id' => 'kiosk:test', 'device_name' => 'Tablet', 'platform' => 'android'])['token']];

        $this->assertSecondFactorRequired(
            $this->signed('POST', "/api/trainer/classes/{$clase->id}/start", ['session_date' => self::LUNES, 'embedding' => $this->camiloFace()], $tablet, $bearer),
            'active',
        );
        $this->signed('POST', "/api/trainer/classes/{$clase->id}/start", ['session_date' => self::LUNES, 'embedding' => $this->camiloFace(), 'pin' => '135792'], $tablet, $bearer)
            ->assertStatus(422)->assertJsonPath('code', 'pin_invalid');
        $this->assertSame(0, ClassSession::count(), 'Nada se inicia sin el segundo factor.');

        $this->signed('POST', "/api/trainer/classes/{$clase->id}/start", ['session_date' => self::LUNES, 'embedding' => $this->juanFace(), 'pin' => self::JUAN_PIN], $tablet, $bearer)
            ->assertOk()->assertJsonPath('session.start_identity_method', 'face_pin');
    }

    // ── Requisitos 3, 4 y 7: excepción presencial y después ────────────────

    public function test_con_la_excepcion_presencial_camilo_se_registra_y_ninguno_entra_como_el_otro(): void
    {
        $tablet = $this->tablet();
        $this->pairLikeProduction($tablet);

        $this->grantException($this->camilo, $this->juan)->assertCreated()->assertJsonStructure(['exception' => ['expires_at']]);
        $this->enrollFace($this->camilo, self::CAMILO_DOC, $this->camiloFace(), $tablet)->assertCreated()
            ->assertJsonPath('biometric.status', 'active');

        $exception = FaceLookalikeException::query()->sole();
        $this->assertNotNull($exception->consumed_at, 'La excepción es de un solo uso.');
        $this->assertSame(TrainerFaceReference::query()->where('identity_id', $this->camilo->identity_id)->value('id'), (int) $exception->consumed_reference_id);
        $this->assertSame([$exception->id], TrainerAuditLog::query()->where('event', 'biometric.enrolled')->latest('id')->first()->metadata['lookalike_exceptions']);
        $this->assertSame(1, FaceLookalike::count(), 'El par sigue: los dos necesitan rostro + PIN.');

        $this->setPin($this->juan, self::JUAN_DOC, $this->juanFace(), self::JUAN_PIN, $tablet)->assertCreated();
        $this->setPin($this->camilo, self::CAMILO_DOC, $this->camiloFace(), self::CAMILO_PIN, $tablet)->assertCreated();

        // Ninguno entra como el otro con su cara, ni con su propio PIN.
        $this->assertSecondFactorRequired($this->verifyAs(self::JUAN_DOC, $this->camiloFace(), $tablet), 'active');
        $this->assertSecondFactorRequired($this->verifyAs(self::CAMILO_DOC, $this->juanFace(), $tablet), 'active');
        $this->verifyAs(self::JUAN_DOC, $this->camiloFace(), $tablet, self::CAMILO_PIN)->assertStatus(422)->assertJsonPath('code', 'pin_invalid');
        $this->verifyAs(self::CAMILO_DOC, $this->juanFace(), $tablet, self::JUAN_PIN)->assertStatus(422)->assertJsonPath('code', 'pin_invalid');

        // Cada uno, con su cara y su PIN.
        $this->verifyAs(self::JUAN_DOC, $this->juanFace(), $tablet, self::JUAN_PIN)->assertOk()->assertJsonPath('verification.method', 'face_pin');
        $this->verifyAs(self::CAMILO_DOC, $this->camiloFace(), $tablet, self::CAMILO_PIN)->assertOk()->assertJsonPath('verification.method', 'face_pin');
        $this->verifyAs(self::CAMILO_DOC, $this->camiloFace(), $tablet, self::CAMILO_PIN, 'trainer_session')->assertOk()->assertJsonStructure(['trainer_session' => ['token']]);

        // Y su propia cara sola tampoco basta: el par exige siempre el PIN.
        $this->assertSecondFactorRequired($this->verifyAs(self::CAMILO_DOC, $this->camiloFace(), $tablet), 'active');
    }

    public function test_la_excepcion_exige_permiso_dos_comprobaciones_presenciales_y_un_par_registrado(): void
    {
        $tablet = $this->tablet();
        $this->pairLikeProduction($tablet);
        $otro = $this->trainerPerson('1075000077', [TrainerRole::FLOOR], 'Otra Persona');
        $this->enrollFace($otro, '1075000077', $this->v(0.0, 0.0, 1.0), $tablet)->assertCreated();

        $this->grantException($this->camilo, $this->juan, permissions: ['biometrics.view'])->assertForbidden();
        $this->grantException($this->camilo, $this->juan, ['subject_verified_in_person' => false])->assertStatus(422)->assertJsonValidationErrors('subject_verified_in_person');
        $this->grantException($this->camilo, $this->juan, ['other_verified_in_person' => false])->assertStatus(422)->assertJsonValidationErrors('other_verified_in_person');
        // Exactamente `true`: ni «yes» ni 1 (revisión 2026-10-27, I-3).
        $this->grantException($this->camilo, $this->juan, ['subject_verified_in_person' => 'yes'])->assertStatus(422)->assertJsonValidationErrors('subject_verified_in_person');
        $this->grantException($this->camilo, $this->juan, ['other_verified_in_person' => 1])->assertStatus(422)->assertJsonValidationErrors('other_verified_in_person');
        $this->grantException($this->camilo, $this->juan, ['reason' => 'corto'])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->grantException($this->camilo, $otro)->assertStatus(409)->assertJsonPath('code', 'lookalike_not_registered');
        $this->grantException($this->juan, $this->camilo)->assertStatus(409)->assertJsonPath('code', 'lookalike_other_not_enrolled');
        $this->assertSame(0, FaceLookalikeException::count());
    }

    public function test_la_excepcion_caduca_y_solo_cubre_a_la_otra_persona_del_par(): void
    {
        $tablet = $this->tablet();
        $this->pairLikeProduction($tablet);

        // Caduca a los 30 minutos.
        $this->grantException($this->camilo, $this->juan)->assertCreated();
        Carbon::setTestNow(now()->addMinutes(31));
        $this->enrollFace($this->camilo, self::CAMILO_DOC, $this->camiloFace(), $tablet)->assertStatus(409)->assertJsonPath('code', 'face_already_linked');

        // No cubre a una tercera persona que también se parece a Camilo.
        $k = $this->v(0.01745, 0.99985);
        $this->assertGreaterThan(1.0, $this->d($k, $this->juanFace()), 'Premisa: la tercera no choca con Juan.');
        $this->assertLessThan(1.0, $this->d($k, $this->camiloFace()), 'Premisa: sí con Camilo.');
        $tercera = $this->trainerPerson('1075000088', [TrainerRole::FLOOR], 'Tercera Persona');
        $this->enrollFace($tercera, '1075000088', $k, $tablet)->assertCreated();
        $this->grantException($this->camilo, $this->juan)->assertCreated();
        $this->enrollFace($this->camilo, self::CAMILO_DOC, $this->camiloFace(), $tablet)->assertStatus(409)->assertJsonPath('code', 'face_already_linked');
        $this->assertNull(FaceLookalikeException::query()->latest('id')->first()->consumed_at, 'Un rechazo no gasta la excepción.');
    }

    public function test_la_excepcion_no_vale_para_una_cara_que_es_practicamente_la_del_otro(): void
    {
        $tablet = $this->tablet();
        $this->pairLikeProduction($tablet);
        $this->grantException($this->camilo, $this->juan)->assertCreated();

        // A 0,50 de Juan: más cerca que sus propias verificaciones. Es su cara.
        $casiJuan = $this->v(0.875, sqrt(1 - 0.875 ** 2));
        $this->assertEqualsWithDelta(0.5, $this->d($casiJuan, $this->juanFace()), 0.0001);
        $this->enrollFace($this->camilo, self::CAMILO_DOC, $casiJuan, $tablet)->assertStatus(409)->assertJsonPath('code', 'face_already_linked');
        $this->assertNull(TrainerFaceReference::query()->where('identity_id', $this->camilo->identity_id)->where('status', 'active')->first());
    }

    public function test_una_excepcion_gastada_caducada_o_sin_par_no_vale(): void
    {
        // La red ante carreras y el caso sin par (revisión 2026-10-27, I-4).
        $tablet = $this->tablet();
        $this->pairLikeProduction($tablet);
        $registry = app(LookalikeRegistry::class);
        $this->grantException($this->camilo, $this->juan)->assertCreated();
        $exception = FaceLookalikeException::query()->sole();
        $refId = (int) TrainerFaceReference::query()->where('identity_id', $this->juan->identity_id)->value('id');

        $exception->forceFill(['consumed_at' => now()])->save();
        try {
            $registry->consumeExceptions([$exception->id], $refId);
            $this->fail('Una excepción ya usada no puede consumirse otra vez.');
        } catch (WorkforceException $e) {
            $this->assertSame('face_already_linked', $e->code_);
        }

        // Sin par registrado, una excepción viva no cuenta.
        $this->grantException($this->camilo, $this->juan)->assertCreated();
        $this->assertCount(1, $registry->liveExceptions($this->camilo->identity_id));
        FaceLookalike::query()->delete();
        $this->assertSame([], $registry->liveExceptions($this->camilo->identity_id));
    }

    public function test_la_excepcion_nunca_interviene_al_verificar(): void
    {
        $tablet = $this->tablet();
        $this->pairLikeProduction($tablet);
        // Una excepción viva y sin usar no cambia nada al verificar.
        $this->grantException($this->camilo, $this->juan)->assertCreated();

        $this->assertSecondFactorRequired($this->verifyAs(self::JUAN_DOC, $this->camiloFace(), $tablet), 'not_set');
        $this->assertSecondFactorRequired($this->verifyAs(self::JUAN_DOC, $this->juanFace(), $tablet), 'not_set');
    }

    // ── Requisito 1: ambigüedad para todos (sin par) ─────────────────────────

    public function test_una_captura_que_coincide_con_dos_personas_pide_el_pin(): void
    {
        $tablet = $this->tablet();
        $ana = $this->receptionist('1075000001');
        $bea = $this->receptionist('1075000002', 'Bea Torres');
        $a = $this->v(1.0);
        $c = 1 - (1.3 ** 2) / 2;
        $b = $this->v($c, sqrt(1 - $c * $c));
        $this->enrollWith($ana, '1075000001', [$a, $a, $a], $tablet)->assertCreated();
        $this->enrollWith($bea, '1075000002', [$b, $b, $b], $tablet)->assertCreated();

        $entreLasDos = $this->v(1.0 + $c, sqrt(1 - $c * $c));
        $this->assertLessThan(1.0, $this->d($entreLasDos, $a));
        $this->assertLessThan(1.0, $this->d($entreLasDos, $b));

        $this->assertSecondFactorRequired($this->verifyAs('1075000001', $entreLasDos, $tablet), 'not_set');
        $this->assertSame('coincidence', TrainerAuditLog::query()->where('event', 'biometric.verify_ambiguous')->sole()->metadata['cause']);
        // Una captura clara de Ana sigue entrando con el rostro solo.
        $this->verifyAs('1075000001', $a, $tablet)->assertOk()->assertJsonPath('verification.method', 'face');
        $this->assertSame(0, FaceLookalike::count(), 'Una captura ambigua no crea un par: solo un registro rechazado.');
    }

    public function test_sin_margen_suficiente_frente_a_otra_persona_pide_el_pin_y_el_margen_es_configurable(): void
    {
        $tablet = $this->tablet();
        $ana = $this->receptionist('1075000001');
        $bea = $this->receptionist('1075000002', 'Bea Torres');
        $a = $this->v(1.0);
        $c = 1 - (1.25 ** 2) / 2;
        $b = $this->v($c, sqrt(1 - $c * $c));
        $this->enrollWith($ana, '1075000001', [$a, $a, $a], $tablet)->assertCreated();
        $this->enrollWith($bea, '1075000002', [$b, $b, $b], $tablet)->assertCreated();

        // Propia 0,85; la otra 1,02: no coincide con Bea, pero el margen (0,17) < 0,20.
        $probe = $this->v(0.63875, 0.34851, 0.68596);
        $this->assertEqualsWithDelta(0.85, $this->d($probe, $a), 0.001);
        $this->assertEqualsWithDelta(1.02, $this->d($probe, $b), 0.001);

        $this->assertSecondFactorRequired($this->verifyAs('1075000001', $probe, $tablet), 'not_set');
        $this->assertSame('margin', TrainerAuditLog::query()->where('event', 'biometric.verify_ambiguous')->sole()->metadata['cause']);

        config(['workforce.face.ambiguity_margin' => 0.10]);
        $this->verifyAs('1075000001', $probe, $tablet)->assertOk()->assertJsonPath('verification.method', 'face');
    }

    public function test_el_umbral_global_sigue_en_1(): void
    {
        $tablet = $this->tablet();
        $this->enrollFace($this->juan, self::JUAN_DOC, $this->juanFace(), $tablet)->assertCreated();
        $a99 = $this->v(1 - (0.99 ** 2) / 2, sqrt(1 - (1 - (0.99 ** 2) / 2) ** 2));
        $a101 = $this->v(1 - (1.01 ** 2) / 2, sqrt(1 - (1 - (1.01 ** 2) / 2) ** 2));

        $this->verifyAs(self::JUAN_DOC, $a99, $tablet)->assertOk();
        $this->verifyAs(self::JUAN_DOC, $a101, $tablet)->assertStatus(422)->assertJsonPath('code', 'face_no_match')->assertJsonMissingPath('second_factor');
    }

    // ── PIN ─────────────────────────────────────────────────────────────────

    public function test_crear_el_pin_exige_el_codigo_de_pin_un_pin_fuerte_y_la_propia_cara_sin_gastar_el_codigo_en_vano(): void
    {
        $tablet = $this->tablet();
        $this->enrollFace($this->juan, self::JUAN_DOC, $this->juanFace(), $tablet)->assertCreated();
        // Una sola referencia: `identify` admite 20 por minuto y dispositivo.
        $ref = $this->ref(self::JUAN_DOC, $tablet);
        $body = fn (string $code, array $face, string $pin) => [
            'employee_ref' => $ref, 'authorization_code' => $code, 'embedding' => $face, 'pin' => $pin,
        ];

        // Un código de enrolamiento no sirve para el PIN.
        $enrollCode = $this->postJson("/api/admin/biometrics/identities/{$this->juan->identity->uuid}/enrollment-code", ['reason' => 'Otra cosa'], $this->adminWithPermissions(['biometrics.manage']))->json('code');
        $this->signed('POST', '/api/kiosk/pin/setup', $body($enrollCode, $this->juanFace(), self::JUAN_PIN), $tablet)
            ->assertStatus(422)->assertJsonPath('code', 'authorization_code_not_issued');

        // Sin código vivo, la cara ni se compara: no gasta intentos faciales (B-1).
        $this->signed('POST', '/api/kiosk/pin/setup', $body('12345678', $this->v(0.0, 0.0, 1.0), self::JUAN_PIN), $tablet)
            ->assertStatus(422)->assertJsonPath('code', 'authorization_code_not_issued');
        $this->assertSame(0, RateLimiter::attempts('workforce-face:pin-setup:identity:'.$this->juan->identity_id));

        $code = $this->pinCode($this->juan->identity);
        $weakPins = [
            '111111', '123456', '654321', '012345', '135791',          // repetidos y series
            '121212', '112233', '111222', '123123', '123321',          // patrones y capicúas
            '147258', '159753', '789456',                              // recorridos del teclado
            '251290', '122590', '901225', '199001', '061995',          // fechas
            '102030', '100000',                                        // comunes
            substr(self::JUAN_DOC, -6), substr(self::JUAN_DOC, 0, 6),  // su documento
        ];
        foreach ($weakPins as $weak) {
            $this->signed('POST', '/api/kiosk/pin/setup', $body($code, $this->juanFace(), $weak), $tablet)
                ->assertStatus(422)->assertJsonPath('code', 'pin_weak');
        }
        $this->signed('POST', '/api/kiosk/pin/setup', $body($code, $this->juanFace(), '12345'), $tablet)->assertStatus(422)->assertJsonValidationErrors('pin');
        // La batería de arriba agota el cupo del kiosko (60/min por dispositivo).
        Carbon::setTestNow(now()->addMinutes(2));
        // Otra cara (Camilo no está registrado y queda a 1,3 de Juan): no.
        $lejos = $this->v(1 - (1.3 ** 2) / 2, sqrt(1 - (1 - (1.3 ** 2) / 2) ** 2));
        $this->signed('POST', '/api/kiosk/pin/setup', $body($code, $lejos, self::JUAN_PIN), $tablet)
            ->assertStatus(422)->assertJsonPath('code', 'face_no_match');

        // El código sigue vivo: nada de lo anterior lo gastó.
        $this->signed('POST', '/api/kiosk/pin/setup', $body($code, $this->juanFace(), self::JUAN_PIN), $tablet)
            ->assertCreated()->assertJsonPath('pin.status', 'active');

        $pin = WorkforcePin::query()->sole();
        $this->assertStringNotContainsString(self::JUAN_PIN, (string) $pin->pin_hash);
        $this->assertSame(1, TrainerAuditLog::query()->where('event', 'biometric.pin_set')->count());
        $this->identify(self::JUAN_DOC, $tablet)->assertJsonPath('employee.pin.status', 'active');
    }

    /** Un PIN equivocado con la cara de Camilo, como quien prueba el de Juan. */
    private function wrongPinAsJuan(array $tablet): TestResponse
    {
        // El contador facial por persona no debe cortar la prueba del PIN.
        RateLimiter::clear('workforce-face:kiosk:identity:'.$this->juan->identity_id);

        return $this->verifyAs(self::JUAN_DOC, $this->camiloFace(), $tablet, '135792');
    }

    private function juanEnters(array $tablet): TestResponse
    {
        RateLimiter::clear('workforce-face:kiosk:identity:'.$this->juan->identity_id);

        return $this->verifyAs(self::JUAN_DOC, $this->juanFace(), $tablet, self::JUAN_PIN);
    }

    public function test_el_acierto_del_titular_no_borra_los_fallos_y_el_bloqueo_escala(): void
    {
        // Revisión de seguridad 2026-10-27 (M-1): si acertar ponía a cero los
        // fallos, Camilo probaba PIN de Juan sin fin entre sus accesos.
        $tablet = $this->tablet();
        $this->pairLikeProduction($tablet);
        $this->setPin($this->juan, self::JUAN_DOC, $this->juanFace(), self::JUAN_PIN, $tablet)->assertCreated();

        foreach (range(1, 4) as $i) {
            $this->wrongPinAsJuan($tablet)->assertStatus(422)->assertJsonPath('code', 'pin_invalid')
                ->assertJsonMissingPath('second_factor.attempts_left');
        }
        $this->juanEnters($tablet)->assertOk()->assertJsonPath('verification.method', 'face_pin');
        // Un fallo más tras el acierto de Juan: el quinto en la ventana.
        $this->wrongPinAsJuan($tablet)->assertStatus(429)->assertJsonPath('code', 'pin_locked');
        $this->juanEnters($tablet)->assertStatus(429)->assertJsonPath('code', 'pin_locked');

        // Primer bloqueo: 15 min.
        Carbon::setTestNow(now()->addMinutes(16));
        $this->juanEnters($tablet)->assertOk();

        // Segundo bloqueo en menos de 7 días: 1 h.
        foreach (range(1, 4) as $i) {
            $this->wrongPinAsJuan($tablet)->assertStatus(422);
        }
        $this->wrongPinAsJuan($tablet)->assertStatus(429);
        Carbon::setTestNow(now()->addMinutes(16));
        $this->juanEnters($tablet)->assertStatus(429)->assertJsonPath('code', 'pin_locked');
        Carbon::setTestNow(now()->addMinutes(45));
        $this->juanEnters($tablet)->assertOk();

        // Cada fallo queda anotado; el que bloquea, como bloqueo.
        $this->assertSame(8, TrainerAuditLog::query()->where('event', 'biometric.pin_failed')->count());
        $this->assertSame(2, TrainerAuditLog::query()->where('event', 'biometric.pin_locked')->count());
    }

    public function test_los_fallos_caducan_a_las_24_horas_y_un_pin_nuevo_libera_el_bloqueo(): void
    {
        $tablet = $this->tablet();
        $this->pairLikeProduction($tablet);
        $this->setPin($this->juan, self::JUAN_DOC, $this->juanFace(), self::JUAN_PIN, $tablet)->assertCreated();

        foreach (range(1, 4) as $i) {
            $this->wrongPinAsJuan($tablet)->assertStatus(422);
        }
        Carbon::setTestNow(now()->addHours(25));
        foreach (range(1, 4) as $i) {
            $this->wrongPinAsJuan($tablet)->assertStatus(422);
        }
        $this->wrongPinAsJuan($tablet)->assertStatus(429);

        // Un administrador libera el bloqueo autorizando un PIN nuevo.
        RateLimiter::clear('workforce-face:pin-setup:identity:'.$this->juan->identity_id);
        $this->setPin($this->juan, self::JUAN_DOC, $this->juanFace(), '527063', $tablet)->assertCreated();
        $pin = WorkforcePin::query()->where('identity_id', $this->juan->identity_id)->sole();
        $this->assertSame([0, 0, null], [$pin->failed_attempts, $pin->lock_level, $pin->locked_until]);
        RateLimiter::clear('workforce-face:kiosk:identity:'.$this->juan->identity_id);
        $this->verifyAs(self::JUAN_DOC, $this->juanFace(), $tablet, '527063')->assertOk();
    }

    public function test_con_el_codigo_de_uno_el_otro_del_par_no_le_cambia_el_pin(): void
    {
        // Revisión de seguridad 2026-10-27 (B-1).
        $tablet = $this->tablet();
        $this->pairLikeProduction($tablet);
        $this->grantException($this->camilo, $this->juan)->assertCreated();
        $this->enrollFace($this->camilo, self::CAMILO_DOC, $this->camiloFace(), $tablet)->assertCreated();

        // Camilo, con el código de PIN de Juan y su propia cara: no.
        $this->setPin($this->juan, self::JUAN_DOC, $this->camiloFace(), self::JUAN_PIN, $tablet)
            ->assertStatus(422)->assertJsonPath('code', 'face_no_match');
        $this->assertNull(WorkforcePin::query()->where('identity_id', $this->juan->identity_id)->first());
        // Y no puede usar el documento de Juan como PIN propio.
        $this->setPin($this->camilo, self::CAMILO_DOC, $this->camiloFace(), substr(self::JUAN_DOC, -6), $tablet)
            ->assertStatus(422)->assertJsonPath('code', 'pin_weak');
        // Juan, con su cara, sí.
        $this->setPin($this->juan, self::JUAN_DOC, $this->juanFace(), self::JUAN_PIN, $tablet)->assertCreated();
    }

    public function test_un_pin_que_llega_sin_hacer_falta_se_comprueba_igual(): void
    {
        $tablet = $this->tablet();
        $this->enrollFace($this->juan, self::JUAN_DOC, $this->juanFace(), $tablet)->assertCreated();
        $this->setPin($this->juan, self::JUAN_DOC, $this->juanFace(), self::JUAN_PIN, $tablet)->assertCreated();

        $this->verifyAs(self::JUAN_DOC, $this->juanFace(), $tablet)->assertOk()->assertJsonPath('verification.method', 'face');
        $this->verifyAs(self::JUAN_DOC, $this->juanFace(), $tablet, '135792')->assertStatus(422)->assertJsonPath('code', 'pin_invalid');
        $this->verifyAs(self::JUAN_DOC, $this->juanFace(), $tablet, self::JUAN_PIN)->assertOk()->assertJsonPath('verification.method', 'face_pin');
    }

    public function test_anular_el_pin_desde_el_crm_y_lo_que_ve_cada_permiso(): void
    {
        $tablet = $this->tablet();
        $this->pairLikeProduction($tablet);
        $this->setPin($this->juan, self::JUAN_DOC, $this->juanFace(), self::JUAN_PIN, $tablet)->assertCreated();
        $url = "/api/admin/biometrics/identities/{$this->juan->identity->uuid}";

        $view = $this->getJson($url, $this->adminWithPermissions(['biometrics.view']))->assertOk()
            ->assertJsonPath('data.pin.status', 'active')
            ->assertJsonPath('data.second_factor_always', true)
            ->assertJsonPath('data.lookalikes', []);
        $manage = $this->getJson($url, $this->adminWithPermissions(['biometrics.view', 'biometrics.manage']))->assertOk()
            ->assertJsonPath('data.lookalikes.0.identity', $this->camilo->identity->uuid)
            ->assertJsonPath('data.lookalikes.0.name', 'Camilo López');
        foreach ([$view, $manage] as $res) {
            $this->assertStringNotContainsString(self::JUAN_PIN, $res->getContent());
            $this->assertStringNotContainsString('distance', $res->getContent());
            $this->assertStringNotContainsString('pin_hash', $res->getContent());
        }

        $this->postJson("{$url}/pin/revoke", ['reason' => 'Lo olvidó'], $this->adminWithPermissions(['biometrics.view']))->assertForbidden();
        $this->postJson("{$url}/pin/revoke", ['reason' => 'Lo olvidó'], $this->adminWithPermissions(['biometrics.manage']))->assertOk()->assertJsonPath('data.status', 'revoked');
        $this->postJson("{$url}/pin/revoke", ['reason' => 'Lo olvidó'], $this->adminWithPermissions(['biometrics.manage']))->assertStatus(409)->assertJsonPath('code', 'pin_not_active');
        $this->assertNull(WorkforcePin::query()->sole()->pin_hash, 'Anular borra el PIN.');

        $this->verifyAs(self::JUAN_DOC, $this->juanFace(), $tablet, self::JUAN_PIN)->assertStatus(409)->assertJsonPath('code', 'pin_not_set');
        $this->assertSecondFactorRequired($this->verifyAs(self::JUAN_DOC, $this->juanFace(), $tablet), 'not_set');
    }

    public function test_el_codigo_de_administrador_de_jornada_sigue_valiendo_solo_para_quien_esta_en_un_par(): void
    {
        // Compatibilidad con la app publicada: ante `face_no_match` ofrece el
        // código de jornada, y ese respaldo (sin rostro) no cambia.
        $tablet = $this->tablet();
        $this->pairLikeProduction($tablet);
        $code = $this->postJson("/api/admin/workforce/staff/{$this->juan->identity->uuid}/fallback-code", ['purpose' => 'workday', 'reason' => 'Rostro ambiguo'], $this->adminWithPermissions(['workforce.manage']))
            ->assertCreated()->json('code');

        $this->signed('POST', '/api/kiosk/verify-code', ['employee_ref' => $this->ref(self::JUAN_DOC, $tablet), 'purpose' => 'workday', 'code' => $code], $tablet)
            ->assertOk()->assertJsonPath('verification.method', 'admin_code');
    }

    // ── Carga de los pares ya diagnosticados (migración) ─────────────────────

    public function test_la_migracion_carga_los_pares_de_los_rechazos_ya_diagnosticados(): void
    {
        $tablet = $this->tablet();
        $this->enrollFace($this->juan, self::JUAN_DOC, $this->juanFace(), $tablet)->assertCreated();
        // Estado de producción ANTES de esta versión: el rechazo y su
        // diagnóstico, sin par.
        $log = TrainerAuditLog::create(['actor_type' => 'system', 'identity_id' => $this->camilo->identity_id, 'event' => 'biometric.duplicate_rejected', 'metadata' => ['identity_id' => $this->camilo->identity_id, 'trainer_id' => null], 'created_at' => now()]);
        FaceDuplicateMatch::create(['audit_log_id' => $log->id, 'face_reference_id' => TrainerFaceReference::query()->sole()->id, 'distance' => 0.9147, 'created_at' => now()]);

        $migration = require database_path('migrations/2026_10_27_100001_create_biometric_second_factor_tables.php');
        $migration->down();
        $migration->up();

        $pair = FaceLookalike::query()->sole();
        $this->assertSame([min($this->juan->identity_id, $this->camilo->identity_id), max($this->juan->identity_id, $this->camilo->identity_id)], [(int) $pair->identity_low_id, (int) $pair->identity_high_id]);
        $this->assertSecondFactorRequired($this->verifyAs(self::JUAN_DOC, $this->camiloFace(), $tablet), 'not_set');
    }

    // ── Lo que ve cada permiso (revisión 2026-10-27, B-4 y B-5) ──────────────

    public function test_la_auditoria_del_entrenador_no_ensena_la_biometria_sin_permiso_de_biometria(): void
    {
        $tablet = $this->tablet();
        $this->pairLikeProduction($tablet);
        $this->assertSecondFactorRequired($this->verifyAs(self::JUAN_DOC, $this->camiloFace(), $tablet), 'not_set');
        $url = "/api/admin/trainers/{$this->juan->id}/audit";

        $sinBiometria = $this->getJson($url, $this->adminWithPermissions(['trainers.view']))->assertOk();
        $this->assertSame([], array_values(array_filter($sinBiometria->json('data.*.event'), fn ($e) => str_starts_with($e, 'biometric.'))));
        $this->assertStringNotContainsString('pair', $sinBiometria->getContent());

        $conBiometria = $this->getJson($url, $this->adminWithPermissions(['trainers.view', 'biometrics.view']))->assertOk();
        $this->assertContains('biometric.verify_ambiguous', $conBiometria->json('data.*.event'));
    }

    public function test_el_motivo_de_la_excepcion_solo_lo_ve_quien_gestiona_la_biometria(): void
    {
        $tablet = $this->tablet();
        $this->pairLikeProduction($tablet);
        $this->grantException($this->camilo, $this->juan, ['reason' => 'Comprobé en persona a Camilo López y a Juan'])->assertCreated();
        $url = "/api/admin/biometrics/identities/{$this->juan->identity->uuid}";
        $detail = fn (TestResponse $r) => collect($r->json('data.history'))->firstWhere('event', 'biometric.lookalike_exception_granted')['detail'];

        // Sin `manage`, ni el evento: su hora, igual en las dos personas, delataría la pareja.
        $view = $this->getJson($url, $this->adminWithPermissions(['biometrics.view']))->assertOk();
        $this->assertNotContains('biometric.lookalike_exception_granted', $view->json('data.history.*.event'));
        $this->assertNotContains('biometric.lookalike_detected', $view->json('data.history.*.event'));
        $this->assertStringNotContainsString('Camilo', $view->getContent());
        $manage = $this->getJson($url, $this->adminWithPermissions(['biometrics.view', 'biometrics.manage']))->assertOk();
        $this->assertSame('Comprobé en persona a Camilo López y a Juan', $detail($manage));
    }

    // ── Robustez ────────────────────────────────────────────────────────────

    public function test_dos_primeros_registros_a_la_vez_dan_409_y_no_un_500(): void
    {
        // Tester 2026-10-27 (D-3): el que perdía recibía un 500 y el log
        // guardaba el INSERT con la plantilla cifrada. Se simula que el otro
        // registro gana justo antes de nuestro INSERT.
        $tablet = $this->tablet();
        $identityId = $this->juan->identity_id;
        TrainerFaceReference::creating(function () use ($identityId): void {
            TrainerFaceReference::withoutEvents(fn () => DB::table('trainer_face_references')->insert([
                'identity_id' => $identityId, 'status' => 'active', 'template' => 'x', 'enrolled_at' => now(),
                'enrolled_via' => 'kiosk', 'consent_version' => '2026-10-v1', 'created_at' => now(), 'updated_at' => now(),
            ]));
        });

        try {
            $this->enrollFace($this->juan, self::JUAN_DOC, $this->juanFace(), $tablet)
                ->assertStatus(409)->assertJsonPath('code', 'face_already_enrolled');
        } finally {
            TrainerFaceReference::flushEventListeners();
        }
    }

    // ── Retención ───────────────────────────────────────────────────────────

    public function test_el_mantenimiento_borra_el_par_cuando_ninguno_sigue_trabajando_aqui(): void
    {
        $tablet = $this->tablet();
        $this->pairLikeProduction($tablet);

        $this->camilo->update(['status' => 'inactive']);
        $this->artisan('workforce:maintenance')->assertExitCode(0);
        $this->assertSame(1, FaceLookalike::count(), 'Juan sigue activo: el par se conserva.');

        $this->juan->update(['status' => 'inactive']);
        $this->artisan('workforce:maintenance')->assertExitCode(0);
        $this->assertSame(0, FaceLookalike::count());
    }

    public function test_desactivar_a_la_persona_anula_su_pin(): void
    {
        $tablet = $this->tablet();
        $this->enrollFace($this->juan, self::JUAN_DOC, $this->juanFace(), $tablet)->assertCreated();
        $this->setPin($this->juan, self::JUAN_DOC, $this->juanFace(), self::JUAN_PIN, $tablet)->assertCreated();

        $this->juan->update(['status' => 'inactive']);

        $this->assertSame(WorkforcePin::STATUS_REVOKED, WorkforcePin::query()->sole()->status());
        $this->assertSame(1, TrainerAuditLog::query()->where('event', 'biometric.pin_revoked')->count());
    }

    private function classOf(Trainer $trainer): MyClass
    {
        return MyClass::create([
            'name' => 'Funcional 9am', 'type' => 'Funcional', 'trainer_id' => $trainer->id, 'day_of_week' => 'Lunes',
            'start_time' => '09:00', 'end_time' => '10:00', 'max_capacity' => 20, 'status' => 'active',
            'allow_online_booking' => true, 'is_recurring' => true,
        ]);
    }
}
