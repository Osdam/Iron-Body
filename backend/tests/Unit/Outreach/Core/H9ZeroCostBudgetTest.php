<?php

namespace Tests\Unit\Outreach\Core;

use App\Services\Outreach\Core\PolicyLadder;
use PHPUnit\Framework\TestCase;

/**
 * Cierre de B9 (L9-88): un canal AUTOMÁTICO sin coste por envío —la tarifa de WhatsApp o la reserva por llamada de la
 * voz en 0— es `budget_zero` aunque tenga techo en dólares y cupo. Antes reservaba 0 micros y el techo no frenaba nada
 * (el comentario de `config/outreach.php` ya prometía «0 = sin precio = budget_zero»). Se parte de los casos REALES del
 * fixture de la escalera y solo se cambia el coste.
 */
class H9ZeroCostBudgetTest extends TestCase
{
    use LoadsOutreachFixtures;

    /** @return array<string, mixed> */
    private static function caso(string $nombre): array
    {
        foreach (self::fixture('policy-ladder-cases')['n4'] as $caso) {
            if (str_contains($caso['name'], $nombre)) {
                return $caso;
            }
        }
        self::fail("No está el caso «{$nombre}» en policy-ladder-cases.json");
    }

    public function test_whatsapp_con_techo_y_cupo_pero_sin_tarifa_es_budget_zero(): void
    {
        $caso = self::caso('la reserva cabe justo');
        $this->assertTrue(PolicyLadder::decide($caso['input'])['allowed'], 'el caso de partida sale (con tarifa)');

        $sinTarifa = $caso['input'];
        $sinTarifa['budget']['costMicros'] = 0;
        $d = PolicyLadder::decide($sinTarifa);
        $this->assertFalse($d['allowed']);
        $this->assertSame(['deny', 'N4', ['budget_zero']], [$d['verdict'], $d['level'], $d['reasons']]);
    }

    public function test_la_voz_sin_reserva_por_llamada_tambien_es_budget_zero(): void
    {
        $caso = self::caso('voice_policy:<motivo>');
        $this->assertNotContains('budget_zero', PolicyLadder::decide($caso['input'])['reasons'], 'con reserva no hay budget_zero');

        $sinReserva = $caso['input'];
        $sinReserva['budget']['costMicros'] = 0;
        $this->assertContains('budget_zero', PolicyLadder::decide($sinReserva)['reasons']);
    }
}
