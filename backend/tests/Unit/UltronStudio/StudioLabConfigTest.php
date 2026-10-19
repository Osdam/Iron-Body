<?php

namespace Tests\Unit\UltronStudio;

use App\Services\UltronStudio\StudioLabConfig as Cfg;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * La configuración del laboratorio (contrato H4 §A1).
 *
 * LO QUE SE PROTEGE. Los cuatro cerrojos antes de gastar (bandera apagada,
 * driver `fake`, presupuesto diario 0 y clave ausente o compartida); que la
 * clave compartida se detecte por HUELLA sin que esta clase lea la
 * configuración de nadie más; que ningún valor, array ni volcado del objeto
 * contenga una clave; los defectos y los topes duros, también en el fichero
 * de configuración; y las constantes fijas del contrato.
 */
class StudioLabConfigTest extends TestCase
{
    private const CLAVE_LAB = 'sk-lab-centinela-0001';

    private const CLAVE_MOTOR = 'sk-motor-centinela-0002';

    /** Un bloque `lab` listo para `openai`, con la huella de la clave del motor. */
    private static function openai(array $cambios = []): array
    {
        $base = [
            'enabled' => true,
            'driver' => 'openai',
            'openai' => [
                'api_key' => self::CLAVE_LAB,
                'forbidden_key_sha256' => hash('sha256', self::CLAVE_MOTOR),
                'model' => 'gpt-4.1-mini',
                'temperature' => null,
                'reasoning_effort' => null,
                'base_url' => null,
            ],
            'price_input_usd_per_mtok' => '0.40',
            'price_output_usd_per_mtok' => '1.60',
            'daily_budget_usd' => '2',
            'run_budget_usd' => '0.50',
            'max_runs_per_day' => 20,
            'max_cases_per_run' => 12,
            'actor_max_output_tokens' => 400,
            'judge_max_output_tokens' => 900,
            'timeout_seconds' => 60,
        ];

        return array_replace_recursive($base, $cambios);
    }

    public function test_current_lee_el_bloque_lab_de_la_configuracion(): void
    {
        config(['ultron_studio.lab' => self::openai()]);
        $this->assertSame('openai', Cfg::current()->mode());
        $this->assertTrue(Cfg::current()->configured());

        config(['ultron_studio.lab' => null]);
        $this->assertSame('fake', Cfg::current()->mode(), 'sin bloque, los defectos');
        $this->assertFalse(Cfg::current()->enabled);
    }

    public function test_por_defecto_esta_apagado_en_fake_y_sin_motivos(): void
    {
        // El fichero de configuración tal cual, sin ninguna variable del laboratorio.
        $c = Cfg::fromArray($this->loadConfigWith([])['lab']);

        $this->assertFalse($c->enabled, 'la bandera nace apagada');
        $this->assertSame('fake', $c->mode());
        $this->assertTrue($c->configured());
        $this->assertSame([], $c->reasons());
        $this->assertSame(0, $c->dailyBudgetMicros, 'presupuesto diario 0 por defecto');
        $this->assertSame(500_000, $c->runBudgetMicros);
        $this->assertSame(20, $c->maxRunsPerDay);
        $this->assertSame(12, $c->maxCasesPerRun);
        $this->assertSame(400, $c->actorMaxOutputTokens);
        $this->assertSame(900, $c->judgeMaxOutputTokens);
        $this->assertSame(60, $c->timeoutSeconds);
        $this->assertSame(['input' => 0, 'output' => 0], $c->prices(), 'el modo de prueba cuesta 0');
        // Lo que le faltaría para pasar a OpenAI, sin pasar.
        $this->assertContains(Cfg::REASON_MISSING_KEY, $c->openAiReasons());
        $this->assertContains(Cfg::REASON_ZERO_BUDGET, $c->openAiReasons());
    }

    public function test_openai_completo_esta_configurado(): void
    {
        $c = Cfg::fromArray(self::openai());

        $this->assertSame('openai', $c->mode());
        $this->assertTrue($c->configured());
        $this->assertSame([], $c->reasons());
        $this->assertSame(['input' => 400_000, 'output' => 1_600_000], $c->prices());
        $this->assertSame(2_000_000, $c->dailyBudgetMicros);
        $this->assertSame('gpt-4.1-mini', $c->model);
    }

