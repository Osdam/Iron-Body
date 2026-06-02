<?php

namespace App\Services;

use App\Models\Member;
use App\Models\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Generador central de notificaciones de Iron Body.
 *
 * Todas las notificaciones (app + CRM) nacen aquí. Cada evento real produce
 * como máximo UNA notificación por audiencia gracias a `event_key`
 * (idempotencia). Es *aditivo*: se engancha desde los flujos existentes
 * (pagos, clases, rutinas, IRON IA) sin alterar su resultado. Si algo falla
 * al notificar, se registra en log pero NUNCA rompe el flujo de negocio.
 */
class NotificationService
{
    // ── Eventos de PAGO ────────────────────────────────────────────────────────

    /**
     * Pago aprobado. Notifica al miembro y deja copia para el CRM (admin).
     *
     * @param  Member|null  $member
     * @param  object|array  $payment  PaymentTransaction o Payment (lee defensivo)
     */
    public function notifyPaymentApproved($member, $payment): void
    {
        $this->safe(function () use ($member, $payment): void {
            $reference = $this->attr($payment, 'reference');
            $amount    = (float) $this->attr($payment, 'amount', 0);
            $money     = $this->formatCop($amount);
            $name      = $member?->full_name ?? 'Miembro';

            $payload = array_filter([
                'reference' => $reference,
                'amount'    => $amount,
            ], fn ($v) => $v !== null);

            $this->createMemberNotification($member, [
                'type'        => 'payment',
                'title'       => 'Pago aprobado',
                'message'     => "Tu pago de {$money} fue aprobado correctamente.",
                'priority'    => 'medium',
                'should_popup' => true,
                'action_type' => 'payment_detail',
                'action_payload' => $payload,
                'metadata'    => ['reference' => $reference, 'amount' => $amount, 'member_name' => $name, 'source_event' => 'payment_approved'],
                'event_key'   => $reference ? "payment_approved_{$reference}" : null,
            ]);

            $this->createAdminNotification([
                'type'        => 'payment',
                'title'       => 'Pago aprobado',
                'message'     => "{$name} pagó {$money}" . ($reference ? " (ref. {$reference})." : '.'),
                'priority'    => 'low',
                'member'      => $member,
                'action_type' => 'payment_detail',
                'action_payload' => $payload,
                'metadata'    => ['reference' => $reference, 'amount' => $amount, 'member_name' => $name],
                'event_key'   => $reference ? "admin_payment_approved_{$reference}" : null,
            ]);
        });
    }

    /** Pago rechazado/fallido. */
    public function notifyPaymentRejected($member, $payment): void
    {
        $this->safe(function () use ($member, $payment): void {
            $reference = $this->attr($payment, 'reference');
            $amount    = (float) $this->attr($payment, 'amount', 0);
            $money     = $this->formatCop($amount);
            $reason    = $this->attr($payment, 'failure_reason');
            $name      = $member?->full_name ?? 'Miembro';

            $payload = array_filter([
                'reference' => $reference,
                'amount'    => $amount,
            ], fn ($v) => $v !== null);

            $this->createMemberNotification($member, [
                'type'        => 'payment',
                'title'       => 'Pago rechazado',
                'message'     => "Tu pago de {$money} no pudo procesarse. No se realizó ningún cobro.",
                'priority'    => 'high',
                'action_type' => 'payment_detail',
                'action_payload' => $payload,
                'metadata'    => ['reference' => $reference, 'amount' => $amount, 'reason' => $reason, 'member_name' => $name],
                'event_key'   => $reference ? "payment_rejected_{$reference}" : null,
            ]);

            $this->createAdminNotification([
                'type'        => 'payment',
                'title'       => 'Pago rechazado',
                'message'     => "El pago de {$name} por {$money} fue rechazado" . ($reference ? " (ref. {$reference})." : '.'),
                'priority'    => 'medium',
                'member'      => $member,
                'action_type' => 'payment_detail',
                'action_payload' => $payload,
                'metadata'    => ['reference' => $reference, 'amount' => $amount, 'reason' => $reason, 'member_name' => $name],
                'event_key'   => $reference ? "admin_payment_rejected_{$reference}" : null,
            ]);
        });
    }

    // ── Eventos de MEMBRESÍA ─────────────────────────────────────────────────────

    /**
     * Membresía activada/renovada.
     *
     * @param  object|array  $membership  normalmente un Plan o un array con name/id
     */
    public function notifyMembershipActivated($member, $membership): void
    {
        $this->safe(function () use ($member, $membership): void {
            $planName = $this->attr($membership, 'name') ?? $this->attr($membership, 'plan') ?? 'tu plan';
            $planId   = $this->attr($membership, 'id') ?? $this->attr($membership, 'plan_id');
            $endDate  = $this->attr($membership, 'membership_end_date') ?? $this->attr($membership, 'end_date');
            $name     = $member?->full_name ?? 'Miembro';

            $this->createMemberNotification($member, [
                'type'        => 'membership',
                'title'       => 'Membresía activada',
                'message'     => "Tu plan {$planName} quedó activo. ¡A entrenar!",
                'priority'    => 'medium',
                'should_popup' => true,
                'action_type' => 'membership_detail',
                'action_payload' => array_filter(['plan_id' => $planId, 'plan' => $planName]),
                'metadata'    => ['plan' => $planName, 'plan_id' => $planId, 'end_date' => $endDate, 'member_name' => $name, 'source_event' => 'membership_activated'],
                'event_key'   => ($member && $planId) ? "membership_activated_{$member->id}_{$planId}" : null,
            ]);

            $this->createAdminNotification([
                'type'        => 'membership',
                'title'       => 'Membresía activada',
                'message'     => "{$name} activó el plan {$planName}.",
                'priority'    => 'low',
                'member'      => $member,
                'metadata'    => ['plan' => $planName, 'plan_id' => $planId, 'member_name' => $name],
                'event_key'   => ($member && $planId) ? "admin_membership_activated_{$member->id}_{$planId}" : null,
            ]);
        });
    }

    /** Membresía próxima a vencer (la dispara un comando programado). */
    public function notifyMembershipExpiring($member, $membership): void
    {
        $this->safe(function () use ($member, $membership): void {
            $planName = $this->attr($membership, 'name') ?? $this->attr($membership, 'plan') ?? 'tu plan';
            $endDate  = $this->attr($membership, 'membership_end_date') ?? $this->attr($membership, 'end_date');
            $endStr   = $endDate ? (string) $endDate : 'pronto';
            $name     = $member?->full_name ?? 'Miembro';

            $this->createMemberNotification($member, [
                'type'        => 'membership',
                'title'       => 'Tu membresía está por vencer',
                'message'     => "Tu plan {$planName} vence el {$endStr}. Renueva para no perder tu acceso.",
                'priority'    => 'high',
                'action_type' => 'membership_renew',
                'action_payload' => array_filter(['plan' => $planName, 'end_date' => $endDate]),
                'metadata'    => ['plan' => $planName, 'end_date' => $endDate, 'member_name' => $name],
                'event_key'   => ($member && $endDate) ? "membership_expiring_{$member->id}_{$endStr}" : null,
            ]);
        });
    }

