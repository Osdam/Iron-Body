<?php

namespace Tests\Unit\Outreach\Core;

use App\Services\Outreach\Core\PolicyLadder;

/**
 * La escena de las pruebas literales del propietario de §13.1 que son de
 * B9-core: la entrada de la escalera del fixture de TEST9-a (`baseInput`, todo
 * permitido) con lo que un canal AUTOMÁTICO necesita para salir en vivo (el
 * envío abierto en código y presupuesto), y la decisión REAL de
 * {@see PolicyLadder} por canal. Sin dobles.
 */
trait H9Scenario
{
    use LoadsOutreachFixtures;

    /**
     * La decisión de la escalera para un canal, en vivo.
     *
     * @param  array<string, mixed>  $patch
     * @return array<string, mixed>
     */
    private static function ladderFor(string $channel, array $patch = []): array
    {
        $vivo = ['flags' => ['sendsEnabledInCode' => true], 'budget' => ['dailyBudgetMicros' => 10_000_000, 'costMicros' => 50_000, 'channelDailyCap' => 100]];

        return PolicyLadder::decide(array_replace_recursive(self::fixture('policy-ladder-cases')['baseInput'], $vivo, ['channel' => $channel], $patch));
    }

    /**
     * @param  list<string>  $channels
     * @param  array<string, mixed>  $patch
     * @return array<string, array<string, mixed>>
     */
    private static function perChannel(array $channels, array $patch = []): array
    {
        $out = [];
        foreach ($channels as $canal) {
            $out[$canal] = self::ladderFor($canal, $patch);
        }

        return $out;
    }

    /**
     * Nivel → estado de una decisión.
     *
     * @param  array<string, mixed>  $decision
     * @return array<string, string>
     */
    private static function statuses(array $decision): array
    {
        return array_column($decision['levels'], 'status', 'level');
    }
}
