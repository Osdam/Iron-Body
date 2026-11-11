<?php

namespace Tests\Feature\Outreach\V9;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Feature\Outreach\V9\Support\V9World;
use Tests\TestCase;

/**
 * V9 · `vc_requester_chk` CON EL SOLICITANTE `strategy` (migración
 * `2026_11_13_100001`; Enmienda 17 del H8 propuesta, contrato H9 D9-9.4):
 *
 *  - SOLO EN POSTGRESQL (como todos los CHECK de forma de DB8): admite una
 *    saliente pedida por una estrategia —sin cuenta ni rol, con el trabajo
 *    como `client_request_id`, la etiqueta «Estrategia <clave>» y siempre un
 *    primer intento— y CONSERVA las dos ramas de DB8 (`admin` y `system`);
 *    con el mismo número de CHECK en `voice_%` (51) y uno solo con ese nombre;
 *  - `down()` se niega con llamadas de estrategia y, sin ellas, restaura el
 *    CHECK de DB8; `up()` otra vez lo amplía (ida y vuelta);
 *  - en SQLite no hay CHECK que reescribir: la migración no toca el esquema.
 */
class V9RequesterCheckTest extends TestCase
{
    use RefreshDatabase;
    use V9World;

    private const MIGRATION = '2026_11_13_100001_allow_strategy_requester_on_voice_calls';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->v9VoiceConfig();
    }

    private function migracion(): Migration
    {
        return require database_path('migrations/'.self::MIGRATION.'.php');
    }

    /** Una saliente `requested` pedida por una estrategia, tal como la escribe la puerta (sin insertarla). @return array<string, mixed> */
    private static function filaDeEstrategia(array $cambio = []): array
    {
        return array_merge([
            'uuid' => (string) Str::uuid(), 'call_ref' => (string) Str::uuid(), 'direction' => 'outbound', 'status' => 'requested',
            'target_source' => 'canary_registry', 'canary_number_uuid' => (string) Str::uuid(), 'voice_contact_id' => null,
            'remote_hash' => hash('sha256', 'v9-remoto'), 'remote_last4' => '9001',
            'client_request_id' => (string) Str::uuid(), 'requested_by_type' => 'strategy', 'requested_by_admin_id' => null,
            'requested_by_name' => 'Estrategia renovacion-voz', 'requested_by_role' => null, 'attempt' => 1, 'parent_call_id' => null,
            'voice_publication_seq' => 1, 'part' => 'canary', 'stamp_state' => 'applied', 'studio_version_uuid' => (string) Str::uuid(),
            'studio_content_hash' => str_repeat('a', 64), 'voice_profile_hash' => hash('sha256', 'p'), 'spec_hash' => hash('sha256', 's'),
            'payload_hash' => hash('sha256', 'c'), 'compiler_version' => 'ultron-voice-compiler/1', 'provider' => 'elevenlabs', 'provider_agent_id' => 'agent_v9_canary_0001',
            'budget_day' => '2026-11-17', 'reserved_micros' => 610500, 'tool_calls' => 0,
            'status_changed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ], $cambio);
    }

    /** @return array{checks: int, named: int, definition: string} */
    private function huella(): array
    {
        $filas = DB::select("SELECT pg_get_constraintdef(k.oid) AS def FROM pg_constraint k JOIN pg_class c ON c.oid = k.conrelid WHERE k.contype = 'c' AND c.relname = 'voice_calls' AND k.conname = 'vc_requester_chk'");

        return [
            'checks' => (int) DB::scalar("SELECT count(*) FROM pg_constraint k JOIN pg_class c ON c.oid = k.conrelid WHERE k.contype = 'c' AND c.relname LIKE 'voice\\_%'"),
            'named' => count($filas),
            'definition' => (string) ($filas[0]->def ?? ''),
        ];
    }

    public function test_en_sqlite_no_hay_check_que_reescribir_y_la_migracion_no_toca_el_esquema(): void
    {
        if ($this->isPostgres()) {
            $this->markTestSkipped('Solo SQLite: en PostgreSQL lo cubren las pruebas de abajo.');
        }
        $esquema = static fn (): array => DB::table('sqlite_master')->where('tbl_name', 'voice_calls')->orderBy('name')->get(['type', 'name', 'sql'])->map(static fn (object $o): string => "{$o->type} {$o->name}: {$o->sql}")->all();
        $antes = $esquema();
        $this->migracion()->up();
        $this->migracion()->down();
        $this->assertSame($antes, $esquema());
        // La regla la vigila el servicio: en SQLite la fila de una estrategia entra (la puerta es la única que la escribe).
        $this->assertSame(1, DB::table('voice_calls')->insert(self::filaDeEstrategia()) ? 1 : 0);
    }

    public function test_solo_en_postgres_el_check_admite_la_estrategia_y_conserva_las_ramas_de_db8(): void
    {
        $this->requirePostgres('los CHECK de forma de DB8 solo existen en PostgreSQL.');

        $h = $this->huella();
        $this->assertSame([51, 1], [$h['checks'], $h['named']], 'DROP + ADD: el mismo número de CHECK en voice_% y uno con ese nombre');
        $this->assertStringContainsString("'strategy'", $h['definition']);
        $this->assertStringContainsString("'admin'", $h['definition']);
        $this->assertStringContainsString("'system'", $h['definition']);

        $this->assertSame([], $this->violatedChecks('voice_calls', self::filaDeEstrategia()), 'la fila de una estrategia es válida');
        $this->assertAccepted(fn () => DB::table('voice_calls')->insert(self::filaDeEstrategia()), 'la saliente de una estrategia');

        foreach ([
            'una estrategia con cuenta' => ['requested_by_admin_id' => 7],
            'una estrategia con rol' => ['requested_by_role' => 'Super Admin'],
            'una estrategia sin trabajo' => ['client_request_id' => null],
            'una estrategia con el nombre de una persona' => ['requested_by_name' => 'Ana Pérez'],
            'una estrategia sin nombre' => ['requested_by_name' => null],
            'un solicitante desconocido' => ['requested_by_type' => 'robot'],
            'una persona sin su petición (DB8)' => ['requested_by_type' => 'admin', 'requested_by_admin_id' => 7, 'client_request_id' => null],
            'el sistema en un primer intento (DB8)' => ['requested_by_type' => 'system', 'requested_by_name' => null, 'client_request_id' => null],
        ] as $caso => $cambio) {
            $this->assertSame(['vc_requester_chk'], $this->violatedChecks('voice_calls', self::filaDeEstrategia($cambio)), $caso);
            $this->assertRejected(fn () => DB::table('voice_calls')->insert(self::filaDeEstrategia($cambio)), '23514', 'vc_requester_chk', $caso);
        }

        // Un reintento de negocio de una estrategia es OTRO trabajo, nunca una fila hija: con su madre válida, solo cae el requester.
        $madre = (int) DB::table('voice_calls')->insertGetId(self::filaDeEstrategia(['remote_hash' => hash('sha256', 'v9-madre')]));
        DB::table('voice_calls')->where('id', $madre)->update(['status' => 'canceled', 'policy_reason' => 'outside_hours', 'ended_at' => now(), 'status_changed_at' => now()]);
        $hija = self::filaDeEstrategia(['attempt' => 2, 'parent_call_id' => $madre]);
        $this->assertSame(['vc_requester_chk'], $this->violatedChecks('voice_calls', $hija));
        $this->assertRejected(fn () => DB::table('voice_calls')->insert($hija), '23514', 'vc_requester_chk', 'una estrategia en un segundo intento');

        // Lo de DB8 sigue: una persona con su petición; el sistema solo en un reintento.
        $this->assertSame([], $this->violatedChecks('voice_calls', self::filaDeEstrategia(['requested_by_type' => 'admin', 'requested_by_admin_id' => 7, 'requested_by_name' => 'Ana', 'requested_by_role' => 'Super Admin'])));
        $this->assertSame([], $this->violatedChecks('voice_calls', self::filaDeEstrategia(['requested_by_type' => 'system', 'requested_by_name' => null, 'client_request_id' => null, 'attempt' => 2, 'parent_call_id' => $madre])));
    }

    public function test_solo_en_postgres_down_se_niega_con_llamadas_de_estrategia_y_sin_ellas_restaura_db8_ida_y_vuelta(): void
    {
        $this->requirePostgres('los CHECK de forma de DB8 solo existen en PostgreSQL.');
        $m = $this->migracion();
        $antes = $this->huella();

        DB::beginTransaction();
        try {
            DB::table('voice_calls')->insert(self::filaDeEstrategia());
            try {
                $m->down();
                $this->fail('down() no se negó con una llamada de estrategia');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('down() se niega a restaurar el CHECK de DB8 con 1 llamada(s) pedida(s) por una estrategia', $e->getMessage());
            }
            $this->assertSame($antes, $this->huella(), 'down() no tocó nada');
        } finally {
            DB::rollBack();
        }

        DB::beginTransaction();
        try {
            $m->down();
            $db8 = $this->huella();
            $this->assertSame([51, 1], [$db8['checks'], $db8['named']]);
            $this->assertStringNotContainsString("'strategy'", $db8['definition'], 'el CHECK de DB8, tal cual');
            $this->assertRejected(fn () => DB::table('voice_calls')->insert(self::filaDeEstrategia()), '23514', 'vc_requester_chk', 'DB8 no admite estrategias');

            $m->up();
            $this->assertSame($antes, $this->huella(), 'up() deja lo mismo que la primera vez');
            $this->assertAccepted(fn () => DB::table('voice_calls')->insert(self::filaDeEstrategia()));
        } finally {
            DB::rollBack();
        }
        $this->assertSame($antes, $this->huella());
    }

    public function test_solo_en_postgres_up_se_niega_sobre_un_esquema_que_no_es_el_de_db8(): void
    {
        $this->requirePostgres('los CHECK de forma de DB8 solo existen en PostgreSQL.');

        DB::beginTransaction();
        try {
            try {
                $this->migracion()->up();
                $this->fail('up() amplió dos veces el CHECK');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('ya admite el solicitante «strategy»', $e->getMessage());
            }
        } finally {
            DB::rollBack();
        }
    }
}
