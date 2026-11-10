<?php

namespace Tests\Feature\Telephony;

use App\Models\Voice\VoiceBrakeEntry;
use App\Models\Voice\VoiceCall;
use App\Models\Voice\VoiceCallEvent;
use App\Services\Telephony\VoiceBrake;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Telephony\Support\VoiceFixtures;
use Tests\TestCase;

/**
 * `POST /api/webhooks/voice/elevenlabs/initiation` de punta a punta (contrato
 * H8, D8-11, D8-16, E1-2; fixture `elevenlabs-webhook-bodies.json`
 * §initiation). Sin red (`Http::preventStrayRequests()`).
 *
 * En el árbol compartido la constante está cerrada: NINGUNA entrante sale
 * autorizada (`calls_disabled_in_code`) y toda iniciación deja su fila
 * `denied` con su sello, su huella y sus 4 dígitos. La vía autorizada la
 * recorre TEST8 en la copia del banco con la constante girada.
 */
class VoiceInitiationWebhookTest extends TestCase
{
    use RefreshDatabase;
    use VoiceFixtures;

    private const URI = '/api/webhooks/voice/elevenlabs/initiation';

    private const SENTINELS = ['+573000000031', '+573000000077', '573000000031', '573000000077', '+576010000090'];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake();
        $this->voiceWorld();
    }

    private function body(string $caller, string $sid, string $conversation = 'conv_h8fx_0021', string $agent = self::SEALED_AGENT, string $called = self::VOICE_FROM): string
    {
        return (string) json_encode(['caller_id' => $caller, 'agent_id' => $agent, 'called_number' => $called, 'call_sid' => $sid, 'conversation_id' => $conversation]);
    }

    private function sid(string $n): string
    {
        return 'CA000000000000000000000000a80000'.$n;
    }

    /** @return array<string, mixed> */
    private function assertCleanResponse(TestResponse $r, string $authorized): array
    {
        $r->assertOk();
        $json = $r->json();
        $this->assertSame(['type', 'dynamic_variables'], array_keys($json));
        $this->assertSame('conversation_initiation_client_data', $json['type']);
        $this->assertSame(['caller_authorized', 'call_direction', 'call_ref', 'version_label'], array_keys($json['dynamic_variables']));
        $this->assertSame($authorized, $json['dynamic_variables']['caller_authorized']);
        $this->assertSame('entrante', $json['dynamic_variables']['call_direction']);
        $this->assertTrue(Str::isUuid($json['dynamic_variables']['call_ref']));
        foreach (self::SENTINELS as $s) {
            $this->assertStringNotContainsString($s, (string) $r->getContent(), 'la respuesta lleva un teléfono');
        }
        foreach (['conversation_config_override', 'caller_id', 'called_number', 'user_id'] as $prohibida) {
            $this->assertStringNotContainsString($prohibida, (string) $r->getContent());
        }

        return $json['dynamic_variables'];
    }

    public function test_sin_el_secreto_bueno_es_401_sin_fila_ni_evento(): void
    {
        $cuerpo = $this->body(self::CANARY_PHONE, $this->sid('1b'));
        foreach ([null, 'h8fixture-initiation-0000000000000000000009', self::TOOL_SECRET, ''] as $secreto) {
            $this->rawPost(self::URI, $cuerpo, $this->initiationHeaders($secreto))->assertStatus(401)->assertJson(['ok' => false, 'code' => 'unauthorized']);
        }
        // Sin secreto configurado no vale ninguna cabecera, tampoco la vacía (nunca hash_equals('', '')).
        config(['voice.elevenlabs.initiation_secret' => null]);
        $this->rawPost(self::URI, $cuerpo, $this->initiationHeaders(''))->assertStatus(401);
        $this->rawPost(self::URI, $cuerpo, $this->initiationHeaders(self::INITIATION_SECRET))->assertStatus(401);
        $this->assertSame(0, VoiceCall::query()->count());
        $this->assertSame(0, VoiceCallEvent::query()->count());
        Http::assertNothingSent();
    }

    public function test_el_tope_de_8_kb_va_antes_de_mirar_nada(): void
    {
        $this->rawPost(self::URI, str_repeat('x', 8193), $this->initiationHeaders())->assertStatus(413)->assertJson(['code' => 'payload_too_large']);
        $this->rawPost(self::URI, str_repeat(' ', 8192), $this->initiationHeaders())->assertOk();   // pasa el tope (y se deniega por mal formada)
        $this->assertSame(0, VoiceCall::query()->count());
    }

    public function test_autenticada_pero_mal_formada_se_deniega_explicitamente_sin_fila(): void
    {
        $v = $this->assertCleanResponse($this->rawPost(self::URI, (string) json_encode(['caller_id' => self::CANARY_PHONE, 'agent_id' => self::SEALED_AGENT, 'called_number' => self::VOICE_FROM]), $this->initiationHeaders()), 'no');
        $this->assertSame('canario · sin publicación', $v['version_label']);
        $this->assertSame(0, VoiceCall::query()->count());
        $this->assertCleanResponse($this->rawPost(self::URI, 'esto no es JSON', $this->initiationHeaders()), 'no');
    }

    public function test_un_canario_activo_con_la_constante_cerrada_queda_denied_con_su_sello_huella_y_evento(): void
    {
        $canario = $this->activeVoiceCanary();
        $pub = $this->publishVoice();

        $v = $this->assertCleanResponse($this->rawPost(self::URI, $this->body(self::CANARY_PHONE, $this->sid('15')), $this->initiationHeaders()), 'no');
        $this->assertSame('canario · publicación '.$pub['seq'], $v['version_label']);

        $fila = VoiceCall::query()->sole();
        $this->assertSame(['inbound', 'denied', false, 'calls_disabled_in_code'], [$fila->direction, $fila->status, $fila->caller_authorized, $fila->policy_reason]);
        $this->assertSame($v['call_ref'], $fila->call_ref);
        $this->assertSame($canario, $fila->canary_number_uuid);
        // La huella y los 4 dígitos del fixture (§initiation), nunca el número.
        $this->assertSame('ff5fdd07e94788f57149e5abc085c8f0a19e926c6c68f8708c13a0bf10f0ce1d', $fila->remote_hash);
        $this->assertSame('0031', $fila->remote_last4);
        // El sello de la parte canaria vigente, escrito al empezar (D8-8).
        $this->assertSame(['applied', 'canary', $pub['seq'], self::SEALED_AGENT, $pub['specHash'], $pub['payloadHash'], 'ultron-voice-compiler/1', $pub['versionUuid']],
            [$fila->stamp_state, $fila->part, $fila->voice_publication_seq, $fila->provider_agent_id, $fila->spec_hash, $fila->payload_hash, $fila->compiler_version, $fila->studio_version_uuid]);
        $this->assertSame([$this->sid('15'), 'conv_h8fx_0021'], [$fila->provider_call_sid, $fila->provider_conversation_id]);
        $evento = VoiceCallEvent::query()->sole();
        $this->assertSame(['initiation_received', 'elevenlabs', $this->sid('15'), 'denied'], [$evento->kind, $evento->provider, $evento->provider_event_key, $evento->outcome]);
        $this->assertSame(['calls_disabled_in_code'], $evento->codes['reasons'], 'el canario activo con todo abierto: solo la constante lo para');
        $this->assertFalse(VoiceBrake::isOpen());
        Http::assertNothingSent();
    }

    public function test_la_misma_iniciacion_dos_veces_da_la_misma_respuesta_y_una_sola_fila(): void
    {
        $this->publishVoice();
        $a = $this->assertCleanResponse($this->rawPost(self::URI, $this->body(self::CANARY_PHONE, $this->sid('15')), $this->initiationHeaders()), 'no');
        $b = $this->assertCleanResponse($this->rawPost(self::URI, $this->body(self::CANARY_PHONE, $this->sid('15')), $this->initiationHeaders()), 'no');
        $this->assertSame($a, $b);
        $this->assertSame(1, VoiceCall::query()->count());
        $this->assertSame(1, VoiceCallEvent::query()->where('kind', 'initiation_received')->count());
    }

    public function test_numero_no_registrado_oculto_freno_y_numero_llamado_ajeno_se_niegan_por_nombre(): void
    {
        $this->activeVoiceCanary();
        $this->publishVoice();

        $this->assertCleanResponse($this->rawPost(self::URI, $this->body(self::STRANGER_PHONE, $this->sid('16'), 'conv_h8fx_0022'), $this->initiationHeaders()), 'no');
        $extrano = VoiceCall::query()->where('provider_call_sid', $this->sid('16'))->sole();
        $this->assertSame('b5d53bc17ee5dcc95f38308e60d13a9107b08c846d9a3582cb5953d43ec16ae2', $extrano->remote_hash);
        $this->assertContains('not_an_active_voice_canary', VoiceCallEvent::query()->where('call_id', $extrano->id)->sole()->codes['reasons']);

        $this->assertCleanResponse($this->rawPost(self::URI, $this->body('anonymous', $this->sid('17'), 'conv_h8fx_0023'), $this->initiationHeaders()), 'no');
        $oculto = VoiceCall::query()->where('provider_call_sid', $this->sid('17'))->sole();
        $this->assertSame([null, null, 'denied'], [$oculto->remote_hash, $oculto->remote_last4, $oculto->status], 'DF-E17: sin huella sobre «anonymous»');

        $this->assertCleanResponse($this->rawPost(self::URI, $this->body(self::CANARY_PHONE, $this->sid('1a'), 'conv_h8fx_0026', self::SEALED_AGENT, '+576010000091'), $this->initiationHeaders()), 'no');
        $this->assertContains('wrong_called_number', VoiceCallEvent::query()->where('provider_event_key', $this->sid('1a'))->sole()->codes['reasons']);

        VoiceBrake::open('guard_violation', 'guard:prueba', []);
        $this->assertCleanResponse($this->rawPost(self::URI, $this->body(self::CANARY_PHONE, $this->sid('18'), 'conv_h8fx_0024'), $this->initiationHeaders()), 'no');
        $this->assertContains('voice_braked', VoiceCallEvent::query()->where('provider_event_key', $this->sid('18'))->sole()->codes['reasons']);
    }

    public function test_un_agent_id_que_no_es_el_sellado_abre_el_freno_con_seal_mismatch(): void
    {
        $this->activeVoiceCanary();
        $this->publishVoice();

        $this->assertCleanResponse($this->rawPost(self::URI, $this->body(self::CANARY_PHONE, $this->sid('19'), 'conv_h8fx_0025', 'agent_h8fx_canary_0002'), $this->initiationHeaders()), 'no');
        $fila = VoiceCall::query()->sole();
        $this->assertSame('denied', $fila->status);
        $this->assertSame(['initiation_received', 'seal_mismatch'], VoiceCallEvent::query()->where('call_id', $fila->id)->orderBy('id')->pluck('kind')->all());
        $freno = VoiceBrakeEntry::query()->sole();
        $this->assertSame(['open', 'seal_mismatch', 'seal:'.$this->sid('19')], [$freno->kind, $freno->reason, $freno->open_key]);
        $this->assertTrue(VoiceBrake::isOpen());
    }

    /**
     * B8-g (SEC8-c M-2), los dos modos del número. Sin declarar (o declarado
     * `true`), lo de siempre: al canario activo solo lo para la constante.
     * Declarado «Outbound Only» (`VOICE_NUMBER_INBOUND_CAPABLE=false`), la
     * iniciación se deniega SIEMPRE, con las entrantes encendidas, y el primer
     * motivo (el de `policy_reason`) es `number_outbound_only`.
     */
    public function test_con_el_numero_declarado_solo_saliente_la_iniciacion_deniega_siempre(): void
    {
        $this->activeVoiceCanary();
        $this->publishVoice();
        $this->assertTrue(config('voice.inbound.enabled'));
        $motivos = fn (string $sid): array => VoiceCallEvent::query()->where('provider_event_key', $sid)->sole()->codes['reasons'];

        // Sin declarar: lo de siempre.
        $this->assertCleanResponse($this->rawPost(self::URI, $this->body(self::CANARY_PHONE, $this->sid('31'), 'conv_h8fx_0031'), $this->initiationHeaders()), 'no');
        $this->assertSame(['calls_disabled_in_code'], $motivos($this->sid('31')));

        // Declarado solo saliente: denegada siempre, y es su primer motivo.
        config(['voice.number.inbound_capable' => false]);
        $this->assertCleanResponse($this->rawPost(self::URI, $this->body(self::CANARY_PHONE, $this->sid('32'), 'conv_h8fx_0032'), $this->initiationHeaders()), 'no');
        $fila = VoiceCall::query()->where('provider_call_sid', $this->sid('32'))->sole();
        $this->assertSame(['denied', false, 'number_outbound_only'], [$fila->status, $fila->caller_authorized, $fila->policy_reason]);
        $this->assertSame(['number_outbound_only', 'calls_disabled_in_code'], $motivos($this->sid('32')));
        $this->assertFalse(VoiceBrake::isOpen(), 'con el agente sellado no hay sello roto: solo se deniega');

        // Declarado `true`: lo de siempre otra vez.
        config(['voice.number.inbound_capable' => true]);
        $this->assertCleanResponse($this->rawPost(self::URI, $this->body(self::CANARY_PHONE, $this->sid('33'), 'conv_h8fx_0033'), $this->initiationHeaders()), 'no');
        $this->assertSame(['calls_disabled_in_code'], $motivos($this->sid('33')));
    }

    /**
     * SEC8-c M-2: declarado solo saliente, pero el número sí admite entrantes y
     * apunta a un agente VIEJO: la iniciación lo deniega, abre el freno por
     * `seal_mismatch` y nunca queda atendida por ese agente.
     */
    public function test_declarado_solo_saliente_con_un_agente_viejo_en_el_numero_deniega_y_frena(): void
    {
        $this->activeVoiceCanary();
        $this->publishVoice();
        config(['voice.number.inbound_capable' => false]);

        $this->assertCleanResponse($this->rawPost(self::URI, $this->body(self::CANARY_PHONE, $this->sid('34'), 'conv_h8fx_0034', 'agent_h8fx_viejo_0001'), $this->initiationHeaders()), 'no');
        $fila = VoiceCall::query()->sole();
        $this->assertSame(['denied', false, 'number_outbound_only'], [$fila->status, $fila->caller_authorized, $fila->policy_reason]);
        $this->assertSame(['number_outbound_only', 'calls_disabled_in_code', 'seal_mismatch'],
            VoiceCallEvent::query()->where('call_id', $fila->id)->where('kind', 'initiation_received')->sole()->codes['reasons']);
        $this->assertSame(['open', 'seal_mismatch', 'seal:'.$this->sid('34')], [($freno = VoiceBrakeEntry::query()->sole())->kind, $freno->reason, $freno->open_key]);
        $this->assertTrue(VoiceBrake::isOpen());
    }

    /**
     * B8-g (decisión del lead): si ElevenLabs llamara al webhook de iniciación en
     * una SALIENTE (la doc lo describe solo para entrantes), su SID ya es el de
     * una fila saliente de Laravel: se responde como saliente, con la
     * autorización de esa fila, y no se crea ninguna entrante `denied`. Antes
     * respondía `caller_authorized=no` y `entrante` y el agente colgaba al canario.
     */
    public function test_el_webhook_de_iniciacion_con_el_sid_de_una_saliente_responde_como_saliente_sin_crear_filas(): void
    {
        $pub = $this->publishVoice();
        $viva = $this->voiceCall('outbound', 'dialing', ['provider_call_sid' => $this->sid('41'), 'remote' => '+573000000041']);
        $terminada = $this->voiceCall('outbound', 'completed', ['provider_call_sid' => $this->sid('42'), 'remote' => '+573000000042']);
        $filas = VoiceCall::query()->count();

        // La viva: autorizada, saliente, su call_ref y su etiqueta. Quien «llama» en una saliente es el número de voz.
        $r = $this->rawPost(self::URI, $this->body(self::VOICE_FROM, $this->sid('41'), 'conv_h8fx_0041', self::SEALED_AGENT, '+573000000041'), $this->initiationHeaders())->assertOk();
        $this->assertSame(['type', 'dynamic_variables'], array_keys($r->json()));
        $this->assertSame(['caller_authorized' => 'si', 'call_direction' => 'saliente', 'call_ref' => $viva->call_ref, 'version_label' => 'canario · publicación '.$pub['seq']],
            $r->json('dynamic_variables'));
        foreach (array_merge(self::SENTINELS, ['573000000041']) as $s) {
            $this->assertStringNotContainsString($s, (string) $r->getContent());
        }

        // La que ya terminó: no autorizada, y tampoco crea nada.
        $r = $this->rawPost(self::URI, $this->body(self::VOICE_FROM, $this->sid('42'), 'conv_h8fx_0042', self::SEALED_AGENT, '+573000000042'), $this->initiationHeaders())->assertOk();
        $this->assertSame(['no', 'saliente', $terminada->call_ref], [$r->json('dynamic_variables.caller_authorized'), $r->json('dynamic_variables.call_direction'), $r->json('dynamic_variables.call_ref')]);

        $this->assertSame($filas, VoiceCall::query()->count(), 'ninguna fila nueva: ni entrante denied ni otra');
        $this->assertSame(0, VoiceCallEvent::query()->where('kind', 'initiation_received')->count());
        $this->assertSame(['dialing', 'completed'], [VoiceCall::query()->findOrFail($viva->id)->status, VoiceCall::query()->findOrFail($terminada->id)->status]);
        $this->assertFalse(VoiceBrake::isOpen());

        // Un SID que no es de ninguna fila: lo de siempre (una entrante, denegada).
        $this->assertCleanResponse($this->rawPost(self::URI, $this->body(self::CANARY_PHONE, $this->sid('43'), 'conv_h8fx_0043'), $this->initiationHeaders()), 'no');
        $this->assertSame(['inbound', 'denied'], [($nueva = VoiceCall::query()->where('provider_call_sid', $this->sid('43'))->sole())->direction, $nueva->status]);
    }

    public function test_sin_publicacion_la_fila_lleva_el_sello_off_y_no_publication(): void
    {
        $this->assertCleanResponse($this->rawPost(self::URI, $this->body(self::CANARY_PHONE, $this->sid('20')), $this->initiationHeaders()), 'no');
        $fila = VoiceCall::query()->sole();
        $this->assertSame(['off', null, null], [$fila->stamp_state, $fila->part, $fila->provider_agent_id]);
        $this->assertContains('no_publication', VoiceCallEvent::query()->sole()->codes['reasons']);
    }
}
