<?php

namespace App\Services\Outreach\Run\Stream;

use App\Services\Outreach\Run\OutreachTables;
use Illuminate\Support\Facades\DB;

/**
 * El tema `outreach.outcomes`: el libro de resultados (`outreach_outcomes`,
 * solo inserción) por `id` creciente. `data`: {type: outcome, id: uuid del
 * resultado, state: next_action, at: occurred_at, ref: source}. Sin la
 * persona ni el canal del trabajo: el detalle se abre por la inscripción.
 */
final class OutreachOutcomesTopic extends OutreachTopic
{
    public const NAME = 'outreach.outcomes';

    /** El GET de lista del módulo cuyo permiso exige el tema. */
    public const LIST_PATH = 'enrollments';

    public function name(): string
    {
        return self::NAME;
    }

    public function cursor(): string
    {
        return (string) (int) DB::table(OutreachTables::OUTCOMES)->max('id');
    }

    public function since(string $cursor, int $limit): array
    {
        $desde = ctype_digit($cursor) && strlen($cursor) <= 18 ? (int) $cursor : (int) $this->cursor();
        $eventos = [];
        foreach (DB::table(OutreachTables::OUTCOMES)->where('id', '>', $desde)->orderBy('id')->limit(max(1, $limit))->get(['id', 'uuid', 'outcome', 'source', 'next_action', 'occurred_at']) as $f) {
            $eventos[] = self::event((string) $f->id, [
                'type' => (string) $f->outcome,
                'id' => (string) $f->uuid,
                'state' => $f->next_action !== null ? (string) $f->next_action : null,
                'at' => self::iso($f->occurred_at),
                'ref' => (string) $f->source,
            ]);
        }

        return $eventos;
    }
}
