<?php

namespace Tests\Feature\Outreach\Schema;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * LOS ÍNDICES DE LECTURA DEL HITO 9 (DB9; migración 2026_11_12_100003, L9-29 y
 * L9-31): existen con sus columnas, son válidos, no son únicos ni parciales,
 * van fuera de transacción y en PG con CONCURRENTLY, y no tocan `voice_*`
 * (protección 9). La ida y vuelta en PG (`CONCURRENTLY` no cabe en la
 * transacción de `RefreshDatabase`) y la reconstrucción de un índice inválido
 * se ensayan con `artisan migrate` (informe, §7).
 */
class OutreachReadIndexesTest extends TestCase
{
    use OutreachSchemaFixtures;
    use RefreshDatabase;

    private const MIGRATION = '2026_11_12_100003_add_outreach_read_indexes';

    /** nombre → [tabla, columnas] (escrito aquí desde L9-31, no tomado de la migración). */
    private const INDEXES = [
        'users_membership_end_date_h9_idx' => ['users', ['membership_end_date']],
        'payment_transactions_user_status_h9_idx' => ['payment_transactions', ['user_id', 'status']],
        'class_reservations_member_date_h9_idx' => ['class_reservations', ['member_id', 'session_date']],
        'receivables_status_due_at_h9_idx' => ['receivables', ['status', 'due_at']],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_los_cuatro_indices_existen_con_sus_columnas_validos_y_sin_ser_unicos_ni_parciales(): void
    {
        foreach (self::INDEXES as $nombre => [$tabla, $columnas]) {
            if ($this->isPostgres()) {
                $this->assertSame("CREATE INDEX {$nombre} ON public.{$tabla} USING btree (".implode(', ', $columnas).')',
                    DB::scalar('SELECT indexdef FROM pg_indexes WHERE schemaname = current_schema() AND indexname = ?', [$nombre]), $nombre);
                $i = DB::selectOne('SELECT i.indisvalid::int AS valido, i.indisready::int AS listo, i.indisunique::int AS unico, (i.indpred IS NOT NULL)::int AS parcial
                    FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid WHERE c.relname = ?', [$nombre]);
                $this->assertSame([1, 1, 0, 0], [(int) $i->valido, (int) $i->listo, (int) $i->unico, (int) $i->parcial], $nombre);

                continue;
            }
            $this->assertSame("CREATE INDEX {$nombre} ON {$tabla} (".implode(', ', $columnas).')',
                DB::table('sqlite_master')->where('type', 'index')->where('name', $nombre)->value('sql'), $nombre);
        }
    }

    /** Protección 9: las tablas `voice_*` no cambian el 15-11 (L9-31 pedía un índice en `voice_contacts`: Q-8 para el lead). */
    public function test_no_toca_las_tablas_de_la_voz(): void
    {
        $codigo = '';
        foreach (token_get_all((string) file_get_contents(database_path('migrations/'.self::MIGRATION.'.php'))) as $t) {
            if (! is_array($t) || ! in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $codigo .= is_array($t) ? $t[1] : $t;
            }
        }
        $this->assertStringNotContainsString('voice_', $codigo);
        $indices = $this->isPostgres()
            ? array_map(static fn (object $f): string => $f->indexname, DB::select("SELECT indexname FROM pg_indexes WHERE tablename = 'voice_contacts' ORDER BY 1"))
            : DB::table('sqlite_master')->where('type', 'index')->where('tbl_name', 'voice_contacts')->orderBy('name')->pluck('name')->all();
        $esperados = ['vct_one_live', 'vct_status_idx', 'voice_contacts_client_request_id_unique', 'voice_contacts_uuid_unique', ...($this->isPostgres() ? ['voice_contacts_pkey'] : [])];
        sort($esperados, SORT_STRING);
        sort($indices, SORT_STRING);
        $this->assertSame($esperados, $indices);
    }

    public function test_va_fuera_de_transaccion_y_en_postgres_concurrente_e_idempotente(): void
    {
        $m = $this->migrationNamed(self::MIGRATION);
        $this->assertFalse($m->withinTransaction, 'CREATE INDEX CONCURRENTLY no cabe en una transacción');
        $codigo = (string) file_get_contents(database_path('migrations/'.self::MIGRATION.'.php'));
        foreach (['CREATE INDEX CONCURRENTLY IF NOT EXISTS', 'DROP INDEX CONCURRENTLY IF EXISTS', 'indisvalid'] as $fragmento) {
            $this->assertStringContainsString($fragmento, $codigo, $fragmento);
        }
        $this->assertSame(self::INDEXES, $m::INDEXES);
    }

    /**
     * Con los cuatro ya válidos, up() no crea ni borra nada (lo que hace el reintento tras un fallo a medias) y, sin
     * `lock_timeout` en la sesión, pone 5 s mientras dura y deja el valor que había; con uno puesto, lo respeta. (Fuera
     * de transacción no vale SET LOCAL: es un SET de sesión que se restaura en `finally`.)
     */
    public function test_solo_en_postgres_up_con_los_cuatro_validos_no_toca_nada_y_acota_la_espera_mientras_dura(): void
    {
        if (! $this->isPostgres()) {
            $this->markTestSkipped('lock_timeout y CONCURRENTLY solo existen en PostgreSQL.');
        }
        $m = $this->migrationNamed(self::MIGRATION);
        $sentencias = [];
        DB::listen(function ($q) use (&$sentencias): void {
            $sentencias[] = $q->sql;
        });
        foreach (['0' => ["SET lock_timeout = '5s'", "SET lock_timeout = '0'"], '2s' => []] as $previo => $esperadas) {
            DB::statement("SET LOCAL lock_timeout = '{$previo}'");
            $sentencias = [];
            $m->up();
            $this->assertSame($esperadas, array_values(array_filter($sentencias, static fn (string $s): bool => str_starts_with($s, 'SET '))), "con lock_timeout = {$previo}");
            $this->assertSame([], array_values(array_filter($sentencias, static fn (string $s): bool => (bool) preg_match('/\\b(CREATE|DROP) INDEX\\b/', $s))),
                'con los cuatro válidos, ni crea ni borra');
            $this->assertSame((string) $previo, DB::scalar("SELECT current_setting('lock_timeout')"), "después, con lock_timeout = {$previo}");
        }
    }

    public function test_solo_en_sqlite_down_quita_solo_los_suyos_y_up_los_repone(): void
    {
        if ($this->isPostgres()) {
            $this->markTestSkipped('En PG va con CONCURRENTLY, que no cabe en la transacción de la prueba: la ida y vuelta se ensaya con artisan (informe, §7).');
        }
        $tablas = array_values(array_unique(array_column(self::INDEXES, 0)));
        $foto = fn (): array => DB::table('sqlite_master')->whereIn('tbl_name', $tablas)->orderBy('type')->orderBy('name')->get(['type', 'name', 'sql'])
            ->map(static fn (object $o): string => "{$o->type} {$o->name}: {$o->sql}")->all();
        $antes = $foto();
        $m = $this->migrationNamed(self::MIGRATION);

        DB::beginTransaction();
        try {
            $m->down();
            $despues = $foto();
            $this->assertSame(array_values(array_filter($antes, static fn (string $l): bool => ! str_contains($l, '_h9_idx'))), $despues, 'down() tocó algo que no es suyo');
            $m->up();
            $m->up();   // idempotente
            $this->assertSame($antes, $foto());
        } finally {
            DB::rollBack();
        }
    }
}
