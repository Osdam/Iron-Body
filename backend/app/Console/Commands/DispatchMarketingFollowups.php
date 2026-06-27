<?php

namespace App\Console\Commands;

use App\Models\MarketingCall;
use App\Models\MarketingFollowup;
use App\Models\MarketingLead;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Despacha los seguimientos comerciales vencidos (marketing_followups).
 *
 * SEGURO Y ADITIVO:
 *   - INERTE por defecto: si el agente no está habilitado
 *     (marketing.agent_enabled) o el despacho está apagado
 *     (marketing.followups.dispatch_enabled), SOLO reporta lo que haría; no
 *     envía mensajes ni inicia llamadas reales.
 *   - Respeta do_not_contact: cancela el seguimiento (no se vuelve a intentar).
 *   - Idempotente y anti-duplicados: cada fila se procesa con lockForUpdate y se
 *     revalida `pending` dentro de la transacción; las llamadas se materializan
 *     con firstOrCreate por followup (no duplica MarketingCall).
 *   - NO activa membresías ni toca pagos.
 *
 * Hoy materializa la ESTRUCTURA de "llamar en 2 horas" (crea MarketingCall sin
 * contactar a Twilio todavía — Fase 6). El envío real de mensajes a Meta queda
 * para la fase viva (send-message / n8n + META_ENABLED).
 */
class DispatchMarketingFollowups extends Command
{
    protected $signature = 'marketing:dispatch-followups
        {--limit= : Máximo de seguimientos a procesar (default: config)}
        {--force : Procesa aunque el agente/despacho estén deshabilitados}';

    protected $description = 'Procesa seguimientos comerciales vencidos (idempotente, respeta do_not_contact).';

    public function handle(): int
    {
        $limit = (int) ($this->option('limit') ?: config('marketing.followups.batch_limit', 100));
        $limit = max(1, $limit);

        $dispatchEnabled = $this->option('force')
            || ((bool) config('marketing.agent_enabled', false)
                && (bool) config('marketing.followups.dispatch_enabled', false));

        $due = MarketingFollowup::query()
            ->where('status', MarketingFollowup::STATUS_PENDING)
            ->whereNotNull('due_at')
            ->where('due_at', '<=', now())
            ->orderBy('due_at')
            ->limit($limit)
            ->pluck('id');

        $stats = ['due' => $due->count(), 'dispatched' => 0, 'calls' => 0, 'held' => 0, 'skipped_dnc' => 0];

        if (! $dispatchEnabled) {
            $this->info(sprintf(
                'marketing:dispatch-followups → INERTE (agente/despacho off). Vencidos: %d. Usa --force o habilita los flags.',
                $stats['due'],
            ));
            return self::SUCCESS;
        }

        foreach ($due as $id) {
            DB::transaction(function () use ($id, &$stats): void {
                /** @var MarketingFollowup|null $followup */
                $followup = MarketingFollowup::lockForUpdate()->find($id);
                // Otra corrida ya lo tomó (anti-duplicado).
                if ($followup === null || $followup->status !== MarketingFollowup::STATUS_PENDING) {
                    return;
                }

                $lead = MarketingLead::find($followup->lead_id);

                // Respeta do_not_contact: cancela, no reintenta.
                if ($lead !== null && ! $lead->isContactable()) {
                    $followup->update(['status' => MarketingFollowup::STATUS_CANCELLED]);
                    $stats['skipped_dnc']++;
                    return;
                }

                if ($followup->type === 'call') {
                    $this->materializeCall($followup, $lead);
                    $followup->update(['status' => MarketingFollowup::STATUS_DONE]);
                    $stats['calls']++;
                    $stats['dispatched']++;
                    return;
                }

                // message / task: el envío vivo es de otra fase (n8n + META_ENABLED).
                // No marcamos done para no "perder" el seguimiento; queda pendiente.
                $stats['held']++;
            });
        }

        $this->info(sprintf(
            'marketing:dispatch-followups → vencidos: %d · despachados: %d (llamadas: %d) · retenidos: %d · cancelados(DNC): %d',
            $stats['due'], $stats['dispatched'], $stats['calls'], $stats['held'], $stats['skipped_dnc'],
        ));

        return self::SUCCESS;
    }

    /**
     * Crea la intención de llamada (estructura para Twilio Voice — Fase 6) de
     * forma idempotente por seguimiento. NO contacta a Twilio aún.
     */
    private function materializeCall(MarketingFollowup $followup, ?MarketingLead $lead): void
    {
        MarketingCall::firstOrCreate(
            ['marketing_followup_id' => $followup->id],
            [
                'marketing_lead_id' => $followup->lead_id,
                'provider'          => 'twilio',
                'status'            => MarketingCall::STATUS_PENDING,
                'direction'         => MarketingCall::DIRECTION_OUTBOUND,
                'to_phone'          => $lead?->phone,
                'reason'            => 'followup',
                'scheduled_at'      => $followup->due_at,
            ],
        );

        Log::info('marketing.followup.call_scheduled', [
            'followup_id' => $followup->id,
            'lead_id'     => $followup->lead_id,
        ]);
    }
}
