<?php

namespace App\Services\Outreach\Core;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * La ESCALERA de precedencia N1–N7 (contrato H9, D9-7, D9-8 etapas 4 y 6;
 * fixture `policy-ladder-cases.json`; T16–T22). PURA: todo lo que decide entra
 * en `$input` (los hechos ya leídos por los adaptadores, banderas, frenos,
 * estrategia, presupuestos y «ahora», en `now`). Se evalúa para UN canal; el
 * selector ({@see ChannelSelector}) la recorre por cada canal del paso.
 *
 * EN ORDEN, y el PRIMER nivel que niega decide (DF-L1): dentro de él se
 * acumulan TODOS sus motivos en el orden de {@see REASON_ORDER}; los niveles
 * anteriores salen `pass` y los posteriores `not_evaluated`. Si todos los
 * motivos del nivel que decide son temporales ({@see TEMPORAL}), `defer`; si
 * alguno es permanente, `deny` sin `retryAt` (DF-L2). `retryAt` (DF-L3) es el
 * primer hueco del calendario completo (ley ∩ horario propio ∩ el de la
 * estrategia) a partir del más tardío entre «ahora» y lo que suelta cada
 * motivo temporal (el presupuesto de contacto, las 00:00 de Bogotá del día
 * siguiente para los topes diarios, `cooldownUntil`). Ningún nivel inferior
 * levanta la negativa de uno superior: no se evalúa.
 *
 *  - N1 (DF-L10, DF-B5): calendario y presupuesto de contacto
 *    ({@see ContactBudget}; con la entrada OPCIONAL `phone` = `{refs,
 *    contacts}`, también el tope diario por teléfono, L9-27 y L9-30),
 *    consentimiento del MEDIO para la finalidad (el
 *    asistido aplica el de su medio), opt-outs, exclusión de voz y RNE (solo
 *    voz), menor (solo con la marca `true`: desconocido no se infiere, Q-10) y
 *    persona desconocida.
 *  - N2 (DF-L4): frenos y banderas; si decide N2 el trabajo ESPERA a que se
 *    suelte (`waitsForRelease`), no se cancela. ULTRON y la pausa del agente
 *    aplican al medio WhatsApp; el freno de voz al medio voz.
 *  - N3: la versión comercial aprobada (referencia, catálogo compatible —L9-21—,
 *    enlaces de pago, R-xx, canario).
 *  - N4 (DF-L5, DF-L6): el canal; en SOMBRA la constante, el canal apagado o
 *    sin implementar y los presupuestos son AVISOS.
 *  - N5 (DF-L7, DF-L11): la estrategia y el horario propio
 *    (`calendar_outside_own_hours`, L9-15; mal configurado no deja hueco:
 *    `deny`); en sombra el alcance canario es aviso.
 *  - N6 (DF-L8, DF-L12): el contexto, con su efecto (`exit_converted`,
 *    `handoff`, `cancel_context_changed`), y el bloqueo comercial por cartera
 *    vencida (P9-7, L9-20).
 *  - N7 (DF-L9): «llámame luego»: aplaza al primer hueco desde esa hora; si
 *    cae en o después del fin de la ventana, `deny` (`enrollment_window_over`).
 */
final class PolicyLadder
{
    public const LEVELS = ['N1', 'N2', 'N3', 'N4', 'N5', 'N6', 'N7'];

