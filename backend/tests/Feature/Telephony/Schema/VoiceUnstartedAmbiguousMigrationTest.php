<?php

namespace Tests\Feature\Telephony\Schema;

use App\Models\Voice\VoiceCall;
use App\Models\Voice\VoiceCallEvent;
use App\Services\Telephony\VoiceAmbiguousResolution;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * LA MIGRACIÓN E19-3 (`2026_11_22_100002`), en los dos motores y con SQL a
 * pelo: `vc_status_forward` de DB8 rehecho con `ambiguous → failed_to_start`.
 *
 *  · El disparador es el de DB8 (nombre, evento, función, motivo) con el grafo
 *    de DB8 más esa transición; los recuentos de DB8 no cambian; el grafo es el
 *    de `VoiceCall::TRANSITIONS`.
 *  · La transición pasa con su fin y la fila cumple TODOS los CHECK de PG sin
 *    cambiar ninguno; lo que los CHECK siguen negando (sin `ended_at`, con un id
 *    del proveedor) se rechaza. El resto del grafo no cambia (DF-S3: nunca
 *    `canceled`; un final no cambia).
 *  · `down()` devuelve EXACTAMENTE el disparador de DB8 (comparado con el que
 *    crea DB8 de verdad), también con filas ya cerradas así, y vuelve a subir.
 *  · up() se niega si el esquema no es el de DB8.
 *  · Solo PG: con un rol que NO es el dueño y los privilegios por defecto de
 *    producción (como `iron_app`), `voice:resolve-ambiguous` cierra la fila; lo
 *    que es del dueño, no.
 */
class VoiceUnstartedAmbiguousMigrationTest extends TestCase
{
    use RefreshDatabase;
    use VoiceSchemaFixtures;

    private const E193 = '2026_11_22_100002_allow_unstarted_ambiguous_voice_calls_to_fail_to_start';

    private const FORWARD = 'una llamada solo avanza por los estados de D8-13 y un estado final no cambia (UPDATE en voice_calls)';