    /** Membresía vencida (la dispara un comando programado). */
    public function notifyMembershipExpired($member, $membership): void
    {
        $this->safe(function () use ($member, $membership): void {
            $planName = $this->attr($membership, 'name') ?? $this->attr($membership, 'plan') ?? 'tu plan';
            $endDate  = $this->attr($membership, 'membership_end_date') ?? $this->attr($membership, 'end_date');
            $endStr   = $endDate ? (string) $endDate : '';
            $name     = $member?->full_name ?? 'Miembro';

            $this->createMemberNotification($member, [
                'type'        => 'membership',
                'title'       => 'Tu membresía venció',
                'message'     => "Tu plan {$planName} venció. Renueva para recuperar tu acceso completo.",
                'priority'    => 'high',
                'action_type' => 'membership_renew',
                'action_payload' => array_filter(['plan' => $planName, 'end_date' => $endDate]),
                'metadata'    => ['plan' => $planName, 'end_date' => $endDate, 'member_name' => $name],
                'event_key'   => ($member && $endStr) ? "membership_expired_{$member->id}_{$endStr}" : null,
            ]);

            $this->createAdminNotification([
                'type'        => 'membership',
                'title'       => 'Membresía vencida',
                'message'     => "La membresía de {$name} ({$planName}) venció.",
                'priority'    => 'low',
                'member'      => $member,
                'metadata'    => ['plan' => $planName, 'end_date' => $endDate, 'member_name' => $name],
                'event_key'   => ($member && $endStr) ? "admin_membership_expired_{$member->id}_{$endStr}" : null,
            ]);
        });
    }

    /**
     * Cambio de plan realizado por un admin desde el CRM. Notifica al miembro.
     * Idempotente por miembro + plan + día.
     */
    public function notifyMembershipPlanChanged($member, ?string $plan): void
    {
        $this->safe(function () use ($member, $plan): void {
            $planName = $plan && trim($plan) !== '' ? trim($plan) : 'tu plan';
            $name     = $member?->full_name ?? 'Miembro';
            $slug     = \Illuminate\Support\Str::slug($planName) ?: 'plan';

            $this->createMemberNotification($member, [
                'type'        => 'membership',
                'title'       => 'Tu plan fue actualizado',
                'message'     => "Tu plan ahora es {$planName}.",
                'priority'    => 'medium',
                'action_type' => 'membership_detail',
                'action_payload' => array_filter(['plan' => $plan]),
                'metadata'    => ['plan' => $planName, 'member_name' => $name],
                'event_key'   => $member ? "membership_plan_changed_{$member->id}_{$slug}_" . now()->toDateString() : null,
            ]);
        });
    }

    // ── Eventos de CLASE ─────────────────────────────────────────────────────────

    /** Miembro reserva una clase desde la app. Notifica al miembro y al CRM. */
    public function notifyClassReserved($member, $class): void
    {
        $this->safe(function () use ($member, $class): void {
            $classId   = $this->attr($class, 'id');
            $className  = $this->attr($class, 'name') ?? 'una clase';
            $when       = $this->classWhenLabel($class);
            $name       = $member?->full_name ?? 'Miembro';
            $payload    = array_filter(['class_id' => $classId]);
            $meta       = ['class_id' => $classId, 'class' => $className, 'member_name' => $name];

            $this->createMemberNotification($member, [
                'type'        => 'class',
                'title'       => 'Clase reservada',
                'message'     => "Reservaste {$className}" . ($when ? " · {$when}." : '.'),
                'priority'    => 'medium',
                'should_popup' => true,
                'action_type' => 'class_detail',
                'action_payload' => $payload,
                'metadata'    => $meta + ['source_event' => 'class_reserved'],
                'event_key'   => ($member && $classId) ? "class_reserved_{$classId}_{$member->id}" : null,
            ]);

            // Aviso operativo al CRM (sin event_key: puede reservar/cancelar varias veces).
            $this->createAdminNotification([
                'type'        => 'class',
                'title'       => 'Nueva reserva de clase',
                'message'     => "{$name} reservó {$className}" . ($when ? " · {$when}." : '.'),
                'priority'    => 'low',
                'member'      => $member,
                'action_type' => 'class_detail',
                'action_payload' => $payload,
                'metadata'    => $meta,
                'event_key'   => null,
            ]);
        });
    }

    /** Miembro cancela su propia reserva desde la app. Notifica miembro y CRM. */
    public function notifyClassReservationCancelled($member, $class): void
    {
        $this->safe(function () use ($member, $class): void {
            $classId   = $this->attr($class, 'id');
            $className  = $this->attr($class, 'name') ?? 'una clase';
            $name       = $member?->full_name ?? 'Miembro';
            $payload    = array_filter(['class_id' => $classId]);
            $meta       = ['class_id' => $classId, 'class' => $className, 'member_name' => $name];

            $this->createMemberNotification($member, [
                'type'        => 'class',
                'title'       => 'Reserva cancelada',
                'message'     => "Cancelaste tu reserva de {$className}.",
                'priority'    => 'low',
                'action_type' => 'class_detail',
                'action_payload' => $payload,
                'metadata'    => $meta,
                // Sin event_key: una clase puede reservarse/cancelarse varias veces.
                'event_key'   => null,
            ]);

            $this->createAdminNotification([
                'type'        => 'class',
                'title'       => 'Reserva cancelada',
                'message'     => "{$name} canceló su reserva de {$className}.",
                'priority'    => 'low',
                'member'      => $member,
                'action_type' => 'class_detail',
                'action_payload' => $payload,
                'metadata'    => $meta,
                'event_key'   => null,
            ]);
        });
    }

    /**
     * Clase creada desde el CRM. Avisa al CRM y, si admite reservas online,
     * difunde "nueva clase disponible" a todos los miembros (broadcast global).
     */
    public function notifyClassCreated($class): void
    {
        $this->safe(function () use ($class): void {
            $classId   = $this->attr($class, 'id');
            $className  = $this->attr($class, 'name') ?? 'una clase';
            $type       = $this->attr($class, 'type');
            $when       = $this->classWhenLabel($class);
            $payload    = array_filter(['class_id' => $classId]);
            $meta       = array_filter(['class_id' => $classId, 'class' => $className, 'type' => $type]);

            $this->createAdminNotification([
                'type'        => 'class',
                'title'       => 'Clase creada',
                'message'     => "Se creó la clase {$className}" . ($when ? " · {$when}." : '.'),
                'priority'    => 'low',
                'action_type' => 'class_detail',
                'action_payload' => $payload,
                'metadata'    => $meta,
                'event_key'   => $classId ? "admin_class_created_{$classId}" : null,
            ]);

            // Broadcast a miembros solo si la clase es reservable online.
            if ($this->attr($class, 'allow_online_booking')) {
                $this->createMemberNotification(null, [
                    'type'        => 'class',
                    'title'       => 'Nueva clase disponible',
                    'message'     => "Nueva clase: {$className}" . ($when ? " · {$when}." : '.') . ' Reserva tu cupo.',
                    'priority'    => 'low',
                    'action_type' => 'class_detail',
                    'action_payload' => $payload,
                    'metadata'    => $meta,
                    'event_key'   => $classId ? "class_created_broadcast_{$classId}" : null,
                ]);
            }
        });
    }

    /**
     * Clase actualizada desde el CRM (fecha/hora/entrenador/cupo). Notifica a los
     * miembros inscritos y deja copia para el CRM. Idempotente por hash de cambio.
     *
     * @param  iterable<Member>  $members  miembros con reserva activa
     */
    public function notifyClassUpdated($class, iterable $members): void
    {
        $this->safe(function () use ($class, $members): void {
            $classId   = $this->attr($class, 'id');
            $className  = $this->attr($class, 'name') ?? 'tu clase';
            $when       = $this->classWhenLabel($class);
            $hash       = substr(md5((string) ($this->attr($class, 'updated_at') ?? now())), 0, 8);
            $payload    = array_filter(['class_id' => $classId]);

            foreach ($members as $member) {
                $this->createMemberNotification($member, [
                    'type'        => 'class',
                    'title'       => 'Clase actualizada',
                    'message'     => "Tu clase {$className} fue actualizada" . ($when ? " · {$when}." : '. Revisa los nuevos detalles.'),
                    'priority'    => 'medium',
                    'action_type' => 'class_detail',
                    'action_payload' => $payload,
                    'metadata'    => ['class_id' => $classId, 'class' => $className, 'member_name' => $member?->full_name],
                    'event_key'   => ($member && $classId) ? "class_updated_{$classId}_{$member->id}_{$hash}" : null,
                ]);
            }

            $this->createAdminNotification([
                'type'        => 'class',
                'title'       => 'Clase actualizada',
                'message'     => "Se actualizó la clase {$className}.",
                'priority'    => 'low',
                'action_type' => 'class_detail',
                'action_payload' => $payload,
                'metadata'    => array_filter(['class_id' => $classId, 'class' => $className]),
                'event_key'   => $classId ? "admin_class_updated_{$classId}_{$hash}" : null,
            ]);
        });
    }