    /** `reasonOrder` del fixture (D9-7 y DF-B4). */
    public const REASON_ORDER = [
        'N1' => [
            'calendar_outside_legal_hours', 'calendar_sunday', 'calendar_holiday', 'no_consent_for_purpose_channel', 'consent_denied',
            'do_not_contact', 'do_not_contact_sibling', 'commercial_opt_out', 'voice_excluded', 'rne_check_missing', 'subject_minor',
            'daily_contact_limit', 'weekly_contact_limit', 'min_hours_between_contacts', 'cross_channel_week_rule',
            'collections_channel_week_rule', 'unknown_subject',
        ],
        'N2' => [
            'master_flag_off', 'mode_off', 'outreach_brake_global', 'outreach_brake_channel', 'outreach_brake_strategy',
            'ultron_abort_latch', 'marketing_agent_paused', 'voice_braked', 'human_in_control', 'legacy_autonomy_on',
            'legacy_followups_active', 'global_suppression',
        ],
        'N3' => ['studio_reference_invalid', 'catalog_incompatible', 'payment_links_disabled', 'template_violates_restriction', 'canary_only_declared'],
        'N4' => [
            'sends_disabled_in_code', 'channel_disabled', 'channel_not_live', 'template_not_approved', 'template_category_mismatch',
            'no_whatsapp_identity', 'no_voice_contact', 'voice_policy:*', 'budget_zero', 'budget_exhausted', 'channel_daily_cap',
            'assisted_capacity_reached',
        ],
        'N5' => [
            'strategy_not_published', 'strategy_paused', 'chain_unreadable', 'canary_scope_mismatch', 'calendar_outside_own_hours',
            'outside_strategy_schedule', 'max_touches_reached', 'strategy_daily_cap', 'enrollment_window_over', 'cooldown_active', 'step_guard_false',
        ],
        'N6' => ['success_reached', 'context_changed', 'human_requested_recently', 'collections_overdue_blocks_commercial', 'purpose_conflict_today', 'lower_priority'],
        'N7' => ['callback_scheduled', 'enrollment_window_over'],
    ];

    /** Los motivos que solo aplazan (`temporalReasons` del fixture). */
    public const TEMPORAL = [
        'assisted_capacity_reached', 'budget_exhausted', 'calendar_holiday', 'calendar_outside_legal_hours', 'calendar_outside_own_hours',
        'calendar_sunday', 'callback_scheduled', 'channel_daily_cap', 'collections_channel_week_rule', 'cooldown_active', 'cross_channel_week_rule',
        'daily_contact_limit', 'lower_priority', 'min_hours_between_contacts', 'outside_strategy_schedule', 'purpose_conflict_today',
        'strategy_daily_cap', 'weekly_contact_limit',
    ];

    /** Lo que en sombra se anota sin negar (`shadowWarnings` del fixture). */
    public const SHADOW_WARNINGS = [
        'assisted_capacity_reached', 'budget_exhausted', 'budget_zero', 'canary_scope_mismatch', 'channel_daily_cap', 'channel_disabled',
        'channel_not_live', 'legacy_autonomy_on', 'sends_disabled_in_code',
    ];

    /** Anotaciones del selector (N7), no motivos que nieguen. */
    public const N7_ANNOTATIONS = ['preference_applied', 'instruction_ignored_by_policy'];

    public const EFFECTS = ['success_reached' => 'exit_converted', 'context_changed' => 'cancel_context_changed', 'human_requested_recently' => 'handoff'];

    public const VOICE_POLICY_PATTERN = '/^[a-z][a-z_]{0,47}$/D';

    /** La forma de la entrada (`baseInput` del fixture): clave → tipo; `?` = puede ser null, pero tiene que estar. */
    /**
     * Entradas OPCIONALES, fuera de {@see SPEC} (ausentes o null = sin el dato):
     * `phone` = `{refs: list<64 hex>, contacts: list<{at, phoneRef}>}`, el tope
     * diario por teléfono de N1 (L9-27, L9-30; la forma la valida
     * {@see ContactBudget}).
     */
    public const OPTIONAL = ['phone'];

