<?php

namespace App\Services\Valuations;

use App\Models\Member;
use App\Models\Notification;
use App\Models\ProfessionalAssessment;
use App\Models\Trainer;
use App\Models\ValuationAppointment;
use App\Services\NotificationService;
use App\Support\Access\TrainerMemberScope;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
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
 *
 * TODO aviso al socio de una cita es PUSH (`should_popup`) y abre la pantalla
 * de Valoración física (`action_type=route`, `action_url=/evaluation`), según
 * CONTRATO_SOLICITUDES_VALORACION.md §4. La única excepción la decide el
 * procesador: en horas de silencio, si ya no sirve posponerlo, sale en la app
 * SIN push (contrato del ciclo §6). Que `should_popup=false` no empuje depende
 * de `fcm.only_popup` (por defecto true): con FCM_ONLY_POPUP=false,
 * NotificationService empuja toda notificación de socio.
 */
final class ValuationNotifier
{
    public const REQUIRED_PERMISSION = ValuationAccess::VIEW_ALL;

    public const TYPE = 'valuation';

    /** La pantalla de la app que abre el aviso de una cita (Valoración física). */
    public const MEMBER_ROUTE = '/evaluation';

    /** `source_event` de los avisos del envío de una valoración (contrato del ciclo §1). */
    public const SOURCE_COMPLETED = 'valuation_completed';

    public const SOURCE_UNLINKED = 'valuation_assessment_unlinked';

    public const SOURCE_REVIEW = 'valuation_assessment_review';

    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * Aviso al socio. Lanza si no hay socio: el llamante lo registra como
     * omitido, nunca lo «arregla» enviando sin destinatario.
     *
     * @param  bool  $conPush  false solo en horas de silencio cuando posponerlo ya no sirve (contrato del ciclo §6)
     */
    public function toMember(?Member $socio, ValuationAppointment $cita, string $kind, CarbonInterface $ahora, bool $conPush = true): Notification
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
            'should_popup' => $conPush,
            // La tabla `notifications` no tiene `action_route`: el destino de
            // una notificación de socio va en `action_type=route` + `action_url`
            // (mismo patrón que la valoración profesional, `/assessment`).
            'action_type' => 'route',
            'action_url' => self::MEMBER_ROUTE,
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

    /**
     * El resultado del ENVÍO de una valoración profesional en la bandeja del
     * CRM (contrato del ciclo §1): `valuation_completed`,
     * `valuation_assessment_unlinked` o `valuation_assessment_review`.
     *
     * Se llama DESPUÉS del commit y nunca rompe el envío (ya confirmado): si
     * falla, queda en el log sin el contenido. La escritura va en su propia
     * transacción (o punto de guardado, si hubiera una abierta): en PostgreSQL
     * un INSERT fallido dentro de una transacción ajena la dejaría abortada.
     *
     * Sin medidas ni datos físicos: nombres cortos, fecha y, si hay, la cita.
     * Una valoración que ya cerró una cita (`already_linked`) no avisa: en el
     * envío no ocurre, y la conciliación no avisa nunca.
     *
     * @param  array{outcome: string, appointment: ValuationAppointment|null, candidates: int}  $emparejamiento  de ValuationAgenda::completeFromAssessment()
     */
    public function assessmentOutcome(ProfessionalAssessment $valoracion, array $emparejamiento): void
    {
        $resultado = $emparejamiento['outcome'];
        try {
            if ($resultado === ValuationAgenda::MATCH_ALREADY_LINKED) {
                return;
            }

            $cita = $emparejamiento['appointment'];
            $socio = Member::query()->withoutGlobalScope(TrainerMemberScope::NAME)->find($valoracion->member_id);
            $entrenador = Trainer::query()->find($valoracion->trainer_id);
            $texto = ValuationMessages::forAssessmentOutcome(
                $resultado,
                $valoracion->submitted_at,
                $entrenador?->full_name,
                $socio?->full_name,
                $resultado === ValuationAgenda::MATCH_DIFFERENT_TRAINER ? $cita?->trainer()->value('full_name') : null,
                (int) $emparejamiento['candidates'],
            );

            $base = [
                'member_id' => (int) $valoracion->member_id,
                'assessment_uuid' => $valoracion->uuid,
            ];
            [$origen, $accion, $carga, $clave] = match ($resultado) {
                ValuationAgenda::MATCH_LINKED => [
                    self::SOURCE_COMPLETED, 'valuation_detail', ['appointment_uuid' => $cita->uuid], 'completed',
                ],
                ValuationAgenda::MATCH_NONE => [
                    self::SOURCE_UNLINKED, 'valuation_member', $base, 'unlinked',
                ],
                ValuationAgenda::MATCH_DIFFERENT_TRAINER => [
                    self::SOURCE_REVIEW, 'valuation_member', $base + ['appointment_uuid' => $cita->uuid, 'reason' => $resultado], 'review',
                ],
                ValuationAgenda::MATCH_AMBIGUOUS => [
                    self::SOURCE_REVIEW, 'valuation_member', $base + ['reason' => $resultado], 'review',
                ],
            };

            $metadata = array_filter([
                'source_event' => $origen,
                'member_id' => (int) $valoracion->member_id,
                'trainer_id' => (int) $valoracion->trainer_id,
                'assessment_uuid' => $valoracion->uuid,
                'appointment_uuid' => $cita?->uuid,
                'reason' => $origen === self::SOURCE_REVIEW ? $resultado : null,
            ], fn ($v) => $v !== null);

            DB::transaction(fn () => $this->notifications->createAdminNotification([
                'type' => self::TYPE,
                'title' => $texto['title'],
                'message' => $texto['message'],
                'priority' => 'medium',
                'required_permission' => self::REQUIRED_PERMISSION,
                'action_type' => $accion,
                'action_payload' => $carga,
                'metadata' => $metadata,
                'event_key' => 'valuation:assessment:'.$valoracion->uuid.':'.$clave,
            ]));
        } catch (Throwable $e) {
            Log::warning('valuations.assessment_notice_failed', [
                'assessment_uuid' => $valoracion->uuid,
                'outcome' => $resultado,
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
