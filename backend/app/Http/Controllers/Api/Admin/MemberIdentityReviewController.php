<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Member;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Revisión manual de identidad desde el CRM.
 *
 * La app marca a un socio como "Revisión manual" cuando el OCR no da confianza
 * suficiente, y sus capturas SÍ llegaban al servidor —se guardan en el disco
 * privado— pero no existía ninguna forma de mirarlas: el estado prometía una
 * revisión que nadie podía hacer.
 *
 * Estas rutas cierran el circuito. Las imágenes NO se sirven en público: van por
 * un endpoint autenticado como administración y se transmiten desde el disco
 * privado, nunca desde `public/`. Cada consulta queda registrada porque es
 * acceso a documentación de identidad.
 */
class MemberIdentityReviewController extends Controller
{
    /** Estado de la documentación de un socio (sin exponer rutas internas). */
    public function show(Member $member): JsonResponse
    {
        $doc = $member->identityDocument;

        if ($doc === null) {
            return response()->json([
                'ok' => true,
                'data' => ['has_document' => false],
            ]);
        }

        return response()->json([
            'ok' => true,
            'data' => [
                'has_document' => true,
                'identity_status' => $doc->identity_status,
                'document_type' => $doc->document_type,
                'ocr_full_name' => $doc->ocr_full_name,
                'ocr_confidence' => $doc->ocr_confidence,
                'birth_date' => $doc->birth_date,
                'registration_status' => $member->status,
                // Solo se indica si la imagen existe; la ruta interna no sale.
                'front_available' => filled($doc->front_path),
                'back_available' => filled($doc->back_path),
                'updated_at' => $doc->updated_at?->toIso8601String(),
            ],
        ]);
    }

    /** Transmite una cara del documento desde el disco privado. */
    public function image(Member $member, string $side): StreamedResponse|JsonResponse
    {
        if (! in_array($side, ['front', 'back'], true)) {
            return response()->json(['message' => 'Cara no válida.'], 422);
        }

        $doc = $member->identityDocument;
        $path = $side === 'front' ? $doc?->front_path : $doc?->back_path;

        if (blank($path) || ! Storage::disk('local')->exists($path)) {
            return response()->json(['message' => 'Documento no disponible.'], 404);
        }

        // Acceso a documentación de identidad: queda trazado (sin la ruta).
        Log::info('identity.review.image_viewed', [
            'member_id' => $member->id,
            'side' => $side,
        ]);

        return Storage::disk('local')->response(
            $path,
            "identidad-{$member->id}-{$side}",
            ['Content-Type' => ($side === 'front' ? $doc->front_mime : $doc->back_mime) ?: 'application/octet-stream'],
        );
    }
}
