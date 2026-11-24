<?php

namespace Tests\Unit\UltronStudio;

use App\Models\MarketingKnowledgeItem;
use App\Models\Plan;
use App\Services\Marketing\Ultron\CriticContract;
use App\Services\Marketing\Ultron\Prompts\UltronPromptCompiler as C;
use App\Services\UltronStudio\StudioLabChecks;
use App\Services\UltronStudio\StudioLabEngineCase;
use App\Services\UltronStudio\StudioLabEngineCheck;
use App\Services\UltronStudio\StudioLabEngineContext;
use App\Services\UltronStudio\StudioLabEngineFlow;
use App\Services\UltronStudio\StudioLabEngineModelClient;
use App\Services\UltronStudio\StudioLabModelClient;
use App\Services\UltronStudio\StudioPromptBundle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Tests\Unit\Marketing\Ultron\Prompts\PromptPublications;

/**
 * PT-17 · PUERTA 1 · LA REPRODUCCIÓN DETERMINISTA DE LA MEDICIÓN DEL
 * COMPILADOR 7, sin modelo ni red (diagnóstico estructural del 24-11-2026,
 * `ops/evidencia-h9/2026-11-24/04-diagnostico-estructural-pt17.md`, §1 C5 y
 * §3; L2, L3 y L4 de su §2).
 *
 * Los 25 casos grabados (`tests/fixtures/ultron-studio/pt17-borradores-reales-c7.json`:
 * 20 del turno de planes, E-001 a E-005, y 5 de la consulta combinada, E-006)
 * pasan por el motor REAL del laboratorio ({@see StudioLabEngineCase::run()}):
 * el contexto de `decide`, el recorrido S2 con sus IF y sus Code, cada
 * «Comprobar Borrador k» contra `ai/check` ({@see StudioLabEngineCheck::respond()})
 * y lo que enviaría el commit ({@see StudioLabEngineCheck::predict()}). Lo único
 * que no es del servidor es lo que escribió el modelo: el estratega, los
 * redactores y los revisores devuelven lo grabado (un cliente que solo sabe
 * repetir; si el recorrido le pide un nodo que no se grabó, la reproducción
 * no vale y se dice).
 *
 * LA LÍNEA BASE. Con las reglas de `b0105991` la reproducción es IDÉNTICA a
 * lo grabado (el recorrido, cada borrador con su medida y su tope, cada
 * `ai/check` con sus notas, cada revisor y el bloque `final`; la huella del
 * paquete es la medida, `200019e4…`) y da lo que midió el laboratorio: 9 de
 * 20 escritas por el modelo con la lista válida, 11 respaldos (7 del revisor
 * y 4 de la lista), 4 injustificados, 4 recortes, máx. 301 y la combinada 5
 * de 5. Con la puerta 1, los mismos textos dan:
 *
 *  R1-R2. La reproducción sigue valiendo (ningún nodo sin grabar; cada
 *      borrador, su texto y su medida; el recorrido es el grabado o, con la
 *      lista firme de L4, el grabado sin el tercer borrador) y da lo de la
 *      fila 3 de la tabla del diagnóstico: 18 de 20 escritas por el modelo
 *      con la lista válida; respaldo solo en los dos casos con un defecto del
 *      servidor (`{{PLAN_PRICE}}` dentro de la lista); 0 recortes; 0
 *      respaldos injustificados; longitud máxima ≤ 320 (308); la combinada 5
 *      de 5. Cada texto del modelo que el revisor rechazó sale con el aviso
 *      (`criticAdvisory`), sobre un rechazo blando —sin fallo duro ni
 *      dimensiones de seguridad, exactitud o política (P-1 A)— y una lista sin
 *      defectos.
 *      El primer borrador sigue en 0 de 20 (R1 del diagnóstico: ninguna regla
 *      del servidor lo cambia).
 *  R3. Un solo tope (L2): todo borrador de estos turnos de lista se mide
 *      contra el de la lista (320) y sin recorte; cada nota a un borrador que
 *      habla de un solo plan dice que falta la lista y calcula sobre ese tope
 *      (71 de los marcadores, quedan 179 de 250), y ninguna sobre 200 o 160;
 *      el aviso de longitud del laboratorio usa la medida y el tope del
 *      servidor (avisaba en 22 de los 25).
 *  R4. Las pistas (L3): en los seis escenarios, el estratega recibe
 *      `should_recommend_now=false`, `question_budget=0`, `may_ask=[]` y
 *      `suggested_question=null`, y Validar Estrategia no le deja preguntar.
 *
 * La línea de resumen (`[PT-17 c7]`) sale por la salida de errores, como las
 * de las demás pruebas que miden algo.
 *
 * El catálogo, el de la medición: 3 · Plan Valera, 4 · Plan Mensual y
 * 8 · Élite, de un mes, con sus nombres y precios (lo fija que cada borrador
 * mida lo grabado) y los beneficios de `PlansSeeder`; el horario, con los
 * domingos. `recommended_plan_id` no se grabó: la fixture lo infiere de la
 * medida (3, el plan por defecto, en los 25).
 */
