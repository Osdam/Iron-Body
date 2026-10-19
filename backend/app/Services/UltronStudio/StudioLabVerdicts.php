<?php

namespace App\Services\UltronStudio;

use App\Exceptions\UltronStudioException;
use App\Models\UltronStudio\StudioLabEvent;
use App\Models\UltronStudio\StudioLabRun;
use App\Models\UltronStudio\StudioLabRunCase;
use App\Models\UltronStudio\StudioLabVerdict;
use App\Models\UltronStudio\StudioVersion;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * El veredicto HUMANO sobre una ejecución del laboratorio (contrato H4 §A6).
 * Es la ÚNICA vía que inserta veredictos, y solo desde
 * `POST lab/runs/{uuid}/verdict`: nada aprueba solo (ni el runner, ni el job,
 * ni el comando nombran el veredicto; una prueba estática lo vigila).
 *
 *  - Solo sobre ejecuciones `completed`, en modo `openai` y sobre una VERSIÓN
 *    (409 `run_not_reviewable {reason}`).
 *  - CUATRO OJOS respecto al AUTOR DE LA VERSIÓN, por identidad: el autor
 *    recibe 403 `four_eyes_required` y queda un `run.verdict_denied`
 *    deduplicado. Quien lanzó la ejecución sí puede emitirlo si no es el autor
 *    (exigir lo contrario bloquearía a un equipo de dos personas).
 *  - Uno por ejecución (UNIQUE `run_id`): el segundo es 409 `verdict_exists`.
 *  - La huella que vio la persona (`resultsHash`) tiene que ser la que se
 *    recalcula de las filas, y la guardada también: si no, 409
 *    `run_integrity_mismatch`.
 *  - `approved` reconoce EXACTAMENTE los casos `fail`, `review`, `error` y
 *    `skipped` (409 `cases_not_acknowledged {cases}`).
 *
 * Se escriben, en la misma transacción, la fila de solo inserción y el evento
 * `run.verdict_recorded`. Relación con F: ninguna en este hito; ni la versión
 * ni el motor cambian.
 */
final class StudioLabVerdicts
{
    public function __construct(private readonly StudioLabEvents $events) {}

    /**
     * @param  list<string>|null  $acknowledgedCases  solo en `approved`
     *
     * @throws UltronStudioException
     */
    public function record(StudioAccess $access, StudioLabRun $run, string $decision, ?string $note, ?array $acknowledgedCases, string $resultsHash): StudioLabRun
    {
        $access->require(StudioAccess::APPROVE);

        $motivo = StudioLabRuns::notReviewableReason($run);
        if ($motivo !== null) {
            throw UltronStudioException::runNotReviewable($motivo, StudioLabRuns::NOT_REVIEWABLE[$motivo]);
        }

        $version = StudioVersion::query()->whereKey($run->target_version_id)->first(['id', 'uuid', 'content_hash', 'created_by_admin_id']);
        if ($version === null) {
            throw UltronStudioException::runNotReviewable('target_not_version', StudioLabRuns::NOT_REVIEWABLE['target_not_version']);
        }
        if ((int) $version->created_by_admin_id === $access->id()) {
            $this->events->recordDenied($access->actor, 'run.verdict_denied', StudioLabEvent::SUBJECT_RUN, $run->uuid,
                'Intentó emitir el veredicto sobre su propia versión', ['diff' => ['reason' => 'four_eyes_required']]);

            throw UltronStudioException::labFourEyesRequired();
        }
        if ($run->verdict()->exists()) {
            throw UltronStudioException::verdictExists();
        }

        $casos = StudioLabRunCase::query()->where('run_id', $run->id)->orderBy('position')->get();
        $recalculada = StudioLabRuns::resultsHash($run, $casos);
        if (! hash_equals((string) $run->results_hash, $recalculada)
            || ! hash_equals($recalculada, strtolower($resultsHash))
            || ! hash_equals((string) $version->content_hash, (string) $run->target_content_hash)) {
            throw UltronStudioException::runIntegrityMismatch();
        }

        $exigidos = StudioLabRuns::requiredAcknowledgements($casos->all());
        $reconocidos = null;
        if ($decision === StudioLabVerdict::DECISION_APPROVED) {
            if (self::asSet($acknowledgedCases ?? []) !== self::asSet($exigidos)) {
                throw UltronStudioException::casesNotAcknowledged($exigidos);
            }
            $reconocidos = $exigidos;
        }

        try {
            DB::transaction(function () use ($access, $run, $version, $decision, $note, $reconocidos, $recalculada): void {
                $yo = $access->snapshot();
                StudioLabVerdict::query()->forceCreate([
                    'run_id' => $run->id,
                    'version_id' => $version->id,
                    'version_content_hash' => $version->content_hash,
                    'results_hash' => $recalculada,
                    'decision' => $decision,
                    'note' => $note,
                    'acknowledged_cases' => $reconocidos,
                    'reviewer_admin_id' => $yo['id'],
                    'reviewer_name' => $yo['name'],
                    'reviewer_role' => $yo['role'],
                    'created_at' => now(),
                ]);

                $this->events->record($access->actor, 'run.verdict_recorded', StudioLabEvent::SUBJECT_RUN, $run->uuid,
                    'Emitió el veredicto «'.self::label($decision).'» sobre la ejecución', [
                        'diff' => ['decision' => $decision, 'note' => $note, 'acknowledgedCases' => $reconocidos],
                    ]);
            });
        } catch (UniqueConstraintViolationException) {
            // Dos veredictos a la vez: el UNIQUE paró al segundo.
            throw UltronStudioException::verdictExists();
        }

        return $run->fresh() ?? $run;
    }

    private static function label(string $decision): string
    {
        return match ($decision) {
            StudioLabVerdict::DECISION_APPROVED => 'aprobada',
            StudioLabVerdict::DECISION_REJECTED => 'rechazada',
            default => 'necesita cambios',
        };
    }

    /**
     * @param  list<string>  $codes
     * @return list<string>
     */
    private static function asSet(array $codes): array
    {
        $set = array_values(array_unique($codes));
        sort($set, SORT_STRING);

        return $set;
    }
}
