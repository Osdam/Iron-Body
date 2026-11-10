<?php

namespace App\Services\UltronStudio;

use App\Services\Outreach\Core\CanonicalCondition;
use App\Services\Outreach\Core\CanonicalJson;
use App\Services\Outreach\Core\ChannelSelector;
use App\Services\Outreach\Core\ConditionEvaluator;
use App\Services\Outreach\Core\ContactBudget;
use App\Services\Outreach\Core\DecisionTrace;
use App\Services\Outreach\Core\DecisionTraceText;
use App\Services\Outreach\Core\EnrollmentGate;
use App\Services\Outreach\Core\FactSnapshot;
use App\Services\Outreach\Core\OutreachCatalog;
use App\Services\Outreach\Core\PolicyLadder;
use App\Services\Outreach\Core\RelativeTime;
use DateTimeImmutable;

/**
 * LA SIMULACIÓN EXPLICABLE Y REPRODUCIBLE de una estrategia (contrato H9,
 * D9-12, T12). PURA: no lee la base, ni la configuración, ni el reloj (`now`
 * entra como argumento). Con el MISMO contenido, las MISMAS personas (la misma
 * foto de hechos) y el MISMO instante da la misma decisión y la misma
 * `traceHash` por persona, y el mismo resultado.
 *
 * POR PERSONA, en el orden del planificador (D9-8), sin escribir nada:
 *  1. la puerta de inscripción (`EnrollmentGate`: audiencia → exclusiones →
 *     éxito; solo `true` inscribe, `unknown` deja fuera con `insufficient_facts`);
 *  2. si se inscribe, el PRIMER paso de la secuencia: la escalera N1–N7
 *     (`PolicyLadder`) por cada canal del paso y el selector (`ChannelSelector`),
 *     en modo SOMBRA (los avisos de N4 no niegan, D9-8 «Sombra»; L9-16);
 *  3. la traza (`DecisionTrace`, sin datos personales) y su texto en español
 *     (`DecisionTraceText`, los mismos textos que espeja el CRM).
 * Y la proyección de la secuencia (los pasos con sus retrasos y canales).
 *
 * LOS HALLAZGOS (D9-12): bloqueantes —audiencia vacía, saturación (más
 * inscritos que el tope diario × la ventana), todos negados por
 * consentimiento, y los bloqueantes de la validación (plantilla no aprobada,
 * finalidad incoherente con la categoría…)— y avisos: el resto.
 *
 * Lo que guarda {@see StudioStrategySimulations} son SOLO los recuentos, los
 * hallazgos y las huellas: nunca las trazas por persona (§6.1).
 */
final class StudioStrategySimulator
{
    public const FORMAT = 'ultron-strategy-simulation/1';

    /** «`human_requested` reciente» (N6): los días que mira la simulación. */
    public const HUMAN_REQUESTED_DAYS = 30;

    /** El umbral del bloqueo comercial por cartera vencida del servidor (P9-7, L9-20: 15 días). */
    public const COLLECTIONS_BLOCK_DAYS = 15;

    public const MAX_TRACES = 50;

    // Hallazgos propios de la simulación (D9-12).
    public const AUDIENCE_EMPTY = 'audience_empty';

    public const AUDIENCE_SATURATION = 'audience_saturation';

    public const ALL_DENIED_CONSENT = 'all_denied_by_consent';

    public const MOSTLY_UNKNOWN = 'mostly_insufficient_facts';

    public const SHADOW_WARNINGS = 'shadow_warnings';

    public const CONSENT_REASONS = ['consent_denied', 'no_consent_for_purpose_channel'];

    /** Lo que se dice de una persona que no entra (la puerta de inscripción). */
    public const GATE_TEXTS = [
        EnrollmentGate::NOT_IN_AUDIENCE => 'No cumple la audiencia de la estrategia.',
        EnrollmentGate::INSUFFICIENT_FACTS => 'Faltan datos para decidir: queda fuera (el lado seguro).',
        EnrollmentGate::EXCLUDED => 'Cumple una exclusión de la estrategia.',
        EnrollmentGate::SUCCESS_REACHED => 'El objetivo ya está cumplido.',
    ];

