<?php

namespace Tests\Unit\Outreach\Core;

use App\Services\Outreach\Core\CanonicalJson;
use App\Services\Outreach\Core\ChannelSelector;
use App\Services\Outreach\Core\DecisionTraceText;
use App\Services\Outreach\Core\OutreachCatalog;
use App\Services\Outreach\Core\PolicyLadder;

/**
 * `trace-text-cases.json` (L9-25): lo que el CRM (FE9) espeja de los textos de
 * la traza, EXPORTADO de la implementación: el diccionario de
 * {@see DecisionTraceText::export()} y casos de {@see DecisionTraceText::explain()}
 * sobre decisiones REALES de {@see PolicyLadder} y {@see ChannelSelector}, con
 * una entrada propia y sintética (sin datos de nadie y sin depender de los
 * fixtures de TEST9-a). Lo escribe `tools/export-trace-text-cases.php`;
 * `DecisionTraceAndPurityTest` exige que el fichero sea, byte a byte, el
 * export vigente.
 */
final class TraceTextCases
{
    public const PATH = __DIR__.'/fixtures/trace-text-cases.json';

    /** Una entrada de la escalera que permite (la forma de `PolicyLadder::SPEC`). */
    private const BASE = [
        'now' => '2026-11-10T15:00:00Z',
        'purpose' => 'commercial',
        'channel' => 'assisted:whatsapp',
        'subject' => ['known' => true, 'isMinor' => false],
        'consent' => ['voice' => 'granted', 'whatsapp' => 'granted'],
        'optOut' => ['commercial' => false, 'doNotContact' => false, 'siblingDoNotContact' => false],
        'voice' => ['excluded' => false, 'rneOk' => true],
        'history' => [],
        'limits' => ['perDay' => 1, 'perWeek' => 2, 'minHoursBetween' => 48],
        'ownHours' => null,
        'flags' => ['master' => true, 'mode' => 'live', 'channelEnabled' => true, 'channelLive' => true, 'sendsEnabledInCode' => false, 'legacyAutonomy' => false, 'legacyFollowupsScheduler' => false],
        'brakes' => ['global' => false, 'channel' => false, 'strategy' => false, 'ultronAbortLatch' => false, 'marketingAgentPaused' => false, 'voiceBrake' => false],
        'conversation' => ['humanInControl' => false],
        'globalSuppression' => false,
        'studio' => ['referenceValid' => true, 'catalogCompatible' => true, 'paymentLinksEnabled' => true, 'canaryOnly' => false, 'templateViolations' => []],
        'receivable' => ['known' => true, 'overdueDaysMax' => null],
        'collectionsBlock' => ['serverDays' => 15, 'strategyDays' => null],
        'step' => ['usesPaymentLink' => false, 'templateApproved' => true, 'templateCategoryMatches' => true],
        'identity' => ['voiceContact' => true, 'whatsapp' => true],
        'voicePolicyReasons' => [],
        'budget' => ['dailyBudgetMicros' => 0, 'reservedTodayMicros' => 0, 'costMicros' => 0, 'channelDailyCap' => 0, 'channelUsedToday' => 0, 'assistedDailyCap' => 20, 'assistedUsedToday' => 0],
        'strategy' => [
            'trigger' => 'membership.expiring_window', 'published' => true, 'paused' => false, 'chainReadable' => true, 'part' => 'rest', 'inCanaryScope' => true,
            'schedule' => null, 'touches' => 0, 'maxTouches' => 6, 'dailyCap' => 50, 'usedToday' => 0, 'enrollmentWindowEndsAt' => '2026-11-30T05:00:00Z',
            'cooldownUntil' => null, 'stepGuard' => 'true',
        ],
        'context' => ['success' => 'false', 'audience' => 'true', 'exclusions' => 'false', 'humanRequestedRecently' => false, 'otherPurposeToday' => false, 'lowerPriority' => false],
        'conversational' => ['callbackAt' => null],
    ];

