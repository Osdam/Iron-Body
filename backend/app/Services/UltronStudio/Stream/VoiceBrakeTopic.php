<?php

namespace App\Services\UltronStudio\Stream;

use App\Services\UltronStudio\StudioAccess;
use Illuminate\Support\Facades\DB;

/**
 * El tema `voice.brake`: las aperturas y las sueltas del freno de la voz
 * (`voice_brakes`, de solo inserción, por `id`). Permiso: el de
 * `GET api/admin/ultron-studio/voice/brakes` (`ultron.view`).
 *
 * `data`: {type: voice_brake.open|voice_brake.release, id: uuid de la fila,
 * state: motivo del freno (en la suelta, el de la apertura que suelta), at,
 * ref: en la suelta, el uuid de la apertura}. Ni la evidencia ni la nota.
 */
final class VoiceBrakeTopic implements StreamCountable, StreamTopic
{
    public const NAME = 'voice.brake';

    public function name(): string
    {
        return self::NAME;
    }

    public function permission(): string
    {
        return StudioAccess::VIEW;
    }

    public function listRoute(): ?string
    {
        return 'GET api/admin/ultron-studio/voice/brakes';
    }

    public function cursor(): string
    {
        return (string) (int) DB::table('voice_brakes')->max('id');
    }

    public function countThrough(string $cursor): int
    {
        $hasta = StreamCursors::id($cursor) ?? (int) $this->cursor();

        return (int) DB::table('voice_brakes')->where('id', '<=', $hasta)->count();
    }

    public function since(string $cursor, int $limit): array
    {
        $desde = StreamCursors::id($cursor) ?? (int) $this->cursor();

        $filas = DB::table('voice_brakes as b')
            ->leftJoin('voice_brakes as o', 'o.id', '=', 'b.brake_id')
            ->where('b.id', '>', $desde)
            ->orderBy('b.id')
            ->limit($limit)
            ->get(['b.id', 'b.uuid', 'b.kind', 'b.reason', 'b.created_at', 'o.uuid as open_uuid', 'o.reason as open_reason']);

        $eventos = [];
        foreach ($filas as $f) {
            $motivo = $f->reason ?? $f->open_reason;
            $eventos[] = new StreamEvent((string) $f->id, [
                'type' => 'voice_brake.'.$f->kind,
                'id' => (string) $f->uuid,
                'state' => $motivo !== null ? (string) $motivo : null,
                'at' => StreamCursors::iso($f->created_at),
                'ref' => $f->open_uuid !== null ? (string) $f->open_uuid : null,
            ]);
        }

        return $eventos;
    }
}
