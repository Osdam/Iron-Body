<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Story;
use App\Models\StoryReaction;
use App\Models\StoryView;
use App\Models\User;
use App\Services\FirebaseStorageService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Stories tipo Instagram/WhatsApp.
 *
 * Endpoints (autenticados como member):
 *  POST   /api/app/stories          → subir story (multipart)
 *  GET    /api/app/stories          → feed activo (no expirado)
 *  POST   /api/app/stories/{id}/view → registrar vista (idempotente)
 *  DELETE /api/app/stories/{id}     → borrar (solo si owner)
 *
 * Endpoints CRM admin (sin auth.member — patrón del resto del CRM):
 *  POST   /api/admin/stories          → subir como user (admin)
 *  DELETE /api/admin/stories/{id}    → borrar cualquier story
 */
class StoriesController extends Controller
{
    /**
     * Lifetime por defecto del story (24h, igual que Instagram/WhatsApp).
     */
    private const DEFAULT_LIFETIME_HOURS = 24;

    /** Límite de tamaño de upload (en bytes). 30 MB. */
    private const MAX_SIZE = 30 * 1024 * 1024;

    // ── Endpoints autenticados (member) ─────────────────────────────────

    /** POST /api/app/stories */
    public function storeAsMember(Request $request): JsonResponse
    {
        $member = $request->attributes->get('auth_member');

        $data = $request->validate([
            'file' => 'required|file|max:30720|mimes:jpg,jpeg,png,webp,mp4,mov',
            'caption' => 'nullable|string|max:280',
            'duration_ms' => 'nullable|integer|min:100|max:60000',
        ]);

        // Prefiere nombre completo del miembro; nunca exponemos el
        // documento como "nombre visible" (PII, además queda feo en UI).
        // Estructura REAL de `members` en PostgreSQL: el nombre es `full_name`
        // (no existen columnas `name`/`last_name`). Usar las inexistentes hacía
        // que author_name quedara vacío y siempre cayera al fallback.
        $authorName = trim((string) ($member->full_name ?? ''));
        if ($authorName === '') {
            $authorName = 'Miembro Iron Body';
        }

        $story = $this->createStory(
            file: $data['file'],
            authorType: 'member',
            authorId: $member->id,
            authorName: $authorName,
            authorAvatar: null, // members no tiene columna de avatar en Postgres
            caption: $data['caption'] ?? null,
            durationMs: $data['duration_ms'] ?? null,
        );

        // SSE + FCM: dispara el evento. NotificationService gestiona la
        // deduplicación por event_key y la entrega de push.
        app(NotificationService::class)->notifyStoryCreated($story);

        return response()->json([
            'ok' => true,
            'data' => $this->serializeStory($story, $member->id, 'member'),
        ], 201);
    }

