<?php

namespace Tests\Unit\UltronStudio;

use App\Services\UltronStudio\StudioLabChecks;
use App\Services\UltronStudio\StudioLabCost;
use App\Services\UltronStudio\StudioLabEngineChecks;
use App\Services\UltronStudio\StudioLabOpenAiClient;
use App\Services\UltronStudio\StudioLabOutcome;
use App\Services\UltronStudio\StudioLabPersonalData;
use App\Services\UltronStudio\StudioLabPrompts;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * PARIDAD del núcleo del laboratorio (contrato H4 §A10): el PHP reproduce
 * EXACTAMENTE cada caso de `tests/fixtures/ultron-studio/lab-cases.json`.
 *
 * Ese fichero lo escribió el tester a mano desde el contrato (ver su README);
 * no lo genera ninguna implementación. Si un caso discrepa, se corrige el PHP;
 * el fixture solo cambia con una enmienda del contrato. Las cinco secciones:
 * agregación y propuesta, datos personales, coste, pruebas deterministas
 * (con la salida enlatada de cada guarda del motor) y fallos del proveedor
 * (con el cliente de OpenAI real contra `Http::fake`).
 */
class StudioLabParityTest extends TestCase
{
    private const SECCIONES = ['outcome', 'personalData', 'cost', 'checks', 'providerErrors'];

    private const CLAVE_LAB = 'sk-lab-paridad-0001';

    private static ?array $fixture = null;

