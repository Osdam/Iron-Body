<?php

namespace Tests\Feature\UltronStudio;

use App\Services\UltronStudio\StudioPublishing;
use Illuminate\Support\Facades\Route;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * EL MOTOR NO LEE EL STUDIO (contrato §A8).
 *
 * El Studio describe al agente; no lo gobierna. Esa frontera tiene que poder
 * comprobarse sin leer todo el código: ningún fichero fuera de los del Studio
 * nombra sus clases (`UltronStudio`), sus tablas (`ultron_studio_…`) ni su
 * configuración (`config('ultron_studio…')`). Se mide sobre los TOKENS de PHP,
 * como `PaymentMintAuthorityTest`: los comentarios no cuentan, el código sí
 * (nombres de clase, `use`, cadenas con nombres de tabla o claves de config).
 *
 * Si mañana el motor necesita leer una versión aprobada, esta prueba falla a
 * propósito: esa fase tiene que cambiar `StudioPublishing::ENGINE_READS_VERSIONS`
 * y esta lista en el mismo commit que el motor, a conciencia.
 */
class UltronEngineIsolationTest extends TestCase
{
    /** Los únicos ficheros (o directorios, con barra final) que pueden nombrar el Studio. */
    private const ALLOWED = [
        'app/Services/UltronStudio/',
        'app/Models/UltronStudio/',
        'app/Http/Controllers/Crm/UltronStudio/',
        'app/Console/Commands/UltronStudio/',
        'app/Exceptions/UltronStudioException.php',
        'routes/ultron-studio.php',
        'config/ultron_studio.php',
        'database/migrations/2026_10_18_100001_create_ultron_studio_tables.php',
        'database/migrations/2026_10_18_100002_add_ultron_studio_guards.php',
        // Hito G (contrato G §A7): la columna `conditions` de las reglas.
        'database/migrations/2026_10_19_100001_add_conditions_to_ultron_studio_rules.php',
        // Hito 4 (contrato H4 §A8): el laboratorio.
        'database/migrations/2026_10_19_100002_create_ultron_studio_lab_tables.php',
        'database/migrations/2026_10_19_100003_add_ultron_studio_lab_guards.php',
    ];

    /** Lo que se rastrea: todo el PHP de la aplicación que corre en producción. */
    private const SCANNED = ['app', 'routes', 'config', 'database', 'bootstrap'];

    public function test_fuera_del_studio_nadie_nombra_sus_clases_sus_tablas_ni_su_configuracion(): void
    {
        $fuera = [];
        $dentro = 0;

        foreach ($this->phpFiles() as $relativa) {
            $hallazgos = $this->references(base_path($relativa));
            if ($hallazgos === []) {
                continue;
            }
            if ($this->allowed($relativa)) {
                $dentro++;

                continue;
            }
            $fuera[$relativa] = $hallazgos;
        }

        $this->assertSame([], $fuera, "Código fuera del Studio que lo nombra (el motor no puede leer el Studio):\n".json_encode($fuera, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        // La prueba ve lo que tiene que ver: si el rastreo dejara de encontrar
        // las referencias legítimas, no estaría comprobando nada.
        $this->assertGreaterThanOrEqual(15, $dentro);
    }

    public function test_las_zonas_del_motor_estan_limpias_y_la_lista_blanca_no_esta_vieja(): void
    {
        foreach (['app/Services/Marketing', 'app/Jobs', 'app/Http/Controllers/Api/Internal', 'routes/marketing.php', 'routes/api.php', 'config/marketing.php'] as $zona) {
            $this->assertFileExists(base_path($zona));
            foreach ($this->phpFiles([$zona]) as $relativa) {
                $this->assertSame([], $this->references(base_path($relativa)), "{$relativa} nombra el Studio.");
            }
        }

        foreach (self::ALLOWED as $permitido) {
            $this->assertFileExists(base_path(rtrim($permitido, '/')), "La lista blanca nombra algo que no existe: {$permitido}");
        }
    }

    public function test_ninguna_ruta_del_studio_cuelga_de_internal_ni_lleva_la_firma_de_n8n(): void
    {
        $delStudio = 0;
        foreach (Route::getRoutes() as $route) {
            $accion = $route->getActionName();
            $esDelStudio = str_contains($accion, '\\UltronStudio\\');

            if (str_contains($route->uri(), 'ultron-studio')) {
                $this->assertTrue($esDelStudio, "{$route->uri()} no la atiende un controlador del Studio.");
            }
            if (! $esDelStudio) {
                continue;
            }

            $delStudio++;
            $this->assertStringStartsWith('api/admin/ultron-studio/', $route->uri());
            $this->assertStringNotContainsString('internal', $route->uri());
            $this->assertNotContains('automation.internal', $route->gatherMiddleware(), $route->uri());
        }

        // Las 19 de F, las dos de lectura del Hito G (engine/readings y compare)
        // y las 12 del laboratorio del Hito 4 (`lab/*`, contrato H4 §A7).
        $this->assertSame(33, $delStudio);
    }

    public function test_el_motor_no_lee_versiones_y_publicar_esta_apagado(): void
    {
        $this->assertFalse(StudioPublishing::ENGINE_READS_VERSIONS);
        $this->assertFalse(StudioPublishing::available());
        $this->assertFalse((bool) config('ultron_studio.publishing_enabled'));
    }

    // ────────────────────────────────────────────────────────────────────────

    /**
     * Qué nombra del Studio un fichero, mirando solo el código.
     *
     * @return list<string>
     */
    private function references(string $ruta): array
    {
        $out = [];
        foreach (token_get_all((string) file_get_contents($ruta)) as $t) {
            if (! is_array($t) || in_array($t[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE, T_INLINE_HTML], true)) {
                continue;
            }
            $texto = $t[1];
            $cadena = $t[0] === T_CONSTANT_ENCAPSED_STRING || $t[0] === T_ENCAPSED_AND_WHITESPACE;
            if (str_contains($texto, 'UltronStudio')
                || str_contains($texto, 'ultron_studio_')
                || ($cadena && preg_match('/^[\'"]?ultron_studio(\.|[\'"]?$)/', $texto) === 1)) {
                $out[] = token_name($t[0]).' '.$texto.' (línea '.$t[2].')';
            }
        }

        return $out;
    }

    private function allowed(string $relativa): bool
    {
        foreach (self::ALLOWED as $permitido) {
            if (str_ends_with($permitido, '/') ? str_starts_with($relativa, $permitido) : $relativa === $permitido) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>|null  $raices
     * @return list<string> rutas relativas a la base
     */
    private function phpFiles(?array $raices = null): array
    {
        $base = rtrim(base_path(), '/').'/';
        $out = [];
        foreach ($raices ?? self::SCANNED as $raiz) {
            $absoluta = $base.$raiz;
            if (is_file($absoluta)) {
                $out[] = $raiz;

                continue;
            }
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($absoluta, RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isFile() && $f->getExtension() === 'php' && ! str_contains($f->getPathname(), '/bootstrap/cache/')) {
                    $out[] = substr($f->getPathname(), strlen($base));
                }
            }
        }
        sort($out);

        return $out;
    }
}
