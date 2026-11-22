<?php

namespace Tests\Feature\Outreach\Schema;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\Feature\Commercial\CommercialSuppression1581Test;
use Tests\TestCase;

/**
 * LA SUPRESIÓN LEY 1581 DEL LIBRO DE AUTORIZACIONES (C9-1, `outreach_consents`),
 * DE VERDAD, EN POSTGRESQL 14. Ejecuta `docs/ultron-studio/ops/supresion-1581.sql`
 * TAL CUAL con `psql`, como el runbook (los parámetros por la entrada estándar),
 * sobre filas CONFIRMADAS y con el modelo de roles de producción (las tablas son
 * de un dueño sin superusuario y la aplicación es otro rol), al estilo de
 * {@see CommercialSuppression1581Test}:
 *
 *  0. En seco, por :subject: cuenta las autorizaciones de la persona y los
 *     contadores de sus números (también los que solo salen de sus
 *     autorizaciones); no cambia nada; la salida no lleva datos.
 *  1. La redacción real, conectado COMO el dueño: sus cuatro filas pasan a la
 *     forma suprimida SIN apagar ningún disparador, los contadores de sus
 *     números se borran, nada de otra persona cambia, una fila en el libro.
 *  2. Repetirla no encuentra nada ni deja otra fila.
 *  3. Por :admin_id: el nombre de quien confirmó pasa a la marca (la guarda lo
 *     admite y se queda encendida); el de quien registró, no.
 *  4. Después, la guarda sigue: ni el dueño cambia lo redactado, y la
 *     aplicación sigue sin UPDATE.
 *
 * Al terminar se marca la base para que la siguiente prueba con RefreshDatabase
 * migre desde cero. Solo PostgreSQL (en SQLite no hay psql, roles ni DO).
 */
class OutreachConsentSuppression1581Test extends TestCase
{
    private const SUP_OWNER = 'ultron_studio_1581o_owner';

    private const SUP_APP = 'ultron_studio_1581o_app';

    private const APP_ROLE_ENV = 'ULTRON_STUDIO_DB_APP_ROLE';

    private const SCRIPT = 'docs/ultron-studio/ops/supresion-1581.sql';

    /** Lo que el bloque toca; el resto de tablas no cambia (salvo el libro). */
    private const TOUCHED = ['outreach_consents', 'outreach_budget_counters', 'ultron_studio_audit_events'];

    /** Textos sembrados (evidencias y nombres): nunca en la salida ni en el libro. */
    private const PERSONAL_TEXTS = ['Zoila Consentida', 'Beto Confirmador', 'Ana Registradora', 'Otra Persona'];

    private string $psql = '';

    private int $paso = 0;