    public const SPEC = [
        'now' => 'instant',
        'purpose' => 'purpose',
        'channel' => 'channel',
        'subject' => ['known' => 'bool', 'isMinor' => '?bool'],
        'consent' => ['voice' => '?string', 'whatsapp' => '?string'],
        'optOut' => ['commercial' => '?bool', 'doNotContact' => '?bool', 'siblingDoNotContact' => '?bool'],
        'voice' => ['excluded' => '?bool', 'rneOk' => '?bool'],
        'history' => 'list',
        'limits' => ['perDay' => 'int', 'perWeek' => 'int', 'minHoursBetween' => 'int'],
        'ownHours' => 'any',
        'flags' => [
            'master' => 'bool', 'mode' => 'string', 'channelEnabled' => 'bool', 'channelLive' => 'bool', 'sendsEnabledInCode' => 'bool',
            'legacyAutonomy' => 'bool', 'legacyFollowupsScheduler' => 'bool',
        ],
        'brakes' => ['global' => 'bool', 'channel' => 'bool', 'strategy' => 'bool', 'ultronAbortLatch' => 'bool', 'marketingAgentPaused' => 'bool', 'voiceBrake' => 'bool'],
        'conversation' => ['humanInControl' => '?bool'],
        'globalSuppression' => 'bool',
        'studio' => ['referenceValid' => 'bool', 'catalogCompatible' => 'bool', 'paymentLinksEnabled' => 'bool', 'canaryOnly' => 'bool', 'templateViolations' => 'list'],
        'receivable' => ['known' => '?bool', 'overdueDaysMax' => '?int'],
        'collectionsBlock' => ['serverDays' => 'int', 'strategyDays' => '?int'],
        'step' => ['usesPaymentLink' => 'bool', 'templateApproved' => 'bool', 'templateCategoryMatches' => 'bool'],
        'identity' => ['voiceContact' => '?bool', 'whatsapp' => '?bool'],
        'voicePolicyReasons' => 'list',
        'budget' => [
            'dailyBudgetMicros' => 'int', 'reservedTodayMicros' => 'int', 'costMicros' => 'int', 'channelDailyCap' => 'int',
            'channelUsedToday' => 'int', 'assistedDailyCap' => 'int', 'assistedUsedToday' => 'int',
        ],
        'strategy' => [
            'trigger' => '?string', 'published' => 'bool', 'paused' => 'bool', 'chainReadable' => 'bool', 'part' => 'string',
            'inCanaryScope' => '?bool', 'schedule' => '?array', 'touches' => 'int', 'maxTouches' => 'int', 'dailyCap' => 'int',
            'usedToday' => 'int', 'enrollmentWindowEndsAt' => '?instant', 'cooldownUntil' => '?instant', 'stepGuard' => '?string',
        ],
        'context' => [
            'success' => 'string', 'audience' => 'string', 'exclusions' => '?string', 'humanRequestedRecently' => '?bool',
            'otherPurposeToday' => 'bool', 'lowerPriority' => 'bool',
        ],
        'conversational' => ['callbackAt' => '?instant'],
    ];

