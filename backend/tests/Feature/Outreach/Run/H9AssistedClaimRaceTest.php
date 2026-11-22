<?php

namespace Tests\Feature\Outreach\Run;

use App\Services\Outreach\Facts\SubjectKey;
use App\Support\Privacy\PhoneFingerprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Outreach\Run\Support\RunWorld;
use Tests\TestCase;

/**
 * R3-2 de `informe-security-bandeja-ronda3.md` CON DOS CONEXIONES DE VERDAD (solo PostgreSQL): un TRABAJADOR
 * REAL (un proceso PHP con la aplicación arrancada, `Support/assisted-claim-worker.php`, sin transacción que lo
 * envuelva, como una petición) reclama un trabajo de la bandeja. Se para en una BARRERA (un cerrojo consultivo
 * que la prueba retiene) justo después de la última lectura de la comprobación y ANTES del `UPDATE`; mientras
 * espera, la prueba CONFIRMA por su conexión un «no contactar» del prospecto o la pausa de la estrategia en la
 * cadena, y suelta la barrera. El trabajador escribe la reclamación y su 201 ya no lleva ni el enlace ni el
 * guion: la relectura completa tras el `UPDATE` ve, en READ COMMITTED, lo que la otra conexión confirmó.
 * Sin señal (el control), el 201 lleva el contacto.
 *
 * Las filas que el trabajador tiene que ver se CONFIRMAN por una conexión de apoyo (la transacción de
 * `RefreshDatabase` no las vería) y se borran al final, solo ellas, con los disparadores apagados (base
 * desechable, nunca producción). La conexión de la prueba no escribe nada confirmado (memoria del proyecto:
 * un UPDATE suyo dejaría la fila bloqueada para el borrado). Como `H9IdempotencyRaceTest`.
 */
class H9AssistedClaimRaceTest extends TestCase
{
    use RefreshDatabase;
    use RunWorld;

    private const CONNECTION = 'h9_bandeja_carrera';

    /** Martes 17-11-2026, 10:00 de Bogotá; el trabajador reclama a las 10:15. */
    private const NOW = '2026-11-17 15:00:00';

    private const CLAIM_AT = '2026-11-17 15:15:00';

    /** La clave del cerrojo consultivo de la barrera (cabe en 32 bits: `pg_locks.objid`). */
    private const BARRIER = 913032;

    private const WORKER = __DIR__.'/Support/assisted-claim-worker.php';

    /** La cuenta que reclama en el trabajador (no hace falta su fila: `claim()` solo usa el id y el nombre). */
    private const CLAIMER = 902;

    private bool $sembrado = false;

    private int $identidadesAntes = 0;

