<?php

namespace App\Services\Notifications;

use App\Models\Member;
use App\Models\MemberDeviceToken;
use App\Models\NotificationCampaign;
use App\Models\NotificationDispatch;
use App\Support\Notifications\NotificationCategory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Resolución de audiencia y envío de campañas manuales.
 *
 * La audiencia se calcula SIEMPRE con la misma consulta que luego envía, y el
 * recuento se muestra antes de confirmar. Que la cifra que se aprueba y la que
 * se ejecuta salgan del mismo sitio es lo que evita la sorpresa cara.
 */
class CampaignService
{
    public function __construct(private readonly NotificationDispatcher $dispatcher) {}

    /**
     * Socios que recibirían la campaña.
     *
     * Solo entra quien tiene dispositivo activo y membresía en curso: mandar a
     * un socio dado de baja una promoción del gimnasio no es alcance, es ruido.
     *
     * @return Collection<int,int>
     */
    public function audience(NotificationCampaign $campaign): Collection
    {
        $filters = $campaign->audience ?? [];

        $query = Member::query()
            ->where('status', Member::STATUS_ACTIVE)
            ->whereIn('id', MemberDeviceToken::query()->where('is_active', true)->select('member_id'));

        if (! empty($filters['member_ids']) && is_array($filters['member_ids'])) {
            $query->whereIn('id', array_map('intval', $filters['member_ids']));
        }

        if (! empty($filters['inactive_days']) && is_numeric($filters['inactive_days'])) {
            $cutoff = CarbonImmutable::now()->subDays((int) $filters['inactive_days']);
            $query->whereNotIn('id', function ($sub) use ($cutoff): void {
                $sub->from('attendances')
                    ->select('member_id')
                    ->where('action', 'entry')
                    ->where('captured_at', '>=', $cutoff)
                    ->whereNotNull('member_id');
            });
        }

        return $query->pluck('id');
    }

    public function estimate(NotificationCampaign $campaign): int
    {
        $count = $this->audience($campaign)->count();
        $campaign->update(['estimated_recipients' => $count]);

        return $count;
    }

    /**
     * Envía la campaña. Cada destinatario pasa por el dispatcher, así que las
     * preferencias, las horas de silencio y los límites siguen mandando: una
     * campaña del gimnasio no puede saltarse el "no quiero" de nadie.
     *
     * @return array{sent:int,suppressed:int,failed:int}
     */
    public function send(NotificationCampaign $campaign, string $actor): array
    {
        if (! $campaign->isSendable()) {
            throw new RuntimeException('campaign_not_sendable');
        }

        if (! NotificationCategory::isValid($campaign->category)) {
            throw new RuntimeException('invalid_category');
        }

        $campaign->update([
            'status' => NotificationCampaign::STATUS_SENDING,
            'started_at' => now(),
            'approved_by' => $actor,
            'approved_at' => now(),
        ]);

        $stats = ['sent' => 0, 'suppressed' => 0, 'failed' => 0];

        foreach ($this->audience($campaign) as $memberId) {
            $dispatch = $this->dispatcher->dispatch(
                memberId: (int) $memberId,
                category: $campaign->category,
                title: $campaign->title,
                body: $campaign->body,
                actionRoute: $campaign->action_route,
                templateKey: 'campaign_'.$campaign->id,
                // Una campaña, un envío por socio. Reintentar no duplica.
                idempotencyKey: sprintf('campaign:%d:%d', $campaign->id, $memberId),
                campaignId: $campaign->id,
            );

            match ($dispatch->status) {
                NotificationDispatch::STATUS_SENT => $stats['sent']++,
                NotificationDispatch::STATUS_FAILED => $stats['failed']++,
                default => $stats['suppressed']++,
            };
        }

        $campaign->update([
            'status' => NotificationCampaign::STATUS_SENT,
            'finished_at' => now(),
            'sent_count' => $stats['sent'],
            'suppressed_count' => $stats['suppressed'],
            'failed_count' => $stats['failed'],
        ]);

        return $stats;
    }

    public function cancel(NotificationCampaign $campaign, string $actor): void
    {
        if (! $campaign->isCancellable()) {
            throw new RuntimeException('campaign_not_cancellable');
        }

        $campaign->update([
            'status' => NotificationCampaign::STATUS_CANCELLED,
            'cancelled_by' => $actor,
            'cancelled_at' => now(),
        ]);
    }
}
