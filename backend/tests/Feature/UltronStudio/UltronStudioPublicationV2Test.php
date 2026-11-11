<?php

namespace Tests\Feature\UltronStudio;

use App\Models\Admin;
use App\Models\UltronStudio\StudioVersion;
use App\Services\Marketing\Ultron\Prompts\UltronPromptOffer;
use App\Services\UltronStudio\StudioPublicationDeclaration as D;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * E11 POR HTTP, DEL TESTER (Enmienda M-1; plan de motores §B.4, M1-12; §6 del
 * informe de B-S). Las huellas de paquete esperadas NO salen del Studio: salen
 * de `tests/fixtures/ultron-prompts/compile-cases.json`, la derivación
 * independiente del contrato.
 *
 *  - `previewHash` ata el paquete: con la llave encendida cambia frente a la de
 *    la llave apagada aunque la clasificación sea idéntica, y es estable;
 *  - `previewHash` ata la parte del resto que se arrastra (M1-12): con la misma
 *    versión, otra cabeza da otra huella con la llave encendida (y la misma con
 *    la llave apagada, Q-4); publicar con la huella vieja es `preview_stale`;
 *  - la tabla de cambios de la vista previa son los parches del contrato;
 *  - promover con la llave encendida sobre una cabeza `/1` escribe `/1` (nadie
 *    vio un paquete: la canaria vuelve a Legacy, `declaration_v1`); sobre una
 *    `/2`, sigue en `/2` con la misma parte.
 */
class UltronStudioPublicationV2Test extends TestCase
{
    use PublicationFixtures;
    use RefreshDatabase;
    use StudioFixtures;

    private const COMPILE_CASES = __DIR__.'/../../fixtures/ultron-prompts/compile-cases.json';

    private Admin $ana;

    private Admin $root;

    private Admin $pub;

    private Admin $pro;

    /** @var array<string,mixed>|null */
    private static ?array $compile = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->importBaseline();
        $this->openKeys();
        $this->ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana Autora');
        $this->root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root Revisa');
        $this->pub = $this->personWith(['ultron.view', 'ultron.publish', 'ultron.rollback'], 'Pablo Publica');
        $this->pro = $this->personWith(['ultron.view', 'ultron.publish'], 'Paula Promueve');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_la_huella_de_la_vista_previa_ata_el_paquete_y_es_estable(): void
    {
        $base = $this->approvedVersion($this->ana, $this->root, 'Línea base', ['persona' => ['tone' => 'cercano', 'maxChars' => 320]]);

        $apagada = $this->previewOf($this->pub, $base->uuid);
        $this->assertSame(['declaration', null], [$apagada['effect'], $apagada['prompts']]);

        $this->promptsOn();
        $encendida = $this->previewOf($this->pub, $base->uuid);
        $this->assertSame('prompts', $encendida['effect']);
        $this->assertSame([$this->bundleOf('cercano', true, 320, 'cita'), true], [$encendida['prompts']['bundleHash'], $encendida['prompts']['baseline']], 'la línea base compila al paquete Legacy');
        // Misma clasificación con y sin llave (la línea base no tiene filas `prompt`)…
        $this->assertSame([$apagada['noEffect'], $apagada['params']], [$encendida['noEffect'], $encendida['params']]);
        // …y aun así otra huella: lo único que cambia es el paquete.
        $this->assertNotSame($apagada['previewHash'], $encendida['previewHash'], 'previewHash no ata el paquete');
        $this->assertSame($encendida['previewHash'], $this->previewOf($this->pub, $base->uuid)['previewHash'], 'previewHash no es estable');
    }

    public function test_la_tabla_de_cambios_de_la_vista_previa_son_los_parches_del_contrato(): void
    {
        $this->promptsOn();
        $v = $this->approvedVersion($this->ana, $this->root, 'G8', ['persona' => ['tone' => 'profesional', 'maxChars' => 200]]);
        $caso = $this->compileCase('profesional', true, 200, 'cita');

        $p = $this->previewOf($this->pub, $v->uuid)['prompts'];
        $this->assertSame([$caso['expect']['bundleHash'], $caso['expect']['bytes'], false], [$p['bundleHash'], $p['bytes'], $p['baseline']]);
        $this->assertSame(['persona.tone', 'persona.maxChars'], array_column($p['changes'], 'param'), 'en el orden de aplicación');

        $textos = self::compile()['texts'];
        $esperado = [];
        foreach ($caso['expect']['patches'] as [$rol, $operacion, $ancla, $clave]) {
            $esperado[$rol][] = [$operacion, $ancla, $textos[$clave]['text']];
        }
        $obtenido = [];
        foreach ($p['changes'] as $cambio) {
            foreach ($cambio['patches'] as $x) {
                $obtenido[$x['role']][] = [$x['operation'], $x['anchor'], $x['text']];
            }
        }
        ksort($esperado);
        ksort($obtenido);
        $this->assertSame($esperado, $obtenido, 'la vista previa no enseña los parches del contrato');
    }

