<?php

namespace App\Services\Outreach\Run\Stream;

use App\Services\Outreach\Run\OutreachTables;
use Illuminate\Support\Facades\DB;

/**
 * El tema `outreach.jobs`: los trabajos (`outreach_jobs`) que nacen o cambian
 * de estado. `data`: {type: channel, id: uuid del trabajo, state: status,
 * at: updated_at, ref: cancel_reason o mode}. Nunca `phone_ref`, `subject_key`
 * ni `subject_ref`: un trabajo se abre por su uuid en la API.
 */
final class OutreachJobsTopic extends OutreachTopic
{
    public const NAME = 'outreach.jobs';

    /** El GET de lista del módulo cuyo permiso exige el tema. */
    public const LIST_PATH = 'jobs';

    public function name(): string
    {
        return self::NAME;
    }

    public function cursor(): string
    {
        return $this->compositeHead(OutreachTables::JOBS);
    }

    public function since(string $cursor, int $limit): array
    {
        $eventos = [];
        foreach ($this->compositeSince(DB::table(OutreachTables::JOBS), $cursor, $limit)->get(['id', 'uuid', 'channel', 'mode', 'status', 'cancel_reason', 'updated_at']) as $f) {
            $eventos[] = self::event(self::compositeCursor($f->updated_at, (int) $f->id), [
                'type' => (string) $f->channel,
                'id' => (string) $f->uuid,
                'state' => (string) $f->status,
                'at' => self::iso($f->updated_at),
                'ref' => $f->cancel_reason !== null ? (string) $f->cancel_reason : (string) $f->mode,
            ]);
        }

        return $eventos;
    }
}
