<?php

namespace Tests\Feature\Telephony;

use App\Models\Admin;
use App\Models\Plan;
use App\Models\UltronStudio\StudioVoiceLabSession;
use App\Models\Voice\VoiceCall;
use App\Models\Voice\VoiceCallEvent;
use App\Models\Voice\VoiceExclusion;
use App\Services\Telephony\VoiceBrake;
use App\Services\Telephony\VoiceTools;
use App\Services\UltronStudio\StudioVoiceLabSessions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Telephony\Support\VoiceFixtures;
use Tests\Feature\UltronStudio\PublicationFixtures;
use Tests\Feature\UltronStudio\StudioFixtures;
use Tests\Feature\UltronStudio\VoiceLabFixtures;
use Tests\TestCase;

/**
 * LAS HERRAMIENTAS DE CONSULTA EN EL LABORATORIO HABLADO, POR LA RUTA
 * (contrato H8, Enmienda 18, E18-8; decisión del lead del 11-11-2026).
 *
 * El hallazgo: en la primera sesión real del laboratorio (producción, 11-11)
 * el agente no pudo decir planes ni precios. La conversación del laboratorio
 * no es una llamada y su `call_ref` era el literal `laboratorio`, que ni
 * siquiera pasa el esquema estricto de `POST /api/voice/tools/{tool}` (422
 * `invalid_schema`).
 *
 * Ahora el `call_ref` de la conversación es el uuid de la sesión y, con la
 * sesión ABIERTA y VIGENTE, el laboratorio ENCENDIDO y su agente de
 * ElevenLabs desplegado, la telefonía atiende las dos herramientas de SOLO
 * LECTURA con los mismos datos que en una llamada. Todo lo demás, la negativa
 * neutra de siempre (`call_not_found`), idéntica byte a byte: nadie con el
 * secreto distingue una sesión que no existe de una cerrada. Sin filas en
 * `voice_*`; el cupo (el de una llamada, 20) lo lleva la sesión.
 *
 * Se abre una sesión de verdad por la API del Studio (ElevenLabs simulado
 * sobre `Http::fake`, red cerrada) y se llama a la ruta pública con el
 * secreto de las herramientas y el `call_ref` que el servidor entregó al
 * navegador.
 *
 * EL OYENTE. La telefonía no nombra el Studio: pregunta con el evento con
 * nombre {@see LAB_TOOL_EVENT} y el laboratorio responde
 * (`StudioVoiceLabSessions::answerToolCall`). Lo registra el arranque de la
 * aplicación (`AppServiceProvider::oyenteDelLaboratorioHablado`), y las dos
 * guardas de lista cerrada de SEC8 se ampliaron a exactamente ese oyente
 * (decisión del lead con revisión de seguridad, E18-8). Estas pruebas dependen
 * de ESE registro, no de uno propio; sin oyente, todo sigue como antes
 * ({@see test_sin_oyente_el_laboratorio_recibe_la_negativa_de_siempre}).
 */
class VoiceLabToolsTest extends TestCase
{
    use PublicationFixtures, VoiceFixtures {
        // Las dos crean una persona con un rol de prueba; la del laboratorio (la de VoiceLabPostCallWiringTest).
        PublicationFixtures::personWith insteadof VoiceFixtures;
    }
    use RefreshDatabase;
    use StudioFixtures;
    use VoiceLabFixtures;

    /** El nombre del evento, del contrato (E18-8): fijado aquí para que un cambio de nombre sea un cambio visible. */
    private const LAB_TOOL_EVENT = 'voice.lab.tool_call';

    /** El secreto de las herramientas de {@see VoiceLabFixtures::elevenLabsLikeProduction()}. */
    private const LAB_TOOL_SECRET = 'tool-centinela-laboratorio';

    private const CONVERSATION = 'conv_lab_herramientas_0001';

    private const SITE = 'Sede Centro';

    private const HOURS = 'Lunes a viernes de 5:00 a 22:00';

    private ?Admin $tina = null;

    private string $version = '';

