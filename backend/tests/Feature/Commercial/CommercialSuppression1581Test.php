<?php

namespace Tests\Feature\Commercial;

use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\Feature\Telephony\Schema\VoiceSuppression1581Test;
use Tests\TestCase;

/**
 * LA SUPRESIÓN LEY 1581 DE LO COMERCIAL, DE VERDAD, EN POSTGRESQL 14 (hallazgo I-2
 * de `informe-security-quejas.md`; política en `informe-db-comercial.md` §3).
 *
 * Ejecuta `docs/ultron-studio/ops/supresion-1581.sql` TAL CUAL con `psql`, como el
 * runbook (los parámetros por la entrada estándar, delante del guion), sobre filas
 * sintéticas CONFIRMADAS, con el modelo de roles de producción (las tablas son de
 * un dueño sin superusuario y la aplicación es otro rol). Una sola prueba de punta
 * a punta, al estilo de {@see VoiceSuppression1581Test}:
 *
 *  0. En seco, por :subject: los recuentos de commercial_events (y cuántos son
 *     freno N6), de marketing_ai_actions y de lo que solo se informa, y el aviso
 *     «SIN BAJA GLOBAL»; no cambia nada; la salida no lleva datos.
 *  1. Con :dry 0, frenos N6 y prospectos sin «no contactar»: ABORTA sin tocar nada
 *     (el ORDEN de security: primero la baja global).
 *  2. La aplicación marca «no contactar» en sus prospectos.
 *  3. La redacción real, conectado COMO el dueño: los hechos de la persona se
 *     borran, sus acciones de la IA se quedan con el motivo suprimido y solo los
 *     sellos operativos en la metadata, nada de otra persona cambia, nada fuera de
 *     esas dos tablas salvo una fila del libro, los mismos disparadores y CHECK.
 *  4. Repetirla no encuentra nada.
 *  5. Por l:<id>: el prospecto, su socio y los demás prospectos de ese socio; sin
 *     frenos, no hace falta la baja.
 *  6. Un socio sin prospecto, con queja: la escalera ya falla cerrada en N1 sin
 *     prospecto, así que no hace falta la baja.
 *
 * Los datos quedan CONFIRMADOS y las tablas, de otro rol: al terminar se marca la
 * base para que la siguiente prueba con RefreshDatabase migre desde cero. Solo
 * PostgreSQL (en SQLite no hay psql, roles ni DO).
 */
class CommercialSuppression1581Test extends TestCase
{
    private const SUP_OWNER = 'ultron_studio_1581c_owner';

    private const SUP_APP = 'ultron_studio_1581c_app';

    private const APP_ROLE_ENV = 'ULTRON_STUDIO_DB_APP_ROLE';

    private const SCRIPT = 'docs/ultron-studio/ops/supresion-1581.sql';

    /** Las dos tablas que este bloque toca. */
    private const TOUCHED = ['commercial_events', 'marketing_ai_actions'];

    /** Lo que solo se informa. */
    private const INFORMED = ['commercial_opportunities', 'commercial_segments', 'commercial_tool_invocations'];

    /** Textos de la persona sembrados: nunca en la salida ni en el libro. */
    private const PERSONAL_TEXTS = ['Zoila Comercial', 'me cobraron dos veces y nadie responde', 'precio-alto-zoila', 'Otro Cliente'];

    private string $psql = '';

    private int $paso = 0;

    /** @var array<string, int> ids de lo sembrado */
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

    public function test_la_supresion_de_lo_comercial_borra_sus_hechos_y_redacta_lo_que_la_ia_guarda_sin_tocar_nada_mas(): void
    {
        $this->prepareProductionLikeDatabase();
        $this->s = $this->seedPeople();

        $this->stepDryRun();
        $this->stepRealRunAbortsWithoutGlobalOptOut();
        $this->stepTheApplicationMarksDoNotContact();
        $this->stepRealRedactionAsTheOwner();
        $this->stepRepeatingFindsNothing();
        $this->stepByLeadReachesItsMemberAndSiblings();
        $this->stepMemberWithoutLeadNeedsNoOptOut();
    }

    // ── 0 · en seco ─────────────────────────────────────────────────────────

