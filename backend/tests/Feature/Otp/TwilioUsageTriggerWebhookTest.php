<?php

namespace Tests\Feature\Otp;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Webhook de aviso de gasto de Twilio.
 *
 * Es un endpoint público, así que lo que se fija aquí es que nadie pueda
 * inventarse una alerta de gasto y que un reintento de Twilio no multiplique el
 * ruido. No controla nada del coste: para eso está el techo propio.
 */
class TwilioUsageTriggerWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const RUTA = '/api/webhooks/twilio/usage-trigger';

    private const TOKEN = 'auth-token-de-prueba';

    protected function setUp(): void
    {
        parent::setUp();
        config(['otp.twilio.token' => self::TOKEN]);
    }

    /** Firma igual que la calcula Twilio: HMAC-SHA1 sobre URL + params ordenados. */
    private function firma(array $datos, ?string $token = null): string
    {
        ksort($datos);
        $base = url(self::RUTA);
        foreach ($datos as $k => $v) {
            $base .= $k.$v;
        }

        return base64_encode(hash_hmac('sha1', $base, $token ?? self::TOKEN, true));
    }

    private function carga(string $idempotencia = 'tok-1'): array
    {
        return [
            'FriendlyName' => 'Gasto diario crítico',
            'UsageCategory' => 'totalprice',
            'TriggerBy' => 'price',
            'TriggerValue' => '6.00',
            'CurrentValue' => '6.43',
            'Recurring' => 'daily',
            'DateFired' => '2026-09-13T04:00:00Z',
            'IdempotencyToken' => $idempotencia,
        ];
    }

    public function test_sin_firma_se_rechaza(): void
    {
        $this->postJson(self::RUTA, $this->carga())->assertStatus(403);
    }

    public function test_una_firma_ajena_se_rechaza(): void
    {
        $datos = $this->carga();

        $this->withHeader('X-Twilio-Signature', $this->firma($datos, 'token-de-un-impostor'))
            ->post(self::RUTA, $datos)
            ->assertStatus(403);
    }

    public function test_manipular_un_valor_invalida_la_firma(): void
    {
        $datos = $this->carga();
        $firma = $this->firma($datos);
        $datos['CurrentValue'] = '9999.00';

        $this->withHeader('X-Twilio-Signature', $firma)
            ->post(self::RUTA, $datos)
            ->assertStatus(403);
    }

    public function test_sin_auth_token_configurado_falla_cerrado(): void
    {
        $datos = $this->carga();
        $firma = $this->firma($datos);
        config(['otp.twilio.token' => '']);

        $this->withHeader('X-Twilio-Signature', $firma)
            ->post(self::RUTA, $datos)
            ->assertStatus(403);
    }

    public function test_un_aviso_firmado_se_acepta_y_queda_registrado(): void
    {
        $registros = [];
        Log::listen(function (MessageLogged $m) use (&$registros) {
            // Sin escapar: si no, la tilde viaja como í y la comparación miente.
            $registros[] = $m->message.' '.json_encode($m->context, JSON_UNESCAPED_UNICODE);
        });

        $datos = $this->carga();

        $this->withHeader('X-Twilio-Signature', $this->firma($datos))
            ->post(self::RUTA, $datos)
            ->assertOk()
            ->assertJsonPath('ok', true);

        $todo = implode("\n", $registros);
        $this->assertStringContainsString('twilio.usage_trigger.alerta', $todo);
        $this->assertStringContainsString('Gasto diario crítico', $todo);
        $this->assertStringNotContainsString(self::TOKEN, $todo, 'El Auth Token nunca se registra.');
    }

    public function test_el_reintento_de_twilio_no_duplica_la_alerta(): void
    {
        $alertas = 0;
        Log::listen(function (MessageLogged $m) use (&$alertas) {
            if ($m->message === 'twilio.usage_trigger.alerta') {
                $alertas++;
            }
        });

        $datos = $this->carga('tok-repetido');
        $firma = $this->firma($datos);

        $this->withHeader('X-Twilio-Signature', $firma)->post(self::RUTA, $datos)->assertOk();
        $this->withHeader('X-Twilio-Signature', $firma)->post(self::RUTA, $datos)
            ->assertOk()
            ->assertJsonPath('duplicate', true);

        $this->assertSame(1, $alertas, 'Twilio reintenta; la alerta se emite una sola vez.');
    }

    public function test_dos_avisos_distintos_si_se_registran_por_separado(): void
    {
        $alertas = 0;
        Log::listen(function (MessageLogged $m) use (&$alertas) {
            if ($m->message === 'twilio.usage_trigger.alerta') {
                $alertas++;
            }
        });

        foreach (['tok-a', 'tok-b'] as $t) {
            $datos = $this->carga($t);
            $this->withHeader('X-Twilio-Signature', $this->firma($datos))->post(self::RUTA, $datos)->assertOk();
        }

        $this->assertSame(2, $alertas);
    }
}
