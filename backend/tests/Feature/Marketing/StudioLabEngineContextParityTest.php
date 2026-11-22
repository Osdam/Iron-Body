<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingConversation;
use App\Models\MarketingKnowledgeItem;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Models\Plan;
use App\Services\Marketing\Ultron\Prompts\UltronPromptCompiler;
use App\Services\Marketing\Ultron\UltronDecideToken;
use App\Services\UltronStudio\StudioLabEngineContext;
use App\Services\UltronStudio\StudioPromptBundle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Unit\Marketing\Ultron\Prompts\PromptPublications;

/**
 * T5 DEL APARTADO 5 (diseño `plans/informes-cierre/diseno-arq-lab.md` §3.4):
 * EL CONTEXTO DEL LABORATORIO ES EL DE `decide`.
 *
 * Para cada escenario, una conversación SEMBRADA equivalente (un prospecto
 * nuevo, los mismos turnos como mensajes, la versión «profesional, 200»
 * publicada en la canaria, el clasificador determinista) pasa por el `POST
 * ai/decide` real, y el escenario por {@see StudioLabEngineContext::build()}.
 * Se compara el árbol de claves (en su orden) y todos los campos que no dependen
 * de quién es la persona: la decisión base, la fase, las transiciones, las
 * herramientas, el referente, la novedad, el perfil, el pago, las pistas, la
 * base de conocimiento, los planes, el gimnasio, la hora, la app, la
 * membresía, el plan por defecto, la política (con la lista de planes), la
 * técnica de venta, la marca, las banderas y el contrato de la comprobación;
 * del hilo, el rol y el texto. Y lo firmado: el `sp` y el `po` del token frente
 * a los del laboratorio. Lo que sí depende de la persona (el prospecto: el
 * laboratorio no tiene nombre ni datos) se compara solo en su forma.
 */
class StudioLabEngineContextParityTest extends TestCase
{
    use PromptPublications;
    use RefreshDatabase;

    private const SECRET = 'test-internal-secret';

    private const VERSION = 'c0000000-0000-4000-8000-0000000000c6';

    private const GOBERNADO = ['persona.tone' => 'profesional', 'persona.maxChars' => 200];

    private const CATALOGO = [
        [25, 'PLAN DÍA', 10000, 1], [2, 'Plan Semana', 45000, 7], [3, 'Plan Valera', 65000, 30], [4, 'Plan Mensual', 80000, 30],
        [5, 'Trimestre', 210000, 90], [8, 'Élite', 180000, 30], [14, 'PROMO X4', 75000, 30],
    ];

