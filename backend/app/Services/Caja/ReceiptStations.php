<?php

namespace App\Services\Caja;

use App\Models\Admin;
use App\Models\ReceiptAgentTicket;
use App\Models\ReceiptStation;
use App\Services\Audit\AuditTrail;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Los equipos de caja con el servicio local de impresión: alta, ajustes,
 * tickets de prueba y de configuración, y su rastro. Contrato §5.3.
 *
 * Registrar un equipo es lo que lo habilita para recibir trabajos: sin estación
 * registrada y activa no se emite ningún ticket para él. Por eso escribir aquí
 * exige SUPERVISAR una caja (`cash.gym.manage` o `cash.products.manage`, lo
 * comprueba el controlador) y cada alta, cada cambio y cada ticket de
 * configuración quedan en la auditoría, con su autor, en la MISMA transacción
 * que el cambio: un cambio sin traza no se confirma.
 *
 * Los tickets de prueba no se auditan uno a uno: no tocan nada y su rastro
 * completo ya está en `receipt_agent_tickets` (quién, cuándo, qué salió).
 */
final class ReceiptStations
{
    /** Campos que el administrador puede cambiar con PATCH. */
    public const EDITABLE = ['name', 'active', 'printer_name', 'columns', 'codepage', 'feed_lines'];

    private const MAX_EVENTS = 100;

    public function __construct(
        private readonly AuditTrail $audit,
        private readonly ReceiptAgentTickets $tickets,
    ) {}

    public function find(string $stationKey): ?ReceiptStation
    {
        return ReceiptStation::where('station_key', strtolower($stationKey))->first();
    }

