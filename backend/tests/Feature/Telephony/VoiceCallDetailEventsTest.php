<?php

namespace Tests\Feature\Telephony;

use App\Jobs\Voice\PlaceOutboundVoiceCall;
use App\Models\Admin;
use App\Models\Voice\VoiceCall;
use App\Models\Voice\VoiceCallEvent;
use App\Services\Telephony\VoiceCallsQuery;
use App\Services\Telephony\VoiceEvents;
use App\Services\Telephony\VoiceHangup;
use App\Services\Telephony\VoiceReconciler;
use App\Services\Voice\Dto\OutboundCallResult;
use App\Services\Voice\NullVoiceAgentDeployer;
use App\Services\Voice\VoiceAdapters;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use ReflectionMethod;
use RuntimeException;
use Tests\Feature\Telephony\Support\VoiceFixtures;
use Tests\TestCase;

/**
 * SEC8-b (3), O-1 (funcional): el DETALLE de una llamada
 * (`GET /api/admin/voice/calls/{uuid}`) daba los `codes` de cada evento tal como
 * se guardan (un objeto con códigos y números de turno) y el CRM los lee como
 * LISTA DE CÓDIGOS (`studio-voice-parse.ts`, `leerEvento` → `codigos()`: cada
 * uno con `RE_CODIGO` y como mucho 64): descartaba casi todos los eventos y en
 * un incidente no se veían los turnos de la guarda ni la conciliación.
 *
 * Ahora el backend los da como una lista plana de códigos cortos y pintables,
 * sin cambiar lo guardado. Las respuestas de esta prueba salen de los caminos
 * REALES (la post-llamada firmada, la conciliación con Twilio simulado, el
 * corte, el job, la iniciación): es la captura del detalle con eventos, no
 * `events: []`.
 */
class VoiceCallDetailEventsTest extends TestCase
{
    use RefreshDatabase;
    use VoiceFixtures;

    /** Copia de `RE_CODIGO` y del tope de `codigos()` del CRM (`studio-voice-parse.ts:141` y `:209`). */
    private const CRM_CODE = '/^[a-z][a-z0-9_]{0,47}$/';

    private const CRM_MAX_CODES = 64;

    private const ACCOUNT = 'AC8f8f8f8f8f8f8f8f8f8f8f8f8f8f8f8f';

    private const SID = 'CA000000000000000000000000a8f10001';

