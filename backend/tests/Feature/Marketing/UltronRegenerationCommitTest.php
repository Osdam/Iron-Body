<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingAiAction;
use App\Models\MarketingConversation;
use App\Models\MarketingKnowledgeItem;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Models\Plan;
use App\Services\Marketing\CommercialPhaseMachine as P;
use App\Services\Marketing\SalesIntents;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\UltronTurnEvents;
use Tests\TestCase;
use Tests\Unit\Marketing\Ultron\Prompts\PromptPublications;

/**
 * A4 · «REGENERAR ANTES DE SUSTITUIR», OLA 4: EL COMMIT ACEPTA LA TRAZA DE LAS
 * REESCRITURAS (plan `plan-arquitecto-a4-regenerar.md` §3 P-2, P-7 y P-8,
 * §4.3 y §6 «Ola 4»), por el endpoint y con las clases reales.
 *
 *  - El bloque `regeneration` es TRAZA, como `prompts`: nada del commit se
 *    ramifica por él. Con el bloque, la fila lleva `metadata.regeneration`;
 *    sin él, la fila es la de hoy; con un bloque incoherente, solo cambia
 *    `consistent` (false): el mismo texto y los mismos efectos.
 *  - `critic.attempt` admite hasta 3 (como mucho dos reescrituras).
 *  - La forma, por lista blanca: un valor fuera del contrato es un 422 de
 *    validación; una clave de más, también dentro del bloque y de cada
 *    reescritura, un 422 `unexpected_fields`.
 *
 * Lo que la fila tenía antes sin el bloque lo fijan además las dos
 * instantáneas Legacy de A4 (`tests/fixtures/ultron-a4/`), sin regenerar.
 *
 * CORRECCIONES TRAS LAS REVISIONES (C5, R-4 de seguridad): la traza se guarda
 * SOLO si el token de `decide` de ese turno lleva la oferta del Studio
 * (`sp.m = studio`, que es cuando `decide` ofreció `regeneration`), aunque la
 * versión se haya revertido entre `decide` y el commit. Sin la oferta, el bloque
 * no se guarda y queda un aviso; un `critic.attempt` de 3 se guarda tal cual,
 * con su aviso (es una anomalía de n8n que conviene ver).
 */
class UltronRegenerationCommitTest extends TestCase
{
    use PromptPublications;
    use RefreshDatabase;
    use UltronTurnEvents;

    private const SECRET = 'test-internal-secret';

    private const VERSION = 'c0000000-0000-4000-8000-0000000000d4';

    private const CATALOGO = [
        [25, 'PLAN DÍA', 10000, 1],
        [2, 'Plan Semana', 45000, 7],
        [3, 'Plan Valera', 65000, 30],
        [4, 'Plan Mensual', 80000, 30],
        [5, 'Trimestre', 210000, 90],
        [8, 'Élite', 180000, 30],
        [14, 'PROMO X4', 75000, 30],
    ];

    private const PANORAMA_CON_PRECIO = '¿Qué planes tienen y cuánto cuesta la mensualidad?';

    private const TRES = 'Claro. Tenemos {{PLAN_1}}, {{PLAN_2}} y {{PLAN_3}}, todos de un mes. ¿Cuál le interesa más?';

    private const TRES_EXPANDIDO = 'Claro. Tenemos Plan Valera ($65.000 COP), Plan Mensual ($80.000 COP) y Élite ($180.000 COP), todos de un mes. ¿Cuál le interesa más?';

    /** Un borrador sin los marcadores de la lista, que vale también sin la oferta del Studio (C5). */
    private const SIN_MARCADORES = 'Claro. El Plan Valera y el Plan Mensual son de un mes. ¿Cuál le interesa?';

    /** Lo que mandaría S2 tras dos reescrituras: una del servidor y otra del revisor. */
    private const BLOQUE = [
        'contract' => 'ultron-regen/1',
        'drafts' => 3,
        'critics' => 2,
        'checks' => 2,
        'check_errors' => 0,
        'rewrites' => [
            ['by' => 'server', 'codes' => ['register_tuteo', 'length_over_cap'], 'issues' => ['naturalness', 'progressive_disclosure']],
            ['by' => 'critic', 'codes' => [], 'issues' => ['relevance']],
        ],
        'tokens' => ['input' => 41000, 'output' => 900],
    ];

