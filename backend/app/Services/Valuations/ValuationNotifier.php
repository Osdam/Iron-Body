<?php

namespace App\Services\Valuations;

use App\Models\Member;
use App\Models\Notification;
use App\Models\ValuationAppointment;
use App\Models\ValuationReminder;
use App\Services\NotificationService;
use App\Support\Access\TrainerMemberScope;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

/**
 * Los dos únicos caminos por los que la agenda de valoraciones notifica.
 *
 *  · AL SOCIO: `NotificationService::createMemberNotification` (app + push FCM
 *    si `should_popup`). JAMÁS con un socio nulo: con `member_id` nulo y popup,
 *    el servicio empuja a TODOS los socios (`sendToAllMembers`). Aquí un socio
 *    que no se resuelve es un error, nunca una difusión.
 *  · AL CRM: la bandeja existente (`audience admin`) con
 *    `required_permission = valuations.view_all`, para que no la vea quien no
 *    ve la agenda. Sin `member` enlazado: la fila no lleva el documento.
 *
 * Idempotente por `event_key`: reintentar no duplica la fila ni el push.
 */
final class ValuationNotifier
{
    public const REQUIRED_PERMISSION = ValuationAccess::VIEW_ALL;

    public const TYPE = 'valuation';

    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * Aviso al socio. Lanza si no hay socio: el llamante lo registra como
     * omitido, nunca lo «arregla» enviando sin destinatario.
     */
    public function toMember(?Member $socio, ValuationAppointment $cita, string $kind, CarbonInterface $ahora): Notification
    {
        if (! $socio instanceof Member || ! $socio->getKey()) {
            throw new LogicException('Aviso de valoración sin socio: no se envía (sería una difusión a todos).');
        }

        $texto = ValuationMessages::forReminder($kind, $cita, $ahora);

        $n = $this->notifications->createMemberNotification($socio, [
            'type' => self::TYPE,
            'title' => $texto['title'],
            'message' => $texto['message'],
            'priority' => 'medium',
            'should_popup' => in_array($kind, [
                ValuationReminder::KIND_REMINDER_LONG,
                ValuationReminder::KIND_REMINDER_SHORT,
                ValuationReminder::KIND_CANCELLATION,
            ], true),
            'metadata' => ['source_event' => 'valuation_'.$kind, 'appointment_uuid' => $cita->uuid],
            'event_key' => self::eventKey($cita, $kind),
        ]);

        if (! $n instanceof Notification) {
            throw new LogicException('La notificación al socio no quedó registrada.');
        }

        return $n;
    }

    /** Aviso en la bandeja del CRM (recordatorios internos). */
    public function toCrm(ValuationAppointment $cita, string $kind, CarbonInterface $ahora): Notification
    {
        $texto = ValuationMessages::forReminder($kind, $cita, $ahora);

        return $this->admin($cita, $texto, self::eventKey($cita, $kind), 'high', $kind);
    }

    /**
     * Aviso INMEDIATO en la bandeja del CRM: creada, reprogramada, cancelada,
     * confirmada. Nunca rompe la operación que lo origina (ya está confirmada
     * cuando se llama): si falla, queda en el log sin el contenido.
     */
    public function internalEvent(ValuationAppointment $cita, string $accion): void
    {
        try {
            $cita->loadMissing(['member' => fn ($q) => $q->withoutGlobalScope(TrainerMemberScope::NAME), 'trainer']);
            $texto = ValuationMessages::forInternalEvent($accion, $cita);
            $this->admin($cita, $texto, 'valuation:'.$cita->uuid.':crm_'.$accion.':v'.$cita->version, 'medium', $accion);
        } catch (Throwable $e) {
            Log::warning('valuations.internal_event_failed', [
                'appointment_uuid' => $cita->uuid,
                'action' => $accion,
                'error' => $e::class,
            ]);
        }
    }

    public static function eventKey(ValuationAppointment $cita, string $kind): string
    {
        return 'valuation:'.$cita->uuid.':'.$kind.':v'.$cita->version;
    }

    /** @param  array{title: string, message: string}  $texto */
    private function admin(ValuationAppointment $cita, array $texto, string $eventKey, string $prioridad, string $origen): Notification
    {
        $n = $this->notifications->createAdminNotification([
            'type' => self::TYPE,
            'title' => $texto['title'],
            'message' => $texto['message'],
            'priority' => $prioridad,
            'required_permission' => self::REQUIRED_PERMISSION,
            'action_type' => 'valuation_detail',
            'action_payload' => ['appointment_uuid' => $cita->uuid],
            'metadata' => ['source_event' => 'valuation_'.$origen, 'appointment_uuid' => $cita->uuid],
            'event_key' => $eventKey,
        ]);

        if (! $n instanceof Notification) {
            throw new LogicException('La notificación del CRM no quedó registrada.');
        }

        return $n;
    }
}
