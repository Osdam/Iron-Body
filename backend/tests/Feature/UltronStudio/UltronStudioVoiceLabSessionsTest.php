<?php

namespace Tests\Feature\UltronStudio;

use App\Http\Controllers\Crm\UltronStudio\UltronStudioVoiceLabController;
use App\Http\Middleware\LimitRequestBodySize;
use App\Models\Admin;
use App\Models\UltronStudio\StudioVoiceLabSession;
use App\Services\UltronStudio\StudioVoiceCompiler;
use App\Services\UltronStudio\StudioVoiceLabConfig;
use App\Services\UltronStudio\StudioVoiceLabSessions;
use App\Support\Access\AuthorizationMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * LAS SESIONES DEL LABORATORIO HABLADO por la API (contrato H8, D8-23, D8-7 y
 * protección 12): las rutas que LAB8 entrega a S8-b, los cuatro cerrojos y el
 * cupo, la forma de lo que llega (422), el simulador y ElevenLabs (con su
 * simulador sobre `Http::fake` y la red cerrada), la reserva frente al tope por
 * sesión, lo aceptado y el día de Bogotá, la URL firmada una sola vez, el
 * cierre por quien probó, por el informe del proveedor y por plazo.
 *
 * Las carreras de la reserva con dos sesiones de PostgreSQL están en
 * `UltronStudioVoiceLabBudgetLockTest`; el veredicto, en
 * `UltronStudioVoiceLabVerdictTest`; cero red con la configuración de
 * producción, en `UltronStudioVoiceLabNoNetworkTest`.
 */