class StudioLabEnginePt17C7ReplayTest extends TestCase
{
    use PromptPublications;
    use RefreshDatabase;

    private const FIXTURE = __DIR__.'/../../fixtures/ultron-studio/pt17-borradores-reales-c7.json';

    private const BASICOS = ['Acceso ilimitado al gimnasio', 'Zona de pesas y maquinaria', 'Asesoría semi personalizada con entrenador de planta', 'Seguimiento en ejecución de ejercicios', 'Valoración inicial', 'Acceso básico completo a la app', 'Perfil de usuario', 'Visualización de plan activo', 'Historial de pagos y documentos', 'Rutinas generales básicas', 'Registro básico de IMC, peso y racha', 'Acceso a reels, stories y eventos generales', 'IRON IA limitada'];

    private const TOTAL_ACCESS = ['Acceso total al gimnasio', 'Acceso completo a IRONBODY WORKOUT', 'Sesiones funcionales por agenda', 'Entrenamiento intensivo y dinámico', 'Rutinas guiadas por entrenadores', 'Seguimiento continuo durante todo el proceso', 'Guía nutricional para mejorar resultados', 'Acceso completo a la app', 'IRON IA completa', 'IRON IA por texto, voz, audio, imagen y cámara', 'Análisis visual en vivo', 'IRON IA coach completo', 'Rutinas personalizadas', 'Seguimiento digital completo', 'Nutrición avanzada', 'Historial completo de progreso'];

    /** id → nombre, precio y días (los de la medición). */
    private const CATALOGO = [
        3 => ['Plan Valera', 65000, 30],
        4 => ['Plan Mensual', 80000, 30],
        8 => ['Élite', 180000, 30],
    ];

    /**
     * Cómo se nombra a mano cada plan de la lista, para contar de cuántos
     * habla un borrador sin usar la función que se prueba (con mayúscula: «el
     * acceso mensual» no es el Plan Mensual).
     */
    private const NOMBRES = [3 => '/\bValera\b/u', 4 => '/\bMensual\b/u', 8 => '/\b[EÉ]lite\b/u'];

    /** La versión 4 medida: «profesional · 200». */
    private const V4 = ['persona.tone' => 'profesional', 'persona.maxChars' => 200];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-24 13:55:00', 'UTC'));
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

    // ── R1 y R2 ─────────────────────────────────────────────────────────────

