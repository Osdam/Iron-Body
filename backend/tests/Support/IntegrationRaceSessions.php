<?php

namespace Tests\Support;

use App\Models\Admin;
use App\Services\Admin\AdminSessionService;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Feature\UltronStudio\PublicationRaceSessions;

/**
 * SESIONES RIVALES DE VERDAD para las carreras de las integraciones del Studio
 * (contrato H6 §I11, fila «PostgreSQL»). Calcado de
 * {@see PublicationRaceSessions} (H5): cada sesión
 * es un PROCESO HIJO con su propia conexión a PostgreSQL que arranca la
 * aplicación entera y manda la petición por el kernel HTTP (middleware,
 * cupos, sesión del personal por token, validación, controlador, servicio y
 * transacción reales). Un 23505 aborta una TRANSACCIÓN de verdad.
 *
 * Coordinación, toda en la base (memoria «artisan serve: workers y
 * --no-reload»):
 *  - BARRERA: la prueba toma un cerrojo consultivo en exclusiva; cada hijo,
 *    ya arrancado, lo pide compartido y espera. Cuando `pg_locks` enseña a
 *    todos esperando, la prueba lo suelta y salen a la vez.
 *  - PAUSAS antes de la primera sentencia que contiene unos fragmentos (dentro
 *    o fuera de una transacción), cada una una vez: hasta que N sesiones
 *    esperen un cerrojo suyo (`pg_blocking_pids` + escalón de 40 ms), hasta
 *    que la prueba suelte otro cerrojo, o solo AVISAR (`signal`: escribe la
 *    marca y sigue; para saber que una sesión llegó a un punto que no espera
 *    cerrojos de la base, como el cerrojo de la caché).
 *  - RIVAL CRUDO (`raw`): una transacción con sentencias dadas que se confirma
 *    cuando otra sesión espera sus cerrojos.
 *
 * Lo que añade al del H5, porque el H6 lo necesita:
 *  - la CONFIGURACIÓN de las integraciones (`ultron_studio`, `meta`, `otp`,
 *    `voice`, `marketing.ultron` y `cache.default`) pasa entera al hijo, con
 *    retoques por sesión (`config`): una sesión puede arrancar con OTRA
 *    configuración (un proceso sin reiniciar frente a otro ya desplegado);
 *  - `inherit: false` + `env`: el hijo NO recibe la configuración de la prueba
 *    y la calcula de verdad desde su entorno (`config/*.php`), para la centinela;
 *  - `http`: un `Http::fake` DENTRO del hijo (la sonda de Twilio no puede salir
 *    a la red) con `preventStrayRequests`, y el recuento de lo que intentó;
 *  - `artisan`: un comando en lugar de una petición (el de instantáneas);
 *  - el log del hijo (`MessageLogged`) y su stderr vuelven en el resultado.
 *
 * La caché de los hijos es la de la prueba (`array`: una por proceso) salvo que
 * se pida `cache.default = database`, la de producción (hecho al congelar el
 * H6): la sonda se pone en fila con `Cache::lock` y ese cerrojo solo existe
 * entre procesos con una caché compartida.
 *
 * Los hijos ven lo que la prueba CONFIRMA: la prueba no envuelve nada en una
 * transacción y, al terminar, pide que la siguiente vuelva a crear el esquema
 * (las tablas del Studio prohíben DELETE y TRUNCATE). El reloj de cada hijo es
 * el de la prueba, en UTC.
 *
 * Diagnóstico opcional: `ULTRON_STUDIO_RACE_LOG=<fichero>` (una línea JSON por
 * sesión) y `ULTRON_STUDIO_RACE_ISOLATION='repeatable read'` (todas las
 * sesiones con ese nivel por defecto, como un `default_transaction_isolation`
 * en el rol).
 */
trait IntegrationRaceSessions
{
    /** @var array<int, array{proc: resource, pipes: array<int, resource>, label: string, out: string, err: string, lines: list<string>, result: array<string, mixed>|null}> */
    private array $racers = [];