    private ?Migration $e193 = null;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        putenv(self::APP_ROLE_ENV);
        parent::tearDown();
    }

    public function test_el_disparador_es_el_de_db8_mas_e19_3_con_el_mismo_motivo_y_los_mismos_recuentos(): void
    {
        $m = $this->e193();
        $h8 = $this->voiceMigration();
        $esperado = $h8::CALL_TRANSITIONS;
        $esperado['ambiguous'][] = 'failed_to_start';
        $this->assertSame($esperado, $m::graph(), 'DB8 más E19-3, y nada más');
        $this->assertSame(VoiceCall::TRANSITIONS, $m::graph(), 'el modelo y la base dicen lo mismo');
        $this->assertTrue(VoiceAmbiguousResolution::schemaAllows());

        $this->assertSame(1, $this->namedTriggers());
        $this->assertSame($this->isPostgres() ? 27 : 21, $this->voiceTriggerCount(), 'los de DB8, ni uno más');
        if ($this->isPostgres()) {
            $this->assertSame(51, $this->voiceCheckCount(), 'ningún CHECK cambia');
            $f = DB::selectOne("SELECT p.proname, t.tgenabled::text AS e, pg_get_triggerdef(t.oid) AS def FROM pg_trigger t JOIN pg_proc p ON p.oid = t.tgfoid
                WHERE t.tgrelid = 'voice_calls'::regclass AND t.tgname = 'vc_status_forward'");
            $this->assertSame(['voice_forbid', 'O'], [$f->proname, $f->e]);
            $this->assertStringStartsWith('CREATE TRIGGER vc_status_forward BEFORE UPDATE OF status ON public.voice_calls FOR EACH ROW WHEN', $f->def);
            $this->assertStringEndsWith("EXECUTE FUNCTION voice_forbid('una llamada solo avanza por los estados de D8-13 y un estado final no cambia')", $f->def);

            return;
        }
        $this->assertSame($m::triggerSql($m::graph(), 'sqlite'), $this->triggerDefinition(), 'el texto exacto, con el mensaje de message() de DB8');
    }

    public function test_ambiguous_a_failed_to_start_pasa_con_su_fin_y_la_fila_cumple_todos_los_check(): void
    {
        $id = $this->ambiguous();
        $this->assertAccepted(fn () => $this->close($id), 'ambiguous → failed_to_start');
        $fila = DB::table('voice_calls')->where('id', $id)->first();
        $this->assertSame(['failed_to_start', null, null], [$fila->status, $fila->provider_call_sid, $fila->provider_conversation_id]);
        $this->assertNotNull($fila->ended_at);
        if (! $this->isPostgres()) {
            return;   // en SQLite no hay CHECK de forma (DB8): lo que sigue es de PG
        }
        $this->assertSame([], $this->violatedChecks('voice_calls', array_diff_key((array) $fila, ['id' => 1])), 'ninguno de los 18 CHECK de la llamada la rechaza');

        // Lo que los CHECK de DB8 siguen negando, ahora que el disparador la deja pasar.
        $sinFin = $this->ambiguous('+573000000302');
        $this->assertCheckRejects(fn () => DB::table('voice_calls')->where('id', $sinFin)->update(['status' => 'failed_to_start', 'status_changed_at' => now()]),
            'vc_ended_chk', 'un final lleva su fin');
        $conId = $this->ambiguous('+573000000303');
        $this->assertCheckRejects(fn () => $this->close($conId, ['provider_call_sid' => $this->callSid('e19-3')]),
            'vc_provider_binding_chk', 'una que trae el id del proveedor sí salió: no se cierra como no iniciada');
        $conMotivo = $this->ambiguous('+573000000304');
        $this->assertCheckRejects(fn () => $this->close($conMotivo, ['policy_reason' => 'publication_moved']),
            'vc_authorization_chk', 'una saliente que no es canceled no lleva motivo de política');
    }

    public function test_el_resto_del_grafo_no_cambia(): void
    {
        $id = $this->ambiguous();
        $this->assertGuarded(fn () => DB::table('voice_calls')->where('id', $id)->update(['status' => 'canceled', 'policy_reason' => 'publication_moved',
            'ended_at' => now(), 'status_changed_at' => now()]), self::FORWARD, 'DF-S3: una ambigua no se cancela');
        $this->assertGuarded(fn () => DB::table('voice_calls')->where('id', $id)->update(['status' => 'requested', 'status_changed_at' => now()]), self::FORWARD, 'ni vuelve a requested');
        $this->close($id);
        foreach (['ambiguous', 'requested', 'dialing', 'completed', 'canceled'] as $hacia) {
            $this->assertGuarded(fn () => DB::table('voice_calls')->where('id', $id)->update(['status' => $hacia, 'status_changed_at' => now()]), self::FORWARD,
                "failed_to_start es final: → {$hacia}");
        }
        $this->assertSame('failed_to_start', DB::table('voice_calls')->where('id', $id)->value('status'));
    }

    public function test_down_devuelve_exactamente_el_disparador_de_db8_y_up_vuelve_a_e19_3(): void
    {
        $m = $this->e193();
        $conE193 = $this->triggerDefinition();
        DB::beginTransaction();
        try {
            // El de DB8 DE VERDAD: el que crea su propia migración (sin filas, down() + up() de DB8).
            $this->voiceMigration()->down();
            $this->voiceMigration()->up();
            $deDb8 = $this->triggerDefinition();
            $this->assertNotSame($deDb8, $conE193);

            $m->up();
            $this->assertSame($conE193, $this->triggerDefinition(), 'up() deja lo mismo que en un migrate real');
            $m->down();
            $this->assertSame($deDb8, $this->triggerDefinition(), 'down() devuelve el de DB8, carácter a carácter');
            $id = $this->ambiguous();
            $this->assertGuarded(fn () => $this->close($id), self::FORWARD, 'con el de DB8, la transición vuelve a estar prohibida');
            $m->up();
            $this->assertSame($conE193, $this->triggerDefinition());
            $this->assertAccepted(fn () => $this->close($id));
        } finally {
            DB::rollBack();
        }
        $this->assertSame($conE193, $this->triggerDefinition());
    }

    public function test_down_es_reversible_con_filas_ya_cerradas_asi(): void
    {
        $m = $this->e193();
        $cerrada = $this->ambiguous();
        $this->close($cerrada);
        $antes = (array) DB::table('voice_calls')->where('id', $cerrada)->first();
        $huella = $this->voiceFootprint();

        DB::beginTransaction();
        try {
            $m->down();   // el disparador no revisa filas: no hay nada que negar
            $this->assertSame($antes, (array) DB::table('voice_calls')->where('id', $cerrada)->first(), 'la fila no cambia');
            if ($this->isPostgres()) {
                $this->assertSame([], $this->violatedChecks('voice_calls', array_diff_key($antes, ['id' => 1])), 'y cumple el esquema de DB8');
            }
            $this->assertGuarded(fn () => DB::table('voice_calls')->where('id', $cerrada)->update(['status' => 'completed', 'status_changed_at' => now()]), self::FORWARD,
                'sigue siendo un final');
            $m->up();
            $this->assertSame($huella, $this->voiceFootprint(), 'ida y vuelta: el mismo esquema');
        } finally {
            DB::rollBack();
        }
    }

    public function test_up_se_niega_si_el_esquema_no_es_el_de_db8_y_no_deja_nada(): void
    {
        $m = $this->e193();
        DB::beginTransaction();
        try {
            DB::statement($this->isPostgres() ? 'DROP TRIGGER vc_status_forward ON voice_calls' : 'DROP TRIGGER vc_status_forward');
            $cuantos = $this->voiceTriggerCount();
            try {
                $m->up();
                $this->fail('up() rehízo un disparador que no existía');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('vc_status_forward no existe exactamente una vez en voice_calls (0)', $e->getMessage());
            }
            $this->assertSame(0, $this->namedTriggers(), 'no crea nada');
            $this->assertSame($cuantos, $this->voiceTriggerCount());
        } finally {
            DB::rollBack();
        }
        $this->assertSame(1, $this->namedTriggers());
    }

    // ── Solo PostgreSQL: el rol de la aplicación ────────────────────────────

    public function test_solo_en_postgres_la_transicion_nueva_funciona_con_el_rol_de_la_aplicacion(): void
    {
        $this->requirePostgres('los roles y los privilegios solo existen en PostgreSQL.');

        $this->withAppRole(function (Migration $h8): void {
            // Como un `migrate` real con el rol de producción: DB8, sus enmiendas y E19-3 (todo como dueño).
            $h8->down();
            $h8->up();
            foreach (['2026_11_13_100001_allow_strategy_requester_on_voice_calls', '2026_11_14_100001_allow_webhook_unhealthy_reason_on_voice_brakes',
                '2026_11_14_100002_add_one_call_per_strategy_job_index_on_voice_calls', self::E193] as $migracion) {
                (require database_path("migrations/{$migracion}.php"))->up();
            }
            $this->assertSame([true, true, true, true, false], $this->privileges('voice_calls'), 'llamadas: arwd (su máquina de estados), sin TRUNCATE');
            $this->assertSame([true, true, false, false, false], $this->privileges('voice_call_events'), 'eventos: solo inserción');
            $this->assertNotSame(self::APP_ROLE, (string) DB::scalar("SELECT tableowner FROM pg_tables WHERE tablename = 'voice_calls'"), 'el rol de prueba NO es el dueño');

            $fila = $this->ambiguous('+573000000305', now()->subMinutes(VoiceAmbiguousResolution::GRACE_MINUTES + 1));
            $uuid = (string) DB::table('voice_calls')->where('id', $fila)->value('uuid');
            $otra = $this->ambiguous('+573000000306');

            [$estado, $mensaje] = $this->asApp(function () use ($fila, $uuid, $otra): void {
                // La orden de consola con los privilegios de la aplicación: FOR UPDATE, la transición y su evento.
                $codigo = Artisan::call('voice:resolve-ambiguous', ['call' => $uuid, '--confirm' => true, '--reason' => 'Comprobado en Twilio: ninguna llamada a esa hora']);
                $salida = Artisan::output();
                $this->assertSame(0, $codigo, $salida);
                $this->assertSame('failed_to_start', DB::table('voice_calls')->where('id', $fila)->value('status'));
                $this->assertSame(1, VoiceCallEvent::query()->where('call_id', $fila)->where('kind', 'reconciled')->where('outcome', 'ambiguous_resolved')->count());
                // La misma transición, directa, también (es la de la conciliación automática).
                $this->assertTrue(VoiceCall::transition($otra, [VoiceCall::STATUS_AMBIGUOUS], VoiceAmbiguousResolution::FINAL, VoiceAmbiguousResolution::transitionValues()));
            });
            $this->assertNull($estado, "el rol de la aplicación no cierra la fila: {$mensaje}");

            // Lo que no es de la aplicación sigue sin serlo, y el disparador la vigila igual.
            foreach ([
                'ALTER TABLE voice_calls DISABLE TRIGGER vc_status_forward',
                'DROP TRIGGER vc_status_forward ON voice_calls',
                'CREATE OR REPLACE TRIGGER vc_status_forward BEFORE UPDATE OF status ON voice_calls FOR EACH ROW EXECUTE FUNCTION voice_forbid(\'x\')',
                'SET session_replication_role = replica',
            ] as $sql) {
                [$sqlstate, $mensaje] = $this->asApp(fn () => DB::statement($sql));
                $this->assertSame('42501', $sqlstate, "{$sql}: {$mensaje}");
            }
            [$sqlstate, $mensaje] = $this->asApp(fn () => DB::table('voice_calls')->where('id', $otra)->update(['status' => 'canceled', 'policy_reason' => 'publication_moved',
                'ended_at' => now(), 'status_changed_at' => now()]));
            $this->assertSame('P0001', $sqlstate, $mensaje);
            $this->assertStringContainsString(self::FORWARD, $mensaje);
        });
    }

    // ── Apoyo ───────────────────────────────────────────────────────────────

    private function e193(): Migration
    {
        return $this->e193 ??= require database_path('migrations/'.self::E193.'.php');
    }

    /** Una saliente pedida por Laravel que quedó `ambiguous` (sin ids del proveedor, como deja el job). */
    private function ambiguous(string $remoto = '+573000000301', ?Carbon $desde = null): int
    {
        $id = $this->insertOutbound(['remote_hash' => $this->hex('e19-3-'.$remoto), 'remote_last4' => substr($remoto, -4)]);
        DB::table('voice_calls')->where('id', $id)->update(['status' => 'ambiguous', 'failure_code' => 'timeout', 'status_changed_at' => $desde ?? now(), 'updated_at' => now()]);

        return $id;
    }

    /** La salida de E19-3, como la escribe el servicio (`VoiceAmbiguousResolution::transitionValues()`). @param  array<string, mixed>  $extra */
    private function close(int $id, array $extra = []): int
    {
        return DB::table('voice_calls')->where('id', $id)->update(['status' => 'failed_to_start', 'status_changed_at' => now(), 'updated_at' => now()]
            + VoiceAmbiguousResolution::transitionValues() + $extra);
    }

    private function namedTriggers(): int
    {
        return $this->isPostgres()
            ? (int) DB::scalar("SELECT count(*) FROM pg_trigger WHERE NOT tgisinternal AND tgrelid = 'voice_calls'::regclass AND tgname = 'vc_status_forward'")
            : DB::table('sqlite_master')->where('type', 'trigger')->where('tbl_name', 'voice_calls')->where('name', 'vc_status_forward')->count();
    }

    private function triggerDefinition(): ?string
    {
        return $this->isPostgres()
            ? DB::scalar("SELECT pg_get_triggerdef(oid) FROM pg_trigger WHERE tgrelid = 'voice_calls'::regclass AND tgname = 'vc_status_forward'")
            : DB::table('sqlite_master')->where('type', 'trigger')->where('name', 'vc_status_forward')->value('sql');
    }
}