    public const FINDING_MESSAGES = [
        self::AUDIENCE_EMPTY => 'Nadie de la simulación entra en la audiencia.',
        self::AUDIENCE_SATURATION => 'La audiencia supera el tope diario por la ventana de la inscripción.',
        self::ALL_DENIED_CONSENT => 'Todas las personas que entran quedan negadas por consentimiento.',
        self::MOSTLY_UNKNOWN => 'La mayoría queda fuera por falta de datos.',
        self::SHADOW_WARNINGS => 'En vivo, el canal o el presupuesto negarían el contacto (en sombra solo es un aviso).',
    ];

    /**
     * @param  array<string,mixed>  $content  el contenido CANÓNICO (validado)
     * @param  array{audience: CanonicalCondition, exclusions: CanonicalCondition|null, success: CanonicalCondition, guards: array<int, CanonicalCondition|null>}  $conditions
     * @param  list<array{ref: string, snapshot: FactSnapshot, person: array<string,mixed>, receivable: array{known: bool|null, overdueDaysMax: int|null}, canary: array<string, bool|null>, phone: array<string,mixed>|null}>  $people
     * @param  array{studio: array{referenceValid: bool, paymentLinksEnabled: bool, canaryOnly: bool}, templates: array<string, array{approved: bool, categoryMatches: bool, usesPaymentLink: bool, violations: list<string>}>, validation: array{blocking: list<array<string,mixed>>, warnings: list<array<string,mixed>>}, estimatedAudience?: int|null}  $context  `templates` por canal del primer paso
     * @return array{format:string,at:string,contentHash:string,evaluatorVersion:string,catalogVersion:string,people:list<array<string,mixed>>,counts:array<string,mixed>,findings:array{blocking:list<array<string,mixed>>,warnings:list<array<string,mixed>>},sequence:list<array<string,mixed>>,decisionsHash:string}
     */
    public static function run(array $content, string $contentHash, array $conditions, string $strategyUuid, array $people, DateTimeImmutable $now, array $context): array
    {
        $personas = [];
        foreach ($people as $p) {
            $personas[] = self::person($content, $conditions, $p, $now, $context);
        }
        $recuentos = self::counts($personas);

        return [
            'format' => self::FORMAT,
            'at' => RelativeTime::format($now),
            'contentHash' => $contentHash,
            'evaluatorVersion' => ConditionEvaluator::VERSION,
            'catalogVersion' => OutreachCatalog::VERSION,
            'people' => $personas,
            'counts' => $recuentos,
            'findings' => self::findings($content, $recuentos, $personas, $context),
            'sequence' => self::projection($content),
            // La huella de las decisiones (sin datos de nadie): misma entrada, misma huella (T12).
            'decisionsHash' => CanonicalJson::hash(array_map(static fn (array $p): array => [$p['ref'], $p['decision']['kind'], $p['traceHash']], $personas)),
        ];
    }

    /**
     * Una persona: la puerta, la escalera por canal, el selector, la traza y su texto.
     *
     * @param  array<string,mixed>  $content
     * @param  array<string,mixed>  $conditions
     * @param  array<string,mixed>  $p
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    private static function person(array $content, array $conditions, array $p, DateTimeImmutable $now, array $context): array
    {
        /** @var FactSnapshot $foto */
        $foto = $p['snapshot'];
        $puerta = EnrollmentGate::evaluate($conditions['audience'], $conditions['exclusions'], $conditions['success'], $foto, $now);
        $condiciones = ['audience' => $puerta['audience'], 'exclusions' => $puerta['exclusions'], 'success' => $puerta['success']];

