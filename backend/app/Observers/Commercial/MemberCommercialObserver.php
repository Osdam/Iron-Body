<?php

namespace App\Observers\Commercial;

use App\Models\Member;
use App\Services\Commercial\CommercialEventRecorder;
use App\Services\Commercial\CommercialSubjectResolver;
use App\Services\Commercial\CommercialVocabulary as V;

/**
 * La ficha de socio queda enlazada con una cuenta de la aplicación.
 *
 * Es el mismo criterio que usa {@see \App\Services\Commercial\CommercialSubject}
 * para saber si alguien «tiene app»: un `user_id` en la ficha. Se observa el
 * paso de nulo a un valor, que es el instante exacto de la vinculación.
 *
 * Importa comercialmente porque un socio con app entrena más y renueva más, así
 * que acompañar la instalación no es soporte técnico: es retención.
 */
class MemberCommercialObserver
{
    public function __construct(
        private readonly CommercialEventRecorder $recorder,
        private readonly CommercialSubjectResolver $resolver,
    ) {}

    public function updated(Member $member): void
    {
        if (! (bool) config('commercial.events_enabled', false)) {
            return;
        }

        if (! $member->wasChanged('user_id')) {
            return;
        }

        // Solo el enlace. Desenlazar existe (una cuenta mal asociada que se
        // corrige) pero no es un hecho comercial.
        if (empty($member->user_id) || ! empty($member->getOriginal('user_id'))) {
            return;
        }

        $this->recorder->record(
            event: V::EV_APP_LINKED,
            subject: $this->resolver->fromMember($member->id),
            payload: ['user_id' => $member->user_id],
            dedupeKey: "member:{$member->id}:app_linked",
        );
    }
}
