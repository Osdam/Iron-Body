<?php

namespace Tests\Feature\Outreach\AI9;

use App\Services\Outreach\Run\Classifier\ReplyClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Outreach\AI9\Support\AI9World;
use Tests\TestCase;

/**
 * AI9 · LA IDEMPOTENCIA CON DOS PROCESOS (solo PostgreSQL): dos trabajadores
 * reales (`Support/ai9-worker.php`) corren A LA VEZ la pasada de WhatsApp
 * sobre la MISMA respuesta (salen juntos de una barrera, un cerrojo
 * consultivo que la prueba retiene hasta ver a los dos esperando). El
 * transporte tarda 300 ms y apunta cada llamada en un fichero común. Queda UNA
 * llamada (un cobro), UN `replied` y UN resultado del clasificador, y la
 * segunda vuelta de los dos no llama ni registra nada: solo el proceso cuya
 * inserción de `replied` ganó la clave única (`oout_source_unique`) clasifica.
 *
 * El mundo se SIEMBRA confirmado (los trabajadores no ven la transacción de
 * `RefreshDatabase`): durante la siembra la conexión por defecto es una de
 * apoyo, en modo autocommit, y al acabar se borran SOLO las filas nuevas de
 * cada tabla, con los disparadores apagados (base desechable, nunca
 * producción; como `H9IdempotencyRaceTest`).
 */
class ReplyClassifierRaceTest extends TestCase
{
    use AI9World;
    use RefreshDatabase;

    private const CONNECTION = 'ai9_carrera';

    private const NOW = '2026-11-17 15:00:00';

    private const BARRIER = 913019;

    private const WORKER = __DIR__.'/Support/ai9-worker.php';

    private ?string $defectoAntes = null;

    /** @var array<string, int> tabla → id máximo antes de sembrar */
    private array $antes = [];