class UltronStudioVoiceLabSessionsTest extends TestCase
{
    use PublicationFixtures;
    use RefreshDatabase;
    use StudioFixtures;
    use VoiceLabFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->voiceLabRoutes();
        $this->importBaseline();
    }

    // ── Las rutas ───────────────────────────────────────────────────────────

    public function test_las_rutas_son_las_que_lab8_entrega_a_s8_b(): void
    {
        foreach (self::VOICE_LAB_ROUTES as [$metodo, $ruta, $accion, $llave, $limitadores]) {
            $r = $this->voiceLabRoute($metodo, $ruta);
            $this->assertNotNull($r, "falta {$metodo} {$ruta}");
            $this->assertSame(UltronStudioVoiceLabController::class.'@'.$accion, $r->getActionName(), "{$metodo} {$ruta}");
            $pila = $r->gatherMiddleware();
            $this->assertContains('api', $pila, "{$metodo} {$ruta} va en el grupo api (ProtectAdminPaths y la puerta)");
            $this->assertContains(LimitRequestBodySize::class.':65536', $pila, "{$metodo} {$ruta}: el tope de cuerpo del Studio");
            foreach ($limitadores as $limitador) {
                $this->assertContains($limitador, $pila, "{$metodo} {$ruta}: {$limitador}");
            }
            if (str_contains($ruta, '{uuid}')) {
                $this->assertArrayHasKey('uuid', $r->wheres, "{$metodo} {$ruta}: el uuid se valida en la ruta");
            }
            // B8-b las clasifica una a una; mientras no lo haga, la puerta las deniega (y las pruebas la quitan).
            $clasificada = AuthorizationMap::resolve($r);
            $this->assertTrue($clasificada === null || $clasificada === $llave, "{$metodo} {$ruta} pide {$llave} en AuthorizationMap, no ".json_encode($clasificada));
        }
    }

    // ── Los cerrojos ────────────────────────────────────────────────────────

    public function test_apagado_el_estado_responde_200_y_las_demas_409_sin_escribir_ni_salir_a_la_red(): void
    {
        $this->voiceLabOff();
        $this->elevenLabsLikeProduction();
        $tina = $this->tester();

        $this->voiceLab('GET', 'status', $tina)->assertOk()->assertExactJson(['ok' => true, 'data' => [
            'enabled' => false,
            'message' => 'El laboratorio hablado está apagado en el servidor.',
            'can' => ['view' => true, 'run' => true, 'verdict' => false],
        ]]);

        $uuid = (string) Str::uuid();
        foreach ([
            ['GET', 'sessions', []],
            ['GET', "sessions/{$uuid}", []],
            ['POST', 'sessions', ['versionUuid' => $uuid, 'profileUuid' => $uuid, 'acceptedMaxCostMicros' => 1, 'clientRequestId' => $uuid]],
            ['POST', "sessions/{$uuid}/end", self::observations()],
        ] as [$metodo, $ruta, $cuerpo]) {
            $this->voiceLab($metodo, $ruta, $tina, $cuerpo)->assertStatus(409)->assertJsonPath('code', 'voice_lab_disabled');
        }
        $this->voiceLab('POST', "sessions/{$uuid}/verdict", $this->reviewer(), ['decision' => 'approved', 'specHash' => str_repeat('a', 64)])
            ->assertStatus(409)->assertJsonPath('code', 'voice_lab_disabled');

        $this->assertSame(0, StudioVoiceLabSession::query()->count());
        $this->assertSame(0, DB::table('ultron_studio_voice_agents')->count());
        Http::assertNothingSent();
    }

    public function test_sin_credencial_es_401_en_todas(): void
    {
        $this->voiceLabFake();
        $uuid = (string) Str::uuid();
        foreach (self::VOICE_LAB_ROUTES as [$metodo, $ruta]) {
            $this->json($metodo, '/api/admin/ultron-studio/'.str_replace('{uuid}', $uuid, $ruta))->assertStatus(401);
        }
    }

    public function test_sin_permiso_es_403_aunque_la_bandera_este_apagada(): void
    {
        $this->voiceLabOff();
        $uuid = (string) Str::uuid();

        // Sin nada del Studio, ni el estado.
        $recepcion = $this->person(Admin::ROLE_RECEPCION, 'Recepción');
        foreach (self::VOICE_LAB_ROUTES as [$metodo, $ruta]) {
            $this->voiceLab($metodo, str_replace(['voice-lab/', '{uuid}'], ['', $uuid], $ruta), $recepcion)->assertStatus(403);
        }

        // Con solo `ultron.view`: abrir y terminar piden su llave; el veredicto, la suya.
        $soloVer = $this->labPerson(['ultron.view'], 'Solo mira');
        $this->voiceLab('GET', 'status', $soloVer)->assertOk()->assertJsonPath('data.can', ['view' => true, 'run' => false, 'verdict' => false]);
        $this->voiceLab('POST', 'sessions', $soloVer)->assertStatus(403)->assertJsonPath('required_permission', 'ultron.voice_lab_run');
        $this->voiceLab('POST', "sessions/{$uuid}/end", $soloVer)->assertStatus(403)->assertJsonPath('required_permission', 'ultron.voice_lab_run');
        $this->voiceLab('POST', "sessions/{$uuid}/verdict", $soloVer)->assertStatus(403)->assertJsonPath('required_permission', 'ultron.approve');

        // La llave de abrir sin la de ver no abre nada (toda llave presupone ver).
        $ciego = $this->labPerson(['ultron.voice_lab_run'], 'Sin ver');
        $this->voiceLab('POST', 'sessions', $ciego)->assertStatus(403)->assertJsonPath('required_permission', 'ultron.view');
        $this->assertSame(0, StudioVoiceLabSession::query()->count());
    }

    public function test_encendido_sin_configurar_dice_los_motivos_y_no_sale_nada(): void
    {
        $tina = $this->tester();
        $ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana Autora');
        $version = $this->labVersion($ana);
        $perfil = $this->seedProfile($ana, null, 'submitted');

        // ElevenLabs como en producción, pero sin verificar, sin precio ni presupuesto, sin base ni referencias.
        $this->voiceLabElevenLabs([
            'voice.prices.elevenlabs' => null, 'voice.lab.daily_budget_usd' => '0', 'voice.webhook_base_url' => 'http://crm.ironbody.test',
            'voice.elevenlabs.tool_secret_ref' => null,
        ]);
        $motivos = ['credentials_unverified', 'missing_webhook_base', 'missing_secret_refs', 'missing_price', 'zero_budget'];
        $this->voiceLab('GET', 'status', $tina)->assertOk()
            ->assertJsonPath('data.mode', 'elevenlabs')
            ->assertJsonPath('data.configured', false)
            ->assertJsonPath('data.reasons', $motivos)
            ->assertJsonPath('data.credentials', 'ELEVENLABS_UNVERIFIED');
        $this->openSession($tina, $version->uuid, $perfil)->assertStatus(409)
            ->assertJsonPath('code', 'voice_lab_not_configured')
            ->assertJsonPath('reasons', $motivos)
            ->assertJsonPath('mode', 'elevenlabs');

        // Configurado salvo la verificación: el cuarto cerrojo, solo.
        $this->voiceLabElevenLabs();
        $this->openSession($tina, $version->uuid, $perfil)->assertStatus(409)->assertJsonPath('reasons', ['credentials_unverified']);

        // Un driver que no es ninguno no cae en silencio al simulador.
        $this->voiceLabFake(['voice.lab.driver' => 'openai']);
        $this->openSession($tina, $version->uuid, $perfil)->assertStatus(409)->assertJsonPath('reasons', ['invalid_driver'])->assertJsonPath('mode', null);

        // Sin cupo de sesiones, tampoco en el simulador.
        $this->voiceLabFake(['voice.lab.max_sessions_per_day' => 0]);
        $this->openSession($tina, $version->uuid, $perfil)->assertStatus(409)->assertJsonPath('reasons', ['zero_sessions']);

        $this->assertSame(0, StudioVoiceLabSession::query()->count());
        $this->assertSame(0, DB::table('ultron_studio_voice_agents')->count());
        Http::assertNothingSent();
    }

    public function test_el_estado_encendido_dice_el_presupuesto_el_cupo_los_limites_y_los_catalogos(): void
    {
        $this->voiceLabElevenLabs();
        $this->voiceVerified();
        $tina = $this->tester();

        $d = $this->voiceLab('GET', 'status', $tina)->assertOk()->json('data');
        $this->assertTrue($d['enabled']);
        $this->assertSame('elevenlabs', $d['mode']);
        $this->assertTrue($d['configured']);
        $this->assertSame([], $d['reasons']);
        $this->assertSame('ELEVENLABS_VERIFIED', $d['credentials']);
        $this->assertSame(StudioVoiceLabSessions::FIDELITY['elevenlabs'], $d['fidelity']);
        $this->assertSame([
            'timezone' => 'America/Bogota', 'day' => StudioVoiceLabSessions::budgetDay(), 'dailyCapMicros' => 2000000, 'spentMicros' => 0,
            'reservedMicros' => 0, 'remainingMicros' => 2000000, 'sessionCapMicros' => 500000, 'pricePerMinuteMicros' => 100000,
        ], $d['budget']);
        $this->assertSame(['today' => 0, 'maxPerDay' => 5], $d['sessions']);
        $this->assertSame(['maxSessionSeconds' => 300, 'urlTtlSeconds' => 900, 'verdictNoteMax' => 500, 'maxInterruptions' => 99], $d['limits']);
        // 300 s a 0,10 US$/min = 0,50 US$.
        $this->assertSame(['maxSeconds' => 300, 'reservedMicros' => 500000], $d['estimate']);
        $this->assertSame(array_keys(StudioVoiceLabSessions::OBJECTIVES), array_column($d['observations']['objectives'], 'code'));
        $this->assertSame(array_keys(StudioVoiceLabSessions::RESTRICTIONS), array_column($d['observations']['restrictions'], 'code'));
        $this->assertSame(array_keys(StudioVoiceLabSessions::ERRORS), array_column($d['observations']['errors'], 'code'));
        $this->assertSame(['view' => true, 'run' => true, 'verdict' => false], $d['can']);
        $this->assertStringNotContainsString(self::VOICE_KEY, json_encode($d));
        $this->assertStringNotContainsString('secref_', json_encode($d), 'las referencias de los secretos no se enseñan');
        Http::assertNothingSent();
    }

    // ── El simulador ────────────────────────────────────────────────────────

    public function test_en_el_simulador_abre_y_termina_sin_red_sin_coste_y_sin_veredicto(): void
    {
        $this->voiceLabFake();
        $tina = $this->tester();
        $ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana Autora');
        $version = $this->labVersion($ana);
        $perfil = $this->seedProfile($ana, null, 'submitted');

        $r = $this->openSession($tina, $version->uuid, $perfil, null, 0)->assertCreated();
        $d = $r->json('data');
        $this->assertSame('open', $d['status']);
        $this->assertSame('fake', $d['mode']);
        $this->assertSame(['issued' => true, 'expiresAt' => $d['connection']['expiresAt']], $d['connection'], 'en el simulador no hay URL firmada');
        $this->assertSame(['day' => StudioVoiceLabSessions::budgetDay(), 'reservedMicros' => 0, 'costMicros' => null], $d['budget']);
        $this->assertSame(180, $d['maxSeconds']);
        $this->assertSame('deployed', $d['agent']['status']);
        $this->assertMatchesRegularExpression('/^fake_lab_[0-9a-f]{24}$/', $d['agent']['providerAgentId']);
        $this->assertSame($version->uuid, $d['version']['uuid']);
        $this->assertSame($perfil, $d['profile']['uuid']);
        $this->assertTrue($d['mine']);
        $this->assertSame(['allowed' => true], $d['actions']['end']);
        $this->assertSame('forbidden', $d['actions']['verdict']['code'], 'quien prueba no tiene la llave del veredicto');

        $agente = DB::table('ultron_studio_voice_agents')->sole();
        $this->assertSame(['lab', 'fake', 'deployed'], [$agente->purpose, $agente->provider, $agente->status]);
        $this->assertSame($agente->projection_hash, $agente->observed_hash);
        $this->assertSame(0, (int) $agente->serves_canary + (int) $agente->serves_rest);

        $fin = $this->voiceLab('POST', "sessions/{$d['uuid']}/end", $tina, self::observations(['errors' => ['latencia']]))->assertOk()->json('data');
        $this->assertSame(['ended', 'tester_ended', 0], [$fin['status'], $fin['endReason'], $fin['budget']['costMicros']]);
        $this->assertSame(['aviso_ia', 'saludo', 'informo_horarios'], $fin['metrics']['objectives']);
        $this->assertSame(['latencia'], $fin['metrics']['errors']);
        $this->assertSame(1, $fin['metrics']['interruptions']);
        $this->assertSame(['respected' => true, 'broken' => [], 'guardViolations' => null], $fin['metrics']['restrictions']);
        $this->assertSame(['provider' => false, 'tester' => true], $fin['metrics']['reported']);

        // Nadie opinó de un agente que no habló.
        $rita = $this->reviewer();
        $this->voiceLab('POST', "sessions/{$d['uuid']}/verdict", $rita, ['decision' => 'approved', 'specHash' => $d['agent']['specHash']])
            ->assertStatus(409)->assertJsonPath('code', 'voice_lab_session_not_reviewable')->assertJsonPath('reason', 'fake_mode');
        Http::assertNothingSent();
    }

    // ── ElevenLabs (simulado) ───────────────────────────────────────────────

    public function test_con_elevenlabs_despliega_un_agente_de_laboratorio_lo_comprueba_y_entrega_la_url_una_vez(): void
    {
        $this->voiceLabElevenLabs();
        $this->voiceVerified();
        $this->fakeElevenLabs();
        $tina = $this->tester();
        $ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana Autora');
        $version = $this->labVersion($ana);
        $perfil = $this->seedProfile($ana, null, 'submitted');
        $peticion = (string) Str::uuid();

        $r = $this->openSession($tina, $version->uuid, $perfil, $peticion, $this->acceptedMax($tina))->assertCreated();
        $d = $r->json('data');
        $this->assertSame(['open', 'elevenlabs'], [$d['status'], $d['mode']]);
        $this->assertStringContainsString('conversation_signature='.self::URL_SIGNATURE, $d['connection']['signedUrl']);
        $this->assertStringStartsWith('wss://127.0.0.1:8096/', $d['connection']['signedUrl']);
        $this->assertTrue($d['connection']['issued']);
        $this->assertEqualsWithDelta(now()->addSeconds(900)->getTimestamp(), Carbon::parse($d['connection']['expiresAt'])->getTimestamp(), 2);
        // 180 s a 0,10 US$/min.
        $this->assertSame(300000, $d['budget']['reservedMicros']);
        $this->assertSame('deployed', $d['agent']['status']);
        $this->assertMatchesRegularExpression('/^agent_lab_\d+$/', $d['agent']['providerAgentId']);
        $this->assertStringNotContainsString(self::VOICE_KEY, $r->getContent(), 'la clave nunca sale del servidor');

        // Lo que salió: las 3 herramientas, el agente, su lectura (agente y herramientas) y la URL; todo con la clave y nada más.
        $metodos = [];
        foreach (Http::recorded() as [$peticionHttp]) {
            /** @var Request $peticionHttp */
            $this->assertSame([self::VOICE_KEY], $peticionHttp->header('xi-api-key'));
            $metodos[] = $peticionHttp->method().' '.parse_url($peticionHttp->url(), PHP_URL_PATH);
        }
        $this->assertSame([
            'POST /v1/convai/tools', 'POST /v1/convai/tools', 'POST /v1/convai/tools', 'POST /v1/convai/agents/create',
            'GET /v1/convai/agents/'.$d['agent']['providerAgentId'], 'GET /v1/convai/tools/tool_lab_1', 'GET /v1/convai/tools/tool_lab_2', 'GET /v1/convai/tools/tool_lab_3',
            'GET /v1/convai/conversation/get-signed-url',
        ], $metodos);
        // Un agente de LABORATORIO, sin número, con la duración del perfil y la autenticación encendida.
        $cuerpo = $this->sim['agents'][$d['agent']['providerAgentId']];
        $this->assertStringContainsString('· lab ·', $cuerpo['name']);
        $this->assertContains('ironbody-voice-lab', $cuerpo['tags']);
        $this->assertSame(180, $cuerpo['conversation_config']['conversation']['max_duration_seconds']);
        $this->assertTrue($cuerpo['platform_settings']['auth']['enable_auth']);
        $this->assertSame(['agent_concurrency_limit' => 1, 'daily_limit' => 1, 'bursting_enabled' => false], $cuerpo['platform_settings']['call_limits']);

        $agente = DB::table('ultron_studio_voice_agents')->sole();
        $this->assertSame(['lab', 'elevenlabs', 'deployed', $d['agent']['providerAgentId']], [$agente->purpose, $agente->provider, $agente->status, $agente->provider_agent_id]);
        $this->assertSame($agente->projection_hash, $agente->observed_hash);
        $this->assertSame($peticion, $agente->client_request_id, 'un agente por sesión: un reintento encuentra el suyo');
        $this->assertSame($d['agent']['specHash'], $agente->spec_hash);

        // La URL firmada no se guarda en ninguna parte.
        $sesion = DB::table('ultron_studio_voice_lab_sessions')->sole();
        $this->assertStringNotContainsString(self::URL_SIGNATURE, json_encode([$sesion, $agente]));
        $this->assertSame('open', $sesion->status);
        $this->assertNotNull($sesion->url_expires_at);

        // La misma petición otra vez: 200 `replayed`, la misma sesión, SIN la URL y sin salir a la red.
        $antes = count(Http::recorded());
        $otra = $this->openSession($tina, $version->uuid, $perfil, $peticion, 0)->assertOk()->assertJsonPath('replayed', true)->json('data');
        $this->assertSame($d['uuid'], $otra['uuid']);
        $this->assertArrayNotHasKey('signedUrl', $otra['connection']);
        $this->assertCount($antes, Http::recorded());
        $this->assertSame(1, StudioVoiceLabSession::query()->count());
    }

    public function test_la_huella_es_la_misma_que_compilara_la_publicacion(): void
    {
        $this->voiceLabElevenLabs();
        $this->voiceVerified();
        $this->fakeElevenLabs();
        $tina = $this->tester();
        $ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana Autora');
        $root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root');
        $version = $this->approvedVersion($ana, $root, 'Voz aprobada');
        $perfil = $this->seedProfile($ana, $root, 'approved');

        $huella = $this->openSession($tina, $version->uuid, $perfil, null, $this->acceptedMax($tina))->assertCreated()->json('data.agent.specHash');

        $fila = DB::table('ultron_studio_voice_profiles')->where('uuid', $perfil)->sole();
        $publicacion = StudioVoiceCompiler::compile([
            'uuid' => $version->uuid, 'status' => $version->status, 'contentHash' => $version->content_hash, 'content' => $version->content,
            'createdBy' => $ana->id, 'approvedBy' => $root->id,
        ], [
            'uuid' => $perfil, 'status' => 'approved', 'contentHash' => $fila->content_hash, 'content' => json_decode($fila->content, true),
            'createdBy' => $ana->id, 'approvedBy' => $root->id,
        ], 300, StudioVoiceCompiler::PURPOSE_PUBLICATION);
        $this->assertSame($publicacion['specHash'], $huella, 'lo que se prueba es lo que se publicará (P-19)');
    }

    public function test_si_el_proveedor_falla_la_sesion_queda_fallida_sin_gasto_y_es_502(): void
    {
        $this->voiceLabElevenLabs();
        $this->voiceVerified();
        $this->fakeElevenLabs();
        $tina = $this->tester();
        $ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana Autora');
        $version = $this->labVersion($ana);
        $perfil = $this->seedProfile($ana, null, 'submitted');
        $acepto = $this->acceptedMax($tina);

        foreach ([
            ['createStatus', 503, 'agent_create_ambiguous', 'failed', 'create_ambiguous'],
            ['createStatus', 422, 'agent_not_created', 'failed', 'create_rejected'],
            ['drift', true, 'agent_drift', 'rejected', null],
            ['urlStatus', 401, 'url_auth_failed', 'deployed', null],
            ['urlStatus', 500, 'url_error', 'deployed', null],
        ] as [$clave, $valor, $motivo, $estadoAgente, $fallo]) {
            $this->sim = ['tools' => [], 'agents' => [], 'n' => $this->sim['n'], 'drift' => false, 'urlStatus' => 200, 'createStatus' => 200, $clave => $valor];
            $r = $this->openSession($tina, $version->uuid, $perfil, null, $acepto)->assertStatus(502)
                ->assertJsonPath('code', 'voice_lab_provider_failed')
                ->assertJsonPath('endReason', $motivo)
                ->assertJsonPath('session.status', 'failed')
                ->assertJsonPath('session.budget.costMicros', 0);
            $this->assertArrayNotHasKey('signedUrl', $r->json('session.connection'), $motivo);
            $this->assertStringNotContainsString('detail', $r->getContent(), 'nada de la respuesta del proveedor viaja');
            $agente = DB::table('ultron_studio_voice_agents')->orderByDesc('id')->first();
            $this->assertSame([$estadoAgente, $fallo], [$agente->status, $agente->failure_code], $motivo);
        }

        // Nada quedó reservado: el día entero sigue libre.
        $this->assertSame(['spent' => 0, 'reserved' => 0], StudioVoiceLabSessions::committed(StudioVoiceLabSessions::budgetDay()));
    }

    // ── La reserva y el cupo ────────────────────────────────────────────────

    public function test_la_reserva_respeta_el_tope_por_sesion_lo_aceptado_y_el_dia(): void
    {
        $this->voiceLabElevenLabs(['voice.lab.session_budget_usd' => '0.20']);
        $this->voiceVerified();
        $this->fakeElevenLabs();
        $tina = $this->tester();
        $ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana Autora');
        $version = $this->labVersion($ana);
        $perfil = $this->seedProfile($ana, null, 'submitted');

        // 180 s a 0,10 US$/min = 0,30 US$ > 0,20 por sesión.
        $this->openSession($tina, $version->uuid, $perfil)->assertStatus(409)
            ->assertJsonPath('code', 'voice_lab_session_budget_exceeded')
            ->assertJsonPath('requiredMicros', 300000)->assertJsonPath('capMicros', 200000);

        // Con tope suficiente, pero aceptando menos de lo que cuesta: la estimación cambió.
        $this->voiceLabElevenLabs(['voice.lab.daily_budget_usd' => '0.40']);
        $this->openSession($tina, $version->uuid, $perfil, null, 299999)->assertStatus(409)
            ->assertJsonPath('code', 'voice_lab_estimate_changed')
            ->assertJsonPath('estimate', ['maxSeconds' => 180, 'reservedMicros' => 300000, 'pricePerMinuteMicros' => 100000]);

        // El día: 0,40 US$ caben en una sesión de 0,30 y no en dos.
        $this->openSession($tina, $version->uuid, $perfil, null, 300000)->assertCreated();
        $this->openSession($tina, $version->uuid, $perfil, null, 300000)->assertStatus(409)
            ->assertJsonPath('code', 'voice_lab_daily_budget_exceeded')
            ->assertJsonPath('requiredMicros', 300000)->assertJsonPath('remainingMicros', 100000);
        $this->assertSame(1, StudioVoiceLabSession::query()->count());
        $this->assertSame(1, DB::table('ultron_studio_voice_agents')->count(), 'la negativa no dejó ningún agente');
        $this->voiceLab('GET', 'status', $tina)->assertJsonPath('data.budget.reservedMicros', 300000)->assertJsonPath('data.budget.remainingMicros', 100000);

        // La frontera: lo que cabe JUSTO, cabe (0,60 = dos de 0,30); después, nada.
        $this->voiceLabElevenLabs(['voice.lab.daily_budget_usd' => '0.60']);
        $this->openSession($tina, $version->uuid, $perfil, null, 300000)->assertCreated();
        $this->openSession($tina, $version->uuid, $perfil, null, 300000)->assertStatus(409)->assertJsonPath('remainingMicros', 0);
    }

    public function test_la_reserva_redondea_hacia_arriba_y_el_tope_por_sesion_admite_lo_justo(): void
    {
        // 61 s a 0,10 US$/min = 101 666,67 micro-US$: se reserva 101 667, nunca menos.
        $cfg = StudioVoiceLabConfig::fromValues(['driver' => 'elevenlabs', 'price_micros_per_minute' => 100000]);
        $this->assertSame([101667, 300000, 500000], [$cfg->reservationFor(61), $cfg->reservationFor(180), $cfg->reservationFor(300)]);
        $this->assertSame(0, StudioVoiceLabConfig::fromValues(['driver' => 'fake'])->reservationFor(300), 'el simulador no cuesta');

        $this->voiceLabElevenLabs(['voice.lab.session_budget_usd' => '0.30']);
        $this->voiceVerified();
        $this->fakeElevenLabs();
        $tina = $this->tester();
        $ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana Autora');
        // 180 s cuestan exactamente el tope por sesión: cabe.
        $this->openSession($tina, $this->labVersion($ana)->uuid, $this->seedProfile($ana, null, 'submitted'), null, 300000)->assertCreated();
    }

    public function test_el_cupo_de_sesiones_es_por_persona_y_por_dia_de_bogota(): void
    {
        $this->voiceLabFake(['voice.lab.max_sessions_per_day' => 2]);
        $ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana Autora');
        $version = $this->labVersion($ana);
        $perfil = $this->seedProfile($ana, null, 'submitted');
        $tina = $this->tester();
        $otro = $this->tester('Otro Prueba');

        // 23:30 de Bogotá es ya el día siguiente en UTC: cuenta el de Bogotá.
        $this->travelTo(Carbon::parse('2026-11-07 23:30:00', 'America/Bogota'));
        $this->openSession($tina, $version->uuid, $perfil, null, 0)->assertCreated()->assertJsonPath('data.budget.day', '2026-11-07');
        $this->openSession($tina, $version->uuid, $perfil, null, 0)->assertCreated();
        $this->openSession($tina, $version->uuid, $perfil, null, 0)->assertStatus(409)
            ->assertJsonPath('code', 'voice_lab_daily_sessions_exceeded')
            ->assertJsonPath('sessionsToday', 2)->assertJsonPath('maxSessionsPerDay', 2);
        // Otra persona tiene su propio cupo.
        $this->openSession($otro, $version->uuid, $perfil, null, 0)->assertCreated();

        // A medianoche de Bogotá, el cupo vuelve.
        $this->travelTo(Carbon::parse('2026-11-08 00:00:30', 'America/Bogota'));
        $this->openSession($tina, $version->uuid, $perfil, null, 0)->assertCreated()->assertJsonPath('data.budget.day', '2026-11-08');
    }

    // ── Lo que el laboratorio no prueba y la forma ──────────────────────────

    public function test_la_duracion_el_compilador_y_los_404(): void
    {
        $this->voiceLabFake(['voice.lab.max_session_seconds' => 120]);
        $tina = $this->tester();
        $ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana Autora');
        $version = $this->labVersion($ana);

        // El agente corta a los 180 s del perfil: más que los 120 de la sesión.
        $this->openSession($tina, $version->uuid, $this->seedProfile($ana, null, 'submitted'), null, 0)->assertStatus(409)
            ->assertJsonPath('code', 'voice_lab_duration_exceeds_session')
            ->assertJsonPath('maxDurationSeconds', 180)->assertJsonPath('maxSessionSeconds', 120);

        // El compilador de S8 se niega con su motivo, sin repetir el texto.
        $this->voiceLabFake();
        $this->openSession($tina, $version->uuid, $this->seedProfile($ana, null, 'draft'), null, 0)->assertStatus(422)
            ->assertJsonPath('code', 'voice_compile_refused')->assertJsonPath('reason', 'profile_not_approved');
        $conDinero = $this->seedProfile($ana, null, 'submitted', ['holes' => ['closing' => 'Hoy el plan cuesta 99.000 pesos.']]);
        $r = $this->openSession($tina, $version->uuid, $conDinero, null, 0)->assertStatus(422)->assertJsonPath('reason', 'text_money');
        $this->assertStringNotContainsString('99.000', $r->getContent());

        // Los destinos de transferencia ya se montan en el agente de prueba (E10-10, S8-d):
        // `UltronStudioVoiceLabTransferTest`.

        $perfil = $this->seedProfile($ana, null, 'submitted');
        $this->openSession($tina, (string) Str::uuid(), $perfil, null, 0)->assertStatus(404)->assertJsonPath('code', 'version_not_found');
        $this->openSession($tina, $version->uuid, (string) Str::uuid(), null, 0)->assertStatus(404)->assertJsonPath('code', 'voice_profile_not_found');
        $this->voiceLab('GET', 'sessions/'.Str::uuid(), $tina)->assertStatus(404)->assertJsonPath('code', 'voice_lab_session_not_found');
        $this->voiceLab('GET', 'sessions/no-es-un-uuid', $tina)->assertStatus(404);
        $this->assertSame(0, StudioVoiceLabSession::query()->count());
        $this->assertSame(0, DB::table('ultron_studio_voice_agents')->count());
    }

    public function test_422_de_la_forma_de_lo_que_llega(): void
    {
        $this->voiceLabFake();
        $tina = $this->tester();
        $uuid = (string) Str::uuid();

        $this->voiceLab('POST', 'sessions', $tina, [])->assertStatus(422)->assertJsonValidationErrors(['versionUuid', 'profileUuid', 'acceptedMaxCostMicros', 'clientRequestId']);
        $this->voiceLab('POST', 'sessions', $tina, ['versionUuid' => 'x', 'profileUuid' => 3, 'acceptedMaxCostMicros' => '100', 'clientRequestId' => 'no'])
            ->assertStatus(422)->assertJsonValidationErrors(['versionUuid', 'profileUuid', 'acceptedMaxCostMicros', 'clientRequestId']);
        $this->voiceLab('POST', 'sessions', $tina, ['versionUuid' => $uuid, 'profileUuid' => $uuid, 'acceptedMaxCostMicros' => -1, 'clientRequestId' => $uuid])
            ->assertStatus(422)->assertJsonValidationErrors(['acceptedMaxCostMicros']);

        // En dos fases (`ValidatesListSizesFirst`): primero las listas y su tamaño; después, lo demás.
        $this->voiceLab('POST', "sessions/{$uuid}/end", $tina, [])->assertStatus(422)->assertJsonValidationErrors(['objectives', 'restrictionsBroken', 'errors']);
        $this->voiceLab('POST', "sessions/{$uuid}/end", $tina, ['objectives' => array_fill(0, 9, 'saludo'), 'restrictionsBroken' => [], 'errors' => []])
            ->assertStatus(422)->assertJsonValidationErrors(['objectives']);
        $this->voiceLab('POST', "sessions/{$uuid}/end", $tina, ['objectives' => [], 'restrictionsBroken' => [], 'errors' => []])
            ->assertStatus(422)->assertJsonValidationErrors(['interruptions']);
        $this->voiceLab('POST', "sessions/{$uuid}/end", $tina, self::observations(['objectives' => ['vender_mucho'], 'errors' => ['corte', 'corte'], 'interruptions' => 100]))
            ->assertStatus(422)->assertJsonValidationErrors(['objectives.0', 'errors.0', 'interruptions']);

        $rita = $this->reviewer();
        $this->voiceLab('POST', "sessions/{$uuid}/verdict", $rita, ['decision' => 'rejected', 'specHash' => str_repeat('a', 64)])
            ->assertStatus(422)->assertJsonValidationErrors(['note']);
        $this->voiceLab('POST', "sessions/{$uuid}/verdict", $rita, ['decision' => 'needs_changes', 'note' => "Con\u{200B}invisible", 'specHash' => 'corta'])
            ->assertStatus(422)->assertJsonValidationErrors(['decision', 'note', 'specHash']);
        $this->voiceLab('GET', 'sessions?versionUuid=x&specHash=zz&status=ok&scope=todo', $tina)
            ->assertStatus(422)->assertJsonValidationErrors(['versionUuid', 'specHash', 'status', 'scope']);
    }

    public function test_la_repeticion_y_la_llave_reutilizada(): void
    {
        $this->voiceLabFake();
        $tina = $this->tester();
        $otro = $this->tester('Otro Prueba');
        $ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana Autora');
        $version = $this->labVersion($ana);
        $perfil = $this->seedProfile($ana, null, 'submitted');
        $otroPerfil = $this->seedProfile($ana, null, 'submitted');
        $llave = (string) Str::uuid();

        $uuid = $this->openSession($tina, $version->uuid, $perfil, $llave, 0)->assertCreated()->json('data.uuid');
        $this->openSession($tina, strtoupper($version->uuid), $perfil, strtoupper($llave), 0)->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('data.uuid', $uuid);
        $this->openSession($otro, $version->uuid, $perfil, $llave, 0)->assertStatus(409)->assertJsonPath('code', 'client_request_reused');
        $this->openSession($tina, $version->uuid, $otroPerfil, $llave, 0)->assertStatus(409)->assertJsonPath('code', 'client_request_reused');
        $this->assertSame(1, StudioVoiceLabSession::query()->count());
    }

    // ── Terminar, el informe del proveedor y el plazo ───────────────────────

    public function test_terminar_solo_quien_la_abrio_una_vez_y_con_lo_que_observo(): void
    {
        $this->voiceLabFake();
        $tina = $this->tester();
        $otro = $this->tester('Otro Prueba');
        $ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana Autora');
        $uuid = $this->openSession($tina, $this->labVersion($ana)->uuid, $this->seedProfile($ana, null, 'submitted'), null, 0)->assertCreated()->json('data.uuid');

        $this->voiceLab('GET', "sessions/{$uuid}", $otro)->assertOk()->assertJsonPath('data.actions.end.code', 'voice_lab_not_session_owner');
        $this->voiceLab('POST', "sessions/{$uuid}/end", $otro, self::observations())->assertStatus(403)->assertJsonPath('code', 'voice_lab_not_session_owner');

        $d = $this->voiceLab('POST', "sessions/{$uuid}/end", $tina, self::observations([
            'objectives' => ['cierre', 'aviso_ia', 'aviso_ia'], 'restrictionsBroken' => ['descuento'], 'interruptions' => 0, 'errors' => [],
        ]))->assertStatus(422)->json();
        $this->assertArrayHasKey('objectives.1', $d['errors'], 'un código repetido no pasa');

        $fin = $this->voiceLab('POST', "sessions/{$uuid}/end", $tina, self::observations(['objectives' => ['cierre', 'aviso_ia'], 'restrictionsBroken' => ['descuento']]))
            ->assertOk()->json('data');
        // En el orden del catálogo, no en el de llegada.
        $this->assertSame(['aviso_ia', 'cierre'], $fin['metrics']['objectives']);
        $this->assertSame(['respected' => false, 'broken' => ['descuento'], 'guardViolations' => null], $fin['metrics']['restrictions']);
        $this->assertSame('voice_lab_already_reported', $fin['actions']['end']['code']);
        $this->voiceLab('POST', "sessions/{$uuid}/end", $tina, self::observations())->assertStatus(409)->assertJsonPath('code', 'voice_lab_already_reported');

        // Lo guardado son códigos y números.
        $guardado = DB::table('ultron_studio_voice_lab_sessions')->value('guard_codes');
        $this->assertSame([
            'format' => 'ultron-voice-lab-findings/1',
            'provider' => null,
            'tester' => ['objectives' => ['aviso_ia', 'cierre'], 'restrictionsBroken' => ['descuento'], 'interruptions' => 1, 'errors' => []],
        ], json_decode($guardado, true));
    }

    public function test_el_informe_del_proveedor_cierra_con_la_duracion_y_el_coste_reales_y_es_idempotente(): void
    {
        $this->voiceLabElevenLabs();
        $this->voiceVerified();
        $this->fakeElevenLabs();
        $tina = $this->tester();
        $ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana Autora');
        $version = $this->labVersion($ana);
        $perfil = $this->seedProfile($ana, null, 'submitted');
        $d = $this->openSession($tina, $version->uuid, $perfil, null, $this->acceptedMax($tina))->assertCreated()->json('data');
        $agente = $d['agent']['providerAgentId'];
        $sesiones = app(StudioVoiceLabSessions::class);

        // Lo que no es de una sesión del laboratorio, no la toca.
        $this->assertSame('not_a_lab_session', $sesiones->recordProviderReport(self::postCall('agent_publicado_9', 'conv_x', 30)));
        $this->assertSame('not_a_lab_session', $sesiones->recordProviderReport(self::postCall(null, 'conv_x', 30)));
        $this->assertSame('ignored', $sesiones->recordProviderReport(self::postCall($agente, 'conv_x', null, [], 'call_initiation_failure')));

        // 90 de 180 s: la mitad de la reserva.
        $this->assertSame('recorded', $sesiones->recordProviderReport(self::postCall($agente, 'conv_lab_1', 90), [['code' => 'money_figure', 'turn' => 3]]));
        $this->assertSame('duplicate', $sesiones->recordProviderReport(self::postCall($agente, 'conv_lab_1', 90)));
        $s = $this->voiceLab('GET', "sessions/{$d['uuid']}", $tina)->assertOk()->json('data');
        $this->assertSame(['ended', 'provider_report', 90, 150000], [$s['status'], $s['endReason'], $s['metrics']['durationSeconds'], $s['budget']['costMicros']]);
        $this->assertSame(['consultar_horarios', 'end_call'], $s['metrics']['toolCalls']);
        $this->assertSame(['respected' => false, 'broken' => [], 'guardViolations' => [['code' => 'money_figure', 'turn' => 3]]], $s['metrics']['restrictions']);
        $this->assertSame(['provider' => true, 'tester' => false], $s['metrics']['reported']);

        // Quien probó cuenta lo suyo después: no cambia lo liquidado.
        $fin = $this->voiceLab('POST', "sessions/{$d['uuid']}/end", $tina, self::observations())->assertOk()->json('data');
        $this->assertSame(['ended', 'provider_report', 150000], [$fin['status'], $fin['endReason'], $fin['budget']['costMicros']]);
        $this->assertSame(['provider' => true, 'tester' => true], $fin['metrics']['reported']);
        $this->assertSame(['spent' => 150000, 'reserved' => 0], StudioVoiceLabSessions::committed(StudioVoiceLabSessions::budgetDay()));

        // Una guarda sin forma es un fallo de quien llama, no un dato.
        $this->expectException(\InvalidArgumentException::class);
        $sesiones->recordProviderReport(self::postCall($agente, 'conv_lab_2', 10), [['code' => 'Texto Libre', 'turn' => 1]]);
    }

    public function test_el_informe_corrige_lo_que_cobro_el_cierre_de_quien_probo(): void
    {
        $this->voiceLabElevenLabs();
        $this->voiceVerified();
        $this->fakeElevenLabs();
        $tina = $this->tester();
        $ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana Autora');
        $d = $this->openSession($tina, $this->labVersion($ana)->uuid, $this->seedProfile($ana, null, 'submitted'), null, 500000)->assertCreated()->json('data');

        // Sin el informe, terminarla cobra la reserva entera.
        $this->voiceLab('POST', "sessions/{$d['uuid']}/end", $tina, self::observations())->assertOk()->assertJsonPath('data.budget.costMicros', 300000);
        // El informe llega después: 61 de 180 s → ceil(300000 × 61 / 180) = 101667.
        $this->assertSame('recorded', app(StudioVoiceLabSessions::class)->recordProviderReport(self::postCall($d['agent']['providerAgentId'], 'conv_lab_9', 61)));
        $this->voiceLab('GET', "sessions/{$d['uuid']}", $tina)->assertJsonPath('data.budget.costMicros', 101667)
            ->assertJsonPath('data.endReason', 'tester_ended')->assertJsonPath('data.metrics.durationSeconds', 61);
    }

    public function test_por_plazo_se_cierra_sola_cobra_la_reserva_y_la_que_no_se_abrio_no_cobra_nada(): void
    {
        $this->voiceLabElevenLabs();
        $this->voiceVerified();
        $this->fakeElevenLabs();
        $tina = $this->tester();
        $ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana Autora');
        $version = $this->labVersion($ana);
        $perfil = $this->seedProfile($ana, null, 'submitted');
        $this->travelTo(Carbon::parse('2026-11-07 10:00:00', 'America/Bogota'));
        $vieja = $this->openSession($tina, $version->uuid, $perfil, null, 500000)->assertCreated()->json('data.uuid');

        // Una que murió a mitad de abrirse: su agente, sin URL.
        $muerta = StudioVoiceLabSession::query()->forceCreate([
            'client_request_id' => (string) Str::uuid(), 'agent_id' => DB::table('ultron_studio_voice_agents')->value('id'),
            'status' => 'open', 'budget_day' => StudioVoiceLabSessions::budgetDay(), 'reserved_micros' => 300000, 'max_seconds' => 180,
            'created_by_admin_id' => $tina->id, 'created_by_name' => $tina->name, 'created_by_role' => $tina->role,
            'created_at' => now(), 'status_changed_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertSame(['spent' => 0, 'reserved' => 600000], StudioVoiceLabSessions::committed(StudioVoiceLabSessions::budgetDay()));
        // Sin conexión no hay nada que terminar ni que contar.
        $this->voiceLab('POST', "sessions/{$muerta->uuid}/end", $tina, self::observations())->assertStatus(409)->assertJsonPath('code', 'voice_lab_session_not_started');
        $this->voiceLab('GET', "sessions/{$muerta->uuid}", $tina)->assertJsonPath('data.actions.end.code', 'voice_lab_session_not_started')
            ->assertJsonPath('data.connection', ['issued' => false, 'expiresAt' => null]);

        // A los 16 minutos la URL caducó, pero la conversación pudo empezar en el
        // último momento: la sesión sigue viva. La que nunca tuvo URL ya no cuenta.
        $this->travel(16)->minutes();
        $this->voiceLab('GET', "sessions/{$vieja}", $tina)->assertOk()->assertJsonPath('data.expired', false);
        $this->assertSame(['spent' => 0, 'reserved' => 300000], StudioVoiceLabSessions::committed(StudioVoiceLabSessions::budgetDay()));

        // 15 min de URL + 5 de conversación + 2 de margen, y un poco más.
        $this->travel(7)->minutes();
        $this->voiceLab('GET', "sessions/{$vieja}", $tina)->assertOk()->assertJsonPath('data.status', 'open')->assertJsonPath('data.expired', true)
            ->assertJsonPath('data.actions.end', ['allowed' => true]);
        // Al leer no se escribe nada.
        $this->assertSame('open', StudioVoiceLabSession::query()->where('uuid', $vieja)->value('status'));
        $this->assertSame(['spent' => 0, 'reserved' => 300000], StudioVoiceLabSessions::committed(StudioVoiceLabSessions::budgetDay()));

        // La siguiente apertura las cierra.
        $this->openSession($tina, $version->uuid, $perfil, null, 500000)->assertCreated();
        $cerrada = StudioVoiceLabSession::query()->where('uuid', $vieja)->sole();
        $this->assertSame(['ended', 'deadline', 300000], [$cerrada->status, $cerrada->end_reason, $cerrada->cost_micros]);
        $muerta->refresh();
        $this->assertSame(['failed', 'abandoned', 0], [$muerta->status, $muerta->end_reason, $muerta->cost_micros]);
        $this->assertSame(['spent' => 300000, 'reserved' => 300000], StudioVoiceLabSessions::committed(StudioVoiceLabSessions::budgetDay()));
    }

    public function test_la_lista_filtra_y_pagina_sin_consultas_por_fila(): void
    {
        $this->voiceLabFake();
        $tina = $this->tester();
        $otro = $this->tester('Otro Prueba');
        $ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana Autora');
        $version = $this->labVersion($ana);
        $perfil = $this->seedProfile($ana, null, 'submitted');
        $otroPerfil = $this->seedProfile($ana, null, 'submitted', ['holes' => ['closing' => 'Hasta pronto, gracias.']]);
        $a = $this->openSession($tina, $version->uuid, $perfil, null, 0)->assertCreated()->json('data');
        $this->openSession($otro, $version->uuid, $otroPerfil, null, 0)->assertCreated();
        $this->openSession($tina, $version->uuid, $otroPerfil, null, 0)->assertCreated();

        $this->assertSame(3, $this->voiceLab('GET', 'sessions', $tina)->assertOk()->json('data.total'));
        $this->assertSame(2, $this->voiceLab('GET', 'sessions?scope=mine', $tina)->json('data.total'));
        $this->assertSame([$a['uuid']], array_column($this->voiceLab('GET', "sessions?profileUuid={$perfil}", $tina)->json('data.data'), 'uuid'));
        $this->assertSame([$a['uuid']], array_column($this->voiceLab('GET', 'sessions?specHash='.strtoupper($a['agent']['specHash']), $tina)->json('data.data'), 'uuid'));
        $this->assertSame(3, $this->voiceLab('GET', "sessions?versionUuid={$version->uuid}&status=open", $tina)->json('data.total'));
        $this->assertSame(0, $this->voiceLab('GET', 'sessions?status=ended', $tina)->json('data.total'));

        // La página entera en un número fijo de consultas, tenga las filas que tenga.
        $consultas = 0;
        DB::listen(function () use (&$consultas): void {
            $consultas++;
        });
        $this->voiceLab('GET', 'sessions?per_page=1', $tina)->assertOk();
        $una = $consultas;
        $consultas = 0;
        $this->voiceLab('GET', 'sessions?per_page=3', $tina)->assertOk();
        $this->assertSame($una, $consultas, 'una consulta por fila (N+1)');
    }
}
