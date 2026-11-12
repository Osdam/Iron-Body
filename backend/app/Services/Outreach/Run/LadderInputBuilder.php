<?php

namespace App\Services\Outreach\Run;

use App\Services\Outreach\Core\OutreachCatalog;
use App\Services\Outreach\Core\RelativeTime;
use App\Services\Outreach\Facts\CoreProjection;
use App\Services\Outreach\Facts\SubjectFacts;
use Closure;
use DateTimeImmutable;

/**
 * LA ENTRADA DE LA ESCALERA (contrato H9, D9-7; `PolicyLadder::SPEC`): junta,
 * para UNA persona, UNA estrategia viva y UN canal del paso, lo que la
 * escalera necesita y que ningún otro sabe:
 *
 *  - de la PERSONA, lo que da B9-facts (`CoreProjection::policyInput()`:
 *    sujeto, consentimientos, opt-outs, voz, historial, conversación,
 *    identidad de canal, frenos y banderas ajenos);
 *  - de la EJECUCIÓN: el ahora, la finalidad, el canal, los límites efectivos,
 *    el horario propio, las banderas y la constante (`OutreachSendGate`), los
 *    frenos propios (`OutreachStop`), la supresión, la referencia comercial
 *    (N3) y el estado de la plantilla (N3/N4), la cartera y el bloqueo
 *    comercial (P9-7), el presupuesto reservado hoy (N4), la estrategia (N5:
 *    publicada, pausada, cadena, parte, canario, horario, contactos, topes,
 *    ventana, enfriamiento, guarda), el contexto (N6) y la instrucción
 *    conversacional (N7), más `phone` (L9-30: los teléfonos y sus contactos
 *    de hoy).
 *
 * Todo lo que va en la entrada es DERIVADO (códigos, enteros, booleanos,
 * instantes, seudónimos): la entrada se guarda entera en la traza para que
 * `outreach:replay` la vuelva a evaluar.
 */
final class LadderInputBuilder
{
    /**
     * `$sendsEnabledInCode` (W9) es SOLO el doble de la puerta para las
     * pruebas que ensayan el envío con la constante abierta sin girarla (la
     * vía de la protección 2: nunca en el árbol compartido); null = la
     * constante de {@see OutreachSendGate}, siempre en producción.
     *
     * @param  (Closure(): bool)|null  $sendsEnabledInCode
     */
    public function __construct(
        private readonly OutreachPublishedStrategies $bridge = new OutreachPublishedStrategies,
        private readonly BudgetReservations $budgets = new BudgetReservations,
        private readonly OutreachStore $store = new OutreachStore,
        private readonly SubjectSuppressions $suppressions = new SubjectSuppressions,
        private readonly ?Closure $sendsEnabledInCode = null,
    ) {}

