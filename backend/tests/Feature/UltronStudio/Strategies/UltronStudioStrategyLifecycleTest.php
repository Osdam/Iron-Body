<?php

namespace Tests\Feature\UltronStudio\Strategies;

use App\Models\UltronStudio\StudioMessageTemplate;
use App\Models\UltronStudio\StudioStrategyPublication;
use App\Models\UltronStudio\StudioStrategyVersion;
use App\Services\UltronStudio\StudioStrategyPublications;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * EL CICLO COMPLETO por la API (contrato H9, D9-11.0; §13.1 #26, la parte de
 * la API: «una estrategia nueva desde el CRM sin tocar el motor»): crear la
 * estrategia, la plantilla a cuatro ojos, el borrador, validar, congelar,
 * simular la versión, aprobar (otra persona), publicar en canario, pausar,
 * reanudar y revertir; la cadena solo crece y verifica entera. Cero red.
 */
class UltronStudioStrategyLifecycleTest extends TestCase
{
    use RefreshDatabase;
    use StrategyFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->withoutRouteMap();
        $this->grantStrategyPermissions();
        $this->strategyFlags(enabled: true, publishing: true);
        Carbon::setTestNow($this->weekdayMorning());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_una_estrategia_nueva_recorre_todo_el_gobierno_por_la_api_sin_codigo_nuevo(): void
    {
        [$ana, $beto] = $this->bootstrapPair();

        // La plantilla: la crea Ana y la aprueba Beto (otra persona).
        $plantilla = $this->s9('POST', 'strategies/templates', $ana, [
            'clientRequestId' => (string) Str::uuid(), 'key' => 'guion-renovacion', 'channel' => 'assisted', 'purpose' => 'commercial',
            'content' => ['medium' => 'whatsapp', 'script' => 'Saluda, recuerda que su plan vence pronto y ofrece ayuda para renovarlo.'],
        ])->assertCreated()->json('data');
        $this->assertSame('DRAFT', $plantilla['status']);
        $this->s9('POST', "strategies/templates/{$plantilla['uuid']}/approve", $ana, [])->assertStatus(403)->assertJsonPath('code', 'four_eyes_required');
        $this->s9('POST', "strategies/templates/{$plantilla['uuid']}/approve", $beto, [])->assertOk()->assertJsonPath('data.status', 'APPROVED');
        $t = StudioMessageTemplate::query()->where('uuid', $plantilla['uuid'])->firstOrFail();

        // La estrategia y su borrador.
        $s = $this->s9('POST', 'strategies', $ana, ['clientRequestId' => (string) Str::uuid(), 'key' => 'renovacion-7d', 'name' => 'Renovación a 7 días', 'purpose' => 'commercial'])
            ->assertCreated()->json('data');
        $this->assertSame(1, $s['draft']['revision']);
        $this->s9('PUT', "strategies/{$s['uuid']}/draft", $ana, ['baseRevision' => 1, 'draft' => $this->strategyContent($t)])->assertOk()->assertJsonPath('data.draft.revision', 2);
        // Otra pestaña con la revisión vieja: 409 draft_moved.
        $this->s9('PUT', "strategies/{$s['uuid']}/draft", $ana, ['baseRevision' => 1, 'draft' => $this->strategyContent($t)])->assertStatus(409)->assertJsonPath('code', 'draft_moved');

        $validacion = $this->s9('POST', "strategies/{$s['uuid']}/validate", $ana, [])->assertOk()->json('data');
        $this->assertTrue($validacion['valid']);
        $this->assertSame([], $validacion['blocking']);
        $this->assertContains('canary_only_declared@content.studio', $validacion['warningIds']);

        // Congelar: REVIEW_REQUIRED (tiene avisos).
        $v = $this->s9('POST', "strategies/{$s['uuid']}/versions", $ana, ['clientRequestId' => (string) Str::uuid()])->assertCreated()->json('data');
        $this->assertSame('REVIEW_REQUIRED', $v['status']);
        $this->assertSame(1, $v['number']);

        // Aprobar sin simulación de la versión: 409 simulation_required.
        $this->s9('POST', "strategies/versions/{$v['uuid']}/approve", $beto, ['acknowledgedWarnings' => $v['validation']['warningIds']])
            ->assertStatus(409)->assertJsonPath('code', 'simulation_required');

        $sim = $this->s9('POST', "strategies/versions/{$v['uuid']}/simulations", $ana, [
            'kind' => 'synthetic', 'at' => '2026-11-17T15:00:00Z', 'people' => $this->syntheticPeople(),
        ])->assertCreated()->json('data');
        $this->assertSame(0, $sim['blockingFindings'], json_encode($sim['findings']));
        $this->assertSame('plan', $sim['people'][0]['decision']['kind'], json_encode($sim['people'][0]['text']));
        $this->assertSame('exclude', $sim['people'][1]['decision']['kind']);

        // La autora no aprueba; con avisos sin reconocer, tampoco; Beto, sí.
        $this->s9('POST', "strategies/versions/{$v['uuid']}/approve", $ana, ['acknowledgedWarnings' => $v['validation']['warningIds']])->assertStatus(403);
        $this->s9('POST', "strategies/versions/{$v['uuid']}/approve", $beto, ['acknowledgedWarnings' => []])->assertStatus(409)->assertJsonPath('code', 'warnings_not_acknowledged');
        $this->s9('POST', "strategies/versions/{$v['uuid']}/approve", $beto, ['acknowledgedWarnings' => $v['validation']['warningIds']])
            ->assertOk()->assertJsonPath('data.status', 'APPROVED');

        // Publicar: la autora no; Beto sí, con la huella que vio.
        $this->s9('POST', "strategies/versions/{$v['uuid']}/publish", $ana, ['clientRequestId' => (string) Str::uuid(), 'expectedContentHash' => $v['contentHash']])->assertStatus(403);
        $llave = (string) Str::uuid();
        $pub = $this->s9('POST', "strategies/versions/{$v['uuid']}/publish", $beto, ['clientRequestId' => $llave, 'expectedContentHash' => $v['contentHash']])
            ->assertCreated()->json('data');
        $this->assertSame('ok', $pub['verified']);
        $this->assertSame(['canary' => ['contentHash' => $v['contentHash'], 'version' => $v['uuid']], 'rest' => null, 'status' => 'active'], $pub['state']);
        // El reintento con la misma llave devuelve lo mismo.
        $this->s9('POST', "strategies/versions/{$v['uuid']}/publish", $beto, ['clientRequestId' => $llave, 'expectedContentHash' => $v['contentHash']])
            ->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('data.seq', $pub['seq']);
        $this->assertSame('CANARY', StudioStrategyVersion::query()->where('uuid', $v['uuid'])->value('status'));

        // Pausar (siempre, con motivo), reanudar (bandera y ventana) y revertir la canaria.
        $this->s9('POST', "strategies/{$s['uuid']}/pause", $ana, ['clientRequestId' => (string) Str::uuid(), 'reason' => 'Revisión del texto del guion'])
            ->assertCreated()->assertJsonPath('data.state.status', 'paused');
        $this->s9('POST', "strategies/{$s['uuid']}/resume", $beto, ['clientRequestId' => (string) Str::uuid()])->assertCreated()->assertJsonPath('data.state.status', 'active');
        $this->s9('POST', "strategies/{$s['uuid']}/rollback", $ana, ['clientRequestId' => (string) Str::uuid(), 'reason' => 'Se vuelve atrás la canaria'])
            ->assertCreated()->assertJsonPath('data.state.status', 'off');
        $this->assertSame('ROLLED_BACK', StudioStrategyVersion::query()->where('uuid', $v['uuid'])->value('status'));

        // La cadena: 4 entradas seguidas, verifica entera, y el historial dice `ok` en cada una.
        $this->assertSame(['publish', 'pause', 'resume', 'rollback'], StudioStrategyPublication::query()->orderBy('seq')->pluck('action')->all());
        $this->assertSame(['result' => 'ok', 'seq' => null, 'verified' => 4], StudioStrategyPublications::verifyChain());
        $historial = $this->s9('GET', "strategies/{$s['uuid']}/publications", $ana)->assertOk()->json('data.data');
        $this->assertSame(['ok', 'ok', 'ok', 'ok'], array_column($historial, 'verified'));

        // Archivar, ya sin versiones vivas.
        $this->s9('POST', "strategies/{$s['uuid']}/archive", $ana)->assertOk()->assertJsonPath('data.archived', true);

        // El libro lo cuenta todo, sin datos personales.
        $eventos = DB::table('ultron_studio_audit_events')->whereIn('subject_type', ['strategy', 'strategy_version', 'message_template', 'person_binding'])->pluck('event')->all();
        foreach (['strategy.created', 'strategy.draft_saved', 'strategy_version.created', 'strategy_version.simulated', 'strategy_version.approved',
            'strategy_version.published', 'strategy.paused', 'strategy.resumed', 'strategy_version.rolled_back', 'strategy.archived',
            'message_template.created', 'message_template.approved', 'person_binding.bootstrapped'] as $evento) {
            $this->assertContains($evento, $eventos, "falta {$evento} en el libro");
        }
    }
}
