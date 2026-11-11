<?php

namespace Tests\Feature\Outreach\W9;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Feature\Outreach\W9\Support\W9World;
use Tests\TestCase;

/**
 * W9 · `ultron_studio_template_meta_checks` (contrato H9, §6.1, protección
 * 12; las convenciones de DB9): solo inserción en los dos motores; la
 * comprobación es de una plantilla de WHATSAPP del Studio y de su huella
 * exacta; en PostgreSQL, los CHECK de dominio y de forma, la función propia
 * sin SECURITY DEFINER y la de F intacta; `down()` se niega con filas.
 */
class TemplateMetaChecksSchemaTest extends TestCase
{
    use RefreshDatabase;
    use W9World;

    private const TABLE = 'ultron_studio_template_meta_checks';

    private const MIGRATION = 'database/migrations/2026_11_13_200001_create_ultron_studio_template_meta_checks_table.php';

    public function test_la_tabla_existe_con_sus_columnas_y_sin_datos_de_personas(): void
    {
        $this->assertTrue(Schema::hasTable(self::TABLE));
        $columnas = Schema::getColumnListing(self::TABLE);
        sort($columnas);
        $this->assertSame([
            'category', 'checked_at', 'client_request_id', 'created_at', 'id', 'language', 'meta_body_hash', 'meta_components_hash', 'meta_name', 'meta_template_id',
            'recorded_by_admin_id', 'recorded_by_name', 'source', 'status', 'submission_id', 'template_content_hash', 'template_id', 'uuid',
        ], $columnas, 'del texto de Meta, solo su huella; de quien registra, la instantánea del personal (L9-96: + source, submission_id y meta_components_hash, 2026_11_15_100002)');
    }

    public function test_solo_insercion_y_de_una_plantilla_de_whatsapp_a_su_huella_exacta(): void
    {
        $plantilla = $this->whatsappTemplate();
        $id = $this->metaCheck($plantilla);
        $this->assertSame(1, DB::table(self::TABLE)->count());

        $this->assertRejected(fn () => DB::table(self::TABLE)->where('id', $id)->update(['status' => 'PAUSED']), 'solo inserción');
        $this->assertRejected(fn () => DB::table(self::TABLE)->where('id', $id)->delete(), 'solo inserción');
        $this->assertRejected(fn () => $this->metaCheck($plantilla, ['template_content_hash' => str_repeat('a', 64)]), 'de su huella exacta');
        $asistida = $this->approvedTemplate(['channel' => 'assisted']);
        $hashAsistida = (string) DB::table('ultron_studio_message_templates')->where('id', $asistida)->value('content_hash');
        $this->assertRejected(fn () => DB::table(self::TABLE)->insert($this->row($asistida, $hashAsistida)), 'de una plantilla de WhatsApp');
        $this->assertSame(1, DB::table(self::TABLE)->count());
    }

