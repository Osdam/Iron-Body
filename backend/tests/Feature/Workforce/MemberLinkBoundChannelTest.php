<?php

namespace Tests\Feature\Workforce;

use App\Exceptions\OtpException;
use App\Models\Identity;
use App\Models\Member;
use App\Models\MemberAuthChallenge;
use App\Models\MemberDeviceSession;
use App\Services\Otp\OtpCostGuard;
use App\Services\Otp\OtpPolicy;
use App\Services\OtpService;
use App\Services\Sms\SmsSenderFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Workforce\Concerns\MemberKit;
use Tests\Feature\Workforce\Concerns\WorkforceKit;
use Tests\TestCase;

/**
 * Precondición 1 del vínculo (H1): el código que el socio dicta en el mostrador
 * va LIGADO A SU RETO y no pasa por Twilio Verify.
 *
 * En producción el OTP de socios es Twilio Verify, que comprueba TELÉFONO +
 * código y no el reto: si el código del vínculo saliera por Verify, serviría
 * para un login de la app abierto para ese número —y quien atiende lo tiene en
 * la mano—. Estas pruebas corren con Verify ACTIVO.
 *
 * La única frontera fingida es la HTTP de Twilio, y se finge con estado, como
 * se comporta de verdad: Verify guarda UNA verificación pendiente por número y,
 * mientras siga pendiente, un nuevo inicio reenvía el MISMO código (para otro
 * hay que cancelarla); `VerificationCheck` aprueba teléfono + código. Messages
 * entrega el cuerpo tal cual. La «bandeja» es lo que llega al teléfono del
 * socio, venga por donde venga.
 */
class MemberLinkBoundChannelTest extends TestCase
{
    use MemberKit;
    use RefreshDatabase;
    use WorkforceKit;

    private const YURI = '1075000777';

    private const TEL_SOCIO = '+57 300 555 1234';

    private const E164_SOCIO = '+573005551234';

    private const REMITENTE = '+15005550006';

    private array $admin;

    /** @var array<string, list<array{via: string, code: ?string, body: ?string, from: ?string}>> */
    private array $bandeja = [];

    /** @var array<string, string> verificación pendiente en Verify, por número */
    private array $verifyPendiente = [];

    /** @var list<string> URL de cada llamada a Twilio, en orden */
    private array $llamadas = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-15 09:00', 'America/Bogota')->utc());
        $this->admin = $this->adminWithPermissions(['workforce.view', 'workforce.manage', 'members.view', 'members.edit']);

        config([
            'workforce.member_link_enabled' => true,
            // Verify ACTIVO, como en producción…
            'otp.driver' => 'twilio',
            'otp.twilio.sid' => 'ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
            'otp.twilio.token' => 'token-de-prueba',
            'otp.twilio.verify_service_sid' => 'VAxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
            'otp.twilio.verify_base' => 'https://verify.twilio.com',
            'otp.twilio.base' => 'https://api.twilio.com',
            'otp.default_country_code' => '57',
            // …más el remitente del SMS normal. La bandera heredada sigue
            // cerrada: el vínculo no la necesita y el login no la recibe.
            'otp.twilio.from' => self::REMITENTE,
            'otp.legacy_sms_sender_enabled' => false,
            'otp.policy.lock_wait' => 0,
        ]);

        Http::fake(fn (HttpRequest $r) => $this->twilio($r));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── El Twilio fingido ───────────────────────────────────────────────────

