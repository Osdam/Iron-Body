<?php

namespace App\Services\Members;

use App\Models\Member;
use App\Models\MemberSecurityEvent;
use App\Observers\MemberPhoneObserver;
use App\Services\Identity\MemberOwnershipLink;

/**
 * Rastro de cada cambio REAL del teléfono propio de la ficha de socio
 * (`members.phone`), venga del camino que venga.
 *
 * Para qué: la cuarentena del vínculo con el personal
 * ({@see MemberOwnershipLink::phoneRecentlyChanged()})
 * no deja verificar con un número recién puesto, porque quien lo puso puede
 * ser quien recibe el código. Antes solo veía dos rastros parciales: la traza
 * del PATCH de la ficha del CRM —que lista los campos del USUARIO, así que si
 * `users.phone` ya tenía el número nuevo, `members.phone` cambiaba sin que
 * `phone` apareciera (hueco M2)— y el evento `phone_changed` de los flujos de
 * cambio de número. El registro, las importaciones y el resto de guardados no
 * dejaban nada.
 *
 * Un solo punto: {@see MemberPhoneObserver} lo llama en cada
 * guardado del modelo. Lo que escribe la columna SIN pasar por el modelo (un
 * `update()` del constructor de consultas, que no dispara eventos) tiene que
 * llamarlo a mano; hoy solo es el PATCH de la ficha del CRM.
 *
 * Qué guarda: el NOMBRE del campo y por dónde entró. Ni el número ni una huella
 * suya: la huella de un celular de 10 dígitos se revierte por fuerza bruta y
 * sobreviviría al borrado de la cuenta. No es una traza de auditoría con
 * responsable (esa es la del CRM o el `phone_changed` del flujo): es el hecho
 * de datos, sin duplicar ninguna de las dos.
 *
 * Se escribe ANTES del cambio y sin tragarse errores: si el rastro no se puede
 * guardar, el teléfono no cambia. Un rastro sin cambio (la escritura fallara
 * después) solo alarga una cuarentena; un cambio sin rastro la abriría.
 */
class MemberPhoneTrail
{
    /**
     * ¿Cambia de verdad el número al que iría un SMS? Solo formato
     * («+57 300 555 1234» → «3005551234») no es un cambio.
     */
    public function changes(?string $before, ?string $after): bool
    {
        return Member::normalizePhone($this->clean($before)) !== Member::normalizePhone($this->clean($after));
    }

    public function recordIfChanged(Member $member, ?string $before, ?string $after): void
    {
        if (! $member->exists || ! $this->changes($before, $after)) {
            return;
        }

        $request = request();
        $route = $request->route();

        MemberSecurityEvent::create([
            'member_id' => $member->getKey(),
            'type' => MemberSecurityEvent::TYPE_PHONE_FIELD_CHANGED,
            'ip_address' => $route !== null ? $request->ip() : null,
            'user_agent' => $route !== null ? mb_substr((string) $request->userAgent(), 0, 500) : null,
            'metadata' => [
                'field' => 'phone',
                // La plantilla de la ruta, no la ruta: sin ids ni documentos.
                'via' => $route !== null ? $request->method().' '.$route->uri() : 'console',
            ],
        ]);
    }

    private function clean(?string $phone): ?string
    {
        $phone = trim((string) $phone);

        return $phone === '' ? null : $phone;
    }
}
