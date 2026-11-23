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
 * DOS AUTORÍAS (B-1 de la revisión de security; corrección decidida por el
 * lead): quien escribe el texto queda en `commercial_text_by` (cambia solo
 * cuando cambia el texto: {@see textChanged()}) y quien lo pone a la venta,
 * en `commercial_sale_by` ({@see becomesSellable()}). Antes una sola columna
 * guardaba las dos cosas, y encender un plan sobrescribía a quien escribió el
 * texto: con un clic de otra persona, quien lo escribió lo publicaba.
 *
 * FALLA CERRADO TAMBIÉN CON UN TEXTO ILEGIBLE (N-2 de la misma revisión): una
 * lista anidada no se puede leer y {@see Plan::commercialText()} la da como
 * sin beneficios. Si el texto de después no se puede leer y el valor en bruto
 * de `benefits` cambió, cuenta como texto cambiado.
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

    /** Las columnas de las dos autorías (B-1): quién escribió el texto y quién puso el plan a la venta. */
    public const AUTHOR_COLUMNS = ['commercial_text_by', 'commercial_sale_by'];

    /**
     * Por qué una escritura pide el permiso, o null si no lo pide. Pura.
     *
     * @param  array{name: string, benefits: list<string>}|null  $textBefore  null en un alta
     * @param  array{name: string, benefits: list<string>}  $textAfter
     * @param  bool  $unreadableChange  N-2: el texto de después no se puede leer y el valor en bruto cambió
     */
    public static function reasonFor(?array $textBefore, bool $sellableBefore, array $textAfter, bool $sellableAfter, bool $unreadableChange = false): ?string
    {
        if (! $sellableAfter) {
            return null;
        }
        if (! $sellableBefore) {
            return self::REASON_BECOMES_SELLABLE;
        }

        return self::textChanged($textBefore, $textAfter, $unreadableChange) ? self::REASON_TEXT_CHANGED : null;
    }

    /**
     * ¿Cambió el texto comercial? Por valor ({@see Plan::commercialTextEquals()}),
     * o porque no se puede leer y su valor en bruto cambió (N-2). Un alta
     * (`$textBefore` null) siempre es texto nuevo. Cuando sí, quien escribe
     * queda en `commercial_text_by`. Pura.
     *
     * @param  array{name: string, benefits: list<string>}|null  $textBefore
     * @param  array{name: string, benefits: list<string>}  $textAfter
     */
    public static function textChanged(?array $textBefore, array $textAfter, bool $unreadableChange = false): bool
    {
        return $textBefore === null || $unreadableChange || ! Plan::commercialTextEquals($textBefore, $textAfter);
    }

    /** ¿Entra en la venta? Cuando sí, quien lo hace queda en `commercial_sale_by` (B-1). Pura. */
    public static function becomesSellable(bool $sellableBefore, bool $sellableAfter): bool
    {
        return ! $sellableBefore && $sellableAfter;
    }

    /**
     * Los cambios de la auditoría sin las columnas de las autorías: la línea ya
     * dice quién lo hizo (su actor), y la auditoría de un plan sigue siendo la
     * de siempre, sin campos de fontanería de más.
     *
     * @param  list<array<string, mixed>>  $changes  lo que dio `AuditTrail::changesOf()`
     * @return list<array<string, mixed>>
     */
    public static function withoutAuthor(array $changes): array
    {
        return array_values(array_filter($changes, static fn (array $c): bool => ! in_array($c['field'] ?? null, self::AUTHOR_COLUMNS, true)));
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
