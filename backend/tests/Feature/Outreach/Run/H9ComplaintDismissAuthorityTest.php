<?php

namespace Tests\Feature\Outreach\Run;

use App\Models\Admin;
use App\Models\AdminRole;
use App\Models\MarketingAiAction;
use App\Models\RolePermission;
use App\Services\Outreach\Facts\CoreProjection;
use App\Services\Outreach\Run\LadderInputBuilder;
use App\Services\Outreach\Run\OutreachAccess;
use App\Support\Access\CrmPermission;
use App\Support\Access\PermissionCatalog;
use App\Support\Access\RolePermissionPolicy;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Outreach\Run\Support\RunWorld;
use Tests\TestCase;

/**
 * Q1-8 · QUIÉN DESCARTA UNA QUEJA DE LA IA (B1 de `informe-apisec-quejas.md`):
 * «No era una queja» (`complaint_category = not_a_complaint` en la ruta de
 * resolver del Inbox) SUELTA un freno, así que exige su propia llave,
 * `commercial_automation.complaints_dismiss` (solo Super Admin por defecto,
 * como `release`), ANTES de leer la conversación y de abrir la transacción:
 * sin ella, 403 `forbidden` + `required_permission`, ningún
 * `complaint_dismissed`, la revisión sigue pendiente y la queja sigue
 * frenando. Confirmarla, «otra» y resolver sin categoría siguen EXACTAMENTE
 * como antes para todos: esa ruta la usa el personal a diario.
 *
 * Sobre las clases REALES: la puerta (`AuthorizationMap`, `marketing.manage`),
 * la capacidad del Inbox por rol, `CrmPermission` con la política persistida,
 * `MarketingStaffReviewService`, el observador de la IA y la foto del H9.
 *
 * El martes 17-11-2026 a las 10:00 de Bogotá (15:00Z).
 */
class H9ComplaintDismissAuthorityTest extends TestCase
{
    use RefreshDatabase;
    use RunWorld;

    private const NOW = '2026-11-17 15:00:00';

    private const DISMISS = 'commercial_automation.complaints_dismiss';

    /** @var list<string> */
    private array $escrituras = [];

    private bool $escuchando = false;