    private function twilio(HttpRequest $r)
    {
        $url = $r->url();
        $this->llamadas[] = $url;

        if (str_ends_with($url, '/Verifications')) {
            $to = (string) $r['To'];
            // Pendiente: se reenvía el mismo. Nuevo: uno que no haya llegado
            // ya a esa bandeja, para que ninguna prueba acierte por casualidad.
            $this->verifyPendiente[$to] ??= $this->codigoNuevoPara($to);
            $this->bandeja[$to][] = ['via' => 'verify', 'code' => $this->verifyPendiente[$to], 'body' => null, 'from' => null];

            return Http::response(['status' => 'pending'], 201);
        }

        if (str_ends_with($url, '/VerificationCheck')) {
            $to = (string) $r['To'];
            $ok = isset($this->verifyPendiente[$to]) && hash_equals($this->verifyPendiente[$to], (string) $r['Code']);
            if ($ok) {
                unset($this->verifyPendiente[$to]);
            }

            return Http::response(['status' => $ok ? 'approved' : 'pending'], 200);
        }

        if (str_ends_with($url, '/Messages.json')) {
            $body = (string) $r['Body'];
            preg_match('/\b(\d{6})\b/', $body, $m);
            $this->bandeja[(string) $r['To']][] = ['via' => 'messages', 'code' => $m[1] ?? null, 'body' => $body, 'from' => (string) $r['From']];

            return Http::response(['sid' => 'SMxxxxxxxxxxxxxxxx', 'status' => 'queued'], 201);
        }

        return Http::response(['message' => 'ruta de Twilio no fingida'], 404);
    }

    private function codigoNuevoPara(string $to): string
    {
        $vistos = array_column($this->bandeja[$to] ?? [], 'code');
        foreach (['424242', '535353', '646464'] as $c) {
            if (! in_array($c, $vistos, true)) {
                return $c;
            }
        }

        return '757575';
    }

    /** Lo último que llegó al teléfono del socio. */
    private function ultimoSms(string $e164 = self::E164_SOCIO): array
    {
        $this->assertNotEmpty($this->bandeja[$e164] ?? [], 'No llegó ningún SMS al teléfono del socio.');

        return $this->bandeja[$e164][array_key_last($this->bandeja[$e164])];
    }

    /** @return list<string> */
    private function llamadasA(string $sufijo): array
    {
        return array_values(array_filter($this->llamadas, fn (string $u) => str_ends_with($u, $sufijo)));
    }

    private function sinVerify(string $mensaje): void
    {
        $this->assertSame([], array_values(array_filter($this->llamadas, fn (string $u) => str_contains($u, 'verify.twilio.com'))), $mensaje);
    }

    // ── El camino del CRM ───────────────────────────────────────────────────

    /** @return array{0: Identity, 1: Member} */
    private function yuriEnPersonal(): array
    {
        $yuri = $this->socio(self::YURI, ['phone' => self::TEL_SOCIO]);
        $uuid = $this->postJson('/api/admin/workforce/staff', [
            'full_name' => 'Yuri Andrea', 'document' => self::YURI, 'phone' => '+57 311 999 8877', 'positions' => ['administrative'],
        ], $this->admin)->assertCreated()->json('data.identity_id');

        return [Identity::query()->where('uuid', $uuid)->firstOrFail(), $yuri];
    }

    private function pedir(Identity $identity, Member $member): TestResponse
    {
        return $this->postJson("/api/admin/workforce/staff/{$identity->uuid}/member-link", ['member_id' => $member->id], $this->admin);
    }

    private function confirmar(Identity $identity, Member $member, string $challenge, string $code): TestResponse
    {
        return $this->postJson("/api/admin/workforce/staff/{$identity->uuid}/member-link/confirm", [
            'member_id' => $member->id, 'challenge_id' => $challenge, 'code' => $code,
        ], $this->admin);
    }

    /** El socio abre la app en su teléfono: reto de login, por Verify. */
    private function loginDelSocio(string $equipo = 'telefono-del-socio'): string
    {
        return (string) $this->postJson('/api/members/login', ['document_number' => self::YURI, 'device_id' => $equipo])
            ->assertOk()->assertJsonPath('data.requires_otp', true)->json('data.challenge_id');
    }

    private function otroCodigo(string $code): string
    {
        return $code === '000000' ? '111111' : '000000';
    }

    // ── El código del vínculo no pasa por Verify ────────────────────────────

