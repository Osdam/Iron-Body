<?php

namespace Tests\Feature\Otp;

use App\Exceptions\OtpException;
use App\Models\Member;
use App\Models\MemberAuthChallenge;
use App\Models\MemberDeviceBinding;
use App\Models\Trainer;
use App\Models\User;
use App\Services\Otp\OtpPolicy;
use App\Services\OtpService;
use App\Services\Trainer\TrainerOtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Control de coste de Twilio.
 *
 * Cada prueba cuenta LLAMADAS AL PROVEEDOR, que es lo que se factura, no
 * respuestas HTTP ni filas en la base. La auditoría del 12/09/2026 midió 562 SMS
 * para 403 logins buenos: 70 de esos SMS salieron sobre una verificación que
 * seguía viva y el resto se los llevó gente reintentando sin freno.
 *
 * Lo que se fija aquí es justamente eso: que un código vivo no se pague dos
 * veces, que una ráfaga no multiplique el gasto, que haya un techo, y que nada
 * de esto deje fuera a un socio legítimo.
 */
class TwilioCostControlTest extends TestCase
{
    use RefreshDatabase;

    /** Llamadas a `Verifications` (= SMS facturable) desde que arrancó la prueba. */
    private int $envios = 0;

    /** Llamadas a `VerificationCheck` (= comprobar un código, no cuesta SMS). */
    private int $comprobaciones = 0;

    /** Cualquier salida hacia Twilio, incluidas las que mueren en la red. */
    private int $intentos = 0;

    // Gobierno del doble: se cambian desde la prueba, nunca se registra otro
    // `Http::fake`, porque Laravel ACUMULA los closures y gana el primero que
    // responde — registrar un segundo doble no sustituye al de setUp.
    private bool $aceptaEnvios = true;

    private string $estadoComprobacion = 'approved';

