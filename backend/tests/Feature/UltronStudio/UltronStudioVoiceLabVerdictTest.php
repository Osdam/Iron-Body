<?php

namespace Tests\Feature\UltronStudio;

use App\Models\Admin;
use App\Models\UltronStudio\StudioVoiceLabSession;
use App\Services\UltronStudio\StudioVoiceLabVerdicts;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * EL VEREDICTO DEL LABORATORIO HABLADO (contrato H8, D8-23, D8-19 y P-19): de
 * OTRA identidad (ni quien abrió la sesión ni el autor de la versión), sobre
 * una sesión terminada de un agente real, sobre la MISMA huella que vio la
 * persona (la que exigirá la publicación), uno por sesión y definitivo. Y la
 * lectura de la puerta de publicación de S8-b, {@see StudioVoiceLabVerdicts::latestFor()}.
 */
class UltronStudioVoiceLabVerdictTest extends TestCase
{
    use PublicationFixtures;
    use RefreshDatabase;
    use StudioFixtures;
    use VoiceLabFixtures;

    private Admin $autor;

    private Admin $tina;

    private Admin $rita;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->voiceLabRoutes();
        $this->importBaseline();
        $this->voiceLabElevenLabs();
        $this->voiceVerified();
        $this->fakeElevenLabs();
        $this->autor = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root Autor');
        $this->tina = $this->tester();
        $this->rita = $this->reviewer();
    }

    /** @return array<string, mixed> SessionDto de una sesión ya terminada por quien la abrió */
    private function endedSession(Admin $opener, ?string $versionUuid = null, ?string $profileUuid = null): array
    {
        $versionUuid ??= $this->labVersion($this->autor)->uuid;
        $profileUuid ??= $this->seedProfile($this->autor, null, 'submitted');
        $uuid = $this->openSession($opener, $versionUuid, $profileUuid, null, 500000)->assertCreated()->json('data.uuid');

        return $this->voiceLab('POST', "sessions/{$uuid}/end", $opener, self::observations())->assertOk()->json('data');
    }

    public function test_otra_identidad_aprueba_sobre_la_misma_huella_una_vez_y_es_definitivo(): void
    {
        $s = $this->endedSession($this->tina);
        $this->assertSame(['allowed' => true], $this->voiceLab('GET', "sessions/{$s['uuid']}", $this->rita)->json('data.actions.verdict'));

        $d = $this->voiceLab('POST', "sessions/{$s['uuid']}/verdict", $this->rita, ['decision' => 'approved', 'specHash' => strtoupper($s['agent']['specHash'])])
            ->assertOk()->json('data');
        $this->assertSame('approved', $d['verdict']['decision']);
        $this->assertNull($d['verdict']['note']);
        $this->assertSame(['name' => $this->rita->name, 'role' => $this->rita->role], $d['verdict']['by']);
        $this->assertSame('verdict_exists', $d['actions']['verdict']['code']);
        $this->assertSame('voice_lab_session_not_open', $this->voiceLab('GET', "sessions/{$s['uuid']}", $this->tina)->json('data.actions.end.code'));

        $this->voiceLab('POST', "sessions/{$s['uuid']}/verdict", $this->rita, ['decision' => 'rejected', 'note' => 'Cambio de opinión.', 'specHash' => $s['agent']['specHash']])
            ->assertStatus(409)->assertJsonPath('code', 'verdict_exists');

        // Definitivo también en la base: ningún UPDATE pasa.
        $this->assertDatabaseFails(fn () => DB::table('ultron_studio_voice_lab_sessions')->where('uuid', $s['uuid'])->update(['cost_micros' => 1]));
        $this->assertDatabaseFails(fn () => DB::table('ultron_studio_voice_lab_sessions')->where('uuid', $s['uuid'])->update(['verdict' => 'rejected', 'verdict_note' => 'Por la puerta de atrás.']));

        // La puerta de la publicación (S8-b) lo lee por la huella.
        $this->assertSame([
            'latest' => 'approved',
            'sessionUuid' => $s['uuid'],
            'verdictAt' => $d['verdict']['at'],
            'reviewerId' => $this->rita->id,
            'sessionCreatorId' => $this->tina->id,
        ], StudioVoiceLabVerdicts::latestFor($s['agent']['specHash']));
        $this->assertNull(StudioVoiceLabVerdicts::latestFor(str_repeat('0', 64))['latest']);
    }

    public function test_cuatro_ojos_ni_quien_abrio_la_sesion_ni_el_autor_de_la_version(): void
    {
        // Con las tres llaves, quien abrió la sesión sigue sin poder juzgarla.
        $todo = $this->labPerson(['ultron.view', 'ultron.voice_lab_run', 'ultron.approve'], 'Tiene todo');
        $s = $this->endedSession($todo);
        $this->assertSame('four_eyes_required', $this->voiceLab('GET', "sessions/{$s['uuid']}", $todo)->json('data.actions.verdict.code'));
        $this->voiceLab('POST', "sessions/{$s['uuid']}/verdict", $todo, ['decision' => 'approved', 'specHash' => $s['agent']['specHash']])
            ->assertStatus(403)->assertJsonPath('code', 'four_eyes_required')->assertJsonPath('reason', 'session_creator');

        // El autor de la versión tampoco, aunque no la abriera.
        $this->voiceLab('POST', "sessions/{$s['uuid']}/verdict", $this->autor, ['decision' => 'approved', 'specHash' => $s['agent']['specHash']])
            ->assertStatus(403)->assertJsonPath('code', 'four_eyes_required')->assertJsonPath('reason', 'version_author');
        $this->assertNull(StudioVoiceLabSession::query()->where('uuid', $s['uuid'])->value('verdict'));

        // Y la base lo impide aunque se escriba a mano (CHECK en PG, disparador en SQLite).
        $this->assertDatabaseFails(fn () => DB::table('ultron_studio_voice_lab_sessions')->where('uuid', $s['uuid'])->update([
            'verdict' => 'approved', 'verdict_by_admin_id' => $todo->id, 'verdict_by_name' => $todo->name, 'verdict_at' => now(),
        ]));

        // Otra persona sí.
        $this->voiceLab('POST', "sessions/{$s['uuid']}/verdict", $this->rita, ['decision' => 'approved', 'specHash' => $s['agent']['specHash']])->assertOk();
    }

    public function test_solo_sobre_una_sesion_terminada_de_un_agente_real_y_con_la_huella_que_se_vio(): void
    {
        $version = $this->labVersion($this->autor)->uuid;
        $perfil = $this->seedProfile($this->autor, null, 'submitted');
        $abierta = $this->openSession($this->tina, $version, $perfil, null, 500000)->assertCreated()->json('data');
        $this->voiceLab('POST', "sessions/{$abierta['uuid']}/verdict", $this->rita, ['decision' => 'approved', 'specHash' => $abierta['agent']['specHash']])
            ->assertStatus(409)->assertJsonPath('code', 'voice_lab_session_not_reviewable')->assertJsonPath('reason', 'not_ended');

        // Una que el proveedor no dejó abrir.
        $this->sim['urlStatus'] = 404;
        $fallida = $this->openSession($this->tina, $version, $perfil, null, 500000)->assertStatus(502)->json('session');
        $this->voiceLab('POST', "sessions/{$fallida['uuid']}/verdict", $this->rita, ['decision' => 'approved', 'specHash' => $fallida['agent']['specHash']])
            ->assertStatus(409)->assertJsonPath('reason', 'not_ended');
        $this->sim['urlStatus'] = 200;

        // Terminada, pero con la huella de otra cosa: lo que vio no es esta sesión.
        $s = $this->endedSession($this->tina, $version, $perfil);
        $this->voiceLab('POST', "sessions/{$s['uuid']}/verdict", $this->rita, ['decision' => 'approved', 'specHash' => str_repeat('a', 64)])
            ->assertStatus(409)->assertJsonPath('code', 'voice_lab_integrity_mismatch');
        $this->assertNull(StudioVoiceLabVerdicts::latestFor($s['agent']['specHash'])['latest']);
    }

    public function test_rechazar_lleva_nota_y_manda_el_veredicto_mas_reciente(): void
    {
        $version = $this->labVersion($this->autor)->uuid;
        $perfil = $this->seedProfile($this->autor, null, 'submitted');
        $primera = $this->endedSession($this->tina, $version, $perfil);
        $huella = $primera['agent']['specHash'];

        $this->voiceLab('POST', "sessions/{$primera['uuid']}/verdict", $this->rita, ['decision' => 'approved', 'specHash' => $huella])->assertOk();
        $this->assertSame('approved', StudioVoiceLabVerdicts::latestFor($huella)['latest']);

        // La misma versión y el mismo perfil: la misma huella. El rechazo más reciente manda.
        $this->travel(1)->minutes();
        $segunda = $this->endedSession($this->tina, $version, $perfil);
        $this->assertSame($huella, $segunda['agent']['specHash']);
        $d = $this->voiceLab('POST', "sessions/{$segunda['uuid']}/verdict", $this->rita, ['decision' => 'rejected', 'note' => 'Interrumpe demasiado y no ofrece a una persona.', 'specHash' => $huella])
            ->assertOk()->json('data.verdict');
        $this->assertSame(['rejected', 'Interrumpe demasiado y no ofrece a una persona.'], [$d['decision'], $d['note']]);
        $this->assertSame(['latest' => 'rejected', 'sessionUuid' => $segunda['uuid']], array_intersect_key(StudioVoiceLabVerdicts::latestFor($huella), ['latest' => 1, 'sessionUuid' => 1]));
    }

    public function test_la_puerta_de_la_publicacion_no_cuenta_un_veredicto_del_simulador_aunque_se_escriba_a_mano(): void
    {
        // El servicio no deja juzgar una sesión del simulador; la base, sí (el
        // modo no es una columna de la sesión). La lectura de la puerta de
        // publicación tampoco se lo cree.
        $this->voiceLabFake();
        $s = $this->endedSession($this->tina);
        $this->assertSame('fake', $s['mode']);
        DB::table('ultron_studio_voice_lab_sessions')->where('uuid', $s['uuid'])->update([
            'verdict' => 'approved', 'verdict_by_admin_id' => $this->rita->id, 'verdict_by_name' => $this->rita->name, 'verdict_at' => now(),
        ]);

        $this->assertNull(StudioVoiceLabVerdicts::latestFor($s['agent']['specHash'])['latest']);
    }

    private function assertDatabaseFails(callable $write): void
    {
        try {
            DB::transaction(static fn () => $write());
        } catch (QueryException) {
            $this->addToAssertionCount(1);

            return;
        }
        $this->fail('La base dejó pasar una escritura que sus guardas prohíben.');
    }
}