        if (! $puerta['enroll']) {
            $decision = ['kind' => 'exclude', 'level' => null, 'reasons' => [$puerta['reason']], 'retryAt' => null];
            $traza = DecisionTrace::build($decision, $foto, $now, $condiciones, ['gate' => ['reason' => $puerta['reason']]]);

            return [
                'ref' => $p['ref'],
                'gate' => $puerta['reason'],
                'decision' => $decision + ['channel' => null],
                'factsHash' => $foto->hash(),
                'traceHash' => DecisionTrace::hash($traza),
                'trace' => $traza,
                'text' => [self::GATE_TEXTS[$puerta['reason']] ?? 'No entra en la estrategia.'],
                'warnings' => [],
            ];
        }

        $paso = $content['sequence'][0];
        $guarda = $conditions['guards'][0] ?? null;
        $guardaValor = $guarda === null ? ConditionEvaluator::TRUE : ConditionEvaluator::evaluate($guarda, $foto, $now, 'guard')->label();
        if ($guarda !== null) {
            $condiciones['guard'] = ConditionEvaluator::evaluate($guarda, $foto, $now, 'guard');
        }
        $contexto = EnrollmentGate::context($puerta) + [
            'humanRequestedRecently' => self::humanRequestedRecently($foto, $now),
            'otherPurposeToday' => false,
            'lowerPriority' => false,
        ];
        $porCanal = [];
        $escaleras = [];
        foreach ($paso['channels'] as $canal) {
            $escaleras[$canal] = PolicyLadder::decide(self::ladderInput($content, $canal, $p, $now, $context, $guardaValor, $contexto));
            $porCanal[$canal] = [
                'verdict' => $escaleras[$canal]['verdict'],
                'level' => $escaleras[$canal]['level'],
                'reasons' => $escaleras[$canal]['reasons'],
                'retryAt' => $escaleras[$canal]['retryAt'],
            ];
        }
        $eleccion = ChannelSelector::choose($paso['channels'], $porCanal);
        $elegido = $eleccion['channel'];
        $principal = $escaleras[$elegido ?? $paso['channels'][0]];
        $decision = match ($eleccion['verdict']) {
            ChannelSelector::SELECTED => ['kind' => 'plan', 'level' => null, 'reasons' => [], 'retryAt' => null],
            ChannelSelector::DEFER => ['kind' => 'defer', 'level' => self::firstLevel($eleccion, 'defer'), 'reasons' => self::reasonsOf($eleccion, 'defer'), 'retryAt' => $eleccion['retryAt']],
            default => ['kind' => 'deny', 'level' => self::firstLevel($eleccion, 'deny'), 'reasons' => self::reasonsOf($eleccion, 'deny'), 'retryAt' => null],
        };
        $traza = DecisionTrace::build($decision, $foto, $now, $condiciones, [
            'gate' => ['reason' => $puerta['reason']],
            'policy' => self::ladderForTrace($principal),
            'channel' => $eleccion,
        ]);

