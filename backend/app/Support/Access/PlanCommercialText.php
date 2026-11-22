<?php

namespace App\Support\Access;

use App\Exceptions\PlanArchiveException;
use App\Models\Admin;
use App\Models\Plan;
use Illuminate\Http\Request;

/**
 * Quién decide lo que se dice FUERA de un plan: su nombre y sus beneficios
 * (PT-14 · A, diseño `plans/informes-cierre/diseno-arq-pt14.md` §3 D1–D3, D15).
 *
 * Lo que se gobierna es el CONJUNTO VENDIBLE ({@see Plan::sellable()}: ULTRON,
 * Sofía, los enlaces de pago y la app). Pide `plans.commercial_text` toda
 * escritura que deje un plan vendible y además:
 *  - cambie su texto comercial (`text_changed`), comparado por VALOR con
 *    {@see Plan::commercialText()} —el CRM manda `name` y `benefits` en cada
 *    guardado, y reenviar lo mismo no es cambiarlo—;
 *  - o lo meta en la venta (`becomes_sellable`): crearlo vendible, activarlo,
 *    ofrecerlo fuera, darle precio o vigencia o reactivarlo. Sin esto, el
 *    texto se editaría con el plan fuera de la venta y se encendería después.
 * Apagar, archivar y borrar van en la dirección segura y no lo piden.
 *
 * El permiso no es una ruta: el nombre viaja en el mismo PUT que el precio
 * (como `payments.backdate`). Lo aplican PlanController y PlanArchive con esta
 * pieza, para que la regla viva en un solo sitio (como {@see InventoryCosts}).
 *
 * Falla CERRADO: el token compartido no resuelve a una persona y no lo tiene.
 */
final class PlanCommercialText
{
    public const PERMISSION = CrmPermission::PLANS_COMMERCIAL_TEXT;

    public const REASON_TEXT_CHANGED = 'text_changed';

    public const REASON_BECOMES_SELLABLE = 'becomes_sellable';

    /**
     * Por qué una escritura pide el permiso, o null si no lo pide. Pura.
     *
     * @param  array{name: string, benefits: list<string>}|null  $textBefore  null en un alta
     * @param  array{name: string, benefits: list<string>}  $textAfter
     */
    public static function reasonFor(?array $textBefore, bool $sellableBefore, array $textAfter, bool $sellableAfter): ?string
    {
        if (! $sellableAfter) {
            return null;
        }
        if (! $sellableBefore) {
            return self::REASON_BECOMES_SELLABLE;
        }

        return $textBefore === null || ! Plan::commercialTextEquals($textBefore, $textAfter) ? self::REASON_TEXT_CHANGED : null;
    }

    /**
     * ¿Cambia el autor? Cuando el texto cambia o el plan entra en la venta: el
     * autor es «la última persona que escribió el texto comercial vigente o lo
     * puso a la venta» (D4). Pura.
     *
     * @param  array{name: string, benefits: list<string>}  $textBefore
     * @param  array{name: string, benefits: list<string>}  $textAfter
     */
    public static function authorChanges(array $textBefore, bool $sellableBefore, array $textAfter, bool $sellableAfter): bool
    {
        return ! Plan::commercialTextEquals($textBefore, $textAfter) || (! $sellableBefore && $sellableAfter);
    }

    /**
     * Los cambios de la auditoría sin la columna del autor: la línea ya dice
     * quién escribió (su actor), y la auditoría de un plan sigue siendo la de
     * siempre, sin un campo de fontanería más.
     *
     * @param  list<array<string, mixed>>  $changes  lo que dio `AuditTrail::changesOf()`
     * @return list<array<string, mixed>>
     */
    public static function withoutAuthor(array $changes): array
    {
        return array_values(array_filter($changes, static fn (array $c): bool => ($c['field'] ?? null) !== 'commercial_text_by'));
    }

    /** @throws PlanArchiveException 403 `plan_commercial_text_forbidden` si quien escribe no tiene la llave */
    public static function assertAllowed(Request $request, string $reason): void
    {
        if (! CrmPermission::allows(AdminActor::from($request), self::PERMISSION)) {
            throw PlanArchiveException::commercialTextForbidden($reason);
        }
    }

    /**
     * Si quien mira tiene la llave; null sin sesión de administrador. Se
     * resuelve UNA vez por petición: preguntarlo por plan recalcularía la
     * política entera en cada fila del listado (como {@see InventoryCosts}).
     */
    public static function allowedFor(?Admin $viewer): ?bool
    {
        return $viewer === null ? null : CrmPermission::allows($viewer, self::PERMISSION);
    }

    /**
     * `commercial_text_locked` de un plan para el editor: null sin sesión de
     * administrador; true si el plan es vendible ahora y quien mira no tiene la
     * llave (el CRM bloquea el nombre y los beneficios y no los manda).
     *
     * @param  bool|null  $allowed  lo que dio {@see allowedFor()}
     */
    public static function locked(?bool $allowed, Plan $plan): ?bool
    {
        return $allowed === null ? null : ($plan->isSellable() && ! $allowed);
    }

    /** {@see locked()} para un solo plan. */
    public static function lockedFor(?Admin $viewer, Plan $plan): ?bool
    {
        return self::locked(self::allowedFor($viewer), $plan);
    }
}
