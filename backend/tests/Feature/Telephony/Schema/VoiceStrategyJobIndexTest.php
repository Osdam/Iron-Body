<?php

namespace Tests\Feature\Telephony\Schema;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * SC-13 DEL CIERRE (D-V9-2, «una llamada por trabajo»): el índice único
 * parcial `vc_one_call_per_strategy_job` (migración `2026_11_14_100002`), el
 * respaldo en el esquema de la idempotencia por trabajo que hoy guarda la
 * puerta bajo su cerrojo.
 *
 *  - Existe en los DOS motores con el mismo predicado (como los parciales de
 *    DB8).
 *  - Dos llamadas de estrategia del mismo trabajo: la segunda se rechaza,
 *    también si la primera ya terminó (una por trabajo, para siempre); otro
 *    trabajo, o una llamada de una PERSONA con el mismo `client_request_id`,
 *    no cambian.
 *  - `up()` se niega si ya existe o si hay un trabajo con dos llamadas (sin
 *    borrar nada); `down()` lo quita sin tocar filas; ida y vuelta.
 *  - En PostgreSQL no espera sin límite un cerrojo.
 */
class VoiceStrategyJobIndexTest extends TestCase
{
    use RefreshDatabase;
    use VoiceSchemaFixtures;

    private const SC13_MIGRATION = '2026_11_14_100002_add_one_call_per_strategy_job_index_on_voice_calls';

    private const INDICE = 'vc_one_call_per_strategy_job';

