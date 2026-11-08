<?php

namespace Tests\Feature\UltronStudio;

use App\Models\Admin;
use App\Models\UltronStudio\StudioAuditEvent;
use App\Models\UltronStudio\StudioVersion;
use App\Models\UltronStudio\StudioVoiceAgent;
use App\Models\UltronStudio\StudioVoiceProfile;
use App\Models\UltronStudio\StudioVoicePublication;
use App\Services\Telephony\VoiceBrake;
use App\Services\Telephony\VoicePublishedConfig;
use App\Services\UltronStudio\StudioVoiceEvidence;
use App\Services\UltronStudio\StudioVoiceProfiles;
use App\Services\UltronStudio\StudioVoicePublications;
use App\Services\UltronStudio\StudioVoicePublishing;
use App\Services\Voice\Dto\VoiceDeployResult;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PUBLICAR, PROMOVER, REVERTIR Y APAGAR LA VOZ por la API (contrato H8, D8-7,
 * D8-9, D8-19, D8-20, E2-4, E3-2; S8-b). El despliegue va por un desplegador
 * FALSO que apunta cada llamada (sin red), salvo la última prueba, que usa el
 * adaptador real de ElevenLabs contra `Http::fake`. La cadena que se escribe la
 * verifican el Studio y el LECTOR de la telefonía (B8): las dos tienen que
 * aceptarla.
 */
class UltronStudioVoicePublishingTest extends TestCase
{
    use PublicationFixtures;
    use RefreshDatabase;
    use StudioFixtures;
    use StudioVoiceFixtures;

    private Admin $ana;

    private Admin $root;

    private Admin $pablo;

    private Admin $paula;

