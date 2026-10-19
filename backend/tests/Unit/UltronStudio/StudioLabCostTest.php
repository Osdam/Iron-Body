<?php

namespace Tests\Unit\UltronStudio;

use App\Services\UltronStudio\StudioCatalog;
use App\Services\UltronStudio\StudioLabCatalog;
use App\Services\UltronStudio\StudioLabCost as C;
use App\Services\UltronStudio\StudioLabModelClient as M;
use App\Services\UltronStudio\StudioLabPrompts as P;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * El coste en micro-US$ con enteros (contrato H4 §A3): precio por millón,
 * `ceil(T × P / 10⁶)` por cada par, 3 bytes por token más 50 por llamada,
 * máximo con toda la salida y esperado con la mitad, real con el `usage`, y
 * lo que se carga cuando una llamada falla (tabla de A5).
 */
class StudioLabCostTest extends TestCase
{
    private const PRECIOS = ['input' => 400_000, 'output' => 1_600_000];

    /** @return iterable<string,array{mixed,?int}> */
    public static function importes(): iterable
    {
        yield '0,40' => ['0.40', 400_000];
        yield '0,4' => ['0.4', 400_000];
        yield '2' => ['2', 2_000_000];
        yield 'entero' => [2, 2_000_000];
        yield '0,50' => ['0.50', 500_000];
        yield 'seis decimales' => ['0.000001', 1];
        yield 'cero' => ['0', 0];
        yield 'con espacios' => [' 1.25 ', 1_250_000];
        yield 'ceros delante' => ['007.5', 7_500_000];
        yield 'siete decimales' => ['0.0000001', null];
        yield 'coma decimal' => ['0,40', null];
        yield 'negativo' => ['-1', null];
        yield 'notación científica' => ['1e3', null];
        yield 'vacío' => ['', null];
        yield 'null' => [null, null];
        yield 'float' => [0.4, null];
        yield 'demasiado grande' => ['1000000', null];
        yield 'el mayor admitido' => ['999999.999999', 999_999_999_999];
    }

    #[DataProvider('importes')]
    public function test_un_importe_en_dolares_pasa_a_micros_sin_floats(mixed $usd, ?int $micros): void
    {
        $this->assertSame($micros, C::microsFromUsd($usd));
    }

    public function test_el_coste_de_t_tokens_es_el_techo_de_t_por_p_entre_un_millon(): void
    {
        $this->assertSame(0, C::tokensMicros(0, 400_000));
        $this->assertSame(1, C::tokensMicros(1, 400_000), 'ceil(0,4) = 1');
        $this->assertSame(400, C::tokensMicros(1000, 400_000));
        $this->assertSame(401, C::tokensMicros(1001, 400_000), 'ceil(400,4) = 401');
        $this->assertSame(400_000, C::tokensMicros(1_000_000, 400_000));
        $this->assertSame(1_600_000 * 3 + 2, C::tokensMicros(3_000_001, 1_600_000), 'ceil(4 800 001,6)');
        $this->assertSame(0, C::tokensMicros(-5, 400_000));
        // Sin desbordar con el precio más alto admitido y un usage absurdo.
        $this->assertSame(999_999_999_999 * 9_000, C::tokensMicros(9_000_000_000, 999_999_999_999));
    }

    public function test_los_tokens_de_entrada_se_estiman_con_tres_bytes_y_cincuenta_de_mas(): void
    {
        $this->assertSame(3, C::BYTES_PER_TOKEN);
        $this->assertSame(50, C::CALL_OVERHEAD_TOKENS);
        $this->assertSame(4, C::REPLY_BYTES_PER_TOKEN);
        $this->assertSame(50, C::estimatedInputTokens(0));
        $this->assertSame(51, C::estimatedInputTokens(1));
        $this->assertSame(51, C::estimatedInputTokens(3));
        $this->assertSame(52, C::estimatedInputTokens(4));
        $this->assertSame(1050, C::estimatedInputTokens(3000));

        // Bytes UTF-8 de instrucciones, entrada y esquema codificado.
        $esquema = ['type' => 'object'];
        $this->assertSame(strlen('ñ') + strlen('{"a":"é"}') + strlen('{"type":"object"}'), C::callBytes('ñ', '{"a":"é"}', $esquema));
    }

    public function test_maximo_con_toda_la_salida_y_esperado_con_la_mitad(): void
    {
        // 1000 tokens de entrada (400) y 400 de salida: máximo 640, esperado 320.
        $this->assertSame(['expectedMicros' => 400 + 320, 'maxMicros' => 400 + 640], C::callEstimate(1000, 400, self::PRECIOS));
        // Una salida impar: la mitad exacta, redondeada hacia arriba en micros.
        $this->assertSame(['expectedMicros' => 1 + 1, 'maxMicros' => 1 + 2], C::callEstimate(1, 1, self::PRECIOS));

        $caso = C::caseEstimate(1000, 400, 2000, 900, self::PRECIOS);
        $this->assertSame(400 + 640 + 800 + 1440, $caso['maxMicros']);
        $this->assertSame(400 + 320 + 800 + 720, $caso['expectedMicros']);
        $this->assertSame(['expectedMicros' => 2 * $caso['expectedMicros'], 'maxMicros' => 2 * $caso['maxMicros']], C::sum([$caso, $caso]));
        $this->assertSame(['expectedMicros' => 0, 'maxMicros' => 0], C::sum([]));

        // En `fake` los precios son 0 y no cuesta nada.
        $this->assertSame(['expectedMicros' => 0, 'maxMicros' => 0], C::caseEstimate(5000, 400, 5000, 900, ['input' => 0, 'output' => 0]));
    }

