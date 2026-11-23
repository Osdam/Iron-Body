<?php

namespace Tests\Unit\Marketing\Ultron\Prompts;

use App\Services\Marketing\Ultron\Prompts\UltronPromptCompiler;
use App\Services\Marketing\Ultron\Prompts\UltronPromptOffer;
use App\Services\Marketing\Ultron\UltronPublishedConfig as Lector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «REGENERAR ANTES DE SUSTITUIR» · OLA 2 · `sp.o` EN LA OFERTA DE `decide`
 * (plan del arquitecto `plan-arquitecto-a4-regenerar.md` §3 P-3, cambio 3, y
 * §4.2).
 *
 * `UltronPromptOffer` firma `sp.o = 1` cuando el paquete que ofrece lleva el
 * parche del motor de la lista de planes, con el mismo criterio que `n` y `t`:
 * solo en `studio`. `decide` (ola 3) solo ofrecerá la lista con `sp.o = 1`, y
 * el commit sabrá por la firma que al modelo se le pidió. Una versión del
 * Studio con todos los valores por defecto no lleva el parche ni `o`: sigue
 * como hoy (la lista fija como respaldo). Legacy nunca lo lleva.
 *
 * Sobre entradas REALES firmadas con la firma del motor.
 */
class UltronPromptOfferEngineTest extends TestCase
{
    use PromptPublications;
    use RefreshDatabase;

    private const VERSION_A = 'a0000000-0000-4000-8000-00000000000a';

    private const VERSION_B = 'b0000000-0000-4000-8000-00000000000b';

    private int $canario = 501;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('marketing.ultron.studio_config', Lector::CANARY);
        config()->set('marketing.ultron.studio_prompts', UltronPromptOffer::KEY_CANARY);
        config()->set('marketing.ultron.canary_conversation_id', $this->canario);
    }

    /** @return array{response:array<string,mixed>,sp:array<string,mixed>} */
    private function oferta(): array
    {
        return (new UltronPromptOffer)->forConversation($this->canario);
    }

    public function test_con_el_parche_del_motor_firma_o_junto_a_n_y_t(): void
    {
        $this->publicaEntrada(Lector::CONTRACT_V2, self::parteV2(self::VERSION_B, ['persona.tone' => 'profesional', 'persona.maxChars' => 200]), null);
        $o = $this->oferta();

        $this->assertSame('studio', $o['response']['mode']);
        $this->assertStringContainsString('LISTA DE PLANES DEL BACKEND:', $o['response']['roles']['composer']);
        $this->assertSame(['n' => 200, 't' => 'profesional', 'o' => 1, 'k' => 6], array_intersect_key($o['sp'], ['n' => 1, 't' => 1, 'o' => 1, 'k' => 1]));
        // Enmienda A4-6 (PT-17b): con el parche del motor va también `k`, el compilador, tras `o`.
        $this->assertSame(['m', 'r', 'h', 'v', 'c', 'e', 's', 'n', 't', 'o', 'k'], array_keys($o['sp']), 'o tras n y t, y k la última');
        $this->assertArrayNotHasKey('o', $o['response'], 'la respuesta a n8n no cambia: solo la firma');
    }

    public function test_un_cambio_solo_del_estratega_tambien_lo_lleva(): void
    {
        $this->publicaEntrada(Lector::CONTRACT_V2, self::parteV2(self::VERSION_B, ['strategy.goal' => 'venta']), null);
        $o = $this->oferta();

        $this->assertSame('studio', $o['response']['mode']);
        $this->assertSame(1, $o['sp']['o'] ?? null);
        $this->assertSame(UltronPromptCompiler::VERSION, $o['sp']['k'] ?? null, 'y el compilador, con el parche del motor');
        $this->assertArrayNotHasKey('n', $o['sp']);
        $this->assertArrayNotHasKey('t', $o['sp']);
    }

    public function test_una_version_con_los_valores_por_defecto_no_lo_lleva(): void
    {
        $this->publicaEntrada(Lector::CONTRACT_V2, self::parteV2(self::VERSION_B), null);
        $o = $this->oferta();

        $this->assertSame('studio', $o['response']['mode']);
        $this->assertSame(UltronPromptCompiler::compile(UltronPromptCompiler::BASELINE)['bundleHash'], $o['response']['bundle_hash'], 'la base: la huella Legacy');
        $this->assertStringNotContainsString('LISTA DE PLANES DEL BACKEND', implode("\n", $o['response']['roles']));
        $this->assertSame(['m', 'r', 'h', 'v', 'c', 'e', 's'], array_keys($o['sp']), 'sin n, sin t y sin o: como hoy');
    }

    public function test_legacy_nunca_lo_lleva(): void
    {
        $this->publicaEntrada(Lector::CONTRACT_V2, self::parteV2(self::VERSION_B, ['persona.tone' => 'profesional']), null);

        config()->set('marketing.ultron.studio_prompts', 'off');
        $this->assertSame(['m' => 'legacy', 'r' => 'prompts_off'], array_intersect_key($this->oferta()['sp'], ['m' => 1, 'r' => 1]));
        $this->assertArrayNotHasKey('o', $this->oferta()['sp']);
        $this->assertArrayNotHasKey('k', $this->oferta()['sp']);

        config()->set('marketing.ultron.studio_prompts', UltronPromptOffer::KEY_CANARY);
        config()->set('marketing.ultron.canary_conversation_id', $this->canario + 1);
        $this->assertSame('out_of_scope', $this->oferta()['sp']['r']);
        $this->assertArrayNotHasKey('o', $this->oferta()['sp']);
    }

    public function test_lo_firmado_con_otra_huella_cae_y_no_lo_lleva(): void
    {
        $this->publicaEntrada(Lector::CONTRACT_V2, self::parteV2(self::VERSION_B, ['persona.tone' => 'profesional'], ['bundleHash' => str_repeat('a', 64)]), null);
        $o = $this->oferta();

        $this->assertSame(['legacy', 'bundle_mismatch'], [$o['sp']['m'], $o['sp']['r']]);
        $this->assertArrayNotHasKey('o', $o['sp']);
    }
}
