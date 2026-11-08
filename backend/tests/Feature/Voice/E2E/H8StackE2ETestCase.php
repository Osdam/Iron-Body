<?php

namespace Tests\Feature\Voice\E2E;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use JsonException;
use PDO;
use PDOException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Base del E2E del Hito 8 con la PILA REAL (TEST8-b; contrato H8, «Orden y puertas», paso 3; Enmienda 6, E6-11).
 *
 * NO ES UNA PRUEBA EN PROCESO: cada paso entra por donde entra la realidad contra una pila levantada por
 * `docs/ultron-studio/ops/h8-e2e.sh` en una COPIA del árbol (la cerrada, idéntica al commit, o la abierta del banco,
 * con `CALLS_ENABLED_IN_CODE = true`, protección 2): el servidor (`artisan serve`), el worker real de la cola `voice`,
 * PostgreSQL con el modelo de roles de producción y los simuladores de ElevenLabs y Twilio de OPS8 (`ops/banco-h8/`),
 * con los adaptadores REALES apuntados a ellos (nunca los nulos). Las peticiones son HTTP de verdad con el cliente de
 * Laravel; lo que queda en la base se comprueba con SQL (PDO a la base del banco); lo que vieron los proveedores, con el
 * control GET de los simuladores.
 *
 * SIN EL ARNÉS NO CORRE: sin `H8_E2E_CONTEXT` (el contexto que escribe el arnés, en 600) cada prueba se marca
 * «skipped» con el motivo. No es un verde: en la suite normal no hay pila que probar. El resultado del E2E es el de la
 * ejecución del arnés (su JUnit, `checks-*.jsonl` y el hash de resultados), nunca el de la suite.
 *
 * CERO RED: `Http::preventStrayRequests()` y solo se permiten los cuatro puertos del banco en 127.0.0.1 (la API, los
 * dos simuladores y la valla). Los procesos de PHP de la copia salen por la valla (proxy que bloquea y anota).
 *
 * COMPROBACIONES: `check()` es blanda (se anota y el paso sigue; al final, `finish()` falla con la lista) y `must()` es
 * dura (para el paso: lo que viene detrás depende de ella). Cada una queda en `checks-<fase>.jsonl` con su nombre y su
 * detalle (respuestas sin secretos: el arnés barre la evidencia en busca de las piezas de la ejecución).
 */
abstract class H8StackE2ETestCase extends TestCase
{
    /** `cerrada` o `abierta`: la copia del árbol para la que vale la clase. */
    protected const PHASE = '';

    /** @var array<string, mixed>|null */
    protected static ?array $ctx = null;

    /** Lo que un paso deja al siguiente (los pasos de una fase corren en orden, en un proceso). @var array<string, mixed> */
    protected static array $s = [];

    private static ?PDO $pdo = null;

    /** @var list<string> */
    private array $fallos = [];

    protected function setUp(): void
    {
        parent::setUp();
        $ruta = getenv('H8_E2E_CONTEXT');
        if (! is_string($ruta) || $ruta === '' || ! is_file($ruta)) {
            $this->markTestSkipped('E2E del H8: solo con el arnés docs/ultron-studio/ops/h8-e2e.sh (pila real, simuladores y una copia del árbol).');
        }
        self::$ctx ??= json_decode((string) file_get_contents($ruta), true, 64, JSON_THROW_ON_ERROR);
        if ((self::$ctx['phase'] ?? null) !== static::PHASE) {
            $this->markTestSkipped('E2E del H8: este contexto es de la fase «'.(self::$ctx['phase'] ?? '?').'», no de «'.static::PHASE.'».');
        }

        $puertos = [self::$ctx['ports']['api'], self::$ctx['ports']['elevenlabs'], self::$ctx['ports']['twilio'], self::$ctx['ports']['valla']];
        Http::preventStrayRequests();
        Http::allowStrayRequests(array_map(static fn (int $p): string => "http://127.0.0.1:{$p}/*", $puertos));
        $this->fallos = [];
    }

    // ── Comprobaciones ──────────────────────────────────────────────────────

    /** Blanda: se anota y el paso sigue. */
    protected function check(string $nombre, bool $ok, mixed $detalle = null): bool
    {
        $this->anotar($nombre, $ok, $detalle);
        if (! $ok) {
            $this->fallos[] = $nombre.' — '.self::corto($detalle);
        }
        $this->addToAssertionCount(1);

        return $ok;
    }

    /** Dura: si falla, el paso se para (con lo blando anotado hasta ahí). */
    protected function must(string $nombre, bool $ok, mixed $detalle = null): void
    {
        $this->anotar($nombre, $ok, $detalle);
        if (! $ok) {
            $this->fail(implode("\n", [...$this->fallos, 'PARADA: '.$nombre.' — '.self::corto($detalle)]));
        }
        $this->addToAssertionCount(1);
    }

    /** Al final de cada paso: falla con todas las blandas que no se cumplieron. */
    protected function finish(): void
    {
        $this->assertSame([], $this->fallos, "Comprobaciones que no se cumplieron:\n".implode("\n", $this->fallos));
    }

