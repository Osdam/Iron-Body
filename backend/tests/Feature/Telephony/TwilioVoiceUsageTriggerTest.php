<?php

namespace Tests\Feature\Telephony;

use App\Models\Voice\VoiceBrakeEntry;
use App\Models\Voice\VoiceCallEvent;
use App\Services\Telephony\VoiceBrake;
use App\Services\Telephony\VoiceEvents;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Telephony\Support\VoiceFixtures;
use Tests\TestCase;

/**
 * `POST /api/webhooks/voice/twilio/usage-trigger` (contrato H8, D8-16, D8-19;
 * fixture `twilio-webhook-bodies.json` §usageTrigger): los 7 cuerpos de
 * formulario del fixture con su `X-Twilio-Signature` real (token de la
 * subcuenta y base canónica del fixture). Sin red.
 *
 * DIFERENCIA DECLARADA (DF-W1, Q-5 pendiente del lead): el fixture llama al
 * evento `usage_alert`, que no está en el conjunto cerrado de D8-13 ni en la
 * base de DB8; se persiste como `cost_recorded` (proveedor `twilio`) con
 * `outcome = usage_alert`, deduplicado por el `IdempotencyToken` tal cual.
 */
class TwilioVoiceUsageTriggerTest extends TestCase
{
    use RefreshDatabase;
    use VoiceFixtures;

    private const URI = '/api/webhooks/voice/twilio/usage-trigger';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake();
        $this->voiceWorld(['voice.twilio.signing_token' => 'h8fixturesubaccountsigning000001', 'voice.webhook_base_url' => 'https://crm.ironbody.test']);
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function usageCases(): array
    {
        $casos = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/fixtures/voice/twilio-webhook-bodies.json'), true)['usageTrigger'];
        $out = [];
        foreach ($casos as $c) {
            $out[$c['name']] = [$c];
        }

        return $out;
    }

    /** @param  array<string, mixed>  $c */
    #[DataProvider('usageCases')]
    public function test_cada_aviso_del_fixture(array $c): void
    {
        $this->assertSame(self::URI, $c['requestUri']);
        foreach ($c['given']['seenIdempotencyTokens'] as $token) {
            $evento = VoiceEvents::record('cost_recorded', null, VoiceCallEvent::PROVIDER_TWILIO, $token, ['outcome' => 'usage_alert']);
            VoiceBrake::open('subaccount_alert', 'usage:'.$token, [], null, $evento?->id);
        }
        $antesEventos = VoiceCallEvent::query()->count();
        $antesFrenos = VoiceBrakeEntry::query()->count();

        $firma = $c['headers']['X-Twilio-Signature'] ?? null;
        $cabeceras = ['Content-Type' => 'application/x-www-form-urlencoded'] + ($firma === null || $firma === '' ? [] : ['X-Twilio-Signature' => $firma]);
        $r = $this->rawPost(self::URI, $c['rawBody'], $cabeceras);
        $e = $c['expected'];
        $r->assertStatus($e['http']);

        $nuevos = VoiceCallEvent::query()->count() - $antesEventos;
        $this->assertSame($e['persisted'] ? 1 : 0, $nuevos, 'persistido');
        if ($e['persisted']) {
            $evento = VoiceCallEvent::query()->latest('id')->firstOrFail();
            $this->assertSame(['twilio', 'cost_recorded', 'usage_alert', $e['dedupeKey']], [$evento->provider, $evento->kind, $evento->outcome, $evento->provider_event_key]);
            $this->assertSame('usage_trigger', $evento->codes['source']);
            $this->assertNull($evento->call_id);
        }
        $this->assertSame($e['brakeOpens'] ? 1 : 0, VoiceBrakeEntry::query()->count() - $antesFrenos, 'freno');
        if ($e['brakeOpens']) {
            $this->assertSame('subaccount_alert', VoiceBrakeEntry::query()->latest('id')->firstOrFail()->reason);
            $this->assertTrue(VoiceBrake::isOpen());
        }
        if (($e['duplicate'] ?? false) === true) {
            $r->assertJson(['duplicate' => true]);
        }
        Http::assertNothingSent();
    }

    public function test_sin_token_de_firma_o_sin_base_canonica_es_401(): void
    {
        $caso = self::usageCases()['usageTrigger · usageTrigger · aviso diario de la subcuenta, bien firmado → 200, se persiste y abre el freno'][0];
        foreach ([['voice.twilio.signing_token' => null], ['voice.webhook_base_url' => null], ['voice.webhook_base_url' => 'https://otra.ironbody.test']] as $cambio) {
            config($cambio);
            $this->rawPost(self::URI, $caso['rawBody'], ['Content-Type' => 'application/x-www-form-urlencoded', 'X-Twilio-Signature' => $caso['headers']['X-Twilio-Signature']])->assertStatus(401);
            $this->voiceWorld(['voice.twilio.signing_token' => 'h8fixturesubaccountsigning000001', 'voice.webhook_base_url' => 'https://crm.ironbody.test']);
        }
        $this->assertSame(0, VoiceCallEvent::query()->count());
    }

    public function test_el_tope_de_cuerpo(): void
    {
        $this->rawPost(self::URI, str_repeat('a', 65537), ['Content-Type' => 'application/x-www-form-urlencoded'])->assertStatus(413);
    }
}
