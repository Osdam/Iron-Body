<?php

namespace Tests\Feature\UltronStudio;

use App\Models\Admin;
use App\Models\UltronStudio\StudioVoiceLabSession;
use App\Services\UltronStudio\StudioVoiceLabSessions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * LA RESERVA ATÓMICA DEL LABORATORIO HABLADO con DOS SESIONES de PostgreSQL
 * de verdad (contrato H8, protección 12 y D8-23: «una reserva atómica como el
 * H4»): dos aperturas que juntas superan el techo del día, o el cupo de
 * sesiones de la persona, dan un 201 y un 409, nunca dos 201.
 *
 * Un proceso hijo, con SU conexión, toma el cerrojo del presupuesto, inserta
 * una sesión propia (con su agente de laboratorio, sobre una versión y un
 * perfil ya confirmados) y la CONFIRMA solo cuando ve en `pg_locks` que la
 * petición espera ese cerrojo. Con el cerrojo, la petición lee lo comprometido
 * y el cupo DESPUÉS de esa confirmación y responde 409. Sin él (la mutación
 * «sin cerrojo»), no espera, no la ve y abre una segunda sesión.
 *
 * Además, sobre el SQL: READ COMMITTED primero y el cerrojo antes de leer lo
 * comprometido; y READ COMMITTED aunque la sesión vaya en REPEATABLE READ (la
 * lección B-2 de G).
 *
 * Solo PostgreSQL: SQLite no tiene cerrojos consultivos y escribe en serie.
 * La versión, el perfil y lo del hijo se confirman fuera de la transacción de
 * la prueba (y esas tablas prohíben DELETE), así que al terminar se pide que
 * la siguiente prueba vuelva a crear el esquema.
 */
class UltronStudioVoiceLabBudgetLockTest extends TestCase
{
    use PublicationFixtures;
    use RefreshDatabase;
    use StudioFixtures;
    use VoiceLabFixtures;

    private const LOCK_KEY = 'ultron_studio_voice_lab:budget';

    private Admin $tina;

    /** @var resource|null */
    private $hijo = null;

    /** @var array<int, resource> */
    private array $tuberias = [];