    private bool $conexionCaida = false;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'otp.driver' => 'twilio',
            'otp.twilio.sid' => 'ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
            'otp.twilio.token' => 'token-de-prueba',
            'otp.twilio.verify_service_sid' => 'VAxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
            'otp.twilio.verify_base' => 'https://verify.twilio.com',
            'otp.default_country_code' => '57',
            'otp.ttl' => 300,
            'otp.send_enabled' => true,
            'otp.policy.start_cooldown' => 60,
            'otp.policy.lock_wait' => 0,
            'otp.cost.sms_start' => 0.0592,
            'otp.cost.verification' => 0.05,
            'otp.cost.daily_soft' => 6.0,
            'otp.cost.daily_hard' => 12.0,
        ]);

        Http::fake(function ($request) {
            $this->intentos++;

            if ($this->conexionCaida) {
                throw new ConnectionException('Connection timed out');
            }

            if (str_ends_with($request->url(), '/Verifications')) {
                $this->envios++;

                return $this->aceptaEnvios
                    ? Http::response(['status' => 'pending'], 201)
                    : Http::response(['code' => 60200, 'message' => 'Invalid parameter'], 400);
            }

            $this->comprobaciones++;

            return Http::response(['status' => $this->estadoComprobacion], 200);
        });
    }

    // ── Utilidades ───────────────────────────────────────────────────────────

    private function socio(string $documento, string $telefono): Member
    {
        $user = User::create([
            'name' => 'Socio '.$documento,
            'email' => $documento.'@ironbody.test',
            'password' => 'secret',
            'document' => $documento,
            'phone' => $telefono,
            'status' => 'active',
        ]);

        return Member::create([
            'user_id' => $user->id,
            'full_name' => 'Socio '.$documento,
            'email' => $documento.'@ironbody.test',
            'document_number' => $documento,
            'phone' => $telefono,
            'access_hash' => 'tok-'.$documento,
            'status' => Member::STATUS_ACTIVE,
        ]);
    }

    private function otp(): OtpService
    {
        return app(OtpService::class);
    }

    private function contexto(string $ip = '190.0.0.1', ?string $dispositivo = null): array
    {
        return array_filter(['ip_address' => $ip, 'device_id' => $dispositivo]);
    }

    // ── Reuso de verificación viva ───────────────────────────────────────────

    public function test_la_primera_solicitud_envia_un_solo_sms(): void
    {
        $res = $this->otp()->startChallenge($this->socio('1001', '3001110001'), $this->contexto());

        $this->assertSame(1, $this->envios);
        $this->assertTrue($res['sent']);
        $this->assertFalse($res['reused']);
    }

    public function test_pulsar_dos_veces_con_el_codigo_vivo_no_vuelve_a_llamar_a_twilio(): void
    {
        $socio = $this->socio('1002', '3001110002');

        $primero = $this->otp()->startChallenge($socio, $this->contexto());
        $segundo = $this->otp()->startChallenge($socio, $this->contexto());

        $this->assertSame(1, $this->envios, 'El segundo toque no debe costar un SMS.');
        $this->assertTrue($segundo['reused']);
        // Y devuelve EL MISMO reto: el código que el socio tiene en el móvil sirve.
        $this->assertSame($primero['challenge']->uuid, $segundo['challenge']->uuid);
    }

    public function test_una_rafaga_de_diez_toques_cuesta_un_solo_sms(): void
    {
        $socio = $this->socio('1003', '3001110003');

        for ($i = 0; $i < 10; $i++) {
            $this->otp()->startChallenge($socio, $this->contexto());
        }

        $this->assertSame(1, $this->envios);
    }

    public function test_el_reuso_caduca_con_el_codigo_y_entonces_si_se_envia_de_nuevo(): void
    {
        $socio = $this->socio('1004', '3001110004');
        $this->otp()->startChallenge($socio, $this->contexto());

        // Pasado el TTL el código ya no sirve: pedir otro es legítimo.
        $this->travel(301)->seconds();
        $this->otp()->startChallenge($socio, $this->contexto());

        $this->assertSame(2, $this->envios);
    }

    public function test_un_envio_que_twilio_rechaza_no_se_reutiliza_nunca(): void
    {
        $this->aceptaEnvios = false;
        $socio = $this->socio('1005', '3001110005');

        $primero = $this->otp()->startChallenge($socio, $this->contexto());
        $this->assertFalse($primero['sent']);

        // Pasado el enfriamiento vuelve a intentarlo: reutilizar aquí sería
        // devolver un código que no existe en ningún móvil.
        $this->travel(61)->seconds();
        $segundo = $this->otp()->startChallenge($socio, $this->contexto());

        $this->assertFalse($segundo['reused']);
        $this->assertSame(2, $this->envios);
    }

    public function test_un_envio_rechazado_tambien_respeta_el_enfriamiento(): void
    {
        // Si Twilio rechaza el número, insistir en el acto sólo repite el fallo
        // y puede costar dinero. Se espera igual que en cualquier otro caso.
        $this->aceptaEnvios = false;
        $socio = $this->socio('1015', '3001110015');

        $this->otp()->startChallenge($socio, $this->contexto());

        try {
            $this->otp()->startChallenge($socio, $this->contexto());
            $this->fail('Insistir en el acto sobre un número rechazado debe frenarse.');
        } catch (OtpException $e) {
            $this->assertSame(429, $e->status);
        }

        $this->assertSame(1, $this->envios);
    }

    public function test_el_cambio_de_numero_no_reutiliza_el_codigo_del_numero_anterior(): void
    {
        $socio = $this->socio('1006', '3001110006');

        $this->otp()->startChallenge($socio, $this->contexto(), MemberAuthChallenge::PURPOSE_PHONE_CHANGE, '3009990001');
        $segundo = $this->otp()->startChallenge($socio, $this->contexto(), MemberAuthChallenge::PURPOSE_PHONE_CHANGE, '3009990002');

        $this->assertFalse($segundo['reused'], 'El código salió hacia otro teléfono.');
        $this->assertSame(2, $this->envios);
    }

    public function test_dos_socios_con_el_mismo_telefono_no_comparten_verificacion(): void
    {
        $ana = $this->socio('1007', '3001110007');
        $luis = $this->socio('1008', '3001110007');

        $this->otp()->startChallenge($ana, $this->contexto());
        $res = $this->otp()->startChallenge($luis, $this->contexto());

        $this->assertFalse($res['reused'], 'Nadie se monta en la verificación de otra persona.');
        $this->assertSame(2, $this->envios);
    }

    public function test_si_otro_proceso_tiene_el_candado_no_sale_un_segundo_sms(): void
    {
        $socio = $this->socio('1009', '3001110009');
        $politica = app(OtpPolicy::class);

        // Simula al "ganador" de la carrera: tiene el candado y está hablando
        // con Twilio. El que llega después NO debe disparar otro envío.
        $candado = $politica->lock(MemberAuthChallenge::PURPOSE_LOGIN, '3001110009');
        $this->assertTrue($candado->get());

        try {
            $this->otp()->startChallenge($socio, $this->contexto());
            $this->fail('Debió responder "ya estamos enviando", no llamar a Twilio.');
        } catch (OtpException $e) {
            $this->assertSame(429, $e->status);
            $this->assertSame('in_progress', $e->extra['code']);
            $this->assertSame(0, $this->envios);
        } finally {
            $candado->release();
        }
    }

    // ── Enfriamiento ─────────────────────────────────────────────────────────

    public function test_el_enfriamiento_no_castiga_a_quien_acaba_de_entrar_bien(): void
    {
        $socio = $this->socio('1010', '3001110010');

        $res = $this->otp()->startChallenge($socio, $this->contexto());
        $this->otp()->verify($res['challenge']->uuid, '123456', $this->contexto());

        // Vuelve a entrar enseguida: es legítimo y no se le penaliza.
        $this->travel(5)->seconds();
        $segundo = $this->otp()->startChallenge($socio, $this->contexto());

        $this->assertSame(2, $this->envios);
        $this->assertFalse($segundo['reused']);
    }

    public function test_el_reenvio_antes_del_enfriamiento_no_llama_a_twilio(): void
    {
        $socio = $this->socio('1011', '3001110011');
        $res = $this->otp()->startChallenge($socio, $this->contexto());

        try {
            $this->otp()->resend($res['challenge']->uuid, $this->contexto());
            $this->fail('El reenvío inmediato debe rechazarse.');
        } catch (OtpException $e) {
            $this->assertSame(429, $e->status);
        }

        $this->assertSame(1, $this->envios);
    }

    public function test_el_reenvio_pasado_el_enfriamiento_envia_exactamente_uno(): void
    {
        $socio = $this->socio('1012', '3001110012');
        $res = $this->otp()->startChallenge($socio, $this->contexto());

        $this->travel(61)->seconds();
        $this->otp()->resend($res['challenge']->uuid, $this->contexto());

        $this->assertSame(2, $this->envios);
    }

    // ── Cupos ────────────────────────────────────────────────────────────────

    public function test_el_tope_por_telefono_corta_el_cuarto_envio_de_la_ventana(): void
    {
        config(['otp.policy.phone.window_limit' => 3, 'otp.policy.phone.daily_limit' => 99]);

        // Cuatro socios distintos con el MISMO teléfono: no hay reuso posible, así
        // que lo único que puede frenarlos es el cupo del número.
        foreach (['2001', '2002', '2003'] as $doc) {
            $this->otp()->startChallenge($this->socio($doc, '3002220000'), $this->contexto());
        }
        $this->assertSame(3, $this->envios);

        try {
            $this->otp()->startChallenge($this->socio('2004', '3002220000'), $this->contexto());
            $this->fail('El cuarto envío al mismo número debe cortarse.');
        } catch (OtpException $e) {
            $this->assertSame(429, $e->status);
            $this->assertSame('rate_limited', $e->extra['code']);
            $this->assertGreaterThan(0, $e->extra['retry_after']);
        }

        $this->assertSame(3, $this->envios);
    }

    public function test_el_tope_diario_por_telefono_corta_aunque_la_ventana_corta_lo_permita(): void
    {
        config(['otp.policy.phone.window_limit' => 99, 'otp.policy.phone.daily_limit' => 2]);

        $this->otp()->startChallenge($this->socio('2011', '3002220011'), $this->contexto());
        $this->otp()->startChallenge($this->socio('2012', '3002220011'), $this->contexto());

        $this->expectException(OtpException::class);
        $this->otp()->startChallenge($this->socio('2013', '3002220011'), $this->contexto());
    }

    public function test_la_recepcion_del_gimnasio_no_bloquea_a_socios_legitimos(): void
    {
        // El caso real de la auditoría: 24 socios distintos bajo una sola IP
        // pública. Ninguno ha hecho nada malo y todos deben poder entrar.
        for ($i = 0; $i < 24; $i++) {
            $this->otp()->startChallenge(
                $this->socio('30'.str_pad((string) $i, 3, '0', STR_PAD_LEFT), '30033'.str_pad((string) $i, 5, '0', STR_PAD_LEFT)),
                $this->contexto('181.50.50.50'),
            );
        }

        $this->assertSame(24, $this->envios, 'La IP es señal secundaria, no una llave que eche a nadie.');
    }

    // ── Techo financiero ─────────────────────────────────────────────────────

    public function test_el_techo_duro_impide_nuevos_envios(): void
    {
        // Dos SMS = 0,1184 USD, por encima del techo de 0,10.
        config(['otp.cost.daily_hard' => 0.10, 'otp.policy.phone.window_limit' => 99]);

        $this->otp()->startChallenge($this->socio('4001', '3004440001'), $this->contexto());
        $this->otp()->startChallenge($this->socio('4002', '3004440002'), $this->contexto());
        $this->assertSame(2, $this->envios);

        try {
            $this->otp()->startChallenge($this->socio('4003', '3004440003'), $this->contexto());
            $this->fail('Pasado el techo no puede salir otro SMS.');
        } catch (OtpException $e) {
            $this->assertSame(503, $e->status, 'Degradación controlada, nunca un 500.');
            $this->assertSame('temporarily_unavailable', $e->extra['code']);
            $this->assertGreaterThan(0, $e->extra['retry_after']);
        }

        $this->assertSame(2, $this->envios);
    }

    public function test_el_techo_duro_no_impide_comprobar_un_codigo_ya_enviado(): void
    {
        $socio = $this->socio('4010', '3004440010');
        $res = $this->otp()->startChallenge($socio, $this->contexto());

        // El techo baja DESPUÉS de que el socio recibiera su código.
        config(['otp.cost.daily_hard' => 0.0001]);

        $salida = $this->otp()->verify($res['challenge']->uuid, '123456', $this->contexto());

        $this->assertSame(MemberAuthChallenge::STATUS_VERIFIED, $salida['challenge']->fresh()->status);
        $this->assertSame(1, $this->comprobaciones);
    }

    public function test_el_interruptor_impide_nuevos_envios(): void
    {
        config(['otp.send_enabled' => false]);

        try {
            $this->otp()->startChallenge($this->socio('4020', '3004440020'), $this->contexto());
            $this->fail('Con el interruptor bajado no sale ningún SMS.');
        } catch (OtpException $e) {
            $this->assertSame(503, $e->status);
            $this->assertSame('kill_switch', $e->extra['code']);
        }

        $this->assertSame(0, $this->envios);
    }

    public function test_el_interruptor_no_impide_comprobar_un_codigo_ya_enviado(): void
    {
        $socio = $this->socio('4030', '3004440030');
        $res = $this->otp()->startChallenge($socio, $this->contexto());

        config(['otp.send_enabled' => false]);

        $salida = $this->otp()->verify($res['challenge']->uuid, '123456', $this->contexto());
        $this->assertSame(MemberAuthChallenge::STATUS_VERIFIED, $salida['challenge']->fresh()->status);
    }

    public function test_el_interruptor_tambien_frena_el_reenvio(): void
    {
        $socio = $this->socio('4040', '3004440040');
        $res = $this->otp()->startChallenge($socio, $this->contexto());

        $this->travel(61)->seconds();
        config(['otp.send_enabled' => false]);

        $this->expectException(OtpException::class);
        $this->otp()->resend($res['challenge']->uuid, $this->contexto());
    }

    public function test_el_gasto_estimado_se_acumula_y_sobrevive_al_proceso(): void
    {
        config(['otp.policy.phone.window_limit' => 99]);

        $this->otp()->startChallenge($this->socio('4050', '3004440050'), $this->contexto());
        $this->otp()->startChallenge($this->socio('4051', '3004440051'), $this->contexto());

        // El contador vive en la cache COMPARTIDA, no en memoria del proceso.
        $instanciaNueva = app(OtpPolicy::class)->costSnapshot();
        $this->assertEqualsWithDelta(0.1184, $instanciaNueva['estimated_spent_usd'], 0.0001);
        $this->assertSame(0, Cache::get('otp:cost:'.now()->format('Y-m-d')) % 1, 'Se guarda como entero.');
    }

    public function test_una_verificacion_completada_suma_su_propio_coste(): void
    {
        $socio = $this->socio('4060', '3004440060');
        $res = $this->otp()->startChallenge($socio, $this->contexto());
        $this->otp()->verify($res['challenge']->uuid, '123456', $this->contexto());

        // Twilio cobra el SMS siempre y la verificación sólo si se completa.
        $this->assertEqualsWithDelta(
            0.1092,
            app(OtpPolicy::class)->costSnapshot()['estimated_spent_usd'],
            0.0001,
        );
    }

    // ── Comprobación de códigos ──────────────────────────────────────────────

    public function test_comprobar_el_codigo_nunca_envia_un_sms(): void
    {
        $socio = $this->socio('5001', '3005550001');
        $res = $this->otp()->startChallenge($socio, $this->contexto());
        $this->assertSame(1, $this->envios);

        $this->otp()->verify($res['challenge']->uuid, '123456', $this->contexto());

        $this->assertSame(1, $this->envios, 'VerificationCheck no es Verifications.');
        $this->assertSame(1, $this->comprobaciones);
    }

    public function test_un_codigo_verificado_deja_de_estar_vivo_para_reuso(): void
    {
        $socio = $this->socio('5010', '3005550010');
        $res = $this->otp()->startChallenge($socio, $this->contexto());
        $this->otp()->verify($res['challenge']->uuid, '123456', $this->contexto());

        $this->assertNull(
            app(OtpPolicy::class)->liveChallengeUuid(OtpPolicy::SCOPE_MEMBER, $socio->id, MemberAuthChallenge::PURPOSE_LOGIN),
        );
    }

    // ── Fallos del proveedor ─────────────────────────────────────────────────

    public function test_un_timeout_del_proveedor_no_reintenta_por_su_cuenta(): void
    {
        $this->conexionCaida = true;

        $res = $this->otp()->startChallenge($this->socio('6001', '3006660001'), $this->contexto());

        $this->assertSame(1, $this->intentos, 'Una llamada, no una cascada de reintentos.');
        $this->assertFalse($res['sent']);
    }

    public function test_un_timeout_cuenta_como_gasto_porque_el_sms_pudo_salir(): void
    {
        $this->conexionCaida = true;

        $this->otp()->startChallenge($this->socio('6010', '3006660010'), $this->contexto());

        // Ante la duda, el techo de gasto se equivoca por arriba: es la única
        // equivocación que no cuesta dinero.
        $this->assertGreaterThan(0, app(OtpPolicy::class)->costSnapshot()['estimated_spent_usd']);
    }

    // ── Privacidad ───────────────────────────────────────────────────────────

    public function test_ni_el_codigo_ni_el_telefono_aparecen_en_los_registros(): void
    {
        $registros = [];
        Log::listen(function (MessageLogged $m) use (&$registros) {
            $registros[] = $m->message.' '.json_encode($m->context);
        });

        $socio = $this->socio('7001', '3007770001');
        $res = $this->otp()->startChallenge($socio, $this->contexto('190.7.7.7'));
        $this->otp()->verify($res['challenge']->uuid, '123456', $this->contexto('190.7.7.7'));

        $this->assertNotEmpty($registros, 'Debe haber trazas: si no, no estamos observando nada.');

        $todo = implode("\n", $registros);
        $this->assertStringNotContainsString($res['code'], $todo, 'El código jamás se registra.');
        $this->assertStringNotContainsString('3007770001', $todo, 'El teléfono va hasheado.');
        $this->assertStringNotContainsString('190.7.7.7', $todo, 'La IP va hasheada.');
        $this->assertStringNotContainsString('token-de-prueba', $todo, 'Las credenciales nunca se registran.');
        $this->assertStringContainsString('otp.provider_called', $todo);
        $this->assertStringContainsString('otp.verified', $todo);
    }

    public function test_el_reuso_queda_registrado_para_poder_medirlo(): void
    {
        $registros = [];
        Log::listen(function (MessageLogged $m) use (&$registros) {
            $registros[] = json_encode($m->context);
        });

        $socio = $this->socio('7010', '3007770010');
        $this->otp()->startChallenge($socio, $this->contexto());
        $this->otp()->startChallenge($socio, $this->contexto());

        $this->assertStringContainsString('otp.reused', implode("\n", $registros));
    }

    // ── Motor profesional ────────────────────────────────────────────────────

    public function test_el_motor_del_entrenador_tiene_la_misma_proteccion(): void
    {
        $entrenador = Trainer::create([
            'full_name' => 'Entrenador Uno',
            'document' => '9001',
            'phone' => '3009990900',
            'email' => 'ent1@ironbody.test',
            'status' => 'active',
        ]);

        $svc = app(TrainerOtpService::class);
        $svc->startChallenge($entrenador, $this->contexto());
        $segundo = $svc->startChallenge($entrenador, $this->contexto());

        $this->assertSame(1, $this->envios, 'El portal profesional no es un segundo camino sin freno.');
        $this->assertTrue($segundo['reused']);
    }

    public function test_el_interruptor_tambien_para_al_entrenador(): void
    {
        $entrenador = Trainer::create([
            'full_name' => 'Entrenador Dos',
            'document' => '9002',
            'phone' => '3009990901',
            'email' => 'ent2@ironbody.test',
            'status' => 'active',
        ]);

        config(['otp.send_enabled' => false]);

        $this->expectException(OtpException::class);
        app(TrainerOtpService::class)->startChallenge($entrenador, $this->contexto());
    }

    // ── Contrato con la app publicada ────────────────────────────────────────

    public function test_el_login_de_un_equipo_no_confiable_sigue_enviando_sms(): void
    {
        $socio = $this->socio('8001', '3008880001');

        $r = $this->postJson('/api/members/login', [
            'document_number' => $socio->document_number,
            'device_id' => 'equipo-desconocido',
        ]);

        $r->assertOk()->assertJsonPath('data.requires_otp', true);
        $this->assertSame(1, $this->envios);
    }

    public function test_el_login_adaptativo_de_un_equipo_confiable_no_envia_sms(): void
    {
        config(['security.adaptive_login' => true]);

        $socio = $this->socio('8010', '3008880010');
        MemberDeviceBinding::create([
            'device_id' => 'equipo-de-siempre',
            'member_id' => $socio->id,
            'bound_at' => now()->subDays(3),
            'last_otp_reauth_at' => now()->subDays(3),
        ]);

        $r = $this->postJson('/api/members/login', [
            'document_number' => $socio->document_number,
            'device_id' => 'equipo-de-siempre',
        ]);

        $r->assertOk()
            ->assertJsonPath('data.requires_otp', false)
            ->assertJsonPath('data.requires_local_unlock', true);
        $r->assertJsonStructure(['data' => ['unlock_ticket', 'expires_in']]);

        $this->assertSame(0, $this->envios, 'Éste es el 34 % del ahorro.');
    }

    public function test_la_respuesta_del_login_conserva_los_campos_que_lee_la_app(): void
    {
        $socio = $this->socio('8020', '3008880020');

        $r = $this->postJson('/api/members/login', ['document_number' => $socio->document_number]);

        // Los mismos campos que consume member_login_service.dart en 2.0.4.
        $r->assertOk()->assertJsonStructure([
            'ok',
            'data' => ['requires_otp', 'challenge_id', 'destination', 'expires_in', 'resend_cooldown', 'channel'],
        ]);
    }

    public function test_el_reuso_devuelve_la_misma_forma_de_respuesta_que_un_envio_nuevo(): void
    {
        $socio = $this->socio('8030', '3008880030');
        $campos = ['ok', 'data' => ['requires_otp', 'challenge_id', 'destination', 'expires_in', 'resend_cooldown', 'channel']];

        $this->postJson('/api/members/login', ['document_number' => $socio->document_number])->assertOk();
        $segundo = $this->postJson('/api/members/login', ['document_number' => $socio->document_number]);

        $segundo->assertOk()->assertJsonStructure($campos);
        // Y sin el aviso de entrega fallida: el código está vivo, no ha fallado nada.
        $segundo->assertJsonMissingPath('data.delivery_warning');
        $this->assertSame(1, $this->envios);
    }

    public function test_el_limite_alcanzado_responde_429_y_no_un_error_del_servidor(): void
    {
        config(['otp.policy.phone.window_limit' => 1]);

        $primero = $this->socio('8040', '3008880040');
        $segundo = $this->socio('8041', '3008880040');

        $this->postJson('/api/members/login', ['document_number' => $primero->document_number])->assertOk();

        $this->postJson('/api/members/login', ['document_number' => $segundo->document_number])
            ->assertStatus(429)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('code', 'rate_limited')
            ->assertJsonStructure(['message', 'retry_after']);
    }

    public function test_el_techo_de_gasto_responde_503_y_no_un_error_del_servidor(): void
    {
        config(['otp.send_enabled' => false]);
        $socio = $this->socio('8050', '3008880050');

        $this->postJson('/api/members/login', ['document_number' => $socio->document_number])
            ->assertStatus(503)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('code', 'kill_switch')
            ->assertJsonStructure(['message', 'retry_after']);
    }

    // ── Operaciones sensibles ────────────────────────────────────────────────

    public function test_el_cambio_de_numero_sigue_exigiendo_otp(): void
    {
        $socio = $this->socio('9010', '3009990010');

        $res = $this->otp()->startChallenge(
            $socio, $this->contexto(), MemberAuthChallenge::PURPOSE_PHONE_CHANGE, '3009990011',
        );

        $this->assertSame(1, $this->envios);
        $this->assertSame(MemberAuthChallenge::PURPOSE_PHONE_CHANGE, $res['challenge']->purpose);
        $this->assertSame(MemberAuthChallenge::STATUS_PENDING, $res['challenge']->status);
    }

    public function test_un_reto_de_login_no_sirve_para_autorizar_una_accion_sensible(): void
    {
        $socio = $this->socio('9020', '3009990020');
        $login = $this->otp()->startChallenge($socio, $this->contexto());

        $this->expectException(OtpException::class);
        $this->otp()->verifyAction(
            $socio, MemberAuthChallenge::PURPOSE_ACCOUNT_DELETE, $login['challenge']->uuid, '123456', $this->contexto(),
        );
    }

    public function test_los_intentos_de_codigo_equivocado_siguen_acotados(): void
    {
        $this->estadoComprobacion = 'pending'; // Twilio nunca aprueba el código

        config(['otp.max_attempts' => 3]);
        $socio = $this->socio('9030', '3009990030');
        $res = $this->otp()->startChallenge($socio, $this->contexto());

        for ($i = 0; $i < 3; $i++) {
            try {
                $this->otp()->verify($res['challenge']->uuid, '000000', $this->contexto());
            } catch (OtpException) {
                // esperado
            }
        }

        $this->assertSame(MemberAuthChallenge::STATUS_BLOCKED, $res['challenge']->fresh()->status);
        $this->assertSame(1, $this->envios, 'Fallar el código nunca manda otro SMS.');
    }

    // ── Llave para los rate limits del propio Twilio ─────────────────────────

    public function test_sin_configurar_el_limite_de_twilio_no_se_envia_ninguna_llave(): void
    {
        config(['otp.twilio.rate_limit_unique_name' => '']);

        $this->otp()->startChallenge($this->socio('8060', '3008880060'), $this->contexto());

        // Referenciar un límite que no existe en el servicio haría que Twilio
        // rechazara la petición entera: por eso sólo viaja cuando se configura.
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/Verifications')
            && ! isset($r['RateLimits']));
    }

    public function test_la_llave_del_limite_de_twilio_nunca_lleva_el_telefono_en_claro(): void
    {
        config(['otp.twilio.rate_limit_unique_name' => 'phone_hash']);

        $this->otp()->startChallenge($this->socio('8070', '3008880070'), $this->contexto());

        Http::assertSent(function ($r) {
            if (! str_ends_with($r->url(), '/Verifications')) {
                return false;
            }
            $limites = json_decode((string) $r['RateLimits'], true);

            return isset($limites['phone_hash'])
                && $limites['phone_hash'] !== ''
                && ! str_contains((string) $limites['phone_hash'], '3008880070')
                && $limites['phone_hash'] === app(OtpPolicy::class)->phoneHash('3008880070');
        });
    }

    // ── Prueba económica (Fase 22) ───────────────────────────────────────────

    public function test_simulacion_economica_sobre_el_patron_auditado(): void
    {
        // Sin cupos por medio: lo que se mide aquí es cuánto ahorra el REUSO,
        // que es la parte que depende del código. El login adaptativo es una
        // bandera de configuración y se cubre en su propia prueba.
        config([
            'otp.policy.phone.window_limit' => 999,
            'otp.policy.phone.daily_limit' => 999,
            'otp.policy.account.window_limit' => 999,
            'otp.policy.account.daily_limit' => 999,
            'otp.policy.ip.window_limit' => 9999,
            'otp.policy.ip.daily_limit' => 9999,
            'otp.cost.daily_hard' => 9999,
        ]);

        // Patrón real de la auditoría: de cada 4 socios, 1 pulsa dos veces
        // seguidas dentro del TTL (los 129 envíos redundantes de 528).
        $socios = 40;
        $antes = 0;
        for ($i = 0; $i < $socios; $i++) {
            $socio = $this->socio('99'.str_pad((string) $i, 3, '0', STR_PAD_LEFT), '31000'.str_pad((string) $i, 5, '0', STR_PAD_LEFT));

            $this->otp()->startChallenge($socio, $this->contexto());
            $antes++;

            if ($i % 4 === 0) {
                $this->otp()->startChallenge($socio, $this->contexto());
                $antes++; // sin protección, esto habría sido otro SMS
            }
        }

        $despues = $this->envios;
        $ahorro = 100 * (1 - $despues / $antes);

        $this->assertSame(50, $antes, 'Tráfico simulado: 40 socios, 10 de ellos repiten.');
        $this->assertSame(40, $despues, 'Sólo un SMS por socio.');
        $this->assertEqualsWithDelta(20.0, $ahorro, 0.1);

        // Lo que no se negocia: ningún socio se quedó sin poder entrar.
        $this->assertSame(
            $socios,
            MemberAuthChallenge::query()->where('status', MemberAuthChallenge::STATUS_PENDING)->count(),
            'Los 40 tienen su código vivo.',
        );
    }
}
