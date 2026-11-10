<?php

namespace App\Services\UltronStudio\Stream;

use App\Models\Voice\VoiceCallEvent;
use Illuminate\Support\Facades\DB;

/**
 * Base de los dos temas que leen la cola de EVENTOS DE LLAMADA
 * (`voice_call_events`, de solo inserción, por `id` creciente), repartidos
 * por `kind`: {@see VoiceCallsTopic} (el ciclo de vida de una llamada) y
 * {@see VoiceReconcileTopic} (lo que escribe la conciliación). Los dos
 * conjuntos son disjuntos y juntos son {@see VoiceCallEvent::KINDS} (lo
 * vigila una prueba de guarda).
 *
 * `data`: {type: `<prefijo>.<kind>`, id: uuid de la llamada (o del evento si
 * no tiene llamada), state: estado actual de la llamada, at: cuándo ocurrió,
 * ref: sentido (inbound|outbound)}. Se seleccionan solo esas columnas: ni
 * huellas, ni los 4 dígitos, ni códigos, ni actores.
 */
abstract class VoiceCallEventsTopic implements StreamCountable, StreamTopic
{
    /** Lo que escribe la conciliación (VoiceReconciler, el aviso de gasto y el post-llamada sin llamada conocida). */
    public const RECONCILE_KINDS = ['reconciled', 'cost_recorded', 'unknown_provider_call'];

    /** @return list<string> */
    abstract protected function kinds(): array;

    abstract protected function prefix(): string;

    public function cursor(): string
    {
        return (string) (int) DB::table('voice_call_events')->whereIn('kind', $this->kinds())->max('id');
    }

    public function countThrough(string $cursor): int
    {
        $hasta = StreamCursors::id($cursor) ?? (int) $this->cursor();

        return (int) DB::table('voice_call_events')->whereIn('kind', $this->kinds())->where('id', '<=', $hasta)->count();
    }

    public function since(string $cursor, int $limit): array
    {
        $desde = StreamCursors::id($cursor) ?? (int) $this->cursor();

        $filas = DB::table('voice_call_events as e')
            ->leftJoin('voice_calls as c', 'c.id', '=', 'e.call_id')
            ->where('e.id', '>', $desde)
            ->whereIn('e.kind', $this->kinds())
            ->orderBy('e.id')
            ->limit($limit)
            ->get(['e.id', 'e.uuid', 'e.kind', 'e.occurred_at', 'e.created_at', 'c.uuid as call_uuid', 'c.status', 'c.direction']);

        $eventos = [];
        foreach ($filas as $f) {
            $eventos[] = new StreamEvent((string) $f->id, [
                'type' => $this->prefix().'.'.$f->kind,
                'id' => (string) ($f->call_uuid ?? $f->uuid),
                'state' => $f->status !== null ? (string) $f->status : null,
                'at' => StreamCursors::iso($f->occurred_at ?? $f->created_at),
                'ref' => $f->direction !== null ? (string) $f->direction : null,
            ]);
        }

        return $eventos;
    }
}
