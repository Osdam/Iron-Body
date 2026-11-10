<?php

namespace Tests\Feature\Outreach\Guards;

use App\Support\Access\AuthorizationMap;
use App\Support\Access\CrmPermission;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * MAPA DE RUTAS Y TOKEN COMPARTIDO (contrato H9, D9-13, T6; L9-36, L9-38,
 * L9-43; SEC9-a). Cada ruta nueva del H9 (`strategies/**`, `stream`,
 * `outreach/**`) está clasificada en `AuthorizationMap` (denegar por defecto)
 * o en la LISTA CERRADA de pendientes de la rama del H9, y nunca en las dos.
 * Hoy `auth:coverage --unmapped` lista exactamente las 30 de S9: el lead las
 * mapea en la rama (L9-38). Mientras tanto `EnforceAdminAuthorization` las
 * DENIEGA (falla cerrado, `auth:admin:unmapped_route`): no es un agujero del
 * H8 sino un pendiente de la rama, y esta guarda cae en cuanto una se mapee
 * (para vaciar la lista) o aparezca una ruta nueva sin clasificar.
 *
 * Y el token compartido: el dominio `ultron` ya está excluido; el dominio
 * `commercial_automation` (D9-13) todavía no, y ninguna ruta mapeada puede
 * depender de una llave de ese dominio mientras no lo esté.
 */
class OutreachRoutesMapGuardTest extends TestCase
{
    /** Las rutas del H9 ya clasificadas en la rama (L9-38): la lista queda vacía a propósito; una ruta nueva sin mapa la hace caer. */
    private const PENDING_IN_H9_BRANCH = [];

    /** Las llaves del H9 que el mapa puede usar (D9-13), además de las del Studio que ya existen. */
    private const H9_KEYS = [
        'ultron.view', 'ultron.approve', 'ultron.strategies_edit', 'ultron.strategies_publish', 'ultron.strategies_rollback', 'ultron.person_bindings_manage',
        'commercial_automation.view', 'commercial_automation.simulate_real', 'commercial_automation.timeline_view', 'commercial_automation.assisted_work',
        'commercial_automation.stop', 'commercial_automation.release',
    ];

    /** D9-13: el dominio de la ejecución está excluido del token compartido (rama del H9, R-2 de SEC9-a). */
    private const SHARED_TOKEN_DOMAIN_PENDING = false;

    public function test_toda_ruta_del_h9_esta_clasificada_o_en_la_lista_cerrada_de_pendientes_y_nunca_en_las_dos(): void
    {
        $vistas = [];
        $pendientesVistas = [];
        foreach ($this->h9Routes() as $ruta) {
            $clave = AuthorizationMap::routeKey($ruta);
            $vistas[] = $clave;
            $this->assertTrue(AuthorizationMap::isAdministrative($ruta), "{$clave} no es administrativa: el mapa no la miraría.");
            $permiso = AuthorizationMap::resolve($ruta);
            if (in_array($clave, self::PENDING_IN_H9_BRANCH, true)) {
                $pendientesVistas[] = $clave;
                $this->assertNull($permiso, "{$clave} ya está mapeada: quítala de PENDING_IN_H9_BRANCH (la lista tiene que vaciarse en la rama del H9).");

                continue;
            }
            $this->assertNotNull($permiso, "{$clave} es una ruta del H9 sin clasificar en AuthorizationMap y fuera de la lista de pendientes (denegar por defecto, D9-13).");
            foreach ((array) $permiso as $llave) {
                if ($llave === AuthorizationMap::SELF) {
                    continue;
                }
                $this->assertMatchesRegularExpression('/^(ultron|commercial_automation|audit|security|telephony)\./', (string) $llave, "{$clave} → {$llave}: una llave fuera de los dominios del Studio y de la ejecución.");
            }
        }
        $this->assertGreaterThanOrEqual(31, count($vistas), 'el rastreo ve las 30 rutas de S9 y el canal SSE');
        sort($pendientesVistas);
        $esperadas = self::PENDING_IN_H9_BRANCH;
        sort($esperadas);
        $this->assertSame($esperadas, $pendientesVistas, 'La lista de pendientes no coincide con las rutas reales sin mapa (una ruta renombrada o retirada).');
        if ($pendientesVistas !== []) {
            fwrite(STDERR, "\n[SEC9-a] ".count($pendientesVistas)." rutas del H9 sin mapa (denegadas por defecto), pendientes de la rama del H9 (L9-38)\n");
        }
    }