    private bool $confirmoFuera = false;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Dos sesiones y un cerrojo consultivo: solo PostgreSQL (SQLite escribe en serie).');
        }
        Http::preventStrayRequests();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->voiceLabRoutes();
        $this->importBaseline();
        $this->tina = $this->tester();
        DB::statement("SET lock_timeout = '30s'");
    }

    protected function tearDown(): void
    {
        $this->cerrarHijo();
        parent::tearDown();
        if ($this->confirmoFuera) {
            RefreshDatabaseState::$migrated = false;
        }
    }

    public function test_dos_sesiones_que_juntas_superan_el_techo_dan_un_201_y_un_409(): void
    {
        $this->voiceLabElevenLabs();
        $this->voiceVerified();
        [$version, $perfil] = $this->confirmedVersionAndProfile();

        // La otra sesión reserva todo el día menos 1000 micro-US$ y confirma cuando la petición espere.
        $this->otraSesionCuandoEspere($version, $perfil, 2000000 - 1000, $this->tina->id + 1000);
        $r = $this->openSession($this->tina, $version['uuid'], $perfil['uuid'], null, 500000);
        $hijo = $this->esperarHijo();

        $this->assertSame(1, $hijo['waiter'], 'POST voice-lab/sessions no esperó el cerrojo del presupuesto: '.$hijo['salida']);
        $r->assertStatus(409)
            ->assertJsonPath('code', 'voice_lab_daily_budget_exceeded')
            ->assertJsonPath('requiredMicros', 300000)
            ->assertJsonPath('remainingMicros', 1000);
        $this->assertSame(1, StudioVoiceLabSession::query()->count(), 'solo la de la otra sesión');
        Http::assertNothingSent();
    }

    public function test_dos_sesiones_de_la_misma_persona_con_un_solo_hueco_dan_un_201_y_un_409(): void
    {
        $this->voiceLabFake(['voice.lab.max_sessions_per_day' => 1]);
        [$version, $perfil] = $this->confirmedVersionAndProfile();

        // La otra petición de la MISMA persona ocupa el único hueco del día.
        $this->otraSesionCuandoEspere($version, $perfil, 0, $this->tina->id);
        $r = $this->openSession($this->tina, $version['uuid'], $perfil['uuid'], null, 0);
        $hijo = $this->esperarHijo();

        $this->assertSame(1, $hijo['waiter'], 'POST voice-lab/sessions no esperó el cerrojo: '.$hijo['salida']);
        $r->assertStatus(409)
            ->assertJsonPath('code', 'voice_lab_daily_sessions_exceeded')
            ->assertJsonPath('sessionsToday', 1)
            ->assertJsonPath('maxSessionsPerDay', 1);
        $this->assertSame(1, StudioVoiceLabSession::query()->count());
    }

    public function test_read_committed_primero_y_el_cerrojo_antes_de_leer_lo_comprometido(): void
    {
        $this->voiceLabFake();
        $ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana Autora');
        $version = $this->labVersion($ana)->uuid;
        $perfil = $this->seedProfile($ana, null, 'submitted');

        $sentencias = [];
        DB::listen(function ($q) use (&$sentencias): void {
            $sentencias[] = [$q->sql, $q->bindings];
        });
        $this->openSession($this->tina, $version, $perfil, null, 0)->assertCreated();

        $nivel = $cerrojo = $cupo = $lee = $agente = $sesion = null;
        foreach ($sentencias as $i => [$sql, $bindings]) {
            if ($nivel === null && $sql === 'SET TRANSACTION ISOLATION LEVEL READ COMMITTED') {
                $nivel = $i;
            }
            if ($cerrojo === null && str_contains($sql, 'pg_advisory_xact_lock') && $bindings === [crc32(self::LOCK_KEY)]) {
                $cerrojo = $i;
            }
            if ($cerrojo !== null && $cupo === null && str_starts_with($sql, 'select count(*) as aggregate from "ultron_studio_voice_lab_sessions"')) {
                $cupo = $i;
            }
            if ($cerrojo !== null && $lee === null && str_contains($sql, 'COALESCE(SUM(') && str_contains($sql, '"ultron_studio_voice_lab_sessions"')) {
                $lee = $i;
            }
            if ($agente === null && str_starts_with($sql, 'insert into "ultron_studio_voice_agents"')) {
                $agente = $i;
            }
            if ($sesion === null && str_starts_with($sql, 'insert into "ultron_studio_voice_lab_sessions"')) {
                $sesion = $i;
            }
        }
        $this->assertNotNull($cerrojo, 'POST voice-lab/sessions no toma el cerrojo del presupuesto');
        $this->assertNotNull($nivel);
        $this->assertLessThan($cerrojo, $nivel, 'READ COMMITTED va antes del cerrojo');
        $this->assertNotNull($cupo, 'no se contó el cupo DESPUÉS del cerrojo');
        $this->assertNotNull($lee, 'no se leyó lo comprometido DESPUÉS del cerrojo');
        $this->assertLessThan($agente, $lee);
        $this->assertLessThan($sesion, $agente);
    }

    public function test_el_cerrojo_va_en_read_committed_aunque_la_sesion_vaya_en_repeatable_read(): void
    {
        // Una sesión PROPIA, fuera de la transacción de la prueba, con REPEATABLE
        // READ por defecto, como la dejaría un `default_transaction_isolation`
        // en el rol (lección B-2 de G).
        config(['database.connections.voice_lab_rr' => config('database.connections.'.DB::getDefaultConnection())]);
        $rr = DB::connection('voice_lab_rr');
        $rr->statement('SET SESSION CHARACTERISTICS AS TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $rr->statement("SET lock_timeout = '5s'");
        $this->assertSame('repeatable read', $rr->transaction(static fn () => $rr->selectOne('SHOW transaction_isolation')->transaction_isolation));

        $sesiones = app(StudioVoiceLabSessions::class);
        $cerrojo = new \ReflectionMethod($sesiones, 'serializeBudget');
        $porDefecto = DB::getDefaultConnection();
        DB::setDefaultConnection('voice_lab_rr');
        try {
            $nivel = $rr->transaction(function () use ($cerrojo, $sesiones, $rr): string {
                $cerrojo->invoke($sesiones);

                return $rr->selectOne('SHOW transaction_isolation')->transaction_isolation;
            });
        } finally {
            DB::setDefaultConnection($porDefecto);
            DB::purge('voice_lab_rr');
        }
        $this->assertSame('read committed', $nivel);
    }

    /**
     * Una versión enviada a revisión y un perfil `submitted`, CONFIRMADOS por
     * otra conexión: el hijo los referencia desde la suya.
     *
     * @return array{0:array{id:int,uuid:string},1:array{id:int,uuid:string}}
     */
    private function confirmedVersionAndProfile(): array
    {
        $ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana Autora');
        config(['database.connections.voice_lab_seed' => config('database.connections.'.DB::getDefaultConnection())]);
        $porDefecto = DB::getDefaultConnection();
        DB::setDefaultConnection('voice_lab_seed');
        $this->confirmoFuera = true;
        try {
            $version = $this->seedVersion($ana, $this->baseline(), 'REVIEW_REQUIRED');
            $perfil = $this->seedProfile($ana, null, 'submitted');
            $idPerfil = (int) DB::table('ultron_studio_voice_profiles')->where('uuid', $perfil)->value('id');
        } finally {
            DB::setDefaultConnection($porDefecto);
            DB::purge('voice_lab_seed');
        }

        return [['id' => (int) $version->id, 'uuid' => (string) $version->uuid], ['id' => $idPerfil, 'uuid' => $perfil]];
    }

    /**
     * Un proceso hijo con SU conexión: BEGIN, el cerrojo del presupuesto, un
     * agente de laboratorio y una sesión abierta de HOY (día de Bogotá) con
     * `$reserva` micro-US$ reservados y su URL; en cuanto otra sesión espera
     * ese cerrojo, COMMIT. Si nadie espera en 10 s, confirma igualmente y lo
     * dice.
     *
     * @param  array{id:int,uuid:string}  $version
     * @param  array{id:int,uuid:string}  $perfil
     */
    private function otraSesionCuandoEspere(array $version, array $perfil, int $reserva, int $persona): void
    {
        $cfg = DB::connection()->getConfig();
        $orden = [
            'dsn' => sprintf('pgsql:host=%s;port=%s;dbname=%s', $cfg['host'], $cfg['port'], $cfg['database']),
            'user' => $cfg['username'],
            'password' => $cfg['password'],
            'key' => crc32(self::LOCK_KEY),
            'day' => StudioVoiceLabSessions::budgetDay(),
            'reserve' => $reserva,
            'person' => $persona,
            'version' => $version['id'],
            'profile' => $perfil['id'],
            'agent' => [(string) Str::uuid(), (string) Str::uuid()],
            'session' => [(string) Str::uuid(), (string) Str::uuid()],
        ];
        $propios = (int) DB::scalar("SELECT count(*) FROM pg_locks WHERE locktype = 'advisory' AND pid = pg_backend_pid() AND objid = ".crc32(self::LOCK_KEY));
        $this->assertSame(0, $propios, 'La sesión de la prueba ya tiene el cerrojo del presupuesto: el hijo no podría tomarlo.');

        $script = <<<'PHP'
// Las columnas son `timestamp without time zone` con la hora de pared UTC que
// escribe Eloquent: el hijo escribe igual (`now()` iría en la zona del servidor).
$o = json_decode(getenv('ULTRON_STUDIO_VOICE_LAB_TEST_CHILD'), true);
$pdo = new PDO($o['dsn'], $o['user'], $o['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("SET lock_timeout = '15s'");
$pdo->beginTransaction();
$pdo->prepare('SELECT pg_advisory_xact_lock(?)')->execute([$o['key']]);
$a = $pdo->prepare("INSERT INTO ultron_studio_voice_agents (uuid, client_request_id, purpose, studio_version_id, studio_content_hash, voice_profile_id, voice_profile_hash, compiler_version, spec_hash, payload_hash, projection_hash, provider, status, serves_canary, serves_rest, created_by_admin_id, created_by_name, created_at, status_changed_at, updated_at)
    VALUES (?, ?, 'lab', ?, repeat('a', 64), ?, repeat('b', 64), 'ultron-voice-compiler/1', repeat('c', 64), repeat('d', 64), repeat('e', 64), 'elevenlabs', 'pending', false, false, ?, 'Otra sesión', (now() AT TIME ZONE 'UTC'), (now() AT TIME ZONE 'UTC'), (now() AT TIME ZONE 'UTC')) RETURNING id");
$a->execute([$o['agent'][0], $o['agent'][1], $o['version'], $o['profile'], $o['person']]);
$agente = (int) $a->fetchColumn();
$pdo->prepare("INSERT INTO ultron_studio_voice_lab_sessions (uuid, client_request_id, agent_id, status, budget_day, reserved_micros, max_seconds, url_expires_at, started_at, created_by_admin_id, created_by_name, created_at, status_changed_at, updated_at)
    VALUES (?, ?, ?, 'open', ?, ?, 180, (now() AT TIME ZONE 'UTC') + interval '15 minutes', (now() AT TIME ZONE 'UTC'), ?, 'Otra sesión', (now() AT TIME ZONE 'UTC'), (now() AT TIME ZONE 'UTC'), (now() AT TIME ZONE 'UTC'))")
    ->execute([$o['session'][0], $o['session'][1], $agente, $o['day'], $o['reserve'], $o['person']]);
fwrite(STDOUT, "LOCKED\n");
fflush(STDOUT);
$espera = $pdo->prepare("SELECT count(*) FROM pg_locks WHERE locktype = 'advisory' AND NOT granted AND pid <> pg_backend_pid() AND database = (SELECT oid FROM pg_database WHERE datname = current_database())");
$waiter = 0;
$hasta = microtime(true) + 10;
while (microtime(true) < $hasta) {
    $espera->execute();
    if ((int) $espera->fetchColumn() > 0) {
        $waiter = 1;
        break;
    }
    usleep(10000);
}
$pdo->commit();
fwrite(STDOUT, "WAITER={$waiter}\nCOMMITTED\n");
PHP;

        $this->hijo = proc_open([PHP_BINARY, '-r', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $this->tuberias, null, ['ULTRON_STUDIO_VOICE_LAB_TEST_CHILD' => json_encode($orden)] + getenv());
        if (! is_resource($this->hijo)) {
            throw new RuntimeException('No se pudo lanzar la otra sesión.');
        }

        $leer = [$this->tuberias[1]];
        $nada = null;
        $linea = stream_select($leer, $nada, $nada, 20) === 1 ? fgets($this->tuberias[1]) : false;
        if ($linea !== "LOCKED\n") {
            throw new RuntimeException('La otra sesión no tomó el cerrojo: '.$linea.stream_get_contents($this->tuberias[2]));
        }
    }

    /** @return array{waiter:int,salida:string} */
    private function esperarHijo(): array
    {
        $salida = stream_get_contents($this->tuberias[1]).stream_get_contents($this->tuberias[2]);
        $this->cerrarHijo();
        preg_match('/WAITER=(\d)/', $salida, $m);

        return ['waiter' => (int) ($m[1] ?? -1), 'salida' => $salida];
    }

    private function cerrarHijo(): void
    {
        foreach ($this->tuberias as $t) {
            if (is_resource($t)) {
                fclose($t);
            }
        }
        $this->tuberias = [];
        if (is_resource($this->hijo)) {
            proc_close($this->hijo);
        }
        $this->hijo = null;
    }
}
