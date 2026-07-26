<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Services\Admin\IronCrm\IronCrmAiService;
use App\Services\Admin\IronCrm\IronCrmAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * IRON — copiloto administrativo del CRM. Endpoints bajo /api/admin/iron-ai/*.
 *
 * Protección: grupo `auth.admin` (sesión admin real del CRM o secreto n8n) +
 * blindaje global ProtectAdminPaths (por estar bajo /api/admin). SOLO LECTURA.
 *
 *  POST   /api/admin/iron-ai/chat     → responde un mensaje (con contexto real).
 *  POST   /api/admin/iron-ai/upload   → valida una imagen y devuelve su data URL.
 *  GET    /api/admin/iron-ai/history  → historial (local en el frontend; MVP).
 *  DELETE /api/admin/iron-ai/history  → limpiar historial (local en el frontend).
 */
class IronCrmAiController extends Controller
{
    public function __construct(
        private readonly IronCrmAiService $ai,
        private readonly IronCrmAuditService $audit,
    ) {}

    public function chat(Request $request): JsonResponse
    {
        $maxChars = (int) config('iron_crm.max_message_chars', 4000);

        $data = $request->validate([
            'message' => ['required', 'string', 'min:1', "max:{$maxChars}"],
            'history' => ['sometimes', 'array', 'max:50'],
            'history.*.role' => ['required_with:history', 'string', 'in:user,assistant'],
            'history.*.content' => ['required_with:history', 'string', "max:{$maxChars}"],
            'image' => ['sometimes', 'nullable', 'string'],
        ]);

        $admin = $this->resolveAdmin($request);
        $message = trim($data['message']);
        $history = $this->sanitizeHistory($data['history'] ?? []);

        $image = null;
        $imageError = null;
        if (! empty($data['image'])) {
            [$image, $imageError] = $this->validateImageDataUrl($data['image']);
            if ($imageError !== null) {
                return response()->json(['ok' => false, 'message' => $imageError], 422);
            }
        }

        $result = $this->ai->chat($message, $history, $admin, $image);

        $this->audit->logChat($request, $admin, [
            'message_length' => Str::length($message),
            'had_image' => $image !== null,
            'tools_used' => $result['tools_used'] ?? [],
            'error' => ! ($result['ok'] ?? false),
            'model' => $result['model'] ?? null,
        ]);

        return response()->json([
            'ok' => (bool) ($result['ok'] ?? false),
            'reply' => $result['reply'] ?? IronCrmAiService::FRIENDLY_ERROR,
        ], ($result['ok'] ?? false) ? 200 : 200); // Nunca 500 al frontend: mensaje amable.
    }

    /**
     * Valida una imagen adjunta (multipart) y la devuelve como data URL para que
     * el frontend la reenvíe con el próximo `chat`. NO se persiste en disco.
     */
    public function upload(Request $request): JsonResponse
    {
        if (empty(config('iron_crm.image.enabled'))) {
            return response()->json(['ok' => false, 'message' => 'La carga de imágenes está deshabilitada.'], 422);
        }

        $maxMb = (int) config('iron_crm.image.max_size_mb', 5);
        $request->validate([
            'file' => ['required', 'file', 'image', 'mimes:jpeg,jpg,png,webp', 'max:'.($maxMb * 1024)],
        ]);

        $file = $request->file('file');
        $base64 = base64_encode(file_get_contents($file->getRealPath()));
        $dataUrl = 'data:'.$file->getMimeType().';base64,'.$base64;

        return response()->json([
            'ok' => true,
            'data_url' => $dataUrl,
            'name' => $file->getClientOriginalName(),
            'size' => $file->getSize(),
        ]);
    }

    /**
     * Historial: en esta fase se mantiene LOCAL en el frontend (localStorage)
     * para no crear tablas ni persistir PII de las conversaciones. El endpoint
     * existe para dejar el contrato listo; devuelve vacío de forma explícita.
     */
    public function history(Request $request): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'storage' => 'local',
            'data' => [],
            'note' => 'El historial se mantiene en el navegador (MVP). Persistencia en backend: fase 2.',
        ]);
    }

    public function clearHistory(Request $request): JsonResponse
    {
        return response()->json(['ok' => true, 'storage' => 'local']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function resolveAdmin(Request $request): ?Admin
    {
        $admin = $request->attributes->get('auth_admin');

        return $admin instanceof Admin ? $admin : null;
    }

    /**
     * @param  array<int, mixed>  $history
     * @return array<int, array{role: string, content: string}>
     */
    private function sanitizeHistory(array $history): array
    {
        $out = [];
        foreach ($history as $item) {
            if (! is_array($item)) {
                continue;
            }
            $role = ($item['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
            $content = trim((string) ($item['content'] ?? ''));
            if ($content !== '') {
                $out[] = ['role' => $role, 'content' => $content];
            }
        }

        return $out;
    }

    /**
     * Valida un data URL de imagen (tipo permitido + tamaño). Devuelve
     * [dataUrl, null] si es válido, o [null, mensajeError].
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function validateImageDataUrl(string $dataUrl): array
    {
        if (empty(config('iron_crm.image.enabled'))) {
            return [null, 'La carga de imágenes está deshabilitada.'];
        }

        if (! preg_match('#^data:(image/[a-zA-Z0-9.+-]+);base64,(.+)$#s', $dataUrl, $m)) {
            return [null, 'Formato de imagen no válido.'];
        }

        $mime = strtolower($m[1]);
        $allowed = (array) config('iron_crm.image.mimes', ['image/jpeg', 'image/png', 'image/webp']);
        if (! in_array($mime, $allowed, true)) {
            return [null, 'Tipo de imagen no permitido. Usa JPG, PNG o WEBP.'];
        }

        $binary = base64_decode($m[2], true);
        if ($binary === false) {
            return [null, 'La imagen no se pudo decodificar.'];
        }

        $maxBytes = ((int) config('iron_crm.image.max_size_mb', 5)) * 1024 * 1024;
        if (strlen($binary) > $maxBytes) {
            return [null, 'La imagen supera el tamaño máximo permitido.'];
        }

        return [$dataUrl, null];
    }
}