    private const TRAZA = [
        'drafts' => 3,
        'critics' => 2,
        'checks' => 2,
        'check_errors' => 0,
        'rewrites' => [
            ['by' => 'server', 'codes' => ['register_tuteo', 'length_over_cap'], 'issues' => ['naturalness', 'progressive_disclosure']],
            ['by' => 'critic', 'codes' => [], 'issues' => ['relevance']],
        ],
        'tokens' => ['input' => 41000, 'output' => 900],
        'consistent' => true,
    ];

    private MarketingConversation $conversation;

    private int $mundo = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-18 22:47:00', 'UTC'));
        config()->set('marketing.ultron.enabled', true);
        config()->set('automation.internal_secret', self::SECRET);
        config()->set('meta.enabled', false);
        config()->set('marketing.ai.enabled', true);
        config()->set('marketing.ai.driver', 'fake');
        config()->set('marketing.inbound.auto_analyze', false);
        config()->set('marketing.ultron.payment_links_enabled', false);
        config()->set('marketing.ultron.studio_config', 'canary');
        config()->set('marketing.ultron.studio_prompts', 'canary');
        config()->set('automation.rate_limits.marketing_ai', 100000);
        Http::fake();

        foreach (self::CATALOGO as $i => [$id, $nombre, $precio, $dias]) {
            (new Plan)->forceFill([
                'id' => $id, 'name' => $nombre, 'price' => $precio, 'duration_days' => $dias,
                'active' => true, 'sellable' => true, 'sort_order' => $i + 1, 'is_recommended' => false,
                'benefits' => json_encode(['Acceso total al gimnasio']),
            ])->save();
        }
        MarketingKnowledgeItem::create([
            'category' => 'schedule', 'key' => 'schedule.opening_hours', 'title' => 'Horario general de atencion',
            'content' => 'Lunes a viernes de 5:00 a. m. a 10:00 p. m. Sabados de 8:00 a. m. a 2:00 p. m. Domingos de 8:00 a. m. a 2:00 p. m. Festivos de 8:00 a. m. a 2:00 p. m.',
            'is_active' => true, 'origin' => MarketingKnowledgeItem::ORIGIN_HUMAN_PANEL,
            'review_status' => MarketingKnowledgeItem::REVIEW_APPROVED,
        ]);
        // Una sola publicación para todas las conversaciones del caso: el mismo sello en cada fila.
        $this->publicaEntrada('ultron-engine-config/2', self::parteV2(self::VERSION, ['persona.tone' => 'profesional', 'persona.maxChars' => 200]), null);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── Traza: con el bloque, sin él y con uno incoherente ──────────────────

    /**
     * m-10 del revisor: el nombre decía «la de hoy» y la prueba compara DENTRO
     * del código nuevo (con el bloque frente a sin él, en el mismo commit). Lo
     * que la fila tenía antes del cambio lo fijan las instantáneas Legacy.
     */
    public function test_con_el_bloque_la_fila_lleva_la_traza_y_sin_el_es_la_misma_fila_sin_la_clave(): void
    {
        $hoy = $this->turnoAprobado(null, ['verdict' => 'pass', 'attempt' => 1, 'score' => 0.9]);
        $conBloque = $this->turnoAprobado(self::BLOQUE, ['verdict' => 'pass', 'attempt' => 3, 'score' => 0.9]);

        $this->assertArrayNotHasKey('regeneration', $hoy['fila']->metadata, 'sin bloque, la fila es la de hoy');
        $this->assertMismaTraza(self::TRAZA, $conBloque['fila']->metadata['regeneration'] ?? null);

        // Lo demás, idéntico: el mismo texto, el mismo envío, los mismos efectos; solo cambia lo que dice la traza (y el intento del revisor que se audita).
        $this->assertSame(self::TRES_EXPANDIDO, $hoy['respuesta']['reply_final']);
        $this->assertSame($hoy['respuesta']['reply_final'], $conBloque['respuesta']['reply_final']);
        $this->assertSame([$hoy['saliente'], $hoy['fase']], [$conBloque['saliente'], $conBloque['fase']]);
        $this->assertSame(
            self::sinOrdenDeClaves(self::comparable($hoy['fila']->metadata, ['critic'])),
            self::sinOrdenDeClaves(self::comparable($conBloque['fila']->metadata, ['critic', 'regeneration'])),
        );
        $this->assertSame([1, 3], [$hoy['fila']->metadata['critic']['attempt'], $conBloque['fila']->metadata['critic']['attempt']]);
    }

