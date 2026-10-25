<?php

namespace Tests\Unit\UltronStudio;

use App\Services\Marketing\Ultron\UltronPublishedConfig as M;
use App\Services\UltronStudio\StudioCanaryEvidence as E;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * La parte pura de la evidencia del canario (contrato H5 §A6 y §A5.2) en lo que
 * el fixture no afirma: la paridad caso a caso está en
 * `StudioPublicationParityTest`.
 *
 * LO QUE SE PROTEGE. Que `byState` hable con los estados del sello del motor;
 * que las horas sean horas enteras y nunca negativas; que la huella no dependa
 * del reloj ni de cómo llega `since`, y sí de cada recuento y de cada umbral;
 * que una evidencia sin forma o incoherente no se evalúe (lanza, no promueve);
 * que los umbrales respeten los mínimos duros de §A13; y el orden de las dos
 * puertas de la evidencia al promover. Relojes en UTC (memoria «Relojes de
 * prueba en UTC»).
 */
class StudioCanaryEvidenceTest extends TestCase
{
    private const SINCE = '2026-11-03T15:04:05Z';

    private const THRESHOLDS = ['minHours' => 24, 'minTurns' => 3];

    /** Los siete contadores del acta (las claves de `ultron:canary-report --json`). */
    private const ACTA = ['traspasos_no_autorizados', 'horarios_aplazados_en_una_persona', 'planes_no_vendibles', 'precios_que_no_son_del_catalogo',
        'enlaces_no_oficiales', 'datos_personales', 'negaciones_de_un_cobro_disponible'];

    /** Una evidencia que pasa a las 24 h: 3 turnos sellados al 100 %, sin frenos, acta limpia, sin deriva. */
    private static function measures(array $cambios = []): array
    {
        return array_replace([
            'since' => self::SINCE,
            'turns' => 3,
            'stamped' => 3,
            'byState' => ['applied' => 3] + array_fill_keys(E::BY_STATE, 0),
            'brakes' => ['count' => 0, 'engaged' => false],
            'acta' => ['exitCode' => 0, 'total' => 0, 'counts' => array_fill_keys(self::ACTA, 0)],
            'recoveries' => 0,
            'drift' => [],
            'signatureOk' => true,
        ], $cambios);
    }

    private static function now(int $segundosDesdeSince = 86400): DateTimeImmutable
    {
        return (new DateTimeImmutable(self::SINCE))->modify("+{$segundosDesdeSince} seconds");
    }

    public function test_las_claves_de_by_state_son_los_estados_del_sello_del_motor_y_las_filas_sin_sello(): void
    {
        $estados = [M::APPLIED, M::LEGACY, M::OUT_OF_SCOPE, 'unstamped'];
        foreach ([M::CONTRACT_UNKNOWN, M::HASH_MISMATCH, M::SIGNATURE_INVALID, M::MALFORMED, M::TABLE_MISSING, M::READ_FAILED] as $motivo) {
            $estados[] = M::FALLBACK.'/'.$motivo;
        }
        sort($estados, SORT_STRING);

        $this->assertSame($estados, E::BY_STATE);
    }

    public function test_las_horas_son_enteras_contadas_al_segundo_y_nunca_negativas(): void
    {
        $this->assertSame(23, E::hours(self::SINCE, self::now(86399)));
        $this->assertSame(24, E::hours(self::SINCE, self::now(86400)));
        $this->assertSame(23, E::hours(self::SINCE, new DateTimeImmutable('2026-11-04T15:04:04.999999Z')));
        $this->assertSame(0, E::hours(self::SINCE, self::now(0)));
        $this->assertSame(0, E::hours(self::SINCE, (new DateTimeImmutable(self::SINCE))->modify('-1 second')));
        $this->assertSame(0, E::hours(self::SINCE, (new DateTimeImmutable(self::SINCE))->modify('-10 hours')));

        // El mismo instante escrito en la zona de Bogotá.
        $this->assertSame(24, E::hours(new DateTimeImmutable('2026-11-03 10:04:05', new DateTimeZone('America/Bogota')), self::now(86400)));
    }

