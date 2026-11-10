<?php

namespace Tests\Feature\Outreach\Run;

use App\Services\Outreach\Core\RelativeTime;
use App\Services\Outreach\Facts\SubjectKey;
use App\Support\Privacy\PhoneFingerprint;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Outreach\Run\Support\RunWorld;
use Tests\TestCase;

/**
 * §13.1 #13 A NIVEL DE EJECUCIÓN (contrato H9, D9-8 etapas 2 y 5, L9-1,
 * L9-57 Q-5; REV9 M-3, TEST9-c; solo PostgreSQL): DOS TRABAJADORES REALES
 * (dos procesos PHP con la aplicación arrancada, `Support/h13-worker.php`)
 * evalúan la MISMA oportunidad A LA VEZ —salen juntos de una barrera (un
 * cerrojo consultivo de PostgreSQL que la prueba retiene hasta ver a los dos
 * esperando)— por el código de verdad del planificador: barrido, puerta,
 * inscripción y planificación. Queda UNA inscripción (la idempotencia por
 * ocurrencia, `oenr_occurrence_unique`, que el almacén recoge como
 * `already_enrolled`) y UN trabajo (el cerrojo por persona, `ojob_one_live`,
 * recogido como `collisions`), sin filas rotas ni pasadas fallidas; y la
 * segunda vuelta de los dos no cambia nada.
 *
 * `OutreachConcurrencyTest` (DB9) prueba las mismas garantías con filas a
 * pelo (la BASE); `OutreachRaceTest` (B9-run) prueba la reserva (#16). Esta
 * prueba es la que pasa por el PLANIFICADOR. Las filas que los trabajadores
 * tienen que ver se CONFIRMAN por una conexión de apoyo (la transacción de
 * `RefreshDatabase` no las vería) y se borran al final, solo ellas, con los
 * disparadores apagados (base desechable, nunca producción). Nada
 * confirmado se toca desde la conexión de la prueba (memoria del proyecto:
 * un UPDATE suyo dejaría la fila bloqueada para el borrado).
 */
class H9IdempotencyRaceTest extends TestCase
{
    use RefreshDatabase;
    use RunWorld;

    private const CONNECTION = 'h9_test9c_carrera';

    /** Martes 17-11-2026, 10:00 de Bogotá. */
    private const NOW = '2026-11-17 15:00:00';

    /** La clave del cerrojo consultivo de la barrera (cabe en 32 bits: `pg_locks.objid`). */
    private const BARRIER = 913013;

    private const WORKER = __DIR__.'/Support/h13-worker.php';

    private bool $sembrado = false;

    private int $identidadesAntes = 0;

