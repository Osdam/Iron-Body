<?php

namespace Tests\Unit\Outreach\Core;

use App\Services\Outreach\Core\PolicyLadder;
use PHPUnit\Framework\TestCase;

/**
 * I-1 de la auditoría de cierre B (aprobada por el lead el 11-11-2026;
 * contrato H9, D9-11.6 y D9-7 N3) sobre `PolicyLadder` REAL: en sombra,
 * `canary_only_declared` (la versión del Studio declara
 * `governance.canaryOnly` y la persona no está en el alcance canario del
 * medio) es un AVISO y la escalera sigue con N4–N7; fuera de la sombra niega
 * exactamente como antes. Nada más cambia: N1 y N2 siguen decidiendo antes,
 * el resto de N3 sigue negando y una persona canaria no deja aviso.
 */
class H9ShadowCanaryOnlyLadderTest extends TestCase
{
    use LoadsOutreachFixtures;

    private const CANALES = ['assisted:whatsapp', 'assisted:voice', 'whatsapp_template', 'voice_call'];

    private const AVISO = ['level' => 'N3', 'reason' => 'canary_only_declared'];

    /** @param  array<string, mixed>  $patch */
    private static function decide(string $mode, string $channel, array $patch = []): array
    {
        return PolicyLadder::decide(array_replace_recursive(self::fixture('policy-ladder-cases')['baseInput'], ['flags' => ['mode' => $mode], 'channel' => $channel], $patch));
    }

    /** «Solo canario» declarado y la persona fuera del alcance (`false`) o sin alcance conocido (`null`). */
    private static function fuera(?bool $alcance, string $parte = 'rest'): array
    {
        return ['studio' => ['canaryOnly' => true], 'strategy' => ['inCanaryScope' => $alcance, 'part' => $parte]];
    }

    public function test_en_sombra_solo_canario_avisa_y_la_escalera_sigue_hasta_n7_en_cada_canal(): void
    {
        foreach (self::CANALES as $canal) {
            foreach ([false, null] as $alcance) {
                $d = self::decide('shadow', $canal, self::fuera($alcance));
                $donde = "{$canal} con alcance ".json_encode($alcance);
                $this->assertSame(['allow', true, null, [], null, null], [$d['verdict'], $d['allowed'], $d['level'], $d['reasons'], $d['retryAt'], $d['effect']], $donde);
                $this->assertSame(array_fill(0, 7, 'pass'), array_column($d['levels'], 'status'), "{$donde}: N4–N7 se evalúan");
                $this->assertSame(self::AVISO, $d['warnings'][0], "{$donde}: el aviso va en N3 y antes que los de N4");
                $this->assertCount(1, array_keys($d['warnings'], self::AVISO, true), $donde);
            }
        }
        // Con los demás avisos de sombra que correspondan: la plantilla de WhatsApp (constante cerrada, sin presupuesto) y la parte canaria (N5).
        $this->assertSame(
            [self::AVISO, ['level' => 'N4', 'reason' => 'sends_disabled_in_code'], ['level' => 'N4', 'reason' => 'budget_zero'], ['level' => 'N5', 'reason' => 'canary_scope_mismatch']],
            self::decide('shadow', 'whatsapp_template', self::fuera(false, 'canary'))['warnings'],
        );
    }

    public function test_fuera_de_la_sombra_niega_en_n3_exactamente_como_antes(): void
    {
        $vivo = ['flags' => ['sendsEnabledInCode' => true], 'budget' => ['dailyBudgetMicros' => 10_000_000, 'costMicros' => 50_000, 'channelDailyCap' => 100]];
        foreach (self::CANALES as $canal) {
            foreach ([false, null] as $alcance) {
                $d = self::decide('live', $canal, array_replace_recursive($vivo, self::fuera($alcance)));
                $this->assertSame([
                    'verdict' => 'deny', 'allowed' => false, 'level' => 'N3', 'reasons' => ['canary_only_declared'], 'retryAt' => null, 'waitsForRelease' => false, 'effect' => null,
                    'levels' => [
                        ['level' => 'N1', 'status' => 'pass'], ['level' => 'N2', 'status' => 'pass'], ['level' => 'N3', 'status' => 'deny'], ['level' => 'N4', 'status' => 'not_evaluated'],
                        ['level' => 'N5', 'status' => 'not_evaluated'], ['level' => 'N6', 'status' => 'not_evaluated'], ['level' => 'N7', 'status' => 'not_evaluated'],
                    ],
                    'warnings' => [],
                ], $d, "{$canal} en vivo con alcance ".json_encode($alcance));
            }
        }
        // Con la ejecución apagada decide N2 (`mode_off`), antes que N3, como siempre.
        $apagado = self::decide('off', 'assisted:whatsapp', self::fuera(false));
        $this->assertSame(['deny', 'N2', ['mode_off'], []], [$apagado['verdict'], $apagado['level'], $apagado['reasons'], $apagado['warnings']]);
    }