    public function test_r1_r2_la_puerta_1_sobre_los_25_recorridos_grabados(): void
    {
        $f = self::fixture();
        $paquete = self::paquete($f['engineTarget']['version']);
        $this->assertSame([200, 'profesional', 1, C::VERSION], [$paquete['sp']['n'], $paquete['sp']['t'], $paquete['sp']['o'], $paquete['sp']['k']], 'el sello de la v4 con el compilador de hoy');
        $this->assertSame($f['engineTarget']['bundleHash'], $paquete['bundleHash'], 'el mismo paquete de prompts que se midió');

        $reproducidos = $this->reproducirTodos();
        $this->assertCount(25, $reproducidos);
        $m = self::medir($reproducidos);
        fwrite(STDERR, "\n[PT-17 c7] ".$m['line']."\n");

        // R1 · La reproducción vale: lo que pide el recorrido, el modelo lo escribió en la medición.
        foreach ($reproducidos as $r) {
            $nombre = $r['case']['case'];
            $this->assertSame('done', $r['run']['status'], "{$nombre}: el recorrido pidió algo que no se grabó (".json_encode($r['run']['class'] ?? null).')');
            $e = $r['run']['engine'];
            $grabado = $r['case']['path'];
            if ($e['path'] !== $grabado) {
                // L4: tras un segundo borrador que es la lista firme del turno, ya no hay tercero.
                $this->assertSame(['composer_retry_2', 'critic_3'], array_slice($grabado, -2), "{$nombre}: solo se acorta el tercer borrador");
                $this->assertSame(array_slice($grabado, 0, -2), $e['path'], "{$nombre}: el recorrido grabado sin el tercer borrador");
                $this->assertSame([false, StudioLabEngineCheck::REASON_LIST_FINAL], [$e['drafts'][1]['check']['mayRewrite'], $e['drafts'][1]['check']['reason']], "{$nombre}: lo acorta la lista firme");
            }
            $this->assertSame(array_intersect_key($r['case']['strategy'], array_flip(['intent', 'nextState', 'toolsRequested', 'handoffAllowed', 'corrections'])), $e['strategy'], "{$nombre}: la estrategia validada");
            foreach ($e['drafts'] as $i => $d) {
                $g = $r['case']['drafts'][$i];
                $this->assertSame([$g['n'], $g['text'], $g['chars']], [$d['n'], $d['text'], $d['chars']], "{$nombre}: el borrador {$g['n']}, con la medida grabada");
            }
        }

        // R2 · Lo que mide PT-17 en las 20 del turno de planes, y la combinada.
        $this->assertSame(18, $m['model'], 'escritas por el modelo con la lista válida · '.$m['line']);
        $this->assertSame(['44/200/E-001', '46/213/E-002'], $m['fallbackCases'], 'respaldo solo en los dos casos con un defecto del servidor · '.$m['line']);
        $this->assertSame(['plans_overview' => 2], $m['fallbacks'], $m['line']);
        $this->assertSame([], $m['unjustified'], 'ningún respaldo sin un defecto del servidor ni un fallo grave · '.$m['line']);
        $this->assertSame([], $m['trimmed'], 'ningún recorte · '.$m['line']);
        $this->assertLessThanOrEqual(320, $m['maxChars'], 'la longitud de lo que escribe el modelo · '.$m['line']);
        $this->assertSame(308, $m['maxChars'], 'la de la reproducción del diagnóstico · '.$m['line']);
        $this->assertSame(5, $m['combined'], 'la combinada · '.$m['line']);
        $this->assertSame(0, $m['first'], 'el primer borrador no lo cambia ninguna regla del servidor · '.$m['line']);

        $avisos = 0;
        foreach ($reproducidos as $r) {
            $nombre = $r['case']['case'];
            $final = $r['run']['engine']['final'];
            $ultimo = $r['run']['engine']['drafts'][$final['draft'] - 1];
            if ($final['origin'] === StudioLabEngineCheck::ORIGIN_FALLBACK) {
                // Los dos que quedan: `{{PLAN_PRICE}}` dentro de la lista, que el servidor no deja salir (C3).
                $this->assertSame('plans_overview', $final['codes'][0] ?? null, "{$nombre}: un defecto del servidor");
                $this->assertMatchesRegularExpression('/\{\{\s*PLAN_PRICE\s*\}\}/', $ultimo['text'], $nombre);
                $this->assertArrayNotHasKey('criticAdvisory', $final, $nombre);

                continue;
            }
            // Del modelo: o el revisor aprobó, o rechazó sin fallo grave una lista sin defectos (L4) y va el aviso.
            $rechazado = ($ultimo['critic']['verdict'] ?? null) === 'fail';
            $this->assertSame($rechazado, ($final['criticAdvisory'] ?? false) === true, "{$nombre}: el aviso, solo si el revisor lo rechazó");
            if ($rechazado) {
                $avisos++;
                $this->assertNull($ultimo['critic']['hardFail'], "{$nombre}: un rechazo blando");
                $this->assertSame([], array_values(array_intersect($ultimo['critic']['issues'], CriticContract::VETO_DIMENSIONS)), "{$nombre}: sin seguridad, exactitud ni política (P-1 A)");
                $this->assertSame([], $final['codes'], "{$nombre}: una lista sin defectos del servidor");
            }
        }
        $this->assertSame(11, $avisos, 'los textos del modelo que salen con el aviso del revisor (10 del turno de planes y 1 de la combinada)');
    }

    // ── R3 ──────────────────────────────────────────────────────────────────