    /**
     * @param  array<string,mixed>  $input  con la forma de {@see SPEC}
     * @return array{verdict: string, allowed: bool, level: string|null, reasons: list<string>, retryAt: string|null, waitsForRelease: bool, effect: string|null, levels: list<array{level: string, status: string}>, warnings: list<array{level: string, reason: string}>}
     *
     * @throws InvalidArgumentException si la entrada no tiene esa forma (un error de quien llama, no una persona)
     */
    public static function decide(array $input): array
    {
        $in = self::normalize($input, self::SPEC, '');
        // L9-27, L9-30: el tope diario por teléfono es OPCIONAL (ausente o null = no se cruza, como un teléfono
        // desconocido); su forma la valida ContactBudget en N1, que siempre se evalúa.
        $in['phone'] = $input[self::OPTIONAL[0]] ?? null;
        if ($in['phone'] !== null && ! is_array($in['phone'])) {
            throw new InvalidArgumentException('phone tiene que ser {refs, contacts} o null.');
        }
        $ahora = RelativeTime::parseInstant($in['now']);
        $medio = OutreachCatalog::mediumOf($in['channel']);
        $modo = in_array($in['flags']['mode'], ['shadow', 'live'], true) ? $in['flags']['mode'] : 'off';
        $sombra = $modo === 'shadow';

        $niveles = [];
        $avisos = [];
        $decision = null;
        foreach (self::LEVELS as $nivel) {
            if ($decision !== null) {
                $niveles[] = ['level' => $nivel, 'status' => 'not_evaluated'];

                continue;
            }
            $r = match ($nivel) {
                'N1' => self::n1($in, $ahora, $medio),
                'N2' => self::n2($in, $medio, $modo),
                'N3' => self::n3($in),
                'N4' => self::n4($in, $ahora, $medio, $sombra),
                'N5' => self::n5($in, $ahora, $sombra),
                'N6' => self::n6($in, $ahora),
                'N7' => self::n7($in, $ahora),
            };
            foreach ($r['warnings'] as $aviso) {
                $avisos[] = ['level' => $nivel, 'reason' => $aviso];
            }
            if ($r['reasons'] === []) {
                $niveles[] = ['level' => $nivel, 'status' => 'pass'];

                continue;
            }
            $motivos = self::ordered($nivel, $r['reasons']);
            $temporal = array_diff($motivos, self::TEMPORAL) === [];
            $reintento = null;
            if ($temporal) {
                $desde = $ahora;
                foreach ($r['release'] as $instante) {
                    $desde = max($desde, $instante);
                }
                $reintento = ContactCalendar::nextSlot($desde, $in['ownHours'], $in['strategy']['schedule']);
            }
            $veredicto = $temporal && $reintento !== null ? 'defer' : 'deny';
            $niveles[] = ['level' => $nivel, 'status' => $veredicto];
            $decision = [$veredicto, $nivel, $motivos, $veredicto === 'defer' ? RelativeTime::format($reintento) : null, $r['effect'] ?? null];
        }

        if ($decision === null) {
            return [
                'verdict' => 'allow', 'allowed' => true, 'level' => null, 'reasons' => [], 'retryAt' => null,
                'waitsForRelease' => false, 'effect' => null, 'levels' => $niveles, 'warnings' => $avisos,
            ];
        }
        [$veredicto, $nivel, $motivos, $reintento, $efecto] = $decision;

        return [
            'verdict' => $veredicto, 'allowed' => false, 'level' => $nivel, 'reasons' => $motivos, 'retryAt' => $reintento,
            'waitsForRelease' => $nivel === 'N2', 'effect' => $efecto, 'levels' => $niveles, 'warnings' => $avisos,
        ];
    }

    // ── Niveles: cada uno devuelve sus motivos, lo que suelta cada temporal y sus avisos ──

    /** @return array{reasons: list<string>, release: array<string, DateTimeImmutable>, warnings: list<string>} */
    private static function n1(array $in, DateTimeImmutable $ahora, string $medio): array
    {
        $presupuesto = ContactBudget::check($in['history'], $ahora, $in['purpose'], $medio, $in['limits'], $in['phone']);
        $motivos = $presupuesto->reasons;
        $suelta = array_map(static fn (string $i): DateTimeImmutable => RelativeTime::parseInstant($i), $presupuesto->releaseAt);

        $consentimiento = $in['consent'][$medio];
        if ($consentimiento === 'denied') {
            $motivos[] = 'consent_denied';
        } elseif ($consentimiento !== 'granted') {
            $motivos[] = 'no_consent_for_purpose_channel';
        }
        $o = $in['optOut'];
        if ($o['doNotContact'] !== false) {
            $motivos[] = 'do_not_contact';
        }
        if ($o['siblingDoNotContact'] !== false) {
            $motivos[] = 'do_not_contact_sibling';
        }
        if ($in['purpose'] === OutreachCatalog::PURPOSE_COMMERCIAL && $o['commercial'] !== false) {
            $motivos[] = 'commercial_opt_out';
        }
        if ($medio === OutreachCatalog::MEDIUM_VOICE) {
            if ($in['voice']['excluded'] !== false) {
                $motivos[] = 'voice_excluded';
            }
            if ($in['voice']['rneOk'] !== true) {
                $motivos[] = 'rne_check_missing';
            }
        }
        if ($in['subject']['isMinor'] === true) {
            $motivos[] = 'subject_minor';
        }
        if (! $in['subject']['known']) {
            $motivos[] = 'unknown_subject';
        }

        return ['reasons' => $motivos, 'release' => $suelta, 'warnings' => []];
    }

