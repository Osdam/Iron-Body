<?php

namespace Tests\Feature\UltronStudio;

use App\Models\Admin;
use App\Models\UltronStudio\StudioAuditEvent;
use App\Models\UltronStudio\StudioVoicePublication;
use App\Services\Telephony\VoiceBrake;
use App\Services\Telephony\VoicePublishedConfig;
use App\Services\UltronStudio\StudioVoicePublications;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Lo que se LEE del gobierno de la voz (S8-b): el estado de la cadena y su
 * historial verificados (una fila que no firmó el Studio se ve), la
 * previsualización de la compilación (sin red y sin escribir), la evidencia del
 * canario y el freno de la telefonía (E2-6).
 */
class UltronStudioVoiceChainTest extends TestCase
{
    use PublicationFixtures;
    use RefreshDatabase;
    use StudioFixtures;
    use StudioVoiceFixtures;

    private Admin $ana;

    private Admin $root;

    private Admin $pablo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->voiceSetUp();
        $this->ana = $this->personWith([...self::VOICE_MANAGER, 'ultron.approve', 'ultron.edit_draft'], 'Ana Autora');
        $this->root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root Revisa');
        $this->pablo = $this->personWith(self::VOICE_PUBLISHER, 'Pablo Publica');
        $this->bindFake();
    }

    public function test_el_estado_y_el_historial_de_la_cadena_verificados_y_una_fila_ajena_se_ve(): void
    {
        $vacia = $this->voice('GET', 'publications', $this->ana)->assertOk()->json('data');
        $this->assertSame(['head' => null, 'canary' => null, 'rest' => null, 'verified' => true], $vacia['state']);
        $this->assertSame(['enabled' => true, 'code' => null], array_intersect_key($vacia['publishing'], ['enabled' => 1, 'code' => 1]));

        $v = $this->approvedVersion($this->ana, $this->root, 'Versión');
        $p = $this->approvedProfile($this->ana, $this->root);
        $this->labVerdictFor($v, $p, $this->ana, $this->root);
        $this->publishVoice($this->pablo, $v, $p)->assertCreated();

        $d = $this->voice('GET', 'publications', $this->ana)->assertOk()->json('data');
        $this->assertSame([1, 'publish', 'canary'], [$d['state']['head']['seq'], $d['state']['head']['action'], $d['state']['head']['scope']]);
        $this->assertSame(['ok', true], [$d['state']['canary']['verified'], $d['state']['canary']['serving']]);
        $this->assertSame([$v->uuid, $p->uuid], [$d['state']['canary']['agent']['version']['uuid'], $d['state']['canary']['agent']['profile']['uuid']]);
        $this->assertNull($d['state']['rest']);
        $this->assertSame(['ok'], array_column($d['history']['data'], 'verified'));

        // Una fila que el Studio no firmó (alguien con acceso a la base): la cadena la deja entrar
        // si encadena, pero la firma no cuadra y se ve, también en el lector.
        $agente = (int) StudioVoicePublication::query()->value('agent_id');
        $cabeza = StudioVoicePublication::query()->sole();
        $mensaje = StudioVoicePublications::message(['seq' => 2, 'prev_hash' => $cabeza->effective_hash, 'scope' => 'rest'] + json_decode($cabeza->effective_json, true));
        DB::table('ultron_studio_voice_publications')->insert([
            'uuid' => (string) Str::uuid(), 'seq' => 2, 'client_request_id' => (string) Str::uuid(), 'action' => 'promote', 'scope' => 'rest',
            'previous_publication_id' => $cabeza->id, 'prev_hash' => $cabeza->effective_hash, 'agent_id' => $agente,
            'effective_json' => $mensaje, 'effective_hash' => hash('sha256', $mensaje), 'signature' => str_repeat('e', 64),
            'lab_gate' => json_encode(['mode' => null]), 'actor_admin_id' => $this->pablo->id, 'actor_name' => 'Nadie', 'created_at' => now(),
        ]);
        $d = $this->voice('GET', 'publications', $this->ana)->assertOk()->json('data');
        $this->assertSame(['signature_mismatch', false], [$d['state']['rest']['verified'], $d['state']['verified']]);
        $this->assertSame(['signature_mismatch', 'ok'], array_column($d['history']['data'], 'verified'));
        $this->assertSame('unreadable', VoicePublishedConfig::read()['rest']->status, 'el lector de la telefonía deniega igual');

        $this->voice('GET', 'publications?per_page=0', $this->ana)->assertStatus(422);
    }

    public function test_una_fila_bien_firmada_que_no_cuadra_con_su_agente_o_su_actor_no_verifica(): void
    {
        $v = $this->approvedVersion($this->ana, $this->root, 'Versión');
        $p = $this->approvedProfile($this->ana, $this->root);
        $this->labVerdictFor($v, $p, $this->ana, $this->root);
        $this->publishVoice($this->pablo, $v, $p)->assertCreated();
        $cabeza = StudioVoicePublication::query()->sole();
        $base = json_decode($cabeza->effective_json, true);

        // Firmadas con la clave de verdad (quien tiene la APP_KEY y la base), pero el
        // mensaje no es lo que dicen la fila y su agente: `row_mismatch`, nunca `ok`.
        foreach ([
            'otra huella de la carga' => ['agent_config_hash' => str_repeat('9', 64)],
            'otro agente del proveedor' => ['provider_agent_id' => 'agent_ajeno_0001'],
            'otra persona' => ['actor_id' => $this->root->id],
            'otra versión' => ['version_uuid' => (string) Str::uuid()],
        ] as $nombre => $cambio) {
            $seq = (int) StudioVoicePublication::query()->max('seq') + 1;
            $anterior = StudioVoicePublication::query()->where('seq', $seq - 1)->sole();
            $mensaje = StudioVoicePublications::message(array_replace($base, ['seq' => $seq, 'prev_hash' => $anterior->effective_hash, 'scope' => 'rest'], $cambio));
            DB::table('ultron_studio_voice_publications')->insert([
                'uuid' => (string) Str::uuid(), 'seq' => $seq, 'client_request_id' => (string) Str::uuid(), 'action' => 'promote', 'scope' => 'rest',
                'previous_publication_id' => $anterior->id, 'prev_hash' => $anterior->effective_hash, 'agent_id' => $cabeza->agent_id,
                'effective_json' => $mensaje, 'effective_hash' => hash('sha256', $mensaje), 'signature' => StudioVoicePublications::signer()($mensaje),
                'lab_gate' => json_encode(['mode' => null]), 'actor_admin_id' => $this->pablo->id, 'actor_name' => 'Pablo Publica', 'created_at' => now(),
            ]);
            $this->assertSame(StudioVoicePublications::VERIFY_ROW_MISMATCH, StudioVoicePublications::verifyRow(StudioVoicePublications::current('rest')), $nombre);
        }
    }

    public function test_la_previsualizacion_compila_sin_red_ni_escribir_y_dice_lo_que_falta(): void
    {
        $v = $this->approvedVersion($this->ana, $this->root, 'Versión');
        $p = $this->approvedProfile($this->ana, $this->root, self::profileContentWithTransfer());
        $enviado = (string) $this->createProfile($this->ana, null, null, 'Enviado')->json('data.uuid');
        $this->voice('POST', "profiles/{$enviado}/submit", $this->ana)->assertOk();
        $cuenta = static fn (): array => [DB::table('ultron_studio_voice_agents')->count(), DB::table('ultron_studio_voice_publications')->count(), StudioAuditEvent::query()->count()];
        $antes = $cuenta();

        $r = $this->voice('POST', 'compile-preview', $this->ana, ['versionUuid' => $v->uuid, 'profileUuid' => $p->uuid])->assertOk();
        $this->assertSame(self::specHashOf($v, $p), $r->json('data.specHash'));
        $this->assertSame(['Recepción'], array_column($r->json('data.spec.transfer.destinations'), 'alias'));
        $this->assertStringNotContainsString('6010000099', $r->getContent(), 'la especificación no lleva teléfonos');
        $this->assertSame([], $r->json('data.deployable.missing'));
        $this->assertNull($r->json('data.labVerdict.latest'));

        $this->deployConfig(['voice.webhook_base_url' => 'http://no-local.test', 'voice.elevenlabs.agent_phone_number_id' => null]);
        $this->assertSame(['webhook_base_url', 'phone_number_id'], $this->voice('POST', 'compile-preview', $this->ana, ['versionUuid' => $v->uuid, 'profileUuid' => $p->uuid])->json('data.deployable.missing'));

        // Un perfil enviado solo compila para el laboratorio.
        $this->voice('POST', 'compile-preview', $this->ana, ['versionUuid' => $v->uuid, 'profileUuid' => $enviado])->assertStatus(422)->assertJsonPath('reason', 'profile_not_approved');
        $this->voice('POST', 'compile-preview', $this->ana, ['versionUuid' => $v->uuid, 'profileUuid' => $enviado, 'purpose' => 'lab'])->assertOk()->assertJsonPath('data.purpose', 'lab');

        $this->assertSame($antes, $cuenta(), 'previsualizar no escribe nada');
        $this->assertSame([], $this->fake->calls, 'previsualizar no despliega');
        // Previsualizar es de quien gestiona la voz.
        $this->voice('POST', 'compile-preview', $this->pablo, ['versionUuid' => $v->uuid, 'profileUuid' => $p->uuid])->assertStatus(403)->assertJsonPath('required_permission', 'ultron.voice_manage');
        $this->voice('POST', 'compile-preview', $this->ana, ['versionUuid' => $v->uuid, 'profileUuid' => $p->uuid, 'purpose' => 'otro'])->assertStatus(422);
    }

    public function test_la_evidencia_sin_publicacion_y_el_freno_de_la_telefonia(): void
    {
        $e = $this->voice('GET', 'canary-evidence', $this->ana)->assertOk()->json('data');
        $this->assertSame([false, ['no_canary_publication']], [$e['passes'], $e['failing']]);
        $this->assertSame(['minHours' => 24, 'minCalls' => 3], $e['thresholds']);

        $f = $this->voice('GET', 'brakes', $this->ana)->assertOk()->json('data');
        $this->assertSame(['state' => 'released', 'brakes' => []], $f);

        VoiceBrake::open('seal_mismatch', 'h8s-sello-1', ['codes' => ['seal_mismatch']]);
        $f = $this->voice('GET', 'brakes?limit=5', $this->ana)->assertOk()->json('data');
        $this->assertSame('engaged', $f['state']);
        $this->assertSame(['seal_mismatch', false], [$f['brakes'][0]['reason'], $f['brakes'][0]['released']]);
        $this->voice('GET', 'brakes?limit=51', $this->ana)->assertStatus(422);

        // Soltarlo: solo con `ultron.voice_publish`, con una nota; nunca solo.
        $uuid = $f['brakes'][0]['uuid'];
        $cuerpo = ['clientRequestId' => (string) Str::uuid(), 'note' => 'Revisado con el equipo: fue una prueba'];
        $this->voice('POST', "brakes/{$uuid}/release", $this->ana, $cuerpo)->assertStatus(403)->assertJsonPath('required_permission', 'ultron.voice_publish');
        $this->voice('POST', "brakes/{$uuid}/release", $this->pablo, ['clientRequestId' => (string) Str::uuid()])->assertStatus(422)->assertJsonValidationErrors('note');
        $this->voice('POST', "brakes/{$uuid}/release", $this->pablo, ['clientRequestId' => (string) Str::uuid(), 'note' => str_repeat('a', 301)])->assertStatus(422);
        $r = $this->voice('POST', "brakes/{$uuid}/release", $this->pablo, $cuerpo)->assertOk();
        $this->assertSame([$uuid, 'released'], [$r->json('data.brakeUuid'), $r->json('data.state')]);
        $this->assertSame(1, StudioAuditEvent::query()->where('event', 'voice_brake.released')->where('subject_uuid', $uuid)->count());
        // La telefonía la suelta una vez (409 suyo) y una apertura inventada es 404.
        $this->voice('POST', "brakes/{$uuid}/release", $this->pablo, ['clientRequestId' => (string) Str::uuid(), 'note' => 'Otra vez'])->assertStatus(409)->assertJsonPath('code', 'brake_already_released');
        $this->voice('POST', 'brakes/'.Str::uuid().'/release', $this->pablo, ['clientRequestId' => (string) Str::uuid(), 'note' => 'No existe'])->assertStatus(404);
    }
}
