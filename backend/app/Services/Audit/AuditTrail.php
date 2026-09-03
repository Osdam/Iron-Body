<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Support\Access\AdminActor;
use Illuminate\Http\Request;

/**
 * Escribe la traza de una operación administrativa, desde el servidor.
 *
 * Existe porque la escribía el navegador. Tras cada operación el CRM lanzaba un
 * `POST /api/admin/audit-logs`, y ese endpoint exige `roles.manage` —escribir en
 * el dominio `audit` es la operación más privilegiada del panel—, así que todo
 * el que no fuera Super Admin recibía 403. Y el fallo no se veía: la entrada se
 * pintaba en la vista y en `localStorage` ANTES de intentar guardarla, con el
 * error descartado. Quien operaba abría el registro, veía su acción listada y
 * la daba por guardada. En la base de datos no estaba.
 *
 * Aquí no hay nada que el cliente pueda omitir ni falsificar: la traza es
 * consecuencia de la operación, y el actor sale de la credencial verificada.
 *
 * Deliberadamente pequeño. {@see FinancialAudit} sigue con sus métodos propios
 * porque ventas y cobros tienen forma fija y ya están validados en producción;
 * esto cubre el resto de dominios, donde lo único común es cómo se firma la
 * fila. Envolver ambos en una jerarquía no quitaría duplicación real y tocaría
 * código que hoy funciona.
 */
class AuditTrail
{
    /**
     * Deja constancia de una mutación.
     *
     * Sin persona identificada NO escribe. Las automatizaciones entran con un
     * token compartido que no es nadie, y firmar una traza sin responsable sería
     * peor que no tenerla: daría por auditado lo que no lo está.
     *
     * NO va en try/catch, al contrario que otras auditorías del proyecto. Donde
     * se llama dentro de la transacción del negocio, tragarse el fallo
     * devolvería justo lo que se corrige —una operación sin traza— y en
     * silencio. Si la traza no se puede escribir, la operación no se confirma.
     *
     * @param  array{action: string, module: string, entity: string, entity_id?: string|int|null, target_name?: ?string, summary?: ?string, changes?: ?array<int,array<string,mixed>>, metadata?: ?array<string,mixed>}  $evento
     */
    public function record(Request $request, array $evento): void
    {
        $actor = AdminActor::from($request);
        if ($actor === null) {
            return;
        }

        AuditLog::create([
            'action' => $evento['action'],
            'module' => $evento['module'],
            'entity' => $evento['entity'],
            'entity_id' => isset($evento['entity_id']) ? (string) $evento['entity_id'] : null,
            'target_name' => $evento['target_name'] ?? null,
            'summary' => $evento['summary'] ?? null,
            'changes' => $evento['changes'] ?? null,
            'metadata' => $evento['metadata'] ?? null,
            'actor_id' => (string) $actor->id,
            // Instantánea: la traza debe seguir diciendo quién fue aunque la
            // cuenta se renombre o se elimine después.
            'actor_name' => $actor->name,
            'actor_role' => $actor->role,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 512),
            'created_at' => now(),
        ]);
    }

    /**
     * Qué campos cambiaron, en la forma que ya consume el CRM.
     *
     * Solo los que de verdad cambiaron: volcar el objeto entero llenaría la
     * traza de ruido y escondería el dato que importa. Los valores no se
     * guardan —pueden ser datos personales— salvo que el llamante los pase
     * explícitamente.
     *
     * @param  array<string, mixed>  $antes
     * @param  array<string, mixed>  $despues
     * @return array<int, array<string, string>>
     */
    public function diff(array $antes, array $despues): array
    {
        $cambios = [];

        foreach ($despues as $campo => $valor) {
            if (! array_key_exists($campo, $antes) || $antes[$campo] !== $valor) {
                $cambios[] = ['field' => $campo];
            }
        }

        return $cambios;
    }
}
