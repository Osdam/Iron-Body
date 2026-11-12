<?php

namespace App\Observers\Commercial;

use App\Models\MarketingAiAction;
use App\Models\Member;
use App\Services\Commercial\CommercialEventRecorder;
use App\Services\Commercial\CommercialSubjectResolver;
use App\Services\Commercial\CommercialVocabulary as V;
use App\Services\Observability\ChannelLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * La queja que la IA detecta en una conversación.
 *
 * Una queja no hace que el motor actúe: hace que el contacto automático se
 * calle durante un tiempo (el H9 la lee en N6, `complaint_recent`). Hasta este
 * observador nadie registraba `complaint_created`, así que ninguna estrategia
 * podía excluir a quien se quejó.
 *
 * Se engancha a la fila de `marketing_ai_actions`, no a la conversación. El
 * agente de ventas marca la conversación con `update()` del constructor de
 * consultas, que no dispara eventos de Eloquent. La revisión pendiente,
 * además, es un único valor que la siguiente sobrescribe. La fila de la IA, en
 * cambio, es una por turno, histórica, y se crea con `MarketingAiAction::create`.
 *
 * QUÉ CUENTA COMO QUEJA: `reason === 'complaint'` (el motivo de la revisión de
 * ULTRON y del agente de ventas) o `metadata.intent === 'complaint'` (la
 * intención del turno). Es la clasificación del modelo, sin corroborar. Un
 * falso positivo solo silencia el contacto durante la ventana: es el lado
 * seguro.
 *
 * QUÉ SE GUARDA: la persona, la conversación y la fila de la IA. Nunca el
 * texto del mensaje, ni el motivo libre, ni el teléfono. `source` es un código
 * cerrado (`ultron` o `sales_agent`), derivado de las columnas de la fila y no
 * de su metadata libre.
 *
 * Una queja al día de Bogotá por conversación (o por prospecto, si la fila no
 * trae conversación). La ventana del H9 cuenta desde la última.
 *
 * DESPUÉS DEL COMMIT de quien crea la fila (`DB::afterCommit`), nunca dentro
 * de su transacción. En PostgreSQL una sentencia que falla dentro de una
 * transacción la deja abortada (25P02) aunque PHP se trague la excepción, y
 * `CommercialEventRecorder` se traga a propósito la violación de unicidad de
 * `dedupe_key` (dos turnos de la misma conversación con queja el mismo día):
 * registrar dentro dejaría mudo el resto del turno. Si esa transacción se
 * revierte, la fila no existe y la queja no se registra. Sin transacción
 * abierta, se registra en el acto.
 */
class MarketingAiActionCommercialObserver
{
    /** El motivo y la intención con los que la IA marca una queja. */
    public const COMPLAINT = 'complaint';

    public function __construct(
        private readonly CommercialEventRecorder $recorder,
        private readonly CommercialSubjectResolver $resolver,
    ) {}

    public function created(MarketingAiAction $action): void
    {
        if (! (bool) config('commercial.events_enabled', false)) {
            return;
        }

        if ($action->lead_id === null || ! self::isComplaint($action)) {
            return;
        }

        // Lo que hace falta se fija AHORA, en el momento de la fila: el
        // registro corre después del commit y no relee el modelo.
        $actionId = (int) $action->id;
        $leadId = (int) $action->lead_id;
        $conversacion = $action->conversation_id !== null ? (int) $action->conversation_id : null;
        $source = $action->source_type !== null ? 'ultron' : 'sales_agent';
        $cuando = Carbon::now();
        // El día del gimnasio, no el del servidor: una queja a las 20:00 de
        // Bogotá y otra a la mañana siguiente son dos días distintos.
        $dia = $cuando->copy()->setTimezone(Member::BUSINESS_TZ)->format('Ymd');

        DB::afterCommit(function () use ($actionId, $leadId, $conversacion, $source, $cuando, $dia): void {
            try {
                $this->record($actionId, $leadId, $conversacion, $source, $cuando, $dia);
            } catch (\Throwable $e) {
                // Contención deliberada, DENTRO del callback: una excepción aquí
                // le llegaría a quien llamó a `DB::transaction` con sus datos ya
                // confirmados, y sin transacción se ejecuta en el acto dentro de
                // `MarketingAiAction::create`, que ULTRON envuelve en un
                // `try/catch (QueryException)` que la traduciría a «esta decisión
                // ya se ejecutó» (UltronCommitService). Que se pierda el registro
                // de una queja es menor; lo deja escrito el log.
                ChannelLog::error('commercial.complaint.record_failed', [
                    'ai_action_id' => $actionId,
                    'exception' => class_basename($e),
                ]);
            }
        });
    }

    /** ¿Marca esta fila una queja? */
    public static function isComplaint(MarketingAiAction $action): bool
    {
        $intencion = is_array($action->metadata) ? ($action->metadata['intent'] ?? null) : null;

        return $action->reason === self::COMPLAINT || $intencion === self::COMPLAINT;
    }

    private function record(int $actionId, int $leadId, ?int $conversacion, string $source, Carbon $cuando, string $dia): void
    {
        $ambito = $conversacion !== null ? "conversation:{$conversacion}" : "lead:{$leadId}";

        $this->recorder->record(
            event: V::EV_COMPLAINT_CREATED,
            subject: $this->resolver->fromLead($leadId) + ['opportunity_id' => null],
            payload: [
                'source' => $source,
                'conversation_id' => $conversacion,
                'ai_action_id' => $actionId,
            ],
            dedupeKey: "{$ambito}:complaint:{$dia}",
            // La hora de la fila, la misma con la que se calculó el día de la clave.
            occurredAt: $cuando,
        );
    }
}