    /**
     * Clase cancelada/eliminada desde el CRM. Notifica a los miembros inscritos
     * y deja aviso operativo en el CRM.
     *
     * @param  iterable<Member>  $members  miembros con reserva activa
     */
    public function notifyClassCancelled($class, iterable $members = []): void
    {
        $this->safe(function () use ($class, $members): void {
            $classId   = $this->attr($class, 'id');
            $className  = $this->attr($class, 'name') ?? 'una clase';
            $payload    = array_filter(['class_id' => $classId]);

            foreach ($members as $member) {
                $this->createMemberNotification($member, [
                    'type'        => 'class',
                    'title'       => 'Clase cancelada',
                    'message'     => "La clase {$className} fue cancelada. Tu reserva ya no está activa.",
                    'priority'    => 'high',
                    'action_type' => 'class_detail',
                    'action_payload' => $payload,
                    'metadata'    => ['class_id' => $classId, 'class' => $className, 'member_name' => $member?->full_name],
                    'event_key'   => ($member && $classId) ? "class_cancelled_{$classId}_{$member->id}" : null,
                ]);
            }

            $this->createAdminNotification([
                'type'        => 'class',
                'title'       => 'Clase cancelada',
                'message'     => "Se canceló la clase {$className}.",
                'priority'    => 'low',
                'action_type' => 'class_detail',
                'action_payload' => $payload,
                'metadata'    => array_filter(['class_id' => $classId, 'class' => $className]),
                'event_key'   => $classId ? "admin_class_cancelled_{$classId}" : null,
            ]);
        });
    }

    /** Clase sin cupos disponibles. Aviso operativo al CRM. */
    public function notifyClassFull($class): void
    {
        $this->safe(function () use ($class): void {
            $classId   = $this->attr($class, 'id');
            $className  = $this->attr($class, 'name') ?? 'una clase';

            $this->createAdminNotification([
                'type'        => 'class',
                'title'       => 'Clase sin cupos',
                'message'     => "La clase {$className} alcanzó su cupo máximo.",
                'priority'    => 'low',
                'action_type' => 'class_detail',
                'action_payload' => array_filter(['class_id' => $classId]),
                'metadata'    => array_filter(['class_id' => $classId, 'class' => $className]),
                'event_key'   => $classId ? "class_full_{$classId}" : null,
            ]);
        });
    }

    /**
     * Recordatorio de clase próxima (lo dispara el comando programado).
     * Idempotente por miembro + clase + franja horaria concreta.
     */
    public function notifyClassReminder($member, $class, $when = null): void
    {
        $this->safe(function () use ($member, $class, $when): void {
            $classId   = $this->attr($class, 'id');
            $className  = $this->attr($class, 'name') ?? 'tu clase';
            $whenCarbon = $when instanceof Carbon ? $when : ($when ? Carbon::parse($when) : null);
            $whenLabel  = $whenCarbon ? $whenCarbon->format('H:i') : $this->classWhenLabel($class);
            $dateHour   = $whenCarbon ? $whenCarbon->format('Y-m-d_H') : 'soon';

            $this->createMemberNotification($member, [
                'type'        => 'class',
                'title'       => 'Tu clase está por comenzar',
                'message'     => "Recuerda tu clase {$className}" . ($whenLabel ? " a las {$whenLabel}." : '.'),
                'priority'    => 'medium',
                'action_type' => 'class_detail',
                'action_payload' => array_filter(['class_id' => $classId]),
                'metadata'    => ['class_id' => $classId, 'class' => $className, 'member_name' => $member?->full_name],
                'event_key'   => ($member && $classId) ? "class_reminder_{$classId}_{$member->id}_{$dateHour}" : null,
            ]);
        });
    }

    // ── Eventos de RUTINA / IRON IA ───────────────────────────────────────────────

    /** Rutina creada en el CRM (general o asignada). Aviso operativo al admin. */
    public function notifyRoutineCreated($routine): void
    {
        $this->safe(function () use ($routine): void {
            $routineId   = $this->attr($routine, 'id');
            $routineName = $this->attr($routine, 'name') ?? 'una rutina';
            $assignedTo  = $this->attr($routine, 'assigned_member_name');
            $suffix      = ($assignedTo && trim((string) $assignedTo) !== '' && strcasecmp((string) $assignedTo, 'Plantilla general') !== 0)
                ? " · asignada a {$assignedTo}."
                : '.';

            $this->createAdminNotification([
                'type'        => 'routine',
                'title'       => 'Nueva rutina creada',
                'message'     => "Se creó la rutina «{$routineName}»{$suffix}",
                'priority'    => 'low',
                'action_type' => 'routine_detail',
                'action_payload' => array_filter(['routine_id' => $routineId]),
                'metadata'    => array_filter(['routine_id' => $routineId, 'routine' => $routineName, 'member_name' => $assignedTo]),
                'event_key'   => $routineId ? "routine_created_{$routineId}" : null,
            ]);
        });
    }

    public function notifyRoutineAssigned($member, $routine): void
    {
        $this->safe(function () use ($member, $routine): void {
            $routineId   = $this->attr($routine, 'id');
            $routineName = $this->attr($routine, 'name') ?? 'una rutina';
            $name        = $member?->full_name ?? 'Miembro';

            $this->createMemberNotification($member, [
                'type'        => 'routine',
                'title'       => 'Nueva rutina asignada',
                'message'     => "Tu entrenador te asignó la rutina «{$routineName}».",
                'priority'    => 'medium',
                'should_popup' => true,
                'action_type' => 'routine_detail',
                'action_payload' => array_filter(['routine_id' => $routineId]),
                'metadata'    => ['routine_id' => $routineId, 'routine' => $routineName, 'member_name' => $name, 'source_event' => 'routine_assigned'],
                'event_key'   => ($member && $routineId) ? "routine_assigned_{$routineId}_{$member->id}" : null,
            ]);
        });
    }

    /** Rutina del miembro actualizada por su entrenador/admin. */
    public function notifyRoutineUpdated($member, $routine): void
    {
        $this->safe(function () use ($member, $routine): void {
            $routineId   = $this->attr($routine, 'id');
            $routineName = $this->attr($routine, 'name') ?? 'tu rutina';
            $hash        = substr(md5((string) ($this->attr($routine, 'updated_at') ?? now())), 0, 8);

            $this->createMemberNotification($member, [
                'type'        => 'routine',
                'title'       => 'Rutina actualizada',
                'message'     => "Tu rutina «{$routineName}» fue actualizada. Revisa los cambios.",
                'priority'    => 'medium',
                'action_type' => 'routine_detail',
                'action_payload' => array_filter(['routine_id' => $routineId]),
                'metadata'    => ['routine_id' => $routineId, 'routine' => $routineName, 'member_name' => $member?->full_name],
                'event_key'   => ($member && $routineId) ? "routine_updated_{$routineId}_{$member->id}_{$hash}" : null,
            ]);
        });
    }

