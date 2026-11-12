<?php

namespace App\Services\UltronStudio;

use App\Exceptions\UltronStudioException;
use App\Models\UltronStudio\StudioStrategy;
use App\Models\UltronStudio\StudioStrategySimulation;
use App\Models\UltronStudio\StudioStrategyVersion;
use App\Services\Outreach\Core\CanonicalCondition;
use App\Services\Outreach\Core\CanonicalJson;
use App\Services\Outreach\Core\ConditionCanonicalizer;
use App\Services\Outreach\Core\ConditionEvaluator;
use App\Services\Outreach\Core\EnrollmentGate;
use App\Services\Outreach\Core\FactSnapshot;
use App\Services\Outreach\Core\OutcomeTable;
use App\Services\Outreach\Core\OutreachCatalog;
use App\Services\Outreach\Core\RelativeTime;
use App\Services\Outreach\Facts\AttendanceFacts;
use App\Services\Outreach\Facts\BatchSnapshot;
use App\Services\Outreach\Facts\BatchSnapshotBuilder;
use App\Services\Outreach\Facts\Candidate;
use App\Services\Outreach\Facts\ClassFacts;
use App\Services\Outreach\Facts\CoreProjection;
use App\Services\Outreach\Facts\FactSelection;
use App\Services\Outreach\Facts\LeadFacts;
use App\Services\Outreach\Facts\MembershipFacts;
use App\Services\Outreach\Facts\PaymentFacts;
use App\Services\Outreach\Facts\ReceivableFacts;
use App\Services\Outreach\Facts\ValuationFacts;
use App\Services\Outreach\Run\OutreachConfig;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * LAS SIMULACIONES de una estrategia (contrato H9, D9-12, §6.1, §7 #9, #10;
 * T12). El motor es {@see StudioStrategySimulator} (puro); aquí se arman las
 * personas, se guarda lo que se puede guardar y se responde.
 *
 *  - `synthetic` (`ultron.strategies_edit`): hasta {@see MAX_SYNTHETIC}
 *    personas SINTÉTICAS del CRM: hechos tipados y validados contra el
 *    catálogo (un hecho que no existe, un tipo que no corresponde o un valor
 *    fuera de rango es 422 con todas las claves), y si se quiere consentimiento,
 *    historial, eventos y transiciones sintéticos. El instante (`at`) es parte
 *    de la entrada: la misma entrada da el mismo resultado (T12).
 *  - `sample` (`commercial_automation.simulate_real`): el PREFILTRO DEL
 *    DISPARADOR AHORA y una muestra DETERMINISTA de hasta {@see MAX_SAMPLE}
 *    personas reales, leídas en SOLO LECTURA por `BatchSnapshotBuilder` (sin
 *    inscripciones, trabajos ni reservas). La respuesta lleva hasta 50 trazas
 *    con `subjectRef` (seudónimo), NUNCA nombres ni teléfonos.
 *
 * SE GUARDA (`ultron_studio_strategy_simulations`, solo inserción): la huella
 * de la entrada y la del resultado, los recuentos, los hallazgos y la huella
 * de las decisiones (≤ 16 KB). NUNCA las trazas por persona.
 */
final class StudioStrategySimulations
{
    public const MAX_SYNTHETIC = 50;

    public const MAX_SAMPLE = 200;

    public const MAX_RESULT_BYTES = 16384;

    public const MAX_ROWS_PER_PERSON = 20;

