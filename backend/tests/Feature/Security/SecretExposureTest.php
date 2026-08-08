<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Que un fichero con credenciales no pueda salir por HTTP ni leerlo cualquiera.
 *
 * Esto no es paranoia teórica: el directorio de la aplicación en producción
 * acumuló 56 respaldos de `.env` a permisos 644 —tokens de Meta, llaves de
 * Wompi de producción, credenciales de Factus, la clave de OpenAI— durante
 * meses, legibles por cualquier usuario del sistema. Nadie lo detectó porque
 * ninguna prueba miraba eso.
 *
 * Estas pruebas son el guardia que faltaba. Cubren dos superficies distintas:
 * lo que la aplicación puede servir (rutas) y lo que hay en el disco
 * (permisos y ubicación).
 */
class SecretExposureTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ninguna ruta de la aplicación puede devolver un fichero de entorno.
     *
     * Se prueban también las variantes codificadas porque un traversal casi
     * nunca llega escrito en limpio.
     */
    #[DataProvider('exposurePaths')]
    public function test_no_route_ever_serves_an_env_file(string $path): void
    {
        $response = $this->get($path);

        $this->assertNotSame(200, $response->getStatusCode(), "La ruta {$path} devolvió 200.");

        $body = $response->getContent() ?: '';
        foreach (['APP_KEY=', 'DB_PASSWORD=', 'META_ACCESS_TOKEN=', 'OPENAI_API_KEY=', 'WOMPI_PRIVATE_KEY='] as $marker) {
            $this->assertStringNotContainsString($marker, $body, "Se filtró {$marker} en {$path}");
        }
    }

    public static function exposurePaths(): array
    {
        return [
            'directo' => ['/.env'],
            'un nivel arriba' => ['/../.env'],
            'dos niveles arriba' => ['/../../.env'],
            'respaldo con nombre' => ['/.env.backup'],
            'respaldo con fecha' => ['/.env.backup-wompi-prod-2026-07-18-194241'],
            'respaldo guion bajo' => ['/.env.bak'],
            'copia de editor' => ['/.env~'],
            'swap de vim' => ['/.env.swp'],
            'codificado' => ['/%2e%2e/.env'],
            'doble codificado' => ['/%252e%252e/.env'],
            'dentro de public' => ['/public/.env'],
            'git' => ['/.git/config'],
            'log de laravel' => ['/storage/logs/laravel.log'],
            'log del canal' => ['/storage/logs/channel.log'],
        ];
    }

    /**
     * El docroot es `public/`. Un fichero de entorno ahí dentro sería servible
     * por HTTP en cuanto alguien retoque la configuración de nginx.
     */
    public function test_the_public_directory_contains_no_environment_files(): void
    {
        $found = array_filter(
            (array) glob(public_path('{,.}env*'), GLOB_BRACE),
            'is_file',
        );

        $this->assertSame(
            [],
            array_values($found),
            'Hay ficheros de entorno dentro del docroot público: '.implode(', ', $found),
        );
    }

    /**
     * Ningún fichero con secretos reales puede ser legible por «otros».
     *
     * Se apoya en el mismo comando que se ejecuta en el servidor, para que la
     * prueba y la comprobación de producción no puedan divergir.
     */
    public function test_no_file_with_real_secrets_is_world_readable(): void
    {
        $exitCode = $this->artisan('security:check-secret-exposure');

        $exitCode->assertSuccessful();
    }

    /**
     * La configuración COMPILADA cuenta como fichero con secretos.
     *
     * En producción el `.env` estaba a 600 y el comando decía que no había nada
     * expuesto, mientras `bootstrap/cache/config.php` —el volcado de `config()`
     * con APP_KEY, la contraseña de la base, Wompi, Meta, OpenAI y Factus ya
     * resueltos— estaba a 664. Lo escribe `config:cache` con el umask del
     * proceso, así que no basta con arreglarlo una vez: vuelve a nacer legible
     * en cada despliegue. Esta prueba fija que el comando lo mire.
     */
    public function test_the_compiled_config_cache_is_not_world_readable(): void
    {
        $path = base_path('bootstrap/cache/config.php');
        $existed = File::exists($path);
        $originalPerms = $existed ? (fileperms($path) & 0777) : null;

        if (! $existed) {
            File::ensureDirectoryExists(dirname($path));
            File::put($path, "<?php return ['app' => ['key' => 'base64:loquesea']];");
        }

        try {
            chmod($path, 0664);
            clearstatcache(true, $path);

            $this->artisan('security:check-secret-exposure')->assertFailed();

            chmod($path, 0600);
            clearstatcache(true, $path);

            $this->artisan('security:check-secret-exposure')->assertSuccessful();
        } finally {
            if ($existed) {
                chmod($path, $originalPerms);
            } else {
                File::delete($path);
            }
        }
    }

    /**
     * Los ficheros de ejemplo SÍ pueden ser legibles, pero justo por eso no
     * pueden llevar valores de verdad: son los que acaban en Git.
     */
    public function test_the_example_env_files_carry_no_real_values(): void
    {
        foreach (['.env.example', '.env.production.example'] as $name) {
            $path = base_path($name);
            if (! File::exists($path)) {
                continue;
            }

            $contents = File::get($path);

            // Un token de Meta empieza por EAA; una clave de OpenAI por sk-.
            $this->assertDoesNotMatchRegularExpression(
                '/^\s*META_ACCESS_TOKEN\s*=\s*"?EAA/m',
                $contents,
                "{$name} contiene un token real de Meta.",
            );
            $this->assertDoesNotMatchRegularExpression(
                '/^\s*OPENAI_API_KEY\s*=\s*"?sk-[A-Za-z0-9_-]{20,}/m',
                $contents,
                "{$name} contiene una clave real de OpenAI.",
            );
            $this->assertDoesNotMatchRegularExpression(
                // La alternancia va AGRUPADA a propósito: sin los paréntesis
                // internos, el `|prod_` se aplicaría al patrón entero y saltaría
                // con cualquier «prod_» suelto en un comentario del fichero.
                '/^\s*(WOMPI_PRIVATE_KEY|WOMPI_EVENTS_SECRET)\s*=\s*"?(prv_prod|prod_)/m',
                $contents,
                "{$name} contiene una llave de producción de Wompi.",
            );
            $this->assertDoesNotMatchRegularExpression(
                '/^\s*APP_KEY\s*=\s*"?base64:[A-Za-z0-9+\/]{40,}/m',
                $contents,
                "{$name} contiene una APP_KEY real.",
            );
        }
    }

    /** El .gitignore tiene que seguir excluyendo el .env y sus respaldos. */
    public function test_git_never_tracks_environment_files_with_secrets(): void
    {
        $gitignore = File::exists(base_path('.gitignore'))
            ? File::get(base_path('.gitignore'))
            : '';

        $this->assertMatchesRegularExpression(
            '/^\.env$/m',
            $gitignore,
            'El .gitignore ya no excluye el fichero .env.',
        );
    }
}
