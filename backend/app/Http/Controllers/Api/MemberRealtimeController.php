<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CatalogEvent;
use App\Models\Member;
use App\Models\MemberRealtimeEvent;
use App\Services\RealtimeEvents;
use App\Support\SseStream;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Canal real-time PRIVADO del miembro (SSE). Entrega las señales de cambio que
 * el backend emite ({@see RealtimeEvents}) para que la app
 * actualice AppState/módulos al instante, sin polling como mecanismo principal.
 *
 * Seguridad de canal (Bloque 3): la ruta va bajo `auth_member` (el token valida
 * la conexión) y la consulta está ACOTADA a `auth_member->id`, de modo que un
 * miembro JAMÁS recibe eventos de otro. No viajan tokens/OTP/secretos: solo el
 * tipo de cambio y los módulos afectados. La conexión es acotada y el cliente
 * reconecta solo (sin tener un worker tomado indefinidamente).
 */
class MemberRealtimeController extends Controller
{
    public function stream(Request $request): SymfonyResponse
    {
        /** @var Member|null $member */
        $member = $request->attributes->get('auth_member');
        if (! $member) {
            return response()->json(['ok' => false, 'message' => 'Sesión requerida.'], 401);
        }

        $sinCursor = ! $request->filled('after_id');
        $sinCursorCatalogo = ! $request->filled('after_catalog_id');

        // Solo lo NUEVO tras conectar (las señales son efímeras, no histórico).
        $cursor = $request->filled('after_id')
            ? (int) $request->query('after_id')
            : (int) (MemberRealtimeEvent::where('member_id', $member->id)->max('id') ?? 0);

        $memberId = (int) $member->id;

        // Canal GLOBAL de catálogo, multiplexado en esta misma conexión. Tiene
        // su propio cursor porque son dos secuencias de id independientes:
        // mezclarlas en `Last-Event-ID` haría que un evento personal tapara uno
        // de catálogo, o al revés. Y va aquí, y no en una segunda conexión SSE,
        // porque el teléfono ya mantiene ésta abierta.
        $catalogCursor = self::initialCatalogCursor($request);

        return SseStream::response(function () use ($memberId, &$cursor, &$catalogCursor): void {
            $items = MemberRealtimeEvent::query()
                ->where('member_id', $memberId)
                ->where('id', '>', $cursor)
                ->orderBy('id')
                ->limit(50)
                ->get();

            foreach ($items as $e) {
                SseStream::emit('app', [
                    'type' => $e->type,
                    'member_id' => $memberId,
                    'version' => (string) $e->version,
                    'changed' => $e->changed ?? [],
                    'timestamp' => $e->created_at?->toIso8601String(),
                ], $e->id);
                $cursor = (int) $e->id;
            }

            // Catálogo: mismo patrón, pero sin destinatario. El id lleva prefijo
            // `c` para que el cliente sepa a qué cursor pertenece.
            $catalog = CatalogEvent::query()
                ->where('id', '>', $catalogCursor)
                ->orderBy('id')
                ->limit(50)
                ->get();

            foreach (self::catalogToEmit($catalog) as $e) {
                SseStream::emit('catalog', self::catalogPayload($e), 'c'.$e->id);
            }
            if ($catalog->isNotEmpty()) {
                $catalogCursor = (int) $catalog->last()->id;
            }
        }, 25, 1500, function () use ($sinCursor, $sinCursorCatalogo, &$cursor, &$catalogCursor): void {
            // Un cliente sin cursor aprende el suyo ANTES de que llegue ningún
            // evento. Sin esto, al reconectar volvía a pedir «desde ahora» y lo
            // ocurrido en el hueco de reconexión (~2 s de cada ~27) se perdía.
            // La app ya lee las líneas `id:` por separado de los datos.
            if ($sinCursor) {
                echo "id: {$cursor}\n\n";
            }
            if ($sinCursorCatalogo) {
                echo "id: c{$catalogCursor}\n\n";
            }
        }); // tick 1.5s durante ~25s; el cliente reconecta solo.
    }

    /**
     * Desde dónde se lee el canal global en esta conexión.
     *
     * La app 1.0.x solo manda `after_id`: no sabe reanudar el canal global, y
     * con «desde ahora» perdía las señales de clases del hueco de cada
     * reconexión (~2 s de cada ~27). A ese cliente se le repiten las de los
     * últimos segundos; volver a recibir una señal solo cuesta una recarga.
     */
    public static function initialCatalogCursor(Request $request): int
    {
        if ($request->filled('after_catalog_id')) {
            return (int) $request->query('after_catalog_id');
        }
        if ($request->filled('after_id')) {
            return (int) (CatalogEvent::where('created_at', '<', now()->subSeconds(10))->max('id') ?? 0);
        }

        return (int) (CatalogEvent::max('id') ?? 0);
    }

    /**
     * Lo que se emite de una lectura del canal global: todos los avisos de
     * producto y, de los de clases, solo el ÚLTIMO. La app vuelve a pedir sus
     * clases con cada aviso de clases y uno basta: al volver de segundo plano
     * con un cursor viejo recibía uno por cada reserva de la pausa.
     *
     * @param  iterable<CatalogEvent>  $filas  en orden de id
     * @return list<CatalogEvent>
     */
    public static function catalogToEmit(iterable $filas): array
    {
        $deClases = fn (CatalogEvent $e): bool => str_starts_with((string) $e->type, 'class.')
            || str_starts_with((string) $e->type, 'reservation.');

        $ultimaDeClases = null;
        foreach ($filas as $e) {
            if ($deClases($e)) {
                $ultimaDeClases = $e;
            }
        }

        $salida = [];
        foreach ($filas as $e) {
            if (! $deClases($e) || $e === $ultimaDeClases) {
                $salida[] = $e;
            }
        }

        return $salida;
    }

    /**
     * Payload de un evento del canal global. `class_id` y `session_date` dicen
     * de qué clase y ocurrencia es un aviso de clases; la app actual los ignora
     * y enruta por `type` (`class.*` y `reservation.*` refrescan Clases).
     *
     * @return array<string, mixed>
     */
    public static function catalogPayload(CatalogEvent $e): array
    {
        return [
            'type' => $e->type,
            'product_id' => $e->product_id,
            'class_id' => $e->class_id,
            'session_date' => $e->session_date?->toDateString(),
            'changed' => $e->changed ?? [],
            'version' => (string) $e->version,
            'event_id' => (int) $e->id,
            'timestamp' => $e->created_at?->toIso8601String(),
        ];
    }
}
