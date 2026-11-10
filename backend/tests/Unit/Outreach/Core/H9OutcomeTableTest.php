<?php

namespace Tests\Unit\Outreach\Core;

use App\Services\Outreach\Core\DecisionTraceText;
use App\Services\Outreach\Core\FollowUpMachine;
use App\Services\Outreach\Core\OutcomeTable;
use App\Services\Outreach\Core\RelativeTime;
use PHPUnit\Framework\TestCase;

/**
 * §13.1 #21, texto literal del propietario: «Respuesta conversacional
 * ambigua». Clasificación `ambiguous` → veredicto `needs_review` (no es un
 * resultado del conjunto cerrado): para la secuencia y crea una tarea
 * asistida; ninguna acción automática (regla asimétrica P9-6). Sobre
 * `OutcomeTable` y `FollowUpMachine` reales.
 */
class H9OutcomeTableTest extends TestCase
{
    private const REVISION = ['verdict' => 'needs_review', 'outcome' => null, 'transition' => 'handoff_human', 'enrollmentStatus' => 'handed_off', 'effects' => ['assisted_task'], 'incident' => false];

    public function test_ambiguous_es_needs_review_con_cualquier_confianza_y_diga_lo_que_diga_la_estrategia(): void
    {
        foreach ([0, 49, 50, 89, 90, 100] as $confianza) {
            foreach ([null, ...OutcomeTable::TRANSITIONS] as $pedida) {
                $r = OutcomeTable::resolve('ambiguous', 'classifier', $confianza, $pedida === null ? null : ['ambiguous' => $pedida], 1);
                $this->assertSame(self::REVISION, $r, "{$confianza} / ".json_encode($pedida));
            }
        }
        $this->assertFalse(OutcomeTable::isOutcome('needs_review'), 'needs_review es un veredicto, no un resultado (L9-12)');
    }

    public function test_por_debajo_del_umbral_tambien_es_revision(): void
    {
        foreach (OutcomeTable::TABLE as $resultado => $fila) {
            if (! in_array('classifier', $fila['sources'], true)) {
                continue;
            }
            $bajo = $resultado === 'opted_out' ? OutcomeTable::CLASSIFIER_LOW - 1 : OutcomeTable::CLASSIFIER_HIGH - 1;
            $this->assertSame(self::REVISION, OutcomeTable::resolve($resultado, 'classifier', $bajo, null, 1), $resultado);
        }
    }

    public function test_para_la_secuencia_sin_ninguna_accion_automatica(): void
    {
        $pasos = [
            ['id' => 'a', 'delay' => ['n' => 0, 'unit' => 'hours', 'from' => 'enrollment']],
            ['id' => 'b', 'delay' => ['n' => 1, 'unit' => 'days', 'from' => 'previous']],
        ];
        $inscripcion = ['status' => 'active', 'stepIndex' => 0, 'attempt' => 1, 'touches' => 1, 'enrolledAt' => '2026-11-09T15:00:00Z', 'windowEndsAt' => '2026-11-30T05:00:00Z'];
        $r = FollowUpMachine::apply($inscripcion, $pasos, OutcomeTable::resolve('ambiguous', 'classifier', 97, ['ambiguous' => 'next_step'], 1), RelativeTime::parseInstant('2026-11-10T15:00:00Z'));

        $this->assertSame(['status' => 'handed_off', 'stepIndex' => 0, 'attempt' => 1, 'nextJob' => null, 'reason' => 'closed'], $r);
        $this->assertSame('Necesita revisión humana', DecisionTraceText::VERDICTS['needs_review']);
        $this->assertSame('Se abre una tarea en la bandeja asistida.', DecisionTraceText::text('assisted_task'));
    }
}
