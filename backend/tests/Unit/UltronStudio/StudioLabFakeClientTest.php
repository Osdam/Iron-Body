<?php

namespace Tests\Unit\UltronStudio;

use App\Services\UltronStudio\StudioCatalog;
use App\Services\UltronStudio\StudioLabCatalog;
use App\Services\UltronStudio\StudioLabChecks;
use App\Services\UltronStudio\StudioLabCost;
use App\Services\UltronStudio\StudioLabEngineChecks;
use App\Services\UltronStudio\StudioLabFakeClient as F;
use App\Services\UltronStudio\StudioLabOutcome;
use App\Services\UltronStudio\StudioLabPrompts as P;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * El modo `fake` (contrato H4 §A3): sin red, coste 0, el actor con una frase
 * fija recortada a la longitud de la versión y el juez `unclear` a todo con
 * «Modo de prueba: sin IA». Cada caso queda como mucho en `review` y la
 * ejecución no tiene propuesta.
 */
class StudioLabFakeClientTest extends TestCase
{
    private static function linea(): array
    {
        $sel = P::selectRules(StudioCatalog::legacyRules());

        return ['configs' => StudioCatalog::baseline()['configs'], 'edgeIds' => StudioCatalog::baseline()['edgeIds'], 'rules' => $sel['included']];
    }

    public function test_los_doce_escenarios_en_fake_sin_red_sin_coste_y_como_mucho_en_review(): void
    {
        Http::fake();
        $objetivo = self::linea();
        $cliente = new F($objetivo['configs']['persona']['maxChars']);
        $casos = [];

        foreach (StudioLabCatalog::scenarios() as $s) {
            $actor = $cliente->complete('actor', P::ACTOR_INSTRUCTIONS, P::actorInput($objetivo, $s), P::actorSchema(), 400);
            $leido = P::readActorCall($actor);
            $this->assertNull($leido['class'], $s['code']);
            $this->assertSame(F::ACTOR_REPLY, $leido['reply'], $s['code']);
            $this->assertSame(0, StudioLabCost::callMicros(null, $actor['usage'], 99999, ['input' => 400_000, 'output' => 1_600_000]), 'cuesta 0 aunque haya precio');

            // La frase no dispara ninguna prueba determinista.
            $hallazgos = [...StudioLabChecks::check($leido['reply'], $objetivo), ...(new StudioLabEngineChecks)->check($leido['reply'], $s['facts'])];
            $this->assertSame([], $hallazgos, $s['code']);

            $juez = $cliente->complete('judge', P::JUDGE_INSTRUCTIONS, P::judgeInput($objetivo, $s, $leido['reply']), P::judgeSchema(), 900);
            $opinion = P::readJudgeCall($juez, array_column($s['criteria'], 'id'), array_column($objetivo['rules'], 'code'));
            $this->assertNull($opinion['class'], $s['code']);
            foreach ([...$opinion['judge']['criteria'], ...$opinion['judge']['rules']] as $o) {
                $this->assertSame('unclear', $o['verdict']);
                $this->assertSame('Modo de prueba: sin IA', $o['reason']);
            }
            $this->assertSame('Modo de prueba: sin IA', $opinion['judge']['summary']);
            $this->assertSame([], $opinion['judge']['risks']);

            $resultado = StudioLabOutcome::forCase($hallazgos, $opinion['judge'], $s['criteria']);
            $this->assertSame(StudioLabOutcome::REVIEW, $resultado, $s['code']);
            $casos[] = ['status' => 'done', 'outcome' => $resultado];
            $this->assertSame(0, $actor['latencyMs'] + $juez['latencyMs']);
            $this->assertNull($actor['http']);
        }

        Http::assertNothingSent();
        $this->assertNull(StudioLabOutcome::proposal('completed', 'fake', $casos), 'una ejecución fake no tiene propuesta');
    }

    public function test_la_frase_se_recorta_a_la_longitud_de_la_version(): void
    {
        $this->assertLessThanOrEqual(160, mb_strlen(F::ACTOR_REPLY), 'cabe en la longitud mínima de la rejilla');
        $corta = (new F(20))->complete('actor', '', '{}', P::actorSchema(), 400);
        $this->assertSame(mb_substr(F::ACTOR_REPLY, 0, 20), $corta['output']['reply']);
        $this->assertSame(F::ACTOR_REPLY, (new F)->complete('actor', '', '{}', P::actorSchema(), 400)['output']['reply']);
    }

    public function test_una_entrada_del_juez_ilegible_es_salida_no_valida(): void
    {
        $r = (new F)->complete('judge', '', 'no es json', P::judgeSchema(), 900);
        $this->assertSame('error', $r['status']);
        $this->assertSame('invalid_output', $r['class']);
    }
}