    /**
     * POST /api/app/stories/firebase
     *
     * Crea un story cuyo media YA fue subido por la app a Firebase Storage
     * (mismo patrón que los reels). La app sube el archivo directamente al
     * bucket usando su sesión Firebase Auth (Auth Bridge) y luego nos manda
     * SOLO la metadata. Ventajas: no pasa el binario por el backend (rápido,
     * sin límite de PHP/Nginx) y el archivo queda en el mismo storage que el
     * resto del contenido multimedia.
     *
     * Seguridad: el author_id sale del `auth_member` (bearer válido), NUNCA
     * del cliente. La ruta del objeto (`firebase_path`) se guarda para poder
     * BORRAR físicamente el objeto luego (vía service account).
     */
    public function storeAsMemberFirebase(Request $request): JsonResponse
    {
        $member = $request->attributes->get('auth_member');

        $data = $request->validate([
            'type' => 'nullable|string|in:image,video',
            // media_type es alias de type (la app envía ambos por claridad).
            'media_type' => 'nullable|string|in:image,video',
            // Ruta del objeto en el bucket (gs://… o ruta relativa).
            // `storage_gs_url` es alias de `firebase_path`; al menos uno requerido.
            'firebase_path' => 'required_without:storage_gs_url|string|max:1000',
            'storage_gs_url' => 'required_without:firebase_path|string|max:1000',
            // URL https tokenizada que devolvió Firebase (para mostrar/compartir).
            'download_url' => 'required|url|max:1000',
            // Content-type real del objeto subido (image/jpeg, video/mp4…).
            'content_type' => 'nullable|string|max:100',
            'caption' => 'nullable|string|max:280',
            'duration_ms' => 'nullable|integer|min:100|max:60000',
            'size_bytes' => 'nullable|integer|min:0',
        ]);

        // Normaliza alias: prioriza los nombres nuevos, cae a los previos.
        $type = $data['type'] ?? $data['media_type'] ?? 'image';
        $gsPath = $data['storage_gs_url'] ?? $data['firebase_path'];

        // Metadata-only: el binario ya lo subió la app a Firebase Storage.
        // El backend NO sube archivo, NO usa Storage::disk('firebase'), NO valida
        // archivo local — solo persiste la metadata en PostgreSQL.
        Log::info('Story publish: metadata-start', [
            'member_id' => $member->id,
            'type' => $type,
            'content_type' => $data['content_type'] ?? null,
            'size_bytes' => $data['size_bytes'] ?? null,
        ]);

        // Estructura REAL de `members` en PostgreSQL: el nombre es `full_name`
        // (no existen columnas `name`/`last_name`). Usar las inexistentes hacía
        // que author_name quedara vacío y siempre cayera al fallback.
        $authorName = trim((string) ($member->full_name ?? ''));
        if ($authorName === '') {
            $authorName = 'Miembro Iron Body';
        }

        $story = Story::create([
            'author_type' => 'member',
            'author_id' => $member->id,
            'author_name' => $authorName,
            'author_avatar' => null, // members no tiene columna de avatar en Postgres
            'type' => $type,
            'file_path' => $gsPath,
            'download_url' => $data['download_url'],
            'disk' => 'firebase',
            'duration_ms' => $data['duration_ms'] ?? null,
            'caption' => $data['caption'] ?? null,
            'size_bytes' => $data['size_bytes'] ?? null,
            'expires_at' => now()->addHours(self::DEFAULT_LIFETIME_HOURS),
        ]);

        Log::info('Story publish: metadata-ok', [
            'story_id' => $story->id,
            'member_id' => $member->id,
        ]);

        app(NotificationService::class)->notifyStoryCreated($story);

        return response()->json([
            'ok' => true,
            'data' => $this->serializeStory($story, $member->id, 'member'),
        ], 201);
    }

    /** GET /api/app/stories */
    public function indexAsMember(Request $request): JsonResponse
    {
        $member = $request->attributes->get('auth_member');

        $stories = Story::active()->forFeed()->get();
        $viewed = StoryView::where('viewer_type', 'member')
            ->where('viewer_id', $member->id)
            ->whereIn('story_id', $stories->pluck('id'))
            ->pluck('story_id')
            ->all();
        $viewedSet = array_flip($viewed);

        $serialized = $stories->map(
            fn (Story $s) => $this->serializeStory($s, $member->id, 'member',
                viewed: isset($viewedSet[$s->id]))
        );

        // Agrupado por autor (estilo Instagram: cada autor = un anillo).
        $grouped = $serialized->groupBy('author_key')->map(function ($group) {
            $first = $group->first();
            return [
                'author_type' => $first['author_type'],
                'author_id' => $first['author_id'],
                'author_name' => $first['author_name'],
                'author_avatar' => $first['author_avatar'],
                'all_viewed' => $group->every(fn ($s) => $s['viewed']),
                // Todas las stories de un grupo comparten autor → el flag es
                // uniforme. El viewer lo usa para mostrar "Eliminar".
                'is_owner' => $first['is_owner'],
                'stories' => $group->values(),
            ];
        })->values();

        return response()->json(['ok' => true, 'data' => $grouped]);
    }