    /** Un dato que no cuadra solo se marca: el mismo texto y los mismos efectos que con uno coherente. */
    public function test_un_bloque_incoherente_solo_marca_consistent_false(): void
    {
        $coherente = $this->turnoAprobado(self::BLOQUE, ['verdict' => 'pass', 'attempt' => 3, 'score' => 0.9]);
        $mentiroso = $this->turnoAprobado(self::BLOQUE, ['verdict' => 'pass', 'attempt' => 1, 'score' => 0.9]);

        $this->assertMismaTraza(['consistent' => false] + self::TRAZA, $mentiroso['fila']->metadata['regeneration']);
        $this->assertSame($coherente['respuesta']['reply_final'], $mentiroso['respuesta']['reply_final']);
        $this->assertSame([$coherente['saliente'], $coherente['fase']], [$mentiroso['saliente'], $mentiroso['fase']]);
        $this->assertSame(
            self::sinOrdenDeClaves(self::comparable($coherente['fila']->metadata, ['critic', 'regeneration'])),
            self::sinOrdenDeClaves(self::comparable($mentiroso['fila']->metadata, ['critic', 'regeneration'])),
        );
    }

    public function test_el_tercer_intento_del_revisor_se_acepta_y_se_audita(): void
    {
        $r = $this->turnoAprobado(self::BLOQUE, ['verdict' => 'pass', 'attempt' => 3, 'score' => 0.9, 'issues' => ['relevance']]);
        $this->assertSame(3, $r['fila']->metadata['critic']['attempt']);
        $this->assertSame('executed', $r['fila']->status);

        // Y por el camino del revisor que tumba el tercer borrador: el respaldo, con el intento 3 y la traza.
        $m = $this->entranteNuevo(self::PANORAMA_CON_PRECIO);
        $token = $this->decide($m);
        $this->commit($this->payload($m, $token, self::TRES, ['verdict' => 'fail', 'attempt' => 3, 'score' => 0.2, 'issues' => ['relevance']], self::BLOQUE))->assertOk();
        $fila = $this->ultimaAccion();
        $this->assertSame([3, 'fail'], [$fila->metadata['critic']['attempt'], $fila->metadata['critic']['verdict']]);
        $this->assertMismaTraza(self::TRAZA, $fila->metadata['regeneration']);
        $this->assertArrayHasKey('fallback_mode', $fila->metadata, 'el respaldo de siempre');

        // Y en la rendición, sin veredicto en la fila: la traza, sin nada con qué cuadrar.
        $m = $this->entranteNuevo(self::PANORAMA_CON_PRECIO);
        $token = $this->decide($m);
        $this->commit(['recovery' => ['reason' => 'unknown_placeholder', 'attempt' => 3]] + $this->payload($m, $token, 'Tenemos {{PLAN_9}}.', ['verdict' => 'pass', 'attempt' => 3, 'score' => 0.9], self::BLOQUE))->assertOk();
        $fila = $this->ultimaAccion();
        $this->assertArrayHasKey('recovery', $fila->metadata);
        $this->assertMismaTraza(['consistent' => null] + self::TRAZA, $fila->metadata['regeneration']);
    }

    // ── C5 · R-4: la traza, solo con la oferta firmada ──────────────────────

    /** @return array<string, array{bool}> */
    public static function sinLaOferta(): array
    {
        return ['sin negociar' => [false], 'prompts del Studio apagados' => [true]];
    }

