<?php

namespace Tests\Feature\UltronStudio;

use App\Models\Admin;
use App\Models\UltronStudio\StudioAuditEvent;
use App\Models\UltronStudio\StudioVersion;
use App\Models\UltronStudio\StudioVoiceAgent;
use App\Models\UltronStudio\StudioVoiceProfile;
use App\Models\UltronStudio\StudioVoicePublication;
use App\Services\Telephony\VoicePublishedConfig;
use App\Services\UltronStudio\StudioAudit;
use App\Services\UltronStudio\StudioVersions;
use App\Services\UltronStudio\StudioVoiceEvidence;
use App\Services\UltronStudio\StudioVoicePublications;
use App\Services\UltronStudio\StudioVoicePublishing;
use App\Services\Voice\Dto\VoiceAgentObservation;
use App\Services\Voice\Dto\VoiceAgentPayload;
use App\Services\Voice\Dto\VoiceDeployResult;
use App\Services\Voice\VoiceAgentDeployer;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PUBLICAR CON UN NÚMERO «OUTBOUND ONLY» (S8-d, 09-11-2026). El propietario
 * importó el número de Iron Body en ElevenLabs como solo saliente (un caller ID
 * verificado en Twilio) y prioriza las salientes. La documentación oficial de
 * la integración nativa: esos números «cannot be assigned to agents». Con
 * `VOICE_NUMBER_INBOUND_CAPABLE=false` publicar NO intenta asignar, la entrada
 * dice `number.outcome = not_applicable_outbound_only` (en su `evidence`, sin
 * firmar, y en la respuesta y el libro), revertir y apagar tampoco tocan el
 * número y la compensación no tiene nada que devolver. Sin la clave, el
 * comportamiento de siempre: se asigna y un número que no lo admite lo
 * rechaza el proveedor (falla cerrado).
 */
