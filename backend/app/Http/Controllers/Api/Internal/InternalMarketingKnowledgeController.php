<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Models\MarketingKnowledgeItem;
use App\Services\Marketing\MarketingKnowledgeBaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Gestión INTERNA (n8n / operación, firmado HMAC) de la base de conocimiento
 * comercial. SIN endpoints públicos. No expone secretos: el contenido es
 * información comercial pública. No toca pagos ni facturación.
 */
class InternalMarketingKnowledgeController extends Controller
{
    public function __construct(private readonly MarketingKnowledgeBaseService $kb)
    {
    }

    /** GET /api/internal/marketing/knowledge/doctor — resumen del conocimiento. */
    public function doctor(): JsonResponse
    {
        return response()->json(['ok' => true, 'data' => $this->kb->summary()]);
    }

    /** GET /api/internal/marketing/knowledge — lista ítems (filtro opcional por categoría). */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category'    => ['nullable', Rule::in(MarketingKnowledgeItem::CATEGORIES)],
            'only_active' => 'nullable|boolean',
        ]);

        $query = MarketingKnowledgeItem::query()
            ->when($data['category'] ?? null, fn ($q, $c) => $q->category($c))
            ->when($data['only_active'] ?? false, fn ($q) => $q->where('is_active', true))
            ->orderBy('category')->orderBy('priority')->orderBy('id');

        return response()->json([
            'ok'   => true,
            'data' => $query->get()->map(fn (MarketingKnowledgeItem $i) => [
                'id'          => $i->id,
                'category'    => $i->category,
                'key'         => $i->key,
                'title'       => $i->title,
                'content'     => $i->content,
                'priority'    => $i->priority,
                'is_active'   => (bool) $i->is_active,
                'valid_from'  => optional($i->valid_from)->toIso8601String(),
                'valid_until' => optional($i->valid_until)->toIso8601String(),
                'source'      => $i->source,
                'updated_at'  => optional($i->updated_at)->toIso8601String(),
            ]),
        ]);
    }

    /** POST /api/internal/marketing/knowledge — crea o actualiza por `key`. */
    public function upsert(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category'    => ['required', Rule::in(MarketingKnowledgeItem::CATEGORIES)],
            'key'         => 'required|string|max:120',
            'title'       => 'nullable|string|max:160',
            'content'     => 'required|string|max:4000',
            'priority'    => 'nullable|integer|min:0|max:10000',
            'is_active'   => 'nullable|boolean',
            'valid_from'  => 'nullable|date',
            'valid_until' => 'nullable|date',
            'metadata'    => 'nullable|array',
        ]);

        $existing = MarketingKnowledgeItem::where('key', $data['key'])->first();

        $item = MarketingKnowledgeItem::updateOrCreate(
            ['key' => $data['key']],
            [
                'category'    => $data['category'],
                'title'       => $data['title'] ?? null,
                'content'     => $data['content'],
                'priority'    => $data['priority'] ?? 100,
                'is_active'   => $data['is_active'] ?? true,
                'valid_from'  => $data['valid_from'] ?? null,
                'valid_until' => $data['valid_until'] ?? null,
                'metadata'    => $data['metadata'] ?? null,
                'source'      => $existing?->source ?? 'api',
            ],
        );

        return response()->json([
            'ok'      => true,
            'created' => $item->wasRecentlyCreated,
            'id'      => $item->id,
            'key'     => $item->key,
        ]);
    }
}
