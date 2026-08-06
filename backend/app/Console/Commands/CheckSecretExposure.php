<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Comprueba que ningún fichero con credenciales sea legible por quien no debe.
 *
 * Existe porque esto ya pasó: el directorio de la aplicación acumuló 56
 * respaldos de `.env` a permisos 644 —con tokens de Meta, llaves de Wompi de
 * producción, credenciales de Factus y la clave de OpenAI— legibles por
 * cualquier usuario del sistema. Nadie se dio cuenta durante meses porque
 * ninguna alarma miraba eso.
 *
 * Se revisan tres cosas:
 *
 *  1. Que ningún fichero con secretos tenga permiso de lectura para «otros».
 *  2. Que no haya ficheros de entorno DENTRO del docroot público, donde nginx
 *     podría servirlos por muy bien configurado que esté hoy.
 *  3. Que el `.env` en uso no esté a la vista.
 *
 * Devuelve código de salida 1 si encuentra algo, para poder engancharlo a un
 * despliegue o a un cron y que falle de forma visible.
 */
class CheckSecretExposure extends Command
{
    protected $signature = 'security:check-secret-exposure
        {--json : Salida en JSON}';

    protected $description = 'Detecta ficheros con credenciales legibles por usuarios sin permiso.';

    /**
     * Claves cuyo valor convierte un fichero en sensible. Si aparece cualquiera
     * de estas CON un valor real, el fichero no puede ser legible por todos.
     */
    private const SECRET_KEYS = [
        'APP_KEY', 'DB_PASSWORD',
        'META_ACCESS_TOKEN', 'META_APP_SECRET', 'META_WEBHOOK_SECRET', 'META_VERIFY_TOKEN',
        'OPENAI_API_KEY',
        'WOMPI_PRIVATE_KEY', 'WOMPI_EVENTS_SECRET', 'WOMPI_INTEGRITY_SECRET',
        'FACTUS_CLIENT_SECRET', 'FACTUS_PASSWORD',
        'TWILIO_AUTH_TOKEN', 'LIVEKIT_API_SECRET', 'FIREBASE_PRIVATE_KEY',
        'MARKETING_HERMES_API_KEY', 'API_SERVER_KEY',
    ];

    /** Valores que no son secretos aunque la clave lo parezca. */
    private const PLACEHOLDERS = ['null', 'changeme', 'tu_', 'your_', 'placeholder', 'xxx'];

    public function handle(): int
    {
        $findings = array_merge(
            $this->worldReadableSecrets(),
            $this->envInsidePublicRoot(),
        );

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'ok' => $findings === [],
                'findings' => $findings,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $findings === [] ? self::SUCCESS : self::FAILURE;
        }

        if ($findings === []) {
            $this->info('Ningún fichero con credenciales está expuesto.');

            return self::SUCCESS;
        }

        $this->error(sprintf('%d fichero(s) con credenciales al alcance de quien no debe:', count($findings)));
        $this->newLine();
        $this->table(
            ['problema', 'permisos', 'fichero'],
            array_map(fn (array $f) => [$f['issue'], $f['permissions'], $f['path']], $findings),
        );
        $this->newLine();
        $this->line('  Arreglo: <fg=yellow>chmod 600 <fichero></> y mover los respaldos fuera del directorio de la aplicación.');

        return self::FAILURE;
    }

    /**
     * Ficheros de entorno legibles por «otros» que contienen secretos reales.
     *
     * @return array<int, array<string,string>>
     */
    private function worldReadableSecrets(): array
    {
        $findings = [];

        foreach ($this->envFiles() as $path) {
            if (! is_readable($path)) {
                continue;
            }

            $perms = fileperms($path);
            // Bit de lectura para «otros» (0004).
            if (($perms & 0004) === 0) {
                continue;
            }

            if (! $this->containsRealSecret($path)) {
                continue; // un .env.example sin valores no es un problema
            }

            $findings[] = [
                'issue' => 'legible por cualquier usuario',
                'permissions' => substr(sprintf('%o', $perms), -4),
                'path' => $path,
            ];
        }

        return $findings;
    }

    /**
     * Ficheros de entorno dentro del directorio que sirve nginx.
     *
     * Hoy nginx los bloquea, pero una regla puede desaparecer en un cambio de
     * configuración y entonces quedarían servibles por HTTP. Un fichero de
     * entorno no tiene ninguna razón para vivir ahí.
     *
     * @return array<int, array<string,string>>
     */
    private function envInsidePublicRoot(): array
    {
        $public = public_path();

        if (! is_dir($public)) {
            return [];
        }

        $findings = [];

        foreach ((array) glob($public.'/{,.}env*', GLOB_BRACE) as $path) {
            if (! is_file($path)) {
                continue;
            }
            $findings[] = [
                'issue' => 'dentro del docroot público',
                'permissions' => substr(sprintf('%o', fileperms($path)), -4),
                'path' => $path,
            ];
        }

        return $findings;
    }

    /** @return array<int,string> */
    private function envFiles(): array
    {
        $paths = array_merge(
            (array) glob(base_path('.env*')),
            (array) glob(base_path('*/.env*')),
        );

        return array_values(array_filter($paths, 'is_file'));
    }

    /** ¿Tiene alguna clave sensible con un valor de verdad? */
    private function containsRealSecret(string $path): bool
    {
        $contents = @file_get_contents($path);
        if ($contents === false) {
            return false;
        }

        foreach (self::SECRET_KEYS as $key) {
            // Espacio HORIZONTAL únicamente ([ \t]), nunca \s: en PCRE \s
            // incluye el salto de línea, así que una clave vacía —«APP_KEY=»
            // seguida de otra línea— capturaba el valor de la línea siguiente y
            // marcaba como secreto un fichero de ejemplo perfectamente limpio.
            // Un detector que grita en falso se acaba ignorando.
            if (preg_match('/^[ \t]*'.preg_quote($key, '/').'[ \t]*=[ \t]*"?([^"\r\n#]+)"?/m', $contents, $m) !== 1) {
                continue;
            }

            $value = trim($m[1]);

            if ($value === '') {
                continue; // clave declarada pero sin valor: eso es un ejemplo
            }

            foreach (self::PLACEHOLDERS as $placeholder) {
                if (stripos($value, $placeholder) === 0) {
                    continue 2; // es un ejemplo, no un secreto
                }
            }

            return true;
        }

        return false;
    }
}
