<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Models\Plan;
use App\Services\Marketing\CommercialPhaseMachine as P;
use App\Services\Marketing\HumanHandoffAuthority;
use App\Services\Marketing\OutboundContentGuard;
use App\Services\Marketing\SalesIntents;
use App\Services\Marketing\Ultron\ComposerStyleGuard;
use App\Services\Marketing\Ultron\CriticContract;
use App\Services\Marketing\Ultron\MembershipFactGuard;
use App\Services\Marketing\Ultron\PaymentFactGuard;
use App\Services\Marketing\Ultron\UltronDecideService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Lo que defiende a la MATRIZ DE AUTORIDAD de envejecer.
 *
 * `backend/docs/marketing/ULTRON_AUTHORITY.md` dice, decisión por decisión,
 * quién propone y quién decide. Un documento así es útil exactamente hasta el
 * día en que alguien añade un cerrojo y no lo escribe; a partir de ahí es peor
 * que no tenerlo, porque se lee con confianza. Este archivo hace que ese día no
 * llegue en silencio: si aparece un código de rechazo, una herramienta o un
 * motivo de derivación que el documento no menciona, la suite se pone roja.
 *
 * Cubre además el hueco que nadie cubría: la suite probaba que el controlador
 * RECHAZA un enum desconocido del Critic, pero no que ACEPTA los que el
 * contrato declara. Un valor que se caiga de `CriticContract` desparejaría n8n
 * y el backend sin que ningún test dijera nada.
 */
class UltronAuthorityMatrixTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-internal-secret';

    private const DOC = __DIR__.'/../../../docs/marketing/ULTRON_AUTHORITY.md';

    private MarketingConversation $conversation;

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('marketing.ultron.enabled', true);
        config()->set('automation.internal_secret', self::SECRET);
        config()->set('meta.enabled', false);
        config()->set('marketing.ai.enabled', true);
        config()->set('marketing.ai.driver', 'fake');
        config()->set('marketing.inbound.auto_analyze', false);
        Http::fake();

        $this->plan = Plan::create(['name' => 'Plan Mensual', 'price' => 80000, 'duration_days' => 30, 'active' => true, 'sellable' => true]);
        $lead = MarketingLead::create([
            'channel' => 'whatsapp', 'source' => 'inbound', 'phone' => '3150536026',
            'meta_user_id' => '573150536026', 'name' => 'Prospecto', 'status' => MarketingLead::STATUS_NEW,
        ]);
        $this->conversation = MarketingConversation::create([
            'lead_id' => $lead->id, 'channel' => 'whatsapp', 'status' => 'open',
            'ai_enabled' => true, 'human_takeover' => false, 'commercial_phase' => P::RECOMMENDATION,
        ]);
    }

    // ── A. El contrato del Critic se ACEPTA entero ───────────────────────────

    public function test_the_endpoint_accepts_every_dimension_the_contract_declares(): void
    {
        foreach (array_chunk(CriticContract::DIMENSIONS, CriticContract::MAX_ISSUES) as $i => $tanda) {
            $r = $this->commit('a.'.$i, ['issues' => $tanda, 'verdict' => 'pass', 'hard_fail' => null]);

            $this->assertNull($r->json('errors.critic.issues'), 'tanda '.$i.': '.implode(',', $tanda));
            $this->assertNull($r->json('errors.critic.issues.0'));
        }
    }

    public function test_the_endpoint_accepts_every_hard_fail_the_contract_declares(): void
    {
        foreach (CriticContract::HARD_FAILS as $i => $hardFail) {
            $r = $this->commit('b.'.$i, ['verdict' => 'fail', 'hard_fail' => $hardFail, 'issues' => []]);

            $this->assertNull($r->json('errors.critic.hard_fail'), $hardFail);
        }
    }

    public function test_the_two_critic_lists_have_no_repeats_and_no_empties(): void
    {
        foreach ([CriticContract::DIMENSIONS, CriticContract::HARD_FAILS] as $lista) {
            $this->assertSame(count($lista), count(array_unique($lista)));
            $this->assertSame([], array_filter($lista, fn ($v) => ! is_string($v) || trim($v) === ''));
        }
    }

    // ── B. Qué derivación puede proponer el modelo ───────────────────────────

    public function test_the_model_can_only_propose_an_explicit_human_request(): void
    {
        $this->assertSame([HumanHandoffAuthority::EXPLICIT_HUMAN_REQUEST], HumanHandoffAuthority::MODEL_PROPOSABLE);
        $this->assertSame([], array_diff(HumanHandoffAuthority::MODEL_PROPOSABLE, HumanHandoffAuthority::ALLOWED_REASONS));
        $this->assertGreaterThan(
            count(HumanHandoffAuthority::MODEL_PROPOSABLE),
            count(HumanHandoffAuthority::ALLOWED_REASONS),
            'los demás motivos existen, pero solo los afirma Laravel',
        );
    }

    // ── C. El documento no puede quedarse atrás ──────────────────────────────

    /**
     * Cada cerrojo con nombre, cada herramienta y cada motivo de derivación
     * tienen que estar escritos en la matriz.
     *
     * Es la aserción que convierte el documento en algo defendido: añadir un
     * código de rechazo sin documentarlo pone esto en rojo, y quien lo añada se
     * entera en su propia rama y no seis meses después.
     */
    public function test_the_matrix_names_every_lock_tool_and_handoff_reason(): void
    {
        $doc = (string) file_get_contents(self::DOC);
        $this->assertNotSame('', trim($doc), 'la matriz de autoridad existe y no está vacía');

        $ausentes = [];
        foreach ($this->todoLoQueDebeEstarDocumentado() as $etiqueta => $valor) {
            if (! str_contains($doc, $valor)) {
                $ausentes[] = $etiqueta.' → '.$valor;
            }
        }

        $this->assertSame([], $ausentes, "La matriz de autoridad no menciona:\n  ".implode("\n  ", $ausentes));
    }

    /** @return array<string,string> */
    private function todoLoQueDebeEstarDocumentado(): array
    {
        $debe = [];

        foreach (UltronDecideService::TOOL_VOCABULARY as $tool) {
            $debe['herramienta '.$tool] = $tool;
        }
        foreach (CriticContract::HARD_FAILS as $hard) {
            $debe['hard_fail '.$hard] = $hard;
        }
        foreach (HumanHandoffAuthority::ALLOWED_REASONS as $reason) {
            $debe['motivo de derivación '.$reason] = $reason;
        }
        foreach ((new \ReflectionClass(OutboundContentGuard::class))->getConstants() as $nombre => $valor) {
            if (str_starts_with($nombre, 'CODE_')) {
                $debe['cerrojo '.$nombre] = (string) $valor;
            }
        }
        $debe['cerrojo de tono'] = ComposerStyleGuard::CODE_PRESSURE;
        $debe['cerrojo de membresía'] = MembershipFactGuard::CODE;
        $debe['cerrojo de pago'] = PaymentFactGuard::CODE;

        return $debe;
    }

    /**
     * El documento tiene que citar por NOMBRE y no por línea: una cita
     * `archivo:línea` nace caducada, y la verificación anterior ya encontró
     * media docena desplazadas.
     */
    public function test_the_matrix_does_not_cite_line_numbers(): void
    {
        $doc = (string) file_get_contents(self::DOC);

        preg_match_all('~\b[A-Z][A-Za-z]+\.php:\d+~', $doc, $m);

        $this->assertSame([], array_unique($m[0]), 'citas por línea: se desplazan al primer refactor');
    }

    // ── Fontanería ───────────────────────────────────────────────────────────

    /** @param  array<string,mixed>  $critic */
    private function commit(string $wamid, array $critic): TestResponse
    {
        $m = MarketingMessage::create([
            'conversation_id' => $this->conversation->id,
            'direction' => MarketingMessage::DIRECTION_INBOUND,
            'sender_type' => MarketingMessage::SENDER_LEAD,
            'body' => 'hola, cuentame de los planes',
            'meta_message_id' => 'matrix.'.$wamid.'.'.uniqid(),
            'status' => 'received',
        ]);

        $token = $this->postJson('/api/internal/marketing/ai/decide', [
            'conversation_id' => $this->conversation->id, 'message_id' => $m->id,
        ], ['Authorization' => 'Bearer '.self::SECRET])->json('decide_token');

        return $this->postJson('/api/internal/marketing/ai/commit', [
            'source_type' => 'inbound_message', 'source_event_id' => $m->id,
            'idempotency_key' => $m->meta_message_id, 'conversation_id' => $this->conversation->id,
            'decide_token' => $token,
            'proposal' => [
                'strategy_goal' => 'collect_data', 'next_state' => P::RECOMMENDATION,
                'intent' => SalesIntents::GENERAL_INFO, 'reply_draft' => 'Claro, te cuento con gusto.',
                'recommended_plan_id' => null, 'main_barrier' => null, 'next_best_action' => 'recommend_plan',
                'confidence' => 0.8, 'tools_requested' => [],
            ],
            'critic' => array_merge(['attempt' => 1, 'score' => 0.9], $critic),
        ], ['Authorization' => 'Bearer '.self::SECRET]);
    }
}
