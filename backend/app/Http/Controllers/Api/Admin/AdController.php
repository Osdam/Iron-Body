<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppAd;
use App\Services\FirebaseStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Gestión de publicidad desde el CRM (Bloque 4). Patrón del CRM (sin auth a
 * nivel de ruta). La imagen puede venir como:
 *   - metadata de Firebase Storage (image_url + image_path), o
 *   - archivo `image` (fallback: se guarda en el disco público).
 */
class AdController extends Controller
{
    public function __construct(private FirebaseStorageService $firebase)
    {
    }

    public function index(): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'data' => AppAd::orderByDesc('priority')->orderByDesc('id')->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateAd($request);
        $data = array_merge($data, $this->resolveImage($request));
        if (empty($data['image_url'])) {
            return response()->json(['ok' => false, 'message' => 'La publicidad requiere una imagen.'], 422);
        }
        $ad = AppAd::create($data);
        return response()->json(['ok' => true, 'data' => $ad], 201);
    }

    public function update(Request $request, AppAd $ad): JsonResponse
    {
        $data = $this->validateAd($request, $ad);
        $image = $this->resolveImage($request);
        if (! empty($image['image_url'])) {
            $data = array_merge($data, $image);
        }
        $ad->update($data);
        return response()->json(['ok' => true, 'data' => $ad]);
    }

    public function destroy(AppAd $ad): JsonResponse
    {
        $this->deleteImage($ad->image_path);
        $ad->delete(); // cascade borra las vistas
        return response()->json(['ok' => true]);
    }

    public function activate(AppAd $ad): JsonResponse
    {
        $ad->update(['is_active' => true]);
        return response()->json(['ok' => true, 'data' => $ad]);
    }

    public function deactivate(AppAd $ad): JsonResponse
    {
        $ad->update(['is_active' => false]);
        return response()->json(['ok' => true, 'data' => $ad]);
    }

    private function validateAd(Request $request, ?AppAd $ad = null): array
    {
        $required = $ad ? 'sometimes' : 'required';
        return $request->validate([
            'title' => [$required, 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'image' => ['nullable', 'image', 'max:8192'],
            'image_url' => ['nullable', 'string', 'max:1000'],
            'image_path' => ['nullable', 'string', 'max:1000'],
            'target_url' => ['nullable', 'string', 'max:1000'],
            'placement' => ['nullable', 'string', 'max:40'],
            'frequency_rule' => ['nullable', 'in:once,daily,always'],
            'priority' => ['nullable', 'integer'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'is_active' => ['nullable', 'boolean'],
            'created_by' => ['nullable', 'string', 'max:255'],
        ]);
    }

    /** Resuelve la imagen: archivo subido (disco público) o metadata Firebase. */
    private function resolveImage(Request $request): array
    {
        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('app_ads', 'public');
            return ['image_url' => Storage::disk('public')->url($path), 'image_path' => $path];
        }
        return array_filter([
            'image_url' => $request->input('image_url'),
            'image_path' => $request->input('image_path'),
        ], fn ($v) => $v !== null);
    }

    private function deleteImage(?string $path): void
    {
        if (! $path) {
            return;
        }
        if (str_starts_with($path, 'app_ads/')) {
            Storage::disk('public')->delete($path); // fallback local
            return;
        }
        if (! str_starts_with($path, 'http')) {
            $this->firebase->deleteObject($path); // best-effort (Firebase)
        }
    }
}
