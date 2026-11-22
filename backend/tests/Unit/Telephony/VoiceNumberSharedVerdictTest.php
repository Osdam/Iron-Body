<?php

namespace Tests\Unit\Telephony;

use App\Services\Telephony\VoiceNumberGuard;
use PHPUnit\Framework\TestCase;

/**
 * ENMIENDA DEL 22-11-2026, POR DECISIÓN EXPRESA DEL PROPIETARIO (protección 6
 * y D8-5 del contrato H8; texto literal en `MATRIZ_CIERRE_V2.md` §9): la tabla
 * PURA de {@see VoiceNumberGuard::sharedVerdict()}. El número empresarial se
 * admite como origen de las salientes si y solo si se cumplen las siete
 * condiciones a la vez (la 2, sobre el número configurado y, desde la ronda 2,
 * también sobre el observado: ocho hechos), cada una con su valor EXACTO; con cualquiera sin
 * cumplir, el primer motivo que falla en el orden cerrado de
 * {@see VoiceNumberGuard::SHARED_REASONS}.
 *
 * Cada condición se prueba SOLA (las otras seis en regla), con todos los
 * valores que no la cumplen (también null, un hecho que falta y las formas
 * parecidas de texto o número): es lo que caza una mutación que la quite o la
 * afloje. Y las 256 combinaciones de «en regla / no» fijan el orden.
 */
class VoiceNumberSharedVerdictTest extends TestCase
{
    /** Los ocho hechos en regla. */
    private const EN_REGLA = [
        'declared' => true,
        'businessNumber' => true,
        'inboundCapableDeclared' => false,
        'inboundEnabled' => false,
        'observationVerified' => true,
        'observedBusinessNumber' => true,
        'observedInboundCapable' => false,
        'observedAgentAssigned' => false,
    ];

    /** Cada hecho, el motivo que da si no se cumple y los valores que NO lo cumplen (`'falta'` = la clave no está). */
    private const NO_CUMPLEN = [
        'declared' => ['not_declared', [false, null, 'falta', 'true', 1]],
        'businessNumber' => ['not_business_number', [false, null, 'falta', 'true', 1]],
        // Sin declarar (null) o `true`: la declaración «solo saliente» tiene que ser explícita.
        'inboundCapableDeclared' => ['inbound_capable_declared', [true, null, 'falta', 'false', 0]],
        'inboundEnabled' => ['inbound_enabled', [true, null, 'falta', 'false', 0]],
        'observationVerified' => ['observation_unverified', [false, null, 'falta', 'verified', 1]],
        // Ronda 2: el número que vio la conciliación es el visible; una caché de antes de este hecho (falta) no vale.
        'observedBusinessNumber' => ['observed_not_business_number', [false, null, 'falta', 'true', 1]],
        // Corrección (ii) del diseño: `supports_inbound` ausente o null NO vale, solo un `false` del proveedor.
        'observedInboundCapable' => ['observed_inbound', [true, null, 'falta', 'false', 0]],
        'observedAgentAssigned' => ['observed_agent', [true, null, 'falta', 'false', 0]],
    ];

    public function test_los_motivos_son_un_conjunto_cerrado_y_ordenado(): void
    {
        $this->assertSame(['not_declared', 'not_business_number', 'inbound_capable_declared', 'inbound_enabled', 'observation_unverified',
            'observed_not_business_number', 'observed_inbound', 'observed_agent'], VoiceNumberGuard::SHARED_REASONS);
        $this->assertSame(array_column(self::NO_CUMPLEN, 0), VoiceNumberGuard::SHARED_REASONS, 'la tabla de abajo sigue el mismo orden');
        $this->assertSame(array_keys(self::EN_REGLA), array_keys(self::NO_CUMPLEN));
    }

    public function test_con_los_ocho_hechos_en_regla_se_admite(): void
    {
        $this->assertSame(['admitted' => true, 'reason' => null], VoiceNumberGuard::sharedVerdict(self::EN_REGLA));
        // Un hecho de más no cambia nada (la función lee solo los ocho).
        $this->assertSame(['admitted' => true, 'reason' => null], VoiceNumberGuard::sharedVerdict(self::EN_REGLA + ['otro' => 'x']));
    }

    public function test_cada_condicion_sola_y_con_cada_valor_que_no_la_cumple_niega_con_su_motivo(): void
    {
        $casos = 0;
        foreach (self::NO_CUMPLEN as $hecho => [$motivo, $valores]) {
            foreach ($valores as $valor) {
                $f = self::EN_REGLA;
                if ($valor === 'falta') {
                    unset($f[$hecho]);
                } else {
                    $f[$hecho] = $valor;
                }
                $this->assertSame(['admitted' => false, 'reason' => $motivo], VoiceNumberGuard::sharedVerdict($f), $hecho.' = '.var_export($valor, true));
                $casos++;
            }
        }
        $this->assertSame(40, $casos);
    }

    public function test_sin_ningun_hecho_no_se_admite(): void
    {
        $this->assertSame(['admitted' => false, 'reason' => 'not_declared'], VoiceNumberGuard::sharedVerdict([]));
    }

    /** Las 2^8 combinaciones: se admite solo con los ocho; si no, manda la primera que falla en el orden cerrado. */
    public function test_las_256_combinaciones_fijan_el_orden(): void
    {
        $hechos = array_keys(self::EN_REGLA);
        $admitidas = 0;
        for ($mascara = 0; $mascara < 2 ** count($hechos); $mascara++) {
            $f = self::EN_REGLA;
            $primera = null;
            foreach ($hechos as $i => $hecho) {
                if (($mascara >> $i) & 1) {
                    $f[$hecho] = self::NO_CUMPLEN[$hecho][1][0];
                    $primera ??= self::NO_CUMPLEN[$hecho][0];
                }
            }
            $veredicto = VoiceNumberGuard::sharedVerdict($f);
            $this->assertSame(['admitted' => $primera === null, 'reason' => $primera], $veredicto, 'máscara '.decbin($mascara));
            $admitidas += $veredicto['admitted'] ? 1 : 0;
        }
        $this->assertSame(1, $admitidas, 'una sola combinación de las 256 se admite');
    }
}
