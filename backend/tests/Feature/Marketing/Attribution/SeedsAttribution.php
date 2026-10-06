<?php

namespace Tests\Feature\Marketing\Attribution;

use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\MarketingLeadAttribution;
use App\Models\MarketingMessage;
use App\Models\Member;
use App\Models\MetaAdEntity;
use App\Models\Payment;
use App\Models\Receivable;
use App\Models\ReceivablePayment;
use App\Models\User;
use App\Services\Marketing\LeadAttributionService;
use App\Services\Marketing\Meta\MetaAdsApiClient;
use Carbon\CarbonImmutable;

/**
 * Datos de prueba para la atribución, con la forma que tienen en producción.
 *
 * Las atribuciones se escriben con el MISMO LeadAttributionService que usa el
 * webhook: así lo que lee el panel es lo que de verdad se guarda, y no una fila
 * montada a mano que podría no parecerse.
 *
 * Las horas se pasan en UTC, que es como se guardan. Bogotá va cinco horas por
 * detrás: las 19:00 de Bogotá son las 00:00 UTC del día siguiente.
 */
trait SeedsAttribution
{
    private int $seedSeq = 0;

    /** Un lead real de WhatsApp con teléfono 57… y primer contacto en `$at` (UTC). */
    protected function lead(string $at, array $attrs = []): MarketingLead
    {
        $n = ++$this->seedSeq;
        $phone = '573'.str_pad((string) (100000000 + $n * 7919), 9, '0', STR_PAD_LEFT);

        return MarketingLead::forceCreate(array_merge([
            'channel' => 'whatsapp',
            'source' => 'inbound',
            'meta_user_id' => $phone,
            'phone' => $phone,
            'name' => 'Prospecto '.$n,
            'status' => MarketingLead::STATUS_NEW,
            'temperature' => MarketingLead::STATUS_COLD,
            'first_message_at' => $at,
            'last_message_at' => $at,
            'created_at' => $at,
            'updated_at' => $at,
        ], $attrs));
    }

    protected function conversation(MarketingLead $lead, array $attrs = []): MarketingConversation
    {
        return MarketingConversation::forceCreate(array_merge([
            'lead_id' => $lead->id,
            'channel' => $lead->channel,
            'status' => 'open',
            'ai_enabled' => true,
            'human_takeover' => false,
        ], $attrs));
    }