    /** Las claves del contexto que no dependen de la persona: tienen que ser idénticas. */
    private const IGUALES = ['resolved_reference', 'novelty', 'customer', 'payment', 'strategy_hints', 'knowledge_base', 'active_plans', 'gym', 'business_time', 'app', 'membership',
        'default_plan_id', 'commercial_turn_policy', 'sales_playbook', 'approved_brand_copy', 'flags'];

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
            (new Plan)->forceFill(['id' => $id, 'name' => $nombre, 'price' => $precio, 'duration_days' => $dias, 'active' => true, 'sellable' => true, 'sort_order' => $i + 1, 'is_recommended' => false,
                'benefits' => json_encode($id === 4 ? ['Acceso total al gimnasio', 'Clases grupales ilimitadas'] : ['Acceso total al gimnasio'])])->save();
        }
        MarketingKnowledgeItem::create([
            'category' => 'schedule', 'key' => 'schedule.opening_hours', 'title' => 'Horario general de atencion',
            'content' => 'Lunes a viernes de 5:00 a. m. a 10:00 p. m. Sabados de 8:00 a. m. a 2:00 p. m.',
            'is_active' => true, 'origin' => MarketingKnowledgeItem::ORIGIN_HUMAN_PANEL, 'review_status' => MarketingKnowledgeItem::REVIEW_APPROVED,
            'metadata' => ['windows' => ['lunes' => ['05:00', '22:00'], 'martes' => ['05:00', '22:00'], 'miercoles' => ['05:00', '22:00'], 'jueves' => ['05:00', '22:00'], 'viernes' => ['05:00', '22:00'], 'sabado' => ['08:00', '14:00']]],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array<string, array{0:list<array{role:string,text:string}>,1:array{paymentState:string,handoffRequested:bool}}> */
    public static function escenarios(): array
    {
        $p = static fn (string $t): array => ['role' => 'prospect', 'text' => $t];
        $a = static fn (string $t): array => ['role' => 'agent', 'text' => $t];
        $sin = ['paymentState' => 'none', 'handoffRequested' => false];

        return [
            'C-01 saludo' => [[$p('Hola, buenas tardes')], $sin],
            'C-13 panorama con precio' => [[$p('¿Qué planes tienen y cuánto cuestan?')], $sin],
            'C-02 precio' => [[$p('¿Cuánto vale la mensualidad?')], $sin],
            'C-09 horario' => [[$p('¿A qué hora abren los domingos?')], $sin],
            'C-14 qué incluye' => [[$p('¿Qué incluye el plan mensual?')], $sin],
            'C-15 todo' => [[$p('Cuénteme todo: horarios, planes, clases y dónde quedan')], $sin],
            'C-16 informal' => [[$p('oye cuentame de los planes q tienen y cuanto valen, es q quiero empezar ya mismo y no se cual me sirve mas')], $sin],
            'C-06 pide un asesor' => [[$p('Quiero hablar con un asesor humano, por favor')], ['paymentState' => 'none', 'handoffRequested' => true]],
            'C-07 con un turno del asesor' => [[$p('Hola, quería información del gimnasio'), $a('¡Hola! Con gusto le ayudo. ¿Busca entrenar por su cuenta o prefiere clases grupales?'), $p('Mmm, no sé')], $sin],
            'C-17 baja' => [[$p('Por favor no me escriban más')], $sin],
            'el asesor con una cifra' => [[$p('¿Y el semestral?'), $a('El semestre está en $390.000 COP.'), $p('ok y el mensual')], $sin],
        ];
    }

    /**
     * @param  list<array{role:string,text:string}>  $turnos
     * @param  array{paymentState:string,handoffRequested:bool}  $hechos
     */
    #[DataProvider('escenarios')]
    public function test_t5_el_contexto_del_laboratorio_es_el_de_decide(array $turnos, array $hechos): void
    {
        [$real, $token] = $this->decideReal($turnos);
        $parte = self::parteV2(self::VERSION, self::GOBERNADO);
        $paquete = StudioPromptBundle::rolesFor($parte['governed'], self::VERSION, $parte['contentHash']);
        $lab = app(StudioLabEngineContext::class)->build(['turns' => $turnos, 'facts' => $hechos], $paquete['sp']);
        $d = $lab['decide'];

        // El árbol: las mismas claves, en su orden (sin lo que el laboratorio no tiene: los prompts los entrega directamente).
        $this->assertSame(array_keys(array_diff_key($real, ['studio_prompts' => true])), array_keys($d), 'las claves de primer nivel');
        $this->assertSame(array_keys($real['context']), array_keys($d['context']), 'las claves del contexto');
        $this->assertSame(array_keys($real['context']['lead']), array_keys($d['context']['lead']));
        $this->assertSame(array_keys($real['context']['memory']), array_keys($d['context']['memory']));
        $this->assertSame(array_keys($real['source']), array_keys($d['source']));

        // Lo determinista, idéntico.
        foreach (['decision', 'commercial_phase', 'allowed_transitions', 'allowed_tools', 'allowed_staff_review_reasons', 'knowledge_version', 'regeneration'] as $k) {
            $this->assertSame($real[$k], $d[$k], $k);
        }
        // Lo que identifica el mensaje (su id y su hora) es sintético en el laboratorio: fuera de la comparación.
        $sinIds = static function (mixed $v) use (&$sinIds): mixed {
            if (! is_array($v)) {
                return $v;
            }
            $out = [];
            foreach ($v as $k => $x) {
                if (! in_array($k, ['message_id', 'at'], true)) {
                    $out[$k] = $sinIds($x);
                }
            }

            return $out;
        };
        foreach (self::IGUALES as $k) {
            $real_k = $real['context'][$k];
            $lab_k = $d['context'][$k];
            if ($k === 'customer') {
                // La persona del laboratorio no tiene nombre: lo único de ella que cambia.
                $this->assertSame([true, false], [$real_k['known']['has_name'], $lab_k['known']['has_name']]);
                unset($real_k['known']['has_name'], $lab_k['known']['has_name']);
            }
            $this->assertSame($sinIds($real_k), $sinIds($lab_k), "context.{$k}");
        }
        $this->assertSame($sinIds($real['context']['memory']), $sinIds($d['context']['memory']), 'la memoria de un primer contacto');
        $this->assertSame(array_map(static fn (array $m): array => [$m['role'], $m['body']], $real['context']['recent_messages']),
            array_map(static fn (array $m): array => [$m['role'], $m['body']], $d['context']['recent_messages']), 'el hilo: rol y texto');
        // Lo de la persona: el laboratorio no tiene sus datos (la persona es sintética).
        $this->assertSame([null, null, false, false], [$d['context']['lead']['name'], $d['context']['lead']['objective'], $d['context']['lead']['do_not_contact'], $d['context']['lead']['is_member']]);

        // Lo que el motor firmaría en el token: el mismo sp (sin la entrada de la cadena) y la misma lista.
        $firmado = app(UltronDecideToken::class)->peek($token, (int) $real['source']['source_event_id'] > 0 ? $this->conversationId : 0, (int) $real['source']['source_event_id']);
        $this->assertTrue($firmado['valid']);
        $sinCadena = static fn (array $sp): array => array_diff_key($sp, ['e' => true, 's' => true]);
        $this->assertSame($sinCadena($firmado['extra']['sp']), $sinCadena($lab['turn']['sp']));
        $this->assertSame($firmado['extra']['po'] ?? [], $lab['turn']['po']);
        $this->assertSame((string) end($turnos)['text'], $lab['inbound']);
    }

    public function test_t5_lo_que_dice_el_escenario_pasa_por_el_filtro_y_lo_de_negocio_no(): void
    {
        $sp = StudioPromptBundle::rolesFor(array_replace(UltronPromptCompiler::BASELINE, self::GOBERNADO))['sp'];
        // Lo del escenario: un teléfono en un turno no se construye.
        try {
            app(StudioLabEngineContext::class)->build(['turns' => [['role' => 'prospect', 'text' => 'Mi número es 3001234567']], 'facts' => ['paymentState' => 'none', 'handoffRequested' => false]], $sp);
            $this->fail('un dato personal del escenario pasó');
        } catch (InvalidArgumentException $e) {
            $this->assertStringNotContainsString('3001234567', $e->getMessage());
        }
        // Lo de negocio: el teléfono público del gimnasio en la base de conocimiento SÍ viaja (es lo que envía el motor).
        MarketingKnowledgeItem::create(['category' => 'location', 'key' => 'location.phone', 'title' => 'Teléfono del gimnasio', 'content' => 'Llámenos al 3209876543.',
            'is_active' => true, 'origin' => MarketingKnowledgeItem::ORIGIN_HUMAN_PANEL, 'review_status' => MarketingKnowledgeItem::REVIEW_APPROVED]);
        $d = app(StudioLabEngineContext::class)->build(['turns' => [['role' => 'prospect', 'text' => '¿Dónde quedan?']], 'facts' => ['paymentState' => 'none', 'handoffRequested' => false]], $sp)['decide'];
        $this->assertStringContainsString('3209876543', json_encode($d['context']['knowledge_base'], JSON_UNESCAPED_UNICODE));
    }

    // ── Andamio ─────────────────────────────────────────────────────────────

    private int $conversationId = 0;

    /**
     * La conversación sembrada equivalente y su `decide` real.
     *
     * @param  list<array{role:string,text:string}>  $turnos
     * @return array{0:array<string,mixed>,1:string}
     */
    private function decideReal(array $turnos): array
    {
        $lead = MarketingLead::create(['channel' => 'whatsapp', 'source' => 'inbound', 'phone' => '3150539555', 'meta_user_id' => '573150539555', 'name' => 'Prospecto', 'status' => MarketingLead::STATUS_NEW]);
        $c = MarketingConversation::create(['lead_id' => $lead->id, 'channel' => 'whatsapp', 'status' => 'open', 'ai_enabled' => true, 'human_takeover' => false]);
        $this->conversationId = (int) $c->id;
        config()->set('marketing.ultron.canary_conversation_id', $c->id);
        $this->publicaEntrada('ultron-engine-config/2', self::parteV2(self::VERSION, self::GOBERNADO), null);
        $ultimo = null;
        foreach ($turnos as $i => $t) {
            $persona = $t['role'] === 'prospect';
            $ultimo = MarketingMessage::create([
                'conversation_id' => $c->id,
                'direction' => $persona ? MarketingMessage::DIRECTION_INBOUND : MarketingMessage::DIRECTION_OUTBOUND,
                'sender_type' => $persona ? MarketingMessage::SENDER_LEAD : MarketingMessage::SENDER_AI,
                'body' => $t['text'], 'meta_message_id' => 'wamid.ctx.'.$i.'.'.uniqid(), 'status' => $persona ? 'received' : 'sent',
            ]);
        }
        $real = $this->postJson('/api/internal/marketing/ai/decide', ['conversation_id' => $c->id, 'message_id' => $ultimo->id, 'prompt_contract' => 'ultron-prompts/1'],
            ['Authorization' => 'Bearer '.self::SECRET])->assertOk()->json();
        $this->assertSame('studio', $real['studio_prompts']['mode'], 'decide ofrece la versión');

        return [$real, (string) $real['decide_token']];
    }
}