    /** POST /api/app/stories/{id}/view */
    public function recordView(Request $request, int $id): JsonResponse
    {
        $member = $request->attributes->get('auth_member');

        $story = Story::active()->findOrFail($id);

        // updateOrCreate gracias al unique index — idempotente.
        StoryView::firstOrCreate(
            [
                'story_id' => $story->id,
                'viewer_type' => 'member',
                'viewer_id' => $member->id,
            ],
            ['viewed_at' => now()]
        );

        return response()->json(['ok' => true]);
    }

    /**
     * GET /api/app/stories/{id}/viewers — quién vio este story.
     *
     * Solo accesible para el **owner** del story (member que lo creó).
     * Responde con la lista de viewers (member name + viewed_at),
     * ordenada del más reciente al más antiguo.
     */
    public function listViewers(Request $request, int $id): JsonResponse
    {
        $member = $request->attributes->get('auth_member');
        $story = Story::findOrFail($id);

        // Solo el dueño puede ver sus stats.
        if ($story->author_type !== 'member' || $story->author_id !== $member->id) {
            return response()->json(['ok' => false, 'error' => 'forbidden'], 403);
        }

        // JOIN con members para incluir nombre. Si el viewer es admin
        // (viewer_type='user'), incluimos también con su nombre.
        $views = $story->views()->orderBy('viewed_at', 'desc')->limit(500)->get();

        $memberIds = $views->where('viewer_type', 'member')->pluck('viewer_id')->unique();
        $userIds = $views->where('viewer_type', 'user')->pluck('viewer_id')->unique();

        $members = \App\Models\Member::whereIn('id', $memberIds)
            ->get(['id', 'full_name'])
            ->keyBy('id');
        $users = User::whereIn('id', $userIds)
            ->get(['id', 'name'])
            ->keyBy('id');

        $serialized = $views->map(function ($v) use ($members, $users) {
            if ($v->viewer_type === 'member') {
                $m = $members[$v->viewer_id] ?? null;
                $name = $m
                    ? (trim((string) ($m->full_name ?? '')) ?: 'Miembro Iron Body')
                    : 'Miembro Iron Body';
                $avatar = null; // members sin columna de avatar
            } else {
                $u = $users[$v->viewer_id] ?? null;
                $name = $u?->name ?? 'Admin Iron Body';
                $avatar = null;
            }
            return [
                'viewer_type' => $v->viewer_type,
                'viewer_id' => $v->viewer_id,
                'name' => $name,
                'avatar' => $avatar,
                'viewed_at' => $v->viewed_at?->toIso8601String(),
            ];
        });

        return response()->json([
            'ok' => true,
            'data' => [
                'count' => $serialized->count(),
                'viewers' => $serialized->values(),
            ],
        ]);
    }

    /**
     * POST /api/app/stories/{id}/react
     * Body: { "type": "heart" }
     *
     * Idempotente: el unique index (story_id, viewer_type, viewer_id)
     * garantiza una reacción por viewer. Cambiar de tipo es un UPSERT —
     * no se crea duplicado, solo se actualiza `type` y `reacted_at`.
     */
    public function react(Request $request, int $id): JsonResponse
    {
        $member = $request->attributes->get('auth_member');

        $data = $request->validate([
            'type' => 'required|string|in:' . implode(',', StoryReaction::VALID_TYPES),
        ]);

        $story = Story::active()->findOrFail($id);

        StoryReaction::updateOrCreate(
            [
                'story_id' => $story->id,
                'viewer_type' => 'member',
                'viewer_id' => $member->id,
            ],
            [
                'type' => $data['type'],
                'reacted_at' => now(),
            ],
        );

        return response()->json(['ok' => true]);
    }

