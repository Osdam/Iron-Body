<?php

namespace App\Services\UltronStudio;

use App\Exceptions\UltronStudioException;
use App\Models\UltronStudio\StudioAuditEvent;
use App\Models\UltronStudio\StudioVoicePublication;
use App\Services\Telephony\VoiceBrake;
use App\Services\Telephony\VoiceEvidence;
use Carbon\CarbonImmutable;
use Closure;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * LA EVIDENCIA DEL CANARIO DE VOZ Y EL FRENO (contrato H8, D8-19, E2-6 y P-16),
 * vistos desde el Studio.
 *
 * EL ÚNICO FICHERO DEL STUDIO QUE HABLA CON LOS SERVICIOS DE LA TELEFONÍA
 * (E2-6): el Studio lee la evidencia y suelta el freno SOLO a través de
 * `Telephony\VoiceEvidence` y `Telephony\VoiceBrake` (B8), nunca escribe en
 * `voice_*` ni las lee directamente. Lo que este fichero les pide está en
 * {@see measure()}, {@see brakeState()}, {@see brakes()} y {@see release()}
 * (el contrato que necesita el Studio, en el informe de S8-b). Si la telefonía
 * no está o falla, FALLA CERRADO: la evidencia no pasa (`evidence_unavailable`),
 * el freno es `unknown` (no se publica ni se promueve sin saber que está suelto)
 * y soltarlo no se puede.
 *
 * LA EVIDENCIA PARA PROMOVER (D8-19, P-16), medida en el servidor sobre la
 * entrada vigente de la parte canaria: al menos {@see minHours()} horas desde
 * ella y {@see minCalls()} llamadas selladas con ella; 0 violaciones de la
 * guarda, 0 `seal_mismatch`, 0 deriva, 0 llamadas desconocidas y 0 veces el
 * techo de gasto; la cadena verificada; el freno suelto; y un veredicto
 * `approved` del laboratorio hablado sobre la misma huella, de otra identidad
 * que quien abrió la sesión (la base lo exige: `usvl_four_eyes_chk`). La
 * decisión es pura ({@see evaluate()}) y su huella (`evidenceHash`) la manda el
 * CRM al promover: si cambió entretanto, 409 `evidence_changed`.
 */
final class StudioVoiceEvidence
{
    public const DEFAULT_MIN_HOURS = 24;

    public const DEFAULT_MIN_CALLS = 3;

    public const MIN_HOURS = 1;

    public const MAX_HOURS = 168;

    public const MIN_CALLS = 1;

    public const MAX_CALLS = 100;

    public const BRAKE_RELEASED = 'released';

    public const BRAKE_ENGAGED = 'engaged';

    public const BRAKE_UNKNOWN = 'unknown';

    /** Los recuentos que la telefonía tiene que dar a 0 (D8-19). */
    public const ZERO_COUNTS = ['guardViolations', 'sealMismatches', 'driftEvents', 'unknownProviderCalls', 'budgetBreaches'];

    /** Los motivos de una evidencia que no pasa, en su orden. */
    public const FAILURES = [
        'no_canary_publication', 'evidence_unavailable', 'chain_unverified', 'too_soon', 'too_few_calls',
        'guard_violations', 'seal_mismatches', 'drift_events', 'unknown_provider_calls', 'budget_breaches',
        'brake_engaged', 'lab_verdict_missing',
    ];

    public const NOTE_MIN = 5;

    /** El de la telefonía (`VoiceBrake::NOTE_MAX`, 300): la nota la vuelve a comprobar ella. */
    public const NOTE_MAX = 300;

    /**
     * @param  Closure(int,string,DateTimeInterface):mixed|null  $measure  solo las pruebas lo pasan: en producción, {@see measure()} (la telefonía)
     */
    public function __construct(
        private readonly StudioAudit $audit,
        private readonly ?Closure $measure = null,
    ) {}

    // ── La evidencia ────────────────────────────────────────────────────────