    private function anotar(string $nombre, bool $ok, mixed $detalle): void
    {
        $linea = json_encode([
            'phase' => static::PHASE,
            'step' => $this->name(),
            'check' => $nombre,
            'ok' => $ok,
            'detail' => self::corto($detalle, 1200),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        file_put_contents((string) self::$ctx['checksFile'], $linea."\n", FILE_APPEND | LOCK_EX);
    }

    private static function corto(mixed $detalle, int $max = 600): string
    {
        $t = is_string($detalle) ? $detalle : (string) json_encode($detalle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        return mb_strlen($t) > $max ? mb_substr($t, 0, $max).'…' : $t;
    }

    // ── La API (HTTP de verdad) ─────────────────────────────────────────────

    /**
     * @param  'a'|'b'|'c'|null  $quien  Ana (Super Admin, autora), Beto (Super Admin, revisor) o Carla (Administrador)
     * @param  array<string, mixed>  $cuerpo
     * @param  array<string, mixed>  $consulta
     */
    protected function api(string $metodo, string $ruta, ?string $quien, array $cuerpo = [], array $consulta = []): Response
    {
        $peticion = Http::acceptJson()->timeout(60);
        if ($quien !== null) {
            $peticion = $peticion->withToken($this->token($quien));
        }
        $url = self::$ctx['api'].'/api/'.ltrim($ruta, '/');
        $enviar = static fn (): Response => match (strtoupper($metodo)) {
            'GET' => $peticion->get($url, $consulta),
            'POST' => $peticion->post($url.($consulta === [] ? '' : '?'.http_build_query($consulta)), $cuerpo),
            'PUT' => $peticion->put($url, $cuerpo),
            default => throw new \InvalidArgumentException("verbo {$metodo}"),
        };
        $r = $enviar();
        // Los limitadores son los de producción (CACHE_STORE=database) y el E2E va más rápido que una persona: ante un
        // 429 con `Retry-After`, una espera y UN reintento, como haría el CRM. Cada espera se cuenta (paso h).
        $espera = (int) $r->header('Retry-After');
        if ($r->status() === 429 && $espera > 0 && $espera <= 65) {
            self::$s['esperas'][] = ['route' => strtoupper($metodo).' '.$ruta, 'who' => $quien, 'seconds' => $espera];
            sleep($espera + 1);
            $r = $enviar();
        }

        return $r;
    }

    /** @param  array<string, mixed>  $cuerpo */
    protected function studio(string $metodo, string $ruta, ?string $quien, array $cuerpo = [], array $consulta = []): Response
    {
        return $this->api($metodo, 'admin/ultron-studio/'.ltrim($ruta, '/'), $quien, $cuerpo, $consulta);
    }

    /** @param  array<string, mixed>  $cuerpo */
    protected function telefonia(string $metodo, string $ruta, ?string $quien, array $cuerpo = [], array $consulta = []): Response
    {
        return $this->api($metodo, 'admin/voice/'.ltrim($ruta, '/'), $quien, $cuerpo, $consulta);
    }

    private function token(string $quien): string
    {
        $f = (string) (self::$ctx['tokens'][$quien] ?? '');
        $t = is_file($f) ? trim((string) file_get_contents($f)) : '';
        $this->assertNotSame('', $t, "sin token de la cuenta {$quien}");

        return $t;
    }

    /** Una respuesta en una línea para el detalle: el estado, el código y lo que se pida del cuerpo. */
    protected static function resumen(Response $r, string ...$claves): array
    {
        $out = ['http' => $r->status(), 'code' => $r->json('code') ?? $r->json('error.code')];
        foreach ($claves as $k) {
            $out[$k] = $r->json($k);
        }

        return $out;
    }

    // ── Los simuladores (control GET) ───────────────────────────────────────

    /** @param  array<string, mixed>  $q  @return array<string, mixed> */
    protected function el(string $control, array $q = []): array
    {
        return $this->control((int) self::$ctx['ports']['elevenlabs'], $control, $q);
    }

    /** @param  array<string, mixed>  $q  @return array<string, mixed> */
    protected function tw(string $control, array $q = []): array
    {
        return $this->control((int) self::$ctx['ports']['twilio'], $control, $q);
    }

    /**
     * Las piezas sintéticas de la ejecución (600). Solo para actuar como ElevenLabs o su panel; ningún valor va a un
     * detalle ni a una salida.
     *
     * @return array<string, string>
     */
    protected function piezas(): array
    {
        $out = [];
        foreach (file((string) self::$ctx['pieces'], FILE_IGNORE_NEW_LINES) ?: [] as $l) {
            if ($l !== '' && $l[0] !== '#' && str_contains($l, '=')) {
                [$k, $v] = explode('=', $l, 2);
                $out[trim($k)] = trim($v);
            }
        }

        return $out;
    }

    /**
     * EL PANEL DE ELEVENLABS: una petición al simulador con la clave (`xi-api-key`), como alguien con la consola o con
     * una clave filtrada, FUERA de Laravel. Es lo que tiene que vigilar la deriva (D8-19, D8-21; E2-3).
     *
     * @param  array<string, mixed>  $cuerpo
     */
    protected function panel(string $metodo, string $ruta, array $cuerpo = []): Response
    {
        $url = 'http://127.0.0.1:'.self::$ctx['ports']['elevenlabs'].$ruta;

        return Http::withHeaders(['xi-api-key' => $this->piezas()['EL_API_KEY']])->acceptJson()->timeout(30)
            ->send(strtoupper($metodo), $url, $cuerpo === [] ? [] : ['json' => $cuerpo]);
    }

    /** @return array<string, mixed> */
    protected function valla(): array
    {
        return $this->control((int) self::$ctx['ports']['valla'], 'stats');
    }

    /** @param  array<string, mixed>  $q  @return array<string, mixed> */
    private function control(int $puerto, string $control, array $q = []): array
    {
        $r = Http::timeout(30)->get("http://127.0.0.1:{$puerto}/__".ltrim($control, '_/'), $q);

        return ['_http' => $r->status()] + (is_array($r->json()) ? $r->json() : ['_raw' => mb_substr($r->body(), 0, 300)]);
    }

    /**
     * Lo que cambió en un simulador desde una foto de `/__stats`: peticiones por plantilla de ruta y violaciones por
     * código.
     *
     * @param  array<string, mixed>  $antes
     * @param  array<string, mixed>  $despues
     * @return array{requests:int, byRoute:array<string,int>, violations:array<string,int>, emitted:array<string,int>}
     */
    protected static function delta(array $antes, array $despues): array
    {
        $resta = static function (array $a, array $b): array {
            $out = [];
            foreach ($b as $k => $v) {
                if (is_int($v) && $v - (int) ($a[$k] ?? 0) !== 0) {
                    $out[$k] = $v - (int) ($a[$k] ?? 0);
                }
            }
            ksort($out);

            return $out;
        };

        return [
            'requests' => (int) ($despues['requests'] ?? 0) - (int) ($antes['requests'] ?? 0),
            'byRoute' => $resta($antes['byRoute'] ?? [], $despues['byRoute'] ?? []),
            'violations' => $resta($antes['violationsByCode'] ?? [], $despues['violationsByCode'] ?? []),
            'emitted' => $resta($antes['emitted'] ?? [], $despues['emitted'] ?? []),
        ];
    }

    /** Cuántas entradas lleva el registro de un simulador (`el` o `tw`): la marca para {@see rutasDesde()}. */
    protected function marca(string $sim): int
    {
        return (int) ($this->{$sim}('requests', ['since' => 1_000_000])['total'] ?? 0);
    }

    /** Las peticiones al PROVEEDOR desde una marca, por id de ruta (no por plantilla: GET y POST de una llamada comparten la suya). @return array<string, int> */
    protected function rutasDesde(string $sim, int $marca): array
    {
        $out = [];
        foreach ((array) ($this->{$sim}('requests', ['since' => $marca])['entries'] ?? []) as $e) {
            if (($e['kind'] ?? null) === 'request') {
                $id = (string) ($e['routeId'] ?? 'desconocida');
                $out[$id] = ($out[$id] ?? 0) + 1;
            }
        }
        ksort($out);

        return $out;
    }

    /** ¿El número importado del simulador está SIN agente? (la clave existe y vale null). */
    protected function numeroSinAgente(): bool
    {
        $n = $this->el('state')['numbers'][0] ?? null;

        return is_array($n) && array_key_exists('agent', $n) && $n['agent'] === null;
    }

    /**
     * F-6 (simulador, OPS8): `pareceDatoPersonal` (`sim-comun.cjs:110`) quita los guiones antes de buscar móviles, y la
     * cola de un `call_ref` aleatorio (un uuid) puede casar con `3\d{9}` o `60\d{8}`. Cuando el simulador marca
     * `dynamic_variables_personal_data` o `initiation_response_personal_data` en una conversación, este ORÁCULO PROPIO
     * mira las variables que de verdad llegaron (`/__state`): exactamente las cuatro del contrato, `call_ref` un uuid,
     * y ningún valor con «+» y cifras, con 7 o más cifras seguidas FUERA del uuid ni con el número de la llamada. Si
     * no hay ningún teléfono, es un falso positivo del simulador: se cuenta aparte (paso h) y se informa; si lo hay,
     * es una fuga real y la comprobación de quien llama falla.
     */
    protected function falsoPositivoDeTelefono(string $conversacion, string $codigo, string $telefono): bool
    {
        $dv = $this->el('state', ['conversation_id' => $conversacion])['dynamicVariables'] ?? null;
        if (! is_array($dv)) {
            return false;
        }
        $claves = array_keys($dv);
        sort($claves);
        $limpio = $claves === ['call_direction', 'call_ref', 'caller_authorized', 'version_label']
            && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', (string) $dv['call_ref']) === 1;
        $digitos = substr(preg_replace('/\D/', '', $telefono) ?? '', -10);
        foreach ($dv as $k => $v) {
            $texto = $k === 'call_ref' ? '' : (string) $v;
            $limpio = $limpio && preg_match('/\+\s*\d/', $texto) !== 1 && preg_match('/\d{7,}/', preg_replace('/[\s().-]/', '', $texto) ?? '') !== 1
                && ($digitos === '' || ! str_contains(preg_replace('/\D/', '', $texto) ?? '', $digitos));
        }
        if ($limpio) {
            self::$s['falsosPositivos'][$codigo] = (self::$s['falsosPositivos'][$codigo] ?? 0) + 1;
            self::$s['falsosPositivosDetalle'][] = ['code' => $codigo, 'callRefTail' => substr((string) $dv['call_ref'], -12)];
        }

        return $limpio;
    }

    /** El paso h deja los falsos positivos verificados de F-6 para el cierre del arnés (que no puede verificarlos). */
    protected function anotarFalsosPositivos(): void
    {
        file_put_contents((string) self::$ctx['run'].'/falsos-positivos-f6.json', (string) json_encode((object) (self::$s['falsosPositivos'] ?? [])));
    }

    /** Las violaciones propias menos los falsos positivos de F-6 YA verificados con {@see falsoPositivoDeTelefono()}. @param array<string,int> $propias @return array<string,int> */
    protected static function sinFalsosPositivos(array $propias): array
    {
        foreach ((array) (self::$s['falsosPositivos'] ?? []) as $codigo => $n) {
            if (isset($propias[$codigo])) {
                $propias[$codigo] -= $n;
                if ($propias[$codigo] <= 0) {
                    unset($propias[$codigo]);
                }
            }
        }

        return $propias;
    }

    /** @return array{el:array<string,mixed>, tw:array<string,mixed>} */
    protected function fotos(): array
    {
        return ['el' => $this->el('stats'), 'tw' => $this->tw('stats')];
    }

    /**
     * Las violaciones que los simuladores cuentan y que NO son de Laravel. Vacío desde que OPS8-b corrigió F-2
     * (`2b3c2936`: el simulador acepta `call_direction` y `version_label`, E1-5): cualquier violación del contrato es
     * propia. Los falsos positivos de F-6 no van aquí: se verifican uno a uno ({@see falsoPositivoDeTelefono()}).
     */
    public const KNOWN_SIM_DRIFT = [];

    /** @param  array<string, int>  $violaciones  @return array{own:array<string,int>, simDrift:array<string,int>} */
    protected static function separar(array $violaciones): array
    {
        $propias = array_diff_key($violaciones, array_flip(self::KNOWN_SIM_DRIFT));
        $deriva = array_intersect_key($violaciones, array_flip(self::KNOWN_SIM_DRIFT));

        return ['own' => $propias, 'simDrift' => $deriva];
    }

    // ── La base del banco (SQL) ─────────────────────────────────────────────

    private static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $d = self::$ctx['db'];
            self::$pdo = new PDO("pgsql:host={$d['host']};port={$d['port']};dbname={$d['database']}", (string) $d['reader'], '', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            self::$pdo->exec("SET TIME ZONE 'UTC'");
        }

        return self::$pdo;
    }

    /** @param  list<mixed>  $p  @return list<array<string, mixed>> */
    protected function sql(string $q, array $p = []): array
    {
        $st = self::pdo()->prepare($q);
        $st->execute($p);

        return $st->fetchAll();
    }

    /** @param  list<mixed>  $p  @return array<string, mixed>|null */
    protected function fila(string $q, array $p = []): ?array
    {
        return $this->sql($q, $p)[0] ?? null;
    }

    /** @param  list<mixed>  $p */
    protected function valor(string $q, array $p = []): mixed
    {
        $f = $this->fila($q, $p);

        return $f === null ? null : array_values($f)[0];
    }

    /**
     * Una sentencia con el ROL DE LA APLICACIÓN (el de producción, `iron_app`, aquí el del banco): para comprobar lo
     * que la base le niega. Devuelve el SQLSTATE (null si pasó).
     */
    protected function comoAplicacion(string $q): ?string
    {
        $d = self::$ctx['db'];
        $pdo = new PDO("pgsql:host={$d['host']};port={$d['port']};dbname={$d['database']}", (string) $d['app'], '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        try {
            $pdo->beginTransaction();
            $pdo->exec($q);
            $pdo->rollBack();

            return null;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            return (string) $e->getCode();
        }
    }

    // ── La copia del árbol: artisan, el ayudante y el emisor del laboratorio ─

    /**
     * Un proceso de la copia con UN entorno limpio (`env -i`): el `.env` de la copia y la red por la valla, nunca el
     * entorno de PHPUnit (que llevaría la base en memoria de las pruebas).
     *
     * @param  list<string>  $orden
     * @param  array<string, string>  $extra
     * @return array{code:int, out:string, err:string}
     */
    protected function proceso(array $orden, array $extra = [], int $plazo = 120): array
    {
        $entorno = ['PATH' => (string) getenv('PATH'), 'HOME' => (string) getenv('HOME'), 'LC_ALL' => 'C', 'PGGSSENCMODE' => 'disable'] + self::$ctx['red'] + $extra;
        $env = array_map(static fn (string $k, string $v): string => "{$k}={$v}", array_keys($entorno), array_values($entorno));
        $p = new Process(['/usr/bin/env', '-i', ...$env, ...$orden], (string) self::$ctx['backend'], null, null, $plazo);
        $p->run();

        return ['code' => (int) $p->getExitCode(), 'out' => $p->getOutput(), 'err' => $p->getErrorOutput()];
    }

    /** @param  list<string>  $args  @return array{code:int, out:string, err:string, json:mixed} */
    protected function artisanCopia(array $args, array $extra = [], ?string $prepend = null): array
    {
        $php = [(string) self::$ctx['php']];
        if ($prepend !== null) {
            $php[] = '-d';
            $php[] = 'auto_prepend_file='.$prepend;
        }
        $r = $this->proceso([...$php, 'artisan', ...$args], $extra);
        try {
            $r['json'] = json_decode(trim($r['out']), true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $r['json'] = null;
        }

        return $r;
    }

    /** @param  list<string>  $args  @return array<string, mixed> */
    protected function ayudante(array $args): array
    {
        $r = $this->proceso([(string) self::$ctx['php'], (string) self::$ctx['helpers']['ayudante'], (string) self::$ctx['backend'], ...$args]);
        $this->must('ayudante '.$args[0].' sale con 0', $r['code'] === 0, mb_substr($r['err'], 0, 400));

        return json_decode(trim($r['out']), true, 64, JSON_THROW_ON_ERROR);
    }

    /**
     * Un worker de UN trabajo de la cola `voice-e2e` (las salientes selladas por el ayudante), con el reloj de Carbon en
     * `$instante` si se da (ver `ops/h8-e2e/reloj.php`). El worker residente del arnés atiende `voice`.
     *
     * @return array{code:int, out:string, err:string}
     */
    protected function workerDeUnTrabajo(?string $instante): array
    {
        $extra = $instante === null ? [] : ['H8_E2E_RELOJ_OBJETIVO' => $instante, 'H8_E2E_VENDOR' => self::$ctx['backend'].'/vendor/autoload.php'];

        return $this->artisanCopia(['queue:work', 'voice', '--queue=voice-e2e', '--stop-when-empty', '--tries=1', '--timeout=60'], $extra,
            $instante === null ? null : (string) self::$ctx['helpers']['reloj']);
    }

    // ── Recorridos comunes a las dos fases ──────────────────────────────────

    /**
     * El «T3 de voz» por la API del H6 (D8-19: canarios de voz `active` con cuatro ojos): Ana registra los dos canarios
     * sintéticos, no puede autorizar lo suyo, y Beto los autoriza y los activa. Deja sus uuid en `self::$s['canarios']`.
     */
    protected function prepararCanarios(): void
    {
        $uuids = [];
        foreach (self::ctx()['canaries'] as $i => $telefono) {
            $r = $this->studio('POST', 'integrations/canaries', 'a', [
                'clientRequestId' => self::uuid(), 'channel' => 'voice', 'phone' => $telefono, 'alias' => 'Canario de voz '.($i + 1), 'consentAttested' => true,
            ]);
            $this->must("canario {$i}: Ana lo registra (201)", $r->status() === 201, self::resumen($r, 'data.number.status'));
            $uuid = (string) $r->json('data.number.uuid');
            $this->check("canario {$i}: la respuesta no lleva el teléfono, solo la máscara", ! str_contains($r->body(), substr($telefono, 3)) && is_string($r->json('data.number.masked')), $r->json('data.number.masked'));
            $propia = $this->studio('POST', "integrations/canaries/{$uuid}/authorize", 'a');
            $this->check("canario {$i}: Ana no autoriza lo que registró (cuatro ojos)", in_array($propia->status(), [403, 409], true), self::resumen($propia));
            $au = $this->studio('POST', "integrations/canaries/{$uuid}/authorize", 'b');
            $this->must("canario {$i}: Beto lo autoriza", $au->status() === 200, self::resumen($au, 'data.number.status'));
            $ac = $this->studio('POST', "integrations/canaries/{$uuid}/activate", 'b');
            $this->must("canario {$i}: Beto lo activa", $ac->status() === 200 && $ac->json('data.number.status') === 'active', self::resumen($ac, 'data.number.status'));
            $uuids[] = $uuid;
        }
        $filas = $this->sql('SELECT uuid, status, channel, phone_last4, phone_encrypted IS NOT NULL AS cifrado, registered_by_admin_id, authorized_by_admin_id, activated_by_admin_id FROM ultron_studio_canary_numbers ORDER BY id');
        $a = self::ctx()['accounts']['a']['id'];
        $b = self::ctx()['accounts']['b']['id'];
        $this->check('SQL: dos canarios de voz activos, cifrados, registrados por Ana y autorizados y activados por Beto',
            count($filas) === 2 && array_reduce($filas, static fn (bool $ok, array $f): bool => $ok && $f['status'] === 'active' && $f['channel'] === 'voice' && $f['cifrado'] === true
                && (int) $f['registered_by_admin_id'] === $a && (int) $f['authorized_by_admin_id'] === $b && (int) $f['activated_by_admin_id'] === $b, true),
            array_map(static fn (array $f): array => ['status' => $f['status'], 'last4' => $f['phone_last4']], $filas));
        self::$s['canarios'] = $uuids;
    }

    /** El perfil de voz del E2E: las cuatro herramientas del catálogo, sin destinos (el laboratorio no los admite aún). @return array<string, mixed> */
    protected static function contenidoPerfil(string $saludo = '¿En qué te puedo ayudar hoy?'): array
    {
        return [
            'voiceId' => 'pNInz6obpgDQGcFmaJgB',
            'greetings' => ['inbound' => $saludo, 'outbound' => 'Te llamo porque pediste información de Iron Body.'],
            'holes' => ['presentation' => 'Te ayudo con planes, horarios y la sede.', 'closing' => 'Gracias por tu tiempo.'],
            'maxDurationSeconds' => 300,
            'tools' => ['consultar_planes', 'consultar_horarios', 'solicitar_humano', 'no_volver_a_llamar'],
            'systemTools' => ['end_call'],
            'transfer' => ['destinations' => []],
            'privacy' => ['retentionDays' => 30],
            'voicemail' => 'hang_up',
        ];
    }

    /**
     * RECORRIDO a (D8-6, D8-23, P-19): la versión del Studio aprobada a cuatro ojos (la primera vez), un perfil de voz
     * → enviar → aprobar (otra cuenta) → `compile-preview` (sin red) → sesión del laboratorio hablado con el adaptador
     * REAL contra el simulador (URL firmada, de un solo uso) → cierre por el informe post-llamada (conversación web) →
     * veredicto de OTRA identidad sobre la MISMA `specHash`.
     *
     * @param  array<string, mixed>  $contenido
     * @return array{version:string, profile:string, specHash:string, session:string, labAgent:string}
     */
    protected function recorridoLaboratorio(string $etiqueta, array $contenido): array
    {
        $a = (int) self::ctx()['accounts']['a']['id'];
        $b = (int) self::ctx()['accounts']['b']['id'];

        // La versión del Studio (H5, sin cambios): Ana la crea desde la línea base; Beto la aprueba.
        if (! isset(self::$s['version'])) {
            $d = $this->studio('GET', 'draft', 'a');
            $this->must('versión: GET draft', $d->status() === 200, self::resumen($d));
            $rev = (int) $d->json('data.revision');
            if ($d->json('data.exists') !== true) {
                $g = $this->studio('PUT', 'draft', 'a', ['baseRevision' => $rev, 'content' => $d->json('data.content')]);
                $this->must('versión: PUT draft (la línea base)', $g->status() === 200, self::resumen($g));
                $rev = (int) $g->json('data.revision');
            }
            $v = $this->studio('POST', 'versions', 'a', ['label' => 'Versión del E2E de voz', 'note' => null, 'draftRevision' => $rev, 'clientRequestId' => self::uuid()]);
            $this->must('versión: Ana la crea (201)', $v->status() === 201, self::resumen($v, 'data.status'));
            $version = (string) $v->json('data.uuid');
            $sub = $this->studio('POST', "versions/{$version}/submit", 'a');
            $this->must('versión: Ana la envía a revisión', $sub->status() === 200, self::resumen($sub, 'data.status'));
            $avisos = $sub->json('data.warnings') ?? [];
            $propia = $this->studio('POST', "versions/{$version}/approve", 'a', ['acknowledgedWarnings' => $avisos]);
            $this->check('versión: Ana no aprueba la suya (cuatro ojos)', $propia->status() === 403, self::resumen($propia));
            $ap = $this->studio('POST', "versions/{$version}/approve", 'b', ['acknowledgedWarnings' => $avisos]);
            $this->must('versión: Beto la aprueba', $ap->status() === 200, self::resumen($ap, 'data.status'));
            $f = $this->fila('SELECT v.status, v.created_by_admin_id, r.decision, r.reviewer_admin_id FROM ultron_studio_versions v JOIN ultron_studio_version_reviews r ON r.version_id = v.id WHERE v.uuid = ?', [$version]);
            $this->check('SQL: versión APPROVED, creada por Ana y aprobada por Beto', $f !== null && $f['status'] === 'APPROVED' && (int) $f['created_by_admin_id'] === $a
                && $f['decision'] === 'approved' && (int) $f['reviewer_admin_id'] === $b, $f);
            self::$s['version'] = $version;
        }
        $version = (string) self::$s['version'];

        // El perfil de voz: Ana lo crea y lo envía; Beto lo aprueba.
        $p = $this->studio('POST', 'voice/profiles', 'a', ['clientRequestId' => self::uuid(), 'label' => $etiqueta, 'content' => $contenido]);
        $this->must("perfil «{$etiqueta}»: Ana lo crea (201)", $p->status() === 201, self::resumen($p, 'data.status'));
        $perfil = (string) $p->json('data.uuid');
        $this->check('perfil: nace en draft', $p->json('data.status') === 'draft', $p->json('data.status'));
        $s1 = $this->studio('POST', "voice/profiles/{$perfil}/submit", 'a');
        $this->must('perfil: Ana lo envía', $s1->status() === 200 && $s1->json('data.status') === 'submitted', self::resumen($s1, 'data.status'));
        $propio = $this->studio('POST', "voice/profiles/{$perfil}/approve", 'a');
        $this->check('perfil: Ana no aprueba el suyo (cuatro ojos)', $propio->status() === 403, self::resumen($propio));
        $ap = $this->studio('POST', "voice/profiles/{$perfil}/approve", 'b');
        $this->must('perfil: Beto lo aprueba', $ap->status() === 200 && $ap->json('data.status') === 'approved', self::resumen($ap, 'data.status'));
        $fp = $this->fila('SELECT status, created_by_admin_id, reviewed_by_admin_id, content_hash FROM ultron_studio_voice_profiles WHERE uuid = ?', [$perfil]);
        $this->check('SQL: perfil approved, de Ana, aprobado por Beto, con su huella', $fp !== null && $fp['status'] === 'approved' && (int) $fp['created_by_admin_id'] === $a
            && (int) $fp['reviewed_by_admin_id'] === $b && preg_match('/^[0-9a-f]{64}$/', (string) $fp['content_hash']) === 1, $fp);

        // compile-preview: sin red y sin escribir.
        $antes = $this->fotos();
        $agentesAntes = (int) $this->valor('SELECT count(*) FROM ultron_studio_voice_agents');
        $pv = $this->studio('POST', 'voice/compile-preview', 'a', ['versionUuid' => $version, 'profileUuid' => $perfil]);
        $this->must('compile-preview: 200', $pv->status() === 200, self::resumen($pv));
        $spec = (string) $pv->json('data.specHash');
        $this->must('compile-preview: specHash de 64 hex', preg_match('/^[0-9a-f]{64}$/', $spec) === 1, $spec);
        $this->check('compile-preview: desplegable con esta configuración (no falta nada)', $pv->json('data.deployable.missing') === [], $pv->json('data.deployable'));
        $this->check('compile-preview: todavía sin veredicto del laboratorio sobre esta huella', $pv->json('data.labVerdict.latest') === null, $pv->json('data.labVerdict'));
        $this->check('compile-preview: la respuesta no lleva teléfonos ni secretos (ni el número de voz)', ! str_contains($pv->body(), '6010000090') && ! str_contains($pv->body(), 'secref_'), null);
        $d1 = self::delta($antes['el'], $this->el('stats'));
        $this->check('compile-preview: ninguna petición a ElevenLabs (sin red)', $d1['requests'] === 0, $d1);
        $this->check('compile-preview: no escribe agentes', (int) $this->valor('SELECT count(*) FROM ultron_studio_voice_agents') === $agentesAntes, null);

        // La sesión del laboratorio hablado: Carla no (gasta: solo Super Admin); Ana sí, con el adaptador real.
        $crid = self::uuid();
        $cuerpo = ['versionUuid' => $version, 'profileUuid' => $perfil, 'acceptedMaxCostMicros' => 400000, 'clientRequestId' => $crid];
        $nc = $this->studio('POST', 'voice-lab/sessions', 'c', $cuerpo);
        $this->check('laboratorio: Carla (Administrador) no abre sesiones (403, ultron.voice_lab_run)', $nc->status() === 403, self::resumen($nc));
        $antes = $this->fotos();
        $ls = $this->studio('POST', 'voice-lab/sessions', 'a', $cuerpo);
        $this->must('laboratorio: Ana abre la sesión (201)', $ls->status() === 201, self::resumen($ls, 'data.status', 'data.endReason'));
        $sesion = (string) $ls->json('data.uuid');
        $agenteLab = (string) $ls->json('data.agent.providerAgentId');
        $url = (string) $ls->json('data.connection.signedUrl');
        $this->check('laboratorio: modo elevenlabs (adaptador real) y sesión abierta', $ls->json('data.mode') === 'elevenlabs' && $ls->json('data.status') === 'open', self::resumen($ls, 'data.mode', 'data.status'));
        $this->check('laboratorio: el agente de laboratorio tiene la MISMA specHash que la previsualización', $ls->json('data.agent.specHash') === $spec, $ls->json('data.agent.specHash'));
        $this->check('laboratorio: URL firmada del simulador (ws://127.0.0.1) para ese agente', str_starts_with($url, 'ws://127.0.0.1:'.self::ctx()['ports']['elevenlabs'].'/v1/convai/conversation?agent_id='.$agenteLab.'&'), substr($url, 0, 60));
        $exp = $ls->json('data.connection.expiresAt');
        $this->check('laboratorio: la URL caduca en ≤ 15 min', is_string($exp) && strtotime($exp) - time() <= 900 && strtotime($exp) - time() > 0, $exp);
        $d2 = self::delta($antes['el'], $this->el('stats'));
        $this->check('laboratorio: en ElevenLabs, las 4 herramientas, el agente, su lectura y la URL firmada', ($d2['byRoute']['/v1/convai/tools'] ?? 0) === 4 && ($d2['byRoute']['/v1/convai/agents/create'] ?? 0) === 1
            && ($d2['byRoute']['/v1/convai/agents/{agent_id}'] ?? 0) === 1 && ($d2['byRoute']['/v1/convai/conversation/get-signed-url'] ?? 0) === 1, $d2);
        $this->check('laboratorio: 0 violaciones en ElevenLabs al desplegar el agente de laboratorio', self::separar($d2['violations'])['own'] === [], $d2['violations']);
        $re = $this->studio('POST', 'voice-lab/sessions', 'a', $cuerpo);
        $this->check('laboratorio: repetir la petición devuelve la sesión SIN la URL (un solo uso)', $re->status() === 200 && $re->json('replayed') === true && $re->json('data.connection.signedUrl') === null
            && $re->json('data.uuid') === $sesion, self::resumen($re, 'replayed', 'data.connection'));
        $fl = $this->fila('SELECT a.purpose, a.provider, a.status, a.spec_hash, a.provider_agent_id, s.status AS sesion, s.url_expires_at IS NOT NULL AS url FROM ultron_studio_voice_lab_sessions s JOIN ultron_studio_voice_agents a ON a.id = s.agent_id WHERE s.uuid = ?', [$sesion]);
        $this->check('SQL: agente de laboratorio (lab, elevenlabs, deployed, misma huella, el id del simulador) y sesión abierta con URL', $fl !== null && $fl['purpose'] === 'lab' && $fl['provider'] === 'elevenlabs'
            && $fl['status'] === 'deployed' && $fl['spec_hash'] === $spec && $fl['provider_agent_id'] === $agenteLab && $fl['sesion'] === 'open' && $fl['url'] === true, $fl);
        $this->check('SQL: la URL firmada no se guarda en ninguna parte de la sesión', (int) $this->valor("SELECT count(*) FROM ultron_studio_voice_lab_sessions WHERE row_to_json(ultron_studio_voice_lab_sessions)::text LIKE '%conversation_signature%'") === 0, null);

        // El cierre por el informe post-llamada del SIMULADOR: la conversación web que registró la URL firmada
        // (`/__emit/post-call?web=1&agent_id=`, OPS8-b), sin `phone_call` ni `call_sid`. El emisor devuelve solo el
        // estado HTTP: lo que cuenta es lo que queda en la base.
        $eventos = (int) $this->valor('SELECT count(*) FROM voice_call_events');
        $frenos = (int) $this->valor('SELECT count(*) FROM voice_brakes');
        $sesionSql = 'SELECT status, end_reason, duration_seconds, cost_micros, reserved_micros FROM ultron_studio_voice_lab_sessions WHERE uuid = ?';
        $pc = $this->el('emit/post-call', ['web' => '1', 'agent_id' => $agenteLab]);
        $fs = $this->fila($sesionSql, [$sesion]);
        $reconocida = ($pc['deliveries'][0]['status'] ?? null) === 200 && $fs !== null && $fs['status'] === 'ended' && $fs['end_reason'] === 'provider_report';
        $consecuencias = ['emisor' => ['expected' => $pc['expected'] ?? null, 'deliveries' => $pc['deliveries'] ?? null], 'sesion' => $fs,
            'eventosNuevos' => $this->sql('SELECT kind, outcome FROM voice_call_events ORDER BY id OFFSET ?', [$eventos]),
            'frenosNuevos' => $this->sql('SELECT kind, reason FROM voice_brakes ORDER BY id OFFSET ?', [$frenos])];
        $this->check('laboratorio: la post-llamada web del simulador → 200 y la sesión se cierra por el informe del proveedor', $reconocida, $consecuencias);
        if ($reconocida) {
            $this->check('SQL: la sesión lleva la duración del informe (64 s) y el coste prorrateado (> 0 y ≤ la reserva)', (int) $fs['duration_seconds'] === 64
                && (int) $fs['cost_micros'] > 0 && (int) $fs['cost_micros'] <= (int) $fs['reserved_micros'], $fs);
            $this->check('SQL: el informe del laboratorio no escribe en voice_* ni abre el freno', (int) $this->valor('SELECT count(*) FROM voice_call_events') === $eventos
                && (int) $this->valor('SELECT count(*) FROM voice_brakes') === $frenos, $consecuencias);
            $dup = $this->el('emit/post-call', ['web' => '1', 'agent_id' => $agenteLab]);
            $this->check('el MISMO informe otra vez (la misma conversación): 200, la sesión no cambia, sin eventos ni freno', ($dup['deliveries'][0]['status'] ?? null) === 200
                && $this->fila($sesionSql, [$sesion]) === $fs && (int) $this->valor('SELECT count(*) FROM voice_call_events') === $eventos
                && (int) $this->valor('SELECT count(*) FROM voice_brakes') === $frenos, $dup['deliveries'] ?? null);
        } else {
            // Hallazgo F-1 del informe (lo corrige B8-c): sin oyente de `voice.lab.post_call`, la conversación del
            // laboratorio cuenta como una llamada desconocida y echa el freno. Queda en rojo arriba; para ejercer el
            // resto del recorrido, la sesión la cierra quien la abrió (`end`) y una persona suelta el freno.
            $fin = $this->studio('POST', "voice-lab/sessions/{$sesion}/end", 'a', ['objectives' => ['aviso_ia', 'saludo'], 'restrictionsBroken' => [], 'interruptions' => 0, 'errors' => []]);
            $this->must('vía alterna (F-1): Ana cierra la sesión con lo que observó', $fin->status() === 200 && $fin->json('data.status') === 'ended' && $fin->json('data.endReason') === 'tester_ended', self::resumen($fin, 'data.status', 'data.endReason'));
            $this->soltarFrenos('a', 'Vía alterna del E2E: conversación del laboratorio sin oyente (F-1).');
        }

        // El veredicto, de OTRA identidad y sobre la MISMA huella.
        $va = $this->studio('POST', "voice-lab/sessions/{$sesion}/verdict", 'a', ['decision' => 'approved', 'specHash' => $spec]);
        $this->check('veredicto: Ana (abrió la sesión y es la autora de la versión) no lo da (403 cuatro ojos)', $va->status() === 403, self::resumen($va));
        $otra = substr($spec, 0, 63).($spec[63] === '0' ? '1' : '0');
        $vm = $this->studio('POST', "voice-lab/sessions/{$sesion}/verdict", 'b', ['decision' => 'approved', 'specHash' => $otra]);
        $this->check('veredicto: con otra specHash → 409 (integridad)', $vm->status() === 409, self::resumen($vm));
        $vb = $this->studio('POST', "voice-lab/sessions/{$sesion}/verdict", 'b', ['decision' => 'approved', 'specHash' => $spec]);
        $this->must('veredicto: Beto lo aprueba sobre la misma specHash', $vb->status() === 200 && $vb->json('data.verdict.decision') === 'approved', self::resumen($vb, 'data.verdict'));
        $v2 = $this->studio('POST', "voice-lab/sessions/{$sesion}/verdict", 'b', ['decision' => 'rejected', 'note' => 'Segundo intento', 'specHash' => $spec]);
        $this->check('veredicto: es definitivo (un segundo → 409)', $v2->status() === 409, self::resumen($v2));
        $fv = $this->fila('SELECT verdict, verdict_by_admin_id, created_by_admin_id FROM ultron_studio_voice_lab_sessions WHERE uuid = ?', [$sesion]);
        $this->check('SQL: veredicto approved de Beto sobre una sesión de Ana', $fv !== null && $fv['verdict'] === 'approved' && (int) $fv['verdict_by_admin_id'] === $b && (int) $fv['created_by_admin_id'] === $a, $fv);
        $pv2 = $this->studio('POST', 'voice/compile-preview', 'a', ['versionUuid' => $version, 'profileUuid' => $perfil]);
        $this->check('compile-preview: ahora con el veredicto approved del laboratorio sobre su huella', $pv2->json('data.labVerdict.latest') === 'approved' && $pv2->json('data.specHash') === $spec, $pv2->json('data.labVerdict'));

        return ['version' => $version, 'profile' => $perfil, 'specHash' => $spec, 'session' => $sesion, 'labAgent' => $agenteLab];
    }

    // ── Ayudantes de los recorridos ─────────────────────────────────────────

    /** Lo que dejó un paso anterior; sin ello, este paso no se puede probar (y lo dice). */
    protected function requiere(string $clave): mixed
    {
        $this->must("estado del paso anterior «{$clave}» (si falta, ese paso falló antes de dejarlo)", array_key_exists($clave, self::$s), array_keys(self::$s));

        return self::$s[$clave];
    }

    /**
     * Suelta TODAS las aperturas del freno que sigan echadas, una a una (D8-19: solo una persona con
     * `ultron.voice_publish`, con nota). Devuelve cuántas soltó.
     */
    protected function soltarFrenos(string $quien, string $nota): int
    {
        $lista = $this->studio('GET', 'voice/brakes', $quien, [], ['limit' => 50]);
        $this->must('GET voice/brakes', $lista->status() === 200, self::resumen($lista));
        $n = 0;
        foreach ((array) $lista->json('data.brakes') as $f) {
            if (($f['released'] ?? true) === false) {
                $r = $this->studio('POST', 'voice/brakes/'.$f['uuid'].'/release', $quien, ['clientRequestId' => self::uuid(), 'note' => $nota]);
                $this->must('suelta del freno «'.($f['reason'] ?? '?').'»', $r->status() === 200, self::resumen($r, 'data.state'));
                $n++;
            }
        }
        $estado = $this->studio('GET', 'voice/brakes', $quien)->json('data.state');
        $this->must('el freno queda suelto', $estado === 'released', $estado);

        return $n;
    }

    /**
     * Una entrante por el simulador (Twilio llama a ElevenLabs y ElevenLabs a la iniciación de Laravel) y su fila.
     *
     * @param  array<string, string>  $extra
     * @return array{sim: array<string, mixed>, row: array<string, mixed>|null}
     */
    protected function entrante(string $desde, array $extra = []): array
    {
        $r = $this->el('inbound', ['from' => $desde] + $extra);
        $fila = isset($r['conversation_id']) ? $this->fila('SELECT * FROM voice_calls WHERE provider_conversation_id = ?', [$r['conversation_id']]) : null;

        return ['sim' => $r, 'row' => $fila];
    }

    /**
     * La conversación termina en el simulador (Twilio `completed` con su duración) y ElevenLabs manda la post-llamada
     * firmada a Laravel. `$post` admite `agent_id`, `transcript`, `version_id`…
     *
     * @param  array<string, string>  $post
     * @return array{call: array<string, mixed>, post: array<string, mixed>}
     */
    protected function cerrarConversacion(string $conversacion, int $duracion, array $post = []): array
    {
        $c = $this->el('call', ['conversation_id' => $conversacion, 'event' => 'completed', 'duration' => $duracion]);
        $p = $this->el('emit/post-call', ['conversation_id' => $conversacion] + $post);

        return ['call' => $c, 'post' => $p];
    }

    /** Espera (sondeando la base) a que una llamada salga de `requested`. Devuelve el estado. */
    protected function esperarEstado(string $uuid, float $segundos = 15.0): ?string
    {
        $fin = microtime(true) + $segundos;
        do {
            $estado = $this->valor('SELECT status FROM voice_calls WHERE uuid = ?', [$uuid]);
            if ($estado !== null && $estado !== 'requested') {
                return (string) $estado;
            }
            usleep(200_000);
        } while (microtime(true) < $fin);

        return $estado === null ? null : (string) $estado;
    }

    /** `voice:reconcile --json` (con `--numbers` si se pide) en la copia, con la red por la valla. @return array{code:int, json:mixed, err:string} */
    protected function conciliar(bool $numeros = false): array
    {
        $r = $this->artisanCopia(array_values(array_filter(['voice:reconcile', '--json', $numeros ? '--numbers' : null])));

        return ['code' => $r['code'], 'json' => $r['json'], 'err' => mb_substr($r['err'], 0, 300)];
    }

    /** El precio de Twilio (texto con signo, US$) en micro-US$, como `VoiceCostGuard::budgetMicros`. */
    protected static function micros(?string $precio): ?int
    {
        return $precio === null ? null : (int) round(abs((float) $precio) * 1_000_000);
    }

    // ── Piezas de dominio ───────────────────────────────────────────────────

    protected static function uuid(): string
    {
        return strtolower((string) Str::uuid());
    }

    /** Lo que el contrato fija para el E2E (E6-11): el contexto de la ejecución. @return array<string, mixed> */
    protected static function ctx(): array
    {
        return self::$ctx;
    }

    /**
     * ¿Está `$instante` dentro del horario legal de salientes? Oráculo INDEPENDIENTE del código: D8-15 (lunes a viernes
     * 07:00–19:00, sábados 08:00–15:00, Bogotá; ni domingos ni festivos) con los festivos del fixture de TEST8-a
     * (`tests/fixtures/voice/holidays-co-2026-2028.json`).
     *
     * @return array{outsideHours:bool, holiday:bool}
     */
    protected static function calendarioLegal(\DateTimeInterface $instante): array
    {
        $local = \DateTimeImmutable::createFromInterface($instante)->setTimezone(new \DateTimeZone('America/Bogota'));
        $fx = json_decode((string) file_get_contents(base_path('tests/fixtures/voice/holidays-co-2026-2028.json')), true, 64, JSON_THROW_ON_ERROR);
        $festivos = array_column($fx['years'][$local->format('Y')] ?? [], 'date');
        $dia = (int) $local->format('N');
        $s = (int) $local->format('G') * 3600 + (int) $local->format('i') * 60 + (int) $local->format('s');
        $dentro = ($dia <= 5 && $s >= 7 * 3600 && $s < 19 * 3600) || ($dia === 6 && $s >= 8 * 3600 && $s < 15 * 3600);

        return ['outsideHours' => ! $dentro, 'holiday' => in_array($local->format('Y-m-d'), $festivos, true)];
    }
}