    /** @var array<string, list<int|string>> tabla → claves de las personas sembradas (se borran solo esas filas) */
    private array $personas = ['users' => [], 'members' => [], 'marketing_leads' => [], 'marketing_conversations' => [], 'ultron_studio_canary_numbers' => []];

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
        $this->fixtureConnection = self::CONNECTION;
        $c = DB::connection(self::CONNECTION);
        foreach ([...self::EXECUTION_TABLES, ...self::GOVERNANCE_TABLES] as $tabla) {
            $this->assertSame(0, $c->table($tabla)->count(), "{$tabla} tiene filas confirmadas de otra prueba: no se borran filas ajenas");
        }
        $this->identidadesAntes = (int) $c->table('identities')->max('id');
        Carbon::setTestNow(Carbon::parse(self::NOW, 'UTC'));
        config(['commercial.events_enabled' => true]);
        $this->sembrado = true;
    }

    protected function tearDown(): void
    {
        if ($this->sembrado) {
            $c = DB::connection(self::CONNECTION);
            $c->select('SELECT pg_advisory_unlock_all()');
            $c->statement('SET session_replication_role = replica');
            try {
                // Solo las filas de esta prueba: setUp() comprobó que las tablas del H9 estaban vacías.
                foreach ([...array_reverse(self::EXECUTION_TABLES), ...array_reverse(self::GOVERNANCE_TABLES)] as $tabla) {
                    $tabla === self::T_CURSORS ? $c->table($tabla)->delete() : $c->table($tabla)->where('id', '>', 0)->delete();
                }
                $c->table('identities')->where('id', '>', $this->identidadesAntes)->delete();
                $c->table('ultron_studio_canary_numbers')->whereIn('phone_hash', $this->personas['ultron_studio_canary_numbers'])->delete();
                foreach (['marketing_conversations', 'marketing_leads', 'members', 'users'] as $tabla) {
                    if ($this->personas[$tabla] !== []) {
                        $c->table($tabla)->whereIn('id', $this->personas[$tabla])->delete();
                    }
                }
            } finally {
                $c->statement('SET session_replication_role = DEFAULT');
            }
            DB::purge(self::CONNECTION);
        }
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_13_dos_trabajadores_reales_evaluan_la_misma_oportunidad_a_la_vez_y_queda_una_inscripcion_y_un_trabajo(): void
    {
        $vivo = $this->liveStrategy($this->validContent($this->assistedTemplate()));
        $uuid = $vivo['strategyUuid'];
        $socio = $this->committedQualifyingMember(5, '3001234567');
        $apoyo = DB::connection(self::CONNECTION);

        $salidas = $this->raceRound();
        $barridos = array_map(static fn (array $s): array => $s['sweep']['strategies'][$uuid] ?? [], $salidas);
        $this->assertCount(2, array_filter($barridos), 'los dos barrieron la estrategia: '.json_encode($salidas));
        $this->assertSame(2, array_sum(array_column($barridos, 'candidates')), 'los dos vieron al socio');
        $this->assertSame(1, array_sum(array_column($barridos, 'enrolled')), 'uno inscribe');
        $this->assertSame(1, array_sum(array_column($barridos, 'already_enrolled')), 'el otro se encuentra la ocurrencia ya inscrita (antes de insertar o por el UNIQUE)');
        $planes = array_column($salidas, 'plan');
        $this->assertSame(1, array_sum(array_column($planes, 'planned')), 'uno planifica');
        $this->assertLessThanOrEqual(1, array_sum(array_column($planes, 'collisions')), 'el otro, o ya no la ve vencida (tiene trabajo) o choca con el cerrojo por persona');
        $this->assertSame(0, array_sum(array_column($planes, 'canceled')) + array_sum(array_column($planes, 'denied')) + array_sum(array_column($planes, 'deferred')));

        $this->assertSame(1, $apoyo->table('outreach_enrollments')->count());
        $inscripcion = $apoyo->table('outreach_enrollments')->first();
        $this->assertSame($socio['key'], $inscripcion->subject_key);
        $this->assertSame('active', $inscripcion->status);
        $this->assertSame('shadow', $inscripcion->mode);
        $this->assertSame(1, $apoyo->table('outreach_jobs')->count());
        $trabajo = $apoyo->table('outreach_jobs')->first();
        $this->assertSame('planned', $trabajo->status);
        $this->assertSame('simulated', $trabajo->channel);
        $this->assertSame($socio['key'], $trabajo->subject_key);
        $this->assertSame((int) $inscripcion->id, (int) $trabajo->enrollment_id);
        $this->assertSame(1, $apoyo->table('outreach_decisions')->where('kind', 'enroll')->count());
        $this->assertSame(1, $apoyo->table('outreach_decisions')->where('kind', 'plan')->count());
        $this->assertSame(2, $apoyo->table('outreach_runs')->where('kind', 'sweep')->where('status', 'completed')->count(), 'cada trabajador deja su pasada, completada');
        $this->assertSame(0, $apoyo->table('outreach_runs')->where('status', 'failed')->count());
        $this->assertStringNotContainsString('3001234567', json_encode($salidas), 'ni teléfonos en lo que devuelven las pasadas');

        // Otra vez los dos a la vez: nada nuevo (la ocurrencia ya está inscrita y el trabajo vive).
        $salidas = $this->raceRound();
        foreach ($salidas as $s) {
            $this->assertSame(0, $s['sweep']['strategies'][$uuid]['enrolled'], $s['label']);
            $this->assertSame(1, $s['sweep']['strategies'][$uuid]['already_enrolled'], $s['label']);
            $this->assertSame(0, $s['plan']['due'], $s['label']);
        }
        $this->assertSame([1, 1], [$apoyo->table('outreach_enrollments')->count(), $apoyo->table('outreach_jobs')->count()]);
        $this->assertSame(4, $apoyo->table('outreach_runs')->where('kind', 'sweep')->where('status', 'completed')->count());
    }

    // ── La carrera ──────────────────────────────────────────────────────────

    /**
     * Lanza dos trabajadores, espera a verlos a los dos en la barrera, la
     * suelta y recoge lo que devuelven.
     *
     * @return list<array<string, mixed>>
     */
    private function raceRound(): array
    {
        $apoyo = DB::connection(self::CONNECTION);
        $apoyo->select('SELECT pg_advisory_lock(?)', [self::BARRIER]);
        $procesos = [];
        foreach (['A', 'B'] as $etiqueta) {
            $procesos[$etiqueta] = $this->spawnWorker($etiqueta);
        }
        // Los dos cerrojos compartidos sin conceder: los dos trabajadores esperan en la barrera ANTES de soltarla (la carrera es real).
        $esperando = 0;
        for ($i = 0; $i < 600 && $esperando < 2; $i++) {
            $esperando = (int) $apoyo->scalar("SELECT count(*) FROM pg_locks WHERE locktype = 'advisory' AND objid = ? AND NOT granted", [self::BARRIER]);
            if ($esperando < 2) {
                usleep(50_000);
            }
        }
        if ($esperando !== 2) {
            $apoyo->select('SELECT pg_advisory_unlock(?)', [self::BARRIER]);
            $this->fail('Los dos trabajadores tenían que estar esperando en la barrera: '.$this->stderrOf($procesos));
        }
        $apoyo->select('SELECT pg_advisory_unlock(?)', [self::BARRIER]);
        $salidas = [];
        foreach ($procesos as $etiqueta => $p) {
            $salidas[] = $this->collect($etiqueta, $p);
        }

        return $salidas;
    }

    /** @return array{proc: resource, out: string, err: string} */
    private function spawnWorker(string $etiqueta): array
    {
        $out = (string) tempnam(sys_get_temp_dir(), "h13-{$etiqueta}-out-");
        $err = (string) tempnam(sys_get_temp_dir(), "h13-{$etiqueta}-err-");
        $args = json_encode(['now' => self::NOW, 'barrier' => self::BARRIER, 'mode' => 'shadow', 'label' => $etiqueta], JSON_THROW_ON_ERROR);
        $proc = proc_open(
            [PHP_BINARY, '-d', 'memory_limit=512M', self::WORKER, $args],
            [0 => ['pipe', 'r'], 1 => ['file', $out, 'w'], 2 => ['file', $err, 'w']],
            $pipes,
            base_path(),
            $this->workerEnv(),
        );
        $this->assertIsResource($proc, "no arrancó el trabajador {$etiqueta}");
        fclose($pipes[0]);

        return ['proc' => $proc, 'out' => $out, 'err' => $err];
    }

    /** El entorno del trabajador: la MISMA base y la MISMA APP_KEY que la prueba (la cadena firmada tiene que verificar), todo lo demás como la suite. @return array<string, string> */
    private function workerEnv(): array
    {
        $c = (array) config('database.connections.'.config('database.default'));

        return array_merge(getenv(), [
            'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => (string) config('app.key'),
            'DB_CONNECTION' => 'pgsql', 'DB_HOST' => (string) ($c['host'] ?? '127.0.0.1'), 'DB_PORT' => (string) ($c['port'] ?? 5432),
            'DB_DATABASE' => (string) $c['database'], 'DB_USERNAME' => (string) $c['username'], 'DB_PASSWORD' => (string) ($c['password'] ?? ''), 'DB_URL' => '',
            'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array', 'LOG_CHANNEL' => 'null', 'BROADCAST_CONNECTION' => 'null', 'MAIL_MAILER' => 'array',
            'PULSE_ENABLED' => 'false', 'TELESCOPE_ENABLED' => 'false', 'NIGHTWATCH_ENABLED' => 'false', 'LIVE_ENABLED' => 'false', 'OTP_DRIVER' => 'dev',
            'COMMERCIAL_EVENTS_ENABLED' => 'true', 'COMMERCIAL_AUTOMATION_ENABLED' => 'true', 'COMMERCIAL_AUTOMATION_MODE' => 'shadow',
            'COMMERCIAL_AUTOMATION_ASSISTED_ENABLED' => 'true', 'COMMERCIAL_AUTOMATION_ASSISTED_DAILY_CAP' => '20',
            'PGGSSENCMODE' => 'disable',
        ]);
    }

    /** Espera al trabajador (≤ 60 s), lee su JSON y falla con su stderr si no devolvió lo que debía. @param  array{proc: resource, out: string, err: string}  $p @return array<string, mixed> */
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
        $this->assertSame('pgsql', $salida['driver'], $etiqueta);

        return $salida;
    }

    /** @param  array<string, array{proc: resource, out: string, err: string}>  $procesos */
    private function stderrOf(array $procesos): string
    {
        $texto = '';
        foreach ($procesos as $etiqueta => $p) {
            proc_terminate($p['proc'], 9);
            proc_close($p['proc']);
            $texto .= "[{$etiqueta}] ".(string) file_get_contents($p['err']).' '.(string) file_get_contents($p['out'])."\n";
            @unlink($p['out']);
            @unlink($p['err']);
        }

        return $texto;
    }

    // ── Las filas CONFIRMADAS de la persona (por la conexión de apoyo) ──────

    /**
     * Un socio que califica (vence en `$days` días, prospecto de WhatsApp
     * autorizado, conversación y número canario), confirmado por la conexión
     * de apoyo: los trabajadores son otros procesos.
     *
     * @return array{member: int, key: string}
     */
    private function committedQualifyingMember(int $days, string $phone): array
    {
        $c = DB::connection(self::CONNECTION);
        $n = count($this->personas['users']) + 1;
        $fin = (new DateTimeImmutable(RelativeTime::bogotaDate(self::utcAt(self::NOW)), new DateTimeZone('America/Bogota')))->modify("+{$days} days")->format('Y-m-d');
        $creado = '2026-01-01 12:00:00';
        $user = (int) $c->table('users')->insertGetId([
            'name' => "Socio carrera {$n}", 'email' => "carrera{$n}-".Str::random(6).'@run.test', 'password' => 'x',
            'plan' => 'Mensual', 'membership_end_date' => $fin, 'created_at' => $creado, 'updated_at' => $creado,
        ]);
        $member = (int) $c->table('members')->insertGetId([
            'member_uuid' => (string) Str::uuid(), 'access_hash' => hash('sha256', 'carrera'.$n.Str::random(8)), 'full_name' => "Socio carrera {$n}",
            'document_number' => 'CAR'.$n.Str::random(6), 'user_id' => $user, 'birth_date' => '1990-05-05', 'is_minor' => false,
            'status' => 'active', 'created_at' => $creado, 'updated_at' => $creado,
        ]);
        $lead = (int) $c->table('marketing_leads')->insertGetId([
            'channel' => 'whatsapp', 'phone' => $phone, 'status' => 'new', 'temperature' => 'warm', 'consent_status' => 'granted',
            'do_not_contact' => false, 'member_id' => $member, 'created_at' => $creado, 'updated_at' => $creado,
        ]);
        $conversacion = (int) $c->table('marketing_conversations')->insertGetId([
            'lead_id' => $lead, 'channel' => 'whatsapp', 'status' => 'open', 'human_takeover' => false, 'ai_enabled' => true,
            'created_at' => $creado, 'updated_at' => $creado,
        ]);
        $huella = PhoneFingerprint::of('+57'.$phone);
        $c->table('ultron_studio_canary_numbers')->insert([
            'uuid' => (string) Str::uuid(), 'client_request_id' => (string) Str::uuid(), 'channel' => 'whatsapp', 'alias' => 'Canario carrera',
            'phone_hash' => $huella, 'phone_last4' => substr($phone, -4), 'country_code' => 'CO', 'phone_encrypted' => null, 'status' => 'active',
            'consent_attested' => true, 'registered_by_admin_id' => 1, 'registered_by_name' => 'Ana', 'authorized_by_admin_id' => 2, 'authorized_by_name' => 'Beto',
            'authorized_at' => '2026-11-01 12:00:00', 'activated_by_admin_id' => 2, 'activated_by_name' => 'Beto', 'activated_at' => '2026-11-01 12:00:00',
            'status_changed_at' => '2026-11-01 12:00:00', 'created_at' => '2026-11-01 12:00:00', 'updated_at' => '2026-11-01 12:00:00',
        ]);
        $this->personas['users'][] = $user;
        $this->personas['members'][] = $member;
        $this->personas['marketing_leads'][] = $lead;
        $this->personas['marketing_conversations'][] = $conversacion;
        $this->personas['ultron_studio_canary_numbers'][] = $huella;

        return ['member' => $member, 'key' => SubjectKey::member($member)];
    }
}