    /**
     * Rutina completada por el miembro. Felicita al miembro y avisa al CRM.
     * NOTA: requiere un evento real de "completar rutina" en la app para
     * dispararse (hoy no existe ese flujo). Disponible para cuando se implemente.
     */
    /**
     * Entrenamiento guardado / rutina completada. Cada guardado real es un
     * evento NUEVO: el event_key incluye el id de la finalización (o timestamp),
     * así no se bloquean futuros entrenamientos legítimos.
     */
    public function notifyRoutineCompleted($member, $routine, $completionId = null): void
    {
        $this->safe(function () use ($member, $routine, $completionId): void {
            $routineId   = $this->attr($routine, 'id');
            $routineName = $this->attr($routine, 'name') ?? 'tu rutina';
            $name        = $member?->full_name ?? 'Miembro';
            $first       = trim(explode(' ', $name)[0]) ?: $name;
            // Discriminador único por guardado (id de RoutineCompletion o ts).
            $uid         = $completionId ?? now()->timestamp;

            $this->createMemberNotification($member, [
                'type'        => 'routine',
                'title'       => 'Entrenamiento guardado',
                'message'     => "Excelente trabajo, {$first}. Tu progreso quedó registrado.",
                'priority'    => 'medium',
                'should_popup' => true,
                'action_type' => 'routine_detail',
                'action_payload' => array_filter(['routine_id' => $routineId, 'completion_id' => $completionId]),
                'metadata'    => ['routine_id' => $routineId, 'routine' => $routineName, 'member_name' => $name, 'source_event' => 'routine_completed'],
                'event_key'   => ($member && $routineId) ? "routine_completed_{$routineId}_{$member->id}_{$uid}" : null,
            ]);

            $this->createAdminNotification([
                'type'        => 'routine',
                'title'       => 'Entrenamiento guardado',
                'message'     => "{$name} guardó un entrenamiento de «{$routineName}».",
                'priority'    => 'low',
                'member'      => $member,
                'metadata'    => ['routine_id' => $routineId, 'routine' => $routineName, 'member_name' => $name],
                'event_key'   => ($member && $routineId) ? "admin_routine_completed_{$routineId}_{$member->id}_{$uid}" : null,
            ]);
        });
    }

    /**
     * Rutina general/plantilla publicada. Broadcast opcional a miembros.
     * Disponible para uso explícito; no se dispara automáticamente para evitar
     * saturar a todos los miembros en cada plantilla creada.
     */
    public function notifyRoutinePublished($routine): void
    {
        $this->safe(function () use ($routine): void {
            $routineId   = $this->attr($routine, 'id');
            $routineName = $this->attr($routine, 'name') ?? 'una rutina';

            $this->createMemberNotification(null, [
                'type'        => 'routine',
                'title'       => 'Nueva rutina disponible',
                'message'     => "Hay una nueva rutina disponible: «{$routineName}».",
                'priority'    => 'low',
                'action_type' => 'routine_detail',
                'action_payload' => array_filter(['routine_id' => $routineId]),
                'metadata'    => ['routine_id' => $routineId, 'routine' => $routineName],
                'event_key'   => $routineId ? "routine_published_{$routineId}" : null,
            ]);
        });
    }

    /**
     * Rutina eliminada/desactivada en el CRM. Avisa al admin y, si estaba
     * asignada a un miembro, a ese miembro.
     */
    public function notifyRoutineDeleted($routine, $member = null): void
    {
        $this->safe(function () use ($routine, $member): void {
            $routineId   = $this->attr($routine, 'id');
            $routineName = $this->attr($routine, 'name') ?? 'una rutina';
            $date        = now()->toDateString();

            $this->createAdminNotification([
                'type'        => 'routine',
                'title'       => 'Rutina eliminada',
                'message'     => "Se eliminó la rutina «{$routineName}».",
                'priority'    => 'low',
                'member'      => $member,
                'metadata'    => array_filter(['routine_id' => $routineId, 'routine' => $routineName]),
                'event_key'   => $routineId ? "routine_deleted_{$routineId}_{$date}" : null,
            ]);

            if ($member) {
                $this->createMemberNotification($member, [
                    'type'        => 'routine',
                    'title'       => 'Rutina ya no disponible',
                    'message'     => "Tu rutina asignada «{$routineName}» ya no está disponible.",
                    'priority'    => 'low',
                    'metadata'    => ['routine_id' => $routineId, 'routine' => $routineName, 'member_name' => $member->full_name],
                    'event_key'   => $routineId ? "routine_deleted_member_{$routineId}_{$member->id}_{$date}" : null,
                ]);
            }
        });
    }

    // ── Eventos de ENTRENADOR ─────────────────────────────────────────────────────

    /** Entrenador creado en el CRM. Aviso operativo al admin. */
    public function notifyTrainerCreated($trainer): void
    {
        $this->safe(function () use ($trainer): void {
            $trainerId   = $this->attr($trainer, 'id');
            $trainerName = $this->attr($trainer, 'full_name') ?? $this->attr($trainer, 'name') ?? 'un entrenador';
            $specialty   = $this->attr($trainer, 'main_specialty');

            $this->createAdminNotification([
                'type'        => 'trainer',
                'title'       => 'Nuevo entrenador registrado',
                'message'     => "Se registró al entrenador {$trainerName}" . ($specialty ? " ({$specialty})." : '.'),
                'priority'    => 'low',
                'action_type' => 'trainer_detail',
                'action_payload' => array_filter(['trainer_id' => $trainerId]),
                'metadata'    => array_filter(['trainer_id' => $trainerId, 'trainer' => $trainerName, 'specialty' => $specialty]),
                'event_key'   => $trainerId ? "trainer_created_{$trainerId}" : null,
            ]);
        });
    }

    /** Entrenador actualizado en el CRM. Aviso operativo al admin (sin saturar). */
    public function notifyTrainerUpdated($trainer): void
    {
        $this->safe(function () use ($trainer): void {
            $trainerId   = $this->attr($trainer, 'id');
            $trainerName = $this->attr($trainer, 'full_name') ?? $this->attr($trainer, 'name') ?? 'un entrenador';
            $hash        = substr(md5((string) ($this->attr($trainer, 'updated_at') ?? now())), 0, 8);

            $this->createAdminNotification([
                'type'        => 'trainer',
                'title'       => 'Entrenador actualizado',
                'message'     => "Se actualizó la ficha de {$trainerName}.",
                'priority'    => 'low',
                'action_type' => 'trainer_detail',
                'action_payload' => array_filter(['trainer_id' => $trainerId]),
                'metadata'    => array_filter(['trainer_id' => $trainerId, 'trainer' => $trainerName]),
                'event_key'   => $trainerId ? "trainer_updated_{$trainerId}_{$hash}" : null,
            ]);
        });
    }

    /** Entrenador eliminado/desactivado en el CRM. Aviso operativo al admin. */
    public function notifyTrainerDeleted($trainer): void
    {
        $this->safe(function () use ($trainer): void {
            $trainerId   = $this->attr($trainer, 'id');
            $trainerName = $this->attr($trainer, 'full_name') ?? $this->attr($trainer, 'name') ?? 'un entrenador';
            $date        = now()->toDateString();

            $this->createAdminNotification([
                'type'        => 'trainer',
                'title'       => 'Entrenador eliminado',
                'message'     => "Se eliminó al entrenador {$trainerName}.",
                'priority'    => 'low',
                'metadata'    => array_filter(['trainer_id' => $trainerId, 'trainer' => $trainerName]),
                'event_key'   => $trainerId ? "trainer_deleted_{$trainerId}_{$date}" : null,
            ]);
        });
    }