    private function stepDryRun(): void
    {
        $antes = $this->digests();
        [$rc, $out] = $this->runScript('00-seco', $this->params('m:'.$this->s['ma'], '1'));
        $this->assertSame(0, $rc, "0 · en seco: {$out}");
        $this->assertStringContainsString('EN SECO (H9): nada ha cambiado.', $out, '0 · el bloque del H9 corre antes, igual que siempre');
        $this->assertStringContainsString('la persona: 2 prospecto(s) y su socio', $out);
        $this->assertStringContainsString('commercial_events · 3 hecho(s), 2 de ellos freno N6 (queja o petición de persona) · se borrarían', $out);
        $this->assertStringContainsString('marketing_ai_actions · 2 fila(s) con motivo o metadata de la persona · se redactarían', $out);
        $this->assertStringContainsString('se informa, no se toca: commercial_opportunities 1 · commercial_segments 1 · commercial_tool_invocations 0', $out);
        $this->assertStringContainsString('SIN BAJA GLOBAL: 2 prospecto(s) sin «no contactar»', $out);
        $this->assertStringContainsString('EN SECO (comercial): nada ha cambiado.', $out);
        $this->assertStringContainsString('== EN SECO: ROLLBACK, nada ha cambiado.', $out);
        $this->assertSame($antes, $this->digests(), '0 · en seco no cambia ninguna tabla');
        $this->assertNoPersonalData($out, '0');
    }

    // ── 1 · sin la baja global, aborta ──────────────────────────────────────

    private function stepRealRunAbortsWithoutGlobalOptOut(): void
    {
        $antes = $this->digests();
        $disparadores = $this->triggerStates();
        [$rc, $out] = $this->runScript('01-sin-baja', $this->params('m:'.$this->s['ma'], '0'), asOwner: true);
        $this->assertNotSame(0, $rc, '1 · con frenos N6 y prospectos sin «no contactar», la redacción aborta');
        $this->assertStringContainsString('primero la baja global', $out);
        $this->assertSame($antes, $this->digests(), '1 · abortar no cambia nada');
        $this->assertSame($disparadores, $this->triggerStates());
        $this->assertNoPersonalData($out, '1');
    }

    // ── 2 · la aplicación marca «no contactar» ──────────────────────────────

    private function stepTheApplicationMarksDoNotContact(): void
    {
        $n = DB::table('marketing_leads')->whereIn('id', [$this->s['la1'], $this->s['la2']])->update(['do_not_contact' => true, 'updated_at' => now()]);
        $this->assertSame(2, $n);
    }

    // ── 3 · la redacción real ───────────────────────────────────────────────