    /**
     * DELETE /api/app/stories/{id}/react
     * Quita la reacción del usuario actual (toggle off).
     */
    public function unreact(Request $request, int $id): JsonResponse
    {
        $member = $request->attributes->get('auth_member');

        StoryReaction::where('story_id', $id)
            ->where('viewer_type', 'member')
            ->where('viewer_id', $member->id)
            ->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * GET /api/app/stories/{id}/reactions
     *
     * Devuelve:
     * - counts: { heart: 5, fire: 3, ... } solo de tipos con count > 0
     * - my_reaction: el tipo del usuario actual (o null)
     * - viewers: lista detallada de quién reaccionó (solo si el caller es
     *   el OWNER del story; sino se omite por privacidad)
     */
    public function listReactions(Request $request, int $id): JsonResponse
    {
        $member = $request->attributes->get('auth_member');
        $story = Story::findOrFail($id);

        $isOwner = ($story->author_type === 'member'
            && $story->author_id === $member->id);

        // Counts por tipo — solo los que tienen al menos una reacción.
        $countsRaw = StoryReaction::where('story_id', $story->id)
            ->selectRaw('type, COUNT(*) as total')
            ->groupBy('type')
            ->pluck('total', 'type')
            ->all();
        $counts = [];
        foreach (StoryReaction::VALID_TYPES as $t) {
            if (!empty($countsRaw[$t])) {
                $counts[$t] = (int) $countsRaw[$t];
            }
        }

        // Reacción del usuario actual.
        $mine = StoryReaction::where('story_id', $story->id)
            ->where('viewer_type', 'member')
            ->where('viewer_id', $member->id)
            ->value('type');

        $response = [
            'ok' => true,
            'data' => [
                'total' => array_sum($counts),
                'counts' => $counts,
                'my_reaction' => $mine,
            ],
        ];

        // Solo el owner ve quién reaccionó (privacidad).
        if ($isOwner) {
            $reactions = StoryReaction::where('story_id', $story->id)
                ->orderBy('reacted_at', 'desc')
                ->limit(500)
                ->get();

            $memberIds = $reactions->where('viewer_type', 'member')
                ->pluck('viewer_id')->unique();
            $members = \App\Models\Member::whereIn('id', $memberIds)
                ->get(['id', 'full_name'])
                ->keyBy('id');

            $response['data']['reactors'] = $reactions->map(function ($r) use ($members) {
                $m = $r->viewer_type === 'member' ? ($members[$r->viewer_id] ?? null) : null;
                $name = $m
                    ? (trim((string) ($m->full_name ?? '')) ?: 'Miembro Iron Body')
                    : 'Miembro Iron Body';
                return [
                    'viewer_type' => $r->viewer_type,
                    'viewer_id' => $r->viewer_id,
                    'name' => $name,
                    'avatar' => null, // members sin columna de avatar
                    'type' => $r->type,
                    'reacted_at' => $r->reacted_at?->toIso8601String(),
                ];
            })->values();
        }

        return response()->json($response);
    }

    /** DELETE /api/app/stories/{id} */
    public function destroyAsMember(Request $request, int $id): JsonResponse
    {
        $member = $request->attributes->get('auth_member');

        $story = Story::findOrFail($id);

        if ($story->author_type !== 'member' || $story->author_id !== $member->id) {
            return response()->json(['ok' => false, 'error' => 'forbidden'], 403);
        }

        $this->deleteStoryWithFile($story);

        return response()->json(['ok' => true]);
    }

    // ── Endpoints CRM admin (sin auth — patrón del resto del CRM) ──────

    /** POST /api/admin/stories — admin upload desde CRM */
    public function storeAsAdmin(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file' => 'required|file|max:30720|mimes:jpg,jpeg,png,webp,mp4,mov',
            'caption' => 'nullable|string|max:280',
            'duration_ms' => 'nullable|integer|min:100|max:60000',
            'user_id' => 'nullable|integer|exists:users,id',
        ]);

        $user = User::find($data['user_id'] ?? null) ?? User::query()->first();