    /** @var array<string, list<int|string>> tabla → claves de la persona sembrada (se borran solo esas filas) */
    private array $personas = ['users' => [], 'members' => [], 'marketing_leads' => [], 'marketing_conversations' => [], 'ultron_studio_canary_numbers' => []];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Solo PostgreSQL: la carrera necesita dos procesos contra una base real.');
        }
        if (! function_exists('proc_open')) {
            $this->markTestSkipped('Sin proc_open no hay trabajador.');
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

    /** @return array<string, array{0: string, 1: string|null}> */
    public static function senales(): array
    {
        return [
            'un «no contactar» del prospecto, confirmado por la otra conexión' => ['dnc', 'opted_out'],
            'la pausa de la estrategia en la cadena, confirmada por la otra conexión' => ['pause', 'strategy_stopped'],
            'nada entre medias (el control)' => ['none', null],
        ];
    }

    #[DataProvider('senales')]
    public function test_r3_2_lo_que_otra_conexion_confirma_entre_la_comprobacion_y_el_update_no_entrega_el_contacto(string $senal, ?string $motivo): void
    {
        $vivo = $this->liveStrategy($this->validContent($this->assistedTemplate(), ['exclusions' => null]));
        $persona = $this->committedMember('3001234567');
        $trabajo = $this->committedStartedJob($vivo, $persona);
        $apoyo = DB::connection(self::CONNECTION);

        // La barrera, cogida ANTES de lanzar al trabajador.
        $apoyo->select('SELECT pg_advisory_lock(?)', [self::BARRIER]);
        $p = $this->spawnWorker($trabajo['uuid']);
        $esperando = 0;
        for ($i = 0; $i < 600 && $esperando < 1; $i++) {
            $esperando = (int) $apoyo->scalar("SELECT count(*) FROM pg_locks WHERE locktype = 'advisory' AND objid = ? AND NOT granted", [self::BARRIER]);
            if ($esperando < 1) {
                usleep(50_000);
            }
        }
        if ($esperando !== 1) {
            $apoyo->select('SELECT pg_advisory_unlock(?)', [self::BARRIER]);
            $this->fail('El trabajador tenía que estar esperando en la barrera: '.$this->stderrOf($p));
        }
        $this->assertNull($apoyo->table('outreach_jobs')->where('id', $trabajo['id'])->value('assisted_claimed_by_admin_id'), 'en la barrera, todavía sin reclamar');

        // Con el trabajador ya comprobado y parado ANTES de su UPDATE, la otra conexión CONFIRMA la señal.
        match ($senal) {
            'dnc' => $apoyo->table('marketing_leads')->where('id', $persona['lead'])->update(['do_not_contact' => true, 'updated_at' => now()]),
            'pause' => $this->pauseStrategy($vivo),
            'none' => null,
        };
        $apoyo->select('SELECT pg_advisory_unlock(?)', [self::BARRIER]);
        $salida = $this->collect($p);

        $this->assertSame(0, $salida['inTransaction'], 'el trabajador, sin transacción que lo envuelva (como una petición)');
        $this->assertNotNull($salida['barrierAt'], 'el trabajador pasó por la barrera');
        $this->assertLessThan($salida['updateAt'], $salida['barrierAt'], 'la barrera, después de comprobar y ANTES del UPDATE');
        $r = $salida['claim'];
        $this->assertFalse($r['replayed']);
        $this->assertSame(self::CLAIMER, (int) $apoyo->table('outreach_jobs')->where('id', $trabajo['id'])->value('assisted_claimed_by_admin_id'), 'la reclamación queda escrita y confirmada: no se deshace nada');
        if ($motivo === null) {
            $this->assertSame([['kind' => 'member', 'id' => $persona['member']], true, null], [$r['job']['person'], is_string($r['job']['script']), $r['job']['contactWithheld']], 'sin señal, el 201 lleva el contacto');
        } else {
            $this->assertSame([null, null, $motivo], [$r['job']['person'], $r['job']['script'], $r['job']['contactWithheld']], "{$senal}: el 201 ya no lleva ni el enlace ni el guion");
        }
        $this->assertStringNotContainsString('3001234567', json_encode($salida));
    }

    // ── El trabajador ───────────────────────────────────────────────────────

    /** @return array{proc: resource, out: string, err: string} */
    private function spawnWorker(string $jobUuid): array
    {
        $out = (string) tempnam(sys_get_temp_dir(), 'r32-out-');
        $err = (string) tempnam(sys_get_temp_dir(), 'r32-err-');
        $args = json_encode([
            'now' => self::CLAIM_AT, 'barrier' => self::BARRIER, 'job' => $jobUuid, 'clientRequestId' => (string) Str::uuid(),
            'adminId' => self::CLAIMER, 'adminName' => 'Beto',
        ], JSON_THROW_ON_ERROR);
        $proc = proc_open(
            [PHP_BINARY, '-d', 'memory_limit=512M', self::WORKER, $args],
            [0 => ['pipe', 'r'], 1 => ['file', $out, 'w'], 2 => ['file', $err, 'w']],
            $pipes,
            base_path(),
            $this->workerEnv(),
        );
        $this->assertIsResource($proc, 'no arrancó el trabajador');
        fclose($pipes[0]);

        return ['proc' => $proc, 'out' => $out, 'err' => $err];
    }

    /** El entorno del trabajador: la MISMA base y la MISMA APP_KEY que la prueba (la cadena firmada tiene que verificar). @return array<string, string> */
    private function workerEnv(): array
    {
        $c = (array) config('database.connections.'.config('database.default'));

        return array_merge(getenv(), [
            'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => (string) config('app.key'),
            'DB_CONNECTION' => 'pgsql', 'DB_HOST' => (string) ($c['host'] ?? '127.0.0.1'), 'DB_PORT' => (string) ($c['port'] ?? 5432),
            'DB_DATABASE' => (string) $c['database'], 'DB_USERNAME' => (string) $c['username'], 'DB_PASSWORD' => (string) ($c['password'] ?? ''), 'DB_URL' => '',
            'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array', 'LOG_CHANNEL' => 'null', 'BROADCAST_CONNECTION' => 'null', 'MAIL_MAILER' => 'array',
            'PULSE_ENABLED' => 'false', 'TELESCOPE_ENABLED' => 'false', 'NIGHTWATCH_ENABLED' => 'false', 'LIVE_ENABLED' => 'false', 'OTP_DRIVER' => 'dev',
            'PGGSSENCMODE' => 'disable',
        ]);
    }

    /** Espera al trabajador (≤ 60 s), lee su JSON y falla con su stderr si no devolvió lo que debía. @param  array{proc: resource, out: string, err: string}  $p @return array<string, mixed> */
    private function collect(array $p): array
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
        $this->assertFalse($estado['running'], "el trabajador no terminó a tiempo. stderr: {$stderr}");
        $salida = json_decode($json, true);
        $this->assertIsArray($salida, "el trabajador no devolvió JSON: {$json}\n{$stderr}");
        $this->assertArrayNotHasKey('error', $salida, 'el trabajador falló: '.json_encode($salida['error'] ?? null)."\n{$stderr}");
        $this->assertSame('pgsql', $salida['driver']);

        return $salida;
    }

    /** @param  array{proc: resource, out: string, err: string}  $p */
    private function stderrOf(array $p): string
    {
        proc_terminate($p['proc'], 9);
        proc_close($p['proc']);
        $texto = (string) file_get_contents($p['err']).' '.(string) file_get_contents($p['out']);
        @unlink($p['out']);
        @unlink($p['err']);

        return $texto;
    }

    // ── Las filas CONFIRMADAS (por la conexión de apoyo) ────────────────────

    /**
     * Un socio con su prospecto de WhatsApp autorizado y su conversación, confirmado por la conexión de apoyo
     * (el trabajador es otro proceso), como `H9IdempotencyRaceTest::committedQualifyingMember()`.
     *
     * @return array{member: int, lead: int, key: string}
     */
    private function committedMember(string $phone): array
    {
        $c = DB::connection(self::CONNECTION);
        $n = count($this->personas['users']) + 1;
        $creado = '2026-01-01 12:00:00';
        $user = (int) $c->table('users')->insertGetId([
            'name' => "Socio bandeja {$n}", 'email' => "bandeja{$n}-".Str::random(6).'@run.test', 'password' => 'x',
            'plan' => 'Mensual', 'membership_end_date' => '2026-11-22', 'created_at' => $creado, 'updated_at' => $creado,
        ]);
        $member = (int) $c->table('members')->insertGetId([
            'member_uuid' => (string) Str::uuid(), 'access_hash' => hash('sha256', 'bandeja'.$n.Str::random(8)), 'full_name' => "Socio bandeja {$n}",
            'document_number' => 'BAN'.$n.Str::random(6), 'user_id' => $user, 'birth_date' => '1990-05-05', 'is_minor' => false,
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
        $this->personas['users'][] = $user;
        $this->personas['members'][] = $member;
        $this->personas['marketing_leads'][] = $lead;
        $this->personas['marketing_conversations'][] = $conversacion;
        // F-2: su número, canario ACTIVO de WhatsApp (la parte canario y la línea base «solo canario» solo salen a eso).
        $huella = PhoneFingerprint::of('+57'.$phone);
        $c->table('ultron_studio_canary_numbers')->insert([
            'uuid' => (string) Str::uuid(), 'client_request_id' => (string) Str::uuid(), 'channel' => 'whatsapp', 'alias' => 'Canario carrera',
            'phone_hash' => $huella, 'phone_last4' => substr($phone, -4), 'country_code' => 'CO', 'phone_encrypted' => null, 'status' => 'active',
            'consent_attested' => true, 'registered_by_admin_id' => 1, 'registered_by_name' => 'Ana', 'authorized_by_admin_id' => 2, 'authorized_by_name' => 'Beto',
            'authorized_at' => '2026-11-01 12:00:00', 'activated_by_admin_id' => 2, 'activated_by_name' => 'Beto', 'activated_at' => '2026-11-01 12:00:00',
            'status_changed_at' => '2026-11-01 12:00:00', 'created_at' => '2026-11-01 12:00:00', 'updated_at' => '2026-11-01 12:00:00',
        ]);
        $this->personas['ultron_studio_canary_numbers'][] = $huella;

        return ['member' => $member, 'lead' => $lead, 'key' => SubjectKey::member($member)];
    }

    /**
     * El trabajo asistido de la persona, `started` en vivo, por las filas del esquema y la conexión de apoyo.
     *
     * @param  array<string, mixed>  $live
     * @param  array{member: int, lead: int, key: string}  $persona
     * @return array{id: int, uuid: string}
     */
    private function committedStartedJob(array $live, array $persona): array
    {
        $plantilla = $this->assistedTemplate();
        $e = $this->insertEnrollment($live, ['mode' => 'live', 'subject_key' => $persona['key'], 'member_id' => $persona['member']]);
        $id = $this->insertJob($e, [
            'channel' => 'assisted', 'medium' => 'whatsapp', 'template_version_id' => $plantilla,
            'template_hash' => (string) $this->db()->table(self::T_TEMPLATES)->where('id', $plantilla)->value('content_hash'),
            'not_before' => '2026-11-17 15:00:00', 'expires_at' => '2026-11-18 09:00:00', 'budget_day' => '2026-11-17',
        ]);
        $this->walkJob($id, 'started');

        return ['id' => $id, 'uuid' => (string) $this->db()->table(self::T_JOBS)->where('id', $id)->value('uuid')];
    }
}