    /**
     * `GET voice/canary-evidence`: la evidencia de la entrada vigente de la
     * parte canaria, y si pasa. Sin entrada (o apagada), no pasa.
     *
     * @return array{dto:array<string,mixed>,evaluation:array{passes:bool,failing:list<string>,hours:int,evidenceHash:string},record:array<string,mixed>}
     */
    public function canary(): array
    {
        $entrada = StudioVoicePublications::current(StudioVoicePublication::SCOPE_CANARY);
        if ($entrada === null || $entrada->agent === null) {
            $registro = ['publication' => null, 'specHash' => null, 'measures' => null, 'thresholds' => self::thresholds()];
            $evaluacion = ['passes' => false, 'failing' => ['no_canary_publication'], 'hours' => 0, 'evidenceHash' => StudioContentHasher::hash($registro)];

            return ['dto' => self::dto($registro, $evaluacion), 'evaluation' => $evaluacion, 'record' => $registro];
        }

        $desde = CarbonImmutable::instance($entrada->created_at ?? now())->utc();
        $medidas = $this->measure !== null
            ? self::validMeasures(($this->measure)((int) $entrada->seq, StudioVoicePublication::SCOPE_CANARY, $desde))
            : self::measure((int) $entrada->seq, StudioVoicePublication::SCOPE_CANARY, $desde);
        $registro = [
            'publication' => ['uuid' => (string) $entrada->uuid, 'seq' => (int) $entrada->seq, 'since' => $desde->format(StudioVoicePublications::PUBLISHED_AT_FORMAT)],
            'specHash' => (string) $entrada->agent->spec_hash,
            'measures' => $medidas,
            'chainVerified' => StudioVoicePublications::verifyRow($entrada) === StudioVoicePublications::VERIFY_OK,
            'brake' => self::brakeState(),
            'labVerdict' => self::labVerdict((string) $entrada->agent->spec_hash),
            'labGate' => StudioVoicePublishing::labGateMode(),
            'thresholds' => self::thresholds(),
        ];
        $evaluacion = self::evaluate($registro, now());

        return ['dto' => self::dto($registro, $evaluacion), 'evaluation' => $evaluacion, 'record' => $registro];
    }

    /**
     * La decisión, pura: los motivos que no se cumplen, en el orden de
     * {@see FAILURES}, y la huella del registro (lo que el CRM manda al
     * promover). `hours` son las horas enteras desde la entrada.
     *
     * @param  array<string,mixed>  $record
     * @return array{passes:bool,failing:list<string>,hours:int,evidenceHash:string}
     */
    public static function evaluate(array $record, DateTimeInterface $now): array
    {
        $fallan = [];
        $horas = 0;
        if (($record['publication'] ?? null) === null) {
            $fallan[] = 'no_canary_publication';
        } else {
            $desde = CarbonImmutable::parse((string) $record['publication']['since']);
            $horas = max(0, intdiv(CarbonImmutable::instance($now)->getTimestamp() - $desde->getTimestamp(), 3600));
            $m = $record['measures'] ?? null;
            $umbrales = $record['thresholds'];
            if (! is_array($m)) {
                $fallan[] = 'evidence_unavailable';
            }
            if (($record['chainVerified'] ?? false) !== true) {
                $fallan[] = 'chain_unverified';
            }
            if ($horas < $umbrales['minHours']) {
                $fallan[] = 'too_soon';
            }
            if (is_array($m)) {
                if ($m['calls'] < $umbrales['minCalls']) {
                    $fallan[] = 'too_few_calls';
                }
                foreach (['guardViolations' => 'guard_violations', 'sealMismatches' => 'seal_mismatches', 'driftEvents' => 'drift_events',
                    'unknownProviderCalls' => 'unknown_provider_calls', 'budgetBreaches' => 'budget_breaches'] as $clave => $motivo) {
                    if ($m[$clave] !== 0) {
                        $fallan[] = $motivo;
                    }
                }
            }
            if (($record['brake'] ?? self::BRAKE_UNKNOWN) !== self::BRAKE_RELEASED) {
                $fallan[] = 'brake_engaged';
            }
            if (($record['labVerdict']['latest'] ?? null) !== 'approved' && ($record['labGate'] ?? null) !== StudioVoicePublishing::LAB_WAIVED) {
                $fallan[] = 'lab_verdict_missing';
            }
        }

        return ['passes' => $fallan === [], 'failing' => $fallan, 'hours' => $horas, 'evidenceHash' => StudioContentHasher::hash($record)];
    }

