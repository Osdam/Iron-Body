<?php

namespace App\Services\Outreach\Run;

use App\Services\Outreach\Core\ChannelSelector;
use App\Services\Outreach\Core\ConditionEvaluator;
use App\Services\Outreach\Core\DecisionTrace;
use App\Services\Outreach\Core\EnrollmentGate;
use App\Services\Outreach\Core\FactSnapshot;
use App\Services\Outreach\Core\PolicyLadder;
use App\Services\Outreach\Core\RelativeTime;
use App\Services\Outreach\Facts\CatalogDomains;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * `outreach:replay <decision_uuid>` (contrato H9, D9-12 «Reproducibilidad»;
 * T12; §13.1 #24): vuelve a evaluar una decisión GUARDADA con su propia foto
 * de hechos derivados (`facts_json`), su instante (`evaluated_at`), las
 * condiciones de su versión y las ENTRADAS de la escalera que la traza guardó
 * (derivadas: banderas, frenos, estrategia, presupuesto, teléfono), con el
 * núcleo puro de ahora, y compara la `trace_hash`. Misma entrada, misma
 * salida: si no, el evaluador cambió o la fila no es lo que dice.
 *
 * SOLO LECTURA: nunca escribe una fila ni toca un trabajo (la pasada
 * `replay` de `outreach_runs` la deja la orden, no este servicio). Si la foto
 * se guardó recortada (`factsTruncated`) o la traza no trae las entradas, lo
 * dice (`not_reproducible`) en vez de fingir.
 */
final class OutreachReplay
{
    public const SAME = 'same';

    public const DIFFERENT = 'different';

    public const NOT_REPRODUCIBLE = 'not_reproducible';

    public function __construct(private readonly OutreachStore $store = new OutreachStore, private readonly OutreachPublishedStrategies $bridge = new OutreachPublishedStrategies) {}

    /**
     * @return array{result: string, reason: string|null, decision: string, kind: string, storedHash: string, replayedHash: string|null, ladder: array<string, bool>}
     */
    public function replay(string $decisionUuid): array
    {
        $d = $this->store->decisionByUuid($decisionUuid);
        if ($d === null) {
            throw OutreachException::notFound('decision');
        }
        $salida = ['result' => self::NOT_REPRODUCIBLE, 'reason' => null, 'decision' => (string) $d->uuid, 'kind' => (string) $d->kind, 'storedHash' => (string) $d->trace_hash, 'replayedHash' => null, 'ladder' => []];
        $guardada = json_decode((string) $d->trace_json, true);
        $traza = is_array($guardada) && array_is_list($guardada) ? ($guardada[0] ?? null) : $guardada;
        if (! is_array($traza)) {
            return $salida + ['reason' => 'trace_unreadable'];
        }
        if (($traza['factsTruncated'] ?? false) === true) {
            return ['reason' => 'facts_truncated'] + $salida;
        }
        $hechosGuardados = json_decode((string) $d->facts_json, true);
        if (! is_array($hechosGuardados) || ! hash_equals(hash('sha256', (string) $d->facts_json), (string) $d->facts_hash)) {
            return ['reason' => 'facts_hash_mismatch'] + $salida;
        }
        try {
            $hechos = FactSnapshot::fromArray($hechosGuardados);
            $ahora = RelativeTime::parseInstant((string) $traza['evaluatedAt']);
        } catch (Throwable) {
            return ['reason' => 'facts_unreadable'] + $salida;
        }
        $contenido = $this->bridge->contentOfVersion((int) $d->strategy_version_id);
        if ($contenido === null) {
            return ['reason' => 'version_unreadable'] + $salida;
        }
        $dominios = (new CatalogDomains)->read();
        $vivo = new LiveStrategy(
            strategyId: (int) $d->strategy_id, strategyUuid: '00000000-0000-4000-8000-000000000000', strategyKey: 'replay', purpose: (string) ($contenido['purpose'] ?? 'commercial'),
            versionId: (int) $d->strategy_version_id, versionUuid: '00000000-0000-4000-8000-000000000000', versionNumber: 0, versionStatus: 'REPLAY', contentHash: (string) $d->content_hash,
            content: $contenido, contentReason: null, catalogVersion: (string) $d->catalog_version, evaluatorVersion: (string) $d->evaluator_version,
            studioRefKind: (string) $d->studio_ref_kind, studioVersionUuid: $d->studio_version_uuid, studioContentHash: (string) $d->studio_content_hash,
            part: (string) $d->part, publicationSeq: (int) $d->publication_seq, chainStatus: 'active', chainVerified: 'ok',
            studio: ['referenceValid' => true, 'paymentLinksEnabled' => false, 'canaryOnly' => false, 'reason' => null], dynamicDomains: $dominios,
        );

        // Las condiciones, con la misma foto y el mismo instante.
        $condiciones = [];
        if (isset($traza['conditions'])) {
            $puerta = EnrollmentGate::evaluate($vivo->audience(), $vivo->exclusions(), $vivo->success(), $hechos, $ahora);
            $condiciones = array_filter(['audience' => $puerta['audience'], 'exclusions' => $puerta['exclusions'], 'success' => $puerta['success']]);
            if (isset($traza['conditions']['guard'])) {
                $indice = (int) ($traza['followUp']['stepIndex'] ?? 0);
                $guarda = $vivo->guard($indice);
                if ($guarda !== null) {
                    $condiciones['guard'] = ConditionEvaluator::evaluate($guarda, $hechos, $ahora, 'guard');
                }
            }
        }
        // La escalera, con las entradas guardadas (derivadas), canal a canal; y el selector sobre sus salidas.
        $partes = [];
        foreach (DecisionTrace::PARTS as $parte) {
            if (isset($traza[$parte])) {
                $partes[$parte] = $traza[$parte];
            }
        }
        $igualPorCanal = [];
        if (isset($traza['policy']) && is_array($traza['policy'])) {
            $salidas = [];
            foreach ($traza['policy'] as $canal => $p) {
                if (! is_array($p['input'] ?? null)) {
                    return ['reason' => 'ladder_input_missing'] + $salida;
                }
                $otra = PolicyLadder::decide($p['input']);
                $igualPorCanal[(string) $canal] = $otra === ($p['output'] ?? null);
                $salidas[(string) $canal] = $otra;
                $partes['policy'][$canal] = ['input' => $p['input'], 'output' => $otra];
            }
            if (isset($traza['channel']['order']) && is_array($traza['channel']['order']) && $salidas !== []) {
                $partes['channel'] = ChannelSelector::choose(array_values(array_map('strval', $traza['channel']['order'])), $salidas, null);
                // El selector guardado pudo recibir los canales en el orden del paso; se compara por lo que decidió.
                $partes['channel']['order'] = $traza['channel']['order'];
            }
        }
        $decision = $traza['decision'] ?? ['kind' => (string) $d->kind];
        try {
            $rehecha = DecisionTrace::build(['kind' => (string) $decision['kind'], 'level' => $decision['level'] ?? null, 'reasons' => $decision['reasons'] ?? [], 'retryAt' => $decision['retryAt'] ?? null],
                $hechos, $ahora, $condiciones, $partes);
        } catch (Throwable) {
            return ['reason' => 'rebuild_failed'] + $salida;
        }
        $huella = hash('sha256', DecisionTrace::json([$rehecha]));

        return [
            'result' => hash_equals((string) $d->trace_hash, $huella) ? self::SAME : self::DIFFERENT,
            'reason' => null,
            'decision' => (string) $d->uuid,
            'kind' => (string) $d->kind,
            'storedHash' => (string) $d->trace_hash,
            'replayedHash' => $huella,
            'ladder' => $igualPorCanal,
        ];
    }

    /** Cuántas decisiones hay de una inscripción (para la orden, sin leerlas todas). */
    public function countFor(string $enrollmentUuid): int
    {
        return (int) DB::table(OutreachTables::DECISIONS.' as d')->join(OutreachTables::ENROLLMENTS.' as e', 'e.id', '=', 'd.enrollment_id')->where('e.uuid', strtolower($enrollmentUuid))->count();
    }
}
