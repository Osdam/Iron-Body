<?php

namespace App\Services\UltronStudio;

use App\Models\UltronStudio\StudioStrategyPublication;
use App\Models\UltronStudio\StudioStrategyVersion;
use App\Services\Outreach\Core\CanonicalJson;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * LA EVIDENCIA DEL CANARIO de una versión de estrategia (contrato H9, D9-11.0
 * «Supervisión» y «Promoción», P9-19, P9-20; T2). MEDIDA EN EL SERVIDOR y en
 * SOLO LECTURA sobre las tablas de la ejecución (`outreach_*`, de B9-run),
 * desde la entrada `publish` de la versión:
 *
 *  - ≥ {@see StudioStrategyConfig::canaryThresholds()} horas en canario;
 *  - ≥ N contactos que SALIERON en vivo (trabajos `started` de la parte
 *    canaria, nunca `simulated`);
 *  - 0 negativas de N1 detectadas después (decisiones `deny` de nivel N1 de
 *    la versión en la parte canaria: algo pasó la escalera y luego no debía);
 *  - 0 trabajos `ambiguous` sin resolver;
 *  - la tasa de *opt-out* ≤ {@see MAX_OPT_OUT_PERMILLE} ‰ (P9-20: 5 %).
 *
 * SIN LAS TABLAS DE LA EJECUCIÓN, o si no se pueden leer, NO PASA (falla
 * cerrado). La huella (`evidenceHash`) es la de lo que decide —sin las horas
 * exactas, que cambian a cada segundo—: lo que se vio es lo que se promueve.
 * Lo que se guarda en la cadena al promover no lleva ids ni textos.
 */
final class StudioStrategyEvidence
{
    /** P9-20: un *opt-out* por encima del 5 % frena; aquí, no deja promover. */
    public const MAX_OPT_OUT_PERMILLE = 50;

    public const FORMAT = 'ultron-strategy-canary-evidence/1';

    /**
     * @return array{dto: array<string,mixed>, record: array<string,mixed>, passes: bool, evidenceHash: string}
     */
    public static function measure(StudioStrategyVersion $version): array
    {
        $umbral = StudioStrategyConfig::canaryThresholds();
        $publicada = StudioStrategyPublication::query()->where('version_id', $version->id)->where('action', StudioStrategyPublication::ACTION_PUBLISH)
            ->orderByDesc('seq')->first(['id', 'seq', 'created_at']);
        $horas = $publicada?->created_at === null ? 0 : (int) floor($publicada->created_at->diffInMinutes(now(), true) / 60);
        $medidas = $publicada === null ? null : self::read((string) $version->uuid, $publicada->created_at->toDateTimeString());

        $motivos = [];
        if ($publicada === null || $version->status !== 'CANARY') {
            $motivos[] = 'not_in_canary';
        }
        if ($medidas === null) {
            $motivos[] = 'execution_unreadable';
        }
        if ($horas < $umbral['minHours']) {
            $motivos[] = 'min_hours';
        }
        if (($medidas['touches'] ?? 0) < $umbral['minTouches']) {
            $motivos[] = 'min_touches';
        }
        if (($medidas['n1After'] ?? 1) > 0) {
            $motivos[] = 'n1_after';
        }
        if (($medidas['ambiguousOpen'] ?? 1) > 0) {
            $motivos[] = 'ambiguous_open';
        }
        $permil = ($medidas['touches'] ?? 0) > 0 ? intdiv(1000 * ($medidas['optOuts'] ?? 0), $medidas['touches']) : 0;
        if ($permil > self::MAX_OPT_OUT_PERMILLE) {
            $motivos[] = 'opt_out_rate';
        }
        $pasa = $motivos === [];
        $registro = [
            'format' => self::FORMAT,
            'version' => (string) $version->uuid,
            'publishSeq' => $publicada === null ? null : (int) $publicada->seq,
            'hoursOk' => $horas >= $umbral['minHours'],
            'touches' => $medidas['touches'] ?? null,
            'n1After' => $medidas['n1After'] ?? null,
            'ambiguousOpen' => $medidas['ambiguousOpen'] ?? null,
            'optOuts' => $medidas['optOuts'] ?? null,
            'thresholds' => $umbral + ['maxOptOutPermille' => self::MAX_OPT_OUT_PERMILLE],
            'passes' => $pasa,
            'failing' => $motivos,
        ];
        $huella = CanonicalJson::hash($registro);

        return [
            'dto' => $registro + ['hours' => $horas, 'optOutPermille' => $permil, 'evidenceHash' => $huella, 'measuredAt' => now()->toIso8601String()],
            'record' => $registro + ['evidenceHash' => $huella],
            'passes' => $pasa,
            'evidenceHash' => $huella,
        ];
    }

    /**
     * Las medidas, en solo lectura. null si las tablas de la ejecución no
     * existen o no se pueden leer (falla cerrado; el log, solo la clase).
     *
     * @return array{touches:int,n1After:int,ambiguousOpen:int,optOuts:int}|null
     */
    private static function read(string $versionUuid, string $since): ?array
    {
        foreach (['outreach_jobs', 'outreach_decisions', 'outreach_outcomes', 'outreach_enrollments'] as $tabla) {
            if (! Schema::hasTable($tabla)) {
                return null;
            }
        }
        try {
            $version = DB::table('ultron_studio_strategy_versions')->where('uuid', $versionUuid)->value('id');
            $trabajos = DB::table('outreach_jobs')->where('strategy_version_id', $version)->where('part', 'canary')->where('mode', 'live')->where('created_at', '>=', $since);

            return [
                'touches' => (clone $trabajos)->where('status', 'started')->where('channel', '<>', 'simulated')->count(),
                'ambiguousOpen' => (clone $trabajos)->where('status', 'ambiguous')->count(),
                'n1After' => DB::table('outreach_decisions')->where('strategy_version_id', $version)->where('part', 'canary')->where('mode', 'live')
                    ->where('kind', 'deny')->where('level', 'N1')->where('evaluated_at', '>=', $since)->count(),
                'optOuts' => DB::table('outreach_outcomes as o')->join('outreach_enrollments as e', 'e.id', '=', 'o.enrollment_id')
                    ->where('e.strategy_version_id', $version)->where('e.part', 'canary')->where('e.mode', 'live')
                    ->where('o.outcome', 'opted_out')->where('o.occurred_at', '>=', $since)->count(),
            ];
        } catch (Throwable $e) {
            // Tragado a propósito: sin poder medir no se promueve (falla cerrado). Al log, solo la clase.
            Log::warning('ultron_studio.strategy_evidence_unreadable', ['error_class' => class_basename($e)]);

            return null;
        }
    }
}
