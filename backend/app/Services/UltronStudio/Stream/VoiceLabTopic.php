<?php

namespace App\Services\UltronStudio\Stream;

use App\Services\UltronStudio\StudioAccess;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * El tema `voice.lab`: las sesiones del laboratorio hablado
 * (`ultron_studio_voice_lab_sessions`). Permiso: el de
 * `GET api/admin/ultron-studio/voice-lab/sessions` (`ultron.view`).
 *
 * La tabla se actualiza (open → ended|failed, el veredicto, los códigos de la
 * guarda) y el libro solo recibe la apertura y el veredicto, no el final: el
 * cursor es el compuesto de {@see TouchCursorTopic} sobre el mayor de
 * `status_changed_at`, `verdict_at`, `updated_at` y `created_at` (el barrido
 * de sesiones abandonadas sube `status_changed_at` sin tocar `updated_at`).
 * La tabla es pequeña (presupuesto diario) y sin índice por toque.
 *
 * `data`: {type: voice_lab.session, id: uuid de la sesión, state: estado,
 * at: el toque, ref: veredicto o motivo de fin}. Ni notas, ni costes, ni
 * códigos de la guarda, ni personas.
 */
final class VoiceLabTopic extends TouchCursorTopic
{
    public const NAME = 'voice.lab';

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
        return 'GET api/admin/ultron-studio/voice-lab/sessions';
    }

    protected function query(): Builder
    {
        return DB::table('ultron_studio_voice_lab_sessions');
    }

    protected function touchSql(): string
    {
        return self::greatest('status_changed_at', 'COALESCE(verdict_at, status_changed_at)', 'COALESCE(updated_at, status_changed_at)', 'created_at');
    }

    protected function columns(): array
    {
        return ['uuid', 'status', 'verdict', 'end_reason'];
    }

    protected function event(object $row, string $cursor): StreamEvent
    {
        return new StreamEvent($cursor, [
            'type' => 'voice_lab.session',
            'id' => (string) $row->uuid,
            'state' => (string) $row->status,
            'at' => StreamCursors::iso($row->touch),
            'ref' => $row->verdict !== null ? (string) $row->verdict : ($row->end_reason !== null ? (string) $row->end_reason : null),
        ]);
    }
}