    private string $llamadas = '';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Solo PostgreSQL: la carrera necesita dos procesos contra una base real.');
        }
        if (! function_exists('proc_open')) {
            $this->markTestSkipped('Sin proc_open no hay trabajadores.');
        }
        config(['database.connections.'.self::CONNECTION => config('database.connections.'.config('database.default'))]);
        $c = DB::connection(self::CONNECTION);
        foreach ($c->select("SELECT table_name FROM information_schema.columns WHERE table_schema = 'public' AND column_name = 'id' ORDER BY table_name") as $t) {
            $this->antes[(string) $t->table_name] = (int) $c->table((string) $t->table_name)->max('id');
        }
        $this->assertSame(0, $c->table('outreach_outcomes')->count(), 'filas confirmadas de otra prueba: no se borran filas ajenas');
        $this->llamadas = (string) tempnam(sys_get_temp_dir(), 'ai9-calls-');
        $this->defectoAntes = (string) config('database.default');
        DB::setDefaultConnection(self::CONNECTION);
        Carbon::setTestNow(Carbon::parse(self::NOW, 'UTC'));
        config(['commercial.events_enabled' => true]);
        $this->threePeople();
    }

    protected function tearDown(): void
    {
        if ($this->defectoAntes !== null) {
            DB::setDefaultConnection($this->defectoAntes);
            $c = DB::connection(self::CONNECTION);
            $c->select('SELECT pg_advisory_unlock_all()');
            $c->statement('SET session_replication_role = replica');
            try {
                foreach ($c->select("SELECT table_name FROM information_schema.columns WHERE table_schema = 'public' AND column_name = 'id'") as $t) {
                    $c->table((string) $t->table_name)->where('id', '>', $this->antes[(string) $t->table_name] ?? 0)->delete();
                }
                $c->table('outreach_budget_counters')->where('scope', 'like', 'classifier:%')->delete();
            } finally {
                $c->statement('SET session_replication_role = DEFAULT');
            }
            DB::purge(self::CONNECTION);
            @unlink($this->llamadas);
        }
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_dos_procesos_a_la_vez_sobre_la_misma_respuesta_hacen_una_llamada_y_un_resultado(): void
    {
        $s = $this->sentTemplate();
        $this->inbound($s['member']['lead'], 'No me interesa, gracias');
        $apoyo = DB::connection(self::CONNECTION);
        $this->assertSame(0, $apoyo->table('outreach_outcomes')->where('outcome', 'replied')->count());

        $salidas = $this->raceRound();

        $this->assertSame(1, $this->callsMade(), 'una llamada al modelo, un cobro: '.json_encode($salidas));
        $this->assertSame(1, $apoyo->table('outreach_outcomes')->where('outcome', 'replied')->count());
        $clasificador = $apoyo->table('outreach_outcomes')->where('source', 'classifier')->get();
        $this->assertCount(1, $clasificador);
        $this->assertSame('not_interested', $clasificador[0]->outcome);
        $this->assertSame(ReplyClassifier::sourceKey((string) $s['job']->uuid, self::MODEL), $clasificador[0]->source_key);
        $this->assertSame(1, (int) $apoyo->table('outreach_budget_counters')->where('scope', 'classifier:reply')->value('reserved_units'));
        $llamaron = array_values(array_filter($salidas, static fn (array $x): bool => ($x['run']['classifier']['calls'] ?? 0) === 1));
        $this->assertCount(1, $llamaron, 'uno clasifica');
        $otro = array_values(array_filter($salidas, static fn (array $x): bool => ($x['run']['classifier']['calls'] ?? 0) === 0));
        $this->assertCount(1, $otro);
        $this->assertSame(1, $otro[0]['run']['duplicates'] ?? null, 'el otro se encuentra el replied ya registrado (UNIQUE) y no llama');

        // Otra vez los dos a la vez: nada nuevo.
        $this->raceRound();
        $this->assertSame(1, $this->callsMade());
        $this->assertSame(2, $apoyo->table('outreach_outcomes')->count(), 'replied + el freno, nada más');
    }

    private function callsMade(): int
    {
        clearstatcache();

        return count(array_filter(explode("\n", (string) file_get_contents($this->llamadas))));
    }

    // ── La carrera (la forma de H9IdempotencyRaceTest) ──────────────────────

    /** @return list<array<string, mixed>> */
    private function raceRound(): array
    {
        $apoyo = DB::connection(self::CONNECTION);
        $apoyo->select('SELECT pg_advisory_lock(?)', [self::BARRIER]);
        $procesos = [];
        foreach (['A', 'B'] as $etiqueta) {
            $procesos[$etiqueta] = $this->spawnWorker($etiqueta);
        }
        $esperando = 0;
        for ($i = 0; $i < 600 && $esperando < 2; $i++) {
            $esperando = (int) $apoyo->scalar("SELECT count(*) FROM pg_locks WHERE locktype = 'advisory' AND objid = ? AND NOT granted", [self::BARRIER]);
            if ($esperando < 2) {
                usleep(50_000);
            }
        }
        $apoyo->select('SELECT pg_advisory_unlock(?)', [self::BARRIER]);
        if ($esperando !== 2) {
            foreach ($procesos as $p) {
                proc_terminate($p['proc'], 9);
            }
            $this->fail('Los dos trabajadores tenían que estar esperando en la barrera.');
        }
        $salidas = [];
        foreach ($procesos as $etiqueta => $p) {
            $salidas[] = $this->collect($etiqueta, $p);
        }

        return $salidas;
    }

    /** @return array{proc: resource, out: string, err: string} */
    private function spawnWorker(string $etiqueta): array
    {
        $out = (string) tempnam(sys_get_temp_dir(), "ai9-{$etiqueta}-out-");
        $err = (string) tempnam(sys_get_temp_dir(), "ai9-{$etiqueta}-err-");
        $args = json_encode(['now' => now()->format('Y-m-d H:i:s'), 'barrier' => self::BARRIER, 'calls' => $this->llamadas, 'label' => $etiqueta], JSON_THROW_ON_ERROR);
        $c = (array) config('database.connections.'.self::CONNECTION);
        $env = array_merge(getenv(), [
            'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => (string) config('app.key'),
            'DB_CONNECTION' => 'pgsql', 'DB_HOST' => (string) ($c['host'] ?? '127.0.0.1'), 'DB_PORT' => (string) ($c['port'] ?? 5432),
            'DB_DATABASE' => (string) $c['database'], 'DB_USERNAME' => (string) $c['username'], 'DB_PASSWORD' => (string) ($c['password'] ?? ''), 'DB_URL' => '',
            'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array', 'LOG_CHANNEL' => 'null', 'BROADCAST_CONNECTION' => 'null', 'MAIL_MAILER' => 'array',
            'PULSE_ENABLED' => 'false', 'TELESCOPE_ENABLED' => 'false', 'NIGHTWATCH_ENABLED' => 'false', 'LIVE_ENABLED' => 'false', 'OTP_DRIVER' => 'dev',
            'PGGSSENCMODE' => 'disable',
        ]);
        $proc = proc_open([PHP_BINARY, '-d', 'memory_limit=512M', self::WORKER, $args], [0 => ['pipe', 'r'], 1 => ['file', $out, 'w'], 2 => ['file', $err, 'w']], $pipes, base_path(), $env);
        $this->assertIsResource($proc, "no arrancó el trabajador {$etiqueta}");
        fclose($pipes[0]);

        return ['proc' => $proc, 'out' => $out, 'err' => $err];
    }

    /** @param  array{proc: resource, out: string, err: string}  $p @return array<string, mixed> */
    private function collect(string $etiqueta, array $p): array
    {
        $estado = proc_get_status($p['proc']);
        for ($i = 0; $i < 1200 && $estado['running']; $i++) {
            usleep(50_000);
            $estado = proc_get_status($p['proc']);
        }
        if ($estado['running']) {
            proc_terminate($p['proc'], 9);
        }
        proc_close($p['proc']);
        $json = (string) file_get_contents($p['out']);
        $stderr = (string) file_get_contents($p['err']);
        @unlink($p['out']);
        @unlink($p['err']);
        $this->assertFalse($estado['running'], "el trabajador {$etiqueta} no terminó a tiempo. stderr: {$stderr}");
        $salida = json_decode($json, true);
        $this->assertIsArray($salida, "el trabajador {$etiqueta} no devolvió JSON: {$json}\n{$stderr}");
        $this->assertArrayNotHasKey('error', $salida, "el trabajador {$etiqueta} falló: ".json_encode($salida['error'] ?? null)."\n{$stderr}");
        $this->assertSame('pgsql', $salida['driver']);

        return $salida;
    }
}