    public function test_la_huella_de_la_vista_previa_ata_la_parte_del_resto_que_se_arrastra(): void
    {
        $p1 = $this->seededVersion($this->ana, $this->root, $this->contentWith(['persona' => ['tone' => 'entusiasta']]), 'PUBLISHED');
        $this->seedEntry(D::ACTION_PROMOTE, $p1, $this->partOf($p1), $this->partOf($p1));
        $v = $this->approvedVersion($this->ana, $this->root, 'G8', ['persona' => ['tone' => 'profesional', 'maxChars' => 200]]);

        $apagada1 = $this->previewOf($this->pub, $v->uuid)['previewHash'];
        $this->promptsOn();
        $vista1 = $this->previewOf($this->pub, $v->uuid);
        $this->assertSame([$p1->uuid, $this->bundleOf('entusiasta', true, 320, 'cita')], [$vista1['prompts']['carried']['versionUuid'], $vista1['prompts']['carried']['bundleHash']]);

        // Otra cabeza: el resto es ahora OTRA versión publicada (sin emojis).
        $this->assertTrue(StudioVersion::transition($p1->id, ['PUBLISHED'], ['status' => 'RETIRED', 'status_changed_at' => now()]));
        $p2 = $this->seededVersion($this->ana, $this->root, $this->contentWith(['persona' => ['emojis' => false]]), 'PUBLISHED');
        $this->seedEntry(D::ACTION_PROMOTE, $p2, $this->partOf($p2), $this->partOf($p2));

        $vista2 = $this->previewOf($this->pub, $v->uuid);
        $this->assertSame([$p2->uuid, $this->bundleOf('cercano', false, 320, 'cita')], [$vista2['prompts']['carried']['versionUuid'], $vista2['prompts']['carried']['bundleHash']]);
        $this->assertSame($vista1['prompts']['bundleHash'], $vista2['prompts']['bundleHash'], 'el paquete de la versión no cambia');
        $this->assertNotSame($vista1['previewHash'], $vista2['previewHash'], 'previewHash no ata la parte arrastrada (M1-12)');

        // Con la llave apagada, la huella de Q-4 no depende de la cabeza.
        config(['marketing.ultron.studio_prompts' => 'off']);
        $this->assertSame($apagada1, $this->previewOf($this->pub, $v->uuid)['previewHash']);

        // Quien vio arrastrar P1 no puede publicar arrastrando P2.
        $this->promptsOn();
        $this->publishAs($this->pub, $v->uuid, null, ['previewHash' => $vista1['previewHash'], 'acknowledgedNoEffect' => $vista1['noEffect']])
            ->assertStatus(409)->assertJsonPath('code', 'preview_stale');
        $this->assertSame('APPROVED', $v->fresh()->status);
    }

    public function test_promover_con_la_llave_encendida_sobre_una_cabeza_v1_escribe_v1(): void
    {
        $v = $this->publishedInCanary(promptsWhenPublishing: false);
        $this->assertSame(D::CONTRACT, $this->chainHead()->engine_contract);

        $this->promptsOn();
        $this->promote($v);

        $cabeza = $this->headEntry();
        $this->assertSame([D::CONTRACT, D::CONTRACT], [$cabeza['column'], $cabeza['contract']], 'promover no da efecto a un paquete que nadie vio');
        $this->assertSame([$v->uuid, $v->uuid], [$cabeza['canary']['version'], $cabeza['rest']['version']]);
        $this->assertFalse(D::isPartV2($cabeza['canary']));
        $this->assertSame('PUBLISHED', $v->fresh()->status);
        $this->assertChainMatchesStates();
        $this->assertSame(['legacy', 'declaration_v1'], $this->decideOffer(), 'la canaria vuelve a los prompts Legacy hasta otra publicación');
    }

