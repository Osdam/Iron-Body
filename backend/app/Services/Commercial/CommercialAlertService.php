<?php

namespace App\Services\Commercial;

use App\Models\CommercialAlert;
use App\Services\Observability\ChannelLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Detecta y mantiene las alertas comerciales.
 *
 * Todas las reglas son deterministas: una alerta se abre porque una consulta
 * devuelve filas, no porque un modelo opine. Una alerta generada por una IA que
 * después nadie puede comprobar acaba ignorada, y una bandeja que se ignora es
 * peor que no tenerla.
 *
 * Dos comportamientos que la hacen usable, y sin los cuales sobraría:
 *
 *  · **Deduplicación por huella.** La evaluación corre cada cierto tiempo; sin
 *    huella, un pago pendiente durante un día abriría noventa y seis alertas.
 *    Se actualiza la existente y se suma evidencia.
 *
 *  · **Cierre automático solo cuando se puede demostrar.** Si el pago entró o
 *    alguien contestó, la alerta se cierra sola y queda dicho por qué. Lo que
 *    no se puede demostrar se deja abierto para que lo mire una persona: cerrar
 *    por si acaso es exactamente cómo se pierde un cliente sin enterarse.
 *
 * Nada de esto escribe a nadie. Detectar no es actuar.
 */
class CommercialAlertService
{
    /**
     * Evalúa todas las reglas. Devuelve cuántas quedaron abiertas.
     *
     * @return array{opened:int,updated:int,closed:int}
     */
    public function evaluate(): array
    {
        $before = CommercialAlert::query()->whereIn('status', CommercialAlert::OPEN_STATUSES)->count();

        $rules = [
            'detectPendingPayments',
            'detectRepeatedDeclines',
            'detectUnansweredConversations',
            'detectExpiredOpportunities',
            'detectOutdatedAds',
        ];

        foreach ($rules as $rule) {
            try {
                $this->{$rule}();
            } catch (Throwable $e) {
                // Una regla rota no puede llevarse las demás por delante.
                ChannelLog::warning('alerts.rule_failed', [
                    'rule' => $rule,
                    'error_class' => class_basename($e),
                ]);
            }
        }

        $closed = $this->autoCloseResolved();
        $after = CommercialAlert::query()->whereIn('status', CommercialAlert::OPEN_STATUSES)->count();

        return ['opened' => max(0, $after - $before + $closed), 'updated' => 0, 'closed' => $closed];
    }

    // ── Reglas ──────────────────────────────────────────────────────────

    /** Alguien dio a pagar y su pago lleva horas sin resolverse. */
    private function detectPendingPayments(): void
    {
        if (! Schema::hasTable('payment_transactions')) {
            return;
        }

        $rows = DB::table('payment_transactions as p')
            ->leftJoin('marketing_leads as l', 'l.member_id', '=', 'p.member_id')
            ->where('p.status', 'pending')
            ->where('p.created_at', '<=', now()->subHours(2))
            ->where('p.created_at', '>=', now()->subDays(7))
            ->select('p.id', 'p.reference', 'p.amount', 'p.member_id', 'p.created_at', 'l.id as lead_id')
            ->limit(100)
            ->get();

        foreach ($rows as $row) {
            $this->upsert([
                'type' => CommercialAlert::TYPE_PAYMENT_PENDING,
                'severity' => CommercialAlert::SEVERITY_HIGH,
                'fingerprint' => 'payment_pending:'.$row->id,
                'title' => 'Pago a medias sin resolver',
                'summary' => 'Una persona inició un pago y todavía no se ha confirmado. '
                    .'Puede estar creyendo que ya pagó.',
                'suggested_action' => 'Comprobar el estado en la pasarela y avisar a la persona.',
                'marketing_lead_id' => $row->lead_id,
                'member_id' => $row->member_id,
                'opportunity_value' => (float) $row->amount,
                'due_at' => now()->addHours(6),
                'evidence' => ['reference' => $row->reference, 'created_at' => (string) $row->created_at],
            ]);
        }
    }

