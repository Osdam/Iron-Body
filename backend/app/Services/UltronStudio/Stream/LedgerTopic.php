<?php

namespace App\Services\UltronStudio\Stream;

use App\Models\UltronStudio\StudioAuditEvent;
use App\Models\UltronStudio\StudioDraft;
use App\Services\UltronStudio\StudioAccess;
use Illuminate\Database\Eloquent\Builder;

/**
 * El tema `ledger`: la cola del libro de solo inserción del Studio
 * (`ultron_studio_audit_events`) por `id` creciente.
 *
 * Permiso: el de `GET api/admin/ultron-studio/audit` (`ultron.view`). Filtro
 * por sujeto, el mismo que el GET: sin `audit.view`, los eventos de BORRADOR
 * solo si el borrador es de quien mira (StudioAudit::page). El resto de
 * sujetos (versiones, reglas, sistema, integraciones, estrategias…) se lee
 * con `ultron.view`, igual que allí.
 *
 * `data`: {type: evento del libro, id: uuid del sujeto (o su tipo cuando no
 * tiene uuid: `system`), state: estado al que pasó, at: UTC, hash: huella
 * del contenido si la fila la lleva, ref: tipo de sujeto}. Nunca `summary`,
 * `diff`, `metadata`, actor, ip ni user agent: ni se seleccionan.
 */
final class LedgerTopic implements StreamCountable, StreamTopic
{
    public const NAME = 'ledger';

    public function __construct(private readonly StudioAccess $viewer) {}

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
        return 'GET api/admin/ultron-studio/audit';
    }

    public function cursor(): string
    {
        return (string) (int) StudioAuditEvent::query()->max('id');
    }

    public function countThrough(string $cursor): int
    {
        $hasta = StreamCursors::id($cursor) ?? (int) $this->cursor();

        return (int) $this->visible()->where('id', '<=', $hasta)->count();
    }

    public function since(string $cursor, int $limit): array
    {
        // Un cursor que no es un id del libro (texto de otra forma) arranca en
        // la cabeza: no se vuelve a emitir el libro entero por un cursor roto.
        $desde = StreamCursors::id($cursor) ?? (int) $this->cursor();

        $consulta = $this->visible()
            ->select(['id', 'event', 'subject_type', 'subject_uuid', 'to_status', 'content_hash', 'created_at'])
            ->where('id', '>', $desde)
            ->orderBy('id')
            ->limit($limit);

        $eventos = [];
        foreach ($consulta->get() as $fila) {
            $eventos[] = new StreamEvent((string) $fila->id, [
                'type' => (string) $fila->event,
                'id' => $fila->subject_uuid !== null ? (string) $fila->subject_uuid : (string) $fila->subject_type,
                'state' => $fila->to_status !== null ? (string) $fila->to_status : null,
                'at' => $fila->created_at?->utc()->toIso8601String() ?? now()->utc()->toIso8601String(),
                'hash' => $fila->content_hash !== null ? (string) $fila->content_hash : null,
                'ref' => (string) $fila->subject_type,
            ]);
        }

        return $eventos;
    }

    /** El libro que esta persona puede ver: sin `audit.view`, los borradores solo si son suyos (StudioAudit::page). */
    private function visible(): Builder
    {
        $consulta = StudioAuditEvent::query();
        if (! $this->viewer->seesEveryDraftEvent()) {
            $consulta->where(fn ($q) => $q
                ->where('subject_type', '<>', StudioAuditEvent::SUBJECT_DRAFT)
                ->orWhereIn('subject_uuid', StudioDraft::query()->select('uuid')->where('admin_id', $this->viewer->id())));
        }

        return $consulta;
    }
}