    public function test_promover_con_la_llave_encendida_sobre_una_cabeza_v2_sigue_en_v2_con_la_misma_parte(): void
    {
        $v = $this->publishedInCanary(promptsWhenPublishing: true);
        $publicada = $this->headEntry();
        $this->assertSame(D::CONTRACT_V2, $publicada['column']);
        $this->assertSame($this->bundleOf('profesional', true, 200, 'cita'), $publicada['canary']['prompts']['bundleHash']);

        $this->promote($v);

        $cabeza = $this->headEntry();
        $this->assertSame([D::CONTRACT_V2, D::CONTRACT_V2], [$cabeza['column'], $cabeza['contract']]);
        $this->assertSame($publicada['canary'], $cabeza['canary'], 'la MISMA parte (Q-3), con su paquete');
        $this->assertSame($publicada['canary'], $cabeza['rest']);
        $this->assertSame('PUBLISHED', $v->fresh()->status);
        $this->assertChainMatchesStates();
        $this->assertSame(['studio', null], $this->decideOffer());
    }

    // ── Piezas ──────────────────────────────────────────────────────────────

    private function promptsOn(): void
    {
        config(['marketing.ultron.studio_prompts' => 'canary']);
    }

    /** G8 publicada en canario (con o sin la llave de prompts), con 3 turnos y 25 h de canario: lista para promover. */
    private function publishedInCanary(bool $promptsWhenPublishing): StudioVersion
    {
        self::at('2026-11-03T15:00:00Z');
        $this->openKeys(['marketing.ultron.studio_config' => 'all', 'marketing.ultron.studio_prompts' => $promptsWhenPublishing ? 'canary' : 'off']);
        $v = $this->approvedVersion($this->ana, $this->root, 'G8', ['persona' => ['tone' => 'profesional', 'maxChars' => 200]]);
        $this->publishAs($this->pub, $v->uuid)->assertCreated();
        $this->stampTurns(3, $v->uuid, now()->addHour());
        self::at('2026-11-04T16:00:00Z');
        $this->freshSessions();

        return $v;
    }

    private function promote(StudioVersion $v): void
    {
        $evidencia = $this->evidenceOf($this->pro, $v->uuid);
        $this->assertTrue($evidencia['passes'], json_encode($evidencia['failing']));
        $this->promoteAs($this->pro, $v->uuid, null, $evidencia['evidenceHash'])->assertCreated()->assertJsonPath('data.action', 'promote');
    }

    /** @return array<string,mixed> */
    private function headEntry(): array
    {
        $c = $this->chainHead();

        return D::entry((string) $c->effective_json, (string) $c->uuid, (int) $c->seq) + ['column' => $c->engine_contract];
    }

    /** @return array{0:string,1:?string} modo y motivo de la oferta REAL de `decide` a la canaria */
    private function decideOffer(): array
    {
        $r = app(UltronPromptOffer::class)->forConversation(self::CANARY_ID)['response'];

        return [$r['mode'], $r['reason']];
    }

    /** @return array<string,mixed> */
    private static function compile(): array
    {
        return self::$compile ??= json_decode((string) file_get_contents(self::COMPILE_CASES), true, 64, JSON_THROW_ON_ERROR);
    }

    /** @return array<string,mixed> el caso de compile-cases.json (derivado del contrato, no del Studio) */
    private function compileCase(string $tone, bool $emojis, int $maxChars, string $goal): array
    {
        foreach (self::compile()['cases'] as $c) {
            // Compilador 2: la quinta clave, vacía (los bytes son los del compilador 1).
            if ($c['governed'] === ['persona.emojis' => $emojis, 'persona.maxChars' => $maxChars, 'persona.tone' => $tone, 'rules.forbiddenPhrases' => [], 'strategy.goal' => $goal]) {
                return $c;
            }
        }
        $this->fail("compile-cases.json no tiene {$tone}/{$maxChars}");
    }

    private function bundleOf(string $tone, bool $emojis, int $maxChars, string $goal): string
    {
        return $this->compileCase($tone, $emojis, $maxChars, $goal)['expect']['bundleHash'];
    }

    private static function at(string $iso): void
    {
        Carbon::setTestNow(Carbon::parse($iso)->utc());
        CarbonImmutable::setTestNow(CarbonImmutable::parse($iso)->utc());
    }
}
