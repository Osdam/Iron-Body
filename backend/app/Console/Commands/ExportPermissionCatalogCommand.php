<?php

namespace App\Console\Commands;

use App\Support\Access\CrmPermission;
use Illuminate\Console\Command;

/**
 * Vuelca el catálogo de permisos a un fichero que el CRM pueda comprobar.
 *
 * Existe porque los dos lados divergieron sin que nada avisara. El CRM llegó a
 * tener 41 claves con cadenas que el backend no emite —`plans.edit`,
 * `classes.create`, diez de `moderation.*`—, y el efecto no era un error visible
 * sino una pantalla que bloqueaba en silencio: a recepción se le negaba editar
 * planes teniendo `plans.manage` concedido, porque el enum preguntaba por
 * `plans.edit`. Nadie podía darse cuenta leyendo un solo repositorio.
 *
 * El fichero generado lo consume una prueba del CRM que falla si alguien
 * introduce un permiso inexistente. No autoriza nada ni se usa en tiempo de
 * ejecución: es un contrato comprobable entre dos repositorios.
 */
class ExportPermissionCatalogCommand extends Command
{
    protected $signature = 'permissions:export {--path= : dónde escribir el .ts}';

    protected $description = 'Exporta el catálogo de permisos para que el CRM verifique su enum';

    public function handle(): int
    {
        $permisos = CrmPermission::all();
        sort($permisos);

        $destino = $this->option('path')
            ?: base_path('../../Iron-Body_Front/src/app/models/backend-permission-catalog.ts');

        $lineas = implode('', array_map(static fn (string $p) => "  '{$p}',\n", $permisos));

        $contenido = <<<TS
        // Catálogo canónico de permisos del backend.
        //
        // GENERADO — no editar a mano. Lo produce `php artisan permissions:export`
        // desde CrmPermission::all(), que es lo que el servidor aplica de verdad.
        //
        // Existe para que una prueba del CRM compruebe que no se inventa permisos.
        // Hubo 41 claves en el enum con cadenas que el backend no emitía jamás, y por
        // eso a Recepción se le bloqueaba editar planes teniendo `plans.manage`
        // concedido: el enum preguntaba por `plans.edit`.
        export const BACKEND_PERMISSION_CATALOG: readonly string[] = [
        {$lineas}] as const;

        TS;

        if (! is_dir(dirname($destino))) {
            $this->error('No existe el directorio: '.dirname($destino));

            return self::FAILURE;
        }

        file_put_contents($destino, $contenido);
        $this->info(count($permisos).' permisos exportados a '.$destino);

        return self::SUCCESS;
    }
}