    public function test_r3_un_solo_tope_por_turno_de_lista_y_la_nota_de_la_lista(): void
    {
        $reproducidos = $this->reproducirTodos();
        $deUnPlan = 0;
        $longitud = [];
        foreach ($reproducidos as $r) {
            $nombre = $r['case']['case'];
            $e = $r['run']['engine'];
            $this->assertSame([320, false], [$e['final']['cap'], $e['final']['trimmed']], "{$nombre}: lo que iría al commit, contra el tope de la lista y entero");
            foreach ($e['drafts'] as $d) {
                $this->assertSame(320, $d['cap'], "{$nombre}: el borrador {$d['n']}, contra el tope de la lista");
                if ($d['check'] === null) {
                    continue;
                }
                $this->assertStringNotContainsString('limite publicado es 200', $d['check']['notes'], "{$nombre}/{$d['n']}: un solo tope");
                $this->assertStringNotContainsString(' de 160', $d['check']['notes'], "{$nombre}/{$d['n']}: un solo tope");
                if (count(self::planesDe($d['text'])) < 2) {
                    $deUnPlan++;
                    $this->assertContains('plans_overview/missing', $d['check']['codes'], "{$nombre}/{$d['n']}: habla de un solo plan");
                    $this->assertStringStartsWith('FALTA LA LISTA DE PLANES', $d['check']['notes'], "{$nombre}/{$d['n']}: la nota dice que falta la lista");
                    $this->assertStringContainsString('Ya expandidos ocupan 71 caracteres: quedan 179 de 250.', $d['check']['notes'], "{$nombre}/{$d['n']}: con el tope de la lista");
                }
            }
            // El aviso de longitud del laboratorio, con la medida y el tope que aplica el servidor (antes, 22 de 25).
            $hallazgos = StudioLabChecks::check((string) $r['run']['reply'], ['configs' => ['persona' => ['tone' => 'profesional', 'maxChars' => 200]]], $e['final']);
            if (in_array(StudioLabChecks::ID_LENGTH, array_column($hallazgos, 'id'), true)) {
                $longitud[] = $nombre;
            }
        }
        $this->assertSame(29, $deUnPlan, 'los borradores comprobados que hablan de un solo plan (de 50)');
        $this->assertSame(['44/200/E-001'], $longitud, 'el aviso de longitud, solo donde el servidor ve más de 320 (365: va al respaldo)');
        $this->assertSame(22, count(array_filter(self::fixture()['cases'], static fn (array $c): bool => in_array(StudioLabChecks::ID_LENGTH, array_column($c['labChecks'], 'id'), true))), 'en la medición avisaba en 22 de 25');
    }

    // ── R4 ──────────────────────────────────────────────────────────────────

    public function test_r4_las_pistas_de_un_turno_de_lista_en_los_seis_escenarios(): void
    {
        $f = self::fixture();
        $sp = self::paquete($f['engineTarget']['version'])['sp'];
        foreach ($f['scenarios'] as $codigo => $escenario) {
            $ctx = app(StudioLabEngineContext::class)->build($escenario, $sp);
            $this->assertSame($f['po'], $ctx['turn']['po'], "{$codigo}: el servidor ofrece la lista");
            $h = $ctx['decide']['context']['strategy_hints'];
            $this->assertSame(
                ['should_recommend_now' => false, 'may_ask' => [], 'question_budget' => 0, 'suggested_question' => null],
                array_intersect_key($h, array_flip(['should_recommend_now', 'question_budget', 'may_ask', 'suggested_question'])),
                "{$codigo}: el turno responde con la lista",
            );
            // Y Validar Estrategia (el port del Code de n8n) no le deja preguntar aunque el estratega quiera.
            $v = StudioLabEngineFlow::validateStrategy($ctx['decide'], ['output' => [['content' => [['text' => [
                'intent' => 'pricing_question', 'next_state' => 'NEW_LEAD', 'recommended_plan_id' => 3, 'tools_requested' => [],
                'information_needed' => ['objective', 'experience_level'], 'question_needed' => true,
            ]]]]]]);
            $this->assertSame([[], false], [$v['strategy']['information_needed'], $v['strategy']['question_needed']], "{$codigo}: sin preguntas");
        }
    }

    // ── Andamio ─────────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private static function fixture(): array
    {
        return json_decode((string) file_get_contents(self::FIXTURE), true, 32, JSON_THROW_ON_ERROR);
    }

    /** @return array{roles:array<string,string>,sp:array<string,mixed>,bundleHash:string} el paquete que firma el Studio para la v4 con el compilador de hoy */
    private static function paquete(string $version): array
    {
        $parte = self::parteV2($version, self::V4);

        return StudioPromptBundle::rolesFor($parte['governed'], $version, $parte['contentHash']);
    }