    /** @return iterable<string,array{array<string,mixed>,list<string>}> */
    public static function motivos(): iterable
    {
        yield 'sin clave' => [['openai' => ['api_key' => '']], [Cfg::REASON_MISSING_KEY]];
        yield 'clave en blanco' => [['openai' => ['api_key' => '   ']], [Cfg::REASON_MISSING_KEY]];
        yield 'clave compartida' => [['openai' => ['api_key' => self::CLAVE_MOTOR]], [Cfg::REASON_SHARED_KEY]];
        yield 'clave compartida con espacios' => [['openai' => ['api_key' => ' '.self::CLAVE_MOTOR.' ']], [Cfg::REASON_SHARED_KEY]];
        yield 'sin modelo' => [['openai' => ['model' => '']], [Cfg::REASON_MISSING_MODEL]];
        yield 'modelo basura' => [['openai' => ['model' => 'gpt 4 <script>']], [Cfg::REASON_MISSING_MODEL]];
        yield 'sin precio de entrada' => [['price_input_usd_per_mtok' => null], [Cfg::REASON_MISSING_PRICE]];
        yield 'precio con coma' => [['price_output_usd_per_mtok' => '1,60'], [Cfg::REASON_MISSING_PRICE]];
        yield 'precio con siete decimales' => [['price_output_usd_per_mtok' => '1.6000001'], [Cfg::REASON_MISSING_PRICE]];
        yield 'precio cero' => [['price_input_usd_per_mtok' => '0'], [Cfg::REASON_MISSING_PRICE]];
        yield 'presupuesto diario cero' => [['daily_budget_usd' => '0'], [Cfg::REASON_ZERO_BUDGET]];
        yield 'presupuesto diario ilegible' => [['daily_budget_usd' => 'dos'], [Cfg::REASON_ZERO_BUDGET]];
        yield 'tope por ejecución cero' => [['run_budget_usd' => '0'], [Cfg::REASON_ZERO_BUDGET]];
        yield 'todo a la vez, en orden' => [
            ['openai' => ['api_key' => null, 'model' => null], 'price_input_usd_per_mtok' => null, 'daily_budget_usd' => '0'],
            [Cfg::REASON_MISSING_KEY, Cfg::REASON_MISSING_MODEL, Cfg::REASON_MISSING_PRICE, Cfg::REASON_ZERO_BUDGET],
        ];
        yield 'driver desconocido: solo ese motivo' => [['driver' => 'opneai'], [Cfg::REASON_INVALID_DRIVER]];
    }

    #[DataProvider('motivos')]
    public function test_los_motivos_de_lab_not_configured(array $cambios, array $motivos): void
    {
        $c = Cfg::fromArray(self::openai($cambios));

        $this->assertSame($motivos, $c->reasons());
        $this->assertFalse($c->configured());
        foreach ($motivos as $m) {
            $this->assertContains($m, Cfg::REASONS);
        }
    }

    public function test_un_driver_desconocido_no_cae_en_fake_ni_en_openai(): void
    {
        $c = Cfg::fromArray(self::openai(['driver' => 'gpt']));
        $this->assertNull($c->mode());
        $this->assertFalse($c->isFake());
        $this->assertFalse($c->isOpenAi());
        $this->assertNull($c->toArray()['mode']);

        $this->assertSame('openai', Cfg::fromArray(self::openai(['driver' => ' OpenAI ']))->mode(), 'mayúsculas y espacios no cuentan');
    }

    public function test_en_fake_no_hay_motivos_aunque_falte_todo_lo_de_openai(): void
    {
        $c = Cfg::fromArray(['driver' => 'fake']);
        $this->assertTrue($c->configured());
        $this->assertSame([], $c->reasons());
        $this->assertNull($c->toArray()['model'], 'el modo de prueba no consulta ningún modelo');
    }

    public function test_la_bandera_solo_se_enciende_con_un_verdadero(): void
    {
        $this->assertTrue(Cfg::fromArray(['enabled' => true])->enabled);
        foreach ([1, '1', 'true', null, false] as $valor) {
            $this->assertFalse(Cfg::fromArray(['enabled' => $valor])->enabled, var_export($valor, true));
        }
    }

