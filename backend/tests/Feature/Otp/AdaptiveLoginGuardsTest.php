<?php

namespace Tests\Feature\Otp;

use App\Models\Member;
use App\Models\MemberAuthChallenge;
use App\Models\MemberDeviceBinding;
use App\Models\MemberSecurityEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Guardas del login adaptativo.
 *
 * Activar `SECURITY_ADAPTIVE_LOGIN` es la única medida del control de coste que
 * toca la postura de seguridad: en un equipo ya vinculado y limpio, la posesión
 * se demuestra con el vínculo dispositivo↔socio más la biometría local, en vez
 * de un SMS en cada entrada.
 *
 * Esta clase fija las ocho condiciones que tienen que seguir forzando OTP. No
 * están aquí para que la suite se ponga verde: están para que nadie relaje una
 * de ellas más adelante buscando ahorrar otro SMS.
 */
class AdaptiveLoginGuardsTest extends TestCase
{
    use RefreshDatabase;

    private int $envios = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'otp.driver' => 'twilio',
            'otp.twilio.sid' => 'ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
            'otp.twilio.token' => 'token-de-prueba',
            'otp.twilio.verify_service_sid' => 'VAxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
            'otp.default_country_code' => '57',
            'security.adaptive_login' => true,
        ]);

        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/Verifications')) {
                $this->envios++;

                return Http::response(['status' => 'pending'], 201);
            }

            return Http::response(['status' => 'approved'], 200);
        });
    }

    private function socio(string $documento = '5500', string $telefono = '3005500001'): Member
    {
        $user = User::create([
            'name' => 'Socio '.$documento, 'email' => $documento.'@ironbody.test',
            'password' => 'secret', 'document' => $documento, 'phone' => $telefono, 'status' => 'active',
        ]);

        return Member::create([
            'user_id' => $user->id, 'full_name' => 'Socio '.$documento,
            'email' => $documento.'@ironbody.test', 'document_number' => $documento,
            'phone' => $telefono, 'access_hash' => 'tok-'.$documento, 'status' => Member::STATUS_ACTIVE,
        ]);
    }

    private function vincular(Member $m, string $dispositivo, ?string $ultimoOtp = null): MemberDeviceBinding
    {
        return MemberDeviceBinding::create([
            'device_id' => $dispositivo,
            'member_id' => $m->id,
            'bound_at' => now()->subDays(5),
            'last_otp_reauth_at' => $ultimoOtp ? now()->parse($ultimoOtp) : now()->subDays(5),
        ]);
    }

    private function entrar(Member $m, ?string $dispositivo): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/members/login', array_filter([
            'document_number' => $m->document_number,
            'device_id' => $dispositivo,
        ]));
    }

    // ── 1 · el equipo debe estar vinculado a ESE socio ───────────────────────

    public function test_un_equipo_nunca_visto_exige_otp(): void
    {
        $socio = $this->socio();

        $this->entrar($socio, 'equipo-nuevo')->assertOk()->assertJsonPath('data.requires_otp', true);
        $this->assertSame(1, $this->envios);
    }

    public function test_sin_identificador_de_dispositivo_exige_otp(): void
    {
        $socio = $this->socio();

        $this->entrar($socio, null)->assertOk()->assertJsonPath('data.requires_otp', true);
        $this->assertSame(1, $this->envios);
    }

    public function test_un_equipo_vinculado_a_otro_socio_no_da_desbloqueo_local(): void
    {
        $ana = $this->socio('5501', '3005500011');
        $luis = $this->socio('5502', '3005500012');
        $this->vincular($ana, 'equipo-compartido');

        // Luis intenta entrar desde el equipo de Ana: el vínculo lo deniega.
        $r = $this->entrar($luis, 'equipo-compartido');

        $this->assertNotSame(200, $r->getStatusCode(), 'Un equipo ajeno no puede dar acceso.');
        $this->assertSame(0, $this->envios);
    }

    // ── 3 · señales de riesgo fuerzan OTP ────────────────────────────────────

    public function test_una_senal_adversarial_reciente_fuerza_otp_aunque_el_equipo_sea_confiable(): void
    {
        $socio = $this->socio();
        $this->vincular($socio, 'equipo-de-siempre');

        // Un solo OTP fallido en la última hora ya supera el umbral local (=1).
        MemberSecurityEvent::create([
            'member_id' => $socio->id,
            'type' => MemberSecurityEvent::TYPE_LOGIN_FAILED,
            'created_at' => now()->subMinutes(5),
        ]);

        $this->entrar($socio, 'equipo-de-siempre')->assertOk()->assertJsonPath('data.requires_otp', true);
        $this->assertSame(1, $this->envios, 'Ante riesgo, se paga el SMS sin discutir.');
    }

    public function test_sin_senales_de_riesgo_el_equipo_confiable_entra_sin_sms(): void
    {
        $socio = $this->socio();
        $this->vincular($socio, 'equipo-de-siempre');

        $this->entrar($socio, 'equipo-de-siempre')
            ->assertOk()
            ->assertJsonPath('data.requires_otp', false)
            ->assertJsonPath('data.requires_local_unlock', true);

        $this->assertSame(0, $this->envios);
    }

    // ── 4 · el OTP periódico sigue siendo obligatorio ────────────────────────

    public function test_pasados_treinta_dias_se_vuelve_a_exigir_otp(): void
    {
        $socio = $this->socio();
        $this->vincular($socio, 'equipo-de-siempre', now()->subDays(31)->toDateTimeString());

        $this->entrar($socio, 'equipo-de-siempre')->assertOk()->assertJsonPath('data.requires_otp', true);
        $this->assertSame(1, $this->envios);
    }

    public function test_un_ticket_viejo_no_puede_saltarse_la_revalidacion_periodica(): void
    {
        $socio = $this->socio();
        $binding = $this->vincular($socio, 'equipo-de-siempre');

        $ticket = $this->entrar($socio, 'equipo-de-siempre')->json('data.unlock_ticket');
        $this->assertNotEmpty($ticket);

        // Entre la emisión y el canje, el equipo pasa a necesitar revalidación.
        $binding->update(['last_otp_reauth_at' => now()->subDays(40)]);

        $this->postJson('/api/members/login/trusted-unlock', [
            'ticket' => $ticket,
            'document_number' => $socio->document_number,
            'device_id' => 'equipo-de-siempre',
        ])->assertStatus(409)->assertJsonPath('code', 'verification_required');
    }

    // ── 2 y 6 · el ticket tiene que ser válido ───────────────────────────────

    public function test_el_ticket_de_un_socio_no_sirve_para_otro(): void
    {
        $ana = $this->socio('5510', '3005500021');
        $luis = $this->socio('5511', '3005500022');
        $this->vincular($ana, 'equipo-de-ana');
        $this->vincular($luis, 'equipo-de-luis');

        $ticketDeAna = $this->entrar($ana, 'equipo-de-ana')->json('data.unlock_ticket');

        $this->postJson('/api/members/login/trusted-unlock', [
            'ticket' => $ticketDeAna,
            'document_number' => $luis->document_number,
            'device_id' => 'equipo-de-luis',
        ])->assertStatus(410)->assertJsonPath('code', 'ticket_expired');
    }

    public function test_el_ticket_es_de_un_solo_uso(): void
    {
        $socio = $this->socio();
        $this->vincular($socio, 'equipo-de-siempre');

        $ticket = $this->entrar($socio, 'equipo-de-siempre')->json('data.unlock_ticket');
        $cuerpo = [
            'ticket' => $ticket,
            'document_number' => $socio->document_number,
            'device_id' => 'equipo-de-siempre',
        ];

        $this->postJson('/api/members/login/trusted-unlock', $cuerpo)->assertOk();
        $this->postJson('/api/members/login/trusted-unlock', $cuerpo)
            ->assertStatus(410)
            ->assertJsonPath('code', 'ticket_expired');
    }

    public function test_un_ticket_caducado_no_sirve(): void
    {
        config(['security.local_ticket_ttl' => 180]);
        $socio = $this->socio();
        $this->vincular($socio, 'equipo-de-siempre');

        $ticket = $this->entrar($socio, 'equipo-de-siempre')->json('data.unlock_ticket');

        $this->travel(181)->seconds();

        $this->postJson('/api/members/login/trusted-unlock', [
            'ticket' => $ticket,
            'document_number' => $socio->document_number,
            'device_id' => 'equipo-de-siempre',
        ])->assertStatus(410);
    }

    public function test_un_ticket_inventado_no_sirve(): void
    {
        $socio = $this->socio();
        $this->vincular($socio, 'equipo-de-siempre');

        $this->postJson('/api/members/login/trusted-unlock', [
            'ticket' => 'ticket-inventado-por-un-atacante',
            'document_number' => $socio->document_number,
            'device_id' => 'equipo-de-siempre',
        ])->assertStatus(410);
    }

    // ── Estado de la cuenta por encima de todo ───────────────────────────────

    public function test_una_cuenta_suspendida_no_entra_ni_con_equipo_confiable(): void
    {
        $socio = $this->socio();
        $this->vincular($socio, 'equipo-de-siempre');
        $socio->update(['status' => Member::STATUS_SUSPENDED]);

        $r = $this->entrar($socio, 'equipo-de-siempre');

        $this->assertNotSame(200, $r->getStatusCode());
        $this->assertSame(0, $this->envios);
    }

    // ── 8 · las operaciones sensibles no cambian ─────────────────────────────

    public function test_el_cambio_de_numero_sigue_exigiendo_otp_con_el_adaptativo_activo(): void
    {
        $socio = $this->socio();
        $this->vincular($socio, 'equipo-de-siempre');

        // El adaptativo sólo afecta al propósito `login`, nunca a este.
        $res = app(\App\Services\OtpService::class)->startChallenge(
            $socio, ['device_id' => 'equipo-de-siempre'],
            MemberAuthChallenge::PURPOSE_PHONE_CHANGE, '3009998888',
        );

        $this->assertSame(1, $this->envios);
        $this->assertSame(MemberAuthChallenge::PURPOSE_PHONE_CHANGE, $res['challenge']->purpose);
    }

    public function test_el_borrado_de_cuenta_sigue_exigiendo_otp_con_el_adaptativo_activo(): void
    {
        $socio = $this->socio();
        $this->vincular($socio, 'equipo-de-siempre');

        app(\App\Services\OtpService::class)->startChallenge(
            $socio, ['device_id' => 'equipo-de-siempre'], MemberAuthChallenge::PURPOSE_ACCOUNT_DELETE,
        );

        $this->assertSame(1, $this->envios);
    }

    // ── Con la bandera apagada, nada de esto existe ──────────────────────────

    public function test_con_la_bandera_apagada_el_desbloqueo_local_no_existe(): void
    {
        config(['security.adaptive_login' => false]);
        $socio = $this->socio();
        $this->vincular($socio, 'equipo-de-siempre');

        $this->entrar($socio, 'equipo-de-siempre')->assertOk()->assertJsonPath('data.requires_otp', true);

        $this->postJson('/api/members/login/trusted-unlock', [
            'ticket' => 'lo-que-sea',
            'document_number' => $socio->document_number,
            'device_id' => 'equipo-de-siempre',
        ])->assertStatus(404);
    }
}