    /** Los subárboles de configuración que leen las integraciones: los hijos arrancan con los de la prueba. */
    private const RACE_CONFIG_TREES = ['ultron_studio', 'meta', 'otp', 'voice', 'marketing.ultron', 'cache.default'];

    /**
     * El proceso hijo. Lee su orden de `ULTRON_STUDIO_RACE_SESSION` y escribe en
     * stdout una línea por hito (`READY`, `PAUSED…`, `HOLDING`) y al final
     * `RESULT {json}`.
     */
    private const RACE_WORKER = <<<'PHP'
$o = json_decode((string) getenv('ULTRON_STUDIO_RACE_SESSION'), true, 512, JSON_THROW_ON_ERROR);
$out = static function (string $linea): void {
    fwrite(STDOUT, $linea."\n");
    fflush(STDOUT);
};
$r = ['label' => $o['label'], 'pid' => null, 'isolation' => null, 'status' => null, 'json' => null, 'body' => null, 'exit' => null, 'output' => null,
    'paused' => false, 'pausedAt' => [], 'waiters' => null, 'http' => [], 'logs' => [], 'error' => null, 't' => []];
try {
    require $o['base'].'/vendor/autoload.php';
    $app = require $o['base'].'/bootstrap/app.php';
    $esComando = $o['artisan'] !== null;
    $kernel = $app->make($esComando ? Illuminate\Contracts\Console\Kernel::class : Illuminate\Contracts\Http\Kernel::class);
    $kernel->bootstrap();
    foreach ($o['config'] as $clave => $valor) {
        config([$clave => $valor]);
    }
    $conexion = config('database.default');
    if ($o['isolation'] !== null) {
        config(["database.connections.{$conexion}.isolation_level" => $o['isolation']]);
    }
    Illuminate\Support\Facades\DB::purge($conexion);
    app('cache')->forgetDriver(config('cache.default'));
    if ($o['now'] !== null) {
        Illuminate\Support\Carbon::setTestNow(Illuminate\Support\Carbon::parse($o['now'])->utc());
        Carbon\CarbonImmutable::setTestNow(Carbon\CarbonImmutable::parse($o['now'])->utc());
    }
    Illuminate\Support\Facades\Event::listen(Illuminate\Log\Events\MessageLogged::class, static function (Illuminate\Log\Events\MessageLogged $m) use (&$r): void {
        $r['logs'][] = $m->level.' '.$m->message.' '.json_encode($m->context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    });
    if ($o['http'] !== null) {
        // La sonda no sale nunca a la red desde una prueba: todo lo que intente queda grabado.
        Illuminate\Support\Facades\Http::preventStrayRequests();
        Illuminate\Support\Facades\Http::fake(static fn () => Illuminate\Support\Facades\Http::response($o['http']['body'], $o['http']['status']));
    }
    $db = Illuminate\Support\Facades\DB::connection($conexion);
    $db->statement("SET lock_timeout = '60s'");
    $r['pid'] = (int) $db->selectOne('select pg_backend_pid() as p')->p;
    $r['isolation'] = (string) $db->selectOne('show default_transaction_isolation')->default_transaction_isolation;
    $cfg = $db->getConfig();
    $monitor = static fn (): PDO => new PDO(sprintf('pgsql:host=%s;port=%s;dbname=%s', $cfg['host'], $cfg['port'], $cfg['database']), $cfg['username'], $cfg['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    // ¿Cuántas sesiones esperan un cerrojo de esta? (`pg_blocking_pids`): hasta
    // que sean `$minimo` o pasen `$segundos`.
    $esperaQueMeEsperen = static function (float $segundos, int $minimo = 1) use ($monitor, &$r): int {
        $m = $monitor();
        $q = $m->prepare('select count(*) from pg_stat_activity where pid <> ? and ? = any(pg_blocking_pids(pid))');
        $hasta = microtime(true) + $segundos;
        $n = 0;
        while (microtime(true) < $hasta) {
            $q->execute([$r['pid'], $r['pid']]);
            $n = (int) $q->fetchColumn();
            if ($n >= $minimo) {
                break;
            }
            usleep(10000);
        }
        // El escalón: quien espera ya está dentro de su espera, no a punto de entrar.
        usleep(40000);

        return $n;
    };

    if ($o['barrier'] !== null) {
        $out('READY');
        $db->select('select pg_advisory_lock_shared(?)', [$o['barrier']]);
        $db->select('select pg_advisory_unlock_shared(?)', [$o['barrier']]);
    }
    $r['t']['start'] = microtime(true);

    $pausas = $o['pauses'];
    if ($pausas !== []) {
        $hechas = array_fill(0, count($pausas), false);
        $db->beforeExecuting(function (string $sql) use (&$hechas, &$r, $pausas, $db, $out, $monitor, $esperaQueMeEsperen): void {
            foreach ($pausas as $k => $p) {
                if ($hechas[$k]) {
                    continue;
                }
                foreach ($p['before'] as $fragmento) {
                    if (! str_contains($sql, $fragmento)) {
                        continue 2;
                    }
                }
                if ($p['inTransaction'] !== null && ($db->transactionLevel() > 0) !== $p['inTransaction']) {
                    continue;
                }
                $hechas[$k] = true;
                $r['paused'] = true;
                $r['pausedAt'][] = $k;
                $out($k === 0 ? 'PAUSED' : "PAUSED{$k}");
                if ($p['until'] === 'blocked') {
                    $r['waiters'] = $esperaQueMeEsperen((float) $p['timeout'], (int) $p['minWaiters']);
                } elseif ($p['until'] === 'released') {
                    $m = $monitor();
                    $m->prepare('select pg_advisory_lock_shared(?)')->execute([$p['key']]);
                    $m->prepare('select pg_advisory_unlock_shared(?)')->execute([$p['key']]);
                }

                return;
            }
        });
    }

    if ($o['raw'] !== null) {
        $db->beginTransaction();
        foreach ($o['raw']['statements'] as [$sql, $bindings]) {
            $db->statement($sql, $bindings);
        }
        $out('HOLDING');
        $r['waiters'] = $esperaQueMeEsperen((float) $o['raw']['timeout']);
        $db->commit();
        $r['status'] = 0;
    } elseif ($esComando) {
        $r['exit'] = $kernel->call($o['artisan']['command'], $o['artisan']['params']);
        $r['output'] = $kernel->output();
    } else {
        $q = $o['request'];
        $peticion = Illuminate\Http\Request::create($q['uri'], $q['method'], [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$q['token'],
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => '127.0.0.1',
        ], $q['body'] === null ? null : json_encode($q['body'], JSON_THROW_ON_ERROR));
        $respuesta = $kernel->handle($peticion);
        $r['status'] = $respuesta->getStatusCode();
        $r['body'] = (string) $respuesta->getContent();
        $r['json'] = json_decode($r['body'], true);
        $kernel->terminate($peticion, $respuesta);
    }
    if ($o['http'] !== null) {
        foreach (Illuminate\Support\Facades\Http::recorded() as [$peticionSaliente]) {
            $r['http'][] = $peticionSaliente->method().' '.$peticionSaliente->url();
        }
    }
    $r['t']['end'] = microtime(true);
} catch (Throwable $e) {
    $r['error'] = ['class' => get_class($e), 'message' => mb_substr($e->getMessage(), 0, 1500)];
}
$out('RESULT '.json_encode($r, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_UNICODE));
PHP;

    /** La configuración de las integraciones tal como la tiene ahora la prueba. */
    protected function raceConfig(): array
    {
        $c = [];
        foreach (self::RACE_CONFIG_TREES as $clave) {
            $c[$clave] = config($clave);
        }

        return $c;
    }

    /** Una sesión del personal NUEVA para un hijo (cada pestaña, su token). */
    protected function raceToken(Admin $admin): string
    {
        return app(AdminSessionService::class)->issueSession($admin)['token'];
    }

    /**
     * Una petición a la API del Studio para un hijo.
     *
     * @param  array<string, mixed>|null  $body
     * @return array{method:string,uri:string,token:string,body:array<string,mixed>|null}
     */
    protected function raceRequest(string $method, string $path, Admin $as, ?array $body = null, ?string $token = null): array
    {
        return ['method' => $method, 'uri' => '/api/admin/ultron-studio/'.ltrim($path, '/'), 'token' => $token ?? $this->raceToken($as), 'body' => $body];
    }

    /**
     * Pausa ANTES de la primera sentencia que contenga todos los fragmentos
     * (`$inTransaction`: true dentro de una transacción, false fuera, null da
     * igual). `until`: 'blocked' (hasta que `$minWaiters` sesiones esperen un
     * cerrojo de esta, con tope `$timeout`), 'released' (hasta que la prueba
     * suelte el cerrojo `$key`) o 'signal' (solo escribe la marca y sigue).
     *
     * @param  list<string>  $before
     * @return array<string, mixed>
     */
    protected static function pauseBefore(array $before, ?bool $inTransaction, string $until, ?int $key = null, float $timeout = 15.0, int $minWaiters = 1): array
    {
        return ['before' => $before, 'inTransaction' => $inTransaction, 'until' => $until, 'key' => $key, 'timeout' => $timeout, 'minWaiters' => $minWaiters];
    }

    /**
     * Lanza una sesión rival. `request`, `raw` o `artisan`, uno de los tres.
     *
     * Opciones: `barrier` (?int), `pause` (una pausa o varias, {@see pauseBefore()}),
     * `isolation` (?string), `config` (retoques de esta sesión), `inherit`
     * (false: sin la configuración de la prueba), `env` (variables de entorno
     * extra), `http` ({status, body}: el falso de la red dentro del hijo),
     * `raw` ({statements, timeout}) y `artisan` ({command, params}).
     *
     * @param  array<string, mixed>|null  $request  {@see raceRequest()}
     * @param  array<string, mixed>  $opts
     */
    protected function spawnRacer(string $label, ?array $request, array $opts = []): int
    {
        $porDefecto = getenv('ULTRON_STUDIO_RACE_ISOLATION');
        $isolation = $opts['isolation'] ?? (is_string($porDefecto) && $porDefecto !== '' ? $porDefecto : null);
        $pausa = $opts['pause'] ?? null;
        $config = (($opts['inherit'] ?? true) ? $this->raceConfig() : []) + [];
        foreach ($opts['config'] ?? [] as $clave => $valor) {
            $config[$clave] = $valor;
        }
        $orden = [
            'label' => $label,
            'base' => base_path(),
            'now' => now()->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'config' => $config,
            'barrier' => $opts['barrier'] ?? null,
            'pauses' => $pausa === null ? [] : (array_is_list($pausa) ? $pausa : [$pausa]),
            'isolation' => $isolation,
            'request' => $request,
            'raw' => $opts['raw'] ?? null,
            'artisan' => $opts['artisan'] ?? null,
            'http' => $opts['http'] ?? null,
        ];
        $env = ['ULTRON_STUDIO_RACE_SESSION' => json_encode($orden, JSON_THROW_ON_ERROR), 'PGGSSENCMODE' => 'disable'] + ($opts['env'] ?? []) + getenv();

        $proc = proc_open([PHP_BINARY, '-d', 'memory_limit=512M', '-r', self::RACE_WORKER], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env);
        if (! is_resource($proc)) {
            throw new RuntimeException("No se pudo lanzar la sesión {$label}.");
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $this->racers[] = ['proc' => $proc, 'pipes' => $pipes, 'label' => $label, 'out' => '', 'err' => '', 'lines' => [], 'result' => null];

        return array_key_last($this->racers);
    }

    /** Lee lo que haya en las tuberías de un hijo (sin bloquear más de `$wait` segundos). */
    private function pumpRacer(int $i, float $wait): void
    {
        $s = &$this->racers[$i];
        $leer = array_values(array_filter([$s['pipes'][1] ?? null, $s['pipes'][2] ?? null], static fn ($p): bool => is_resource($p) && ! feof($p)));
        if ($leer === []) {
            return;
        }
        $nada = null;
        $seg = (int) floor($wait);
        if (@stream_select($leer, $nada, $nada, $seg, (int) (($wait - $seg) * 1_000_000)) < 1) {
            return;
        }
        foreach ($leer as $tubo) {
            $trozo = (string) fread($tubo, 65536);
            if ($tubo === $s['pipes'][1]) {
                $s['out'] .= $trozo;
                while (($nl = strpos($s['out'], "\n")) !== false) {
                    $linea = substr($s['out'], 0, $nl);
                    $s['out'] = substr($s['out'], $nl + 1);
                    $s['lines'][] = $linea;
                    if (str_starts_with($linea, 'RESULT ')) {
                        $s['result'] = json_decode(substr($linea, 7), true);
                    }
                }
            } else {
                $s['err'] .= $trozo;
            }
        }
    }

    /** Espera a que el hijo escriba la línea `$marca` (READY, PAUSED, HOLDING). */
    protected function awaitRacerLine(int $i, string $marca, float $timeout = 60.0): void
    {
        $hasta = microtime(true) + $timeout;
        while (microtime(true) < $hasta) {
            if (in_array($marca, $this->racers[$i]['lines'], true)) {
                return;
            }
            if ($this->racers[$i]['result'] !== null) {
                throw new RuntimeException("La sesión {$this->racers[$i]['label']} terminó sin llegar a {$marca}: ".json_encode($this->racers[$i]['result']).' '.$this->racers[$i]['err']);
            }
            $this->pumpRacer($i, 0.05);
        }

        throw new RuntimeException("La sesión {$this->racers[$i]['label']} no llegó a {$marca} en {$timeout} s: ".implode(' | ', $this->racers[$i]['lines']).' '.$this->racers[$i]['err']);
    }

    /**
     * Espera a que el hijo termine y devuelve su resultado (`status`, `json`,
     * `body`, `pid`, `waiters`, `paused`, `http`, `logs`, `exit`, `output`,
     * `error`) más su stderr.
     *
     * @return array<string, mixed>
     */
    protected function collectRacer(int $i, float $timeout = 90.0): array
    {
        $hasta = microtime(true) + $timeout;
        while ($this->racers[$i]['result'] === null && microtime(true) < $hasta) {
            $this->pumpRacer($i, 0.05);
            $estado = proc_get_status($this->racers[$i]['proc']);
            if (! $estado['running']) {
                // Hasta EOF: la última línea puede pasar de los 64 KB de cada lectura
                // (un 500 con su traza de depuración).
                while ($this->racers[$i]['result'] === null && array_filter([$this->racers[$i]['pipes'][1] ?? null, $this->racers[$i]['pipes'][2] ?? null], static fn ($p): bool => is_resource($p) && ! feof($p)) !== [] && microtime(true) < $hasta) {
                    $this->pumpRacer($i, 0.2);
                }
                break;
            }
        }
        $s = $this->racers[$i];
        if ($s['result'] === null) {
            throw new RuntimeException("La sesión {$s['label']} no devolvió resultado: ".implode(' | ', $s['lines']).' '.$s['err']);
        }
        $rastro = getenv('ULTRON_STUDIO_RACE_LOG');
        if (is_string($rastro) && $rastro !== '') {
            file_put_contents($rastro, json_encode([
                'test' => $this->nameWithDataSet(),
                'label' => $s['label'],
                'pid' => $s['result']['pid'] ?? null,
                'isolation' => $s['result']['isolation'] ?? null,
                'status' => $s['result']['status'] ?? null,
                'code' => $s['result']['json']['code'] ?? (($s['result']['json']['data']['replayed'] ?? false) ? 'replayed' : (($s['result']['json']['data']['reused'] ?? false) ? 'reused' : null)),
                'currentStatus' => $s['result']['json']['currentStatus'] ?? null,
                'waiters' => $s['result']['waiters'] ?? null,
                'http' => count($s['result']['http'] ?? []),
            ], JSON_UNESCAPED_UNICODE)."\n", FILE_APPEND);
        }

        return $s['result'] + ['stderr' => trim($s['err'])];
    }

    /**
     * Todas las que quedan, en orden de lanzamiento.
     *
     * @param  list<int>  $ids
     * @return list<array<string, mixed>>
     */
    protected function collectRacers(array $ids, float $timeout = 120.0): array
    {
        $hasta = microtime(true) + $timeout;

        return array_map(fn (int $i): array => $this->collectRacer($i, max(1.0, $hasta - microtime(true))), $ids);
    }

    /** La prueba toma el cerrojo de la barrera (o de una pausa) en exclusiva, en SU sesión. */
    protected function holdLock(int $key): void
    {
        DB::select('select pg_advisory_lock(?)', [$key]);
    }

    protected function releaseLock(int $key): void
    {
        DB::select('select pg_advisory_unlock(?)', [$key]);
    }

    /** Espera a que `$n` sesiones estén pidiendo el cerrojo `$key` (o falla). */
    protected function awaitLockWaiters(int $key, int $n, float $timeout = 90.0): void
    {
        $hasta = microtime(true) + $timeout;
        $vistos = 0;
        while (microtime(true) < $hasta) {
            $vistos = (int) DB::scalar("select count(*) from pg_locks where locktype = 'advisory' and not granted and objid = ? and objsubid = 1 and database = (select oid from pg_database where datname = current_database())", [$key]);
            if ($vistos >= $n) {
                return;
            }
            foreach (array_keys($this->racers) as $i) {
                $this->pumpRacer($i, 0.0);
                if ($this->racers[$i]['result'] !== null && ! in_array('READY', $this->racers[$i]['lines'], true)) {
                    throw new RuntimeException("La sesión {$this->racers[$i]['label']} terminó antes de la barrera: ".json_encode($this->racers[$i]['result']).' '.$this->racers[$i]['err']);
                }
            }
            usleep(20000);
        }

        throw new RuntimeException("Solo {$vistos} de {$n} sesiones llegaron al cerrojo {$key} en {$timeout} s.");
    }

    /**
     * N sesiones que salen A LA VEZ: se lanzan todas, esperan en la barrera y
     * la prueba la suelta cuando las N esperan. Devuelve sus resultados.
     *
     * @param  list<array{0:string,1:array<string,mixed>|null,2?:array<string,mixed>}>  $sesiones  [etiqueta, petición, opciones]
     * @return list<array<string, mixed>>
     */
    protected function raceAll(array $sesiones, int $barrier): array
    {
        $this->holdLock($barrier);
        $ids = [];
        try {
            foreach ($sesiones as $s) {
                $ids[] = $this->spawnRacer($s[0], $s[1], ['barrier' => $barrier] + ($s[2] ?? []));
            }
            $this->awaitLockWaiters($barrier, count($ids));
        } finally {
            $this->releaseLock($barrier);
        }

        return $this->collectRacers($ids);
    }

    /** Mata lo que quede vivo y suelta los cerrojos de la sesión de la prueba. */
    protected function stopRacers(): void
    {
        foreach ($this->racers as $s) {
            foreach ($s['pipes'] as $tubo) {
                if (is_resource($tubo)) {
                    fclose($tubo);
                }
            }
            if (is_resource($s['proc'])) {
                $estado = proc_get_status($s['proc']);
                if ($estado['running']) {
                    proc_terminate($s['proc'], 9);
                }
                proc_close($s['proc']);
            }
        }
        $this->racers = [];
        try {
            DB::select('select pg_advisory_unlock_all()');
        } catch (\Throwable) {
            // Sin conexión no hay cerrojos de sesión que soltar.
        }
    }
}