    public function test_la_huella_no_depende_del_reloj_ni_de_como_llega_since(): void
    {
        $base = E::evaluate(self::measures(), self::THRESHOLDS, self::now(86400));

        $bogota = new DateTimeImmutable('2026-11-03 10:04:05.75', new DateTimeZone('America/Bogota'));
        $otra = E::evaluate(self::measures(['since' => $bogota]), self::THRESHOLDS, self::now(5 * 86400));

        $this->assertSame($base['evidenceHash'], $otra['evidenceHash']);
        $this->assertSame(self::SINCE, E::evidence(self::measures(['since' => $bogota]), self::THRESHOLDS)['since']);
        $this->assertSame([24, 120], [$base['hours'], $otra['hours']]);
    }

    public function test_la_huella_cambia_con_cada_recuento_y_con_cada_umbral(): void
    {
        $h = E::evaluate(self::measures(), self::THRESHOLDS, self::now())['evidenceHash'];
        $acta = array_fill_keys(self::ACTA, 0);

        $variantes = [
            'otro since' => [self::measures(['since' => '2026-11-03T15:04:06Z']), self::THRESHOLDS],
            'un turno más' => [self::measures(['turns' => 4, 'stamped' => 4, 'byState' => ['applied' => 4] + array_fill_keys(E::BY_STATE, 0)]), self::THRESHOLDS],
            'un sello menos' => [self::measures(['stamped' => 2]), self::THRESHOLDS],
            'otro reparto por estado' => [self::measures(['stamped' => 2, 'byState' => ['applied' => 2, 'legacy' => 1] + array_fill_keys(E::BY_STATE, 0)]), self::THRESHOLDS],
            'un freno' => [self::measures(['brakes' => ['count' => 1, 'engaged' => false]]), self::THRESHOLDS],
            'el freno echado' => [self::measures(['brakes' => ['count' => 0, 'engaged' => true]]), self::THRESHOLDS],
            'otra salida del acta' => [self::measures(['acta' => ['exitCode' => 1, 'total' => 0, 'counts' => $acta]]), self::THRESHOLDS],
            'un hallazgo del acta' => [self::measures(['acta' => ['exitCode' => 1, 'total' => 1, 'counts' => ['datos_personales' => 1] + $acta]]), self::THRESHOLDS],
            'un acta sin JSON' => [self::measures(['acta' => ['exitCode' => 1, 'total' => null, 'counts' => null]]), self::THRESHOLDS],
            'una recuperación' => [self::measures(['recoveries' => 1]), self::THRESHOLDS],
            'una deriva' => [self::measures(['drift' => [['param' => 'governance.enabled', 'declared' => true, 'engine' => null]]]), self::THRESHOLDS],
            'la firma mala' => [self::measures(['signatureOk' => false]), self::THRESHOLDS],
            'otro umbral de horas' => [self::measures(), ['minHours' => 25, 'minTurns' => 3]],
            'otro umbral de turnos' => [self::measures(), ['minHours' => 24, 'minTurns' => 2]],
        ];

        $huellas = [];
        foreach ($variantes as $que => [$medidas, $umbrales]) {
            $huellas[$que] = E::evaluate($medidas, $umbrales, self::now())['evidenceHash'];
            $this->assertNotSame($h, $huellas[$que], "La huella no cambió con {$que}.");
        }
        $this->assertCount(count($variantes), array_unique($huellas));
    }

    public function test_el_objeto_de_la_huella_sale_normalizado(): void
    {
        $desordenado = array_reverse(['applied' => 3] + array_fill_keys(E::BY_STATE, 0), true);
        $acta = array_reverse(array_fill_keys(self::ACTA, 0), true);

        $e = E::evidence(self::measures(['byState' => $desordenado, 'acta' => ['counts' => $acta, 'total' => 0, 'exitCode' => 0]]), ['minTurns' => 3, 'minHours' => 24]);

        $this->assertSame(['since', 'turns', 'stamped', 'byState', 'brakes', 'acta', 'recoveries', 'drift', 'signatureOk', 'thresholds'], array_keys($e));
        $this->assertSame(E::BY_STATE, array_keys($e['byState']));
        $nombres = self::ACTA;
        sort($nombres, SORT_STRING);
        $this->assertSame($nombres, array_keys($e['acta']['counts']));
        $this->assertSame(['exitCode', 'total', 'counts'], array_keys($e['acta']));
        $this->assertSame(['minHours' => 24, 'minTurns' => 3], $e['thresholds']);
    }