    private function stepRealRedactionAsTheOwner(): void
    {
        $antes = $this->digests();
        $filas = $this->rows();
        $disparadores = $this->triggerStates();
        $restricciones = $this->constraintDefinitions();
        $libro = (int) DB::table('ultron_studio_audit_events')->max('id');

        [$rc, $out] = $this->runScript('03-real', $this->params('m:'.$this->s['ma'], '0'), asOwner: true);

        $this->assertSame(0, $rc, "3 · la redacción real, conectado como el dueño: {$out}");
        $this->assertStringContainsString('Comercial · borrado y redactado: {"commercial_events": 3, "marketing_ai_actions": 2}', $out);
        $this->assertStringContainsString('SUPRESIÓN CONFIRMADA (COMMIT)', $out);

        // Los hechos de la persona, borrados; los de las demás, intactos (fila a fila).
        $ahora = $this->rows();
        $this->assertSame(array_values(array_diff(array_keys($filas['commercial_events']), [$this->s['ea1'], $this->s['ea2'], $this->s['ea3']])),
            array_keys($ahora['commercial_events']), '3 · se borran solo los tres hechos de la persona (por socio y por sus dos prospectos)');
        foreach ($ahora['commercial_events'] as $id => $fila) {
            $this->assertSame($filas['commercial_events'][$id], $fila, "3 · el hecho {$id} de otra persona no cambia");
        }

        // Sus acciones de la IA: la fila se queda; motivo suprimido y solo los sellos operativos.
        $this->assertSame(array_keys($filas['marketing_ai_actions']), array_keys($ahora['marketing_ai_actions']), '3 · ninguna acción de la IA se borra');
        $cambiadas = array_keys(array_diff_assoc($ahora['marketing_ai_actions'], $filas['marketing_ai_actions']));
        sort($cambiadas);
        $this->assertSame([$this->s['aa1'], $this->s['aa2']], $cambiadas, '3 · cambian solo las dos acciones con texto de la persona');
        $aa1 = DB::table('marketing_ai_actions')->where('id', $this->s['aa1'])->first();
        $this->assertSame('[suprimido]', $aa1->reason);
        $this->assertEquals(['message_id' => 11, 'studio' => ['state' => 'applied', 'version' => 3]], json_decode($aa1->metadata, true), '3 · conserva los sellos del análisis y del canario; pierde intent y temperature');
        $aa2 = DB::table('marketing_ai_actions')->where('id', $this->s['aa2'])->first();
        $this->assertSame('[suprimido]', $aa2->reason, '3 · el motivo de texto libre se suprime');
        $this->assertEquals(['outbound' => ['message_id' => 12, 'sent' => true], 'recovery' => ['reason' => 'draft_rejected', 'attempt' => 1], 'blocked_reason' => 'guardrail_blocked'],
            json_decode($aa2->metadata, true), '3 · el vigía de turnos sigue viendo el envío y la recuperación; la objeción y el perfilado desaparecen');
        $this->assertSame('complaint', DB::table('marketing_ai_actions')->where('id', $this->s['ab1'])->value('reason'), '3 · la otra persona conserva lo suyo');

        // Fuera de las dos tablas: nada, salvo una fila del libro.
        $despues = $this->digests();
        foreach ($antes as $tabla => $md5) {
            if (in_array($tabla, self::TOUCHED, true) || $tabla === 'ultron_studio_audit_events') {
                continue;
            }
            $this->assertSame($md5, $despues[$tabla] ?? null, "3 · {$tabla} no cambia");
        }
        foreach (self::INFORMED as $tabla) {
            $this->assertSame($antes[$tabla], $despues[$tabla], "3 · {$tabla} solo se informa");
        }
        $nuevas = DB::table('ultron_studio_audit_events')->where('id', '>', $libro)->get();
        $this->assertCount(1, $nuevas, '3 · una fila en el libro (el bloque del H9 no encontró nada y no deja la suya)');
        $fila = $nuevas->first();
        $this->assertSame('system.personal_data_redacted', $fila->event);
        $meta = json_decode((string) $fila->metadata, true);
        $this->assertSame('commercial', $meta['scope']);
        // jsonb ordena las claves por longitud: se comparan sin orden.
        $this->assertEquals(['commercial_events' => 3, 'marketing_ai_actions' => 2], $meta['fields']);
        $this->assertEquals(['commercial_opportunities' => 1, 'commercial_segments' => 1, 'commercial_tool_invocations' => 0], $meta['commercial_informed']);
        $this->assertNoPersonalData($fila->summary.' '.$fila->metadata, '3 · libro');
        $this->assertStringNotContainsString('m:'.$this->s['ma'], $fila->summary.' '.$fila->metadata.' '.$out, '3 · la clave de la persona no se escribe en ningún sitio');

        $this->assertSame($disparadores, $this->triggerStates(), '3 · los mismos disparadores, todos como estaban');
        $this->assertSame($restricciones, $this->constraintDefinitions(), '3 · las mismas restricciones');
        $this->assertNoPersonalData($out, '3');
    }

    // ── 4 · repetir ─────────────────────────────────────────────────────────

    private function stepRepeatingFindsNothing(): void
    {
        $antes = $this->digests();
        [$rc, $out] = $this->runScript('04-repetir', $this->params('m:'.$this->s['ma'], '0'), asOwner: true);
        $this->assertSame(0, $rc, "4 · {$out}");
        $this->assertStringContainsString('commercial_events · 0 hecho(s)', $out);
        $this->assertStringContainsString('marketing_ai_actions · 0 fila(s)', $out);
        $this->assertStringContainsString('Comercial: nada que borrar ni redactar para esa clave', $out);
        $this->assertSame($antes, $this->digests(), '4 · repetir no cambia nada (ni una fila del libro)');
    }

    // ── 5 · por prospecto ───────────────────────────────────────────────────