    private int $telefono = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Carbon::setTestNow(Carbon::parse(self::NOW, 'UTC'));
        config(['commercial.events_enabled' => true, 'commercial.enabled' => false]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Una cuenta activa con ese rol y, ENCIMA de sus llaves por defecto, las que se le den desde Configuración →
     * Roles (`role_permissions`, la política real). Un rol que no es del sistema se crea como rol propio.
     *
     * @param  list<string>  $llaves
     * @return array{0: Admin, 1: array<string, string>}
     */
    private function cuenta(string $rol, array $llaves = []): array
    {
        if (! in_array($rol, [...Admin::ROLES, CrmPermission::ROLE_ENTRENADOR], true)) {
            AdminRole::firstOrCreate(['name' => $rol], ['is_system' => false]);
        }
        foreach ($llaves as $llave) {
            RolePermission::query()->updateOrCreate(['role' => $rol, 'permission' => $llave], ['granted' => true]);
        }
        Cache::forget('crm.role_permissions.v1');
        app(RolePermissionPolicy::class)->flush();
        $admin = Admin::create(['name' => $rol, 'email' => 'b1-'.uniqid().'@q18.test', 'password' => 'secret-password', 'role' => $rol, 'status' => 'active']);

        return [$admin, $this->actingAsAdmin($admin)];
    }

    /** Un socio con su prospecto, su conversación de WhatsApp y una queja que la IA marcó en ella (por el observador REAL). @return array{key: string, conversation: int} */
    private function quejaPendiente(): array
    {
        $socio = $this->qualifyingMember(self::utcAt(self::NOW), 5, '30012345'.str_pad((string) $this->telefono++, 2, '0', STR_PAD_LEFT));
        $c = (int) DB::table('marketing_conversations')->where('lead_id', $socio['lead'])->orderBy('id')->value('id');
        MarketingAiAction::create([
            'lead_id' => $socio['lead'], 'conversation_id' => $c,
            'action_type' => 'staff_review', 'reason' => 'complaint', 'status' => 'created',
            'metadata' => ['needs_staff_review' => true, 'staff_review_reason' => 'complaint', 'intent' => 'complaint'],
        ]);
        DB::table('marketing_conversations')->where('id', $c)->update(['staff_review_pending' => true, 'staff_review_reason' => 'complaint']);
        $this->assertTrue($this->frena($socio['key']), 'la queja de la IA frena antes de resolver');

        return ['key' => $socio['key'], 'conversation' => $c];
    }

    /** ¿Frena hoy la queja a esta persona en lo comercial? Por la foto REAL (BatchSnapshotBuilder → EngineFacts). */
    private function frena(string $clave): ?bool
    {
        $eventos = CoreProjection::snapshot($this->snap([$clave], self::NOW, CoreProjection::selectionFor(['events' => ['complaint_created']]))->subject($clave))->events;

        return LadderInputBuilder::complaintRecently($eventos, self::utcAt(self::NOW), 'commercial');
    }

    /**
     * La petición y lo que ESCRIBIÓ (cada INSERT, UPDATE o DELETE: «verbo tabla [columnas]»), sin las tablas de la
     * sesión, que cualquier petición autenticada toca.
     *
     * @param  array<string, string>  $sesion
     * @param  array<string, mixed>  $cuerpo
     * @return array{0: int, 1: mixed, 2: list<string>}
     */
    private function resolver(int $conversacion, array $sesion, array $cuerpo): array
    {
        if (! $this->escuchando) {
            $this->escuchando = true;
            DB::listen(function (QueryExecuted $q): void {
                if (preg_match('/^\s*(insert into|update|delete from)\s+"?(\w+)"?(.*)$/is', $q->sql, $m) === 1
                    && ! in_array($m[2], ['admin_sessions', 'admin_session_events', 'personal_access_tokens'], true)) {
                    $columnas = [];
                    if (strtolower($m[1]) === 'update') {
                        preg_match_all('/"(\w+)"\s*=\s*\?/', (string) preg_split('/\swhere\s/i', $m[3])[0], $set);
                        $columnas = $set[1];
                    } elseif (preg_match('/^\s*\(([^)]*)\)/', $m[3], $cols) === 1) {
                        $columnas = array_map(static fn (string $c): string => trim($c, ' "'), explode(',', $cols[1]));
                    }
                    sort($columnas);
                    $this->escrituras[] = strtok(strtolower($m[1]), ' ').' '.$m[2].' ['.implode(',', $columnas).']';
                }
            });
        }
        $antes = count($this->escrituras);
        $r = $this->postJson("/api/admin/marketing/inbox/conversations/{$conversacion}/staff-review/resolve", $cuerpo, $sesion);

        return [$r->status(), $r->json(), array_slice($this->escrituras, $antes)];
    }

    /** @return array{0: bool, 1: ?int, 2: int} pendiente, quién resolvió y cuántos descartes hay */
    private static function estado(int $conversacion): array
    {
        $f = DB::table('marketing_conversations')->where('id', $conversacion)->first();

        return [(bool) $f->staff_review_pending, $f->staff_review_resolved_by !== null ? (int) $f->staff_review_resolved_by : null,
            DB::table('commercial_events')->where('event', 'complaint_dismissed')->count()];
    }

    public function test_la_llave_esta_en_los_catalogos_y_solo_la_tiene_super_admin_por_defecto(): void
    {
        $this->assertSame(self::DISMISS, OutreachAccess::COMPLAINTS_DISMISS);
        $this->assertContains(self::DISMISS, OutreachAccess::PERMISSIONS);
        $this->assertContains(self::DISMISS, PermissionCatalog::all());
        $fila = array_column(PermissionCatalog::rows(), null, 'key')[self::DISMISS];
        $this->assertNotSame('', (string) $fila['label']);
        $this->assertStringContainsString('No llega por defecto al Administrador', (string) $fila['help']);
        $this->assertContains(self::DISMISS, CrmPermission::defaultsFor(Admin::ROLE_SUPER_ADMIN));
        foreach ([Admin::ROLE_ADMINISTRADOR, Admin::ROLE_RECEPCION, Admin::ROLE_ADMINISTRATIVO, CrmPermission::ROLE_ENTRENADOR] as $rol) {
            $this->assertNotContains(self::DISMISS, CrmPermission::defaultsFor($rol), "{$rol} no la recibe por defecto");
        }
        // La misma política que `release` (soltar frenos) y al revés que `stop` (echarlos).
        $this->assertNotContains(OutreachAccess::RELEASE, CrmPermission::defaultsFor(Admin::ROLE_ADMINISTRADOR));
        $this->assertNotContains(self::DISMISS, CrmPermission::readOnly(), 'el token compartido no la obtiene');
    }

    /**
     * Las pruebas negativas de B1 («Pruebas negativas para tester»): quien hoy opera el Inbox y podía descartar
     * (Administrador, Recepción con `marketing.manage`, un rol «Ventas» con `marketing.manage`) recibe 403 con la
     * llave que falta, sin escribir nada; la revisión sigue pendiente y la queja sigue frenando. Antes de B1, los
     * tres daban 200 `dismissed: 1`.
     */
    public function test_sin_la_llave_de_descartar_403_nada_escrito_la_revision_pendiente_y_la_queja_frenando(): void
    {
        $sinLlave = [
            'Administrador' => $this->cuenta(Admin::ROLE_ADMINISTRADOR),
            'Recepción + marketing.manage' => $this->cuenta(Admin::ROLE_RECEPCION, ['marketing.manage']),
            'Ventas + marketing.manage' => $this->cuenta('Ventas', ['marketing.manage']),
        ];
        foreach ($sinLlave as $quien => [, $sesion]) {
            $q = $this->quejaPendiente();
            [$status, $json, $escrito] = $this->resolver($q['conversation'], $sesion, ['complaint_category' => 'not_a_complaint', 'note' => 'no lo era']);
            $this->assertSame(403, $status, $quien);
            $this->assertSame(['ok' => false, 'code' => 'forbidden', 'message' => 'No tienes permiso para esta acción.', 'required_permission' => self::DISMISS], $json, $quien);
            $this->assertSame([], $escrito, "{$quien}: nada escrito (ni la revisión, ni la nota, ni un descarte)");
            $this->assertSame([true, null, 0], self::estado($q['conversation']), "{$quien}: la revisión sigue pendiente");
            $this->assertTrue($this->frena($q['key']), "{$quien}: la queja sigue frenando");
        }
        // Una conversación que no existe: el mismo 403 (la llave va antes de leer nada).
        [$status, $json] = $this->resolver(999999, $sinLlave['Administrador'][1], ['complaint_category' => 'not_a_complaint']);
        $this->assertSame([403, self::DISMISS], [$status, $json['required_permission'] ?? null]);
        $this->assertSame(0, DB::table('marketing_conversation_notes')->count());
    }

    public function test_con_la_llave_200_super_admin_por_defecto_y_otro_rol_si_se_la_dan(): void
    {
        $conLlave = [
            'Super Admin' => $this->cuenta(Admin::ROLE_SUPER_ADMIN),
            'Ventas + marketing.manage + complaints_dismiss' => $this->cuenta('Ventas', ['marketing.manage', self::DISMISS]),
        ];
        foreach ($conLlave as $quien => [$admin, $sesion]) {
            $q = $this->quejaPendiente();
            [$status, $json] = $this->resolver($q['conversation'], $sesion, ['complaint_category' => 'not_a_complaint']);
            $this->assertSame([200, ['ok' => true, 'staff_review_pending' => false, 'complaint' => ['category' => 'not_a_complaint', 'dismissed' => 1]]], [$status, $json], $quien);
            $this->assertSame([false, (int) $admin->id], array_slice(self::estado($q['conversation']), 0, 2), $quien);
            $this->assertFalse($this->frena($q['key']), "{$quien}: descartada, deja de frenar");
        }
        $this->assertSame(2, DB::table('commercial_events')->where('event', 'complaint_dismissed')->count());
    }

    /**
     * Lo que el personal hace a diario NO cambia: sin categoría, `complaint_confirmed` y `other`, para cada rol que
     * opera el Inbox, la MISMA respuesta y las MISMAS escrituras (verbo, tabla y columnas) que antes de B1, con nota
     * y sin ella. Esta prueba pasa igual con el controlador de antes (`dc01397a`): lo comprobé al cerrar B1.
     */
    public function test_sin_categoria_confirmada_u_otra_exactamente_como_antes_para_todos(): void
    {
        $roles = [
            'Super Admin' => $this->cuenta(Admin::ROLE_SUPER_ADMIN),
            'Administrador' => $this->cuenta(Admin::ROLE_ADMINISTRADOR),
            'Recepción + marketing.manage' => $this->cuenta(Admin::ROLE_RECEPCION, ['marketing.manage']),
            'Ventas + marketing.manage' => $this->cuenta('Ventas', ['marketing.manage']),
        ];
        $resolverLaRevision = 'update marketing_conversations [staff_review_pending,staff_review_resolved_at,staff_review_resolved_by,updated_at]';
        $nota = 'insert marketing_conversation_notes [author_admin_id,body,conversation_id,created_at,updated_at]';
        $cuerpos = [
            'sin categoría' => [[], ['ok' => true, 'staff_review_pending' => false]],
            'sin categoría (null)' => [['complaint_category' => null], ['ok' => true, 'staff_review_pending' => false]],
            'complaint_confirmed' => [['complaint_category' => 'complaint_confirmed'], ['ok' => true, 'staff_review_pending' => false, 'complaint' => ['category' => 'complaint_confirmed', 'dismissed' => 0]]],
            'other' => [['complaint_category' => 'other'], ['ok' => true, 'staff_review_pending' => false, 'complaint' => ['category' => 'other', 'dismissed' => 0]]],
        ];
        foreach ($roles as $quien => [$admin, $sesion]) {
            foreach ($cuerpos as $caso => [$cuerpo, $respuesta]) {
                foreach ([false, true] as $conNota) {
                    $q = $this->quejaPendiente();
                    [$status, $json, $escrito] = $this->resolver($q['conversation'], $sesion, $conNota ? $cuerpo + ['note' => 'Lo vimos en recepción'] : $cuerpo);
                    $etiqueta = "{$quien} · {$caso}".($conNota ? ' · con nota' : '');
                    $this->assertSame([200, $respuesta], [$status, $json], $etiqueta);
                    $this->assertSame($conNota ? [$resolverLaRevision, $nota] : [$resolverLaRevision], $escrito, $etiqueta);
                    $this->assertSame([false, (int) $admin->id, 0], self::estado($q['conversation']), $etiqueta);
                    $this->assertTrue($this->frena($q['key']), "{$etiqueta}: la queja sigue frenando");
                }
            }
        }
    }

    public function test_el_token_compartido_y_una_cuenta_inactiva_no_descartan_ni_escriben(): void
    {
        $q = $this->quejaPendiente();
        [$status, , $escrito] = $this->resolver($q['conversation'], $this->sharedTokenHeaders(), ['complaint_category' => 'not_a_complaint']);
        $this->assertContains($status, [401, 403], 'el token compartido');
        $this->assertSame([], $escrito);

        // Una cuenta CON la llave que se desactiva con la sesión abierta.
        [$admin, $sesion] = $this->cuenta(Admin::ROLE_SUPER_ADMIN);
        $admin->forceFill(['status' => 'inactive'])->save();
        [$status, , $escrito] = $this->resolver($q['conversation'], $sesion, ['complaint_category' => 'not_a_complaint']);
        $this->assertContains($status, [401, 403], 'la cuenta inactiva');
        $this->assertSame([], $escrito);

        $this->assertSame([true, null, 0], self::estado($q['conversation']));
        $this->assertTrue($this->frena($q['key']));
    }
}