    public function test_un_acta_que_sale_con_error_no_pasa_aunque_no_cuente_hallazgos(): void
    {
        $r = E::evaluate(self::measures(['acta' => ['exitCode' => 1, 'total' => 0, 'counts' => array_fill_keys(self::ACTA, 0)]]), self::THRESHOLDS, self::now());

        $this->assertSame(['acta'], $r['failing']);
        $this->assertFalse($r['passes']);
    }

    public function test_las_recuperaciones_y_el_reparto_por_estado_no_deciden(): void
    {
        $r = E::evaluate(self::measures(['recoveries' => 5]), self::THRESHOLDS, self::now());

        $this->assertTrue($r['passes']);
        $this->assertSame([], $r['failing']);
    }

    /** @return iterable<string,array{array<string,mixed>}> */
    public static function evidenciasSinForma(): iterable
    {
        $estados = static fn (array $cambios): array => $cambios + array_fill_keys(E::BY_STATE, 0);
        $acta = array_fill_keys(self::ACTA, 0);
        $falta = self::measures();
        unset($falta['recoveries']);

        yield 'falta una medida' => [$falta];
        yield 'sobra una medida (las horas no se cuentan: salen del reloj)' => [self::measures(['hours' => 24])];
        yield 'turnos negativos' => [self::measures(['turns' => -1])];
        yield 'sellados como texto' => [self::measures(['stamped' => '3'])];
        yield 'byState sin unstamped' => [self::measures(['byState' => array_diff_key($estados(['applied' => 3]), ['unstamped' => true])])];
        yield 'byState con un estado que no existe' => [self::measures(['byState' => $estados(['applied' => 3, 'paused' => 0])])];
        // Las dos con stamped ≤ applied: solo las tumba la suma.
        yield 'byState que reparte menos turnos de los que hay' => [self::measures(['stamped' => 2, 'byState' => $estados(['applied' => 2])])];
        yield 'byState que reparte más turnos de los que hay' => [self::measures(['byState' => $estados(['applied' => 3, 'legacy' => 1])])];
        yield 'más sellados que aplicados' => [self::measures(['byState' => $estados(['applied' => 2, 'unstamped' => 1])])];
        yield 'el freno echado como 0' => [self::measures(['brakes' => ['count' => 0, 'engaged' => 0]])];
        yield 'los frenos sin engaged' => [self::measures(['brakes' => ['count' => 0]])];
        yield 'un acta sin JSON a medias' => [self::measures(['acta' => ['exitCode' => 1, 'total' => null, 'counts' => $acta]])];
        yield 'un acta cuyo total no es la suma' => [self::measures(['acta' => ['exitCode' => 1, 'total' => 2, 'counts' => ['datos_personales' => 1] + $acta]])];
        yield 'los recuentos del acta como lista' => [self::measures(['acta' => ['exitCode' => 0, 'total' => 0, 'counts' => [0, 0]]])];
        yield 'los recuentos del acta vacíos' => [self::measures(['acta' => ['exitCode' => 0, 'total' => 0, 'counts' => []]])];
        yield 'la salida del acta como texto' => [self::measures(['acta' => ['exitCode' => '0', 'total' => 0, 'counts' => $acta]])];
        yield 'la deriva como objeto' => [self::measures(['drift' => ['param' => 'governance.enabled', 'declared' => true, 'engine' => false]])];
        yield 'una deriva de un parámetro que no es must_match' => [self::measures(['drift' => [['param' => 'persona.tone', 'declared' => 'cercano', 'engine' => 'profesional']]])];
        yield 'una deriva con una lectura numérica' => [self::measures(['drift' => [['param' => 'governance.enabled', 'declared' => true, 'engine' => 1]]])];
        yield 'una deriva sin engine' => [self::measures(['drift' => [['param' => 'governance.enabled', 'declared' => true]]])];
        yield 'signatureOk como 1' => [self::measures(['signatureOk' => 1])];
        yield 'since con desfase' => [self::measures(['since' => '2026-11-03T10:04:05-05:00'])];
        yield 'since con fracciones' => [self::measures(['since' => '2026-11-03T15:04:05.5Z'])];
        yield 'since imposible' => [self::measures(['since' => '2026-02-30T15:04:05Z'])];
        yield 'since como entero' => [self::measures(['since' => 1793718245])];
    }