        $story = $this->createStory(
            file: $data['file'],
            authorType: 'user',
            authorId: $user?->id ?? 0,
            authorName: $user?->name ?? 'Iron Body',
            authorAvatar: null,
            caption: $data['caption'] ?? null,
            durationMs: $data['duration_ms'] ?? null,
        );

        app(NotificationService::class)->notifyStoryCreated($story);

        return response()->json([
            'ok' => true,
            'data' => $this->serializeStory($story, 0, 'user'),
        ], 201);
    }

    /** DELETE /api/admin/stories/{id} — admin puede borrar cualquier story */
    public function destroyAsAdmin(int $id): JsonResponse
    {
        $story = Story::findOrFail($id);
        $this->deleteStoryWithFile($story);
        return response()->json(['ok' => true]);
    }

    /** GET /api/admin/stories — listado completo para el CRM (incluye expiradas reciente) */
    public function indexAsAdmin(): JsonResponse
    {
        $stories = Story::orderBy('created_at', 'desc')->limit(200)->get();
        return response()->json([
            'ok' => true,
            'data' => $stories->map(fn (Story $s) => $this->serializeStory($s, 0, 'user')),
        ]);
    }

    // ── Helpers internos ────────────────────────────────────────────────

    private function createStory(
        \Illuminate\Http\UploadedFile $file,
        string $authorType,
        int $authorId,
        string $authorName,
        ?string $authorAvatar,
        ?string $caption,
        ?int $durationMs,
    ): Story {
        $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        $type = in_array($ext, ['mp4', 'mov'], true) ? 'video' : 'image';
        $filename = Str::uuid()->toString() . '.' . $ext;
        $path = $file->storeAs('stories', $filename, 'public');

        return Story::create([
            'author_type' => $authorType,
            'author_id' => $authorId,
            'author_name' => $authorName,
            'author_avatar' => $authorAvatar,
            'type' => $type,
            'file_path' => $path,
            'disk' => 'public',
            'duration_ms' => $durationMs,
            'caption' => $caption,
            'size_bytes' => $file->getSize(),
            'expires_at' => now()->addHours(self::DEFAULT_LIFETIME_HOURS),
        ]);
    }

    private function deleteStoryWithFile(Story $story): void
    {
        try {
            if ($story->isFirebaseStored()) {
                // Borrado físico en Firebase Storage vía service account —
                // libera el recurso aunque la app se cierre tras el delete.
                app(FirebaseStorageService::class)->deleteObject($story->file_path);
            } else {
                Storage::disk($story->disk)->delete($story->file_path);
            }
        } catch (\Throwable $e) {
            // Si el archivo ya no existe, igual borramos el row.
        }
        $story->delete();
    }

    private function serializeStory(Story $s, int $viewerId, string $viewerType, ?bool $viewed = null): array
    {
        // Ownership autoritativo desde el servidor — NUNCA confiar en una
        // comparación de IDs en el cliente (es la causa de que "Eliminar"
        // no apareciera: el id de sesión del cliente no siempre coincide
        // 1:1 con author_id por tipos/UUID). El cliente solo lee el flag.
        $isOwner = $s->author_type === $viewerType && $s->author_id === $viewerId;

        return [
            'id' => $s->id,
            'author_type' => $s->author_type,
            'author_id' => $s->author_id,
            'author_key' => $s->author_type . ':' . $s->author_id,
            'author_name' => $s->author_name,
            'author_avatar' => $s->author_avatar,
            'type' => $s->type,
            'url' => $s->file_url,
            'duration_ms' => $s->duration_ms,
            'caption' => $s->caption,
            'created_at' => $s->created_at?->toIso8601String(),
            'expires_at' => $s->expires_at->toIso8601String(),
            'viewed' => $viewed ?? false,
            // Permisos resueltos por backend (defense-in-depth: el DELETE
            // vuelve a validar owner). El admin del CRM borra por su propio
            // endpoint, así que aquí can_delete == is_owner del member.
            'is_owner' => $isOwner,
            'can_delete' => $isOwner,
        ];
    }
}
