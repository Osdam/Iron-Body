<?php

namespace App\Console\Commands;

use App\Support\Access\AuthorizationMap;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;

/**
 * Simulador de cobertura: pasa TODAS las rutas por el mapa de autorización y
 * dice qué quedaría protegido, qué es excepción y qué no se sabe clasificar.
 *
 * Se ejecuta ANTES de activar el enforcement y después de cada cambio de
 * rutas. Con `--unmapped` lista solo lo que falta, que es como se cierra el
 * hueco sin leer 341 líneas.
 */
class AuthorizationCoverageCommand extends Command
{
    protected $signature = 'auth:coverage {--unmapped : Listar solo las rutas sin clasificar}
                                          {--role= : Simular qué vería un rol concreto}';

    protected $description = 'Comprueba que toda ruta administrativa resuelve a un permiso';

    public function handle(): int
    {
        $rutas = collect(Route::getRoutes())->filter(
            fn ($r) => AuthorizationMap::isAdministrative($r)
        );

        $mapeadas = [];
        $excepciones = [];
        $sinMapear = [];

        foreach ($rutas as $r) {
            $permiso = AuthorizationMap::resolve($r);
            $clave = AuthorizationMap::routeKey($r);

            if ($permiso === null) {
                $sinMapear[] = $clave;
            } elseif (in_array($permiso, [AuthorizationMap::PUBLIC, AuthorizationMap::SELF], true)) {
                $excepciones[$clave] = $permiso;
            } else {
                $mapeadas[$clave] = $permiso;
            }
        }

        if ($this->option('unmapped')) {
            foreach ($sinMapear as $c) {
                $this->line($c);
            }

            return $sinMapear === [] ? self::SUCCESS : self::FAILURE;
        }

        if ($rol = $this->option('role')) {
            return $this->simulateRole($rol, $mapeadas, $excepciones);
        }

        // CONFLICTOS: rutas que ya traían una anotación `admin.can` y que el
        // mapa resuelve a OTRO permiso. Cada uno es una decisión que hay que
        // tomar a conciencia, no un detalle: significan que dos autoridades
        // dicen cosas distintas sobre la misma ruta.
        $conflictos = [];
        foreach ($rutas as $r) {
            $anotado = null;
            foreach ($r->gatherMiddleware() as $m) {
                if (is_string($m) && str_starts_with($m, 'admin.can:')) {
                    $anotado = substr($m, strlen('admin.can:'));
                }
            }
            if ($anotado === null) {
                continue;
            }
            $delMapa = AuthorizationMap::resolve($r);
            if (\App\Support\Access\CrmPermission::canonical($anotado)
                !== \App\Support\Access\CrmPermission::canonical((string) $delMapa)) {
                $conflictos[] = AuthorizationMap::routeKey($r)."  anotado={$anotado}  mapa={$delMapa}";
            }
        }

        $this->newLine();
        $this->line("TOTAL_ADMIN_ROUTES=".$rutas->count());
        $this->line("MAPPED=".count($mapeadas));
        $this->line("EXPLICIT_EXCEPTIONS=".count($excepciones));
        $this->line("UNMAPPED=".count($sinMapear));
        $this->line("CONFLICTS=".count($conflictos));
        foreach ($conflictos as $c) {
            $this->line('  ! '.$c);
        }
        $this->newLine();

        $this->line('Permisos exigidos, por frecuencia:');
        collect($mapeadas)->countBy()->sortDesc()->each(
            fn ($n, $p) => $this->line(sprintf('  %4d  %s', $n, $p))
        );

        if ($sinMapear !== []) {
            $this->newLine();
            $this->error('SIN CLASIFICAR ('.count($sinMapear).'):');
            foreach (array_slice($sinMapear, 0, 40) as $c) {
                $this->line('  '.$c);
            }

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Cobertura completa: ninguna ruta administrativa queda sin clasificar.');

        return self::SUCCESS;
    }

    /**
     * @param  array<string,string>  $mapeadas
     * @param  array<string,string>  $excepciones
     */
    private function simulateRole(string $rol, array $mapeadas, array $excepciones): int
    {
        // Sin base de datos disponible se simula sobre los defaults del código.
        // La herramienta es de diseño: tiene que servir antes de que exista el
        // entorno, no solo con producción delante.
        try {
            $efectivos = app(\App\Support\Access\RolePermissionPolicy::class)->effectiveFor($rol);
        } catch (\Throwable) {
            $this->comment('Sin acceso a role_permissions: se simula con los valores por defecto del código.');
            $efectivos = \App\Support\Access\CrmPermission::defaultsFor($rol);
        }
        $canon = array_map(\App\Support\Access\CrmPermission::canonical(...), $efectivos);

        $permitidas = [];
        $denegadas = [];
        foreach ($mapeadas as $clave => $permiso) {
            if (in_array(\App\Support\Access\CrmPermission::canonical($permiso), $canon, true)) {
                $permitidas[] = $clave;
            } else {
                $denegadas[] = $clave;
            }
        }

        $this->newLine();
        $this->line("ROLE={$rol}");
        $this->line('PERMISSIONS='.count($efectivos));
        $this->line('ALLOWED_ROUTES='.count($permitidas).' (+'.count($excepciones).' excepciones)');
        $this->line('DENIED_ROUTES='.count($denegadas));
        $this->newLine();

        $this->line('Dominios accesibles:');
        collect($permitidas)
            ->map(fn ($c) => $mapeadas[$c])
            ->countBy()->sortDesc()
            ->each(fn ($n, $p) => $this->line(sprintf('  %4d  %s', $n, $p)));

        return self::SUCCESS;
    }
}
