<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\MemberBiometric;
use App\Models\MemberDeviceBinding;
use App\Models\MemberSecurityEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Perfil → Registro facial (member/face-enrollment).
 *
 * Solo el titular, con la sesión que le dio un OTP real en ese equipo, con la
 * autorización biométrica vigente y solo si su cuenta nunca tuvo rostro.
 * Lo demás —cambiar, volver a registrar, menores, cuentas sin SMS— es de
 * recepción. La ruta pública del alta (token de la APK) sigue cerrada.
 */
class MemberFaceSelfEnrollmentTest extends TestCase
{
    use RefreshDatabase;

    private const APK_TOKEN = 'apk-shared-registration-token';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['services.member_registration.token' => self::APK_TOKEN]);
    }

    private function member(string $document, ?string $phone = '3001234567', array $extra = []): Member
    {
        $user = User::create([
            'name' => "Socio {$document}",
            'email' => "s{$document}@example.com",
            'password' => 'secret',
            'document' => $document,
            'phone' => $phone,
            'status' => 'active',
            'plan' => 'Mensual',
            'membership_end_date' => now()->addMonth()->toDateString(),
        ]);

        return Member::create(array_merge([
            'user_id' => $user->id,
            'full_name' => "Socio {$document}",
            'email' => "s{$document}@example.com",
            'document_number' => $document,
            'phone' => $phone,
            'access_hash' => 'hash-'.$document,
            'status' => Member::STATUS_ACTIVE,
            'biometric_status' => Member::BIOMETRIC_PENDING,
        ], $extra));
    }

    /** Login real: documento → OTP (driver dev) → token de sesión del equipo. */
    private function loginWithOtp(Member $member, string $device = 'device-A'): string
    {
        $login = $this->postJson('/api/members/login', [
            'document_number' => $member->document_number,
            'device_id' => $device,
        ])->assertOk()->assertJsonPath('data.requires_otp', true);

        $verify = $this->postJson('/api/members/login/verify', [
            'challenge_id' => $login->json('data.challenge_id'),
            'code' => $login->json('data.dev_code'),
            'device_id' => $device,
        ])->assertOk();

        $token = $verify->json('data.token');
        $this->assertNotEmpty($token);

        return $token;
    }

    private function bearer(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }

    private function enrollPayload(array $override = []): array
    {
        return array_merge([
            'face' => UploadedFile::fake()->image('face.jpg', 480, 640),
            'consent_accepted' => '1',
            'consent_version' => config('members.biometric_consent.version'),
            'captured_at' => now()->toIso8601String(),
            'normalizer_version' => 'face_norm_v1',
            'platform' => 'android',
        ], $override);
    }

    private function enroll(string $token, array $override = [])
    {
        return $this->post('/api/member/face-enrollment', $this->enrollPayload($override), $this->bearer($token) + ['Accept' => 'application/json']);
    }

    private function existingFace(Member $member, string $status = MemberBiometric::STATUS_ACTIVE): MemberBiometric
    {
        Storage::disk('local')->put("members/{$member->member_uuid}/biometrics/faces/original.jpg", 'rostro-original');
        $member->forceFill(['biometric_status' => Member::BIOMETRIC_REGISTERED])->save();

        return MemberBiometric::create([
            'member_id' => $member->id,
            'face_path' => "members/{$member->member_uuid}/biometrics/faces/original.jpg",
            'face_mime' => 'image/jpeg',
            'biometric_reference_status' => $status,
        ]);
    }

    private function assertNoFaceStored(Member $member): void
    {
        $this->assertSame(0, MemberBiometric::where('member_id', $member->id)->count());
        $this->assertSame([], Storage::disk('local')->allFiles("members/{$member->member_uuid}"));
    }

    // ── 1. Socio del CRM sin rostro: OTP + autorización → primer rostro ─────

    public function test_socio_sin_rostro_registra_su_primer_rostro_tras_el_otp(): void
    {
        $member = $this->member('1001001001');
        $token = $this->loginWithOtp($member);

        $this->getJson('/api/member/face-enrollment', $this->bearer($token))
            ->assertOk()
            ->assertJsonPath('data.status', 'not_registered')
            ->assertJsonPath('data.can_enroll', true)
            ->assertJsonPath('data.consent.version', config('members.biometric_consent.version'));

        $this->enroll($token)
            ->assertCreated()
            ->assertJsonPath('code', 'face_registered')
            ->assertJsonPath('data.biometric_status', Member::BIOMETRIC_REGISTERED);

        $bio = MemberBiometric::where('member_id', $member->id)->sole();
        $this->assertSame(MemberBiometric::STATUS_ACTIVE, $bio->biometric_reference_status);
        $this->assertSame(config('members.biometric_consent.version'), $bio->consent_version);
        $this->assertNotNull($bio->consent_accepted_at);
        $this->assertSame('app_profile', $bio->enrolled_via);
        $this->assertSame('face_norm_v1', $bio->normalizer_version);
        Storage::disk('local')->assertExists($bio->face_path);
        $this->assertSame(Member::BIOMETRIC_REGISTERED, $member->fresh()->biometric_status);

        $event = MemberSecurityEvent::where('member_id', $member->id)
            ->where('type', MemberSecurityEvent::TYPE_FACE_SELF_ENROLLED)->sole();
        $this->assertSame('device-A', $event->device_id);

        // Lo que baja LocalGym (y el CRM): el catálogo y la imagen, con sesión de persona.
        $admin = $this->actingAsAdmin();
        $ref = collect($this->getJson('/api/attendances/face-references', $admin)->assertOk()->json('data'))
            ->firstWhere('member_id', $member->id);
        $this->assertNotNull($ref, 'El rostro nuevo debe estar en face-references.');
        $this->get("/api/attendances/face-image/{$member->user_id}", $admin)->assertOk();
    }

    // ── 2. Con rostro: ni otro ni sustitución ───────────────────────────────

    public function test_socio_con_rostro_no_registra_otro_ni_lo_sustituye(): void
    {
        $member = $this->member('1001001002');
        $token = $this->loginWithOtp($member);
        $original = $this->existingFace($member);

        $this->getJson('/api/member/face-enrollment', $this->bearer($token))
            ->assertOk()
            ->assertJsonPath('data.status', 'registered')
            ->assertJsonPath('data.can_enroll', false)
            ->assertJsonPath('data.code', 'face_already_registered')
            ->assertJsonPath('data.consent', null);

        $this->enroll($token)
            ->assertStatus(409)
            ->assertJsonPath('code', 'face_already_registered')
            ->assertJsonPath('message', 'Tu rostro ya está registrado correctamente. Si necesitas cambiarlo, acércate a recepción.');

        $this->assertSame($original->face_path, MemberBiometric::where('member_id', $member->id)->sole()->face_path);
        $this->assertSame(['rostro-original'], array_map(
            fn ($f) => Storage::disk('local')->get($f),
            Storage::disk('local')->allFiles("members/{$member->member_uuid}"),
        ));
    }

    // ── 3. Sin autorización biométrica válida ───────────────────────────────

    public function test_sin_autorizacion_biometrica_no_se_guarda_nada(): void
    {
        $member = $this->member('1001001003');
        $token = $this->loginWithOtp($member);

        $this->enroll($token, ['consent_accepted' => '0'])->assertStatus(422);
        $payload = $this->enrollPayload();
        unset($payload['consent_accepted'], $payload['consent_version']);
        $this->post('/api/member/face-enrollment', $payload, $this->bearer($token) + ['Accept' => 'application/json'])
            ->assertStatus(422);
        $this->enroll($token, ['consent_version' => '2020-01-v0'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'consent_outdated');

        $this->assertNoFaceStored($member);
        $this->assertSame(Member::BIOMETRIC_PENDING, $member->fresh()->biometric_status);
    }

    // ── 4. Cuenta ajena: el socio sale de la sesión, no del cliente ─────────

    public function test_no_se_registra_un_rostro_en_otra_cuenta(): void
    {
        $owner = $this->member('1001001004');
        $victim = $this->member('1001001005', '3009998877');
        $token = $this->loginWithOtp($owner);

        // Un member_id en el cuerpo no cambia de quién es el rostro.
        $this->enroll($token, ['member_id' => $victim->id])->assertCreated();
        $this->assertSame(1, MemberBiometric::where('member_id', $owner->id)->count());
        $this->assertNoFaceStored($victim);

        // Y la ruta pública del alta sigue cerrada para la cuenta activa ajena.
        $this->post("/api/members/{$victim->id}/biometric", [
            'face' => UploadedFile::fake()->image('face.jpg'),
        ], ['Authorization' => 'Bearer '.self::APK_TOKEN, 'Accept' => 'application/json'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'registration_closed');
        $this->assertNoFaceStored($victim);
    }

    // ── 5. Token de la APK, access_hash o sesión sin OTP ────────────────────

    public function test_sin_sesion_individual_con_otp_se_rechaza(): void
    {
        $member = $this->member('1001001006');

        // Sin token y con el token compartido de la APK: no hay sesión.
        $this->post('/api/member/face-enrollment', $this->enrollPayload(), ['Accept' => 'application/json'])->assertStatus(401);
        $this->enroll(self::APK_TOKEN)->assertStatus(401);

        // access_hash del alta: autentica, pero no prueba un OTP.
        $this->getJson('/api/member/face-enrollment', $this->bearer($member->access_hash))
            ->assertOk()
            ->assertJsonPath('data.can_enroll', false)
            ->assertJsonPath('data.code', 'otp_session_required');
        $this->enroll($member->access_hash)
            ->assertStatus(403)
            ->assertJsonPath('code', 'otp_session_required');

        $this->assertNoFaceStored($member);
    }

    public function test_sesion_emitida_sin_otp_o_con_otp_vencido_se_rechaza(): void
    {
        // Socio sin teléfono: el login entra sin código (otp.skip_when_no_phone).
        $noPhone = $this->member('1001001007', null);
        $login = $this->postJson('/api/members/login', [
            'document_number' => $noPhone->document_number,
            'device_id' => 'device-sin-sms',
        ])->assertOk();
        $token = $login->json('data.token');
        $this->assertNotEmpty($token, 'El modo sin teléfono emite sesión sin OTP.');

        $this->enroll($token)->assertStatus(403)->assertJsonPath('code', 'otp_session_required');
        $this->assertNoFaceStored($noPhone);

        // OTP real, pero de hace más que la ventana de revalidación.
        $member = $this->member('1001001008');
        $fresh = $this->loginWithOtp($member, 'device-viejo');
        MemberDeviceBinding::where('device_id', 'device-viejo')
            ->update(['last_otp_reauth_at' => now()->subDays((int) config('security.trusted_reauth_days', 30) + 1)]);

        $this->enroll($fresh)->assertStatus(403)->assertJsonPath('code', 'otp_session_required');
        $this->assertNoFaceStored($member);
    }

    // ── 6. Repetido: una sola plantilla ─────────────────────────────────────

    public function test_envio_repetido_no_duplica_la_plantilla(): void
    {
        $member = $this->member('1001001009');
        $token = $this->loginWithOtp($member);

        $this->enroll($token)->assertCreated();
        $this->enroll($token)->assertStatus(409)->assertJsonPath('code', 'face_already_registered');

        $this->assertSame(1, MemberBiometric::where('member_id', $member->id)->count());
        $this->assertCount(1, Storage::disk('local')->allFiles("members/{$member->member_uuid}"));
    }

    /**
     * Carrera: otra petición inserta su fila entre la comprobación y el alta.
     * SQLite no tiene SELECT … FOR UPDATE; el único de member_id es la red que
     * queda, y aquí se ve que su rechazo no deja ni fila ni fichero huérfano.
     */
    public function test_carrera_contra_el_unico_responde_409_y_no_deja_restos(): void
    {
        $member = $this->member('1001001013');
        $token = $this->loginWithOtp($member);

        MemberBiometric::creating(function (MemberBiometric $bio): void {
            DB::table('member_biometrics')->insert([
                'member_id' => $bio->member_id,
                'face_path' => 'otra/peticion.jpg',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->enroll($token)->assertStatus(409)->assertJsonPath('code', 'face_already_registered');

        $this->assertNoFaceStored($member);
        $this->assertSame(Member::BIOMETRIC_PENDING, $member->fresh()->biometric_status);
    }

    // ── 7. Revocado o borrado por recepción: no vuelve por la app ───────────

    public function test_rostro_desactivado_no_se_reactiva_desde_la_app(): void
    {
        $member = $this->member('1001001010');
        $token = $this->loginWithOtp($member);
        $this->existingFace($member, MemberBiometric::STATUS_DISABLED);
        $member->forceFill(['biometric_status' => Member::BIOMETRIC_PENDING])->save();

        $this->enroll($token)->assertStatus(409)->assertJsonPath('code', 'face_already_registered');
        $this->assertSame(
            MemberBiometric::STATUS_DISABLED,
            MemberBiometric::where('member_id', $member->id)->sole()->biometric_reference_status,
        );
    }

    public function test_el_autorregistro_es_una_sola_vez_por_cuenta(): void
    {
        $member = $this->member('1001001011');
        $token = $this->loginWithOtp($member);
        $this->enroll($token)->assertCreated();

        // Recepción borra el rostro (p. ej. no era el del titular).
        $this->deleteJson("/api/attendances/face-enrollment/{$member->id}", [], $this->actingAsAdmin())->assertOk();
        $this->assertSame(Member::BIOMETRIC_PENDING, $member->fresh()->biometric_status);

        $this->getJson('/api/member/face-enrollment', $this->bearer($token))
            ->assertJsonPath('data.can_enroll', false)
            ->assertJsonPath('data.code', 'face_enrollment_reception');
        $this->enroll($token)->assertStatus(409)->assertJsonPath('code', 'face_enrollment_reception');
        $this->assertNoFaceStored($member);
    }

    // ── Menor de edad: lo autoriza su acudiente, en recepción ───────────────

    public function test_menor_de_edad_se_remite_a_recepcion(): void
    {
        $member = $this->member('1001001012', '3001112233', ['is_minor' => true]);
        $token = $this->loginWithOtp($member);

        $this->enroll($token)->assertStatus(403)->assertJsonPath('code', 'minor_requires_guardian');
        $this->assertNoFaceStored($member);
    }
}