    public function test_el_canal_sse_esta_clasificado_con_la_base_del_studio(): void
    {
        $stream = null;
        foreach (Route::getRoutes() as $ruta) {
            if ($ruta->uri() === 'api/admin/ultron-studio/stream') {
                $stream = $ruta;
            }
        }
        $this->assertNotNull($stream, 'La ruta del canal SSE existe.');
        $this->assertSame(['GET', 'HEAD'], $stream->methods());
        $this->assertSame('App\\Http\\Controllers\\Crm\\UltronStudio\\UltronStudioStreamController@stream', $stream->getActionName(), 'El controlador vive en Crm/UltronStudio (L9-43).');
        $this->assertStringContainsString("[UltronStudioStreamController::class, 'stream']", (string) file_get_contents(base_path('routes/ultron-studio.php')), 'La ruta se declara en routes/ultron-studio.php (L9-43).');
        $this->assertStringNotContainsString('UltronStudioStreamController', (string) file_get_contents(base_path('routes/api.php')), 'routes/api.php es zona del motor: el canal no se declara ahí (L9-43).');
        // Hoy sin mapa (pendiente del lead en la rama): cuando se mapee, la puerta tiene que ser la base del Studio y nada más.
        $permiso = AuthorizationMap::resolve($stream);
        if (in_array(AuthorizationMap::routeKey($stream), self::PENDING_IN_H9_BRANCH, true)) {
            $this->assertNull($permiso, 'El canal ya está mapeado: quítalo de PENDING_IN_H9_BRANCH.');

            return;
        }
        $this->assertSame('ultron.view', $permiso, 'La puerta del canal es la base del Studio (SSE9); cada tema exige la suya dentro.');
    }

    public function test_el_token_compartido_no_entra_en_el_studio_y_el_dominio_de_la_ejecucion_queda_anotado(): void
    {
        $this->assertContains('ultron', CrmPermission::SHARED_TOKEN_EXCLUDED_DOMAINS, 'El token compartido no actúa sobre el Studio (D9-13).');

        $excluido = in_array('commercial_automation', CrmPermission::SHARED_TOKEN_EXCLUDED_DOMAINS, true);
        $this->assertSame(! self::SHARED_TOKEN_DOMAIN_PENDING, $excluido, 'El dominio commercial_automation cambió de estado en SHARED_TOKEN_EXCLUDED_DOMAINS: ajusta SHARED_TOKEN_DOMAIN_PENDING (D9-13).');

        // Mientras no esté excluido, ninguna ruta mapeada puede depender SOLO de una llave de ese dominio.
        if (! $excluido) {
            foreach ($this->h9Routes() as $ruta) {
                $permiso = AuthorizationMap::resolve($ruta);
                foreach ((array) $permiso as $llave) {
                    $this->assertStringStartsNotWith('commercial_automation.', (string) $llave, AuthorizationMap::routeKey($ruta).': una llave de commercial_automation con el dominio aún abierto al token compartido (D9-13).');
                }
            }
            fwrite(STDERR, "\n[SEC9-a] commercial_automation todavía no está en SHARED_TOKEN_EXCLUDED_DOMAINS (pendiente de la rama del H9, D9-13)\n");
        }

        // Las llaves del H9 que el mapa use tienen que ser las de D9-13.
        foreach ($this->h9Routes() as $ruta) {
            foreach ((array) AuthorizationMap::resolve($ruta) as $llave) {
                if (is_string($llave) && (str_starts_with($llave, 'ultron.strateg') || str_starts_with($llave, 'ultron.person_bindings') || str_starts_with($llave, 'commercial_automation.'))) {
                    $this->assertContains($llave, self::H9_KEYS, AuthorizationMap::routeKey($ruta).": la llave {$llave} no es de D9-13.");
                }
            }
        }
    }

    /** @return list<RouteDefinition> */
    private function h9Routes(): array
    {
        $out = [];
        foreach (Route::getRoutes() as $ruta) {
            $uri = $ruta->uri();
            if (preg_match('#^api/admin/ultron-studio/(strategies|stream)(/|$)#', $uri) === 1 || preg_match('#^api/admin/(ultron-studio/)?outreach(/|$)#', $uri) === 1
                || preg_match('#^api/admin/commercial-automation(/|$)#', $uri) === 1) {
                $out[] = $ruta;
            }
        }

        return $out;
    }
}