    /** Un mensaje entrante del lead a la hora `$at` (UTC). */
    protected function inbound(MarketingConversation $conversation, string $at, array $metadata = []): MarketingMessage
    {
        return MarketingMessage::forceCreate([
            'conversation_id' => $conversation->id,
            'direction' => MarketingMessage::DIRECTION_INBOUND,
            'sender_type' => MarketingMessage::SENDER_LEAD,
            'body' => 'Hola, quiero información',
            'metadata' => $metadata === [] ? null : $metadata,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    /** El bloque `referral` de un anuncio click-to-WhatsApp, como lo guarda el webhook. */
    protected function adReferral(string $adId, string $url = 'https://fb.me/2xYz9Ab', ?string $clickId = null): array
    {
        return [
            'source_type' => 'ad',
            'source_id' => $adId,
            'source_url' => $url,
            'headline' => 'Plan de octubre con 2x1',
            'body' => 'Escríbenos y te contamos',
            'media_type' => 'video',
            'ctwa_clid' => $clickId ?? 'ARAkLkA8rmlFeiCktEJQ-QTwRiyYHAFDLMNDBH0CD3qpjd0HR4irJ6LEkR7JwFF4XvnO',
        ];
    }

    protected function postReferral(string $url = 'https://www.instagram.com/p/C9xYz/'): array
    {
        return [
            'source_type' => 'post',
            'source_id' => '17999999999999999',
            'source_url' => $url,
            'headline' => 'Publicación',
            'media_type' => 'image',
        ];
    }

    /**
     * Registra un contacto con el servicio real. Sin referral, deja constancia de
     * que se desconoce de dónde vino.
     */
    protected function touch(MarketingLead $lead, ?array $referral, string $at, ?MarketingConversation $conversation = null): ?MarketingLeadAttribution
    {
        return app(LeadAttributionService::class)->record(
            leadId: $lead->id,
            referral: $referral,
            conversationId: $conversation?->id,
            contactId: $lead->meta_user_id,
            receivedAt: CarbonImmutable::parse($at, 'UTC'),
        );
    }

    /**
     * Una persona del CRM: su usuario y, si se pide, su ficha de socio, ambos con
     * el teléfono en el formato que se quiera.
     *
     * @return array{0: User, 1: ?Member}
     */
    protected function customer(string $phone, bool $withMember = true, ?string $memberPhone = null): array
    {
        $n = ++$this->seedSeq;
        $user = User::forceCreate([
            'name' => 'Cliente '.$n,
            'email' => 'cliente-'.$n.'-'.uniqid().'@ironbody.test',
            'password' => bcrypt('secret-password'),
            'phone' => $phone,
            'status' => 'active',
        ]);

        $member = $withMember ? Member::forceCreate([
            'user_id' => $user->id,
            'full_name' => 'Cliente '.$n,
            'document_number' => (string) (1075000000 + $n),
            'phone' => $memberPhone ?? $phone,
        ]) : null;

        return [$user, $member];
    }

    /** Una ficha de socio sin usuario. */
    protected function memberOnly(string $phone): Member
    {
        $n = ++$this->seedSeq;

        return Member::forceCreate([
            'full_name' => 'Socio '.$n,
            'document_number' => (string) (1076000000 + $n),
            'phone' => $phone,
        ]);
    }

    /** Un cobro en `payments` a la hora `$at` (UTC). Por defecto, efectivo y pagado. */
    protected function payment(User $user, float $amount, string $at, array $attrs = []): Payment
    {
        return Payment::forceCreate(array_merge([
            'user_id' => $user->id,
            'amount' => $amount,
            'method' => 'cash',
            'status' => 'paid',
            'paid_at' => $at,
            'created_at' => $at,
            'updated_at' => $at,
        ], $attrs));
    }

    /** Un abono a una cuenta por cobrar de la ficha, a la hora `$at` (UTC). */
    protected function installment(Member $member, float $amount, string $at, string $type = 'gym', string $status = ReceivablePayment::STATUS_APPLIED): ReceivablePayment
    {
        $receivable = Receivable::forceCreate([
            'member_id' => $member->id,
            'debtor_type' => 'member',
            'debtor_id' => $member->id,
            'type' => $type,
            'concept' => 'Plan trimestral a plazos',
            'total_amount' => 300000,
            'status' => Receivable::STATUS_PARTIALLY_PAID,
        ]);

        return ReceivablePayment::forceCreate([
            'receivable_id' => $receivable->id,
            'amount' => $amount,
            'method' => 'cash',
            'balance_before' => 300000,
            'balance_after' => 300000 - $amount,
            'status' => $status,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    /** La cuenta publicitaria de las pruebas: la misma que FakesMetaAds::ACCOUNT. */
    protected function testAdAccount(): string
    {
        return '1234567890';
    }

    /**
     * La dimensión de Meta para un anuncio: campaña → conjunto → anuncio, de una
     * cuenta publicitaria.
     *
     * Un anuncio solo se resuelve contra la cuenta CONFIGURADA. Sin `$account`,
     * va a la configurada y, si la prueba no configuró ninguna, se configura la
     * de las pruebas SIN token: el anuncio se resuelve, pero el gasto sigue «sin
     * conexión a Meta Ads».
     */
    protected function adHierarchy(
        string $adId,
        string $adsetId = '120211',
        string $campaignId = '120201',
        string $campaignName = 'Campaña Octubre',
        string $adsetName = 'Neiva 18-35',
        ?string $adName = null,
        ?string $account = null,
    ): void {
        if ($account === null) {
            $account = app(MetaAdsApiClient::class)->accountId();
            if ($account === null) {
                $account = $this->testAdAccount();
                config()->set('meta.ads.ad_account_id', $account);
            }
        }

        $now = now();
        MetaAdEntity::query()->upsert([
            ['ad_account_id' => $account, 'level' => 'campaign', 'entity_id' => $campaignId, 'name' => $campaignName, 'campaign_id' => $campaignId, 'adset_id' => null, 'effective_status' => 'ACTIVE', 'synced_at' => $now],
            ['ad_account_id' => $account, 'level' => 'adset', 'entity_id' => $adsetId, 'name' => $adsetName, 'campaign_id' => $campaignId, 'adset_id' => $adsetId, 'effective_status' => null, 'synced_at' => $now],
            ['ad_account_id' => $account, 'level' => 'ad', 'entity_id' => $adId, 'name' => $adName ?? 'Video promo '.$adId, 'campaign_id' => $campaignId, 'adset_id' => $adsetId, 'effective_status' => null, 'synced_at' => $now],
        ], ['ad_account_id', 'level', 'entity_id'], ['name', 'campaign_id', 'adset_id', 'effective_status', 'synced_at']);
    }
}