    public function test_los_topes_duros_se_aplican_aunque_la_configuracion_los_supere(): void
    {
        $c = Cfg::fromArray(self::openai([
            'max_cases_per_run' => 999, 'actor_max_output_tokens' => 99999, 'judge_max_output_tokens' => 99999, 'timeout_seconds' => 3600, 'max_runs_per_day' => -3,
        ]));
        $this->assertSame(20, $c->maxCasesPerRun);
        $this->assertSame(800, $c->actorMaxOutputTokens);
        $this->assertSame(1600, $c->judgeMaxOutputTokens);
        $this->assertSame(60, $c->timeoutSeconds);
        $this->assertSame(0, $c->maxRunsPerDay);

        $abajo = Cfg::fromArray(self::openai(['max_cases_per_run' => 0, 'actor_max_output_tokens' => 1, 'judge_max_output_tokens' => 0, 'timeout_seconds' => 0]));
        $this->assertSame(1, $abajo->maxCasesPerRun);
        $this->assertSame(16, $abajo->actorMaxOutputTokens, 'el mínimo de la API');
        $this->assertSame(16, $abajo->judgeMaxOutputTokens);
        $this->assertSame(10, $abajo->timeoutSeconds, 'nunca un timeout de 0, que es infinito');
    }

    public function test_temperatura_y_esfuerzo_solo_si_son_validos(): void
    {
        $this->assertNull(Cfg::fromArray(self::openai())->temperature);
        $this->assertSame(0.2, Cfg::fromArray(self::openai(['openai' => ['temperature' => '0.2']]))->temperature);
        $this->assertSame(2.0, Cfg::fromArray(self::openai(['openai' => ['temperature' => '2']]))->temperature);
        $this->assertNull(Cfg::fromArray(self::openai(['openai' => ['temperature' => '3']]))->temperature);
        $this->assertNull(Cfg::fromArray(self::openai(['openai' => ['temperature' => 'tibio']]))->temperature);
        $this->assertSame('low', Cfg::fromArray(self::openai(['openai' => ['reasoning_effort' => ' LOW ']]))->reasoningEffort);
        $this->assertNull(Cfg::fromArray(self::openai(['openai' => ['reasoning_effort' => 'muy alto']]))->reasoningEffort);
    }

    public function test_ningun_volcado_del_objeto_lleva_una_clave_ni_su_huella(): void
    {
        $c = Cfg::fromArray(self::openai());
        $volcados = [
            json_encode($c->toArray()),
            var_export($c, true),
            print_r($c, true),
            serialize($c),
            json_encode(get_object_vars($c)),
        ];
        foreach ($volcados as $v) {
            $this->assertStringNotContainsString(self::CLAVE_LAB, $v);
            $this->assertStringNotContainsString(self::CLAVE_MOTOR, $v);
            $this->assertStringNotContainsString(hash('sha256', self::CLAVE_MOTOR), $v);
            $this->assertStringNotContainsString(hash('sha256', self::CLAVE_LAB), $v);
        }
        $this->assertSame(
            ['enabled', 'mode', 'configured', 'reasons', 'model', 'prices', 'dailyBudgetMicros', 'runBudgetMicros', 'maxRunsPerDay', 'maxCasesPerRun', 'actorMaxOutputTokens', 'judgeMaxOutputTokens', 'timeoutSeconds', 'timezone'],
            array_keys($c->toArray()),
        );
    }

    public function test_el_fichero_de_configuracion_calcula_la_huella_de_la_otra_clave_y_aplica_defectos_y_topes(): void
    {
        $variables = [
            'OPENAI_API_KEY' => ' '.self::CLAVE_MOTOR.' ',
            'ULTRON_STUDIO_LAB_ENABLED' => 'true',
            'ULTRON_STUDIO_LAB_DRIVER' => ' OpenAI ',
            'ULTRON_STUDIO_LAB_MAX_CASES_PER_RUN' => '50',
            'ULTRON_STUDIO_LAB_ACTOR_MAX_OUTPUT_TOKENS' => '5000',
            'ULTRON_STUDIO_LAB_JUDGE_MAX_OUTPUT_TOKENS' => '',
            'ULTRON_STUDIO_LAB_TIMEOUT_SECONDS' => '0',
            'ULTRON_STUDIO_LAB_MAX_RUNS_PER_DAY' => 'muchas',
        ];
        $lab = $this->loadConfigWith($variables)['lab'];

        $this->assertTrue($lab['enabled']);
        $this->assertSame('openai', $lab['driver']);
        $this->assertSame(hash('sha256', self::CLAVE_MOTOR), $lab['openai']['forbidden_key_sha256'], 'la huella de la clave recortada');
        $this->assertSame(20, $lab['max_cases_per_run']);
        $this->assertSame(800, $lab['actor_max_output_tokens']);
        $this->assertSame(900, $lab['judge_max_output_tokens'], 'vacío → el defecto');
        $this->assertSame(10, $lab['timeout_seconds']);
        $this->assertSame(20, $lab['max_runs_per_day'], 'ilegible → el defecto');
        $this->assertSame('0', $lab['daily_budget_usd']);
        $this->assertSame('0.50', $lab['run_budget_usd']);

        $sinOtra = $this->loadConfigWith(['OPENAI_API_KEY' => ''])['lab'];
        $this->assertNull($sinOtra['openai']['forbidden_key_sha256']);
        $this->assertFalse($sinOtra['enabled']);
        $this->assertSame('fake', $sinOtra['driver']);
    }