    /** @var array<string, mixed> lo sembrado */
    private array $s = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Solo PostgreSQL: la supresión 1581 es SQL de PostgreSQL (psql, DO, roles). Ejecutar con docs/ultron-studio/ops/pgunit.sh.');
        }
        $psql = getenv('PSQL_BIN') ?: (new ExecutableFinder)->find('psql', null, ['/opt/homebrew/bin', '/opt/homebrew/opt/postgresql@14/bin', '/usr/local/bin', '/usr/bin']);
        $this->assertIsString($psql, 'Sin psql no se puede ejecutar supresion-1581.sql (PSQL_BIN=<ruta> para indicarlo).');
        $this->psql = $psql;
    }

    protected function tearDown(): void
    {
        putenv(self::APP_ROLE_ENV);
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    public function test_la_supresion_redacta_las_autorizaciones_de_la_persona_sin_apagar_su_guarda(): void
    {
        $this->prepareProductionLikeDatabase();
        $this->s = $this->seedConsents();

        $this->stepDryRun();
        $this->stepRealRedactionAsTheOwner();
        $this->stepRepeatingFindsNothing();
        $this->stepStaffNameByAdminId();
        $this->stepTheGuardStaysAfterwards();
    }

    // ── 0 · en seco ─────────────────────────────────────────────────────────

    private function stepDryRun(): void
    {
        $antes = $this->digests();
        [$rc, $out] = $this->runScript('00-seco', $this->params(['subject' => $this->s['ka'], 'dry' => '1']));
        $this->assertSame(0, $rc, "0 · en seco: {$out}");
        $this->assertStringContainsString('outreach_consents · 4 fila(s) · pasarían a la forma suprimida', $out);
        $this->assertStringContainsString('outreach_budget_counters · 2 contador(es) phone: de sus seudónimos · se borrarían', $out, '0 · también el número que solo sale de una autorización');
        $this->assertStringContainsString('total H9: 4 fila(s) en 5 tablas · 2 contador(es)', $out);
        $this->assertStringContainsString('EN SECO (H9): nada ha cambiado.', $out);
        $this->assertStringContainsString('== EN SECO: ROLLBACK, nada ha cambiado.', $out);
        $this->assertSame($antes, $this->digests(), '0 · en seco no cambia ninguna tabla');
        $this->assertNoPersonalData($out, '0');
    }

    // ── 1 · la redacción real ───────────────────────────────────────────────

    private function stepRealRedactionAsTheOwner(): void
    {
        $antes = $this->digests();
        $filas = $this->consentRows();
        $disparadores = $this->triggerStates();
        $restricciones = $this->constraintDefinitions();
        $libro = (int) DB::table('ultron_studio_audit_events')->max('id');

        [$rc, $out] = $this->runScript('01-real', $this->params(['subject' => $this->s['ka'], 'dry' => '0']), asOwner: true);
        $this->assertSame(0, $rc, "1 · la redacción real, conectado como el dueño: {$out}");
        $this->assertStringContainsString('outreach_consents · 4 fila(s) a la forma suprimida · ningún disparador apagado (su guarda admite la redacción)', $out);
        $this->assertStringContainsString('SUPRESIÓN CONFIRMADA (COMMIT)', $out);

        // Sus cuatro filas, en la forma suprimida; lo demás de cada fila, igual.
        $ahora = $this->consentRows();
        $this->assertSame(array_keys($filas), array_keys($ahora), '1 · ninguna fila se borra');
        foreach ($this->s['filasA'] as $id) {
            $f = DB::table('outreach_consents')->where('id', $id)->first();
            $this->assertSame(['x:'.$id, null, null, null, '[suprimido]'], [$f->subject_key, $f->member_id, $f->lead_id, $f->phone_ref, $f->evidence], "1 · la fila {$id}");
            $this->assertNotNull($f->redacted_at);
            $antesFila = $this->s['antes'][$id];
            foreach (['kind', 'purpose', 'channel', 'source', 'request_uuid', 'legal_version', 'legal_hash', 'recorded_by_admin_id', 'recorded_by_name', 'confirmed_by_name'] as $c) {
                $this->assertSame($antesFila->{$c}, $f->{$c}, "1 · la fila {$id} conserva {$c}");
            }
        }
        foreach ($this->s['filasB'] as $id) {
            $this->assertSame($filas[$id], $ahora[$id], "1 · la fila {$id} de otra persona no cambia");
        }
        // Los contadores de sus dos números (el de un trabajo no lo hay: salen de sus autorizaciones), borrados; el de B, no.
        $this->assertSame([$this->s['contadorB']], DB::table('outreach_budget_counters')->orderBy('id')->pluck('scope')->all());

        // Fuera de las tablas tocadas, nada; una fila en el libro (la del bloque del H9: los demás bloques no encuentran nada).
        $despues = $this->digests();
        foreach ($antes as $tabla => $md5) {
            if (! in_array($tabla, self::TOUCHED, true)) {
                $this->assertSame($md5, $despues[$tabla] ?? null, "1 · {$tabla} no cambia");
            }
        }
        $nuevas = DB::table('ultron_studio_audit_events')->where('id', '>', $libro)->get();
        $this->assertCount(1, $nuevas, '1 · una fila en el libro');
        $fila = $nuevas->first();
        $this->assertSame('system.personal_data_redacted', $fila->event);
        $this->assertStringContainsString('4 fila(s) en forma suprimida en 5 tabla(s) y 2 contador(es) de teléfono borrado(s)', $fila->summary);
        $meta = json_decode((string) $fila->metadata, true);
        $this->assertSame('outreach', $meta['scope']);
        $this->assertSame(4, $meta['fields']['outreach_consents']);
        $this->assertSame(2, $meta['fields']['outreach_budget_counters']);
        $this->assertSame(2, $meta['phone_refs']);
        $this->assertNoPersonalData($fila->summary.' '.$fila->metadata, '1 · libro');
        $this->assertStringNotContainsString($this->s['ka'], $fila->summary.' '.$fila->metadata.' '.$out, '1 · la clave de la persona no se escribe');
        foreach ($this->s['refsA'] as $ref) {
            $this->assertStringNotContainsString($ref, $fila->metadata.' '.$out, '1 · ni sus seudónimos');
        }
        $this->assertSame($disparadores, $this->triggerStates(), '1 · los mismos disparadores, todos encendidos');
        $this->assertSame($restricciones, $this->constraintDefinitions(), '1 · las mismas restricciones');
        $this->assertNoPersonalData($out, '1');
    }

    // ── 2 · repetir ─────────────────────────────────────────────────────────

    private function stepRepeatingFindsNothing(): void
    {
        $antes = $this->digests();
        [$rc, $out] = $this->runScript('02-repetir', $this->params(['subject' => $this->s['ka'], 'dry' => '0']), asOwner: true);
        $this->assertSame(0, $rc, "2 · {$out}");
        $this->assertStringContainsString('outreach_consents · 0 fila(s)', $out);
        $this->assertStringContainsString('H9: nada que redactar en la automatización comercial para esa clave', $out);
        $this->assertSame($antes, $this->digests(), '2 · repetir no cambia nada (ni una fila del libro)');
    }

    // ── 3 · el nombre de quien confirmó, por :admin_id ──────────────────────

    private function stepStaffNameByAdminId(): void
    {
        $disparadores = $this->triggerStates();
        [$rc, $out] = $this->runScript('03-admin-seco', $this->params(['admin_id' => '2', 'dry' => '1']));
        $this->assertSame(0, $rc, "3 · {$out}");
        $this->assertStringContainsString('outreach_consents.confirmed_by_name (por confirmed_by_admin_id) · 2 fila(s) · se redactaría', $out);
        $this->assertStringNotContainsString('outreach_consents.recorded_by_name', $out, '3 · Beto no registró nada');

        $libro = (int) DB::table('ultron_studio_audit_events')->max('id');
        [$rc, $out] = $this->runScript('03-admin-real', $this->params(['admin_id' => '2', 'dry' => '0']), asOwner: true);
        $this->assertSame(0, $rc, "3 · la redacción del nombre, con la guarda encendida: {$out}");
        $this->assertStringContainsString('outreach_consents · columnas [confirmed_by_name] · disparadores de UPDATE que saltarían, apagados en esta transacción: ninguno', $out,
            '3 · el de los nombres salta y no se apaga: valida la redacción');
        $this->assertSame([], DB::table('outreach_consents')->where('confirmed_by_admin_id', 2)->where('confirmed_by_name', '<>', '[suprimido]')->pluck('id')->all());
        $this->assertSame(2, DB::table('outreach_consents')->where('confirmed_by_admin_id', 2)->count());
        $this->assertSame(5, DB::table('outreach_consents')->where('recorded_by_name', 'Ana Registradora')->count(), '3 · quien registró conserva su nombre');
        $this->assertSame(1, DB::table('ultron_studio_audit_events')->where('id', '>', $libro)->count());
        $this->assertSame($disparadores, $this->triggerStates());
        $this->assertNoPersonalData($out, '3');
    }

    // ── 4 · la guarda sigue ─────────────────────────────────────────────────

    private function stepTheGuardStaysAfterwards(): void
    {
        $id = $this->s['filasA'][0];
        foreach ([
            ['subject_key' => $this->s['ka'], 'member_id' => $this->s['ma'], 'redacted_at' => null],
            ['evidence' => 'otra'],
            ['kind' => 'granted'],
            ['confirmed_by_name' => 'Beto'],
        ] as $cambio) {
            try {
                DB::transaction(fn () => DB::table('outreach_consents')->where('id', $id)->update($cambio));
                $this->fail('4 · la guarda dejó cambiar lo redactado: '.implode(', ', array_keys($cambio)));
            } catch (QueryException $e) {
                $this->assertSame('P0001', (string) $e->getCode(), $e->getMessage());
            }
        }
        $p = DB::selectOne("SELECT has_any_column_privilege(?, 'public.outreach_consents', 'UPDATE')::int AS u, has_table_privilege(?, 'public.outreach_consents', 'DELETE')::int AS d", [self::SUP_APP, self::SUP_APP]);
        $this->assertSame([0, 0], [(int) $p->u, (int) $p->d], '4 · la aplicación sigue sin UPDATE ni DELETE');
    }

    // ── Preparación ─────────────────────────────────────────────────────────

    private function prepareProductionLikeDatabase(): void
    {
        DB::statement(sprintf(
            "DO \$\$ BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '%1\$s') THEN CREATE ROLE %1\$s LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS; END IF;
                IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '%2\$s') THEN CREATE ROLE %2\$s LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS NOINHERIT; END IF;
            END \$\$",
            self::SUP_OWNER, self::SUP_APP,
        ));
        foreach ([self::SUP_OWNER, self::SUP_APP] as $rol) {
            $r = DB::selectOne('SELECT rolsuper::int AS su, rolbypassrls::int AS rls FROM pg_roles WHERE rolname = ?', [$rol]);
            $this->assertSame([0, 0], [(int) $r->su, (int) $r->rls], "{$rol} no es superusuario");
        }
        DB::statement('GRANT USAGE ON SCHEMA public TO '.self::SUP_APP);
        DB::statement('ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO '.self::SUP_APP);
        DB::statement('ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT USAGE, SELECT, UPDATE ON SEQUENCES TO '.self::SUP_APP);
        putenv(self::APP_ROLE_ENV.'='.self::SUP_APP);
        $this->assertSame(0, Artisan::call('migrate:fresh'), 'migrate:fresh con el rol de la aplicación');
        putenv(self::APP_ROLE_ENV);
        DB::statement(sprintf("DO \$\$ DECLARE t text; BEGIN
                FOR t IN SELECT tablename FROM pg_tables WHERE schemaname = 'public' LOOP
                    EXECUTE format('ALTER TABLE public.%%I OWNER TO %s', t);
                END LOOP;
            END \$\$", self::SUP_OWNER));
        $this->assertSame(0, (int) DB::scalar("SELECT count(*) FROM pg_tables WHERE schemaname = 'public' AND tableowner <> ?", [self::SUP_OWNER]), 'todas las tablas son del dueño');
        $p = DB::selectOne("SELECT has_table_privilege(?, 'public.outreach_consents', 'INSERT')::int AS i, has_any_column_privilege(?, 'public.outreach_consents', 'UPDATE')::int AS u", [self::SUP_APP, self::SUP_APP]);
        $this->assertSame([1, 0], [(int) $p->i, (int) $p->u], 'la aplicación inserta y no cambia (el REVOKE de la migración)');
    }

    /** @return array<string, mixed> */
    private function seedConsents(): array
    {
        $ref = static fn (string $e): string => hash('sha256', 'c9-1581-'.$e);
        $legal = hash('sha256', 'Autorizo a Iron Body a escribirme por WhatsApp.');
        $base = static fn (array $o): array => array_merge([
            'uuid' => (string) Str::uuid(), 'client_request_id' => (string) Str::uuid(), 'lead_id' => null, 'channel' => 'whatsapp', 'request_uuid' => null,
            'source' => 'reception_form', 'legal_version' => null, 'legal_hash' => null, 'phone_ref' => null, 'actor_type' => 'admin',
            'recorded_by_admin_id' => 1, 'recorded_by_name' => 'Ana Registradora', 'recorded_by_role' => 'recepcion',
            'confirmed_by_admin_id' => null, 'confirmed_by_name' => null, 'confirmed_by_role' => null, 'redacted_at' => null, 'created_at' => now(),
        ], $o);
        $inserta = static fn (array $f): int => (int) DB::table('outreach_consents')->insertGetId($f);
        $pide = fn (int $member, string $purpose, string $tel, string $evidencia) => $base(['subject_key' => 'm:'.$member, 'member_id' => $member, 'purpose' => $purpose,
            'kind' => 'requested', 'evidence' => $evidencia, 'legal_version' => 'c9-wa/2026-11-22.1', 'legal_hash' => $legal, 'phone_ref' => $ref($tel)]);
        $confirma = static fn (array $p): array => array_merge($p, ['uuid' => (string) Str::uuid(), 'client_request_id' => (string) Str::uuid(), 'kind' => 'granted',
            'request_uuid' => $p['uuid'], 'evidence' => 'Verificado por Beto Confirmador', 'confirmed_by_admin_id' => 2, 'confirmed_by_name' => 'Beto Confirmador', 'confirmed_by_role' => 'coordinacion']);

        $s = ['ma' => 7101, 'ka' => 'm:7101', 'refsA' => [$ref('A1'), $ref('A2')]];
        // La persona A: comercial pedida, confirmada y retirada (la retirada con el seudónimo de su número de ese momento, C-2:
        // la supresión también lo pone a NULL); servicio pedido (pendiente) para OTRO número.
        $pa = $pide(7101, 'commercial', 'A1', 'Formulario de Zoila Consentida en recepción');
        $ga = $confirma($pa);
        $ra = $base(['subject_key' => 'm:7101', 'member_id' => 7101, 'purpose' => 'commercial', 'kind' => 'revoked', 'source' => 'member_request', 'phone_ref' => $ref('A1'),
            'evidence' => 'Zoila Consentida pidió la baja', 'actor_type' => 'system', 'recorded_by_admin_id' => null, 'recorded_by_name' => 'Sistema', 'recorded_by_role' => null]);
        $sa = $pide(7101, 'service', 'A2', 'Pidió recordatorios de clase (Zoila Consentida)');
        $s['filasA'] = [$inserta($pa), $inserta($ga), $inserta($ra), $inserta($sa)];
        // La persona B: pedida y confirmada (Beto confirma también la suya).
        $pb = $pide(7102, 'commercial', 'B', 'Formulario de Otra Persona');
        $s['filasB'] = [$inserta($pb), $inserta($confirma($pb))];
        $s['antes'] = [];
        foreach ($s['filasA'] as $id) {
            $s['antes'][$id] = DB::table('outreach_consents')->where('id', $id)->first();
        }
        // Los contadores del día por teléfono: los dos de A (sin ningún trabajo: salen de sus autorizaciones) y el de B.
        foreach ([$ref('A1'), $ref('A2'), $ref('B')] as $r) {
            DB::table('outreach_budget_counters')->insert(['budget_day' => '2026-11-22', 'scope' => 'phone:'.$r, 'mode' => 'live', 'cap_units' => 1, 'cap_micros' => 0,
                'reserved_units' => 1, 'reserved_micros' => 0, 'created_at' => now()]);
        }
        $s['contadorB'] = 'phone:'.$ref('B');

        return $s;
    }

    /** @param  array<string, string>  $o  @return array<string, string> */
    private function params(array $o): array
    {
        return ['radicado' => 'T1581-C9-'.(++$this->paso), 'owner' => self::SUP_OWNER] + $o;
    }

    /**
     * `cat <parámetros> supresion-1581.sql | psql -X -q -d <base>`, como el runbook.
     *
     * @param  array<string, string>  $params
     * @return array{0:int,1:string}
     */
    private function runScript(string $nombre, array $params, bool $asOwner = false): array
    {
        $lineas = [];
        foreach ($params as $clave => $valor) {
            $this->assertDoesNotMatchRegularExpression('/[\\\\\n\r]/', $valor, "parámetro {$clave}");
            $lineas[] = '\\set '.$clave." '".str_replace("'", "''", $valor)."'";
        }
        $c = config('database.connections.'.config('database.default'));
        $proceso = new Process(
            [$this->psql, '-X', '-q', '-h', (string) $c['host'], '-p', (string) $c['port'], '-U', $asOwner ? self::SUP_OWNER : (string) $c['username'], '-d', (string) $c['database']],
            null,
            ['PGGSSENCMODE' => 'disable', 'PGPASSWORD' => (string) ($c['password'] ?? ''), 'PGOPTIONS' => '', 'PGAPPNAME' => 'supresion-1581-c9-prueba'],
        );
        $proceso->setInput(implode("\n", $lineas)."\n".file_get_contents(base_path(self::SCRIPT)));
        $proceso->setTimeout(180);
        $proceso->run();
        $salida = $proceso->getOutput().$proceso->getErrorOutput();
        $dir = getenv('SUPRESION_1581_LOG_DIR');
        if (is_string($dir) && $dir !== '' && is_dir($dir)) {
            file_put_contents($dir.'/c9-'.$nombre.'.log', "rc={$proceso->getExitCode()}\n".$salida);
        }

        return [(int) $proceso->getExitCode(), $salida];
    }

    // ── Fotos y comprobaciones ──────────────────────────────────────────────

    /** @return array<string, string> tabla → md5 de todas sus filas */
    private function digests(): array
    {
        $out = [];
        foreach (DB::select("SELECT tablename FROM pg_tables WHERE schemaname = 'public' ORDER BY 1") as $t) {
            $out[$t->tablename] = (string) DB::scalar("SELECT md5(coalesce(string_agg(x::text, '|' ORDER BY x::text), '')) FROM public.\"{$t->tablename}\" x");
        }

        return $out;
    }

    /** @return array<int, string> id → la fila en texto */
    private function consentRows(): array
    {
        $out = [];
        foreach (DB::select('SELECT x.id, x::text AS fila FROM public.outreach_consents x ORDER BY x.id') as $f) {
            $out[(int) $f->id] = (string) $f->fila;
        }

        return $out;
    }

    /** @return list<string> */
    private function triggerStates(): array
    {
        return array_map(static fn (object $f): string => "{$f->t}.{$f->n}={$f->e}", DB::select(
            "SELECT c.relname AS t, tg.tgname AS n, tg.tgenabled::text AS e FROM pg_trigger tg JOIN pg_class c ON c.oid = tg.tgrelid
               JOIN pg_namespace s ON s.oid = c.relnamespace WHERE s.nspname = 'public' AND NOT tg.tgisinternal ORDER BY 1, 2"));
    }

    /** @return list<string> */
    private function constraintDefinitions(): array
    {
        return array_map(static fn (object $f): string => "{$f->conname} {$f->def}", DB::select(
            "SELECT k.conname, pg_get_constraintdef(k.oid) AS def FROM pg_constraint k JOIN pg_class c ON c.oid = k.conrelid
              WHERE c.relname IN ('outreach_consents', 'outreach_budget_counters', 'ultron_studio_audit_events') ORDER BY 1"));
    }

    private function assertNoPersonalData(string $texto, string $paso): void
    {
        foreach (self::PERSONAL_TEXTS as $dato) {
            $this->assertStringNotContainsString($dato, $texto, "{$paso} · la salida no lleva datos personales");
        }
    }
}
