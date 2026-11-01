<?php

namespace Tests\Feature\UltronStudio;

use App\Support\Privacy\PhoneFingerprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * LA PRUEBA CENTINELA (contrato H6 §I1 P5 y §I9.6): valores reconocibles en
 * cada secreto (`TWILIO_TOKEN`/`TWILIO_AUTH_TOKEN`, `META_ADS_ACCESS_TOKEN`,
 * `ELEVENLABS_API_KEY`, `ELEVENLABS_WEBHOOK_SECRET` y la llave de la sonda), en
 * el teléfono del lead canario de la semilla y en los números que se
 * registran. Se recorren las 18 rutas (con las escrituras y la sonda
 * encendidas, rechazos incluidos) y los dos comandos; ninguno de esos valores
 * —ni las huellas de los teléfonos— aparece en ninguna respuesta, en el libro,
 * en `facts`, en las salidas de consola ni en el log capturado.
 */
class UltronStudioIntegrationsSentinelTest extends TestCase
{
    use IntegrationFixtures;
    use RefreshDatabase;
    use StudioFixtures;

    private const TWILIO_TOKEN = 'CENTINELAtwilioTOKEN7f3a9c01';

    private const SENTINEL_META_TOKEN = 'CENTINELAmetaTOKEN8b2d04';

    private const EL_API_KEY = 'CENTINELAelevenAPIKEY5e61';

    private const EL_WEBHOOK = 'CENTINELAelevenWEBHOOK9a07';

    private const PROBE_SECRET = 'CENTINELAprobeKEYSECRET3c88';

    /** El teléfono del lead canario y los que se registran (WhatsApp y voz). */
    private const LEAD_PHONE = '3001239876';

    private const WA_PHONE = '+573005550123';

    private const VOICE_PHONE = '+576015550123';

    /** @var list<string> */
    private array $seen = [];

