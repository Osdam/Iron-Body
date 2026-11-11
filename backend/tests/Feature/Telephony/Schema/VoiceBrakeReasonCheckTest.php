<?php

namespace Tests\Feature\Telephony\Schema;

use App\Models\Voice\VoiceBrakeEntry;
use App\Services\Telephony\VoiceBrake;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/**
 * SC-3 DEL CIERRE (R8-12): `vbr_reason_chk` CON EL MOTIVO `webhook_unhealthy`
 * (migración `2026_11_14_100001`, el patrón de V9 `2026_11_13_100001`):
 *
 *  - el modelo y el servicio del freno admiten exactamente los seis de DB8 y
 *    `webhook_unhealthy` (los dos motores);
 *  - SOLO EN POSTGRESQL (como todos los CHECK de forma de DB8): el CHECK lo
 *    admite, CONSERVA los seis de DB8 literales y rechaza cualquier otro; el
 *    mismo número de CHECK en `voice_%` (51) y uno solo con ese nombre;
 *  - `down()` se niega con aperturas del motivo nuevo y, sin ellas, restaura el
 *    CHECK EXACTO de DB8 (comparado con el que deja DB8 recién creada); `up()`
 *    otra vez lo amplía (ida y vuelta) y se niega a ampliar dos veces o sobre
 *    un esquema sin el CHECK;
 *  - no espera sin límite un cerrojo: sin `lock_timeout` en la sesión pone 5 s
 *    y, con otra transacción sobre `voice_brakes`, cae limpia (55P03) sin
 *    tocar nada;
 *  - en SQLite no hay CHECK que reescribir: la migración no toca el esquema.
 */
class VoiceBrakeReasonCheckTest extends TestCase
{
    use RefreshDatabase;
    use VoiceSchemaFixtures;

    private const SC3_MIGRATION = '2026_11_14_100001_allow_webhook_unhealthy_reason_on_voice_brakes';

    private const V9_MIGRATION = '2026_11_13_100001_allow_strategy_requester_on_voice_calls';