    /** Entrenador asignado a un miembro. Notifica al miembro y al CRM (admin). */
    public function notifyTrainerAssigned($member, $trainer): void
    {
        $this->safe(function () use ($member, $trainer): void {
            $trainerId   = $this->attr($trainer, 'id');
            $trainerName = $this->attr($trainer, 'full_name') ?? $this->attr($trainer, 'name') ?? 'tu entrenador';
            $name        = $member?->full_name ?? 'Miembro';
            $date        = now()->toDateString();
            $payload     = array_filter(['trainer_id' => $trainerId]);

            $this->createMemberNotification($member, [
                'type'        => 'trainer',
                'title'       => 'Entrenador asignado',
                'message'     => "Se te asignó el entrenador {$trainerName}.",
                'priority'    => 'medium',
                'should_popup' => true,
                'action_type' => 'trainer_detail',
                'action_payload' => $payload,
                'metadata'    => ['trainer_id' => $trainerId, 'trainer' => $trainerName, 'member_name' => $name, 'source_event' => 'trainer_assigned'],
                'event_key'   => ($member && $trainerId) ? "trainer_assigned_{$trainerId}_{$member->id}_{$date}" : null,
            ]);

            $this->createAdminNotification([
                'type'        => 'trainer',
                'title'       => 'Entrenador asignado',
                'message'     => "Entrenador {$trainerName} asignado a {$name}.",
                'priority'    => 'low',
                'member'      => $member,
                'action_type' => 'trainer_detail',
                'action_payload' => $payload,
                'metadata'    => ['trainer_id' => $trainerId, 'trainer' => $trainerName, 'member_name' => $name],
                'event_key'   => ($member && $trainerId) ? "admin_trainer_assigned_{$trainerId}_{$member->id}_{$date}" : null,
            ]);
        });
    }

    /** Entrenador retirado de un miembro. Notifica al miembro y al CRM (admin). */
    public function notifyTrainerUnassigned($member, $trainer): void
    {
        $this->safe(function () use ($member, $trainer): void {
            $trainerId   = $this->attr($trainer, 'id');
            $trainerName = $this->attr($trainer, 'full_name') ?? $this->attr($trainer, 'name') ?? 'tu entrenador';
            $name        = $member?->full_name ?? 'Miembro';
            $date        = now()->toDateString();

            $this->createMemberNotification($member, [
                'type'        => 'trainer',
                'title'       => 'Entrenador actualizado',
                'message'     => 'Tu entrenador asignado fue actualizado.',
                'priority'    => 'low',
                'metadata'    => ['trainer_id' => $trainerId, 'trainer' => $trainerName, 'member_name' => $name],
                'event_key'   => ($member && $trainerId) ? "trainer_unassigned_{$trainerId}_{$member->id}_{$date}" : null,
            ]);

            $this->createAdminNotification([
                'type'        => 'trainer',
                'title'       => 'Entrenador retirado',
                'message'     => "Se retiró al entrenador {$trainerName} de {$name}.",
                'priority'    => 'low',
                'member'      => $member,
                'metadata'    => ['trainer_id' => $trainerId, 'trainer' => $trainerName, 'member_name' => $name],
                'event_key'   => ($member && $trainerId) ? "admin_trainer_unassigned_{$trainerId}_{$member->id}_{$date}" : null,
            ]);
        });
    }

    /**
     * Observación/recomendación de un entrenador a un miembro. Disponible para
     * cuando exista un flujo real de notas de entrenador.
     */
    public function notifyTrainerNote($member, $trainer, $note): void
    {
        $this->safe(function () use ($member, $trainer, $note): void {
            $trainerId   = $this->attr($trainer, 'id');
            $trainerName = $this->attr($trainer, 'full_name') ?? $this->attr($trainer, 'name') ?? 'Tu entrenador';
            $noteId      = $this->attr($note, 'id');
            $noteText    = $this->attr($note, 'message') ?? $this->attr($note, 'text') ?? 'tiene una recomendación para ti.';

            $this->createMemberNotification($member, [
                'type'        => 'trainer',
                'title'       => "Mensaje de {$trainerName}",
                'message'     => $noteText,
                'priority'    => 'medium',
                'action_type' => 'trainer_detail',
                'action_payload' => array_filter(['trainer_id' => $trainerId, 'note_id' => $noteId]),
                'metadata'    => ['trainer_id' => $trainerId, 'trainer' => $trainerName, 'note_id' => $noteId],
                'event_key'   => ($member && $trainerId && $noteId) ? "trainer_note_{$trainerId}_{$member->id}_{$noteId}" : null,
            ]);
        });
    }

    // ── PROMOCIÓN / NUEVO MIEMBRO ──────────────────────────────────────────────────

    /** Promoción publicada: broadcast a miembros + copia operativa al CRM. */
    public function notifyPromotionPublished(string $title, string $message, array $extra = []): void
    {
        $this->safe(function () use ($title, $message, $extra): void {
            $slug = $extra['slug'] ?? \Illuminate\Support\Str::slug($title) ?: 'promo';
            $key  = "promotion_{$slug}_" . now()->toDateString();

            $this->createMemberNotification(null, [
                'type'        => 'promotion',
                'title'       => $title,
                'message'     => $message,
                'priority'    => 'low',
                'metadata'    => $extra,
                'event_key'   => $key,
            ]);
        });
    }

    /**
     * Nuevo miembro registrado. Aviso operativo al CRM (admin) + bienvenida de
     * sistema al propio miembro (puebla la categoría Sistema de la app).
     */
    public function notifyNewMemberRegistered($member): void
    {
        $this->safe(function () use ($member): void {
            $name = $member?->full_name ?? 'Nuevo miembro';
            $doc  = $member?->document_number;

            $this->createAdminNotification([
                'type'        => 'system',
                'title'       => 'Nuevo miembro registrado',
                'message'     => "{$name} inició su registro" . ($doc ? " (doc. {$doc})." : '.'),
                'priority'    => 'low',
                'member'      => $member,
                'metadata'    => array_filter(['member_name' => $name, 'document' => $doc]),
                'event_key'   => $member ? "system_new_member_{$member->id}" : null,
            ]);

            // Bienvenida de sistema al miembro (categoría Sistema en la app).
            $this->createMemberNotification($member, [
                'type'        => 'system',
                'title'       => '¡Bienvenido a Iron Body!',
                'message'     => 'Tu cuenta fue creada. Aquí verás avisos del gimnasio, pagos, clases y más.',
                'priority'    => 'low',
                'metadata'    => array_filter(['member_name' => $name]),
                'event_key'   => $member ? "system_welcome_{$member->id}" : null,
            ]);
        });
    }

    // ── MIEMBRO (auditoría CRM) ────────────────────────────────────────────────────

    /** Miembro/usuario creado desde el CRM. Aviso operativo al admin. */
    public function notifyMemberCreated($member, ?string $name = null, ?string $document = null): void
    {
        $this->safe(function () use ($member, $name, $document): void {
            $memberId = $this->attr($member, 'id');
            $name = $name ?? $this->attr($member, 'full_name') ?? $this->attr($member, 'name') ?? 'Nuevo miembro';
            $document = $document ?? $this->attr($member, 'document_number') ?? $this->attr($member, 'document');

            $this->createAdminNotification([
                'type'      => 'system',
                'title'     => 'Miembro creado',
                'message'   => "Se creó al miembro {$name}" . ($document ? " (doc. {$document})." : '.'),
                'priority'  => 'low',
                'member'    => $member instanceof Member ? $member : null,
                'metadata'  => array_filter(['member_name' => $name, 'document' => $document]),
                'event_key' => $memberId ? "member_created_{$memberId}" : null,
            ]);
        });
    }

