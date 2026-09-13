<?php

namespace App\Services\Exports;

use App\Models\Admin;
use App\Support\Access\CrmPermission;

/**
 * Registro de lo que se puede exportar.
 *
 * Añadir un conjunto nuevo es: escribir su ExportDataset, listarlo aquí y
 * darle su ruta y su permiso en AuthorizationMap. Nada más; el CRM lo pinta
 * solo a partir de describe().
 */
class ExportCatalog
{
    /** @var array<string, class-string<ExportDataset>> */
    private const DATASETS = [
        'members' => MembersExport::class,
        'payments' => PaymentsExport::class,
    ];

    public function get(string $key): ?ExportDataset
    {
        $clase = self::DATASETS[$key] ?? null;

        return $clase ? app($clase) : null;
    }

    /** @return list<ExportDataset> */
    public function all(): array
    {
        return array_map(fn (string $clase) => app($clase), array_values(self::DATASETS));
    }

    /**
     * Solo los que esta sesión puede descargar. Ofrecer uno que va a devolver
     * 403 es peor que no mostrarlo.
     *
     * @return list<ExportDataset>
     */
    public function allowedFor(?Admin $admin): array
    {
        return array_values(array_filter(
            $this->all(),
            fn (ExportDataset $d) => CrmPermission::allows($admin, $d->permission()),
        ));
    }
}