    public function test_el_coste_real_sale_del_usage_y_lo_que_supera_la_reserva_es_desviacion(): void
    {
        $this->assertSame(400 + 320, C::actualMicros(['inputTokens' => 1000, 'outputTokens' => 200], self::PRECIOS));
        $this->assertSame(0, C::overrunMicros(700, 1000));
        $this->assertSame(0, C::overrunMicros(1000, 1000));
        $this->assertSame(250, C::overrunMicros(1250, 1000));
    }

    public function test_la_estimacion_de_un_caso_cuenta_bytes_utf8_de_los_prompts_reales(): void
    {
        $objetivo = ['configs' => StudioCatalog::baseline()['configs'], 'rules' => P::selectRules(StudioCatalog::legacyRules())['included']];
        $escenario = StudioLabCatalog::byCode('C-01');
        $escenario['turns'][0]['text'] = 'Hola, ¿cuánto vale la mañana? 💪🏽 ñññ';

        $e = C::estimateScenario($objetivo, $escenario, 400, 900, self::PRECIOS);

        $bytesActor = strlen(P::ACTOR_INSTRUCTIONS) + strlen(P::actorInput($objetivo, $escenario)) + strlen((string) json_encode(P::actorSchema(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->assertSame(intdiv($bytesActor + 2, 3) + 50, $e['actorInputTokens']);
        $caracteres = mb_strlen(P::ACTOR_INSTRUCTIONS) + mb_strlen(P::actorInput($objetivo, $escenario)) + mb_strlen((string) json_encode(P::actorSchema()));
        $this->assertGreaterThan($caracteres, $bytesActor, 'con tildes y emojis, los bytes superan a los caracteres: se cuentan bytes');

        $bytesJuez = strlen(P::JUDGE_INSTRUCTIONS) + strlen(P::judgeEstimateInput($objetivo, $escenario)) + strlen((string) json_encode(P::judgeSchema(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->assertSame(intdiv($bytesJuez + 400 * 4 + 2, 3) + 50, $e['judgeInputTokens'], 'la respuesta del actor cuenta con su máximo, a 4 bytes por token');

        $this->assertSame(C::caseEstimate($e['actorInputTokens'], 400, $e['judgeInputTokens'], 900, self::PRECIOS), ['expectedMicros' => $e['expectedMicros'], 'maxMicros' => $e['maxMicros']]);
        $this->assertGreaterThan(0, $e['maxMicros']);
        $this->assertSame(0, C::estimateScenario($objetivo, $escenario, 400, 900, ['input' => 0, 'output' => 0])['maxMicros'], 'fake: 0');
    }

    /** @return iterable<string,array{?string,?array,int}> */
    public static function cargos(): iterable
    {
        $usage = ['inputTokens' => 1000, 'outputTokens' => 200];
        yield 'bien: el usage' => [null, $usage, 720];
        yield 'bien sin usage: el máximo' => [null, null, 5000];
        yield 'timeout: el máximo' => [M::CLASS_TIMEOUT, null, 5000];
        yield 'timeout aunque traiga usage: el máximo' => [M::CLASS_TIMEOUT, $usage, 5000];
        yield 'sin conexión: 0' => [M::CLASS_CONNECTION, null, 0];
        yield 'auth: 0' => [M::CLASS_AUTH, null, 0];
        yield 'cuota: 0' => [M::CLASS_QUOTA, null, 0];
        yield 'ritmo: 0' => [M::CLASS_RATE_LIMITED, null, 0];
        yield 'rechazo: 0' => [M::CLASS_BAD_REQUEST, null, 0];
        yield '5xx: 0' => [M::CLASS_SERVER, null, 0];
        yield 'negativa: el usage' => [M::CLASS_REFUSAL, $usage, 720];
        yield 'incompleta: el usage' => [M::CLASS_INCOMPLETE, $usage, 720];
        yield 'salida no válida: el usage' => [M::CLASS_INVALID_OUTPUT, $usage, 720];
        yield 'salida no válida sin usage: el máximo' => [M::CLASS_INVALID_OUTPUT, null, 5000];
        yield 'sin configurar: 0' => [M::CLASS_NOT_CONFIGURED, null, 0];
        yield 'clase desconocida: el máximo' => ['otra', null, 5000];
    }

    #[DataProvider('cargos')]
    public function test_lo_que_se_carga_por_una_llamada_sigue_la_tabla_de_a5(?string $clase, ?array $usage, int $esperado): void
    {
        $this->assertSame($esperado, C::callMicros($clase, $usage, 5000, self::PRECIOS));
    }
}
