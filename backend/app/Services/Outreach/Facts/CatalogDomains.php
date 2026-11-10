<?php

namespace App\Services\Outreach\Facts;

use App\Services\Outreach\Core\OutreachCatalog;
use Illuminate\Support\Facades\DB;

/**
 * Los DOMINIOS EXTERNOS del catálogo (contrato H9, D9-4; `OutreachCatalog::enumDomain()`):
 * los valores que vienen de la base y con los que se valida un literal de una
 * condición. SOLO LECTURA. Tablas: `plans`, `classes`.
 *
 *  - `plans`: los ids (ENTEROS) de los planes VENDIBLES vigentes (`active`,
 *    `sellable`, sin archivar);
 *  - `class_types`: los tipos de las clases activas, normalizados con
 *    {@see FactValues::slug()} (la misma forma con la que {@see ClassFacts} los expone).
 */
final class CatalogDomains
{
    public const TABLES = ['plans', 'classes'];

    /** @return array{plans: list<int>, class_types: list<string>} dominio → valores ordenados */
    public function read(): array
    {
        $planes = DB::table('plans')->where('active', true)->where('sellable', true)->whereNull('archived_at')
            ->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();

        $tipos = [];
        foreach (DB::table('classes')->where('status', 'active')->distinct()->pluck('type') as $tipo) {
            $slug = FactValues::slug($tipo);
            if ($slug !== null) {
                $tipos[$slug] = true;
            }
        }
        $tipos = array_map('strval', array_keys($tipos));
        sort($tipos, SORT_STRING);

        return [OutreachCatalog::DOMAIN_PLANS => $planes, OutreachCatalog::DOMAIN_CLASS_TYPES => $tipos];
    }
}