    /**
     * @param  array{brakes: array<string,bool>, unreadable: list<string>}|null  $global  los frenos ajenos de la foto
     * @param  array<string, mixed>  $ctx  ver {@see context()}
     * @return array<string, mixed>
     */
    public function build(LiveStrategy $live, SubjectFacts $s, ?array $global, string $stepChannel, DateTimeImmutable $now, string $mode, array $ctx): array
    {
        $persona = CoreProjection::policyInput($s, $live->purpose, $global);
        $medio = OutreachCatalog::mediumOf($stepChannel) ?? OutreachCatalog::MEDIUM_WHATSAPP;
        $canalTrabajo = OutreachSendGate::jobChannelOf($stepChannel);
        $banderas = OutreachSendGate::channelFlags($stepChannel);
        if ($this->sendsEnabledInCode !== null) {
            $banderas['sendsEnabledInCode'] = ($this->sendsEnabledInCode)() === true;
        }
        $dia = (string) ($ctx['budgetDay'] ?? RelativeTime::bogotaDate($now));
        $paso = $live->step((int) ($ctx['stepIndex'] ?? 0)) ?? [];
        $plantilla = $this->bridge->templateState($paso['templates'][$stepChannel] ?? ['uuid' => '', 'hash' => ''], $stepChannel, $live->purpose);
        $compatible = $live->content !== null && $live->contentReason === null;
        $tieneCartera = $s->has('receivable.has_overdue') && $s->fact('receivable.has_overdue') !== null;
        $diasCartera = $s->fact('receivable.overdue_days_max');

        $historial = [...$persona['history'], ...($ctx['extraHistory'] ?? [])];
        $frenos = OutreachStop::ladderBrakes($ctx['brakes'] ?? ['global' => false, 'channels' => [], 'strategies' => []], $live->strategyUuid, $canalTrabajo);

        $entrada = [
            'now' => RelativeTime::format($now),
            'purpose' => $live->purpose,
            'channel' => $stepChannel,
            'subject' => $persona['subject'],
            'consent' => $persona['consent'],
            'optOut' => $persona['optOut'],
            'voice' => $persona['voice'],
            'history' => $historial,
            'limits' => OutreachConfig::effectiveLimits(),
            'ownHours' => OutreachConfig::ownHours(),
            'flags' => [
                'master' => OutreachConfig::enabled(),
                'mode' => $mode,
                'channelEnabled' => $banderas['channelEnabled'],
                'channelLive' => $banderas['channelLive'],
                'sendsEnabledInCode' => $banderas['sendsEnabledInCode'],
                'legacyAutonomy' => $persona['flags']['legacyAutonomy'],
                'legacyFollowupsScheduler' => $persona['flags']['legacyFollowupsScheduler'],
            ],
            'brakes' => $frenos + $persona['brakes'],
            'conversation' => $persona['conversation'],
            'globalSuppression' => (bool) ($ctx['suppressed'] ?? $this->suppressions->isSuppressed((string) ($s->canonicalKey ?? $s->key), $live->purpose, $medio)),
            'studio' => [
                'referenceValid' => $live->studio['referenceValid'],
                'catalogCompatible' => $compatible,
                'paymentLinksEnabled' => $live->studio['paymentLinksEnabled'],
                'canaryOnly' => $live->studio['canaryOnly'],
                'templateViolations' => $plantilla['violations'],
            ],
            'receivable' => ['known' => $tieneCartera ? true : null, 'overdueDaysMax' => is_int($diasCartera) ? $diasCartera : null],
            'collectionsBlock' => ['serverDays' => OutreachConfig::collectionsBlockDays(), 'strategyDays' => $live->limits()['commercialBlockOverdueDays'] ?? null],
            'step' => ['usesPaymentLink' => $plantilla['usesPaymentLink'], 'templateApproved' => $plantilla['approved'], 'templateCategoryMatches' => $plantilla['categoryMatches']],
            'identity' => $persona['identity'],
            'voicePolicyReasons' => [],
            'budget' => $this->budget($live, $canalTrabajo, $plantilla['category'], $dia, $mode),
            'strategy' => [
                'trigger' => $live->triggerEvent() !== '' ? $live->triggerEvent() : null,
                'published' => $live->runnable() && $live->chainStatus === OutreachPublishedStrategies::STATUS_ACTIVE,
                'paused' => $live->paused(),
                'chainReadable' => $live->chainReadable(),
                'part' => $live->part,
                // El alcance canario del MEDIO del canal (D9-11.6): lo mira N5 en la parte canaria y N3 si la versión del Studio declara «solo canario».
                'inCanaryScope' => $s->policy("canary.{$medio}"),
                'schedule' => $live->schedule(),
                'touches' => (int) ($ctx['touches'] ?? 0),
                'maxTouches' => $live->limit('maxTouches', 6),
                'dailyCap' => $live->limit('contactsPerDay', 1),
                'usedToday' => $this->store->strategyJobsOn($live->strategyId, $mode, $dia),
                'enrollmentWindowEndsAt' => $ctx['enrollmentWindowEndsAt'] ?? null,
                'cooldownUntil' => $ctx['cooldownUntil'] ?? null,
                'stepGuard' => $ctx['stepGuard'] ?? null,
            ],
            'context' => [
                'success' => (string) ($ctx['gate']['success'] ?? 'unknown'),
                'audience' => (string) ($ctx['gate']['audience'] ?? 'unknown'),
                'exclusions' => (string) ($ctx['gate']['exclusions'] ?? 'false'),
                'humanRequestedRecently' => $ctx['humanRequestedRecently'] ?? null,
                'complaintRecently' => $ctx['complaintRecently'] ?? null,
                'otherPurposeToday' => (bool) ($ctx['otherPurposeToday'] ?? false),
                'lowerPriority' => (bool) ($ctx['lowerPriority'] ?? false),
            ],
            'conversational' => ['callbackAt' => $ctx['callbackAt'] ?? null],
        ];
        // L9-33: el teléfono SIEMPRE (desconocido = `refs: []`), con los contactos de hoy a esos números.
        $refs = CoreProjection::phoneKeys($s) ?? [];
        $entrada['phone'] = ['refs' => $refs, 'contacts' => $refs === [] ? [] : $this->store->phoneContactsToday($refs, $dia)];

        return $entrada;
    }