    private function stepByLeadReachesItsMemberAndSiblings(): void
    {
        [$rc, $out] = $this->runScript('05-lead-seco', $this->params('l:'.$this->s['lc1'], '1'));
        $this->assertSame(0, $rc, "5 · {$out}");
        $this->assertStringContainsString('la persona: 2 prospecto(s) y su socio', $out, '5 · el prospecto, su socio y el otro prospecto de ese socio');
        $this->assertStringContainsString('commercial_events · 2 hecho(s), 0 de ellos freno N6', $out);
        $this->assertStringNotContainsString('SIN BAJA GLOBAL', $out, '5 · sin frenos no hace falta la baja');

        $libro = (int) DB::table('ultron_studio_audit_events')->max('id');
        $otros = DB::table('commercial_events')->whereNotIn('id', [$this->s['ec1'], $this->s['ec2']])->count();
        [$rc, $out] = $this->runScript('05-lead-real', $this->params('l:'.$this->s['lc1'], '0'), asOwner: true);
        $this->assertSame(0, $rc, "5 · {$out}");
        $this->assertSame(0, DB::table('commercial_events')->whereIn('id', [$this->s['ec1'], $this->s['ec2']])->count(), '5 · el hecho del otro prospecto y el del socio, borrados');
        $this->assertSame($otros, DB::table('commercial_events')->count(), '5 · nada más');
        $this->assertSame('[suprimido]', DB::table('marketing_ai_actions')->where('id', $this->s['ac1'])->value('reason'));
        $this->assertSame('{}', DB::table('marketing_ai_actions')->where('id', $this->s['ac1'])->value('metadata'), '5 · sin sellos operativos, la metadata queda vacía');
        $this->assertSame(1, DB::table('ultron_studio_audit_events')->where('id', '>', $libro)->count());
    }

    // ── 6 · socio sin prospecto ─────────────────────────────────────────────

    private function stepMemberWithoutLeadNeedsNoOptOut(): void
    {
        [$rc, $out] = $this->runScript('06-socio-sin-lead', $this->params('m:'.$this->s['md'], '0'), asOwner: true);
        $this->assertSame(0, $rc, "6 · un socio sin prospecto no necesita la baja (N1 ya falla cerrado sin prospecto): {$out}");
        $this->assertStringContainsString('la persona: 0 prospecto(s) y su socio', $out);
        $this->assertSame(0, DB::table('commercial_events')->where('id', $this->s['ed1'])->count());
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
    }

    /** @return array<string, int> */
    private function seedPeople(): array
    {
        $ahora = now();
        $socio = fn (string $nombre): int => (int) DB::table('members')->insertGetId([
            'member_uuid' => (string) Str::uuid(), 'access_hash' => hash('sha256', $nombre.Str::random(8)), 'full_name' => $nombre,
            'document_number' => 'C1581-'.Str::random(8), 'status' => 'active', 'created_at' => $ahora, 'updated_at' => $ahora,
        ]);
        $lead = fn (?int $member, string $nombre): int => (int) DB::table('marketing_leads')->insertGetId([
            'channel' => 'whatsapp', 'source' => 'inbound', 'name' => $nombre, 'status' => 'new', 'member_id' => $member,
            'do_not_contact' => false, 'created_at' => $ahora, 'updated_at' => $ahora,
        ]);
        $hecho = fn (string $evento, ?int $member, ?int $lead, array $payload, string $clave): int => (int) DB::table('commercial_events')->insertGetId([
            'event' => $evento, 'member_id' => $member, 'marketing_lead_id' => $lead, 'dedupe_key' => $evento.':'.$clave,
            'payload' => json_encode($payload), 'occurred_at' => $ahora, 'created_at' => $ahora, 'updated_at' => $ahora,
        ]);
        $accion = fn (int $lead, ?string $motivo, ?array $meta): int => (int) DB::table('marketing_ai_actions')->insertGetId([
            'lead_id' => $lead, 'action_type' => 'reply', 'status' => 'executed', 'reason' => $motivo,
            'metadata' => $meta === null ? null : json_encode($meta), 'created_at' => $ahora, 'updated_at' => $ahora,
        ]);

        $s = [];
        // La persona A: socio con dos prospectos.
        $s['ma'] = $socio('Zoila Comercial');
        $s['la1'] = $lead($s['ma'], 'Zoila Comercial');
        $s['la2'] = $lead($s['ma'], 'Zoila Comercial');
        $s['ea1'] = $hecho('payment_approved', $s['ma'], null, ['reference' => 'REF-ZOILA', 'amount' => 80000], 'tx:1:approved');
        $s['ea2'] = $hecho('complaint_created', null, $s['la1'], ['source' => 'ultron', 'conversation_id' => 7], 'conversation:7:complaint:20261111');
        $s['ea3'] = $hecho('human_requested', $s['ma'], $s['la2'], ['source' => 'customer_request'], 'conversation:8:takeover:202611111200');
        $s['aa1'] = $accion($s['la1'], 'complaint', ['intent' => 'complaint', 'temperature' => 'cold', 'message_id' => 11, 'studio' => ['state' => 'applied', 'version' => 3]]);
        $s['aa2'] = $accion($s['la2'], 'me cobraron dos veces y nadie responde', [
            'objection' => 'precio-alto-zoila', 'lead_score' => 12, 'outbound' => ['message_id' => 12, 'sent' => true],
            'recovery' => ['reason' => 'draft_rejected', 'attempt' => 1], 'blocked_reason' => 'guardrail_blocked',
        ]);
        $s['aa3'] = $accion($s['la1'], null, null);
        $s['aa4'] = $accion($s['la1'], null, ['message_id' => 13]);
        DB::table('commercial_opportunities')->insert(['uuid' => (string) Str::uuid(), 'member_id' => $s['ma'], 'goal' => 'renew', 'next_action' => 'wait', 'reason' => 'x', 'created_at' => $ahora, 'updated_at' => $ahora]);
        DB::table('commercial_segments')->insert(['marketing_lead_id' => $s['la1'], 'segment' => 'at_risk', 'computed_at' => $ahora, 'created_at' => $ahora, 'updated_at' => $ahora]);

        // Otra persona B: nada suyo cambia.
        $s['mb'] = $socio('Otro Cliente');
        $s['lb'] = $lead($s['mb'], 'Otro Cliente');
        $s['eb1'] = $hecho('complaint_created', $s['mb'], null, ['source' => 'ultron'], 'lead:b:complaint:20261111');
        $s['eb2'] = $hecho('payment_failed', null, $s['lb'], ['reference' => 'REF-OTRO'], 'tx:2:declined');
        $s['ab1'] = $accion($s['lb'], 'complaint', ['intent' => 'complaint']);

        // La persona C: un prospecto enlazado a un socio que tiene otro prospecto; sin frenos.
        $s['mc'] = $socio('Carla Tercera');
        $s['lc1'] = $lead($s['mc'], 'Carla Tercera');
        $s['lc2'] = $lead($s['mc'], 'Carla Tercera');
        $s['ec1'] = $hecho('payment_link_created', null, $s['lc2'], ['reference' => 'REF-C'], 'tx:3:link');
        $s['ec2'] = $hecho('membership_renewed', $s['mc'], null, ['plan' => 'Mensual'], 'membership:3:2026-12-01');
        $s['ac1'] = $accion($s['lc2'], 'quiere el anual', ['intent' => 'buy']);

        // La persona D: socio sin prospecto, con una queja.
        $s['md'] = $socio('Dario Sinlead');
        $s['ed1'] = $hecho('complaint_created', $s['md'], null, ['source' => 'ultron'], 'member:d:complaint:20261111');

        return $s;
    }

