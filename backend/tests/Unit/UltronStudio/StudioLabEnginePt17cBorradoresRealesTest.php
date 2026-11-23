<?php

namespace Tests\Unit\UltronStudio;

use App\Models\MarketingKnowledgeItem;
use App\Models\Plan;
use App\Services\Marketing\Ultron\Prompts\UltronPromptCompiler as C;
use App\Services\Marketing\Ultron\UltronPlansOverview as O;
use App\Services\Marketing\Ultron\UltronReplyFitter;
use App\Services\UltronStudio\StudioLabEngineCheck;
use App\Services\UltronStudio\StudioLabEngineContext;
use App\Services\UltronStudio\StudioPromptBundle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Tests\Unit\Marketing\Ultron\Prompts\PromptPublications;

/**
 * PT-17c · LOS BORRADORES REALES DE LA MEDICIÓN DEL COMPILADOR 6, CONTRA LAS
 * COMPROBACIONES DEL SERVIDOR, sin modelo ni red (Enmienda A4-7 del H5; nota
 * de diseño `plans/informes-motores/diseno-pt17c-estratega-c7.md`).
 *
 * El compilador 7 cambia SOLO el texto del estratega; el servidor no cambia.
 * Esto fija, con los textos que escribió el modelo en producción
 * (`tests/fixtures/ultron-studio/pt17c-borradores-reales-c6.json`, 25 casos
 * de la v4 con el 6), que las comprobaciones del servidor —`ai/check`
 * ({@see StudioLabEngineCheck::check()}) y el commit
 * ({@see StudioLabEngineCheck::predict()}), las MISMAS funciones del motor,
 * con su paridad fijada en `StudioLabEngineCheckParityTest` y
 * `UltronCheckCommitParityTest`— no rechazan lo que no deben y siguen
 * rechazando lo que sí, con el sello del compilador 7:
 *
 *  R1. CONSULTA COMBINADA («¿Qué planes tienen y abren los domingos?»): el
 *      servidor ofrece la lista y los cinco SEGUNDOS borradores reales, que el
 *      revisor tumbó, pasan `ai/check` y el commit sale del modelo con la
 *      lista (tres planes, `PLAN_3` = Élite, id 8) y el horario del domingo,
 *      sin recorte; con `recommended_plan_id` 3 o 8 (la referencia del
 *      contrato no cambia el texto).
 *  R2. PRIMEROS BORRADORES: los 25 reales siguen dando EXACTAMENTE sus
 *      códigos de forma (la lista que falta o el nombre a mano, la longitud),
 *      medidos con lo mismo que en el laboratorio; y uno con la lista válida
 *      (el segundo borrador real de 38/171) pasa ya como primer intento.
 *  R3. SIN RECHAZOS INJUSTIFICADOS: los 14 textos finales reales que escribió
 *      el modelo y los 5 segundos borradores de la combinada pasan todas las
 *      comprobaciones del servidor (`ai/check` sin defecto y el commit sin
 *      código, del modelo y entero).
 *  R4. LOS MARCADORES SON POSICIONES: en cada texto real con `{{PLAN_3}}`, el
 *      servidor pone el Élite (id 8), nunca el Plan Valera (id 3).
 *
 * El catálogo: 3 · Plan Valera, 4 · Plan Mensual y 8 · Élite, de un mes, con
 * los nombres y precios de producción (lo fija la igualdad con `labChars`) y
 * los beneficios de `PlansSeeder` (los dos primeros, los de «Básicos»; el
 * Élite, los de «Total Access»), que son los que citan los borradores; el
 * catálogo de producción no se leyó. El horario, con los domingos.
 */
class StudioLabEnginePt17cBorradoresRealesTest extends TestCase
{
    use PromptPublications;
    use RefreshDatabase;

    private const VERSION = 'c0000000-0000-4000-8000-0000000000c7';

    private const FIXTURE = __DIR__.'/../../fixtures/ultron-studio/pt17c-borradores-reales-c6.json';

    private const BASICOS = ['Acceso ilimitado al gimnasio', 'Zona de pesas y maquinaria', 'Asesoría semi personalizada con entrenador de planta', 'Seguimiento en ejecución de ejercicios', 'Valoración inicial', 'Acceso básico completo a la app', 'Perfil de usuario', 'Visualización de plan activo', 'Historial de pagos y documentos', 'Rutinas generales básicas', 'Registro básico de IMC, peso y racha', 'Acceso a reels, stories y eventos generales', 'IRON IA limitada'];

