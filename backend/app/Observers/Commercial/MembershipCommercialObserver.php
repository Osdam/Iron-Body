<?php

namespace App\Observers\Commercial;

use App\Models\User;
use App\Services\Commercial\CommercialEventRecorder;
use App\Services\Commercial\CommercialSubjectResolver;
use App\Services\Commercial\CommercialVocabulary as V;
use Illuminate\Support\Carbon;

/**
 * Alta y renovación de membresía, observadas donde realmente ocurren.
 *
 * La vigencia vive en `users.membership_end_date`; ese campo es la verdad que
 * decide si alguien entra al gimnasio. Escuchar ahí cubre todos los caminos que
 * la extienden —pago aprobado, renovación recurrente, alta manual desde el CRM,
 * corrección administrativa— sin tener que enumerarlos.
 *
 * La distinción entre alta y renovación no es cosmética: a un socio nuevo se le
 * acompaña a empezar, y a uno que renueva se le agradece. Confundirlas produce
 * el mensaje de bienvenida que recibe alguien que lleva dos años viniendo.
 */
class MembershipCommercialObserver
{
    public function __construct(
        private readonly CommercialEventRecorder $recorder,
        private readonly CommercialSubjectResolver $resolver,
    ) {}

    public function updated(User $user): void
    {
        if (! (bool) config('commercial.events_enabled', false)) {
            return;
        }

        if (! $user->wasChanged('membership_end_date')) {
            return;
        }

        $previous = $this->toDate($user->getOriginal('membership_end_date'));
        $current = $this->toDate($user->membership_end_date);

        // Solo cuenta ganar vigencia. Acortarla es una cancelación o una
        // corrección: son hechos reales, pero no comerciales, y tratarlos aquí
        // llevaría a felicitar a alguien por darse de baja.
        if ($current === null || ($previous !== null && ! $current->greaterThan($previous))) {
            return;
        }

        // Y tiene que ser vigencia de VERDAD. Cargar una fecha ya pasada es
        // corregir un histórico —una migración, un alta antigua que se
        // registra tarde—, no vender nada. Sin esta comprobación, importar
        // datos viejos dispararía una bienvenida por cada socio importado.
        if ($current->lessThan(Carbon::today())) {
            return;
        }

        // Renovación si la anterior seguía viva al extenderla; alta si no había
        // membresía o ya había caducado (que comercialmente es una
        // reactivación, y se acompaña distinto).
        $isRenewal = $previous !== null && $previous->greaterThanOrEqualTo(Carbon::today());

        $this->recorder->record(
            event: $isRenewal ? V::EV_MEMBERSHIP_RENEWED : V::EV_MEMBERSHIP_ACTIVATED,
            subject: $this->resolver->fromUser($user->id),
            payload: [
                'plan' => $user->plan,
                'previous_end_date' => $previous?->toDateString(),
                'ends_at' => $current->toDateString(),
            ],
            // La identidad del hecho es «este usuario quedó vigente hasta este
            // día». Dos procesos que apliquen la misma extensión producen la
            // misma clave y solo uno registra.
            dedupeKey: "membership:{$user->id}:{$current->toDateString()}",
        );
    }

    private function toDate(mixed $value): ?Carbon
    {
        if (blank($value)) {
            return null;
        }

        try {
            return Carbon::parse($value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
