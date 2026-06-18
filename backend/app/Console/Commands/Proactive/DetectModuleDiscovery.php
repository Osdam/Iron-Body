<?php

namespace App\Console\Commands\Proactive;

use App\Models\Member;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * module.discovery — PREPARADO PERO INACTIVO.
 *
 * Invita a usar módulos premium poco usados. Requiere la tabla
 * `app_module_usages` POBLADA por instrumentación en Flutter (Fase 3). Sin esos
 * datos NO se puede saber qué módulos no usa el miembro, así que el detector se
 * salta siempre mientras:
 *   - config('proactive_coach.discovery_enabled') sea false, o
 *   - la tabla no exista / no tenga datos.
 *
 * Esto evita inventar uso y evita spam (de lo contrario dispararía para todos).
 */
class DetectModuleDiscovery extends BaseProactiveDetectorCommand
{
    protected $signature = 'ironbody:detect-module-discovery {--dry-run} {--member-id=} {--limit=} {--event=}';
    protected $description = '[INACTIVO] Invita a descubrir módulos poco usados. Requiere tracking app_module_usages (Fase 3).';

    protected function detect(): void
    {
        if (!config('proactive_coach.discovery_enabled', false)) {
            $this->warn('module.discovery está INACTIVO (PROACTIVE_COACH_DISCOVERY_ENABLED=false). Requiere instrumentación de uso de módulos (Fase 3). No se emite nada.');
            return;
        }

        if (!Schema::hasTable('app_module_usages') || DB::table('app_module_usages')->limit(1)->count() === 0) {
            $this->warn('Sin datos en app_module_usages: no hay base para inferir módulos no usados. No se emite nada.');
            return;
        }

        // Lógica futura (Fase 3): por cada miembro, comparar el set de módulos
        // premium contra los module_key registrados; si faltan módulos clave,
        // emitir module.discovery. NO se implementa el criterio hasta tener
        // datos reales de uso (no inventar uso).
        $this->forEachMember(function (Member $member) {
            // Marcador explícito: requiere criterio basado en datos reales.
            // Intencionalmente NO emite hasta definir el umbral con datos.
            unset($member);
        });
    }
}
