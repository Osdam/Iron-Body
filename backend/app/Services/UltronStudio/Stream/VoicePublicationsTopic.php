<?php

namespace App\Services\UltronStudio\Stream;

use App\Services\UltronStudio\StudioAccess;
use Illuminate\Support\Facades\DB;

/**
 * El tema `voice.publications`: la cadena de voz
 * (`ultron_studio_voice_publications`, de solo inserción, por `id`, que crece
 * con `seq`). Permiso: el de `GET api/admin/ultron-studio/voice/publications`
 * (`ultron.view`).
 *
 * `data`: {type: voice_publication.publish|promote|rollback|off, id: uuid de
 * la entrada, state: parte (canary|rest), at, hash: huella efectiva de la
 * entrada, ref: su `seq`}. Ni el mensaje firmado, ni la firma, ni el motivo.
 */
final class VoicePublicationsTopic implements StreamCountable, StreamTopic
{
    public const NAME = 'voice.publications';

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
        return 'GET api/admin/ultron-studio/voice/publications';
    }

    public function cursor(): string
    {
        return (string) (int) DB::table('ultron_studio_voice_publications')->max('id');
    }

    public function countThrough(string $cursor): int
    {
        $hasta = StreamCursors::id($cursor) ?? (int) $this->cursor();

        return (int) DB::table('ultron_studio_voice_publications')->where('id', '<=', $hasta)->count();
    }

    public function since(string $cursor, int $limit): array
    {
        $desde = StreamCursors::id($cursor) ?? (int) $this->cursor();

        $filas = DB::table('ultron_studio_voice_publications')
            ->where('id', '>', $desde)
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'uuid', 'seq', 'action', 'scope', 'effective_hash', 'created_at']);

        $eventos = [];
        foreach ($filas as $f) {
            $eventos[] = new StreamEvent((string) $f->id, [
                'type' => 'voice_publication.'.$f->action,
                'id' => (string) $f->uuid,
                'state' => (string) $f->scope,
                'at' => StreamCursors::iso($f->created_at),
                'hash' => (string) $f->effective_hash,
                'ref' => (string) $f->seq,
            ]);
        }

        return $eventos;
    }
}
