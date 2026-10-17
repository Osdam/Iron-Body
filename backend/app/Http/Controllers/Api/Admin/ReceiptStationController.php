<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\ReceiptAgentTicket;
use App\Models\ReceiptStation;
use App\Rules\PlainText;
use App\Services\Caja\ReceiptAgentTickets;
use App\Services\Caja\ReceiptStations;
use App\Support\Access\AdminActor;
use App\Support\Access\CrmPermission;
use App\Support\Caja\UntrustedText;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Equipos de caja con el servicio local de impresión (contrato §5.3).
 *
 * NO TOCA DINERO NI RECIBOS: registra equipos, sus ajustes y los tickets de
 * prueba y de configuración. Una página de prueba no lleva datos de nadie.
 *
 * AUTORIZACIÓN EN DOS PUERTAS, como los recibos:
 *
 *  · el mapa central deja pasar a quien tenga algún permiso de caja
 *    (AuthorizationMap::RECEIPT_CASH_PERMISSIONS);
 *  · aquí se exige una PERSONA (el token compartido llega con `cash.*.view`
 *    por ser lectura, y aquí se le cierra) y, para ESCRIBIR —registrar,
 *    cambiar, emitir tickets— y para ver los EVENTOS de un equipo, supervisar
 *    alguna caja: `cash.gym.manage` o `cash.products.manage`. Recepción
 *    imprime; no decide qué equipo imprime ni lee el rastro de las dos cajas
 *    (los eventos mezclan recibos de ambas: revisión de seguridad M-5).
 *    Ningún permiso nuevo.
 */
class ReceiptStationController extends Controller
{
    private const MANAGE_PERMISSIONS = [CrmPermission::CASH_GYM_MANAGE, CrmPermission::CASH_PRODUCTS_MANAGE];

    public function __construct(private readonly ReceiptStations $stations) {}

    /** GET /api/admin/receipt-stations */
    public function index(Request $request): JsonResponse
    {
        if (! AdminActor::from($request)) {
            return $this->sinPersona();
        }

        return response()->json([
            'ok' => true,
            'data' => ReceiptStation::query()->orderBy('name')->orderBy('id')->get()
                ->map(fn (ReceiptStation $s) => $this->stations->present($s))
                ->all(),
            'agent' => [
                'enabled' => ReceiptAgentTickets::enabled(),
                'min_protocol' => ReceiptAgentTickets::minProtocol(),
                'protocol' => ReceiptAgentTickets::PROTOCOL,
            ],
        ]);
    }

    /** POST /api/admin/receipt-stations {station_key, name} */
    public function store(Request $request): JsonResponse
    {
        if (! $actor = AdminActor::from($request)) {
            return $this->sinPersona();
        }
        if (! $this->canManage($actor)) {
            return $this->forbidden();
        }

        $this->requireUtf8($request);
        $data = $request->validate([
            'station_key' => ['required', 'uuid'],
            'name' => ['required', 'string', 'min:3', 'max:60', new PlainText],
        ]);

        ['station' => $station, 'created' => $creada] = $this->stations->register($data['station_key'], $data['name'], $actor, $request);

        if (! $creada) {
            return response()->json([
                'ok' => false,
                'code' => 'station_exists',
                'message' => 'Este equipo ya estaba registrado.',
                'data' => $this->stations->present($station),
            ], 409);
        }

        return response()->json(['ok' => true, 'data' => $this->stations->present($station)], 201);
    }

