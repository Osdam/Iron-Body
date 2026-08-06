<?php

namespace App\Services\IronGuard;

use App\Models\Incident;
use App\Models\IncidentEvent;
use App\Services\Observability\ChannelLog;
use Illuminate\Support\Facades\DB;

/**
 * Abre incidentes agrupando por clase de problema.
 *
 * Toda la utilidad del panel depende de esto. Si cada mensaje fallido abriera su
 * propia alarma, un worker caído una hora generaría cientos de filas y la
 * pantalla se volvería ilegible; y un panel ilegible se deja de mirar, que es la
 * forma habitual en que muere la observabilidad.
 *
 * El `fingerprint` describe la CLASE (fuente + tipo + clave discriminante como
 * el código de error), nunca la ocurrencia concreta. Dentro de la ventana de
 * agrupación, lo que llega suma ocurrencias y actualiza la evidencia.
 */
class IncidentRecorder
{
    /**
     * Registra una ocurrencia. Devuelve el incidente vivo (nuevo o actualizado).
     *
     * @param  array{source:string,kind:string,title:string,severity?:string,evidence?:array,correlation_ids?:array,affected_conversations?:int,affected_messages?:int}  $data
     */
    public function record(array $data): Incident
    {
        $fingerprint = $this->fingerprint($data);
        $severity = $data['severity'] ?? Incident::SEVERITY_MEDIUM;

        return DB::transaction(function () use ($fingerprint, $data, $severity): Incident {
            // Bloqueo de fila: dos detectores simultáneos no pueden crear dos
            // incidentes de la misma clase ni perder ocurrencias al sumar.
            $existing = Incident::where('fingerprint', $fingerprint)->lockForUpdate()->first();

            if ($existing !== null && $this->stillGrouping($existing)) {
                return $this->increment($existing, $data, $severity);
            }

            // O es nuevo, o el anterior ya se cerró / quedó fuera de ventana:
            // en ambos casos esto es un incidente distinto. Si había uno viejo
            // resuelto con el mismo fingerprint se reabre en su sitio, porque
            // el índice único no admite dos.
            if ($existing !== null) {
                return $this->reopen($existing, $data, $severity);
            }

            return $this->open($fingerprint, $data, $severity);
        });
    }

    private function open(string $fingerprint, array $data, string $severity): Incident
    {
        $incident = Incident::create([
            'fingerprint' => $fingerprint,
            'source' => $data['source'],
            'kind' => $data['kind'],
            'title' => $data['title'],
            'severity' => $severity,
            'status' => Incident::STATUS_OPEN,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'occurrences' => 1,
            'affected_conversations' => $data['affected_conversations'] ?? 0,
            'affected_messages' => $data['affected_messages'] ?? 0,
            'evidence' => $data['evidence'] ?? null,
            'correlation_ids' => $this->trimCorrelationIds($data['correlation_ids'] ?? []),
            'release' => config('observability.release') ?: null,
        ]);

        $this->addEvent($incident, IncidentEvent::KIND_STATUS, 'Incidente abierto por detección determinista.', [
            'severity' => $severity,
        ]);

        ChannelLog::warning('guard.incident.opened', [
            'incident_id' => $incident->id,
            'source' => $incident->source,
            'kind' => $incident->kind,
            'severity' => $severity,
        ]);

        return $incident;
    }

    private function increment(Incident $incident, array $data, string $severity): Incident
    {
        $changes = [
            'last_seen_at' => now(),
            'occurrences' => $incident->occurrences + 1,
            'affected_conversations' => max($incident->affected_conversations, $data['affected_conversations'] ?? 0),
            'affected_messages' => max($incident->affected_messages, $data['affected_messages'] ?? 0),
            // La evidencia se refresca: importa cómo está AHORA, no cómo estaba
            // la primera vez.
            'evidence' => $data['evidence'] ?? $incident->evidence,
            'correlation_ids' => $this->trimCorrelationIds(array_merge(
                (array) $incident->correlation_ids,
                $data['correlation_ids'] ?? [],
            )),
        ];

        // Un incidente solo empeora. Que una comprobación puntual lo vea "leve"
        // no debe rebajar algo que ya se juzgó grave.
        if ($incident->shouldEscalateTo($severity)) {
            $changes['severity'] = $severity;
        }

        // Insistir sube la gravedad: lo que se repite mucho deja de ser anécdota.
        $escalateAfter = (int) config('observability.incidents.escalate_after_occurrences', 10);
        if ($escalateAfter > 0
            && $changes['occurrences'] >= $escalateAfter
            && $incident->severity === Incident::SEVERITY_MEDIUM) {
            $changes['severity'] = Incident::SEVERITY_HIGH;
        }

        $incident->forceFill($changes)->save();

        return $incident;
    }

    private function reopen(Incident $incident, array $data, string $severity): Incident
    {
        $incident->forceFill([
            'status' => Incident::STATUS_OPEN,
            'severity' => $severity,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'occurrences' => 1,
            'affected_conversations' => $data['affected_conversations'] ?? 0,
            'affected_messages' => $data['affected_messages'] ?? 0,
            'evidence' => $data['evidence'] ?? null,
            'correlation_ids' => $this->trimCorrelationIds($data['correlation_ids'] ?? []),
            'resolved_at' => null,
            'acknowledged_at' => null,
            'release' => config('observability.release') ?: null,
        ])->save();

        $this->addEvent($incident, IncidentEvent::KIND_STATUS, 'Reabierto: el problema volvió a aparecer.', []);

        ChannelLog::warning('guard.incident.reopened', [
            'incident_id' => $incident->id,
            'kind' => $incident->kind,
        ]);

        return $incident;
    }

    /** ¿Sigue dentro de la ventana en la que esto cuenta como el mismo suceso? */
    private function stillGrouping(Incident $incident): bool
    {
        if (! $incident->isOpen()) {
            return false; // ya lo cerraron: si vuelve, es un suceso nuevo
        }

        $window = (int) config('observability.incidents.grouping_window_minutes', 60);

        return $incident->last_seen_at !== null
            && $incident->last_seen_at->greaterThan(now()->subMinutes($window));
    }

    /**
     * El fingerprint describe la CLASE, no la ocurrencia. Por eso solo entran
     * `source`, `kind` y las claves que de verdad distinguen un problema de
     * otro (por ejemplo el código de error de Meta) — nunca un id de mensaje,
     * que haría que cada fallo fuera un incidente distinto.
     */
    private function fingerprint(array $data): string
    {
        $parts = [
            $data['source'],
            $data['kind'],
            ...array_map('strval', $data['fingerprint_keys'] ?? []),
        ];

        return hash('sha256', implode('|', $parts));
    }

    /** Se guardan unos pocos hilos: suficientes para investigar, sin inflar la fila. */
    private function trimCorrelationIds(array $ids): array
    {
        return array_values(array_slice(array_unique(array_filter($ids)), 0, 10));
    }

    public function addEvent(Incident $incident, string $kind, ?string $summary, array $payload = [], ?string $actor = 'system'): IncidentEvent
    {
        return IncidentEvent::create([
            'incident_id' => $incident->id,
            'kind' => $kind,
            'actor' => $actor,
            'summary' => $summary,
            'payload' => $payload ?: null,
        ]);
    }
}
