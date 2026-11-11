<?php

namespace App\Services\Outreach\Run;

use App\Services\Outreach\Core\OutreachCatalog;
use Illuminate\Support\Facades\DB;

/**
 * LA SUPRESIÓN de una persona (contrato H9, D9-10 `opted_out` → «supresión
 * por finalidad y canal, ninguna estrategia lo anula» y `wrong_person` →
 * «supresión de ese canal»; D9-15 «toda señal negativa de cualquier fuente
 * gana»; §13.1 #10; SC-1 y SC-2 de la revisión de seguridad del cierre).
 * Hasta que exista `outreach_consents` (C9), la fuente de verdad es el libro
 * de resultados del propio H9, que es de solo inserción: lo registrado no se
 * puede borrar ni reinterpretar, así que la supresión es permanente. SOLO
 * LECTURA. Alimenta `globalSuppression` de N2 (la escalera lo niega con
 * `global_suppression`), en el planificador y otra vez en el despachador.
 *
 *  - `opted_out` que la PERSONA dijo con sus palabras, por WhatsApp
 *    (`inbound_message`, W9) o en la llamada (`voice_call`, la herramienta
 *    `no_volver_a_llamar`), en una inscripción de la finalidad F → suprimida
 *    para F en TODOS los medios (SC-1): quien contesta «No me contacten más» a
 *    una plantilla tampoco recibe la llamada de otra estrategia de F;
 *  - una palabra de baja (segura o POSIBLE, SC-2) que la ejecución vio por
 *    WhatsApp y dejó en el libro con el detalle {@see OPT_OUT_WORD_DETAIL} (el
 *    normalizador, como `human_requested` derivado a una persona; el canal,
 *    como `failed` de un trabajo que no envió) en una inscripción de F →
 *    suprimida para F en todos los medios hasta que una persona la resuelva
 *    (hoy, marcando «no contactar»: el libro no tiene «descartar», D-3 del
 *    informe sec-fix-baja);
 *  - `opted_out` de otra fuente (`staff`, `classifier`) en una inscripción de
 *    F por el medio M (o sin trabajo: por cualquier medio) → suprimida para
 *    (F, M), como hasta ahora;
 *  - `wrong_person` por el medio M → suprimida para (cualquier finalidad, M).
 *
 * Nada de esto cruza finalidades: una baja comercial no alcanza `service` (lo
 * decide el propietario con su asesoría, D-5 del informe sec-fix-baja).
 */
final class SubjectSuppressions
{
    public const SUPPRESSING_OUTCOMES = ['opted_out', 'wrong_person'];

    /** SC-1 · las fuentes cuyo `opted_out` alcanza TODOS los medios de su finalidad: la persona lo dijo con sus palabras. */
    public const PURPOSE_WIDE_OPT_OUT_SOURCES = ['inbound_message', 'voice_call'];

    /** SC-2 · el detalle con el que la ejecución deja en el libro una palabra de baja (segura o posible) vista por WhatsApp. */
    public const OPT_OUT_WORD_DETAIL = 'opt_out_word';

    public function isSuppressed(string $subjectKey, string $purpose, string $medium): bool
    {
        $filas = DB::table(OutreachTables::OUTCOMES.' as o')
            ->join(OutreachTables::ENROLLMENTS.' as e', 'e.id', '=', 'o.enrollment_id')
            ->leftJoin(OutreachTables::JOBS.' as j', 'j.id', '=', 'o.job_id')
            ->where('o.subject_key', $subjectKey)
            ->where(static fn ($q) => $q->whereIn('o.outcome', self::SUPPRESSING_OUTCOMES)->orWhere('o.detail_code', self::OPT_OUT_WORD_DETAIL))
            ->limit(200)
            ->get(['o.outcome', 'o.source', 'o.detail_code', 'e.purpose', 'j.channel', 'j.medium']);
        foreach ($filas as $f) {
            $medio = self::mediumOfJob($f->channel, $f->medium);
            $suMedio = $medio === null || $medio === $medium;
            if ((string) $f->outcome === 'wrong_person' && $suMedio) {
                return true;
            }
            if ((string) $f->purpose !== $purpose) {
                continue;
            }
            if ((string) $f->detail_code === self::OPT_OUT_WORD_DETAIL) {
                return true;
            }
            if ((string) $f->outcome === 'opted_out' && ($suMedio || in_array((string) $f->source, self::PURPOSE_WIDE_OPT_OUT_SOURCES, true))) {
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