    /** PATCH /api/admin/receipt-stations/{station_key} */
    public function update(Request $request, string $station): JsonResponse
    {
        if (! $actor = AdminActor::from($request)) {
            return $this->sinPersona();
        }
        if (! $this->canManage($actor)) {
            return $this->forbidden();
        }

        $this->requireUtf8($request);
        $fila = $this->stationFor($station);
        if ($fila === null) {
            return $this->notRegistered();
        }

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'min:3', 'max:60', new PlainText],
            'active' => ['sometimes', 'boolean'],
            'printer_name' => ['sometimes', 'nullable', 'string', 'max:120', new PlainText, $this->localPrinterOnly()],
            'columns' => ['sometimes', 'integer', Rule::in(ReceiptStation::COLUMNS)],
            'codepage' => ['sometimes', 'string', Rule::in(ReceiptStation::CODEPAGES)],
            'feed_lines' => ['sometimes', 'integer', 'min:0', 'max:'.ReceiptStation::MAX_FEED_LINES],
        ]);

        $fila = $this->stations->update($fila, $data, $request);

        return response()->json(['ok' => true, 'data' => $this->stations->present($fila)]);
    }

    /**
     * POST /api/admin/receipt-stations/{station_key}/tickets
     * {purpose: test|configure, variant?: minimal|full|sample_1|sample_5|sample_20|sample_membership}
     * (por defecto minimal; los `sample_*` son recibos sintéticos, ver §5.4)
     *
     * El token sale UNA vez, en esta respuesta. No se guarda en claro.
     */
    public function issueTicket(Request $request, string $station): JsonResponse
    {
        if (! $actor = AdminActor::from($request)) {
            return $this->sinPersona();
        }
        if (! $this->canManage($actor)) {
            return $this->forbidden();
        }

        $this->requireUtf8($request);
        $fila = $this->stationFor($station);
        if ($fila === null) {
            return $this->notRegistered();
        }

        $data = $request->validate([
            'purpose' => ['required', Rule::in([ReceiptAgentTicket::PURPOSE_TEST, ReceiptAgentTicket::PURPOSE_CONFIGURE])],
            'variant' => ['nullable', Rule::in(ReceiptAgentTicket::VARIANTS)],
        ]);

        if (! ReceiptAgentTickets::enabled()) {
            return response()->json([
                'ok' => false,
                'code' => 'agent_disabled',
                'message' => 'La impresión con el servicio local está desactivada.',
            ], 503);
        }
        if (! $fila->active) {
            return response()->json([
                'ok' => false,
                'code' => 'station_inactive',
                'message' => 'Este equipo está desactivado: actívalo antes de probarlo o configurarlo.',
            ], 403);
        }

        $ticket = $this->stations->issueTicket($fila, $data['purpose'], $data['variant'] ?? null, $actor, $request);

        return response()
            ->json(['ok' => true, 'data' => $ticket], 201)
            // El token es una credencial de un solo uso: que ningún intermedio
            // lo guarde.
            ->header('Cache-Control', 'no-store');
    }

    /**
     * GET /api/admin/receipt-stations/{station_key}/events?limit=30
     *
     * Exige supervisar alguna caja, como las escrituras: el rastro de un equipo
     * mezcla recibos de las dos cajas y dice quién imprimió qué (M-5).
     */
    public function events(Request $request, string $station): JsonResponse
    {
        if (! $actor = AdminActor::from($request)) {
            return $this->sinPersona();
        }
        if (! $this->canManage($actor)) {
            return $this->forbidden();
        }

        $this->requireUtf8($request);
        $fila = $this->stationFor($station);
        if ($fila === null) {
            return $this->notRegistered();
        }

        $data = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json([
            'ok' => true,
            'data' => $this->stations->events($fila, (int) ($data['limit'] ?? 30)),
        ]);
    }

    // ── Comunes ─────────────────────────────────────────────────────────────

    private function stationFor(string $stationKey): ?ReceiptStation
    {
        // Antes de consultar: en PostgreSQL la columna es `uuid` y un texto que
        // no lo sea revienta la consulta con un 500 en vez de dar un 404.
        return Str::isUuid($stationKey) ? $this->stations->find($stationKey) : null;
    }

    /**
     * Toda cadena de la petición —claves incluidas— en UTF-8 válido, antes de
     * validar campo a campo y antes de tocar la base (M-1). Un cuerpo
     * form-urlencoded deja pasar bytes que no lo son.
     */
    private function requireUtf8(Request $request): void
    {
        if (! UntrustedText::allUtf8($request->all())) {
            throw ValidationException::withMessages([
                'input' => ['El texto enviado no es UTF-8 válido.'],
            ]);
        }
    }

    /**
     * La impresora tiene que estar conectada a ESTE equipo (M-4, parte
     * servidor). Una ruta de red (\\host\cola, //host/cola) o una URL
     * (http://…/printers/…) haría que el servicio mandara cada recibo —y en
     * algunos montajes la credencial del equipo— a otra máquina.
     */
    private function localPrinterOnly(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && (str_starts_with($value, '\\\\') || str_starts_with($value, '//') || str_contains($value, '://'))) {
                $fail('Elige una impresora conectada a este equipo, no una impresora de red.');
            }
        };
    }

    private function canManage(Admin $actor): bool
    {
        foreach (self::MANAGE_PERMISSIONS as $permiso) {
            if (CrmPermission::allows($actor, $permiso)) {
                return true;
            }
        }

        return false;
    }

    private function sinPersona(): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'code' => 'forbidden',
            'message' => 'Los equipos de impresión exigen una sesión de administrador.',
        ], 403);
    }

    private function forbidden(): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'code' => 'forbidden',
            'message' => 'Solo quien supervisa una caja puede registrar o configurar equipos de impresión.',
            'required_permission' => implode(' | ', self::MANAGE_PERMISSIONS),
        ], 403);
    }

    private function notRegistered(): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'code' => 'station_not_registered',
            'message' => 'Ese equipo no está registrado.',
        ], 404);
    }
}
