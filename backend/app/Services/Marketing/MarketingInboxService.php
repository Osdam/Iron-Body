<?php

namespace App\Services\Marketing;

use App\Models\MarketingConversation;
use App\Models\MarketingMessage;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Lectura de la bandeja: lista filtrable, detalle saneado y métricas. No envía
 * mensajes ni cambia el estado de la IA (eso vive en los servicios de acción).
 */
class MarketingInboxService
{
    /** Lista paginada de conversaciones con filtros del Inbox. */
    public function list(Request $request, ?int $viewerAdminId): LengthAwarePaginator
    {
        $perPage = min(max((int) $request->integer('per_page', 20), 1), 50);

        $query = MarketingConversation::query()
            ->with([
                // OJO: lead_stage vive en marketing_conversations, NO en
                // marketing_leads. Pedirlo aquí rompía la consulta (SQL 42703)
                // y dejaba la lista vacía aunque las métricas sí contaran.
                'lead:id,name,phone,channel,status,temperature,objective,do_not_contact',
                'assignedAdmin:id,name',
                'tags:id,conversation_id,tag',
            ])
            ->latest('last_message_at');

        $this->applyFilters($query, $request, $viewerAdminId);

        return $query->paginate($perPage);
    }

    private function applyFilters(Builder $query, Request $request, ?int $viewerAdminId): void
    {
        if ($channel = $request->query('channel')) {
            $query->where('channel', $channel);
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        // IA activa / pausada.
        $ai = $request->query('ai');
        if ($ai === 'active') {
            $query->where('ai_enabled', true);
        } elseif ($ai === 'paused') {
            $query->where('ai_enabled', false);
        }

        // staff_review.
        $sr = $request->query('staff_review');
        if ($sr === 'pending') {
            $query->where('staff_review_pending', true);
        } elseif ($sr === 'resolved') {
            $query->where('staff_review_pending', false)->whereNotNull('staff_review_resolved_at');
        }

        // No leídos.
        if ($request->boolean('unread')) {
            $query->where('unread_count', '>', 0);
        }

        // Asignación: mine | unassigned | {id}.
        $assigned = $request->query('assigned');
        if ($assigned === 'mine') {
            $query->where('assigned_to_admin_id', $viewerAdminId);
        } elseif ($assigned === 'unassigned') {
            $query->whereNull('assigned_to_admin_id');
        } elseif (is_numeric($assigned)) {
            $query->where('assigned_to_admin_id', (int) $assigned);
        }

        // Tag.
        if ($tag = $request->query('tag')) {
            $query->whereHas('tags', fn (Builder $q) => $q->where('tag', $tag));
        }

        // Búsqueda libre: nombre / teléfono del lead o texto de un mensaje.
        $q = trim((string) $request->query('q', ''));
        if ($q !== '') {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%';
            $query->where(function (Builder $outer) use ($like): void {
                $outer->whereHas('lead', fn (Builder $l) => $l
                    ->where('name', 'like', $like)
                    ->orWhere('phone', 'like', $like))
                    ->orWhereHas('messages', fn (Builder $m) => $m->where('body', 'like', $like));
            });
        }
    }

    /** Tarjeta resumida para la lista. */
    public function presentListItem(MarketingConversation $c): array
    {
        $preview = MarketingMessage::where('conversation_id', $c->id)
            ->latest('created_at')
            ->value('body');

        return [
            'id'                    => $c->id,
            'lead_id'               => $c->lead_id,
            'lead_name'             => $c->lead?->name,
            'phone'                 => $c->lead?->phone,
            'channel'               => $c->channel,
            'status'                => $c->status,
            'ai_enabled'            => (bool) $c->ai_enabled,
            'human_takeover'        => (bool) $c->human_takeover,
            'human_takeover_source' => $c->human_takeover_source,
            'assigned_to'           => $c->assignedAdmin ? ['id' => $c->assignedAdmin->id, 'name' => $c->assignedAdmin->name] : null,
            'unread_count'          => (int) $c->unread_count,
            'last_message_at'       => $c->last_message_at?->toIso8601String(),
            'last_inbound_at'       => $c->last_inbound_at?->toIso8601String(),
            'last_outbound_at'      => $c->last_outbound_at?->toIso8601String(),
            'staff_review_pending'  => (bool) $c->staff_review_pending,
            'staff_review_reason'   => $c->staff_review_reason,
            'tags'                  => $c->tags->pluck('tag')->all(),
            'last_message_preview'  => $preview !== null ? mb_strimwidth((string) $preview, 0, 120, '…') : null,
        ];
    }

    /** Detalle completo y saneado de una conversación (marca como leída). */
    public function detail(MarketingConversation $conversation): array
    {
        // Marcar como leída al abrir el detalle.
        if ((int) $conversation->unread_count !== 0 || $conversation->last_read_at === null) {
            $conversation->forceFill(['unread_count' => 0, 'last_read_at' => now()])->save();
        }

        $conversation->load([
            'lead',
            'assignedAdmin:id,name',
            'tags',
            'notes' => fn ($q) => $q->with('author:id,name')->latest('created_at'),
        ]);

        $messages = MarketingMessage::where('conversation_id', $conversation->id)
            ->orderBy('created_at')
            ->get()
            ->map(fn (MarketingMessage $m) => [
                'id'             => $m->id,
                'direction'      => $m->direction,
                'sender_type'    => $m->sender_type,
                'sender_user_id' => $m->sender_user_id,
                'body'           => $m->body,
                'status'         => $m->status,
                'created_at'     => $m->created_at?->toIso8601String(),
                // No se expone metadata cruda del proveedor.
            ])->all();

        $aiActions = \App\Models\MarketingAiAction::where('conversation_id', $conversation->id)
            ->latest('created_at')
            ->limit(20)
            ->get()
            ->map(fn ($a) => [
                'action_type' => $a->action_type,
                'status'      => $a->status,
                'reason'      => $a->reason,
                'created_at'  => $a->created_at?->toIso8601String(),
            ])->all();

        $lead = $conversation->lead;

        return [
            'conversation' => [
                'id'                    => $conversation->id,
                'channel'               => $conversation->channel,
                'status'                => $conversation->status,
                'ai_enabled'            => (bool) $conversation->ai_enabled,
                'human_takeover'        => (bool) $conversation->human_takeover,
                'human_takeover_source' => $conversation->human_takeover_source,
                'unread_count'          => 0,
                'last_message_at'       => $conversation->last_message_at?->toIso8601String(),
                'staff_review'          => [
                    'pending'     => (bool) $conversation->staff_review_pending,
                    'reason'      => $conversation->staff_review_reason,
                    'resolved_at' => $conversation->staff_review_resolved_at?->toIso8601String(),
                ],
                'assignment'            => $conversation->assignedAdmin
                    ? ['id' => $conversation->assignedAdmin->id, 'name' => $conversation->assignedAdmin->name]
                    : null,
            ],
            'lead' => $lead ? [
                'id'             => $lead->id,
                'name'           => $lead->name,
                'phone'          => $lead->phone,
                'channel'        => $lead->channel,
                'status'         => $lead->status,
                'temperature'    => $lead->temperature,
                'lead_stage'     => $conversation->lead_stage,
                'do_not_contact' => (bool) $lead->do_not_contact,
            ] : null,
            'messages'   => $messages,
            'ai_actions' => $aiActions,
            'notes'      => $conversation->notes->map(fn ($n) => [
                'id'         => $n->id,
                'author'     => $n->author?->name,
                'body'       => $n->body,
                'created_at' => $n->created_at?->toIso8601String(),
            ])->all(),
            'tags' => $conversation->tags->pluck('tag')->all(),
        ];
    }

    /** Métricas básicas de operación de la bandeja. */
    public function metrics(?int $viewerAdminId = null): array
    {
        $base = fn () => MarketingConversation::query();

        $open = (clone $base())->where('status', 'open')->count();
        $unassigned = (clone $base())->whereNull('assigned_to_admin_id')->where('status', '!=', 'closed')->count();
        $unreadTotal = (int) (clone $base())->sum('unread_count');
        $staffReviewPending = (clone $base())->where('staff_review_pending', true)->count();
        $handledByHuman = (clone $base())->where('human_takeover', true)->count();
        $handledByAi = (clone $base())->where('ai_enabled', true)->count();

        // Tiempo medio de primera respuesta (segundos) sobre conversaciones que
        // ya tuvieron primera respuesta.
        $ttfrRows = (clone $base())
            ->whereNotNull('first_response_at')
            ->get(['created_at', 'first_response_at']);
        $ttfr = null;
        if ($ttfrRows->isNotEmpty()) {
            $sum = 0;
            foreach ($ttfrRows as $row) {
                $sum += max(0, $row->first_response_at->getTimestamp() - $row->created_at->getTimestamp());
            }
            $ttfr = (int) round($sum / $ttfrRows->count());
        }

        $byStatus = (clone $base())
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        return [
            'open_conversations'            => $open,
            'unassigned'                    => $unassigned,
            'unread_total'                  => $unreadTotal,
            'staff_review_pending'          => $staffReviewPending,
            'handled_by_ai'                 => $handledByAi,
            'handled_by_human'              => $handledByHuman,
            'first_response_time_avg_seconds' => $ttfr,
            'conversations_by_status'       => $byStatus,
        ];
    }
}