    /** @return array{reasons: list<string>, release: array<string, DateTimeImmutable>, warnings: list<string>} */
    private static function n2(array $in, string $medio, string $modo): array
    {
        $f = $in['flags'];
        $b = $in['brakes'];
        $motivos = [];
        $avisos = [];
        if (! $f['master']) {
            $motivos[] = 'master_flag_off';
        }
        if ($modo === 'off') {
            $motivos[] = 'mode_off';
        }
        if ($b['global']) {
            $motivos[] = 'outreach_brake_global';
        }
        if ($b['channel']) {
            $motivos[] = 'outreach_brake_channel';
        }
        if ($b['strategy']) {
            $motivos[] = 'outreach_brake_strategy';
        }
        if ($medio === OutreachCatalog::MEDIUM_WHATSAPP && $b['ultronAbortLatch']) {
            $motivos[] = 'ultron_abort_latch';
        }
        if ($medio === OutreachCatalog::MEDIUM_WHATSAPP && $b['marketingAgentPaused']) {
            $motivos[] = 'marketing_agent_paused';
        }
        if ($medio === OutreachCatalog::MEDIUM_VOICE && $b['voiceBrake']) {
            $motivos[] = 'voice_braked';
        }
        if ($in['conversation']['humanInControl'] !== false) {
            $motivos[] = 'human_in_control';
        }
        if ($f['legacyAutonomy']) {
            // D9-1: el H9 en `live` exige el motor sin autonomía; en sombra no sale nada y se anota.
            $modo === 'shadow' ? $avisos[] = 'legacy_autonomy_on' : $motivos[] = 'legacy_autonomy_on';
        }
        if ($f['legacyFollowupsScheduler'] && $in['strategy']['trigger'] === 'lead.followup_due') {
            $motivos[] = 'legacy_followups_active';
        }
        if ($in['globalSuppression']) {
            $motivos[] = 'global_suppression';
        }

        return ['reasons' => $motivos, 'release' => [], 'warnings' => $avisos];
    }

    /** @return array{reasons: list<string>, release: array<string, DateTimeImmutable>, warnings: list<string>} */
    private static function n3(array $in): array
    {
        $s = $in['studio'];
        $motivos = [];
        if (! $s['referenceValid']) {
            $motivos[] = 'studio_reference_invalid';
        }
        if (! $s['catalogCompatible']) {
            // L9-21: la versión usa un hecho o un evento que el catálogo vigente ya no tiene; no se reinterpreta.
            $motivos[] = 'catalog_incompatible';
        }
        if ($in['step']['usesPaymentLink'] && ! $s['paymentLinksEnabled']) {
            $motivos[] = 'payment_links_disabled';
        }
        if ($s['templateViolations'] !== []) {
            $motivos[] = 'template_violates_restriction';
        }
        if ($s['canaryOnly'] && $in['strategy']['inCanaryScope'] !== true) {
            $motivos[] = 'canary_only_declared';
        }

        return ['reasons' => $motivos, 'release' => [], 'warnings' => []];
    }