    /**
     * Los recuentos de la telefonía (B8, `VoiceEvidence::forPublication`): las
     * llamadas selladas con la entrada `$seq` de la parte `$part` desde
     * `$since` y sus incidentes. null si la telefonía no está o falla, o si lo
     * que devuelve no tiene la forma esperada (falla cerrado).
     *
     * @return array{calls:int,guardViolations:int,sealMismatches:int,driftEvents:int,unknownProviderCalls:int,budgetBreaches:int}|null
     */
    public static function measure(int $seq, string $part, DateTimeInterface $since): ?array
    {
        if (! method_exists(VoiceEvidence::class, 'forPublication')) {
            // Hasta que la telefonía la dé (E2-6), no hay evidencia: no se promueve.
            return null;
        }
        try {
            $r = VoiceEvidence::forPublication($seq, $part, $since);
        } catch (Throwable $e) {
            // Tragado a propósito: sin evidencia no se promueve (falla cerrado). Solo la clase.
            Log::warning('ultron_studio.voice_evidence_unavailable', ['error_class' => class_basename($e)]);

            return null;
        }

        return self::validMeasures($r);
    }

    /**
     * Los recuentos con su forma (enteros no negativos, exactamente los que se
     * piden), o null: lo que no tiene forma no es evidencia (falla cerrado).
     *
     * @return array{calls:int,guardViolations:int,sealMismatches:int,driftEvents:int,unknownProviderCalls:int,budgetBreaches:int}|null
     */
    public static function validMeasures(mixed $r): ?array
    {
        if (! is_array($r)) {
            return null;
        }
        $out = [];
        foreach (['calls', ...self::ZERO_COUNTS] as $clave) {
            if (! is_int($r[$clave] ?? null) || $r[$clave] < 0) {
                return null;
            }
            $out[$clave] = $r[$clave];
        }

        return $out;
    }

    /**
     * El último veredicto del laboratorio hablado sobre esta huella de
     * especificación (D8-23, P-19): el de su ÚNICO dueño, LAB8
     * (`StudioVoiceLabVerdicts::latestFor`), que solo cuenta agentes de
     * laboratorio REALES (un veredicto sobre el simulador no prueba la voz). La
     * compilación del laboratorio y la de la publicación comparten `specHash`
     * (el propósito no entra en la especificación).
     *
     * @return array{latest:string|null,sessionUuid:string|null,at:string|null}
     */
    public static function labVerdict(string $specHash): array
    {
        $ultimo = StudioVoiceLabVerdicts::latestFor($specHash);

        return [
            'latest' => is_string($ultimo['latest'] ?? null) ? $ultimo['latest'] : null,
            'sessionUuid' => is_string($ultimo['sessionUuid'] ?? null) ? $ultimo['sessionUuid'] : null,
            'at' => is_string($ultimo['verdictAt'] ?? null) ? $ultimo['verdictAt'] : null,
        ];
    }

    /** @return array{minHours:int,minCalls:int} los umbrales, acotados otra vez a sus topes duros */
    public static function thresholds(): array
    {
        $acotar = static function (mixed $valor, int $defecto, int $min, int $max): int {
            return is_int($valor) ? min($max, max($min, $valor)) : $defecto;
        };

        return [
            'minHours' => $acotar(config('ultron_studio.voice.canary_min_hours'), self::DEFAULT_MIN_HOURS, self::MIN_HOURS, self::MAX_HOURS),
            'minCalls' => $acotar(config('ultron_studio.voice.canary_min_calls'), self::DEFAULT_MIN_CALLS, self::MIN_CALLS, self::MAX_CALLS),
        ];
    }

    // ── El freno (D8-19) ────────────────────────────────────────────────────

    /** `released`, `engaged` o `unknown` (sin la telefonía o si falla: falla cerrado). */
    public static function brakeState(): string
    {
        if (! method_exists(VoiceBrake::class, 'isOpen')) {
            return self::BRAKE_UNKNOWN;
        }
        try {
            $echado = VoiceBrake::isOpen();
        } catch (Throwable $e) {
            // Tragado a propósito: un freno que no se puede leer cuenta como echado. Solo la clase.
            Log::warning('ultron_studio.voice_brake_unavailable', ['error_class' => class_basename($e)]);

            return self::BRAKE_UNKNOWN;
        }

        return match ($echado) {
            false => self::BRAKE_RELEASED,
            true => self::BRAKE_ENGAGED,
            default => self::BRAKE_UNKNOWN,
        };
    }

