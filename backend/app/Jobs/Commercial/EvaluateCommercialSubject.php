<?php

namespace App\Jobs\Commercial;

use App\Models\CommercialEvent;
use App\Models\MarketingLead;
use App\Models\Member;
use App\Services\Commercial\CommercialSubject;
use App\Services\Commercial\NextBestActionEngine;
use App\Services\Commercial\OpportunityReconciler;
use App\Services\Observability\ChannelLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;

/**
 * El ciclo completo que dispara un hecho: cerrar lo cumplido, recalcular, y
 * elegir el objetivo siguiente.
 *
 * Corre fuera de la petición a propósito. El hecho que lo origina suele ocurrir
 * dentro de la transacción de un pago o de un webhook con presupuesto de
 * milisegundos; ahí no cabe recalcular segmentos ni recorrer doce reglas.
 *
 * El orden importa y no es arbitrario:
 *
 *   1. Reconciliar — cerrar lo que los hechos ya dan por cumplido. Va primero
 *      porque si no, el motor vuelve a proponer el objetivo que se acaba de
 *      conseguir: la persona pagaría y recibiría un recordatorio de pago.
 *   2. Evaluar — con las oportunidades muertas ya fuera, elegir el siguiente
 *      mejor objetivo. Aquí es donde «ninguna venta termina la relación»
 *      pasa de principio a código: cerrar en el paso 1 es lo que deja sitio
 *      para que el paso 2 encuentre el objetivo que viene después de la venta.
 *   3. Marcar evaluado — al final, para que un fallo intermedio deje el evento
 *      pendiente y la corrida programada lo reintente.
 */
class EvaluateCommercialSubject implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(public readonly int $commercialEventId)
    {
        // P4: una analítica comercial no puede retrasar el mensaje de nadie.
        $lane = (array) config('queue.lanes.commercial');
        $this->onQueue($lane['queue'] ?? 'commercial');
    }

    public function handle(
        NextBestActionEngine $engine,
        OpportunityReconciler $reconciler,
    ): void {
        // El flag se comprueba AQUÍ y no solo al encolar: un job puede haber
        // quedado en la cola desde antes de que alguien apagara el motor.
        if (! (bool) config('commercial.enabled')) {
            return;
        }

        $event = CommercialEvent::find($this->commercialEventId);

        if ($event === null || $event->evaluated_at !== null) {
            return; // borrado, o ya lo evaluó otra corrida
        }

        if ($event->correlation_id) {
            Context::add('correlation_id', $event->correlation_id);
        }

        $lead = $event->marketing_lead_id ? MarketingLead::find($event->marketing_lead_id) : null;
        $member = $event->member_id ? Member::find($event->member_id) : null;

        if ($lead === null && $member === null) {
            $event->forceFill(['evaluated_at' => now()])->save();

            return;
        }

        // Un candado por PERSONA, no por evento. Dos hechos simultáneos sobre la
        // misma persona —el pago se aprueba y la membresía se activa en el mismo
        // segundo— llegarían a dos workers a la vez y cada uno abriría su propia
        // oportunidad para el mismo objetivo. El candado los serializa: uno
        // decide, el otro ve el mundo ya actualizado y confirma.
        $lock = Cache::lock($this->lockKey($lead, $member), 30);

        $lock->block(15, function () use ($event, $lead, $member, $engine, $reconciler): void {
            $subject = CommercialSubject::build($lead, $member);

            $closed = $reconciler->reconcile($subject);

            // Se reconstruye el sujeto si algo se cerró: la decisión siguiente
            // tiene que ver el mundo posterior al cierre, no el anterior.
            if ($closed !== []) {
                $subject = CommercialSubject::build(
                    $lead?->fresh(),
                    $member?->fresh(),
                );
            }

            $opportunity = $engine->evaluate($subject, $event->correlation_id);

            $event->forceFill([
                'evaluated_at' => now(),
                'commercial_opportunity_id' => $event->commercial_opportunity_id ?? $opportunity?->id,
            ])->save();

            ChannelLog::info('commercial.event.evaluated', [
                'commercial_event_id' => $event->id,
                'event' => $event->event,
                'closed' => count($closed),
                'opportunity_id' => $opportunity?->id,
                'goal' => $opportunity?->goal,
            ]);
        });
    }

    /**
     * Si el candado no se consigue, el evento queda sin evaluar y la corrida
     * programada lo recoge. Fallar ruidosamente aquí llenaría failed_jobs de
     * ruido por algo que se resuelve solo.
     */
    public function failed(\Throwable $e): void
    {
        ChannelLog::error('commercial.event.evaluation_failed', [
            'commercial_event_id' => $this->commercialEventId,
            'exception' => class_basename($e),
            'message' => $e->getMessage(),
        ]);
    }

    private function lockKey(?MarketingLead $lead, ?Member $member): string
    {
        // La persona se identifica por su ficha de miembro siempre que exista,
        // INCLUIDA la que cuelga del lead ya convertido. Un hecho que llega con
        // el lead y otro que llega con el miembro son hechos sobre la misma
        // persona; con claves distintas cada uno tomaría su propio candado y
        // volvería a entrar justo la concurrencia que esto evita.
        $memberId = $member?->id ?? $lead?->member_id;

        return $memberId !== null
            ? "commercial:subject:member:{$memberId}"
            : "commercial:subject:lead:{$lead?->id}";
    }
}
