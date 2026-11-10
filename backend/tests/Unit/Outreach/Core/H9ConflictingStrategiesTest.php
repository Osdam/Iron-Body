<?php

namespace Tests\Unit\Outreach\Core;

use App\Services\Outreach\Core\CanonicalCondition;
use App\Services\Outreach\Core\DecisionTrace;
use App\Services\Outreach\Core\DecisionTraceText;
use App\Services\Outreach\Core\EnrollmentGate;
use App\Services\Outreach\Core\FactSnapshot;
use App\Services\Outreach\Core\PriorityArbiter;
use App\Services\Outreach\Core\RelativeTime;
use PHPUnit\Framework\TestCase;

/**
 * §13.1 #12, texto literal del propietario: «Reglas contradictorias entre
 * campañas». Dos estrategias con reglas opuestas sobre la misma persona: gana
 * la escalera (N1–N7) y la prioridad; la traza nombra la regla que decidió;
 * nunca dos contactos. Sobre `EnrollmentGate`, `PriorityArbiter`,
 * `PolicyLadder` y `DecisionTrace` reales.
 */
class H9ConflictingStrategiesTest extends TestCase
{
    use H9Scenario;

    private const RENOVAR = '7a000000-0000-4000-8000-000000000001';

    private const REACTIVAR = '7a000000-0000-4000-8000-000000000002';

    private const AHORA = '2026-11-10T15:00:00Z';

    public function test_reglas_opuestas_solo_una_inscribe_y_la_traza_nombra_la_regla(): void
    {
        $vence = ['fact' => 'membership.days_to_expiry', 'op' => 'lte', 'value' => 7];
        $activa = ['fact' => 'membership.active', 'op' => 'eq', 'value' => true];
        $exito = CanonicalCondition::parse(['fact' => 'membership.days_to_expiry', 'op' => 'gt', 'value' => 30]);
        $foto = FactSnapshot::fromArray(['facts' => ['membership.active' => true, 'membership.days_to_expiry' => 5]]);
        $ahora = RelativeTime::parseInstant(self::AHORA);

        // RENOVAR inscribe a quien vence en ≤ 7 días; REACTIVAR excluye justo a esos.
        $a = EnrollmentGate::evaluate(CanonicalCondition::parse($vence), null, $exito, $foto, $ahora);
        $b = EnrollmentGate::evaluate(CanonicalCondition::parse($activa), CanonicalCondition::parse($vence), $exito, $foto, $ahora);
        $this->assertSame([true, 'enroll'], [$a['enroll'], $a['reason']]);
        $this->assertSame([false, 'excluded'], [$b['enroll'], $b['reason']]);
        $this->assertSame('Cumple una exclusión.', DecisionTraceText::text($b['reason']));

        // La traza de REACTIVAR nombra la regla que decidió: el hecho, el operador y el valor de su exclusión.
        $traza = DecisionTrace::build(['kind' => 'exclude', 'reasons' => [$b['reason']]], $foto, $ahora, ['exclusions' => $b['exclusions']]);
        $hoja = $traza['conditions']['exclusions']['trace'][0];
        $this->assertSame(['fact', 'membership.days_to_expiry', 'lte', 7, 5, 'true'], [$hoja['kind'], $hoja['fact'], $hoja['op'], $hoja['expected'], $hoja['observed'], $hoja['result']]);
    }