    public function test_en_postgresql_los_check_la_funcion_propia_y_la_de_f(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Los CHECK de dominio y de forma y las funciones son de PostgreSQL (la puerta PG de W9).');
        }
        $plantilla = $this->whatsappTemplate();
        $hash = (string) DB::table('ultron_studio_message_templates')->where('id', $plantilla)->value('content_hash');
        foreach ([
            // L9-96 (2026_11_15_100002): PENDING y los demás estados de Meta ya son del dominio; este no.
            'ustmc_status_chk' => ['status' => 'INVENTADO'],
            'ustmc_language_chk' => ['language' => 'en_US'],
            // Cabe en la columna (10) y no es del dominio: así salta el CHECK y no el tamaño (22001).
            'ustmc_category_chk' => ['category' => 'promo'],
            'ustmc_shape_chk' => ['meta_name' => 'Con Mayúsculas'],
        ] as $check => $cambio) {
            try {
                DB::transaction(fn () => DB::table(self::TABLE)->insert(array_merge($this->row($plantilla, $hash), $cambio)));
                $this->fail("{$check} no saltó");
            } catch (QueryException $e) {
                $this->assertSame('23514', $e->errorInfo[0] ?? null, $check);
                $this->assertStringContainsString($check, $e->getMessage());
            }
        }
        foreach ([['meta_body_hash' => 'no-hex'], ['meta_template_id' => 'abc'], ['recorded_by_name' => '  '], ['checked_at' => now()->addDay()]] as $cambio) {
            $this->assertRejected(fn () => DB::transaction(fn () => DB::table(self::TABLE)->insert(array_merge($this->row($plantilla, $hash), $cambio))), 'forma: '.json_encode(array_keys($cambio)));
        }
        // W9 4 + ustmc_submission_of_template (L9-96, 2026_11_15_100002).
        $this->assertSame(5, (int) DB::scalar('SELECT count(*) FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid WHERE NOT t.tgisinternal AND c.relname = ?', [self::TABLE]));
        $this->assertSame(0, (int) DB::scalar('SELECT count(*) FROM pg_proc WHERE proname = ? AND prosecdef', ['ultron_studio_chk_ustmc_template_of_whatsapp']), 'sin SECURITY DEFINER');
        $this->assertSame(1, (int) DB::scalar("SELECT count(*) FROM pg_proc WHERE proname = 'ultron_studio_forbid'"), 'la de F, una y sin redeclarar');
    }

    public function test_en_postgresql_el_rol_de_la_aplicacion_inserta_y_no_actualiza_ni_borra(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Los roles y los privilegios solo existen en PostgreSQL.');
        }
        $rol = 'ultron_studio_w9_app';
        DB::beginTransaction();
        try {
            DB::statement('DO $$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = \''.$rol.'\') THEN CREATE ROLE '.$rol.' NOLOGIN NOSUPERUSER NOINHERIT; END IF; END $$');
            DB::statement('GRANT USAGE ON SCHEMA public TO '.$rol);
            // La guarda entre tablas es INVOKER: el rol lee las plantillas como la aplicación de hoy.
            DB::statement('GRANT SELECT ON ultron_studio_message_templates TO '.$rol);
            DB::statement('ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO '.$rol);
            DB::statement('ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT USAGE, SELECT, UPDATE ON SEQUENCES TO '.$rol);
            putenv('ULTRON_STUDIO_DB_APP_ROLE='.$rol);
            $m = $this->migration();
            $m->down();
            $m->up();

            $p = DB::selectOne("SELECT has_table_privilege(?, ?, 'SELECT')::int AS s, has_table_privilege(?, ?, 'INSERT')::int AS i,
                    has_any_column_privilege(?, ?, 'UPDATE')::int AS u, has_table_privilege(?, ?, 'DELETE')::int AS d",
                array_merge(...array_fill(0, 4, [$rol, 'public.'.self::TABLE])));
            $this->assertSame([1, 1, 0, 0], [(int) $p->s, (int) $p->i, (int) $p->u, (int) $p->d], 'solo inserción para la aplicación');

            $plantilla = $this->whatsappTemplate();
            $hash = (string) DB::table('ultron_studio_message_templates')->where('id', $plantilla)->value('content_hash');
            $fila = $this->row($plantilla, $hash);
            $comoApp = function (\Closure $fn) use ($rol): ?string {
                try {
                    DB::transaction(function () use ($fn, $rol): void {
                        DB::statement('SET LOCAL ROLE '.$rol);
                        $fn();
                    });
                } catch (QueryException $e) {
                    return (string) ($e->errorInfo[0] ?? $e->getCode());
                }

                return null;
            };
            $this->assertNull($comoApp(fn () => DB::table(self::TABLE)->insert($fila)), 'la aplicación registra una comprobación');
            $this->assertSame('42501', $comoApp(fn () => DB::table(self::TABLE)->where('id', '>', 0)->update(['status' => 'PAUSED'])));
            $this->assertSame('42501', $comoApp(fn () => DB::table(self::TABLE)->where('id', '>', 0)->delete()));
        } finally {
            putenv('ULTRON_STUDIO_DB_APP_ROLE');
            DB::rollBack();
        }
    }

    public function test_down_se_niega_con_filas_y_sin_ellas_deja_el_esquema_como_estaba(): void
    {
        $migracion = $this->migration();
        $plantilla = $this->whatsappTemplate();
        $this->metaCheck($plantilla);
        try {
            DB::transaction(fn () => $migracion->down());
            $this->fail('down() revirtió con filas');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('se niega', $e->getMessage());
        }
        $this->assertTrue(Schema::hasTable(self::TABLE));
        $this->assertSame(1, DB::table(self::TABLE)->count());

        // Sin filas: la tabla se va y vuelve (ida y vuelta).
        $sinFilas = $this->migration();
        if (DB::connection()->getDriverName() === 'pgsql') {
            $this->markTestSkipped('En PG la fila no se puede borrar (solo inserción): la ida y vuelta sin filas se prueba en SQLite.');
        }
        DB::statement('DROP TRIGGER ustmc_forbid_delete');
        DB::table(self::TABLE)->delete();
        DB::transaction(fn () => $sinFilas->down());
        $this->assertFalse(Schema::hasTable(self::TABLE));
        DB::transaction(fn () => $sinFilas->up());
        $this->assertTrue(Schema::hasTable(self::TABLE));
        $this->assertSame(2, DB::table('sqlite_master')->where('type', 'trigger')->where('tbl_name', self::TABLE)->where('name', 'like', 'ustmc_forbid_%')->count());
    }

    /** @return array<string, mixed> */
    private function row(int $templateId, string $hash): array
    {
        return [
            'uuid' => (string) Str::uuid(), 'client_request_id' => (string) Str::uuid(), 'template_id' => $templateId, 'template_content_hash' => $hash,
            'meta_name' => 'renovacion_w9', 'language' => 'es', 'category' => 'marketing', 'meta_body_hash' => hash('sha256', 'x'), 'meta_template_id' => null,
            'status' => 'APPROVED', 'checked_at' => now()->subMinute(), 'recorded_by_admin_id' => 1, 'recorded_by_name' => 'Ana', 'created_at' => now(),
        ];
    }

    private function assertRejected(\Closure $sentencia, string $que): void
    {
        try {
            // En un SAVEPOINT: en PostgreSQL un error dentro de la transacción de la prueba la dejaría abortada.
            DB::transaction(fn () => $sentencia());
            $this->fail("Se aceptó lo que la base tenía que rechazar: {$que}");
        } catch (QueryException $e) {
            $this->assertMatchesRegularExpression('/ultron_studio:|23514|chk/', $e->getMessage(), $que);
        }
    }

    private function migration(): Migration
    {
        return require base_path(self::MIGRATION);
    }
}