    /** Miembro/usuario actualizado desde el CRM. Auditoría admin (idempotente por hash). */
    public function notifyMemberUpdated($member, ?string $name = null): void
    {
        $this->safe(function () use ($member, $name): void {
            $memberId = $this->attr($member, 'id');
            $name = $name ?? $this->attr($member, 'full_name') ?? $this->attr($member, 'name') ?? 'Un miembro';
            $hash = substr(md5((string) ($this->attr($member, 'updated_at') ?? now())), 0, 8);

            $this->createAdminNotification([
                'type'      => 'system',
                'title'     => 'Miembro actualizado',
                'message'   => "Se actualizaron los datos de {$name}.",
                'priority'  => 'low',
                'member'    => $member instanceof Member ? $member : null,
                'metadata'  => array_filter(['member_name' => $name]),
                'event_key' => $memberId ? "member_updated_{$memberId}_{$hash}" : null,
            ]);
        });
    }

    /** Miembro/usuario eliminado desde el CRM. Aviso operativo al admin. */
    public function notifyMemberDeleted($member, ?string $name = null, ?string $document = null): void
    {
        $this->safe(function () use ($member, $name, $document): void {
            $memberId = $this->attr($member, 'id');
            $name = $name ?? $this->attr($member, 'full_name') ?? $this->attr($member, 'name') ?? 'Un miembro';
            $document = $document ?? $this->attr($member, 'document_number') ?? $this->attr($member, 'document');
            $date = now()->toDateString();

            $this->createAdminNotification([
                'type'      => 'system',
                'title'     => 'Miembro eliminado',
                'message'   => "Se eliminó al miembro {$name}" . ($document ? " (doc. {$document})." : '.'),
                'priority'  => 'low',
                'metadata'  => array_filter(['member_name' => $name, 'document' => $document]),
                'event_key' => $memberId ? "member_deleted_{$memberId}_{$date}" : null,
            ]);
        });
    }

    /** Membresía cancelada/desactivada. Notifica al miembro y al CRM. */
    public function notifyMembershipCancelled($member, ?string $plan = null): void
    {
        $this->safe(function () use ($member, $plan): void {
            $planName = $plan && trim($plan) !== '' ? trim($plan) : 'tu plan';
            $name     = $member?->full_name ?? 'Miembro';
            $date     = now()->toDateString();

            $this->createMemberNotification($member, [
                'type'        => 'membership',
                'title'       => 'Membresía cancelada',
                'message'     => "Tu plan {$planName} fue cancelado. Contáctanos para reactivarlo.",
                'priority'    => 'high',
                'action_type' => 'membership_renew',
                'metadata'    => ['plan' => $planName, 'member_name' => $name],
                'event_key'   => $member ? "membership_cancelled_{$member->id}_{$date}" : null,
            ]);

            $this->createAdminNotification([
                'type'      => 'membership',
                'title'     => 'Membresía cancelada',
                'message'   => "Se canceló la membresía de {$name} ({$planName}).",
                'priority'  => 'low',
                'member'    => $member,
                'metadata'  => ['plan' => $planName, 'member_name' => $name],
                'event_key' => $member ? "admin_membership_cancelled_{$member->id}_{$date}" : null,
            ]);
        });
    }

    // ── PROMOCIÓN (CRUD) y helpers de SISTEMA/AUDITORÍA ────────────────────────────

    /** Promoción creada en el CRM. Aviso operativo al admin (no difunde aún). */
    public function notifyPromotionCreated(string $title, ?int $promotionId = null, array $extra = []): void
    {
        $this->safe(function () use ($title, $promotionId, $extra): void {
            $this->createAdminNotification([
                'type'      => 'promotion',
                'title'     => 'Promoción creada',
                'message'   => "Se creó la promoción «{$title}».",
                'priority'  => 'low',
                'metadata'  => array_merge(['promotion' => $title], $extra),
                'event_key' => $promotionId ? "promotion_created_{$promotionId}" : null,
            ]);
        });
    }

    /** Promoción actualizada en el CRM. Auditoría admin. */
    public function notifyPromotionUpdated(string $title, ?int $promotionId = null): void
    {
        $this->safe(function () use ($title, $promotionId): void {
            $hash = substr(md5($title . now()), 0, 8);
            $this->createAdminNotification([
                'type'      => 'promotion',
                'title'     => 'Promoción actualizada',
                'message'   => "Se actualizó la promoción «{$title}».",
                'priority'  => 'low',
                'metadata'  => ['promotion' => $title],
                'event_key' => $promotionId ? "promotion_updated_{$promotionId}_{$hash}" : null,
            ]);
        });
    }

    /** Promoción eliminada en el CRM. Auditoría admin. */
    public function notifyPromotionDeleted(string $title, ?int $promotionId = null): void
    {
        $this->safe(function () use ($title, $promotionId): void {
            $date = now()->toDateString();
            $this->createAdminNotification([
                'type'      => 'promotion',
                'title'     => 'Promoción eliminada',
                'message'   => "Se eliminó la promoción «{$title}».",
                'priority'  => 'low',
                'metadata'  => ['promotion' => $title],
                'event_key' => $promotionId ? "promotion_deleted_{$promotionId}_{$date}" : null,
            ]);
        });
    }

    /** Evento de sistema genérico (alias claro sobre notifySystem). */
    public function notifySystemEvent(string $title, string $message, string $audience = Notification::AUDIENCE_ADMIN, array $extra = []): ?Notification
    {
        return $this->notifySystem($title, $message, $audience, $extra);
    }

    /** Evento de auditoría operativa para el CRM (admin). */
    public function notifyAdminAuditEvent(string $title, string $message, array $extra = []): ?Notification
    {
        return $this->safe(function () use ($title, $message, $extra): ?Notification {
            return $this->createAdminNotification(array_merge([
                'type'     => 'system',
                'title'    => $title,
                'message'  => $message,
                'priority' => 'low',
            ], $extra));
        });
    }

    /**
     * Día nutricional registrado (push interno al miembro). DISPONIBLE: requiere
     * un flujo real de persistencia de nutrición en backend para dispararse (hoy
     * la nutrición vive solo en la app). Enganchar donde se guarde el día.
     */
    public function notifyNutritionDayLogged($member, ?string $dateKey = null): void
    {
        $this->safe(function () use ($member, $dateKey): void {
            $date = $dateKey ?? now()->toDateString();

            $this->createMemberNotification($member, [
                'type'         => 'system',
                'title'        => 'Día nutricional registrado',
                'message'      => 'Tu avance de hoy quedó guardado correctamente.',
                'priority'     => 'low',
                'should_popup' => true,
                'metadata'     => ['source_event' => 'nutrition_day_logged', 'member_name' => $member?->full_name],
                'event_key'    => $member ? "nutrition_day_{$member->id}_{$date}" : null,
            ]);
        });
    }

    /**
     * Meta nutricional del día completada (rueda al 100%). Notificación tipo
     * `nutrition` + push interno. Una vez por miembro y día (idempotente);
     * al día siguiente puede volver a notificar.
     */
    public function notifyNutritionGoalCompleted($member, int $percentage = 100, ?string $date = null): void
    {
        $this->safe(function () use ($member, $percentage, $date): void {
            $d     = $date ?? now()->toDateString();
            $name  = $member?->full_name ?? 'Miembro';
            $first = trim(explode(' ', $name)[0]) ?: $name;
            $msg   = $percentage >= 100
                ? 'Llegaste al 100% de tu meta nutricional diaria.'
                : "Excelente, {$first}. Cumpliste tu objetivo nutricional de hoy.";

            $this->createMemberNotification($member, [
                'type'         => 'nutrition',
                'title'        => 'Meta nutricional completada',
                'message'      => $msg,
                'priority'     => 'medium',
                'should_popup' => true,
                'action_type'  => 'nutrition_detail',
                'action_payload' => array_filter(['date' => $d, 'percentage' => $percentage]),
                'metadata'     => ['percentage' => $percentage, 'date' => $d, 'source_event' => 'nutrition_goal_completed', 'member_name' => $name],
                'event_key'    => $member ? "nutrition_goal_completed_{$member->id}_{$d}" : null,
            ]);
        });
    }