    public function test_el_mismo_dia_gana_la_prioridad_y_nunca_hay_dos_contactos(): void
    {
        $arbitraje = PriorityArbiter::arbitrate([
            ['uuid' => self::REACTIVAR, 'purpose' => 'commercial', 'priority' => 20, 'goal' => 'reactivate'],
            ['uuid' => self::RENOVAR, 'purpose' => 'commercial', 'priority' => 80, 'goal' => 'renew'],
        ]);
        $this->assertSame(self::RENOVAR, $arbitraje['winner']);
        $this->assertSame([['uuid' => self::REACTIVAR, 'reason' => 'lower_priority']], $arbitraje['deferred']);

        // La que pierde, con su marca de prioridad: N6 aplaza a mañana y la traza lo dice.
        $perdedora = self::ladderFor('whatsapp_template', ['context' => ['lowerPriority' => true]]);
        $this->assertSame(['defer', 'N6', ['lower_priority'], '2026-11-11T13:00:00Z'], [$perdedora['verdict'], $perdedora['level'], $perdedora['reasons'], $perdedora['retryAt']]);
        $this->assertSame([
            'Se aplaza el contacto (N6 · Contexto verificado del contacto).',
            'Otra estrategia tiene prioridad hoy.',
            'Se vuelve a intentar el 11-11-2026 a las 08:00 (Bogotá).',
        ], DecisionTraceText::explain($perdedora));

        // La ganadora sale; una vez registrado su contacto, la otra no sale en lo que queda del día, aunque ya no pierda en prioridad.
        $this->assertTrue(self::ladderFor('whatsapp_template')['allowed']);
        $contacto = [['at' => self::AHORA, 'medium' => 'whatsapp', 'purpose' => 'commercial', 'source' => 'outreach']];
        $fin = RelativeTime::parseInstant('2026-11-11T05:00:00Z');
        for ($t = RelativeTime::parseInstant(self::AHORA); $t < $fin; $t = $t->modify('+15 minutes')) {
            $d = self::ladderFor('whatsapp_template', ['now' => RelativeTime::format($t), 'history' => $contacto]);
            $this->assertFalse($d['allowed'], RelativeTime::format($t));
            $this->assertSame('N1', $d['level']);
            $this->assertContains('daily_contact_limit', $d['reasons'], RelativeTime::format($t));
        }
    }

    public function test_la_escalera_manda_sobre_la_prioridad(): void
    {
        // REACTIVAR tiene la prioridad más alta pero usa un enlace de pago que la versión comercial no permite.
        $arbitraje = PriorityArbiter::arbitrate([
            ['uuid' => self::RENOVAR, 'purpose' => 'commercial', 'priority' => 1, 'goal' => 'renew'],
            ['uuid' => self::REACTIVAR, 'purpose' => 'commercial', 'priority' => 100, 'goal' => 'reactivate'],
        ]);
        $this->assertSame(self::REACTIVAR, $arbitraje['winner']);
        $d = self::ladderFor('whatsapp_template', ['step' => ['usesPaymentLink' => true], 'studio' => ['paymentLinksEnabled' => false]]);
        $this->assertSame(['deny', 'N3', ['payment_links_disabled']], [$d['verdict'], $d['level'], $d['reasons']]);
        $this->assertSame(['No se contacta (N3 · Versión comercial aprobada).', 'La versión comercial no permite enlaces de pago.'], DecisionTraceText::explain($d));

        // Y entre finalidades, el servicio va antes que lo comercial aunque su prioridad sea menor (P9-7).
        $porFinalidad = PriorityArbiter::arbitrate([
            ['uuid' => self::RENOVAR, 'purpose' => 'service', 'priority' => 1, 'goal' => 'complete_onboarding'],
            ['uuid' => self::REACTIVAR, 'purpose' => 'commercial', 'priority' => 100, 'goal' => 'reactivate'],
        ]);
        $this->assertSame(self::RENOVAR, $porFinalidad['winner']);
        $this->assertSame([['uuid' => self::REACTIVAR, 'reason' => 'purpose_conflict_today']], $porFinalidad['deferred']);
        $otraFinalidad = self::ladderFor('whatsapp_template', ['context' => ['otherPurposeToday' => true]]);
        $this->assertSame(['defer', 'N6', ['purpose_conflict_today']], [$otraFinalidad['verdict'], $otraFinalidad['level'], $otraFinalidad['reasons']]);
    }
}
