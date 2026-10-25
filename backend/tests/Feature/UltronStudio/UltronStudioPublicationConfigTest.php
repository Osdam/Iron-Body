<?php

namespace Tests\Feature\UltronStudio;

use App\Models\Admin;
use App\Services\Marketing\Ultron\UltronPublishedConfig;
use App\Services\UltronStudio\StudioPublicationDeclaration as D;
use App\Services\UltronStudio\StudioPublishing;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * La CONFIGURACIÓN de la publicación (contrato H5 §A13) y el comando
 * `ultron-studio:publication-check` (§A6, «Freno»):
 *
 *  - la llave del motor la lee el Studio con el MISMO criterio que el lector
 *    del motor (es quien la obedece);
 *  - cada variable de A13 desde el entorno: el defecto sin ella, lo que hace
 *    una línea vacía (en la ventana, «sin ventana»; en los umbrales, el
 *    defecto) y un valor inválido;
 *  - `.env.example` las documenta con sus defectos;
 *  - el comando sale 1 ante deriva o cabeza inválida y 0 si todo cuadra, sin
 *    ids de conversación ni textos.
 */
class UltronStudioPublicationConfigTest extends TestCase
{
    use PublicationFixtures;
    use RefreshDatabase;
    use StudioFixtures;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** @return iterable<string, array{mixed}> */
    public static function scopeValues(): iterable
    {
        foreach ([null, '', 'off', 'canary', 'all', ' ALL ', 'Canary', 'on', 'true', 'canary,all', ['all'], 1] as $i => $valor) {
            yield "valor {$i}: ".json_encode($valor) => [$valor];
        }
    }

    #[DataProvider('scopeValues')]
    public function test_la_llave_del_motor_se_lee_como_la_lee_el_lector(mixed $valor): void
    {
        config(['marketing.ultron.studio_config' => $valor]);

        $this->assertSame(app(UltronPublishedConfig::class)->describe()['env'], StudioPublishing::engineScope());
    }

    public function test_las_constantes_compartidas_con_el_motor_son_las_suyas(): void
    {
        $this->assertSame([UltronPublishedConfig::OFF, UltronPublishedConfig::CANARY, UltronPublishedConfig::ALL], StudioPublishing::SCOPES);
        $this->assertSame(UltronPublishedConfig::CONTRACT, D::CONTRACT);
        $this->assertSame(UltronPublishedConfig::MAX_EFFECTIVE_JSON, D::MAX_EFFECTIVE_JSON);
    }

