<?php

namespace Tests\Unit\Outreach\Core;

use App\Services\Outreach\Core\ChannelSelector;
use App\Services\Outreach\Core\OutreachCatalog;
use App\Services\Outreach\Core\PolicyLadder;
use App\Services\Outreach\Core\PriorityArbiter;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PARIDAD de la escalera N1–N7 (`policy-ladder-cases.json`; T16–T22, §13.1
 * #4, #5, #8, #9, #11, #12, #15, #17), del selector de canal
 * (`channel-selection-cases.json`; T13, T19, T22, §13.1 #8 y #9) —contrastado
 * además con la escalera real aplicada a cada `ladderPatchByChannel`— y de la
 * prioridad (`priority-cases.json`; §13.1 #5 y #12), caso a caso. Y las
 * propiedades: un nivel inferior nunca levanta la negativa de uno superior;
 * el orden de llegada de las estrategias no cambia nada.
 */
class PolicyAndChannelParityTest extends TestCase
{
    use LoadsOutreachFixtures;

    /** @return iterable<string, array{array<string,mixed>}> */
    public static function ladder(): iterable
    {
        return self::cases('policy-ladder-cases', ['n1', 'n2', 'n3', 'n4', 'n5', 'n6', 'n7', 'precedence', 'shadow']);
    }

    #[DataProvider('ladder')]
    public function test_escalera(array $caso): void
    {
        $this->assertSame(self::merge(self::fixture('policy-ladder-cases')['baseInput'], $caso['patch']), $caso['input'], 'el caso es baseInput + su patch');
        $this->assertCanonicalSame($caso['expected'], PolicyLadder::decide($caso['input']));
    }

    /** @return iterable<string, array{array<string,mixed>}> */
    public static function choose(): iterable
    {
        return self::cases('channel-selection-cases', ['choose']);
    }

    #[DataProvider('choose')]
    public function test_seleccion_de_canal(array $caso): void
    {
        $this->assertCanonicalSame($caso['expected'], ChannelSelector::choose($caso['channels'], $caso['perChannel'], $caso['preferredMedium']));

        // La decisión de cada canal es la de la escalera real con el patch del caso (no una invención del fixture).
        foreach ($caso['ladderPatchByChannel'] as $canal => $patch) {
            $d = PolicyLadder::decide(self::merge(self::fixture('policy-ladder-cases')['baseInput'], $patch));
            $this->assertSame($caso['perChannel'][$canal], ['verdict' => $d['verdict'], 'level' => $d['level'], 'reasons' => $d['reasons'], 'retryAt' => $d['retryAt']], $canal);
        }
    }

    /** @return iterable<string, array{array<string,mixed>}> */
    public static function arbitrate(): iterable
    {
        return self::cases('priority-cases', ['arbitrate']);
    }

    #[DataProvider('arbitrate')]
    public function test_prioridad(array $caso): void
    {
        $this->assertSame($caso['expected'], PriorityArbiter::arbitrate($caso['candidates'], $caso['todayPurpose']));
        // Determinista: cualquier orden de llegada da lo mismo.
        $this->assertSame($caso['expected'], PriorityArbiter::arbitrate(array_reverse($caso['candidates']), $caso['todayPurpose']));
    }

    public function test_los_conjuntos_del_fixture_son_los_del_codigo(): void
    {
        $f = self::fixture('policy-ladder-cases');
        $this->assertSame($f['reasonOrder'], PolicyLadder::REASON_ORDER);
        $this->assertSame($f['temporalReasons'], PolicyLadder::TEMPORAL);
        $this->assertSame($f['shadowWarnings'], PolicyLadder::SHADOW_WARNINGS);
        $this->assertSame($f['n7Annotations'], PolicyLadder::N7_ANNOTATIONS);
        foreach (['n1', 'n2', 'n3', 'n4', 'n5', 'n6', 'n7', 'precedence', 'shadow'] as $s) {
            $this->assertCount($f['counts'][$s], $f[$s]);
        }
        $p = self::fixture('priority-cases');
        $this->assertSame($p['goalPriority'], OutreachCatalog::GOAL_PRIORITY);
    }

    /**
     * T16–T22: ningún nivel inferior levanta una negativa de uno superior. Para
     * cada negativa de un nivel, se le suman las «ganas» de los inferiores (una
     * petición de llamada ya, una estrategia sin tope, contexto favorable…) y
     * el veredicto, el nivel y los motivos no cambian.
     */
    public function test_un_nivel_inferior_nunca_levanta_una_negativa(): void
    {
        $base = self::fixture('policy-ladder-cases')['baseInput'];
        $negativas = [
            'N1' => ['optOut' => ['doNotContact' => true]],
            'N2' => ['brakes' => ['global' => true]],
            'N3' => ['studio' => ['referenceValid' => false]],
            'N4' => ['step' => ['templateApproved' => false]],
            'N5' => ['strategy' => ['paused' => true]],
            'N6' => ['context' => ['audience' => 'false']],
        ];
        $ganas = [
            ['conversational' => ['callbackAt' => '2026-11-10T15:30:00Z']],
            ['strategy' => ['maxTouches' => 6, 'touches' => 0, 'dailyCap' => 500, 'usedToday' => 0, 'stepGuard' => 'true']],
            ['context' => ['success' => 'false', 'lowerPriority' => false, 'otherPurposeToday' => false]],
            ['flags' => ['sendsEnabledInCode' => true, 'channelEnabled' => true, 'channelLive' => true]],
        ];
        foreach ($negativas as $nivel => $negativa) {
            $sola = PolicyLadder::decide(self::merge($base, $negativa));
            $this->assertSame('deny', $sola['verdict'], $nivel);
            $this->assertSame($nivel, $sola['level']);
            foreach ($ganas as $g) {
                $con = PolicyLadder::decide(self::merge(self::merge($base, $g), $negativa));
                $this->assertSame([$sola['verdict'], $sola['level'], $sola['reasons']], [$con['verdict'], $con['level'], $con['reasons']], "{$nivel} + ".json_encode($g));
                $despues = array_slice($con['levels'], array_search($nivel, PolicyLadder::LEVELS, true) + 1);
                $this->assertSame([], array_filter($despues, static fn (array $l): bool => $l['status'] !== 'not_evaluated'), 'los posteriores no se evalúan');
            }
        }
    }

    public function test_una_entrada_sin_forma_es_un_error_de_quien_llama(): void
    {
        $base = self::fixture('policy-ladder-cases')['baseInput'];
        $rotas = [
            array_diff_key($base, ['flags' => true]),
            self::merge($base, ['channel' => 'sms']),
            self::merge($base, ['purpose' => 'ventas']),
            self::merge($base, ['now' => '2026-11-10 15:00']),
            self::merge($base, ['subject' => ['known' => 'yes']]),
            self::merge($base, ['budget' => ['costMicros' => '5']]),
            self::merge($base, ['channel' => 'voice_call', 'voicePolicyReasons' => ['Frecuencia Excedida']]),
            self::merge($base, ['strategy' => ['enrollmentWindowEndsAt' => 'mañana']]),
        ];
        foreach ($rotas as $i => $entrada) {
            try {
                PolicyLadder::decide($entrada);
                $this->fail("La entrada rota {$i} no lanzó.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_los_desconocidos_niegan_salvo_el_menor(): void
    {
        $base = self::fixture('policy-ladder-cases')['baseInput'];
        foreach ([
            [['optOut' => ['doNotContact' => null]], 'N1', 'do_not_contact'],
            [['optOut' => ['siblingDoNotContact' => null]], 'N1', 'do_not_contact_sibling'],
            [['optOut' => ['commercial' => null]], 'N1', 'commercial_opt_out'],
            [['channel' => 'assisted:voice', 'voice' => ['rneOk' => null, 'excluded' => false]], 'N1', 'rne_check_missing'],
            [['conversation' => ['humanInControl' => null]], 'N2', 'human_in_control'],
            [['identity' => ['whatsapp' => null]], 'N4', 'no_whatsapp_identity'],
            [['context' => ['humanRequestedRecently' => null]], 'N6', 'human_requested_recently'],
            [['context' => ['exclusions' => 'unknown']], 'N6', 'context_changed'],
        ] as [$patch, $nivel, $motivo]) {
            $d = PolicyLadder::decide(self::merge($base, $patch));
            $this->assertSame([$nivel, [$motivo]], [$d['level'], $d['reasons']], json_encode($patch));
        }
        $this->assertTrue(PolicyLadder::decide(self::merge($base, ['subject' => ['isMinor' => null]]))['allowed'], 'un menor desconocido no se infiere (Q-10)');
        $this->assertTrue(PolicyLadder::decide(self::merge($base, ['context' => ['exclusions' => null]]))['allowed'], 'sin exclusiones propias');
    }

    public function test_un_aplazamiento_sin_hueco_niega(): void
    {
        // Un domingo con el horario propio mal configurado: N1 no tiene dónde aplazar → deny sin retryAt (DF-L11).
        $d = PolicyLadder::decide(self::merge(self::fixture('policy-ladder-cases')['baseInput'], ['ownHours' => 'invalid', 'now' => '2026-11-08T15:00:00Z']));
        $this->assertSame(['deny', 'N1', ['calendar_sunday'], null], [$d['verdict'], $d['level'], $d['reasons'], $d['retryAt']]);
        // Una estrategia sin ningún día con franja: N5 tampoco tiene dónde aplazar.
        $d = PolicyLadder::decide(self::merge(self::fixture('policy-ladder-cases')['baseInput'], ['strategy' => ['schedule' => ['days' => [6], 'windows' => [['from' => 960, 'to' => 1020]]]]]));
        $this->assertSame(['deny', 'N5', ['outside_strategy_schedule'], null], [$d['verdict'], $d['level'], $d['reasons'], $d['retryAt']]);
    }

    public function test_el_selector_rechaza_lo_que_no_tiene_forma(): void
    {
        $permitido = ['verdict' => 'allow', 'level' => null, 'reasons' => [], 'retryAt' => null];
        foreach ([
            [[], []],
            [['sms'], ['sms' => $permitido]],
            [['voice_call', 'voice_call'], ['voice_call' => $permitido]],
            [['voice_call'], []],
            [['voice_call'], ['voice_call' => ['verdict' => 'defer', 'level' => 'N1', 'reasons' => [], 'retryAt' => null]]],
        ] as $i => [$canales, $decisiones]) {
            try {
                ChannelSelector::choose($canales, $decisiones);
                $this->fail("El caso {$i} no lanzó.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(InvalidArgumentException::class);
        ChannelSelector::choose(['voice_call'], ['voice_call' => $permitido], 'sms');
    }

    public function test_la_prioridad_rechaza_lo_que_no_tiene_forma(): void
    {
        $bien = ['uuid' => '50000001-0000-4000-8000-000000000001', 'purpose' => 'commercial', 'priority' => 50, 'goal' => 'renew'];
        foreach ([
            [[$bien, $bien], null],
            [[['uuid' => '50000001-0000-4000-8000-00000000000A'] + $bien], null],
            [[['priority' => 0] + $bien], null],
            [[['goal' => 'vender'] + $bien], null],
            [[$bien], 'ventas'],
        ] as $i => [$candidatos, $hoy]) {
            try {
                PriorityArbiter::arbitrate($candidatos, $hoy);
                $this->fail("El caso {$i} no lanzó.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(['winner' => null, 'ranking' => [], 'deferred' => []], PriorityArbiter::arbitrate([]));
    }

    /** baseInput + patch, recursivo en los objetos (las listas se sustituyen). */
    private static function merge(array $base, array $patch): array
    {
        foreach ($patch as $k => $v) {
            $base[$k] = is_array($v) && $v !== [] && ! array_is_list($v) && isset($base[$k]) && is_array($base[$k]) ? self::merge($base[$k], $v) : $v;
        }

        return $base;
    }

    /** DF-L9: si el hueco de la llamada pedida cae justo en el fin de la ventana de la inscripción, ya no vale. */
    public function test_la_llamada_pedida_en_el_fin_de_la_ventana(): void
    {
        $base = self::fixture('policy-ladder-cases')['baseInput'];
        // Jueves 12-11 a las 10:00 de Bogotá (15:00Z): dentro de la ley.
        $pedida = ['conversational' => ['callbackAt' => '2026-11-12T15:00:00Z']];
        $d = PolicyLadder::decide(self::merge($base, $pedida + ['strategy' => ['enrollmentWindowEndsAt' => '2026-11-12T15:00:00Z']]));
        $this->assertSame(['deny', 'N7', ['enrollment_window_over'], null], [$d['verdict'], $d['level'], $d['reasons'], $d['retryAt']]);
        $d = PolicyLadder::decide(self::merge($base, $pedida + ['strategy' => ['enrollmentWindowEndsAt' => '2026-11-12T15:00:01Z']]));
        $this->assertSame(['defer', 'N7', ['callback_scheduled'], '2026-11-12T15:00:00Z'], [$d['verdict'], $d['level'], $d['reasons'], $d['retryAt']]);
    }

    /** L9-27, L9-30: en la escalera, el tope por teléfono es de N1 y aplaza al primer hueco legal del día siguiente. */
    public function test_el_tope_diario_por_telefono_en_la_escalera(): void
    {
        $base = self::fixture('policy-ladder-cases')['baseInput'];
        $ref = str_repeat('d', 64);
        $d = PolicyLadder::decide($base + ['phone' => ['refs' => [$ref], 'contacts' => [['at' => '2026-11-10T13:00:00Z', 'phoneRef' => $ref]]]]);
        $this->assertSame(['defer', 'N1', ['daily_contact_limit'], '2026-11-11T13:00:00Z'], [$d['verdict'], $d['level'], $d['reasons'], $d['retryAt']]);
        $this->assertTrue(PolicyLadder::decide($base + ['phone' => ['refs' => [], 'contacts' => [['at' => '2026-11-10T13:00:00Z', 'phoneRef' => $ref]]]])['allowed'], 'desconocido no se cruza');
        $this->assertTrue(PolicyLadder::decide($base + ['phone' => null])['allowed']);
        $this->assertTrue(PolicyLadder::decide($base)['allowed'], 'sin la clave: como hoy (B9-facts y los fixtures no la llevan)');
        foreach (['x', ['refs' => [$ref]]] as $malo) {
            try {
                PolicyLadder::decide($base + ['phone' => $malo]);
                $this->fail('Un teléfono sin forma no lanzó.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** DF-L12: la cartera que no se sabe (known null o false) bloquea lo comercial; ni cobranza ni servicio se bloquean. */
    public function test_la_cartera_desconocida_bloquea_solo_lo_comercial(): void
    {
        $base = self::fixture('policy-ladder-cases')['baseInput'];
        foreach ([null, false] as $sabe) {
            $d = PolicyLadder::decide(self::merge($base, ['receivable' => ['known' => $sabe]]));
            $this->assertSame(['deny', 'N6', ['collections_overdue_blocks_commercial'], null], [$d['verdict'], $d['level'], $d['reasons'], $d['retryAt']], json_encode($sabe));
            foreach (['collections', 'service'] as $finalidad) {
                $this->assertTrue(PolicyLadder::decide(self::merge($base, ['purpose' => $finalidad, 'receivable' => ['known' => $sabe, 'overdueDaysMax' => 90]]))['allowed'], $finalidad);
            }
        }
        $this->assertFalse(PolicyLadder::overdueBlocks(['known' => true, 'overdueDaysMax' => 15], ['serverDays' => 15, 'strategyDays' => null]), 'a los N justos no bloquea');
        $this->assertTrue(PolicyLadder::overdueBlocks(['known' => true, 'overdueDaysMax' => 1], ['serverDays' => 15, 'strategyDays' => 0]), 'la estrategia puede bajarlo a 0');
    }

    /** DF-L11: el horario propio y el de la estrategia son dos motivos de N5, cada uno de su capa; el hueco cumple los dos. */
    public function test_el_horario_propio_y_el_de_la_estrategia_son_motivos_distintos(): void
    {
        $base = self::fixture('policy-ladder-cases')['baseInput'];
        // Martes 08:30 de Bogotá: la ley ya abrió; el propio abre a las 09:00.
        $escena = ['now' => '2026-11-10T13:30:00Z', 'ownHours' => ['weekdays' => [540, 1080], 'saturday' => [540, 780]]];
        $dentro = PolicyLadder::decide(self::merge($base, $escena + ['strategy' => ['schedule' => ['days' => [1, 2, 3, 4, 5], 'windows' => [['from' => 480, 'to' => 600]]]]]));
        $this->assertSame(['defer', 'N5', ['calendar_outside_own_hours'], '2026-11-10T14:00:00Z'], [$dentro['verdict'], $dentro['level'], $dentro['reasons'], $dentro['retryAt']], 'dentro del de la estrategia: solo el propio');
        $fuera = PolicyLadder::decide(self::merge($base, $escena + ['strategy' => ['schedule' => ['days' => [1, 2, 3, 4, 5], 'windows' => [['from' => 600, 'to' => 720]]]]]));
        $this->assertSame(['defer', 'N5', ['calendar_outside_own_hours', 'outside_strategy_schedule'], '2026-11-10T15:00:00Z'], [$fuera['verdict'], $fuera['level'], $fuera['reasons'], $fuera['retryAt']], 'fuera de los dos');
    }
}