    public function test_ningun_secreto_ni_telefono_sale_por_ninguna_via(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        Event::listen(MessageLogged::class, function (MessageLogged $m): void {
            $this->seen[] = 'log:'.$m->level.' '.$m->message.' '.json_encode($m->context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        });

        $this->integrationsFlags(writes: true, probe: true);
        config([
            'meta.ads.access_token' => self::SENTINEL_META_TOKEN,
            'otp.driver' => 'twilio',
            'otp.twilio.sid' => 'AC0123456789abcdef0123456789abcdef',
            'otp.twilio.token' => self::TWILIO_TOKEN,
            'otp.twilio.verify_service_sid' => 'VA'.str_repeat('0', 28).'9f0e',
            'ultron_studio.integrations.twilio_aliases' => ['sid_source' => 'TWILIO_SID', 'token_source' => 'TWILIO_TOKEN', 'sid_consistent' => true, 'token_consistent' => true],
            'ultron_studio.integrations.twilio_probe.key_sid' => 'SK'.str_repeat('5', 32),
            'ultron_studio.integrations.twilio_probe.key_secret' => self::PROBE_SECRET,
            'voice.elevenlabs.api_key' => self::EL_API_KEY,
            'voice.elevenlabs.webhook_secret' => self::EL_WEBHOOK,
            'voice.elevenlabs.agent_id' => 'agent_centinela',
        ]);
        $this->metaConfigured();
        config(['meta.ads.access_token' => self::SENTINEL_META_TOKEN]);
        $this->metaCampaign(self::ACCOUNTS[0], '120000000000000387', 'Pauta '.self::LEAD_PHONE);
        $this->engineCanary(self::LEAD_PHONE);
        // La sonda devuelve números de personas por todas partes: el saneador los tira.
        Http::fake(fn (HttpRequest $r) => Http::response([
            'sid' => 'AC0123456789abcdef0123456789abcdef', 'status' => 'active', 'type' => 'Full', 'friendly_name' => 'Iron '.self::WA_PHONE,
            'auth_token' => self::TWILIO_TOKEN,
            'incoming_phone_numbers' => [['phone_number' => self::VOICE_PHONE, 'friendly_name' => self::WA_PHONE, 'capabilities' => ['voice' => true, 'sms' => true]]],
            'outgoing_caller_ids' => [['phone_number' => self::WA_PHONE, 'friendly_name' => 'Ana '.self::WA_PHONE]],
            'calls' => [['to' => self::WA_PHONE, 'from' => self::VOICE_PHONE, 'status' => 'completed']],
            'alerts' => [['error_code' => '60200', 'log_level' => 'error', 'alert_text' => 'To: '.self::WA_PHONE]],
        ], 200));

        $ana = $this->personWith(self::ADMIN_KEYS, 'Ana');
        $root = $this->personWith(self::SUPER_KEYS, 'Root');

        // Rechazos con el teléfono en el cuerpo (422) y escrituras reales.
        $this->see($this->registerCanary($ana, self::WA_PHONE.' ext 1'));
        $this->see($this->registerCanary($ana, '+57 601 555 0123'));
        $this->see($this->registerCanary($ana, self::WA_PHONE, alias: 'Ana '.self::LEAD_PHONE));
        $wa = (string) $this->see($this->registerCanary($ana, self::WA_PHONE))->json('data.number.uuid');
        $this->see($this->registerCanary($ana, '+57 300 555 0123'));
        $voz = (string) $this->see($this->registerCanary($ana, self::VOICE_PHONE, 'voice', 'Fijo de recepción'))->json('data.number.uuid');
        foreach ([$wa, $voz] as $uuid) {
            $this->see($this->integrations('POST', "canaries/{$uuid}/authorize", $ana));
            $this->see($this->integrations('POST', "canaries/{$uuid}/authorize", $root));
            $this->see($this->integrations('POST', "canaries/{$uuid}/activate", $ana));
            $this->see($this->integrations('POST', "canaries/{$uuid}/activate", $root));
            $this->see($this->integrations('POST', "canaries/{$uuid}/expectation", $ana, ['versionUuid' => null]));
            $this->see($this->integrations('POST', "canaries/{$uuid}/deactivate", $ana, ['reason' => 'Motivo de la centinela']));
        }
        $campana = self::campaignId('120000000000000387');
        $enlace = (string) $this->see($this->integrations('POST', 'meta/links', $ana, ['clientRequestId' => (string) Str::uuid(), 'campaign' => $campana, 'target' => ['kind' => 'baseline']]))->json('data.link.uuid');
        $this->see($this->integrations('POST', "meta/links/{$enlace}/deactivate", $ana, ['reason' => 'Motivo de la centinela']));
        foreach (['meta_ads', 'twilio', 'voice'] as $i => $proveedor) {
            $huella = $this->see($this->integrations('GET', 'config', $root))->json("data.providers.{$i}.effectiveFingerprint");
            $this->see($this->integrations('POST', 'config/acknowledge', $root, ['clientRequestId' => (string) Str::uuid(), 'provider' => $proveedor, 'fingerprint' => $huella]));
        }
        $this->see($this->integrations('POST', 'config/acknowledge', $root, ['clientRequestId' => (string) Str::uuid(), 'provider' => 'twilio', 'fingerprint' => str_repeat('0', 64)]));
        $this->see($this->integrations('POST', 'twilio/probe', $root, ['clientRequestId' => (string) Str::uuid()]));
        foreach (['status', 'meta/campaigns', 'meta/links', 'canaries?status=all', 'twilio', 'twilio/calls', 'voice', 'config'] as $lectura) {
            $this->see($this->integrations('GET', $lectura, $ana));
        }
        $this->see($this->integrations('POST', "canaries/{$wa}/revoke", $ana, ['reason' => 'Motivo de la centinela']));
        $this->see($this->integrations('POST', "canaries/{$voz}/revoke", $ana, ['reason' => 'Motivo de la centinela']));

        foreach ([['ultron-studio:integrations-doctor', ['--json' => true]], ['ultron-studio:integrations-doctor', []], ['ultron-studio:integrations-snapshot', ['--json' => true]]] as [$comando, $opciones]) {
            Artisan::call($comando, $opciones);
            $this->seen[] = 'consola:'.Artisan::output();
        }

        // Lo que queda en la base del Studio: el libro entero y los `facts`.
        $this->seen[] = 'libro:'.json_encode(DB::table('ultron_studio_audit_events')->get(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->seen[] = 'facts:'.json_encode(DB::table('ultron_studio_integration_snapshots')->get(['facts', 'note', 'actor_name']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->seen[] = 'asociaciones:'.json_encode(DB::table('ultron_studio_campaign_links')->get(['campaign_name_at_link', 'deactivation_reason']), JSON_UNESCAPED_UNICODE);

        $centinelas = [
            self::TWILIO_TOKEN, self::SENTINEL_META_TOKEN, self::EL_API_KEY, self::EL_WEBHOOK, self::PROBE_SECRET,
            self::LEAD_PHONE, '57'.self::LEAD_PHONE, '3005550123', '6015550123', '300 555 0123', '601 555 0123',
            PhoneFingerprint::of('+57'.self::LEAD_PHONE), PhoneFingerprint::of(self::WA_PHONE), PhoneFingerprint::of(self::VOICE_PHONE),
        ];
        $todo = implode("\n", $this->seen);
        $this->assertGreaterThan(40, count($this->seen), 'el recorrido vio las respuestas, el libro, los facts, la consola y el log');
        $this->assertStringContainsString('canary_number.registered', $todo, 'el libro está en lo que se mira');
        // Las respuestas JSON escapan el «•» (U+2022): se busca escapado.
        $this->assertStringContainsString('+57 \\u2022\\u2022\\u2022 \\u2022\\u2022\\u2022 0123', $todo, 'los números enmascarados sí salen');
        foreach ($centinelas as $centinela) {
            $this->assertStringNotContainsString($centinela, $todo, "«{$centinela}» salió por alguna vía.");
        }
    }

    private function see(TestResponse $r): TestResponse
    {
        $this->assertLessThan(500, $r->status(), $r->getContent());
        $this->seen[] = 'respuesta:'.$r->getContent();

        return $r;
    }
}
