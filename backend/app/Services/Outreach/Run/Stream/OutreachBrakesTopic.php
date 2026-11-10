<?php

namespace App\Services\Outreach\Run\Stream;

use App\Services\Outreach\Run\OutreachTables;
use Illuminate\Support\Facades\DB;

/**
 * El tema `outreach.brakes`: los frenos y la PARADA GLOBAL (`outreach_brakes`)
 * que se abren o se sueltan. `data`: {type: scope, id: uuid del freno, state:
 * open|released, at: updated_at, ref: reason}. Sin nombres ni notas.
 */
final class OutreachBrakesTopic extends OutreachTopic
{
    public const NAME = 'outreach.brakes';

    /** El GET de lista del módulo cuyo permiso exige el tema. */
    public const LIST_PATH = 'brakes';

    public function name(): string
    {
        return self::NAME;
    }

    public function cursor(): string
    {
        return $this->compositeHead(OutreachTables::BRAKES);
    }

    public function since(string $cursor, int $limit): array
    {
        $eventos = [];
        foreach ($this->compositeSince(DB::table(OutreachTables::BRAKES), $cursor, $limit)->get(['id', 'uuid', 'scope', 'reason', 'released_at', 'updated_at']) as $f) {
            $eventos[] = self::event(self::compositeCursor($f->updated_at, (int) $f->id), [
                'type' => (string) $f->scope,
                'id' => (string) $f->uuid,
                'state' => $f->released_at === null ? 'open' : 'released',
                'at' => self::iso($f->updated_at),
                'ref' => (string) $f->reason,
            ]);
        }

        return $eventos;
    }
}