    private const OTRA = 'pgsql_sc13_cerrojo';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        DB::purge(self::OTRA);
        parent::tearDown();
    }

    private function migracion(): Migration
    {
        return require database_path('migrations/'.self::SC13_MIGRATION.'.php');
    }

    /** Una saliente `requested` pedida por una estrategia para el trabajo `$trabajo`, tal como la escribe la puerta. */
    private function deEstrategia(string $trabajo, array $o = []): array
    {
        return $this->outboundRow(array_merge(['client_request_id' => $trabajo, 'requested_by_type' => 'strategy', 'requested_by_admin_id' => null,
            'requested_by_name' => 'Estrategia renovacion-voz', 'requested_by_role' => null, 'attempt' => 1], $o));
    }

    private function existe(): bool
    {
        return array_key_exists(self::INDICE, $this->voicePartialIndexes());
    }

    public function test_existe_en_los_dos_motores_con_el_mismo_predicado(): void
    {
        $this->assertSame($this->isPostgres() ? "((requested_by_type)::text = 'strategy'::text)" : "requested_by_type = 'strategy'", $this->voicePartialIndexes()[self::INDICE] ?? null);
        $this->assertCount(4, $this->voicePartialIndexes(), 'los 3 de DB8 y este');
    }

    public function test_una_llamada_por_trabajo_de_estrategia_y_lo_demas_no_cambia(): void
    {
        $trabajo = (string) Str::uuid();
        $primera = (int) DB::table('voice_calls')->insertGetId($this->deEstrategia($trabajo));

        $this->assertUniqueRejects(fn () => DB::table('voice_calls')->insert($this->deEstrategia($trabajo)), self::INDICE, 'voice_calls', ['client_request_id'], 'el mismo trabajo, otra llamada');
        $this->assertAccepted(fn () => DB::table('voice_calls')->insert($this->deEstrategia((string) Str::uuid())), 'otro trabajo');
        // Una persona con el mismo `client_request_id` (su UNIQUE es por persona, DB8): el índice solo mira las de estrategia.
        $this->assertAccepted(fn () => DB::table('voice_calls')->insert($this->outboundRow(['client_request_id' => $trabajo])), 'una persona con el mismo id de petición');

        // Una por trabajo para siempre: aunque la primera ya terminara, otra fila de ese trabajo no entra.
        DB::table('voice_calls')->where('id', $primera)->update(['status' => 'canceled', 'policy_reason' => 'outside_hours', 'ended_at' => now(), 'status_changed_at' => now()]);
        $this->assertUniqueRejects(fn () => DB::table('voice_calls')->insert($this->deEstrategia($trabajo)), self::INDICE, 'voice_calls', ['client_request_id'], 'el mismo trabajo tras cancelarse la primera');
        $this->assertSame(1, (int) DB::table('voice_calls')->where('requested_by_type', 'strategy')->where('client_request_id', $trabajo)->count());
    }

    public function test_up_se_niega_si_ya_existe_o_con_un_trabajo_de_dos_llamadas_y_down_lo_quita_sin_tocar_filas(): void
    {
        $m = $this->migracion();
        $antes = $this->voicePartialIndexes();

        DB::beginTransaction();
        try {
            try {
                $m->up();
                $this->fail('up() creó el índice dos veces');
            } catch (RuntimeException $e) {
                $this->assertNotInstanceOf(QueryException::class, $e, $e->getMessage());
                $this->assertStringContainsString('vc_one_call_per_strategy_job ya existe en voice_calls', $e->getMessage());
            }

            // Sin el índice (down), dos llamadas del mismo trabajo entran… y up() se niega a crearlo sin borrar nada.
            $m->down();
            $this->assertFalse($this->existe());
            $trabajo = (string) Str::uuid();
            DB::table('voice_calls')->insert($this->deEstrategia($trabajo));
            DB::table('voice_calls')->insert($this->deEstrategia($trabajo));
            try {
                $m->up();
                $this->fail('up() creó el índice con un trabajo de dos llamadas');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('1 trabajo(s) de estrategia con más de una llamada en voice_calls', $e->getMessage());
            }
            $this->assertFalse($this->existe(), 'no lo creó');
            $this->assertSame(2, (int) DB::table('voice_calls')->where('client_request_id', $trabajo)->count(), 'y no borró nada');
        } finally {
            DB::rollBack();
        }
        $this->assertSame($antes, $this->voicePartialIndexes());

        // Ida y vuelta sin filas; down() otra vez no hace nada (idempotente).
        DB::beginTransaction();
        try {
            $m->down();
            $m->down();
            $this->assertFalse($this->existe());
            $m->up();
            $this->assertSame($antes, $this->voicePartialIndexes());
        } finally {
            DB::rollBack();
        }
        $this->assertSame($antes, $this->voicePartialIndexes());
    }

    public function test_solo_en_postgres_no_espera_sin_limite_un_cerrojo(): void
    {
        $this->requirePostgres('lock_timeout y los cerrojos de tabla son de PostgreSQL.');
        $antes = $this->voicePartialIndexes();
        $m = $this->migracion();

        // Otra transacción lee voice_calls (ACCESS SHARE): DROP INDEX necesita ACCESS EXCLUSIVE.
        config(['database.connections.'.self::OTRA => config('database.connections.'.DB::getDefaultConnection())]);
        $otra = DB::connection(self::OTRA);
        $otra->beginTransaction();
        $otra->statement('LOCK TABLE voice_calls IN ACCESS SHARE MODE NOWAIT');
        try {
            DB::statement("SET LOCAL lock_timeout = '200ms'");
            $t0 = microtime(true);
            try {
                $m->down();
                $this->fail('down() esperó el cerrojo o lo atravesó');
            } catch (QueryException $e) {
                $this->assertSame('55P03', (string) $e->getCode(), $e->getMessage());
            }
            $this->assertLessThan(5.0, microtime(true) - $t0);
            $this->assertSame($antes, $this->voicePartialIndexes(), 'no tocó nada');
        } finally {
            $otra->rollBack();
        }

        // Sin límite en la sesión, la migración pone el suyo (5 s).
        DB::beginTransaction();
        try {
            $m->down();
            DB::statement('SET LOCAL lock_timeout = 0');
            $m->up();
            $this->assertSame('5s', (string) DB::scalar("SELECT current_setting('lock_timeout')"));
            $this->assertSame($antes, $this->voicePartialIndexes());
        } finally {
            DB::rollBack();
        }
    }
}
