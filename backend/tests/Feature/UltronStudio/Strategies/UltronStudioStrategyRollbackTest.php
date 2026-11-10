<?php

namespace Tests\Feature\UltronStudio\Strategies;

use App\Exceptions\UltronStudioException;
use App\Models\Admin;
use App\Models\UltronStudio\StudioMessageTemplate;
use App\Models\UltronStudio\StudioStrategy;
use App\Models\UltronStudio\StudioStrategyPublication;
use App\Models\UltronStudio\StudioStrategyVersion;
use App\Services\Outreach\Core\CanonicalJson;
use App\Services\UltronStudio\StudioAccess;
use App\Services\UltronStudio\StudioAudit;
use App\Services\UltronStudio\StudioPersonBindings;
use App\Services\UltronStudio\StudioStrategies;
use App\Services\UltronStudio\StudioStrategyPublications;
use App\Services\UltronStudio\StudioStrategyPublishing;
use App\Services\UltronStudio\StudioStrategySimulations;
use App\Services\UltronStudio\StudioStrategyVersions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * T2 · CANARIO Y PROMOCIÓN, #25 · REVERSIÓN DE UNA VERSIÓN APROBADA y la parte
 * del gobierno de #23 · PAUSA (contrato H9, D9-8 «Reversión y no
 * retroactividad», D9-11.0; §13.1 #23 y #25; §13.2 T2):
 *
 *  - promover exige la evidencia medida en el servidor y su huella (sin las
 *    filas de la ejecución no pasa: falla cerrado) y cuatro ojos;
 *  - revertir la publicada la pasa a ROLLED_BACK y devuelve la anterior
 *    RETIRED a PUBLISHED con una entrada NUEVA (otra `seq`: cursor nuevo); la
 *    cadena solo crece y lo anterior no cambia; sin anterior, la estrategia se
 *    apaga;
 *  - pausar deja la estrategia en `paused` en la cadena (la ejecución, B9-run,
 *    cancela sus trabajos pendientes en la pasada siguiente) y no toca las
 *    versiones; archivar exige que no quede nada vivo.
 *
 * La evidencia REAL solo existe con envíos (W9 o asistido, §7 #14): para
 * recorrer la promoción se inyecta una evidencia que pasa (la costura de
 * pruebas, como la firma). Cero red.
 */
class UltronStudioStrategyRollbackTest extends TestCase
{
    use RefreshDatabase;
    use StrategyFixtures;

    private Admin $ana;

    private Admin $beto;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->withoutRouteMap();
        $this->grantStrategyPermissions();
        $this->strategyFlags(enabled: true, publishing: true);
        Carbon::setTestNow($this->weekdayMorning());
        [$this->ana, $this->beto] = $this->bootstrapPair();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** La publicación con una evidencia que pasa (o la que se diga). */
    private function publishing(bool $passes = true): StudioStrategyPublishing
    {
        $evidencia = static function (StudioStrategyVersion $v) use ($passes): array {
            $registro = ['format' => 'ultron-strategy-canary-evidence/1', 'version' => (string) $v->uuid, 'touches' => 3, 'passes' => $passes];

            return ['dto' => $registro, 'record' => $registro + ['evidenceHash' => CanonicalJson::hash($registro)], 'passes' => $passes, 'evidenceHash' => CanonicalJson::hash($registro)];
        };

        return new StudioStrategyPublishing(app(StudioAudit::class), app(StudioPersonBindings::class), null, $evidencia);
    }

    private static function hashFor(StudioStrategyVersion $v): string
    {
        return CanonicalJson::hash(['format' => 'ultron-strategy-canary-evidence/1', 'version' => (string) $v->uuid, 'touches' => 3, 'passes' => true]);
    }

    /** Una versión nueva de la estrategia (otra prioridad), congelada, simulada y aprobada por Beto. */
    private function approvedVersion(StudioStrategy $s, int $prioridad): StudioStrategyVersion
    {
        $t = StudioMessageTemplate::query()->where('status', 'APPROVED')->firstOrFail();
        $acceso = new StudioAccess($this->ana);
        app(StudioStrategies::class)->saveDraft($acceso, Request::create('/'), (string) $s->uuid, (int) $s->refresh()->draft_revision, $this->strategyContent($t, ['priority' => $prioridad]));
        $v = app(StudioStrategyVersions::class)->freeze($acceso, Request::create('/'), (string) $s->uuid, (string) Str::uuid())['version'];
        app(StudioStrategySimulations::class)->simulate($acceso, Request::create('/'), $s, $v, 'synthetic', ['people' => $this->syntheticPeople(), 'at' => '2026-11-17T15:00:00Z']);

        return app(StudioStrategyVersions::class)->approve(new StudioAccess($this->beto), Request::create('/'), (string) $v->uuid, $v->validation_json['warningIds'], null);
    }

    private function strategy(): StudioStrategy
    {
        $this->approvedTemplate($this->ana, $this->beto);

        return app(StudioStrategies::class)->create(new StudioAccess($this->ana), Request::create('/'), (string) Str::uuid(), 'renovacion-rb', 'Renovación (reversión)', 'commercial', null)['strategy'];
    }

    public function test_t2_promover_exige_evidencia_medida_y_su_huella_y_cuatro_ojos(): void
    {
        $s = $this->strategy();
        $v1 = $this->approvedVersion($s, 60);
        $this->s9('POST', "strategies/versions/{$v1->uuid}/publish", $this->beto, ['clientRequestId' => (string) Str::uuid(), 'expectedContentHash' => $v1->content_hash])->assertCreated();

        // La evidencia REAL, sin envíos (sin filas de la ejecución): no pasa y lo dice.
        $evidencia = $this->s9('GET', "strategies/versions/{$v1->uuid}/canary-evidence", $this->beto)->assertOk()->json('data');
        $this->assertFalse($evidencia['passes']);
        $this->assertContains('min_touches', $evidencia['failing']);
        $this->assertContains('min_hours', $evidencia['failing']);
        $this->s9('POST', "strategies/versions/{$v1->uuid}/promote", $this->beto, ['clientRequestId' => (string) Str::uuid(), 'evidenceHash' => $evidencia['evidenceHash']])
            ->assertStatus(409)->assertJsonPath('code', 'canary_evidence_insufficient');

        // Con evidencia que pasa: la huella vista tiene que ser la de ahora.
        $promover = $this->publishing();
        try {
            $promover->promote(new StudioAccess($this->beto), Request::create('/'), (string) $v1->uuid, (string) Str::uuid(), str_repeat('f', 64), null);
            $this->fail('Promovió con otra huella de evidencia.');
        } catch (UltronStudioException $e) {
            $this->assertSame('evidence_changed', $e->errorCode);
        }
        $r = $promover->promote(new StudioAccess($this->beto), Request::create('/'), (string) $v1->uuid, (string) Str::uuid(), self::hashFor($v1), null);
        $this->assertSame(['canary' => null, 'rest' => ['contentHash' => $v1->content_hash, 'version' => $v1->uuid], 'status' => 'active'], $r['entry']['state']);
        $this->assertSame('PUBLISHED', $v1->refresh()->status);
        $this->assertSame('ok', $r['entry']['verified']);
        $fila = StudioStrategyPublication::query()->where('action', 'promote')->firstOrFail();
        $this->assertSame('required', $fila->simulation_gate);
        $this->assertSame(true, $fila->evidence['passes']);
    }

    public function test_25_revertir_la_publicada_vuelve_a_la_anterior_con_una_entrada_nueva_y_la_cadena_solo_crece(): void
    {
        $s = $this->strategy();
        $promover = $this->publishing();
        $beto = new StudioAccess($this->beto);

        $v1 = $this->approvedVersion($s, 60);
        $promover->publish($beto, Request::create('/'), (string) $v1->uuid, (string) Str::uuid(), (string) $v1->content_hash, null);
        $promover->promote($beto, Request::create('/'), (string) $v1->uuid, (string) Str::uuid(), self::hashFor($v1), null);
        $v2 = $this->approvedVersion($s, 70);
        $promover->publish($beto, Request::create('/'), (string) $v2->uuid, (string) Str::uuid(), (string) $v2->content_hash, null);
        $promover->promote($beto, Request::create('/'), (string) $v2->uuid, (string) Str::uuid(), self::hashFor($v2), null);
        $this->assertSame(['RETIRED', 'PUBLISHED'], [$v1->refresh()->status, $v2->refresh()->status]);

        $antes = StudioStrategyPublication::query()->orderBy('seq')->get(['seq', 'effective_hash', 'signature'])->toArray();
        $r = $this->s9('POST', "strategies/{$s->uuid}/rollback", $this->ana, ['clientRequestId' => (string) Str::uuid(), 'reason' => 'La versión 2 no convence', 'part' => 'rest'])
            ->assertCreated()->json('data');

        // La revertida, ROLLED_BACK (final); la anterior, PUBLISHED otra vez con una entrada NUEVA.
        $this->assertSame(['PUBLISHED', 'ROLLED_BACK'], [$v1->refresh()->status, $v2->refresh()->status]);
        $this->assertSame(['canary' => null, 'rest' => ['contentHash' => $v1->content_hash, 'version' => $v1->uuid], 'status' => 'active'], $r['state']);
        $this->assertSame(count($antes) + 1, $r['seq']);
        // La cadena solo crece: lo anterior, byte a byte, igual; y verifica entera.
        $this->assertSame($antes, StudioStrategyPublication::query()->where('seq', '<=', count($antes))->orderBy('seq')->get(['seq', 'effective_hash', 'signature'])->toArray());
        $this->assertSame('ok', StudioStrategyPublications::verifyChain()['result']);

        // Revertir otra vez la publicada (v1, sin anterior íntegra que no esté revertida): la estrategia se apaga.
        $r2 = $this->s9('POST', "strategies/{$s->uuid}/rollback", $this->ana, ['clientRequestId' => (string) Str::uuid(), 'reason' => 'Se apaga la estrategia'])->assertCreated()->json('data');
        $this->assertSame(['canary' => null, 'rest' => null, 'status' => 'off'], $r2['state']);
        $this->assertSame('ROLLED_BACK', $v1->refresh()->status);
        // Ya no hay nada que revertir.
        $this->s9('POST', "strategies/{$s->uuid}/rollback", $this->ana, ['clientRequestId' => (string) Str::uuid(), 'reason' => 'Otra vez'])
            ->assertStatus(409)->assertJsonPath('code', 'nothing_to_rollback');
        // Una revertida no vuelve a publicarse.
        $this->s9('POST', "strategies/versions/{$v2->uuid}/publish", $this->beto, ['clientRequestId' => (string) Str::uuid(), 'expectedContentHash' => $v2->content_hash])
            ->assertStatus(409)->assertJsonPath('code', 'invalid_transition');
    }

    public function test_23_pausar_deja_la_estrategia_en_pausa_en_la_cadena_sin_tocar_las_versiones_y_archivar_exige_que_no_quede_nada_vivo(): void
    {
        $s = $this->strategy();
        $v1 = $this->approvedVersion($s, 60);
        $this->s9('POST', "strategies/versions/{$v1->uuid}/publish", $this->beto, ['clientRequestId' => (string) Str::uuid(), 'expectedContentHash' => $v1->content_hash])->assertCreated();

        // Pausar exige motivo y lo puede hacer quien revierte (Administrador incluido), también la autora.
        $this->s9('POST', "strategies/{$s->uuid}/pause", $this->ana, ['clientRequestId' => (string) Str::uuid()])->assertStatus(422);
        $p = $this->s9('POST', "strategies/{$s->uuid}/pause", $this->ana, ['clientRequestId' => (string) Str::uuid(), 'reason' => 'Cancelación inmediata de la campaña'])
            ->assertCreated()->json('data');
        $this->assertSame('paused', $p['state']['status']);
        $this->assertSame(['contentHash' => $v1->content_hash, 'version' => $v1->uuid], $p['state']['canary']);
        $this->assertSame('CANARY', $v1->refresh()->status, 'pausar no cambia las versiones: lo dice la cadena');
        $this->assertSame('paused', StudioStrategyPublications::stateOf($s)['status']);
        // En pausa no se publica otra ni se archiva; pausar otra vez no vale.
        $this->s9('POST', "strategies/{$s->uuid}/pause", $this->ana, ['clientRequestId' => (string) Str::uuid(), 'reason' => 'Otra pausa'])->assertStatus(409)->assertJsonPath('code', 'strategy_not_active');
        $this->s9('POST', "strategies/{$s->uuid}/archive", $this->ana)->assertStatus(409)->assertJsonPath('code', 'strategy_live');
        // Revertir la canaria con la estrategia en pausa: queda apagada; entonces sí se archiva.
        $this->s9('POST', "strategies/{$s->uuid}/rollback", $this->ana, ['clientRequestId' => (string) Str::uuid(), 'reason' => 'Se retira la canaria'])->assertCreated()
            ->assertJsonPath('data.state.status', 'off');
        $this->s9('POST', "strategies/{$s->uuid}/archive", $this->ana)->assertOk();
        // Archivada: ni borrador, ni versiones nuevas, ni reanudar.
        $this->s9('PUT', "strategies/{$s->uuid}/draft", $this->ana, ['baseRevision' => (int) $s->refresh()->draft_revision, 'draft' => ['goal' => 'renew']])
            ->assertStatus(409)->assertJsonPath('code', 'strategy_archived');
        $this->s9('POST', "strategies/{$s->uuid}/versions", $this->ana, ['clientRequestId' => (string) Str::uuid()])->assertStatus(409)->assertJsonPath('code', 'strategy_archived');
    }
}
