<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingLead;
use App\Models\Plan;
use App\Services\Marketing\MarketingKnowledgeBaseService;
use App\Services\Marketing\SalesGuardrailException;
use App\Services\Marketing\SalesPaymentGuardrailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Qué se le puede vender a alguien de fuera.
 *
 * Esto existe por dos filas reales de producción. «Comisión de personalizados»
 * (precio 0) es el vehículo contable con el que el equipo apunta comisiones de
 * entrenamiento personalizado: 86 pagos registrados. Y «PLAN SEPTIEMBRE» vale
 * 1 peso y dura dos días. Los dos estaban `active` y los dos llegaban al
 * catálogo del asesor comercial, listos para cotizarse por WhatsApp.
 *
 * `active` no significa vendible: lo consultan los informes, la app del socio y
 * el punto de venta, y esos planes TIENEN que seguir activos. Por eso la
 * vendibilidad es una pregunta aparte, y se contesta en un solo sitio.
 */
class SellablePlanGuardTest extends TestCase
{
    use RefreshDatabase;

    private function plan(array $attrs = []): Plan
    {
        return Plan::create(array_merge([
            'name' => 'Plan Mensual', 'price' => 80000, 'duration_days' => 30,
            'active' => true, 'sellable' => true, 'sort_order' => 1,
            'benefits' => json_encode(['Acceso ilimitado al gimnasio']),
        ], $attrs));
    }

    // ── La regla de dominio ───────────────────────────────────────────────────

    public function test_a_normal_plan_is_sellable(): void
    {
        $this->assertTrue($this->plan()->isSellable());
    }

    /** El plan contable: activo para el sistema, invisible para quien compra. */
    public function test_an_internal_zero_price_plan_is_not_sellable(): void
    {
        $contable = $this->plan(['name' => 'Comisión de personalizados', 'price' => 0]);

        $this->assertTrue((bool) $contable->active, 'Debe seguir activo: el equipo lo usa a diario.');
        $this->assertFalse($contable->isSellable());
    }

    /**
     * El de un peso. Pasa «activo» y pasa «precio > 0»: sólo lo detiene la
     * decisión explícita del negocio de no venderlo.
     */
    public function test_a_symbolic_price_plan_is_stopped_only_by_the_flag(): void
    {
        $simbolico = $this->plan(['name' => 'PLAN SEPTIEMBRE', 'price' => 1, 'duration_days' => 2, 'sellable' => false]);

        $this->assertTrue((bool) $simbolico->active);
        $this->assertGreaterThan(0, (float) $simbolico->price);
        $this->assertFalse($simbolico->isSellable(), 'La bandera de negocio es lo único que lo para.');
    }

    /** Un plan sin duración no da acceso a nada: tampoco se vende. */
    public function test_a_plan_without_duration_is_not_sellable(): void
    {
        $this->assertFalse($this->plan(['duration_days' => 0])->isSellable());
    }

    public function test_an_inactive_plan_is_not_sellable(): void
    {
        $this->assertFalse($this->plan(['active' => false])->isSellable());
    }

    // ── El catálogo que ve el asesor ─────────────────────────────────────────

    public function test_the_catalogue_only_shows_what_can_be_sold(): void
    {
        $vendible = $this->plan();
        $this->plan(['name' => 'Comisión de personalizados', 'price' => 0]);
        $this->plan(['name' => 'PLAN SEPTIEMBRE', 'price' => 1, 'duration_days' => 2, 'sellable' => false]);
        $this->plan(['name' => 'Viejo', 'active' => false]);

        $catalogo = app(MarketingKnowledgeBaseService::class)->activePlans();

        $this->assertCount(1, $catalogo);
        $this->assertSame($vendible->id, $catalogo[0]['id']);
    }

    /** Y el plan que se cotiza por defecto tampoco puede ser uno interno. */
    public function test_the_default_quoted_plan_is_never_an_internal_one(): void
    {
        // sort_order menor: ganaría si el orden fuera lo único que cuenta.
        $this->plan(['name' => 'Comisión de personalizados', 'price' => 0, 'sort_order' => 0]);
        $mensual = $this->plan(['name' => 'Plan Mensual', 'sort_order' => 5]);

        $this->assertSame($mensual->id, app(MarketingKnowledgeBaseService::class)->defaultMonthlyPlan()?->id);
    }

    // ── El cobro ─────────────────────────────────────────────────────────────

    private function lead(): MarketingLead
    {
        return MarketingLead::create([
            'channel' => 'whatsapp', 'source' => 'inbound', 'phone' => '3150000000',
            'meta_user_id' => '573150000000', 'name' => 'Prospecto',
            'status' => MarketingLead::STATUS_NEW,
        ]);
    }

    public function test_a_payment_link_is_refused_for_the_accounting_plan(): void
    {
        $this->expectException(SalesGuardrailException::class);

        app(SalesPaymentGuardrailService::class)->assertCanGeneratePaymentLink(
            $this->lead(),
            $this->plan(['name' => 'Comisión de personalizados', 'price' => 0]),
        );
    }

    /** El caso que antes se colaba: precio válido, plan activo, y aun así no se vende. */
    public function test_a_payment_link_is_refused_for_the_one_peso_plan(): void
    {
        $plan = $this->plan(['name' => 'PLAN SEPTIEMBRE', 'price' => 1, 'duration_days' => 2, 'sellable' => false]);

        try {
            app(SalesPaymentGuardrailService::class)->assertCanGeneratePaymentLink($this->lead(), $plan);
            $this->fail('Se generó un cobro para un plan que el negocio no vende.');
        } catch (SalesGuardrailException $e) {
            $this->assertSame('plan_not_sellable', $e->errorCode);
        }
    }

    public function test_a_payment_link_is_allowed_for_a_real_plan(): void
    {
        app(SalesPaymentGuardrailService::class)->assertCanGeneratePaymentLink($this->lead(), $this->plan());

        $this->assertTrue(true, 'Un plan vendible sí puede cobrarse.');
    }

    // ── La rumba obsoleta ────────────────────────────────────────────────────

    /**
     * El gimnasio no ofrece rumba, pero cinco planes la prometían en sus
     * beneficios. ULTRON la citó dos veces en una conversación real: no se la
     * inventó el modelo, la copió del catálogo.
     */
    public function test_no_sellable_plan_promises_classes_that_do_not_exist(): void
    {
        $this->plan(['benefits' => json_encode(['Acceso ilimitado al gimnasio', 'Zona de pesas y maquinaria'])]);

        foreach (app(MarketingKnowledgeBaseService::class)->activePlans() as $p) {
            $this->assertStringNotContainsStringIgnoringCase(
                'rumba',
                json_encode($p['benefits'], JSON_UNESCAPED_UNICODE),
                "El plan {$p['name']} sigue prometiendo rumba.",
            );
        }
    }
}
