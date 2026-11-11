<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Models\Plan;
use App\Services\Marketing\MarketingKnowledgeBaseService;
use App\Services\Marketing\Ultron\GymFactsProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Lo que ULTRON dice de las clases de un plan sale de lo que el plan da DE
 * VERDAD: el módulo «Clases» (Módulos de la app), que es la única puerta para
 * reservar desde la app y desde el mostrador.
 *
 * Antes salía de la columna `access_classes` («Acceso a clases»), que no
 * aplicaba nadie, nace en `true` y el CRM reescribía a `true` en cada guardado:
 * un plan sin clases se anunciaba con clases. La clave del contrato
 * (`access_classes`) no cambia; cambia de dónde sale su valor.
 */
class UltronPlanClassesFactTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'secreto-de-prueba-ultron-clases';

    /** Con el módulo y con la columna vieja diciendo lo contrario. */
    private Plan $conClases;

    /** Sin el módulo, con la columna vieja en su `true` de siempre. */
    private Plan $sinClases;

    /** Un plan anterior a los módulos: sin `features`, que resuelven a «sin clases». */
    private Plan $sinModulos;

    protected function setUp(): void
    {
        parent::setUp();

        $plan = fn (string $nombre, array $extra) => Plan::create(array_merge([
            'name' => $nombre, 'price' => 90000, 'duration_days' => 30, 'active' => true,
            'benefits' => json_encode(['Acceso al gimnasio']),
        ], $extra));

        $this->conClases = $plan('Élite', [
            'features' => array_merge(Plan::defaultFeatures(), ['classes' => true]),
            'access_classes' => false,
        ]);
        $this->sinClases = $plan('Pro', [
            'features' => array_merge(Plan::defaultFeatures(), ['classes' => false]),
            'access_classes' => true,
        ]);
        $this->sinModulos = $plan('Mensual', ['access_classes' => true]);
    }

    /** @param list<array<string,mixed>> $planes */
    private function clasesPorPlan(array $planes): array
    {
        return array_column($planes, 'access_classes', 'id');
    }

    private function esperado(): array
    {
        return [
            $this->conClases->id => true,
            $this->sinClases->id => false,
            $this->sinModulos->id => false,
        ];
    }

    public function test_el_catalogo_de_ultron_lee_el_modulo_de_clases(): void
    {
        $planes = app(MarketingKnowledgeBaseService::class)->activePlans();

        $this->assertEqualsCanonicalizing($this->esperado(), $this->clasesPorPlan($planes));
        // El resto del contrato no cambia.
        foreach ($planes as $p) {
            $this->assertSame(['id', 'name', 'price', 'duration_days', 'benefits', 'tier', 'original_price',
                'is_recommended', 'badge', 'access_classes', 'restrictions'], array_keys($p));
            $this->assertIsBool($p['access_classes']);
        }
    }

    public function test_comparar_planes_y_su_detalle_leen_el_modulo_de_clases(): void
    {
        $g = new GymFactsProvider;
        $ids = [$this->conClases->id, $this->sinClases->id, $this->sinModulos->id];

        $this->assertEqualsCanonicalizing($this->esperado(), $this->clasesPorPlan($g->comparePlans($ids)));
        $this->assertTrue($g->planDetails($this->conClases->id)['access_classes']);
        $this->assertFalse($g->planDetails($this->sinClases->id)['access_classes']);
        $this->assertFalse($g->planDetails($this->sinModulos->id)['access_classes']);
    }

    public function test_activar_o_desactivar_el_modulo_cambia_lo_que_ultron_dice(): void
    {
        // La columna vieja, a propósito, diciendo lo contrario que el módulo.
        $this->sinClases->update([
            'features' => array_merge($this->sinClases->resolvedFeatures(), ['classes' => true]),
            'access_classes' => false,
        ]);
        $this->conClases->update([
            'features' => array_merge($this->conClases->resolvedFeatures(), ['classes' => false]),
            'access_classes' => true,
        ]);

        $planes = $this->clasesPorPlan(app(MarketingKnowledgeBaseService::class)->activePlans());

        $this->assertTrue($planes[$this->sinClases->id]);
        $this->assertFalse($planes[$this->conClases->id]);
    }

    public function test_el_contexto_comercial_de_decide_lleva_el_valor_real(): void
    {
        config()->set('marketing.ultron.enabled', true);
        config()->set('automation.internal_secret', self::SECRET);
        config()->set('meta.enabled', false);
        config()->set('marketing.ai.enabled', true);
        config()->set('marketing.ai.driver', 'fake');
        config()->set('marketing.ultron.payment_links_enabled', false);

        $lead = MarketingLead::create([
            'channel' => 'whatsapp', 'source' => 'inbound', 'phone' => '3150536026',
            'meta_user_id' => '573150536026', 'name' => 'Prospecto', 'status' => MarketingLead::STATUS_NEW,
        ]);
        $conversacion = MarketingConversation::create([
            'lead_id' => $lead->id, 'channel' => 'whatsapp', 'status' => 'open',
            'ai_enabled' => true, 'human_takeover' => false,
        ]);
        $mensaje = MarketingMessage::create([
            'conversation_id' => $conversacion->id, 'direction' => MarketingMessage::DIRECTION_INBOUND,
            'sender_type' => MarketingMessage::SENDER_LEAD, 'body' => '¿qué planes incluyen clases?',
            'status' => 'received',
        ]);

        $planes = $this->postJson('/api/internal/marketing/ai/decide', [
            'conversation_id' => $conversacion->id, 'message_id' => $mensaje->id,
        ], ['Authorization' => 'Bearer '.self::SECRET])->assertOk()->json('context.active_plans');

        $this->assertEqualsCanonicalizing($this->esperado(), $this->clasesPorPlan($planes));
    }
}