    /** Una segunda conexión a la misma base, para sostener un cerrojo desde otra transacción. */
    private const OTRA = 'pgsql_sc3_cerrojo';

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
        return require database_path('migrations/'.self::SC3_MIGRATION.'.php');
    }

    /** @return list<string> los seis motivos de DB8, de su migración (no de la nueva) */
    private function motivosDeDb8(): array
    {
        $db8 = require database_path('migrations/'.self::MIGRATION.'.php');

        return $db8::BRAKE_REASONS;
    }

    /** @return array{checks: int, named: int, definition: string} */
    private function huella(): array
    {
        $filas = DB::select("SELECT pg_get_constraintdef(k.oid) AS def FROM pg_constraint k JOIN pg_class c ON c.oid = k.conrelid WHERE k.contype = 'c' AND c.relname = 'voice_brakes' AND k.conname = 'vbr_reason_chk'");

        return [
            'checks' => $this->voiceCheckCount(),
            'named' => count($filas),
            'definition' => (string) ($filas[0]->def ?? ''),
        ];
    }

    /** DROP + ADD en UNA sentencia: un solo cerrojo y ningún instante sin CHECK (vacía el registro de consultas). */
    private function assertUnaSolaSentencia(string $que): void
    {
        $alters = array_values(array_filter(array_column(DB::getQueryLog(), 'query'), static fn (string $q): bool => str_starts_with($q, 'ALTER TABLE')));
        DB::flushQueryLog();
        $this->assertCount(1, $alters, "{$que}: un solo ALTER TABLE");
        $this->assertMatchesRegularExpression('/^ALTER TABLE voice_brakes DROP CONSTRAINT (IF EXISTS )?vbr_reason_chk, ADD CONSTRAINT vbr_reason_chk CHECK \\(/', $alters[0], $que);
    }

    /** Una apertura del freno tal como la escribe el sistema, con `$reason` (sin insertarla). @return array<string, mixed> */
    private function apertura(string $reason): array
    {
        return ['uuid' => (string) Str::uuid(), 'kind' => 'open', 'brake_id' => null, 'reason' => $reason, 'open_key' => 'webhook:sc3-'.bin2hex(random_bytes(8)),
            'call_id' => null, 'event_id' => null, 'evidence' => '{"where":"webhook_health","codes":["webhook_auto_disabled"]}', 'note' => null, 'client_request_id' => null,
            'actor_type' => 'system', 'actor_admin_id' => null, 'actor_name' => 'Sistema (freno automático)', 'actor_role' => null, 'created_at' => now()];
    }

    // ── Los dos motores ─────────────────────────────────────────────────────

    public function test_el_modelo_y_el_servicio_admiten_los_seis_de_db8_y_webhook_unhealthy_y_ninguno_mas(): void
    {
        $this->assertSame([...$this->motivosDeDb8(), 'webhook_unhealthy'], VoiceBrakeEntry::REASONS, 'los seis de DB8 en su orden y el nuevo al final');
        $m = $this->migracion();
        $this->assertSame('reason IS NULL OR reason IN ('.implode(', ', array_map(static fn (string $r): string => "'{$r}'", VoiceBrakeEntry::REASONS)).')', $m::EXPRESSION,
            'el CHECK nuevo es exactamente el conjunto del modelo');
        $this->assertStringStartsWith('reason IS NULL OR reason IN (', $m::h8Expression());
        $this->assertSame(str_replace(", 'webhook_unhealthy')", ')', $m::EXPRESSION), $m::h8Expression(), 'quitando el nuevo queda el de DB8, literal');

        $apertura = VoiceBrake::open('webhook_unhealthy', 'webhook:sc3-modelo', ['where' => 'webhook_health', 'codes' => ['webhook_disabled']]);
        $this->assertNotNull($apertura);
        $this->assertSame('webhook_unhealthy', VoiceBrakeEntry::query()->where('open_key', 'webhook:sc3-modelo')->value('reason'));
        $this->assertTrue(VoiceBrake::isOpen());

        // El conjunto es cerrado en el servicio también en SQLite (donde no hay CHECK).
        foreach (['webhook_down', 'manual', 'WEBHOOK_UNHEALTHY'] as $otro) {
            try {
                VoiceBrake::open($otro, 'webhook:sc3-'.strtolower($otro), []);
                $this->fail("el servicio abrió el freno con «{$otro}»");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        // Las causas del fixture del H8 (§brake) siguen siendo las seis: el motivo nuevo no es una de ellas.
        $this->assertSame($this->motivosDeDb8(), array_values(array_unique(VoiceBrake::TRIGGERS)));
    }

    public function test_en_sqlite_no_hay_check_que_reescribir_y_la_migracion_no_toca_el_esquema(): void
    {
        if ($this->isPostgres()) {
            $this->markTestSkipped('Solo SQLite: en PostgreSQL lo cubren las pruebas de abajo.');
        }
        $esquema = static fn (): array => DB::table('sqlite_master')->where('tbl_name', 'voice_brakes')->orderBy('name')->get(['type', 'name', 'sql'])
            ->map(static fn (object $o): string => "{$o->type} {$o->name}: {$o->sql}")->all();
        $antes = $esquema();
        $this->migracion()->up();
        $this->migracion()->down();
        $this->assertSame($antes, $esquema());
        $this->assertAccepted(fn () => DB::table('voice_brakes')->insert($this->apertura('webhook_unhealthy')), 'la apertura del motivo nuevo');
    }

    // ── Solo PostgreSQL ─────────────────────────────────────────────────────

    public function test_solo_en_postgres_el_check_admite_webhook_unhealthy_conserva_los_seis_y_rechaza_lo_demas(): void
    {
        $this->requirePostgres('los CHECK de forma de DB8 solo existen en PostgreSQL.');

        $h = $this->huella();
        $this->assertSame([51, 1], [$h['checks'], $h['named']], 'DROP + ADD: el mismo número de CHECK en voice_% y uno con ese nombre');
        foreach (VoiceBrakeEntry::REASONS as $motivo) {
            $this->assertStringContainsString("'{$motivo}'", $h['definition'], $motivo);
            $this->assertSame([], $this->violatedChecks('voice_brakes', $this->apertura($motivo)), $motivo);
            $this->assertAccepted(fn () => DB::table('voice_brakes')->insert($this->apertura($motivo)), $motivo);
        }
        foreach (['manual', 'webhook_down', 'WEBHOOK_UNHEALTHY', 'webhook_unhealthy '] as $otro) {
            $this->assertSame(['vbr_reason_chk'], $this->violatedChecks('voice_brakes', $this->apertura($otro)), $otro);
            $this->assertRejected(fn () => DB::table('voice_brakes')->insert($this->apertura($otro)), '23514', 'vbr_reason_chk', $otro);
        }
        // Una suelta sigue sin motivo (vbr_shape_chk); el CHECK la admite.
        $abierta = $this->insertBrakeOpen(['reason' => 'webhook_unhealthy', 'open_key' => 'webhook:sc3-suelta']);
        $this->assertAccepted(fn () => $this->insertBrakeRelease($abierta), 'la suelta de una apertura del motivo nuevo');
    }

    public function test_solo_en_postgres_down_se_niega_con_el_motivo_nuevo_y_sin_el_restaura_el_check_exacto_de_db8_ida_y_vuelta(): void
    {
        $this->requirePostgres('los CHECK de forma de DB8 solo existen en PostgreSQL.');
        $m = $this->migracion();
        $antes = $this->huella();

        // Con una apertura del motivo nuevo: se niega y no toca nada.
        DB::beginTransaction();
        try {
            $this->insertBrakeOpen(['reason' => 'webhook_unhealthy', 'open_key' => 'webhook:sc3-down']);
            try {
                $m->down();
                $this->fail('down() no se negó con una apertura de webhook_unhealthy');
            } catch (RuntimeException $e) {
                $this->assertNotInstanceOf(QueryException::class, $e, $e->getMessage());
                $this->assertStringContainsString('down() se niega a restaurar el CHECK de DB8 con 1 apertura(s) del freno por «webhook_unhealthy»', $e->getMessage());
            }
            $this->assertSame($antes, $this->huella(), 'down() no tocó nada');
            $this->assertSame(1, (int) DB::table('voice_brakes')->where('reason', 'webhook_unhealthy')->count(), 'y no borró la fila');
        } finally {
            DB::rollBack();
        }

        // Sin ellas: el CHECK EXACTO de DB8. Para saber cuál es, DB8 desde cero (down + up, sin filas) dentro de la transacción.
        DB::beginTransaction();
        try {
            $this->voiceMigration()->down();
            $this->voiceMigration()->up();
            (require database_path('migrations/'.self::V9_MIGRATION.'.php'))->up();
            $db8 = $this->huella();
            $this->assertSame([51, 1], [$db8['checks'], $db8['named']]);
            $this->assertStringNotContainsString("'webhook_unhealthy'", $db8['definition']);

            DB::enableQueryLog();
            $m->up();
            $this->assertSame($antes, $this->huella(), 'up() sobre DB8 recién creada deja lo mismo que el migrate');
            $this->assertUnaSolaSentencia('up()');

            $m->down();
            $this->assertSame($db8, $this->huella(), 'down() deja el CHECK EXACTO de DB8');
            $this->assertUnaSolaSentencia('down()');
            DB::disableQueryLog();
            $this->assertRejected(fn () => DB::table('voice_brakes')->insert($this->apertura('webhook_unhealthy')), '23514', 'vbr_reason_chk', 'DB8 no admite el motivo nuevo');

            $m->up();
            $this->assertSame($antes, $this->huella(), 'up() otra vez lo amplía igual');
            $this->assertAccepted(fn () => DB::table('voice_brakes')->insert($this->apertura('webhook_unhealthy')));
        } finally {
            DB::rollBack();
        }
        $this->assertSame($antes, $this->huella());
    }

    public function test_solo_en_postgres_up_se_niega_a_ampliar_dos_veces_o_sin_el_check(): void
    {
        $this->requirePostgres('los CHECK de forma de DB8 solo existen en PostgreSQL.');
        $antes = $this->huella();

        DB::beginTransaction();
        try {
            try {
                $this->migracion()->up();
                $this->fail('up() amplió dos veces el CHECK');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('ya admite el motivo «webhook_unhealthy»', $e->getMessage());
            }
            $this->assertSame($antes, $this->huella());

            DB::statement('ALTER TABLE voice_brakes DROP CONSTRAINT vbr_reason_chk');
            try {
                $this->migracion()->up();
                $this->fail('up() se aplicó sobre un esquema sin vbr_reason_chk');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('vbr_reason_chk no existe exactamente una vez en voice_brakes (0)', $e->getMessage());
            }
            $this->assertSame(0, $this->huella()['named'], 'no lo creó a medias');
        } finally {
            DB::rollBack();
        }
        $this->assertSame($antes, $this->huella());
    }

    public function test_solo_en_postgres_no_espera_sin_limite_un_cerrojo(): void
    {
        $this->requirePostgres('lock_timeout y los cerrojos de tabla son de PostgreSQL.');
        $antes = $this->huella();
        $m = $this->migracion();

        // Otra transacción sostiene un cerrojo sobre voice_brakes (lo toma antes de que esta toque la tabla).
        config(['database.connections.'.self::OTRA => config('database.connections.'.DB::getDefaultConnection())]);
        $otra = DB::connection(self::OTRA);
        $otra->beginTransaction();
        $otra->statement('LOCK TABLE voice_brakes IN ACCESS SHARE MODE NOWAIT');
        try {
            DB::statement("SET LOCAL lock_timeout = '200ms'");
            $t0 = microtime(true);
            try {
                $m->down();
                $this->fail('down() esperó el cerrojo o lo atravesó');
            } catch (QueryException $e) {
                $this->assertSame('55P03', (string) $e->getCode(), $e->getMessage());
            }
            $this->assertLessThan(5.0, microtime(true) - $t0, 'cae con el lock_timeout de la sesión, no espera sin límite');
            $this->assertSame($antes, $this->huella(), 'no tocó nada');
        } finally {
            $otra->rollBack();
        }

        // Sin límite en la sesión, la migración pone el suyo (5 s) para lo que queda de la transacción.
        DB::beginTransaction();
        try {
            $m->down();
            DB::statement('SET LOCAL lock_timeout = 0');
            $m->up();
            $this->assertSame('5s', (string) DB::scalar("SELECT current_setting('lock_timeout')"));
            $this->assertSame($antes, $this->huella());
        } finally {
            DB::rollBack();
        }
    }
}