    /**
     * Sin la oferta en el token, `decide` no ofreció `regeneration`: si n8n
     * manda el bloque igual, no se guarda y queda un aviso (sin el bloque). El
     * turno sale como sin él. El tercer intento del revisor se guarda tal cual,
     * con su aviso.
     */
    #[DataProvider('sinLaOferta')]
    public function test_sin_la_oferta_firmada_la_traza_no_se_guarda_y_queda_un_aviso(bool $negocia): void
    {
        if ($negocia) {
            config()->set('marketing.ultron.studio_prompts', 'off');
        }
        $avisos = $this->escuchaLosAvisos();
        $m = $this->entranteNuevo(self::PANORAMA_CON_PRECIO);
        $token = $this->decideSinLaOferta($m, $negocia);

        $this->commit($this->payload($m, $token, self::SIN_MARCADORES, ['verdict' => 'pass', 'attempt' => 3, 'score' => 0.9], self::BLOQUE))->assertOk();

        $fila = $this->ultimaAccion();
        $this->assertArrayNotHasKey('regeneration', $fila->metadata, 'sin la oferta, sin la traza');
        $this->assertSame(3, $fila->metadata['critic']['attempt'], 'el intento, tal cual');
        $id = $this->conversation->id;
        $this->assertSame([
            ['warning', 'ultron.commit.regeneration_not_offered', ['conversation_id' => $id]],
            ['warning', 'ultron.commit.critic_attempt_not_offered', ['conversation_id' => $id, 'attempt' => 3]],
        ], $avisos(), 'los dos avisos, y sin el bloque');

        // Sin el bloque y con el intento de siempre: ni traza ni avisos (lo de hoy).
        $m = $this->entranteNuevo(self::PANORAMA_CON_PRECIO);
        $token = $this->decideSinLaOferta($m, $negocia);
        $avisos = $this->escuchaLosAvisos();
        $this->commit($this->payload($m, $token, self::SIN_MARCADORES, ['verdict' => 'pass', 'attempt' => 2, 'score' => 0.9], null))->assertOk();
        $this->assertArrayNotHasKey('regeneration', $this->ultimaAccion()->metadata);
        $this->assertSame([], $avisos());
    }

    /**
     * Con la oferta firmada, la traza se guarda aunque la versión se haya
     * revertido entre `decide` y el commit (aquí, la cadena del Studio y sus
     * prompts apagados después de `decide`): lo que hizo n8n es verdad, y lo
     * ofreció `decide`. Sin avisos.
     */
    public function test_con_la_oferta_firmada_la_traza_se_guarda_aunque_la_version_se_haya_revertido(): void
    {
        $m = $this->entranteNuevo(self::PANORAMA_CON_PRECIO);
        $token = $this->decide($m);
        config()->set('marketing.ultron.studio_config', 'off');
        config()->set('marketing.ultron.studio_prompts', 'off');
        $avisos = $this->escuchaLosAvisos();

        $this->commit($this->payload($m, $token, self::TRES, ['verdict' => 'pass', 'attempt' => 3, 'score' => 0.9], self::BLOQUE))->assertOk();

        $this->assertMismaTraza(self::TRAZA, $this->ultimaAccion()->metadata['regeneration'] ?? null);
        $this->assertArrayNotHasKey('studio', $this->ultimaAccion()->metadata, 'la versión se revirtió: la fila ya no lleva su sello');
        $this->assertSame([], $avisos());
    }

    /**
     * Una fila bloqueada (aquí, la persona escribió otra vez: `superseded`) se
     * escribe ANTES de verificar el token: la oferta se lee del token firmado
     * igual, con `peek()`. Con ella, la traza; sin ella, no, con su aviso.
     */
    public function test_una_fila_bloqueada_guarda_la_traza_solo_con_la_oferta(): void
    {
        $m = $this->entranteNuevo(self::PANORAMA_CON_PRECIO);
        $token = $this->decide($m);
        $this->otroEntrante('Y otra cosa');
        $this->commit($this->payload($m, $token, self::TRES, ['verdict' => 'pass', 'attempt' => 3, 'score' => 0.9], self::BLOQUE))->assertOk()->assertJsonPath('outcome', 'blocked');
        // La fila bloqueada no lleva veredicto: la traza, sin nada con qué cuadrar.
        $this->assertMismaTraza(['consistent' => null] + self::TRAZA, $this->ultimaAccion()->metadata['regeneration'] ?? null);

        $m = $this->entranteNuevo(self::PANORAMA_CON_PRECIO);
        $token = $this->decideSinLaOferta($m, false);
        $this->otroEntrante('Y otra cosa');
        $avisos = $this->escuchaLosAvisos();
        $this->commit($this->payload($m, $token, self::SIN_MARCADORES, ['verdict' => 'pass', 'attempt' => 3, 'score' => 0.9], self::BLOQUE))->assertOk()->assertJsonPath('outcome', 'blocked');
        $this->assertArrayNotHasKey('regeneration', $this->ultimaAccion()->metadata);
        $this->assertSame([['warning', 'ultron.commit.regeneration_not_offered', ['conversation_id' => $this->conversation->id]]], $avisos());
    }