    /**
     * Los planes de la lista de los que habla un texto, por su marcador o a
     * mano (lectura propia de la prueba, sin `UltronPlansOverview`).
     *
     * @return list<int>
     */
    private static function planesDe(string $texto): array
    {
        $po = self::fixture()['po'];
        preg_match_all('/\{\{\s*PLAN_([1-3])\s*\}\}/', $texto, $m);
        $planes = array_map(static fn (string $k): int => $po[(int) $k - 1], $m[1]);
        foreach (self::NOMBRES as $id => $patron) {
            if (preg_match($patron, $texto) === 1) {
                $planes[] = $id;
            }
        }

        return array_values(array_unique($planes));
    }

    /**
     * Lo que devolvió cada nodo de OpenAI en ese caso: el estratega (con lo
     * que grabó el motor de su estrategia validada; lo demás no lo mira el
     * servidor), cada redactor y cada revisor que corrió.
     *
     * @param  array<string,mixed>  $caso
     * @return array<string,array<string,mixed>>
     */
    private static function salidas(array $caso): array
    {
        $e = $caso['strategy'];
        $out = [StudioLabEngineFlow::STRATEGIST => [
            'strategy_goal' => 'informar', 'next_state' => $e['nextState'], 'intent' => $e['intent'], 'recommended_plan_id' => $e['recommendedPlanId'],
            'main_barrier' => null, 'next_best_action' => 'answer', 'confidence' => 0.9, 'tools_requested' => $e['toolsRequested'], 'staff_review_reason' => null,
            'reason' => 'reproduccion de la medicion', 'human_handoff_requested' => false, 'human_handoff_reason' => null, 'human_handoff_evidence' => null,
            'conversation_goal' => 'recommend', 'information_needed' => [], 'question_needed' => false, 'response_goal' => 'presentar los planes de la lista',
            'buying_signal' => 'weak', 'closing_opportunity' => false, 'payment_opportunity' => false, 'app_support_opportunity' => false, 'recommendation_goal' => 'compare_plans',
        ]];
        foreach ($caso['drafts'] as $d) {
            preg_match_all('/\{\{\s*PLAN_[1-3]\s*\}\}/', $d['text'], $m);
            $out[StudioLabEngineFlow::COMPOSERS[$d['n'] - 1]] = [
                'reply_draft' => $d['text'],
                'uses_price_placeholder' => preg_match('/\{\{\s*PLAN_PRICE\s*\}\}/', $d['text']) === 1,
                'questions_asked' => substr_count($d['text'], '?'),
                'plans_mentioned' => count(array_unique($m[0])),
            ];
            if ($d['critic'] !== null) {
                $out[StudioLabEngineFlow::CRITICS[$d['n'] - 1]] = ['verdict' => $d['critic']['verdict'], 'issues' => $d['critic']['issues'], 'notes' => $d['critic']['notes'], 'hard_fail' => $d['critic']['hardFail']];
            }
        }

        return $out;
    }

    /**
     * Un caso por el motor del laboratorio, con lo grabado como modelo.
     *
     * @param  array<string,mixed>  $caso
     * @param  array<string,mixed>  $escenario
     * @param  array<string,mixed>  $paquete
     * @return array{run:array<string,mixed>,asked:list<string>}
     */
    private function reproducir(array $caso, array $escenario, array $paquete): array
    {
        $cliente = new class(self::salidas($caso)) implements StudioLabEngineModelClient
        {
            /** @var list<string> */
            public array $asked = [];

            /** @param array<string,array<string,mixed>> $salidas */
            public function __construct(private readonly array $salidas) {}

            public function completeEngine(string $node, string $system, string $user, int $timeoutSeconds): array
            {
                $this->asked[] = $node;
                if (! array_key_exists($node, $this->salidas)) {
                    // El recorrido pide algo que el modelo no escribió en la medición: la reproducción no vale.
                    return ['status' => StudioLabModelClient::STATUS_ERROR, 'output' => null, 'usage' => null, 'class' => 'not_recorded', 'http' => null, 'latencyMs' => 0, 'exception' => null];
                }

                return ['status' => StudioLabModelClient::STATUS_OK, 'output' => $this->salidas[$node], 'usage' => ['inputTokens' => 0, 'outputTokens' => 0, 'reasoningTokens' => 0], 'class' => null, 'http' => null, 'latencyMs' => 0, 'exception' => null];
            }
        };
        $run = app(StudioLabEngineCase::class)->run($paquete, $escenario, $cliente, static fn (): int => 0);

        return ['run' => $run, 'asked' => $cliente->asked];
    }

