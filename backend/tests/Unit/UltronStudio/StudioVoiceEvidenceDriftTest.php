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
            'measures' => ['calls' => 3, 'guardViolations' => 0, 'sealMismatches' => 0, 'driftEvents' => 0, 'unknownProviderCalls' => 0, 'budgetBreaches' => 0, 'missingPostCall' => 0],
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
            // E13-3: una llamada sellada sin su post-llamada procesada no prueba nada.
            'post-llamada que falta' => [['measures' => ['missingPostCall' => 1]], ['missing_post_call']],
            'freno echado' => [['brake' => E::BRAKE_ENGAGED], ['brake_engaged']],
            'freno sin leer' => [['brake' => E::BRAKE_UNKNOWN], ['brake_engaged']],
            'veredicto rechazado' => [['labVerdict' => ['latest' => 'rejected']], ['lab_verdict_missing']],
            'sin veredicto' => [['labVerdict' => ['latest' => null]], ['lab_verdict_missing']],
            'todo a la vez' => [['chainVerified' => false, 'measures' => ['calls' => 0, 'sealMismatches' => 1, 'budgetBreaches' => 3, 'missingPostCall' => 2], 'brake' => E::BRAKE_ENGAGED, 'labVerdict' => ['latest' => null]],
                ['chain_unverified', 'too_few_calls', 'seal_mismatches', 'budget_breaches', 'missing_post_call', 'brake_engaged', 'lab_verdict_missing']],
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
        $this->assertSame([
            'no_canary_publication', 'evidence_unavailable', 'chain_unverified', 'too_soon', 'too_few_calls',
            'guard_violations', 'seal_mismatches', 'drift_events', 'unknown_provider_calls', 'budget_breaches',
            'missing_post_call', 'brake_engaged', 'lab_verdict_missing',
        ], E::FAILURES, 'E13-3: `missing_post_call` en la lista cerrada, detrás de los recuentos de incidentes');
    }

    public function test_e13_3_un_registro_sin_missing_post_call_no_pasa_aunque_lo_demas_cuadre(): void
    {
        // Un registro escrito con la forma anterior a E13-3 (seis claves) no
        // cuenta el recuento que falta como un cero: falla cerrado.
        $r = self::record();
        unset($r['measures']['missingPostCall']);
        $e = E::evaluate($r, self::at('2026-11-12T00:00:00Z'));
        $this->assertFalse($e['passes']);
        $this->assertSame(['missing_post_call'], $e['failing']);
    }

    public function test_la_medida_de_la_telefonia_tiene_que_tener_su_forma(): void
    {
        $bien = ['calls' => 3, 'guardViolations' => 0, 'sealMismatches' => 0, 'driftEvents' => 0, 'unknownProviderCalls' => 0, 'budgetBreaches' => 0, 'missingPostCall' => 0];
        $this->assertSame($bien, E::validMeasures($bien));
        $this->assertSame(E::MEASURE_KEYS, array_keys(E::validMeasures(array_reverse($bien, true)) ?? []), 'sale en el orden de MEASURE_KEYS');
        $this->assertSame(['calls', 'guardViolations', 'sealMismatches', 'driftEvents', 'unknownProviderCalls', 'budgetBreaches', 'missingPostCall'], E::MEASURE_KEYS);
        foreach ([
            'falta una' => array_diff_key($bien, ['driftEvents' => 1]),
            // E13-3: la forma de la telefonía anterior (seis claves) ya no es evidencia.
            'sin missingPostCall (forma anterior a E13-3)' => array_diff_key($bien, ['missingPostCall' => 1]),
            // «Exactas»: una de más tampoco (nunca se ignora en silencio).
            'una de más' => $bien + ['otra' => 0],
            'negativa' => ['calls' => -1] + $bien,
            'missingPostCall negativa' => ['missingPostCall' => -1] + $bien,
            'texto' => ['calls' => '3'] + $bien,
            'decimal' => ['calls' => 3.0] + $bien,
            'null' => ['missingPostCall' => null] + $bien,
            'lista de siete' => array_values($bien),
        ] as $nombre => $mala) {
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
