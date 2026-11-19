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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
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
 */
class UltronRegenerationCommitTest extends TestCase
{
    use PromptPublications;
    use RefreshDatabase;

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

    public function test_con_el_bloque_la_fila_lo_lleva_y_sin_el_es_la_de_hoy(): void
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
            'código inventado' => [self::enElBloque($con(['rewrites' => [['by' => 'server', 'codes' => ['policy_greeting_sells']]]])), 'regeneration.rewrites.0.codes.0'],
            'cinco códigos' => [self::enElBloque($con(['rewrites' => [['by' => 'server', 'codes' => ['register_tuteo', 'length_over_cap', 'plans_overview', 'benefit_misattributed', 'register_tuteo']]]])), 'regeneration.rewrites.0.codes'],
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