    private const TOTAL_ACCESS = ['Acceso total al gimnasio', 'Acceso completo a IRONBODY WORKOUT', 'Sesiones funcionales por agenda', 'Entrenamiento intensivo y dinámico', 'Rutinas guiadas por entrenadores', 'Seguimiento continuo durante todo el proceso', 'Guía nutricional para mejorar resultados', 'Acceso completo a la app', 'IRON IA completa', 'IRON IA por texto, voz, audio, imagen y cámara', 'Análisis visual en vivo', 'IRON IA coach completo', 'Rutinas personalizadas', 'Seguimiento digital completo', 'Nutrición avanzada', 'Historial completo de progreso'];

    /** id → nombre, precio y días (de producción). */
    private const CATALOGO = [
        3 => ['Plan Valera', 65000, 30],
        4 => ['Plan Mensual', 80000, 30],
        8 => ['Élite', 180000, 30],
    ];

    private const V4 = ['persona.tone' => 'profesional', 'persona.maxChars' => 200];

    private const PASS = ['verdict' => 'pass', 'issues' => [], 'notes' => ''];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-23 18:00:00', 'UTC'));
        config()->set('marketing.ultron.enabled', true);
        config()->set('meta.enabled', false);
        config()->set('marketing.ai.enabled', true);
        config()->set('marketing.ai.driver', 'fake');
        config()->set('marketing.ultron.payment_links_enabled', false);
        Http::fake();
        $orden = 0;
        foreach (self::CATALOGO as $id => [$nombre, $precio, $dias]) {
            (new Plan)->forceFill([
                'id' => $id, 'name' => $nombre, 'price' => $precio, 'duration_days' => $dias,
                'active' => true, 'sellable' => true, 'sort_order' => ++$orden, 'is_recommended' => false,
                'benefits' => json_encode($id === 8 ? self::TOTAL_ACCESS : self::BASICOS),
            ])->save();
        }
        MarketingKnowledgeItem::create([
            'category' => 'schedule', 'key' => 'schedule.opening_hours', 'title' => 'Horario general de atencion',
            'content' => 'Lunes a viernes de 5:00 a. m. a 10:00 p. m. Sabados de 8:00 a. m. a 2:00 p. m. Domingos de 8:00 a. m. a 2:00 p. m. Festivos de 8:00 a. m. a 2:00 p. m.',
            'is_active' => true, 'origin' => MarketingKnowledgeItem::ORIGIN_HUMAN_PANEL, 'review_status' => MarketingKnowledgeItem::REVIEW_APPROVED,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array<string,mixed> */
    private static function fixture(): array
    {
        return json_decode((string) file_get_contents(self::FIXTURE), true, 16, JSON_THROW_ON_ERROR);
    }

    /** @return array<string,mixed> el `sp` que el Studio firma para la v4 con el compilador de hoy */
    private static function sp(): array
    {
        $parte = self::parteV2(self::VERSION, self::V4);

        return StudioPromptBundle::rolesFor($parte['governed'], self::VERSION, $parte['contentHash'])['sp'];
    }

    /** @return array<string,mixed> el turno de ese mensaje con el sello de la v4 */
    private function turno(string $mensaje): array
    {
        return app(StudioLabEngineContext::class)->build(['turns' => [['role' => 'prospect', 'text' => $mensaje]], 'facts' => ['paymentState' => 'none', 'handoffRequested' => false]], self::sp())['turn'];
    }

    /** El turno de un caso de la fixture (`…/E-006` es la combinada; los demás, el turno de planes). */
    private function turnoDe(string $caso): array
    {
        $f = self::fixture();

        return $this->turno(str_ends_with($caso, '/E-006') ? $f['scenarios']['E-006'] : $f['scenarios']['E-001']);
    }

    /** @return array{0:array<string,mixed>,1:array<string,mixed>} [ai/check del intento k, la predicción del commit con el revisor en pass] */
    private function pasa(array $turno, string $borrador, int $intento, int $plan = 3): array
    {
        $lab = app(StudioLabEngineCheck::class);

        return [
            $lab->check($turno, $intento, ['reply_draft' => $borrador, 'intent' => 'pricing_question', 'recommended_plan_id' => $plan, 'tools_requested' => []], 3),
            $lab->predict($turno, ['reply_draft' => $borrador, 'recommended_plan_id' => $plan, 'critic' => self::PASS], ['intent' => 'pricing_question', 'tools_requested' => []]),
        ];
    }

    /** Los códigos de `ai/check` como los anota la medición: `código` o `código/detalle`. */
    private static function codigos(array $check): array
    {
        return array_map(static fn (array $d): string => $d['detail'] === null ? $d['code'] : $d['code'].'/'.$d['detail'], $check['defects']);
    }

    /** @return array<int,string> plan_id → su texto expandido */
    private static function textos(): array
    {
        $out = [];
        foreach (Plan::query()->whereIn('id', array_keys(self::CATALOGO))->get() as $plan) {
            $out[(int) $plan->id] = O::item($plan);
        }

        return $out;
    }

    private static function expandido(string $borrador, array $po): string
    {
        return O::expand($borrador, self::textos(), $po)['text'];
    }

    // ── R1 ──────────────────────────────────────────────────────────────────

    public function test_r1_la_consulta_combinada_lista_y_horario_de_los_cinco_segundos_borradores_reales(): void
    {
        $f = self::fixture();
        $this->assertCount(5, $f['combinedSecond']);
        $turno = $this->turno($f['scenarios']['E-006']);
        $this->assertSame([200, C::VERSION], [$turno['sp']['n'], $turno['sp']['k']], 'el sello de la v4 con el compilador de hoy');
        $this->assertSame($f['po'], $turno['po'], 'a la combinada el servidor le ofrece la lista');

        foreach ($f['combinedSecond'] as $caso) {
            $nombre = $caso['case'];
            $this->assertSame('fail', $caso['critic']['verdict'], "{$nombre}: el revisor lo tumbó en la medición");
            $this->assertSame(['PLAN_1', 'PLAN_2', 'PLAN_3'], $caso['markers'], "{$nombre}: los tres planes");
            $expandido = self::expandido($caso['text'], $turno['po']);
            $this->assertSame($caso['labChars'], UltronReplyFitter::measure($expandido), "{$nombre}: mide lo mismo que en el laboratorio");
            foreach (['Plan Valera ($65.000 COP)', 'Plan Mensual ($80.000 COP)', 'Élite ($180.000 COP)'] as $plan) {
                $this->assertStringContainsString($plan, $expandido, "{$nombre}: la lista");
            }
            $this->assertMatchesRegularExpression('/domingos?/iu', $expandido, "{$nombre}: contesta el domingo");
            $this->assertMatchesRegularExpression('/\b8(:00)?\s*a\.?\s*m\.?/iu', $expandido, "{$nombre}: con su hora de apertura");

            foreach ([3, 8] as $plan) {
                [$check, $commit] = $this->pasa($turno, $caso['text'], 2, $plan);
                $this->assertSame([true, []], [$check['pass'], $check['defects']], "{$nombre} (plan {$plan}): ai/check no pide nada");
                $this->assertSame([StudioLabEngineCheck::ORIGIN_MODEL, null, [], false, 320, true], [$commit['origin'], $commit['cause'], $commit['codes'], $commit['trimmed'], $commit['cap'], $commit['withinCap']], "{$nombre} (plan {$plan}): el commit lo envía tal cual, con la lista");
                $this->assertSame([$caso['labChars'], $caso['labChars']], [$commit['measured'], $commit['chars']], "{$nombre} (plan {$plan}): entero");
            }
        }
    }

    // ── R2 ──────────────────────────────────────────────────────────────────

    public function test_r2_los_primeros_borradores_reales_siguen_dando_sus_codigos_y_uno_con_la_lista_valida_pasa(): void
    {
        $f = self::fixture();
        $this->assertCount(25, $f['firstDrafts']);
        $turnos = ['planes' => $this->turno($f['scenarios']['E-001']), 'combinada' => $this->turno($f['scenarios']['E-006'])];
        $porCodigo = [];
        foreach ($f['firstDrafts'] as $caso) {
            $nombre = $caso['case'];
            $turno = $turnos[str_ends_with($nombre, '/E-006') ? 'combinada' : 'planes'];
            $this->assertNotSame([], $caso['codes'], "{$nombre}: ningún primer borrador fue válido en la medición");
            [$check, $commit] = $this->pasa($turno, $caso['text'], 1);
            $this->assertSame($caso['codes'], self::codigos($check), "{$nombre}: los mismos códigos que en producción");
            $this->assertFalse($check['pass'], $nombre);
            $this->assertTrue($check['rewrite'], "{$nombre}: en el primer intento, el servidor pide reescribir");
            $this->assertSame([$caso['labChars'], $caso['labCap']], [$commit['measured'], $commit['cap'] ?? 200], "{$nombre}: medido como en el laboratorio");
            foreach ($caso['codes'] as $codigo) {
                $porCodigo[$codigo] = ($porCodigo[$codigo] ?? 0) + 1;
            }
        }
        ksort($porCodigo);
        $this->assertSame(['length_over_cap' => 23, 'plans_overview/missing' => 14, 'plans_overview/name_written' => 10], $porCodigo, 'solo defectos de forma: la lista que falta, el nombre a mano y la longitud');

        // Un borrador real con la lista válida (el segundo de 38/171) pasa ya como PRIMER intento.
        $valido = array_values(array_filter($f['finalModel'], static fn (array $c): bool => $c['case'] === '38/171/E-003'))[0];
        [$check, $commit] = $this->pasa($turnos['planes'], $valido['text'], 1);
        $this->assertSame([true, [], false], [$check['pass'], $check['defects'], $check['rewrite']], 'al primer intento, sin nada que reescribir');
        $this->assertSame([StudioLabEngineCheck::ORIGIN_MODEL, [], $valido['labChars']], [$commit['origin'], $commit['codes'], $commit['chars']]);
    }

    // ── R3 ──────────────────────────────────────────────────────────────────

    public function test_r3_los_14_finales_reales_y_los_5_segundos_de_la_combinada_pasan_todas_las_comprobaciones(): void
    {
        $f = self::fixture();
        $this->assertCount(14, $f['finalModel']);
        $textos = [...array_map(static fn (array $c): array => $c + ['intento' => $c['draft']], $f['finalModel']), ...array_map(static fn (array $c): array => $c + ['intento' => 2], $f['combinedSecond'])];
        $this->assertCount(19, $textos);
        $turnos = ['planes' => $this->turno($f['scenarios']['E-001']), 'combinada' => $this->turno($f['scenarios']['E-006'])];

        foreach ($textos as $caso) {
            $nombre = $caso['case'];
            $turno = $turnos[str_ends_with($nombre, '/E-006') ? 'combinada' : 'planes'];
            $this->assertGreaterThanOrEqual(O::MIN_MARKERS, count(array_unique($caso['markers'])), "{$nombre}: trae la lista");
            [$check, $commit] = $this->pasa($turno, $caso['text'], $caso['intento']);
            $this->assertSame([true, []], [$check['pass'], $check['defects']], "{$nombre}: ai/check sin defecto");
            $this->assertSame([StudioLabEngineCheck::ORIGIN_MODEL, null, [], false, true, 'ok'], [$commit['origin'], $commit['cause'], $commit['codes'], $commit['trimmed'], $commit['withinCap'], $commit['register']], "{$nombre}: el commit lo envía tal cual");
            $this->assertSame([$caso['labChars'], 320], [$commit['chars'], $commit['cap']], "{$nombre}: entero, con el tope de la lista");
            $this->assertLessThanOrEqual(320, $commit['chars'], $nombre);
        }
    }

    // ── R4 ──────────────────────────────────────────────────────────────────

    public function test_r4_en_los_textos_reales_plan_3_es_el_plan_de_id_8(): void
    {
        $f = self::fixture();
        $turno = $this->turno($f['scenarios']['E-001']);
        $this->assertSame([3, 4, 8], $turno['po']);
        $conPlan3 = 0;
        foreach ([...$f['firstDrafts'], ...$f['finalModel'], ...$f['combinedSecond']] as $caso) {
            if (! in_array('PLAN_3', $caso['markers'], true)) {
                continue;
            }
            $conPlan3++;
            $r = O::expand($caso['text'], self::textos(), $turno['po']);
            $this->assertContains(8, $r['used'], "{$caso['case']}: PLAN_3 es el tercero de la lista, el de id 8");
            $this->assertStringContainsString('Élite ($180.000 COP)', $r['text'], $caso['case']);
            $sinPlan3 = O::expand(str_replace('{{PLAN_3}}', '', $caso['text']), self::textos(), $turno['po']);
            $this->assertNotContains(8, $sinPlan3['used'], "{$caso['case']}: el Élite sale solo por PLAN_3");
            if (! in_array('PLAN_1', $caso['markers'], true)) {
                $this->assertNotContains(3, $r['used'], "{$caso['case']}: sin PLAN_1, el plan de id 3 no sale");
            }
        }
        $this->assertSame(29, $conPlan3, 'los 29 textos reales con PLAN_3');
    }
}
