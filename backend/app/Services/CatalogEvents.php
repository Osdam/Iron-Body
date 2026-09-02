<?php

namespace App\Services;

use App\Models\CatalogEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Bus GLOBAL de cambios de catálogo. Espejo de {@see RealtimeEvents}, pero sin
 * destinatario: lo que cambia en el catálogo lo ven todos.
 *
 * Dos garantías que importan:
 *
 * 1. SE EMITE DESPUÉS DEL COMMIT. Si la transacción se deshace, el aviso no
 *    sale. Avisar de un stock que luego revierte es peor que no avisar: el
 *    cliente pediría el estado canónico y vería lo de antes, sin saber por qué.
 *
 * 2. ES BEST-EFFORT. Nunca lanza ni bloquea la operación de negocio. Que falle
 *    el aviso no puede impedir una venta.
 */
class CatalogEvents
{
    /** Un producto concreto cambió: el cliente invalida ESE producto. */
    public const PRODUCT_CHANGED = 'catalog.product.changed';

    /** Cambio masivo (importación, alta en lote): el cliente recarga todo. */
    public const INVALIDATE = 'catalog.invalidate';

    /**
     * Avisa de que un producto cambió.
     *
     * [$changed] nombra QUÉ cambió (`stock`, `price`, `visibility`, `image`,
     * `archived`…) para que el cliente pueda decidir si le afecta. Nunca lleva
     * el valor nuevo.
     */
    public static function productChanged(?int $productId, array $changed = []): void
    {
        if ($productId === null || $productId <= 0) {
            return;
        }
        self::record(self::PRODUCT_CHANGED, $productId, $changed);
    }

    /** Avisa de un cambio masivo: el catálogo entero deja de ser fiable. */
    public static function invalidate(string $reason = 'bulk'): void
    {
        self::record(self::INVALIDATE, null, ['reason' => $reason]);
    }

    private static function record(string $type, ?int $productId, array $changed): void
    {
        // Fuera de transacción se ejecuta al instante; dentro, sólo si commitea.
        DB::afterCommit(function () use ($type, $productId, $changed): void {
            try {
                CatalogEvent::create([
                    'type' => $type,
                    'product_id' => $productId,
                    'changed' => $changed,
                    'version' => (int) (microtime(true) * 1000),
                    'created_at' => now(),
                ]);
            } catch (\Throwable $e) {
                // Que no se pueda avisar no puede tumbar una venta.
                Log::warning('catalog:event:failed', [
                    'type' => $type,
                    'product_id' => $productId,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }
}
