<?php

namespace Tests\Unit\UltronStudio;

use App\Services\UltronStudio\StudioCatalog;
use App\Services\UltronStudio\StudioLabCatalog;
use App\Services\UltronStudio\StudioLabCost;
use App\Services\UltronStudio\StudioLabPrompts as P;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class StudioLabEngineJudgeTest extends TestCase
{
    public function test_el_actor_y_el_juez_historicos_conservan_su_huella_y_su_entrada(): void
    {
        $this->assertSame('2026-11-12.6', P::PROMPT_VERSION);
        $this->assertSame('01fc520af225f0373d6d4a3312e93fa1fca72cd8eb74037526da222102c51f0b', P::hash());
        $this->assertSame('e88cbee7f41baa7b9701a333aa6bd08dfed7814af7cac0e685128f8a6c82fdae', P::engineJudgeHash());
        $scenario = StudioLabCatalog::byCode('C-02');
        foreach ([[], ['engine' => ['compiler' => 8]], ['engine' => ['compiler' => '9']]] as $target) {
            $this->assertFalse(P::engineJudgeEnabled($target));
            $this->assertSame(P::JUDGE_INSTRUCTIONS, P::engineJudgeInstructions($target));
            $this->assertSame(P::judgeInput($target, $scenario, '{{PLAN_PRICE}}'), P::engineJudgeInput($target, $scenario, '{{PLAN_PRICE}}', []));
            $this->assertSame(P::judgeEstimateInput($target, $scenario), P::engineJudgeEstimateInput($target, $scenario));
        }
    }

    public function test_el_juez_c9_distingue_borrador_candidato_y_respaldo_sin_arreglar_el_texto(): void
    {
        $target = ['engine' => ['compiler' => 9], 'configs' => StudioCatalog::baseline()['configs']];
        $raw = 'Planes LISTA_DE_PLANES sin llaves; no debe repararse en el juez.';
        $rendered = $raw.' Planes de un mes: Plan Uno ($65.000 COP); Plan Dos ($80.000 COP).';
        $prediction = ['origin' => 'fallback', 'codes' => ['plans_overview'], 'chars' => 320, 'cap' => 320, 'withinCap' => true, 'trimmed' => false, 'list' => ['shape' => 'server', 'slot' => false]];
        $input = json_decode(P::engineJudgeInput($target, StudioLabCatalog::byCode('C-02'), $raw, ['candidate_rendered' => $rendered, 'prediction' => $prediction]), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($raw, $input['borrador_modelo']);
        $this->assertSame($rendered, $input['respuesta']);
        $this->assertSame($prediction, $input['observacion_servidor']);
        $this->assertSame(P::ENGINE_JUDGE_VERSION, $input['contrato_respuesta']);
        $this->assertSame(P::ENGINE_JUDGE_INSTRUCTIONS, P::engineJudgeInstructions($target));
        foreach (['no es una respuesta entregable del modelo', 'no atribuyas al modelo un respaldo', '320 para una lista aunque la versión indique 200', 'Una pregunta de cierre no es obligatoria', 'sin inventar ni atribuirle el texto del respaldo'] as $text) {
            $this->assertStringContainsString($text, P::ENGINE_JUDGE_INSTRUCTIONS.$input['tarea']);
        }
        $this->assertStringNotContainsString('El actor no tiene datos del gimnasio', P::ENGINE_JUDGE_INSTRUCTIONS);
        $this->assertStringContainsString('exactamente una ranura literal {{LISTA_DE_PLANES}}', P::ENGINE_JUDGE_INSTRUCTIONS);
        $this->assertStringNotContainsString('la forma sin ranura puede ser válida', P::ENGINE_JUDGE_INSTRUCTIONS);
        $this->assertSame(['criteria' => ['c1', 'c2', 'c3'], 'rules' => []], P::judgeTargets(json_encode($input, JSON_THROW_ON_ERROR)));
    }

    public function test_los_hechos_son_compactos_autorizados_y_no_llevan_precios_ids_ni_memoria(): void
    {
        $turn = ['po' => [3, 4], 'inbound' => '¿Qué planes tienen y abren los domingos?', 'catalog' => [
            ['id' => 3, 'name' => 'Plan A', 'price' => 65000, 'duration_days' => 30, 'benefits' => ['Acceso a pesas'], 'private' => 'no enviar'],
            ['id' => 4, 'name' => 'Plan B', 'price' => 80000, 'duration_days' => 30, 'benefits' => ['Rutinas guiadas']],
            ['id' => 99, 'name' => 'Plan fuera de la lista', 'price' => 90000],
        ]];
        $context = ['knowledge_base' => ['business_identity' => ['Somos un gimnasio.'], 'location' => ['Otra sede.']], 'gym' => ['opening_hours' => ['Domingos de 8 a 14.'], 'trainers' => ['dato ajeno']], 'memory' => ['private' => 'dato ajeno']];
        $facts = P::engineJudgeFacts($context, $turn);
        $this->assertSame(['Plan A', 'Plan B'], array_column($facts['plans'], 'name'));
        $this->assertSame(['Domingos de 8 a 14.', 'Somos un gimnasio.'], $facts['gym']);
        foreach ($facts['plans'] as $plan) {
            $this->assertArrayNotHasKey('price', $plan);
            $this->assertArrayNotHasKey('id', $plan);
            $this->assertArrayNotHasKey('private', $plan);
        }
        $this->assertFalse($facts['incomplete']);
        $turn['catalog'][0]['benefits'] = [str_repeat('dato completo ', 2000)];
        $facts = P::engineJudgeFacts($context, $turn);
        $this->assertTrue($facts['incomplete']);
        $this->assertLessThanOrEqual(P::ENGINE_JUDGE_FACT_BYTES, strlen(json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)));
        $this->assertNotContains('Plan A', array_column($facts['plans'], 'name'), 'un dato que no cabe no se corta para fabricar evidencia parcial');
    }

    public function test_el_presupuesto_c9_cubre_dos_textos_con_escape_json_y_los_hechos(): void
    {
        $target = ['engine' => ['compiler' => 9]];
        $scenario = StudioLabCatalog::byCode('C-02');
        $raw = str_repeat("\x01", P::ENGINE_JUDGE_TEXT_MAX);
        $prediction = ['origin' => 'fallback', 'codes' => ['plans_overview'], 'chars' => P::ENGINE_JUDGE_TEXT_MAX, 'cap' => 320, 'withinCap' => false, 'trimmed' => false, 'list' => ['shape' => 'server', 'slot' => false]];
        $input = P::engineJudgeInput($target, $scenario, $raw, ['candidate_rendered' => $raw, 'prediction' => $prediction, 'authorized_facts' => ['gym' => [str_repeat('x', 7900)]]]);
        $reservedBytes = StudioLabCost::callBytes(P::engineJudgeInstructions($target), P::engineJudgeEstimateInput($target, $scenario), P::judgeSchema()) + 2 * P::ENGINE_JUDGE_TEXT_MAX * 6 + P::ENGINE_JUDGE_FACT_BYTES;
        $actualBytes = StudioLabCost::callBytes(P::engineJudgeInstructions($target), $input, P::judgeSchema());
        $this->assertGreaterThanOrEqual($actualBytes, $reservedBytes);
    }

    public function test_un_texto_que_supera_la_cota_no_se_trunca_para_el_juez(): void
    {
        $this->expectException(InvalidArgumentException::class);
        P::engineJudgeInput(['engine' => ['compiler' => 9]], [], str_repeat('x', P::ENGINE_JUDGE_TEXT_MAX + 1), ['candidate_rendered' => '', 'prediction' => []]);
    }

    public function test_el_lector_de_schema_aplica_el_patron_sin_cambiar_los_esquemas_historicos(): void
    {
        $schema = ['type' => 'string', 'pattern' => '^.*\\{\\{LISTA_DE_PLANES\\}\\}.*$'];
        $this->assertTrue(P::conforms($schema, 'Estos son: {{LISTA_DE_PLANES}}.'));
        $this->assertFalse(P::conforms($schema, 'Estos son: LISTA_DE_PLANES.'));
        $this->assertTrue(P::conforms(P::actorSchema(), ['reply' => 'Respuesta histórica sin ranura']));
    }
}