    public static function json(): string
    {
        return json_encode(self::build(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }

    /** @return array<string, mixed> */
    public static function build(): array
    {
        $diccionario = DecisionTraceText::export();
        $telefono = str_repeat('0', 63).'1';

        $explicaciones = [];
        foreach ([
            'permitido' => [],
            'N1 niega: autorización negada y «no contactar»' => ['consent' => ['whatsapp' => 'denied'], 'optOut' => ['doNotContact' => true]],
            'N1 aplaza: hubo un contacto hace dos horas (tope diario y 48 h)' => ['history' => [['at' => '2026-11-10T13:00:00Z', 'medium' => 'whatsapp', 'purpose' => 'commercial', 'source' => 'outreach']]],
            'N1 aplaza: su teléfono ya tuvo un contacto hoy (L9-27)' => ['phone' => ['refs' => [$telefono], 'contacts' => [['at' => '2026-11-10T13:00:00Z', 'phoneRef' => $telefono]]]],
            'N1 niega: domingo con el horario propio mal configurado (sin hueco)' => ['now' => '2026-11-08T15:00:00Z', 'ownHours' => 'invalid'],
            'N2 espera: PARADA GLOBAL' => ['brakes' => ['global' => true]],
            'N3 niega: referencia que no cuadra y catálogo incompatible' => ['studio' => ['referenceValid' => false, 'catalogCompatible' => false]],
            'N4 niega: voz con el envío cerrado en código y la política de llamadas' => ['channel' => 'voice_call', 'voicePolicyReasons' => ['outside_window']],
            'sombra: los avisos de N4 no niegan' => ['flags' => ['mode' => 'shadow'], 'channel' => 'whatsapp_template'],
            'N5 aplaza: fuera del horario propio (L9-15)' => ['now' => '2026-11-10T13:30:00Z', 'ownHours' => ['weekdays' => [540, 1080], 'saturday' => [540, 780]]],
            'N6 niega y deriva: pidió una persona y tiene cartera vencida' => ['context' => ['humanRequestedRecently' => true], 'receivable' => ['overdueDaysMax' => 60]],
            'N6 cierra: objetivo cumplido' => ['context' => ['success' => 'true']],
            'N7 aplaza: pidió que lo contacten el jueves a las 15:00' => ['conversational' => ['callbackAt' => '2026-11-12T20:00:00Z']],
        ] as $nombre => $parche) {
            $decision = PolicyLadder::decide(array_replace_recursive(self::BASE, $parche));
            $explicaciones[] = self::caso($nombre, false, $decision, null);
        }

        // El selector: la plantilla no está aprobada (N4), la voz pedida está frenada (N2) y el asistido sale.
        $canales = ['whatsapp_template', 'voice_call', 'assisted:whatsapp'];
        $porCanal = [];
        foreach ($canales as $canal) {
            $parche = ['channel' => $canal, 'step' => ['templateApproved' => $canal !== 'whatsapp_template'], 'brakes' => ['voiceBrake' => true], 'flags' => ['sendsEnabledInCode' => true]];
            $porCanal[$canal] = PolicyLadder::decide(array_replace_recursive(self::BASE, $parche));
        }
        $eleccion = ChannelSelector::choose($canales, $porCanal, 'voice');
        $explicaciones[] = self::caso('selector: pidió voz, la voz está frenada y sale el asistido', false, $porCanal[$eleccion['channel']], $eleccion);

        // Códigos sin texto (no salen del núcleo; las reservas de explain()).
        $explicaciones[] = self::caso('reservas: códigos sin texto (sintético)', true, [
            'verdict' => 'deny', 'level' => 'N9', 'reasons' => ['zzz_unknown'], 'retryAt' => null, 'warnings' => [['level' => 'N4', 'reason' => 'zzz_unknown']], 'effect' => 'zzz_unknown',
        ], ['perChannel' => [['channel' => 'voice_call', 'verdict' => 'zzz_unknown', 'level' => null, 'reasons' => ['zzz_unknown'], 'retryAt' => null]], 'annotations' => ['zzz_unknown']]);

        $texto = [];
        foreach (['voice_policy', DecisionTraceText::VOICE_POLICY_PREFIX.'outside_window', 'calendar_outside_own_hours', 'daily_contact_limit', 'zzz_unknown',
            // W9: el rechazo de Meta con su código usa el texto de `meta_error` (cierre de B9).
            'meta_error', DecisionTraceText::META_ERROR_PREFIX.'131026'] as $codigo) {
            $texto[] = ['code' => $codigo, 'expected' => DecisionTraceText::text($codigo)];
        }

        return [
            'fixture' => 'trace-text-cases.json',
            'version' => 1,
            'contract' => 'backend/docs/ultron-studio/ULTRON_STUDIO_H9_CONTRACT.md (§8 «Simulación» y «Accesibilidad»; L9-25)',
            'generatedBy' => 'B9-core: DecisionTraceText::export() y DecisionTraceText::explain() sobre decisiones reales de PolicyLadder y ChannelSelector (backend/tests/Unit/Outreach/Core/tools/export-trace-text-cases.php)',
            'readme' => 'Lo que FE9 espeja. dictionary: textos por código, niveles, veredictos, plantillas y reservas de explain(). text: text(code) en los casos raros (voice_policy:<motivo> usa el texto de voice_policy; un código desconocido es null). explain: explain(ladder, channel) → frases, en ese orden; solo se leen verdict, level, reasons, retryAt, warnings y effect de la escalera, y perChannel y annotations del selector. retryAt se escribe en Bogotá (dd-mm-aaaa y HH:mm). Entradas sintéticas: sin datos de personas.',
            'catalogVersion' => OutreachCatalog::VERSION,
            'dictionaryHash' => CanonicalJson::hash($diccionario),
            'dictionary' => $diccionario,
            'text' => $texto,
            'explain' => $explicaciones,
        ];
    }

    /**
     * @param  array<string, mixed>  $ladder
     * @param  array<string, mixed>|null  $channel
     * @return array<string, mixed>
     */
    private static function caso(string $nombre, bool $sintetico, array $ladder, ?array $channel): array
    {
        return ['name' => $nombre, 'synthetic' => $sintetico, 'ladder' => $ladder, 'channel' => $channel, 'expected' => DecisionTraceText::explain($ladder, $channel)];
    }
}
