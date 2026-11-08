<?php

namespace Tests\Unit\UltronStudio;

use App\Services\UltronStudio\StudioContentHasher;
use App\Services\UltronStudio\StudioVoiceDrift as D;
use App\Services\UltronStudio\StudioVoiceEvidence as E;
use App\Services\Voice\Dto\VoiceAgentObservation;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * Las decisiones PURAS de la evidencia del canario de voz (D8-19, P-16) y de la
 * deriva (D8-21, E2-3): qué falla, en qué orden y con qué huella; y cuándo un
 * agente o el número están desplegados como se publicaron. Sin base ni red.
 */
class StudioVoiceEvidenceDriftTest extends TestCase
{
    /** @return array<string, mixed> una evidencia que pasa, con lo que se le cambie */
    private static function record(array $over = []): array
    {
        return array_replace_recursive([
            'publication' => ['uuid' => '9a000000-0000-4000-8000-000000000001', 'seq' => 4, 'since' => '2026-11-09T14:00:00Z'],
            'specHash' => str_repeat('a', 64),
            'measures' => ['calls' => 3, 'guardViolations' => 0, 'sealMismatches' => 0, 'driftEvents' => 0, 'unknownProviderCalls' => 0, 'budgetBreaches' => 0],
            'chainVerified' => true,
            'brake' => E::BRAKE_RELEASED,
            'labVerdict' => ['latest' => 'approved', 'sessionUuid' => null, 'at' => null],
            'labGate' => 'required',
            'thresholds' => ['minHours' => 24, 'minCalls' => 3],
        ], $over);
    }

    private static function at(string $iso): DateTimeImmutable
    {
        return new DateTimeImmutable($iso, new DateTimeZone('UTC'));
    }

    public function test_pasa_con_24_horas_n_llamadas_y_cero_incidentes_y_su_huella_es_la_del_registro(): void
    {
        $r = self::record();
        $e = E::evaluate($r, self::at('2026-11-10T14:00:00Z'));
        $this->assertSame(['passes' => true, 'failing' => [], 'hours' => 24, 'evidenceHash' => StudioContentHasher::hash($r)], $e);

        // Un segundo antes de las 24 h, no (horas enteras, hacia abajo).
        $this->assertSame(['too_soon'], E::evaluate($r, self::at('2026-11-10T13:59:59Z'))['failing']);
    }

    public function test_cada_motivo_en_su_orden(): void
    {
        $tarde = self::at('2026-11-12T00:00:00Z');
        $casos = [
            'sin publicación' => [['publication' => null], ['no_canary_publication']],
            'sin la telefonía' => [['measures' => null], ['evidence_unavailable']],
            'la cadena no verifica' => [['chainVerified' => false], ['chain_unverified']],
            'pocas llamadas' => [['measures' => ['calls' => 2]], ['too_few_calls']],
            'una violación de la guarda' => [['measures' => ['guardViolations' => 1]], ['guard_violations']],
            'sello que no cuadra' => [['measures' => ['sealMismatches' => 2]], ['seal_mismatches']],
            'deriva' => [['measures' => ['driftEvents' => 1]], ['drift_events']],
            'llamada desconocida' => [['measures' => ['unknownProviderCalls' => 1]], ['unknown_provider_calls']],
            'techo de gasto' => [['measures' => ['budgetBreaches' => 1]], ['budget_breaches']],
            'freno echado' => [['brake' => E::BRAKE_ENGAGED], ['brake_engaged']],
            'freno sin leer' => [['brake' => E::BRAKE_UNKNOWN], ['brake_engaged']],
            'veredicto rechazado' => [['labVerdict' => ['latest' => 'rejected']], ['lab_verdict_missing']],
            'sin veredicto' => [['labVerdict' => ['latest' => null]], ['lab_verdict_missing']],
            'todo a la vez' => [['chainVerified' => false, 'measures' => ['calls' => 0, 'sealMismatches' => 1, 'budgetBreaches' => 3], 'brake' => E::BRAKE_ENGAGED, 'labVerdict' => ['latest' => null]],
                ['chain_unverified', 'too_few_calls', 'seal_mismatches', 'budget_breaches', 'brake_engaged', 'lab_verdict_missing']],
        ];
        foreach ($casos as $nombre => [$cambio, $fallan]) {
            $r = $cambio === ['publication' => null] ? array_replace(self::record(), $cambio) : self::record($cambio);
            if (array_key_exists('measures', $cambio) && $cambio['measures'] === null) {
                $r['measures'] = null;
            }
            $e = E::evaluate($r, $tarde);
            $this->assertSame($fallan, $e['failing'], $nombre);
            $this->assertFalse($e['passes'], $nombre);
        }

        // Con la puerta del laboratorio dispensada (P-19), el veredicto no hace falta.
        $this->assertTrue(E::evaluate(self::record(['labGate' => 'waived', 'labVerdict' => ['latest' => null]]), $tarde)['passes']);
        $this->assertSame(array_values(E::FAILURES), E::FAILURES, 'motivos en una lista cerrada');
    }