    /** @return array{reasons: list<string>, release: array<string, DateTimeImmutable>, warnings: list<string>} */
    private static function n4(array $in, DateTimeImmutable $ahora, string $medio, bool $sombra): array
    {
        $f = $in['flags'];
        $p = $in['budget'];
        $automatico = in_array($in['channel'], OutreachCatalog::AUTOMATIC_CHANNELS, true);
        $motivos = [];
        $suelta = [];
        $avisos = [];
        $marcar = static function (string $motivo, ?DateTimeImmutable $suelto = null) use ($sombra, &$motivos, &$suelta, &$avisos): void {
            if ($sombra && in_array($motivo, self::SHADOW_WARNINGS, true)) {
                $avisos[] = $motivo;

                return;
            }
            $motivos[] = $motivo;
            if ($suelto !== null) {
                $suelta[$motivo] = $suelto;
            }
        };
        $manana = self::nextBogotaMidnight($ahora);

        if ($automatico && ! $f['sendsEnabledInCode']) {
            $marcar('sends_disabled_in_code');
        }
        if (! $f['channelEnabled']) {
            $marcar('channel_disabled');
        }
        if (! $f['channelLive']) {
            $marcar('channel_not_live');
        }
        if (! $in['step']['templateApproved']) {
            $marcar('template_not_approved');
        }
        if (! $in['step']['templateCategoryMatches']) {
            $marcar('template_category_mismatch');
        }
        if ($medio === OutreachCatalog::MEDIUM_WHATSAPP && $in['identity']['whatsapp'] !== true) {
            $marcar('no_whatsapp_identity');
        }
        if ($medio === OutreachCatalog::MEDIUM_VOICE && $in['identity']['voiceContact'] !== true) {
            $marcar('no_voice_contact');
        }
        if ($in['channel'] === OutreachCatalog::CHANNEL_VOICE_CALL) {
            foreach ($in['voicePolicyReasons'] as $motivo) {
                if (! is_string($motivo) || preg_match(self::VOICE_POLICY_PATTERN, $motivo) !== 1) {
                    throw new InvalidArgumentException('voicePolicyReasons son motivos de OutboundPolicy.');
                }
                $marcar('voice_policy:'.$motivo);
            }
        }
        if ($automatico) {
            if ($p['dailyBudgetMicros'] <= 0 || $p['channelDailyCap'] <= 0) {
                $marcar('budget_zero');
            } else {
                if ($p['dailyBudgetMicros'] < $p['reservedTodayMicros'] + $p['costMicros']) {
                    $marcar('budget_exhausted', $manana);
                }
                if ($p['channelUsedToday'] >= $p['channelDailyCap']) {
                    $marcar('channel_daily_cap', $manana);
                }
            }
        } elseif ($p['assistedDailyCap'] <= 0) {
            $marcar('budget_zero');
        } elseif ($p['assistedUsedToday'] >= $p['assistedDailyCap']) {
            $marcar('assisted_capacity_reached', $manana);
        }

        return ['reasons' => $motivos, 'release' => $suelta, 'warnings' => $avisos];
    }

