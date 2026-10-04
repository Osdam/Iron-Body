<?php

use App\Services\Marketing\Ultron\CourtesyRequestService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * La Agenda comercial pasa a ser la ÚNICA fuente de las visitas, también de las
 * que solicita ULTRON por WhatsApp.
 *
 * Hasta ahora la solicitud de cortesía vivía en `marketing_agent_actions` y la
 * cita solo nacía cuando una persona la ejecutaba desde un panel aparte: el
 * equipo no veía las solicitudes en su agenda. Ahora la solicitud ES una cita en
 * estado `requested`, y confirmarla es pasarla a `scheduled`, que es lo que ya
 * significaba «confirmada» para ULTRON y para la guarda de promesas. No hay
 * estados duplicados.
 *
 * - `source`: de dónde salió la cita (`ultron`, `crm`, `commercial_tool`).
 *   Las anteriores quedan en null: no se sabe y no se inventa.
 * - Índice único parcial: una sola solicitud ABIERTA de ULTRON por lead. Es la
 *   red de la base para reintentos que llegaran a la vez; la lógica ya mueve la
 *   solicitud existente en vez de abrir otra.
 * - `marketing_agenda_state`: la versión de la agenda que escucha el canal SSE.
 *   Sube con cada escritura de una cita, venga del camino que venga.
 * - Las solicitudes de ULTRON que estuvieran abiertas en `marketing_agent_actions`
 *   se trasladan a la agenda: no puede quedar ninguna en el panel viejo, que ya
 *   no las lee. Idempotente: una ya trasladada no se repite.
 * - La cita de una cortesía ya ejecutada se marca `ultron`. Las cerradas sin cita
 *   (rechazadas, canceladas) NO se convierten en citas: inventaría filas con
 *   fechas pasadas que cambiarían las cifras de periodos ya cerrados. La guarda
 *   de promesas las sigue reconociendo en el panel viejo
 *   ({@see CourtesyRequestService::hadLegacyCourtesy()}).
 *
 * AGUANTA UN ROLLBACK Y UN NUEVO DESPLIEGUE. `down()` no borra `source`: sin él,
 * las solicitudes que ULTRON ya escribió en la agenda perderían su origen, y al
 * volver a desplegar escaparían al índice y a la guarda. `up()` tolera lo que el
 * rollback dejó, y lo que el código viejo anotara en el panel mientras tanto
 * mueve la solicitud abierta de esa persona en vez de abrir otra.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('marketing_appointments', 'source')) {
            Schema::table('marketing_appointments', function (Blueprint $table): void {
                $table->string('source', 20)->nullable();
            });
        }

        // El mismo predicado con el que ULTRON busca la solicitud de una persona
        // (visita solicitada): si el índice ocupara filas que la búsqueda no ve
        // —p. ej. una solicitud que alguien pasó a llamada—, la siguiente
        // cortesía chocaría contra él en vez de moverla.
        DB::statement(
            "CREATE UNIQUE INDEX IF NOT EXISTS marketing_appointments_one_open_courtesy_per_lead
             ON marketing_appointments (marketing_lead_id)
             WHERE source = 'ultron' AND status = 'requested' AND type = 'visit'"
        );

        if (! Schema::hasTable('marketing_agenda_state')) {
            Schema::create('marketing_agenda_state', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('version')->default(0);
                $table->timestamps();
            });
        }
        DB::table('marketing_agenda_state')->insertOrIgnore(['id' => 1, 'version' => 0, 'created_at' => now(), 'updated_at' => now()]);

        $this->trasladarSolicitudesAbiertas();
        $this->marcarCitasDeCortesiasEjecutadas();
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS marketing_appointments_one_open_courtesy_per_lead');
        Schema::dropIfExists('marketing_agenda_state');
        // `source` se queda a propósito: ver el docblock de la clase.
    }

    private function trasladarSolicitudesAbiertas(): void
    {
        $abiertas = DB::table('marketing_agent_actions')
            ->where('action_type', 'create_appointment')
            ->whereIn('status', ['suggested', 'approved'])
            ->where('payload->source', 'ultron')
            ->orderByDesc('id')
            ->get();

        $trasladadas = 0;
        $leadsConSolicitud = [];

        foreach ($abiertas as $accion) {
            $payload = json_decode((string) $accion->payload, true) ?: [];
            $lead = $accion->marketing_lead_id;

            // Dos abiertas del mismo lead: vale la más reciente; la otra queda sustituida.
            if ($lead !== null && isset($leadsConSolicitud[$lead])) {
                DB::table('marketing_agent_actions')->where('id', $accion->id)->update([
                    'status' => 'cancelled',
                    'rejection_reason' => 'Sustituida por una solicitud más reciente al trasladar la cortesía a la Agenda comercial.',
                    'updated_at' => now(),
                ]);

                continue;
            }

            $citaId = DB::table('marketing_appointments')
                ->where('metadata->migrated_from_action_id', (int) $accion->id)
                ->value('id')
                ?? $this->moverLaAbiertaDelLead($accion, $payload);

            if ($citaId === null) {
                $citaId = DB::table('marketing_appointments')->insertGetId([
                    'uuid' => (string) Str::uuid(),
                    'marketing_lead_id' => $lead,
                    'marketing_conversation_id' => $accion->marketing_conversation_id,
                    'type' => 'visit',
                    'status' => 'requested',
                    'source' => 'ultron',
                    'title' => $payload['title'] ?? 'Día de cortesía',
                    'notes' => $payload['notes'] ?? null,
                    'scheduled_at' => $payload['scheduled_at'],
                    'duration_minutes' => (int) ($payload['duration_minutes'] ?? 60),
                    'metadata' => json_encode([
                        'requested_date' => $payload['requested_date'] ?? null,
                        'requested_time' => $payload['requested_time'] ?? null,
                        'requested_at_local' => $payload['requested_at_local'] ?? null,
                        'message_id' => $accion->marketing_message_id,
                        'migrated_from_action_id' => (int) $accion->id,
                        'ultron_conversation_ids' => array_values(array_filter([(int) $accion->marketing_conversation_id])),
                        'history' => [['event' => 'requested', 'at' => (string) $accion->created_at, 'by' => 'ultron']],
                    ], JSON_UNESCAPED_UNICODE),
                    'created_at' => $accion->created_at,
                    'updated_at' => now(),
                ]);
            }

            DB::table('marketing_agent_actions')->where('id', $accion->id)->update([
                'status' => 'cancelled',
                'rejection_reason' => "Trasladada a la Agenda comercial como solicitud (cita #{$citaId}).",
                'updated_at' => now(),
            ]);

            if ($lead !== null) {
                $leadsConSolicitud[$lead] = true;
            }
            $trasladadas++;
        }

        if ($trasladadas > 0) {
            DB::table('marketing_agenda_state')->where('id', 1)->increment('version');
        }
    }

    /**
     * La persona ya tiene una solicitud abierta de ULTRON en la agenda: pasa
     * cuando, tras un rollback, el código viejo volvió a anotar en el panel. La
     * acción, que es más reciente, MUEVE esa misma cita a su día. Nunca dos
     * abiertas, y el índice único no salta en medio del despliegue.
     *
     * @param  array<string,mixed>  $payload
     */
    private function moverLaAbiertaDelLead(object $accion, array $payload): ?int
    {
        if ($accion->marketing_lead_id === null) {
            return null;
        }
        $abierta = DB::table('marketing_appointments')
            ->where('marketing_lead_id', $accion->marketing_lead_id)
            ->where('source', 'ultron')
            ->where('status', 'requested')
            ->where('type', 'visit')
            ->first();
        if ($abierta === null) {
            return null;
        }

        $meta = json_decode((string) $abierta->metadata, true) ?: [];
        $conversaciones = array_map('intval', (array) ($meta['ultron_conversation_ids'] ?? []));
        foreach ([$abierta->marketing_conversation_id, $accion->marketing_conversation_id] as $c) {
            if ($c !== null && ! in_array((int) $c, $conversaciones, true)) {
                $conversaciones[] = (int) $c;
            }
        }
        $meta = array_merge($meta, [
            'requested_date' => $payload['requested_date'] ?? null,
            'requested_time' => $payload['requested_time'] ?? null,
            'requested_at_local' => $payload['requested_at_local'] ?? null,
            'ultron_conversation_ids' => $conversaciones,
        ]);
        $meta['history'][] = [
            'event' => 'rescheduled', 'at' => (string) $accion->created_at, 'by' => 'ultron',
            'from' => (string) $abierta->scheduled_at, 'to' => (string) $payload['scheduled_at'],
            'migrated_from_action_id' => (int) $accion->id,
        ];

        DB::table('marketing_appointments')->where('id', $abierta->id)->update([
            'scheduled_at' => $payload['scheduled_at'],
            'metadata' => json_encode($meta, JSON_UNESCAPED_UNICODE),
            'updated_at' => now(),
        ]);

        return (int) $abierta->id;
    }

    /**
     * Las cortesías que el equipo ya ejecutó antes de este cambio crearon su cita
     * sin origen: se marca `ultron`, para que la agenda enseñe de dónde salió y
     * la guarda de promesas la reconozca como la cortesía de esa conversación.
     */
    private function marcarCitasDeCortesiasEjecutadas(): void
    {
        DB::table('marketing_agent_actions')
            ->where('action_type', 'create_appointment')
            ->where('status', 'executed')
            ->where('payload->source', 'ultron')
            ->get(['result'])
            ->each(function ($accion): void {
                $cita = (int) data_get(json_decode((string) $accion->result, true), 'appointment_id');
                if ($cita > 0) {
                    DB::table('marketing_appointments')->where('id', $cita)->whereNull('source')->update(['source' => 'ultron']);
                }
            });
    }
};
