<?php

namespace App\Services\UltronStudio\Stream;

use App\Models\Incident;
use App\Services\UltronStudio\StudioAccess;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * El tema `monitor.incidents`: los incidentes de IRON GUARD de las fuentes
 * del Studio (`ultron_studio`, el monitor del H7; `outreach`, el del H9;
 * `ultron_studio_stream.incident_sources`).
 *
 * Se lee la FILA del incidente (`incidents`) y no su cola de eventos
 * (`incident_events`): una repetición (`IncidentRecorder::increment`) solo
 * suma a la fila, sin dejar evento, y es justo lo que un monitor tiene que
 * ver. Cursor compuesto de {@see TouchCursorTopic} sobre `updated_at`, que
 * Eloquent sube al abrir, repetir, reabrir y cambiar de estado.
 *
 * Permiso: el de `GET api/admin/iron-guard/incidents` (`security.view`) y,
 * como ese controlador, solo ciertos ROLES ({@see ALLOWED_ROLES}, la misma
 * lista que `IronGuardController`, vigilada por una prueba): una llave sin el
 * rol omite el tema igual que sin la llave.
 *
 * `data`: {type: incident.opened (abierto o reabierto: una ocurrencia y
 * estado `open`) | incident.updated (repetición, reconocimiento, resolución),
 * id: id del incidente, state: estado, at: el toque, ref: clase del fallo
 * (`publication_drift`…)}. Ni títulos, ni evidencia, ni análisis, ni personas.
 */
final class IncidentsTopic extends TouchCursorTopic implements StreamViewerGate
{
    public const NAME = 'monitor.incidents';

    /** Los roles que IRON GUARD deja pasar (IronGuardController::ALLOWED_ROLES, privada allí). */
    public const ALLOWED_ROLES = ['super admin', 'administrador', 'admin'];

    public function name(): string
    {
        return self::NAME;
    }

    public function permission(): string
    {
        return 'security.view';
    }

    public function listRoute(): ?string
    {
        return 'GET api/admin/iron-guard/incidents';
    }

    public function readableBy(StudioAccess $viewer): bool
    {
        return in_array(mb_strtolower(trim((string) $viewer->actor->role)), self::ALLOWED_ROLES, true);
    }

    protected function query(): Builder
    {
        return DB::table('incidents')->whereIn('source', self::sources());
    }

    protected function touchSql(): string
    {
        return 'COALESCE(updated_at, created_at, last_seen_at)';
    }

    protected function columns(): array
    {
        return ['status', 'kind', 'occurrences'];
    }

    protected function event(object $row, string $cursor): StreamEvent
    {
        $abierto = (string) $row->status === Incident::STATUS_OPEN && (int) $row->occurrences === 1;

        return new StreamEvent($cursor, [
            'type' => $abierto ? 'incident.opened' : 'incident.updated',
            'id' => (string) $row->id,
            'state' => (string) $row->status,
            'at' => StreamCursors::iso($row->touch),
            'ref' => (string) $row->kind,
        ]);
    }

    /** @return list<string> */
    public static function sources(): array
    {
        $fuentes = config('ultron_studio_stream.incident_sources');

        return is_array($fuentes) && $fuentes !== [] ? array_values(array_filter($fuentes, 'is_string')) : ['ultron_studio', 'outreach'];
    }
}