    /**
     * Los 25 casos reproducidos, en el orden de la fixture.
     *
     * @return list<array{case:array<string,mixed>,run:array<string,mixed>,asked:list<string>}>
     */
    private function reproducirTodos(): array
    {
        $f = self::fixture();
        $paquete = self::paquete($f['engineTarget']['version']);
        $out = [];
        foreach ($f['cases'] as $caso) {
            $out[] = ['case' => $caso] + $this->reproducir($caso, $f['scenarios'][substr($caso['case'], -5)], $paquete);
        }

        return $out;
    }

    /**
     * Lo que mide PT-17 sobre lo reproducido, con las reglas del script de
     * aceptación (`aceptacion-pt17.py`): válida = del modelo y sin código de la
     * lista; injustificado = respaldo sin ningún código del servidor ni fallo
     * grave del revisor.
     *
     * @param  list<array{case:array<string,mixed>,run:array<string,mixed>}>  $reproducidos
     * @return array{model:int,first:int,fallbacks:array<string,int>,fallbackCases:list<string>,unjustified:list<string>,trimmed:list<string>,maxChars:int,combined:int,line:string}
     */
    private static function medir(array $reproducidos): array
    {
        $planes = array_values(array_filter($reproducidos, static fn (array $r): bool => $r['case']['group'] === 'planes'));
        $combinada = array_values(array_filter($reproducidos, static fn (array $r): bool => $r['case']['group'] === 'combinada'));
        $final = static fn (array $r): array => (array) ($r['run']['engine']['final'] ?? []);
        $valida = static fn (array $r): bool => ($final($r)['origin'] ?? null) === StudioLabEngineCheck::ORIGIN_MODEL
            && array_filter((array) ($final($r)['codes'] ?? []), static fn (string $c): bool => str_starts_with($c, 'plans_overview')) === [];
        $respaldos = array_values(array_filter($planes, static fn (array $r): bool => ($final($r)['origin'] ?? null) !== StudioLabEngineCheck::ORIGIN_MODEL));
        $causas = [];
        foreach ($respaldos as $r) {
            $causa = (string) ($final($r)['cause'] ?? 'sin resultado');
            $causas[$causa] = ($causas[$causa] ?? 0) + 1;
        }
        ksort($causas);
        $graves = static fn (array $r): bool => array_filter((array) ($r['run']['engine']['drafts'] ?? []), static fn (array $d): bool => ($d['critic']['hardFail'] ?? null) !== null) !== [];
        $injustificados = array_values(array_filter($respaldos, static fn (array $r): bool => ($final($r)['codes'] ?? []) === [] && ! $graves($r)));
        $recortados = array_values(array_filter($planes, static fn (array $r): bool => ($final($r)['trimmed'] ?? false) === true));
        $largos = array_map(static fn (array $r): int => (int) $final($r)['chars'], array_values(array_filter([...$planes, ...$combinada], static fn (array $r): bool => ($final($r)['origin'] ?? null) === StudioLabEngineCheck::ORIGIN_MODEL)));
        $primer = count(array_filter($planes, static fn (array $r): bool => ($r['run']['engine']['drafts'][0]['check']['pass'] ?? null) === true));
        $nombres = static fn (array $rs): array => array_map(static fn (array $r): string => $r['case']['case'], $rs);

        $m = [
            'model' => count(array_filter($planes, $valida)),
            'first' => $primer,
            'fallbacks' => $causas,
            'fallbackCases' => $nombres($respaldos),
            'unjustified' => $nombres($injustificados),
            'trimmed' => $nombres($recortados),
            'maxChars' => $largos === [] ? 0 : max($largos),
            'combined' => count(array_filter($combinada, $valida)),
        ];
        $m['line'] = sprintf(
            'planes: %d de %d escritas por el modelo con la lista válida · válidas al primer borrador %d · respaldos %d %s · injustificados %d · recortes %d · máx. %d · combinada %d de %d',
            $m['model'], count($planes), $m['first'], count($respaldos), json_encode($causas), count($injustificados), count($recortados), $m['maxChars'], $m['combined'], count($combinada),
        );

        return $m;
    }
}
