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
 * Se revisan cinco cosas:
 *
 *  1. Que ningún fichero con secretos tenga permiso de lectura para «otros».
 *  2. Que no haya ficheros de entorno DENTRO del docroot público, donde nginx
 *     podría servirlos por muy bien configurado que esté hoy.
 *  3. Que el `.env` en uso no esté a la vista.
 *  4. Que la CONFIGURACIÓN COMPILADA no esté a la vista. Esta se añadió después
 *     de encontrarla en producción a 664: `config:cache` vuelca en
 *     `bootstrap/cache/config.php` el valor RESUELTO de todo —APP_KEY, la
 *     contraseña de la base, Wompi, Meta, OpenAI, Factus— y lo escribe con el
 *     umask del proceso, que deja 644 por defecto. El `.env` estaba
 *     impecable a 600 y esta orden decía que no había nada expuesto, mientras
 *     al lado había una copia legible de todos los secretos. Proteger el
 *     original y no su copia compilada es no proteger nada.
 *  5. Que ningún LOG legible por «otros» lleve una credencial (auditoría
 *     B-T17): los canales `channel` y `voice` se crean en 0664 a propósito, y
 *     un volcado del entorno que acabe en uno (una traza, un `printenv`, un
 *     `.env` pegado) deja la clave a la vista de cualquiera. SC-12 del cierre:
 *     en un log se reconoce también `CLAVE=valor` en mitad de una línea,
 *     `"CLAVE":"valor"` (JSON) y los volcados de PHP ({@see LOG_FORMATS}); lo
 *     que NO se mira lo dice la propia salida ({@see LOG_NOT_COVERED}), para
 *     que un verde no se lea como más de lo que es.
 *
 * Las claves vigiladas son las de {@see SECRET_KEYS} (con las 13 que H-4 de
 * SEC7 echó en falta) y las de las familias de la voz del Hito 8
 * ({@see SECRET_KEY_FAMILIES}). Nunca imprime un VALOR: cada hallazgo dice el
 * fichero, sus permisos y los NOMBRES de las claves con valor real.
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
        // La clave PROPIA del laboratorio de ULTRON STUDIO (Hito 4).
        'ULTRON_STUDIO_LAB_OPENAI_API_KEY',
        // H-4 de SEC7 (auditoría B-T17): las que dejaba fuera. Las de ElevenLabs
        // van en las familias de la voz, abajo.
        'ADMIN_API_TOKEN', 'AUTOMATION_INTERNAL_SECRET', 'DB_OWNER_PASSWORD', 'MAIL_PASSWORD',
        'META_EMBEDDED_SIGNUP_APP_SECRET', 'N8N_WEBHOOK_SECRET', 'REDIS_PASSWORD',
        'ULTRON_STUDIO_TWILIO_PROBE_KEY_SECRET', 'FACTUS_WEBHOOK_SECRET', 'AWS_SECRET_ACCESS_KEY',
        'LABSMOBILE_TOKEN', 'MEMBER_REGISTRATION_TOKEN',
    ];

    /**
     * Auditoría B-T17: las familias de la VOZ (Hito 8). Es secreta toda clave
     * con uno de estos prefijos cuyo nombre TERMINA en uno de
     * {@see SECRET_SUFFIXES}: hoy `ELEVENLABS_API_KEY`, `ELEVENLABS_WEBHOOK_SECRET`,
     * `ELEVENLABS_TOOL_SECRET`, `ELEVENLABS_INITIATION_SECRET`,
     * `TWILIO_VOICE_API_KEY_SECRET` y `TWILIO_VOICE_SIGNING_TOKEN`, y las que
     * vengan con esa forma. Los ids (`*_REF`, `*_SID`, `*_ID`), la región, la
     * base de los webhooks y las banderas no lo son: un `.env.example` con
     * `ELEVENLABS_REGION=default` sigue limpio.
     */
    private const SECRET_KEY_FAMILIES = ['ELEVENLABS', 'TWILIO_VOICE', 'VOICE_WEBHOOK'];

    /** Lo que hace secreta una clave de una familia (el mismo criterio que la guarda de la voz). */
    private const SECRET_SUFFIXES = ['API_KEY', 'SECRET', 'TOKEN', 'PASSWORD'];

    /** Valores que no son secretos aunque la clave lo parezca. */
    private const PLACEHOLDERS = ['null', 'changeme', 'tu_', 'your_', 'placeholder', 'xxx'];

    /** Los logs que se miran (los legibles por «otros»). */
    private const LOG_FILES = 'storage/logs/*.log';

    /** SC-12: las formas en que se reconoce un secreto en una línea de log ({@see logLinePatterns()}). */
    private const LOG_FORMATS = [
        'CLAVE=valor al principio de la línea (también con espacios, comillas o export)',
        'CLAVE=valor en mitad de la línea (pegado al =, o entre comillas)',
        '"CLAVE":"valor" (JSON, también escapado dentro de otro JSON)',
        "'CLAVE' => 'valor' y [CLAVE] => valor (volcados de PHP)",
    ];

    /** SC-12: lo que el rastreo de logs NO ve. Lo dice la salida: un verde no cubre esto. */
    private const LOG_NOT_COVERED = [
        'en mitad de la línea, CLAVE = valor sin comillas y separado del = (se confundiría con el texto que sigue)',
        'un valor partido en varias líneas o codificado (base64, URL, \\u…)',
        'CLAVE: valor (YAML, cabeceras) ni Authorization: Bearer …',
        'una clave en minúsculas, con otro nombre o con un prefijo propio (PUSHER_APP_KEY no es APP_KEY)',
        'otros ficheros: rotados (*.log.1), comprimidos (*.gz) o fuera de storage/logs',
    ];

    public function handle(): int
    {
        $findings = array_merge(
            $this->worldReadableSecrets(),
            $this->envInsidePublicRoot(),
            $this->worldReadableCompiledConfig(),
            $this->worldReadableLogsWithSecrets(),
        );

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'ok' => $findings === [],
                'findings' => $findings,
                // SC-12: qué cubre y qué no el rastreo de logs (aditivo).
                'logScan' => ['files' => self::LOG_FILES, 'formats' => self::LOG_FORMATS, 'notCovered' => self::LOG_NOT_COVERED],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $findings === [] ? self::SUCCESS : self::FAILURE;
        }

        if ($findings === []) {
            $this->info('Ningún fichero con credenciales está expuesto.');
            $this->logScanNote();

            return self::SUCCESS;
        }

        $this->error(sprintf('%d fichero(s) con credenciales al alcance de quien no debe:', count($findings)));
        $this->newLine();
        // Solo NOMBRES de claves: el valor no sale nunca de esta orden.
        $this->table(
            ['problema', 'permisos', 'fichero', 'claves'],
            array_map(fn (array $f) => [$f['issue'], $f['permissions'], $f['path'], (($f['keys'] ?? []) === [] ? '—' : implode(', ', $f['keys']))
                .(isset($f['unscannedLines']) ? ' · '.$f['unscannedLines'].' línea(s) sin revisar' : '')], $findings),
        );
        $this->newLine();
        $this->line('  Arreglo: <fg=yellow>chmod 600 <fichero></> y mover los respaldos fuera del directorio de la aplicación.');
        $this->line('  La configuración compilada se vuelve a escribir en cada <fg=yellow>config:cache</>:');
        $this->line('  el chmod hay que repetirlo ahí, no una sola vez.');
        $this->logScanNote();

        return self::FAILURE;
    }

    /** SC-12: lo que mira y lo que NO mira el rastreo de logs, siempre (también en verde). */
    private function logScanNote(): void
    {
        $this->line('  Logs: solo '.self::LOG_FILES.' legibles por cualquiera. Reconoce: '.implode('; ', self::LOG_FORMATS).'.');
        $this->line('  NO mira: '.implode('; ', self::LOG_NOT_COVERED).'.');
    }

    /**
     * La configuración compilada (`bootstrap/cache/config.php`) legible por
     * «otros».
     *
     * No se comprueba si "contiene secretos": si existe, los contiene. Es el
     * volcado literal de `config()` con los valores ya resueltos desde el .env.
     *
     * @return array<int, array<string,string>>
     */
    private function worldReadableCompiledConfig(): array
    {
        $path = base_path('bootstrap/cache/config.php');

        if (! is_file($path)) {
            return []; // sin cachear no hay copia que proteger
        }

        $perms = fileperms($path);

        if (($perms & 0004) === 0) {
            return [];
        }

        return [[
            'issue' => 'configuración compilada legible por cualquier usuario',
            'permissions' => substr(sprintf('%o', $perms), -4),
            'path' => $path,
        ]];
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

            $keys = $this->realSecretKeys($path);
            if ($keys === []) {
                continue; // un .env.example sin valores no es un problema
            }

            $findings[] = [
                'issue' => 'legible por cualquier usuario',
                'permissions' => substr(sprintf('%o', $perms), -4),
                'path' => $path,
                'keys' => $keys,
            ];
        }

        return $findings;
    }

    /**
     * Auditoría B-T17: logs legibles por «otros» que llevan una credencial.
     *
     * No se marca un log por ser legible (los canales `channel` y `voice` se
     * crean en 0664 a propósito: php-fpm, los workers y el programador escriben
     * en el mismo fichero): se marca si además lleva una clave vigilada CON
     * valor real en una línea suya, la forma en que un volcado del entorno
     * acaba en un log. Se lee línea a línea, sin cargar el fichero en memoria.
     *
     * @return array<int, array<string,mixed>>
     */
    private function worldReadableLogsWithSecrets(): array
    {
        $findings = [];

        foreach ((array) glob(storage_path('logs').'/*.log') as $path) {
            if (! is_file($path) || ! is_readable($path)) {
                continue;
            }

            $perms = fileperms($path);
            if (($perms & 0004) === 0) {
                continue; // solo lo leen su dueño y su grupo
            }

            [$keys, $sinRevisar] = $this->realSecretKeysInLogLines($path);
            if ($keys === [] && $sinRevisar === 0) {
                continue;
            }

            $finding = [
                // Una línea que no se pudo revisar no pasa por limpia: el log sale igual, con cuántas fueron.
                'issue' => $keys !== [] ? 'log legible por cualquier usuario con credenciales' : 'log legible por cualquier usuario que no se pudo revisar entero',
                'permissions' => substr(sprintf('%o', $perms), -4),
                'path' => $path,
                'keys' => $keys,
            ];
            if ($sinRevisar > 0) {
                $finding['unscannedLines'] = $sinRevisar;
            }
            $findings[] = $finding;
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

    /**
     * Los NOMBRES de las claves sensibles con un valor de verdad en un fichero
     * de entorno (ordenados, sin repetir; nunca sus valores).
     *
     * @return list<string>
     */
    private function realSecretKeys(string $path): array
    {
        $contents = @file_get_contents($path);
        if ($contents === false) {
            return [];
        }

        return $this->realSecretKeysIn($contents);
    }

    /**
     * Las claves de un LOG, línea a línea (los logs pueden pesar cientos de MB),
     * con todas las formas de {@see logLinePatterns()}, y cuántas líneas no se
     * pudieron revisar (un fallo de PCRE, p. ej. el límite de retroceso en una
     * línea enorme: no se da por limpia).
     *
     * @return array{0: list<string>, 1: int}
     */
    private function realSecretKeysInLogLines(string $path): array
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return [[], 0];
        }

        $patterns = self::logLinePatterns();
        $terminals = self::keyTerminals();
        $keys = [];
        $unscanned = 0;
        try {
            while (($line = fgets($handle)) !== false) {
                // Filtro barato: toda clave vigilada termina en una de estas palabras (KEY, PASSWORD, TOKEN, SECRET).
                if (! self::containsAny($line, $terminals)) {
                    continue;
                }
                foreach ($patterns as $pattern) {
                    if (preg_match_all($pattern, $line, $matches, PREG_SET_ORDER) === false) {
                        $unscanned++;

                        break;
                    }
                    foreach ($matches as $m) {
                        // `rtrim('\\')`: el cierre de un JSON escapado deja su barra pegada al valor.
                        if (self::isRealValue(trim(rtrim($m[2], '\\')))) {
                            $keys[$m[1]] = true;
                        }
                    }
                }
            }
        } finally {
            fclose($handle);
        }

        $keys = array_keys($keys);
        sort($keys, SORT_STRING);

        return [$keys, $unscanned];
    }

    /**
     * Las claves sensibles con un valor de verdad en un texto: TODAS sus
     * apariciones (una clave repetida con un ejemplo delante y el secreto
     * detrás también cuenta).
     *
     * @return list<string>
     */
    private function realSecretKeysIn(string $contents): array
    {
        if (preg_match_all(self::secretAssignmentPattern(), $contents, $matches, PREG_SET_ORDER) < 1) {
            return [];
        }

        $keys = [];
        foreach ($matches as $m) {
            if (self::isRealValue(trim($m[2]))) {
                $keys[$m[1]] = true;
            }
        }

        $keys = array_keys($keys);
        sort($keys, SORT_STRING);

        return $keys;
    }

    /**
     * `CLAVE=valor` al principio de una línea, con `export ` delante o sin él,
     * para las claves de {@see SECRET_KEYS} y las de las familias de la voz
     * ({@see SECRET_KEY_FAMILIES} + `_…` + uno de {@see SECRET_SUFFIXES}).
     *
     * Espacio HORIZONTAL únicamente ([ \t]), nunca \s: en PCRE \s incluye el
     * salto de línea, así que una clave vacía —«APP_KEY=» seguida de otra
     * línea— capturaba el valor de la línea siguiente y marcaba como secreto un
     * fichero de ejemplo perfectamente limpio. Un detector que grita en falso
     * se acaba ignorando.
     */
    private static function secretAssignmentPattern(): string
    {
        return '/^[ \t]*(?:export[ \t]+)?('.self::keyAlternation().')[ \t]*=[ \t]*"?([^"\r\n#]*)"?/m';
    }

    /**
     * SC-12 del cierre: las formas de un secreto en una línea de LOG, además de
     * {@see secretAssignmentPattern()} (al principio de la línea). Grupo 1, la
     * clave; grupo 2, el valor (`(?|…)`: cada alternativa reusa los números).
     * La clave va ENTERA y en mayúsculas, como en el entorno: no puede ir pegada
     * a otra palabra (`PUSHER_APP_KEY` no es `APP_KEY`; `$APP_KEY`, una
     * variable, tampoco).
     *
     *  1. `CLAVE=valor` en cualquier punto: entre comillas (con espacios o sin
     *     ellos alrededor del `=`), o sin comillas PEGADO al `=` y hasta un
     *     espacio, una comilla o un separador. Nunca `==` ni `=>`. Sin comillas y
     *     separado del `=`, el valor sería la palabra que sigue
     *     (`faltan APP_KEY= y DB_PASSWORD=`): no se toma (lo dice
     *     {@see LOG_NOT_COVERED}); al principio de la línea lo ve el patrón del
     *     entorno.
     *  2. `"CLAVE":"valor"` (JSON), con o sin espacios y también con las
     *     comillas escapadas de un JSON dentro de otro (`\"CLAVE\":\"valor\"`).
     *  3. `'CLAVE' => 'valor'` (`var_export`) y `[CLAVE] => valor` (`print_r`).
     *
     * Como en el entorno, espacio HORIZONTAL únicamente ([ \t]) y nada cruza
     * un salto de línea: un valor vacío no se lleva el de la línea siguiente.
     *
     * @return list<string>
     */
    private static function logLinePatterns(): array
    {
        $key = '('.self::keyAlternation().')';

        return [
            self::secretAssignmentPattern(),
            '/(?<![A-Za-z0-9_$])'.$key.'[ \t]*=(?![=>])(?|[ \t]*"([^"\r\n]*)"|[ \t]*\'([^\'\r\n]*)\'|([^\s"\'`,;&)}\]>]*))/',
            '/\\\\?"'.$key.'\\\\?"[ \t]*:[ \t]*\\\\?"([^"\r\n]*)"/',
            '/(?|\''.$key.'\'|"'.$key.'"|\['.$key.'\])[ \t]*=>[ \t]*(?|\'([^\'\r\n]*)\'|"([^"\r\n]*)"|([^\s,\'"\])]*))/',
        ];
    }

    /**
     * Las claves vigiladas, como alternancia de PCRE: las de {@see SECRET_KEYS}
     * y las de las familias de la voz ({@see SECRET_KEY_FAMILIES} + `_…` + uno
     * de {@see SECRET_SUFFIXES}).
     */
    private static function keyAlternation(): string
    {
        $quote = static fn (string $k): string => preg_quote($k, '/');
        $explicit = implode('|', array_map($quote, self::SECRET_KEYS));
        $families = '(?:'.implode('|', array_map($quote, self::SECRET_KEY_FAMILIES)).')(?:_[A-Z0-9]+)*?_(?:'
            .implode('|', array_map($quote, self::SECRET_SUFFIXES)).')';

        return $explicit.'|'.$families;
    }

    /**
     * La última palabra de cada clave vigilada (`KEY`, `PASSWORD`, `TOKEN`,
     * `SECRET`): una línea que no lleva ninguna no puede llevar una clave
     * vigilada. Sale de las mismas listas: una clave nueva con otra terminación
     * entra sola en el filtro.
     *
     * @return list<string>
     */
    private static function keyTerminals(): array
    {
        $last = static fn (string $k): string => substr($k, (int) strrpos('_'.$k, '_'));

        return array_values(array_unique(array_map($last, [...self::SECRET_KEYS, ...self::SECRET_SUFFIXES])));
    }

    /** @param  list<string>  $needles */
    private static function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** Un valor de verdad: ni vacío (una clave declarada sin valor es un ejemplo) ni uno de {@see PLACEHOLDERS}. */
    private static function isRealValue(string $value): bool
    {
        if ($value === '') {
            return false;
        }
        foreach (self::PLACEHOLDERS as $placeholder) {
            if (stripos($value, $placeholder) === 0) {
                return false;
            }
        }

        return true;
    }
}
