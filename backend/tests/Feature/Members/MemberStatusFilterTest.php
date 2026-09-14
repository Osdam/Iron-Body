<?php

namespace Tests\Feature\Members;

use App\Models\Admin;
use App\Models\User;
use App\Support\Members\MembershipFilter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Filtro de estado del listado de Miembros.
 *
 * Filtraba `where('status', ?)` contra la columna, exacto y sensible a
 * mayúsculas, y por eso fallaba justo en los dos casos que más se usan:
 *
 *  - «Vencidos» no devolvía casi nada, porque un socio caducado conserva
 *    `status = 'active'` y lo que cambió es `membership_end_date`.
 *  - «Inactivos» se saltaba las filas importadas del sistema anterior, que
 *    traen «inactivo» en español.
 *
 * Estas pruebas fijan el vocabulario de MembershipFilter, que comparten el
 * listado y el módulo de exportación.
 */
class MemberStatusFilterTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Admin
    {
        return Admin::create([
            'name' => 'Recepción',
            'email' => 'filtro-'.uniqid().'@ironbody.test',
            'password' => 'secret-password',
            'role' => Admin::ROLE_ADMINISTRADOR,
            'status' => 'active',
        ]);
    }

    private function socio(string $nombre, array $attrs = []): User
    {
        return User::create(array_merge([
            'name' => $nombre,
            'email' => strtolower($nombre).'-'.uniqid().'@example.com',
            'password' => 'secret',
            'status' => 'active',
        ], $attrs));
    }

    private function hoy(): CarbonImmutable
    {
        return CarbonImmutable::now(MembershipFilter::TZ)->startOfDay();
    }

    /** @return list<string> nombres devueltos por el listado */
    private function filtrar(string $estado): array
    {
        $res = $this->getJson('/api/users?status='.$estado.'&per_page=100', $this->actingAsAdmin($this->admin()))
            ->assertOk();

        return array_column($res->json('data'), 'name');
    }

    private function poblar(): void
    {
        $hoy = $this->hoy();

        $this->socio('Vigente', ['membership_end_date' => $hoy->addDays(60)->toDateString()]);
        // Cuenta activa pero membresía caducada: el caso que no salía.
        $this->socio('CaducadaPorFecha', ['membership_end_date' => $hoy->subDays(5)->toDateString()]);
        $this->socio('PorVencer', ['membership_end_date' => $hoy->addDays(3)->toDateString()]);
        // Grafía del sistema anterior.
        $this->socio('InactivoLegado', ['status' => 'inactivo']);
        $this->socio('InactivoIngles', ['status' => 'inactive']);
        $this->socio('Pendiente', ['status' => 'pending']);
        // Estado vacío: el resto del CRM lo cuenta como activo.
        $this->socio('SinEstado', ['status' => '', 'membership_end_date' => $hoy->addDays(20)->toDateString()]);
        $this->socio('SinMembresia', ['membership_end_date' => null]);
    }

    public function test_vencidos_encuentra_a_quien_se_le_paso_la_fecha(): void
    {
        $this->poblar();

        // Antes devolvía una lista vacía: nadie tiene status 'expired'.
        $this->assertSame(['CaducadaPorFecha'], $this->filtrar('expired'));
    }

    public function test_inactivos_encuentra_las_dos_grafias(): void
    {
        $this->poblar();

        $this->assertEqualsCanonicalizing(
            ['InactivoLegado', 'InactivoIngles'],
            $this->filtrar('inactive'),
            'las filas importadas traen «inactivo» en español',
        );
    }

    public function test_activos_excluye_a_los_vencidos_y_cuenta_el_estado_vacio(): void
    {
        $this->poblar();

        $activos = $this->filtrar('active');

        $this->assertContains('Vigente', $activos);
        $this->assertContains('PorVencer', $activos, 'por vencer todavía es activo');
        $this->assertContains('SinMembresia', $activos, 'sin fecha no está vencida');
        $this->assertContains('SinEstado', $activos, 'estado vacío cuenta como activo');
        $this->assertNotContains('CaducadaPorFecha', $activos, 'un vencido no es un activo');
        $this->assertNotContains('InactivoLegado', $activos);
    }

    public function test_pendientes_sigue_funcionando(): void
    {
        $this->poblar();

        $this->assertSame(['Pendiente'], $this->filtrar('pending'));
    }

    public function test_por_vencer_usa_la_ventana_pedida(): void
    {
        $hoy = $this->hoy();
        $this->socio('EnTresDias', ['membership_end_date' => $hoy->addDays(3)->toDateString()]);
        $this->socio('EnVeinteDias', ['membership_end_date' => $hoy->addDays(20)->toDateString()]);
        $this->socio('YaVencida', ['membership_end_date' => $hoy->subDay()->toDateString()]);

        $this->assertSame(['EnTresDias'], $this->filtrar('expiring_7'));
        $this->assertEqualsCanonicalizing(['EnTresDias', 'EnVeinteDias'], $this->filtrar('expiring_30'));
        // Lo ya vencido no es «por vencer»: son dos listas de trabajo distintas.
        $this->assertNotContains('YaVencida', $this->filtrar('expiring_30'));
    }

    public function test_vencidas_hace_poco_separa_la_recuperacion_del_historico(): void
    {
        $hoy = $this->hoy();
        $this->socio('VencioAyer', ['membership_end_date' => $hoy->subDay()->toDateString()]);
        $this->socio('VencioHaceUnAno', ['membership_end_date' => $hoy->subDays(365)->toDateString()]);

        $recientes = $this->filtrar('expired_recent');

        $this->assertSame(['VencioAyer'], $recientes);
        $this->assertEqualsCanonicalizing(['VencioAyer', 'VencioHaceUnAno'], $this->filtrar('expired'));
    }

    public function test_sin_filtro_salen_todos(): void
    {
        $this->poblar();

        $res = $this->getJson('/api/users?per_page=100', $this->actingAsAdmin($this->admin()))->assertOk();

        $this->assertCount(8, $res->json('data'));
    }

    public function test_un_valor_desconocido_no_devuelve_la_lista_entera(): void
    {
        $this->poblar();

        // Ignorar el filtro haría creer que no hay nadie en ese estado cuando
        // lo que pasa es que el filtro no se aplicó.
        $this->assertSame([], $this->filtrar('inventado'));
    }
}
