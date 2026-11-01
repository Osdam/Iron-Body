<?php

namespace Tests\Feature\UltronStudio;

use App\Services\UltronStudio\StudioPublishing;
use Illuminate\Support\Facades\Route;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * EL MOTOR NO LEE EL STUDIO (contrato §A8), SALVO UNA TABLA (contrato H5 §A9).
 *
 * El Studio describe al agente; no lo gobierna. Esa frontera tiene que poder
 * comprobarse sin leer todo el código: ningún fichero fuera de los del Studio
 * nombra sus clases (`UltronStudio`), sus tablas (`ultron_studio_…`) ni su
 * configuración (`config('ultron_studio…')`). Se mide sobre los TOKENS de PHP,
 * como `PaymentMintAuthorityTest`: los comentarios no cuentan, el código sí
 * (nombres de clase, `use`, cadenas con nombres de tabla o claves de config).
 *
 * HITO 5, PASO 1c (mismo commit que el lector): el motor lee UNA tabla del
 * Studio (`ultron_studio_publications`) desde UNA clase
 * (`UltronPublishedConfig`). Esa clase no entra en la lista blanca: tiene su
 * propia aserción, y su referencia al Studio tiene que ser EXACTAMENTE esa
 * cadena, sin escrituras; solo la nombra, fuera del Studio, `UltronCommitService`
 * (que sella). La prueba de comportamiento —una sola SELECT por commit, y
 * ninguna con la llave en `off`— vive en
 * `Tests\Feature\Marketing\UltronCommitStudioStampTest` (§A9, prueba 2).
 *
 * HITO 5, PASO 3 (mismo commit que publicar): `StudioPublishing::
 * ENGINE_READS_VERSIONS` está girada (hay código que publica y código que lee)
 * y las rutas son 38. Lo que sigue apagado POR DEFECTO son las dos llaves de
 * entorno: la del motor (`MARKETING_ULTRON_STUDIO_CONFIG=off`: no consulta
 * nada) y la del Studio (`ULTRON_STUDIO_PUBLISHING_ENABLED=false`: publicar y
 * promover, 409). Se evalúan los dos ficheros de configuración sin las
 * variables en el entorno.
 *
 * HITO 6 (contrato H6 §I9.1): las dos migraciones de las integraciones entran
 * en la lista blanca y las rutas son 56. Las clases NEUTRAS del hito
 * (`App\Services\Voice`, `App\Services\Twilio` y `PhoneFingerprint`) no
 * nombran el Studio, y el motor no las nombra a ellas.
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
        // Hito 5 (contrato H5 §A3): la cadena de publicaciones y sus guardas.
        'database/migrations/2026_10_25_100001_create_ultron_studio_publications_table.php',
        'database/migrations/2026_10_25_100002_add_ultron_studio_publication_guards.php',
        // Hito 6 (contrato H6 §I9.1): las integraciones comerciales y sus guardas.
        'database/migrations/2026_11_01_100001_create_ultron_studio_integration_tables.php',
        'database/migrations/2026_11_01_100002_add_ultron_studio_integration_guards.php',
    ];

    /** Hito 6: las clases neutras del hito. Ni el motor las nombra ni ellas nombran el Studio. */
    private const NEUTRAL_ZONES = ['app/Services/Voice', 'app/Services/Twilio', 'app/Support/Privacy/PhoneFingerprint.php'];

    /**
     * El lector del motor (contrato H5 §A4). No está en la lista blanca: lo único
     * que puede nombrar del Studio es la tabla de la cadena, una vez, como cadena.
     * Un `use` de una clase del Studio, otra tabla o su configuración lo delatan.
     */
    private const ENGINE_READER = 'app/Services/Marketing/Ultron/UltronPublishedConfig.php';

    private const ENGINE_READER_REFERENCES = ["T_CONSTANT_ENCAPSED_STRING 'ultron_studio_publications'"];

    /** Fuera del Studio, los únicos que nombran al lector: él mismo y el commit, que sella (§A9). */
    private const ENGINE_READER_NAMERS = [
        'app/Services/Marketing/Ultron/UltronCommitService.php',
        self::ENGINE_READER,
    ];

    /** Lo que sería escribir o bloquear: ninguna de estas llamadas aparece en el lector. */
    private const WRITE_CALLS = ['insert', 'insertorignore', 'insertgetid', 'insertusing', 'update', 'updateorinsert', 'upsert',
        'delete', 'truncate', 'statement', 'unprepared', 'affectingstatement', 'increment', 'decrement', 'create', 'forcecreate',
        'save', 'fill', 'forcefill', 'push', 'touch', 'destroy', 'lockforupdate', 'sharedlock'];

    /** Lo que se rastrea: todo el PHP de la aplicación que corre en producción. */
    private const SCANNED = ['app', 'routes', 'config', 'database', 'bootstrap'];

    /** Las zonas del motor: limpias del Studio salvo el lector. */
    private const ENGINE_ZONES = ['app/Services/Marketing', 'app/Jobs', 'app/Http/Controllers/Api/Internal', 'routes/marketing.php', 'routes/api.php', 'config/marketing.php'];

    public function test_fuera_del_studio_nadie_nombra_sus_clases_sus_tablas_ni_su_configuracion(): void
    {
        $fuera = [];
        $dentro = 0;
        $lectorVisto = false;

        foreach ($this->phpFiles() as $relativa) {
            $hallazgos = $this->references(base_path($relativa));
            if ($hallazgos === []) {
                continue;
            }
            if ($this->allowed($relativa)) {
                $dentro++;

                continue;
            }
            if ($relativa === self::ENGINE_READER && $this->sinLinea($hallazgos) === self::ENGINE_READER_REFERENCES) {
                $lectorVisto = true;

                continue;
            }
            $fuera[$relativa] = $hallazgos;
        }

        $this->assertSame([], $fuera, "Código fuera del Studio que lo nombra (el motor solo puede leer ultron_studio_publications, desde UltronPublishedConfig):\n".json_encode($fuera, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        // La prueba ve lo que tiene que ver: si el rastreo dejara de encontrar
        // las referencias legítimas, no estaría comprobando nada.
        $this->assertGreaterThanOrEqual(17, $dentro);
        $this->assertTrue($lectorVisto, 'El rastreo no vio la única lectura del motor sobre el Studio.');
    }

    public function test_las_zonas_del_motor_estan_limpias_y_la_lista_blanca_no_esta_vieja(): void
    {
        foreach (self::ENGINE_ZONES as $zona) {
            $this->assertFileExists(base_path($zona));
            foreach ($this->phpFiles([$zona]) as $relativa) {
                $esperado = $relativa === self::ENGINE_READER ? self::ENGINE_READER_REFERENCES : [];
                $this->assertSame($esperado, $this->sinLinea($this->references(base_path($relativa))), "{$relativa} nombra el Studio.");
            }
        }

        foreach (self::ALLOWED as $permitido) {
            $this->assertFileExists(base_path(rtrim($permitido, '/')), "La lista blanca nombra algo que no existe: {$permitido}");
        }
    }

    /**
     * El lector, por dentro (contrato H5 §A9): la tabla una sola vez, nada del
     * Studio importado, ninguna llamada que escriba o bloquee, y solo lo nombra
     * el commit.
     */
    public function test_la_unica_lectura_del_motor_es_de_solo_lectura_y_solo_la_usa_el_commit(): void
    {
        $lector = base_path(self::ENGINE_READER);
        $this->assertFileExists($lector);

        $codigo = $this->codeOnly($lector);
        $this->assertSame(1, substr_count($codigo, 'ultron_studio_'), 'La tabla del Studio se nombra una sola vez en el lector.');
        $this->assertStringNotContainsString('UltronStudio', $codigo);
        $this->assertSame([], array_values(array_intersect($this->calledMethods($lector), self::WRITE_CALLS)), 'El lector escribe o bloquea.');

        $nombran = [];
        foreach ($this->phpFiles() as $relativa) {
            if ($this->allowed($relativa)) {
                continue;
            }
            if (str_contains($this->codeOnly(base_path($relativa)), 'UltronPublishedConfig')) {
                $nombran[] = $relativa;
            }
        }
        $this->assertSame(self::ENGINE_READER_NAMERS, $nombran, 'Fuera del Studio, solo el commit invoca al lector.');
    }

    /**
     * La llave del motor (contrato H5 §A13) nace apagada: se evalúa
     * config/marketing.php SIN la variable en el entorno, sea cual sea el
     * entorno con el que corra la suite.
     */
    public function test_la_llave_del_motor_vale_off_por_defecto(): void
    {
        $nombre = 'MARKETING_ULTRON_STUDIO_CONFIG';
        $antes = [$_SERVER[$nombre] ?? null, $_ENV[$nombre] ?? null, getenv($nombre)];

        try {
            unset($_SERVER[$nombre], $_ENV[$nombre]);
            putenv($nombre);
            $config = require base_path('config/marketing.php');
        } finally {
            [$servidor, $entorno, $proceso] = $antes;
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

        $this->assertSame('off', $config['ultron']['studio_config']);
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

        // Las 19 de F, las dos de lectura del Hito G (engine/readings y compare),
        // las 12 del laboratorio del Hito 4 (`lab/*`, contrato H4 §A7), las 5
        // de la publicación del Hito 5 (contrato H5 §A7) y las 18 de las
        // integraciones del Hito 6 (contrato H6 §I7.1).
        $this->assertSame(56, $delStudio);
    }

    /**
     * Hito 6 (contrato H6 §I9.1): ningún fichero de las zonas del motor nombra
     * la voz, el cliente de Twilio del hito ni la huella de teléfonos; y esas
     * clases neutras no nombran el Studio (ya lo cubre el rastreo global; se
     * escribe aparte para que el motivo se lea).
     */
    public function test_el_motor_no_nombra_las_clases_neutras_y_ellas_no_nombran_el_studio(): void
    {
        $vistos = 0;
        foreach (self::ENGINE_ZONES as $zona) {
            foreach ($this->phpFiles([$zona]) as $relativa) {
                $codigo = $this->codeOnly(base_path($relativa));
                foreach (['App\\Services\\Voice', 'App\\Services\\Twilio', 'PhoneFingerprint', 'Services\\Voice\\', 'Services\\Twilio\\'] as $nombre) {
                    $this->assertStringNotContainsString($nombre, $codigo, "{$relativa} nombra {$nombre}.");
                }
                $vistos++;
            }
        }
        $this->assertGreaterThan(50, $vistos, 'el rastreo ve las zonas del motor');

        $neutros = 0;
        foreach (self::NEUTRAL_ZONES as $zona) {
            $this->assertFileExists(base_path($zona));
            foreach ($this->phpFiles([$zona]) as $relativa) {
                $this->assertSame([], $this->references(base_path($relativa)), "{$relativa} nombra el Studio.");
                $this->assertStringNotContainsString('ultron-studio', $this->codeOnly(base_path($relativa)), "{$relativa} nombra las rutas del Studio.");
                $neutros++;
            }
        }
        $this->assertGreaterThanOrEqual(15, $neutros, 'el rastreo ve las clases neutras');
    }

    /**
     * Hito 5 (contrato H5 §A9, prueba 1, paso 3): hay código que publica y
     * código que lee, pero el motor solo lee con SU llave y publicar nace
     * apagado. Los dos ficheros de configuración se evalúan SIN las variables
     * en el entorno, sea cual sea el entorno con el que corra la suite.
     */
    public function test_el_motor_lee_solo_con_su_llave_y_publicar_esta_apagado_por_defecto(): void
    {
        $this->assertTrue(StudioPublishing::ENGINE_READS_VERSIONS);

        $marketing = $this->configWithout('config/marketing.php', 'MARKETING_ULTRON_STUDIO_CONFIG');
        $studio = $this->configWithout('config/ultron_studio.php', 'ULTRON_STUDIO_PUBLISHING_ENABLED');
        $this->assertSame('off', $marketing['ultron']['studio_config']);
        $this->assertFalse($studio['publishing_enabled']);

        // Con esos valores, ninguna llave abre publicar ni promover.
        config(['marketing.ultron.studio_config' => $marketing['ultron']['studio_config'], 'ultron_studio.publishing_enabled' => $studio['publishing_enabled']]);
        $this->assertFalse(StudioPublishing::available());
        $this->assertSame('publishing_disabled', StudioPublishing::keyRefusal('publish')['code']);
        $this->assertSame('publishing_disabled', StudioPublishing::keyRefusal('promote')['code']);
        $this->assertSame(StudioPublishing::SCOPE_OFF, StudioPublishing::engineScope());
    }

    /**
     * Un fichero de configuración evaluado sin una variable en el entorno.
     *
     * @return array<string, mixed>
     */
    private function configWithout(string $fichero, string $nombre): array
    {
        $antes = [$_SERVER[$nombre] ?? null, $_ENV[$nombre] ?? null, getenv($nombre)];

        try {
            unset($_SERVER[$nombre], $_ENV[$nombre]);
            putenv($nombre);

            return require base_path($fichero);
        } finally {
            [$servidor, $entorno, $proceso] = $antes;
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

    /**
     * Las referencias sin su número de línea, para comparar QUÉ se nombra.
     *
     * @param  list<string>  $referencias
     * @return list<string>
     */
    private function sinLinea(array $referencias): array
    {
        return array_map(fn (string $r) => (string) preg_replace('/ \(línea \d+\)$/u', '', $r), $referencias);
    }

    /** El código de un fichero sin comentarios ni docblocks. */
    private function codeOnly(string $ruta): string
    {
        $out = '';
        foreach (token_get_all((string) file_get_contents($ruta)) as $t) {
            if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= is_array($t) ? $t[1] : $t;
        }

        return $out;
    }

    /**
     * Los nombres de método que se llaman en un fichero (`->x(`, `?->x(` o `::x(`), en minúscula.
     *
     * @return list<string>
     */
    private function calledMethods(string $ruta): array
    {
        $tokens = array_values(array_filter(
            token_get_all((string) file_get_contents($ruta)),
            fn ($t) => ! is_array($t) || ! in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));
        $out = [];
        foreach ($tokens as $i => $t) {
            $esFlecha = is_array($t) && in_array($t[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true);
            $nombre = $tokens[$i + 1] ?? null;
            $parentesis = $tokens[$i + 2] ?? null;
            if ($esFlecha && is_array($nombre) && $nombre[0] === T_STRING && $parentesis === '(') {
                $out[] = strtolower($nombre[1]);
            }
        }

        return array_values(array_unique($out));
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
