<?php

namespace Tests\Feature\Telephony;

use App\Models\Voice\VoiceBrakeEntry;
use App\Models\Voice\VoiceCall;
use App\Models\Voice\VoiceCallEvent;
use App\Services\Telephony\VoiceBrake;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Telephony\Support\VoiceFixtures;
use Tests\TestCase;

/**
 * `POST /api/webhooks/voice/elevenlabs/post-call` de punta a punta (contrato
 * H8, D8-8 «Después», D8-10 capa 4, D8-13, D8-16; fixture
 * `elevenlabs-webhook-bodies.json` §postCall y §routeRules): los 16 cuerpos
 * crudos del fixture, firmados como ElevenLabs, con su fila previa, y lo que
 * tiene que quedar: HTTP, eventos (tipo y clave), transición, sello, coste
 * crudo, guarda, freno y los centinelas que NUNCA se guardan (ni en la base
 * ni en el registro).
 */
class VoicePostCallWebhookTest extends TestCase
{
    use RefreshDatabase;
    use VoiceFixtures;

    private const URI = '/api/webhooks/voice/elevenlabs/post-call';

    private string $log;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake();
        $this->voiceWorld();
        $this->travelTo(Carbon::createFromTimestamp(1793804400));   // world.now del fixture
        $this->log = storage_path('logs/voice-prueba-'.bin2hex(random_bytes(4)).'.log');
        config(['logging.channels.voice' => ['driver' => 'single', 'path' => $this->log, 'level' => 'debug']]);
    }

    protected function tearDown(): void
    {
        if (is_file($this->log)) {
            unlink($this->log);
        }
        parent::tearDown();
    }

    /** @param  array<string, mixed>  $c */
    private function given(array $c): ?VoiceCall
    {
        $g = $c['given']['call'] ?? null;
        if ($g === null) {
            return null;
        }
        $o = ['call_ref' => $g['callRef'], 'provider_agent_id' => $g['sealedAgentId']];
        if ($g['providerConversationId'] !== null) {
            $o['provider_conversation_id'] = $g['providerConversationId'];
        }
        if ($g['providerCallSid'] !== null) {
            $o['provider_call_sid'] = $g['providerCallSid'];
        }
        $llamada = $this->voiceCall($g['direction'], $g['status'], $o);
        foreach ($c['given']['existingEvents'] ?? [] as $e) {
            DB::table('voice_call_events')->insert(['uuid' => (string) Str::uuid(), 'call_id' => $llamada->id, 'provider' => $e['provider'], 'kind' => $e['kind'],
                'provider_event_key' => $e['providerEventKey'], 'actor_type' => 'provider', 'created_at' => now()]);
        }

        return $llamada;
    }

    /** @return array<string, array{0: array<string, mixed>}> los 16 casos de §postCall, uno por prueba (cada uno en su transacción) */
    public static function postCallCases(): array
    {
        $casos = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/fixtures/voice/elevenlabs-webhook-bodies.json'), true)['postCall'];
        $out = [];
        foreach ($casos as $c) {
            $out[$c['name']] = [$c];
        }

        return $out;
    }

    /** @param  array<string, mixed>  $c */
    #[DataProvider('postCallCases')]
    public function test_cada_cuerpo_del_fixture_deja_lo_que_dice_el_contrato(array $c): void
    {
        $llamada = $this->given($c);
        $e = $c['expected'];
        $r = $this->rawPost(self::URI, $c['rawBody'], ['ElevenLabs-Signature' => $this->elevenSignature($c['rawBody'])]);
        $r->assertStatus($e['http']);

        $previos = array_map(static fn (array $x): array => [$x['provider'], $x['kind'], $x['providerEventKey']], $c['given']['existingEvents'] ?? []);
        $nuevos = VoiceCallEvent::query()->orderBy('id')->get()
            ->reject(static fn (VoiceCallEvent $ev): bool => in_array([$ev->provider, $ev->kind, $ev->provider_event_key], $previos, true))
            ->map(static fn (VoiceCallEvent $ev): array => ['provider' => $ev->provider, 'kind' => $ev->kind, 'providerEventKey' => $ev->provider_event_key])->values()->all();
        $this->assertSame($e['events'], $nuevos, 'eventos');

        if ($llamada !== null) {
            $despues = VoiceCall::query()->findOrFail($llamada->id);
            $this->assertSame($e['transition']['to'] ?? ($e['statusAfter'] ?? $llamada->status), $despues->status, 'estado');
            if (isset($e['durationSecs'])) {
                $this->assertSame($e['durationSecs'], $despues->duration_seconds, 'duración');
            }
            if (isset($e['seal'])) {
                $this->assertSame([$e['seal']['observedAgentId'], $e['seal']['observedVersionId'], $e['seal']['observedBranchId'], $e['seal']['sealVerified']],
                    [$despues->observed_agent_id, $despues->observed_version_id, $despues->observed_branch_id, $despues->seal_verified], 'sello');
            }
            if (isset($e['costRaw'])) {
                $this->assertSame($e['costRaw'], VoiceCallEvent::query()->where('call_id', $llamada->id)->where('kind', 'post_call_transcription')->latest('id')->first()?->codes['costRaw'], 'coste crudo');
            }
        }
        if (($e['guard'] ?? null) !== null) {
            $this->assertSame($e['guard']['violations'], VoiceCallEvent::query()->where('kind', 'guard_verdict')->latest('id')->first()?->codes['violations'], 'guarda');
        }
        $this->assertSame($e['brakeOpens'], VoiceBrake::isOpen(), 'freno');
        $this->assertNothingPersisted($e['mustNotPersist'] ?? [], $c['name']);
        Http::assertNothingSent();
    }

    public function test_firma_mala_o_sin_secreto_es_401_sin_escribir_nada(): void
    {
        $cuerpo = '{"type":"post_call_transcription","event_timestamp":1793804340,"data":{"agent_id":"agent_h8fx_canary_0003","conversation_id":"conv_h8fx_0001"}}';
        foreach ([null, '', 't=1793804340,v0='.str_repeat('0', 64), 'basura', $this->elevenSignature($cuerpo, 1793804400 - 1801), $this->elevenSignature($cuerpo, 1793804400 + 301), $this->elevenSignature($cuerpo, null, 'otro-secreto')] as $firma) {
            $cabeceras = $firma === null ? [] : ['ElevenLabs-Signature' => $firma];
            $this->rawPost(self::URI, $cuerpo, $cabeceras)->assertStatus(401)->assertJson(['ok' => false, 'code' => 'invalid_signature']);
        }
        config(['voice.elevenlabs.webhook_secret' => null]);
        $this->rawPost(self::URI, $cuerpo, ['ElevenLabs-Signature' => $this->elevenSignature($cuerpo)])->assertStatus(401);
        $this->assertSame(0, VoiceCallEvent::query()->count());
        $this->assertSame(0, VoiceBrakeEntry::query()->count());
    }

    public function test_el_tope_de_un_mega_va_antes_que_la_firma(): void
    {
        $grande = '{"type":"x","data":"'.str_repeat('a', 1048576).'"}';
        $this->rawPost(self::URI, $grande, ['ElevenLabs-Signature' => $this->elevenSignature($grande)])->assertStatus(413);
        $justo = str_repeat(' ', 1048576);
        $this->rawPost(self::URI, $justo, ['ElevenLabs-Signature' => $this->elevenSignature($justo)])->assertOk()->assertJson(['ignored' => true]);
    }

    public function test_una_violacion_de_la_guarda_abre_el_freno_y_solo_guarda_codigos(): void
    {
        $this->voiceCall('outbound', 'active', ['provider_conversation_id' => 'conv_h8fx_0901', 'provider_call_sid' => 'CA000000000000000000000000a8000901']);
        $cuerpo = (string) json_encode(['type' => 'post_call_transcription', 'event_timestamp' => 1793804340, 'data' => [
            'agent_id' => self::SEALED_AGENT, 'conversation_id' => 'conv_h8fx_0901',
            'transcript' => [
                ['role' => 'agent', 'message' => '¡Hola! ¿En qué te ayudo?'],
                ['role' => 'user', 'message' => 'Soy Ana Prueba, ¿cuánto vale?'],
                ['role' => 'agent', 'message' => 'Te queda en $ 79.000 y la primera semana es gratis.'],
            ],
            'metadata' => ['call_duration_secs' => 40],
        ]], JSON_UNESCAPED_UNICODE);
        $this->rawPost(self::URI, $cuerpo, ['ElevenLabs-Signature' => $this->elevenSignature($cuerpo)])->assertOk()->assertJson(['violations' => 2]);
        $veredicto = VoiceCallEvent::query()->where('kind', 'guard_verdict')->sole();
        $this->assertSame([['turn' => 0, 'code' => 'voice_notice_missing'], ['turn' => 2, 'code' => 'voice_money_not_from_tool']], $veredicto->codes['violations']);
        $freno = VoiceBrakeEntry::query()->sole();
        $this->assertSame(['guard_violation', ['codes' => ['voice_notice_missing', 'voice_money_not_from_tool']]], [$freno->reason, $freno->evidence]);
        $this->assertNothingPersisted(['Ana Prueba', '79.000', 'gratis', '¿En qué te ayudo?'], 'guarda');
    }

    /** @param  list<string>  $centinelas */
    private function assertNothingPersisted(array $centinelas, string $caso): void
    {
        $volcado = json_encode([
            DB::table('voice_calls')->get()->all(), DB::table('voice_call_events')->get()->all(), DB::table('voice_brakes')->get()->all(),
        ], JSON_UNESCAPED_UNICODE).(is_file($this->log) ? (string) file_get_contents($this->log) : '');
        foreach ($centinelas as $s) {
            $this->assertStringNotContainsString($s, $volcado, "{$caso}: «{$s}» quedó guardado o registrado");
        }
    }
}