    // ── La forma (§4.3) ─────────────────────────────────────────────────────

    /** @return array<string, array{\Closure, string}> */
    public static function bloquesQueNoValen(): array
    {
        $con = fn (array $cambio) => fn (array $b) => array_replace($b, $cambio);

        return [
            'cuarto intento del revisor' => [fn (array $p) => array_replace_recursive($p, ['critic' => ['attempt' => 4]]), 'critic.attempt'],
            'bloque como texto' => [fn (array $p) => ['regeneration' => 'ultron-regen/1'] + $p, 'regeneration'],
            'otro contrato' => [self::enElBloque($con(['contract' => 'ultron-regen/2'])), 'regeneration.contract'],
            'sin contrato' => [self::enElBloque(fn (array $b) => array_diff_key($b, ['contract' => 1])), 'regeneration.contract'],
            'sin borradores' => [self::enElBloque(fn (array $b) => array_diff_key($b, ['drafts' => 1])), 'regeneration.drafts'],
            'cero borradores' => [self::enElBloque($con(['drafts' => 0])), 'regeneration.drafts'],
            'cuatro borradores' => [self::enElBloque($con(['drafts' => 4])), 'regeneration.drafts'],
            'cuatro revisores' => [self::enElBloque($con(['critics' => 4])), 'regeneration.critics'],
            'tres comprobaciones' => [self::enElBloque($con(['checks' => 3])), 'regeneration.checks'],
            'tres errores de comprobación' => [self::enElBloque($con(['check_errors' => 3])), 'regeneration.check_errors'],
            'tres reescrituras' => [self::enElBloque($con(['rewrites' => [['by' => 'server'], ['by' => 'critic'], ['by' => 'critic']]])), 'regeneration.rewrites'],
            'reescrituras como objeto' => [self::enElBloque($con(['rewrites' => ['a' => ['by' => 'server']]])), 'regeneration.rewrites'],
            'reescritura de otro' => [self::enElBloque($con(['rewrites' => [['by' => 'model']]])), 'regeneration.rewrites.0.by'],
            'reescritura sin autor' => [self::enElBloque($con(['rewrites' => [['codes' => ['register_tuteo']]]])), 'regeneration.rewrites.0.by'],
            // Ola 4b: `policy_greeting_sells` ya es un código corregible; el saludo repetido (lo arregla el commit) no lo es.
            'código inventado' => [self::enElBloque($con(['rewrites' => [['by' => 'server', 'codes' => ['policy_greeting_repeated']]]])), 'regeneration.rewrites.0.codes.0'],
            // Ola 4b: el tope sube de 4 a `UltronDraftDefects::MAX_DEFECTS` (8): nueve códigos válidos no caben.
            'nueve códigos' => [self::enElBloque($con(['rewrites' => [['by' => 'server', 'codes' => ['register_tuteo', 'length_over_cap', 'plans_overview', 'benefit_misattributed', 'policy_greeting_sells', 'policy_active_goal_lost', 'policy_wrong_first_plan', 'policy_answer_without_fact', 'policy_general_info_only_discovery']]]])), 'regeneration.rewrites.0.codes'],
            'issue inventado' => [self::enElBloque($con(['rewrites' => [['by' => 'critic', 'issues' => ['vibes']]]])), 'regeneration.rewrites.0.issues.0'],
            'nueve issues' => [self::enElBloque($con(['rewrites' => [['by' => 'critic', 'issues' => ['relevance', 'empathy', 'naturalness', 'context_use', 'sales_judgment', 'pressure_control', 'next_step', 'question_discipline', 'repetition']]]])), 'regeneration.rewrites.0.issues'],
            'tokens negativos' => [self::enElBloque($con(['tokens' => ['input' => -1, 'output' => 3]])), 'regeneration.tokens.input'],
            'tokens de más' => [self::enElBloque($con(['tokens' => ['input' => 1, 'output' => 2000001]])), 'regeneration.tokens.output'],
        ];
    }