        return [
            'ref' => $p['ref'],
            'gate' => $puerta['reason'],
            'decision' => $decision + ['channel' => $elegido],
            'factsHash' => $foto->hash(),
            'traceHash' => DecisionTrace::hash($traza),
            'trace' => $traza,
            'text' => DecisionTraceText::explain($principal, $eleccion),
            'warnings' => array_values(array_unique(array_map(static fn (array $w): string => (string) $w['reason'], $principal['warnings']))),
        ];
    }

    /**
     * La entrada COMPLETA de la escalera para un canal: lo de la persona (de
     * la foto y de su política) y lo de la simulación (modo sombra, la
     * estrategia como publicada en `rest`, sin frenos propios, el estado de la
     * referencia del Studio y de la plantilla del canal).
     *
     * @param  array<string,mixed>  $content
     * @param  array<string,mixed>  $p
     * @param  array<string,mixed>  $context
     * @param  array<string,mixed>  $contexto
     * @return array<string,mixed>
     */
    public static function ladderInput(array $content, string $canal, array $p, DateTimeImmutable $now, array $context, string $guarda, array $contexto): array
    {
        $persona = $p['person'];
        $medio = OutreachCatalog::mediumOf($canal);
        $plantilla = $context['templates'][$canal] ?? ['approved' => false, 'categoryMatches' => false, 'usesPaymentLink' => false, 'violations' => []];
        $automatico = in_array($canal, OutreachCatalog::AUTOMATIC_CHANNELS, true);

        return [
            'now' => RelativeTime::format($now),
            'purpose' => $content['purpose'],
            'channel' => $canal,
            'subject' => $persona['subject'],
            'consent' => $persona['consent'],
            'optOut' => $persona['optOut'],
            'voice' => $persona['voice'],
            'history' => $persona['history'],
            'limits' => ContactBudget::effectiveLimits(),
            'ownHours' => null,
            'flags' => [
                'master' => true,
                'mode' => 'shadow',
                // En sombra, el canal apagado o sin implementar y la constante son avisos (L9-16).
                'channelEnabled' => ! $automatico,
                'channelLive' => ! $automatico,
                'sendsEnabledInCode' => false,
                'legacyAutonomy' => (bool) ($persona['flags']['legacyAutonomy'] ?? false),
                'legacyFollowupsScheduler' => (bool) ($persona['flags']['legacyFollowupsScheduler'] ?? false),
            ],
            'brakes' => [
                'global' => false, 'channel' => false, 'strategy' => false,
                'ultronAbortLatch' => (bool) ($persona['brakes']['ultronAbortLatch'] ?? false),
                'marketingAgentPaused' => (bool) ($persona['brakes']['marketingAgentPaused'] ?? false),
                'voiceBrake' => (bool) ($persona['brakes']['voiceBrake'] ?? false),
            ],
            'conversation' => $persona['conversation'],
            'globalSuppression' => false,
            'studio' => [
                'referenceValid' => $context['studio']['referenceValid'],
                'catalogCompatible' => true,
                'paymentLinksEnabled' => $context['studio']['paymentLinksEnabled'],
                'canaryOnly' => $context['studio']['canaryOnly'],
                'templateViolations' => $plantilla['violations'],
            ],
            'receivable' => $p['receivable'],
            'collectionsBlock' => ['serverDays' => self::COLLECTIONS_BLOCK_DAYS, 'strategyDays' => $content['limits']['commercialBlockOverdueDays'] ?? null],
            'step' => ['usesPaymentLink' => $plantilla['usesPaymentLink'], 'templateApproved' => $plantilla['approved'], 'templateCategoryMatches' => $plantilla['categoryMatches']],
            'identity' => $persona['identity'],
            'voicePolicyReasons' => [],
            'budget' => [
                'dailyBudgetMicros' => 0, 'reservedTodayMicros' => 0, 'costMicros' => 0, 'channelDailyCap' => 0,
                'channelUsedToday' => 0, 'assistedDailyCap' => 0, 'assistedUsedToday' => 0,
            ],
            'strategy' => [
                'trigger' => $content['trigger']['event'],
                'published' => true,
                'paused' => false,
                'chainReadable' => true,
                'part' => 'rest',
                'inCanaryScope' => $medio === null ? null : ($p['canary'][$medio] ?? null),
                'schedule' => $content['schedule'] ?? null,
                'touches' => 0,
                'maxTouches' => $content['limits']['maxTouches'],
                'dailyCap' => $content['limits']['contactsPerDay'],
                'usedToday' => 0,
                'enrollmentWindowEndsAt' => RelativeTime::format($now->modify('+'.$content['limits']['enrollmentDays'].' days')),
                'cooldownUntil' => null,
                'stepGuard' => $guarda,
            ],
            'context' => $contexto,
            'conversational' => ['callbackAt' => null],
        ] + ($p['phone'] !== null ? ['phone' => $p['phone']] : []);
    }

    /**
     * ¿Pidió una persona hace poco? Con la fuente de eventos sin datos, no se
     * sabe (null: la escalera niega en N6, el lado seguro, L9-26).
     */
    public static function humanRequestedRecently(FactSnapshot $foto, DateTimeImmutable $now): ?bool
    {
        if ($foto->events === null) {
            return null;
        }
        foreach ($foto->events as $e) {
            if ($e['key'] === 'human_requested' && RelativeTime::withinLast(RelativeTime::parseInstant($e['at']), $now, self::HUMAN_REQUESTED_DAYS, 'days')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Los recuentos (lo único que se guarda de las personas).
     *
     * @param  list<array<string,mixed>>  $personas
     * @return array<string,mixed>
     */
    public static function counts(array $personas): array
    {
        $r = ['evaluated' => count($personas), 'byGate' => [], 'byKind' => [], 'byLevel' => [], 'byReason' => [], 'byChannel' => [], 'warnings' => []];
        foreach ($personas as $p) {
            $r['byGate'][$p['gate']] = ($r['byGate'][$p['gate']] ?? 0) + 1;
            $r['byKind'][$p['decision']['kind']] = ($r['byKind'][$p['decision']['kind']] ?? 0) + 1;
            if ($p['decision']['level'] !== null) {
                $r['byLevel'][$p['decision']['level']] = ($r['byLevel'][$p['decision']['level']] ?? 0) + 1;
            }
            foreach ($p['decision']['reasons'] as $m) {
                $r['byReason'][$m] = ($r['byReason'][$m] ?? 0) + 1;
            }
            if ($p['decision']['channel'] !== null) {
                $r['byChannel'][$p['decision']['channel']] = ($r['byChannel'][$p['decision']['channel']] ?? 0) + 1;
            }
            foreach ($p['warnings'] as $w) {
                $r['warnings'][$w] = ($r['warnings'][$w] ?? 0) + 1;
            }
        }
        foreach (['byGate', 'byKind', 'byLevel', 'byReason', 'byChannel', 'warnings'] as $k) {
            ksort($r[$k], SORT_STRING);
        }
        $r['enrolled'] = $r['byGate'][EnrollmentGate::ENROLL] ?? 0;

        return $r;
    }

    /**
     * Los hallazgos de D9-12, ordenados por id.
     *
     * @param  array<string,mixed>  $content
     * @param  array<string,mixed>  $recuentos
     * @param  list<array<string,mixed>>  $personas
     * @param  array<string,mixed>  $context
     * @return array{blocking:list<array<string,mixed>>,warnings:list<array<string,mixed>>}
     */
    public static function findings(array $content, array $recuentos, array $personas, array $context): array
    {
        $bloqueantes = [];
        $avisos = [];
        $nuevo = static fn (string $code, string $level): array => ['id' => "{$code}@simulation", 'code' => $code, 'key' => 'simulation', 'level' => $level, 'message' => self::FINDING_MESSAGES[$code]];
        foreach ($context['validation']['blocking'] ?? [] as $b) {
            $bloqueantes[(string) $b['id']] = $b;
        }
        foreach ($context['validation']['warnings'] ?? [] as $w) {
            $avisos[(string) $w['id']] = $w;
        }
        $inscritos = (int) $recuentos['enrolled'];
        if ($recuentos['evaluated'] > 0 && $inscritos === 0) {
            $bloqueantes[self::AUDIENCE_EMPTY.'@simulation'] = $nuevo(self::AUDIENCE_EMPTY, StudioStrategyValidator::LEVEL_BLOCKING);
        }
        $estimada = $context['estimatedAudience'] ?? $inscritos;
        if (is_int($estimada) && $estimada > $content['limits']['contactsPerDay'] * $content['limits']['enrollmentDays']) {
            $bloqueantes[self::AUDIENCE_SATURATION.'@simulation'] = $nuevo(self::AUDIENCE_SATURATION, StudioStrategyValidator::LEVEL_BLOCKING);
        }
        $entran = array_values(array_filter($personas, static fn (array $p): bool => $p['gate'] === EnrollmentGate::ENROLL));
        if ($entran !== [] && array_filter($entran, static fn (array $p): bool => ! self::deniedByConsent($p)) === []) {
            $bloqueantes[self::ALL_DENIED_CONSENT.'@simulation'] = $nuevo(self::ALL_DENIED_CONSENT, StudioStrategyValidator::LEVEL_BLOCKING);
        }
        if ($recuentos['evaluated'] > 0 && ($recuentos['byGate'][EnrollmentGate::INSUFFICIENT_FACTS] ?? 0) * 2 > $recuentos['evaluated']) {
            $avisos[self::MOSTLY_UNKNOWN.'@simulation'] = $nuevo(self::MOSTLY_UNKNOWN, StudioStrategyValidator::LEVEL_WARNING);
        }
        if ($recuentos['warnings'] !== []) {
            $avisos[self::SHADOW_WARNINGS.'@simulation'] = $nuevo(self::SHADOW_WARNINGS, StudioStrategyValidator::LEVEL_WARNING);
        }
        ksort($bloqueantes, SORT_STRING);
        ksort($avisos, SORT_STRING);

        return ['blocking' => array_values($bloqueantes), 'warnings' => array_values($avisos)];
    }

    /**
     * La proyección de la secuencia: los pasos con su retraso, sus canales y
     * lo que hace cada resultado (sin personas).
     *
     * @param  array<string,mixed>  $content
     * @return list<array<string,mixed>>
     */
    public static function projection(array $content): array
    {
        return array_map(static fn (array $paso): array => [
            'id' => $paso['id'],
            'delay' => $paso['delay'],
            'channels' => $paso['channels'],
            'hasGuard' => isset($paso['guard']),
            'on' => $paso['on'] ?? [],
            'waitFor' => $paso['waitFor'] ?? null,
        ], $content['sequence']);
    }

    /** @param  array<string,mixed>  $p */
    private static function deniedByConsent(array $p): bool
    {
        return $p['decision']['kind'] === 'deny' && array_diff($p['decision']['reasons'], self::CONSENT_REASONS) === [] && $p['decision']['reasons'] !== [];
    }

    /**
     * La escalera para la traza (sin repetir lo que ya lleva el selector).
     *
     * @param  array<string,mixed>  $l
     * @return array<string,mixed>
     */
    private static function ladderForTrace(array $l): array
    {
        return [
            'verdict' => $l['verdict'], 'level' => $l['level'], 'reasons' => $l['reasons'], 'retryAt' => $l['retryAt'],
            'effect' => $l['effect'], 'waitsForRelease' => $l['waitsForRelease'], 'levels' => $l['levels'], 'warnings' => $l['warnings'],
        ];
    }

    /** @param  array<string,mixed>  $eleccion */
    private static function firstLevel(array $eleccion, string $veredicto): ?string
    {
        foreach ($eleccion['perChannel'] as $c) {
            if ($c['verdict'] === $veredicto) {
                return $c['level'];
            }
        }

        return null;
    }

    /**
     * Los motivos de los canales con ese veredicto, en su orden y sin repetir.
     *
     * @param  array<string,mixed>  $eleccion
     * @return list<string>
     */
    private static function reasonsOf(array $eleccion, string $veredicto): array
    {
        $motivos = [];
        foreach ($eleccion['perChannel'] as $c) {
            if ($c['verdict'] === $veredicto) {
                foreach ($c['reasons'] as $m) {
                    $motivos[$m] = true;
                }
            }
        }

        return array_map('strval', array_keys($motivos));
    }
}