    public function test_con_verify_activo_el_codigo_del_vinculo_sale_por_sms_normal_y_se_comprueba_aqui(): void
    {
        [$identity, $yuri] = $this->yuriEnPersonal();
        // Lo que se mira en producción antes de encender: el evento del envío.
        $envios = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$envios): void {
            if ($e->message === 'otp.event' && ($e->context['event'] ?? null) === 'otp.provider_called') {
                $envios[] = [
                    'purpose' => $e->context['purpose'] ?? null,
                    'provider' => $e->context['provider'] ?? null,
                    'decision' => $e->context['decision'] ?? null,
                ];
            }
        });

        $inicio = $this->pedir($identity, $yuri)
            ->assertCreated()
            ->assertJsonPath('destination', '•••• 1234')
            ->assertJsonPath('reused', false)
            ->assertJsonMissingPath('dev_code')
            ->assertJsonMissingPath('delivery_warning');

        $this->sinVerify('El inicio del vínculo no puede tocar Twilio Verify.');
        $this->assertCount(1, $this->llamadasA('/Messages.json'), 'Un solo SMS normal.');
        $this->assertSame([['purpose' => 'identity_link', 'provider' => 'sms_twilio', 'decision' => 'sent']], $envios);
        $sms = $this->ultimoSms();
        $this->assertSame('messages', $sms['via']);
        $this->assertSame(self::REMITENTE, $sms['from']);
        $this->assertMatchesRegularExpression('/^\d{6}$/', (string) $sms['code']);
        // Dice para qué es y a quién se da; no dice «no lo compartas», que es
        // justo lo contrario de lo que se le pide al socio.
        $this->assertStringContainsString('vincular tu ficha de socio', $sms['body']);
        $this->assertStringContainsString('solo en persona en el mostrador del gimnasio', $sms['body']);
        $this->assertStringNotContainsStringIgnoringCase('compartas', $sms['body']);

        // El código es nuestro y vive en SU reto (como hash).
        $reto = MemberAuthChallenge::query()->where('uuid', $inicio->json('challenge_id'))->sole();
        $this->assertSame(MemberAuthChallenge::PURPOSE_IDENTITY_LINK, $reto->purpose);
        $this->assertTrue(Hash::check((string) $sms['code'], $reto->code_hash));

        $this->confirmar($identity, $yuri, $reto->uuid, (string) $sms['code'])
            ->assertOk()->assertJsonPath('result', 'linked');
        $this->assertSame($identity->id, $yuri->refresh()->identity_id);
        $this->sinVerify('La confirmación tampoco pregunta a Verify.');
    }

    public function test_un_codigo_de_verify_del_login_no_sirve_para_el_vinculo(): void
    {
        [$identity, $yuri] = $this->yuriEnPersonal();
        $vinculo = (string) $this->pedir($identity, $yuri)->assertCreated()->json('challenge_id');
        $codigoVinculo = (string) $this->ultimoSms()['code'];

        // El socio abre además la app: Verify le manda SU código de login.
        $login = $this->loginDelSocio();
        $codigoLogin = (string) $this->ultimoSms()['code'];
        $this->assertSame('verify', $this->ultimoSms()['via']);
        $this->assertNotSame($codigoVinculo, $codigoLogin, 'Por Verify serían el MISMO código: el agujero de H1.');

        // Ese código no vincula: ni con el reto del vínculo…
        $this->confirmar($identity, $yuri, $vinculo, $codigoLogin)
            ->assertStatus(422)->assertJsonPath('code', 'otp_invalid')->assertJsonPath('remaining', 4);
        $this->assertSame([], $this->llamadasA('/VerificationCheck'), 'El vínculo no le pregunta a Verify.');
        // …ni con el reto del login.
        $this->confirmar($identity, $yuri, $login, $codigoLogin)
            ->assertNotFound()->assertJsonPath('code', 'otp_not_found');
        $this->assertNull($yuri->refresh()->identity_id);

        // El código del vínculo sigue sirviendo para lo suyo.
        $this->confirmar($identity, $yuri, $vinculo, $codigoVinculo)->assertOk()->assertJsonPath('result', 'linked');
    }

    public function test_el_codigo_del_vinculo_no_abre_la_app_ni_por_reto_ni_por_telefono(): void
    {
        [$identity, $yuri] = $this->yuriEnPersonal();
        $vinculo = (string) $this->pedir($identity, $yuri)->assertCreated()->json('challenge_id');
        $codigoVinculo = (string) $this->ultimoSms()['code'];

        // Por reto: el login no canjea un reto de otro propósito.
        $this->postJson('/api/members/login/verify', ['challenge_id' => $vinculo, 'code' => $codigoVinculo, 'device_id' => 'equipo-del-crm'])
            ->assertNotFound()->assertJsonMissingPath('data.token')->assertJsonMissingPath('data.ticket');

        // Por teléfono: quien atiende abre un login del socio desde otro equipo
        // y teclea el código que el socio le dictó. Verify valida teléfono +
        // código; el del vínculo nunca fue suyo, así que no lo aprueba.
        $login = $this->loginDelSocio('equipo-del-crm');
        $this->postJson('/api/members/login/verify', ['challenge_id' => $login, 'code' => $codigoVinculo, 'device_id' => 'equipo-del-crm'])
            ->assertStatus(422)->assertJsonMissingPath('data.token')->assertJsonMissingPath('data.ticket');
        $this->assertSame(0, MemberDeviceSession::query()->where('member_id', $yuri->id)->count());
        $this->assertSame(MemberAuthChallenge::STATUS_PENDING, MemberAuthChallenge::query()->where('uuid', $login)->value('status'));

        // El login, con su propio código, sigue funcionando por Verify.
        $this->postJson('/api/members/login/verify', ['challenge_id' => $login, 'code' => (string) $this->ultimoSms()['code'], 'device_id' => 'telefono-del-socio'])
            ->assertOk();
        $this->assertSame(MemberAuthChallenge::STATUS_VERIFIED, MemberAuthChallenge::query()->where('uuid', $login)->value('status'));
        $this->assertCount(2, $this->llamadasA('/VerificationCheck'), 'Solo el login habló con Verify.');
    }

    // ── Vigencia, un solo uso, intentos, reto anterior ──────────────────────

    public function test_un_codigo_del_vinculo_caducado_no_sirve(): void
    {
        [$identity, $yuri] = $this->yuriEnPersonal();
        $vinculo = (string) $this->pedir($identity, $yuri)->assertCreated()->json('challenge_id');
        $codigo = (string) $this->ultimoSms()['code'];

        Carbon::setTestNow(now()->addMinutes(6));
        $this->confirmar($identity, $yuri, $vinculo, $codigo)->assertStatus(410)->assertJsonPath('code', 'otp_expired');
        $this->assertNull($yuri->refresh()->identity_id);
        $this->sinVerify('Ni para caducar se pregunta a Verify.');
    }

    public function test_un_codigo_del_vinculo_solo_se_usa_una_vez(): void
    {
        [$identity, $yuri] = $this->yuriEnPersonal();
        $vinculo = (string) $this->pedir($identity, $yuri)->assertCreated()->json('challenge_id');
        $codigo = (string) $this->ultimoSms()['code'];
        $otp = app(OtpService::class);

        $otp->verifyAction($yuri, MemberAuthChallenge::PURPOSE_IDENTITY_LINK, $vinculo, $codigo, []);
        try {
            $otp->verifyAction($yuri, MemberAuthChallenge::PURPOSE_IDENTITY_LINK, $vinculo, $codigo, []);
            $this->fail('Un código ya usado tenía que rechazarse.');
        } catch (OtpException $e) {
            $this->assertSame(409, $e->status);
        }
        // Y por la ruta: el reto gastado no vincula.
        $this->confirmar($identity, $yuri, $vinculo, $codigo)->assertStatus(409)->assertJsonPath('code', 'otp_used');
        $this->assertNull($yuri->refresh()->identity_id);
        $this->sinVerify('Comprobado aquí, no en Verify.');
    }

    public function test_al_quinto_codigo_equivocado_se_bloquea_y_ni_el_bueno_sirve(): void
    {
        [$identity, $yuri] = $this->yuriEnPersonal();
        $vinculo = (string) $this->pedir($identity, $yuri)->assertCreated()->json('challenge_id');
        $codigo = (string) $this->ultimoSms()['code'];
        $malo = $this->otroCodigo($codigo);

        foreach ([4, 3, 2, 1] as $quedan) {
            $this->confirmar($identity, $yuri, $vinculo, $malo)->assertStatus(422)->assertJsonPath('remaining', $quedan);
        }
        $this->confirmar($identity, $yuri, $vinculo, $malo)->assertStatus(423)->assertJsonPath('code', 'otp_blocked');
        $this->confirmar($identity, $yuri, $vinculo, $codigo)->assertStatus(423)->assertJsonPath('code', 'otp_blocked');

        $this->assertSame(5, MemberAuthChallenge::query()->where('uuid', $vinculo)->value('attempts'));
        $this->assertNull($yuri->refresh()->identity_id);
        $this->sinVerify('Los intentos se cuentan aquí, no en Verify.');
    }

    public function test_pedir_otro_codigo_invalida_el_anterior(): void
    {
        [$identity, $yuri] = $this->yuriEnPersonal();
        $primero = (string) $this->pedir($identity, $yuri)->assertCreated()->json('challenge_id');
        $codigoPrimero = (string) $this->ultimoSms()['code'];

        // La marca de «código vivo» está en la cache: si se pierde (un
        // `cache:clear` en un despliegue, una expulsión), pedir otra vez ya no
        // reutiliza y sale un reto nuevo, que vence el anterior.
        app(OtpPolicy::class)->forgetLive(OtpPolicy::SCOPE_MEMBER, $yuri->id, MemberAuthChallenge::PURPOSE_IDENTITY_LINK);
        Carbon::setTestNow(now()->addSeconds(61));
        $segundo = (string) $this->pedir($identity, $yuri)->assertCreated()->assertJsonPath('reused', false)->json('challenge_id');
        $codigoSegundo = (string) $this->ultimoSms()['code'];
        $this->assertNotSame($primero, $segundo);
        $this->assertSame(MemberAuthChallenge::STATUS_EXPIRED, MemberAuthChallenge::query()->where('uuid', $primero)->value('status'));

        // El reto vencido no vale aunque su hora no haya pasado.
        $this->confirmar($identity, $yuri, $primero, $codigoPrimero)->assertStatus(410)->assertJsonPath('code', 'otp_expired');
        $this->assertNull($yuri->refresh()->identity_id);

        $this->confirmar($identity, $yuri, $segundo, $codigoSegundo)->assertOk()->assertJsonPath('result', 'linked');
        $this->sinVerify('Ningún paso del vínculo pasa por Verify.');
    }

    public function test_el_vinculo_respeta_interruptor_cupos_y_candado_sin_tocar_twilio(): void
    {
        [$identity, $yuri] = $this->yuriEnPersonal();
        $policy = app(OtpPolicy::class);

        // Interruptor de envíos (y techo de gasto): 503 con espera.
        config(['otp.send_enabled' => false]);
        $this->pedir($identity, $yuri)->assertStatus(503)->assertJsonPath('code', 'otp_unavailable')->assertJsonStructure(['retry_after']);
        config(['otp.send_enabled' => true]);

        // Otro proceso está enviando el código de este número: 429, nada sale.
        $candado = $policy->lock(MemberAuthChallenge::PURPOSE_IDENTITY_LINK, self::TEL_SOCIO);
        $this->assertTrue($candado->get());
        try {
            $this->pedir($identity, $yuri)->assertStatus(429)->assertJsonPath('code', 'otp_rate_limited');
        } finally {
            $candado->release();
        }

        // Cupo de la cuenta compartido con el login: el socio ya gastó el suyo.
        config(['otp.policy.account.window_limit' => 1]);
        $this->loginDelSocio();
        $antes = count($this->llamadas);
        $this->pedir($identity, $yuri)->assertStatus(429)->assertJsonPath('code', 'otp_rate_limited')->assertJsonStructure(['retry_after']);

        $this->assertSame($antes, count($this->llamadas), 'Nada salió hacia Twilio por el vínculo.');
        $this->assertSame([], $this->llamadasA('/Messages.json'));
        $this->assertSame(0, MemberAuthChallenge::query()->where('purpose', MemberAuthChallenge::PURPOSE_IDENTITY_LINK)->count());
    }

    // ── Sin SMS normal: 503, nunca Verify de reserva ────────────────────────

    public function test_sin_sms_normal_configurado_responde_503_sin_tocar_verify(): void
    {
        [$identity, $yuri] = $this->yuriEnPersonal();
        config(['otp.twilio.from' => '']);

        $this->pedir($identity, $yuri)->assertStatus(503)->assertJsonPath('code', 'otp_unavailable');

        $this->assertSame([], $this->llamadas, 'Ni Verify ni Messages.');
        $this->assertSame(0, MemberAuthChallenge::count(), 'Ningún reto creado.');
        $this->assertSame(0.0, app(OtpCostGuard::class)->spentToday(), 'Nada contado como gasto.');
        // La ficha se queda en «coincide por documento, sin verificar».
        $this->getJson("/api/admin/workforce/staff/{$identity->uuid}", $this->admin)
            ->assertOk()->assertJsonPath('data.member.link', 'unlinked');
        $this->assertNull($yuri->refresh()->identity_id);
    }

    public function test_el_reenvio_de_un_codigo_del_vinculo_sin_sms_normal_tampoco_cae_a_verify(): void
    {
        [$identity, $yuri] = $this->yuriEnPersonal();
        $vinculo = (string) $this->pedir($identity, $yuri)->assertCreated()->json('challenge_id');
        $antes = count($this->llamadas);

        // El reenvío público busca el reto solo por uuid (pendiente aparte,
        // DISENO §10b). Si el SMS normal se cae, no puede salir por Verify.
        config(['otp.twilio.from' => '']);
        Carbon::setTestNow(now()->addSeconds(61));
        $gasto = app(OtpCostGuard::class)->spentToday();
        $reenvio = $this->postJson('/api/members/login/resend', ['challenge_id' => $vinculo]);

        $this->assertLessThan(500, $reenvio->status());
        $this->assertSame([], array_slice($this->llamadas, $antes), 'Nada salió hacia Twilio.');
        $this->assertSame($gasto, app(OtpCostGuard::class)->spentToday(), 'Un envío que no salió no cuenta como gasto.');
        $this->sinVerify('El reenvío del vínculo no usa Verify de reserva.');
    }

    // ── Los demás propósitos no cambian ─────────────────────────────────────

    public function test_el_login_y_las_acciones_sensibles_siguen_por_verify(): void
    {
        $socio = $this->socio(self::YURI, ['phone' => self::TEL_SOCIO]);
        $otp = app(OtpService::class);

        foreach ([MemberAuthChallenge::PURPOSE_LOGIN, MemberAuthChallenge::PURPOSE_ACCOUNT_DELETE] as $proposito) {
            $otp->startChallenge($socio, [], $proposito);
        }

        $this->assertCount(2, $this->llamadasA('/Verifications'));
        $this->assertSame([], $this->llamadasA('/Messages.json'), 'Con Verify activo, nada más usa SMS normal.');
    }

    public function test_el_emisor_heredado_sigue_cerrado_para_todo_lo_demas(): void
    {
        // Sin Verify, con TWILIO_FROM y sin la bandera heredada: el login no
        // revive la API de Messages; el vínculo sí la tiene.
        config(['otp.twilio.verify_service_sid' => null]);
        $socio = $this->socio(self::YURI, ['phone' => self::TEL_SOCIO]);

        $login = app(OtpService::class)->startChallenge($socio, [], MemberAuthChallenge::PURPOSE_LOGIN);
        $this->assertFalse($login['sent']);
        $this->assertSame([], $this->llamadas);

        $this->assertFalse(SmsSenderFactory::make()->isConfigured());
        $this->assertTrue(SmsSenderFactory::forChallengeBoundCode()->isConfigured());
    }
}
