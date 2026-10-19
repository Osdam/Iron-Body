<?php

namespace Tests\Unit\UltronStudio;

use App\Services\UltronStudio\StudioLabModelClient as M;
use App\Services\UltronStudio\StudioLabOutcome as O;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * La agregación y la política de fallos (contrato H4 §A4, §A5 y §A1). La
 * matriz completa la fija `lab-cases.json` (StudioLabParityTest); aquí, lo que
 * ese fichero deja fuera a propósito: el juez con huecos, la racha más allá
 * de un error, las clases sin fila en la tabla y la tabla misma.
 */
class StudioLabOutcomeTest extends TestCase
{
    private const CRITERIOS = [['id' => 'c1', 'kind' => 'must'], ['id' => 'c2', 'kind' => 'should']];

    private static function juez(array $criterios, array $reglas = []): array
    {
        return [
            'criteria' => array_map(static fn (string $id, string $v): array => ['id' => $id, 'verdict' => $v, 'evidence' => '', 'reason' => ''], array_keys($criterios), $criterios),
            'rules' => array_map(static fn (string $c, string $v): array => ['code' => $c, 'verdict' => $v, 'reason' => ''], array_keys($reglas), $reglas),
            'risks' => [['level' => 'high', 'text' => 'riesgo']],
            'summary' => '',
        ];
    }

    public function test_un_criterio_sin_opinion_cuenta_como_unclear(): void
    {
        $this->assertSame(O::REVIEW, O::forCase([], self::juez(['c1' => 'pass']), self::CRITERIOS));
        $this->assertSame(O::PASS, O::forCase([], self::juez(['c1' => 'pass', 'c2' => 'pass']), self::CRITERIOS));
    }

    public function test_una_regla_que_no_es_r_ni_s_en_fail_se_revisa_y_no_tumba(): void
    {
        $this->assertSame(O::REVIEW, O::forCase([], self::juez(['c1' => 'pass', 'c2' => 'pass'], ['X-01' => 'fail']), self::CRITERIOS));
        $this->assertSame(O::FAIL, O::forCase([], self::juez(['c1' => 'pass', 'c2' => 'pass'], ['R-12' => 'fail']), self::CRITERIOS));
        $this->assertSame(O::REVIEW, O::forCase([], self::juez(['c1' => 'pass', 'c2' => 'pass'], ['S-004' => 'fail']), self::CRITERIOS));
    }

    public function test_sin_juez_como_mucho_review_y_un_bloqueante_siempre_gana(): void
    {
        $this->assertSame(O::REVIEW, O::forCase([], null, self::CRITERIOS));
        $this->assertSame(O::FAIL, O::forCase([['id' => 'lab-vacia', 'source' => 'studio', 'level' => 'blocking']], null, self::CRITERIOS));
        $this->assertSame(O::REVIEW, O::forCase([['id' => 'motor-no-disponible', 'source' => 'engine', 'level' => 'info']], null, self::CRITERIOS));
    }

    public function test_la_propuesta_solo_existe_en_completed_y_openai(): void
    {
        $bien = [['status' => 'done', 'outcome' => 'pass']];
        $this->assertSame(O::PROPOSAL_APPROVE, O::proposal('completed', 'openai', $bien));
        $this->assertNull(O::proposal('completed', 'fake', $bien), 'una ejecución de prueba no propone');
        $this->assertNull(O::proposal('stopped', 'openai', $bien));
        $this->assertNull(O::proposal('completed', null, $bien));
        $this->assertNull(O::proposal('completed', 'openai', []));
        $this->assertSame(O::PROPOSAL_REJECT, O::proposal('completed', 'openai', [['status' => 'skipped', 'outcome' => null], ['status' => 'done', 'outcome' => 'fail']]), 'un fail manda aunque venga después');
        $this->assertSame('Propuesta del laboratorio: no es un veredicto', O::PROPOSAL_LABEL);
    }

    public function test_la_tabla_de_a5_tal_cual(): void
    {
        $this->assertSame(M::CLASSES, array_keys(M::POLICY));
        $this->assertSame(M::CLASSES, array_keys(M::MESSAGES));
        $esperado = [
            'timeout' => ['reserve', 'continue'],
            'connection' => ['zero', 'continue'],
            'auth' => ['zero', 'stop:provider_auth'],
            'quota' => ['zero', 'stop:provider_quota'],
            'rate_limited' => ['zero', 'delay'],
            'bad_request' => ['zero', 'stop:provider_rejected'],
            'server' => ['zero', 'continue'],
            'refusal' => ['usage', 'continue'],
            'incomplete' => ['usage', 'continue'],
            'invalid_output' => ['usage', 'continue'],
            'not_configured' => ['zero', 'stop:not_configured'],
        ];
        foreach ($esperado as $clase => [$coste, $efecto]) {
            $this->assertSame($coste, O::costMode($clase), $clase);
            $this->assertSame($efecto, O::callEffect($clase, 0), $clase);
        }
        $this->assertSame('usage', O::costMode(null));
        $this->assertSame('continue', O::callEffect(null, 1));
        $this->assertSame(30, M::POLICY['rate_limited']['delaySeconds']);
        foreach (M::MESSAGES as $mensaje) {
            $this->assertDoesNotMatchRegularExpression('/[A-Za-z]+_[a-z]+/', $mensaje, 'mensajes en llano, sin códigos');
        }
    }

    public function test_la_racha_por_caso_de_la_enmienda_1(): void
    {
        // Decisión 2: cuentan timeout, connection, server, refusal, incomplete e invalid_output.
        foreach (['timeout', 'connection', 'server', 'refusal', 'incomplete', 'invalid_output'] as $clase) {
            $this->assertNotSame('stop:provider_errors', O::callEffect($clase, 0), $clase);
            $this->assertSame('stop:provider_errors', O::callEffect($clase, 1), $clase);
            $this->assertSame('stop:provider_errors', O::callEffect($clase, 5), $clase);
            $this->assertSame(1, O::nextStreak($clase, 0), $clase);
            $this->assertSame(2, O::nextStreak($clase, 1), $clase);
        }
        $this->assertSame(0, O::nextStreak(null, 1), 'un caso sin fallo reinicia la racha');
        $this->assertSame(1, O::nextStreak('rate_limited', 1), 'un 429 de ritmo no cuenta: la racha queda igual');
        $this->assertSame('delay', O::callEffect('rate_limited', 1), 'ni para aunque venga en racha');
        foreach (['auth' => 'provider_auth', 'quota' => 'provider_quota', 'bad_request' => 'provider_rejected'] as $clase => $motivo) {
            $this->assertSame("stop:{$motivo}", O::callEffect($clase, 0), $clase);
            $this->assertSame("stop:{$motivo}", O::callEffect($clase, 1), "{$clase}: la parada propia manda sobre la racha");
        }
        // Un modelo que devuelve `incomplete` en todos los casos para la ejecución en el segundo.
        $racha = 0;
        $efectos = [];
        foreach (['incomplete', 'incomplete', 'incomplete'] as $clase) {
            $efectos[] = O::callEffect($clase, $racha);
            $racha = O::nextStreak($clase, $racha);
        }
        $this->assertSame(['continue', 'stop:provider_errors', 'stop:provider_errors'], $efectos);
    }

    public function test_una_clase_desconocida_es_un_error_del_codigo(): void
    {
        $this->expectException(InvalidArgumentException::class);
        O::callEffect('otra', 0);
    }
}