    #[DataProvider('bloquesQueNoValen')]
    public function test_la_forma_del_bloque_se_valida(\Closure $estropea, string $campo): void
    {
        $m = $this->entranteNuevo(self::PANORAMA_CON_PRECIO);
        $token = $this->decide($m);

        $r = $this->commit($estropea($this->payload($m, $token, self::TRES, ['verdict' => 'pass', 'attempt' => 3, 'score' => 0.9], self::BLOQUE)));

        $r->assertStatus(422)->assertJsonValidationErrors([$campo]);
        $this->assertSame(0, MarketingAiAction::query()->where('conversation_id', $this->conversation->id)->count(), 'antes de escribir nada');
    }

    public function test_una_clave_de_mas_en_el_bloque_da_unexpected_fields(): void
    {
        foreach ([
            'regeneration.notes' => fn (array $b) => $b + ['notes' => 'REESCRIBE DE USTED'],
            'regeneration.rewrites.1.text' => function (array $b) {
                $b['rewrites'][1]['text'] = 'Hola Ramiro, te escribo';

                return $b;
            },
            'regeneration.tokens.cost' => function (array $b) {
                $b['tokens']['cost'] = 3;

                return $b;
            },
        ] as $campo => $estropea) {
            $m = $this->entranteNuevo(self::PANORAMA_CON_PRECIO);
            $token = $this->decide($m);

            $this->commit(self::enElBloque($estropea)($this->payload($m, $token, self::TRES, ['verdict' => 'pass', 'attempt' => 3, 'score' => 0.9], self::BLOQUE)))
                ->assertStatus(422)->assertJsonPath('code', 'unexpected_fields')->assertJsonPath('fields', [$campo]);
            $this->assertSame(0, MarketingAiAction::query()->where('conversation_id', $this->conversation->id)->count(), $campo);
        }
    }

    // ── Andamio ──────────────────────────────────────────────────────────────

    private static function enElBloque(\Closure $cambia): \Closure
    {
        return function (array $p) use ($cambia): array {
            $p['regeneration'] = $cambia($p['regeneration']);

            return $p;
        };
    }

    /**
     * Un turno aprobado del panorama en una conversación nueva (el mismo mundo,
     * el mismo instante), con o sin el bloque.
     *
     * @return array{respuesta:array<string,mixed>,fila:MarketingAiAction,saliente:?string,fase:?string}
     */
    private function turnoAprobado(?array $bloque, array $critico): array
    {
        $m = $this->entranteNuevo(self::PANORAMA_CON_PRECIO);
        $token = $this->decide($m);
        $respuesta = $this->commit($this->payload($m, $token, self::TRES, $critico, $bloque))->assertOk()->json();
        $conversacion = $this->conversation->fresh();

        return [
            'respuesta' => $respuesta,
            'fila' => $this->ultimaAccion(),
            'saliente' => MarketingMessage::query()->where('conversation_id', $conversacion->id)
                ->where('direction', MarketingMessage::DIRECTION_OUTBOUND)->latest('id')->value('body'),
            'fase' => $conversacion->commercial_phase ?? null,
        ];
    }

    /** La metadata sin lo que es de cada conversación (los ids del entrante y del saliente) ni las claves que se comparan aparte. */
    private static function comparable(array $metadata, array $aparte): array
    {
        unset($metadata['outbound']['message_id']);

        return array_diff_key($metadata, array_flip(['message_id', ...$aparte]));
    }

    private function assertMismaTraza(array $esperada, mixed $real): void
    {
        $this->assertIsArray($real);
        $this->assertSame(self::sinOrdenDeClaves($esperada), self::sinOrdenDeClaves($real));
    }

    private static function sinOrdenDeClaves(mixed $valor): mixed
    {
        if (! is_array($valor)) {
            return $valor;
        }
        $valor = array_map(self::sinOrdenDeClaves(...), $valor);
        if (! array_is_list($valor)) {
            ksort($valor);
        }

        return $valor;
    }

    private function entranteNuevo(string $texto): MarketingMessage
    {
        $n = str_pad((string) ++$this->mundo, 3, '0', STR_PAD_LEFT);
        $lead = MarketingLead::create([
            'channel' => 'whatsapp', 'source' => 'inbound',
            'phone' => '3150540'.$n, 'meta_user_id' => '573150540'.$n,
            'name' => 'Prospecto', 'status' => MarketingLead::STATUS_NEW,
        ]);
        $this->conversation = MarketingConversation::create([
            'lead_id' => $lead->id, 'channel' => 'whatsapp', 'status' => 'open',
            'ai_enabled' => true, 'human_takeover' => false,
        ]);
        config()->set('marketing.ultron.canary_conversation_id', $this->conversation->id);

        return MarketingMessage::create([
            'conversation_id' => $this->conversation->id,
            'direction' => MarketingMessage::DIRECTION_INBOUND,
            'sender_type' => MarketingMessage::SENDER_LEAD,
            'body' => $texto, 'meta_message_id' => 'wamid.rgc.'.$n, 'status' => 'received',
        ]);
    }