    /** @return array{reasons: list<string>, release: array<string, DateTimeImmutable>, warnings: list<string>} */
    private static function n5(array $in, DateTimeImmutable $ahora, bool $sombra): array
    {
        $e = $in['strategy'];
        $motivos = [];
        $suelta = [];
        $avisos = [];
        if (! $e['published']) {
            $motivos[] = 'strategy_not_published';
        }
        if ($e['paused']) {
            $motivos[] = 'strategy_paused';
        }
        if (! $e['chainReadable']) {
            $motivos[] = 'chain_unreadable';
        }
        if ($e['part'] === 'canary' && $e['inCanaryScope'] !== true) {
            $sombra ? $avisos[] = 'canary_scope_mismatch' : $motivos[] = 'canary_scope_mismatch';
        }
        if (ContactCalendar::window($ahora, $in['ownHours'])['n5'] !== []) {
            // L9-15: el horario propio (P9-17), con la ley ya cumplida (si no, decidió N1).
            $motivos[] = ContactCalendar::OUTSIDE_OWN_HOURS;
        }
        if ($e['schedule'] !== null && ! ContactCalendar::allows($ahora, null, $e['schedule'])) {
            $motivos[] = 'outside_strategy_schedule';
        }
        if ($e['touches'] >= $e['maxTouches']) {
            $motivos[] = 'max_touches_reached';
        }
        if ($e['usedToday'] >= $e['dailyCap']) {
            $motivos[] = 'strategy_daily_cap';
            $suelta['strategy_daily_cap'] = self::nextBogotaMidnight($ahora);
        }
        if ($e['enrollmentWindowEndsAt'] !== null && RelativeTime::parseInstant($e['enrollmentWindowEndsAt']) <= $ahora) {
            $motivos[] = 'enrollment_window_over';
        }
        if ($e['cooldownUntil'] !== null && RelativeTime::parseInstant($e['cooldownUntil']) > $ahora) {
            $motivos[] = 'cooldown_active';
            $suelta['cooldown_active'] = RelativeTime::parseInstant($e['cooldownUntil']);
        }
        if ($e['stepGuard'] !== null && $e['stepGuard'] !== ConditionEvaluator::TRUE) {
            $motivos[] = 'step_guard_false';
        }

        return ['reasons' => $motivos, 'release' => $suelta, 'warnings' => $avisos];
    }

    /** @return array{reasons: list<string>, release: array<string, DateTimeImmutable>, warnings: list<string>, effect: string|null} */
    private static function n6(array $in, DateTimeImmutable $ahora): array
    {
        $x = $in['context'];
        $motivos = [];
        $suelta = [];
        if ($x['success'] === ConditionEvaluator::TRUE) {
            $motivos[] = 'success_reached';
        }
        if ($x['audience'] !== ConditionEvaluator::TRUE || ($x['exclusions'] ?? ConditionEvaluator::FALSE) !== ConditionEvaluator::FALSE) {
            $motivos[] = 'context_changed';
        }
        if ($x['humanRequestedRecently'] !== false) {
            $motivos[] = 'human_requested_recently';
        }
        if ($in['purpose'] === OutreachCatalog::PURPOSE_COMMERCIAL && self::overdueBlocks($in['receivable'], $in['collectionsBlock'])) {
            $motivos[] = 'collections_overdue_blocks_commercial';
        }
        if ($x['otherPurposeToday']) {
            $motivos[] = 'purpose_conflict_today';
            $suelta['purpose_conflict_today'] = self::nextBogotaMidnight($ahora);
        }
        if ($x['lowerPriority']) {
            $motivos[] = 'lower_priority';
            $suelta['lower_priority'] = self::nextBogotaMidnight($ahora);
        }
        $efecto = null;
        foreach (self::ordered('N6', $motivos) as $m) {
            if (isset(self::EFFECTS[$m])) {
                $efecto = self::EFFECTS[$m];

                break;
            }
        }

        return ['reasons' => $motivos, 'release' => $suelta, 'warnings' => [], 'effect' => $efecto];
    }

    /** @return array{reasons: list<string>, release: array<string, DateTimeImmutable>, warnings: list<string>} */
    private static function n7(array $in, DateTimeImmutable $ahora): array
    {
        $pedida = $in['conversational']['callbackAt'];
        if ($pedida === null || RelativeTime::parseInstant($pedida) <= $ahora) {
            // Una hora ya pasada se ignora (DF-L9).
            return ['reasons' => [], 'release' => [], 'warnings' => []];
        }
        $cuando = RelativeTime::parseInstant($pedida);
        $hueco = ContactCalendar::nextSlot($cuando, $in['ownHours'], $in['strategy']['schedule']);
        $fin = $in['strategy']['enrollmentWindowEndsAt'];
        if ($hueco === null || ($fin !== null && $hueco >= RelativeTime::parseInstant($fin))) {
            return ['reasons' => ['enrollment_window_over'], 'release' => [], 'warnings' => []];
        }

        return ['reasons' => ['callback_scheduled'], 'release' => ['callback_scheduled' => $cuando], 'warnings' => []];
    }

