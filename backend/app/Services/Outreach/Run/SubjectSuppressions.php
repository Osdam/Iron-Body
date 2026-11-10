<?php

namespace App\Services\Outreach\Run;

use App\Services\Outreach\Core\OutreachCatalog;
use Illuminate\Support\Facades\DB;

/**
 * LA SUPRESIÓN de una persona (contrato H9, D9-10 `opted_out` → «supresión
 * por finalidad y canal, ninguna estrategia lo anula» y `wrong_person` →
 * «supresión de ese canal»; §13.1 #10). Hasta que exista `outreach_consents`
 * (C9), la fuente de verdad es el libro de resultados del propio H9, que es
 * de solo inserción: un `opted_out` registrado no se puede borrar ni
 * reinterpretar, así que la supresión es permanente para esa finalidad y ese
 * medio. SOLO LECTURA. Alimenta `globalSuppression` de N2 (la escalera lo
 * niega con `global_suppression`).
 *
 *  - `opted_out` de la persona en una inscripción de la finalidad F por el
 *    medio M (o sin trabajo: por cualquier medio) → suprimida para (F, M);
 *  - `wrong_person` por el medio M → suprimida para (cualquier finalidad, M).
 */
final class SubjectSuppressions
{
    public const SUPPRESSING_OUTCOMES = ['opted_out', 'wrong_person'];

    public function isSuppressed(string $subjectKey, string $purpose, string $medium): bool
    {
        $filas = DB::table(OutreachTables::OUTCOMES.' as o')
            ->join(OutreachTables::ENROLLMENTS.' as e', 'e.id', '=', 'o.enrollment_id')
            ->leftJoin(OutreachTables::JOBS.' as j', 'j.id', '=', 'o.job_id')
            ->where('o.subject_key', $subjectKey)
            ->whereIn('o.outcome', self::SUPPRESSING_OUTCOMES)
            ->limit(200)
            ->get(['o.outcome', 'e.purpose', 'j.channel', 'j.medium']);
        foreach ($filas as $f) {
            $medio = self::mediumOfJob($f->channel, $f->medium);
            if ((string) $f->outcome === 'wrong_person' && ($medio === null || $medio === $medium)) {
                return true;
            }
            if ((string) $f->outcome === 'opted_out' && (string) $f->purpose === $purpose && ($medio === null || $medio === $medium)) {
                return true;
            }
        }

        return false;
    }

    /** El medio de un trabajo (`assisted` → su `medium`; los automáticos, el suyo); null = sin trabajo (todos los medios). */
    public static function mediumOfJob(?string $channel, ?string $medium): ?string
    {
        return match ($channel) {
            null, 'simulated' => null,
            'assisted' => $medium,
            OutreachCatalog::CHANNEL_WHATSAPP_TEMPLATE => OutreachCatalog::MEDIUM_WHATSAPP,
            OutreachCatalog::CHANNEL_VOICE_CALL => OutreachCatalog::MEDIUM_VOICE,
            default => null,
        };
    }
}