    public const REF_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,15}$/D';

    public const CONSENT_VALUES = ['denied', 'granted', 'missing'];

    public const MAX_HOURS_AGO = 17520;

    public function __construct(
        private readonly StudioAudit $audit,
        private readonly BatchSnapshotBuilder $snapshots = new BatchSnapshotBuilder,
    ) {}

    // ── Simular ─────────────────────────────────────────────────────────────

    /**
     * Simula una VERSIÓN (sus bytes exactos) o el BORRADOR de una estrategia
     * (`$version` null), y guarda el resultado.
     *
     * @param  array<string,mixed>  $input  `{people, at?}` (synthetic) o `{size?}` (sample)
     * @param  array<string,mixed>|null  $context  el de {@see StudioStrategyValidator::context()}; solo las pruebas lo pasan
     * @return array{simulation: StudioStrategySimulation, people: list<array<string,mixed>>, sequence: list<array<string,mixed>>}
     */
    public function simulate(StudioAccess $access, Request $request, StudioStrategy $strategy, ?StudioStrategyVersion $version, string $kind, array $input, ?array $context = null): array
    {
        $access->require($kind === StudioStrategySimulation::KIND_SAMPLE ? StudioStrategyConfig::SIMULATE_REAL : StudioStrategyConfig::EDIT);
        if (! in_array($kind, StudioStrategySimulation::KINDS, true)) {
            throw new UltronStudioException('Tipo de simulación desconocido.', 'simulation_kind_invalid', 422, ['errors' => ['kind' => ['Tipo de simulación desconocido.']]]);
        }
        $contexto = $context ?? StudioStrategyValidator::context();
        $contenido = $version !== null ? StudioStrategyVersions::reread($version) : StudioStrategies::draftDto($strategy)['content'];
        $v = StudioStrategyValidator::validate($contenido, $contexto, (string) $strategy->purpose);
        if ($v['canonical'] === null) {
            // Sin estructura válida no se evalúa NADA (T11): el 422 con todas las claves.
            throw StudioStrategyValidator::invalid($v);
        }
        $c = $v['canonical'];
        $condiciones = StudioStrategyValidator::conditions($c, $contexto['dynamicDomains']);
        $plantillas = self::templateStates($c, $contexto, $v);
        $studio = ($contexto['studio'])($c['studio']);
        $simContexto = [
            'studio' => ['referenceValid' => $studio['valid'], 'paymentLinksEnabled' => $studio['paymentLinksEnabled'], 'canaryOnly' => $studio['canaryOnly']],
            'templates' => $plantillas,
            'validation' => ['blocking' => $v['blocking'], 'warnings' => $v['warnings']],
            'complaint' => self::complaintContext(),
        ];

        if ($kind === StudioStrategySimulation::KIND_SYNTHETIC) {
            $ahora = self::instant($input['at'] ?? null);
            $personas = self::syntheticPeople($input['people'] ?? null, $ahora, $contexto['dynamicDomains']);
            $entrada = ['kind' => $kind, 'contentHash' => $v['contentHash'], 'at' => RelativeTime::format($ahora), 'people' => self::canonicalPeople($input['people'])];
            $fuente = ['kind' => 'synthetic'];
        } else {
            $ahora = self::instant(null);
            $tam = is_int($input['size'] ?? null) ? max(1, min(self::MAX_SAMPLE, $input['size'])) : self::MAX_SAMPLE;
            [$personas, $foto, $fuente] = $this->samplePeople($c, $condiciones, $strategy, $ahora, $tam);
            $entrada = ['kind' => $kind, 'contentHash' => $v['contentHash'], 'at' => RelativeTime::format($ahora), 'snapshotHash' => $foto?->hash(), 'size' => $tam, 'source' => $fuente];
            $simContexto['estimatedAudience'] = $fuente['estimatedAudience'] ?? null;
        }

        $r = StudioStrategySimulator::run($c, (string) $v['contentHash'], $condiciones, (string) $strategy->uuid, $personas, $ahora, $simContexto);
        $resultado = [
            'format' => $r['format'],
            'at' => $r['at'],
            'contentHash' => $r['contentHash'],
            'evaluatorVersion' => $r['evaluatorVersion'],
            'catalogVersion' => $r['catalogVersion'],
            'counts' => $r['counts'],
            'findings' => $r['findings'],
            'decisionsHash' => $r['decisionsHash'],
            'source' => $fuente,
        ];
        $resultado = self::fit($resultado);
        $huellaResultado = CanonicalJson::hash($resultado);
        $bloqueantes = count($r['findings']['blocking']);

        $fila = DB::transaction(function () use ($access, $request, $strategy, $version, $kind, $entrada, $resultado, $huellaResultado, $bloqueantes, $v, $personas): StudioStrategySimulation {
            $yo = $access->snapshot();
            $s = StudioStrategySimulation::query()->forceCreate([
                'uuid' => strtolower((string) Str::uuid()),
                'strategy_id' => (int) $strategy->id,
                'version_id' => $version?->id,
                'content_hash' => (string) $v['contentHash'],
                'kind' => $kind,
                'input_hash' => CanonicalJson::hash($entrada),
                'sample_size' => count($personas),
                'result_json' => $resultado,
                'result_hash' => $huellaResultado,
                'blocking_findings' => $bloqueantes,
                'catalog_version' => OutreachCatalog::VERSION,
                'evaluator_version' => ConditionEvaluator::VERSION,
                'created_by_admin_id' => $yo['id'],
                'created_by_person_id' => StudioPersonBindings::personOf($yo['id'])['identityId'] ?? null,
                'created_by_name' => $yo['name'],
                'created_by_role' => $yo['role'],
                'created_at' => now(),
            ]);
            $this->audit->record($request, $access->actor, 'strategy_version.simulated',
                $version !== null ? StudioStrategyConfig::SUBJECT_STRATEGY_VERSION : StudioStrategyConfig::SUBJECT_STRATEGY,
                $version !== null ? $version->uuid : $strategy->uuid,
                ($kind === StudioStrategySimulation::KIND_SAMPLE ? 'Simuló sobre una muestra real' : 'Simuló con personas sintéticas').($version !== null ? " la versión {$version->number}" : ' el borrador'), [
                    'contentHash' => (string) $v['contentHash'],
                    'diff' => ['simulation' => $s->uuid, 'kind' => $kind, 'size' => count($personas), 'blocking' => $bloqueantes, 'resultHash' => $huellaResultado],
                ]);

            return $s;
        });

        return [
            'simulation' => $fila,
            'people' => array_map(static fn (array $p): array => [
                'ref' => $p['ref'],
                'gate' => $p['gate'],
                'decision' => $p['decision'],
                'traceHash' => $p['traceHash'],
                'factsHash' => $p['factsHash'],
                'text' => $p['text'],
                'trace' => $p['trace'],
            ], array_slice($r['people'], 0, StudioStrategySimulator::MAX_TRACES)),
            'sequence' => $r['sequence'],
        ];
    }

    /**
     * M-7 de security: la ventana y las finalidades EFECTIVAS de la queja en la ejecución
     * (`OutreachConfig::complaintDays()` y `complaintPurposes()`, con sus reglas de valores inválidos), para el
     * contexto del simulador puro. Así la simulación y la ejecución dicen lo mismo aunque se configuren.
     *
     * @return array{days: int, purposes: list<string>}
     */
    public static function complaintContext(): array
    {
        return ['days' => OutreachConfig::complaintDays(), 'purposes' => OutreachConfig::complaintPurposes()];
    }

    // ── Lecturas ────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    public static function summaryDto(StudioStrategySimulation $s): array
    {
        $r = is_array($s->result_json) ? $s->result_json : [];

        return [
            'uuid' => (string) $s->uuid,
            'kind' => (string) $s->kind,
            'scope' => $s->version_id === null ? 'draft' : 'version',
            'contentHash' => (string) $s->content_hash,
            'inputHash' => (string) $s->input_hash,
            'resultHash' => (string) $s->result_hash,
            'sampleSize' => (int) $s->sample_size,
            'blockingFindings' => (int) $s->blocking_findings,
            'passes' => (int) $s->blocking_findings === 0,
            'at' => $r['at'] ?? null,
            'counts' => $r['counts'] ?? null,
            'findings' => $r['findings'] ?? null,
            'decisionsHash' => $r['decisionsHash'] ?? null,
            'source' => $r['source'] ?? null,
            'createdBy' => ['name' => (string) $s->created_by_name, 'role' => $s->created_by_role],
            'createdAt' => $s->created_at?->toIso8601String(),
        ];
    }

    /** @return list<array<string,mixed>> las de una versión (su huella), de la más reciente a la más antigua (≤ 50) */
    public static function forVersion(StudioStrategyVersion $v): array
    {
        return StudioStrategySimulation::query()->where('strategy_id', $v->strategy_id)->where('content_hash', $v->content_hash)
            ->orderByDesc('id')->limit(50)->get()->map(static fn (StudioStrategySimulation $s): array => self::summaryDto($s))->all();
    }

    // ── Personas sintéticas ─────────────────────────────────────────────────

    /**
     * Las personas sintéticas, validadas y convertidas a lo que evalúa el
     * núcleo. Todo error a la vez (422): un hecho fuera del catálogo, un tipo o
     * un valor que no corresponde, una lista demasiado larga…
     *
     * @param  array<string, list<int|string>>  $dynamic
     * @return list<array{ref: string, snapshot: FactSnapshot, person: array<string,mixed>, receivable: array{known: bool|null, overdueDaysMax: int|null}, canary: array<string, bool|null>, phone: null}>
     */
    public static function syntheticPeople(mixed $people, DateTimeImmutable $now, array $dynamic): array
    {
        $errores = [];
        $falla = static function (string $k, string $c) use (&$errores): void {
            $errores[$k] = ['key' => $k, 'code' => $c];
        };
        if (! is_array($people) || ! array_is_list($people) || $people === []) {
            $falla('people', is_array($people) && $people === [] ? 'empty_values' : 'invalid_type');
            throw self::invalidInput($errores);
        }
        if (count($people) > self::MAX_SYNTHETIC) {
            $falla('people', 'too_many_values');
            throw self::invalidInput($errores);
        }
        $salida = [];
        $refs = [];
        foreach ($people as $i => $p) {
            $raiz = "people.{$i}";
            if (! is_array($p) || array_is_list($p)) {
                $falla($raiz, 'not_an_object');

                continue;
            }
            foreach (array_keys($p) as $k) {
                if (! in_array($k, ['canary', 'consent', 'contacts', 'events', 'facts', 'optOut', 'ref', 'transitions', 'voice'], true)) {
                    $falla("{$raiz}._invalid_key", 'unexpected_key');
                }
            }
            $ref = $p['ref'] ?? null;
            if (! is_string($ref) || preg_match(self::REF_PATTERN, $ref) !== 1) {
                $falla("{$raiz}.ref", 'invalid_format');
            } elseif (isset($refs[$ref])) {
                $falla("{$raiz}.ref", 'duplicate_value');
            } else {
                $refs[$ref] = true;
            }
            $hechos = self::syntheticFacts($p['facts'] ?? [], "{$raiz}.facts", $dynamic, $falla);
            $contactos = self::syntheticRows($p['contacts'] ?? [], "{$raiz}.contacts", $now, $falla, static function (array $c, string $r) use ($falla): ?array {
                $bien = in_array($c['channel'] ?? null, OutreachCatalog::MEDIUMS, true) || ! $falla("{$r}.channel", 'value_not_in_domain');
                $bien = (in_array($c['purpose'] ?? null, OutreachCatalog::PURPOSES, true) || ! $falla("{$r}.purpose", 'value_not_in_domain')) && $bien;
                $bien = (in_array($c['strategy'] ?? null, ['self', 'other', null], true) || ! $falla("{$r}.strategy", 'value_not_in_domain')) && $bien;
                $bien = (($c['outcome'] ?? null) === null || OutcomeTable::isOutcome($c['outcome']) || ! $falla("{$r}.outcome", 'value_not_in_domain')) && $bien;

                return $bien ? ['channel' => $c['channel'], 'purpose' => $c['purpose'], 'strategy' => $c['strategy'] ?? null, 'outcome' => $c['outcome'] ?? null] : null;
            });
            $eventos = array_key_exists('events', $p) && $p['events'] === null ? null : self::syntheticRows($p['events'] ?? [], "{$raiz}.events", $now, $falla, static function (array $e, string $r) use ($falla): ?array {
                if (! is_string($e['key'] ?? null) || ! array_key_exists($e['key'], OutreachCatalog::CRM_EVENTS)) {
                    $falla("{$r}.key", 'unknown_event');

                    return null;
                }

                return ['key' => $e['key'], 'attrs' => []];
            });
            $transiciones = array_key_exists('transitions', $p) && $p['transitions'] === null ? null : self::syntheticRows($p['transitions'] ?? [], "{$raiz}.transitions", $now, $falla, static function (array $t, string $r) use ($falla): ?array {
                $entidad = $t['entity'] ?? null;
                if (! is_string($entidad) || ! isset(OutreachCatalog::ENTITIES[$entidad]) || ! in_array($t['from'] ?? null, OutreachCatalog::ENTITIES[$entidad], true) || ! in_array($t['to'] ?? null, OutreachCatalog::ENTITIES[$entidad], true)) {
                    $falla($r, 'invalid_transition');

                    return null;
                }

                return ['entity' => $entidad, 'from' => $t['from'], 'to' => $t['to']];
            });
            $consentimiento = ['voice' => 'missing', 'whatsapp' => 'missing'];
            foreach ((array) ($p['consent'] ?? []) as $medio => $valor) {
                if (! in_array($medio, OutreachCatalog::MEDIUMS, true) || ! in_array($valor, self::CONSENT_VALUES, true)) {
                    $falla("{$raiz}.consent", 'value_not_in_domain');
                } else {
                    $consentimiento[$medio] = $valor;
                }
            }
            $optOut = ['commercial' => false, 'doNotContact' => false, 'siblingDoNotContact' => false];
            foreach ((array) ($p['optOut'] ?? []) as $k => $valor) {
                array_key_exists($k, $optOut) && is_bool($valor) ? $optOut[$k] = $valor : $falla("{$raiz}.optOut", 'invalid_type');
            }
            $voz = ['excluded' => false, 'rneOk' => true];
            foreach ((array) ($p['voice'] ?? []) as $k => $valor) {
                array_key_exists($k, $voz) && is_bool($valor) ? $voz[$k] = $valor : $falla("{$raiz}.voice", 'invalid_type');
            }
            $canario = [OutreachCatalog::MEDIUM_VOICE => false, OutreachCatalog::MEDIUM_WHATSAPP => false];
            foreach ((array) ($p['canary'] ?? []) as $medio => $valor) {
                in_array($medio, OutreachCatalog::MEDIUMS, true) && is_bool($valor) ? $canario[$medio] = $valor : $falla("{$raiz}.canary", 'invalid_type');
            }
            if ($errores !== [] || $hechos === null) {
                continue;
            }
            try {
                $foto = FactSnapshot::fromArray([
                    'facts' => $hechos,
                    'contacts' => array_map(static fn (array $c): array => ['at' => $c['at']] + $c['row'], $contactos ?? []),
                    'events' => $eventos === null ? null : array_map(static fn (array $e): array => ['at' => $e['at'], 'attrs' => $e['row']['attrs'], 'key' => $e['row']['key']], $eventos),
                    'transitions' => $transiciones === null ? null : array_map(static fn (array $t): array => ['at' => $t['at']] + $t['row'], $transiciones),
                ]);
            } catch (InvalidArgumentException) {
                // Tragado a propósito: es un 422 con su clave, no un fallo del servidor.
                $falla($raiz, 'invalid_type');

                continue;
            }
            $bool = static fn (string $k): ?bool => is_bool($hechos[$k] ?? null) ? $hechos[$k] : null;
            $salida[] = [
                'ref' => (string) $ref,
                'snapshot' => $foto,
                'person' => [
                    'subject' => ['known' => true, 'isMinor' => $bool('subject.is_minor')],
                    'consent' => $consentimiento,
                    'optOut' => $optOut,
                    'voice' => $voz,
                    'history' => array_map(static fn (array $c): array => ['at' => $c['at'], 'medium' => $c['row']['channel'], 'purpose' => $c['row']['purpose'], 'source' => $c['row']['strategy'] === null ? 'ai_proactive' : 'outreach'], $contactos ?? []),
                    'conversation' => ['humanInControl' => $bool('conversation.human_in_control') ?? false],
                    'identity' => ['voiceContact' => $bool('subject.has_voice_contact'), 'whatsapp' => $bool('subject.has_whatsapp_identity')],
                    'brakes' => [],
                    'flags' => [],
                ],
                'receivable' => [
                    'known' => $bool('receivable.has_overdue') !== null || array_key_exists('receivable.overdue_days_max', $hechos) ? true : null,
                    'overdueDaysMax' => is_int($hechos['receivable.overdue_days_max'] ?? null) ? $hechos['receivable.overdue_days_max'] : null,
                ],
                'canary' => $canario,
                'phone' => null,
            ];
        }
        if ($errores !== []) {
            throw self::invalidInput($errores);
        }

        return $salida;
    }

    // ── La muestra real (solo lectura) ──────────────────────────────────────

    /**
     * El prefiltro del disparador AHORA y una muestra determinista; la foto
     * del lote en solo lectura y las personas para el simulador, nombradas
     * por su `subjectRef` (HMAC, no enumerable).
     *
     * @param  array<string,mixed>  $c
     * @param  array<string,mixed>  $condiciones
     * @return array{0: list<array<string,mixed>>, 1: BatchSnapshot|null, 2: array<string,mixed>}
     */
    private function samplePeople(array $c, array $condiciones, StudioStrategy $strategy, DateTimeImmutable $now, int $tam): array
    {
        [$candidatos, $fuente] = self::candidates($c['trigger'], $now);
        $claves = array_values(array_unique(array_map(static fn (Candidate $x): string => $x->subjectKey, $candidatos)));
        // Determinista: el orden lo da la huella de (contenido, clave), no el azar ni el id.
        usort($claves, static fn (string $a, string $b): int => strcmp(hash('sha256', $c['trigger']['event'].'|'.$a), hash('sha256', $c['trigger']['event'].'|'.$b)));
        $muestra = array_slice($claves, 0, $tam);
        $fuente['candidates'] = count($claves);
        $fuente['sampled'] = count($muestra);
        $fuente['estimatedAudience'] = null;
        if ($muestra === []) {
            return [[], null, $fuente];
        }
        $usos = self::mergeUses([$condiciones['audience'], $condiciones['exclusions'], $condiciones['success'], ...array_values($condiciones['guards'])]);
        $base = CoreProjection::selectionFor($usos, true);
        // `human_requested` y `complaint_created` (Q1-3) siempre: N6 los mira aunque la condición no los use.
        $seleccion = new FactSelection($base->facts, array_values(array_unique([...$base->events, 'complaint_created', 'human_requested'])), $base->contacts, $base->policy, $base->global, $base->transitions);
        $foto = $this->snapshots->build($muestra, $now, $seleccion);
        $personas = [];
        foreach ($muestra as $clave) {
            $s = $foto->subject($clave);
            if ($s === null) {
                continue;
            }
            $politica = CoreProjection::policyInput($s, (string) $c['purpose'], $foto->global);
            $personas[] = [
                'ref' => (string) ($s->ref ?? ''),
                'snapshot' => CoreProjection::snapshot($s, (string) $strategy->uuid),
                'person' => $politica,
                'receivable' => [
                    'known' => $s->has('receivable.has_overdue') ? is_bool($s->fact('receivable.has_overdue')) : null,
                    'overdueDaysMax' => is_int($s->fact('receivable.overdue_days_max')) ? $s->fact('receivable.overdue_days_max') : null,
                ],
                'canary' => [
                    OutreachCatalog::MEDIUM_WHATSAPP => $s->policy('canary.whatsapp'),
                    OutreachCatalog::MEDIUM_VOICE => $s->policy('canary.voice'),
                ],
                'phone' => ['refs' => CoreProjection::phoneKeys($s) ?? [], 'contacts' => []],
            ];
        }
        $inscritos = 0;
        foreach ($personas as $p) {
            $inscritos += EnrollmentGate::evaluate($condiciones['audience'], $condiciones['exclusions'], $condiciones['success'], $p['snapshot'], $now)['enroll'] ? 1 : 0;
        }
        // La audiencia estimada: la tasa de la muestra sobre los candidatos del prefiltro (como mucho una página, ≥ si hay más).
        $fuente['estimatedAudience'] = count($personas) === 0 ? 0 : (int) ceil($inscritos * count($claves) / count($personas));
        $fuente['sources'] = $foto->sources;

        return [$personas, $foto, $fuente];
    }

    /**
     * Los candidatos del disparador AHORA, con los prefiltros indexados de los
     * adaptadores (una página de ≤ 500). Los disparadores `crm_fact` no tienen
     * prefiltro (los lee el cursor de la ejecución): la muestra sale vacía y
     * lo dice.
     *
     * @param  array{event: string, params?: array<string,int>}  $trigger
     * @return array{0: list<Candidate>, 1: array<string,mixed>}
     */
    public static function candidates(array $trigger, DateTimeImmutable $now): array
    {
        $p = $trigger['params'] ?? [];
        $pagina = match ($trigger['event']) {
            'membership.expiring_window' => (new MembershipFacts)->endingBetween($now, 0, (int) $p['daysBefore']),
            'membership.expired_window' => (new MembershipFacts)->endedBetween($now, (int) $p['daysAfterMin'], (int) $p['daysAfterMax']),
            'member.inactive' => (new AttendanceFacts)->inactiveSince($now, (int) $p['days']),
            'receivable.overdue' => (new ReceivableFacts)->overdueBetween($now, (int) $p['daysMin'], (int) $p['daysMax']),
            'payment_link.abandoned' => (new PaymentFacts)->abandonedLinks($now, (int) $p['graceHours']),
            'lead.followup_due' => (new LeadFacts)->followupsDue($now),
            'valuation.outdated' => (new ValuationFacts)->outdated($now, (int) $p['days']),
            'class.upcoming_with_capacity' => self::classCandidates($now, (int) $p['withinDays']),
            default => null,
        };
        if ($pagina === null) {
            return [[], ['trigger' => $trigger['event'], 'prefilter' => false, 'note' => 'trigger_without_prefilter']];
        }

        return [$pagina['candidates'], ['trigger' => $trigger['event'], 'prefilter' => true, 'more' => $pagina['next'] !== null]];
    }

    /** @return array{candidates: list<Candidate>, next: ?int} */
    private static function classCandidates(DateTimeImmutable $now, int $withinDays): array
    {
        $clases = new ClassFacts;
        $todos = [];
        $mas = false;
        foreach ($clases->upcomingWithCapacity($now, $withinDays) as $o) {
            if ($o['available'] <= 0) {
                continue;
            }
            $r = $clases->candidatesForOccurrence($now, (int) $o['class_id'], (string) $o['session_date'], $o['class_session_id'], 500 - count($todos));
            $todos = [...$todos, ...$r['candidates']];
            if (count($todos) >= 500) {
                $mas = true;

                break;
            }
        }

        return ['candidates' => $todos, 'next' => $mas ? 1 : null];
    }

    // ── Piezas ──────────────────────────────────────────────────────────────

    /**
     * El estado de la plantilla de cada canal del PRIMER paso (lo que la
     * escalera mira en N3/N4).
     *
     * @param  array<string,mixed>  $c
     * @param  array<string,mixed>  $contexto
     * @param  array<string,mixed>  $v
     * @return array<string, array{approved: bool, categoryMatches: bool, usesPaymentLink: bool, violations: list<string>}>
     */
    private static function templateStates(array $c, array $contexto, array $v): array
    {
        $salida = [];
        foreach ($c['sequence'][0]['channels'] as $canal) {
            $ref = $c['sequence'][0]['templates'][$canal];
            $t = ($contexto['template'])($ref['uuid']);
            $salida[$canal] = [
                'approved' => $t !== null && $t['status'] === 'APPROVED' && $t['integrity'] && hash_equals($t['hash'], $ref['hash']),
                'categoryMatches' => $t !== null && $t['purpose'] === $c['purpose']
                    && ($t['channel'] !== StudioMessageTemplates::CHANNEL_WHATSAPP || ($t['content']['category'] ?? null) === StudioMessageTemplates::CATEGORY_BY_PURPOSE[$c['purpose']]),
                'usesPaymentLink' => $t !== null && StudioMessageTemplates::usesPaymentLink($t['content']),
                'violations' => $t === null ? [] : StudioMessageTemplates::violationsOf($t['content']),
            ];
        }

        return $salida;
    }

    /**
     * Los hechos sintéticos tipados contra el catálogo (D9-4): el hecho existe,
     * no es de historial (los contactos van aparte), y su valor corresponde a
     * su tipo, dominio y rango; null = desconocido.
     *
     * @param  array<string, list<int|string>>  $dynamic
     * @param  callable(string,string):void  $falla
     * @return array<string, bool|int|string|null>|null
     */
    private static function syntheticFacts(mixed $hechos, string $raiz, array $dynamic, callable $falla): ?array
    {
        if (! is_array($hechos) || ($hechos !== [] && array_is_list($hechos))) {
            $falla($raiz, 'not_an_object');

            return null;
        }
        $bien = true;
        $salida = [];
        foreach ($hechos as $clave => $valor) {
            $clave = (string) $clave;
            $ruta = $raiz.'.'.(preg_match('/^[a-z0-9_.]{1,64}$/D', $clave) === 1 ? $clave : '_invalid_key');
            $f = OutreachCatalog::fact($clave);
            if ($f === null || str_starts_with($clave, 'contact.')) {
                $falla($ruta, 'unknown_fact');
                $bien = false;

                continue;
            }
            if ($valor === null) {
                $salida[$clave] = null;

                continue;
            }
            $ok = match ($f['type']) {
                OutreachCatalog::TYPE_BOOL => is_bool($valor),
                OutreachCatalog::TYPE_ENUM => self::inEnum($valor, $f['domain'], $dynamic),
                default => is_int($valor) && ($f['min'] === null || $valor >= $f['min']) && ($f['max'] === null || $valor <= $f['max']),
            };
            if (! $ok) {
                $falla($ruta, is_int($valor) || is_bool($valor) || is_string($valor) ? 'value_out_of_range' : 'invalid_type');
                $bien = false;

                continue;
            }
            $salida[$clave] = $valor;
        }

        return $bien ? $salida : null;
    }

    /** @param  array<string, list<int|string>>  $dynamic */
    private static function inEnum(mixed $valor, mixed $dominio, array $dynamic): bool
    {
        $lista = is_array($dominio) && isset($dominio['dynamic']) ? ($dynamic[$dominio['dynamic']] ?? []) : (array) $dominio;

        return (is_string($valor) || is_int($valor)) && in_array($valor, $lista, true)
            && (! is_string($valor) || preg_match(FactSnapshot::VALUE_PATTERN, $valor) === 1);
    }

    /**
     * Filas sintéticas con `hoursAgo` (0–17 520) → instantes, ≤ 20 por persona.
     *
     * @param  callable(string,string):void  $falla
     * @param  callable(array<string,mixed>,string):(array<string,mixed>|null)  $fila
     * @return list<array{at: string, row: array<string,mixed>}>|null
     */
    private static function syntheticRows(mixed $filas, string $raiz, DateTimeImmutable $now, callable $falla, callable $fila): ?array
    {
        if (! is_array($filas) || ($filas !== [] && ! array_is_list($filas))) {
            $falla($raiz, 'invalid_type');

            return null;
        }
        if (count($filas) > self::MAX_ROWS_PER_PERSON) {
            $falla($raiz, 'too_many_values');

            return null;
        }
        $salida = [];
        foreach ($filas as $i => $f) {
            $ruta = "{$raiz}.{$i}";
            if (! is_array($f) || array_is_list($f)) {
                $falla($ruta, 'not_an_object');

                continue;
            }
            $horas = $f['hoursAgo'] ?? null;
            if (! is_int($horas) || $horas < 0 || $horas > self::MAX_HOURS_AGO) {
                $falla("{$ruta}.hoursAgo", 'value_out_of_range');

                continue;
            }
            $r = $fila($f, $ruta);
            if ($r !== null) {
                $salida[] = ['at' => RelativeTime::format($now->modify("-{$horas} hours")), 'row' => $r];
            }
        }

        return $salida;
    }

    /**
     * La entrada sintética canónica (para su huella): solo claves conocidas.
     *
     * @return list<mixed>
     */
    private static function canonicalPeople(mixed $people): array
    {
        return is_array($people) ? array_values($people) : [];
    }

    /**
     * El instante de la simulación: el dado (UTC, al segundo) o ahora.
     */
    private static function instant(mixed $at): DateTimeImmutable
    {
        if ($at === null) {
            return CarbonImmutable::now('UTC')->startOfSecond()->toDateTimeImmutable();
        }
        if (! RelativeTime::isInstant($at)) {
            throw new UltronStudioException('El instante de la simulación tiene la forma AAAA-MM-DDTHH:MM:SSZ (UTC).', 'simulation_input_invalid', 422, ['errors' => ['at' => ['Instante UTC al segundo.']]]);
        }

        return RelativeTime::parseInstant($at);
    }

    /**
     * @param  list<CanonicalCondition|null>  $condiciones
     * @return array{facts: list<string>, events: list<string>, contacts: bool, transitions: bool}
     */
    private static function mergeUses(array $condiciones): array
    {
        $u = ['facts' => [], 'events' => [], 'contacts' => false, 'transitions' => false];
        foreach ($condiciones as $c) {
            if ($c === null) {
                continue;
            }
            $x = $c->uses();
            $u['facts'] = [...$u['facts'], ...$x['facts']];
            $u['events'] = [...$u['events'], ...$x['events']];
            $u['contacts'] = $u['contacts'] || $x['contacts'];
            $u['transitions'] = $u['transitions'] || $x['transitions'];
        }

        return $u;
    }

    /**
     * Lo que se guarda cabe en 16 KB: si no, se quitan los recuentos por motivo
     * (los más largos) de forma determinista y se marca `truncated`.
     *
     * @param  array<string,mixed>  $r
     * @return array<string,mixed>
     */
    private static function fit(array $r): array
    {
        if (strlen(CanonicalJson::encode($r)) <= self::MAX_RESULT_BYTES) {
            return $r;
        }
        $r['counts']['byReason'] = ['truncated' => true];
        $r['truncated'] = true;

        return $r;
    }

    /** @param  array<string, array{key:string,code:string}>  $errores */
    private static function invalidInput(array $errores): UltronStudioException
    {
        ksort($errores, SORT_STRING);
        $mensajes = [];
        foreach ($errores as $e) {
            $mensajes[$e['key']][] = ConditionCanonicalizer::MESSAGES[$e['code']] ?? 'Valor no admitido.';
        }

        return new UltronStudioException('La entrada de la simulación no es válida.', 'simulation_input_invalid', 422, ['errors' => $mensajes, 'findings' => array_values($errores)]);
    }
}