    /** El mismo medio de pago fallando una y otra vez. */
    private function detectRepeatedDeclines(): void
    {
        if (! Schema::hasTable('payment_transactions')) {
            return;
        }

        $rows = DB::table('payment_transactions as p')
            ->leftJoin('marketing_leads as l', 'l.member_id', '=', 'p.member_id')
            ->where('p.status', 'declined')
            ->where('p.created_at', '>=', now()->subDays(3))
            ->whereNotNull('p.member_id')
            ->groupBy('p.member_id', 'l.id')
            ->havingRaw('COUNT(*) >= 3')
            ->select('p.member_id', 'l.id as lead_id', DB::raw('COUNT(*) as intentos'))
            ->limit(50)
            ->get();

        foreach ($rows as $row) {
            $this->upsert([
                'type' => CommercialAlert::TYPE_REPEATED_DECLINE,
                'severity' => CommercialAlert::SEVERITY_MEDIUM,
                'fingerprint' => 'repeated_decline:member:'.$row->member_id,
                'title' => 'Pagos rechazados varias veces',
                'summary' => 'Esta persona lo ha intentado '.$row->intentos.' veces sin éxito. '
                    .'Probablemente sea su medio de pago, y desde fuera parece un problema nuestro.',
                'suggested_action' => 'Ofrecer otro medio de pago o ayudar por WhatsApp.',
                'member_id' => $row->member_id,
                'marketing_lead_id' => $row->lead_id,
                'evidence' => ['attempts' => (int) $row->intentos],
                'due_at' => now()->addDay(),
            ]);
        }
    }

    /** Escribió y nadie le contestó: ni la IA ni una persona. */
    private function detectUnansweredConversations(): void
    {
        $rows = DB::table('marketing_conversations')
            ->where('status', 'open')
            ->whereNotNull('last_inbound_at')
            ->where('last_inbound_at', '<=', now()->subHours(4))
            ->where('last_inbound_at', '>=', now()->subDays(7))
            ->where(function ($q) {
                $q->whereNull('last_outbound_at')
                    ->orWhereColumn('last_outbound_at', '<', 'last_inbound_at');
            })
            ->select('id', 'lead_id', 'last_inbound_at')
            ->limit(100)
            ->get();

        foreach ($rows as $row) {
            $this->upsert([
                'type' => CommercialAlert::TYPE_NO_REPLY,
                'severity' => CommercialAlert::SEVERITY_HIGH,
                'fingerprint' => 'no_reply:conversation:'.$row->id,
                'title' => 'Escribió y nadie contestó',
                'summary' => 'Lleva más de cuatro horas esperando respuesta.',
                'suggested_action' => 'Abrir la conversación y responder.',
                'marketing_conversation_id' => $row->id,
                'marketing_lead_id' => $row->lead_id,
                'due_at' => now()->addHours(2),
                'evidence' => ['last_inbound_at' => (string) $row->last_inbound_at],
            ]);
        }
    }

    /** Una oportunidad se pasó de su fecha sin que nadie hiciera nada. */
    private function detectExpiredOpportunities(): void
    {
        if (! Schema::hasTable('commercial_opportunities')) {
            return;
        }

        $rows = DB::table('commercial_opportunities')
            ->whereIn('status', CommercialVocabulary::OPEN_STATUSES)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->select('id', 'goal', 'marketing_lead_id', 'member_id', 'estimated_value')
            ->limit(100)
            ->get();

        foreach ($rows as $row) {
            $this->upsert([
                'type' => CommercialAlert::TYPE_OPPORTUNITY_EXPIRED,
                'severity' => CommercialAlert::SEVERITY_MEDIUM,
                'fingerprint' => 'opportunity_expired:'.$row->id,
                'title' => 'Oportunidad vencida sin actuar',
                'summary' => 'Se abrió una oportunidad y pasó su fecha sin que nadie la trabajara.',
                'suggested_action' => 'Revisar si sigue teniendo sentido o cerrarla.',
                'commercial_opportunity_id' => $row->id,
                'marketing_lead_id' => $row->marketing_lead_id,
                'member_id' => $row->member_id,
                'opportunity_value' => $row->estimated_value !== null ? (float) $row->estimated_value : null,
                'evidence' => ['goal' => $row->goal],
            ]);
        }
    }

    /**
     * Conversaciones que llegaron con una pauta que promete lo que ya no hay.
     *
     * Se apoya en la etiqueta que pone el sistema al detectarlo, en vez de
     * volver a comparar anuncios contra catálogo: ese trabajo ya se hizo y
     * repetirlo daría dos fuentes de verdad que pueden discrepar.
     */
    private function detectOutdatedAds(): void
    {
        $rows = DB::table('marketing_conversation_tags as t')
            ->join('marketing_conversations as c', 'c.id', '=', 't.conversation_id')
            ->where('t.tag', 'pauta-desactualizada')
            ->where('t.created_at', '>=', now()->subDays(14))
            ->select('c.id', 'c.lead_id')
            ->limit(100)
            ->get();

        foreach ($rows as $row) {
            $this->upsert([
                'type' => CommercialAlert::TYPE_OUTDATED_AD,
                'severity' => CommercialAlert::SEVERITY_MEDIUM,
                'fingerprint' => 'outdated_ad:conversation:'.$row->id,
                'title' => 'Llegó por una pauta desactualizada',
                'summary' => 'El anuncio por el que llegó promete algo que ya no está vigente.',
                'suggested_action' => 'Corregir la pauta en Meta y revisar qué se le ofreció.',
                'marketing_conversation_id' => $row->id,
                'marketing_lead_id' => $row->lead_id,
            ]);
        }
    }

