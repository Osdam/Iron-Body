<?php

namespace Tests\Feature\Marketing;

use App\Services\Marketing\SalesAgentDecisionSchema;
use App\Services\Marketing\SalesAgentPromptBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Que el prompt no ordene lo que el contrato no permite.
 *
 * El prompt mandaba escalar «(human_takeover)» cuatro veces, incluida una regla
 * que decía que si la persona quiere pagar o inscribirse hay que derivarla. Pero
 * `human_takeover` no existe en el esquema de decisión: el único valor del
 * catálogo que significa «pásalo a una persona» es `human_request`. El modelo
 * obedecía la orden con el único canal que tenía, y por eso un «si por favor»
 * terminaba en un traspaso.
 *
 * Un prompt que pide algo inexpresable no produce silencio: produce el error más
 * cercano.
 */
class SalesPromptHandoffContractTest extends TestCase
{
    use RefreshDatabase;

    private function prompt(): string
    {
        return app(SalesAgentPromptBuilder::class)->systemPrompt();
    }

    /** Ninguna instrucción puede nombrar una acción fuera del contrato. */
    public function test_the_prompt_never_orders_an_action_the_schema_cannot_express(): void
    {
        $this->assertStringNotContainsString(
            'human_takeover',
            $this->prompt(),
            'El prompt sigue ordenando una acción que el esquema no admite.',
        );
    }

    /** Las herramientas que nombra el prompt tienen que existir de verdad. */
    public function test_every_tool_named_in_the_prompt_exists(): void
    {
        preg_match_all('/\btool\s+([a-z_]+)/u', $this->prompt(), $m);

        foreach (array_unique($m[1] ?? []) as $tool) {
            $this->assertContains(
                $tool,
                SalesAgentDecisionSchema::ALLOWED_TOOLS,
                "El prompt nombra la tool «{$tool}», que no está permitida.",
            );
        }
    }

    /**
     * Y lo que causó el fallo: pagar e inscribirse son trabajo del asesor.
     */
    public function test_paying_and_enrolling_are_stated_as_the_agents_own_job(): void
    {
        $p = SalesAgentDecisionSchema::normalize($this->prompt());

        $this->assertStringContainsString('es tu trabajo', $p);
        $this->assertStringNotContainsString('un asesor le comparte el medio de pago y escala', $p);
    }

    /** Derivar debe estar descrito como excepción, no como salida por defecto. */
    public function test_handing_off_is_described_as_the_exception(): void
    {
        $p = SalesAgentDecisionSchema::normalize($this->prompt());

        $this->assertStringContainsString('es la excepcion', $p);
    }
}
