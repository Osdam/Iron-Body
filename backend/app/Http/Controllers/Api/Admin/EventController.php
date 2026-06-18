<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppEvent;
use App\Services\FirebaseStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Gestión de eventos desde el CRM (Bloque 4). Mismo patrón de imagen que la
 * publicidad: metadata de Firebase (image_url + image_path) o archivo `image`
 * (fallback a disco público).
 */
class EventController extends Controller
{
    public function __construct(private FirebaseStorageService $firebase)
    {
    }

    public function index(): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'data' => AppEvent::orderByDesc('id')->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateEvent($request);
        $data = array_merge($data, $this->resolveImage($request));
        $event = AppEvent::create($data);
        // Recién publicado y activo → avisa a los miembros (notificación + SSE).
        if ($event->is_active) {
            app(\App\Services\NotificationService::class)->notifyEventPublished($event);
        }
        return response()->json(['ok' => true, 'data' => $event], 201);
    }

    public function update(Request $request, AppEvent $event): JsonResponse
    {
        $data = $this->validateEvent($request, $event);
        $image = $this->resolveImage($request);
        if (! empty($image['image_url'])) {
            $data = array_merge($data, $image);
        }
        $event->update($data);
        return response()->json(['ok' => true, 'data' => $event]);
    }

    public function destroy(AppEvent $event): JsonResponse
    {
        $this->deleteImage($event->image_path);
        $event->delete();
        return response()->json(['ok' => true]);
    }

    public function activate(AppEvent $event): JsonResponse
    {
        $event->update(['is_active' => true]);
        // Al publicar manualmente, avisa a los miembros (notificación + SSE).
        app(\App\Services\NotificationService::class)->notifyEventPublished($event->fresh());
        return response()->json(['ok' => true, 'data' => $event]);
    }

    public function deactivate(AppEvent $event): JsonResponse
    {
        $event->update(['is_active' => false]);
        return response()->json(['ok' => true, 'data' => $event]);
    }

    private function validateEvent(Request $request, ?AppEvent $event = null): array
    {
        $required = $event ? 'sometimes' : 'required';
        return $request->validate([
            'title' => [$required, 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'image' => ['nullable', 'image', 'max:8192'],
            'image_url' => ['nullable', 'string', 'max:1000'],
            'image_path' => ['nullable', 'string', 'max:1000'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'location' => ['nullable', 'string', 'max:255'],
            'cta_label' => ['nullable', 'string', 'max:80'],
            'cta_url' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
            'created_by' => ['nullable', 'string', 'max:255'],
        ]);
    }

    private function resolveImage(Request $request): array
    {
        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('app_events', 'public');
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
        if (str_starts_with($path, 'app_events/')) {
            Storage::disk('public')->delete($path);
            return;
        }
        if (! str_starts_with($path, 'http')) {
            $this->firebase->deleteObject($path);
        }
    }
}
