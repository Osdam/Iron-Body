<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\LiveStream;
use Illuminate\Http\JsonResponse;

/**
 * Transmisiones en vivo desde el CRM (Bloque 5). Patrón del CRM (sin auth a
 * nivel de ruta). Permite ver el historial y finalizar un live.
 */
class LiveController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'data' => LiveStream::with('host')->latest('id')->get(),
        ]);
    }

    public function end(LiveStream $live): JsonResponse
    {
        if ($live->status !== LiveStream::STATUS_ENDED) {
            $live->update([
                'status' => LiveStream::STATUS_ENDED,
                'ended_at' => now(),
            ]);
        }
        return response()->json(['ok' => true, 'data' => $live->toAppArray()]);
    }
}