    /**
     * ¿Pidió una persona hace poco (L9-41: 30 días)? Con la fuente de eventos
     * sin datos, no se sabe (null: N6 niega, el lado seguro, L9-26).
     *
     * @param  list<array{key: string, at: string}>|null  $events
     */
    public static function humanRequestedRecently(?array $events, DateTimeImmutable $now): ?bool
    {
        if ($events === null) {
            return null;
        }
        foreach ($events as $e) {
            if (($e['key'] ?? null) === 'human_requested' && RelativeTime::withinLast(RelativeTime::parseInstant((string) $e['at']), $now, OutreachConfig::humanRequestedDays(), 'days')) {
                return true;
            }
        }

        return false;
    }

    /**
     * ¿Se quejó hace poco (Q1-3, N6 `complaint_recent`, DF-L14)? Un evento
     * `complaint_created` en los últimos {@see OutreachConfig::complaintDays()}
     * días de Bogotá (hoy cuenta). Solo para las finalidades de
     * {@see OutreachConfig::complaintPurposes()}: con otra finalidad, o con la
     * ventana en 0, la queja no frena (false). Con la fuente de eventos sin
     * datos, no se sabe (null: N6 niega, el lado seguro, como L9-26).
     *
     * @param  list<array{key: string, at: string}>|null  $events
     */
    public static function complaintRecently(?array $events, DateTimeImmutable $now, string $purpose): ?bool
    {
        $dias = OutreachConfig::complaintDays();
        if ($dias === 0 || ! in_array($purpose, OutreachConfig::complaintPurposes(), true)) {
            return false;
        }
        if ($events === null) {
            return null;
        }
        foreach ($events as $e) {
            if (($e['key'] ?? null) === 'complaint_created' && RelativeTime::withinLast(RelativeTime::parseInstant((string) $e['at']), $now, $dias, 'days')) {
                return true;
            }
        }

        return false;
    }

    /**
     * N4: el presupuesto del canal. WhatsApp, por el precio de su categoría;
     * la voz, por la reserva por llamada con su cupo y su techo EFECTIVOS
     * ({@see OutreachConfig::voiceBudget()}: sin reserva, 0 → `budget_zero`).
     *
     * @return array<string, int>
     */
    private function budget(LiveStrategy $live, string $canalTrabajo, ?string $categoria, string $dia, string $mode): array
    {
        $canal = $this->budgets->used($dia, $mode, BudgetReservations::SCOPE_CHANNEL.$canalTrabajo);
        $automatico = in_array($canalTrabajo, OutreachCatalog::AUTOMATIC_CHANNELS, true);
        $esWhatsapp = $canalTrabajo === OutreachCatalog::CHANNEL_WHATSAPP_TEMPLATE;
        $voz = $canalTrabajo === OutreachCatalog::CHANNEL_VOICE_CALL ? OutreachConfig::voiceBudget() : ['dailyCap' => 0, 'dailyBudgetMicros' => 0, 'reserveMicros' => 0];

        return [
            'dailyBudgetMicros' => $esWhatsapp ? OutreachConfig::whatsappDailyBudgetMicros() : $voz['dailyBudgetMicros'],
            'reservedTodayMicros' => $automatico ? $canal['micros'] : 0,
            'costMicros' => $esWhatsapp ? OutreachConfig::whatsappPriceMicros((string) $categoria) : $voz['reserveMicros'],
            'channelDailyCap' => $esWhatsapp ? OutreachConfig::whatsappDailyCap() : $voz['dailyCap'],
            'channelUsedToday' => $automatico ? $canal['units'] : 0,
            'assistedDailyCap' => OutreachConfig::assistedDailyCap(),
            'assistedUsedToday' => $canalTrabajo === 'assisted' ? $canal['units'] : 0,
        ];
    }
}