    public function test_la_medida_de_la_telefonia_tiene_que_tener_su_forma(): void
    {
        $bien = ['calls' => 3, 'guardViolations' => 0, 'sealMismatches' => 0, 'driftEvents' => 0, 'unknownProviderCalls' => 0, 'budgetBreaches' => 0];
        $this->assertSame($bien, E::validMeasures($bien + ['otra' => 'se ignora']));
        foreach (['falta una' => array_diff_key($bien, ['driftEvents' => 1]), 'negativa' => ['calls' => -1] + $bien, 'texto' => ['calls' => '3'] + $bien, 'decimal' => ['calls' => 3.0] + $bien] as $nombre => $mala) {
            $this->assertNull(E::validMeasures($mala), $nombre);
        }
        $this->assertNull(E::validMeasures('nada'));
    }

    public function test_el_veredicto_de_la_deriva_de_un_agente(): void
    {
        $proyeccion = ['conversation_config' => ['agent' => ['first_message' => 'Hola']]];
        $observada = new VoiceAgentObservation(VoiceAgentObservation::OUTCOME_OBSERVED, 'agent_1', null, null, $proyeccion, 200, null);
        $esperada = (string) $observada->projectionHash;

        $this->assertSame(D::OK, D::verdict($esperada, $observada));
        $this->assertSame(D::DRIFT, D::verdict(str_repeat('0', 64), $observada));
        $this->assertSame(D::UNREADABLE, D::verdict($esperada, null));
        $this->assertSame(D::UNREADABLE, D::verdict($esperada, new VoiceAgentObservation(VoiceAgentObservation::OUTCOME_UNREACHABLE, 'agent_1', null, null, null, null, null)));
        $this->assertSame(D::UNREADABLE, D::verdict($esperada, new VoiceAgentObservation(VoiceAgentObservation::OUTCOME_NOT_FOUND, 'agent_1', null, null, null, 404, null)));
        $this->assertSame(D::NOT_CONFIGURED, D::verdict($esperada, new VoiceAgentObservation(VoiceAgentObservation::OUTCOME_NOT_CONFIGURED, null, null, null, null, null, null)));
    }

    public function test_el_numero_apunta_al_agente_publicado_de_las_entrantes(): void
    {
        $this->assertSame(D::NUMBER_OK, D::numberVerdict(true, 'agent_1', 'agent_1'));
        $this->assertSame(D::NUMBER_DRIFT, D::numberVerdict(true, 'agent_2', 'agent_1'), 'otro agente: deriva (E2-3)');
        $this->assertSame(D::NUMBER_DRIFT, D::numberVerdict(true, 'agent_1', null), 'con la voz apagada, el número tiene que estar sin agente');
        $this->assertSame(D::NUMBER_DRIFT, D::numberVerdict(true, null, 'agent_1'), 'sin agente con una publicación: deriva');
        $this->assertSame(D::NUMBER_OK, D::numberVerdict(true, null, null));
        $this->assertSame(D::NUMBER_UNKNOWN, D::numberVerdict(false, null, 'agent_1'), 'sin leerlo no se sabe');
        $this->assertSame(D::NUMBER_UNKNOWN, D::numberVerdict(true, "agent_1\n", 'agent_1'), 'un id sin forma no se compara');
    }
}