    // ── Mantenimiento ───────────────────────────────────────────────────

    /**
     * Crea o actualiza. Nunca duplica.
     *
     * @param  array<string,mixed>  $data
     */
    private function upsert(array $data): CommercialAlert
    {
        $existing = CommercialAlert::query()->where('fingerprint', $data['fingerprint'])->first();

        if ($existing !== null) {
            // Ya cerrada por una persona: NO se reabre sola. Si alguien decidió
            // ignorarla, volver a abrirla en la siguiente evaluación convierte
            // la decisión en ruido y entrena a no usar el botón.
            if (! $existing->isOpen()) {
                return $existing;
            }

            $existing->forceFill([
                'severity' => $data['severity'] ?? $existing->severity,
                'evidence' => $data['evidence'] ?? $existing->evidence,
            ])->save();

            return $existing;
        }

        return CommercialAlert::create(array_merge($data, [
            'status' => CommercialAlert::STATUS_OPEN,
            'detected_at' => now(),
        ]));
    }

    /**
     * Cierra las que ya no aplican, y solo cuando se puede demostrar.
     *
     * Un pago que entró, una conversación contestada. Lo que no se pueda
     * comprobar se deja abierto: cerrar por si acaso es cómo se pierde un
     * cliente sin enterarse.
     */
    public function autoCloseResolved(): int
    {
        $closed = 0;

        // Pagos que dejaron de estar pendientes.
        $pending = CommercialAlert::query()
            ->where('type', CommercialAlert::TYPE_PAYMENT_PENDING)
            ->whereIn('status', CommercialAlert::OPEN_STATUSES)
            ->get();

        foreach ($pending as $alert) {
            $paymentId = (int) str_replace('payment_pending:', '', (string) $alert->fingerprint);

            $stillPending = DB::table('payment_transactions')
                ->where('id', $paymentId)->where('status', 'pending')->exists();

            if (! $stillPending) {
                $this->autoClose($alert, 'El pago dejó de estar pendiente.');
                $closed++;
            }
        }

        // Conversaciones que ya recibieron respuesta.
        $unanswered = CommercialAlert::query()
            ->where('type', CommercialAlert::TYPE_NO_REPLY)
            ->whereIn('status', CommercialAlert::OPEN_STATUSES)
            ->get();

        foreach ($unanswered as $alert) {
            $conversationId = (int) $alert->marketing_conversation_id;

            $answered = DB::table('marketing_conversations')
                ->where('id', $conversationId)
                ->whereNotNull('last_outbound_at')
                ->whereColumn('last_outbound_at', '>=', 'last_inbound_at')
                ->exists();

            if ($answered) {
                $this->autoClose($alert, 'Alguien contestó la conversación.');
                $closed++;
            }
        }

        return $closed;
    }

    private function autoClose(CommercialAlert $alert, string $why): void
    {
        $alert->forceFill([
            'status' => CommercialAlert::STATUS_AUTO_CLOSED,
            'resolved_at' => now(),
            'resolution' => 'auto',
            // Siempre queda dicho POR QUÉ se cerró sola. Una alerta que
            // desaparece sin explicación es indistinguible de un fallo.
            'resolution_note' => $why,
        ])->save();
    }

    /** Cierre manual: alguien la miró y decidió. */
    public function resolve(CommercialAlert $alert, int $adminId, string $resolution, ?string $note = null): CommercialAlert
    {
        $alert->forceFill([
            'status' => $resolution === 'ignored'
                ? CommercialAlert::STATUS_IGNORED
                : CommercialAlert::STATUS_RESOLVED,
            'owner_admin_id' => $adminId,
            'resolved_at' => now(),
            'resolution' => $resolution,
            'resolution_note' => $note,
        ])->save();

        return $alert->fresh();
    }

    public function assign(CommercialAlert $alert, int $adminId): CommercialAlert
    {
        $alert->forceFill([
            'status' => CommercialAlert::STATUS_ASSIGNED,
            'owner_admin_id' => $adminId,
        ])->save();

        return $alert->fresh();
    }
}