    protected function setUp(): void
    {
        parent::setUp();
        $this->voiceSetUp();
        $this->ana = $this->personWith([...self::VOICE_MANAGER, 'ultron.approve', 'ultron.edit_draft'], 'Ana Autora');
        $this->root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root Revisa');
        $this->pablo = $this->personWith(self::VOICE_PUBLISHER, 'Pablo Publica');
        $this->paula = $this->personWith(self::VOICE_PUBLISHER, 'Paula Promueve');
        $this->bindFake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** @return array{0:StudioVersion,1:StudioVoiceProfile} una versión y un perfil aprobados por dos personas, con su veredicto del laboratorio */
    private function ready(string $etiqueta = 'Voz', ?array $contenido = null): array
    {
        $v = $this->approvedVersion($this->ana, $this->root, "Versión {$etiqueta}");
        $p = $this->approvedProfile($this->ana, $this->root, $contenido, "Perfil {$etiqueta}");
        $this->labVerdictFor($v, $p, $this->ana, $this->root);

        return [$v, $p];
    }

    public function test_publica_en_canario_un_agente_nuevo_verificado_con_el_numero_y_la_entrada_firmada(): void
    {
        [$v, $p] = $this->ready('una', self::profileContentWithTransfer());
        $r = $this->publishVoice($this->pablo, $v, $p, null, 'Primera voz')->assertCreated();

        // El despliegue, en su orden: crear → describir → asignar el número al agente nuevo.
        $agente = StudioVoiceAgent::query()->where('purpose', 'publication')->sole();
        $this->assertSame([['create', $agente->payload_hash], ['describe', 'agent_h8s_0001'], ['assign', self::NUMBER_ID, 'agent_h8s_0001']], $this->fake->calls);
        $this->assertSame(['deployed', 'agent_h8s_0001', true, false], [$agente->status, $agente->provider_agent_id, $agente->serves_canary, $agente->serves_rest]);
        $this->assertSame($agente->projection_hash, $agente->observed_hash);
        $this->assertSame(self::specHashOf($v, $p), $agente->spec_hash);

        // La entrada: la primera de la cadena, del canario, firmada y verificada.
        $fila = StudioVoicePublication::query()->sole();
        $this->assertSame([1, 'publish', 'canary', StudioVoicePublications::GENESIS_HASH, 'Primera voz'], [$fila->seq, $fila->action, $fila->scope, $fila->prev_hash, $fila->reason]);
        $mensaje = json_decode($fila->effective_json, true);
        $this->assertSame(['canary', 'agent_h8s_0001', $agente->spec_hash, $agente->payload_hash, $v->uuid, $p->uuid, $this->pablo->id],
            [$mensaje['scope'], $mensaje['provider_agent_id'], $mensaje['spec_hash'], $mensaje['agent_config_hash'], $mensaje['version_uuid'], $mensaje['profile_uuid'], $mensaje['actor_id']]);
        $this->assertSame(StudioVoicePublications::VERIFY_OK, StudioVoicePublications::verifyRow(StudioVoicePublications::current('canary')));
        $this->assertSame(['mode' => 'required', 'verdict' => 'approved'], array_intersect_key($fila->lab_gate, ['mode' => 1, 'verdict' => 1]));

        // El lector de la telefonía (B8) la acepta y saca el sello del agente.
        $leida = VoicePublishedConfig::read();
        $this->assertSame('ok', $leida['canary']->status, 'el lector de la telefonía no acepta la entrada del Studio: '.$leida['canary']->reason);
        $this->assertSame(['agent_h8s_0001', $agente->spec_hash, $agente->payload_hash, $v->content_hash, $p->content_hash],
            [$leida['canary']->providerAgentId, $leida['canary']->specHash, $leida['canary']->payloadHash, $leida['canary']->studioContentHash, $leida['canary']->voiceProfileHash]);
        $this->assertSame('none', $leida['rest']->status);

        // La respuesta: la entrada, el agente (id enmascarado) y el número.
        $this->assertSame(['assigned', 'assigned'], [$r->json('data.number.action'), $r->json('data.number.outcome')]);
        $this->assertSame('…0001', $r->json('data.agent.providerAgentRef'));
        $this->assertStringNotContainsString('agent_h8s_0001', $r->getContent());
        $this->assertStringNotContainsString('6010000099', $r->getContent());
        $this->assertSame(1, StudioAuditEvent::query()->where('event', 'voice_publication.published')->count());
    }

    /**
     * E5-1 (Enmienda 5): los destinos aprobados son los COMPILADOS en el
     * agente. Su fila guarda el alias y la huella `voice-phone` de cada uno,
     * nunca el número, y `[]` sin destinos; la telefonía los lee de ahí.
     */
    public function test_el_agente_guarda_sus_destinos_compilados_como_alias_y_huella_sin_el_numero(): void
    {
        $numeros = ['Recepción' => '+576010000097', 'Ventas' => '+576010000098'];
        $contenido = self::profileContentWithTransfer();
        $contenido['transfer']['destinations'] = array_map(static fn (string $alias, string $numero): array => ['alias' => $alias, 'phone' => $numero], array_keys($numeros), $numeros);
        [$v, $p] = $this->ready('dos destinos', $contenido);
        $this->publishVoice($this->pablo, $v, $p)->assertCreated();

        // Exactamente sus dos destinos, en el orden de la especificación: el alias y la huella del número.
        $esperados = array_map(static fn (string $alias, string $numero): array => ['alias' => $alias, 'hash' => StudioVoiceProfiles::phoneHash($numero)], array_keys($numeros), $numeros);
        $fila = DB::table('ultron_studio_voice_agents')->where('purpose', 'publication')->sole();
        $this->assertSame($esperados, json_decode((string) $fila->transfer_destination_hashes, true));
        $this->assertSame($esperados, StudioVoiceAgent::query()->where('purpose', 'publication')->sole()->transfer_destination_hashes);
        $compilados = $this->voice('POST', 'compile-preview', $this->ana, ['versionUuid' => $v->uuid, 'profileUuid' => $p->uuid])->assertOk()->json('data.spec.transfer.destinations');
        $this->assertSame($esperados, array_map(static fn (array $d): array => ['alias' => $d['alias'], 'hash' => $d['ref']], $compilados), 'son los de la especificación compilada');

        // Ni rastro del número en toda la fila: ni el E.164, ni sus dígitos, ni el número nacional, ni sus 7 últimos.
        $cruda = (string) json_encode((array) $fila, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        foreach ($numeros as $alias => $numero) {
            $digitos = ltrim($numero, '+');
            foreach ([$numero, $digitos, substr($digitos, 2), substr($digitos, -7)] as $rastro) {
                $this->assertStringNotContainsString($rastro, $cruda, "{$alias}: {$rastro}");
            }
        }
        // En la columna, alias sin dígitos y huellas de 64 hex: nada más.
        foreach (json_decode((string) $fila->transfer_destination_hashes, true) as $d) {
            $this->assertSame(['alias', 'hash'], array_keys($d));
            $this->assertDoesNotMatchRegularExpression('/\d/', $d['alias']);
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $d['hash']);
        }

        // La telefonía los lee de la fila del agente, sin descifrar nada (E5-1).
        $parte = VoicePublishedConfig::read()['canary'];
        $this->assertSame('ok', $parte->status, (string) $parte->reason);
        $this->assertSame($esperados, $parte->transferDestinations);

        // Sin destinos, una lista vacía: null queda para los agentes anteriores a la columna.
        [$v2, $p2] = $this->ready('sin destinos', self::profileContent(['maxDurationSeconds' => 240]));
        $this->publishVoice($this->pablo, $v2, $p2)->assertCreated();
        $this->assertSame('[]', DB::table('ultron_studio_voice_agents')->where('purpose', 'publication')->where('spec_hash', self::specHashOf($v2, $p2))->sole()->transfer_destination_hashes);
    }

    public function test_las_puertas_antes_de_desplegar_y_ninguna_sale_a_la_red(): void
    {
        [$v, $p] = $this->ready();

        // La bandera (E2-4).
        $this->deployConfig(['ultron_studio.voice.publishing_enabled' => false]);
        $this->publishVoice($this->pablo, $v, $p)->assertStatus(409)->assertJsonPath('code', 'voice_publishing_disabled');
        $this->deployConfig();

        // El permiso.
        $this->publishVoice($this->personWith(['ultron.view'], 'Solo Ve'), $v, $p)->assertStatus(403)->assertJsonPath('required_permission', 'ultron.voice_publish');

        // Cuatro ojos: nadie publica lo que creó (versión o perfil).
        $autora = $this->personWith([...self::VOICE_PUBLISHER, ...self::VOICE_MANAGER, 'ultron.edit_draft', 'ultron.approve'], 'Autora Publica');
        $suya = $this->approvedVersion($autora, $this->root, 'Suya');
        $this->publishVoice($autora, $suya, $p)->assertStatus(403)->assertJsonPath('code', 'four_eyes_required');
        $suPerfil = $this->approvedProfile($autora, $this->root, null, 'Su perfil');
        $this->publishVoice($autora, $v, $suPerfil)->assertStatus(403)->assertJsonPath('code', 'four_eyes_required');
        $this->assertSame(2, StudioAuditEvent::query()->where('event', 'voice_publication.four_eyes_denied')->count());

        // La ventana (la del H5, P-25): fuera de 07:00–19:00 de Bogotá, no.
        config(['ultron_studio.publishing.window' => '07:00-19:00']);
        Carbon::setTestNow(Carbon::parse('2026-11-09T02:30:00Z'));   // 21:30 en Bogotá
        $this->freshSessions();
        $this->publishVoice($this->pablo, $v, $p)->assertStatus(409)->assertJsonPath('code', 'outside_publication_window');
        Carbon::setTestNow();
        $this->freshSessions();
        config(['ultron_studio.publishing.window' => '']);

        // La compilación: un perfil sin aprobar no se publica (422 con su motivo).
        $borrador = StudioVoiceProfile::query()->where('uuid', $this->createProfile($this->ana, null, null, 'Borrador')->json('data.uuid'))->sole();
        $this->publishVoice($this->pablo, $v, $borrador)->assertStatus(422)->assertJsonPath('reason', 'profile_not_approved');

        // La puerta del laboratorio (P-19): sin veredicto sobre esta huella, no; uno sobre el
        // SIMULADOR tampoco (no prueba la voz); uno que rechaza, tampoco; `waived`, sí (y queda escrito).
        $sinLab = $this->approvedProfile($this->ana, $this->root, self::profileContent(['maxDurationSeconds' => 240]), 'Sin laboratorio');
        $this->publishVoice($this->pablo, $v, $sinLab)->assertStatus(409)->assertJsonPath('code', 'lab_verdict_required');
        $this->labVerdictFor($v, $sinLab, $this->ana, $this->root, 'approved', 'fake');
        $this->publishVoice($this->pablo, $v, $sinLab)->assertStatus(409)->assertJsonPath('code', 'lab_verdict_required')->assertJsonPath('latest', null);
        $rechazado = $this->approvedProfile($this->ana, $this->root, self::profileContent(['maxDurationSeconds' => 200]), 'Rechazado en el laboratorio');
        $this->labVerdictFor($v, $rechazado, $this->ana, $this->root, 'rejected');
        $this->publishVoice($this->pablo, $v, $rechazado)->assertStatus(409)->assertJsonPath('code', 'lab_verdict_required')->assertJsonPath('latest', 'rejected');

        // Las piezas del despliegue: solo nombres de lo que falta.
        $this->deployConfig(['voice.elevenlabs.initiation_secret_ref' => null, 'voice.inbound.daily_cap' => 0, 'voice.twilio.daily_call_cap' => 0]);
        $this->publishVoice($this->pablo, $v, $p)->assertStatus(409)->assertJsonPath('code', 'voice_not_deployable')
            ->assertJsonPath('missing', ['initiation_secret_ref', 'daily_call_cap']);
        $this->deployConfig();

        // Ninguna de esas puertas desplegó nada.
        $this->assertSame([], $this->fake->calls);
        $this->assertSame(0, StudioVoiceAgent::query()->where('purpose', 'publication')->count());
        $this->assertSame(0, StudioVoicePublication::query()->count());

        config(['ultron_studio.voice.lab_gate' => 'waived']);
        $r = $this->publishVoice($this->pablo, $v, $sinLab)->assertCreated();
        $this->assertSame(['mode' => 'waived'], $r->json('data.entries.0.labGate'));
    }

    /**
     * S8-c (E5-6, Q-3 de FE8): lo que se vio es lo que se publica, como la
     * `previewHash` del H5. Publicar lleva la `specHash` de `compile-preview`.
     */
    public function test_publicar_lleva_la_huella_que_se_vio_y_si_cambio_es_spec_changed_sin_desplegar_nada(): void
    {
        [$v, $p] = $this->ready('vista');
        $p2 = $this->approvedProfile($this->ana, $this->root, self::profileContent(['maxDurationSeconds' => 240]), 'Perfil que se cambió');
        $this->labVerdictFor($v, $p2, $this->ana, $this->root);

        // Lo que enseña la previsualización es la huella que compara publicar.
        $vista = $this->voice('POST', 'compile-preview', $this->ana, ['versionUuid' => $v->uuid, 'profileUuid' => $p->uuid])->assertOk()->json('data.specHash');
        $otra = $this->voice('POST', 'compile-preview', $this->ana, ['versionUuid' => $v->uuid, 'profileUuid' => $p2->uuid])->assertOk()->json('data.specHash');
        $this->assertSame([self::specHashOf($v, $p), self::specHashOf($v, $p2)], [$vista, $otra]);
        $this->assertNotSame($vista, $otra);

        // 401 sin sesión; 422 sin la huella o si no es un sha256 en minúsculas; 403 sin la llave.
        $cuerpo = static fn (array $over = []): array => $over + ['clientRequestId' => (string) Str::uuid(), 'versionUuid' => $v->uuid, 'profileUuid' => $p->uuid, 'expectedSpecHash' => $vista];
        $this->json('POST', '/api/admin/ultron-studio/voice/publish', $cuerpo())->assertStatus(401);
        $sinHuella = $cuerpo();
        unset($sinHuella['expectedSpecHash']);
        $this->voice('POST', 'publish', $this->pablo, $sinHuella)->assertStatus(422)->assertJsonValidationErrors(['expectedSpecHash']);
        foreach ([strtoupper($vista), substr($vista, 1), $vista.'0', 'g'.substr($vista, 1), ['x'], 12345] as $mala) {
            $this->voice('POST', 'publish', $this->pablo, $cuerpo(['expectedSpecHash' => $mala]))->assertStatus(422)->assertJsonValidationErrors(['expectedSpecHash']);
        }
        $this->voice('POST', 'publish', $this->personWith(['ultron.view'], 'Solo Ve'), $cuerpo())->assertStatus(403)->assertJsonPath('required_permission', 'ultron.voice_publish');

        // Se vio el perfil 1 y se pide el 2: 409 con las dos huellas.
        $r = $this->publishVoice($this->pablo, $v, $p2, null, null, $vista)->assertStatus(409);
        $this->assertSame(['spec_changed', $vista, $otra], [$r->json('code'), $r->json('expected'), $r->json('actual')]);

        // El orden: la bandera va antes (no compila); el laboratorio y las piezas del despliegue,
        // después (el veredicto es de otra huella; lo que falta da igual si no es lo que se vio).
        $sinLab = $this->approvedProfile($this->ana, $this->root, self::profileContent(['maxDurationSeconds' => 200]), 'Sin laboratorio');
        $this->publishVoice($this->pablo, $v, $sinLab, null, null, $vista)->assertStatus(409)->assertJsonPath('code', 'spec_changed');
        $this->publishVoice($this->pablo, $v, $sinLab)->assertStatus(409)->assertJsonPath('code', 'lab_verdict_required');
        $this->deployConfig(['voice.elevenlabs.initiation_secret_ref' => null]);
        $this->publishVoice($this->pablo, $v, $p2, null, null, $vista)->assertStatus(409)->assertJsonPath('code', 'spec_changed');
        $this->deployConfig(['ultron_studio.voice.publishing_enabled' => false]);
        $this->publishVoice($this->pablo, $v, $p2, null, null, $vista)->assertStatus(409)->assertJsonPath('code', 'voice_publishing_disabled');
        $this->deployConfig();

        // Nada salió al proveedor ni se escribió.
        $this->assertSame([], $this->fake->calls);
        $this->assertSame(0, StudioVoiceAgent::query()->where('purpose', 'publication')->count());
        $this->assertSame(0, StudioVoicePublication::query()->count());
        $this->assertSame(0, StudioAuditEvent::query()->where('event', 'voice_publication.published')->count());

        // Con la huella de lo que se publica: 201, y el agente desplegado es esa compilación.
        $crid = (string) Str::uuid();
        $this->publishVoice($this->pablo, $v, $p2, $crid, null, $otra)->assertCreated();
        $this->assertSame($otra, StudioVoiceAgent::query()->where('serves_canary', true)->value('spec_hash'));

        // El reintento: el mismo cuerpo da lo de la primera vez; otra huella con la misma llave es otra petición.
        $this->publishVoice($this->pablo, $v, $p2, $crid, null, $otra)->assertOk()->assertJsonPath('replayed', true);
        $this->publishVoice($this->pablo, $v, $p2, $crid, null, $vista)->assertStatus(409)->assertJsonPath('code', 'client_request_reused');
        $this->assertCount(1, $this->fake->callsOf('create'));
    }

    /**
     * S8-c (E5-6): promover lleva la `specHash` del agente del canario que se
     * vio (`GET voice/publications` → `state.canary.agent.specHash`).
     */
    public function test_promover_lleva_la_huella_del_canario_que_se_vio_y_si_cambio_es_spec_changed_sin_escribir(): void
    {
        $this->bindFake(fn (): array => ['calls' => 3, 'guardViolations' => 0, 'sealMismatches' => 0, 'driftEvents' => 0, 'unknownProviderCalls' => 0, 'budgetBreaches' => 0]);
        [$v, $p] = $this->ready('uno');
        Carbon::setTestNow(Carbon::parse('2026-11-09T14:00:00Z'));
        $this->freshSessions();
        $this->publishVoice($this->pablo, $v, $p)->assertCreated();
        Carbon::setTestNow(Carbon::parse('2026-11-10T15:00:00Z'));
        $this->freshSessions();

        // Lo que ve el CRM: el agente del canario con su huella y la evidencia, que basta.
        $vista = $this->voice('GET', 'publications', $this->paula)->assertOk()->json('data.state.canary.agent.specHash');
        $this->assertSame(self::specHashOf($v, $p), $vista);
        $e = $this->voice('GET', 'canary-evidence', $this->paula)->assertOk()->json('data');
        $this->assertTrue($e['passes'], json_encode($e['failing']));

        // 401 sin sesión; 422 sin la huella o mal escrita; 403 sin la llave.
        $cuerpo = static fn (array $over = []): array => $over + ['clientRequestId' => (string) Str::uuid(), 'evidenceHash' => $e['evidenceHash'], 'expectedSpecHash' => $vista];
        $this->json('POST', '/api/admin/ultron-studio/voice/promote', $cuerpo())->assertStatus(401);
        $sinHuella = $cuerpo();
        unset($sinHuella['expectedSpecHash']);
        $this->voice('POST', 'promote', $this->paula, $sinHuella)->assertStatus(422)->assertJsonValidationErrors(['expectedSpecHash']);
        $this->voice('POST', 'promote', $this->paula, $cuerpo(['expectedSpecHash' => strtoupper($vista)]))->assertStatus(422)->assertJsonValidationErrors(['expectedSpecHash']);
        $this->voice('POST', 'promote', $this->personWith(['ultron.view'], 'Solo Ve'), $cuerpo())->assertStatus(403)->assertJsonPath('required_permission', 'ultron.voice_publish');

        // Una huella que no es la del agente del canario: 409 con las dos, sin escribir.
        $r = $this->promoteVoice($this->paula, $e['evidenceHash'], null, null, str_repeat('d', 64))->assertStatus(409);
        $this->assertSame(['spec_changed', str_repeat('d', 64), $vista], [$r->json('code'), $r->json('expected'), $r->json('actual')]);

        // Entretanto se publica OTRA voz en canario: lo que se vio ya no es lo que se promovería
        // (y va antes que la evidencia, que para la voz nueva aún no basta).
        [$v2, $p2] = $this->ready('dos', self::profileContent(['maxDurationSeconds' => 240]));
        $this->publishVoice($this->pablo, $v2, $p2)->assertCreated();
        $r = $this->promoteVoice($this->paula, $e['evidenceHash'], null, null, $vista)->assertStatus(409);
        $this->assertSame(['spec_changed', $vista, self::specHashOf($v2, $p2)], [$r->json('code'), $r->json('expected'), $r->json('actual')]);
        $this->assertSame(0, StudioVoicePublication::query()->where('action', 'promote')->count());
        $this->assertSame(0, StudioVoiceAgent::query()->where('serves_rest', true)->count());

        // Con la voz nueva vista y su evidencia: 201, y el resto pasa a ese agente.
        Carbon::setTestNow(Carbon::parse('2026-11-11T16:00:00Z'));
        $this->freshSessions();
        $nueva = $this->voice('GET', 'publications', $this->paula)->json('data.state.canary.agent.specHash');
        $e2 = $this->voice('GET', 'canary-evidence', $this->paula)->json('data');
        $crid = (string) Str::uuid();
        $this->promoteVoice($this->paula, $e2['evidenceHash'], $crid, null, $nueva)->assertCreated();
        $this->assertSame($nueva, StudioVoiceAgent::query()->where('serves_rest', true)->value('spec_hash'));

        // El reintento: el mismo cuerpo da lo de la primera vez; otra huella con la misma llave, 409.
        $this->promoteVoice($this->paula, $e2['evidenceHash'], $crid, null, $nueva)->assertOk()->assertJsonPath('replayed', true);
        $this->promoteVoice($this->paula, $e2['evidenceHash'], $crid, null, $vista)->assertStatus(409)->assertJsonPath('code', 'client_request_reused');
        $this->assertSame(1, StudioVoicePublication::query()->where('action', 'promote')->count());
    }

    public function test_con_el_freno_de_voz_echado_no_se_publica(): void
    {
        [$v, $p] = $this->ready();
        // Lo abre la telefonía (D8-19): el Studio solo lo lee por sus servicios (E2-6).
        VoiceBrake::open('guard_violation', 'h8s-prueba-freno-1');
        $this->publishVoice($this->pablo, $v, $p)->assertStatus(409)->assertJsonPath('code', 'voice_braked')->assertJsonPath('brake', 'engaged');
        $this->assertSame([], $this->fake->calls);
        $this->assertSame(0, StudioVoicePublication::query()->count());
    }

    public function test_un_fallo_del_proveedor_no_publica_y_el_reintento_con_la_misma_llave_da_lo_mismo(): void
    {
        [$v, $p] = $this->ready();
        $crid = (string) Str::uuid();

        $this->fake->createOutcome = VoiceDeployResult::OUTCOME_AMBIGUOUS;
        $r = $this->publishVoice($this->pablo, $v, $p, $crid)->assertStatus(409);
        $this->assertSame(['voice_deploy_failed', 'create_ambiguous'], [$r->json('code'), $r->json('failure')]);
        $this->assertSame(['failed', 'create_ambiguous'], [$r->json('agent.status'), $r->json('agent.failureCode')]);

        // La misma petición otra vez: el mismo fallo, sin volver a crear nada.
        $this->fake->createOutcome = VoiceDeployResult::OUTCOME_CREATED;
        $this->publishVoice($this->pablo, $v, $p, $crid)->assertStatus(409)->assertJsonPath('failure', 'create_ambiguous');
        $this->assertCount(1, $this->fake->callsOf('create'));

        // La proyección observada no es la compilada: el agente queda rechazado y no se publica (D8-7 paso 3).
        $this->fake->describeOutcome = 'drift';
        $this->publishVoice($this->pablo, $v, $p)->assertStatus(409)->assertJsonPath('code', 'voice_agent_rejected')->assertJsonPath('agent.status', 'rejected');

        // Sin poder leerlo, tampoco.
        $this->fake->describeOutcome = 'unreachable';
        $this->publishVoice($this->pablo, $v, $p)->assertStatus(409)->assertJsonPath('failure', 'observe_unreachable');

        $this->assertSame(0, StudioVoicePublication::query()->count());
        $this->assertSame([], $this->fake->callsOf('assign'));
        $this->assertSame(['failed', 'rejected', 'failed'], StudioVoiceAgent::query()->where('purpose', 'publication')->orderBy('id')->pluck('status')->all());
    }

    public function test_sin_numero_asignado_no_se_publica_y_el_reintento_sigue_con_el_mismo_agente(): void
    {
        [$v, $p] = $this->ready();
        $crid = (string) Str::uuid();

        // Con las llamadas cerradas en el código, asignar un agente es `not_attempted` (B8): no se publica.
        $this->fake->assignOutcome = VoiceDeployResult::OUTCOME_NOT_ATTEMPTED;
        $r = $this->publishVoice($this->pablo, $v, $p, $crid)->assertStatus(409);
        $this->assertSame(['voice_number_not_assigned', 'not_attempted', 'calls_disabled_in_code'], [$r->json('code'), $r->json('outcome'), $r->json('reason')]);
        $this->assertSame(0, StudioVoicePublication::query()->count());
        $agente = StudioVoiceAgent::query()->where('client_request_id', $crid)->sole();
        $this->assertSame(['deployed', false], [$agente->status, $agente->serves_canary]);
        // Si el proveedor no tocó el número, no se le pide nada más.
        $this->assertCount(1, $this->fake->callsOf('assign'));

        // El reintento con la misma llave encuentra SU agente: no crea otro.
        $this->fake->assignOutcome = VoiceDeployResult::OUTCOME_ASSIGNED;
        $this->publishVoice($this->pablo, $v, $p, $crid)->assertCreated();
        $this->assertCount(1, $this->fake->callsOf('create'));
        $this->assertSame(1, StudioVoiceAgent::query()->where('purpose', 'publication')->count());

        // Y repetirla ya publicada es la misma entrada; con otro cuerpo, 409.
        $otraVez = $this->publishVoice($this->pablo, $v, $p, $crid)->assertOk();
        $this->assertTrue($otraVez->json('replayed'));
        $this->assertSame(1, $otraVez->json('data.entries.0.seq'));
        $this->publishVoice($this->pablo, $v, $p, $crid, 'Con nota')->assertStatus(409)->assertJsonPath('code', 'client_request_reused');
        $this->publishVoice($this->paula, $v, $p, $crid)->assertStatus(409)->assertJsonPath('code', 'client_request_reused');
    }

    public function test_si_la_cabeza_se_mueve_mientras_se_despliega_es_publication_moved_y_el_numero_vuelve(): void
    {
        [$v, $p] = $this->ready('A');
        [$v2, $p2] = $this->ready('B', self::profileContent(['maxDurationSeconds' => 240]));

        // Mientras Pablo despliega, Paula publica otra voz (de principio a fin).
        $colada = false;
        $this->fake->onAssign = function (string $numero, ?string $agente) use (&$colada, $v2, $p2): void {
            if ($colada || $agente !== 'agent_h8s_0001') {
                return;
            }
            $colada = true;
            $this->publishVoice($this->paula, $v2, $p2)->assertCreated();
        };
        $this->publishVoice($this->pablo, $v, $p)->assertStatus(409)->assertJsonPath('code', 'publication_moved');

        // Gana Paula: su agente atiende el canario; el de Pablo queda desplegado e inerte.
        $this->assertSame(1, StudioVoicePublication::query()->count());
        $this->assertSame('agent_h8s_0002', StudioVoiceAgent::query()->where('serves_canary', true)->value('provider_agent_id'));
        $this->assertFalse((bool) StudioVoiceAgent::query()->where('provider_agent_id', 'agent_h8s_0001')->value('serves_canary'));
        // El número: Pablo lo asignó a su agente, Paula al suyo y la compensación lo
        // devolvió al que dice la cadena (la última llamada es la compensación).
        $this->assertSame(['agent_h8s_0001', 'agent_h8s_0002', 'agent_h8s_0002'], array_column($this->fake->callsOf('assign'), 2));
    }

    public function test_promover_con_la_evidencia_del_canario_y_sin_deriva(): void
    {
        $medidas = ['calls' => 0, 'guardViolations' => 0, 'sealMismatches' => 0, 'driftEvents' => 0, 'unknownProviderCalls' => 0, 'budgetBreaches' => 0];
        $this->bindFake(function (int $seq, string $part) use (&$medidas): array {
            $this->assertSame('canary', $part);

            return $medidas;
        });
        $this->promoteVoice($this->paula, str_repeat('a', 64))
            ->assertStatus(409)->assertJsonPath('code', 'no_canary_publication');

        [$v, $p] = $this->ready();
        Carbon::setTestNow(Carbon::parse('2026-11-09T14:00:00Z'));
        $this->freshSessions();
        $this->publishVoice($this->pablo, $v, $p)->assertCreated();

        // Recién publicada y sin llamadas: no basta.
        $e = $this->voice('GET', 'canary-evidence', $this->paula)->assertOk()->json('data');
        $this->assertFalse($e['passes']);
        $this->assertSame(['too_soon', 'too_few_calls'], $e['failing']);
        $this->promoteVoice($this->paula, $e['evidenceHash'])
            ->assertStatus(409)->assertJsonPath('code', 'canary_evidence_insufficient');

        // 25 h y 3 llamadas selladas sin incidentes: pasa.
        Carbon::setTestNow(Carbon::parse('2026-11-10T15:00:00Z'));
        $this->freshSessions();
        $medidas['calls'] = 3;
        $e = $this->voice('GET', 'canary-evidence', $this->paula)->assertOk()->json('data');
        $this->assertTrue($e['passes'], json_encode($e['failing']));
        $this->assertSame(25, $e['hours']);

        // Una huella de la evidencia que no es la de ahora: 409 con la evidencia nueva.
        $this->promoteVoice($this->paula, str_repeat('b', 64))
            ->assertStatus(409)->assertJsonPath('code', 'evidence_changed')->assertJsonPath('evidence.evidenceHash', $e['evidenceHash']);

        // Con una violación de la guarda entretanto, la huella cambia: 409.
        $medidas['guardViolations'] = 1;
        $this->promoteVoice($this->paula, $e['evidenceHash'])
            ->assertStatus(409)->assertJsonPath('code', 'canary_evidence_insufficient')->assertJsonPath('evidence.failing', ['guard_violations']);
        $medidas['guardViolations'] = 0;

        $r = $this->promoteVoice($this->paula, $e['evidenceHash'], null, 'Evidencia completa')->assertCreated();
        $fila = StudioVoicePublication::query()->where('action', 'promote')->sole();
        $this->assertSame([2, 'rest'], [$fila->seq, $fila->scope]);
        $this->assertSame(3, $fila->evidence['measures']['calls']);
        $agente = StudioVoiceAgent::query()->where('serves_rest', true)->sole();
        $this->assertTrue($agente->serves_canary, 'el mismo agente atiende las dos partes');
        $this->assertSame(StudioVoicePublications::VERIFY_OK, StudioVoicePublications::verifyRow(StudioVoicePublications::current('rest')));
        $this->assertNull($r->json('data.number'), 'promover no toca el número');

        // Un agente con deriva no se promueve (otra publicación, otra evidencia).
        [$v2, $p2] = $this->ready('dos', self::profileContent(['maxDurationSeconds' => 240]));
        $this->publishVoice($this->pablo, $v2, $p2)->assertCreated();
        Carbon::setTestNow(Carbon::parse('2026-11-11T16:00:00Z'));
        $this->freshSessions();
        $e2 = $this->voice('GET', 'canary-evidence', $this->paula)->json('data');
        $this->fake->drifted[] = (string) StudioVoiceAgent::query()->where('serves_canary', true)->value('provider_agent_id');
        $this->promoteVoice($this->paula, $e2['evidenceHash'])
            ->assertStatus(409)->assertJsonPath('code', 'voice_agent_drift')->assertJsonPath('drift.outcome', 'drift');
    }

    public function test_nadie_promueve_lo_que_creo_aunque_tenga_la_llave(): void
    {
        $autora = $this->personWith([...self::VOICE_PUBLISHER, ...self::VOICE_MANAGER], 'Autora del perfil');
        $v = $this->approvedVersion($this->ana, $this->root, 'Versión de otra');
        $p = $this->approvedProfile($autora, $this->root, null, 'Perfil de la autora');
        $this->labVerdictFor($v, $p, $this->ana, $this->root);
        $this->publishVoice($this->pablo, $v, $p)->assertCreated();

        $this->promoteVoice($autora, str_repeat('c', 64))
            ->assertStatus(403)->assertJsonPath('code', 'four_eyes_required');
        $this->assertSame(1, StudioAuditEvent::query()->where('event', 'voice_publication.four_eyes_denied')->count());
        $this->assertSame(0, StudioVoicePublication::query()->where('action', 'promote')->count());
    }

    public function test_revertir_vuelve_al_agente_anterior_de_la_parte_y_le_reasigna_el_numero(): void
    {
        [$v, $p] = $this->ready('uno');
        [$v2, $p2] = $this->ready('dos', self::profileContent(['maxDurationSeconds' => 240]));
        $cuerpo = static fn (string $scope = 'canary'): array => ['clientRequestId' => (string) Str::uuid(), 'scope' => $scope, 'reason' => 'La nueva voz falla'];

        $this->voice('POST', 'rollback', $this->pablo, $cuerpo())->assertStatus(409)->assertJsonPath('code', 'nothing_to_rollback');
        $this->publishVoice($this->pablo, $v, $p)->assertCreated();
        $this->voice('POST', 'rollback', $this->pablo, $cuerpo())->assertStatus(409)->assertJsonPath('code', 'nothing_to_rollback');
        $this->publishVoice($this->pablo, $v2, $p2)->assertCreated();
        $this->assertSame('agent_h8s_0002', StudioVoiceAgent::query()->where('serves_canary', true)->value('provider_agent_id'));

        // Revertir no necesita la bandera ni la ventana (sentido seguro).
        $this->deployConfig(['ultron_studio.voice.publishing_enabled' => false]);
        $r = $this->voice('POST', 'rollback', $this->pablo, $cuerpo())->assertCreated();
        $fila = StudioVoicePublication::query()->where('action', 'rollback')->sole();
        $primera = StudioVoicePublication::query()->where('seq', 1)->sole();
        $this->assertSame([3, 'canary', $primera->id, $primera->agent_id], [$fila->seq, $fila->scope, $fila->restores_publication_id, $fila->agent_id]);
        $this->assertSame('agent_h8s_0001', StudioVoiceAgent::query()->where('serves_canary', true)->value('provider_agent_id'));
        $this->assertSame(['assign', self::NUMBER_ID, 'agent_h8s_0001'], end($this->fake->calls));
        $this->assertSame(['assigned', 'assigned'], [$r->json('data.number.action'), $r->json('data.number.outcome')]);
        $this->assertSame(StudioVoicePublications::VERIFY_OK, StudioVoicePublications::verifyRow(StudioVoicePublications::current('canary')));
        $this->assertSame(['ok', 'agent_h8s_0001'], [VoicePublishedConfig::read()['canary']->status, VoicePublishedConfig::read()['canary']->providerAgentId]);

        // Revertir otra vez vuelve al que se acaba de dejar (el anterior de la parte es el 2).
        // No se vuelve a un agente que ya no es el que se verificó.
        $this->fake->drifted[] = 'agent_h8s_0002';
        $this->voice('POST', 'rollback', $this->pablo, $cuerpo())->assertStatus(409)->assertJsonPath('code', 'voice_agent_drift');
    }

    public function test_revertir_una_parte_que_siempre_tuvo_el_mismo_agente_no_tiene_a_donde_volver(): void
    {
        $this->bindFake(fn (): array => ['calls' => 3, 'guardViolations' => 0, 'sealMismatches' => 0, 'driftEvents' => 0, 'unknownProviderCalls' => 0, 'budgetBreaches' => 0]);
        [$v, $p] = $this->ready();
        Carbon::setTestNow(Carbon::parse('2026-11-09T14:00:00Z'));
        $this->freshSessions();
        $this->publishVoice($this->pablo, $v, $p)->assertCreated();
        Carbon::setTestNow(Carbon::parse('2026-11-10T15:00:00Z'));
        $this->freshSessions();
        // Promover dos veces el mismo agente al resto: dos entradas, un solo agente.
        foreach ([1, 2] as $vez) {
            $e = $this->voice('GET', 'canary-evidence', $this->paula)->json('data');
            $this->promoteVoice($this->paula, $e['evidenceHash'], null, "Vez {$vez}")->assertCreated();
        }
        $this->assertSame(2, StudioVoicePublication::query()->where('scope', 'rest')->count());
        // «El agente anterior» de la parte no es el mismo: nada a donde volver.
        $this->voice('POST', 'rollback', $this->pablo, ['clientRequestId' => (string) Str::uuid(), 'scope' => 'rest', 'reason' => 'Volver atrás'])
            ->assertStatus(409)->assertJsonPath('code', 'nothing_to_rollback');
    }

    public function test_apagar_deja_una_entrada_sin_agente_por_parte_y_el_numero_sin_agente(): void
    {
        $this->bindFake(fn (): array => ['calls' => 3, 'guardViolations' => 0, 'sealMismatches' => 0, 'driftEvents' => 0, 'unknownProviderCalls' => 0, 'budgetBreaches' => 0]);
        $cuerpo = ['clientRequestId' => $crid = (string) Str::uuid(), 'reason' => 'Apagar la voz por la noche'];
        $this->voice('POST', 'off', $this->pablo, $cuerpo)->assertStatus(409)->assertJsonPath('code', 'voice_already_off');

        [$v, $p] = $this->ready();
        Carbon::setTestNow(Carbon::parse('2026-11-09T14:00:00Z'));
        $this->freshSessions();
        $this->publishVoice($this->pablo, $v, $p)->assertCreated();
        Carbon::setTestNow(Carbon::parse('2026-11-10T15:00:00Z'));
        $this->freshSessions();
        $e = $this->voice('GET', 'canary-evidence', $this->paula)->json('data');
        $this->promoteVoice($this->paula, $e['evidenceHash'])->assertCreated();

        $this->deployConfig(['ultron_studio.voice.publishing_enabled' => false]);
        $r = $this->voice('POST', 'off', $this->pablo, $cuerpo)->assertCreated();
        $this->assertSame([[3, 'off', 'canary'], [4, 'off', 'rest']], array_map(static fn (array $e): array => [$e['seq'], $e['action'], $e['scope']], $r->json('data.entries')));
        foreach (StudioVoicePublication::query()->where('action', 'off')->get() as $fila) {
            $this->assertNull($fila->agent_id);
            $this->assertSame('off', json_decode($fila->effective_json, true)['scope'], 'el mensaje firma `off` (DF-V3)');
            $this->assertSame(StudioVoicePublications::VERIFY_OK, StudioVoicePublications::verifyRow($fila));
        }
        $this->assertSame(0, StudioVoiceAgent::query()->where('serves_canary', true)->orWhere('serves_rest', true)->count());
        $this->assertSame(['assign', self::NUMBER_ID, null], end($this->fake->calls));
        $this->assertSame('unassigned', $r->json('data.number.action'));
        $leida = VoicePublishedConfig::read();
        $this->assertSame(['off', 'off'], [$leida['canary']->status, $leida['rest']->status], 'el lector lee las dos partes apagadas: '.$leida['canary']->reason.' / '.$leida['rest']->reason);

        // La misma petición: las dos entradas de la primera vez; ya apagada, 409.
        $otraVez = $this->voice('POST', 'off', $this->pablo, $cuerpo)->assertOk();
        $this->assertSame([3, 4], array_column($otraVez->json('data.entries'), 'seq'));
        $this->voice('POST', 'off', $this->pablo, ['clientRequestId' => (string) Str::uuid(), 'reason' => 'Otra vez'])->assertStatus(409)->assertJsonPath('code', 'voice_already_off');
        $this->assertSame(StudioVoicePublishing::derivedRequestId($crid, 'rest'), StudioVoicePublication::query()->where('seq', 4)->value('client_request_id'));
    }

    public function test_con_el_adaptador_real_y_las_llamadas_cerradas_en_el_codigo_despliega_pero_no_asigna_ni_publica(): void
    {
        // ElevenLabs configurado (`ELEVENLABS_UNVERIFIED`, E1-12) contra un simulador.
        config([
            'voice.elevenlabs.enabled' => true,
            'voice.elevenlabs.api_key' => 'sk_h8s_pruebaCENTINELA0001',
            'voice.elevenlabs.agent_id' => 'agent_h8s_verificacion',
            'voice.elevenlabs.webhook_secret' => 'wsec_h8s_pruebaCENTINELA0001',
            'voice.elevenlabs.region' => 'default',
            'voice.elevenlabs.base_url' => 'https://el.h8s.test',
        ]);
        $this->app->forgetInstance(StudioVoicePublishing::class);
        $this->app->forgetInstance(StudioVoiceEvidence::class);
        [$v, $p] = $this->ready();

        // El «proveedor»: guarda lo que se crea y lo devuelve tal cual al leerlo.
        $herramientas = [];
        $agentes = [];
        Http::fake(function (ClientRequest $req) use (&$herramientas, &$agentes) {
            $ruta = (string) parse_url($req->url(), PHP_URL_PATH);
            if ($req->method() === 'POST' && $ruta === '/v1/convai/tools') {
                $id = 'tool_h8s_'.(count($herramientas) + 1);
                $herramientas[$id] = $req->data()['tool_config'];

                return Http::response(['id' => $id]);
            }
            if ($req->method() === 'POST' && $ruta === '/v1/convai/agents/create') {
                $id = 'agent_h8s_real_'.(count($agentes) + 1);
                $agentes[$id] = $req->data();

                return Http::response(['agent_id' => $id]);
            }
            if ($req->method() === 'GET' && str_starts_with($ruta, '/v1/convai/agents/')) {
                return Http::response($agentes[substr($ruta, strlen('/v1/convai/agents/'))] + ['version_id' => 'ver_1', 'branch_id' => 'br_1']);
            }
            if ($req->method() === 'GET' && str_starts_with($ruta, '/v1/convai/tools/')) {
                return Http::response(['id' => substr($ruta, strlen('/v1/convai/tools/')), 'tool_config' => $herramientas[substr($ruta, strlen('/v1/convai/tools/'))]]);
            }

            return Http::response(['detail' => 'ruta no esperada'], 599);
        });

        $r = $this->publishVoice($this->pablo, $v, $p)->assertStatus(409);
        $this->assertSame(['voice_number_not_assigned', 'not_attempted', 'calls_disabled_in_code'], [$r->json('code'), $r->json('outcome'), $r->json('reason')]);

        // Se desplegó y se verificó de verdad (la proyección leída es la compilada)…
        $agente = StudioVoiceAgent::query()->where('purpose', 'publication')->sole();
        $this->assertSame(['deployed', 'agent_h8s_real_1'], [$agente->status, $agente->provider_agent_id]);
        // …pero nadie tocó el número ni la cadena (la constante del código manda).
        $this->assertSame(0, StudioVoicePublication::query()->count());
        $hosts = array_map(static fn (array $par): string => $par[0]->method().' '.parse_url($par[0]->url(), PHP_URL_HOST).parse_url($par[0]->url(), PHP_URL_PATH), Http::recorded()->all());
        foreach ($hosts as $h) {
            $this->assertStringContainsString('el.h8s.test', $h);
            $this->assertStringNotContainsString('phone-numbers', $h, 'con las llamadas cerradas en el código no se asigna el número');
        }
        $this->assertStringNotContainsString('sk_h8s_pruebaCENTINELA0001', $r->getContent());
    }
}
