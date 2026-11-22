<?php

namespace Tests\Feature\Plans;

use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Plan;
use App\Support\Access\CrmPermission;
use App\Support\Access\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * PT-14 · A: «NOMBRE Y BENEFICIOS» (`plans.commercial_text`), el permiso de lo
 * que se dice fuera de un plan (diseño `plans/informes-cierre/diseno-arq-pt14.md`
 * §3 D1–D4, §4.2, §5 A y §7 A 1–11).
 *
 * Gobierna el CONJUNTO VENDIBLE (`Plan::sellable()`): cambiar el nombre o los
 * beneficios de un plan que se ofrece fuera, y meter un plan en la venta
 * (crearlo, encenderlo, darle precio o vigencia, reactivarlo). Se compara por
 * VALOR: reenviar lo mismo no lo pide. Quien lo escribe o lo pone a la venta
 * queda como autor (`commercial_text_by`), que no sale nunca en el listado
 * público. Por defecto solo lo tiene Super Admin.
 */
class PlanCommercialTextPermissionTest extends TestCase
{
    use RefreshDatabase;

    private const MSG_TEXT_CHANGED = 'Cambiar el nombre o los beneficios de un plan que se ofrece fuera (ULTRON, Sofía, enlaces de pago y la app) pide el permiso “Nombre y beneficios” de Planes. Guarda el resto sin tocarlos, o pide a quien lo tenga que los cambie.';

    private const MSG_BECOMES_SELLABLE = 'Poner a la venta un plan (crearlo o activarlo con “Se ofrece fuera: Sí”, darle precio o vigencia, o reactivarlo) pide el permiso “Nombre y beneficios” de Planes: así ningún texto llega a la venta sin que lo decida quien tiene ese permiso. Puedes guardarlo con “Se ofrece fuera: No”.';

    private const BENEFICIOS = ['Acceso ilimitado al gimnasio', 'Zona de pesas'];

    private Admin $super;

    private Admin $administrador;

    /** @var array<string, string> */
    private array $hSuper;

