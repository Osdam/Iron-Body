<?php

namespace Tests\Unit\Outreach\Core;

use App\Services\Outreach\Core\ContactCalendar;
use App\Services\Outreach\Core\FollowUpMachine;
use App\Services\Outreach\Core\OutcomeTable;
use App\Services\Outreach\Core\RelativeTime;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PARIDAD de los resultados normalizados (`outcome-transitions.json`: la
 * tabla, la matriz del `on` y la resolución por fuente, con la regla
 * asimétrica de la IA; T14, §13.1 #10, #21, #22) y de la máquina de
 * seguimientos (`followup-cases.json`; T23, §13.1 #17), caso a caso. Y las
 * propiedades: el clasificador nunca produce `next_step` ni `retry_step`; la
 * máquina nunca acorta el retraso del paso ni cae en domingo o festivo.
 */
class OutcomesAndFollowUpParityTest extends TestCase
{
    use LoadsOutreachFixtures;

    public function test_la_tabla_y_los_conjuntos_son_los_del_fixture(): void
    {
        $f = self::fixture('outcome-transitions');
        $this->assertSame($f['table'], OutcomeTable::TABLE);
        $this->assertSame($f['allSources'], OutcomeTable::SOURCES);
        $this->assertSame($f['sources15_11'], OutcomeTable::SOURCES_AVAILABLE);
        $this->assertSame($f['classifierSafeTransitions'], OutcomeTable::CLASSIFIER_SAFE_TRANSITIONS);
        $this->assertSame(['low' => OutcomeTable::CLASSIFIER_LOW, 'high' => OutcomeTable::CLASSIFIER_HIGH], $f['classifierThresholds']);
        $this->assertSame($f['retryMax'], OutcomeTable::RETRY_MAX);
        $this->assertSame($f['statusByTransition'], OutcomeTable::STATUS_BY_TRANSITION);
        $this->assertSame($f['enrollmentStatuses'], OutcomeTable::ENROLLMENT_STATUSES);
        $this->assertCount(18, OutcomeTable::OUTCOMES);
        $this->assertSame(OutcomeTable::sortedOutcomes(), array_keys(OutcomeTable::TABLE));
    }

    /** @return iterable<string, array{array<string,mixed>}> */
    public static function outcomes(): iterable
    {
        return self::cases('outcome-transitions', ['matrix', 'resolve']);
    }

    #[DataProvider('outcomes')]
    public function test_resultado(array $caso): void
    {
        $obtenido = $caso['_section'] === 'matrix'
            ? OutcomeTable::check($caso['outcome'], $caso['transition'])
            : OutcomeTable::resolve($caso['outcome'], $caso['source'], $caso['confidence'], $caso['on'], $caso['attempt']);

        $this->assertSame($caso['expected'], $obtenido);
    }

    /** @return iterable<string, array{array<string,mixed>}> */
    public static function followUps(): iterable
    {
        return self::cases('followup-cases', ['apply']);
    }

    #[DataProvider('followUps')]
    public function test_seguimiento(array $caso): void
    {
        $resolucion = OutcomeTable::resolve($caso['outcome'], $caso['source'], $caso['confidence'] ?? null, $caso['on'] ?? null, $caso['enrollment']['attempt']);
        $r = FollowUpMachine::apply($caso['enrollment'], $caso['steps'], $resolucion, RelativeTime::parseInstant($caso['now']), [
            'waitFor' => $caso['waitFor'] ?? null, 'callbackAt' => $caso['callbackAt'] ?? null, 'maxTouches' => $caso['maxTouches'] ?? null,
            'ownHours' => $caso['ownHours'] ?? null, 'schedule' => $caso['schedule'] ?? null,
        ]);

        $this->assertSame($caso['expected'], ['resolution' => ['verdict' => $resolucion['verdict'], 'transition' => $resolucion['transition']]] + $r);
        $this->assertContains($r['reason'], FollowUpMachine::REASONS);
    }

    /** P9-6: haga lo que haga la estrategia, un resultado del clasificador nunca avanza ni reintenta. */
    public function test_el_clasificador_solo_frena_o_deriva(): void
    {
        foreach (OutcomeTable::OUTCOMES as $resultado) {
            foreach ([null, ...OutcomeTable::TRANSITIONS] as $pedida) {
                foreach ([0, 49, 50, 89, 90, 100, null, 101, 99.5] as $confianza) {
                    $r = OutcomeTable::resolve($resultado, 'classifier', $confianza, $pedida === null ? null : [$resultado => $pedida], 1);
                    $this->assertNotContains($r['transition'], [OutcomeTable::NEXT_STEP, OutcomeTable::RETRY_STEP], "{$resultado}/{$pedida}/".json_encode($confianza));
                    if ($r['verdict'] === OutcomeTable::VERDICT_APPLY) {
                        $this->assertContains($r['transition'], OutcomeTable::CLASSIFIER_SAFE_TRANSITIONS);
                    }
                }
            }
        }
    }

    /** §13.1 #10: `opted_out` siempre sale con supresión y cancelación, de cualquier fuente que pueda producirlo. */
    public function test_opted_out_siempre_end_exit(): void
    {
        foreach (OutcomeTable::TABLE['opted_out']['sources'] as $fuente) {
            foreach ([null, ...OutcomeTable::TRANSITIONS] as $pedida) {
                $r = OutcomeTable::resolve('opted_out', $fuente, 100, $pedida === null ? null : ['opted_out' => $pedida], 1);
                $this->assertSame(['end_exit', 'opted_out', ['cancel_pending_all_strategies', 'suppress_purpose_channel']], [$r['transition'], $r['enrollmentStatus'], $r['effects']]);
                $this->assertSame($pedida !== null && $pedida !== 'end_exit', $r['incident']);
            }
        }
    }

    /** T23: el siguiente trabajo nunca sale antes del retraso del paso, de «ahora», ni en domingo o festivo, ni fuera de la ventana. */
    public function test_la_maquina_nunca_acorta_un_intervalo(): void
    {
        $pasos = [
            ['id' => 'a', 'delay' => ['n' => 0, 'unit' => 'hours', 'from' => 'enrollment']],
            ['id' => 'b', 'delay' => ['n' => 2, 'unit' => 'days', 'from' => 'previous']],
            ['id' => 'c', 'delay' => ['n' => 1, 'unit' => 'weeks', 'from' => 'enrollment']],
        ];
        $inscripcion = ['status' => 'active', 'stepIndex' => 0, 'attempt' => 1, 'touches' => 1, 'enrolledAt' => '2026-11-02T15:00:00Z', 'windowEndsAt' => '2027-01-31T05:00:00Z'];
        $inicio = RelativeTime::parseInstant('2026-11-06T00:00:00Z');
        foreach (['next_step', 'retry_step', 'wait'] as $t) {
            for ($h = 0; $h < 24 * 21; $h += 7) {
                $ahora = $inicio->modify("+{$h} hours");
                foreach ([0, 1] as $paso) {
                    $e = ['stepIndex' => $paso] + $inscripcion;
                    $r = FollowUpMachine::apply($e, $pasos, ['verdict' => 'apply', 'transition' => $t, 'enrollmentStatus' => 'active'], $ahora, ['waitFor' => ['n' => 3, 'unit' => 'days']]);
                    $this->assertNotNull($r['nextJob']);
                    $cuando = RelativeTime::parseInstant($r['nextJob']['notBefore']);
                    $planificado = $pasos[$r['nextJob']['stepIndex']]['delay'];
                    $base = $planificado['from'] === 'enrollment' ? RelativeTime::parseInstant($e['enrolledAt']) : $ahora;
                    $this->assertGreaterThanOrEqual($base->modify('+'.($planificado['n'] * ['hours' => 1, 'days' => 24, 'weeks' => 168][$planificado['unit']]).' hours'), $cuando);
                    $this->assertGreaterThanOrEqual($ahora, $cuando);
                    if ($t === 'wait') {
                        $this->assertGreaterThanOrEqual($ahora->modify('+72 hours'), $cuando);
                    }
                    $this->assertTrue(ContactCalendar::allows($cuando, null, null), $r['nextJob']['notBefore']);
                }
            }
        }
    }

    public function test_entradas_sin_forma_o_finales(): void
    {
        $pasos = [['id' => 'a', 'delay' => ['n' => 0, 'unit' => 'hours', 'from' => 'enrollment']]];
        $bien = ['status' => 'active', 'stepIndex' => 0, 'attempt' => 1, 'touches' => 0, 'enrolledAt' => '2026-11-02T15:00:00Z', 'windowEndsAt' => '2026-11-30T05:00:00Z'];
        $ahora = RelativeTime::parseInstant('2026-11-10T15:00:00Z');
        $sigue = ['verdict' => 'apply', 'transition' => 'next_step', 'enrollmentStatus' => 'active'];
        $this->assertSame('enrollment_final', FollowUpMachine::apply(['status' => 'opted_out'] + $bien, $pasos, $sigue, $ahora)['reason']);
        $this->assertSame('sequence_done_waits_window', FollowUpMachine::apply($bien, $pasos, $sigue, $ahora)['reason']);
        foreach ([['stepIndex' => 3] + $bien, ['attempt' => 0] + $bien, ['status' => 'raro'] + $bien, ['enrolledAt' => 'ayer'] + $bien] as $i => $roto) {
            try {
                FollowUpMachine::apply($roto, $pasos, $sigue, $ahora);
                $this->fail("La inscripción rota {$i} no lanzó.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(InvalidArgumentException::class);
        FollowUpMachine::apply($bien, [['id' => 'a', 'delay' => ['n' => -1, 'unit' => 'hours', 'from' => 'enrollment']]], $sigue, $ahora);
    }

    /** DF-F3: un notBefore que cae justo en el fin de la ventana no da trabajo (la ventana es semiabierta). */
    public function test_el_trabajo_en_el_fin_de_la_ventana(): void
    {
        $pasos = [
            ['id' => 'a', 'delay' => ['n' => 0, 'unit' => 'hours', 'from' => 'enrollment']],
            ['id' => 'b', 'delay' => ['n' => 2, 'unit' => 'days', 'from' => 'previous']],
        ];
        $ahora = RelativeTime::parseInstant('2026-11-10T15:00:00Z');
        $e = ['status' => 'active', 'stepIndex' => 0, 'attempt' => 1, 'touches' => 1, 'enrolledAt' => '2026-11-09T15:00:00Z'];
        $siguiente = ['verdict' => 'apply', 'transition' => 'next_step', 'enrollmentStatus' => 'active'];
        // Dos días después: jueves 12-11 a las 10:00 de Bogotá (15:00Z), dentro de la ley.
        $r = FollowUpMachine::apply($e + ['windowEndsAt' => '2026-11-12T15:00:00Z'], $pasos, $siguiente, $ahora);
        $this->assertSame(['waiting', null, 'window_over_no_job'], [$r['status'], $r['nextJob'], $r['reason']]);
        $r = FollowUpMachine::apply($e + ['windowEndsAt' => '2026-11-12T15:00:01Z'], $pasos, $siguiente, $ahora);
        $this->assertSame(['stepIndex' => 1, 'attempt' => 1, 'notBefore' => '2026-11-12T15:00:00Z'], $r['nextJob']);
    }
}