    /** @return array<string, string> */
    private function params(string $subject, string $dry): array
    {
        return ['radicado' => 'T1581-COM-'.(++$this->paso), 'subject' => $subject, 'owner' => self::SUP_OWNER, 'dry' => $dry];
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
            ['PGGSSENCMODE' => 'disable', 'PGPASSWORD' => (string) ($c['password'] ?? ''), 'PGOPTIONS' => '', 'PGAPPNAME' => 'supresion-1581-comercial-prueba'],
        );
        $proceso->setInput(implode("\n", $lineas)."\n".file_get_contents(base_path(self::SCRIPT)));
        $proceso->setTimeout(180);
        $proceso->run();
        $salida = $proceso->getOutput().$proceso->getErrorOutput();
        $dir = getenv('SUPRESION_1581_LOG_DIR');
        if (is_string($dir) && $dir !== '' && is_dir($dir)) {
            file_put_contents($dir.'/comercial-'.$nombre.'.log', "rc={$proceso->getExitCode()}\n".$salida);
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

    /** @return array<string, array<int, string>> tabla tocada → id → la fila en texto */
    private function rows(): array
    {
        $out = [];
        foreach (self::TOUCHED as $tabla) {
            $out[$tabla] = [];
            foreach (DB::select("SELECT x.id, x::text AS fila FROM public.\"{$tabla}\" x ORDER BY x.id") as $f) {
                $out[$tabla][(int) $f->id] = (string) $f->fila;
            }
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
              WHERE c.relname IN ('commercial_events', 'marketing_ai_actions', 'marketing_leads', 'ultron_studio_audit_events') ORDER BY 1"));
    }

    private function assertNoPersonalData(string $texto, string $paso): void
    {
        foreach (self::PERSONAL_TEXTS as $dato) {
            $this->assertStringNotContainsString($dato, $texto, "{$paso} · la salida no lleva datos personales");
        }
    }
}
