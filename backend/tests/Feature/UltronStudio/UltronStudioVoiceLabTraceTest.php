<?php

namespace Tests\Feature\UltronStudio;

use App\Models\Admin;
use App\Models\UltronStudio\StudioAuditEvent;
use App\Models\UltronStudio\StudioVersion;
use App\Services\UltronStudio\StudioVoiceCompiler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TRAZABILIDAD DEL LABORATORIO HABLADO EN EL LIBRO DEL STUDIO (S8-d). Antes,
 * abrir una sesión o dar un veredicto no dejaba nada en el libro: solo las
 * filas del laboratorio. Ahora abrir deja `voice_lab.session_opened` (en la
 * misma transacción que la reserva) y el veredicto, `voice_lab.verdict_recorded`,
 * con el ACTOR, la HUELLA de la especificación y la VERSIÓN y el PERFIL (uuid y
 * huella). Nunca la URL firmada, un teléfono ni la nota del veredicto.
 */
class UltronStudioVoiceLabTraceTest extends TestCase
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

    /** La traza esperada de la versión y el perfil de una sesión. */
    private static function traceOf(StudioVersion $v, string $profileUuid, string $specHash): array
    {
        $perfil = DB::table('ultron_studio_voice_profiles')->where('uuid', $profileUuid)->first(['uuid', 'content_hash']);

        return [
            'specHash' => $specHash,
            'compilerVersion' => StudioVoiceCompiler::VERSION,
            'version' => (string) $v->uuid,
            'versionHash' => (string) $v->content_hash,
            'profile' => (string) $perfil->uuid,
            'profileHash' => (string) $perfil->content_hash,
        ];
    }

    public function test_abrir_una_sesion_deja_actor_huella_version_y_perfil_en_el_libro_y_la_repeticion_no(): void
    {
        $this->voiceLabFake();
        $tina = $this->tester();
        $ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana Autora');
        $version = $this->labVersion($ana);
        $perfil = $this->seedProfile($ana, null, 'submitted');
        $peticion = '6a9b0c1d-2e3f-4a5b-8c6d-7e8f9a0b1c2d';

        $d = $this->openSession($tina, $version->uuid, $perfil, $peticion, 0)->assertCreated()->json('data');
        $e = StudioAuditEvent::query()->where('event', 'voice_lab.session_opened')->sole();
        $this->assertSame(['admin', $tina->id, $tina->name, 'integration', $d['uuid'], 'open', (string) $version->content_hash],
            [$e->actor_type, $e->actor_admin_id, $e->actor_name, $e->subject_type, $e->subject_uuid, $e->to_status, $e->content_hash]);
        $this->assertSame(self::traceOf($version, $perfil, $d['agent']['specHash']) + ['provider' => 'fake', 'reservedMicros' => 0, 'maxSeconds' => 180, 'transferDestinations' => 0], $e->diff);

        // La misma petición otra vez: la misma sesión y ningún evento más.
        $this->openSession($tina, $version->uuid, $perfil, $peticion, 0)->assertOk()->assertJsonPath('replayed', true);
        $this->assertSame(1, StudioAuditEvent::query()->where('event', 'voice_lab.session_opened')->count());

        // Una sesión que no se abre (la reserva o el compilador la paran) no deja evento.
        $this->openSession($tina, $version->uuid, $this->seedProfile($ana, null, 'draft'), null, 0)->assertStatus(422);
        $this->assertSame(1, StudioAuditEvent::query()->where('event', 'voice_lab.session_opened')->count());
    }

    public function test_con_elevenlabs_el_libro_no_lleva_la_url_y_el_veredicto_deja_el_suyo_sin_la_nota(): void
    {
        $this->voiceLabElevenLabs();
        $this->voiceVerified();
        $this->fakeElevenLabs();
        $autor = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root Autor');
        $tina = $this->tester();
        $rita = $this->reviewer();
        $version = $this->labVersion($autor);
        $perfil = $this->seedProfile($autor, null, 'submitted');

        $abierta = $this->openSession($tina, $version->uuid, $perfil, null, 500000)->assertCreated();
        $uuid = (string) $abierta->json('data.uuid');
        $spec = (string) $abierta->json('data.agent.specHash');
        $abrio = StudioAuditEvent::query()->where('event', 'voice_lab.session_opened')->sole();
        $this->assertSame(['elevenlabs', 300000], [$abrio->diff['provider'], $abrio->diff['reservedMicros']]);
        $this->assertStringNotContainsString(self::URL_SIGNATURE, (string) json_encode(DB::table('ultron_studio_audit_events')->get()->all()), 'la URL firmada no va al libro');

        $this->voiceLab('POST', "sessions/{$uuid}/end", $tina, self::observations())->assertOk();
        $nota = 'No dijo el aviso completo; llamar al 3001234567 para repetir.';
        $this->voiceLab('POST', "sessions/{$uuid}/verdict", $rita, ['decision' => 'rejected', 'note' => $nota, 'specHash' => $spec])->assertOk();

        $veredicto = StudioAuditEvent::query()->where('event', 'voice_lab.verdict_recorded')->sole();
        $this->assertSame(['admin', $rita->id, $rita->name, 'integration', $uuid, 'rejected', (string) $version->content_hash],
            [$veredicto->actor_type, $veredicto->actor_admin_id, $veredicto->actor_name, $veredicto->subject_type, $veredicto->subject_uuid, $veredicto->to_status, $veredicto->content_hash]);
        $this->assertSame(['verdict' => 'rejected'] + self::traceOf($version, $perfil, $spec) + ['hasNote' => true], $veredicto->diff);
        // La nota es texto libre: el libro, que no se vacía, no la copia.
        $todo = (string) json_encode(DB::table('ultron_studio_audit_events')->get()->all(), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('3001234567', $todo);
        $this->assertStringNotContainsString('No dijo el aviso completo', $todo);

        // Un veredicto rechazado (el segundo) no deja otro evento.
        $this->voiceLab('POST', "sessions/{$uuid}/verdict", $rita, ['decision' => 'approved', 'specHash' => $spec])->assertStatus(409);
        $this->assertSame(1, StudioAuditEvent::query()->where('event', 'voice_lab.verdict_recorded')->count());
    }
}