    // ── Ayudas ──────────────────────────────────────────────────────────────

    /**
     * P9-7, L9-20 (DF-L12): ¿bloquea la cartera vencida un contacto comercial?
     * Sí si pasa de MÁS de N días (a los N justos no) o si la fuente de
     * cartera no se pudo leer (el lado seguro). N = el del servidor
     * (`outreach.collections.commercial_block_days`, 15) o el de la
     * estrategia si es menor: la estrategia solo puede bajarlo.
     *
     * @param  array{known: bool|null, overdueDaysMax: int|null}  $cartera
     * @param  array{serverDays: int, strategyDays: int|null}  $umbral
     */
    public static function overdueBlocks(array $cartera, array $umbral): bool
    {
        if ($cartera['known'] !== true) {
            return true;
        }
        $dias = $umbral['strategyDays'] === null ? $umbral['serverDays'] : min($umbral['serverDays'], $umbral['strategyDays']);

        return $cartera['overdueDaysMax'] !== null && $cartera['overdueDaysMax'] > $dias;
    }

    /** Las 00:00 de Bogotá del día siguiente (cuando se sueltan los topes diarios). */
    public static function nextBogotaMidnight(DateTimeImmutable $now): DateTimeImmutable
    {
        return RelativeTime::utc(RelativeTime::local($now)->setTime(0, 0)->modify('+1 day'));
    }

    /**
     * Los motivos de un nivel en `reasonOrder` (sin repetidos; los
     * `voice_policy:<motivo>` en su sitio y en el orden en que llegan).
     *
     * @param  list<string>  $motivos
     * @return list<string>
     */
    private static function ordered(string $nivel, array $motivos): array
    {
        $orden = array_flip(self::REASON_ORDER[$nivel]);
        $posicion = static fn (string $m): int => $orden[str_starts_with($m, 'voice_policy:') ? 'voice_policy:*' : $m] ?? PHP_INT_MAX;
        $motivos = array_values(array_unique($motivos));
        $indice = array_flip($motivos);
        usort($motivos, static fn (string $a, string $b): int => $posicion($a) <=> $posicion($b) ?: $indice[$a] <=> $indice[$b]);

        return $motivos;
    }

    /**
     * @return array<string,mixed>
     *
     * @throws InvalidArgumentException
     */
    private static function normalize(array $input, array $spec, string $path): array
    {
        $out = [];
        foreach ($spec as $clave => $tipo) {
            $ruta = $path === '' ? $clave : "{$path}.{$clave}";
            if (! array_key_exists($clave, $input)) {
                throw new InvalidArgumentException("Falta {$ruta} en la entrada de la escalera.");
            }
            if (is_array($tipo)) {
                if (! is_array($input[$clave])) {
                    throw new InvalidArgumentException("{$ruta} tiene que ser un objeto.");
                }
                $out[$clave] = self::normalize($input[$clave], $tipo, $ruta);

                continue;
            }
            $v = $input[$clave];
            $opcional = str_starts_with($tipo, '?');
            $base = ltrim($tipo, '?');
            $bien = ($opcional && $v === null) || match ($base) {
                'any' => true,
                'bool' => is_bool($v),
                'int' => is_int($v),
                'string' => is_string($v),
                'array' => is_array($v),
                'list' => is_array($v) && array_is_list($v),
                'instant' => RelativeTime::isInstant($v),
                'purpose' => in_array($v, OutreachCatalog::PURPOSES, true),
                'channel' => is_string($v) && isset(OutreachCatalog::STEP_CHANNELS[$v]),
                default => false,
            };
            if (! $bien) {
                throw new InvalidArgumentException("{$ruta} no tiene el tipo {$tipo}.");
            }
            $out[$clave] = $v;
        }

        return $out;
    }
}