class UltronStudioVoiceOutboundOnlyTest extends TestCase
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

    /** @return array{0:StudioVersion,1:StudioVoiceProfile} */
    private function ready(string $etiqueta = 'Voz', ?array $contenido = null): array
    {
        $v = $this->approvedVersion($this->ana, $this->root, "Versión {$etiqueta}");
        $p = $this->approvedProfile($this->ana, $this->root, $contenido, "Perfil {$etiqueta}");
        $this->labVerdictFor($v, $p, $this->ana, $this->root);

        return [$v, $p];
    }

    private function outboundOnly(bool $soloSaliente = true): void
    {
        config([StudioVoicePublishing::NUMBER_INBOUND_CAPABLE_KEY => ! $soloSaliente]);
    }

    public function test_la_senal_solo_un_false_explicito_dice_solo_saliente(): void
    {
        $this->assertSame('voice.number.inbound_capable', StudioVoicePublishing::NUMBER_INBOUND_CAPABLE_KEY);
        $this->assertSame('not_applicable_outbound_only', StudioVoicePublishing::NUMBER_NOT_APPLICABLE);
        foreach ([[false, false], [true, true], [null, true], ['false', true], [0, true]] as [$valor, $admite]) {
            config([StudioVoicePublishing::NUMBER_INBOUND_CAPABLE_KEY => $valor]);
            $this->assertSame($admite, StudioVoicePublishing::numberInboundCapable(), var_export($valor, true));
        }
    }

    public function test_publicar_con_un_numero_solo_saliente_no_asigna_y_la_entrada_lo_dice(): void
    {
        $this->outboundOnly();
        // Aunque asignar fallara (p. ej. con la constante cerrada), no se pide: no aplica.
        $this->fake->assignOutcome = VoiceDeployResult::OUTCOME_NOT_ATTEMPTED;
        [$v, $p] = $this->ready('saliente');

        $r = $this->publishVoice($this->pablo, $v, $p)->assertCreated();

        // Crear y verificar el agente, sí; el número, ni una petición.
        $agente = StudioVoiceAgent::query()->where('purpose', 'publication')->sole();
        $this->assertSame([['create', $agente->payload_hash], ['describe', 'agent_h8s_0001']], $this->fake->calls);
        $this->assertSame(['deployed', true], [$agente->status, $agente->serves_canary]);

        // La respuesta, la entrada (en su `evidence`, que no se firma) y su DTO.
        $this->assertSame(['action' => 'assigned', 'outcome' => 'not_applicable_outbound_only', 'reason' => null], $r->json('data.number'));
        $fila = StudioVoicePublication::query()->sole();
        $this->assertSame(['number' => ['action' => 'assigned', 'outcome' => 'not_applicable_outbound_only']], $fila->evidence);
        $this->assertSame(['action' => 'assigned', 'outcome' => 'not_applicable_outbound_only'], $r->json('data.entries.0.number'));
        $this->assertNull($r->json('data.entries.0.evidence'), 'la evidencia de promover no se mezcla con el número');
        $this->assertArrayNotHasKey('evidence', json_decode($fila->effective_json, true), 'lo firmado sigue siendo E1-4');
        $this->assertSame(StudioVoicePublications::VERIFY_OK, StudioVoicePublications::verifyRow(StudioVoicePublications::current('canary')));

        // El libro lo registra.
        $evento = StudioAuditEvent::query()->where('event', 'voice_publication.published')->sole();
        $this->assertSame('not_applicable_outbound_only', $evento->diff['number']);

        // La telefonía lee la entrada (las salientes usan el agente sellado, nunca el del número).
        $leida = VoicePublishedConfig::read()['canary'];
        $this->assertSame(['ok', 'agent_h8s_0001'], [$leida->status, $leida->providerAgentId], (string) $leida->reason);

        // El historial enseña lo mismo.
        $historia = $this->voice('GET', 'publications', $this->pablo)->assertOk()->json('data.history.data.0');
        $this->assertSame(['action' => 'assigned', 'outcome' => 'not_applicable_outbound_only'], $historia['number']);
    }

    public function test_con_un_numero_que_admite_entrantes_se_asigna_como_siempre_y_queda_escrito(): void
    {
        $this->outboundOnly(false);
        [$v, $p] = $this->ready('entrantes');
        $r = $this->publishVoice($this->pablo, $v, $p)->assertCreated();
        $this->assertSame(['assign', self::NUMBER_ID, 'agent_h8s_0001'], end($this->fake->calls));
        $this->assertSame(['action' => 'assigned', 'outcome' => 'assigned', 'reason' => null], $r->json('data.number'));
        $this->assertSame(['number' => ['action' => 'assigned', 'outcome' => 'assigned']], StudioVoicePublication::query()->sole()->evidence);
        $this->assertSame('assigned', StudioAuditEvent::query()->where('event', 'voice_publication.published')->sole()->diff['number']);
    }

    public function test_sin_la_clave_se_intenta_asignar_y_un_numero_que_no_lo_admite_no_publica(): void
    {
        config([StudioVoicePublishing::NUMBER_INBOUND_CAPABLE_KEY => null]);
        // El proveedor rechaza asignar un número solo saliente (un 4xx): falla cerrado, nunca en silencio.
        $this->fake->assignOutcome = VoiceDeployResult::OUTCOME_REJECTED;
        [$v, $p] = $this->ready();
        $this->publishVoice($this->pablo, $v, $p)->assertStatus(409)->assertJsonPath('code', 'voice_number_not_assigned')->assertJsonPath('outcome', 'rejected');
        $this->assertCount(1, $this->fake->callsOf('assign'));
        $this->assertSame(0, StudioVoicePublication::query()->count());
    }

    public function test_revertir_y_apagar_con_un_numero_solo_saliente_no_tocan_el_numero(): void
    {
        $this->outboundOnly();
        [$v, $p] = $this->ready('uno');
        [$v2, $p2] = $this->ready('dos', self::profileContent(['maxDurationSeconds' => 240]));
        $this->publishVoice($this->pablo, $v, $p)->assertCreated();
        $this->publishVoice($this->pablo, $v2, $p2)->assertCreated();

        $r = $this->voice('POST', 'rollback', $this->pablo, ['clientRequestId' => (string) Str::uuid(), 'scope' => 'canary', 'reason' => 'La nueva voz falla'])->assertCreated();
        $this->assertSame(['action' => 'assigned', 'outcome' => 'not_applicable_outbound_only', 'reason' => null], $r->json('data.number'));
        $this->assertSame('agent_h8s_0001', StudioVoiceAgent::query()->where('serves_canary', true)->value('provider_agent_id'));

        $r = $this->voice('POST', 'off', $this->pablo, ['clientRequestId' => (string) Str::uuid(), 'reason' => 'Apagar la voz'])->assertCreated();
        $this->assertSame(['action' => 'unassigned', 'outcome' => 'not_applicable_outbound_only', 'reason' => null], $r->json('data.number'));

        // Ni una sola asignación en todo el recorrido.
        $this->assertSame([], $this->fake->callsOf('assign'));
    }

    public function test_si_la_cabeza_se_mueve_es_publication_moved_y_no_hay_numero_que_devolver(): void
    {
        $this->outboundOnly();
        [$v, $p] = $this->ready('A');
        [$v2, $p2] = $this->ready('B', self::profileContent(['maxDurationSeconds' => 240]));

        // Sin asignación, la carrera se cuela al describir el agente de Pablo: Paula publica entera.
        $colada = false;
        $alDescribir = function (string $agente) use (&$colada, $v2, $p2): void {
            if ($colada || $agente !== 'agent_h8s_0001') {
                return;
            }
            $colada = true;
            $this->publishVoice($this->paula, $v2, $p2)->assertCreated();
        };
        $envoltura = new class($this->fake, $alDescribir) implements VoiceAgentDeployer
        {
            public function __construct(private readonly FakeVoiceDeployer $inner, private readonly Closure $onDescribe) {}

            public function createAgent(VoiceAgentPayload $payload): VoiceDeployResult
            {
                return $this->inner->createAgent($payload);
            }

            public function describeAgent(string $agentId): VoiceAgentObservation
            {
                ($this->onDescribe)($agentId);

                return $this->inner->describeAgent($agentId);
            }

            public function assignNumber(string $providerNumberId, ?string $agentId): VoiceDeployResult
            {
                return $this->inner->assignNumber($providerNumberId, $agentId);
            }
        };
        $this->app->instance(StudioVoicePublishing::class, new StudioVoicePublishing(
            app(StudioAudit::class), app(StudioVersions::class), app(StudioVoiceEvidence::class), $envoltura,
        ));

        $this->publishVoice($this->pablo, $v, $p)->assertStatus(409)->assertJsonPath('code', 'publication_moved');
        $this->assertTrue($colada);
        $this->assertSame('agent_h8s_0002', StudioVoiceAgent::query()->where('serves_canary', true)->value('provider_agent_id'));
        $this->assertSame(1, StudioVoicePublication::query()->count());
        $this->assertSame([], $this->fake->callsOf('assign'), 'ni asignar ni compensar');
    }

    /** Con el adaptador real de ElevenLabs (contra `Http::fake`) y la constante de llamadas cerrada: publica sin ninguna petición al número. */
    public function test_con_el_adaptador_real_publica_sin_pedir_nada_al_numero(): void
    {
        $this->outboundOnly();
        config([
            'voice.elevenlabs.enabled' => true,
            'voice.elevenlabs.api_key' => 'sk_h8s_pruebaCENTINELA0002',
            'voice.elevenlabs.agent_id' => 'agent_h8s_verificacion',
            'voice.elevenlabs.webhook_secret' => 'wsec_h8s_pruebaCENTINELA0002',
            'voice.elevenlabs.region' => 'default',
            'voice.elevenlabs.base_url' => 'https://el.h8s.test',
        ]);
        $this->app->forgetInstance(StudioVoicePublishing::class);
        $this->app->forgetInstance(StudioVoiceEvidence::class);
        [$v, $p] = $this->ready();

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

        $r = $this->publishVoice($this->pablo, $v, $p)->assertCreated();
        $this->assertSame('not_applicable_outbound_only', $r->json('data.number.outcome'));
        $this->assertSame('agent_h8s_real_1', StudioVoiceAgent::query()->where('serves_canary', true)->value('provider_agent_id'));
        foreach (Http::recorded()->all() as [$peticion]) {
            $this->assertStringNotContainsString('phone-numbers', $peticion->url(), 'ninguna petición al número');
        }
        $this->assertStringNotContainsString('sk_h8s_pruebaCENTINELA0002', $r->getContent());
    }
}