    public function notifyIronAiRecommendation($member, $recommendation): void
    {
        $this->safe(function () use ($member, $recommendation): void {
            $recId   = $this->attr($recommendation, 'id');
            $title   = $this->attr($recommendation, 'title') ?? 'Recomendación de IRON IA';
            $message = $this->attr($recommendation, 'message') ?? 'IRON IA tiene una sugerencia para ti.';

            $this->createMemberNotification($member, [
                'type'        => 'iron_ai',
                'title'       => $title,
                'message'     => $message,
                'priority'    => 'medium',
                'should_popup' => true,
                'action_type' => 'iron_ai',
                'action_payload' => array_filter(['recommendation_id' => $recId]),
                'metadata'    => ['recommendation_id' => $recId, 'source_event' => 'iron_ai_recommendation'],
                'event_key'   => ($member && $recId) ? "iron_ai_{$member->id}_{$recId}" : null,
            ]);
        });
    }

    // ── SEGURIDAD / SESIONES ───────────────────────────────────────────────────

    /** Acceso desde un dispositivo nuevo. Push interno + historial + CRM. */
    public function notifyNewDeviceLogin($member, ?string $deviceName = null, ?string $ip = null): void
    {
        $this->safe(function () use ($member, $deviceName, $ip): void {
            $device = $deviceName ?: 'un dispositivo nuevo';
            $name   = $member?->full_name ?? 'Miembro';

            $this->createMemberNotification($member, [
                'type'         => 'security',
                'title'        => 'Nuevo inicio de sesión',
                'message'      => "Detectamos acceso a tu cuenta desde {$device}. Si no fuiste tú, revisa tus dispositivos.",
                'priority'     => 'high',
                'should_popup' => true,
                'action_type'  => 'security_devices',
                'metadata'     => array_filter([
                    'device'       => $deviceName,
                    'ip'           => $ip,
                    'source_event' => 'new_device_login',
                ]),
            ]);

            // Espejo para auditoría en el CRM.
            $this->createAdminNotification([
                'type'        => 'security',
                'title'       => 'Nuevo inicio de sesión de miembro',
                'message'     => "{$name} inició sesión desde {$device}.",
                'priority'    => 'low',
                'member'      => $member,
                'metadata'    => array_filter([
                    'device'      => $deviceName,
                    'ip'          => $ip,
                    'member_name' => $name,
                ]),
            ]);
        });
    }

    /** Se cerró la sesión en otros dispositivos por un inicio nuevo. */
    public function notifyConcurrentSessionRevoked($member, ?string $newDeviceName = null): void
    {
        $this->safe(function () use ($member, $newDeviceName): void {
            $device = $newDeviceName ?: 'un nuevo dispositivo';
            $name   = $member?->full_name ?? 'Miembro';

            $this->createMemberNotification($member, [
                'type'         => 'security',
                'title'        => 'Sesión cerrada en otro dispositivo',
                'message'      => "Tu cuenta se abrió en {$device}. Por seguridad cerramos la sesión en tus otros dispositivos.",
                'priority'     => 'high',
                'should_popup' => true,
                'action_type'  => 'security_devices',
                'metadata'     => array_filter([
                    'device'       => $newDeviceName,
                    'source_event' => 'concurrent_session_revoked',
                ]),
            ]);

            $this->createAdminNotification([
                'type'     => 'security',
                'title'    => 'Relevo de sesión de miembro',
                'message'  => "{$name} abrió sesión en {$device}; se cerró su sesión anterior.",
                'priority' => 'low',
                'member'   => $member,
                'metadata' => array_filter(['device' => $newDeviceName, 'member_name' => $name]),
            ]);
        });
    }

    /**
     * Intento de ingreso concurrente BLOQUEADO (la cuenta ya estaba activa en
     * otro dispositivo principal). Avisa al miembro (lo verá el dispositivo
     * activo) y deja registro de auditoría en el CRM.
     */
    public function notifyConcurrentBlocked($member, ?string $attemptedDeviceName = null, ?string $activeDeviceName = null): void
    {
        $this->safe(function () use ($member, $attemptedDeviceName, $activeDeviceName): void {
            $attempted = $attemptedDeviceName ?: 'otro dispositivo';
            $active     = $activeDeviceName ?: 'tu dispositivo principal';
            $name       = $member?->full_name ?? 'Miembro';

            $this->createMemberNotification($member, [
                'type'         => 'security',
                'title'        => 'Acceso bloqueado por seguridad',
                'message'      => "Bloqueamos un intento de ingreso desde {$attempted}: tu cuenta ya está activa en {$active}.",
                'priority'     => 'high',
                'should_popup' => true,
                'action_type'  => 'security_devices',
                'metadata'     => array_filter([
                    'attempted_device' => $attemptedDeviceName,
                    'active_device'    => $activeDeviceName,
                    'source_event'     => 'concurrent_login_blocked',
                ]),
            ]);

            $this->createAdminNotification([
                'type'     => 'security',
                'title'    => 'Intento de acceso concurrente bloqueado',
                'message'  => "{$name}: se bloqueó un ingreso desde {$attempted} (cuenta ya activa en {$active}).",
                'priority' => 'medium',
                'member'   => $member,
                'metadata' => array_filter([
                    'attempted_device' => $attemptedDeviceName,
                    'active_device'    => $activeDeviceName,
                    'member_name'      => $name,
                ]),
            ]);
        });
    }

    /**
     * Verificación facial fallida (el rostro no coincide con el titular). Avisa
     * al miembro dueño de la cuenta + auditoría CRM. Idempotente por
     * dispositivo y día (no duplica si el mismo equipo reintenta).
     */
    public function notifyFaceMismatch($member, ?string $deviceName = null, ?string $deviceId = null): void
    {
        $this->safe(function () use ($member, $deviceName, $deviceId): void {
            $device = $deviceName ?: 'un dispositivo';
            $name   = $member?->full_name ?? 'Miembro';
            $day    = now()->toDateString();
            $dev    = $deviceId ?: 'unknown';

            $this->createMemberNotification($member, [
                'type'         => 'security',
                'title'        => 'Verificación facial fallida',
                'message'      => "Se intentó acceder a tu cuenta desde {$device} y el rostro no coincidió con el titular.",
                'priority'     => 'high',
                'should_popup' => true,
                'action_type'  => 'security_devices',
                'metadata'     => array_filter(['device' => $deviceName, 'source_event' => 'face_mismatch']),
                'event_key'    => $member ? "face_mismatch_{$member->id}_{$dev}_{$day}" : null,
            ]);

            $this->createAdminNotification([
                'type'      => 'security',
                'title'     => 'Verificación facial fallida',
                'message'   => "{$name}: rostro no coincidente al intentar acceder desde {$device}.",
                'priority'  => 'medium',
                'member'    => $member,
                'metadata'  => array_filter(['device' => $deviceName, 'member_name' => $name]),
                'event_key' => $member ? "admin_face_mismatch_{$member->id}_{$dev}_{$day}" : null,
            ]);
        });
    }