    private const CONVERSATION = 'conv_h8fx_o1_0001';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake();
        $this->voiceWorld([
            'voice.twilio.account_sid' => self::ACCOUNT,
            'voice.twilio.api_key_sid' => 'SK'.str_repeat('0', 31).'1',
            'voice.twilio.api_key_secret' => 'secreto-de-la-key-de-prueba',
            'voice.twilio.base_url' => 'http://127.0.0.1:8095',
            'voice.limits.daily_budget_usd' => '1000',
        ]);
        $this->travelTo(Carbon::createFromTimestamp(1793804400));   // world.now del fixture
    }

    private function detail(VoiceCall $llamada): TestResponse
    {
        return $this->getJson("/api/admin/voice/calls/{$llamada->uuid}", $this->adminWithPermissions(['telephony.view']))->assertOk();
    }

    /** Lo que exige el lector del CRM a cada evento: `codes`, una lista de códigos cortos. */
    private function assertCrmReadable(TestResponse $r): void
    {
        $eventos = $r->json('call.events');
        $this->assertIsArray($eventos);
        $this->assertNotSame([], $eventos, 'el detalle trae sus eventos');
        foreach ($eventos as $i => $e) {
            $this->assertIsArray($e['codes'], "evento {$i} ({$e['kind']}): codes es una lista");
            $this->assertTrue(array_is_list($e['codes']), "evento {$i} ({$e['kind']}): una lista, no un objeto");
            $this->assertLessThanOrEqual(VoiceCallsQuery::MAX_EVENT_CODES, count($e['codes']));
            $this->assertLessThanOrEqual(self::CRM_MAX_CODES, count($e['codes']));
            foreach ($e['codes'] as $c) {
                $this->assertIsString($c);
                $this->assertMatchesRegularExpression(self::CRM_CODE, $c, "evento {$i} ({$e['kind']}): «{$c}» no lo lee el CRM");
            }
        }
    }

    /** @return array<string, list<string>> los `codes` del detalle por tipo de evento (el último de cada tipo) */
    private static function byKind(TestResponse $r): array
    {
        $out = [];
        foreach ($r->json('call.events') as $e) {
            $out[$e['kind']] = $e['codes'];
        }

        return $out;
    }

    private function postCall(string $cuerpo): void
    {
        $this->rawPost('/api/webhooks/voice/elevenlabs/post-call', $cuerpo, ['ElevenLabs-Signature' => $this->elevenSignature($cuerpo)])->assertOk();
    }

    /** Twilio: la lista de la subcuenta enseña `$sid` terminada con su precio, y colgar responde 200. */
    private function twilio(string $sid): void
    {
        $pagina = json_decode((string) file_get_contents(base_path('tests/fixtures/voice/twilio-webhook-bodies.json')), true)['callsPage'][0]['page'];
        $pagina['calls'] = [array_merge($pagina['calls'][0], ['sid' => $sid, 'status' => 'completed', 'price' => '-0.03770', 'duration' => '40'])];
        $pagina['end'] = 1;
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([
            '127.0.0.1:8095/2010-04-01/Accounts/'.self::ACCOUNT.'/Calls/CA*' => Http::response(['sid' => $sid, 'status' => 'completed']),
            '127.0.0.1:8095/2010-04-01/Accounts/'.self::ACCOUNT.'/Calls.json*' => Http::response($pagina),
        ]);
    }

    public function test_la_guarda_con_varias_violaciones_y_la_conciliacion_se_leen_como_listas_de_codigos(): void
    {
        $llamada = $this->voiceCall('outbound', 'active', ['provider_conversation_id' => self::CONVERSATION, 'provider_call_sid' => self::SID]);
        $this->postCall((string) json_encode(['type' => 'post_call_transcription', 'event_timestamp' => 1793804340, 'data' => [
            'agent_id' => self::SEALED_AGENT, 'conversation_id' => self::CONVERSATION,
            'transcript' => [
                ['role' => 'agent', 'message' => '¡Hola! ¿En qué te ayudo?'],
                ['role' => 'user', 'message' => 'Soy Ana Prueba, ¿cuánto vale?'],
                ['role' => 'agent', 'message' => 'Te queda en $ 79.000 y la primera semana es gratis.'],
                ['role' => 'user', 'message' => '¿Me haces un descuento?'],
                ['role' => 'agent', 'message' => 'No tengo ningún descuento para ofrecerte.'],
            ],
            'metadata' => ['call_duration_secs' => 40],
        ]], JSON_UNESCAPED_UNICODE));
        $this->twilio(self::SID);
        $resumen = app(VoiceReconciler::class)->run(VoiceAdapters::carrier(), new NullVoiceAgentDeployer, now()->subHours(48));
        $this->assertSame(1, $resumen['known']);

        $r = $this->detail($llamada);
        $this->assertCrmReadable($r);
        $this->assertSame(['post_call_transcription', 'guard_verdict', 'reconciled', 'cost_recorded'], array_column($r->json('call.events'), 'kind'));
        $codigos = self::byKind($r);

        // La guarda: cada violación con su turno, en orden; lo guardado no cambia.
        $guardadas = VoiceCallEvent::query()->where('kind', 'guard_verdict')->sole()->codes['violations'];
        $this->assertGreaterThanOrEqual(3, count($guardadas));
        $this->assertSame([['turn' => 0, 'code' => 'voice_notice_missing'], ['turn' => 2, 'code' => 'voice_money_not_from_tool']], array_slice($guardadas, 0, 2));
        $this->assertSame(array_map(static fn (array $v): string => $v['code'].'_turno_'.$v['turn'], $guardadas), $codigos['guard_verdict']);
        $this->assertSame(['voice_notice_missing_turno_0', 'voice_money_not_from_tool_turno_2'], array_slice($codigos['guard_verdict'], 0, 2));
        $this->assertContains(4, array_column($guardadas, 'turn'), 'la del descuento, en el turno 4');

        // La conciliación y el precio.
        $this->assertSame(['carrier_status_completed', 'before_completed', 'after_completed'], $codigos['reconciled']);
        $this->assertSame(['micros_37700', 'unit_usd'], $codigos['cost_recorded']);
        // `hasAudio` llega null si el cuerpo no lo dice (`?bool`): lo que no se sabe no se pinta.
        $this->assertNull(VoiceCallEvent::query()->where('kind', 'post_call_transcription')->sole()->codes['hasAudio']);
        $this->assertSame(['duration_seconds_40', 'agent_turns_3', 'tool_calls_0'], $codigos['post_call_transcription']);

        // Ni texto de la transcripción, ni teléfonos, ni los ids del proveedor.
        foreach (['Ana Prueba', '79.000', 'gratis', 'descuento para', self::SID, self::CONVERSATION, '+573000000031', '573000000031'] as $s) {
            $this->assertStringNotContainsString($s, (string) $r->getContent());
        }
    }

    public function test_muchas_violaciones_se_cortan_en_50_con_y_n_mas(): void
    {
        $llamada = $this->voiceCall('outbound', 'active', ['provider_conversation_id' => self::CONVERSATION, 'provider_call_sid' => self::SID]);
        $turnos = [['role' => 'agent', 'message' => 'Hola, te habla el asistente virtual de Iron Body Workout, un sistema de inteligencia artificial. Si no quieres recibir más llamadas, dímelo.']];
        for ($i = 0; $i < 200; $i++) {
            $turnos[] = ['role' => 'user', 'message' => '¿Me haces un descuento?'];
            $turnos[] = ['role' => 'agent', 'message' => 'No tengo ningún descuento para ofrecerte.'];
        }
        $this->postCall((string) json_encode(['type' => 'post_call_transcription', 'event_timestamp' => 1793804340, 'data' => [
            'agent_id' => self::SEALED_AGENT, 'conversation_id' => self::CONVERSATION, 'transcript' => $turnos, 'metadata' => ['call_duration_secs' => 290],
        ]], JSON_UNESCAPED_UNICODE));

        $r = $this->detail($llamada);
        $this->assertCrmReadable($r);
        $guarda = self::byKind($r)['guard_verdict'];
        $guardadas = VoiceCallEvent::query()->where('kind', 'guard_verdict')->sole()->codes;
        $this->assertSame([50, 150], [count($guardadas['violations']), $guardadas['truncated']], 'E10-4: 50 guardadas y 150 que no');
        $this->assertCount(VoiceCallsQuery::MAX_EVENT_CODES, $guarda);
        $this->assertSame(array_map(static fn (array $v): string => $v['code'].'_turno_'.$v['turn'], array_slice($guardadas['violations'], 0, 49)), array_slice($guarda, 0, 49));
        $this->assertSame('y_151_mas', $guarda[49], 'la que no cabe y las 150 que no se guardaron');
    }

    public function test_el_corte_el_job_y_la_iniciacion_sin_ids_ni_clases_y_sin_codigos_es_una_lista_vacia(): void
    {
        // Una saliente cortada mientras el job esperaba y que el proveedor inició igual (E10-5), y un job que muere.
        $this->twilio('CA000000000000000000000000a8f10002');
        $cortada = $this->voiceCall('outbound', 'requested', ['remote' => '+573000000057']);
        VoiceHangup::hangup(VoiceCall::query()->findOrFail($cortada->id), VoiceAdapters::carrier(), $this->person(Admin::ROLE_SUPER_ADMIN, 'Pablo Corta'));
        (new ReflectionMethod(PlaceOutboundVoiceCall::class, 'record'))->invoke(new PlaceOutboundVoiceCall((int) $cortada->id), $cortada,
            new OutboundCallResult('ambiguous', 'server_error', 'conv_h8fx_o1_0002', 'CA000000000000000000000000a8f10002', 502));
        $muerta = $this->voiceCall('outbound', 'requested', ['remote' => '+573000000058']);
        (new PlaceOutboundVoiceCall((int) $muerta->id))->failed(new RuntimeException('se agotó el plazo'));
        VoiceEvents::record('tool_call', (int) $muerta->id, VoiceCallEvent::PROVIDER_ELEVENLABS, null, ['tool' => 'consultar_planes', 'outcome' => 'ok']);

        $c = self::byKind($r = $this->detail($cortada));
        $this->assertCrmReadable($r);
        $this->assertSame(['reason_server_error', 'http_502', 'after_cancel'], $c['outbound_ambiguous'], 'sin la conversación ni el SID');
        $this->assertSame(['carrier_status_completed', 'http_200', 'after_cancel'], $c['hangup_requested']);
        foreach (['conv_h8fx_o1_0002', 'a8f10002', 'CA0000'] as $s) {
            $this->assertStringNotContainsString($s, (string) $r->getContent());
        }

        $c = self::byKind($r = $this->detail($muerta));
        $this->assertCrmReadable($r);
        $this->assertSame([], $c['outbound_ambiguous'], 'la clase del error no sale');
        $this->assertSame([], $c['tool_call'], 'sin códigos: []');
        $this->assertStringNotContainsString('RuntimeException', (string) $r->getContent());

        // Una entrante de alguien que no es canario: los motivos de la iniciación, uno por código.
        $cuerpo = (string) json_encode(['caller_id' => self::STRANGER_PHONE, 'agent_id' => self::SEALED_AGENT, 'called_number' => self::VOICE_FROM,
            'call_sid' => 'CA000000000000000000000000a8f10003', 'conversation_id' => 'conv_h8fx_o1_0003']);
        $this->rawPost('/api/webhooks/voice/elevenlabs/initiation', $cuerpo, $this->initiationHeaders())->assertOk();
        $entrante = VoiceCall::query()->where('provider_call_sid', 'CA000000000000000000000000a8f10003')->sole();
        $c = self::byKind($r = $this->detail($entrante));
        $this->assertCrmReadable($r);
        $guardados = VoiceCallEvent::query()->where('call_id', $entrante->id)->where('kind', 'initiation_received')->sole()->codes;
        $this->assertSame(array_map(static fn (string $m): string => 'reason_'.$m, $guardados['reasons']), array_slice($c['initiation_received'], 0, count($guardados['reasons'])));
        $this->assertContains('reason_no_publication', $c['initiation_received']);
        $this->assertContains('publication_'.$guardados['publication'], $c['initiation_received']);
        $this->assertStringNotContainsString(self::STRANGER_PHONE, (string) $r->getContent());
    }

    public function test_lo_que_no_tiene_forma_de_codigo_se_normaliza_o_no_sale(): void
    {
        $this->assertSame([], VoiceCallsQuery::eventCodes(null));
        $this->assertSame([], VoiceCallsQuery::eventCodes([]));
        $this->assertSame([], VoiceCallsQuery::eventCodes('reason_x'));

        $codigos = VoiceCallsQuery::eventCodes([
            'reason' => 'No-Answer', 'carrierStatus' => 'in-progress', 'unit' => 'USD', 'http' => 503, 'seq' => null, 'hasAudio' => true, 'afterCancel' => false,
            'conversationId' => 'conv_secreta_0001', 'callSid' => 'CA000000000000000000000000a8f1ffff', 'error_class' => 'Illuminate\Queue\MaxAttemptsExceededException',
            'extra' => 'texto libre con +57 300 000 0000', 'reasons' => ['outbound_disabled', 7, 'Fuera De Horario'],
            'violations' => [['turn' => 7, 'code' => str_repeat('codigo_largo_', 6)], ['turn' => 'tres', 'code' => 'x'], ['turn' => 9, 'code' => '9empieza_por_cifra']],
        ]);
        $this->assertSame([
            'reason_no_answer', 'carrier_status_in_progress', 'unit_usd', 'http_503', 'has_audio', 'after_cancel_no',
            'reason_outbound_disabled', 'reason_fuera_de_horario',
            'codigo_largo_codigo_largo_codigo_largo_c_turno_7', 'violacion_turno_9',
        ], $codigos);
        foreach ($codigos as $c) {
            $this->assertMatchesRegularExpression(self::CRM_CODE, $c);
        }
        $this->assertSame(48, strlen($codigos[8]), 'un código largo se recorta para que quepa con su turno');

        // Más de 50 sin `truncated`: 49 y `y_<n>_mas`.
        $muchas = VoiceCallsQuery::eventCodes(['violations' => array_map(static fn (int $t): array => ['turn' => $t, 'code' => 'voice_notice_missing'], range(0, 59))]);
        $this->assertSame([50, 'voice_notice_missing_turno_48', 'y_11_mas'], [count($muchas), $muchas[48], $muchas[49]]);
        // Justo 50: todas, sin marca.
        $this->assertCount(50, $cincuenta = VoiceCallsQuery::eventCodes(['violations' => array_map(static fn (int $t): array => ['turn' => $t, 'code' => 'x_y'], range(1, 50))]));
        $this->assertSame('x_y_turno_50', $cincuenta[49]);
        // Pocas y `truncated`: todas y la marca.
        $this->assertSame(['x_y_turno_1', 'y_3_mas'], VoiceCallsQuery::eventCodes(['violations' => [['turn' => 1, 'code' => 'x_y']], 'truncated' => 3]));
    }
}
