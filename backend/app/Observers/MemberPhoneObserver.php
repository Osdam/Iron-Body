<?php

namespace App\Observers;

use App\Models\Member;
use App\Services\Members\MemberPhoneTrail;

/**
 * Deja el rastro de cada cambio real de `members.phone` hecho con el modelo,
 * desde cualquier camino (ficha del CRM, registro de la app, importaciones,
 * cambio de número, soporte, borrado de la cuenta…). Un observer y no una
 * llamada en cada controlador: uno olvidado abriría la cuarentena del vínculo
 * con el personal (hueco M2). Ver {@see MemberPhoneTrail}.
 *
 * En `updating` y no en `updated`: el rastro se escribe antes que el cambio, y
 * si no se puede escribir, el cambio no ocurre. El alta de una ficha no es un
 * cambio de teléfono y no se rastrea.
 */
class MemberPhoneObserver
{
    public function __construct(private readonly MemberPhoneTrail $trail) {}

    public function updating(Member $member): void
    {
        if (! $member->isDirty('phone')) {
            return;
        }

        $this->trail->recordIfChanged($member, $member->getOriginal('phone'), $member->getAttribute('phone'));
    }
}