    public function test_las_constantes_fijas_del_contrato(): void
    {
        $this->assertSame(30, Cfg::QUEUED_EXPIRY_MINUTES);
        $this->assertSame(30, Cfg::RUN_DEADLINE_MINUTES);
        $this->assertSame(1, Cfg::MAX_RUNNING_CASES);
        $this->assertSame(2, Cfg::MAX_CONSECUTIVE_PROVIDER_ERRORS);
        $this->assertSame('America/Bogota', Cfg::BUDGET_TIMEZONE);
        $this->assertSame(20, Cfg::HARD_MAX_CASES_PER_RUN);
        $this->assertSame(800, Cfg::HARD_MAX_ACTOR_OUTPUT_TOKENS);
        $this->assertSame(1600, Cfg::HARD_MAX_JUDGE_OUTPUT_TOKENS);
        $this->assertSame(60, Cfg::HARD_MAX_TIMEOUT_SECONDS);
        $this->assertSame(['invalid_driver', 'missing_key', 'shared_key', 'missing_model', 'missing_price', 'zero_budget'], Cfg::REASONS);
    }

    /**
     * Evalúa `config/ultron_studio.php` con estas variables de entorno (y sin
     * ninguna otra del laboratorio), y las deja como estaban.
     *
     * @param  array<string,string>  $variables
     * @return array<string,mixed>
     */
    private function loadConfigWith(array $variables): array
    {
        $todas = ['OPENAI_API_KEY', 'ULTRON_STUDIO_LAB_ENABLED', 'ULTRON_STUDIO_LAB_DRIVER', 'ULTRON_STUDIO_LAB_OPENAI_API_KEY', 'ULTRON_STUDIO_LAB_MAX_CASES_PER_RUN',
            'ULTRON_STUDIO_LAB_ACTOR_MAX_OUTPUT_TOKENS', 'ULTRON_STUDIO_LAB_JUDGE_MAX_OUTPUT_TOKENS', 'ULTRON_STUDIO_LAB_TIMEOUT_SECONDS', 'ULTRON_STUDIO_LAB_MAX_RUNS_PER_DAY',
            'ULTRON_STUDIO_LAB_DAILY_BUDGET_USD', 'ULTRON_STUDIO_LAB_RUN_BUDGET_USD', 'ULTRON_STUDIO_LAB_OPENAI_MODEL', 'ULTRON_STUDIO_LAB_PRICE_INPUT_USD_PER_MTOK',
            'ULTRON_STUDIO_LAB_PRICE_OUTPUT_USD_PER_MTOK', 'ULTRON_STUDIO_LAB_OPENAI_TEMPERATURE', 'ULTRON_STUDIO_LAB_OPENAI_REASONING_EFFORT', 'ULTRON_STUDIO_LAB_OPENAI_BASE_URL'];
        $antes = [];
        foreach ($todas as $nombre) {
            $antes[$nombre] = [$_SERVER[$nombre] ?? null, $_ENV[$nombre] ?? null, getenv($nombre)];
            unset($_SERVER[$nombre], $_ENV[$nombre]);
            putenv($nombre);
        }
        try {
            foreach ($variables as $nombre => $valor) {
                $_SERVER[$nombre] = $valor;
                $_ENV[$nombre] = $valor;
                putenv("{$nombre}={$valor}");
            }

            return require config_path('ultron_studio.php');
        } finally {
            foreach ($antes as $nombre => [$servidor, $entorno, $proceso]) {
                unset($_SERVER[$nombre], $_ENV[$nombre]);
                putenv($nombre);
                if ($servidor !== null) {
                    $_SERVER[$nombre] = $servidor;
                }
                if ($entorno !== null) {
                    $_ENV[$nombre] = $entorno;
                }
                if ($proceso !== false) {
                    putenv("{$nombre}={$proceso}");
                }
            }
        }
    }
}