    private function decide(MarketingMessage $m): string
    {
        $decide = $this->postJson('/api/internal/marketing/ai/decide', [
            'conversation_id' => $this->conversation->id, 'message_id' => $m->id, 'prompt_contract' => 'ultron-prompts/1',
        ], $this->headers())->assertOk()->json();
        $this->assertSame(['contract' => 'ultron-regen/1', 'max_drafts' => 3], $decide['regeneration'] ?? null, 'decide ofrece el contrato');

        return $decide['decide_token'];
    }

    /** `decide` de un turno sin la oferta del Studio: sin negociar, o negociado con los prompts del Studio apagados. */
    private function decideSinLaOferta(MarketingMessage $m, bool $negocia): string
    {
        $decide = $this->postJson('/api/internal/marketing/ai/decide', [
            'conversation_id' => $this->conversation->id, 'message_id' => $m->id,
        ] + ($negocia ? ['prompt_contract' => 'ultron-prompts/1'] : []), $this->headers())->assertOk()->json();
        $this->assertArrayNotHasKey('regeneration', $decide, 'decide no ofrece el contrato');
        $this->assertNotSame('studio', self::cargaDelToken($decide['decide_token'])['x']['sp']['m'] ?? null);

        return $decide['decide_token'];
    }

    /** Otro mensaje de la persona en la misma conversación, con su turno abierto: releva al anterior (`superseded`). */
    private function otroEntrante(string $texto): MarketingMessage
    {
        $m = MarketingMessage::create([
            'conversation_id' => $this->conversation->id,
            'direction' => MarketingMessage::DIRECTION_INBOUND,
            'sender_type' => MarketingMessage::SENDER_LEAD,
            'body' => $texto, 'meta_message_id' => 'wamid.rgc.otro.'.uniqid(), 'status' => 'received',
        ]);
        $this->abreTurno($m);

        return $m;
    }

    /**
     * Los avisos de la traza desde ahora: [nivel, evento, contexto sin el sobre
     * de `ChannelLog`].
     *
     * @return \Closure(): list<array{string,string,array<string,mixed>}>
     */
    private function escuchaLosAvisos(): \Closure
    {
        $avisos = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$avisos): void {
            if (in_array($e->message, ['ultron.commit.regeneration_not_offered', 'ultron.commit.critic_attempt_not_offered'], true)) {
                $avisos[] = [$e->level, $e->message, array_diff_key($e->context, array_flip(['event', 'service', 'environment', 'release', 'host', 'correlation_id', 'agent_run_id']))];
            }
        });

        return function () use (&$avisos): array {
            $vistos = $avisos;
            $avisos = [];

            return $vistos;
        };
    }

    private function payload(MarketingMessage $m, string $token, string $borrador, array $critico, ?array $bloque): array
    {
        return [
            'source_type' => 'inbound_message',
            'source_event_id' => $m->id,
            'idempotency_key' => $m->meta_message_id,
            'conversation_id' => $this->conversation->id,
            'decide_token' => $token,
            'proposal' => [
                'strategy_goal' => 'resolve_objection',
                'next_state' => P::GOAL_DISCOVERY,
                'intent' => SalesIntents::PRICING_QUESTION,
                'reply_draft' => $borrador,
                'recommended_plan_id' => null,
                'main_barrier' => null,
                'next_best_action' => 'answer',
                'confidence' => 0.9,
                'tools_requested' => [],
            ],
            'critic' => $critico,
        ] + ($bloque === null ? [] : ['regeneration' => $bloque]);
    }

    private function commit(array $payload): TestResponse
    {
        return $this->postJson('/api/internal/marketing/ai/commit', $payload, $this->headers());
    }

    private function headers(): array
    {
        return ['Authorization' => 'Bearer '.self::SECRET];
    }

    private function ultimaAccion(): MarketingAiAction
    {
        return MarketingAiAction::query()->where('conversation_id', $this->conversation->id)->latest('id')->firstOrFail();
    }
}
