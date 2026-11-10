<?php

namespace App\Services\Outreach\Run\Stream;

use App\Services\Outreach\Run\OutreachTables;
use Illuminate\Support\Facades\DB;

/**
 * El tema `outreach.runs`: las pasadas (`outreach_runs`) que nacen o se
 * cierran. `data`: {type: kind (ingest|sweep|dispatch|monitor|reconcile|replay),
 * id: uuid de la pasada, state: status, at: updated_at, ref: error_code o mode}.
 */
final class OutreachRunsTopic extends OutreachTopic
{
    public const NAME = 'outreach.runs';

    /** El GET de lista del módulo cuyo permiso exige el tema. */
    public const LIST_PATH = 'status';

    public function name(): string
    {
        return self::NAME;
    }

    public function cursor(): string
    {
        return $this->compositeHead(OutreachTables::RUNS);
    }

    public function since(string $cursor, int $limit): array
    {
        $eventos = [];
        foreach ($this->compositeSince(DB::table(OutreachTables::RUNS), $cursor, $limit)->get(['id', 'uuid', 'kind', 'mode', 'status', 'error_code', 'updated_at']) as $f) {
            $eventos[] = self::event(self::compositeCursor($f->updated_at, (int) $f->id), [
                'type' => (string) $f->kind,
                'id' => (string) $f->uuid,
                'state' => (string) $f->status,
                'at' => self::iso($f->updated_at),
                'ref' => $f->error_code !== null ? (string) $f->error_code : (string) $f->mode,
            ]);
        }

        return $eventos;
    }
}
