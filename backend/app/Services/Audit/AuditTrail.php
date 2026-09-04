<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Support\Access\AdminActor;
use Illuminate\Database\Eloquent\Model;
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
     * Qué campos cambiaron DE VERDAD en un modelo recién guardado.
     *
     * Se apoya en el seguimiento de Eloquent, no en una comparación propia. La
     * diferencia no es de estilo: comparar a mano el cuerpo de la petición
     * contra el estado anterior marca cambios que no existen. Medido sobre este
     * mismo proyecto —enviar `price: "80000"` sobre un plan que ya vale `80000`,
     * o `active: 1` sobre uno que ya está activo— una comparación estricta los
     * daba por modificados; `getChanges()` no. Eloquent conoce los casts y las
     * equivalencias numéricas, y esto es exactamente lo que ya hacía el
     * catálogo de productos con `getDirty()`.
     *
     * Antes se registraban las CLAVES ENVIADAS: editar el teléfono de un socio
     * dejaba una traza diciendo que se habían tocado los dieciséis campos del
     * formulario. Cierto en lo literal e inútil para auditar.
     *
     * Debe llamarse DESPUÉS de guardar: hasta entonces `getChanges()` está
     * vacío. Y `$previo` hay que capturarlo ANTES, porque al guardar Eloquent
     * sincroniza los valores originales con los nuevos.
     *
     * Los valores solo se registran para los campos que el llamante liste en
     * `$conValores`. El resto deja constancia únicamente del NOMBRE del campo:
     * la traza no es sitio para el documento, el teléfono ni el correo de un
     * socio, y la lista blanca obliga a decidirlo caso por caso en vez de
     * volcarlo todo por comodidad.
     *
     * @param  array<string, mixed>  $previo  `$model->getOriginal()` antes de guardar
     * @param  list<string>  $conValores  campos NO sensibles cuyo antes/después sí aporta
     * @return list<array<string, mixed>>
     */
    public function changesOf(Model $model, array $previo = [], array $conValores = []): array
    {
        $cambios = [];

        foreach (array_keys($model->getChanges()) as $campo) {
            if ($this->esTecnico($model, $campo)) {
                continue;
            }

            $antes = $previo[$campo] ?? null;
            $ahora = $model->getAttribute($campo);

            if ($this->esEquivalente($model, $campo, $antes, $ahora)) {
                continue;
            }

            $registro = ['field' => $campo];

            if (in_array($campo, $conValores, true)) {
                $registro['from'] = $this->legible($antes);
                $registro['to'] = $this->legible($ahora);
            }

            $cambios[] = $registro;
        }

        return $cambios;
    }

    /**
     * ¿Es una columna de fontanería y no un cambio de negocio?
     *
     * Eloquent toca `updated_at` ANTES de calcular el diff, así que sale como
     * modificada en cada guardado. Se vio en producción: cambiar el precio de un
     * plan dejaba `[price, updated_at, features]` donde solo el precio importaba.
     * Las pruebas no lo detectaron porque creaban y editaban en el mismo
     * segundo, y entonces la marca de tiempo no llega a cambiar.
     */
    private function esTecnico(Model $model, string $campo): bool
    {
        return in_array($campo, array_filter([
            $model->getCreatedAtColumn(),
            $model->getUpdatedAtColumn(),
            method_exists($model, 'getDeletedAtColumn') ? $model->getDeletedAtColumn() : null,
        ]), true);
    }

    /**
     * Para columnas JSON, ¿cambió el CONTENIDO o solo su serialización?
     *
     * Laravel compara los casts `object` y `collection` decodificando el JSON,
     * pero el cast `array` no: reenviar las mismas claves en otro orden lo marca
     * como modificado. También se vio en producción — el formulario de planes
     * reenviaba `features` y bastaba con que el orden variara.
     *
     * Los arrays INDEXADOS no se reordenan: en una lista el orden es el dato, y
     * mover el primer ejercicio de una rutina al final es un cambio real.
     */
    private function esEquivalente(Model $model, string $campo, mixed $antes, mixed $ahora): bool
    {
        if (! $model->hasCast($campo, ['array', 'json'])) {
            return false;
        }

        return $this->normalizar($this->comoArray($antes)) === $this->normalizar($this->comoArray($ahora));
    }

    /** @return array<mixed>|null */
    private function comoArray(mixed $valor): ?array
    {
        if (is_array($valor)) {
            return $valor;
        }

        if (is_string($valor)) {
            $decodificado = json_decode($valor, true);

            return is_array($decodificado) ? $decodificado : null;
        }

        return null;
    }

    /**
     * Ordena las claves de los objetos asociativos, dejando las listas intactas.
     *
     * @param  array<mixed>|null  $valor
     * @return array<mixed>|null
     */
    private function normalizar(?array $valor): ?array
    {
        if ($valor === null) {
            return null;
        }

        $esLista = array_is_list($valor);

        foreach ($valor as $k => $v) {
            if (is_array($v)) {
                $valor[$k] = $this->normalizar($v);
            }
        }

        if (! $esLista) {
            ksort($valor);
        }

        return $valor;
    }

    /**
     * Valor apto para guardar en la traza.
     *
     * Se reduce a texto plano: un objeto o un array dentro de `changes` haría
     * que la vista de auditoría tuviera que adivinar cómo pintarlo, y de paso
     * abriría la puerta a que se colara una estructura entera con datos que
     * nadie revisó.
     */
    private function legible(mixed $valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        if (is_bool($valor)) {
            return $valor ? 'sí' : 'no';
        }

        if (is_scalar($valor)) {
            return mb_substr((string) $valor, 0, 120);
        }

        return '(no representable)';
    }
}