    public function test_la_persona_canaria_o_sin_solo_canario_no_deja_aviso(): void
    {
        foreach (['shadow', 'live'] as $modo) {
            $canaria = self::decide($modo, 'assisted:whatsapp', ['studio' => ['canaryOnly' => true], 'strategy' => ['inCanaryScope' => true]]);
            $this->assertSame(['allow', []], [$canaria['verdict'], $canaria['warnings']], "{$modo}: canaria");
            foreach ([false, null] as $alcance) {
                $sinDeclarar = self::decide($modo, 'assisted:whatsapp', ['studio' => ['canaryOnly' => false], 'strategy' => ['inCanaryScope' => $alcance]]);
                $this->assertSame(['allow', []], [$sinDeclarar['verdict'], $sinDeclarar['warnings']], "{$modo}: sin «solo canario»");
            }
        }
    }

    public function test_la_precedencia_no_cambia_n1_y_n2_deciden_antes_y_lo_demas_de_n3_y_los_inferiores_siguen_negando(): void
    {
        $fuera = self::fuera(false);
        // N1 y N2 deciden antes: N3 ni se evalúa, así que no hay aviso.
        $n1 = self::decide('shadow', 'assisted:whatsapp', array_replace_recursive($fuera, ['optOut' => ['doNotContact' => true]]));
        $this->assertSame(['deny', 'N1', ['do_not_contact'], []], [$n1['verdict'], $n1['level'], $n1['reasons'], $n1['warnings']]);
        $n2 = self::decide('shadow', 'assisted:whatsapp', array_replace_recursive($fuera, ['brakes' => ['global' => true]]));
        $this->assertSame(['deny', 'N2', ['outreach_brake_global'], true, []], [$n2['verdict'], $n2['level'], $n2['reasons'], $n2['waitsForRelease'], $n2['warnings']]);

        // Lo demás de N3 niega igual en sombra, sin el canario entre los motivos.
        foreach ([
            [['studio' => ['referenceValid' => false]], ['studio_reference_invalid']],
            [['studio' => ['catalogCompatible' => false]], ['catalog_incompatible']],
            [['studio' => ['paymentLinksEnabled' => false], 'step' => ['usesPaymentLink' => true]], ['payment_links_disabled']],
            [['studio' => ['templateViolations' => ['R-02']]], ['template_violates_restriction']],
        ] as [$patch, $motivos]) {
            $d = self::decide('shadow', 'assisted:whatsapp', array_replace_recursive($fuera, $patch));
            $this->assertSame(['deny', 'N3', $motivos, [self::AVISO]], [$d['verdict'], $d['level'], $d['reasons'], $d['warnings']], json_encode($patch));
        }

        // Y lo que está por debajo se evalúa de verdad: cada nivel inferior sigue pudiendo negar o aplazar, con el aviso de N3 delante.
        foreach ([
            ['N4', ['step' => ['templateApproved' => false]], 'deny', ['template_not_approved']],
            ['N5', ['strategy' => ['paused' => true]], 'deny', ['strategy_paused']],
            ['N6', ['context' => ['audience' => 'false']], 'deny', ['context_changed']],
            ['N7', ['conversational' => ['callbackAt' => '2026-11-12T16:00:00Z']], 'defer', ['callback_scheduled']],
        ] as [$nivel, $patch, $veredicto, $motivos]) {
            $d = self::decide('shadow', 'assisted:whatsapp', array_replace_recursive($fuera, $patch));
            $this->assertSame([$veredicto, $nivel, $motivos], [$d['verdict'], $d['level'], $d['reasons']], $nivel);
            $this->assertSame(self::AVISO, $d['warnings'][0], $nivel);
        }
    }

    public function test_el_aviso_es_de_los_avisos_de_sombra_y_no_es_temporal(): void
    {
        $this->assertContains('canary_only_declared', PolicyLadder::SHADOW_WARNINGS);
        $this->assertContains('canary_only_declared', PolicyLadder::REASON_ORDER['N3']);
        $this->assertNotContains('canary_only_declared', PolicyLadder::TEMPORAL, 'en vivo es una negativa permanente: sin retryAt');
    }
}
