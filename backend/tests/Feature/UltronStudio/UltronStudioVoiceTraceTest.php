<?php

namespace Tests\Feature\UltronStudio;

use App\Models\Admin;
use App\Models\UltronStudio\StudioAuditEvent;
use App\Models\UltronStudio\StudioVersion;
use App\Models\UltronStudio\StudioVoiceAgent;
use App\Models\UltronStudio\StudioVoiceProfile;
use App\Models\UltronStudio\StudioVoicePublication;
use App\Services\UltronStudio\StudioVoiceCompiler;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TRAZABILIDAD DE LAS PUBLICACIONES DE VOZ EN EL LIBRO DEL STUDIO (S8-d). Cada
 * entrada de la cadena (publicar, promover, revertir, apagar) deja su evento
 * con el ACTOR, la HUELLA de la especificación y la VERSIÓN y el PERFIL (uuid y
 * huella de cada uno) del agente que entra o, al apagar, de los que salen. Y el
 * libro lo enseña por su API a quien tiene `ultron.view`, sin teléfonos ni el
 * id del proveedor. (El laboratorio: `UltronStudioVoiceLabTraceTest`.)
 */
class UltronStudioVoiceTraceTest extends TestCase
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
        $this->bindFake(fn (): array => ['calls' => 3, 'guardViolations' => 0, 'sealMismatches' => 0, 'driftEvents' => 0, 'unknownProviderCalls' => 0, 'budgetBreaches' => 0, 'missingPostCall' => 0]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** @return array{0:StudioVersion,1:StudioVoiceProfile} */
    private function ready(string $etiqueta, ?array $contenido = null): array
    {
        $v = $this->approvedVersion($this->ana, $this->root, "Versión {$etiqueta}");
        $p = $this->approvedProfile($this->ana, $this->root, $contenido, "Perfil {$etiqueta}");
        $this->labVerdictFor($v, $p, $this->ana, $this->root);

        return [$v, $p];
    }

    /** La traza que tiene que llevar el evento de un agente (versión, perfil y compilación). */
    private static function traceOf(StudioVersion $v, StudioVoiceProfile $p): array
    {
        return [
            'specHash' => self::specHashOf($v, $p),
            'compilerVersion' => StudioVoiceCompiler::VERSION,
            'version' => (string) $v->uuid,
            'versionHash' => (string) $v->content_hash,
            'profile' => (string) $p->uuid,
            'profileHash' => (string) $p->content_hash,
        ];
    }

    private function assertTrace(StudioAuditEvent $e, Admin $actor, StudioVersion $v, StudioVoiceProfile $p): void
    {
        $this->assertSame(['admin', $actor->id, $actor->name], [$e->actor_type, $e->actor_admin_id, $e->actor_name], $e->event);
        $this->assertSame('integration', $e->subject_type, $e->event);
        $this->assertSame((string) $v->content_hash, $e->content_hash, $e->event);
        $this->assertSame(self::traceOf($v, $p), array_intersect_key($e->diff, self::traceOf($v, $p)), $e->event);
    }

    public function test_publicar_promover_revertir_y_apagar_dejan_actor_huella_version_y_perfil(): void
    {
        [$v, $p] = $this->ready('uno');
        [$v2, $p2] = $this->ready('dos', self::profileContent(['maxDurationSeconds' => 240]));

        Carbon::setTestNow(Carbon::parse('2026-11-09T14:00:00Z'));
        $this->freshSessions();
        $this->publishVoice($this->pablo, $v, $p)->assertCreated();
        $publicado = StudioAuditEvent::query()->where('event', 'voice_publication.published')->sole();
        $this->assertTrace($publicado, $this->pablo, $v, $p);
        $entrada = StudioVoicePublication::query()->where('seq', 1)->sole();
        $this->assertSame([(string) $entrada->uuid, 1, (string) StudioVoiceAgent::query()->where('serves_canary', true)->value('uuid'), 'required', 'assigned'],
            [$publicado->subject_uuid, $publicado->diff['seq'], $publicado->diff['agent'], $publicado->diff['labGate'], $publicado->diff['number']]);

        Carbon::setTestNow(Carbon::parse('2026-11-10T15:00:00Z'));
        $this->freshSessions();
        $e = $this->voice('GET', 'canary-evidence', $this->paula)->assertOk()->json('data');
        $this->promoteVoice($this->paula, $e['evidenceHash'])->assertCreated();
        $promovido = StudioAuditEvent::query()->where('event', 'voice_publication.promoted')->sole();
        $this->assertTrace($promovido, $this->paula, $v, $p);
        $this->assertSame($e['evidenceHash'], $promovido->diff['evidenceHash']);

        $this->publishVoice($this->pablo, $v2, $p2)->assertCreated();
        $this->voice('POST', 'rollback', $this->pablo, ['clientRequestId' => (string) Str::uuid(), 'scope' => 'canary', 'reason' => 'La nueva voz falla'])->assertCreated();
        // Revertir deja la traza del agente que VUELVE (el de la versión uno).
        $this->assertTrace(StudioAuditEvent::query()->where('event', 'voice_publication.rolled_back')->sole(), $this->pablo, $v, $p);

        $this->voice('POST', 'off', $this->pablo, ['clientRequestId' => (string) Str::uuid(), 'reason' => 'Apagar la voz'])->assertCreated();
        $apagado = StudioAuditEvent::query()->where('event', 'voice_publication.off')->sole();
        $this->assertSame([$this->pablo->id, ['canary', 'rest']], [$apagado->actor_admin_id, $apagado->diff['parts']]);
        // Lo que se apaga: cada parte con la traza de su agente (los dos son el de la versión uno).
        $this->assertSame(['canary', 'rest'], array_column($apagado->diff['turnedOff'], 'part'));
        foreach ($apagado->diff['turnedOff'] as $parte) {
            $this->assertSame(self::traceOf($v, $p), array_intersect_key($parte, self::traceOf($v, $p)), $parte['part']);
        }

        // El libro lo enseña por su API a quien ve el Studio, sin el id del proveedor.
        $libro = $this->studio('GET', 'audit?per_page=50', $this->personWith(['ultron.view'], 'Solo Ve'))->assertOk();
        $eventos = array_column($libro->json('data.data') ?? $libro->json('data'), 'event');
        foreach (['voice_publication.published', 'voice_publication.promoted', 'voice_publication.rolled_back', 'voice_publication.off'] as $evento) {
            $this->assertContains($evento, $eventos);
        }
        $this->assertStringNotContainsString('agent_h8s_', $libro->getContent());
    }
}
