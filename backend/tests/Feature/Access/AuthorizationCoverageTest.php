<?php

namespace Tests\Feature\Access;

use App\Support\Access\AuthorizationMap;
use App\Support\Access\CrmPermission;
use App\Support\Access\PermissionCatalog;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * La red que hace segura toda la autorización del CRM.
 *
 * Existe porque el modelo anterior —anotar cada ruta con `admin.can`— dejó 43
 * rutas protegidas de 341, y nada avisaba. Aquí una ruta administrativa nueva
 * que nadie haya clasificado ROMPE CI, así que el hueco no puede llegar a
 * producción por descuido.
 *
 * Es el test más importante de este módulo: sin él, el mapa central se
 * degradaría igual que se degradaron las anotaciones.
 */
class AuthorizationCoverageTest extends TestCase
{
    /** @return list<\Illuminate\Routing\Route> */
    private function administrativeRoutes(): array
    {
        return collect(Route::getRoutes())
            ->filter(fn ($r) => AuthorizationMap::isAdministrative($r))
            ->values()
            ->all();
    }

    public function test_toda_ruta_administrativa_resuelve_a_un_permiso(): void
    {
        $sinClasificar = [];
        foreach ($this->administrativeRoutes() as $route) {
            if (AuthorizationMap::resolve($route) === null) {
                $sinClasificar[] = AuthorizationMap::routeKey($route);
            }
        }

        $this->assertSame([], $sinClasificar, implode("\n", array_merge(
            ['Rutas administrativas sin clasificar en AuthorizationMap.'],
            ['Añádelas a CONTROLLERS (si el dominio es claro) o a OVERRIDES:'],
            $sinClasificar,
        )));
    }

    public function test_el_universo_administrativo_no_se_ha_encogido(): void
    {
        // Si alguien saca rutas de este universo por accidente —moviéndolas
        // fuera de /admin o quitándoles auth.admin— dejarían de autorizarse sin
        // que ningún otro test lo note.
        $this->assertGreaterThanOrEqual(
            300,
            count($this->administrativeRoutes()),
            'El universo administrativo ha encogido de forma sospechosa.',
        );
    }

    public function test_ningun_permiso_exigido_esta_fuera_del_catalogo(): void
    {
        $catalogo = CrmPermission::all();
        $huerfanos = [];

        foreach ($this->administrativeRoutes() as $route) {
            $p = AuthorizationMap::resolve($route);
            if ($p === null || in_array($p, [AuthorizationMap::PUBLIC, AuthorizationMap::SELF], true)) {
                continue;
            }
            if (! in_array($p, $catalogo, true)) {
                $huerfanos[$p] = AuthorizationMap::routeKey($route);
            }
        }

        $this->assertSame([], $huerfanos,
            'El mapa exige permisos que el catálogo no ofrece: nadie podría concederlos. '
            .json_encode($huerfanos));
    }

    public function test_ningun_permiso_del_catalogo_esta_desconectado(): void
    {
        // El defecto que se está corrigiendo: 44 de 71 llaves no protegían nada.
        // Los alias del vocabulario anterior se excluyen: son sinónimos, no
        // llaves propias.
        $alias = [CrmPermission::CAJA_VIEW, CrmPermission::CAJA_SELL, CrmPermission::CAJA_MANAGE];
        $exigidos = AuthorizationMap::referencedPermissions();

        $desconectados = array_values(array_diff(PermissionCatalog::all(), $exigidos, $alias));

        $this->assertSame([], $desconectados,
            'Hay permisos en el catálogo que ninguna ruta exige: son interruptores sin cable. '
            .implode(', ', $desconectados));
    }

    public function test_las_excepciones_son_pocas_y_deliberadas(): void
    {
        $excepciones = [];
        foreach ($this->administrativeRoutes() as $route) {
            $p = AuthorizationMap::resolve($route);
            if (in_array($p, [AuthorizationMap::PUBLIC, AuthorizationMap::SELF], true)) {
                $excepciones[AuthorizationMap::routeKey($route)] = $p;
            }
        }

        // Una excepción es una ruta que no exige permiso: cuantas menos, mejor.
        // El número no es mágico, es un tope que obliga a justificar la
        // siguiente en vez de añadirla sin pensar.
        $this->assertLessThanOrEqual(15, count($excepciones),
            'Demasiadas rutas sin permiso. Revisa si son excepciones reales: '
            .json_encode(array_keys($excepciones)));
    }

    public function test_solo_el_login_es_publico(): void
    {
        $publicas = [];
        foreach ($this->administrativeRoutes() as $route) {
            if (AuthorizationMap::resolve($route) === AuthorizationMap::PUBLIC) {
                $publicas[] = AuthorizationMap::routeKey($route);
            }
        }

        sort($publicas);
        $this->assertSame(
            ['POST api/admin/auth/login', 'POST api/turnstile/webhook/fire'],
            $publicas,
            'Solo la puerta de entrada del CRM y el webhook del torniquete pueden ser públicas.',
        );
    }

    public function test_el_catalogo_describe_todos_sus_dominios(): void
    {
        $sinDescribir = array_filter(
            PermissionCatalog::rows(),
            fn (array $f) => $f['domain_hint'] === 'Dominio sin describir en PermissionCatalog',
        );

        $this->assertSame([], array_column($sinDescribir, 'key'),
            'Hay dominios sin nombre humano: aparecerían en la matriz con su clave técnica.');
    }
}