    /**
     * Se intentó iniciar sesión con OTRA cuenta en un equipo asociado a este
     * miembro ("cuenta asociada a otro usuario"). Idempotente por equipo, cuenta
     * intentada y día.
     */
    public function notifyDeviceMismatch($owner, ?string $attemptedDocument = null, ?string $deviceName = null, ?string $deviceId = null): void
    {
        $this->safe(function () use ($owner, $attemptedDocument, $deviceName, $deviceId): void {
            $device = $deviceName ?: 'tu dispositivo';
            $name   = $owner?->full_name ?? 'Miembro';
            $day    = now()->toDateString();
            $dev    = $deviceId ?: 'unknown';
            $doc    = $attemptedDocument ?: 'otra cuenta';

            $this->createMemberNotification($owner, [
                'type'         => 'security',
                'title'        => 'Intento de acceso con otra cuenta',
                'message'      => "Se intentó iniciar sesión con otra cuenta en {$device}. Se bloqueó el acceso.",
                'priority'     => 'high',
                'should_popup' => true,
                'action_type'  => 'security_devices',
                'metadata'     => array_filter(['device' => $deviceName, 'attempted_document' => $attemptedDocument, 'source_event' => 'device_account_mismatch']),
                'event_key'    => $owner ? "device_mismatch_{$owner->id}_{$dev}_{$doc}_{$day}" : null,
            ]);

            $this->createAdminNotification([
                'type'      => 'security',
                'title'     => 'Bloqueo por dispositivo asociado a otro titular',
                'message'   => "Se bloqueó un intento de acceso con doc {$doc} en el equipo de {$name}.",
                'priority'  => 'medium',
                'member'    => $owner,
                'metadata'  => array_filter(['device' => $deviceName, 'attempted_document' => $attemptedDocument, 'member_name' => $name]),
                'event_key' => $owner ? "admin_device_mismatch_{$owner->id}_{$dev}_{$doc}_{$day}" : null,
            ]);
        });
    }

    /** Patrón de acceso inusual (varios dispositivos en poco tiempo). */
    public function notifySuspiciousLogin($member, ?string $detail = null): void
    {
        $this->safe(function () use ($member, $detail): void {
            $this->createMemberNotification($member, [
                'type'         => 'security',
                'title'        => 'Actividad de acceso inusual',
                'message'      => 'Detectamos intentos de acceso inusuales a tu cuenta. Si no fuiste tú, protege tu acceso.',
                'priority'     => 'high',
                'should_popup' => true,
                'action_type'  => 'security_devices',
                'metadata'     => array_filter([
                    'detail'       => $detail,
                    'source_event' => 'suspicious_login',
                ]),
            ]);
        });
    }

    // ── SISTEMA / MANUAL ─────────────────────────────────────────────────────────

    /** Notificación de sistema/anuncio. Por defecto va al CRM (admin). */
    public function notifySystem(string $title, string $message, string $audience = Notification::AUDIENCE_ADMIN, array $extra = []): ?Notification
    {
        return $this->safe(function () use ($title, $message, $audience, $extra): ?Notification {
            return Notification::create(array_merge([
                'audience' => $audience,
                'type'     => 'system',
                'title'    => $title,
                'message'  => $message,
                'priority' => 'medium',
            ], $extra));
        });
    }

    // ── Creadores base (reutilizables, con idempotencia) ───────────────────────────

    /** Crea (o reutiliza por event_key) una notificación para un miembro. */
    public function createMemberNotification(?Member $member, array $attrs): ?Notification
    {
        $base = [
            'audience'  => Notification::AUDIENCE_MEMBER,
            'member_id' => $member?->id,
            'user_id'   => $member?->user_id,
            'document'  => $member?->document_number,
            'priority'  => 'medium',
        ];

        return $this->persist(array_merge($base, $attrs));
    }

    /**
     * Crea (o reutiliza) una notificación para el CRM (admin). Acepta `member`
     * en $attrs para enlazar nombre/documento del miembro y permitir búsqueda.
     */
    public function createAdminNotification(array $attrs): ?Notification
    {
        $member = $attrs['member'] ?? null;
        unset($attrs['member']);

        $base = [
            'audience'  => Notification::AUDIENCE_ADMIN,
            'member_id' => $member?->id,
            'user_id'   => $member?->user_id,
            'document'  => $member?->document_number,
            'priority'  => 'medium',
        ];

        return $this->persist(array_merge($base, $attrs));
    }

    // ── Internos ───────────────────────────────────────────────────────────────────

    /** Inserta con dedup por event_key cuando está presente. */
    private function persist(array $attrs): ?Notification
    {
        $eventKey = $attrs['event_key'] ?? null;

        $notification = $eventKey
            ? Notification::firstOrCreate(['event_key' => $eventKey], $attrs)
            : Notification::create($attrs);

        $this->maybePush($notification);

        return $notification;
    }

    /**
     * Empuja push nativo (FCM) para notificaciones de MIEMBRO recién creadas e
     * importantes (should_popup). `wasRecentlyCreated` garantiza idempotencia:
     * si el event_key ya existía (dedup), NO se vuelve a empujar. Se ejecuta
     * `afterResponse` para no añadir latencia al flujo que la originó.
     */
    private function maybePush(?Notification $notification): void
    {
        if (! $notification || ! $notification->wasRecentlyCreated) {
            return;
        }
        if ($notification->audience !== Notification::AUDIENCE_MEMBER) {
            return;
        }
        if (config('fcm.only_popup', true) && ! $notification->should_popup) {
            return;
        }

        $memberId = $notification->member_id;
        dispatch(function () use ($notification, $memberId): void {
            try {
                $member = $memberId ? Member::find($memberId) : null;
                app(\App\Services\Fcm\FcmService::class)->sendToMember($member, $notification);
            } catch (Throwable $e) {
                Log::warning('FCM: push afterResponse falló', ['error' => $e->getMessage()]);
            }
        })->afterResponse();
    }

    /** Lee una propiedad de un modelo Eloquent o un array de forma segura. */
    private function attr($source, string $key, $default = null)
    {
        if (is_array($source)) {
            return $source[$key] ?? $default;
        }
        if (is_object($source)) {
            return $source->{$key} ?? $default;
        }
        return $default;
    }

    /** Formato de pesos colombianos: 120000 → $120.000. */
    private function formatCop(float $amount): string
    {
        return '$' . number_format($amount, 0, ',', '.');
    }

    /** Etiqueta legible de cuándo es una clase: "Lunes a las 09:30". */
    private function classWhenLabel($class): string
    {
        $day  = $this->attr($class, 'day_of_week');
        $time = $this->attr($class, 'start_time');
        return trim(($day ? "$day " : '') . ($time ? "a las $time" : ''));
    }

    /** Ejecuta una closure y nunca deja que un fallo de notificación rompa el flujo. */
    private function safe(callable $fn)
    {
        try {
            return $fn();
        } catch (Throwable $e) {
            Log::warning('NotificationService: fallo al notificar (ignorado)', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Nuevo story disponible (stories tipo Instagram/WhatsApp).
     *
     * Broadcast a todos los miembros. Idempotente por `event_key` único del
     * story id. La capa SSE recoge el insert; la capa FCM dispatchea push.
     */
    public function notifyStoryCreated($story): void
    {
        $this->safe(function () use ($story): void {
            $storyId = $this->attr($story, 'id');
            $author = $this->attr($story, 'author_name') ?? 'Iron Body';
            $type = $this->attr($story, 'type') ?? 'image';

            $title = 'Nueva story';
            $message = $type === 'video'
                ? "$author publicó un video en stories"
                : "$author publicó una nueva story";

            $payload = array_filter(['story_id' => $storyId]);
            $meta = array_filter([
                'story_id' => $storyId,
                'author' => $author,
                'type' => $type,
            ]);

            // Broadcast (member_id=null en createMemberNotification dispara a todos).
            $this->createMemberNotification(null, [
                'type' => 'story',
                'title' => $title,
                'message' => $message,
                'priority' => 'low',
                'action_type' => 'story_open',
                'action_payload' => $payload,
                'metadata' => $meta,
                'event_key' => $storyId ? "story_created_{$storyId}" : null,
            ]);
        });
    }
}