    private static function fixture(): array
    {
        return self::$fixture ??= json_decode(
            (string) file_get_contents(dirname(__DIR__, 2).'/fixtures/ultron-studio/lab-cases.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    /** @return iterable<string,array{array<string,mixed>}> */
    public static function casos(): iterable
    {
        foreach (self::SECCIONES as $seccion) {
            foreach (self::fixture()[$seccion] as $caso) {
                yield $caso['name'] => [$caso + ['section' => $seccion]];
            }
        }
    }

    #[DataProvider('casos')]
    public function test_el_php_reproduce_el_caso_exactamente(array $caso): void
    {
        $obtenido = match ($caso['section']) {
            'outcome' => $this->outcome($caso),
            'personalData' => ['rejected' => ! StudioLabPersonalData::passes($caso['text'])],
            'cost' => $this->cost($caso),
            'checks' => $this->checks($caso),
            'providerErrors' => $this->providerError($caso),
        };

        $this->assertSame($caso['expected'], $obtenido, 'Discrepa del fixture escrito desde el contrato: se corrige el PHP, nunca expected.');
    }

    public function test_el_fixture_esta_bien_formado(): void
    {
        $f = self::fixture();

        $this->assertSame(1, $f['version']);
        $nombres = [];
        foreach (self::SECCIONES as $seccion) {
            $this->assertNotEmpty($f[$seccion], $seccion);
            foreach ($f[$seccion] as $caso) {
                $this->assertArrayHasKey('kind', $caso, $caso['name']);
                $this->assertArrayHasKey('expected', $caso, $caso['name']);
                $nombres[] = $caso['name'];
            }
        }
        $this->assertGreaterThanOrEqual(60, count($nombres), 'el contrato pide al menos 60 casos');
        $this->assertSame(count($nombres), count(array_unique($nombres)), 'nombres de caso repetidos');
    }

    /** @param  array<string,mixed>  $caso */
    private function outcome(array $caso): array
    {
        return match ($caso['kind']) {
            'outcome' => ['outcome' => StudioLabOutcome::forCase($caso['checks'], $caso['judge'], $caso['criteria'])],
            'proposal' => ['aiRecommendation' => StudioLabOutcome::proposal($caso['run']['status'], $caso['run']['mode'], $caso['cases'])],
        };
    }

    /** @param  array<string,mixed>  $caso */
    private function cost(array $caso): array
    {
        $precios = static fn (array $c): array => ['input' => $c['priceInMicrosPerMillion'], 'output' => $c['priceOutMicrosPerMillion']];

        switch ($caso['kind']) {
            case 'price':
                return ['microsPerMillion' => StudioLabCost::microsFromUsd($caso['usd'])];

            case 'tokenCost':
                return ['micros' => StudioLabCost::tokensMicros($caso['tokens'], $caso['microsPerMillion'])];

            case 'inputTokens':
                return ['tokens' => $caso['role'] === 'judge'
                    ? StudioLabCost::judgeInputTokens($caso['bytes'], $caso['actorMaxOutputTokens'])
                    : StudioLabCost::estimatedInputTokens($caso['bytes'])];

            case 'estimate':
                $reservas = [];
                $estimaciones = [];
                foreach ($caso['cases'] as $c) {
                    $e = StudioLabCost::caseEstimate(
                        StudioLabCost::estimatedInputTokens($c['actorInputBytes']),
                        $caso['actorMaxOutputTokens'],
                        StudioLabCost::judgeInputTokens($c['judgeInputBytes'], $caso['actorMaxOutputTokens']),
                        $caso['judgeMaxOutputTokens'],
                        $precios($caso),
                    );
                    $reservas[] = $e['maxMicros'];
                    $estimaciones[] = $e;
                }
                $total = StudioLabCost::sum($estimaciones);

                return ['caseReservedMicros' => $reservas, 'maxMicros' => $total['maxMicros'], 'expectedMicros' => $total['expectedMicros']];

            case 'settle':
                $porLlamada = [];
                foreach ($caso['calls'] as $llamada) {
                    $usage = StudioLabOpenAiClient::parseUsage($llamada['usage']);
                    $this->assertNotNull($usage, 'usage ilegible en el caso');
                    $porLlamada[] = StudioLabCost::callMicros(null, $usage, 0, $precios($caso));
                }
                $coste = array_sum($porLlamada);

                return ['callCostMicros' => $porLlamada, 'costMicros' => $coste, 'overrunMicros' => StudioLabCost::overrunMicros($coste, $caso['reservedMicros'])];
        }

        $this->fail("Tipo de coste desconocido: {$caso['kind']}");
    }

    /**
     * Las pruebas del Studio sobre `reply` y las del motor con la salida
     * enlatada de cada guarda (la tabla del README), proyectadas a
     * `{id, source, level}`.
     *
     * @param  array<string,mixed>  $caso
     * @return list<array{id:string,source:string,level:string}>
     */
    private function checks(array $caso): array
    {
        $objetivo = ['configs' => ['persona' => $caso['persona']], 'edgeIds' => [], 'rules' => $caso['rules']];
        $hechos = $caso['facts'];

        $enlatadas = [];
        foreach (StudioLabEngineChecks::CALLS as $llamada) {
            $this->assertArrayHasKey($llamada, $caso['engine'], "El caso no trae la salida de {$llamada}.");
            $valor = $caso['engine'][$llamada];
            $lanza = in_array($llamada, $caso['throws'], true);
            $enlatadas[$llamada] = function (string $reply, string $pago, bool $traspaso) use ($caso, $hechos, $llamada, $valor, $lanza): mixed {
                // El adaptador pasa a cada guarda los argumentos de §A4.
                $this->assertSame($caso['reply'], $reply);
                $this->assertSame($hechos['paymentState'], $pago);
                $this->assertSame($hechos['handoffRequested'], $traspaso);
                if ($lanza) {
                    throw new RuntimeException("La guarda {$llamada} falla en este caso.");
                }

                return self::realReturn($llamada, $valor);
            };
        }

        $hallazgos = [
            ...StudioLabChecks::check($caso['reply'], $objetivo),
            ...(new StudioLabEngineChecks($enlatadas))->check($caso['reply'], $hechos),
        ];

        return array_map(static fn (array $h): array => ['id' => $h['id'], 'source' => $h['source'], 'level' => $h['level']], $hallazgos);
    }

    /** El valor enlatado → lo que devuelve de verdad la guarda (tabla del README). */
    private static function realReturn(string $llamada, mixed $valor): mixed
    {
        return match ($llamada) {
            StudioLabEngineChecks::CALL_INSPECT => $valor === null
                ? ['safe' => true, 'code' => null, 'signal' => null, 'risk_flag' => null]
                : ['safe' => false, 'code' => $valor, 'signal' => null, 'risk_flag' => $valor],
            StudioLabEngineChecks::CALL_PAYMENT => $valor === null ? null : ['code' => 'machine_reply_payment_fact', 'reason' => $valor, 'detail' => 'enlatado'],
            StudioLabEngineChecks::CALL_MEMBERSHIP => $valor === null ? null : ['code' => 'machine_reply_membership_fact', 'reason' => $valor, 'detail' => 'enlatado'],
            StudioLabEngineChecks::CALL_PROMISE => ['kind' => $valor, 'quote' => null],
            StudioLabEngineChecks::CALL_COURTESY_CONFIRMATION, StudioLabEngineChecks::CALL_COURTESY_REGISTRATION => $valor,
            StudioLabEngineChecks::CALL_STYLE => $valor === null ? null : ['hard' => $valor['hard'], 'soft' => $valor['soft'], 'plans_mentioned' => 0],
        };
    }

    /**
     * Una llamada del cliente REAL de OpenAI contra `Http::fake`, leída como la
     * leerá el runner, y su efecto en la ejecución.
     *
     * @param  array<string,mixed>  $caso
     * @return array{class:?string,costMode:string,runEffect:string}
     */
    private function providerError(array $caso): array
    {
        config(['ultron_studio.lab' => [
            'enabled' => true,
            'driver' => 'openai',
            'openai' => ['api_key' => self::CLAVE_LAB, 'forbidden_key_sha256' => null, 'model' => 'modelo-del-lab', 'temperature' => null, 'reasoning_effort' => null, 'base_url' => null],
            'price_input_usd_per_mtok' => '0.40',
            'price_output_usd_per_mtok' => '1.60',
            'daily_budget_usd' => '2',
            'run_budget_usd' => '0.50',
            'max_runs_per_day' => 20,
            'max_cases_per_run' => 12,
            'actor_max_output_tokens' => 400,
            'judge_max_output_tokens' => 900,
            'timeout_seconds' => 60,
        ]]);

        $respuesta = $caso['response'];
        Http::fake(['*' => match (true) {
            ($respuesta['transport'] ?? null) === 'timeout' => static fn (Request $r) => Create::rejectionFor(new ConnectException('cURL error 28: Operation timed out after 60000 milliseconds with 0 bytes received', $r->toPsrRequest(), null, ['errno' => 28])),
            ($respuesta['transport'] ?? null) === 'connection' => static fn (Request $r) => Create::rejectionFor(new ConnectException('cURL error 7: Failed to connect', $r->toPsrRequest(), null, ['errno' => 7])),
            is_array($respuesta['body'] ?? null) => Http::response($respuesta['body'], $respuesta['status'], ['Content-Type' => 'application/json']),
            default => Http::response((string) ($respuesta['body'] ?? ''), $respuesta['status'], ['Content-Type' => 'text/html']),
        }]);

        $papel = $caso['role'];
        $resultado = (new StudioLabOpenAiClient)->complete($papel, StudioLabPrompts::instructionsFor($papel), '{"caso":"paridad"}', StudioLabPrompts::schemaFor($papel), 400);
        Http::assertSentCount(1);

        $clase = $papel === StudioLabPrompts::ROLE_JUDGE
            ? StudioLabPrompts::readJudgeCall($resultado, $caso['judgeExpects']['criteria'], $caso['judgeExpects']['rules'])['class']
            : StudioLabPrompts::readActorCall($resultado)['class'];

        return [
            'class' => $clase,
            'costMode' => StudioLabOutcome::costMode($clase),
            'runEffect' => StudioLabOutcome::callEffect($clase, $caso['streakBefore']),
        ];
    }
}