    /**
     * El fichero de configuración evaluado con unas variables del entorno.
     *
     * @param  array<string, string|null>  $variables  null = sin la variable
     * @param  string|null  $seccion  la sección del fichero, o null = el fichero entero
     * @return array<string, mixed>
     */
    private function configWith(array $variables, ?string $seccion = 'publishing'): array
    {
        $antes = [];
        foreach ($variables as $nombre => $valor) {
            $antes[$nombre] = [$_SERVER[$nombre] ?? null, $_ENV[$nombre] ?? null, getenv($nombre)];
            unset($_SERVER[$nombre], $_ENV[$nombre]);
            putenv($nombre);
            if ($valor !== null) {
                $_SERVER[$nombre] = $_ENV[$nombre] = $valor;
                putenv("{$nombre}={$valor}");
            }
        }

        try {
            $todo = require base_path('config/ultron_studio.php');

            return $seccion === null ? $todo : $todo[$seccion];
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

    public function test_cada_variable_de_a13_con_su_defecto_su_linea_vacia_y_su_valor_invalido(): void
    {
        $sinNada = $this->configWith([
            'ULTRON_STUDIO_PUBLISH_LAB_GATE' => null, 'ULTRON_STUDIO_CANARY_MIN_HOURS' => null,
            'ULTRON_STUDIO_CANARY_MIN_TURNS' => null, 'ULTRON_STUDIO_PUBLISH_WINDOW' => null,
        ]);
        $this->assertSame(['lab_gate' => 'required', 'canary_min_hours' => 24, 'canary_min_turns' => 3, 'window' => '07:00-19:00'], $sinNada);

        // Vacías: la ventana deja de existir (lo dice el contrato); los umbrales y la puerta, su defecto.
        $vacias = $this->configWith(['ULTRON_STUDIO_PUBLISH_LAB_GATE' => '', 'ULTRON_STUDIO_CANARY_MIN_HOURS' => '', 'ULTRON_STUDIO_CANARY_MIN_TURNS' => '', 'ULTRON_STUDIO_PUBLISH_WINDOW' => '']);
        $this->assertSame(['lab_gate' => 'required', 'canary_min_hours' => 24, 'canary_min_turns' => 3, 'window' => ''], $vacias);

        // Fuera de rango, se recorta; mal escritas, el defecto.
        $raras = $this->configWith(['ULTRON_STUDIO_PUBLISH_LAB_GATE' => ' WAIVED ', 'ULTRON_STUDIO_CANARY_MIN_HOURS' => '500', 'ULTRON_STUDIO_CANARY_MIN_TURNS' => '0', 'ULTRON_STUDIO_PUBLISH_WINDOW' => '08:30-17:15']);
        $this->assertSame(['lab_gate' => 'waived', 'canary_min_hours' => 168, 'canary_min_turns' => 1, 'window' => '08:30-17:15'], $raras);
        foreach (['siete a siete', '19:00-07:00', '07:00-07:00', '7:00-19:00', '24:00-25:00', 'false'] as $ventana) {
            $this->assertSame('07:00-19:00', $this->configWith(['ULTRON_STUDIO_PUBLISH_WINDOW' => $ventana])['window'], $ventana);
        }
        // Solo `waived` (sin distinguir mayúsculas ni espacios) dispensa la puerta.
        foreach (['si', 'off', 'required ', 'waive', 'no'] as $puerta) {
            $this->assertSame('required', $this->configWith(['ULTRON_STUDIO_PUBLISH_LAB_GATE' => $puerta])['lab_gate'], $puerta);
        }
        $this->assertSame(1, $this->configWith(['ULTRON_STUDIO_CANARY_MIN_HOURS' => '-3'])['canary_min_hours']);
        $this->assertSame(24, $this->configWith(['ULTRON_STUDIO_CANARY_MIN_HOURS' => '1.5'])['canary_min_hours']);
    }

    /**
     * M-1 de la revisión de seguridad de H5 (25-10): `(bool) env()` daba por
     * ENCENDIDA la llave con `off`, `no`, `disabled` o ` false`, y `off` es
     * justo el valor «apagado» de la llave hermana del motor. Solo `true`
     * (o 1/on/yes) la enciende; cualquier otro texto y la basura la apagan,
     * también en el servicio y en la puerta de publicar.
     */
    public function test_la_llave_de_publicar_solo_se_enciende_con_true(): void
    {
        foreach (['off', 'no', 'disabled', ' false', 'false', '0', '', 'si', 'enabled', 'activa'] as $valor) {
            $this->assertFalse($this->configWith(['ULTRON_STUDIO_PUBLISHING_ENABLED' => $valor], null)['publishing_enabled'], json_encode($valor));
        }
        $this->assertFalse($this->configWith(['ULTRON_STUDIO_PUBLISHING_ENABLED' => null], null)['publishing_enabled'], 'sin la variable');
        foreach (['true', 'TRUE', '1', 'on', 'yes'] as $valor) {
            $this->assertTrue($this->configWith(['ULTRON_STUDIO_PUBLISHING_ENABLED' => $valor], null)['publishing_enabled'], $valor);
        }
        // La llave del laboratorio (Hito 4) tenía la misma clase de fallo.
        foreach (['off', 'no', 'disabled', ''] as $valor) {
            $this->assertFalse($this->configWith(['ULTRON_STUDIO_LAB_ENABLED' => $valor], null)['lab']['enabled'], json_encode($valor));
        }
        $this->assertTrue($this->configWith(['ULTRON_STUDIO_LAB_ENABLED' => 'true'], null)['lab']['enabled']);

        // En el servicio, un valor que no sea exactamente `true` es «apagada» aunque el motor lea.
        foreach (['off', 'true', 1, '1', null] as $valor) {
            config(['marketing.ultron.studio_config' => 'canary', 'ultron_studio.publishing_enabled' => $valor]);
            $this->assertFalse(StudioPublishing::flagEnabled(), json_encode($valor));
            $this->assertSame('publishing_disabled', StudioPublishing::keyRefusal(D::ACTION_PUBLISH)['code'] ?? null, json_encode($valor));
        }
        config(['ultron_studio.publishing_enabled' => true]);
        $this->assertTrue(StudioPublishing::flagEnabled());
        $this->assertNull(StudioPublishing::keyRefusal(D::ACTION_PUBLISH));
    }

    public function test_la_ventana_y_los_umbrales_se_vuelven_a_acotar_al_leerlos(): void
    {
        config(['ultron_studio.publishing.window' => 'no es una ventana', 'ultron_studio.publishing.canary_min_hours' => 0, 'ultron_studio.publishing.canary_min_turns' => 'mucho']);
        $this->assertSame(['start' => 7 * 60, 'end' => 19 * 60, 'label' => '07:00–19:00'], StudioPublishing::window());
        $this->assertSame(['minHours' => 1, 'minTurns' => 3], StudioPublishing::thresholds());
        config(['ultron_studio.publishing.window' => '']);
        $this->assertNull(StudioPublishing::window());
        $this->assertTrue(StudioPublishing::windowOpen(CarbonImmutable::parse('2026-11-03T08:00:00Z')));
    }

    public function test_env_example_documenta_las_variables_con_sus_defectos(): void
    {
        $ejemplo = (string) file_get_contents(base_path('.env.example'));
        foreach ([
            'ULTRON_STUDIO_PUBLISHING_ENABLED=false',
            'ULTRON_STUDIO_PUBLISH_LAB_GATE=required',
            'ULTRON_STUDIO_CANARY_MIN_HOURS=24',
            'ULTRON_STUDIO_CANARY_MIN_TURNS=3',
            'ULTRON_STUDIO_PUBLISH_WINDOW=07:00-19:00',
            'MARKETING_ULTRON_STUDIO_CONFIG=off',
        ] as $linea) {
            $this->assertMatchesRegularExpression('/^'.preg_quote($linea, '/').'$/m', $ejemplo, $linea);
        }
    }

    public function test_el_comando_de_comprobacion_sale_1_ante_deriva_o_cabeza_invalida(): void
    {
        $this->importBaseline();
        Carbon::setTestNow(Carbon::parse('2026-11-03T15:00:00Z'));
        $this->openKeys();
        $ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana');
        $root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root');

        // Sin publicaciones: Legacy, todo cuadra.
        $this->artisan('ultron-studio:publication-check')->expectsOutputToContain('Cadena vacía')->assertSuccessful();

        $v = $this->approvedVersion($ana, $root, 'Etiqueta de la versión');
        $this->publishAs($root, $v->uuid)->assertCreated();
        $this->artisan('ultron-studio:publication-check')->expectsOutputToContain('La publicación vigente cuadra con el motor.')->assertSuccessful();

        // Deriva: el entorno ya no tiene el cobro como declara la versión.
        config(['marketing.ultron.payment_links_enabled' => true]);
        $this->assertSame(1, Artisan::call('ultron-studio:publication-check', ['--json' => true]));
        $json = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame([false, ['drift']], [$json['ok'], $json['problems']]);
        $this->assertSame([['param' => 'rules.paymentLinksEnabled', 'declared' => false, 'engine' => true]], $json['publication']['drift']);
        $this->assertStringNotContainsString((string) self::CANARY_ID, json_encode($json));
        config(['marketing.ultron.payment_links_enabled' => false]);

        // Cabeza que no verifica.
        $this->seedEntry(D::ACTION_LEGACY, null, null, null, ['signature' => str_repeat('b', 64)]);
        $this->artisan('ultron-studio:publication-check')->expectsOutputToContain('NO VERIFICA')->assertFailed();
    }
}