    /**
     * `GET voice/brakes`: los frenos de la telefonía (B8, `VoiceBrake::list`,
     * las aperturas vivas primero), sin teléfonos ni textos de llamadas.
     *
     * @return array{state:string,brakes:list<array<string,mixed>>|null}
     */
    public static function brakes(int $limit): array
    {
        $estado = self::brakeState();
        if (! method_exists(VoiceBrake::class, 'list')) {
            return ['state' => $estado, 'brakes' => null];
        }
        try {
            $lista = VoiceBrake::list($limit);
        } catch (Throwable $e) {
            // Tragado a propósito: la lista no se inventa; sale null. Solo la clase.
            Log::warning('ultron_studio.voice_brake_list_unavailable', ['error_class' => class_basename($e)]);

            return ['state' => $estado, 'brakes' => null];
        }

        return ['state' => $estado, 'brakes' => is_array($lista) ? array_values($lista) : null];
    }

    /**
     * `POST voice/brakes/{uuid}/release`: SOLO una persona con
     * `ultron.voice_publish`, con una nota (D8-19); nunca se suelta solo. La
     * suelta es de la telefonía (B8, `VoiceBrake::release`, que deja su fila
     * de solo inserción y comprueba que esa apertura existe y no se soltó ya);
     * el libro del Studio lo registra en la misma transacción.
     *
     * Los rechazos de la telefonía (403, 404, 409 `brake_already_released`,
     * 422 de la nota) suben tal cual, con su código.
     *
     * @return array{brakeUuid:string,releaseUuid:string|null,releasedAt:string|null,state:string}
     */
    public function release(StudioAccess $access, Request $request, string $brakeUuid, string $note, string $clientRequestId): array
    {
        $access->require(StudioVoicePublishing::PUBLISH);
        if (! method_exists(VoiceBrake::class, 'release')) {
            throw new UltronStudioException('El freno de voz no se puede soltar ahora: la telefonía no está disponible en este servidor.', 'voice_brake_unavailable', 409);
        }

        $suelta = DB::transaction(function () use ($access, $request, $brakeUuid, $note, $clientRequestId): object {
            $suelta = VoiceBrake::release($access->actor, $brakeUuid, $note, $clientRequestId);
            $this->audit->record($request, $access->actor, 'voice_brake.released', StudioAuditEvent::SUBJECT_INTEGRATION, strtolower($brakeUuid),
                'Soltó el freno de voz', ['diff' => ['note' => $note]]);

            return $suelta;
        });

        return [
            'brakeUuid' => strtolower($brakeUuid),
            'releaseUuid' => is_string($suelta->uuid ?? null) ? $suelta->uuid : null,
            'releasedAt' => isset($suelta->created_at) && $suelta->created_at instanceof DateTimeInterface ? CarbonImmutable::instance($suelta->created_at)->toIso8601String() : null,
            'state' => self::brakeState(),
        ];
    }

    // ── Presentación ────────────────────────────────────────────────────────

    /**
     * `VoiceCanaryEvidenceDto`: lo medido, los umbrales y si pasa. Solo
     * recuentos y huellas: ningún id de llamada, teléfono ni texto.
     *
     * @param  array<string,mixed>  $record
     * @param  array{passes:bool,failing:list<string>,hours:int,evidenceHash:string}  $evaluation
     * @return array<string,mixed>
     */
    public static function dto(array $record, array $evaluation): array
    {
        return [
            'publication' => $record['publication'],
            'specHash' => $record['specHash'],
            'measures' => $record['measures'],
            'chainVerified' => $record['chainVerified'] ?? false,
            'brake' => $record['brake'] ?? self::BRAKE_UNKNOWN,
            'labVerdict' => $record['labVerdict'] ?? null,
            'labGate' => $record['labGate'] ?? StudioVoicePublishing::labGateMode(),
            'thresholds' => $record['thresholds'],
            'hours' => $evaluation['hours'],
            'passes' => $evaluation['passes'],
            'failing' => $evaluation['failing'],
            'evidenceHash' => $evaluation['evidenceHash'],
        ];
    }
}