    #[DataProvider('evidenciasSinForma')]
    public function test_una_evidencia_sin_forma_o_incoherente_no_se_evalua(array $medidas): void
    {
        $this->expectException(InvalidArgumentException::class);

        E::evaluate($medidas, self::THRESHOLDS, self::now());
    }

    /** @return iterable<string,array{array<string,mixed>}> */
    public static function umbralesFueraDeA13(): iterable
    {
        yield 'minHours 0' => [['minHours' => 0, 'minTurns' => 3]];
        yield 'minHours 169' => [['minHours' => 169, 'minTurns' => 3]];
        yield 'minHours como texto' => [['minHours' => '24', 'minTurns' => 3]];
        yield 'minTurns 0' => [['minHours' => 24, 'minTurns' => 0]];
        yield 'minTurns 101' => [['minHours' => 24, 'minTurns' => 101]];
        yield 'minTurns null' => [['minHours' => 24, 'minTurns' => null]];
        yield 'sin minTurns' => [['minHours' => 24]];
        yield 'con una clave de más' => [['minHours' => 24, 'minTurns' => 3, 'window' => '07:00-19:00']];
    }

    #[DataProvider('umbralesFueraDeA13')]
    public function test_los_umbrales_fuera_de_los_topes_de_a13_no_se_aceptan(array $umbrales): void
    {
        $this->expectException(InvalidArgumentException::class);

        E::evaluate(self::measures(), $umbrales, self::now());
    }

    public function test_los_topes_de_los_umbrales_se_aceptan(): void
    {
        foreach ([[1, 1], [168, 100]] as [$horas, $turnos]) {
            $e = E::evidence(self::measures(), ['minHours' => $horas, 'minTurns' => $turnos]);
            $this->assertSame(['minHours' => $horas, 'minTurns' => $turnos], $e['thresholds']);
        }
    }

    public function test_las_dos_puertas_de_la_evidencia_al_promover_en_su_orden(): void
    {
        $vieja = str_repeat('e1', 32);
        $actual = str_repeat('1e', 32);

        $this->assertSame(['code' => 'canary_evidence_insufficient', 'payload' => null], E::refusal(['passes' => false, 'evidenceHash' => $actual], $vieja));
        $this->assertSame(['code' => 'canary_evidence_insufficient', 'payload' => null], E::refusal(['passes' => false, 'evidenceHash' => $actual], $actual));
        $this->assertSame(['code' => 'evidence_changed', 'payload' => null], E::refusal(['passes' => true, 'evidenceHash' => $actual], $vieja));
        $this->assertNull(E::refusal(['passes' => true, 'evidenceHash' => $actual], $actual));

        // Con la evaluación real.
        $r = E::evaluate(self::measures(), self::THRESHOLDS, self::now());
        $this->assertNull(E::refusal($r, $r['evidenceHash']));
        $this->assertSame('evidence_changed', E::refusal($r, strtoupper($r['evidenceHash']))['code']);
    }

    public function test_una_evaluacion_sin_forma_no_decide_la_puerta(): void
    {
        $this->expectException(InvalidArgumentException::class);

        E::refusal(['passes' => 'yes', 'evidenceHash' => str_repeat('1e', 32)], str_repeat('1e', 32));
    }
}