    private string $profile = '';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->voiceLabRoutes();
        $this->importBaseline();
        $this->voiceLabElevenLabs(['voice.facts.site' => self::SITE, 'voice.facts.hours' => self::HOURS]);
        $this->voiceVerified();
        $this->fakeElevenLabs();
        Plan::query()->create(['name' => 'Mensual', 'price' => 89000, 'duration_days' => 30, 'active' => true, 'sellable' => true, 'sort_order' => 1]);
        Plan::query()->create(['name' => 'Oculto', 'price' => 10000, 'duration_days' => 30, 'active' => true, 'sellable' => false]);
    }

    /**
     * El oyente lo registra el arranque de la aplicación (`AppServiceProvider::oyenteDelLaboratorioHablado`), una
     * sola vez y exactamente este: las demás pruebas de este fichero dependen de ESE registro, no de uno propio.
     */
    public function test_el_oyente_lo_registra_la_aplicacion_una_sola_vez(): void
    {
        $this->assertSame([[StudioVoiceLabSessions::class, 'answerToolCall']], Event::getRawListeners()[self::LAB_TOOL_EVENT] ?? null);
        // A-1 de la revisión de seguridad: `getRawListeners()` no ve los comodines (`listen(['*'], …)`), y uno que
        // contestara `counted` abriría el catálogo a cualquier cuerpo. Un solo oyente, comodines incluidos.
        $this->assertFalse(Event::hasWildcardListeners(self::LAB_TOOL_EVENT), 'ningún comodín escucha la consulta del laboratorio');
        $this->assertCount(1, Event::getListeners(self::LAB_TOOL_EVENT), 'un solo oyente, comodines incluidos');
    }

    /**
     * A-2 de la revisión de seguridad: el rechazo del esquema deja una línea con los NOMBRES de las claves y el
     * motivo, nunca un valor. El esquema sigue estricto: un `call_sid` vacío es 422 (lo que mande el proveedor en una
     * conversación web se verá en esta línea en el primer ensayo real, y entonces se decide con evidencia).
     */
    public function test_el_rechazo_del_esquema_deja_su_linea_con_los_nombres_de_las_claves_y_sin_valores(): void
    {
        $ref = $this->openLab()['connection']['dynamicVariables']['call_ref'];
        $lineas = [];
        Event::listen(MessageLogged::class, function (MessageLogged $m) use (&$lineas): void {
            if ($m->message === 'voice.tool.invalid_schema') {
                $lineas[] = $m->context;
            }
        });
        $casos = [
            'call_sid vacío' => ['{"conversation_id":"'.self::CONVERSATION.'","call_sid":"","call_ref":"'.$ref.'"}', ['keys' => ['call_ref', 'call_sid', 'conversation_id'], 'problem' => 'call_sid', 'shape' => 'empty']],
            'call_sid con un texto' => ['{"conversation_id":"'.self::CONVERSATION.'","call_sid":"None"}', ['keys' => ['call_sid', 'conversation_id'], 'problem' => 'call_sid', 'shape' => 'string:4']],
            'call_ref que no es un uuid' => ['{"conversation_id":"'.self::CONVERSATION.'","call_ref":"laboratorio"}', ['keys' => ['call_ref', 'conversation_id'], 'problem' => 'call_ref', 'shape' => 'string:11']],
            'call_ref vacío' => ['{"conversation_id":"'.self::CONVERSATION.'","call_ref":""}', ['keys' => ['call_ref', 'conversation_id'], 'problem' => 'call_ref', 'shape' => 'empty']],
            'una clave de más (y una con forma rara, que no se anota)' => ['{"conversation_id":"'.self::CONVERSATION.'","telefono":"3001234567","x y\n":1}', ['keys' => ['conversation_id', 'telefono'], 'problem' => 'unexpected_keys']],
            'sin ids de llamada' => ['{"call_ref":"'.$ref.'"}', ['keys' => ['call_ref'], 'problem' => 'no_call_ids']],
            'no es un objeto' => ['[1,2]', ['keys' => [], 'problem' => 'not_an_object']],
        ];
        foreach ($casos as $nombre => [$cuerpo, $esperado]) {
            $lineas = [];
            $this->rawPost('/api/voice/tools/consultar_planes', $cuerpo, $this->auth())->assertStatus(422)->assertExactJson(['ok' => false, 'verdict' => 'invalid_schema']);
            $this->assertSame([['tool' => 'consultar_planes'] + $esperado], $lineas, $nombre);
            $texto = (string) json_encode($lineas);
            foreach ([$ref, self::CONVERSATION, '3001234567', 'None', 'laboratorio'] as $valor) {
                $this->assertStringNotContainsString($valor, $texto, "{$nombre}: la línea lleva un valor del cuerpo.");
            }
        }
        // Un cuerpo válido no deja esa línea.
        $lineas = [];
        $this->tool('consultar_planes', $ref)->assertOk()->assertJsonPath('verdict', 'ok');
        $this->assertSame([], $lineas);
        $this->assertSame(1, $this->toolRequests($ref), 'los rechazos del esquema no gastan el cupo de la sesión');
    }

    public function test_el_evento_y_las_herramientas_son_los_del_contrato_y_el_call_ref_del_laboratorio_pasa_el_esquema(): void
    {
        $this->assertSame(self::LAB_TOOL_EVENT, VoiceTools::LAB_TOOL_EVENT);
        $this->assertSame(['consultar_planes', 'consultar_horarios'], VoiceTools::LAB_TOOLS, 'solo las dos de SOLO LECTURA');
        $ref = $this->openLab()['connection']['dynamicVariables']['call_ref'];
        $this->assertNotNull(VoiceTools::body((string) json_encode(['conversation_id' => self::CONVERSATION, 'call_ref' => $ref])),
            'el call_ref que el servidor entrega al navegador pasa el esquema estricto (antes, `laboratorio`: 422 invalid_schema)');
    }

    public function test_con_la_sesion_abierta_atiende_planes_y_horario_con_los_datos_de_una_llamada_y_sin_escribir_en_la_telefonia(): void
    {
        $d = $this->openLab();
        $ref = $d['connection']['dynamicVariables']['call_ref'];
        $this->assertSame($d['uuid'], $ref, 'E18-8: el call_ref de la conversación es el uuid de la sesión');
        $peticionesAlAbrir = count(Http::recorded());

        $planes = $this->tool('consultar_planes', $ref)->assertOk()->json();
        $this->assertSame(['ok' => true, 'verdict' => 'ok', 'planes' => [['nombre' => 'Mensual', 'condiciones' => '30 días de vigencia', 'precio' => '$ 89.000']]], $planes,
            'los planes VENDIBLES (Plan::sellable()) con su precio exacto; nada que no se venda');
        // ElevenLabs puede mandar `call_sid` en null en una conversación web: el mismo resultado.
        $this->tool('consultar_horarios', $ref, ['call_sid' => null])->assertOk()
            ->assertExactJson(['ok' => true, 'verdict' => 'ok', 'disponible' => true, 'sede' => self::SITE, 'horario' => self::HOURS]);

        $this->assertSame(0, VoiceCall::query()->count(), 'el laboratorio no crea llamadas');
        $this->assertSame(0, VoiceCallEvent::query()->count(), 'ni eventos de llamada');
        $this->assertSame(2, $this->toolRequests($d['uuid']), 'cada una contó en el cupo de la sesión');
        $this->assertSame($peticionesAlAbrir, count(Http::recorded()), 'las herramientas no salen a la red');

        // Lo que ve el CRM de la sesión no cambia de forma: el contador no viaja.
        $s = $this->voiceLab('GET', "sessions/{$d['uuid']}", $this->tina)->assertOk();
        $this->assertStringNotContainsString('toolRequests', (string) $s->getContent());
        $this->assertSame('open', $s->json('data.status'));
    }

    public function test_los_datos_son_los_mismos_que_recibe_una_llamada_real(): void
    {
        $d = $this->openLab();
        $this->voiceCall('outbound', 'active', ['provider_conversation_id' => 'conv_h8fx_0031', 'provider_call_sid' => 'CA000000000000000000000000a800001f']);
        foreach (['consultar_planes', 'consultar_horarios'] as $herramienta) {
            $deLaLlamada = $this->rawPost('/api/voice/tools/'.$herramienta, '{"conversation_id":"conv_h8fx_0031"}', $this->auth())->assertOk()->json();
            $delLaboratorio = $this->tool($herramienta, $d['uuid'])->assertOk()->json();
            $this->assertSame($deLaLlamada, $delLaboratorio, "{$herramienta}: los mismos datos en el laboratorio que en una llamada");
        }
    }

    public function test_terminada_fallida_caducada_inexistente_o_de_otro_uuid_la_negativa_es_identica_y_sin_datos(): void
    {
        // Tal como la serializa la respuesta JSON de Laravel (sus opciones por defecto).
        $neutra = (string) json_encode(['ok' => false, 'verdict' => 'call_not_found', 'message' => VoiceTools::NEUTRAL]);
        $respuestas = [];

        // Terminada por quien la abrió (antes respondía).
        $terminada = $this->openLab();
        $this->tool('consultar_planes', $terminada['uuid'])->assertOk()->assertJsonPath('verdict', 'ok');
        $this->voiceLab('POST', "sessions/{$terminada['uuid']}/end", $this->tina, self::observations())->assertOk();
        $respuestas['terminada'] = $this->tool('consultar_planes', $terminada['uuid']);

        // Fallida (antes respondía).
        $fallida = $this->openLab();
        $this->tool('consultar_horarios', $fallida['uuid'])->assertOk()->assertJsonPath('verdict', 'ok');
        $this->assertTrue(StudioVoiceLabSession::advance($this->sessionId($fallida['uuid']), ['open'], [
            'status' => 'failed', 'end_reason' => 'open_error', 'cost_micros' => 0, 'ended_at' => now(), 'status_changed_at' => now(), 'updated_at' => now(),
        ]));
        $respuestas['fallida'] = $this->tool('consultar_horarios', $fallida['uuid']);

        // Inexistente, el uuid de su AGENTE (no el de la sesión) y otro uuid cualquiera.
        $abierta = $this->openLab();
        $this->tool('consultar_planes', $abierta['uuid'])->assertOk()->assertJsonPath('verdict', 'ok');
        $respuestas['inexistente'] = $this->tool('consultar_planes', '00000000-0000-4000-8000-000000000999');
        $respuestas['uuid del agente'] = $this->tool('consultar_planes', $abierta['agent']['uuid']);
        $respuestas['otro uuid'] = $this->tool('consultar_horarios', (string) Str::uuid());

        // Caducada: vigente hasta que caduca la URL más la conversación más larga; un segundo después, no.
        $limite = Carbon::parse($abierta['connection']['expiresAt'])->addSeconds($abierta['maxSeconds']);
        $this->travelTo($limite->copy()->subSecond());
        $this->tool('consultar_planes', $abierta['uuid'])->assertOk()->assertJsonPath('verdict', 'ok');
        $this->travelTo($limite->copy()->addSecond());
        $respuestas['caducada'] = $this->tool('consultar_planes', $abierta['uuid']);

        foreach ($respuestas as $caso => $r) {
            $r->assertOk();
            $this->assertSame($neutra, (string) $r->getContent(), "{$caso}: la misma negativa neutra, byte a byte, sin datos");
        }
        $this->assertSame(0, VoiceCall::query()->count());
        $this->assertSame(0, VoiceCallEvent::query()->count());
    }

    public function test_las_herramientas_que_no_son_de_lectura_se_niegan_con_la_sesion_abierta_y_no_escriben_nada(): void
    {
        $d = $this->openLab();
        $this->tool('consultar_planes', $d['uuid'])->assertOk()->assertJsonPath('verdict', 'ok');
        $neutra = $this->tool('consultar_planes', (string) Str::uuid())->getContent();

        foreach (['solicitar_humano', 'no_volver_a_llamar'] as $herramienta) {
            $r = $this->tool($herramienta, $d['uuid'])->assertOk();
            $this->assertSame($neutra, $r->getContent(), "{$herramienta}: la negativa de siempre (no hay llamada, ni destino, ni número que excluir)");
        }
        $this->assertSame(0, VoiceExclusion::query()->count(), 'ninguna exclusión');
        $this->assertSame(1, $this->toolRequests($d['uuid']), 'las negadas no cuentan');
    }

    public function test_con_el_freno_echado_la_negativa_es_neutra_no_cuenta_y_lo_ajeno_responde_como_hoy(): void
    {
        $d = $this->openLab();
        $this->tool('consultar_planes', $d['uuid'])->assertOk()->assertJsonPath('verdict', 'ok');
        VoiceBrake::open('guard_violation', 'guard:prueba-laboratorio', []);

        $this->tool('consultar_planes', $d['uuid'])->assertOk()
            ->assertExactJson(['ok' => false, 'verdict' => 'voice_braked', 'message' => VoiceTools::NEUTRAL]);
        $this->tool('consultar_horarios', (string) Str::uuid())->assertOk()
            ->assertExactJson(['ok' => false, 'verdict' => 'call_not_found', 'message' => VoiceTools::NEUTRAL]);
        $this->assertSame(1, $this->toolRequests($d['uuid']), 'con el freno no se gasta el cupo');
        $this->assertTrue(VoiceBrake::isOpen());
    }

    public function test_el_cupo_de_la_sesion_es_el_de_una_llamada(): void
    {
        $d = $this->openLab();
        for ($i = 1; $i <= VoiceCall::MAX_TOOL_CALLS; $i++) {
            $this->tool($i % 2 === 0 ? 'consultar_planes' : 'consultar_horarios', $d['uuid'])->assertOk()->assertJsonPath('verdict', 'ok');
        }
        $this->tool('consultar_planes', $d['uuid'])->assertOk()
            ->assertExactJson(['ok' => false, 'verdict' => 'tool_quota_exceeded', 'message' => VoiceTools::NEUTRAL]);
        $this->assertSame(20, VoiceCall::MAX_TOOL_CALLS);
        $this->assertSame(VoiceCall::MAX_TOOL_CALLS, $this->toolRequests($d['uuid']), 'lo que pasa del cupo no cuenta');

        // El cupo es de la sesión: otra sesión abierta tiene el suyo.
        $otra = $this->openLab();
        $this->tool('consultar_planes', $otra['uuid'])->assertOk()->assertJsonPath('verdict', 'ok');
    }

    public function test_con_el_laboratorio_apagado_o_una_sesion_del_simulador_la_negativa_de_siempre(): void
    {
        $d = $this->openLab();
        $this->tool('consultar_planes', $d['uuid'])->assertOk()->assertJsonPath('verdict', 'ok');
        $this->voiceLabOff();
        $this->tool('consultar_planes', $d['uuid'])->assertOk()->assertJsonPath('verdict', 'call_not_found');

        // El simulador no habla con ElevenLabs: nadie legítimo llama a sus herramientas.
        $this->voiceLabFake();
        $simulada = $this->openSession($this->tina, $this->version, $this->profile, null, 0)->assertCreated()->json('data');
        $this->assertSame('fake', $simulada['mode']);
        $this->tool('consultar_planes', $simulada['uuid'])->assertOk()->assertJsonPath('verdict', 'call_not_found');
        $this->assertSame(1, $this->toolRequests($d['uuid']));
    }

    public function test_una_conversacion_que_la_telefonia_conoce_o_con_llamada_de_twilio_no_es_del_laboratorio(): void
    {
        $d = $this->openLab();
        $this->tool('consultar_planes', $d['uuid'])->assertOk()->assertJsonPath('verdict', 'ok');
        $llamada = $this->voiceCall('outbound', 'active', ['provider_conversation_id' => 'conv_h8fx_0031', 'provider_call_sid' => 'CA000000000000000000000000a800001f']);

        // La conversación de una llamada real con el uuid de la sesión: no se vuelve del laboratorio y la llamada no se toca.
        $this->rawPost('/api/voice/tools/consultar_planes', (string) json_encode(['conversation_id' => 'conv_h8fx_0031', 'call_ref' => $d['uuid']]), $this->auth())
            ->assertOk()->assertExactJson(['ok' => false, 'verdict' => 'call_not_found', 'message' => VoiceTools::NEUTRAL]);
        // Una llamada de Twilio (con `call_sid`) tampoco: el laboratorio es una conversación web.
        $this->tool('consultar_planes', $d['uuid'], ['call_sid' => 'CA'.str_repeat('7', 32)])
            ->assertOk()->assertExactJson(['ok' => false, 'verdict' => 'call_not_found', 'message' => VoiceTools::NEUTRAL]);

        $this->assertSame(0, VoiceCall::query()->findOrFail($llamada->id)->tool_calls, 'la llamada real no gastó cupo');
        $this->assertSame(0, VoiceCallEvent::query()->count(), 'call_not_found sin llamada no deja evento, como siempre');
        $this->assertSame(1, $this->toolRequests($d['uuid']));
    }

    public function test_el_contador_sobrevive_al_cierre_por_quien_la_abrio_y_al_informe_del_proveedor(): void
    {
        $d = $this->openLab();
        $this->tool('consultar_planes', $d['uuid'])->assertOk()->assertJsonPath('verdict', 'ok');
        $this->tool('consultar_horarios', $d['uuid'])->assertOk()->assertJsonPath('verdict', 'ok');

        $this->voiceLab('POST', "sessions/{$d['uuid']}/end", $this->tina, self::observations())->assertOk();
        $this->assertSame(2, $this->toolRequests($d['uuid']), 'terminar reescribe guard_codes sin perder el contador');
        $this->assertSame('recorded', app(StudioVoiceLabSessions::class)->recordProviderReport(self::postCall($d['agent']['providerAgentId'], 'conv_lab_herramientas_fin', 60)));
        $guardado = json_decode((string) DB::table('ultron_studio_voice_lab_sessions')->where('uuid', $d['uuid'])->value('guard_codes'), true);
        $this->assertSame(2, $guardado['toolRequests'], 'el informe del proveedor tampoco lo pierde');
        $this->assertNotNull($guardado['tester']);
        $this->assertNotNull($guardado['provider']);
        $this->tool('consultar_planes', $d['uuid'])->assertOk()->assertJsonPath('verdict', 'call_not_found');
    }

    public function test_sin_oyente_el_laboratorio_recibe_la_negativa_de_siempre(): void
    {
        // Lo que pasa mientras el oyente no se registre en el arranque: falla cerrado, como hoy.
        $d = $this->openLab();
        Event::forget(self::LAB_TOOL_EVENT);
        $this->tool('consultar_planes', $d['uuid'])->assertOk()
            ->assertExactJson(['ok' => false, 'verdict' => 'call_not_found', 'message' => VoiceTools::NEUTRAL]);
        $this->assertNull($this->toolRequests($d['uuid']));
    }

    // ── Ayudas ──────────────────────────────────────────────────────────────

    /** Una sesión ABIERTA de ElevenLabs por la API del Studio (la misma versión y el mismo perfil en cada una). @return array<string, mixed> */
    private function openLab(): array
    {
        if ($this->tina === null) {
            $this->tina = $this->tester();
            $ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana Autora');
            $this->version = $this->labVersion($ana)->uuid;
            $this->profile = $this->seedProfile($ana, null, 'submitted');
        }
        $d = $this->openSession($this->tina, $this->version, $this->profile, null, $this->acceptedMax($this->tina))->assertCreated()->json('data');
        $this->assertSame(['open', 'elevenlabs'], [$d['status'], $d['mode']]);

        return $d;
    }

    /** @param  array<string, mixed>  $extra */
    private function tool(string $tool, string $callRef, array $extra = []): TestResponse
    {
        return $this->rawPost('/api/voice/tools/'.$tool, (string) json_encode(['conversation_id' => self::CONVERSATION, 'call_ref' => $callRef] + $extra), $this->auth());
    }

    /** @return array<string, string> */
    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.self::LAB_TOOL_SECRET];
    }

    private function sessionId(string $uuid): int
    {
        return (int) StudioVoiceLabSession::query()->where('uuid', $uuid)->value('id');
    }

    private function toolRequests(string $uuid): ?int
    {
        $g = json_decode((string) DB::table('ultron_studio_voice_lab_sessions')->where('uuid', $uuid)->value('guard_codes'), true);

        return is_array($g) && isset($g['toolRequests']) ? (int) $g['toolRequests'] : null;
    }
}