    /** @var array<string, string> */
    private array $hAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->super = Admin::create(['name' => 'Sara Super', 'email' => 'sara-'.uniqid().'@ironbody.test',
            'password' => 'secret-password', 'role' => Admin::ROLE_SUPER_ADMIN, 'status' => 'active']);
        $this->administrador = Admin::create(['name' => 'Ana Administra', 'email' => 'ana-'.uniqid().'@ironbody.test',
            'password' => 'secret-password', 'role' => Admin::ROLE_ADMINISTRADOR, 'status' => 'active']);
        $this->hSuper = $this->actingAsAdmin($this->super);
        $this->hAdmin = $this->actingAsAdmin($this->administrador);
    }

    // ── 1–3: el texto de un plan vendible ──────────────────────────────────

    public function test_el_administrador_no_cambia_el_nombre_de_un_plan_vendible(): void
    {
        $plan = $this->plan();

        $this->putJson("/api/plans/{$plan->id}", ['name' => 'Mensual Plus', 'price' => 89000, 'benefits' => json_encode(self::BENEFICIOS)], $this->hAdmin)
            ->assertStatus(403)
            ->assertExactJson(['ok' => false, 'code' => 'plan_commercial_text_forbidden', 'reasons' => ['text_changed'], 'message' => self::MSG_TEXT_CHANGED]);

        $this->assertSame('Mensual', $plan->fresh()->name, 'el nombre sigue igual en la base');
        $this->assertSame(0, AuditLog::query()->where('entity', 'plan')->where('action', 'update')->count(), 'sin línea de auditoría');
    }

    public function test_el_administrador_no_cambia_los_beneficios_ni_como_texto_ni_como_lista(): void
    {
        $plan = $this->plan();
        $antes = $plan->fresh()->benefits;

        // Como lo manda el CRM (texto) y como una lista.
        foreach (['Acceso ilimitado al gimnasio, Zona de pesas, Sauna', ['Acceso ilimitado al gimnasio', 'Sauna']] as $beneficios) {
            $this->patchJson("/api/plans/{$plan->id}", ['benefits' => $beneficios], $this->hAdmin)
                ->assertStatus(403)
                ->assertJsonPath('code', 'plan_commercial_text_forbidden')
                ->assertJsonPath('reasons', ['text_changed']);
        }

        $this->assertSame($antes, $plan->fresh()->benefits);
        $this->assertSame(0, AuditLog::query()->where('entity', 'plan')->where('action', 'update')->count());
    }

    /** D3: no es roja hoy; es la de no regresión (el CRM manda `name` y `benefits` en cada guardado). */
    public function test_reenviar_el_mismo_texto_no_pide_el_permiso(): void
    {
        // El legado H4-14: la lista guardada como JSON pegado como texto. El CRM la reenvía tal cual la lee.
        $legado = (string) json_encode(['["Acceso ilimitado al gimnasio"', '"Clases grupales"', '"Toalla"]']);
        $uno = $this->plan(['name' => 'Mensual', 'benefits' => $legado]);
        // La cadena con comas: el CRM la reenvía con otros espacios.
        $dos = $this->plan(['name' => 'Trimestral', 'price' => 239900, 'duration_days' => 90, 'benefits' => 'Acceso ilimitado al gimnasio, Zona de pesas']);

        $this->putJson("/api/plans/{$uno->id}", ['name' => 'Mensual   ', 'benefits' => $legado.'  ', 'price' => 95000, 'duration_days' => 30, 'active' => true], $this->hAdmin)
            ->assertOk();
        $this->putJson("/api/plans/{$dos->id}", ['name' => '  Trimestral', 'benefits' => "Acceso ilimitado al gimnasio,Zona de pesas\n", 'price' => 249900], $this->hAdmin)
            ->assertOk();

        $this->assertSame(95000.0, $uno->fresh()->price);
        $this->assertSame(249900.0, $dos->fresh()->price);
        $this->assertNull($uno->fresh()->commercial_text_by, 'el texto no cambió: el autor no se mueve');
        $traza = AuditLog::query()->where('entity', 'plan')->where('action', 'update')->where('entity_id', (string) $uno->id)->sole();
        $this->assertContains(['field' => 'price', 'from' => '89000', 'to' => '95000'], $traza->changes, 'el precio, con su antes y su después');
    }

    // ── 4–6: entrar en la venta ────────────────────────────────────────────

    public function test_no_se_pone_a_la_venta_en_dos_pasos(): void
    {
        $fuera = $this->plan(['sellable' => false]);

        // Paso 1: con el plan fuera de la venta, el texto sí (queda como autor).
        $this->patchJson("/api/plans/{$fuera->id}", ['name' => 'Mensual Nuevo'], $this->hAdmin)->assertOk();
        $this->assertSame('Mensual Nuevo', $fuera->fresh()->name);
        $this->assertSame((int) $this->administrador->id, $fuera->fresh()->commercial_text_by);

        // Paso 2: encenderlo, no. Ni por ninguna de las otras puertas de la venta.
        foreach ([
            [['sellable' => false], ['sellable' => true]],
            [['active' => false], ['active' => true]],
            [['price' => 0], ['price' => 89000]],
            [['duration_days' => 0], ['duration_days' => 30]],
        ] as [$estado, $cambio]) {
            $plan = $this->plan($estado);
            $this->assertFalse($plan->isSellable());
            $this->patchJson("/api/plans/{$plan->id}", $cambio, $this->hAdmin)
                ->assertStatus(403)
                ->assertExactJson(['ok' => false, 'code' => 'plan_commercial_text_forbidden', 'reasons' => ['becomes_sellable'], 'message' => self::MSG_BECOMES_SELLABLE]);
            $this->assertFalse($plan->fresh()->isSellable(), 'sigue fuera de la venta: '.json_encode($cambio));
        }

        // Apagar va en la dirección segura y no lo pide.
        $vendible = $this->plan(['name' => 'Anual']);
        $this->patchJson("/api/plans/{$vendible->id}", ['sellable' => false], $this->hAdmin)->assertOk();
        $this->assertFalse($vendible->fresh()->isSellable());
    }

    public function test_crear_o_duplicar_un_plan_vendible_pide_el_permiso_y_uno_no_vendible_no(): void
    {
        $alta = ['name' => 'Semestral', 'price' => 420000, 'duration_days' => 180, 'active' => true, 'benefits' => 'Acceso ilimitado al gimnasio'];

        // Crear vendible, y duplicar como lo hace el CRM: sin `sellable`, que nace en `true` en la base.
        foreach ([$alta + ['sellable' => true], $alta] as $cuerpo) {
            $this->postJson('/api/plans', $cuerpo, $this->hAdmin)
                ->assertStatus(403)
                ->assertJsonPath('code', 'plan_commercial_text_forbidden')
                ->assertJsonPath('reasons', ['becomes_sellable'])
                ->assertJsonPath('message', self::MSG_BECOMES_SELLABLE);
        }
        $this->assertSame(0, Plan::query()->count(), 'no se creó nada');
        $this->assertSame(0, AuditLog::query()->where('entity', 'plan')->count());

        // Fuera de la venta, sí: y queda como autor.
        foreach ([['sellable' => false], ['active' => false], ['price' => 0]] as $i => $fuera) {
            $id = $this->postJson('/api/plans', array_merge($alta, ['name' => "Semestral {$i}"], $fuera), $this->hAdmin)->assertCreated()->json('id');
            $plan = Plan::query()->findOrFail($id);
            $this->assertFalse($plan->isSellable());
            $this->assertSame((int) $this->administrador->id, $plan->commercial_text_by);
        }
    }

    public function test_reactivar_pide_tambien_el_permiso(): void
    {
        $plan = $this->plan(['active' => false, 'sellable' => false]);
        $plan->forceFill(['archived_at' => now(), 'archived_by' => $this->super->id, 'archive_reason' => 'Se reemplaza'])->save();
        $this->conceder('plans.archive');

        $this->postJson("/api/plans/{$plan->id}/reactivate", ['reason' => 'Vuelve a la venta'], $this->hAdmin)
            ->assertStatus(403)
            ->assertJsonPath('code', 'plan_commercial_text_forbidden')
            ->assertJsonPath('reasons', ['becomes_sellable']);
        $this->assertTrue($plan->fresh()->isArchived(), 'sigue archivado');

        $this->conceder('plans.commercial_text');
        $this->postJson("/api/plans/{$plan->id}/reactivate", ['reason' => 'Vuelve a la venta'], $this->hAdmin)
            ->assertOk()->assertJsonPath('plan.is_archived', false);
        $this->assertTrue($plan->fresh()->isSellable());
        $this->assertSame((int) $this->administrador->id, $plan->fresh()->commercial_text_by);
    }

    // ── 7–8: el autor y lo que ve cada uno ─────────────────────────────────

    public function test_el_super_admin_puede_y_queda_como_autor_sin_salir_en_el_listado_publico(): void
    {
        $plan = $this->plan();

        $r = $this->putJson("/api/plans/{$plan->id}", ['name' => 'Mensual Plus', 'benefits' => 'Acceso ilimitado al gimnasio, Sauna'], $this->hSuper)
            ->assertOk()->assertJsonPath('name', 'Mensual Plus')->assertJsonPath('commercial_text_locked', false);
        $this->assertArrayNotHasKey('commercial_text_by', $r->json());
        $this->assertSame((int) $this->super->id, $plan->fresh()->commercial_text_by);
        // La auditoría, la de siempre: el texto que cambió, y el autor no entra en `changes` (la línea ya lleva su actor).
        $traza = AuditLog::query()->where('entity', 'plan')->where('action', 'update')->sole();
        $this->assertSame(['name', 'benefits'], array_column($traza->changes, 'field'));
        $this->assertSame((string) $this->super->id, $traza->actor_id);

        $publico = $this->getJson('/api/plans')->assertOk()->json('data.0');
        $this->assertArrayNotHasKey('commercial_text_by', $publico, 'el listado es público: el id no viaja');
        $this->assertArrayHasKey('commercial_text_locked', $publico);
        $this->assertNull($publico['commercial_text_locked'], 'sin sesión de administrador, null');
        $this->assertArrayNotHasKey('commercial_text_by', $this->getJson("/api/plans/{$plan->id}")->assertOk()->json());
    }

    public function test_commercial_text_locked_dice_lo_que_ve_cada_rol(): void
    {
        $vendible = $this->plan();
        $fuera = $this->plan(['name' => 'Interno', 'sellable' => false]);

        $bloqueo = fn (array $h): array => collect($this->getJson('/api/plans', $h)->assertOk()->json('data'))->pluck('commercial_text_locked', 'id')->all();

        $this->assertSame([$vendible->id => true, $fuera->id => false], $bloqueo($this->hAdmin), 'Administrador');
        $this->assertSame([$vendible->id => false, $fuera->id => false], $bloqueo($this->hSuper), 'Super Admin');
        $this->assertSame([$vendible->id => null, $fuera->id => null], $bloqueo([]), 'sin sesión');
        $this->assertSame([$vendible->id => null, $fuera->id => null], $bloqueo($this->sharedTokenHeaders()), 'el token compartido no es nadie');
        $this->getJson("/api/plans/{$vendible->id}", $this->hAdmin)->assertOk()->assertJsonPath('commercial_text_locked', true);
        $this->getJson("/api/plans/{$vendible->id}")->assertOk()->assertJsonPath('commercial_text_locked', null);
    }

    // ── 9–10: lo que no lo pide y el catálogo ──────────────────────────────

    public function test_funciones_ia_archivar_y_borrar_no_piden_el_permiso(): void
    {
        $plan = $this->plan();

        $this->putJson("/api/plans/{$plan->id}/features", ['features' => ['iron_ia' => true, 'classes' => true]], $this->hAdmin)->assertOk();
        $this->putJson("/api/plans/{$plan->id}/ai-capabilities", ['ai_image_analysis_enabled' => true], $this->hAdmin)->assertOk();
        $this->postJson("/api/plans/{$plan->id}/archive", ['reason' => 'Se reemplaza por el anual'], $this->hSuper)->assertOk();
        $this->assertTrue($plan->fresh()->isArchived());

        $sinHistoria = $this->plan(['name' => 'Borrador']);
        $this->deleteJson("/api/plans/{$sinHistoria->id}", [], $this->hAdmin)->assertNoContent();
        $this->assertNull(Plan::query()->find($sinHistoria->id));
    }

    public function test_el_permiso_existe_en_el_catalogo_y_por_defecto_solo_lo_tiene_super_admin(): void
    {
        $this->assertContains('plans.commercial_text', PermissionCatalog::all());
        $fila = collect(PermissionCatalog::rows())->firstWhere('key', 'plans.commercial_text');
        $this->assertSame('plans', $fila['domain']);
        $this->assertSame('Planes', $fila['domain_label']);
        $this->assertSame('Nombre y beneficios', $fila['label']);
        $this->assertSame('Cambiar el nombre o los beneficios de un plan que se ofrece fuera (ULTRON, Sofía, enlaces de pago y la app) y ponerlo a la venta. Quien los escribe no puede publicar después la voz que los recoge. No llega por defecto a ningún rol.', $fila['help']);

        $this->assertContains('plans.commercial_text', CrmPermission::defaultsFor(Admin::ROLE_SUPER_ADMIN));
        foreach ([Admin::ROLE_ADMINISTRADOR, Admin::ROLE_ADMINISTRATIVO, Admin::ROLE_RECEPCION, CrmPermission::ROLE_ENTRENADOR] as $rol) {
            $this->assertNotContains('plans.commercial_text', CrmPermission::defaultsFor($rol), $rol);
        }
        $this->assertNotContains('plans.commercial_text', CrmPermission::readOnly(), 'el token compartido tampoco');
        $this->assertContains('plans.manage', CrmPermission::defaultsFor(Admin::ROLE_ADMINISTRADOR), 'el Administrador sigue gestionando planes');
    }

    // ── 11: la migración ───────────────────────────────────────────────────

    public function test_la_migracion_es_aditiva_reversible_y_la_llave_se_anula_al_borrar_la_cuenta(): void
    {
        $migracion = require database_path('migrations/2026_11_23_100001_add_commercial_text_author_to_plans_table.php');
        $driver = DB::connection()->getDriverName();
        $indices = $this->planIndexes($driver);
        $plan = $this->plan();

        $migracion->down();
        $this->assertFalse(Schema::hasColumn('plans', 'commercial_text_by'), 'down() la quita');
        $migracion->up();
        $this->assertTrue(Schema::hasColumn('plans', 'commercial_text_by'), 'up() la vuelve a poner');
        $this->assertSame($indices, $this->planIndexes($driver), 'los índices de plans siguen siendo los mismos (SQLite reconstruye la tabla)');
        $this->assertSame('Mensual', Plan::query()->findOrFail($plan->id)->name, 'las filas siguen');
        $this->assertNull(Plan::query()->findOrFail($plan->id)->commercial_text_by, 'sin relleno: los textos anteriores, sin autor');

        // La llave: nula si la cuenta se elimina (nullOnDelete, como archived_by).
        $cuenta = (int) Admin::create(['name' => 'Cuenta que se va', 'email' => 'va-'.uniqid().'@ironbody.test',
            'password' => 'secret-password', 'role' => Admin::ROLE_SUPER_ADMIN, 'status' => 'active'])->id;
        DB::table('plans')->where('id', $plan->id)->update(['commercial_text_by' => $cuenta]);
        DB::table('admins')->where('id', $cuenta)->delete();
        $this->assertNull(DB::table('plans')->where('id', $plan->id)->value('commercial_text_by'));
        $this->assertSame(1, DB::table('plans')->where('id', $plan->id)->count(), 'el plan se queda');

        if ($driver === 'pgsql') {
            // SET LOCAL lock_timeout (como E19-3): la sesión de la suite no trae límite y la migración lo pone.
            $this->assertSame('5s', (string) DB::scalar("SELECT current_setting('lock_timeout')"));
            // La aplicación es un rol con solo DML a nivel de tabla: la columna nueva lo hereda.
            DB::statement("DO \$\$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'iron_app') THEN CREATE ROLE iron_app NOLOGIN NOSUPERUSER NOINHERIT; END IF; END \$\$");
            DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON public.plans TO iron_app');
            foreach (['SELECT', 'INSERT', 'UPDATE'] as $privilegio) {
                $this->assertTrue((bool) DB::scalar("SELECT has_column_privilege('iron_app', 'public.plans', 'commercial_text_by', ?)", [$privilegio]), "iron_app: {$privilegio}");
            }
        }
    }

    // ────────────────────────────────────────────────────────────────────────

    /** Un plan vendible por defecto, como lo deja el CRM; sin autor (texto anterior a PT-14). */
    private function plan(array $o = []): Plan
    {
        return Plan::query()->create(array_merge(['name' => 'Mensual', 'price' => 89000, 'duration_days' => 30, 'active' => true,
            'sellable' => true, 'benefits' => json_encode(self::BENEFICIOS)], $o));
    }

    /** Concede una llave al Administrador como lo haría el propietario: desde la matriz de Usuarios y roles. */
    private function conceder(string $permiso): void
    {
        $this->putJson('/api/admin/roles/permissions', ['role' => Admin::ROLE_ADMINISTRADOR, 'permission' => $permiso, 'granted' => true], $this->hSuper)->assertOk();
    }

    /** @return list<string> la definición de cada índice de `plans` */
    private function planIndexes(string $driver): array
    {
        $filas = $driver === 'pgsql'
            ? DB::table('pg_indexes')->where('schemaname', 'public')->where('tablename', 'plans')->orderBy('indexname')->pluck('indexdef')->all()
            : DB::table('sqlite_master')->where('type', 'index')->where('tbl_name', 'plans')->whereNotNull('sql')->orderBy('name')->pluck('sql')->all();

        return array_map('strval', $filas);
    }
}