    /**
     * @return array{station: ReceiptStation, created: bool}
     */
    public function register(string $stationKey, string $name, Admin $actor, Request $request): array
    {
        $clave = strtolower($stationKey);

        if ($existente = $this->find($clave)) {
            return ['station' => $existente, 'created' => false];
        }

        try {
            return DB::transaction(function () use ($clave, $name, $actor, $request): array {
                $station = ReceiptStation::create([
                    'station_key' => $clave,
                    'name' => $name,
                    'created_by_admin_id' => $actor->id,
                    'created_by_name' => $actor->name,
                ]);

                $this->audit->record($request, [
                    'action' => 'create',
                    'module' => 'Caja',
                    'entity' => 'estacion_impresion',
                    'entity_id' => $station->station_key,
                    'target_name' => $station->name,
                    'summary' => 'Registró el equipo de impresión «'.$station->name.'»',
                    'metadata' => ['station_key' => $station->station_key],
                ]);

                return ['station' => $station->fresh(), 'created' => true];
            });
        } catch (QueryException $e) {
            // Dos altas del mismo equipo a la vez: el índice único decide, y la
            // segunda recibe la que ganó.
            if ($existente = $this->find($clave)) {
                return ['station' => $existente, 'created' => false];
            }

            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $cambios  solo claves de EDITABLE, ya validadas
     */
    public function update(ReceiptStation $station, array $cambios, Request $request): ReceiptStation
    {
        return DB::transaction(function () use ($station, $cambios, $request): ReceiptStation {
            $fila = ReceiptStation::whereKey($station->id)->lockForUpdate()->firstOrFail();
            $previo = $fila->getOriginal();

            $fila->fill(array_intersect_key($cambios, array_flip(self::EDITABLE)));

            if (! $fila->isDirty()) {
                return $fila;
            }

            $fila->save();

            $this->audit->record($request, [
                'action' => 'update',
                'module' => 'Caja',
                'entity' => 'estacion_impresion',
                'entity_id' => $fila->station_key,
                'target_name' => $fila->name,
                'summary' => 'Cambió el equipo de impresión «'.$fila->name.'»',
                // Ninguno de estos campos es un dato personal: el antes y el
                // después de todos aporta.
                'changes' => $this->audit->changesOf($fila, $previo, self::EDITABLE),
                'metadata' => ['station_key' => $fila->station_key],
            ]);

            return $fila->fresh();
        });
    }

    /**
     * Un ticket de prueba o de configuración para el equipo.
     *
     * @return array{token: string, expires_at: string, purpose: string}
     */
    public function issueTicket(ReceiptStation $station, string $purpose, ?string $variant, Admin $actor, Request $request): array
    {
        return DB::transaction(function () use ($station, $purpose, $variant, $actor, $request): array {
            $configurar = $purpose === ReceiptAgentTicket::PURPOSE_CONFIGURE;

            ['ticket' => $ticket, 'token' => $token] = $this->tickets->issue(
                $station,
                $purpose,
                null,
                $actor,
                $configurar ? null : ($variant ?? ReceiptAgentTicket::DEFAULT_VARIANT),
                // Qué se pretendía entregar al emitirlo. El canje entrega lo que
                // la estación diga EN ESE MOMENTO (es la fuente de verdad).
                $configurar ? ReceiptAgentTickets::settingsOf($station) : null,
            );

            if ($configurar) {
                $this->audit->record($request, [
                    'action' => 'settings',
                    'module' => 'Caja',
                    'entity' => 'estacion_impresion',
                    'entity_id' => $station->station_key,
                    'target_name' => $station->name,
                    'summary' => 'Emitió un ticket de configuración para el equipo «'.$station->name.'»',
                    // NUNCA el token: el identificador público y la caducidad.
                    'metadata' => [
                        'station_key' => $station->station_key,
                        'ticket_id' => $ticket->uuid,
                        'expires_at' => $ticket->expires_at->toIso8601String(),
                        'settings' => $ticket->payload,
                    ],
                ]);
            }

            return [
                'token' => $token,
                'expires_at' => $ticket->expires_at->toIso8601String(),
                'purpose' => $purpose,
            ];
        });
    }

    /** @return array<string, mixed> */
    public function present(ReceiptStation $station): array
    {
        return [
            'station_key' => $station->station_key,
            'name' => $station->name,
            'active' => (bool) $station->active,
            'printer_name' => $station->printer_name,
            'columns' => (int) $station->columns,
            'codepage' => $station->codepage,
            'feed_lines' => (int) $station->feed_lines,
            'agent_version' => $station->agent_version,
            'agent_protocol' => $station->agent_protocol !== null ? (int) $station->agent_protocol : null,
            'last_printer_name' => $station->last_printer_name,
            'last_seen_at' => optional($station->last_seen_at)->toIso8601String(),
            'created_at' => optional($station->created_at)->toIso8601String(),
            'created_by_name' => $station->created_by_name,
        ];
    }

    /**
     * Los últimos tickets del equipo, del más reciente al más antiguo. Sin token
     * (no existe en claro) y sin su huella.
     *
     * @return list<array<string, mixed>>
     */
    public function events(ReceiptStation $station, int $limit): array
    {
        return ReceiptAgentTicket::query()
            ->where('receipt_station_id', $station->id)
            ->with('print.receipt:id,number')
            ->orderByDesc('id')
            ->limit(max(1, min(self::MAX_EVENTS, $limit)))
            ->get()
            ->map(fn (ReceiptAgentTicket $t) => [
                'purpose' => $t->purpose,
                'issued_at' => optional($t->issued_at)->toIso8601String(),
                'issued_by_name' => $t->issued_by_name,
                'redeemed_at' => optional($t->redeemed_at)->toIso8601String(),
                'reported_at' => optional($t->reported_at)->toIso8601String(),
                'outcome' => $t->outcome,
                'error_code' => $t->error_code,
                'error_message' => $t->error_message,
                'receipt_number' => $t->print?->receipt?->number,
                'agent_version' => $t->agent_version,
                'printer_name' => $t->printer_name,
            ])
            ->all();
    }
}
